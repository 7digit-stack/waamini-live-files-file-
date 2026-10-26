<?php
/**
 * WAAMINI <-> Shopify.
 *
 * One Shopify app (Dev Dashboard) for the whole platform; each client connects their store with Shopify's
 * OAuth screen (client/shopify_connect.php -> client/shopify_callback.php). The store's access token is kept
 * encrypted in shopify_stores. Shopify then posts events to api/shopify_webhook.php, which checks Shopify's
 * HMAC signature, ignores duplicates and turns events into WhatsApp template messages on automation_queue
 * (sent by cron_automation_sender.php from the client's own WhatsApp number). Abandoned checkouts wait in
 * shopify_checkouts and are reminded by cron_shopify.php (chained into the every-minute worker).
 *
 * .env: SHOPIFY_CLIENT_ID, SHOPIFY_CLIENT_SECRET, optional SHOPIFY_API_VERSION, APP_URL (public https URL).
 */

require_once __DIR__ . '/phone_helper.php';
if (!class_exists('EncryptionService')) require_once __DIR__ . '/../services/EncryptionService.php';

const SHOPIFY_SCOPES = 'read_orders,read_customers,read_products,read_merchant_managed_fulfillment_orders,read_checkouts';

/** Webhook topics Waamini subscribes to (GraphQL enum => REST topic). */
const SHOPIFY_TOPICS = [
    'ORDERS_CREATE'    => 'orders/create',
    'ORDERS_FULFILLED' => 'orders/fulfilled',
    'ORDERS_CANCELLED' => 'orders/cancelled',
    'CHECKOUTS_CREATE' => 'checkouts/create',
    'CHECKOUTS_UPDATE' => 'checkouts/update',
    'CUSTOMERS_CREATE' => 'customers/create',
    'APP_UNINSTALLED'  => 'app/uninstalled',
];

/** Events a client can switch on, with the meaning of each template variable. */
function shopify_events(): array
{
    return [
        'order_created'   => ['label' => 'Order confirmation', 'desc' => 'When a customer places a prepaid order.', 'vars' => ['Customer first name', 'Order number', 'Order total', 'Store name'], 'default_on' => true, 'category' => 'UTILITY',
                              'sample' => "Hi {{1}}, thank you for your order {{2}} of {{3}} at {{4}}. We will update you here when it ships."],
        'order_cod'       => ['label' => 'Cash on Delivery confirmation', 'desc' => 'When a customer places a COD order. Ask them to confirm.', 'vars' => ['Customer first name', 'Order number', 'Order total', 'Store name'], 'default_on' => true, 'category' => 'UTILITY',
                              'sample' => "Hi {{1}}, your Cash on Delivery order {{2}} of {{3}} at {{4}} is received. Please reply YES to confirm it."],
        'order_shipped'   => ['label' => 'Shipping update', 'desc' => 'When the order is fulfilled (shipped) in Shopify.', 'vars' => ['Customer first name', 'Order number', 'Tracking link or number', 'Courier'], 'default_on' => true, 'category' => 'UTILITY',
                              'sample' => "Hi {{1}}, good news! Your order {{2}} has shipped with {{4}}. Track it here: {{3}}"],
        'order_cancelled' => ['label' => 'Order cancelled', 'desc' => 'When the order is cancelled in Shopify.', 'vars' => ['Customer first name', 'Order number', 'Store name'], 'default_on' => false, 'category' => 'UTILITY',
                              'sample' => "Hi {{1}}, your order {{2}} at {{3}} has been cancelled. Reply here if you have any questions."],
        'cart_reminder'   => ['label' => 'Abandoned checkout reminder', 'desc' => 'When a shopper leaves checkout without paying (marketing: needs consent).', 'vars' => ['Customer first name', 'Checkout link', 'Cart total', 'Store name'], 'default_on' => false, 'category' => 'MARKETING',
                              'sample' => "Hi {{1}}, you left items worth {{3}} in your cart at {{4}}. Complete your order here: {{2}}"],
    ];
}

/* ------------------------------------------------------------------ config */

function shopify_env(string $k, string $default = ''): string
{
    $v = class_exists('\Waamini\Config\Env') ? \Waamini\Config\Env::get($k, '') : '';
    if ($v === '' || $v === null) $v = $_ENV[$k] ?? getenv($k);
    return ($v === false || $v === null || $v === '') ? $default : trim((string)$v);
}

/**
 * Shopify app keys. Order: the client's own Shopify app (client > Shopify > Shopify app)
 * -> the platform app saved in Admin > Shopify app -> SHOPIFY_CLIENT_ID / SHOPIFY_CLIENT_SECRET in .env.
 * $companyId = 0 returns the platform keys. 'source' says where they came from.
 */
