<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ecommerce_handler.php';

$orderId = isset($_GET['oid']) ? intval($_GET['oid']) : 0;
if ($orderId <= 0)
    die("Invalid Order");

function _rz_get_payment_link(string $keyId, string $keySecret, string $paymentLinkId): ?array {
    $url = 'https://api.razorpay.com/v1/payment_links/' . rawurlencode($paymentLinkId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { curl_close($ch); return null; }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// 1. Fetch Order + Contact + Settings (prepared)
$stmt = $conn->prepare("
    SELECT
        o.id, o.company_id, o.contact_id, o.amount, o.currency, o.status, o.gateway, o.payment_method, o.gateway_order_id,
        s.api_key, s.api_secret, s.gateway AS settings_gateway,
        c.phone
    FROM ecommerce_orders o
    JOIN ecommerce_settings s ON o.company_id = s.company_id
    JOIN contacts c ON o.contact_id = c.id AND c.company_id = o.company_id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order)
    die("Order not found");

if (function_exists('waamini_ecommerce_decrypt_field')) {
    $order['api_key'] = waamini_ecommerce_decrypt_field($order['api_key'] ?? null);
    $order['api_secret'] = waamini_ecommerce_decrypt_field($order['api_secret'] ?? null);
}

// 2. Verify payment with gateway BEFORE marking paid (idempotent)
$verifiedPaid = false;
$gateway = $order['gateway'] ?: ($order['settings_gateway'] ?? '');

if ($order['status'] === 'paid') {
    $verifiedPaid = true;
} elseif ($order['status'] === 'pending') {
    if ($gateway === 'razorpay' && !empty($order['gateway_order_id']) && !empty($order['api_key']) && !empty($order['api_secret'])) {
        $pl = _rz_get_payment_link($order['api_key'], $order['api_secret'], $order['gateway_order_id']);
        if ($pl && ($pl['status'] ?? '') === 'paid') {
            $verifiedPaid = true;
        }
    } else {
        // For UPI QR (manual), we cannot auto-verify here.
        $verifiedPaid = false;
    }
}

if ($verifiedPaid && $order['status'] === 'pending') {
    $becamePaid = waamini_mark_order_paid_if_pending(
        $conn,
        $orderId,
        (int)$order['company_id'],
        (int)$order['contact_id']
    );
    if ($becamePaid && function_exists('waamini_payment_success_notifications')) {
        waamini_payment_success_notifications($conn, $order);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Successful</title>
    <style>
        body { background: #0b141a; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin:0; }
        .card { background: #111b21; padding: 40px; border-radius: 20px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #202c33; max-width: 400px; }
        .icon { font-size: 60px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin-bottom: 10px; color: #00b894; }
        p { color: #8696a0; line-height: 1.5; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #00b894; color: #fff; text-decoration: none; border-radius: 30px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($verifiedPaid): ?>
            <div class="icon">✅</div>
            <h1>Payment Successful!</h1>
            <p>Your payment of <strong><?php echo htmlspecialchars($order['currency']) . " " . number_format((float)$order['amount'], 2); ?></strong> has been verified.</p>
            <p>A confirmation has been sent to your WhatsApp.</p>
            <a href="https://wa.me/<?php echo htmlspecialchars($order['phone']); ?>" class="btn">Back to WhatsApp</a>
        <?php else: ?>
            <div class="icon">⏳</div>
            <h1>Payment Pending Verification</h1>
            <p>We haven't received a verified confirmation from the payment gateway yet.</p>
            <p>Please wait a moment and try again, or contact support if this persists.</p>
            <a href="https://wa.me/<?php echo htmlspecialchars($order['phone']); ?>" class="btn">Back to WhatsApp</a>
        <?php endif; ?>
    </div>
</body>
</html>
