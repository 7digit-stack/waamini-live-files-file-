<?php
/**
 * WAAMINI Medicine Reminder Cron — runs every 30 mins
 * Schedule: Every 30 minutes
 */
$cronKey = getenv('WAAMINI_CRON_KEY')?:'';
if (PHP_SAPI!=='cli'){$p=$_GET['key']??'';if(!$cronKey||!hash_equals($cronKey,$p)){http_response_code(403);die('Forbidden');}}
set_time_limit(120);
ini_set('memory_limit', '256M');
require_once __DIR__.'/db.php';
require_once __DIR__.'/includes/whatsapp_settings.php';
$lockFile=sys_get_temp_dir().'/waamini_meds.lock';
$lock=fopen($lockFile,'w');if(!flock($lock,LOCK_EX|LOCK_NB))die("Running
");
$now=date('H:i'); $today=date('Y-m-d'); $sent=0;

$stmt=$conn->query("SELECT mr.*, c.phone, c.name as cname, co.name as biz_name, wc.access_token, wc.phone_number_id, wc.api_version FROM medicine_reminders mr JOIN contacts c ON mr.contact_id=c.id JOIN companies co ON mr.company_id=co.id JOIN whatsapp_config wc ON wc.company_id=mr.company_id AND wc.status='active' WHERE mr.is_active=1 AND (mr.end_date IS NULL OR mr.end_date>=CURDATE()) AND mr.start_date<=CURDATE()");
while ($r=$stmt->fetch_assoc()) {
    $times=json_decode($r['reminder_times'],true)?:[];
    $meds=json_decode($r['medicines'],true)?:[];
    $shouldSend=false;
    foreach($times as $t){if(abs(strtotime("today $t")-time())<=900)$shouldSend=true;}// within 15 mins
    if(!$shouldSend)continue;
    // Don't double-send same day+time
    $cacheKey=sys_get_temp_dir()."/waamini_med_{$r['id']}_{$now}";
    if(file_exists($cacheKey))continue;
    file_put_contents($cacheKey,'1');
    $medsText=implode("
",array_map(fn($m)=>"💊 $m",$meds));
    $msg="⏰ *Medicine Reminder*

Dear {$r['cname']},

Time for {$r['patient_name']}'s medicines:

{$medsText}

Please take medicines on time! 🙏
— {$r['biz_name']}";
    $apiVer=$r['api_version']?:'v18.0';
    $payload=json_encode(['messaging_product'=>'whatsapp','to'=>$r['phone'],'type'=>'text','text'=>['body'=>$msg]]);
    $accessToken = waamini_whatsapp_decrypt_config_token($r);
    $ch=curl_init("https://graph.facebook.com/{$apiVer}/{$r['phone_number_id']}/messages");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Content-Type: application/json']]);
    $res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($code===200)$sent++;
    usleep(200000);
}
flock($lock,LOCK_UN);fclose($lock);
echo "Done. Sent: $sent
";


