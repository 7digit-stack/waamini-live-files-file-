<?php
/**
 * WAAMINI E-Commerce Handler
 * Handles Payment Link Generation for Razorpay and Stripe
 */

require_once dirname(__DIR__) . '/services/EncryptionService.php';

/**
 * Decrypt ecommerce_settings credential columns (supports legacy plaintext stored in DB).
 */
function waamini_ecommerce_decrypt_field(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (!function_exists('decrypt_secret')) {
        return (string)$stored;
    }
    $plain = decrypt_secret((string)$stored);
    // If decrypt_secret returns empty string, treat it as a failure and keep original ciphertext/plaintext.
    if ($plain !== null && $plain !== '') {
        return (string)$plain;
    }
    return (string)$stored;
}

/**
 * Decrypt api_key / api_secret on a settings row before gateway API calls.
 */
function waamini_ecommerce_decrypt_settings(array $row): array
{
    $row['api_key'] = waamini_ecommerce_decrypt_field($row['api_key'] ?? null);
    $row['api_secret'] = waamini_ecommerce_decrypt_field($row['api_secret'] ?? null);
    return $row;
}

/**
 * Never echo Stripe secret keys (or generic sk_/rk_ tokens) into HTML value attributes.
 */
function waamini_ecommerce_mask_api_key_in_form(string $gateway, string $decryptedKey): bool
{
    if ($decryptedKey === '') {
        return false;
    }
    if (strtolower($gateway) === 'stripe') {
        return true;
    }
    return (bool)preg_match('/^(sk_|rk_)/', $decryptedKey);
}

/**
 * Ensure optional ecommerce_settings columns exist (migration-lite for older installs).
 */
function waamini_ecommerce_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $chk = @$conn->query("SHOW COLUMNS FROM ecommerce_settings LIKE 'webhook_secret'");
    if ($chk && $chk->num_rows === 0) {
        @$conn->query("ALTER TABLE ecommerce_settings ADD COLUMN webhook_secret TEXT NULL COMMENT 'Encrypted Razorpay webhook signing secret'");
    }
    $chk2 = @$conn->query("SHOW COLUMNS FROM ecommerce_settings LIKE 'updated_at'");
    if ($chk2 && $chk2->num_rows === 0) {
        @$conn->query("ALTER TABLE ecommerce_settings ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
    }
    $chk3 = @$conn->query("SHOW COLUMNS FROM ecommerce_settings LIKE 'upi_alert_numbers'");
    if ($chk3 && $chk3->num_rows === 0) {
        @$conn->query("ALTER TABLE ecommerce_settings ADD COLUMN upi_alert_numbers VARCHAR(255) DEFAULT NULL");
    }
}

/**
 * Notify business/admin numbers that the customer selected UPI payment.
 * Uses automation_queue type "text" so QueueWorker will send it.
 */
