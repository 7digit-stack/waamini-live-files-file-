<?php
/** WAAMINI Review Request Cron — Schedule: 0 11 * * * */
$cronKey=getenv('WAAMINI_CRON_KEY')?:'';
if(PHP_SAPI!=='cli'){$p=$_GET['key']??'';if(!$cronKey||!hash_equals($cronKey,$p)){http_response_code(403);die('Forbidden');}}
set_time_limit(120); ini_set('memory_limit', '256M'); require_once __DIR__.'/db.php'; require_once __DIR__.'/includes/whatsapp_settings.php';
$lockFile=sys_get_temp_dir().'/waamini_review.lock';
$lock=fopen($lockFile,'w');if(!flock($lock,LOCK_EX|LOCK_NB))die("Running
");
$sent=0;
$stmt=$conn->query("SELECT rs.*,co.name as biz_name,wc.access_token,wc.phone_number_id,wc.api_version FROM review_settings rs JOIN companies co ON rs.company_id=co.id JOIN whatsapp_config wc ON wc.company_id=rs.company_id AND wc.status='active' WHERE rs.is_active=1");
while($rs=$stmt->fetch_assoc()){
    $cid=$rs['company_id'];$days=intval($rs['send_after_days']??1);
    $stmt_o=$conn->prepare("SELECT DISTINCT o.contact_id FROM ecommerce_orders o WHERE o.company_id=? AND o.status='paid' AND DATE(o.created_at)=DATE_SUB(CURDATE(),INTERVAL ? DAY) AND o.contact_id NOT IN (SELECT contact_id FROM review_requests WHERE company_id=?) LIMIT 100");
    $stmt_o->bind_param("iii",$cid,$days,$cid);$stmt_o->execute();$orders=$stmt_o->get_result();
    while($o=$orders->fetch_assoc()){
        $stmt_ct=$conn->prepare("SELECT name,phone FROM contacts WHERE id=? AND company_id=?");
        $stmt_ct->bind_param("ii",$o['contact_id'],$cid);$stmt_ct->execute();
        $ct=$stmt_ct->get_result()->fetch_assoc();if(!$ct)continue;
        $firstName=explode(' ',$ct['name'])[0];
        $msg=str_replace(['{{first_name}}','{{business_name}}','{{review_url}}'],[$firstName,$rs['biz_name'],$rs['google_review_url']??''],$rs['message']??"Hi {$firstName}! 😊 Thank you for your order from *{$rs['biz_name']}*!

⭐ Could you leave us a Google review?
👉 {$rs['google_review_url']}

Thank you! 🙏");
        $apiVer=$rs['api_version']?:'v18.0';
        $accessToken = waamini_whatsapp_decrypt_config_token($rs);
        $payload=json_encode(['messaging_product'=>'whatsapp','to'=>$ct['phone'],'type'=>'text','text'=>['body'=>$msg]]);
        $ch=curl_init("https://graph.facebook.com/{$apiVer}/{$rs['phone_number_id']}/messages");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Content-Type: application/json']]);
        $r=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($code===200){$stmt_ins=$conn->prepare("INSERT IGNORE INTO review_requests (company_id,contact_id,sent_at) VALUES (?,?,NOW())");$stmt_ins->bind_param("ii",$cid,$o['contact_id']);$stmt_ins->execute();$sent++;}
        usleep(300000);
    }
}
flock($lock,LOCK_UN);fclose($lock);echo "Done. Sent: $sent
";


