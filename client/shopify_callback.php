<?php
/**
 * Shopify OAuth redirect: Shopify sends the client back here after they approve WAAMINI.
 * Checks Shopify's signature and our one-time state, swaps the code for the store's access token
 * (saved encrypted), then subscribes the store's webhooks.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/shopify.php';

$back = static function (string $msg, string $type = 'err'): void {
    header('Location: shopify_connect.php?' . http_build_query([$type === 'ok' ? 'ok' : 'err' => $msg]));
    exit;
};

if (!shopify_configured((int)$companyId)) $back('No Shopify app keys yet. Add your own app on this page, or ask the WAAMINI team to set up the WAAMINI Shopify app.');
if (!empty($viewOnlyMode)) $back('Your account is in view-only mode.');

$shop  = shopify_normalize_shop((string)($_GET['shop'] ?? ''));
$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$want  = (array)($_SESSION['shopify_oauth'] ?? []);
unset($_SESSION['shopify_oauth']);

if (isset($_GET['error'])) $back('Shopify did not connect: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 160));
if ($shop === '' || $code === '') $back('Shopify did not send the store details. Please try connecting again.');
if (!shopify_verify_query($_GET, (int)$companyId)) $back('The reply from Shopify could not be verified. Please try connecting again.');
if ($state === '' || empty($want['state']) || !hash_equals((string)$want['state'], $state) || ($want['shop'] ?? '') !== $shop
    || (int)($want['company'] ?? 0) !== (int)$companyId || time() - (int)($want['at'] ?? 0) > 900) {
    $back('This connection link has expired. Please click Connect again.');
}

$tok = shopify_exchange_code($shop, $code, (int)$companyId);
if (!$tok['ok']) $back('Shopify did not give access: ' . $tok['error']);

$saved = shopify_save_store($conn, (int)$companyId, $shop, $tok['token'], $tok['scope']);
if (!$saved['ok']) $back($saved['error']);

$store = shopify_store_for_company($conn, (int)$companyId);
$wh = $store ? shopify_register_webhooks($conn, $store) : ['ok' => false, 'errors' => ['store not saved']];
if ($wh['ok']) $back('Connected to ' . $shop . '. Shopify events now reach WAAMINI.', 'ok');
$back('Connected to ' . $shop . ', but some Shopify events could not be set up: ' . mb_substr(implode('; ', $wh['errors']), 0, 300));
