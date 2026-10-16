<?php
/**
 * WAAMINI API - E-Commerce Abandoned Cart Catcher
 * Endpoint: /waamini/api/ecommerce_catch.php?company_id=XXX&api_key=YYY
 */

// Cart Recovery is switched off: accept nothing and create no contacts, carts or messages.
// 410 Gone tells stores such as Shopify to stop sending to this webhook.
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['status' => 'disabled', 'message' => 'Abandoned cart recovery is turned off in WAAMINI.']);
exit();

require_once '../db.php';
require_once '../includes/security.php';

header('Content-Type: application/json');

// 1. Authenticate Request
$companyId = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;
// Hex keys must not pass through htmlspecialchars/sanitize (can corrupt verification)
$apiKey = isset($_GET['api_key']) ? trim((string)$_GET['api_key']) : '';

if ($companyId === 0 || empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing auth parameters.']);
    exit();
}

$stmt = $conn->prepare("SELECT id FROM api_keys WHERE company_id = ? AND api_key = ? AND is_active = 1");
$stmt->bind_param("is", $companyId, $apiKey);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API Key.']);
    exit();
}

// 2. Parse Incoming JSON Payload (Shopify / Woo Format)
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit();
}

/* 
 * Shopify Cart Format Example Handling
 * phone: Usually in customer.phone or billing_address.phone
 * total: total_price
 * url: abandoned_checkout_url
 */

$phone = $input['customer']['phone'] ?? $input['billing_address']['phone'] ?? $input['billing']['phone'] ?? '';
$total = $input['total_price'] ?? $input['total'] ?? '0.00';
$checkout_url = $input['abandoned_checkout_url'] ?? $input['checkout_url'] ?? '';
$name = $input['customer']['first_name'] ?? $input['billing']['first_name'] ?? 'Shopper';

// Clean the phone number (remove + and spaces)
$phone = preg_replace('/[^0-9]/', '', $phone);

// If WooCommerce "Action" webhook is used, it might send a simpler custom payload:
if (isset($input['phone'])) {
    $phone = preg_replace('/[^0-9]/', '', $input['phone']);
    $total = $input['total'] ?? '0.00';
    $checkout_url = $input['url'] ?? '';
    $name = $input['name'] ?? 'Shopper';
}

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Phone number missing in payload.']);
    exit();
}

// 3. Find or Create Contact in WAAMINI CRM
$stmt_contact = $conn->prepare("SELECT id FROM contacts WHERE phone = ? AND company_id = ?");
$stmt_contact->bind_param("si", $phone, $companyId);
$stmt_contact->execute();
$contactRes = $stmt_contact->get_result();

if ($contactRes->num_rows === 0) {
    $stmt_ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name, status, chat_labels) VALUES (?, ?, ?, 'unassigned', 'E-Commerce')");
    $stmt_ins->bind_param("iss", $companyId, $phone, $name);
    $stmt_ins->execute();
    $contactId = $stmt_ins->insert_id;
}
else {
    $contactId = $contactRes->fetch_assoc()['id'];
}

// 4. Record the Abandoned Cart
$stmt_cart = $conn->prepare("INSERT INTO ecommerce_carts (company_id, contact_id, cart_url, cart_total, status) VALUES (?, ?, ?, ?, 'abandoned')");
$stmt_cart->bind_param("iiss", $companyId, $contactId, $checkout_url, $total);
$stmt_cart->execute();
$cartId = $stmt_cart->insert_id;

// 5. Queue the Recovery Message (Delay by 1 hour)
// Replaces placeholders like {{name}}
$msgText = "Hey *$name*! 👋\n\nWe noticed you left some awesome items in your cart worth *$total*.\n\nComplete your order now and get *10% OFF* using code: *RECOVER10*\n\nReturn to cart: $checkout_url";

// Schedule for 1 hour from now
$scheduledTime = date('Y-m-d H:i:s', strtotime('+1 hour'));

$stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?, ?, 'abandoned_cart', ?, ?)");
$stmt_q->bind_param("iiss", $companyId, $contactId, $msgText, $scheduledTime);

if ($stmt_q->execute()) {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Cart recorded and recovery message scheduled.']);
}
else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to schedule recovery.']);
}
