<?php
/**
 * Text-based WhatsApp catalog → qty → confirm → payment link flow.
 * Triggered from webhook.php before keyword bot / AI enqueue.
 */
require_once __DIR__ . '/ecommerce_handler.php';
require_once __DIR__ . '/whatsapp_settings.php';
function waamini_checkout_company_profile(mysqli $conn, int $companyId): array
{
    $st = $conn->prepare('SELECT name, phone, email, website, address FROM companies WHERE id=? LIMIT 1');
    if (!$st) {
        return [];
    }
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return is_array($row) ? $row : [];
}
function waamini_checkout_is_slash_command(string $lower): bool
{
    return $lower !== '' && $lower[0] === '/';
}
function waamini_checkout_build_maps_link(string $address): string
{
    $q = trim($address);
    if ($q === '') {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}
function waamini_checkout_sanitize_one_line(string $text, int $maxLen): string
{
    $t = trim($text);
    if ($t === '') return '';
    $t = preg_replace('/\s+/u', ' ', $t);
    if (function_exists('mb_substr')) {
        $t = (string)mb_substr($t, 0, $maxLen, 'UTF-8');
    } else {
        $t = substr($t, 0, $maxLen);
    }
    return trim($t);
}
function waamini_checkout_extract_feature(string $descRaw): string
{
    $t = trim((string)$descRaw);
    if ($t === '') {
        return '';
    }
    $t = strip_tags($t);
    $t = str_replace(["\r\n", "\r", "\n"], ' ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    // Stop at a natural sentence boundary if present
    $cut = null;
    foreach (['. ', '• ', ' - ', ' — '] as $sep) {
        $pos = strpos($t, $sep);
        if ($pos !== false && ($cut === null || $pos < $cut)) {
            $cut = $pos;
        }
    }
    if ($cut !== null && $cut > 20) {
        $t = substr($t, 0, $cut);
    }
    return waamini_checkout_sanitize_one_line($t, 74);
}
function waamini_checkout_ensure_schema(mysqli $conn): void
{
    try {
        $conn->query(
            'CREATE TABLE IF NOT EXISTS whatsapp_checkout_sessions (
                company_id INT NOT NULL,
                contact_id INT NOT NULL,
                step VARCHAR(32) NOT NULL DEFAULT \'idle\',
                payload_json LONGTEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (company_id, contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (\Throwable $e) {
        error_log('[WAAMINI checkout] schema: ' . $e->getMessage());
    }
    try {
        $conn->query('ALTER TABLE ecommerce_orders ADD COLUMN items_json JSON NULL');
    } catch (\Throwable $e) { /* exists */
    }
    try {
        $conn->query("ALTER TABLE ecommerce_orders ADD COLUMN order_source VARCHAR(64) DEFAULT NULL");
    } catch (\Throwable $e) { /* exists */
    }
    // Buyer address fields for delivery orders
    foreach ([
        "ALTER TABLE ecommerce_orders ADD COLUMN buyer_name VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE ecommerce_orders ADD COLUMN buyer_phone VARCHAR(30) DEFAULT NULL",
        "ALTER TABLE ecommerce_orders ADD COLUMN buyer_address TEXT DEFAULT NULL",
        "ALTER TABLE ecommerce_orders ADD COLUMN buyer_city VARCHAR(120) DEFAULT NULL",
        "ALTER TABLE ecommerce_orders ADD COLUMN buyer_pincode VARCHAR(16) DEFAULT NULL",
    ] as $sql) {
        try { $conn->query($sql); } catch (\Throwable $e) { /* exists */ }
    }
    // Catalog interaction tracking table
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS catalog_interactions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                contact_id INT DEFAULT NULL,
                customer_phone VARCHAR(30) NOT NULL,
                product_id INT DEFAULT NULL,
                product_name VARCHAR(255) NOT NULL,
                interaction_type ENUM('pay_now','enquire','buy_now','more_products','catalog_view') NOT NULL,
                auto_reply_sent TINYINT(1) DEFAULT 0,
                auto_reply_message TEXT DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cat_int_company (company_id, interaction_type),
                INDEX idx_cat_int_product (product_id),
                INDEX idx_cat_int_date (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (\Throwable $e) { /* exists */ }
    // Auto-reply templates table
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS catalog_auto_replies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                reply_type ENUM('pay_now','enquire','buy_now','catalog_view','order_confirm') NOT NULL,
                message_template TEXT NOT NULL,
                is_enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_company_type (company_id, reply_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (\Throwable $e) { /* exists */ }
}

// ============================================================
// CATALOG INTERACTION TRACKING
// ============================================================

/**
 * Log a catalog interaction (Pay Now, Enquire, Buy Now, etc.)
 */
function waamini_catalog_track_interaction(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $phone,
    string $productName,
    int $productId,
    string $type,
    bool $autoReplySent = false,
    string $autoReplyMsg = '',
    array $metadata = []
): void {
    try {
        $metaJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $st = $conn->prepare(
            "INSERT INTO catalog_interactions (company_id, contact_id, customer_phone, product_id, product_name, interaction_type, auto_reply_sent, auto_reply_message, metadata, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if ($st) {
            $st->bind_param('iisississ', $companyId, $contactId, $phone, $productId, $productName, $type, $autoReplySent, $autoReplyMsg, $metaJson);
            @$st->execute();
            $st->close();
        }
    } catch (\Throwable $e) {
        error_log('[WAAMINI catalog] track interaction failed: ' . $e->getMessage());
    }
}

/**
 * Default texts saved by the old template form. A stored template that still equals one of
 * these was never customised, so the current default is used instead.
 */
function waamini_catalog_legacy_default(string $type, string $text): bool
{
    static $legacy = [
        'buy_now' => "🛍️ *Great choice!*\n\n*{product_name}* — ₹{price}\n\nTap the link below to complete your purchase:\n{buy_url}\n\nOr reply *cancel* to go back.",
        'order_confirm' => "✅ *Order Confirmed!*\n\nThank you for ordering *{product_name}*.\nOur team will process your order shortly. 🙏",
    ];
    $norm = static fn(string $t) => trim(str_replace("\r\n", "\n", $t));
    return isset($legacy[$type]) && $norm($text) === $norm($legacy[$type]);
}

/**
 * Get auto-reply template for a given type and company.
 * Falls back to default templates if company has none configured.
 */
function waamini_catalog_get_auto_reply(mysqli $conn, int $companyId, string $type, array $vars = []): string
{
    $defaults = [
        'pay_now' => "{details}",
        'enquire' => "{details}",
        'buy_now' => "🛍️ *Great choice!*\n\n*{product_name}* — ₹{price}\n\nBuy it on our website:\n{buy_url}\n\n{next_step}",
        'catalog_view' => "📦 *Welcome to our catalog!*\n\nBrowse our products below. Tap *Pay Now* to order or *Enquire* for more details.",
        'order_confirm' => "✅ *Order confirmed!*\n\nThank you for ordering *{product_name}*. Here is how to pay:",
    ];

    // Try company-specific template first
    $msg = '';
    $st = $conn->prepare('SELECT message_template, is_enabled FROM catalog_auto_replies WHERE company_id=? AND reply_type=? LIMIT 1');
    if ($st) {
        $st->bind_param('is', $companyId, $type);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            // The business switched this reply off: send nothing from the template.
            if ((int)$row['is_enabled'] !== 1) {
                return '';
            }
            $msg = (string)$row['message_template'];
            if (waamini_catalog_legacy_default($type, $msg)) {
                $msg = '';
            }
        }
    }

    // Fallback to default
    if ($msg === '') {
        $msg = $defaults[$type] ?? '';
    }

    // Replace variables
    foreach ($vars as $key => $val) {
        $msg = str_replace('{' . $key . '}', (string)$val, $msg);
    }

    return $msg;
}

/**
 * Send auto-reply for a catalog interaction and track it.
 */
function waamini_catalog_send_auto_reply(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $phone,
    string $type,
    string $productName = '',
    int $productId = 0,
    float $price = 0,
    string $buyUrl = '',
    array $extraVars = [],
    string $templateType = ''
): bool {
    $vars = array_merge($extraVars, [
        'product_name' => $productName,
        'price' => number_format($price, 2),
        'buy_url' => $buyUrl,
    ]);

    $msg = waamini_catalog_get_auto_reply($conn, $companyId, $templateType !== '' ? $templateType : $type, $vars);
    // Switched off: fall back to the plain built-in message for steps the flow needs.
    if ($msg === '') {
        $msg = (string)($extraVars['details'] ?? '');
    }
    // A custom template must still tell the customer what to reply next.
    $nextStep = (string)($extraVars['next_step'] ?? '');
    if ($msg !== '' && $nextStep !== '' && strpos($msg, $nextStep) === false) {
        $msg = rtrim($msg) . "\n\n" . $nextStep;
    }
    if ($msg === '') {
        return false;
    }

    $result = waamini_checkout_send_text($conn, $companyId, $contactId, $phone, $msg);
    $sent = empty($result['error']);

    // Track the interaction
    waamini_catalog_track_interaction(
        $conn, $companyId, $contactId, $phone,
        $productName, $productId, $type,
        $sent, $msg,
        ['price' => $price, 'buy_url' => $buyUrl]
    );

    return $sent;
}

/**
 * Send a UPI QR code image to a WhatsApp contact.
 * Fetches UPI settings from ecommerce_settings, generates QR, sends image.
 */
function waamini_catalog_send_upi_qr_image(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $toDigits,
    float $amount,
    string $productName
): bool {
    $st = $conn->prepare('SELECT gateway, upi_id, upi_name FROM ecommerce_settings WHERE company_id=? AND is_active=1 LIMIT 1');
    if (!$st) return false;
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false;
    $row = waamini_ecommerce_decrypt_settings($row);
    $vpa = trim((string)($row['upi_id'] ?? ''));
    if ($vpa === '') return false;
    $bizName = trim((string)($row['upi_name'] ?? ''));
    if ($bizName === '') $bizName = 'Payment';
    $ref = $productName !== '' ? mb_substr($productName, 0, 30, 'UTF-8') : 'Catalog Order';
    $qr = generate_upi_qr($vpa, $bizName, $amount, $ref);
    $qrUrl = $qr['url'] ?? '';
    if ($qrUrl === '') return false;
    $result = waamini_checkout_send_image($conn, $companyId, $contactId, $toDigits, $qrUrl);
    return empty($result['error']);
}

function waamini_checkout_session_load(mysqli $conn, int $companyId, int $contactId): array
{
    $row = ['step' => 'idle', 'payload' => []];
    $st = $conn->prepare('SELECT step, payload_json FROM whatsapp_checkout_sessions WHERE company_id=? AND contact_id=? LIMIT 1');
    if (!$st) {
        return $row;
    }
    $st->bind_param('ii', $companyId, $contactId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$r) {
        return $row;
    }
    $row['step'] = (string)($r['step'] ?? 'idle');
    $pj = json_decode((string)($r['payload_json'] ?? ''), true);
    $row['payload'] = is_array($pj) ? $pj : [];
    return $row;
}
function waamini_checkout_session_save(mysqli $conn, int $companyId, int $contactId, string $step, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $st = $conn->prepare(
        'INSERT INTO whatsapp_checkout_sessions (company_id, contact_id, step, payload_json) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE step=VALUES(step), payload_json=VALUES(payload_json), updated_at=NOW()'
    );
    if ($st) {
        $st->bind_param('iiss', $companyId, $contactId, $step, $json);
        $st->execute();
    }
}
function waamini_checkout_session_clear(mysqli $conn, int $companyId, int $contactId): void
{
    $st = $conn->prepare('DELETE FROM whatsapp_checkout_sessions WHERE company_id=? AND contact_id=? LIMIT 1');
    if ($st) {
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
    }
}
/**
 * Send plain text via Cloud API using per-company whatsapp_config.
 *
 * @return array{id:?string,error:?string}
 */
/**
 * Local simulation mode for tests (mirrors whatsapp_api_helper.php) — never hits Meta.
 */
/**
 * Public base URL of this WAAMINI install, for links sent to customers.
 * Uses the host the webhook was reached on (works behind ngrok and in a sub-folder),
 * and falls back to APP_URL when there is no request.
 */
function waamini_checkout_public_base(): string
{
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
    }
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || strpos($host, 'ngrok') !== false;
    // App folder = the URL path above webhook.php (empty for a root install)
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = preg_match('#^(.*)/webhook\.php$#', $script, $m) ? $m[1] : '';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/');
}

function waamini_checkout_wa_simulate(): bool
{
    $env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    $simulate = (string)($_ENV['WAAMINI_WA_SIMULATE'] ?? getenv('WAAMINI_WA_SIMULATE') ?: '');
    return $env === 'local' && in_array(strtolower($simulate), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Mark a logged outgoing message as sent with a simulated wamid and return the success tuple.
 *
 * @return array{id:string,error:null}
 */
function waamini_checkout_simulated_send(mysqli $conn, int $companyId, int $newMsgId): array
{
    $waId = 'sim_' . bin2hex(random_bytes(8));
    if ($newMsgId > 0) {
        $upd = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param('sii', $waId, $newMsgId, $companyId);
            $upd->execute();
        }
    }
    return ['id' => $waId, 'error' => null];
}

function waamini_checkout_send_text(mysqli $conn, int $companyId, int $contactId, string $toDigits, string $body): array
{
    $body = mb_substr($body, 0, 4096);
    $token = '';
    $phoneId = '';
    $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
    $cfg = $conn->prepare('SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    if ($cfg) {
        $cfg->bind_param('i', $companyId);
        $cfg->execute();
        $row = $cfg->get_result()->fetch_assoc();
        $token = waamini_whatsapp_decrypt_config_token($row ?? []);
        $phoneId = (string)($row['phone_number_id'] ?? '');
        $apiVer = (string)($row['api_version'] ?? $apiVer);
    }
    if ($token === '' || $phoneId === '') {
        return ['id' => null, 'error' => 'missing_whatsapp_config'];
    }
    $newMsgId = 0;
    if ($contactId > 0) {
        $ins = $conn->prepare(
            "INSERT INTO messages (company_id, contact_id, direction, type, message, is_read, status, created_at) VALUES (?, ?, 'outgoing', 'text', ?, 1, 'sending', NOW())"
        );
        if ($ins) {
            $ins->bind_param('iis', $companyId, $contactId, $body);
            $ins->execute();
            $newMsgId = (int)$conn->insert_id;
        }
    }
    if (waamini_checkout_wa_simulate()) {
        return waamini_checkout_simulated_send($conn, $companyId, $newMsgId);
    }
    $url = 'https://graph.facebook.com/' . $apiVer . '/' . $phoneId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                  => $toDigits,
        'type'                => 'text',
        'text'                => ['preview_url' => false, 'body' => $body],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $waId = $data['messages'][0]['id'] ?? null;
    if ($http >= 200 && $http < 300 && $waId) {
        if ($newMsgId > 0) {
            $wid = (string)$waId;
            $upd = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
            if ($upd) {
                $upd->bind_param('sii', $wid, $newMsgId, $companyId);
                $upd->execute();
            }
        }
        return ['id' => (string)$waId, 'error' => null];
    }
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $http);
    if ($newMsgId > 0) {
        $upd = $conn->prepare("UPDATE messages SET status='failed' WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param('ii', $newMsgId, $companyId);
            $upd->execute();
        }
    }
    return ['id' => null, 'error' => $errMsg];
}
/**
 * Send an image via WhatsApp Cloud API using per-company whatsapp_config.
 *
 * @return array{id:?string,error:?string}
 */
function waamini_checkout_send_image(mysqli $conn, int $companyId, int $contactId, string $toDigits, string $imageUrl): array
{
    $token = '';
    $phoneId = '';
    $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
    $cfg = $conn->prepare('SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    if ($cfg) {
        $cfg->bind_param('i', $companyId);
        $cfg->execute();
        $row = $cfg->get_result()->fetch_assoc();
        $token = waamini_whatsapp_decrypt_config_token($row ?? []);
        $phoneId = (string)($row['phone_number_id'] ?? '');
        $apiVer = (string)($row['api_version'] ?? $apiVer);
    }
    if ($token === '' || $phoneId === '') {
        return ['id' => null, 'error' => 'missing_whatsapp_config'];
    }
    // Log the outgoing image message
    $newMsgId = 0;
    if ($contactId > 0) {
        $ins = $conn->prepare(
            "INSERT INTO messages (company_id, contact_id, direction, type, message, is_read, status, created_at) VALUES (?, ?, 'outgoing', 'image', ?, 1, 'sending', NOW())"
        );
        if ($ins) {
            $ins->bind_param('iis', $companyId, $contactId, $imageUrl);
            $ins->execute();
            $newMsgId = (int)$conn->insert_id;
        }
    }
    if (waamini_checkout_wa_simulate()) {
        return waamini_checkout_simulated_send($conn, $companyId, $newMsgId);
    }
    $url = 'https://graph.facebook.com/' . $apiVer . '/' . $phoneId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toDigits,
        'type' => 'image',
        'image' => ['link' => $imageUrl],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $waId = $data['messages'][0]['id'] ?? null;
    if ($http >= 200 && $http < 300 && $waId) {
        if ($newMsgId > 0) {
            $wid = (string)$waId;
            $upd = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
            if ($upd) {
                $upd->bind_param('sii', $wid, $newMsgId, $companyId);
                $upd->execute();
            }
        }
        return ['id' => (string)$waId, 'error' => null];
    }
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $http);
    if ($newMsgId > 0) {
        $upd = $conn->prepare("UPDATE messages SET status='failed' WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param('ii', $newMsgId, $companyId);
            $upd->execute();
        }
    }
    // Image send failure is non-fatal for the catalog flow — text was already sent
    error_log('[WAAMINI checkout] image send failed company_id=' . $companyId . ' to=' . $toDigits . ' err=' . $errMsg);
    return ['id' => null, 'error' => $errMsg];
}
function waamini_checkout_is_trigger(string $t): bool
{
    $t = strtolower(trim($t));
    // Include both singular 'product' and plural 'products' so either word triggers the catalog
    $exact = ['/products', '/catalog', '/shop', 'products', 'catalog', 'menu', 'shop', 'product'];
    if (in_array($t, $exact, true)) {
        return true;
    }
    if (str_starts_with($t, '/products') || str_starts_with($t, '/catalog') || str_starts_with($t, '/product')) {
        return true;
    }
    return false;
}
/**
 * Block Premium AI enqueue when this text is clearly meant for catalog/keyword flows,
 * even if waamini_catalog_checkout_handle() returned false (DB edge case). Prevents
 * catalog or bot_replies + multiple AI bubbles for one user tap.
 *
 * Uses short-message guard so long free-text ("I need help with my order #123…") can still reach AI.
 */
function waamini_checkout_should_block_autonomous_ai(string $textRaw): bool
{
    $text = waamini_checkout_normalize_inbound($textRaw);
    if ($text === '') {
        return false;
    }
    $lower = strtolower($text);
    if (waamini_checkout_is_slash_command($lower)) {
        return true;
    }
    if (waamini_checkout_is_trigger($lower)) {
        return true;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= 96 && waamini_checkout_is_catalog_intent($text)) {
        return true;
    }
    return false;
}
function waamini_checkout_is_catalog_intent(string $text): bool
{
    if (waamini_checkout_is_trigger($text)) {
        return true;
    }
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    $tLower = strtolower($t);

    // NEVER match "service" or similar service-related words UNLESS the user
    // also mentions "product" or other catalog keywords (e.g. "products and services"
    // should still trigger the catalog, but just "service" should not).
    $hasProductTerm = preg_match('/\b(product|catalog|price|buy|order|shop|price\s*list)\b/i', $tLower);
    if (!$hasProductTerm && preg_match('/\b(service|services|servicing)\b/i', $tLower)) {
        return false;
    }

    // Word-boundary English intents
    if (preg_match('/\b(products?|product|catalog(ue)?|price\s*list|shopping|checkout|buy|order(\s+now)?)\b/i', $t)) {
        return true;
    }
    if (preg_match('/\b(what\s+(do\s+you\s+)?sell|show\s+(me\s+)?(your\s+)?products?|do\s+you\s+have\s+(any\s+)?products?)\b/i', $t)) {
        return true;
    }
    // Mixed-script messages (e.g. Tamil + English): match plural "products" without hitting "production"
    if (preg_match('/(?:^|[^a-z])products(?:[^a-z]|$)/i', $t)) {
        return true;
    }
    return false;
}
/** Simple greetings — short hint so a broken AI does not spam the generic error to every hello. */
function waamini_checkout_is_greeting_only(string $text): bool
{
    $t = trim($text);
    return (bool)preg_match('/^(hi+|hello+|hey+|namaste|vanakkam|good\s+(morning|afternoon|evening))[!.\s]*$/iu', $t);
}
function waamini_checkout_pick_payment_method(mysqli $conn, int $companyId): string
{
    $st = $conn->prepare('SELECT gateway, api_key, api_secret, upi_id FROM ecommerce_settings WHERE company_id=? AND is_active=1 LIMIT 1');
    if (!$st) {
        return 'upi';
    }
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return 'upi';
    }
    $row = waamini_ecommerce_decrypt_settings($row);
    $gw = strtolower((string)($row['gateway'] ?? 'razorpay'));
    if ($gw === 'razorpay' && trim((string)($row['api_key'] ?? '')) !== '' && trim((string)($row['api_secret'] ?? '')) !== '') {
        return 'razorpay';
    }
    if (trim((string)($row['upi_id'] ?? '')) !== '') {
        return 'upi';
    }
    return 'razorpay';
}
function waamini_checkout_normalize_inbound(string $raw): string
{
    $t = trim($raw);
    if ($t === '') {
        return '';
    }
    $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t);
    return trim($t);
}
function waamini_checkout_is_affirm(string $textNorm, string $lower): bool
{
    if ($textNorm !== '' && preg_match('/^(yes|yeah|yep|y|ok|okay|sure|pay|proceed)(\s*[!.]*)?$/iu', $textNorm)) {
        return true;
    }
    return in_array($lower, ['yes', 'y', 'ok', 'okay', 'pay', 'proceed'], true);
}
/**
 * Handle one inbound text message for catalog checkout.
 *
 * @param string $fromDigits WhatsApp sender without +
 */

// ============================================================
// PRODUCT GRID — WhatsApp Interactive Button Cards + UPI Pay
// ============================================================

/**
 * Resolve a full absolute URL from a stored image path.
 */
/**
 * Path of an uploaded product image inside this install ("storage/products/<file>"),
 * whatever prefix was stored with it (older rows saved "/waamini/storage/..."). '' if not ours.
 */
function waamini_product_image_rel(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '' || !preg_match('#(?:^|/)storage/products/([A-Za-z0-9._-]+)$#', $stored, $m)) {
        return '';
    }
    return 'storage/products/' . $m[1];
}

/** Absolute public URL for a product image, for WhatsApp to download. */
function waamini_checkout_resolve_image_url(string $imageUrl): string
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '') return '';
    if (strpos($imageUrl, 'http') === 0) return $imageUrl;
    $rel = waamini_product_image_rel($imageUrl);
    return waamini_checkout_public_base() . '/' . ($rel !== '' ? $rel : ltrim($imageUrl, '/'));
}

