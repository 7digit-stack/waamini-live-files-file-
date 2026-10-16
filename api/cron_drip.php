<?php
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

$cronKey = getenv('WAAMINI_CRON_KEY') ?: '';
// WAAMINI_QUEUE_WORKER is only defined by our own code (local inline processor in smart_router.php,
// queue worker), never from a request, so those internal runs do not need the cron key.
if (PHP_SAPI !== 'cli' && !defined('WAAMINI_QUEUE_WORKER')) {
    $provided = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden: cron key required']);
        exit;
    }
}

set_time_limit(180);
ini_set('memory_limit', '512M');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/whatsapp_api_helper.php';
require_once dirname(__DIR__) . '/includes/drip_message_variator.php';
if (file_exists(dirname(__DIR__) . '/services/WhatsAppService.php')) {
    require_once dirname(__DIR__) . '/services/WhatsAppService.php';
}
if (file_exists(dirname(__DIR__) . '/services/DripOptimizerService.php')) {
    require_once dirname(__DIR__) . '/services/DripOptimizerService.php';
}

$claimed = $conn->query("UPDATE drip_executions SET status='processing' WHERE status='pending' AND scheduled_for <= NOW() LIMIT 200");
$claimedCount = $claimed ? $conn->affected_rows : 0;

$processedCount = 0;
$sentCount      = 0;
$failedCount    = 0;
$skippedCount   = 0;
$variantStats   = [];

if ($claimedCount <= 0) {
    echo json_encode(['ok' => true, 'claimed' => 0, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'variants_used' => []]);
    exit;
}

$useWaService = class_exists('WhatsAppService');

$stmt = $conn->prepare(
    "SELECT e.id AS execution_id, e.company_id, e.contact_id, e.drip_step_id, e.retry_count,
            e.message_variations, e.visited_variants,
            c.phone AS contact_phone, c.name AS contact_name,
            s.template_id, s.message_text, s.randomization_mode,
            dc.status AS campaign_status, dc.id AS campaign_id
     FROM drip_executions e
     JOIN contacts c ON e.contact_id = c.id AND c.company_id = e.company_id
     JOIN drip_steps s ON e.drip_step_id = s.id
     JOIN drip_campaigns dc ON s.drip_campaign_id = dc.id
     WHERE e.status = 'processing'
     ORDER BY e.scheduled_for ASC
     LIMIT 200"
);

