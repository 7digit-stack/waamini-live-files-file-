<?php
/**
 * Queue a WhatsApp invoice summary for an ecommerce_orders row (same payload as api/send_invoice.php).
 */

function waamini_order_invoice_ensure_columns(mysqli $conn): void
{
    try {
        $conn->query('ALTER TABLE ecommerce_orders ADD COLUMN invoice_sent TINYINT(1) DEFAULT 0');
    } catch (\Throwable $e) { /* exists */
    }
    try {
        $conn->query('ALTER TABLE ecommerce_orders ADD COLUMN invoice_sent_at DATETIME DEFAULT NULL');
    } catch (\Throwable $e) { /* exists */
    }
}

/**
 * Queue invoice message via automation_queue (instant). Marks invoice_sent when queued.
 *
 * @return bool true when queued successfully
 */
function waamini_queue_invoice_message_for_order(mysqli $conn, int $companyId, int $orderId): bool
{
    waamini_order_invoice_ensure_columns($conn);

    $orderId = (int)$orderId;
    $companyId = (int)$companyId;
    if ($orderId <= 0 || $companyId <= 0) {
        return false;
    }

    $stmt_o = $conn->prepare(
        'SELECT o.*, c.name, c.phone, c.email FROM ecommerce_orders o
         JOIN contacts c ON o.contact_id=c.id
         WHERE o.id=? AND o.company_id=? LIMIT 1'
    );
    if (!$stmt_o) {
        return false;
    }
    $stmt_o->bind_param('ii', $orderId, $companyId);
    $stmt_o->execute();
    $order = $stmt_o->get_result()->fetch_assoc();
    if (!$order) {
        return false;
    }

    if (!empty($order['invoice_sent'])) {
        return false;
    }

    $stmt_co = $conn->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
    $companyName = 'Our team';
    if ($stmt_co) {
        $stmt_co->bind_param('i', $companyId);
        $stmt_co->execute();
        $cr = $stmt_co->get_result()->fetch_assoc();
        if ($cr && !empty($cr['name'])) {
            $companyName = (string)$cr['name'];
        }
    }

    $itemsArr = json_decode((string)($order['items_json'] ?? ''), true) ?: [];
    $itemLines = '';
    foreach ($itemsArr as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nm = (string)($item['name'] ?? 'Item');
        $qty = (int)($item['qty'] ?? 1);
        $pr = $item['price'] ?? 0;
        $itemLines .= '• ' . $nm . ' x' . $qty . ' — ₹' . $pr . "\n";
    }

    $invoiceMsg = "🧾 *Invoice from {$companyName}*\n\n";
    $invoiceMsg .= 'Order #: *' . ($order['order_number'] ?? 'ORD-' . $orderId) . "*\n";
    $invoiceMsg .= 'Date: ' . date('d M Y', strtotime((string)($order['created_at'] ?? 'now'))) . "\n\n";
    if ($itemLines !== '') {
        $invoiceMsg .= $itemLines . "\n";
    }
    $invoiceMsg .= "━━━━━━━━━━━━━\n";
    $invoiceMsg .= '💰 *Total: ₹' . number_format((float)($order['amount'] ?? 0), 2) . "*\n";
    $invoiceMsg .= 'Status: ' . ucfirst((string)($order['status'] ?? '')) . "\n\n";
    $invoiceMsg .= 'Thank you for your order!';

    $cid = (int)($order['contact_id'] ?? 0);
    if ($cid <= 0) {
        return false;
    }

    $stmt_q = $conn->prepare(
        "INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at, status) VALUES (?,?, 'instant', ?, NOW(), 'pending')"
    );
    if (!$stmt_q) {
        return false;
    }
    $stmt_q->bind_param('iis', $companyId, $cid, $invoiceMsg);
    if (!$stmt_q->execute()) {
        return false;
    }

    $stmtMark = $conn->prepare(
        'UPDATE ecommerce_orders SET invoice_sent=1, invoice_sent_at=NOW() WHERE id=? AND company_id=? LIMIT 1'
    );
    if ($stmtMark) {
        $stmtMark->bind_param('ii', $orderId, $companyId);
        $stmtMark->execute();
    }

    return true;
}
