<?php
/**
 * Razorpay + Stripe webhook listener.
 * Configure in Razorpay Dashboard → Webhooks → URL:
 *   https://your-domain/waamini/api/payment_webhook.php?company_id=YOUR_COMPANY_ID
 * Use the signing secret shown in Razorpay (store it in Payment Settings → Webhook signing secret).
 *
 * Stripe: Configure in Stripe Dashboard → Developers → Webhooks → Add endpoint:
 *   https://your-domain/waamini/api/payment_webhook.php?company_id=YOUR_COMPANY_ID
 * Use the endpoint signing secret (store it in Payment Settings → Webhook signing secret).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ecommerce_handler.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

waamini_ecommerce_ensure_schema($conn);

$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'company_id_required']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty_body']);
    exit;
}

$stmt = $conn->prepare("SELECT api_secret, webhook_secret, gateway FROM ecommerce_settings WHERE company_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'settings_unavailable']);
    exit;
}
$stmt->bind_param("i", $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'unknown_company']);
    exit;
}

$signingSecret = '';
$whEnc = (string)($row['webhook_secret'] ?? '');
if ($whEnc !== '') {
    $signingSecret = waamini_ecommerce_decrypt_field($whEnc);
}
if ($signingSecret === '') {
    $signingSecret = waamini_ecommerce_decrypt_field($row['api_secret'] ?? null);
    if ($signingSecret !== '') {
        error_log('[WAAMINI] payment_webhook: company_id=' . $companyId . ' using Key Secret for webhook HMAC; set Webhook signing secret in dashboard + settings for production.');
    }
}

if ($signingSecret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'signing_secret_not_configured']);
    exit;
}

$razorpaySig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$stripeSig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($razorpaySig === '' && $stripeSig === '') {
    http_response_code(401);
    echo json_encode(['error' => 'missing_signature']);
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

// 1. Handle Stripe if signature present
if ($stripeSig !== '') {
    $isStripeEvent = (isset($data['object']) && $data['object'] === 'event' && isset($data['type']));
    if ($isStripeEvent) {
        $stripeSecret = waamini_ecommerce_decrypt_field((string)($row['webhook_secret'] ?? ''));
        if ($stripeSecret === '') {
            http_response_code(503);
            echo json_encode(['error' => 'stripe_webhook_secret_not_configured']);
            exit;
        }
    }
}

// 2. Handle Razorpay if signature present (and not a Stripe event)
if ($razorpaySig !== '' && !(isset($data['object']) && $data['object'] === 'event')) {
    $expected = hash_hmac('sha256', $rawBody, $signingSecret);
    if (!hash_equals($expected, $razorpaySig)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_signature']);
        exit;
    }
}

function _waamini_stripe_verify_and_extract_event(array $data, string $rawBody, string $endpointSecret): ?array
{
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if ($sigHeader === '' || $endpointSecret === '') {
        return null;
    }

    // Stripe-Signature: t=...,v1=...,v1=...
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        $kv = trim($kv);
        if ($kv === '' || strpos($kv, '=') === false) continue;
        [$k, $v] = explode('=', $kv, 2);
        $parts[trim($k)][] = trim($v);
    }
    $t = isset($parts['t'][0]) ? (int)$parts['t'][0] : 0;
    $v1s = $parts['v1'] ?? [];
    if ($t <= 0 || empty($v1s)) {
        return null;
    }

    // 5 minute tolerance
    if (abs(time() - $t) > 300) {
        return null;
    }

    $signed = $t . '.' . $rawBody;
    $expected = hash_hmac('sha256', $signed, $endpointSecret);
    foreach ($v1s as $sig) {
        if (hash_equals($expected, (string)$sig)) {
            return $data;
        }
    }
    return null;
}

// Stripe event detection (object=event, has type)
$isStripeEvent = (isset($data['object']) && $data['object'] === 'event' && isset($data['type']));
if ($isStripeEvent) {
    // Prefer explicit endpoint secret; fall back to api_secret only for Razorpay.
    $stripeSecret = waamini_ecommerce_decrypt_field((string)($row['webhook_secret'] ?? ''));
    if ($stripeSecret === '') {
        http_response_code(503);
        echo json_encode(['error' => 'stripe_webhook_secret_not_configured']);
        exit;
    }
    $verified = _waamini_stripe_verify_and_extract_event($data, $rawBody, $stripeSecret);
    if ($verified === null) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_stripe_signature']);
        exit;
    }

    $type = (string)($verified['type'] ?? '');
    $obj = $verified['data']['object'] ?? null;
    if (!is_array($obj)) {
        echo json_encode(['received' => true, 'ignored' => 'missing_object']);
        exit;
    }

    // We create Checkout Sessions with client_reference_id=local order id
    if ($type === 'checkout.session.completed') {
        $paymentStatus = (string)($obj['payment_status'] ?? '');
        $mode = (string)($obj['mode'] ?? '');
        $sessionId = (string)($obj['id'] ?? '');
        $ref = isset($obj['client_reference_id']) ? (int)$obj['client_reference_id'] : 0;

        if ($mode !== 'payment' || $ref <= 0) {
            echo json_encode(['received' => true, 'ignored' => 'not_payment_or_missing_ref']);
            exit;
        }
        if ($paymentStatus !== 'paid') {
            echo json_encode(['received' => true, 'ignored' => 'not_paid']);
            exit;
        }

        $stmt_o = $conn->prepare(
            "SELECT o.id, o.company_id, o.contact_id, o.amount, o.currency, o.status, o.gateway, o.gateway_order_id, c.phone
             FROM ecommerce_orders o
             JOIN contacts c ON c.id = o.contact_id AND c.company_id = o.company_id
             WHERE o.id = ? AND o.company_id = ?
             LIMIT 1"
        );
        if (!$stmt_o) {
            http_response_code(500);
            echo json_encode(['error' => 'order_lookup_failed']);
            exit;
        }
        $stmt_o->bind_param("ii", $ref, $companyId);
        $stmt_o->execute();
        $order = $stmt_o->get_result()->fetch_assoc();
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'order_not_found']);
            exit;
        }

        $gw = (string)($order['gateway'] ?? '');
        if ($gw !== '' && $gw !== 'stripe') {
            echo json_encode(['received' => true, 'ignored' => 'gateway_mismatch']);
            exit;
        }
        if ($sessionId !== '' && !empty($order['gateway_order_id']) && $sessionId !== $order['gateway_order_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'stripe_session_id_mismatch']);
            exit;
        }
        if (($order['status'] ?? '') === 'paid') {
            echo json_encode(['received' => true, 'ok' => true, 'idempotent' => true]);
            exit;
        }
        if (($order['status'] ?? '') !== 'pending') {
            echo json_encode(['received' => true, 'ignored' => 'order_not_pending']);
            exit;
        }

        $becamePaid = waamini_mark_order_paid_if_pending(
            $conn,
            $ref,
            (int)$order['company_id'],
            (int)$order['contact_id']
        );
        if ($becamePaid) {
            waamini_payment_success_notifications($conn, $order);
        }
        echo json_encode(['received' => true, 'ok' => true, 'order_id' => $ref]);
        exit;
    }

    echo json_encode(['received' => true, 'ignored' => 'event_not_handled', 'type' => $type]);
    exit;
}

$event = (string)($data['event'] ?? '');
$localOrderId = 0;
$plinkEntity = null;

if ($event === 'payment_link.paid') {
    $plinkEntity = $data['payload']['payment_link']['entity'] ?? null;
} elseif ($event === 'payment_link.updated') {
    $plinkEntity = $data['payload']['payment_link']['entity'] ?? null;
    if (is_array($plinkEntity) && (($plinkEntity['status'] ?? '') !== 'paid')) {
        echo json_encode(['received' => true, 'ignored' => 'not_paid']);
        exit;
    }
}

if (is_array($plinkEntity)) {
    $notes = $plinkEntity['notes'] ?? [];
    if (isset($notes['local_order_id'])) {
        $localOrderId = (int)$notes['local_order_id'];
    }
}

if ($localOrderId <= 0) {
    echo json_encode(['received' => true, 'ignored' => 'no_local_order']);
    exit;
}

$plinkId = is_array($plinkEntity) ? (string)($plinkEntity['id'] ?? '') : '';

$stmt_o = $conn->prepare(
    "SELECT o.id, o.company_id, o.contact_id, o.amount, o.currency, o.status, o.gateway, o.gateway_order_id, c.phone
     FROM ecommerce_orders o
     JOIN contacts c ON c.id = o.contact_id AND c.company_id = o.company_id
     WHERE o.id = ? AND o.company_id = ?
     LIMIT 1"
);
if (!$stmt_o) {
    http_response_code(500);
    echo json_encode(['error' => 'order_lookup_failed']);
    exit;
}
$stmt_o->bind_param("ii", $localOrderId, $companyId);
$stmt_o->execute();
$order = $stmt_o->get_result()->fetch_assoc();
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'order_not_found']);
    exit;
}

$gw = (string)($order['gateway'] ?? '');
if ($gw !== '' && $gw !== 'razorpay') {
    echo json_encode(['received' => true, 'ignored' => 'gateway_mismatch']);
    exit;
}

if ($plinkId !== '' && !empty($order['gateway_order_id']) && $plinkId !== $order['gateway_order_id']) {
    http_response_code(400);
    echo json_encode(['error' => 'payment_link_id_mismatch']);
    exit;
}

if (($order['status'] ?? '') === 'paid') {
    echo json_encode(['received' => true, 'ok' => true, 'idempotent' => true]);
    exit;
}

if (($order['status'] ?? '') !== 'pending') {
    echo json_encode(['received' => true, 'ignored' => 'order_not_pending']);
    exit;
}

$becamePaid = waamini_mark_order_paid_if_pending(
    $conn,
    $localOrderId,
    (int)$order['company_id'],
    (int)$order['contact_id']
);

if ($becamePaid) {
    waamini_payment_success_notifications($conn, $order);
}

echo json_encode(['received' => true, 'ok' => true, 'order_id' => $localOrderId]);
