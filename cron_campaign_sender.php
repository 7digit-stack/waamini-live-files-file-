<?php
/**
 * WAAMINI - Campaign Queue Background Sender (Daemon)
 * Hardened against SQLi, MITM, cURL failures, and WhatsApp API rate limits (Anti-Ban)
 * 
 * Rate Limiting Strategy:
 * - Default: 15 messages/second (safe zone, well below Meta's 20-80 MPS)
 * - User-configurable via system_settings: "campaign_send_rate_limit_mps"
 * - Prevents account flagging for spam/abuse
 */

set_time_limit(55); // Prevent overlaps if run every minute via cron
ignore_user_abort(true);

$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waamini_campaign_sender.lock';
$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another campaign sender is already running\n";
    exit;
}

require __DIR__ . '/db.php';
require_once __DIR__ . '/services/EncryptionService.php';
require_once __DIR__ . '/includes/whatsapp_settings.php';

// Load rate limit config (anti-ban protection)
$rateLimitMPS = 15; // Default: 15 messages per second (safe)
try {
    $rlStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'campaign_send_rate_limit_mps'");
    if ($rlStmt) {
        $rlStmt->execute();
        $rlRes = $rlStmt->get_result();
        if ($rlRow = $rlRes->fetch_assoc()) {
            $parsedRate = (int)($rlRow['setting_value'] ?? 15);
            $rateLimitMPS = max(1, min(100, $parsedRate)); // Clamp: 1-100 MPS
        }
        $rlStmt->close();
    }
} catch (\Throwable $e) {
    error_log("[WAAMINI Campaign Sender] Rate limit config error: " . $e->getMessage());
}

// Calculate delay per message (in microseconds) to achieve desired MPS
// Example: 15 MPS = 1/15 = 0.0667 seconds = 66,666 microseconds per message
$delayPerMessageUS = (int)(1000000 / $rateLimitMPS);
$rateLimitNotes = "Anti-ban: Sending at {$rateLimitMPS} msg/sec (delay: " . round($delayPerMessageUS / 1000, 1) . "ms per msg)";

