<?php
/**
 * UPI Redirector
 * WhatsApp doesn't linkify upi:// URIs. This script provides a clickable https:// link
 * that redirects the mobile browser to the upi:// URI, launching the payment app.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/ecommerce_handler.php';

$orderId = isset($_GET['oi']) ? (int)$_GET['oi'] : 0;
if ($orderId <= 0) {
    die("Invalid Order ID");
}

// Fetch order to get company_id and amount
$stmt = $conn->prepare("SELECT company_id, amount, currency FROM ecommerce_orders WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

$companyId = (int)$order['company_id'];
$amount = (float)$order['amount'];

// Fetch company UPI settings
$stmt2 = $conn->prepare("SELECT upi_id, upi_name FROM ecommerce_settings WHERE company_id = ? AND is_active = 1 LIMIT 1");
$stmt2->bind_param("i", $companyId);
$stmt2->execute();
$settings = $stmt2->get_result()->fetch_assoc();

if (!$settings || empty($settings['upi_id'])) {
    die("UPI not configured for this business.");
}

$settings = waamini_ecommerce_decrypt_settings($settings);
$vpa = $settings['upi_id'];
$bizName = $settings['upi_name'] ?: 'WhatsApp Order';
$ref = "ORD-$orderId";

$res = generate_upi_qr($vpa, $bizName, $amount, $ref);
$uri = $res['uri'];

// Redirect to the upi:// URI
header("Location: " . $uri);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPI Payment | Waamini</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #25D366;
            --primary-dark: #128C7E;
            --bg: #f0f2f5;
            --text: #1c1e21;
        }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: white;
            padding: 40px 30px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            max-width: 440px;
            width: 100%;
            text-align: center;
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .icon-box {
            width: 80px;
            height: 80px;
            background: #e7f9ee;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon-box svg {
            width: 40px;
            height: 40px;
            color: var(--primary);
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 12px;
            color: #054d44;
        }
        p {
            font-size: 16px;
            color: #65676b;
            line-height: 1.5;
            margin-bottom: 32px;
        }
        .order-details {
            background: #f7f8fa;
            padding: 16px;
            border-radius: 16px;
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .amount {
            font-weight: 700;
            color: var(--text);
            font-size: 18px;
        }
        .order-ref {
            color: #8d949e;
            font-size: 14px;
        }
        .btn {
            display: block;
            background: var(--primary);
            color: white;
            padding: 18px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
        }
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 211, 102, 0.4);
        }
        .loader {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
            display: inline-block;
            vertical-align: middle;
            margin-right: 10px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1>Opening Payment App</h1>
        <p>Redirecting you to your UPI app. If it doesn't open in 2 seconds, please click below.</p>
        
        <div class="order-details">
            <span class="order-ref">Order #<?php echo $orderId; ?></span>
            <span class="amount">₹<?php echo number_format($amount, 2); ?></span>
        </div>

        <a href="<?php echo htmlspecialchars($uri); ?>" id="payBtn" class="btn">
            <span class="loader" id="loader"></span>
            Pay Now
        </a>
    </div>

    <script>
        const uri = "<?php echo $uri; ?>";
        
        // Immediate redirect attempt
        window.location.href = uri;

        // Visual feedback
        setTimeout(() => {
            document.getElementById('loader').style.display = 'none';
        }, 3000);
    </script>
</body>
</html>
