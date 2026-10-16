<?php
include '../includes/client_auth_check.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']);
    exit();
}
csrf_verify();

require_once __DIR__ . '/../includes/order_invoice_whatsapp.php';

$orderId   = (int)($_POST['order_id'] ?? 0);
$contactId = (int)($_POST['contact_id'] ?? 0);
if ($orderId <= 0 && $contactId <= 0) {
    echo json_encode(['error' => 'Missing params']);
    exit();
}

if ($orderId <= 0 && $contactId > 0) {
    $st = $conn->prepare('SELECT id FROM ecommerce_orders WHERE contact_id=? AND company_id=? ORDER BY id DESC LIMIT 1');
    if ($st) {
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) {
            $orderId = (int)$row['id'];
        }
    }
}

if ($orderId <= 0) {
    echo json_encode(['error' => 'Order not found']);
    exit();
}

$ok = waamini_queue_invoice_message_for_order($conn, $companyId, $orderId);
if ($ok) {
    echo json_encode(['status' => 'queued', 'message' => 'Invoice queued for WhatsApp']);
} else {
    echo json_encode(['status' => 'skipped', 'message' => 'Invoice not queued (already sent or order missing)']);
}