/**
 * Send an interactive button message with optional image header via WhatsApp Cloud API.
 *
 * @param array    $buttons  [['id'=>'buy_3','title'=>'Pay Now'], ...] max 3, title max 25 chars
 * @param string|null $imageUrl  Optional product image URL for card header
 * @return array{id:?string,error:?string}
 */
function waamini_checkout_send_interactive_buttons(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $toDigits,
    string $bodyText,
    array $buttons,
    ?string $imageUrl = null,
    ?string $footerText = null
): array {
    $token = '';
    $phoneId = '';
    $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
    $cfg = $conn->prepare('SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    if ($cfg) {
        $cfg->bind_param('i', $companyId);
        $cfg->execute();
        $row = $cfg->get_result()->fetch_assoc();
        $token = waamini_whatsapp_decrypt_config_token($row ?? []);
        $phoneId = (string)($row['phone_number_id'] ?? '');
        $apiVer = (string)($row['api_version'] ?? $apiVer);
    }
    if ($token === '' || $phoneId === '') {
        return ['id' => null, 'error' => 'missing_whatsapp_config'];
    }

    // Log outgoing interactive message
    $newMsgId = 0;
    if ($contactId > 0) {
        $ins = $conn->prepare(
            "INSERT INTO messages (company_id, contact_id, direction, type, message, is_read, status, created_at) VALUES (?, ?, 'outgoing', 'interactive', ?, 1, 'sending', NOW())"
        );
        if ($ins) {
            $ins->bind_param('iis', $companyId, $contactId, $bodyText);
            $ins->execute();
            $newMsgId = (int)$conn->insert_id;
        }
    }

    // Build interactive payload
    $interactive = [
        'type' => 'button',
        'body' => ['text' => mb_substr($bodyText, 0, 1024, 'UTF-8')],
        'action' => ['buttons' => []],
    ];

    if ($imageUrl !== null && $imageUrl !== '') {
        $interactive['header'] = [
            'type' => 'image',
            'image' => ['link' => $imageUrl],
        ];
    }

    if ($footerText !== null && $footerText !== '') {
        $interactive['footer'] = ['text' => mb_substr($footerText, 0, 60, 'UTF-8')];
    }

    foreach (array_slice($buttons, 0, 3) as $btn) {
        $interactive['action']['buttons'][] = [
            'type' => 'reply',
            'reply' => [
                'id' => mb_substr((string)($btn['id'] ?? ''), 0, 256, 'UTF-8'),
                'title' => mb_substr((string)($btn['title'] ?? ''), 0, 25, 'UTF-8'),
            ],
        ];
    }

    if (waamini_checkout_wa_simulate()) {
        return waamini_checkout_simulated_send($conn, $companyId, $newMsgId);
    }
    $url = 'https://graph.facebook.com/' . $apiVer . '/' . $phoneId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toDigits,
        'type' => 'interactive',
        'interactive' => $interactive,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $waId = $data['messages'][0]['id'] ?? null;

    if ($http >= 200 && $http < 300 && $waId) {
        if ($newMsgId > 0) {
            $wid = (string)$waId;
            $upd = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
            if ($upd) { $upd->bind_param('sii', $wid, $newMsgId, $companyId); $upd->execute(); }
        }
        return ['id' => (string)$waId, 'error' => null];
    }

    $errMsg = $data['error']['message'] ?? ('HTTP ' . $http);
    if ($newMsgId > 0) {
        $upd = $conn->prepare("UPDATE messages SET status='failed' WHERE id=? AND company_id=?");
        if ($upd) { $upd->bind_param('ii', $newMsgId, $companyId); $upd->execute(); }
    }
    error_log('[WAAMINI checkout] interactive send failed company_id=' . $companyId . ' to=' . $toDigits . ' err=' . $errMsg);
    return ['id' => null, 'error' => $errMsg];
}