function waamini_notify_upi_choice(mysqli $conn, int $companyId, int $orderId, float $amount, string $customerPhone, string $customerName = '', string $itemsDescription = ''): void
{
    $companyId = (int)$companyId;
    $orderId = (int)$orderId;
    if ($companyId <= 0 || $orderId <= 0) return;

    // Optional: avoid spamming repeats for same order
    try {
        $conn->query("ALTER TABLE ecommerce_orders ADD COLUMN upi_choice_notified_at DATETIME DEFAULT NULL");
    } catch (\Throwable $e) { /* exists */ }

    try {
        $stChk = $conn->prepare("SELECT upi_choice_notified_at FROM ecommerce_orders WHERE id=? AND company_id=? LIMIT 1");
        if ($stChk) {
            $stChk->bind_param('ii', $orderId, $companyId);
            $stChk->execute();
            $row = $stChk->get_result()->fetch_assoc();
            $ts = isset($row['upi_choice_notified_at']) ? strtotime((string)$row['upi_choice_notified_at']) : false;
            if ($ts && (time() - $ts) < 300) { // 5 min de-dupe window
                return;
            }
        }
    } catch (\Throwable $e) {}

    $st = $conn->prepare("SELECT upi_alert_numbers FROM ecommerce_settings WHERE company_id=? LIMIT 1");
    if (!$st) return;
    $st->bind_param('i', $companyId);
    $st->execute();
    $s = $st->get_result()->fetch_assoc();
    $numsRaw = (string)($s['upi_alert_numbers'] ?? '');
    $numsRaw = preg_replace('/[^0-9,]/', '', $numsRaw);
    $nums = array_values(array_filter(array_map('trim', explode(',', $numsRaw))));
    if (empty($nums)) return;

    $amountStr = number_format((float)$amount, 2, '.', '');
    $cust = preg_replace('/\D+/', '', (string)$customerPhone);
    $custShow = $cust !== '' ? ('+' . $cust) : 'Customer';

    $name = trim($customerName);
    $nameLine = $name !== '' ? ("Customer: *" . $name . "* (" . $custShow . ")") : ("Customer: " . $custShow);
    $desc = trim($itemsDescription);
    if (function_exists('mb_substr')) {
        $desc = (string)mb_substr($desc, 0, 140, 'UTF-8');
    } else {
        $desc = substr($desc, 0, 140);
    }
    $desc = preg_replace('/\s+/u', ' ', (string)$desc);
    $descLine = $desc !== '' ? ("\nFor: " . $desc) : '';

    $msg = "🔔 *UPI payment selected*\n\nOrder: *#ORD-{$orderId}*\nAmount: *₹{$amountStr}*\n{$nameLine}{$descLine}\n\nStatus: pending (manual confirmation)";

    foreach ($nums as $to) {
        $to = preg_replace('/\D+/', '', $to);
        if ($to === '') continue;
        $ins = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at) VALUES (?, 0, 'text', ?, ?, 'pending', NOW(), NOW())");
        if ($ins) {
            $ins->bind_param('iss', $companyId, $to, $msg);
            @$ins->execute();
        }
    }

    try {
        $up = $conn->prepare("UPDATE ecommerce_orders SET upi_choice_notified_at=NOW() WHERE id=? AND company_id=? LIMIT 1");
        if ($up) {
            $up->bind_param('ii', $orderId, $companyId);
            @$up->execute();
        }
    } catch (\Throwable $e) {}
}

/**
 * Transactional pending → paid update + abandoned cart recovery. Returns true if this call flipped the row to paid.
 */
function waamini_mark_order_paid_if_pending(mysqli $conn, int $orderId, int $companyId, int $contactId): bool
{
    $orderId = (int)$orderId;
    $companyId = (int)$companyId;
    $contactId = (int)$contactId;
    if ($orderId <= 0 || $companyId <= 0) {
        return false;
    }

    $conn->begin_transaction();
    try {
        $stmt_lock = $conn->prepare("SELECT status FROM ecommerce_orders WHERE id=? AND company_id=? FOR UPDATE");
        if (!$stmt_lock) {
            $conn->rollback();
            return false;
        }
        $stmt_lock->bind_param("ii", $orderId, $companyId);
        $stmt_lock->execute();
        $locked = $stmt_lock->get_result()->fetch_assoc();
        if (($locked['status'] ?? '') !== 'pending') {
            $conn->commit();
            return false;
        }

        $stmt_upd = $conn->prepare("UPDATE ecommerce_orders SET status='paid', updated_at=NOW() WHERE id=? AND company_id=? AND status='pending'");
        if (!$stmt_upd) {
            $conn->rollback();
            return false;
        }
        $stmt_upd->bind_param("ii", $orderId, $companyId);
        $stmt_upd->execute();
        $changed = $stmt_upd->affected_rows > 0;

        if ($changed) {
            $stmt_ec = $conn->prepare(
                "UPDATE ecommerce_carts SET status='recovered' WHERE company_id=? AND contact_id=? AND status='abandoned' ORDER BY id DESC LIMIT 1"
            );
            if ($stmt_ec) {
                $stmt_ec->bind_param('ii', $companyId, $contactId);
                @$stmt_ec->execute();
            }
        }
        $conn->commit();
        return $changed;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[WAAMINI] waamini_mark_order_paid_if_pending: ' . $e->getMessage());
        return false;
    }
}

/**
 * Loyalty + WhatsApp queue + conversation updates after a verified paid transition (call only when order just became paid).
 */
