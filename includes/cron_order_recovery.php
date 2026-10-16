<?php
/**
 * Elite Commerce - Automated Order Recovery Cron
 * Scans for pending orders and sends WhatsApp reminders.
 */

// CLI Guard — prevent direct HTTP access
if (PHP_SAPI !== 'cli') {
    $cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        exit('Unauthorized');
    }
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ecommerce_handler.php';

echo "--- Starting Order Recovery Job ---\n";

// Find pending orders created more than 60 minutes ago that haven't received a recovery message
$timeThreshold = date('Y-m-d H:i:s', strtotime('-60 minutes'));

$stmt = $conn->prepare("
    SELECT o.*, c.name as contact_name, c.phone as contact_phone 
    FROM ecommerce_orders o
    JOIN contacts c ON o.contact_id = c.id
    WHERE o.status = 'pending' 
    AND o.recovery_sent = 0 
    AND o.created_at < ?
    LIMIT 20
");
$stmt->bind_param("s", $timeThreshold);
$stmt->execute();
$orders = $stmt->get_result();

$count = 0;
while ($order = $orders->fetch_assoc()) {
    $orderId = $order['id'];
    $companyId = $order['company_id'];
    $contactId = $order['contact_id'];
    $amount = $order['amount'];
    $contactName = $order['contact_name'] ?: 'Customer';
    
    // Determine link/QR
    $paymentInfo = "";
    if ($order['payment_link']) {
        $paymentInfo = $order['payment_link'];
    } elseif ($order['upi_qr_url']) {
        $paymentInfo = "Scan to pay: " . $order['upi_qr_url'];
    }

    if (empty($paymentInfo)) continue;

    // Recovery Message
    $msg = "👋 *Hi " . $contactName . "!*\n\nWe noticed you have a pending order of *₹" . number_format($amount, 2) . "* that hasn't been completed yet. 🛍️\n\nDon't miss out! You can finish your purchase here:\n" . $paymentInfo . "\n\nIf you need any help, just reply to this message! 😊";

    // Queue Message
    $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?, ?, 'order_recovery', ?, NOW())");
    $stmt_q->bind_param("iis", $companyId, $contactId, $msg);
    if ($stmt_q->execute()) {
        // Mark as recovery sent
        $stmt_mark = $conn->prepare("UPDATE ecommerce_orders SET recovery_sent = 1 WHERE id = ?");
        if ($stmt_mark) {
            $stmt_mark->bind_param('i', $orderId);
            $stmt_mark->execute();
        }
        $count++;
        echo "✅ Queued recovery for Order #$orderId (Contact: $contactName)\n";
    }
}

echo "--- Recovery Job Finished. Total Recoveries Queued: $count ---\n";
?>
