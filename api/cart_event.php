<?php
// Called by external website when cart is created/updated
header('Content-Type: application/json');
include '../db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}
$apiKey = $data['api_key'] ?? ($_GET['api_key'] ?? '');
$phone  = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
$total  = floatval($data['cart_total'] ?? 0);
$url    = $data['checkout_url'] ?? '';
$items  = is_array($data['items'] ?? null) ? $data['items'] : [];

if (!$apiKey || !$phone) { http_response_code(400); echo json_encode(['error'=>'Missing api_key or phone']); exit(); }

// Validate API key
$stmt_ak = $conn->prepare("SELECT company_id FROM api_keys WHERE api_key=? LIMIT 1");
$stmt_ak->bind_param("s", $apiKey);
$stmt_ak->execute();
$ak = $stmt_ak->get_result()->fetch_assoc();
if (!$ak) { http_response_code(401); echo json_encode(['error'=>'Invalid API key']); exit(); }
$companyId = intval($ak['company_id']);

// Check recovery is active
$stmt_cs = $conn->prepare("SELECT * FROM cart_recovery_settings WHERE company_id=? AND is_active=1 LIMIT 1");
$stmt_cs->bind_param("i", $companyId);
$stmt_cs->execute();
if (!$stmt_cs->get_result()->num_rows) { echo json_encode(['status'=>'disabled']); exit(); }

// Find or create contact
$stmt_ct = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND phone=? LIMIT 1");
$stmt_ct->bind_param("is", $companyId, $phone);
$stmt_ct->execute();
$ct = $stmt_ct->get_result()->fetch_assoc();
if (!$ct) {
    $stmt_nc = $conn->prepare("INSERT INTO contacts (company_id, phone, name) VALUES (?,?,'Unknown')");
    $stmt_nc->bind_param("is", $companyId, $phone);
    $stmt_nc->execute();
    $contactId = $stmt_nc->insert_id;
} else {
    $contactId = intval($ct['id']);
}

$cartJson = json_encode(['items'=>$items,'checkout_url'=>$url]);
$stmt_ins = $conn->prepare("INSERT INTO abandoned_carts (company_id, contact_id, cart_data, cart_total, recovery_status) VALUES (?,?,?,?,'pending') ON DUPLICATE KEY UPDATE cart_data=VALUES(cart_data), cart_total=VALUES(cart_total), recovery_status='pending', updated_at=NOW()");
$stmt_ins->bind_param("iisd", $companyId, $contactId, $cartJson, $total);
$stmt_ins->execute();

echo json_encode(['status'=>'ok','cart_id'=>$stmt_ins->insert_id]);