function shopify_cfg(int $companyId = 0): array
{
    global $conn;
    $ver = shopify_env('SHOPIFY_API_VERSION', '2026-07');
    if ($companyId > 0 && $conn instanceof mysqli) {
        $own = shopify_own_app($conn, $companyId);
        if ($own) return ['client_id' => $own['client_id'], 'client_secret' => $own['client_secret'], 'api_version' => $ver, 'source' => 'client'];
    }
    if ($conn instanceof mysqli) {
        $p = shopify_platform_app($conn);
        if ($p['client_id'] !== '' && $p['client_secret'] !== '') return $p + ['api_version' => $ver, 'source' => 'admin'];
    }
    $id = shopify_env('SHOPIFY_CLIENT_ID'); $sec = shopify_env('SHOPIFY_CLIENT_SECRET');
    return ['client_id' => $id, 'client_secret' => $sec, 'api_version' => $ver, 'source' => ($id !== '' && $sec !== '') ? 'env' : 'none'];
}

function shopify_configured(int $companyId = 0): bool
{
    $c = shopify_cfg($companyId);
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** Platform app keys saved in Admin > Shopify app (system_settings, secret encrypted). */
function shopify_platform_app(mysqli $conn): array
{
    $out = ['client_id' => '', 'client_secret' => ''];
    try {
        $r = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('shopify_client_id','shopify_client_secret')");
        while ($r && ($x = $r->fetch_assoc())) {
            if ($x['setting_key'] === 'shopify_client_id') $out['client_id'] = trim((string)$x['setting_value']);
            if ($x['setting_key'] === 'shopify_client_secret' && (string)$x['setting_value'] !== '') $out['client_secret'] = (string)(EncryptionService::decrypt_secret((string)$x['setting_value']) ?? '');
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Saves the platform keys. An empty secret keeps the saved one. */
function shopify_save_platform_app(mysqli $conn, string $clientId, string $secret): void
{
    $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'shopify') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $k = 'shopify_client_id'; $v = trim($clientId);
    $st->bind_param('ss', $k, $v); $st->execute();
    if (trim($secret) !== '') {
        $k = 'shopify_client_secret'; $v = EncryptionService::encrypt_secret(trim($secret));
        $st->bind_param('ss', $k, $v); $st->execute();
    }
}

/** A client's own Shopify app (optional). */
function shopify_own_app(mysqli $conn, int $companyId): ?array
{
    shopify_ensure_schema($conn);
    $st = $conn->prepare("SELECT client_id, client_secret FROM shopify_apps WHERE company_id = ? AND use_own = 1 LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $companyId); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$r || trim((string)$r['client_id']) === '' || (string)$r['client_secret'] === '') return null;
    $sec = (string)(EncryptionService::decrypt_secret((string)$r['client_secret']) ?? '');
    return $sec === '' ? null : ['client_id' => trim((string)$r['client_id']), 'client_secret' => $sec];
}

/** Every secret that may have signed a request for this shop: its company's own app first, then the platform app. */
function shopify_secrets_for_shop(mysqli $conn, string $shop): array
{
    $list = [];
    $store = $shop !== '' ? shopify_store_for_shop($conn, $shop) : null;
    if ($store) { $own = shopify_own_app($conn, (int)$store['company_id']); if ($own) $list[] = $own['client_secret']; }
    $p = shopify_cfg(0);
    if ($p['client_secret'] !== '') $list[] = $p['client_secret'];
    return array_values(array_unique($list));
}

/** Public https base of this Waamini install (Shopify only calls https addresses). */
function shopify_base_url(): string
{
    $app = rtrim(shopify_env('APP_URL'), '/');
    if (preg_match('#^https://#i', $app) && !preg_match('#//(localhost|127\.)#i', $app)) return $app;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https://' : 'http://') . $host;
}

function shopify_webhook_url(): string { return shopify_base_url() . '/api/shopify_webhook.php'; }
function shopify_redirect_url(): string { return shopify_base_url() . '/client/shopify_callback.php'; }

/* ------------------------------------------------------------------ schema */

function shopify_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS shopify_stores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        shop_domain VARCHAR(190) NOT NULL,
        shop_name VARCHAR(190) NULL,
        currency VARCHAR(8) NULL,
        access_token TEXT NULL,
        scopes VARCHAR(500) NULL,
        status ENUM('active','disconnected') NOT NULL DEFAULT 'active',
        webhooks_ok TINYINT(1) NOT NULL DEFAULT 0,
        webhook_note VARCHAR(500) NULL,
        settings_json TEXT NULL,
        connected_at DATETIME NULL,
        disconnected_at DATETIME NULL,
        last_event_at DATETIME NULL,
        UNIQUE KEY uq_company (company_id),
        UNIQUE KEY uq_shop (shop_domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS shopify_apps (
        company_id INT PRIMARY KEY,
        use_own TINYINT(1) NOT NULL DEFAULT 0,
        client_id VARCHAR(120) NULL,
        client_secret TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS shopify_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        shop_domain VARCHAR(190) NOT NULL,
        topic VARCHAR(60) NOT NULL,
        webhook_id VARCHAR(100) NOT NULL,
        resource_id VARCHAR(60) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'received',
        note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_webhook (webhook_id),
        KEY idx_company (company_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS shopify_checkouts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        checkout_token VARCHAR(100) NOT NULL,
        phone VARCHAR(25) NULL,
        email VARCHAR(190) NULL,
        first_name VARCHAR(120) NULL,
        total VARCHAR(40) NULL,
        recover_url VARCHAR(1000) NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        due_at DATETIME NULL,
        reminded_at DATETIME NULL,
        reminder_note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_checkout (company_id, checkout_token),
        KEY idx_due (completed, reminded_at, due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ------------------------------------------------------------------ helpers */

/** "mystore", "mystore.myshopify.com", "https://mystore.myshopify.com/admin" -> "mystore.myshopify.com" (or '' when invalid). */
function shopify_normalize_shop(string $in): string
{
    $s = strtolower(trim($in));
    $s = preg_replace('#^https?://#', '', $s);
    $s = preg_replace('#/.*$#', '', (string)$s);
    if ($s !== '' && strpos($s, '.') === false) $s .= '.myshopify.com';
    return preg_match('/^[a-z0-9][a-z0-9-]{1,60}\.myshopify\.com$/', (string)$s) ? (string)$s : '';
}

/** Verifies the hmac Shopify adds to OAuth / app-launch query strings. */
function shopify_verify_query(array $query, int $companyId = 0): bool
{
    $secret = shopify_cfg($companyId)['client_secret'];
    $hmac = (string)($query['hmac'] ?? '');
    if ($secret === '' || $hmac === '') return false;
    unset($query['hmac'], $query['signature']);
    ksort($query);
    $parts = [];
    foreach ($query as $k => $v) {
        if (is_array($v)) $v = '["' . implode('", "', $v) . '"]';
        $parts[] = $k . '=' . $v;
    }
    return hash_equals(hash_hmac('sha256', implode('&', $parts), $secret), $hmac);
}

/** Verifies the X-Shopify-Hmac-Sha256 header of a webhook against the raw body. */
function shopify_verify_webhook(string $rawBody, string $hmacHeader, array $secrets = []): bool
{
    if (!$secrets) $secrets = [shopify_cfg()['client_secret']];
    if ($hmacHeader === '') return false;
    foreach ($secrets as $secret) {
        if ($secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), trim($hmacHeader))) return true;
    }
    return false;
}

function shopify_http(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'error' => $err, 'body' => is_string($resp) ? $resp : '', 'json' => is_string($resp) ? json_decode($resp, true) : null];
}

/** OAuth step 2: authorization code -> permanent offline access token. */
function shopify_exchange_code(string $shop, string $code, int $companyId = 0): array
{
    $c = shopify_cfg($companyId);
    $r = shopify_http('POST', "https://{$shop}/admin/oauth/access_token", ['Content-Type: application/json', 'Accept: application/json'],
        json_encode(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'code' => $code]));
    if ($r['code'] === 200 && !empty($r['json']['access_token'])) {
        return ['ok' => true, 'token' => (string)$r['json']['access_token'], 'scope' => (string)($r['json']['scope'] ?? '')];
    }
    return ['ok' => false, 'error' => $r['error'] ?: ('Shopify answered HTTP ' . $r['code'] . ': ' . mb_substr($r['body'], 0, 200))];
}

function shopify_graphql(string $shop, string $token, string $query, array $vars = []): array
{
    $v = shopify_cfg()['api_version'];
    $r = shopify_http('POST', "https://{$shop}/admin/api/{$v}/graphql.json",
        ['Content-Type: application/json', 'Accept: application/json', 'X-Shopify-Access-Token: ' . $token],
        json_encode(['query' => $query, 'variables' => (object)$vars], JSON_UNESCAPED_SLASHES));
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'error' => $r['error'] ?: ('HTTP ' . $r['code'] . ' ' . mb_substr($r['body'], 0, 200))];
    }
    if (!empty($r['json']['errors'])) {
        $e = $r['json']['errors'];
        return ['ok' => false, 'error' => is_array($e) ? (string)($e[0]['message'] ?? json_encode($e)) : (string)$e];
    }
    return ['ok' => true, 'data' => $r['json']['data'] ?? []];
}

/** Is the Shopify add-on on for this company (plan flag feat_shopify or Companies > Edit > Add-ons override)? */
function shopify_company_allowed(mysqli $conn, int $companyId): bool
{
    static $memo = [];
    if (isset($memo[$companyId])) return $memo[$companyId];
    $on = false;
    try {
        $st = $conn->prepare("SELECT sp.feat_shopify FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
                                WHERE s.company_id = ? ORDER BY (s.status IN ('active','trial')) DESC, s.id DESC LIMIT 1");
        if ($st) { $st->bind_param('i', $companyId); $st->execute(); $on = (int)($st->get_result()->fetch_row()[0] ?? 0) === 1; }
    } catch (\Throwable $e) { $on = false; }   // column not created yet = add-on never switched on
    try {
        $st = $conn->prepare("SELECT features_override FROM companies WHERE id = ?");
        if ($st) {
            $st->bind_param('i', $companyId); $st->execute();
            $ov = json_decode((string)($st->get_result()->fetch_row()[0] ?? ''), true);
            if (is_array($ov) && array_key_exists('feat_shopify', $ov)) $on = (bool)(int)$ov['feat_shopify'];
        }
    } catch (\Throwable $e) {}
    return $memo[$companyId] = $on;
}

/* ------------------------------------------------------------------ stores */

function shopify_store_for_company(mysqli $conn, int $companyId): ?array
{
    shopify_ensure_schema($conn);
    $st = $conn->prepare("SELECT * FROM shopify_stores WHERE company_id = ? LIMIT 1");
    $st->bind_param('i', $companyId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $r;
}

function shopify_store_for_shop(mysqli $conn, string $shop): ?array
{
    shopify_ensure_schema($conn);
    $st = $conn->prepare("SELECT * FROM shopify_stores WHERE shop_domain = ? LIMIT 1");
    $st->bind_param('s', $shop);
    $st->execute();
    $r = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $r;
}

function shopify_token(array $store): string
{
    $t = (string)($store['access_token'] ?? '');
    return $t === '' ? '' : (string)(EncryptionService::decrypt_secret($t) ?? '');
}

/** Per-store settings merged with defaults: events[key] = [on, template]; cart_delay; add_contacts. */
function shopify_settings(?array $store): array
{
    $saved = json_decode((string)($store['settings_json'] ?? ''), true);
    $saved = is_array($saved) ? $saved : [];
    $out = ['events' => [], 'cart_delay' => (int)($saved['cart_delay'] ?? 60), 'add_contacts' => (bool)($saved['add_contacts'] ?? true)];
    foreach (shopify_events() as $k => $e) {
        $s = $saved['events'][$k] ?? [];
        $out['events'][$k] = ['on' => (bool)($s['on'] ?? $e['default_on']), 'template' => (string)($s['template'] ?? '')];
    }
    if (!in_array($out['cart_delay'], [30, 60, 120, 240, 720, 1440], true)) $out['cart_delay'] = 60;
    return $out;
}

/** Saves the store after OAuth: one store per company, one company per store. */
function shopify_save_store(mysqli $conn, int $companyId, string $shop, string $token, string $scope): array
{
    shopify_ensure_schema($conn);
    $other = shopify_store_for_shop($conn, $shop);
    if ($other && (int)$other['company_id'] !== $companyId && $other['status'] === 'active') {
        return ['ok' => false, 'error' => 'This Shopify store is already connected to another WAAMINI account.'];
    }
    if ($other && (int)$other['company_id'] !== $companyId) {
        $d = $conn->prepare("DELETE FROM shopify_stores WHERE id = ?");
        $d->bind_param('i', $other['id']); $d->execute();
    }
    $enc = EncryptionService::encrypt_secret($token);
    $mine = shopify_store_for_company($conn, $companyId);
    if ($mine) {
        $u = $conn->prepare("UPDATE shopify_stores SET shop_domain=?, access_token=?, scopes=?, status='active', connected_at=NOW(), disconnected_at=NULL WHERE id=?");
        $u->bind_param('sssi', $shop, $enc, $scope, $mine['id']); $u->execute();
    } else {
        $i = $conn->prepare("INSERT INTO shopify_stores (company_id, shop_domain, access_token, scopes, status, connected_at) VALUES (?,?,?,?, 'active', NOW())");
        $i->bind_param('isss', $companyId, $shop, $enc, $scope); $i->execute();
    }
    // store name + currency for messages
    $info = shopify_graphql($shop, $token, '{ shop { name currencyCode } }');
    if ($info['ok']) {
        $n = (string)($info['data']['shop']['name'] ?? ''); $cur = (string)($info['data']['shop']['currencyCode'] ?? '');
        $u = $conn->prepare("UPDATE shopify_stores SET shop_name=?, currency=? WHERE company_id=?");
        $u->bind_param('ssi', $n, $cur, $companyId); $u->execute();
    }
    return ['ok' => true];
}

/** Creates the webhook subscriptions (safe to run again: existing ones count as done). */
function shopify_register_webhooks(mysqli $conn, array $store): array
{
    $token = shopify_token($store);
    $shop = (string)$store['shop_domain'];
    $url = shopify_webhook_url();
    $done = 0; $errors = [];
    if (!preg_match('#^https://#i', $url)) {
        $errors[] = 'Webhooks need a public https address (now ' . $url . '). Set APP_URL in .env.';
    } else {
        $existing = [];
        $ex = shopify_graphql($shop, $token, '{ webhookSubscriptions(first: 50) { edges { node { id topic endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } } } } }');
        if ($ex['ok']) {
            foreach ((array)($ex['data']['webhookSubscriptions']['edges'] ?? []) as $e) {
                $n = $e['node'] ?? [];
                if (($n['endpoint']['callbackUrl'] ?? '') === $url) $existing[(string)$n['topic']] = true;
            }
        }
        $m = 'mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } } }';
        foreach (array_keys(SHOPIFY_TOPICS) as $topic) {
            if (isset($existing[$topic])) { $done++; continue; }
            $r = shopify_graphql($shop, $token, $m, ['topic' => $topic, 'sub' => ['callbackUrl' => $url, 'format' => 'JSON']]);
            $ue = $r['ok'] ? (array)($r['data']['webhookSubscriptionCreate']['userErrors'] ?? []) : [];
            if ($r['ok'] && !$ue) { $done++; continue; }
            $msg = $r['ok'] ? (string)($ue[0]['message'] ?? 'unknown') : (string)$r['error'];
            if (stripos($msg, 'already been taken') !== false) { $done++; continue; }
            $errors[] = SHOPIFY_TOPICS[$topic] . ': ' . $msg;
        }
    }
    $ok = $errors ? 0 : 1;
    $note = $errors ? mb_substr(implode(' | ', $errors), 0, 490) : ('All ' . count(SHOPIFY_TOPICS) . ' events connected');
    $u = $conn->prepare("UPDATE shopify_stores SET webhooks_ok=?, webhook_note=? WHERE id=?");
    $u->bind_param('isi', $ok, $note, $store['id']); $u->execute();
    return ['ok' => (bool)$ok, 'done' => $done, 'errors' => $errors];
}

/* ------------------------------------------------------------------ messages */

/** Approved WhatsApp templates of the company: name => [name, language, vars]. */
function shopify_templates(mysqli $conn, int $companyId): array
{
    $out = [];
    $st = $conn->prepare("SELECT name, language, category, header_type, header_text, COALESCE(NULLIF(body_text,''), body) AS body
                          FROM message_templates WHERE company_id = ? AND UPPER(COALESCE(wa_status, status)) = 'APPROVED' ORDER BY name");
    if (!$st) return $out;
    $st->bind_param('i', $companyId);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $ht = strtolower((string)$t['header_type']);
        if (!in_array($ht, ['', 'none', 'text'], true)) continue;          // media headers need a file per message
        if ($ht === 'text' && strpos((string)$t['header_text'], '{{') !== false) continue;
        preg_match_all('/\{\{(\d+)\}\}/', (string)$t['body'], $m);
        $t['vars'] = $m[1] ? max(array_map('intval', $m[1])) : 0;
        $out[(string)$t['name']] = $t;
    }
    return $out;
}

function shopify_first_name(string $name): string
{
    $f = trim(explode(' ', trim($name))[0] ?? '');
    return $f !== '' ? $f : 'there';
}

function shopify_money($amount, string $currency): string
{
    $sym = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'SGD' => 'S$'][$currency] ?? ($currency !== '' ? $currency . ' ' : '');
    return $sym . number_format((float)$amount, 2);
}

/** Best phone of a Shopify order / checkout / customer payload, in WhatsApp format (+91...). */
function shopify_phone(array $p): string
{
    $cands = [
        $p['phone'] ?? '', $p['customer']['phone'] ?? '', $p['shipping_address']['phone'] ?? '',
        $p['billing_address']['phone'] ?? '', $p['customer']['default_address']['phone'] ?? '',
        $p['default_address']['phone'] ?? '', $p['sms_marketing_phone'] ?? '',
    ];
    $country = strtoupper((string)($p['shipping_address']['country_code'] ?? $p['billing_address']['country_code'] ?? 'IN'));
    $cc = ['IN' => '91', 'AE' => '971', 'SA' => '966', 'SG' => '65', 'MY' => '60', 'LK' => '94', 'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61'][$country] ?? '91';
    foreach ($cands as $c) {
        $n = wa_phone_normalize((string)$c, $cc);
        if ($n !== '') return $n;
    }
    return '';
}

function shopify_name(array $p): string
{
    $n = trim((string)(($p['customer']['first_name'] ?? '') . ' ' . ($p['customer']['last_name'] ?? '')));
    if ($n === '') $n = trim((string)(($p['shipping_address']['first_name'] ?? '') . ' ' . ($p['shipping_address']['last_name'] ?? '')));
    if ($n === '') $n = trim((string)(($p['billing_address']['first_name'] ?? '') . ' ' . ($p['billing_address']['last_name'] ?? '')));
    if ($n === '') $n = trim((string)(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')));
    return $n;
}

/** Finds or adds the contact (label "Shopify"). Returns contact id or 0. */
function shopify_contact(mysqli $conn, int $companyId, string $phone, string $name, string $email, bool $create = true): int
{
    if ($phone === '') return 0;
    $c = wa_find_contact_by_phone($conn, $companyId, $phone);
    if ($c) {
        $id = (int)$c['id'];
        $tags = (string)($c['tags'] ?? '');
        $setName = trim((string)($c['name'] ?? '')) === '' || preg_replace('/\D+/', '', (string)$c['name']) === preg_replace('/\D+/', '', $phone);
        $newTags = stripos($tags, 'shopify') === false ? trim($tags . ($tags !== '' ? ',' : '') . 'Shopify', ',') : $tags;
        $u = $conn->prepare("UPDATE contacts SET tags = ?, name = IF(?, ?, name), email = IF(COALESCE(email,'') = '', ?, email) WHERE id = ? AND company_id = ?");
        $sn = (int)($setName && $name !== '');
        $u->bind_param('sissii', $newTags, $sn, $name, $email, $id, $companyId); $u->execute();
        return $id;
    }
    if (!$create) return 0;
    $nm = $name !== '' ? mb_substr($name, 0, 100) : $phone;
    $i = $conn->prepare("INSERT INTO contacts (company_id, name, phone, email, tags, source, status) VALUES (?, ?, ?, ?, 'Shopify', 'shopify', 'active')");
    if (!$i) return 0;
    $em = mb_substr($email, 0, 100);
    $i->bind_param('isss', $companyId, $nm, $phone, $em);
    return $i->execute() ? (int)$conn->insert_id : 0;
}

function shopify_blocked(mysqli $conn, int $companyId, string $phone): bool
{
    $digits = ltrim($phone, '+'); $last10 = wa_phone_last10($phone);
    $st = $conn->prepare("SELECT 1 FROM blacklist WHERE company_id = ? AND (phone = ? OR phone = ? OR phone LIKE CONCAT('%', ?)) LIMIT 1");
    if (!$st) return false;
    $st->bind_param('isss', $companyId, $phone, $digits, $last10);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

/**
 * Queues the template chosen for $event. Returns ['ok'=>bool, 'note'=>string, 'queue_id'=>int].
 * $vals are the template variables in order ({{1}}, {{2}} ...).
 */
function shopify_queue(mysqli $conn, array $store, string $event, string $phone, int $contactId, array $vals, ?string $when = null): array
{
    $companyId = (int)$store['company_id'];
    if (!shopify_company_allowed($conn, $companyId)) return ['ok' => false, 'note' => 'Shopify add-on is off for this account'];
    $set = shopify_settings($store)['events'][$event] ?? null;
    if (!$set || !$set['on']) return ['ok' => false, 'note' => 'switched off'];
    if ($set['template'] === '') return ['ok' => false, 'note' => 'no template chosen'];
    if ($phone === '') return ['ok' => false, 'note' => 'no phone number on the order'];
    if (shopify_blocked($conn, $companyId, $phone)) return ['ok' => false, 'note' => 'number is on the opt-out list'];
    $tpl = shopify_templates($conn, $companyId)[$set['template']] ?? null;
    if (!$tpl) return ['ok' => false, 'note' => 'template "' . $set['template'] . '" is not approved'];

    $params = [];
    for ($i = 0; $i < (int)$tpl['vars']; $i++) {
        $v = trim((string)($vals[$i] ?? ''));
        $params[] = ['type' => 'text', 'text' => $v !== '' ? mb_substr($v, 0, 900) : '-'];
    }
    $payload = json_encode([
        'template_name' => $tpl['name'], 'language_code' => (string)($tpl['language'] ?: 'en'),
        'components' => $params ? [['type' => 'body', 'parameters' => $params]] : [],
        'source' => 'shopify', 'event' => $event,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // readable copy for the chat history
    $body = (string)$tpl['body'];
    foreach ($params as $i => $p) $body = str_replace('{{' . ($i + 1) . '}}', $p['text'], $body);
    $when = $when ?: date('Y-m-d H:i:s');
    $q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at, payload_json, notes) VALUES (?, ?, 'template', ?, ?, 'pending', ?, NOW(), ?, ?)");
    $notes = 'shopify:' . $event;
    $cid = $contactId > 0 ? $contactId : null;
    $q->bind_param('iisssss', $companyId, $cid, $phone, $body, $when, $payload, $notes);
    if (!$q->execute()) return ['ok' => false, 'note' => 'queue error: ' . $conn->error];
    return ['ok' => true, 'note' => 'queued "' . $tpl['name'] . '" for ' . $phone, 'queue_id' => (int)$conn->insert_id];
}

/* ------------------------------------------------------------------ events */

function shopify_is_cod(array $order): bool
{
    $g = strtolower(implode(' ', (array)($order['payment_gateway_names'] ?? [])) . ' ' . (string)($order['gateway'] ?? ''));
    return strpos($g, 'cash on delivery') !== false || preg_match('/\bcod\b/', $g) === 1;
}

/** Handles one verified webhook. Returns a short note for the event log. */
function shopify_handle(mysqli $conn, array $store, string $topic, array $p): string
{
    $companyId = (int)$store['company_id'];
    $settings = shopify_settings($store);
    $storeName = (string)($store['shop_name'] ?: $store['shop_domain']);
    $cur = (string)($p['currency'] ?? $p['presentment_currency'] ?? $store['currency'] ?? 'INR');

    switch ($topic) {
        case 'orders/create':
            $phone = shopify_phone($p); $name = shopify_name($p);
            $cid = shopify_contact($conn, $companyId, $phone, $name, (string)($p['email'] ?? ''), true);
            if (!empty($p['checkout_token'])) {
                // mark the checkout paid even if its checkout webhook arrives after this order (no reminder then)
                $tk = (string)$p['checkout_token'];
                $u = $conn->prepare("INSERT INTO shopify_checkouts (company_id, checkout_token, completed) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completed = 1");
                $u->bind_param('is', $companyId, $tk); $u->execute();
            }
            $event = shopify_is_cod($p) ? 'order_cod' : 'order_created';
            if ($event === 'order_cod' && !$settings['events']['order_cod']['on']) $event = 'order_created';
            $r = shopify_queue($conn, $store, $event, $phone, $cid, [shopify_first_name($name), (string)($p['name'] ?? ('#' . ($p['order_number'] ?? ''))), shopify_money($p['total_price'] ?? 0, $cur), $storeName]);
            return shopify_events()[$event]['label'] . ': ' . $r['note'];

        case 'orders/fulfilled':
            $phone = shopify_phone($p); $name = shopify_name($p);
            $cid = shopify_contact($conn, $companyId, $phone, $name, (string)($p['email'] ?? ''), true);
            $fl = array_values((array)($p['fulfillments'] ?? []));
            $f = $fl ? (array)$fl[count($fl) - 1] : [];
            $track = (string)(($f['tracking_urls'][0] ?? '') ?: ($f['tracking_url'] ?? '') ?: ($f['tracking_numbers'][0] ?? '') ?: ($f['tracking_number'] ?? '') ?: 'Ask us for details');
            $courier = (string)(($f['tracking_company'] ?? '') ?: 'our courier');
            $r = shopify_queue($conn, $store, 'order_shipped', $phone, $cid, [shopify_first_name($name), (string)($p['name'] ?? ''), $track, $courier]);
            return 'Shipping update: ' . $r['note'];

        case 'orders/cancelled':
            $phone = shopify_phone($p); $name = shopify_name($p);
            $cid = shopify_contact($conn, $companyId, $phone, $name, (string)($p['email'] ?? ''), true);
            $r = shopify_queue($conn, $store, 'order_cancelled', $phone, $cid, [shopify_first_name($name), (string)($p['name'] ?? ''), $storeName]);
            return 'Order cancelled: ' . $r['note'];

        case 'checkouts/create':
        case 'checkouts/update':
            $token = (string)($p['token'] ?? $p['cart_token'] ?? '');
            if ($token === '') return 'checkout without token: ignored';
            $phone = shopify_phone($p); $name = shopify_name($p);
            $done = !empty($p['completed_at']) ? 1 : 0;
            $url = mb_substr((string)($p['abandoned_checkout_url'] ?? ''), 0, 1000);
            $total = shopify_money($p['total_price'] ?? 0, $cur);
            $first = shopify_first_name($name);
            $email = mb_substr((string)($p['email'] ?? ''), 0, 190);
            $due = date('Y-m-d H:i:s', time() + 60 * $settings['cart_delay']);
            $st = $conn->prepare("INSERT INTO shopify_checkouts (company_id, checkout_token, phone, email, first_name, total, recover_url, completed, due_at)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE phone = IF(VALUES(phone) <> '', VALUES(phone), phone), email = IF(VALUES(email) <> '', VALUES(email), email),
                  first_name = VALUES(first_name), total = VALUES(total), recover_url = IF(VALUES(recover_url) <> '', VALUES(recover_url), recover_url),
                  completed = GREATEST(completed, VALUES(completed)), due_at = IF(reminded_at IS NULL, VALUES(due_at), due_at)");
            $st->bind_param('issssssis', $companyId, $token, $phone, $email, $first, $total, $url, $done, $due);
            $st->execute();
            $chk = $conn->prepare("SELECT completed FROM shopify_checkouts WHERE company_id = ? AND checkout_token = ?");
            $chk->bind_param('is', $companyId, $token); $chk->execute();
            if ($done || (int)($chk->get_result()->fetch_row()[0] ?? 0) === 1) return 'Checkout completed: no reminder needed';
            return $phone === '' ? 'Checkout saved (no phone yet)' : 'Checkout saved: reminder at ' . date('d M H:i', strtotime($due)) . ' if not paid';

        case 'customers/create':
            if (!$settings['add_contacts']) return 'contact sync off';
            $phone = shopify_phone($p);
            $cid = shopify_contact($conn, $companyId, $phone, shopify_name($p), (string)($p['email'] ?? ''), true);
            return $cid ? 'Contact saved' : 'customer has no phone number';

        case 'app/uninstalled':
            $u = $conn->prepare("UPDATE shopify_stores SET status = 'disconnected', access_token = NULL, webhooks_ok = 0, disconnected_at = NOW() WHERE id = ?");
            $u->bind_param('i', $store['id']); $u->execute();
            return 'App uninstalled in Shopify: store disconnected';
    }
    return 'not used';
}

/** GDPR webhooks Shopify requires from every app. */
function shopify_compliance(mysqli $conn, string $topic, string $shop, array $p): string
{
    $store = shopify_store_for_shop($conn, $shop);
    $companyId = $store ? (int)$store['company_id'] : 0;
    if ($topic === 'customers/redact' && $companyId) {
        $email = (string)($p['customer']['email'] ?? '');
        $phone = wa_phone_normalize((string)($p['customer']['phone'] ?? ''), '91');
        $d = $conn->prepare("DELETE FROM shopify_checkouts WHERE company_id = ? AND ((? <> '' AND email = ?) OR (? <> '' AND phone = ?))");
        $d->bind_param('issss', $companyId, $email, $email, $phone, $phone); $d->execute();
        return 'Customer data removed from Shopify tables (' . $d->affected_rows . ' checkouts)';
    }
    if ($topic === 'shop/redact' && $companyId) {
        $d = $conn->prepare("DELETE FROM shopify_checkouts WHERE company_id = ?"); $d->bind_param('i', $companyId); $d->execute();
        $d = $conn->prepare("DELETE FROM shopify_stores WHERE id = ?"); $d->bind_param('i', $store['id']); $d->execute();
        return 'Shop data removed';
    }
    return $topic === 'customers/data_request' ? 'Data request logged: Waamini only keeps contact name, phone and email (visible in Contacts)' : 'No data held for this shop';
}

/** Abandoned checkouts whose waiting time is over: send the reminder once. Run every minute. */
function shopify_process_due(mysqli $conn, int $limit = 50): int
{
    shopify_ensure_schema($conn);
    $rows = $conn->query("SELECT c.*, s.id AS store_id FROM shopify_checkouts c JOIN shopify_stores s ON s.company_id = c.company_id AND s.status = 'active'
                          WHERE c.completed = 0 AND c.reminded_at IS NULL AND c.due_at <= NOW() AND c.created_at > (NOW() - INTERVAL 3 DAY)
                          ORDER BY c.due_at LIMIT " . (int)$limit);
    $n = 0;
    foreach ($rows ? $rows->fetch_all(MYSQLI_ASSOC) : [] as $c) {
        $store = shopify_store_for_company($conn, (int)$c['company_id']);
        $note = 'skipped';
        if ($store) {
            $phone = (string)$c['phone'];
            if ($phone === '' || (string)$c['recover_url'] === '') {
                $note = $phone === '' ? 'no phone number' : 'no checkout link';
            } else {
                $cid = shopify_contact($conn, (int)$c['company_id'], $phone, (string)$c['first_name'], (string)$c['email'], true);
                $r = shopify_queue($conn, $store, 'cart_reminder', $phone, $cid, [shopify_first_name((string)$c['first_name']), (string)$c['recover_url'], (string)$c['total'], (string)($store['shop_name'] ?: $store['shop_domain'])]);
                $note = $r['note'];
                if ($r['ok']) $n++;
            }
        }
        $u = $conn->prepare("UPDATE shopify_checkouts SET reminded_at = NOW(), reminder_note = ? WHERE id = ?");
        $note = mb_substr($note, 0, 250);
        $u->bind_param('si', $note, $c['id']); $u->execute();
    }
    return $n;
}
