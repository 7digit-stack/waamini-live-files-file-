<?php
/**
 * Campaign tick endpoint (session-authenticated).
 *
 * Purpose: process pending campaign_queue items without relying on system cron in local/dev.
 * Safe in production too (requires authenticated session).
 */
require_once __DIR__ . '/../includes/client_auth_check.php';

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!has_feature('campaigns') && !has_feature('feat_campaigns')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'feature_disabled']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0 && ($isCronBypass ?? false)) {
    $companyId = (int)($_GET['company_id'] ?? 0);
}

if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_company']);
    exit;
}

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 25;

require_once __DIR__ . '/../services/WhatsAppService.php';
require_once __DIR__ . '/../includes/whatsapp_api_helper.php';

// Ensure tracking columns exist (safe/idempotent)
foreach ([
    "ALTER TABLE campaign_queue ADD COLUMN wa_phone_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN whatsapp_message_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_status VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_ts DATETIME DEFAULT NULL",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// Validate campaign ownership when provided
if ($campaignId > 0) {
    $chk = $conn->prepare("SELECT id FROM campaigns WHERE id=? AND company_id=? LIMIT 1");
    if (!$chk) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
        exit;
    }
    $chk->bind_param('ii', $campaignId, $companyId);
    $chk->execute();
    $ok = $chk->get_result()->num_rows > 0;
    $chk->close();
    if (!$ok) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'campaign_not_found']);
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT id, campaign_id, contact_id, phone, wa_phone_id, message_payload
    FROM campaign_queue
    WHERE company_id=?
      AND status='pending'
      AND scheduled_at <= NOW()
      " . ($campaignId > 0 ? " AND campaign_id=?" : "") . "
    ORDER BY id ASC
    LIMIT ?
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
if ($campaignId > 0) {
    $stmt->bind_param('iii', $companyId, $campaignId, $limit);
} else {
    $stmt->bind_param('ii', $companyId, $limit);
}
$stmt->execute();
$res = $stmt->get_result();

$processed = 0;
$sent = 0;
$failed = 0;

