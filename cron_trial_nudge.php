<?php
/**
 * WAAMINI Trial-to-Paid Conversion Nudge
 * Schedule: 0 10 * * *  (daily at 10am)
 * Sends WhatsApp + email nudge to companies on trial expiring in 1-2 days
 */

$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) {
        http_response_code(403); die('Forbidden');
    }
}

set_time_limit(120);
require_once __DIR__ . '/db.php';
set_time_limit(55);
ini_set('memory_limit', '256M');
require_once __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/includes/mail_settings.php';
require_once __DIR__ . '/includes/whatsapp_settings.php';

use PHPMailer\PHPMailer\PHPMailer;

$sent = 0;

// Find trial subscriptions expiring in 2 days
$twoDays = date('Y-m-d', strtotime('+2 days'));
$stmt = $conn->prepare("
    SELECT s.id, s.expires_at, s.company_id,
           c.name as company_name,
           u.email, u.name as contact_name,
           p.name as plan_name, p.monthly_price,
           c.phone as whatsapp_phone
    FROM subscriptions s
    JOIN companies c ON s.company_id = c.id
    JOIN users u ON u.company_id = c.id AND u.role = 'client'
    JOIN subscription_plans p ON s.plan_id = p.id
    WHERE s.status = 'trial'
      AND s.expires_at = ?
    ORDER BY s.id ASC
");
$stmt->bind_param("s", $twoDays);
$stmt->execute();
$trials = $stmt->get_result();

// SMTP settings
$smtp = waamini_mail_load_settings($conn);
$mailConfig = waamini_mail_resolve_config($smtp);

// WA system settings
$waSystemSettings = waamini_whatsapp_load_system_settings($conn);
$apiVersion = (string)($waSystemSettings['wa_default_api_version'] ?? 'v18.0');
$sysWa = [
    'system_wa_token' => (string)($waSystemSettings['system_wa_token'] ?? ''),
    'system_wa_phone_id' => (string)($waSystemSettings['system_wa_phone_id'] ?? ''),
];

$appUrl = $smtp['app_url'] ?? '/waamini';

while ($trial = $trials->fetch_assoc()) {
    $expiresFormatted = date('d M Y', strtotime($trial['expires_at']));
    $name = $trial['contact_name'];
    $email = $trial['email'];
    $price = number_format($trial['monthly_price'], 2);

    // 1. Send Email Nudge
    if (!empty($email)) {
        try {
            $mail = new PHPMailer(true);
            waamini_mail_apply_config($mail, $mailConfig);
            if ($mailConfig['transport'] !== 'sendmail' && (empty($mailConfig['host']) || empty($mailConfig['username']) || empty($mailConfig['password']))) {
                throw new Exception('Email transport not configured');
            }
            $mail->setFrom($mailConfig['from_email'] ?? 'support@waamini.com', $mailConfig['from_name'] ?? 'WAAMINI');
            if (!empty($mailConfig['reply_to'])) {
                $mail->addReplyTo($mailConfig['reply_to']);
            }
            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $mail->Subject = "⏰ Your trial expires in 2 days — Keep your access!";
            $mail->Body = "
<div style='font-family:sans-serif;max-width:500px;margin:0 auto;'>
<h2>Hi $name,</h2>
<p>Your <strong>WAAMINI trial</strong> expires on <strong>$expiresFormatted</strong>.</p>
<p>Don't lose your contacts, campaigns, and automation — upgrade now to keep everything!</p>
<a href='$appUrl/client/subscription.php' style='display:inline-block;background:#25d366;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;margin:16px 0;'>Upgrade Now →</a>
<p style='font-size:13px;color:#666;'>Your current plan: {$trial['plan_name']} — ₹$price/month</p>
</div>";
            $mail->send();
        } catch (Exception $e) {
            error_log("[WAAMINI Trial Nudge] Email failed to $email: " . $e->getMessage());
        }
    }

    // 2. Send WhatsApp Nudge (via system WA account)
    if (!empty($sysWa['system_wa_token']) && !empty($trial['whatsapp_phone'])) {
        $waMsg = "Hi $name 👋

Your WAAMINI trial expires on *$expiresFormatted*.

Upgrade now to keep your contacts, campaigns & automations running! 🚀

👉 $appUrl/client/subscription.php";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $trial['whatsapp_phone'],
            'type' => 'text',
            'text' => ['body' => $waMsg],
        ]);
        $ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$sysWa['system_wa_phone_id']}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $sysWa['system_wa_token'],
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    $sent++;
}

$conn->query("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('cron_trial_nudge_last_run', NOW(), 'cron') ON DUPLICATE KEY UPDATE setting_value=NOW()");
error_log("[WAAMINI Trial Nudge] Processed: $sent trials");
echo "Done. Nudged: $sent trial accounts
";


