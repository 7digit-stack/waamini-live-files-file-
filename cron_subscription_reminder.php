<?php
/**
 * WAAMINI - Subscription WhatsApp Reminder Cron
 * 
 * This script checks for subscriptions expiring in 2 days
 * and sends WhatsApp reminders to clients.
 * 
 * Setup: Run this via Windows Task Scheduler or cron every day
 * Command: php C:\xampp\htdocs\waamini\cron_subscription_reminder.php
 * 
 * Or visit: http://localhost:8080/waamini/cron_subscription_reminder.php?key=YOUR_SECRET_KEY
 */

include 'db.php';
require_once __DIR__ . '/includes/whatsapp_settings.php';

ini_set('memory_limit', '256M');

// Security: Allow CLI or require env-based secret key for web access
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    $secretKey = getenv('WAAMINI_CRON_KEY') ?: '';
    $provided  = $_GET['key'] ?? '';
    if (empty($secretKey) || !hash_equals($secretKey, $provided)) {
        http_response_code(403);
        die('Access denied.');
    }
}

$log = [];
$log[] = "🕐 Reminder cron started at " . date('Y-m-d H:i:s');

// ─── 1. Find subscriptions expiring in exactly 2 days ───
$twoDaysLater = date('Y-m-d', strtotime('+2 days'));
$today = date('Y-m-d');

// SECURITY: Use prepared statement instead of string interpolation
$stmt_exp = $conn->prepare("
    SELECT s.*, p.name as plan_name, c.name as company_name
    FROM subscriptions s
    JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN companies c ON s.company_id = c.id
    WHERE s.status IN ('active', 'trial')
    AND s.reminder_enabled = 1
    AND s.whatsapp_number IS NOT NULL
    AND s.whatsapp_number != ''
    AND s.expires_at = ?
    AND (s.reminder_sent_at IS NULL OR s.reminder_sent_at < ?)
");
$stmt_exp->bind_param("ss", $twoDaysLater, $today);
$stmt_exp->execute();
$expiring = $stmt_exp->get_result();

if (!$expiring || $expiring->num_rows === 0) {
    $log[] = "✅ No subscriptions expiring in 2 days. No reminders to send.";
}
else {
    $log[] = "📋 Found " . $expiring->num_rows . " subscription(s) expiring on $twoDaysLater";

    $sent = 0;
    $failed = 0;

    while ($sub = $expiring->fetch_assoc()) {
        $phone = $sub['whatsapp_number'];
        $planName = $sub['plan_name'];
        $companyName = $sub['company_name'] ?? 'Customer';
        $expiryDate = date('d M Y', strtotime($sub['expires_at']));
        $amount = number_format($sub['amount']);

        // Build WhatsApp message
        $message = "🔔 *WAAMINI - Subscription Renewal Reminder*\n\n"
            . "Hi *{$companyName}*,\n\n"
            . "Your *{$planName}* plan is expiring on *{$expiryDate}* (2 days from now).\n\n"
            . "📋 *Plan Details:*\n"
            . "• Plan: {$planName}\n"
            . "• Amount: ₹{$amount}\n"
            . "• Billing: " . ucfirst($sub['billing_cycle']) . "\n"
            . "• Expires: {$expiryDate}\n\n"
            . "🔄 *Renew now* to avoid service interruption!\n\n"
            . "For renewal, contact:\n"
            . "📞 +91 9150802906 | 9003876516\n"
            . "📧 info@7digit.in\n\n"
            . "Thank you for choosing WAAMINI! 🚀";

        // ─── Send via WhatsApp API ───
        $sendResult = send_whatsapp_reminder($phone, $message, $conn);

        if ($sendResult) {
            // Mark reminder as sent — SECURITY: use prepared statement
            $stmt_upd = $conn->prepare("UPDATE subscriptions SET reminder_sent_at=NOW() WHERE id=?");
            $stmt_upd->bind_param("i", $sub['id']);
            $stmt_upd->execute();

            // Log the reminder
            $logMsg = "Expiry reminder sent for plan: {$planName}";
            $stmtLog = $conn->prepare("INSERT INTO subscription_reminders (subscription_id, phone, message, status, sent_at) VALUES (?, ?, ?, 'sent', NOW())");
            $stmtLog->bind_param("iss", $sub['id'], $phone, $logMsg);
            $stmtLog->execute();

            $log[] = "  ✅ Sent to {$phone} ({$companyName} - {$planName})";
            $sent++;
        }
        else {
            // Log failed attempt
            $logMsg = "Failed to send expiry reminder for plan: {$planName}";
            $stmtLog = $conn->prepare("INSERT INTO subscription_reminders (subscription_id, phone, message, status, sent_at) VALUES (?, ?, ?, 'failed', NOW())");
            $stmtLog->bind_param("iss", $sub['id'], $phone, $logMsg);
            $stmtLog->execute();

            $log[] = "  ❌ Failed for {$phone} ({$companyName})";
            $failed++;
        }
    }

    $log[] = "\n📊 Summary: $sent sent, $failed failed";
}

// ─── 2. Also check for expired subscriptions (mark as expired) ───
// SECURITY: Use prepared statement
$stmt_expired = $conn->prepare("UPDATE subscriptions SET status='expired' WHERE status='active' AND expires_at < ?");
$stmt_expired->bind_param("s", $today);
$stmt_expired->execute();
$affectedRows = $conn->affected_rows;
if ($affectedRows > 0) {
    $log[] = "⏰ Auto-expired $affectedRows subscription(s) past due date.";
}

$log[] = "\n✅ Cron completed at " . date('Y-m-d H:i:s');

// Output
$output = implode("\n", $log);

if ($isCLI) {
    echo $output . "\n";
}
else {
    echo "<pre style='font-family:monospace; background:#1a1a2e; color:#e0e0e0; padding:20px; border-radius:10px;'>" . htmlspecialchars($output) . "</pre>";
}

/**
 * Send WhatsApp message via API using Admin System Account
 */
function send_whatsapp_reminder($phone, $message, $conn)
{
    // Fetch system tokens
    $settings = waamini_whatsapp_load_system_settings($conn);

    $token      = $settings['system_wa_token'] ?? '';
    $phoneId    = $settings['system_wa_phone_id'] ?? '';
    $apiVersion = $settings['wa_default_api_version'] ?? 'v18.0'; // FIX: was v17.0 (EOL May 2024)

    if (empty($token) || empty($phoneId)) {
        return false; // System WA not configured
    }

    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneId}/messages";
    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode == 200 && strpos($response, '"id"') !== false);
}
?>
