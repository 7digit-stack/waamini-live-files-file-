<?php
include '../includes/client_auth_check.php';
require_once '../includes/ecommerce_handler.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST only."]);
    exit();
}

if (isset($_POST['order_id']) && isset($_POST['method'])) {
    csrf_verify();
    $oid = intval($_POST['order_id']);
    $method = sanitize($_POST['method']); // 'razorpay' or 'upi'
    if (!in_array($method, ['upi', 'razorpay'], true)) {
        echo json_encode(["status" => "error", "message" => "Invalid payment method."]);
        exit();
    }

    // 1. Get existing order details
    $stmt = $conn->prepare("SELECT o.*, c.phone, c.name FROM ecommerce_orders o JOIN contacts c ON o.contact_id = c.id WHERE o.id = ? AND o.company_id = ?");
    $stmt->bind_param("ii", $oid, $companyId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        echo json_encode(["status" => "error", "message" => "Order not found."]);
        exit();
    }

    // 2. Generate Payment Link/QR
    // Note: create_payment_link usually inserts a new order. 
    // For an existing order, we can either update IT or just use its details.
    // I'll update the ecommerce_handler.php to handle existing orders better, or 
    // just re-run the relevant parts here for speed.
    
    // Actually, I'll update ecommerce_handler.php to have a send_order_payment function.
    // But for now, let's just implement the specific logic here to avoid more handler complexity.
    
    $stmt_set = $conn->prepare("SELECT * FROM ecommerce_settings WHERE company_id = ? AND is_active = 1");
    $stmt_set->bind_param("i", $companyId);
    $stmt_set->execute();
    $settings = $stmt_set->get_result()->fetch_assoc();

    if (!$settings) {
        echo json_encode(["status" => "error", "message" => "Payment settings not active."]);
        exit();
    }

    $msg = "";
    $attachment = null;

    if ($method === 'upi') {
        $vpa = $settings['upi_id'];
        $bizName = $settings['upi_name'] ?: 'WhatsApp Order';
        if (empty($vpa)) {
            echo json_encode(["status" => "error", "message" => "UPI VPA not configured."]);
            exit();
        }
        
        $upi = generate_upi_qr($vpa, $bizName, floatval($order['amount']), "ORD-".$oid);
        // Notify internal number(s) that UPI was chosen for this order
        try {
            $stN = $conn->prepare("SELECT upi_alert_numbers FROM ecommerce_settings WHERE company_id=? LIMIT 1");
            if ($stN) {
                $stN->bind_param('i', $companyId);
                $stN->execute();
                $nr = $stN->get_result()->fetch_assoc();
                $numsRaw = preg_replace('/[^0-9,]/', '', (string)($nr['upi_alert_numbers'] ?? ''));
                $nums = array_values(array_filter(array_map('trim', explode(',', $numsRaw))));
                if (!empty($nums)) {
                    $amtStr = number_format((float)$order['amount'], 2, '.', '');
                    $cust = preg_replace('/\D+/', '', (string)($order['phone'] ?? ''));
                    $custShow = $cust !== '' ? ('+' . $cust) : 'Customer';
                    $nm = trim((string)($order['name'] ?? ''));
                    $nameLine = $nm !== '' ? ("Customer: *" . $nm . "* (" . $custShow . ")") : ("Customer: " . $custShow);
                    $nmsg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg> *UPI payment selected*' . "\n\nOrder: *#ORD-{$oid}*\nAmount: *{$amtStr}*\n{$nameLine}\n\nStatus: pending (manual confirmation)";
                    foreach ($nums as $to) {
                        $to = preg_replace('/\D+/', '', $to);
                        if ($to === '') continue;
                        $insN = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at) VALUES (?, 0, 'text', ?, ?, 'pending', NOW(), NOW())");
                        if ($insN) {
                            $insN->bind_param('iss', $companyId, $to, $nmsg);
                            @$insN->execute();
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}
        $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> *Order Payment (UPI)*' . "\n\nOrder: #ORD-$oid\nAmount: \xe2\x82\xb9" . number_format($order['amount'], 2) . "\n\nPay via any UPI app (recommended):\n" . $upi['uri'] . "\n\nNote: Some UPI apps limit payments made from a QR saved in *Gallery*. If you see that, use the UPI link above or scan the QR from another device.\n\nIf your bank shows a *limit* error, try a smaller amount or split the payment into 2 parts. You can also ask us for an alternate link.\n\n_Ref: ORD-$oid_";
        $attachment = $upi['url'];
        
        $stmtOrderUpd = $conn->prepare("UPDATE ecommerce_orders SET gateway = 'upi', payment_method = 'upi' WHERE id = ? AND company_id = ?");
        $stmtOrderUpd->bind_param("ii", $oid, $companyId);
        $stmtOrderUpd->execute();
    } 
    else {
        // Razorpay logic
        $res = create_payment_link($order['contact_id'], $order['amount'], $companyId, "Re-sending Payment for Order #$oid", 'razorpay');
        if (isset($res['url'])) {
            $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> *Order Payment*' . "\n\nOrder: #ORD-$oid\nAmount: \xe2\x82\xb9" . number_format($order['amount'], 2) . "\n\nPlease complete your payment securely via Razorpay:\n" . $res['url'];
            $gatewayOrderId = (string)($res['gateway_order_id'] ?? '');
            $stmtOrderUpd = $conn->prepare("UPDATE ecommerce_orders SET gateway = 'razorpay', payment_method = 'razorpay', gateway_order_id = ? WHERE id = ? AND company_id = ?");
            $stmtOrderUpd->bind_param("sii", $gatewayOrderId, $oid, $companyId);
            $stmtOrderUpd->execute();
        } else {
            echo json_encode(["status" => "error", "message" => $res['error'] ?? "Failed to create Razorpay link."]);
            exit();
        }
    }

    // 3. Send to WhatsApp via Automation Queue (or direct insert to messages)
    $stmt_m = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, message, media_url, type) VALUES (?, ?, 'outgoing', ?, ?, ?)");
    $type = $attachment ? 'image' : 'text';
    $stmt_m->bind_param("iisss", $companyId, $order['contact_id'], $msg, $attachment, $type);
    $stmt_m->execute();

    // Also update conversation
    $snippet = "Payment Request: ₹" . number_format($order['amount'], 2);
    $contactId = (int)$order['contact_id'];
    $stmtConv = $conn->prepare("UPDATE chat_conversations SET last_message = ?, last_message_at = NOW() WHERE contact_id = ? AND company_id = ?");
    $stmtConv->bind_param("sii", $snippet, $contactId, $companyId);
    $stmtConv->execute();

    echo json_encode(["status" => "success", "message" => "Payment protocol deployed!"]);
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid request nodes."]);
}
?>
