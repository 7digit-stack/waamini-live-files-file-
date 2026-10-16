<?php
/**
 * Subscription Expiration Alert Cron
 * Sends WhatsApp alerts to clients before their plan expires.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/whatsapp_api_helper.php';
require_once __DIR__ . '/includes/whatsapp_settings.php';

// Security: Only allow CLI or specific key
if (php_sapi_name() !== 'cli') {
    $cronKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '';
    $provided = $_GET['key'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        die('Unauthorized');
    }
}

// 1. Get Admin WhatsApp Config (from company_id = 1)
$stAdm = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id = 1 AND status = 'active' LIMIT 1");
$stAdm->execute();
$adminCfg = $stAdm->get_result()->fetch_assoc();

if (!$adminCfg) {
    die("Admin WhatsApp configuration not found.");
}

$accessToken = waamini_whatsapp_decrypt_config_token($adminCfg);
$phoneId = $adminCfg['phone_number_id'];

// 2. Find subscriptions nearing expiry
// Logic: (expires_at - alert_days_advance) <= NOW() 
// And last_alert_sent_at is NOT today.
$query = "
    SELECT s.*, c.name as company_name, p.name as plan_name
    FROM subscriptions s
    JOIN companies c ON s.company_id = c.id
    JOIN subscription_plans p ON s.plan_id = p.id
    WHERE s.status IN ('active', 'trial')
      AND s.reminder_enabled = 1
      AND s.whatsapp_number IS NOT NULL AND s.whatsapp_number != ''
      AND DATEDIFF(s.expires_at, NOW()) <= s.alert_days_advance
      AND DATEDIFF(s.expires_at, NOW()) >= 0
      AND (s.last_alert_sent_at IS NULL OR DATE(s.last_alert_sent_at) != CURDATE())
";

$res = $conn->query($query);
$sentCount = 0;

while ($sub = $res->fetch_assoc()) {
    $targetPhone = $sub['whatsapp_number'];
    $companyName = $sub['company_name'];
    $planName = $sub['plan_name'];
    $expiryDate = date('d M Y', strtotime($sub['expires_at']));
    $daysLeft = (int)round((strtotime($sub['expires_at']) - time()) / 86400);
    
    if ($daysLeft < 0) $daysLeft = 0;
    
    $message = "⚠️ *Subscription Alert: {$companyName}*\n\nYour *{$planName}* plan is set to expire in *{$daysLeft} days* (on {$expiryDate}).\n\nPlease renew soon to avoid any interruption in service.\n\n_Team Waamini_";
    
    // Send via Admin WA
    $result = send_whatsapp_text($targetPhone, $message, $accessToken, $phoneId);
    
    if ($result) {
        $subId = $sub['id'];
        $conn->query("UPDATE subscriptions SET last_alert_sent_at = NOW() WHERE id = $subId");
        $sentCount++;
        echo "Alert sent to {$companyName} ({$targetPhone})\n";
    } else {
        echo "Failed to send alert to {$companyName} ({$targetPhone})\n";
    }
}

echo "Total alerts sent: {$sentCount}\n";
