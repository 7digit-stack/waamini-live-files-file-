<?php
/**
 * Automated End-of-Day (EOD) Google Sheets Sync
 *
 * This file should be set to run via Cron at 11:59 PM server time daily:
 * 59 23 * * * php /path/to/waamini/cron_eod_sheets.php
 */

// CLI Guard — prevent direct HTTP access
if (PHP_SAPI !== 'cli') {
    $cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        exit('Unauthorized');
    }
}

require_once __DIR__ . '/db.php';
set_time_limit(55);
ini_set('memory_limit', '256M');

// Fetch all companies that have a Google Sheets Webhook configured
$result = $conn->query("SELECT id, name, google_sheet_webhook FROM companies WHERE google_sheet_webhook IS NOT NULL AND google_sheet_webhook != ''");

if (!$result || $result->num_rows === 0) {
    echo "No companies with Google Sheets integration found.
";
    exit();
}

$successCount = 0;
$failCount = 0;

while ($company = $result->fetch_assoc()) {
    $companyId = $company['id'];
    $webhookUrl = $company['google_sheet_webhook'];

    // Fetch contacts for this company that had interaction today
    $stmt = $conn->prepare("
        SELECT name, phone, status, chat_labels as labels, last_message_at 
        FROM contacts 
        WHERE company_id = ? 
        AND DATE(last_message_at) = CURDATE()
    ");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $contactsRes = $stmt->get_result();

    $payload = [];
    while ($contact = $contactsRes->fetch_assoc()) {
        $payload[] = [
            'name' => $contact['name'],
            'phone' => $contact['phone'],
            'status' => $contact['status'] ?: 'unassigned',
            'labels' => $contact['labels'],
            'last_message_at' => $contact['last_message_at']
        ];
    }

    if (count($payload) > 0) {
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); // Use custom request to maintain method
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WAAMINI-CRM-Sync/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $responseStr = (string)$response;
        $isSuccess = ($httpCode == 200 || $httpCode == 302 || strpos($responseStr, 'Success') !== false);
        $status = $isSuccess ? 'Success' : 'Failed';
        $desc = $isSuccess ? "Synced " . count($payload) . " contacts." : "Failed: HTTP $httpCode " . substr((string)$response, 0, 100) . ($curlError ? " Error: $curlError" : "");

        // Update company sync status
        $upd = $conn->prepare("UPDATE companies SET last_gsheet_sync = NOW(), last_gsheet_status = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("si", $status, $companyId);
            $upd->execute();
            $upd->close();
        }

        // Log to system_logs
        $logAction = "Google Sheets Sync";
        $logStmt = $conn->prepare("INSERT INTO system_logs (action, description, created_at) VALUES (?, ?, NOW())");
        if ($logStmt) {
            $logFullDesc = "Company: {$company['name']} ($companyId) - " . $desc;
            $logStmt->bind_param("ss", $logAction, $logFullDesc);
            $logStmt->execute();
            $logStmt->close();
        }

        if ($isSuccess) {
            $successCount++;
            echo "Successfully synced " . count($payload) . " contacts for company: {$company['name']} ($companyId)
";
        }
        else {
            $failCount++;
            echo "Failed to sync for company: {$company['name']} ($companyId). Status: $status
";
        }
    }
    else {
        echo "No active contacts today to sync for company: {$company['name']} ($companyId)
";
    }
}

echo "
--- Sync Complete ---
";
echo "Success: $successCount, Failed: $failCount
";
?>