// Backwards-compat: older DBs may not have these columns yet (safe/idempotent)
foreach ([
    "ALTER TABLE campaign_queue ADD COLUMN wa_phone_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN whatsapp_message_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_status VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_ts DATETIME DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN retry_count INT DEFAULT 0",
    "ALTER TABLE campaign_queue ADD COLUMN retry_after DATETIME DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN last_error_message VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE campaigns ADD COLUMN expired_count INT DEFAULT 0",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// Allow CLI, secret key, or admin-triggered execution
 $isCLI = (php_sapi_name() === 'cli');
 $isAdminTrigger = defined('WAAMINI_ADMIN_CRON_TRIGGER') && WAAMINI_ADMIN_CRON_TRIGGER === true;
ini_set('memory_limit', '256M');

if (!$isCLI && !$isAdminTrigger) {
    $cronKey = getenv('WAAMINI_CRON_KEY') ?: '';
    $provided = $_GET['key'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
}

function log_output($msg) {
    global $isCLI;
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg" . ($isCLI ? "\n" : "<br>\n");
    echo $line;
}

log_output("Starting Queue Worker Batch...");
log_output($rateLimitNotes);

 $limit = 60;

// Start transaction to lock rows
 $conn->begin_transaction();

try {
    $sql = "SELECT q.*,
                   (SELECT wc.access_token FROM whatsapp_config wc
                     WHERE wc.company_id = q.company_id
                       AND (q.wa_phone_id IS NULL OR q.wa_phone_id = '' OR wc.phone_number_id = q.wa_phone_id)
                     ORDER BY wc.updated_at DESC, wc.id DESC
                     LIMIT 1) as whatsapp_token,
                   (SELECT wc.phone_number_id FROM whatsapp_config wc
                     WHERE wc.company_id = q.company_id
                       AND (q.wa_phone_id IS NULL OR q.wa_phone_id = '' OR wc.phone_number_id = q.wa_phone_id)
                     ORDER BY wc.updated_at DESC, wc.id DESC
                     LIMIT 1) as whatsapp_phone_id,
                   (SELECT wc.api_version FROM whatsapp_config wc
                     WHERE wc.company_id = q.company_id
                       AND (q.wa_phone_id IS NULL OR q.wa_phone_id = '' OR wc.phone_number_id = q.wa_phone_id)
                     ORDER BY wc.updated_at DESC, wc.id DESC
                     LIMIT 1) as api_version
            FROM campaign_queue q
                WHERE (q.status = 'pending' AND q.scheduled_at <= NOW())
                    OR (q.status = 'failed' AND q.retry_count < 3 AND (q.retry_after IS NULL OR q.retry_after <= NOW())) 
                ORDER BY q.id ASC
                LIMIT ? FOR UPDATE SKIP LOCKED";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $conn->commit();
        log_output("No pending messages ready in the queue. Exiting.");
        if (is_resource($lockHandle)) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
        if ($isAdminTrigger) return; else exit();
    }

    $messagesToProcess = [];
    // SECURITY FIX: Force integers to prevent SQL injection during implode
    $idsToUpdate = [];

    while ($row = $result->fetch_assoc()) {
        $messagesToProcess[] = $row;
        $idsToUpdate[] = (int)$row['id']; // Cast to integer strictly
    }

    // Mark as processing immediately
    if (!empty($idsToUpdate)) {
        // SECURITY FIX: Use safe placeholder generation instead of raw implode
        $placeholders = implode(',', array_fill(0, count($idsToUpdate), '?'));
        $types = str_repeat('i', count($idsToUpdate));
        
        $updStmt = $conn->prepare("UPDATE campaign_queue SET status = 'processing' WHERE id IN ($placeholders)");
        if (!$updStmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $updStmt->bind_param($types, ...$idsToUpdate);
        $updStmt->execute();
    }

    $conn->commit();
    log_output("Locked " . count($messagesToProcess) . " messages for processing.");

    $successfulCount = 0;

    foreach ($messagesToProcess as $msgJob) {
        $msgId = (int)$msgJob['id'];
        $campaignId = (int)$msgJob['campaign_id'];
        $companyId = (int)$msgJob['company_id'];
        $token = trim($msgJob['whatsapp_token'] ?? '');
        if ($token !== '' && function_exists('decrypt_secret')) {
            $token = decrypt_secret($token) ?? $token;
        }
        $phoneId = trim($msgJob['whatsapp_phone_id'] ?? '');
        
        // DYNAMIC FIX: Use version from DB config, fallback to .env, fallback to v18.0
        $apiVer = $msgJob['api_version'] ?? $_ENV['WHATSAPP_API_VERSION'] ?? 'v18.0';

        // Fallback: if no company-level whatsapp_config, try master system settings
        if (empty($token) || empty($phoneId)) {
            log_output("Job $msgId: No company WhatsApp config found (company_id=$companyId). Trying system settings...");
            $sysSettings = waamini_whatsapp_load_system_settings($conn);
            $sysToken = (string)($sysSettings['system_wa_token'] ?? '');
            $sysPhoneId = (string)($sysSettings['system_wa_phone_id'] ?? '');
            $sysApiVer = (string)($sysSettings['wa_default_api_version'] ?? '');
            if (!empty($sysToken) && !empty($sysPhoneId)) {
                $token = trim($sysToken);
                $phoneId = trim($sysPhoneId);
                $apiVer = $sysApiVer ?: $apiVer;
                log_output("Job $msgId: Using system master WhatsApp config (phone_id=$phoneId).");
            }
        }

        if (empty($token) || empty($phoneId)) {
            $errMsg = 'WhatsApp API not configured for company AND no system master config found';
            $stmt_fail = $conn->prepare("UPDATE campaign_queue SET status = 'failed', error_message = ? WHERE id = ?");
            if ($stmt_fail) {
                $stmt_fail->bind_param("si", $errMsg, $msgId);
                $stmt_fail->execute();
            }
            log_output("Job $msgId failed: $errMsg");
            continue;
        }

        $payloadData = json_decode($msgJob['message_payload'], true);
        $headerTypeChk = strtolower((string)($payloadData['header_type'] ?? ''));
        $headerLinkChk = (string)($payloadData['header_link'] ?? '');
        if ($headerTypeChk !== '' && $headerLinkChk !== '') {
            $isHttps = (stripos($headerLinkChk, 'https://') === 0);
            $isLocal = (stripos($headerLinkChk, 'http://localhost') === 0) || (stripos($headerLinkChk, 'http://127.0.0.1') === 0);
            if (!$isHttps || $isLocal) {
                $msg = 'Header media URL must be a public HTTPS link (not localhost).';
                $stmt_fail = $conn->prepare("UPDATE campaign_queue SET status = 'failed', error_message = ? WHERE id = ?");
                if ($stmt_fail) {
                    $stmt_fail->bind_param("si", $msg, $msgId);
                    $stmt_fail->execute();
                }
                log_output("Job $msgId failed: $msg");
                continue;
            }
        }

        $graphPayload = [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $payloadData['phone_number'] ?? ''),
            'type' => 'template',
            'template' => [
                'name' => $payloadData['template_name'] ?? '',
                'language' => ['code' => $payloadData['template_language'] ?? 'en']
            ]
        ];

        $components = [];
        // Header media support (image/video/document) — required if template has a media header
        $headerType = strtolower((string)($payloadData['header_type'] ?? ''));
        $headerLink = (string)($payloadData['header_link'] ?? '');
        if ($headerType !== '' && $headerLink !== '') {
            $param = null;
            if ($headerType === 'image') {
                $param = ['type' => 'image', 'image' => ['link' => $headerLink]];
            } elseif ($headerType === 'video') {
                $param = ['type' => 'video', 'video' => ['link' => $headerLink]];
            } elseif ($headerType === 'document') {
                $param = ['type' => 'document', 'document' => ['link' => $headerLink]];
            }
            if ($param) {
                $components[] = ['type' => 'header', 'parameters' => [$param]];
            }
        }

        // Body variables support (field_1..field_10)
        // IMPORTANT: WhatsApp requires the exact number of params expected by the template.
        // If we send fewer, Meta returns (#132000) "Number of parameters does not match expected".
        $maxVars = 0;
        try {
            $tn = (string)($payloadData['template_name'] ?? '');
            $tl = (string)($payloadData['template_language'] ?? '');
            if ($tn !== '') {
                $stTpl = $conn->prepare("SELECT body, body_text FROM message_templates WHERE name=? AND (company_id=? OR company_id IS NULL) " . ($tl !== '' ? "AND language=?" : "") . " ORDER BY (company_id IS NULL) ASC, id DESC LIMIT 1");
                if ($stTpl) {
                    if ($tl !== '') {
                        $stTpl->bind_param('sis', $tn, $companyId, $tl);
                    } else {
                        $stTpl->bind_param('si', $tn, $companyId);
                    }
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

        $body_parameters = [];
        $maxVars = max(0, min(10, (int)$maxVars));
        if ($maxVars > 0) {
            for ($i = 1; $i <= $maxVars; $i++) {
                $k = "field_{$i}";
                $val = (string)($payloadData[$k] ?? '');
                if (trim($val) === '') {
                    // Never send fewer params; use a safe placeholder
                    $val = '-';
                }
                $body_parameters[] = ['type' => 'text', 'text' => $val];
            }
        } else {
            // Back-compat fallback: send only non-empty fields
            for ($i = 1; $i <= 10; $i++) {
                $k = "field_{$i}";
                if (isset($payloadData[$k]) && $payloadData[$k] !== '') {
                    $body_parameters[] = ['type' => 'text', 'text' => (string)$payloadData[$k]];
                }
            }
        }
        if (!empty($body_parameters)) {
            $components[] = ['type' => 'body', 'parameters' => $body_parameters];
        }
        // WhatsApp Form (Flow) button: pass a token so the submitted answers link back to the form.
        if (!empty($payloadData['flow_token'])) {
            $components[] = ['type' => 'button', 'sub_type' => 'flow', 'index' => (string)(int)($payloadData['flow_button_index'] ?? 0),
                'parameters' => [['type' => 'action', 'action' => ['flow_token' => (string)$payloadData['flow_token']]]]];
        }

        if (!empty($components)) {
            $graphPayload['template']['components'] = $components;
        }

        // SECURITY FIX: Explicit SSL Verification & Dynamic Version
        $url = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer " . $token,
                "Content-Type: application/json"
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($graphPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            // CRITICAL SECURITY: Prevent MITM attacks on your token
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch); // Capture cURL errors
        curl_close($ch);

        // ROBUSTNESS FIX: Handle cURL network failures safely
        if ($response === false || !empty($curlError)) {
            $stmt_fail = $conn->prepare("UPDATE campaign_queue SET status = 'failed', error_message = ? WHERE id = ?");
            if ($stmt_fail) {
            $stmt_fail->bind_param("si", 'cURL Error: ' . $curlError, $msgId);
            $stmt_fail->execute();
            }
            log_output("Job $msgId -> cURL Error: $curlError");
            continue;
        }

        $responseObj = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($responseObj['messages'][0]['id'])) {
            $waMsgId = (string)$responseObj['messages'][0]['id'];
            $stmt_sent = $conn->prepare("UPDATE campaign_queue SET status='sent', whatsapp_message_id=?, wa_delivery_status=NULL, wa_delivery_ts=NULL, processed_at=NOW() WHERE id = ?");
            if ($stmt_sent) {
                $stmt_sent->bind_param("si", $waMsgId, $msgId);
                $stmt_sent->execute();
            }

            if ($campaignId) {
                $stmt_camp = $conn->prepare("UPDATE campaigns SET sent_count = sent_count + 1 WHERE id = ?");
                if ($stmt_camp) {
                    $stmt_camp->bind_param("i", $campaignId);
                    $stmt_camp->execute();
                }
            }
            $successfulCount++;
            log_output("Job $msgId -> HTTP $httpCode -> Sent successfully.");
        } else {
            $errorMsg = $responseObj['error']['message'] ?? 'Unknown API Error';
            
            // Retry logic: up to 3 attempts with delays (5 min, 30 min, 2 hours)
            $retryCount = (int)($row['retry_count'] ?? 0);
            $nextRetryTime = null;
            $newStatus = 'failed'; // Default if max retries exceeded
            
            if ($retryCount < 3) {
                // Calculate next retry time based on retry_count
                $retryDelays = [
                    0 => '5 minutes',    // First retry after 5 min
                    1 => '30 minutes',   // Second retry after 30 min
                    2 => '2 hours'       // Third retry after 2 hours
                ];
                $delayStr = $retryDelays[$retryCount] ?? '5 minutes';
                $nextRetryTime = date('Y-m-d H:i:s', strtotime("now + {$delayStr}"));
                $newStatus = 'failed'; // Keep as failed but will retry
                
                $stmt_retry = $conn->prepare("UPDATE campaign_queue 
                    SET status = 'failed', 
                        retry_count = retry_count + 1, 
                        retry_after = ?,
                        last_error_message = ?
                    WHERE id = ?");
                if ($stmt_retry) {
                    $stmt_retry->bind_param("ssi", $nextRetryTime, $errorMsg, $msgId);
                    $stmt_retry->execute();
                    log_output("Job $msgId -> HTTP $httpCode -> Failed (Retry $retryCount/3, next in $delayStr): $errorMsg");
                }
            } else {
                // Max retries exceeded - permanently failed
                $stmt_fail = $conn->prepare("UPDATE campaign_queue 
                    SET status = 'failed', 
                        last_error_message = ?
                    WHERE id = ?");
                if ($stmt_fail) {
                    $stmt_fail->bind_param("si", $errorMsg, $msgId);
                    $stmt_fail->execute();
                    log_output("Job $msgId -> HTTP $httpCode -> Permanently failed (max retries exceeded): $errorMsg");
                }
            }
        }

        // ANTI-BAN: Rate limiting - sleep to respect WhatsApp API limits
        // This prevents account flagging for spam when sending bulk messages
        if ($delayPerMessageUS > 0) {
            usleep($delayPerMessageUS); // microseconds
        }
    }

    // Mark finished campaigns as completed
    $conn->query("UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE status = 'running' AND id IN (
        SELECT campaign_id FROM (
            SELECT campaign_id FROM campaigns c2
            WHERE c2.sent_count >= c2.total_contacts AND c2.total_contacts > 0
        ) AS tmp
    )");
    
    $conn->query("UPDATE campaigns c SET status='completed', completed_at=NOW()
        WHERE c.status='running'
        AND (SELECT COUNT(*) FROM campaign_queue q WHERE q.campaign_id=c.id AND q.status='pending') = 0
        AND (SELECT COUNT(*) FROM campaign_queue q WHERE q.campaign_id=c.id) > 0
    ");

    log_output("Worker Batch Completed. Successfully sent $successfulCount out of " . count($messagesToProcess) . " messages.");

} catch (Exception $e) {
    $conn->rollback();
    log_output("Worker Critical Error: " . $e->getMessage());
}
?>