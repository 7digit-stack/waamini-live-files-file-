<?php
/**
 * WAAMINI Upsell Bot
 * Call after order marked as paid → suggest related products
 */
function send_upsell_message($conn, $companyId, $contactId, $orderId)
{
    // Get upsell settings
    $stmt_us = $conn->prepare("SELECT * FROM upsell_settings WHERE company_id=? AND is_active=1 LIMIT 1");
    $stmt_us->bind_param("i", $companyId);
    $stmt_us->execute();
    $settings = $stmt_us->get_result()->fetch_assoc();
    if (!$settings) return;

    // Get order items
    $stmt_o = $conn->prepare("SELECT items_json, amount FROM ecommerce_orders WHERE id=? AND company_id=?");
    $stmt_o->bind_param("ii", $orderId, $companyId);
    $stmt_o->execute();
    $order = $stmt_o->get_result()->fetch_assoc();
    if (!$order) return;

    // Get contact name
    $stmt_c = $conn->prepare("SELECT name FROM contacts WHERE id=? AND company_id=?");
    $stmt_c->bind_param("ii", $contactId, $companyId);
    $stmt_c->execute();
    $contact = $stmt_c->get_result()->fetch_assoc();
    $firstName = explode(' ', $contact['name']??'Friend')[0];

    // Get upsell products
    $stmt_pr = $conn->prepare("SELECT name, price, description FROM upsell_products WHERE company_id=? AND is_active=1 ORDER BY sort_order ASC LIMIT 3");
    $stmt_pr->bind_param("i", $companyId);
    $stmt_pr->execute();
    $products = $stmt_pr->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($products)) return;

    $productLines = implode("\n", array_map(fn($p) => "• *{$p['name']}* — ₹{$p['price']}" . (!empty($p['description']) ? "\n  {$p['description']}" : ''), $products));
    $delay = intval($settings['delay_minutes'] ?? 30);

    $msg = str_replace(
        ['{{first_name}}','{{products}}'],
        [$firstName, $productLines],
        $settings['message'] ?? "Thanks for your order, {{first_name}}! 🙏\n\nCustomers who bought this also loved:\n\n{{products}}\n\nReply to order any of these!"
    );

    $scheduledAt = date('Y-m-d H:i:s', time() + $delay * 60);
    $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,?)");
    $stmt_q->bind_param("iiss", $companyId, $contactId, $msg, $scheduledAt);
    $stmt_q->execute();
}
