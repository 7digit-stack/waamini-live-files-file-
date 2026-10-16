<?php
require_once __DIR__ . '/../includes/client_auth_check.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!has_feature('drip_campaign') && !has_feature('feat_drip_campaign')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'feature_disabled']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_company']);
    exit;
}

require_once __DIR__ . '/../includes/whatsapp_api_helper.php';
require_once __DIR__ . '/../includes/drip_message_variator.php';
if (file_exists(__DIR__ . '/../services/WhatsAppService.php')) {
    require_once __DIR__ . '/../services/WhatsAppService.php';
}
if (file_exists(__DIR__ . '/../services/DripOptimizerService.php')) {
    require_once __DIR__ . '/../services/DripOptimizerService.php';
}

try {
    require_once __DIR__ . '/../includes/ai_crm_sync.php';
    crm_ai_process_due($conn, 3, $companyId);
    require_once __DIR__ . '/../includes/waamini_bot/bootstrap.php';
    WB_Guard::processFollowups($conn, 3, $companyId);
} catch (Throwable $e) {
    error_log('[drip_tick] crm ai: ' . $e->getMessage());
}

$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 25;

if (class_exists('WhatsAppService') && method_exists('WhatsAppService', 'getConfig')) {
    $cfg = WhatsAppService::getConfig($conn, $companyId);
} else {
    $stW = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1");
    $cfg = null;
    if ($stW) {
        $stW->bind_param('i', $companyId);
        $stW->execute();
        $cfg = $stW->get_result()->fetch_assoc();
        $stW->close();
        if ($cfg && function_exists('waamini_whatsapp_decrypt_config_token')) {
            $cfg['access_token'] = waamini_whatsapp_decrypt_config_token($cfg);
        }
    }
}

if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
    echo json_encode(['ok' => true, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'note' => 'missing_whatsapp_config']);
    exit;
}

