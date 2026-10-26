<?php
/**
 * Shopify webhooks for every connected store (and Shopify's mandatory GDPR webhooks).
 * Only requests signed with the app's client secret are accepted. Each delivery is logged once
 * (X-Shopify-Webhook-Id), so Shopify's retries never send a customer the same WhatsApp twice.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/shopify.php';
header('Content-Type: application/json; charset=utf-8');

function shw_out(int $code, string $msg): void { http_response_code($code); echo json_encode(['status' => $msg]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') shw_out(405, 'POST only');
$raw   = (string)file_get_contents('php://input');
$hmac  = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
$topic = strtolower(trim((string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '')));
$shop  = shopify_normalize_shop((string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? ''));
shopify_ensure_schema($conn);
$secrets = shopify_secrets_for_shop($conn, $shop);          // the shop's own app and/or the platform app
if (!$secrets) shw_out(503, 'Shopify app keys are not set on this server');
if (!shopify_verify_webhook($raw, $hmac, $secrets)) shw_out(401, 'invalid signature');
$whId  = mb_substr((string)($_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? $_SERVER['HTTP_X_SHOPIFY_EVENT_ID'] ?? ''), 0, 100);
if ($whId === '') $whId = 'body:' . sha1($raw);
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = [];

try {
    shopify_ensure_schema($conn);
    $store = $shop !== '' ? shopify_store_for_shop($conn, $shop) : null;
    $companyId = $store ? (int)$store['company_id'] : null;
    $resource = mb_substr((string)($payload['id'] ?? $payload['token'] ?? ''), 0, 60);

    // log first: a duplicate delivery hits the unique key and is answered 200 without doing anything
    $ins = $conn->prepare("INSERT IGNORE INTO shopify_events (company_id, shop_domain, topic, webhook_id, resource_id, status) VALUES (?, ?, ?, ?, ?, 'received')");
    $ins->bind_param('issss', $companyId, $shop, $topic, $whId, $resource);
    $ins->execute();
    if ($ins->affected_rows === 0) shw_out(200, 'duplicate ignored');
    $eventId = (int)$conn->insert_id;

    if (in_array($topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) {
        $note = shopify_compliance($conn, $topic, $shop, $payload); $status = 'processed';
    } elseif (!$store) {
        $note = 'store is not connected to WAAMINI'; $status = 'ignored';
    } elseif ($store['status'] !== 'active' && $topic !== 'app/uninstalled') {
        $note = 'store is disconnected'; $status = 'ignored';
    } elseif ($topic !== 'app/uninstalled' && !shopify_company_allowed($conn, (int)$store['company_id'])) {
        $note = 'Shopify add-on is off for this account'; $status = 'ignored';
    } else {
        $note = shopify_handle($conn, $store, $topic, $payload);
        $status = 'processed';
        $u = $conn->prepare("UPDATE shopify_stores SET last_event_at = NOW() WHERE id = ?");
        $u->bind_param('i', $store['id']); $u->execute();
    }
    $note = mb_substr($note, 0, 490);
    $u = $conn->prepare("UPDATE shopify_events SET status = ?, note = ? WHERE id = ?");
    $u->bind_param('ssi', $status, $note, $eventId); $u->execute();
    shw_out(200, 'ok');
} catch (\Throwable $e) {
    error_log('[shopify_webhook] ' . $topic . ' ' . $shop . ': ' . $e->getMessage());
    // let Shopify retry: remove the log row so the retry is not treated as a duplicate
    if (!empty($eventId)) { try { $d = $conn->prepare("DELETE FROM shopify_events WHERE id = ?"); $d->bind_param('i', $eventId); $d->execute(); } catch (\Throwable $x) {} }
    shw_out(500, 'temporary error');
}