function waamini_payment_success_notifications(mysqli $conn, array $order): void
{
    $orderId = (int)($order['id'] ?? 0);
    $companyId = (int)($order['company_id'] ?? 0);
    $contactId = (int)($order['contact_id'] ?? 0);
    if ($orderId <= 0 || $companyId <= 0 || $contactId <= 0) {
        return;
    }

    $amount = floatval($order['amount'] ?? 0);
    $currency = (string)($order['currency'] ?? 'INR');
    $phone = (string)($order['phone'] ?? '');

    require_once dirname(__DIR__) . '/includes/loyalty_handler.php';
    $stmt_ls = $conn->prepare("SELECT points_per_order_fixed, points_per_order_percent FROM loyalty_settings WHERE company_id = ? AND is_active = 1 LIMIT 1");
    if ($stmt_ls) {
        $stmt_ls->bind_param("i", $companyId);
        $stmt_ls->execute();
        $l_settings = $stmt_ls->get_result()->fetch_assoc();
        if ($l_settings) {
            $earned_pts = $l_settings['points_per_order_fixed'] + ($amount * ($l_settings['points_per_order_percent'] / 100));
            add_loyalty_points($companyId, $contactId, $earned_pts, "Completed Order #$orderId");
        }
    }

    $msg = "✅ *Payment Received!*\n\nThank you! We have received your payment of *$currency " . number_format($amount, 2) . "* for Order #$orderId.\n\nYour order is now being processed.";

    $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status) VALUES (?, ?, 'payment_success', ?, 'pending')");
    if ($stmt_q) {
        $stmt_q->bind_param("iis", $companyId, $contactId, $msg);
        $stmt_q->execute();
    }

    $stmtM = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, status) VALUES (?, ?, 'outgoing', 'text', ?, 'queued')");
    if ($stmtM) {
        $stmtM->bind_param("iis", $companyId, $contactId, $msg);
        $stmtM->execute();
    }

    $last = "Paid: $currency " . number_format($amount, 2);
    $stmt_conv = $conn->prepare("UPDATE chat_conversations SET last_message=?, last_message_at=NOW() WHERE contact_id=? AND company_id=?");
    if ($stmt_conv) {
        $stmt_conv->bind_param("sii", $last, $contactId, $companyId);
        $stmt_conv->execute();
    }

    $stmt_ag = $conn->prepare("SELECT phone FROM users WHERE company_id = ? AND role = 'admin' LIMIT 1");
    if ($stmt_ag) {
        $stmt_ag->bind_param("i", $companyId);
        $stmt_ag->execute();
        $agent = $stmt_ag->get_result()->fetch_assoc();

        if ($agent && !empty($agent['phone'])) {
            $agentMsg = "💰 *New Payment Received!*\n\nOrder #$orderId\nAmount: *$currency " . number_format($amount, 2) . "*\nCustomer: " . $phone . "\n\nCheck your dashboard for details.";
            $stmt_an = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?, 0, 'agent_notification', ?, NOW())");
            if ($stmt_an) {
                $stmt_an->bind_param("is", $companyId, $agentMsg);
                $stmt_an->execute();
            }
        }
    }
}

/**
 * Generate UPI QR Code Link and URI
 */
function generate_upi_qr($vpa, $name, $amount, $ref = '')
{
    $vpa = trim((string)$vpa);
    $ref = trim((string)$ref);
    $amt = number_format((float)$amount, 2, '.', '');
    $nameEnc = urlencode((string)$name);
    $uri = "upi://pay?pa=" . urlencode($vpa) . "&pn=" . $nameEnc . "&am=" . $amt . "&cu=INR&tn=" . urlencode($ref);
    // Use a reliable QR code generator API (GoQR.me)
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($uri);
    return ["url" => $qrUrl, "uri" => $uri];
}

