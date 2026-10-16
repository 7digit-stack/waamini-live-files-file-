<?php
header('Content-Type: application/json');
include '../db.php';
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$apiKey = $data['api_key'] ?? '';
$phone  = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
if (!$apiKey || !$phone) { http_response_code(400); echo json_encode(['error'=>'Missing params']); exit(); }

$stmt_ak = $conn->prepare("SELECT company_id FROM api_keys WHERE api_key=? AND is_active=1 LIMIT 1");
$stmt_ak->bind_param("s",$apiKey); $stmt_ak->execute();
$ak = $stmt_ak->get_result()->fetch_assoc();
if (!$ak) { http_response_code(401); echo json_encode(['error'=>'Invalid API key']); exit(); }
$companyId = intval($ak['company_id']);

$stmt_ms = $conn->prepare("SELECT mc.*, co.name as business_name, ak2.access_token, ak2.phone_number_id FROM missed_call_settings mc JOIN companies co ON mc.company_id=co.id JOIN api_keys ak2 ON ak2.company_id=mc.company_id AND ak2.is_active=1 WHERE mc.company_id=? AND mc.is_active=1 LIMIT 1");
$stmt_ms->bind_param("i",$companyId); $stmt_ms->execute();
$ms = $stmt_ms->get_result()->fetch_assoc();
if (!$ms) { echo json_encode(['status'=>'disabled']); exit(); }

// Find or create contact
$stmt_ct = $conn->prepare("SELECT id, name FROM contacts WHERE company_id=? AND phone=? LIMIT 1");
$stmt_ct->bind_param("is",$companyId,$phone); $stmt_ct->execute();
$ct = $stmt_ct->get_result()->fetch_assoc();
$contactId = $ct ? intval($ct['id']) : null;
$firstName = $ct ? explode(' ',$ct['name'])[0] : 'there';

// Log
$stmt_log = $conn->prepare("INSERT INTO missed_calls_log (company_id,phone,contact_id) VALUES (?,?,?)");
$stmt_log->bind_param("isi",$companyId,$phone,$contactId); $stmt_log->execute();
$logId = $stmt_log->insert_id;

// Delay
if ($ms['delay_seconds'] > 0) sleep(min($ms['delay_seconds'],10));

$ver = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='wa_default_api_version' LIMIT 1");
$apiVersion = ($ver && $r=$ver->fetch_assoc()) ? $r['setting_value'] : 'v18.0';

$msg = str_replace(['{{first_name}}','{{business_name}}'],[$firstName,$ms['business_name']],$ms['message']??"Hi! You missed a call from *{$ms['business_name']}*. How can we help?");
$payload = json_encode(['messaging_product'=>'whatsapp','to'=>$phone,'type'=>'text','text'=>['body'=>$msg]]);
$ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$ms['phone_number_id']}/messages");
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$ms['access_token'],'Content-Type: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if ($code===200) {
    $conn->prepare("UPDATE missed_calls_log SET wa_sent=1 WHERE id=?")->bind_param("i",$logId);
    $stmt_u = $conn->prepare("UPDATE missed_calls_log SET wa_sent=1 WHERE id=?");
    $stmt_u->bind_param("i",$logId); $stmt_u->execute();
}
echo json_encode(['status'=>$code===200?'sent':'failed','code'=>$code]);
