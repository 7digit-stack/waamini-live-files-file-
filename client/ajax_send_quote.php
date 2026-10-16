<?php
include '../includes/client_auth_check.php';
require_once '../includes/whatsapp_settings.php';
require_once '../includes/whatsapp_api_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST only."]);
    exit();
}

if (isset($_POST['item']) && isset($_POST['price']) && isset($_POST['contact_id'])) {
    csrf_verify();
    $cid = intval($_POST['contact_id']);
    $price = floatval($_POST['price']);
    $discount = floatval($_POST['discount'] ?? 0);
    $itemName = sanitize($_POST['item']);
    $token = bin2hex(random_bytes(16));

    if ($price <= 0 || empty($itemName)) {
        echo json_encode(["status" => "error", "message" => "Invalid item or price."]);
        exit();
    }

    $total = max(0, $price - $discount);

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

    // 1. Save Quotation
    $itemsJson = json_encode([['name' => $itemName, 'price' => $price, 'qty' => 1]]);
    $stmt = $conn->prepare("INSERT INTO quotations (company_id, contact_id, item_name, price, items_json, discount, total, token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisdsdds", $companyId, $cid, $itemName, $price, $itemsJson, $discount, $total, $token);

    if ($stmt->execute()) {
        $quoteId = $stmt->insert_id;
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $viewLink = "$protocol://$host/api/view_quote.php?t=$token";

        // 2. Format WhatsApp Message
        $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> *Professional Quotation Received*' . "\n\n" .
            "*Item:* $itemName\n" .
            "*Price:* ₹" . number_format($price, 2) . "\n";
            
        if ($discount > 0) {
            $msg .= "*Discount:* -₹" . number_format($discount, 2) . "\n";
            $msg .= "--------------------------\n";
            $msg .= "*Total:* ₹" . number_format($total, 2) . "\n";
        }
        
        $msg .= "\nView and download professional PDF:\n$viewLink\n\n" .
            "Please let us know if you have any questions.";

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
        $snippet = "Quotation: $itemName (₹" . number_format($price, 2) . ")";
        $stmtConv = $conn->prepare("UPDATE chat_conversations SET last_message = ?, last_message_at = NOW() WHERE contact_id = ? AND company_id = ?");
        $stmtConv->bind_param("sii", $snippet, $cid, $companyId);
        $stmtConv->execute();

        echo json_encode(["status" => "success", "message" => "Quotation sent!", "url" => $viewLink]);
    }
    else {
        error_log('[WAAMINI ajax_send_quote] ' . $conn->error);
        echo json_encode(["status" => "error", "message" => "Database error."]);
    }
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid request details."]);
}
?>