while ($row = $res->fetch_assoc()) {
    $processed++;
    $qid = (int)($row['id'] ?? 0);
    $phone = (string)($row['phone'] ?? '');
    $payload = json_decode((string)($row['message_payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    if ($qid <= 0 || $phone === '' || !is_array($payload)) {
        continue;
    }

    // Lock row
    $lock = $conn->prepare("UPDATE campaign_queue SET status='processing' WHERE id=? AND company_id=? AND status='pending'");
    if ($lock) {
        $lock->bind_param('ii', $qid, $companyId);
        $lock->execute();
        if ((int)$conn->affected_rows !== 1) {
            continue;
        }
        $lock->close();
    }

    $tplName = (string)($payload['template_name'] ?? '');
    $tplLang = (string)($payload['template_language'] ?? 'en_US');
    $headerType = strtolower((string)($payload['header_type'] ?? ''));
    $headerLink = (string)($payload['header_link'] ?? '');

    // Build components
    $components = [];
    if ($headerType !== '' && $headerLink !== '') {
        $param = null;
        if ($headerType === 'image') $param = ['type' => 'image', 'image' => ['link' => $headerLink]];
        elseif ($headerType === 'video') $param = ['type' => 'video', 'video' => ['link' => $headerLink]];
        elseif ($headerType === 'document') $param = ['type' => 'document', 'document' => ['link' => $headerLink]];
        if ($param) $components[] = ['type' => 'header', 'parameters' => [$param]];
    }
    // Body params MUST match expected template variable count
    $maxVars = 0;
    try {
        if ($tplName !== '') {
            $stTpl = $conn->prepare("SELECT body, body_text FROM message_templates WHERE name=? AND (company_id=? OR company_id IS NULL) AND (language=? OR language IS NULL OR language='') ORDER BY (company_id IS NULL) ASC, id DESC LIMIT 1");
            if ($stTpl) {
                $stTpl->bind_param('sis', $tplName, $companyId, $tplLang);
                $stTpl->execute();
                $trow = $stTpl->get_result()->fetch_assoc();
                $bodyT = (string)($trow['body'] ?? $trow['body_text'] ?? '');
                if ($bodyT !== '' && preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $bodyT, $m)) {
                    foreach ($m[1] as $n) {
                        $v = (int)$n;
                        if ($v > $maxVars) $maxVars = $v;
                    }
                }
                $stTpl->close();
            }
        }
    } catch (\Throwable $e) {}

    $bodyParams = [];
    $maxVars = max(0, min(10, (int)$maxVars));
    if ($maxVars > 0) {
        for ($i = 1; $i <= $maxVars; $i++) {
            $k = "field_{$i}";
            $val = (string)($payload[$k] ?? '');
            if (trim($val) === '') $val = '-';
            $bodyParams[] = ['type' => 'text', 'text' => $val];
        }
    } else {
        for ($i = 1; $i <= 10; $i++) {
            $k = "field_{$i}";
            if (isset($payload[$k]) && (string)$payload[$k] !== '') {
                $bodyParams[] = ['type' => 'text', 'text' => (string)$payload[$k]];
            }
        }
    }
    if (!empty($bodyParams)) {
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];
    }
    // WhatsApp Form (Flow) button: pass a token so the submitted answers link back to the form.
    if (!empty($payload['flow_token'])) {
        $components[] = ['type' => 'button', 'sub_type' => 'flow', 'index' => (string)(int)($payload['flow_button_index'] ?? 0),
            'parameters' => [['type' => 'action', 'action' => ['flow_token' => (string)$payload['flow_token']]]]];
    }

    // Use central WA config (decrypt token)
    $cfg = WhatsAppService::getConfig($conn, $companyId);
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
        $err = 'missing_whatsapp_config';
        $fail = $conn->prepare("UPDATE campaign_queue SET status='failed', error_message=? WHERE id=? AND company_id=?");
        if ($fail) {
            $fail->bind_param('sii', $err, $qid, $companyId);
            $fail->execute();
            $fail->close();
        }
        $failed++;
        continue;
    }

    // Respect per-row phone id override when present
    $phoneId = (string)($row['wa_phone_id'] ?? '');
    if ($phoneId === '') {
        $phoneId = (string)($cfg['phone_number_id'] ?? '');
    }

    $meta = [];
    $waMsgId = null;
    if ($tplName !== '') {
        $waMsgId = send_whatsapp_template($phone, $tplName, $tplLang, $components, (string)$cfg['access_token'], $phoneId, $meta);
    }

    if ($waMsgId) {
        $up = $conn->prepare("UPDATE campaign_queue SET status='sent', whatsapp_message_id=?, wa_delivery_status=NULL, wa_delivery_ts=NULL WHERE id=? AND company_id=?");
        if ($up) {
            $up->bind_param('sii', $waMsgId, $qid, $companyId);
            $up->execute();
            $up->close();
        }
        $sent++;
    } else {
        $errMsg = '';
        $httpCode = isset($meta['http_code']) ? (int)$meta['http_code'] : null;
        $resp = (string)($meta['response'] ?? '');
        if ($resp !== '') {
            $jd = json_decode($resp, true);
            if (is_array($jd) && isset($jd['error']['message'])) {
                $errMsg = (string)$jd['error']['message'];
            }
        }
        if ($errMsg === '' && !empty($meta['curl_error'])) {
            $errMsg = (string)$meta['curl_error'];
        }
        if ($errMsg === '') $errMsg = 'WhatsApp API error';
        if ($httpCode) $errMsg = "HTTP {$httpCode}: " . $errMsg;

        $fail = $conn->prepare("UPDATE campaign_queue SET status='failed', error_message=? WHERE id=? AND company_id=?");
        if ($fail) {
            $fail->bind_param('sii', $errMsg, $qid, $companyId);
            $fail->execute();
            $fail->close();
        }
        $failed++;
    }
}

// After processing, update campaign aggregates and finalize status when queue is empty.
if ($campaignId > 0) {
    try {
        // Update aggregate counts from campaign_queue (source of truth)
        $upd = $conn->prepare("
            UPDATE campaigns c
            SET
              c.sent_count = (SELECT COUNT(*) FROM campaign_queue q WHERE q.company_id=c.company_id AND q.campaign_id=c.id AND q.status='sent'),
              c.failed_count = (SELECT COUNT(*) FROM campaign_queue q WHERE q.company_id=c.company_id AND q.campaign_id=c.id AND q.status='failed'),
              c.expired_count = (SELECT COUNT(*) FROM campaign_queue q WHERE q.company_id=c.company_id AND q.campaign_id=c.id AND q.status='expired')
            WHERE c.id=? AND c.company_id=?
        ");
        if ($upd) {
            $upd->bind_param('ii', $campaignId, $companyId);
            $upd->execute();
            $upd->close();
        }

        // Complete campaign when nothing left pending/processing
        $done = $conn->prepare("
            UPDATE campaigns c
            SET c.status='completed', c.completed_at=NOW()
            WHERE c.id=? AND c.company_id=? AND c.status='running'
              AND (SELECT COUNT(*) FROM campaign_queue q WHERE q.company_id=c.company_id AND q.campaign_id=c.id AND q.status IN ('pending','processing')) = 0
              AND (SELECT COUNT(*) FROM campaign_queue q WHERE q.company_id=c.company_id AND q.campaign_id=c.id) > 0
        ");
        if ($done) {
            $done->bind_param('ii', $campaignId, $companyId);
            $done->execute();
            $done->close();
        }
    } catch (\Throwable $e) {
        // best-effort
    }
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'sent' => $sent,
    'failed' => $failed,
]);

