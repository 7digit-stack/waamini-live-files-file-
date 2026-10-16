<?php
/** WAAMINI Vehicle Reminder Cron — Schedule: 0 10 * * * */
$cronKey=getenv('WAAMINI_CRON_KEY')?:'';
if(PHP_SAPI!=='cli'){$p=$_GET['key']??'';if(!$cronKey||!hash_equals($cronKey,$p)){http_response_code(403);die('Forbidden');}}
set_time_limit(120); ini_set('memory_limit', '256M'); require_once __DIR__.'/db.php'; require_once __DIR__.'/includes/whatsapp_settings.php';
$lockFile=sys_get_temp_dir().'/waamini_vehicle.lock';
$lock=fopen($lockFile,'w');if(!flock($lock,LOCK_EX|LOCK_NB))die("Running
");
$sent=0;
$stmt=$conn->query("SELECT v.*,c.name,c.phone,co.name as biz_name,wc.access_token,wc.phone_number_id,wc.api_version FROM vehicles v JOIN contacts c ON v.contact_id=c.id JOIN companies co ON v.company_id=co.id JOIN whatsapp_config wc ON wc.company_id=v.company_id AND wc.status='active'");
while($v=$stmt->fetch_assoc()){
    $reminders=[];
    if($v['next_service_date']){$d=(strtotime($v['next_service_date'])-time())/86400;if($d>=0&&$d<=7)$reminders[]=['service',"🔧 *Service Reminder*

Dear {$v['name']},

Your *{$v['make_model']}* ({$v['vehicle_number']}) service is due on *".date('d M Y',strtotime($v['next_service_date']))."*.

Book now to avoid waiting! 📞
— {$v['biz_name']}"];}
    if($v['insurance_expiry']){$d=(strtotime($v['insurance_expiry'])-time())/86400;if($d>=0&&$d<=15)$reminders[]=['insurance',"🛡️ *Insurance Expiry Alert*

Dear {$v['name']},

Vehicle {$v['vehicle_number']} insurance expires on *".date('d M Y',strtotime($v['insurance_expiry']))."*.

Renew now! ⚠️
— {$v['biz_name']}"];}
    if($v['pollution_expiry']){$d=(strtotime($v['pollution_expiry'])-time())/86400;if($d>=0&&$d<=7)$reminders[]=['puc',"🌿 *PUC Expiry Reminder*

Dear {$v['name']},

Pollution certificate for {$v['vehicle_number']} expires *".date('d M Y',strtotime($v['pollution_expiry']))."*.

Get it renewed! 🚓
— {$v['biz_name']}"];}
    foreach($reminders as [$type,$msg]){
        $cacheKey=sys_get_temp_dir()."/waamini_veh_{$v['id']}_{$type}_".date('Ymd');
        if(file_exists($cacheKey))continue;file_put_contents($cacheKey,'1');
        $apiVer=$v['api_version']?:'v18.0';
        $accessToken = waamini_whatsapp_decrypt_config_token($v);
        $payload=json_encode(['messaging_product'=>'whatsapp','to'=>$v['phone'],'type'=>'text','text'=>['body'=>$msg]]);
        $ch=curl_init("https://graph.facebook.com/{$apiVer}/{$v['phone_number_id']}/messages");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Content-Type: application/json']]);
        $r=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($code===200)$sent++;usleep(300000);
    }
}
flock($lock,LOCK_UN);fclose($lock);echo "Done. Sent: $sent
";