/**
 * Show a single product as an interactive card with Pay Now / Enquire / More buttons.
 */
function waamini_checkout_show_product_card(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $fromDigits,
    array $product,
    int $currentIndex,
    int $totalProducts
): bool {
    $name = trim((string)($product['name'] ?? 'Product'));
    $price = (float)($product['price'] ?? 0);
    $priceStr = '₹' . number_format($price, 2);
    $descRaw = (string)($product['description'] ?? '');
    $feature = waamini_checkout_extract_feature($descRaw);
    $imageUrl = waamini_checkout_resolve_image_url((string)($product['image_url'] ?? ''));
    $pid = (int)($product['id'] ?? 0);

    // Card body
    $body = "📦 *" . mb_substr($name, 0, 60, 'UTF-8') . "*\n";
    $body .= "💰 " . $priceStr;
    if ($feature !== '') {
        $body .= "\n\n" . $feature;
    }

    // Buttons — product ID encoded in button IDs for callback routing
    $buttons = [
        ['id' => "pay_{$pid}", 'title' => 'Pay Now'],
        ['id' => "enq_{$pid}", 'title' => 'Enquire'],
    ];
    if ($currentIndex < $totalProducts - 1) {
        $buttons[] = ['id' => 'more_products', 'title' => 'More Products'];
    }

    $footer = ($currentIndex + 1) . " of " . $totalProducts . " products";

    $result = waamini_checkout_send_interactive_buttons(
        $conn, $companyId, $contactId, $fromDigits,
        $body, $buttons,
        $imageUrl !== '' ? $imageUrl : null,
        $footer
    );

    return !empty($result['id']) || empty($result['error']);
}

