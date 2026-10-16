<?php
/**
 * Facebook Lead Ads Webhook (Leadgen)
 *
 * - GET: webhook verification using companies.fb_verify_token
 * - POST: receive leadgen events, fetch lead details via Graph API using Page Access Token,
 *         upsert into contacts, optionally enqueue WhatsApp auto-reply.
 *
 * Callback URL format (per company):
 *   /waamini/api/fb_leads_webhook.php?cid=COMPANY_ID
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/EncryptionService.php';

header('Content-Type: text/plain; charset=utf-8');

$companyId = (int)($_GET['cid'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    echo 'Missing cid';
    exit;
}

// Ensure schema exists (safe/idempotent)
foreach ([
    "ALTER TABLE companies ADD COLUMN fb_page_id VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_verify_token VARCHAR(128) DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_page_access_token TEXT DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_lead_auto_reply_enabled TINYINT(1) DEFAULT 0",
    "ALTER TABLE companies ADD COLUMN fb_lead_auto_reply_message TEXT DEFAULT NULL",
    "ALTER TABLE contacts ADD COLUMN source VARCHAR(50) DEFAULT NULL",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// Load company config
$st = $conn->prepare("SELECT id, fb_verify_token, fb_page_id, fb_page_access_token, fb_lead_auto_reply_enabled, fb_lead_auto_reply_message FROM companies WHERE id=? LIMIT 1");
if (!$st) {
    http_response_code(500);
    echo 'DB error';
    exit;
}
$st->bind_param('i', $companyId);
$st->execute();
$company = $st->get_result()->fetch_assoc();
$st->close();

if (!$company) {
    http_response_code(404);
    echo 'Company not found';
    exit;
}

// ── GET verification ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    $verifyToken = (string)($company['fb_verify_token'] ?? '');
    if ($verifyToken !== '' && $mode === 'subscribe' && $challenge !== '' && hash_equals($verifyToken, $token)) {
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ── POST events ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// Signature validation (recommended in production)
$appSecret = (string)($_ENV['META_APP_SECRET'] ?? getenv('META_APP_SECRET') ?: ($_ENV['FB_APP_SECRET'] ?? getenv('FB_APP_SECRET') ?: ''));
if ($appSecret !== '' && (string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'production') {
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($sig === '') {
        http_response_code(403);
        echo 'Missing signature';
        exit;
    }
    $expected = 'sha256=' . hash_hmac('sha256', file_get_contents('php://input') ?: '', $appSecret);
    if (!hash_equals($expected, $sig)) {
        http_response_code(403);
        echo 'Bad signature';
        exit;
    }
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo 'Bad JSON';
    exit;
}

// Extract leadgen events
$events = [];
foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $val = $change['value'] ?? null;
        if (!is_array($val)) continue;
        $leadId = (string)($val['leadgen_id'] ?? '');
        $formId = (string)($val['form_id'] ?? '');
        $pageId = (string)($val['page_id'] ?? '');
        if ($leadId !== '') {
            $events[] = ['leadgen_id' => $leadId, 'form_id' => $formId, 'page_id' => $pageId];
        }
    }
}

if ($events === []) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// Validate page ID match (if configured)
$cfgPageId = trim((string)($company['fb_page_id'] ?? ''));
if ($cfgPageId !== '' && isset($events[0]['page_id']) && $events[0]['page_id'] !== '' && $cfgPageId !== (string)$events[0]['page_id']) {
    // Still return 200 so Meta doesn't retry; just ignore foreign pages.
    http_response_code(200);
    echo 'OK';
    exit;
}

// Need page access token to fetch lead details
$pageToken = (string)($company['fb_page_access_token'] ?? '');
if ($pageToken !== '' && function_exists('decrypt_secret')) {
    $pageToken = decrypt_secret($pageToken) ?? $pageToken;
}
if ($pageToken === '') {
    error_log('[WAAMINI FB LEADS] Missing fb_page_access_token for company ' . $companyId);
    http_response_code(200);
    echo 'OK';
    exit;
}

require_once __DIR__ . '/../includes/security.php'; // sanitize_phone/email

function fb_graph_get(string $path, string $accessToken): array {
    $url = 'https://graph.facebook.com/v20.0/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($resp) ? $resp : '', 'err' => $err];
}

function fb_field_map(array $fieldData): array {
    $out = [
        'full_name' => '',
        'first_name' => '',
        'last_name' => '',
        'phone' => '',
        'email' => '',
    ];
    foreach ($fieldData as $f) {
        if (!is_array($f)) continue;
        $name = strtolower(trim((string)($f['name'] ?? '')));
        $vals = $f['values'] ?? [];
        $val = is_array($vals) ? (string)($vals[0] ?? '') : '';
        if ($val === '') continue;
        if (in_array($name, ['full_name', 'fullname', 'name'], true)) $out['full_name'] = $val;
        elseif (in_array($name, ['first_name', 'firstname'], true)) $out['first_name'] = $val;
        elseif (in_array($name, ['last_name', 'lastname'], true)) $out['last_name'] = $val;
        elseif (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) $out['phone'] = $val;
        elseif (strpos($name, 'email') !== false) $out['email'] = $val;
    }
    return $out;
}

function fb_tpl_apply(string $tpl, array $vars): string {
    $rep = [];
    foreach ($vars as $k => $v) {
        $rep['{{' . $k . '}}'] = (string)$v;
    }
    return strtr($tpl, $rep);
}

// Ensure group "Facebook Leads" exists
$groupId = null;
try {
    $gName = 'Facebook Leads';
    $gSel = $conn->prepare("SELECT id FROM contact_groups WHERE company_id=? AND name=? LIMIT 1");
    if ($gSel) {
        $gSel->bind_param('is', $companyId, $gName);
        $gSel->execute();
        $row = $gSel->get_result()->fetch_assoc();
        if ($row && (int)$row['id'] > 0) $groupId = (int)$row['id'];
        $gSel->close();
    }
    if ($groupId === null) {
        $gIns = $conn->prepare("INSERT INTO contact_groups (company_id, name, description) VALUES (?,?,?)");
        if ($gIns) {
            $desc = 'Auto-created from Facebook Lead Ads';
            $gIns->bind_param('iss', $companyId, $gName, $desc);
            $gIns->execute();
            $groupId = (int)$conn->insert_id;
            $gIns->close();
        }
    }
} catch (\Throwable $e) {}

// Process each lead event
foreach ($events as $ev) {
    $leadId = (string)($ev['leadgen_id'] ?? '');
    if ($leadId === '') continue;

    // Fetch lead details
    $res = fb_graph_get($leadId . '?fields=created_time,field_data&access_token=' . rawurlencode($pageToken), $pageToken);
    if ($res['err'] !== '') {
        error_log('[WAAMINI FB LEADS] curl error: ' . $res['err']);
        continue;
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json) || isset($json['error'])) {
        $msg = is_array($json) ? (string)($json['error']['message'] ?? 'API error') : 'API error';
        error_log('[WAAMINI FB LEADS] lead fetch failed code=' . $res['code'] . ' msg=' . $msg);
        continue;
    }

    $mapped = fb_field_map((array)($json['field_data'] ?? []));
    $phone = sanitize_phone((string)($mapped['phone'] ?? ''));
    $email = sanitize_email((string)($mapped['email'] ?? ''));
    $first = trim((string)($mapped['first_name'] ?? ''));
    $last  = trim((string)($mapped['last_name'] ?? ''));
    $full  = trim((string)($mapped['full_name'] ?? ''));
    if ($full === '') $full = trim($first . ' ' . $last);
    if ($full === '') $full = $phone !== '' ? $phone : ('FB Lead ' . $leadId);

    if ($phone === '') {
        // Can't WhatsApp without phone, but still store as contact if email exists
        if ($email === '') continue;
    }

    // Upsert contact (by phone preferred)
    $contactId = 0;
    if ($phone !== '') {
        $sel = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND phone=? LIMIT 1");
        if ($sel) {
            $sel->bind_param('is', $companyId, $phone);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            if ($row && (int)$row['id'] > 0) $contactId = (int)$row['id'];
            $sel->close();
        }
    }

    $tags = 'FB Lead';
    if ($contactId > 0) {
        $up = $conn->prepare("UPDATE contacts SET name=?, first_name=?, last_name=?, email=?, tags=?, group_id=?, source='facebook', last_message_at=NOW() WHERE id=? AND company_id=?");
        if ($up) {
            $gid = $groupId;
            $up->bind_param('sssssiis', $full, $first, $last, $email, $tags, $gid, $contactId, $companyId);
            $up->execute();
            $up->close();
        }
    } else {
        $ins = $conn->prepare("INSERT INTO contacts (company_id, name, first_name, last_name, phone, email, tags, group_id, source, status, last_message_at, created_at) VALUES (?,?,?,?,?,?,?,?, 'facebook', 'active', NOW(), NOW())");
        if ($ins) {
            $gid = $groupId;
            $ph = $phone !== '' ? $phone : '+0000000000';
            $ins->bind_param('issssssi', $companyId, $full, $first, $last, $ph, $email, $tags, $gid);
            $ins->execute();
            $contactId = (int)$conn->insert_id;
            $ins->close();
        }
    }

    // Insert a lead message into messages table (for audit)
    if ($contactId > 0) {
        $body = "FB Lead received.\nName: {$full}\nPhone: {$phone}\nEmail: {$email}\nLead ID: {$leadId}";
        $msgId = 'fb_' . $leadId;
        $dupe = $conn->prepare("SELECT id FROM messages WHERE company_id=? AND whatsapp_message_id=? LIMIT 1");
        $exists = false;
        if ($dupe) {
            $dupe->bind_param('is', $companyId, $msgId);
            $dupe->execute();
            $exists = (bool)$dupe->get_result()->fetch_assoc();
            $dupe->close();
        }
        if (!$exists) {
            $insM = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, media_url, is_read, status, whatsapp_message_id, created_at)
                VALUES (?, ?, 'incoming', 'text', ?, '', 0, 'received', ?, NOW())");
            if ($insM) {
                $insM->bind_param('iiss', $companyId, $contactId, $body, $msgId);
                $insM->execute();
                $insM->close();
            }
        }
    }

    // Optional auto WhatsApp reply (queued)
    $autoEnabled = (int)($company['fb_lead_auto_reply_enabled'] ?? 0) === 1;
    $autoMsg = (string)($company['fb_lead_auto_reply_message'] ?? '');
    if ($autoEnabled && $contactId > 0 && $phone !== '' && trim($autoMsg) !== '') {
        $finalMsg = fb_tpl_apply($autoMsg, [
            'name' => $full,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'email' => $email,
        ]);
        $finalMsg = trim($finalMsg);
        if ($finalMsg !== '') {
            $q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at) VALUES (?, ?, 'instant', ?, 'pending', NOW(), NOW())");
            if ($q) {
                $q->bind_param('iis', $companyId, $contactId, $finalMsg);
                $q->execute();
                $q->close();
            }
        }
    }
}

http_response_code(200);
echo 'OK';

