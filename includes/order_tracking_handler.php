<?php
/**
 * WAAMINI Order Tracking Handler
 * Called from webhook.php when a message matches the order tracking trigger keyword
 * Usage: order_tracking_check($conn, $companyId, $contactId, $text)
 */

function order_tracking_check($conn, $companyId, $contactId, $text)
{
    // Load settings
    $stmt_s = $conn->prepare("SELECT * FROM order_tracking_settings WHERE company_id=? AND is_active=1 LIMIT 1");
    $stmt_s->bind_param("i", $companyId);
    $stmt_s->execute();
    $settings = $stmt_s->get_result()->fetch_assoc();
    if (!$settings) return false;

    $kw = mb_strtolower(trim($settings['trigger_keyword'] ?? 'order'));
    $textLower = mb_strtolower(trim($text));

    // Check if message starts with trigger keyword
    if (!str_starts_with($textLower, $kw)) return false;

    // Extract order number from message (everything after keyword)
    $query = trim(substr($text, strlen($kw)));
    $query = trim($query, " \t\n\r:.-");
    if (empty($query)) return false;

    // Look up order by order_number (case-insensitive)
    $stmt_o = $conn->prepare("SELECT * FROM ecommerce_orders WHERE company_id=? AND (UPPER(order_number)=UPPER(?) OR order_number=?) LIMIT 1");
    $stmt_o->bind_param("iss", $companyId, $query, $query);
    $stmt_o->execute();
    $order = $stmt_o->get_result()->fetch_assoc();

    // Build response message
    if ($order) {
        $template = !empty($settings['tracking_message']) ? $settings['tracking_message'] :
            "📦 *Order Status for {{order_number}}*\n\nStatus: *{{status}}*\n{{tracking_url_line}}{{delivery_line}}{{notes_line}}\nThank you for shopping with us! 🙏";

        $statusEmoji = [
            'pending'    => '⏳', 'paid'       => '✅', 'processing' => '⚙️',
            'shipped'    => '🚚', 'delivered'  => '📬', 'failed'     => '❌', 'refunded'   => '💸'
        ];
        $emoji = $statusEmoji[$order['status']] ?? '📦';

        $estDelivery = !empty($order['estimated_delivery']) ? date('d M Y', strtotime($order['estimated_delivery'])) : '';

        $msg = str_replace(
            ['{{order_number}}', '{{status}}', '{{tracking_url}}', '{{estimated_delivery}}', '{{status_notes}}', '{{customer_name}}',
             '{{#tracking_url}}', '{{/tracking_url}}', '{{#estimated_delivery}}', '{{/estimated_delivery}}', '{{#status_notes}}', '{{/status_notes}}',
             '{{tracking_url_line}}', '{{delivery_line}}', '{{notes_line}}'],
            [
                $order['order_number'] ?? 'N/A',
                $emoji . ' ' . ucfirst($order['status']),
                $order['tracking_url'] ?? '',
                $estDelivery,
                $order['status_notes'] ?? '',
                $order['customer_name'] ?? '',
                !empty($order['tracking_url']) ? '' : '(?)',
                !empty($order['tracking_url']) ? '' : '(?)',
                !empty($estDelivery) ? '' : '(?)',
                !empty($estDelivery) ? '' : '(?)',
                !empty($order['status_notes']) ? '' : '(?)',
                !empty($order['status_notes']) ? '' : '(?)',
                !empty($order['tracking_url']) ? "🚚 Track: {$order['tracking_url']}\n" : '',
                !empty($estDelivery) ? "📅 Est. Delivery: $estDelivery\n" : '',
                !empty($order['status_notes']) ? "ℹ️ {$order['status_notes']}\n" : '',
            ],
            $template
        );
        // Clean up conditional blocks
        $msg = preg_replace('/\(\?\)[^\n]*\n?/', '', $msg);
    } else {
        $notFoundTpl = $settings['not_found_message'] ??
            "Sorry, we couldn't find order *{{query}}*. Please check your order number and try again.";
        $msg = str_replace('{{query}}', htmlspecialchars($query), $notFoundTpl);
    }

    // Queue the reply
    $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?,?,'instant',?,NOW())");
    $stmt_q->bind_param("iis", $companyId, $contactId, $msg);
    $stmt_q->execute();

    return true;
}
