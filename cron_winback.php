<?php
/**
 * WAAMINI Win-back Campaign
 * Schedule: 0 11 * * 1  (every Monday at 11am)
 * Re-engages contacts inactive for 30+ days
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(55);
ini_set('memory_limit', '256M');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/whatsapp_settings.php';
$lockFile = sys_get_temp_dir() . '/waamini_winback.lock';
$lock = fopen($lockFile,'w'); if (!flock($lock,LOCK_EX|LOCK_NB)) die("Running
");

$ver_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='wa_default_api_version' LIMIT 1");
$apiVersion = ($ver_res && $r=$ver_res->fetch_assoc()) ? $r['setting_value'] : 'v18.0';

$sent = 0;

// Companies with win-back switched on and a connected WhatsApp number (credentials live in whatsapp_config, token encrypted)
$stmt_co = $conn->prepare("
    SELECT c.id as company_id, c.name as business_name,
           wb.message AS winback_message, wb.inactive_days,
           wa.access_token, wa.phone_number_id
    FROM companies c
    JOIN winback_settings wb ON wb.company_id=c.id AND wb.is_active=1
    JOIN whatsapp_config wa ON wa.company_id=c.id AND wa.status='active'
    WHERE c.status='active' AND wa.phone_number_id <> '' AND wa.access_token <> ''
");
if (!$stmt_co) {
    error_log('[WAAMINI winback] company query failed: ' . $conn->error);
    flock($lock, LOCK_UN); fclose($lock);
    exit(1);
}
$stmt_co->execute();
$companies = $stmt_co->get_result();

while ($company = $companies->fetch_assoc()) {
    $cid = $company['company_id'];
    $company['access_token'] = waamini_whatsapp_decrypt_config_token($company);
    if ($company['access_token'] === '') continue;
    $inactiveDays = max(7, (int)($company['inactive_days'] ?? 30));

    // Find contacts inactive N+ days (company setting, default 30), not blocked, not already re-engaged in 60 days
    $stmt_ct = $conn->prepare("
        SELECT id, name, phone FROM contacts
        WHERE company_id=?
          AND status NOT IN ('blocked','unsubscribed')
          AND (last_message_at IS NULL OR last_message_at < DATE_SUB(NOW(), INTERVAL ? DAY))
          AND (winback_sent_at IS NULL OR winback_sent_at < DATE_SUB(NOW(), INTERVAL 60 DAY))
          AND opted_in=1
        LIMIT 200
    ");
    $stmt_ct->bind_param("ii", $cid, $inactiveDays);
    $stmt_ct->execute();
    $contacts = $stmt_ct->get_result();

    $defaultMsg = "Hey {{first_name}}! 👋 We miss you at *{{business_name}}*!

It's been a while — come back and check what's new! 🎁

Reply *HI* to chat with us.";
    $msgTemplate = $company['winback_message'] ?: $defaultMsg;

    while ($ct = $contacts->fetch_assoc()) {
        $firstName = explode(' ', $ct['name'])[0];
        $msg = str_replace(['{{first_name}}','{{name}}','{{business_name}}'],[$firstName,$ct['name'],$company['business_name']], $msgTemplate);

        $payload = json_encode(['messaging_product'=>'whatsapp','to'=>$ct['phone'],'type'=>'text','text'=>['body'=>$msg]]);
        $ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$company['phone_number_id']}/messages");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$company['access_token'],'Content-Type: application/json']]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

        if ($code===200) {
            // Migration handled schema change
            $stmt_upd = $conn->prepare("UPDATE contacts SET winback_sent_at=NOW() WHERE id=?");
            $stmt_upd->bind_param("i", $ct['id']);
            $stmt_upd->execute();
            $sent++;
        }
        usleep(300000);
    }
}

// Win-back settings table
// Migration handled schema change

$conn->query("INSERT INTO system_settings (setting_key,setting_value,setting_group) VALUES ('cron_winback_last_run',NOW(),'cron') ON DUPLICATE KEY UPDATE setting_value=NOW()");
flock($lock,LOCK_UN); fclose($lock);
echo "Done. Sent: $sent
";




