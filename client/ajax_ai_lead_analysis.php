<?php
/**
 * AI Lead Analysis (MCP + OpenAI) — analyzes the WhatsApp conversation and
 * classifies the lead outcome: follow_up | converted | declined | not_interested | insufficient.
 *
 * Smart-cached per contact: re-calls OpenAI ONLY when new messages exist
 * (cache key = last message id), so it costs nothing on plain reloads.
 *
 * POST: contact_id, _token (csrf), force=1 (optional re-analyze)
 * Returns JSON: { ok, status, label, confidence, reason, next_action, cached, current_outcome }
 */

include '../includes/client_auth_check.php';

header('Content-Type: application/json; charset=utf-8');

function ai_json(array $out): never {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ai_json(['ok' => false, 'error' => 'POST only']);
csrf_verify();

$contactId = (int)($_POST['contact_id'] ?? 0);
$force = (int)($_POST['force'] ?? 0) === 1;
if ($contactId <= 0) ai_json(['ok' => false, 'error' => 'contact_id required']);

// ── Verify contact belongs to this company (tenant scoping) ──
$st = $conn->prepare("SELECT id, name, chat_labels FROM contacts WHERE id=? AND company_id=? LIMIT 1");
$st->bind_param('ii', $contactId, $companyId);
$st->execute();
$contact = $st->get_result()->fetch_assoc();
$st->close();
if (!$contact) ai_json(['ok' => false, 'error' => 'Contact not found']);

// Current outcome from labels (same parser logic as whatsapp.php)
$curLabels = mb_strtolower((string)($contact['chat_labels'] ?? ''), 'UTF-8');
$currentOutcome = '';
foreach (['converted' => 'Converted', 'declined' => 'Declined', 'not interested' => 'Not Interested', 'follow-up' => 'Follow-up'] as $k => $v) {
    if (strpos($curLabels, $k) !== false) { $currentOutcome = $v; break; }
}

// ── Cache table (idempotent) ──
$conn->query(
    "CREATE TABLE IF NOT EXISTS ai_lead_analysis (
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        last_msg_id BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL,
        confidence TINYINT NOT NULL DEFAULT 0,
        reason VARCHAR(500) DEFAULT NULL,
        next_action VARCHAR(500) DEFAULT NULL,
        analyzed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, contact_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
);

// ── Load conversation (last 20 messages, chronological) ──
$st = $conn->prepare(
    "SELECT id, direction, COALESCE(NULLIF(content,''), message) AS body
     FROM messages
     WHERE company_id=? AND contact_id=?
     ORDER BY id DESC LIMIT 20"
);
$st->bind_param('ii', $companyId, $contactId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$rows = array_reverse($rows);

if (count($rows) === 0) {
    ai_json(['ok' => true, 'status' => 'insufficient', 'label' => 'No messages', 'confidence' => 0,
             'reason' => 'No conversation yet to analyze.', 'next_action' => '', 'cached' => false,
             'current_outcome' => $currentOutcome]);
}

$lastMsgId = (int)end($rows)['id'];

// ── Cache hit? (same last message analyzed, not forced) ──
if (!$force) {
    $st = $conn->prepare("SELECT * FROM ai_lead_analysis WHERE company_id=? AND contact_id=? LIMIT 1");
    $st->bind_param('ii', $companyId, $contactId);
    $st->execute();
    $cache = $st->get_result()->fetch_assoc();
    $st->close();
    if ($cache && (int)$cache['last_msg_id'] === $lastMsgId) {
        if (($cache['status'] ?? '') === '__error__') {
            // Same conversation already failed recently: return the cached reason instead of
            // calling the provider again on every page load (the ↻ button forces a retry).
            $age = time() - (int)strtotime((string)($cache['analyzed_at'] ?? ''));
            if ($age >= 0 && $age < 900) {
                ai_json(['ok' => false, 'error' => ((string)($cache['reason'] ?? '')) ?: 'AI analysis is unavailable right now.', 'cached' => true]);
            }
        } else {
            ai_json(['ok' => true, 'status' => $cache['status'],
                     'label' => ai_status_label($cache['status']),
                     'confidence' => (int)$cache['confidence'],
                     'reason' => (string)$cache['reason'],
                     'next_action' => (string)$cache['next_action'],
                     'cached' => true, 'analyzed_at' => $cache['analyzed_at'],
                     'current_outcome' => $currentOutcome]);
        }
    }
}

function ai_status_label(string $s): string {
    return ['follow_up' => 'Follow-up', 'converted' => 'Converted', 'declined' => 'Declined',
            'not_interested' => 'Not Interested', 'insufficient' => 'Too early to tell'][$s] ?? $s;
}

// ── Build transcript for the AI ──
$transcript = '';
foreach ($rows as $r) {
    $who = $r['direction'] === 'incoming' ? 'CUSTOMER' : 'BUSINESS';
    $body = trim((string)$r['body']);
    if ($body === '') continue;
    if (mb_strlen($body, 'UTF-8') > 300) $body = mb_substr($body, 0, 300, 'UTF-8') . '…';
    $transcript .= "{$who}: {$body}\n";
}
if (trim($transcript) === '') {
    ai_json(['ok' => true, 'status' => 'insufficient', 'label' => 'No messages', 'confidence' => 0,
             'reason' => 'Conversation has no readable text.', 'next_action' => '', 'cached' => false,
             'current_outcome' => $currentOutcome]);
}

// ── OpenAI classification (strict JSON) ──
require_once __DIR__ . '/../includes/ai_crm_sync.php';

$system = <<<SYS
You are a CRM lead-outcome classifier for a WhatsApp business inbox (7digit/Waamini, India).
Analyze the conversation transcript (may be English, Tamil, or Tanglish) and classify the lead into EXACTLY one status:

- "converted"      → customer clearly purchased, paid, confirmed the order/subscription, or explicitly agreed to buy
- "declined"       → customer clearly said no / rejected / chose another vendor
- "not_interested" → customer shows disinterest, asks to stop, ignores value, or says "not now" with no future intent
- "follow_up"      → customer shows ANY interest (asked about price/demo/features, booked a demo, said "later/think about it") but has not converted yet — this is the default for warm leads
- "insufficient"   → only greetings/too little information to judge

Rules:
- Tamil/Tanglish cues: "venum/வேணும்" = wants (interest), "vendam/வேண்டாம்" = doesn't want, "seri/sari, pannunga" = agreement, "demo kudu" = wants demo (follow_up).
- A booked demo that hasn't happened yet = follow_up (NOT converted).
- Base the decision ONLY on the transcript. Be decisive; prefer follow_up over insufficient when there's any business intent.

Return ONLY JSON:
{"status":"follow_up|converted|declined|not_interested|insufficient","confidence":0-100,"reason":"one short sentence for the sales agent","next_action":"one short suggested next step for the agent"}
SYS;

// Uses the AI provider the admin chose for WAAMINI Bot (falls back to OPENAI_API_KEY).
$parsed = crm_llm_json($conn, $system, "Conversation transcript (oldest first):\n\n" . $transcript);
if (isset($parsed['_error'])) {
    $err = (string)$parsed['_error'];
    error_log('[AI LEAD] ' . $err);
    if ($err === 'no_ai_provider') {
        $msg = 'AI is not set up yet. The admin must connect an AI provider in WAAMINI Bot settings.';
    } elseif (preg_match('/(rate.?limit|quota|credit|billing|insufficient|exceed|too many|429|402)/i', $err)) {
        $msg = 'AI provider quota reached. Add credits to your provider key or switch model in WAAMINI Bot settings, then tap ↻.';
    } else {
        $msg = 'AI analysis is unavailable right now. Try again shortly.';
    }
    // Negative-cache the failure for this conversation so page reloads stop calling the provider.
    $se = $conn->prepare(
        "INSERT INTO ai_lead_analysis (company_id, contact_id, last_msg_id, status, confidence, reason, next_action, analyzed_at)
         VALUES (?, ?, ?, '__error__', 0, ?, '', NOW())
         ON DUPLICATE KEY UPDATE last_msg_id = VALUES(last_msg_id), status = '__error__', confidence = 0,
             reason = VALUES(reason), next_action = '', analyzed_at = NOW()");
    if ($se) { $se->bind_param('iiis', $companyId, $contactId, $lastMsgId, $msg); $se->execute(); $se->close(); }
    ai_json(['ok' => false, 'error' => $msg]);
}

$validStatuses = ['follow_up', 'converted', 'declined', 'not_interested', 'insufficient'];
$status = in_array($parsed['status'] ?? '', $validStatuses, true) ? $parsed['status'] : 'insufficient';
$confidence = max(0, min(100, (int)($parsed['confidence'] ?? 0)));
$reason = mb_substr(trim((string)($parsed['reason'] ?? '')), 0, 500, 'UTF-8');
$nextAction = mb_substr(trim((string)($parsed['next_action'] ?? '')), 0, 500, 'UTF-8');

// ── Save to cache (upsert) ──
$st = $conn->prepare(
    "INSERT INTO ai_lead_analysis (company_id, contact_id, last_msg_id, status, confidence, reason, next_action, analyzed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE last_msg_id=VALUES(last_msg_id), status=VALUES(status), confidence=VALUES(confidence),
                             reason=VALUES(reason), next_action=VALUES(next_action), analyzed_at=NOW()"
);
$st->bind_param('iiisiss', $companyId, $contactId, $lastMsgId, $status, $confidence, $reason, $nextAction);
$st->execute();
$st->close();

ai_json([
    'ok' => true,
    'status' => $status,
    'label' => ai_status_label($status),
    'confidence' => $confidence,
    'reason' => $reason,
    'next_action' => $nextAction,
    'cached' => false,
    'current_outcome' => $currentOutcome,
]);
?>