if (!$stmt) {
    error_log('[WAAMINI cron_drip] prepare failed: ' . $conn->error);
    $conn->query("UPDATE drip_executions SET status='pending' WHERE status='processing'");
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
$stmt->execute();
$executions = $stmt->get_result();

$cfgCache = [];

while ($row = $executions->fetch_assoc()) {
    $processedCount++;
    $executionId   = (int)($row['execution_id'] ?? 0);
    $companyId     = (int)($row['company_id'] ?? 0);
    $contactId     = (int)($row['contact_id'] ?? 0);
    $contactPhone  = (string)($row['contact_phone'] ?? '');
    $contactName   = (string)($row['contact_name'] ?? '');
    $templateId    = (int)($row['template_id'] ?? 0);
    $campaignId    = (int)($row['campaign_id'] ?? 0);
    $variationsJson = (string)($row['message_variations'] ?? '[]');
    $retryCount    = (int)($row['retry_count'] ?? 0);

    if ($executionId <= 0 || $companyId <= 0 || $contactId <= 0 || $contactPhone === '') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='invalid_data' WHERE id=$executionId");
        $failedCount++;
        continue;
    }

    if (($row['campaign_status'] ?? '') !== 'active') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='campaign_inactive' WHERE id=$executionId");
        $skippedCount++;
        continue;
    }

    $phoneLast10 = substr(preg_replace('/\D+/', '', $contactPhone), -10);
    if ($phoneLast10 !== '') {
        $blStmt = $conn->prepare("SELECT id FROM blacklist WHERE company_id=? AND phone LIKE ? LIMIT 1");
        if ($blStmt) {
            $blPat = '%' . $phoneLast10;
            $blStmt->bind_param('is', $companyId, $blPat);
            $blStmt->execute();
            if ($blStmt->get_result()->num_rows > 0) {
                $blStmt->close();
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='blacklisted' WHERE id=$executionId");
                $skippedCount++;
                continue;
            }
            $blStmt->close();
        }
    }

    $hasFeature = false;
    $planStmt = $conn->prepare(
        "SELECT sp.feat_drip_campaign
         FROM subscriptions sub
         JOIN subscription_plans sp ON sub.plan_id = sp.id
         WHERE sub.company_id = ?
         AND sub.status IN ('active','trial')
         AND (sub.expires_at >= NOW() OR sub.expires_at IS NULL)
         LIMIT 1"
    );
    if ($planStmt) {
        $planStmt->bind_param('i', $companyId);
        $planStmt->execute();
        $planRes = $planStmt->get_result()->fetch_assoc();
        $hasFeature = ($planRes && ($planRes['feat_drip_campaign'] ?? 0) == 1);
        $planStmt->close();
    }
    if (!$hasFeature) {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='no_feature_access' WHERE id=$executionId");
        $skippedCount++;
        continue;
    }

    if (!isset($cfgCache[$companyId])) {
        if ($useWaService) {
            $cfgCache[$companyId] = WhatsAppService::getConfig($conn, $companyId);
        } else {
            $stW = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1");
            if ($stW) {
                $stW->bind_param('i', $companyId);
                $stW->execute();
                $waCfg = $stW->get_result()->fetch_assoc();
                $stW->close();
                if ($waCfg && function_exists('waamini_whatsapp_decrypt_config_token')) {
                    $waCfg['access_token'] = waamini_whatsapp_decrypt_config_token($waCfg);
                }
                $cfgCache[$companyId] = $waCfg ?: null;
            }
        }
    }
    $cfg = $cfgCache[$companyId];
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='missing_wa_config' WHERE id=$executionId");
        $failedCount++;
        continue;
    }

    $messageBody = '';
    $tplName     = '';
    $tplLang     = 'en_US';
    $tplWaStatus = '';
    $usedVariant = null;

    if ($templateId > 0) {
        $tStmt = $conn->prepare(
            "SELECT name, language, wa_status, body, body_text
             FROM message_templates
             WHERE id=? AND (company_id=? OR company_id IS NULL)
             LIMIT 1"
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
            $messageBody = str_replace(
                ['{{1}}', '{{name}}', '{{contact_name}}'],
                $contactName ?: 'there',
                $usedVariant
            );
            $newVisited = json_encode(
                array_values(array_unique(array_merge($visited, [$nextIdx]))),
                JSON_UNESCAPED_UNICODE
            );
            $conn->query(
                "UPDATE drip_executions
                 SET visited_variants='" . $conn->real_escape_string($newVisited) . "',
                     delivered_variant=$nextIdx
                 WHERE id=$executionId"
            );
            $key = 'c' . $campaignId . '_s' . $row['drip_step_id'] . '_v' . $nextIdx;
            $variantStats[$key] = ($variantStats[$key] ?? 0) + 1;
        }
    } elseif (empty($messageBody)) {
        $messageBody = (string)($row['message_text'] ?? '');
    }

    if (empty($usedVariant)) {
        $messageBody = str_replace(
            ['{{1}}', '{{name}}', '{{contact_name}}'],
            $contactName ?: 'there',
            $messageBody
        );
    }
    $messageBody = trim($messageBody);

    if ($messageBody === '') {
        $conn->query("UPDATE drip_executions SET status='failed', error_msg='empty_body' WHERE id=$executionId");
        $failedCount++;
        continue;
    }

    $apiVer = $cfg['api_version'] ?? $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v20.0';
    $_ENV['WHATSAPP_API_VERSION'] = $apiVer;

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

        if ($useWaService) {
            $waMsgId = WhatsAppService::sendTemplate(
                $conn, $companyId, $contactPhone, $tplName, $tplLang ?: 'en_US', $components, $executionId
            );
        } else {
            $waMsgId = send_whatsapp_template(
                $contactPhone, $tplName, $tplLang ?: 'en_US',
                $components, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], $meta
            );
        }
    }

    if (!$waMsgId) {
        if ($useWaService) {
            $waMsgId = WhatsAppService::sendText($conn, $companyId, $contactPhone, $messageBody, $executionId);
        } else {
            $waMsgId = send_whatsapp_text(
                $contactPhone, $messageBody,
                (string)$cfg['access_token'], (string)$cfg['phone_number_id'], null, $meta
            );
        }
    }

    $now = date('Y-m-d H:i:s');
    if ($waMsgId) {
        $msgStmt = $conn->prepare(
            "INSERT INTO messages
             (company_id, contact_id, sender, direction, type, message, is_read, status, whatsapp_message_id, created_at)
             VALUES (?, ?, 'business', 'outgoing', 'text', ?, 1, 'sent', ?, ?)"
        );
        if ($msgStmt) {
            $msgStmt->bind_param('iisss', $companyId, $contactId, $messageBody, $waMsgId, $now);
            $msgStmt->execute();
            $msgStmt->close();
        }
        $conn->query(
            "UPDATE drip_executions
             SET status='sent', sent_at='$now', error_msg=NULL, retry_count=0, send_attempted_at='$now'
             WHERE id=$executionId"
        );
        $sentCount++;
    } else {
        $errMsg = '';
        if (is_array($meta) && !empty($meta['response'])) {
            $jd = json_decode((string)$meta['response'], true);
            if (is_array($jd) && isset($jd['error']['message'])) $errMsg = (string)$jd['error']['message'];
        }
        if ($errMsg === '' && !empty($meta['curl_error'])) $errMsg = (string)$meta['curl_error'];
        $errMsgEsc = $conn->real_escape_string($errMsg ?: 'send_failed');

        if ($retryCount < 3) {
            $nextRetry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $newRetry  = $retryCount + 1;
            $conn->query(
                "UPDATE drip_executions
                 SET status='pending', scheduled_for='$nextRetry', retry_count=$newRetry,
                     error_msg='$errMsgEsc', send_attempted_at='$now'
                 WHERE id=$executionId"
            );
        } else {
            $conn->query(
                "UPDATE drip_executions
                 SET status='failed', error_msg='$errMsgEsc', send_attempted_at='$now'
                 WHERE id=$executionId"
            );
            $failedCount++;
        }
    }
}

$conn->query("UPDATE drip_executions SET status='pending' WHERE status='processing'");

echo json_encode([
    'ok'            => true,
    'claimed'       => $claimedCount,
    'processed'     => $processedCount,
    'sent'          => $sentCount,
    'failed'        => $failedCount,
    'skipped'       => $skippedCount,
    'variants_used' => $variantStats,
], JSON_UNESCAPED_UNICODE);