function create_payment_link($contactId, $amount, $companyId, $itemsDescription = "Order from WhatsApp", $method = 'razorpay')
{
    global $conn;

    $contactId = intval($contactId);
    $companyId = intval($companyId);
    $amount = (float)$amount;
    if ($contactId <= 0 || $companyId <= 0 || $amount <= 0) {
        return ["error" => "Invalid payment parameters."];
    }

    // 1. Get Settings
    $stmt = $conn->prepare("SELECT * FROM ecommerce_settings WHERE company_id = ? AND is_active = 1");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    if (!$settings)
        return ["error" => "E-commerce settings not configured or inactive."];
    $settings = waamini_ecommerce_decrypt_settings($settings);

    // 2. Get Contact Details
    $stmtC = $conn->prepare("SELECT name, phone FROM contacts WHERE id = ? AND company_id = ? LIMIT 1");
    $stmtC->bind_param("ii", $contactId, $companyId);
    $stmtC->execute();
    $contact = $stmtC->get_result()->fetch_assoc();
    if (!$contact) {
        return ["error" => "Contact not found."];
    }
    $custName = $contact['name'] ?: 'Customer';
    $custPhone = $contact['phone'];

    $gateway = ($method === 'upi') ? 'upi' : $settings['gateway'];
    $apiKey = $settings['api_key'];
    $apiSecret = $settings['api_secret'];
    $currency = $settings['currency'] ?: 'INR';

    $orderId = 0;
    // Create local order record
    $stmt_ord = $conn->prepare("INSERT INTO ecommerce_orders (company_id, contact_id, amount, currency, status, gateway, payment_method) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
    $stmt_ord->bind_param("iidsss", $companyId, $contactId, $amount, $currency, $gateway, $method);
    $stmt_ord->execute();
    $orderId = $stmt_ord->insert_id;

    if ($method === 'upi') {
        $vpa = $settings['upi_id'];
        $bizName = $settings['upi_name'] ?: 'WhatsApp Order';
        if (empty($vpa)) return ["error" => "UPI VPA not configured."];
        
        $ref = "ORD-$orderId";
        $upiPayload = array_merge(generate_upi_qr($vpa, $bizName, $amount, $ref), ["order_id" => $orderId]);
        // Notify internal number(s) that UPI was chosen
        waamini_notify_upi_choice($conn, $companyId, $orderId, (float)$amount, (string)$custPhone, (string)$custName, (string)$itemsDescription);
        return $upiPayload;
    }

    if ($gateway === 'razorpay') {
        if (empty($apiKey) || empty($apiSecret)) {
            return ["error" => "Razorpay keys not configured."];
        }
        $ch = curl_init('https://api.razorpay.com/v1/payment_links');
        $payload = [
            "amount" => (int)round($amount * 100), // in paise
            "currency" => $currency,
            "accept_partial" => false,
            "description" => $itemsDescription,
            "customer" => [
                "name" => $custName,
                "contact" => $custPhone
            ],
            "notify" => ["sms" => false, "email" => false],
            "reminder_enable" => false,
            "notes" => ["local_order_id" => $orderId],
            "callback_url" => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']) . "/api/payment_success.php?oid=$orderId",
            "callback_method" => "get"
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "$apiKey:$apiSecret",
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ["error" => "Razorpay request failed: " . $err];
        }
        $resJson = json_decode($response, true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (isset($resJson['short_url'])) {
            $link = $resJson['short_url'];
            $glid = $resJson['id'];
            $stmt_upd = $conn->prepare("UPDATE ecommerce_orders SET gateway_order_id = ? WHERE id = ? AND company_id = ? LIMIT 1");
            if ($stmt_upd) {
                $stmt_upd->bind_param("sii", $glid, $orderId, $companyId);
                $stmt_upd->execute();
            }
            return ["url" => $link, "order_id" => $orderId, "gateway_order_id" => $glid, "gateway" => "razorpay"];
        }
        else {
            $desc = $resJson['error']['description'] ?? ('HTTP ' . $httpCode);
            return ["error" => "Razorpay Error: " . $desc];
        }
    }
    elseif ($gateway === 'stripe') {
        if (empty($apiKey)) {
            return ["error" => "Stripe secret key not configured."];
        }
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        $fields = [
            'success_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']) . "/api/payment_success.php?oid=$orderId",
            'cancel_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']) . "/client/whatsapp.php?c=$contactId",
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][product_data][name]' => $itemsDescription,
            'line_items[0][price_data][unit_amount]' => (int)round($amount * 100),
            'line_items[0][quantity]' => 1,
            'mode' => 'payment',
            'client_reference_id' => $orderId
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "$apiKey:", // Stripe uses Key as username, no secret needed if using Secret Key
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ["error" => "Stripe request failed: " . $err];
        }
        $resJson = json_decode($response, true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (isset($resJson['url'])) {
            $link = $resJson['url'];
            $glid = $resJson['id'];
            $stmt_upd = $conn->prepare("UPDATE ecommerce_orders SET gateway_order_id = ? WHERE id = ? AND company_id = ? LIMIT 1");
            if ($stmt_upd) {
                $stmt_upd->bind_param("sii", $glid, $orderId, $companyId);
                $stmt_upd->execute();
            }
            return ["url" => $link, "order_id" => $orderId, "gateway_order_id" => $glid, "gateway" => "stripe"];
        }
        else {
            $desc = $resJson['error']['message'] ?? ('HTTP ' . $httpCode);
            return ["error" => "Stripe Error: " . $desc];
        }
    }

    return ["error" => "Unsupported Gateway"];
}
?>