/**
 * Show the next product card in the grid, or finish.
 */
function waamini_checkout_show_next_product(
    mysqli $conn,
    int $companyId,
    int $contactId,
    string $fromDigits,
    array $sess
): bool {
    $products = $sess['payload']['products'] ?? [];
    $idx = (int)($sess['payload']['current_index'] ?? 0);

    if (empty($products) || $idx >= count($products)) {
        $count = count($products);
        $r = waamini_checkout_send_text($conn, $companyId, $contactId, $fromDigits,
            "That's all {$count} products! 🛍️\n\nReply *products* to browse again, or tell me what you need.");
        waamini_checkout_session_clear($conn, $companyId, $contactId);
        return true;
    }

    $ok = waamini_checkout_show_product_card($conn, $companyId, $contactId, $fromDigits, $products[$idx], $idx, count($products));

    $sess['payload']['current_index'] = $idx + 1;
    waamini_checkout_session_save($conn, $companyId, $contactId, 'catalog_grid', $sess['payload']);

    return $ok;
}

/**
 * Handle one inbound text message for catalog checkout.
 *
 * @param string $fromDigits WhatsApp sender without +
 */
function waamini_catalog_checkout_handle(mysqli $conn, int $companyId, int $contactId, string $fromDigits, string $textRaw): bool
{
    waamini_checkout_ensure_schema($conn);

    $safeSend = static function (string $body) use ($conn, $companyId, $contactId, $fromDigits): bool {
        $r = waamini_checkout_send_text($conn, $companyId, $contactId, $fromDigits, $body);
        if (!empty($r['error'])) {
            error_log('[WAAMINI checkout] send failed company_id=' . $companyId . ' contact_id=' . $contactId . ' to=' . $fromDigits . ' err=' . (string)$r['error']);
            return false;
        }
        return true;
    };

    $text = waamini_checkout_normalize_inbound($textRaw);
    if ($text === '') {
        return false;
    }

    $lower = strtolower($text);

    // Slash commands: quick business info
    if (waamini_checkout_is_slash_command($lower)) {
        $cmd = trim(strtok($lower, " \t\r\n"));

        // Keep /products (and related) handled by the catalog flow below
        if (!in_array($cmd, ['/products', '/catalog', '/shop'], true)) {
            $profile = waamini_checkout_company_profile($conn, $companyId);
            $bizName = trim((string)($profile['name'] ?? ''));
            $bizPhone = trim((string)($profile['phone'] ?? ''));
            $bizEmail = trim((string)($profile['email'] ?? ''));
            $bizWebsite = trim((string)($profile['website'] ?? ''));
            $bizAddress = trim((string)($profile['address'] ?? ''));

            if (in_array($cmd, ['/help', '/start', '/menu', '/commands'], true)) {
                $lines = [];
                $lines[] = ($bizName !== '' ? "*{$bizName}* — quick menu" : '*Quick menu*');
                $lines[] = '';
                $lines[] = "• */products* — browse & buy products";
                $lines[] = "• */location* — office address + map link";
                $lines[] = "• */contact* — phone/email/website";
                $lines[] = '';
                $lines[] = "You can also type normally, like: \"what products do you have?\"";
                return $safeSend(implode("\n", $lines));
            }

            if (in_array($cmd, ['/location', '/office', '/address', '/map'], true)) {
                if ($bizAddress === '') {
                    $msg = ($bizName !== '' ? "*{$bizName}*\n\n" : '') . "Our office location isn't set yet. The business can add it in *Settings → General → Address*.";
                    return $safeSend($msg);
                }
                $maps = waamini_checkout_build_maps_link($bizAddress);
                $msg = ($bizName !== '' ? "*{$bizName}* — Office location\n\n" : "*Office location*\n\n") . $bizAddress;
                if ($maps !== '') {
                    $msg .= "\n\nMap: " . $maps;
                }
                return $safeSend($msg);
            }

            if (in_array($cmd, ['/contact', '/call', '/phone', '/email', '/website'], true)) {
                $lines = [];
                $lines[] = ($bizName !== '' ? "*{$bizName}* — Contact" : '*Contact*');
                $lines[] = '';
                if ($bizPhone !== '') $lines[] = "Phone: {$bizPhone}";
                if ($bizEmail !== '') $lines[] = "Email: {$bizEmail}";
                if ($bizWebsite !== '') $lines[] = "Website: {$bizWebsite}";
                if (count($lines) <= 2) {
                    $lines[] = "Contact details aren't set yet. The business can add them in *Settings → General*.";
                }
                return $safeSend(implode("\n", $lines));
            }

            return $safeSend("I didn't recognize that command.\n\nTry */help* for the quick menu.");
        }
    }

    // ── CHECK CATALOG TRIGGER FIRST (before bot_reply check) ──
    // If the user's message is a STRONG catalog trigger (like "product" or "products"),
    // show the catalog IMMEDIATELY. This ensures "product" triggers the catalog even
    // when bot_reply keywords might otherwise intercept and return false.
    // We use the stronger is_trigger() check here (exact word matches only) and
    // leave the softer is_catalog_intent() (which also matches "buy", "price", etc.)
    // for the normal session flow below, to avoid resetting in-progress checkouts.
    if (waamini_checkout_is_trigger($text)) {
        $stmt = $conn->prepare(
            'SELECT id, name, description, price, image_url, buy_url FROM products WHERE company_id=? ORDER BY created_at DESC LIMIT 10'
        );
        if ($stmt) {
            $stmt->bind_param('i', $companyId);
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($row = $res->fetch_assoc()) { $items[] = $row; }

            if (count($items) === 0) {
                $safeSend('We have not added any products/services yet. Please check back soon — the business can add them in *Product catalog*.');
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return true;
            }

            $gridPayload = ['products' => $items, 'current_index' => 0];
            waamini_checkout_session_save($conn, $companyId, $contactId, 'catalog_grid', $gridPayload);

            $viewMsg = waamini_catalog_get_auto_reply($conn, $companyId, 'catalog_view', ['product_count' => count($items)]);
            $viewSent = false;
            if ($viewMsg !== '') {
                $viewSent = empty(waamini_checkout_send_text($conn, $companyId, $contactId, $fromDigits, $viewMsg)['error']);
            }
            waamini_catalog_track_interaction(
                $conn, $companyId, $contactId, $fromDigits,
                'Catalog View', 0, 'catalog_view', $viewSent, $viewSent ? $viewMsg : '',
                ['product_count' => count($items)]
            );

            return waamini_checkout_show_product_card($conn, $companyId, $contactId, $fromDigits, $items[0], 0, count($items));
        }
    }

    // Global cancel
    if (in_array($lower, ['cancel', 'stop', 'exit', '/cancel'], true)) {
        $sess = waamini_checkout_session_load($conn, $companyId, $contactId);
        if (($sess['step'] ?? 'idle') !== 'idle') {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Okay — cancelled the order flow. Ask anytime with *products* or */products*.');
        }
    }

    // Release session if message matches any Bot Reply keyword.
    // While a customer is mid-checkout, only an exact match counts, so a keyword
    // that happens to appear inside an address or question does not cancel the order.
    $preStep = (string)(waamini_checkout_session_load($conn, $companyId, $contactId)['step'] ?? 'idle');
    $botRules = $conn->query("SELECT keyword, trigger_keyword FROM bot_replies WHERE company_id=$companyId AND is_active=1");
    while ($botRules && ($rule = $botRules->fetch_assoc())) {
        $rawKw = strtolower(trim((string)($rule['keyword'] ?: $rule['trigger_keyword'] ?: '')));
        if ($rawKw === '') continue;
        $kws = explode(',', $rawKw);
        foreach ($kws as $kw) {
            $kw = trim($kw);
            if ($kw === '') continue;
            if ($lower === $kw || ($preStep === 'idle' && strlen($kw) >= 3 && preg_match('/\\b' . preg_quote($kw, '/') . '\\b/u', $lower))) {
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return false;
            }
        }
    }

    $sess = waamini_checkout_session_load($conn, $companyId, $contactId);
    $step = (string)($sess['step'] ?? 'idle');
    $payload = $sess['payload'] ?? [];

    // If user sends a catalog-trigger while in any non-idle step, reset so we
    // fall through to the catalog grid entry point below.
    if ($step !== 'idle' && waamini_checkout_is_catalog_intent($text)) {
        waamini_checkout_session_clear($conn, $companyId, $contactId);
        $step = 'idle';
        $payload = [];
    }

    // ============================================================
    // CATALOG GRID — interactive product cards with UPI Pay buttons
    // ============================================================

    // Button: "Pay Now" tapped (id = pay_{product_id})
    if ($step === 'catalog_grid' && preg_match('/^pay_(\d+)$/', $lower, $mPay)) {
        $pid = (int)$mPay[1];
        $products = $payload['products'] ?? [];
        $selected = null;
        foreach ($products as $p) {
            if ((int)$p['id'] === $pid) { $selected = $p; break; }
        }
        if (!$selected) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Product not found. Send *products* to browse again.');
        }
        $pname = trim((string)$selected['name']);
        $pPrice = (float)$selected['price'];
        $pBuyUrl = trim((string)($selected['buy_url'] ?? ''));

        // What the customer must reply next (always added, even to a custom template)
        $nextStep = $pBuyUrl !== ''
            ? "Or order here on WhatsApp: reply a *number (1–99)* for quantity.\nReply *cancel* to go back."
            : "How many would you like? Reply with a *number (1–99)*.\nReply *cancel* to go back.";

        // Built-in Pay Now reply with product info
        $payMsg = "🛒 *{product_name}*\n";
        $payMsg .= "💰 Price: *₹{price}*\n\n";
        if ($pBuyUrl !== '') {
            $payMsg .= "👆 Buy on our website:\n{buy_url}\n\n";
        }
        $payMsg .= $nextStep;

        // Tracked as Pay Now; products with a website link use the "Buy now" template.
        // The UPI QR code is sent once, at the end, for the real order total.
        waamini_catalog_send_auto_reply(
            $conn, $companyId, $contactId, $fromDigits,
            'pay_now', $pname, $pid, $pPrice, $pBuyUrl,
            ['details' => $payMsg, 'next_step' => $nextStep],
            $pBuyUrl !== '' ? 'buy_now' : 'pay_now'
        );

        $checkoutPayload = [
            'product_id' => (int)$selected['id'],
            'product_name' => $pname,
            'unit_price' => $pPrice,
            'qty' => 1,
            'line_total' => $pPrice,
        ];
        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_qty_grid', $checkoutPayload);
        return true;
    }

    // Button: "Enquire" tapped (id = enq_{product_id})
    if ($step === 'catalog_grid' && preg_match('/^enq_(\d+)$/', $lower, $mEnq)) {
        $pid = (int)$mEnq[1];
        $products = $payload['products'] ?? [];
        $selected = null;
        foreach ($products as $p) {
            if ((int)$p['id'] === $pid) { $selected = $p; break; }
        }
        if (!$selected) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Product not found. Send *products* to browse again.');
        }
        $pname = trim((string)$selected['name']);
        $pPrice = (float)$selected['price'];
        $pDesc = trim((string)($selected['description'] ?? ''));
        $pBuyUrl = trim((string)($selected['buy_url'] ?? ''));

        // Build a detailed Enquire reply with full product info
        $enqMsg = "📋 *Product Details*\n\n";
        $enqMsg .= "📦 *" . mb_substr($pname, 0, 60, 'UTF-8') . "*\n";
        $enqMsg .= "💰 Price: *₹" . number_format($pPrice, 2) . "*\n\n";

        if ($pDesc !== '') {
            $shortDesc = mb_substr(strip_tags($pDesc), 0, 300, 'UTF-8');
            if (mb_strlen($pDesc, 'UTF-8') > 300) $shortDesc .= '...';
            $enqMsg .= "📝 " . $shortDesc . "\n\n";
        }

        if ($pBuyUrl !== '') {
            $enqMsg .= "🛒 Buy here: " . $pBuyUrl . "\n\n";
        }

        $enqNext = "💬 Type your question below and our team will reply.\nReply *cancel* to go back.";
        $enqMsg .= $enqNext;

        // Track interaction + send reply
        waamini_catalog_send_auto_reply(
            $conn, $companyId, $contactId, $fromDigits,
            'enquire', $pname, $pid, $pPrice, $pBuyUrl,
            ['details' => $enqMsg, 'description' => $pDesc, 'next_step' => $enqNext]
        );

        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_enquiry_msg', [
            'product_id' => (int)$selected['id'],
            'product_name' => $pname,
        ]);
        return true;
    }

    // Button: "More Products" tapped
    if ($step === 'catalog_grid' && $lower === 'more_products') {
        return waamini_checkout_show_next_product($conn, $companyId, $contactId, $fromDigits, $sess);
    }

    // ============================================================
    // GRID → QTY: quantity step (after tapping Pay Now on a card)
    // ============================================================
    if ($step === 'await_qty_grid') {
        if (!preg_match('/^(\d+)$/', $lower, $m)) {
            $wc = str_word_count($text);
            $isNatural = $wc >= 4 || strpos($text, '?') !== false || preg_match('/\b(hi|hello|hey|what|how|why|tell|explain|help|info|detail|please|need|want|know)\b/i', $text);
            if ($isNatural || waamini_checkout_is_greeting_only($text)) {
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return false;
            }
            return $safeSend('Please reply with a number for quantity (example: 1).');
        }
        $qty = (int)$m[1];
        if ($qty < 1 || $qty > 99) {
            return $safeSend('Quantity must be between 1 and 99.');
        }
        $unit = (float)($payload['unit_price'] ?? 0);
        $name = (string)($payload['product_name'] ?? 'Item');
        $line = round($unit * $qty, 2);
        $payload['qty'] = $qty;
        $payload['line_total'] = $line;
        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_confirm', $payload);
        return $safeSend("*Order summary*\n{$name} x {$qty} = ₹" . number_format($line, 2) . "\n\nTotal: *₹" . number_format($line, 2) . "*\n\nReply *YES* for delivery details & payment, or *cancel*.");
    }

    // ============================================================
    // ENQUIRY: collect customer message
    // ============================================================
    if ($step === 'await_enquiry_msg') {
        if (in_array($lower, ['cancel', 'stop', 'exit', '/cancel'], true)) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Cancelled. Send *products* to browse again.');
        }
        $productName = (string)($payload['product_name'] ?? '');
        $message = waamini_checkout_sanitize_one_line($text, 500);

        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS product_enquiries (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    contact_id INT NOT NULL,
                    customer_phone VARCHAR(30) NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    product_id INT DEFAULT NULL,
                    message TEXT DEFAULT NULL,
                    status ENUM('new','contacted','converted','closed') DEFAULT 'new',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_enq (company_id, customer_phone, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) { /* exists */ }

        $pid = (int)($payload['product_id'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO product_enquiries (company_id, contact_id, customer_phone, product_name, product_id, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('iisssi', $companyId, $contactId, $fromDigits, $productName, $pid, $message);
            @$stmt->execute();
            $stmt->close();
        }

        waamini_checkout_session_clear($conn, $companyId, $contactId);

        return $safeSend("Thanks for your enquiry on *{$productName}*! 🙏\n\nOur team will get back to you shortly.\n• Reply *products* to browse & buy\n• Reply */contact* for phone/email");
    }

    // ============================================================
    // CONFIRM → ADDRESS: after confirming order summary
    // ============================================================
    if ($step === 'await_confirm') {
        if (!waamini_checkout_is_affirm($text, $lower)) {
            if (waamini_checkout_is_greeting_only($text) || waamini_checkout_is_catalog_intent($text)) {
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return false;
            }
            return false;
        }

        $qty = (int)($payload['qty'] ?? 0);
        $unit = (float)($payload['unit_price'] ?? 0);
        $name = (string)($payload['product_name'] ?? '');
        $total = (float)($payload['line_total'] ?? 0);
        if ($qty <= 0 || $unit < 0 || $name === '' || $total <= 0) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Something went wrong. Send *products* to start again.');
        }

        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_address', $payload);
        return $safeSend("Great! To complete your order, please share your *delivery details*:\n\nName\nPhone\nAddress (street/locality)\nCity\nPincode\n\nExample:\nRavi Kumar\n9876543210\n123 Main Street\nChennai\n600001\n\nSend *cancel* to abort.");
    }

    // ============================================================
    // ADDRESS → PAYMENT: collect delivery address then generate UPI link
    // ============================================================
    if ($step === 'await_address') {
        if (waamini_checkout_is_greeting_only($text) || waamini_checkout_is_catalog_intent($text)) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return false;
        }
        if (in_array($lower, ['cancel', 'stop', 'exit', '/cancel'], true)) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Order cancelled. Send *products* anytime to start again.');
        }

        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $lineCount = count($lines);

        if ($lineCount < 4) {
            return $safeSend("Please share your full details:\n\nName\nPhone\nAddress\nCity\nPincode");
        }

        $pincode = $lines[$lineCount - 1];
        $city = $lines[$lineCount - 2];
        $address = $lines[$lineCount - 3];
        $buyerName = $lines[0];
        $buyerPhone = preg_replace('/\D+/', '', $lines[1] ?? '');

        if ($buyerPhone === '' || strlen($buyerPhone) < 7) {
            return $safeSend("Please enter a valid phone number (7+ digits) in the second line.");
        }
        if (!preg_match('/^\d{5,6}$/', $pincode)) {
            return $safeSend("Please enter a valid 5 or 6 digit pincode as the last line.");
        }

        $payload['buyer_name'] = waamini_checkout_sanitize_one_line($buyerName, 255);
        $payload['buyer_phone'] = $buyerPhone;
        $payload['buyer_address'] = waamini_checkout_sanitize_one_line($address, 500);
        $payload['buyer_city'] = waamini_checkout_sanitize_one_line($city, 120);
        $payload['buyer_pincode'] = $pincode;

        $qty = (int)($payload['qty'] ?? 0);
        $prodName = (string)($payload['product_name'] ?? '');
        $total = (float)($payload['line_total'] ?? 0);
        if ($qty <= 0 || $prodName === '' || $total <= 0) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('Something went wrong. Send *products* to start again.');
        }

        $method = waamini_checkout_pick_payment_method($conn, $companyId);
        $desc = $prodName . ' x ' . $qty;

        $res = create_payment_link($contactId, $total, $companyId, $desc, $method);
        if (!empty($res['error'])) {
            $errRaw = (string)$res['error'];
            error_log('[WAAMINI checkout] create_payment_link failed company_id=' . $companyId . ' contact_id=' . $contactId . ' err=' . $errRaw);
            $friendly = "We are unable to open a payment link right now — our team will follow up shortly. Thanks for your patience!";
            if (stripos($errRaw, 'not configured') !== false || stripos($errRaw, 'inactive') !== false) {
                $friendly = "Online checkout is not active yet — we will send you payment options personally.";
            } elseif (stripos($errRaw, 'UPI VPA') !== false) {
                $friendly = "We are updating our UPI details — we will share how to pay shortly.";
            }
            return $safeSend($friendly);
        }

        $orderId = (int)($res['order_id'] ?? 0);
        if ($orderId <= 0) {
            return $safeSend('Could not create an order record. Try again shortly.');
        }

        $itemsJson = json_encode([['name' => $prodName, 'qty' => $qty, 'price' => $total]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $stmtU = $conn->prepare(
            "UPDATE ecommerce_orders SET items_json=?, order_source='whatsapp_checkout',
             buyer_name=?, buyer_phone=?, buyer_address=?, buyer_city=?, buyer_pincode=?
             WHERE id=? AND company_id=? LIMIT 1"
        );
        if ($stmtU) {
            $stmtU->bind_param('ssssssii', $itemsJson, $payload['buyer_name'], $payload['buyer_phone'], $payload['buyer_address'], $payload['buyer_city'], $payload['buyer_pincode'], $orderId, $companyId);
            $stmtU->execute();
        }

        $payLine = '';
        if ($method === 'upi' && isset($res['uri'])) {
            $baseUrl = waamini_checkout_public_base();
            $skipWarning = (strpos($baseUrl, 'ngrok') !== false) ? '&ngrok-skip-browser-warning=1' : '';
            $payLink = $baseUrl . '/pay_upi.php?oi=' . $orderId . $skipWarning;
            $payLine = "Pay via UPI (Tap to pay):\n" . $payLink;
            if (!empty($res['url'])) {
                $payLine .= "\n\nQR (Scan to pay):\n" . (string)$res['url'];
            }
        } elseif (!empty($res['url'])) {
            $payLine = "Pay securely here:\n" . (string)$res['url'];
        } else {
            $payLine = 'Payment link created. Please check with support if you do not see a URL.';
        }

        $headline = waamini_catalog_get_auto_reply($conn, $companyId, 'order_confirm', [
            'product_name' => $prodName, 'qty' => $qty, 'total' => number_format($total, 2),
            'price' => number_format($total, 2), 'order_id' => $orderId, 'customer_name' => $payload['buyer_name'],
        ]);
        if (trim($headline) === '') {
            $headline = "🛒 *Order confirmed — payment time!*";
        }
        $msg = rtrim($headline) . "\n\n"
            . $desc . "\n"
            . "Total: *₹" . number_format($total, 2) . "*\n"
            . "Order ref: #{$orderId}\n\n"
            . "📍 Delivery to:\n"
            . $payload['buyer_name'] . "\n"
            . $payload['buyer_address'] . ", " . $payload['buyer_city'] . " - " . $payload['buyer_pincode'] . "\n"
            . "Phone: " . $payload['buyer_phone'] . "\n\n"
            . $payLine . "\n\n"
            . "_After payment, our team will confirm and send your invoice._";

        if (!$safeSend($msg)) {
            return false;
        }

        // Send UPI QR code image for the final payment amount
        if ($method === 'upi') {
            waamini_catalog_send_upi_qr_image(
                $conn, $companyId, $contactId, $fromDigits,
                $total, $prodName
            );
        }

        waamini_checkout_session_clear($conn, $companyId, $contactId);
        return true;
    }

    // ============================================================
    // LEGACY QTY STEP (from old numbered list flow)
    // ============================================================
    if ($step === 'await_qty') {
        if (!preg_match('/^(\d+)$/', $lower, $m)) {
            $wc = str_word_count($text);
            $isNatural = $wc >= 4 || strpos($text, '?') !== false || preg_match('/\b(hi|hello|hey|what|how|why|tell|explain|help|info|detail|please|need|want|know|enaku|venum|sollu)\b/i', $text);
            if ($isNatural || waamini_checkout_is_greeting_only($text)) {
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return false;
            }
            return $safeSend('Please reply with digits only for quantity (example: 2).');
        }
        $qty = (int)$m[1];
        if ($qty < 1 || $qty > 99) {
            return $safeSend('Quantity must be between 1 and 99.');
        }
        $unit = (float)($payload['unit_price'] ?? 0);
        $name = (string)($payload['product_name'] ?? 'Item');
        $line = round($unit * $qty, 2);
        $payload['qty'] = $qty;
        $payload['line_total'] = $line;
        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_confirm', $payload);
        return $safeSend("*Order summary*\n{$name} x {$qty} = ₹" . number_format($line, 2) . "\n\nTotal: *₹" . number_format($line, 2) . "*\n\nReply *YES* for delivery details & payment, or *cancel*.");
    }

    // ============================================================
    // LEGACY PICK STEP (from old numbered list flow)
    // ============================================================
    if ($step === 'await_pick') {
        if (!preg_match('/^(\d+)$/', $lower, $m)) {
            $wordCount = str_word_count($text);
            $hasQuestion = strpos($text, '?') !== false;
            $hasQuestionWord = preg_match('/\b(hi|hello|hey|what|how|why|who|when|where|tell|explain|help|info|detail|please|need|want|know|about|give|enaku|venum|sollu|solunga|sir|bro|da|na|price\s+of|cost\s+of)\b/i', $text);
            $isNaturalLanguage = $wordCount >= 4 || $hasQuestion || $hasQuestionWord || waamini_checkout_is_greeting_only($text);
            if ($isNaturalLanguage) {
                waamini_checkout_session_clear($conn, $companyId, $contactId);
                return false;
            }
            $idsHint = $payload['ids'] ?? [];
            $max = is_array($idsHint) ? count($idsHint) : 0;
            $hint = $max > 0
                ? 'Please reply with a *number* from the list above (*1–' . $max . '*).'
                : 'Please reply with the item number from our list.';
            return $safeSend($hint . "\n\nSend *products* or */products* anytime to see the catalog again.");
        }
        $idx = (int)$m[1];
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || $idx < 1 || $idx > count($ids)) {
            return $safeSend('Please reply with a number from the list (1–' . count($ids) . ').');
        }
        $pid = (int)$ids[$idx - 1];
        $st2 = $conn->prepare('SELECT id, name, price FROM products WHERE id=? AND company_id=? LIMIT 1');
        if (!$st2) { return true; }
        $st2->bind_param('ii', $pid, $companyId);
        $st2->execute();
        $prod = $st2->get_result()->fetch_assoc();
        if (!$prod) {
            waamini_checkout_session_clear($conn, $companyId, $contactId);
            return $safeSend('That item is no longer available. Send */products* to refresh.');
        }
        $payload['product_id'] = (int)$prod['id'];
        $payload['product_name'] = (string)$prod['name'];
        $payload['unit_price'] = (float)$prod['price'];
        unset($payload['ids']);
        waamini_checkout_session_save($conn, $companyId, $contactId, 'await_qty', $payload);
        return $safeSend('Selected: *' . $payload['product_name'] . "*\nPrice: ₹" . number_format($payload['unit_price'], 2) . "\n\nHow many do you want? Reply with a number (1–99).");
    }

    // ── NOTE: Catalog entry check is handled at the top of this function ──
    // If the message was a catalog intent (product/products/catalog/shop/etc.),
    // it was already handled above before the bot_reply check. This ensures
    // "product" always triggers the catalog regardless of any bot_reply keywords.

    return false;
}
?>