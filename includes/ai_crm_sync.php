<?php
/**
 * AI ↔ CRM Auto-Sync Engine
 * Classifies a WhatsApp conversation with OpenAI and applies the lead outcome
 * through the EXACT same side effects as the manual CRM buttons in whatsapp.php:
 *   1. merge outcome into contacts.chat_labels (single-outcome rule)
 *   2. cancel pending lead-automation followups in automation_queue
 *   3. schedule outcome messages (followup_1/followup_2/instant/delayed)
 *   4. trigger_drip_campaigns() so Drip Campaigns react to the new label
 *
 * SAFETY RULES for auto-apply:
 *   - only when confidence >= CRM_AUTO_MIN_CONFIDENCE (default 80)
 *   - never changes an existing 'Converted' outcome (sticky — human owns it)
 *   - never applies 'insufficient'
 *   - records every auto-application in ai_lead_analysis (auto_applied flag)
 */

if (!defined('CRM_AUTO_MIN_CONFIDENCE')) define('CRM_AUTO_MIN_CONFIDENCE', 80);

// ── Label helpers (exact logic mirror of whatsapp.php wa_labels_*) ──
if (!function_exists('crm_labels_parse')) {

    function crm_labels_parse(string $labels): array {
        $out = [];
        foreach (explode(',', $labels) as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $out[mb_strtolower($p, 'UTF-8')] = $p;
        }
        return $out;
    }

    function crm_labels_set_outcome(string $existing, string $outcomeLabel): string {
        $map = crm_labels_parse($existing);
        foreach (['follow-up', 'converted', 'declined', 'not interested', 'not_interested'] as $o) {
            unset($map[$o]);
        }
        $map[mb_strtolower($outcomeLabel, 'UTF-8')] = $outcomeLabel;
        return implode(', ', array_values($map));
    }

    function crm_labels_get_outcome(string $existing): string {
        $map = crm_labels_parse($existing);
        foreach (['converted' => 'Converted', 'declined' => 'Declined', 'not interested' => 'Not Interested', 'follow-up' => 'Follow-up'] as $k => $v) {
            if (isset($map[$k])) return $v;
        }
        return '';
    }

    // ── Lead automation settings (mirror of wa_lead_automation_settings) ──
    function crm_lead_automation_settings(mysqli $conn, int $companyId): array {
        $defaults = [
            'followup_msg_1' => "Hi, just following up on our previous conversation. Do you have any questions?",
            'followup_days_1' => 3,
            'followup_msg_2' => "Checking in again regarding your interest in WAAMINI. How can we help you get started?",
            'followup_days_2' => 7,
            'thanks_msg' => "Thank you for getting in touch with us. We understand this isn't the right time for you. Please feel free to reach out if your requirements change in the future!",
            'converted_msg' => "Thanks for choosing us! We are excited to have you onboard. Let us know if you need any assistance.",
        ];
        $st = $conn->prepare("SELECT * FROM lead_automation_settings WHERE company_id=? LIMIT 1");
        if (!$st) return $defaults;
        $st->bind_param('i', $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return is_array($row) ? array_merge($defaults, $row) : $defaults;
    }

    function crm_queue_insert(mysqli $conn, int $companyId, int $contactId, string $type, string $message, int $delaySeconds): void {
        $type = trim($type);
        $message = trim($message);
        if ($type === '' || $message === '' || $companyId <= 0 || $contactId <= 0) return;
        $scheduledAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $st = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
        if ($st) {
            $st->bind_param('iisss', $companyId, $contactId, $type, $message, $scheduledAt);
            $st->execute();
            $st->close();
        }
    }

    function crm_queue_cancel_pending(mysqli $conn, int $companyId, int $contactId, string $reason): void {
        $reason = trim($reason) !== '' ? trim($reason) : 'outcome_changed';
        $st = $conn->prepare("UPDATE automation_queue SET status='failed', error_msg=? WHERE company_id=? AND contact_id=? AND status='pending' AND type IN ('followup_1','followup_2','delayed','instant')");
        if ($st) {
            $st->bind_param('sii', $reason, $companyId, $contactId);
            $st->execute();
            $st->close();
        }
    }

    /**
     * Apply a lead outcome — EXACT same side effects as the wa_outcome POST in whatsapp.php.
     * $label: 'Follow-up' | 'Converted' | 'Declined' | 'Not Interested'
     */
    function crm_apply_lead_outcome(mysqli $conn, int $companyId, int $contactId, string $label, string $source = 'ai_auto'): bool {
        if (!in_array($label, ['Follow-up', 'Converted', 'Declined', 'Not Interested'], true)) return false;

        // 1. Merge label (single-outcome rule)
        $st = $conn->prepare("SELECT chat_labels FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        if (!$st) return false;
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return false;

        $newLabels = crm_labels_set_outcome((string)($row['chat_labels'] ?? ''), $label);
        $st = $conn->prepare("UPDATE contacts SET chat_labels=? WHERE id=? AND company_id=?");
        $st->bind_param('sii', $newLabels, $contactId, $companyId);
        $st->execute();
        $st->close();

        // Ads & Attribution: same stage + Conversions API side effects as the WhatsApp Chat buttons
        try {
            require_once __DIR__ . '/ads/schema.php';
            ads_set_stage($conn, $companyId, $contactId, ads_stage_for_outcome($label));
        } catch (\Throwable $e) { error_log('[ai_crm_sync] ads stage: ' . $e->getMessage()); }

        // 2+3. Lead automation queue (cancel + schedule per outcome)
        try {
            $la = crm_lead_automation_settings($conn, $companyId);
            crm_queue_cancel_pending($conn, $companyId, $contactId, $source . '_' . strtolower(str_replace(' ', '_', $label)));

            if ($label === 'Follow-up') {
                crm_queue_insert($conn, $companyId, $contactId, 'followup_1', (string)$la['followup_msg_1'], (int)$la['followup_days_1'] * 86400);
                crm_queue_insert($conn, $companyId, $contactId, 'followup_2', (string)$la['followup_msg_2'], (int)$la['followup_days_2'] * 86400);
            } elseif ($label === 'Converted') {
                crm_queue_insert($conn, $companyId, $contactId, 'instant', (string)$la['converted_msg'], 0);
            } elseif ($label === 'Declined' || $label === 'Not Interested') {
                crm_queue_insert($conn, $companyId, $contactId, 'delayed', (string)$la['thanks_msg'], 86400);
            }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] automation scheduling failed: ' . $e->getMessage());
        }

        // 4. Drip campaigns react to the new labels
        try {
            require_once __DIR__ . '/drip_engine.php';
            if (function_exists('trigger_drip_campaigns')) {
                trigger_drip_campaigns($conn, $companyId, $contactId, $newLabels);
            }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] drip trigger failed: ' . $e->getMessage());
        }

        error_log("[CRM SYNC] outcome '{$label}' applied to contact #{$contactId} (source: {$source})");
        return true;
    }

    /** Ensure analysis cache table (shared with ajax_ai_lead_analysis.php) + auto-sync columns. */
    function crm_ensure_analysis_table(mysqli $conn): void {
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
        try { $conn->query("ALTER TABLE ai_lead_analysis ADD COLUMN auto_applied TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
        try { $conn->query("ALTER TABLE ai_lead_analysis ADD COLUMN applied_outcome VARCHAR(30) DEFAULT NULL"); } catch (Throwable $e) {}
    }

    /** Classify conversation via OpenAI. Returns [status, confidence, reason, next_action] or ['error'=>..]. */
    function crm_classify_conversation(mysqli $conn, int $companyId, int $contactId, string $apiKey = ''): array {
        $st = $conn->prepare(
            "SELECT id, direction, COALESCE(NULLIF(content,''), message) AS body
             FROM messages WHERE company_id=? AND contact_id=? ORDER BY id DESC LIMIT 20"
        );
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        if (count($rows) === 0) return ['error' => 'no_messages'];
        $rows = array_reverse($rows);
        $lastMsgId = (int)end($rows)['id'];

        $transcript = '';
        foreach ($rows as $r) {
            $b = trim((string)$r['body']);
            if ($b === '') continue;
            if (mb_strlen($b, 'UTF-8') > 300) $b = mb_substr($b, 0, 300, 'UTF-8') . '…';
            $transcript .= ($r['direction'] === 'incoming' ? 'CUSTOMER' : 'BUSINESS') . ": {$b}\n";
        }
        if (trim($transcript) === '') return ['error' => 'empty_transcript'];

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

ALSO extract CRM details that the CUSTOMER clearly stated in the chat (never guess; use "" when not stated):
- customer_name: the person's own name if they said it ("I am Ravi", "this is Priya from ...")
- email, company (their business name), city
- interests: up to 3 short tags (1-3 words each) for what they want, e.g. "Pro plan", "Bulk messaging", "Demo"
- summary: 1-2 plain sentences: who they are and what they want
- needs: their main requirement in a few words; budget: amount/range if mentioned
- lead_score: 0-100 how likely they are to buy; sentiment: positive|neutral|negative; language: English|Tamil|Tanglish|Hindi|other

Return ONLY JSON:
{"status":"follow_up|converted|declined|not_interested|insufficient","confidence":0-100,"reason":"one short sentence for the sales agent","next_action":"one short suggested next step for the agent","customer_name":"","email":"","company":"","city":"","interests":[],"summary":"","needs":"","budget":"","lead_score":0,"sentiment":"neutral","language":""}
SYS;

        $p = crm_llm_json($conn, $system, "Conversation transcript (oldest first):\n\n" . $transcript, $apiKey);
        if (isset($p['_error'])) return ['error' => $p['_error']];

        $valid = ['follow_up', 'converted', 'declined', 'not_interested', 'insufficient'];
        return [
            'status' => in_array($p['status'] ?? '', $valid, true) ? $p['status'] : 'insufficient',
            'confidence' => max(0, min(100, (int)($p['confidence'] ?? 0))),
            'reason' => mb_substr(trim((string)($p['reason'] ?? '')), 0, 500, 'UTF-8'),
            'next_action' => mb_substr(trim((string)($p['next_action'] ?? '')), 0, 500, 'UTF-8'),
            'last_msg_id' => $lastMsgId,
            'profile' => $p,
        ];
    }

    /**
     * FULL AUTO-SYNC: classify + apply (with safety rules) + record.
     * Returns a short result string for logging.
     */
    function crm_auto_sync(mysqli $conn, int $companyId, int $contactId, string $apiKey = ''): string {
        crm_ensure_analysis_table($conn);

        // Skip if no new messages since last analysis (cache = zero cost)
        $st = $conn->prepare("SELECT last_msg_id FROM ai_lead_analysis WHERE company_id=? AND contact_id=? LIMIT 1");
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
        $cached = $st->get_result()->fetch_assoc();
        $st->close();

        $st = $conn->prepare("SELECT MAX(id) m FROM messages WHERE company_id=? AND contact_id=?");
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
        $maxId = (int)($st->get_result()->fetch_assoc()['m'] ?? 0);
        $st->close();
        if ($cached && (int)$cached['last_msg_id'] === $maxId) return 'cached_no_change';

        $r = crm_classify_conversation($conn, $companyId, $contactId, $apiKey);
        if (isset($r['error'])) return 'classify_error:' . $r['error'];

        $statusToLabel = ['follow_up' => 'Follow-up', 'converted' => 'Converted', 'declined' => 'Declined', 'not_interested' => 'Not Interested'];
        $label = $statusToLabel[$r['status']] ?? '';

        // Current outcome + sticky-converted rule
        $st = $conn->prepare("SELECT chat_labels FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $curLabels = (string)($st->get_result()->fetch_assoc()['chat_labels'] ?? '');
        $st->close();
        $current = crm_labels_get_outcome($curLabels);

        $applied = 0;
        $appliedOutcome = null;
        $why = '';

        $cfgAi = crm_ai_settings($conn, $companyId);
        if ((int)$cfgAi['auto_profile'] === 1) {
            crm_apply_profile($conn, $companyId, $contactId, (array)($r['profile'] ?? []));
        }

        if ((int)$cfgAi['auto_outcome'] !== 1) {
            $why = 'outcome_off';
        } elseif ($label === '') {
            $why = 'insufficient';
        } elseif ($r['confidence'] < (int)crm_ai_settings($conn, $companyId)['min_confidence']) {
            $why = 'low_confidence_' . $r['confidence'];
        } elseif ($current === $label) {
            $why = 'already_' . $label;
        } elseif ($current === 'Converted') {
            $why = 'sticky_converted'; // never auto-downgrade a converted customer
        } else {
            if (crm_apply_lead_outcome($conn, $companyId, $contactId, $label, 'ai_auto')) {
                $applied = 1;
                $appliedOutcome = $label;
                $why = 'applied_' . $label;
            } else {
                $why = 'apply_failed';
            }
        }

        // Record analysis (upsert)
        $st = $conn->prepare(
            "INSERT INTO ai_lead_analysis (company_id, contact_id, last_msg_id, status, confidence, reason, next_action, auto_applied, applied_outcome, analyzed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_msg_id=VALUES(last_msg_id), status=VALUES(status), confidence=VALUES(confidence),
                                     reason=VALUES(reason), next_action=VALUES(next_action),
                                     auto_applied=VALUES(auto_applied), applied_outcome=VALUES(applied_outcome), analyzed_at=NOW()"
        );
        $st->bind_param('iiisissis', $companyId, $contactId, $r['last_msg_id'], $r['status'], $r['confidence'], $r['reason'], $r['next_action'], $applied, $appliedOutcome);
        $st->execute();
        $st->close();

        return $why;
    }

    /**
     * Ask the AI for a JSON answer. Tries the WAAMINI Bot active provider first, then every
     * other provider that has a saved key (so a rate-limited or out-of-credit primary provider
     * does not stop the analysis), then falls back to OPENAI_API_KEY.
     * Returns the decoded array, or ['_error' => '...'].
     */
    function crm_llm_json(mysqli $conn, string $system, string $user, string $apiKey = ''): array {
        $msgs = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
        $text = null;
        $errs = [];
        try {
            require_once __DIR__ . '/waamini_bot/bootstrap.php';
            if (class_exists('WB_Store')) {
                foreach (crm_ai_provider_chain($conn) as [$prov, $model]) {
                    try {
                        $r = WB_OpenAI::chatAs($conn, $prov, $model, $msgs, null, ['json' => true, 'max_tokens' => 900, 'timeout' => 45]);
                    } catch (Throwable $e) {
                        $errs[] = $prov . ': ' . $e->getMessage();
                        continue;
                    }
                    if (!$r['ok']) { $errs[] = $prov . ': ' . (string)$r['error']; continue; }
                    $cand = (string)($r['data']['choices'][0]['message']['content'] ?? '');
                    $parsed = crm_llm_parse_json($cand);
                    if ($parsed !== null) { return $parsed; }
                    $errs[] = $prov . ': bad_json';
                }
            }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] provider call failed: ' . $e->getMessage());
        }
        if ($text === null) {
            if ($apiKey === '') $apiKey = (string)(getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? ''));
            if ($apiKey !== '') {
                $ch = curl_init('https://api.openai.com/v1/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                    CURLOPT_POSTFIELDS => json_encode(['model' => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini', 'messages' => $msgs,
                        'response_format' => ['type' => 'json_object'], 'temperature' => 0.1, 'max_tokens' => 600], JSON_UNESCAPED_UNICODE),
                ]);
                $raw = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($http === 200) {
                    $text = (string)(json_decode((string)$raw, true)['choices'][0]['message']['content'] ?? '');
                    $j = crm_llm_parse_json($text);
                    if ($j !== null) { return $j; }
                    return ['_error' => 'bad_json'];
                }
                return ['_error' => 'openai_http_' . $http];
            }
        }
        if ($errs) return ['_error' => 'provider: ' . mb_substr(implode(' | ', $errs), 0, 240, 'UTF-8')];
        return ['_error' => 'no_ai_provider'];
    }

    /** Tolerate ```json fences or text around the object; null when no JSON object is present. */
    function crm_llm_parse_json(string $text): ?array {
        $text = trim($text);
        if ($text === '') return null;
        $j = json_decode($text, true);
        if (!is_array($j)) {
            $x = strpos($text, '{'); $y = strrpos($text, '}');
            $j = ($x !== false && $y !== false && $y > $x) ? json_decode(substr($text, $x, $y - $x + 1), true) : null;
        }
        return is_array($j) ? $j : null;
    }

    /** Default model to use when a fallback provider is tried (only providers with a saved key qualify). */
    function crm_ai_provider_default_model(string $provider): string {
        return [
            'openai'     => 'gpt-4o-mini',
            'gemini'     => 'gemini-2.0-flash',
            'openrouter' => 'openai/gpt-4o-mini',
            'claude'     => 'claude-3-5-haiku-latest',
            'grok'       => 'grok-3-mini',
            'deepseek'   => 'deepseek-chat',
            'groq'       => 'llama-3.3-70b-versatile',
        ][$provider] ?? '';
    }

    /**
     * Ordered [provider, model] pairs to try: the admin-chosen provider first, then any other
     * provider that has a stored key and a known default model.
     */
    function crm_ai_provider_chain(mysqli $conn): array {
        if (!class_exists('WB_Store')) { return []; }
        $s = WB_Store::settings($conn);
        $active = (string)($s['chat_provider'] ?? 'openai');
        $activeModel = trim((string)($s['chat_model'] ?? ''));
        $chain = [];
        if ($activeModel !== '') { $chain[] = [$active, $activeModel]; }
        foreach (array_keys(WB_Store::providers()) as $prov) {
            if ($prov === $active) continue;
            $ks = WB_Store::providerKeyStatus($conn, $prov);
            if (empty($ks['has_key'])) continue;
            $m = crm_ai_provider_default_model($prov);
            if ($m !== '') { $chain[] = [$prov, $m]; }
        }
        return $chain;
    }


    /**
     * Fill the CRM card from what the customer said in the chat.
     * Never overwrites what a person typed: name/email/company/city are only filled when empty
     * (or when the name is just the phone number). Interest tags are added to Labels.
     * The AI summary lives in its own block at the top of Notes and is replaced on each analysis;
     * notes written by the team stay untouched below it.
     */
    function crm_apply_profile(mysqli $conn, int $companyId, int $contactId, array $p): void {
        if (!$p) return;
        $st = $conn->prepare("SELECT name, phone, email, client_company, city, chat_labels, notes FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        if (!$st) return;
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$c) return;

        $clean = static fn($v, int $max) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, $max, 'UTF-8');
        $set = []; $types = ''; $vals = [];
        $put = static function (string $col, $val, string $t) use (&$set, &$types, &$vals): void { $set[] = "$col=?"; $types .= $t; $vals[] = $val; };

        // name: fill only when empty or just digits (phone)
        $name = $clean($p['customer_name'] ?? '', 80);
        $curName = trim((string)$c['name']);
        if ($name !== '' && mb_strlen($name, 'UTF-8') >= 2 && ($curName === '' || preg_match('/^[\d\s+()-]+$/', $curName))) {
            $put('name', $name, 's');
        }
        $email = $clean($p['email'] ?? '', 100);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && trim((string)$c['email']) === '') $put('email', $email, 's');
        $company = $clean($p['company'] ?? '', 120);
        if ($company !== '' && trim((string)$c['client_company']) === '') $put('client_company', $company, 's');
        $city = $clean($p['city'] ?? '', 80);
        if ($city !== '' && trim((string)$c['city']) === '') $put('city', $city, 's');

        // interest tags -> Labels (merge, keep the outcome label and any team labels)
        $tags = is_array($p['interests'] ?? null) ? array_slice($p['interests'], 0, 3) : [];
        if ($tags) {
            $map = crm_labels_parse((string)$c['chat_labels']);
            $before = count($map);
            foreach ($tags as $t) {
                $t = $clean($t, 30);
                $t = trim($t, " ,.;:#");
                if ($t === '' || mb_strlen($t, 'UTF-8') < 2) continue;
                $k = mb_strtolower($t, 'UTF-8');
                if (in_array($k, ['follow-up', 'converted', 'declined', 'not interested'], true)) continue;
                if (!isset($map[$k]) && count($map) < 12 && mb_strlen(implode(', ', $map) . ', ' . $t, 'UTF-8') <= 250) $map[$k] = $t;
            }
            if (count($map) !== $before) $put('chat_labels', implode(', ', array_values($map)), 's');
        }

        // AI summary block at the top of Notes
        $lines = [];
        $summary = $clean($p['summary'] ?? '', 400);
        if ($summary !== '') $lines[] = $summary;
        foreach (['needs' => 'Needs', 'budget' => 'Budget'] as $k => $lbl) {
            $v = $clean($p[$k] ?? '', 160);
            if ($v !== '') $lines[] = $lbl . ': ' . $v;
        }
        if ($lines) {
            $start = '[AI summary]';
            $end = '[/AI summary]';
            $human = (string)$c['notes'];
            $a = strpos($human, $start); $b = strpos($human, $end);
            if ($a !== false && $b !== false && $b > $a) {
                $human = substr($human, 0, $a) . substr($human, $b + strlen($end));
            }
            $human = trim($human);
            $block = $start . ' ' . date('d M, h:i A') . "\n" . implode("\n", $lines) . "\n" . $end;
            $put('notes', $block . ($human !== '' ? "\n\n" . $human : ''), 's');
        }

        // score / sentiment / language
        if (isset($p['lead_score']) && is_numeric($p['lead_score'])) $put('ai_lead_score', max(0, min(100, (int)$p['lead_score'])), 'i');
        $sent = strtolower((string)($p['sentiment'] ?? ''));
        if (in_array($sent, ['positive', 'neutral', 'negative'], true)) $put('ai_sentiment', $sent, 's');
        $lang = $clean($p['language'] ?? '', 20);
        if ($lang !== '') $put('language', $lang, 's');
        if (!$set) return;
        $set[] = 'ai_score_updated=NOW()';

        try {
            $sql = 'UPDATE contacts SET ' . implode(', ', $set) . ' WHERE id=? AND company_id=?';
            $types .= 'ii'; $vals[] = $contactId; $vals[] = $companyId;
            $u = $conn->prepare($sql);
            if ($u) { $u->bind_param($types, ...$vals); $u->execute(); $u->close(); }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] profile update failed: ' . $e->getMessage());
        }
    }
    /** Per-company switch: AI reads every chat and sets the lead outcome (and so the drips) by itself. */
    function crm_ai_settings(mysqli $conn, int $companyId): array {
        static $cache = [];
        if (isset($cache[$companyId])) return $cache[$companyId];
        $conn->query("CREATE TABLE IF NOT EXISTS crm_ai_settings (
            company_id INT NOT NULL PRIMARY KEY,
            auto_outcome TINYINT(1) NOT NULL DEFAULT 1,
            min_confidence TINYINT NOT NULL DEFAULT 80,
            auto_profile TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        try { $conn->query("ALTER TABLE crm_ai_settings ADD COLUMN auto_profile TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        $out = ['auto_outcome' => 1, 'min_confidence' => CRM_AUTO_MIN_CONFIDENCE, 'auto_profile' => 1];
        $st = $conn->prepare("SELECT auto_outcome, min_confidence, auto_profile FROM crm_ai_settings WHERE company_id=? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) $out = ['auto_outcome' => (int)$row['auto_outcome'], 'min_confidence' => max(50, min(100, (int)$row['min_confidence'])), 'auto_profile' => (int)$row['auto_profile']];
        }
        return $cache[$companyId] = $out;
    }

    function crm_ai_save_settings(mysqli $conn, int $companyId, bool $auto, int $minConfidence, bool $profile = true): void {
        crm_ai_settings($conn, $companyId);
        $a = $auto ? 1 : 0;
        $p = $profile ? 1 : 0;
        $m = max(50, min(100, $minConfidence));
        $st = $conn->prepare("INSERT INTO crm_ai_settings (company_id, auto_outcome, min_confidence, auto_profile) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE auto_outcome=VALUES(auto_outcome), min_confidence=VALUES(min_confidence), auto_profile=VALUES(auto_profile)");
        if ($st) { $st->bind_param('iiii', $companyId, $a, $m, $p); $st->execute(); $st->close(); }
    }

    /**
     * Queue a chat for AI lead analysis. Called by the webhook on every incoming message.
     * Debounced: a burst of messages becomes one analysis ~30 s after the last one,
     * so the bot's reply is included and the AI is not called per message.
     */
    function crm_ai_enqueue(mysqli $conn, int $companyId, int $contactId, int $delaySeconds = 30): void {
        if ($companyId <= 0 || $contactId <= 0) return;
        try {
            $cfg = crm_ai_settings($conn, $companyId);
            if ((int)$cfg['auto_outcome'] !== 1 && (int)$cfg['auto_profile'] !== 1) return;
            $conn->query("CREATE TABLE IF NOT EXISTS crm_ai_jobs (
                company_id INT NOT NULL,
                contact_id INT NOT NULL,
                due_at DATETIME NOT NULL,
                attempts TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (company_id, contact_id),
                KEY idx_due (due_at)
            ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
            $due = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
            $st = $conn->prepare("INSERT INTO crm_ai_jobs (company_id, contact_id, due_at) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE due_at=VALUES(due_at), attempts=0");
            if ($st) { $st->bind_param('iis', $companyId, $contactId, $due); $st->execute(); $st->close(); }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] enqueue failed: ' . $e->getMessage());
        }
    }

    /** Run due analyses. Used by the WAAMINI Bot worker and the inbox tick. Returns how many ran. */
    function crm_ai_process_due(mysqli $conn, int $limit = 5, int $onlyCompany = 0): int {
        $done = 0;
        try {
            $t = $conn->query("SHOW TABLES LIKE 'crm_ai_jobs'");
            if (!$t || $t->num_rows === 0) return 0;
            $sql = "SELECT company_id, contact_id, attempts FROM crm_ai_jobs WHERE due_at <= NOW()" . ($onlyCompany > 0 ? " AND company_id=" . (int)$onlyCompany : '') . " ORDER BY due_at LIMIT " . max(1, $limit);
            $q = $conn->query($sql);
            while ($q && ($job = $q->fetch_assoc())) {
                $cid = (int)$job['company_id']; $ctid = (int)$job['contact_id'];
                // claim the row so a second runner skips it
                $claimTs = date('Y-m-d H:i:s', time() + 300);
                $claim = $conn->prepare("UPDATE crm_ai_jobs SET due_at = ?, attempts = attempts + 1 WHERE company_id=? AND contact_id=? AND due_at <= NOW()");
                $claim->bind_param('sii', $claimTs, $cid, $ctid);
                $claim->execute();
                $got = $claim->affected_rows;
                $claim->close();
                if ($got !== 1) continue;

                $res = crm_auto_sync($conn, $cid, $ctid);
                $retry = strpos($res, 'classify_error:provider') === 0 && (int)$job['attempts'] < 2;
                if (!$retry) {
                    // delete only our claim; a newer message may have re-queued the chat meanwhile
                    $del = $conn->prepare("DELETE FROM crm_ai_jobs WHERE company_id=? AND contact_id=? AND due_at = ?");
                    $del->bind_param('iis', $cid, $ctid, $claimTs);
                    $del->execute();
                    $del->close();
                }
                error_log("[CRM SYNC] contact #{$ctid} (company {$cid}): {$res}");
                $done++;
            }
        } catch (Throwable $e) {
            error_log('[CRM SYNC] process_due failed: ' . $e->getMessage());
        }
        return $done;
    }
}
?>
