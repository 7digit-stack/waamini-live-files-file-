<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/ecommerce_handler.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only.']);
    exit();
}

csrf_verify();

$orderId = (int)($_POST['order_id'] ?? 0);
$utrRaw = trim((string)($_POST['utr'] ?? ''));
$utr = preg_replace('/[^A-Za-z0-9._-]/', '', $utrRaw);
if (function_exists('mb_substr')) {
    $utr = (string)mb_substr($utr, 0, 80, 'UTF-8');
} else {
    $utr = substr($utr, 0, 80);
}

if ($orderId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid order id.']);
    exit();
}
if ($utrRaw !== '' && $utr === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid UTR / reference format.']);
    exit();
}

// Ensure optional column exists for storing UTR/reference
try {
    $conn->query("ALTER TABLE ecommerce_orders ADD COLUMN payment_ref VARCHAR(80) DEFAULT NULL");
} catch (\Throwable $e) { /* exists */ }

$st = $conn->prepare(
    "SELECT o.id, o.company_id, o.contact_id, o.amount, o.currency, o.status, o.gateway, o.payment_method, c.phone
     FROM ecommerce_orders o
     JOIN contacts c ON c.id=o.contact_id AND c.company_id=o.company_id
     WHERE o.id=? AND o.company_id=?
     LIMIT 1"
);
if (!$st) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error.']);
    exit();
}
$st->bind_param('ii', $orderId, $companyId);
$st->execute();
$order = $st->get_result()->fetch_assoc();
if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    exit();
}

$status = strtolower(trim((string)($order['status'] ?? '')));
if ($status === 'paid') {
    echo json_encode(['status' => 'success', 'message' => 'Order already paid.']);
    exit();
}
if ($status !== 'pending') {
    echo json_encode(['status' => 'error', 'message' => 'Only pending orders can be marked paid.']);
    exit();
}

// Only allow manual mark for UPI-based orders
$gw = strtolower(trim((string)($order['gateway'] ?? '')));
$pm = strtolower(trim((string)($order['payment_method'] ?? '')));
if ($gw !== 'upi' && $pm !== 'upi') {
    echo json_encode(['status' => 'error', 'message' => 'This order is not a UPI order.']);
    exit();
}

if ($utr !== '') {
    $up = $conn->prepare("UPDATE ecommerce_orders SET payment_ref=?, updated_at=NOW() WHERE id=? AND company_id=? LIMIT 1");
    if ($up) {
        $up->bind_param('sii', $utr, $orderId, $companyId);
        $up->execute();
    }
}

$becamePaid = waamini_mark_order_paid_if_pending($conn, $orderId, (int)$order['company_id'], (int)$order['contact_id']);
if ($becamePaid) {
    waamini_payment_success_notifications($conn, $order);
    echo json_encode(['status' => 'success', 'message' => 'Marked as paid.']);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Could not mark as paid. Please retry.']);

