<?php
/**
 * WAAMINI Cart Recovery Cron — runs every 30 mins
 * Schedule: Every 30 minutes
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(60);
require_once __DIR__ . '/db.php';
set_time_limit(55);
ini_set('memory_limit', '256M');
$lockFile = sys_get_temp_dir() . '/waamini_cart.lock';
$lock = fopen($lockFile,'w'); if (!flock($lock,LOCK_EX|LOCK_NB)) die("Running
");

$ver_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='wa_default_api_version' LIMIT 1");
$apiVersion = ($ver_res && $r=$ver_res->fetch_assoc()) ? $r['setting_value'] : 'v18.0';

$now = date('Y-m-d H:i:s');
$sent = 0;

// Fetch carts needing message 1
$conn->query("
    UPDATE abandoned_carts ac
    JOIN cart_recovery_settings cs ON ac.company_id=cs.company_id
    SET ac.recovery_status='send1_pending'
    WHERE ac.recovery_status='pending'
    AND TIMESTAMPDIFF(MINUTE, ac.created_at, NOW()) >= cs.delay_minutes
");

foreach (['send1_pending'=>['message1','sent1',0], 'sent1'=>['message2','sent2',120], 'sent2'=>['message3','sent3',360]] as $fromStatus => [$msgField, $toStatus, $minDelay]) {
        if (!in_array($msgField, ['message1', 'message2', 'message3'], true)) {
            continue;
        }

        $sql = "
        SELECT ac.*, cs.$msgField as msg_template, cs.discount_code, cs.discount_pct,
               c.phone, c.name as contact_name, ak.access_token, ak.phone_number_id,
               co.name as business_name
        FROM abandoned_carts ac
        JOIN cart_recovery_settings cs ON ac.company_id=cs.company_id
        JOIN contacts c ON ac.contact_id=c.id
        JOIN api_keys ak ON ak.company_id=ac.company_id AND ak.is_active=1
        JOIN companies co ON ac.company_id=co.id
        WHERE ac.recovery_status=?";
        $types = 's';
        $params = [$fromStatus];
        if ($minDelay > 0) {
            $sql .= " AND TIMESTAMPDIFF(MINUTE, ac.updated_at, NOW()) >= ?";
            $types .= 'i';
            $params[] = $minDelay;
        }
        $sql .= " LIMIT 50";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $carts = $stmt->get_result();

    while ($cart = $carts->fetch_assoc()) {
        $cartData = json_decode($cart['cart_data']??'{}', true);
        $checkoutUrl = $cartData['checkout_url'] ?? '';
        $firstName = explode(' ', $cart['contact_name']??'Friend')[0];
        $msg = str_replace(
            ['{{first_name}}','{{name}}','{{business_name}}','{{cart_total}}','{{checkout_url}}','{{discount_code}}','{{discount_pct}}'],
            [$firstName, $cart['contact_name']??'Friend', $cart['business_name']??'', number_format($cart['cart_total'],2), $checkoutUrl, $cart['discount_code']??'', $cart['discount_pct']??10],
            $cart['msg_template'] ?? "Hi {{first_name}}! You left items in your cart. Complete your order: {{checkout_url}}"
        );

        $payload = json_encode(['messaging_product'=>'whatsapp','to'=>$cart['phone'],'type'=>'text','text'=>['body'=>$msg]]);
        $ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$cart['phone_number_id']}/messages");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cart['access_token'],'Content-Type: application/json']]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

        if ($code===200) {
            $stmt_upd = $conn->prepare("UPDATE abandoned_carts SET recovery_status=?, updated_at=NOW() WHERE id=?");
            $stmt_upd->bind_param("si", $toStatus, $cart['id']);
            $stmt_upd->execute(); $sent++;
        }
        usleep(300000);
    }
}

flock($lock,LOCK_UN); fclose($lock);
echo "Done. Sent: $sent
";


