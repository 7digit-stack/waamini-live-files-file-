<?php
include '../includes/client_auth_check.php';
require_once '../includes/ecommerce_handler.php';
require_once '../includes/whatsapp_settings.php';
require_once '../includes/whatsapp_api_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST only."]);
    exit();
}

if (isset($_POST['amount']) && isset($_POST['contact_id'])) {
    csrf_verify();
    $cid = intval($_POST['contact_id']);
    $amount = floatval($_POST['amount']);
    $desc = sanitize($_POST['description'] ?? 'Product Payment');

    if ($amount <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid amount."]);
        exit();
    }

    // 0. Fetch Contact Phone
    $stPh = $conn->prepare("SELECT phone FROM contacts WHERE id=? AND company_id=?");
    $stPh->bind_param("ii", $cid, $companyId);
    $stPh->execute();
    $contact = $stPh->get_result()->fetch_assoc();
    if (!$contact) {
        echo json_encode(["status" => "error", "message" => "Contact not found."]);
        exit();
    }
    $phone = (string)$contact['phone'];

    // Match method to saved gateway so UPI does not fall into "Unsupported Gateway"
    $method = 'razorpay';
    try {
        $st = $conn->prepare("SELECT gateway, upi_id FROM ecommerce_settings WHERE company_id=? AND is_active=1 LIMIT 1");
        if ($st) {
            $st->bind_param('i', $companyId);
            $st->execute();
            $s = $st->get_result()->fetch_assoc();
            $gw = strtolower((string)($s['gateway'] ?? ''));
            if ($gw === 'stripe') {
                $method = 'stripe';
            } elseif ($gw === 'upi' && trim((string)($s['upi_id'] ?? '')) !== '') {
                $method = 'upi';
            } else {
                $method = 'razorpay';
            }
        }
    } catch (\Throwable $e) {}

    $result = create_payment_link($cid, $amount, $companyId, $desc, $method);

    if (isset($result['url']) || isset($result['uri'])) {
        $payLink = $result['url'] ?? '';
        $upiUri = $result['uri'] ?? '';
        $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> *Payment Request*' . "\n\nItems: $desc\nAmount: \xe2\x82\xb9" . number_format($amount, 2) . "\n\n";
        if ($upiUri !== '') {
            $msg .= "Pay via any UPI app (recommended):\n$upiUri\n\nScan QR:\n" . ($result['url'] ?? '') . "\n\nNote: Some UPI apps limit payments made from a QR saved in *Gallery*. If you see that, use the UPI link above or scan the QR from another device.\n\nIf your bank shows a *limit* error, try a smaller amount or split the payment into 2 parts. You can also request an alternate link (Razorpay/Stripe).";
        } else {
            $msg .= "Click the link below to pay securely:\n$payLink";
        }

        // 3. Send via WhatsApp API
        $waMsgId = null;
        $cfgStmt = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1");
        if ($cfgStmt) {
            $cfgStmt->bind_param("i", $companyId);
            $cfgStmt->execute();
            $cfg = $cfgStmt->get_result()->fetch_assoc();
            if ($cfg && !empty($cfg['access_token']) && !empty($cfg['phone_number_id'])) {
                $waToken = waamini_whatsapp_decrypt_config_token($cfg);
                $waMsgId = send_whatsapp_text($phone, $msg, $waToken, $cfg['phone_number_id'], $cfg['api_version']);
            }
        }

        // 4. Save as Outgoing Message
        $status = $waMsgId ? 'sent' : 'failed';
        $stmtM = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, message, type, status, whatsapp_message_id) VALUES (?, ?, 'outgoing', ?, 'text', ?, ?)");
        $stmtM->bind_param("iisss", $companyId, $cid, $msg, $status, $waMsgId);
        $stmtM->execute();

        // 5. Update Conversation History
        $msgSnippet = "Checkout: ₹" . number_format($amount, 2);
        $stmtConv = $conn->prepare("UPDATE chat_conversations SET last_message = ?, last_message_at = NOW() WHERE contact_id = ? AND company_id = ?");
        $stmtConv->bind_param("sii", $msgSnippet, $cid, $companyId);
        $stmtConv->execute();

        echo json_encode(["status" => "success", "message" => "Payment link sent!", "url" => ($payLink ?: ($result['url'] ?? '')), "uri" => $upiUri]);
    }
    else {
        echo json_encode(["status" => "error", "message" => $result['error'] ?? 'Failed to create link.']);
    }
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
}
?>
