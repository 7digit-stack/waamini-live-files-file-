<?php
include '../includes/client_auth_check.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'POST only']); exit(); }
csrf_verify();

$contactId = intval($_POST['contact_id'] ?? 0);
$amount    = floatval($_POST['amount'] ?? 0);
$desc      = sanitize($_POST['description'] ?? 'Payment');
if (!$contactId || $amount <= 0) { echo json_encode(['error'=>'Invalid params']); exit(); }

// Get contact
$stmt_c = $conn->prepare("SELECT name, phone FROM contacts WHERE id=? AND company_id=?");
$stmt_c->bind_param("ii", $contactId, $companyId);
$stmt_c->execute();
$contact = $stmt_c->get_result()->fetch_assoc();
if (!$contact) { echo json_encode(['error'=>'Contact not found']); exit(); }

// Get Razorpay settings
$stmt_pay = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='payment'");
$pay = [];
while ($r = $stmt_pay->fetch_assoc()) $pay[$r['setting_key']] = $r['setting_value'];
$rzKeyId = $pay['razorpay_key_id'] ?? '';
$rzSecret = $pay['razorpay_key_secret'] ?? '';

if (!$rzKeyId) { echo json_encode(['error'=>'Payment gateway not configured']); exit(); }

// Create Razorpay payment link
$payload = json_encode([
    'amount' => intval($amount * 100),
    'currency' => $pay['currency'] ?? 'INR',
    'description' => $desc,
    'customer' => ['name'=>$contact['name'], 'contact'=>'+'.$contact['phone']],
    'notify' => ['sms'=>false, 'email'=>false],
    'reminder_enable' => false,
]);
$ch = curl_init('https://api.razorpay.com/v1/payment_links');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>"$rzKeyId:$rzSecret",CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>15]);
$resp = json_decode(curl_exec($ch), true);
curl_close($ch);

if (isset($resp['short_url'])) {
    $symbol = $pay['currency_symbol'] ?? '₹';
    $msg = "💳 *Payment Request*\n\nAmount: *{$symbol}" . number_format($amount, 2) . "*\nFor: $desc\n\n👉 Pay here: {$resp['short_url']}\n\n_Link expires in 24 hours._";
    $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,NOW())");
    $stmt_q->bind_param("iis", $companyId, $contactId, $msg);
    $stmt_q->execute();
    echo json_encode(['status'=>'sent','link'=>$resp['short_url']]);
} else {
    echo json_encode(['error'=>'Failed to create payment link: '.($resp['error']['description']??'Unknown error')]);
}
