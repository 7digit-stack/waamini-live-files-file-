<?php
/**
 * WAAMINI – IndiaMART Pull API Sync (Polling)
 * Fetches new leads from IndiaMART and imports into contacts.
 *
 * Official Pull API base:
 *   https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=...
 *
 * Run via:
 * - CLI: php cron_indiamart_sync.php
 * - HTTP: /waamini/cron_indiamart_sync.php?key=CRON_SECRET_KEY
 */

set_time_limit(55);
ini_set('memory_limit', '256M');

require __DIR__ . '/db.php';
require_once __DIR__ . '/services/WhatsAppService.php';

$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    $cronKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '';
    $provided = $_GET['key'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
}

function im_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (php_sapi_name() === 'cli') echo $line . "
";
    error_log('[WAAMINI IM] ' . $msg);
}

// Schema: store imported leads for de-dupe and audit trail
try {
    $conn->query("CREATE TABLE IF NOT EXISTS indiamart_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        unique_query_id VARCHAR(80) NOT NULL,
        query_time DATETIME NULL,
        sender_name VARCHAR(150) DEFAULT NULL,
        sender_mobile VARCHAR(30) DEFAULT NULL,
        sender_email VARCHAR(150) DEFAULT NULL,
        query_type VARCHAR(20) DEFAULT NULL,
        query_message TEXT DEFAULT NULL,
        raw_json LONGTEXT DEFAULT NULL,
        contact_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_query (company_id, unique_query_id),
        INDEX idx_company_time (company_id, query_time),
        INDEX idx_company_contact (company_id, contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// Add missing columns to companies (safe)
foreach ([
    "ALTER TABLE companies ADD COLUMN im_auto_reply TINYINT(1) DEFAULT 0",
    "ALTER TABLE companies ADD COLUMN im_auto_reply_msg TEXT DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN im_last_sync_at DATETIME DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN im_last_sync_status VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE contacts ADD COLUMN source VARCHAR(50) DEFAULT NULL",
] as $sql) {
    try { $conn->query($sql); } catch (\Throwable $e) { /* ignore */ }
}

// Fetch companies with IndiaMART configured
$res = $conn->query("SELECT id, im_crm_key, im_mobile, im_auto_reply, im_auto_reply_msg, im_last_sync_at
    FROM companies
    WHERE im_crm_key IS NOT NULL AND im_crm_key!='' AND im_mobile IS NOT NULL AND im_mobile!=''
    LIMIT 50");

if (!$res) {
    im_log('No companies to sync (query failed).');
    exit;
}

while ($co = $res->fetch_assoc()) {
    $companyId = (int)$co['id'];
    $key = (string)$co['im_crm_key'];
    $mobile = preg_replace('/[^0-9]/', '', (string)$co['im_mobile']);
    $autoReply = ((int)($co['im_auto_reply'] ?? 0) === 1);
    $autoMsg = trim((string)($co['im_auto_reply_msg'] ?? ''));
    if ($autoMsg === '') {
        $autoMsg = "Thanks for your enquiry! We received your request on IndiaMART. How can we help you today?";
    }

    // Use API without date params to fetch delta (IndiaMART returns since last hit, within 24h)
    $url = "https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=" . urlencode($key);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErr) {
        $msg = "cURL error: " . $curlErr;
        $st = $conn->prepare("UPDATE companies SET im_last_sync_at=NOW(), im_last_sync_status=? WHERE id=?");
        if ($st) { $st->bind_param('si', $msg, $companyId); $st->execute(); }
        im_log("Company {$companyId} sync failed: {$msg}");
        continue;
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        $msg = "Invalid JSON (HTTP {$http})";
        $st = $conn->prepare("UPDATE companies SET im_last_sync_at=NOW(), im_last_sync_status=? WHERE id=?");
        if ($st) { $st->bind_param('si', $msg, $companyId); $st->execute(); }
        im_log("Company {$companyId} sync failed: {$msg}");
        continue;
    }

    // Failure codes from IndiaMART
    if (isset($json['STATUS']) && strtoupper((string)$json['STATUS']) === 'FAILURE') {
        $msg = (string)($json['MESSAGE'] ?? 'IndiaMART failure');
        $st = $conn->prepare("UPDATE companies SET im_last_sync_at=NOW(), im_last_sync_status=? WHERE id=?");
        if ($st) { $st->bind_param('si', $msg, $companyId); $st->execute(); }
        im_log("Company {$companyId} IndiaMART API failure: {$msg}");
        continue;
    }

    $items = $json['RESPONSE'] ?? $json['response'] ?? [];
    if (!is_array($items)) $items = [];

    $imported = 0;
    foreach ($items as $lead) {
        if (!is_array($lead)) continue;
        $uqid = (string)($lead['UNIQUE_QUERY_ID'] ?? $lead['unique_query_id'] ?? '');
        if ($uqid === '') continue;

        // de-dupe via unique index
        $qTimeRaw = (string)($lead['QUERY_TIME'] ?? '');
        $qTime = null;
        if ($qTimeRaw !== '') {
            $ts = strtotime($qTimeRaw);
            if ($ts !== false) $qTime = date('Y-m-d H:i:s', $ts);
        }
        $senderName = (string)($lead['SENDER_NAME'] ?? 'IndiaMART Buyer');
        $senderMobile = (string)($lead['SENDER_MOBILE'] ?? '');
        $senderEmail  = (string)($lead['SENDER_EMAIL'] ?? '');
        $qType = (string)($lead['QUERY_TYPE'] ?? '');
        $qMsg  = (string)($lead['QUERY_MESSAGE'] ?? ($lead['QUERY_MESSAGE_1'] ?? ''));

        // Normalize phone to +<digits> if present
        $phoneDigits = preg_replace('/[^0-9]/', '', $senderMobile);
        $phonePlus = $phoneDigits !== '' ? ('+' . $phoneDigits) : '';

        // Upsert contact if we have phone
        $contactId = null;
        if ($phonePlus !== '') {
            $sel = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND (phone=? OR phone=?) LIMIT 1");
            if ($sel) {
                $plain = ltrim($phonePlus, '+');
                $sel->bind_param('iss', $companyId, $phonePlus, $plain);
                $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                if ($row) {
                    $contactId = (int)$row['id'];
                    // Update source to indiamart if it's a new lead from there
                    $updSource = $conn->prepare("UPDATE contacts SET source='indiamart' WHERE id=? AND company_id=?");
                    if ($updSource) {
                        $updSource->bind_param('ii', $contactId, $companyId);
                        $updSource->execute();
                    }
                }
            }
            if (!$contactId) {
                $ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name, source, status, created_at) VALUES (?, ?, ?, 'indiamart', 'active', NOW())");
                if ($ins) {
                    $safeName = $senderName !== '' ? $senderName : 'IndiaMART Buyer';
                    $ins->bind_param('iss', $companyId, $phonePlus, $safeName);
                    $ins->execute();
                    $contactId = (int)$conn->insert_id;
                }
            }
        }

        // Insert lead record (ignore duplicates)
        $rawJson = json_encode($lead, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT IGNORE INTO indiamart_leads
            (company_id, unique_query_id, query_time, sender_name, sender_mobile, sender_email, query_type, query_message, raw_json, contact_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        if ($stmt) {
            $qt = $qTime;
            $cid = $contactId ? (int)$contactId : null;
            $stmt->bind_param(
                'issssssssi',
                $companyId,
                $uqid,
                $qt,
                $senderName,
                $senderMobile,
                $senderEmail,
                $qType,
                $qMsg,
                $rawJson,
                $cid
            );
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $imported++;

                // Log inbound-like message into messages (optional, helps WhatsApp inbox context)
                if ($contactId) {
                    $inText = "IndiaMART Lead
Type: {$qType}
" . ($qMsg !== '' ? $qMsg : 'New enquiry received');
                    $msgIns = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, status, created_at) VALUES (?, ?, 'incoming', 'text', ?, 'received', NOW())");
                    if ($msgIns) {
                        $msgIns->bind_param('iis', $companyId, $contactId, $inText);
                        $msgIns->execute();
                    }
                    $upd = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=? AND company_id=?");
                    if ($upd) { $upd->bind_param('ii', $contactId, $companyId); $upd->execute(); }

                    // Auto-reply via WhatsApp
                    if ($autoReply) {
                        $to = preg_replace('/[^0-9]/', '', $phonePlus);
                        if ($to !== '') {
                            $waId = WhatsAppService::sendText($conn, $companyId, $to, $autoMsg);
                            if ($waId) {
                                $outIns = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, status, whatsapp_message_id, created_at)
                                    VALUES (?, ?, 'outgoing', 'text', ?, 'sent', ?, NOW())");
                                if ($outIns) { $outIns->bind_param('iiss', $companyId, $contactId, $autoMsg, $waId); $outIns->execute(); }
                            }
                        }
                    }
                }
            }
        }
    }

    $status = "ok_imported_" . $imported;
    $st = $conn->prepare("UPDATE companies SET im_last_sync_at=NOW(), im_last_sync_status=? WHERE id=?");
    if ($st) { $st->bind_param('si', $status, $companyId); $st->execute(); }

    im_log("Company {$companyId}: imported {$imported} lead(s)");
}