$apiVer = (string)($cfg['api_version'] ?? ($_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v20.0'));
$_ENV['WHATSAPP_API_VERSION'] = $apiVer;

// Load per-contact cooldown config from campaign settings
$cooldownConfig = [];
$cdStmt = $conn->prepare(
    "SELECT id, cooldown_hours, max_messages_per_day FROM drip_campaigns WHERE company_id=? AND status='active'"
);
if ($cdStmt) {
    $cdStmt->bind_param('i', $companyId);
    $cdStmt->execute();
    $cdRes = $cdStmt->get_result();
    while ($cd = $cdRes->fetch_assoc()) {
        $cooldownConfig[(int)$cd['id']] = [
            'cooldown' => (int)($cd['cooldown_hours'] ?? 4),
            'max_per_day' => (int)($cd['max_messages_per_day'] ?? 10),
        ];
    }
    $cdStmt->close();
}

// Filter out contacts on cooldown: skip if sent a message within cooldown window
$cooldownContacts = [];
if (!empty($cooldownConfig)) {
    $minCooldown = min(array_column($cooldownConfig, 'cooldown'));
    $cooldownThreshold = date('Y-m-d H:i:s', strtotime("-{$minCooldown} hours"));
    $cdCheck = $conn->prepare(
        "SELECT DISTINCT e.contact_id FROM drip_executions e
         JOIN drip_steps s ON e.drip_step_id = s.id
         WHERE e.company_id = ?
           AND e.status = 'sent'
           AND e.sent_at >= ?
           AND s.drip_campaign_id IN (" . implode(',', array_keys($cooldownConfig)) . ")"
    );
    if ($cdCheck) {
        $cdCheck->bind_param('is', $companyId, $cooldownThreshold);
        $cdCheck->execute();
        $cdRes = $cdCheck->get_result();
        while ($cd = $cdRes->fetch_assoc()) {
            $cooldownContacts[(int)$cd['contact_id']] = true;
        }
        $cdCheck->close();
    }
}

// Claim rows atomically, skipping contacts on cooldown
$claimSql = "UPDATE drip_executions SET status='processing'
             WHERE company_id=?
               AND status='pending'
               AND scheduled_for <= NOW()";
if (!empty($cooldownContacts)) {
    $skipIds = implode(',', array_keys($cooldownContacts));
    $claimSql .= " AND contact_id NOT IN ($skipIds)";
}
$claimSql .= " LIMIT ?";

$claimStmt = $conn->prepare($claimSql);
if (!$claimStmt) {
    echo json_encode(['ok' => false, 'error' => 'claim_failed']);
    exit;
}
$claimStmt->bind_param('ii', $companyId, $limit);
$claimStmt->execute();
$claimed = $claimStmt->affected_rows;
$claimStmt->close();

if ($claimed <= 0) {
    echo json_encode(['ok' => true, 'processed' => 0, 'sent' => 0, 'failed' => 0]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT e.id AS execution_id, e.contact_id, e.drip_step_id, e.retry_count,
            e.message_variations, e.visited_variants,
            c.phone AS contact_phone, c.name AS contact_name,
            s.template_id, s.message_text, s.randomization_mode,
            dc.status AS campaign_status, dc.id AS campaign_id
     FROM drip_executions e
     JOIN contacts c ON e.contact_id = c.id AND c.company_id = e.company_id
     JOIN drip_steps s ON e.drip_step_id = s.id
     JOIN drip_campaigns dc ON s.drip_campaign_id = dc.id
     WHERE e.company_id = ? AND e.status = 'processing'
     ORDER BY e.scheduled_for ASC
     LIMIT ?"
);
if (!$stmt) {
    $conn->query("UPDATE drip_executions SET status='pending' WHERE company_id=$companyId AND status='processing'");
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
$stmt->bind_param('ii', $companyId, $limit);
$stmt->execute();
$res = $stmt->get_result();

$useWaService = class_exists('WhatsAppService');
$processed = $sent = $failed = 0;
$variantStats = [];

while ($row = $res->fetch_assoc()) {
    $processed++;
    $executionId  = (int)$row['execution_id'];
    $contactId    = (int)$row['contact_id'];
    $contactPhone = (string)$row['contact_phone'];
    $contactName  = (string)$row['contact_name'];
    $templateId   = (int)($row['template_id'] ?? 0);
    $campaignId   = (int)($row['campaign_id'] ?? 0);
    $variationsJson = (string)($row['message_variations'] ?? '[]');
    $retryCount   = (int)($row['retry_count'] ?? 0);

    if ($executionId <= 0 || $contactId <= 0 || $contactPhone === '') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='invalid_data' WHERE id=$executionId AND company_id=$companyId");
        $failed++;
        continue;
    }

    if (($row['campaign_status'] ?? '') !== 'active') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='campaign_inactive' WHERE id=$executionId AND company_id=$companyId");
        continue;
    }

    // Per-campaign cooldown check
    if (isset($cooldownConfig[$campaignId])) {
        $cdHrs = $cooldownConfig[$campaignId]['cooldown'];
        $cdThreshold = date('Y-m-d H:i:s', strtotime("-{$cdHrs} hours"));
        $cdStmt = $conn->prepare(
            "SELECT id FROM drip_executions WHERE company_id=? AND contact_id=? AND status='sent' AND sent_at >= ? LIMIT 1"
        );
        if ($cdStmt) {
            $cdStmt->bind_param('iis', $companyId, $contactId, $cdThreshold);
            $cdStmt->execute();
            if ($cdStmt->get_result()->num_rows > 0) {
                // Still on cooldown — skip and release
                $conn->query("UPDATE drip_executions SET status='pending' WHERE id=$executionId AND company_id=$companyId");
                $cdStmt->close();
                continue;
            }
            $cdStmt->close();
        }
    }

    $phoneLast10 = substr(preg_replace('/\D+/', '', $contactPhone), -10);
    if ($phoneLast10 !== '') {
        $blStmt = $conn->prepare("SELECT id FROM blacklist WHERE company_id=? AND phone LIKE ? LIMIT 1");
        if ($blStmt) {
            $blPat = '%' . $phoneLast10;
            $blStmt->bind_param("is", $companyId, $blPat);
            $blStmt->execute();
            if ($blStmt->get_result()->num_rows > 0) {
                $blStmt->close();
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='blacklisted' WHERE id=$executionId AND company_id=$companyId");
                continue;
            }
            $blStmt->close();
        }
    }

    $messageBody = '';
    $tplName     = '';
    $tplLang     = 'en_US';
    $tplWaStatus = '';
    $usedVariant = null;

    if ($templateId > 0) {
        $tStmt = $conn->prepare(
            "SELECT name, language, wa_status, body, body_text FROM message_templates WHERE id=? AND (company_id=? OR company_id IS NULL) LIMIT 1"
        );
        if ($tStmt) {
            $tStmt->bind_param('ii', $templateId, $companyId);
            $tStmt->execute();
            $tRow = $tStmt->get_result()->fetch_assoc();
            $tStmt->close();
            if ($tRow) {
                $tplName     = (string)($tRow['name'] ?? '');
                $tplLang     = (string)($tRow['language'] ?? 'en_US');
                $tplWaStatus = strtolower(trim((string)($tRow['wa_status'] ?? '')));
                $messageBody = (string)($tRow['body'] ?? $tRow['body_text'] ?? '');
            }
        }
    }

    $variants = json_decode($variationsJson, true) ?: [];
    if (empty($messageBody) && !empty($variants)) {
        $visited   = json_decode((string)($row['visited_variants'] ?? '[]'), true) ?: [];
        $nextIdx   = drip_next_variant_index($visited, $variants, $contactId, (int)($row['drip_step_id'] ?? 0));
        $usedVariant = $variants[$nextIdx] ?? null;
        if ($usedVariant) {
            $messageBody = str_replace(['{{1}}', '{{name}}', '{{contact_name}}'], $contactName ?: 'there', $usedVariant);
            $newVisited  = json_encode(array_values(array_unique(array_merge($visited, [$nextIdx]))), JSON_UNESCAPED_UNICODE);
            $conn->query(
                "UPDATE drip_executions
                 SET visited_variants='" . $conn->real_escape_string($newVisited) . "',
                     delivered_variant=$nextIdx
                 WHERE id=$executionId AND company_id=$companyId"
            );
            $key = "c{$campaignId}_s{$row['drip_step_id']}_v$nextIdx";
            $variantStats[$key] = ($variantStats[$key] ?? 0) + 1;
        }
    }

    if (empty($messageBody)) $messageBody = (string)($row['message_text'] ?? '');
    if (empty($usedVariant)) {
        $messageBody = str_replace(['{{1}}', '{{name}}', '{{contact_name}}'], $contactName ?: 'there', $messageBody);
    }
    $messageBody = trim($messageBody);

    if ($messageBody === '') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='empty_body' WHERE id=$executionId AND company_id=$companyId");
        $failed++;
        continue;
    }

    $waMsgId = null;
    $meta    = [];

    $isApprovedTpl = ($tplName !== '' && in_array($tplWaStatus, ['approved', 'approve'], true));
    if ($isApprovedTpl && function_exists('send_whatsapp_template')) {
        $maxIdx = 0;
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $messageBody, $m)) {
            foreach ($m[1] as $n) { $i = (int)$n; if ($i > $maxIdx) $maxIdx = $i; }
        }
        $params = [];
        for ($i = 1; $i <= $maxIdx; $i++) {
            $params[] = ['type' => 'text', 'text' => ($i === 1 && $contactName !== '') ? $contactName : 'there'];
        }
        $components = !empty($params) ? [['type' => 'body', 'parameters' => $params]] : [];
        $waMsgId = send_whatsapp_template($contactPhone, $tplName, $tplLang ?: 'en_US', $components, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], $meta);
    }

    if (!$waMsgId) $waMsgId = send_whatsapp_text($contactPhone, $messageBody, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], null, $meta);

    $now = date('Y-m-d H:i:s');
    if ($waMsgId) {
        $msgStmt = $conn->prepare(
            "INSERT INTO messages (company_id, contact_id, sender, direction, type, message, is_read, status, whatsapp_message_id, created_at)
             VALUES (?, ?, 'business', 'outgoing', 'text', ?, 1, 'sent', ?, ?)"
        );
        if ($msgStmt) {
            $msgStmt->bind_param("iisss", $companyId, $contactId, $messageBody, $waMsgId, $now);
            $msgStmt->execute();
            $msgStmt->close();
        }
        $conn->query("UPDATE drip_executions SET status='sent', sent_at='$now', error_msg=NULL WHERE id=$executionId AND company_id=$companyId");
        $sent++;
    } else {
        if ($retryCount < 3) {
            $nextRetry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $newRetry  = $retryCount + 1;
            $upd = $conn->prepare("UPDATE drip_executions SET status='pending', scheduled_for=?, retry_count=? WHERE id=? AND company_id=?");
            if ($upd) { $upd->bind_param('siii', $nextRetry, $newRetry, $executionId, $companyId); $upd->execute(); $upd->close(); }
        } else {
            $conn->query("UPDATE drip_executions SET status='failed', error_msg='max_retries' WHERE id=$executionId AND company_id=$companyId");
            $failed++;
        }
    }
}

$conn->query("UPDATE drip_executions SET status='pending' WHERE company_id=$companyId AND status='processing'");

echo json_encode([
    'ok'            => true,
    'processed'     => $processed,
    'sent'          => $sent,
    'failed'        => $failed,
    'variants_used' => $variantStats,
]);
