<?php
include '../includes/client_auth_check.php';
if (!has_feature('ec_checkout')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client menu pages
require_once __DIR__ . '/../includes/ecommerce_handler.php';

if (!has_feature('feat_native_checkout')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Payments & checkout';
$currentPage = 'ecommerce';

// 1. Prepare environment variables and constants needed for both GET and POST
$webhookHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$webhookHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', $webhookHost);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$webhookProtocol = $isHttps ? 'https' : 'http';
$webhookUrlFull = ($webhookHost !== '')
    ? ($webhookProtocol . '://' . $webhookHost . '/api/payment_webhook.php?company_id=' . (int)$companyId)
    : '';

$settings = [];
waamini_ecommerce_ensure_schema($conn);
$stmt_s = $conn->prepare("SELECT * FROM ecommerce_settings WHERE company_id = ?");
if ($stmt_s) {
    $stmt_s->bind_param("i", $companyId);
    $stmt_s->execute();
    $res = $stmt_s->get_result();
    if ($res) {
        $settings = $res->fetch_assoc() ?: [];
    }
}

function waamini_ecommerce_build_webhook_test_payload(string $gateway, int $companyId): array
{
    $companyId = (int)$companyId;
    $base = [
        'company_id' => $companyId,
        'sent_at' => gmdate('c'),
        'gateway' => $gateway,
    ];

    if ($gateway === 'stripe') {
        return $base + [
            'event' => [
                'id' => 'evt_test_' . $companyId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'created' => time(),
                'data' => [
                    'object' => [
                        'id' => 'cs_test_' . $companyId,
                        'object' => 'checkout.session',
                        'mode' => 'payment',
                        'payment_status' => 'paid',
                        'client_reference_id' => 0,
                    ],
                ],
            ],
        ];
    }

    return $base + [
        'event' => 'payment_link.updated',
        'payload' => [
            'payment_link' => [
                'entity' => [
                    'id' => 'plink_test_' . $companyId,
                    'status' => 'paid',
                    'notes' => ['local_order_id' => 0],
                ],
            ],
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_webhook'])) {
    csrf_verify();

    $gatewayForTest = trim((string)($_POST['gateway_for_test'] ?? ($settings['gateway'] ?? 'razorpay')));
    $gatewayForTest = in_array($gatewayForTest, ['razorpay', 'stripe'], true) ? $gatewayForTest : 'razorpay';

    $storedWebhookSecret = waamini_ecommerce_decrypt_field($settings['webhook_secret'] ?? null);
    $storedApiSecret = waamini_ecommerce_decrypt_field($settings['api_secret'] ?? null);
    $signingSecret = $storedWebhookSecret !== '' ? $storedWebhookSecret : $storedApiSecret;

    if ($webhookUrlFull === '') {
        $_SESSION['_flash_webhook_test'] = [
            'type' => 'error',
            'message' => 'Webhook URL is unavailable. Use a public HTTPS domain before testing webhooks.',
        ];
        header('Location: ecommerce_settings.php');
        exit();
    }

    if ($signingSecret === '') {
        $_SESSION['_flash_webhook_test'] = [
            'type' => 'error',
            'message' => 'Save a webhook signing secret or API secret before running the webhook test.',
        ];
        header('Location: ecommerce_settings.php');
        exit();
    }

    $payload = waamini_ecommerce_build_webhook_test_payload($gatewayForTest, (int)$companyId);
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($rawBody === false) {
        $_SESSION['_flash_webhook_test'] = [
            'type' => 'error',
            'message' => 'Could not build webhook test payload.',
        ];
        header('Location: ecommerce_settings.php');
        exit();
    }

    $headers = ['Content-Type: application/json'];
    $signature = '';
    if ($gatewayForTest === 'stripe') {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $signingSecret);
        $headers[] = 'Stripe-Signature: t=' . $timestamp . ',v1=' . $signature;
    } else {
        $signature = hash_hmac('sha256', $rawBody, $signingSecret);
        $headers[] = 'X-Razorpay-Signature: ' . $signature;
    }

    $ch = curl_init($webhookUrlFull);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = $response === false ? curl_error($ch) : '';
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        $_SESSION['_flash_webhook_test'] = [
            'type' => 'error',
            'message' => 'Webhook test failed: ' . $curlError,
        ];
        header('Location: ecommerce_settings.php');
        exit();
    }

    $decoded = json_decode((string)$response, true);
    $responseSummary = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : trim((string)$response);
    if (function_exists('mb_substr')) {
        $responseSummary = (string)mb_substr($responseSummary, 0, 300, 'UTF-8');
    } else {
        $responseSummary = substr($responseSummary, 0, 300);
    }

    $_SESSION['_flash_webhook_test'] = [
        'type' => ($statusCode >= 200 && $statusCode < 300) ? 'success' : 'error',
        'message' => 'Webhook test ' . (($statusCode >= 200 && $statusCode < 300) ? 'succeeded' : 'returned an error') . ' (HTTP ' . $statusCode . ').',
        'details' => $responseSummary,
    ];
    header('Location: ecommerce_settings.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();

    $gatewayIn = trim((string)($_POST['gateway'] ?? 'razorpay'));
    $gateway = in_array($gatewayIn, ['razorpay', 'stripe', 'upi'], true) ? $gatewayIn : 'razorpay';

    $curIn = strtoupper(trim((string)($_POST['currency'] ?? 'INR')));
    $currency = in_array($curIn, ['INR', 'USD'], true) ? $curIn : 'INR';

    $upiId = trim((string)($_POST['upi_id'] ?? ''));
    $upiName = mb_substr(trim((string)($_POST['upi_name'] ?? '')), 0, 120);
    $upiAlertNumbers = preg_replace('/[^0-9,]/', '', (string)($_POST['upi_alert_numbers'] ?? ''));

    $apiKeyPlain = trim((string)($_POST['api_key'] ?? ''));
    $apiSecretPlain = trim((string)($_POST['api_secret'] ?? ''));
    $webhookSecretPlain = trim((string)($_POST['webhook_secret'] ?? ''));

    if ($upiId !== '' && !preg_match('/^[a-zA-Z0-9._-]{2,64}@[a-zA-Z0-9.-]{2,64}$/', $upiId)) {
        header("Location: ecommerce_settings.php?error=invalid_upi");
        exit();
    }

    $storedKey = (string)($settings['api_key'] ?? '');
    if ($apiKeyPlain !== '') {
        $storedKey = encrypt_secret($apiKeyPlain);
    }

    $storedSecret = (string)($settings['api_secret'] ?? '');
    if ($apiSecretPlain !== '') {
        $storedSecret = encrypt_secret($apiSecretPlain);
    }

    $storedWebhookSecret = (string)($settings['webhook_secret'] ?? '');
    if ($webhookSecretPlain !== '') {
        $storedWebhookSecret = encrypt_secret($webhookSecretPlain);
    }

    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($active === 1) {
        $effectiveKey = $apiKeyPlain !== ''
            ? $apiKeyPlain
            : waamini_ecommerce_decrypt_field($settings['api_key'] ?? null);
        $effectiveSecret = $apiSecretPlain !== ''
            ? $apiSecretPlain
            : waamini_ecommerce_decrypt_field($settings['api_secret'] ?? null);

        if ($gateway === 'razorpay') {
            if ($effectiveKey === '' || $effectiveSecret === '') {
                header("Location: ecommerce_settings.php?error=razorpay_incomplete");
                exit();
            }
        } elseif ($gateway === 'stripe') {
            if ($effectiveKey === '') {
                header("Location: ecommerce_settings.php?error=stripe_incomplete");
                exit();
            }
        } elseif ($gateway === 'upi') {
            if ($upiId === '') {
                header("Location: ecommerce_settings.php?error=upi_incomplete");
                exit();
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO ecommerce_settings (company_id, gateway, api_key, api_secret, webhook_secret, upi_id, upi_name, upi_alert_numbers, currency, is_active, updated_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
                           ON DUPLICATE KEY UPDATE 
                           gateway=VALUES(gateway), api_key=VALUES(api_key), api_secret=VALUES(api_secret), webhook_secret=VALUES(webhook_secret),
                           upi_id=VALUES(upi_id), upi_name=VALUES(upi_name), upi_alert_numbers=VALUES(upi_alert_numbers), currency=VALUES(currency), is_active=VALUES(is_active), updated_at=NOW()");
    if (!$stmt) {
        error_log('[WAAMINI] ecommerce_settings.php: prepare failed: ' . ($conn->error ?? 'unknown'));
        header("Location: ecommerce_settings.php?error=save_failed");
        exit();
    }
    $stmt->bind_param("issssssssi", $companyId, $gateway, $storedKey, $storedSecret, $storedWebhookSecret, $upiId, $upiName, $upiAlertNumbers, $currency, $active);
    if (!$stmt->execute()) {
        error_log('[WAAMINI] ecommerce_settings.php: execute failed: ' . ($stmt->error ?? 'unknown'));
        header("Location: ecommerce_settings.php?error=save_failed");
        exit();
    }

    $_SESSION['_flash_payment_saved'] = true;
    header("Location: ecommerce_settings.php");
    exit();
}

$gatewayCurrent = $settings['gateway'] ?? 'razorpay';
$displayApiKeyPlain = waamini_ecommerce_decrypt_field($settings['api_key'] ?? null);
$maskApiKeyField = waamini_ecommerce_mask_api_key_in_form($gatewayCurrent, $displayApiKeyPlain);

$hasSecretSaved = (($settings['api_secret'] ?? '') !== '');
$hasWebhookSecretSaved = (($settings['webhook_secret'] ?? '') !== '');

$gatewayLabels = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'upi' => 'Direct UPI'];
$effectiveKeyCheck = waamini_ecommerce_decrypt_field($settings['api_key'] ?? null);
$effectiveSecretCheck = waamini_ecommerce_decrypt_field($settings['api_secret'] ?? null);
$credsSummary = '—';
switch ($gatewayCurrent) {
    case 'razorpay':
        $credsSummary = ($effectiveKeyCheck !== '' && $effectiveSecretCheck !== '') ? 'API keys saved' : 'API keys incomplete';
        break;
    case 'stripe':
        $credsSummary = ($effectiveKeyCheck !== '') ? 'Stripe key saved' : 'Stripe key missing';
        break;
    case 'upi':
        $credsSummary = (trim((string)($settings['upi_id'] ?? '')) !== '') ? 'UPI ID saved' : 'UPI ID missing';
        break;
}
$webhookSummary = $hasWebhookSecretSaved ? 'Webhook signing secret saved' : 'Webhook secret not set (dev fallback may use Key Secret)';

$savedAtLabel = '';
if (!empty($settings['updated_at'])) {
    $ts = strtotime((string)$settings['updated_at']);
    if ($ts !== false) {
        $savedAtLabel = date('M j, Y \a\t g:i A', $ts);
    }
}

$showSavedBanner = !empty($_SESSION['_flash_payment_saved']);
if ($showSavedBanner) {
    unset($_SESSION['_flash_payment_saved']);
}

$webhookTestFlash = $_SESSION['_flash_webhook_test'] ?? null;
if (is_array($webhookTestFlash)) {
    unset($_SESSION['_flash_webhook_test']);
}


include '../includes/client_header.php';
?>

<div class="page-header">
    <div class="header-content">
        <h2>Payments &amp; checkout</h2>
        <p class="waamini-ec-page-lead">Connect Razorpay, Stripe, or UPI so customers can pay from WhatsApp.</p>
    </div>
</div>

<?php if ($showSavedBanner): ?>
<div class="alert alert-success" role="status" aria-live="polite">Settings saved successfully.</div>
<?php endif; ?>

<?php if (is_array($webhookTestFlash)): ?>
<div class="alert <?php echo ($webhookTestFlash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="status" aria-live="polite">
    <strong><?php echo htmlspecialchars((string)($webhookTestFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
    <?php if (!empty($webhookTestFlash['details'])): ?>
        <div style="margin-top:0.35rem; font-size:0.875rem; opacity:0.9; word-break:break-word;"><?php echo htmlspecialchars((string)$webhookTestFlash['details'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$err = $_GET['error'] ?? '';
if ($err === 'invalid_upi'): ?>
<div class="alert alert-danger" role="alert">Invalid UPI ID. Use the form <code>name@bank</code> (letters, numbers, dot, underscore, hyphen).</div>
<?php elseif ($err === 'razorpay_incomplete'): ?>
<div class="alert alert-danger" role="alert">Razorpay needs a Key ID and Key Secret. Enter both, or leave secret blank only if one is already saved.</div>
<?php elseif ($err === 'stripe_incomplete'): ?>
<div class="alert alert-danger" role="alert">Stripe needs a secret key. Enter it, or leave blank only if one is already saved.</div>
<?php elseif ($err === 'upi_incomplete'): ?>
<div class="alert alert-danger" role="alert">Direct UPI needs a valid UPI ID (VPA).</div>
<?php elseif ($err === 'save_failed'): ?>
<div class="alert alert-danger" role="alert">Could not save settings. Please try again or contact support.</div>
<?php endif; ?>

<div class="waamini-ec-status">
    <div class="waamini-ec-status-grid">
        <div class="waamini-ec-status-item"><span class="waamini-ec-muted">Gateway</span><br><strong><?php echo htmlspecialchars($gatewayLabels[$gatewayCurrent] ?? $gatewayCurrent, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="waamini-ec-status-item"><span class="waamini-ec-muted">Credentials</span><br><strong><?php echo htmlspecialchars($credsSummary, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="waamini-ec-status-item"><span class="waamini-ec-muted">Webhook</span><br><strong><?php echo htmlspecialchars($webhookSummary, ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
    <?php if ($savedAtLabel !== ''): ?>
    <div class="waamini-ec-status-saved waamini-ec-muted">Last saved: <?php echo htmlspecialchars($savedAtLabel, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>

<div class="waamini-ec-layout">
    <div class="waamini-ec-main">
        <form method="POST" class="form-card neumorphic" id="waamini-ec-form" aria-describedby="ec-form-desc">
            <p id="ec-form-desc" class="waamini-sr-only">Configure payment gateway, currency, and credentials for your store.</p>
            <?php echo csrf_field(); ?>
            
            <div class="waamini-ec-field-group" role="group" aria-labelledby="ec-gateway-heading">
                <div id="ec-gateway-heading" class="waamini-ec-section-heading">
                    <i class="fas fa-microchip" aria-hidden="true"></i>
                    <span>Payment gateway</span>
                </div>
                <div class="gateway-selector" role="radiogroup" aria-label="Choose payment gateway">
                    <label class="gateway-option">
                        <input type="radio" name="gateway" id="gw_razorpay" value="razorpay" <?php echo $gatewayCurrent === 'razorpay' ? 'checked' : ''; ?>>
                        <span class="option-content">
                            <span class="name">Razorpay</span>
                            <span class="desc">India — cards, UPI, wallets</span>
                        </span>
                    </label>
                    <label class="gateway-option">
                        <input type="radio" name="gateway" id="gw_upi" value="upi" <?php echo $gatewayCurrent === 'upi' ? 'checked' : ''; ?>>
                        <span class="option-content">
                            <span class="name">Direct UPI</span>
                            <span class="desc">QR / intent — no PG fee</span>
                        </span>
                    </label>
                    <label class="gateway-option">
                        <input type="radio" name="gateway" id="gw_stripe" value="stripe" <?php echo $gatewayCurrent === 'stripe' ? 'checked' : ''; ?>>
                        <span class="option-content">
                            <span class="name">Stripe</span>
                            <span class="desc">International cards</span>
                        </span>
                    </label>
                </div>
            </div>

            <div id="razorpay_fields" class="gateway-fields" style="<?php echo in_array($gatewayCurrent, ['razorpay','stripe'], true) ? '' : 'display:none;'; ?>" <?php echo in_array($gatewayCurrent, ['razorpay','stripe'], true) ? '' : 'hidden'; ?>>
                <div class="waamini-ec-form-grid">
                    <div class="form-group">
                        <label for="ec_api_key"><?php echo $gatewayCurrent === 'stripe' ? 'Stripe secret key' : 'Key ID (public)'; ?></label>
                        <input type="text" name="api_key" id="ec_api_key" class="form-control" autocomplete="off"
                            <?php if ($maskApiKeyField): ?>value="" placeholder="Saved — type a new key only to replace" aria-describedby="ec_api_key_help"
                            <?php else: ?>value="<?php echo htmlspecialchars($displayApiKeyPlain, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php endif; ?>>
                        <?php if ($maskApiKeyField): ?>
                        <p id="ec_api_key_help" class="waamini-ec-field-hint">A secret key is already stored and is not shown. Paste a new key only if you are rotating credentials.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="ec_api_secret"><?php echo $gatewayCurrent === 'stripe' ? 'Not used for Stripe Checkout here' : 'Key Secret'; ?></label>
                        <input type="password" name="api_secret" id="ec_api_secret" class="form-control" value="" autocomplete="new-password" placeholder="<?php echo $hasSecretSaved ? 'Leave blank to keep saved secret' : ($gatewayCurrent === 'stripe' ? 'Optional for this integration' : '••••••••••••'); ?>" <?php echo $gatewayCurrent === 'stripe' ? 'disabled aria-disabled="true"' : ''; ?>>
                        <?php if ($gatewayCurrent === 'stripe'): ?>
                        <p class="waamini-ec-field-hint">Stripe uses the secret key above for API calls in this app.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="waamini-ec-form-grid waamini-ec-form-grid--full">
                    <div class="form-group">
                        <label for="ec_webhook_secret"><?php echo $gatewayCurrent === 'stripe' ? 'Stripe webhook signing secret (endpoint secret)' : 'Razorpay webhook signing secret'; ?></label>
                        <input type="password" name="webhook_secret" id="ec_webhook_secret" class="form-control" value=""
                               placeholder="<?php
                                    echo $hasWebhookSecretSaved
                                        ? 'Leave blank to keep saved secret'
                                        : ($gatewayCurrent === 'stripe'
                                            ? 'From Stripe Dashboard → Developers → Webhooks → Signing secret'
                                            : 'From Razorpay Dashboard → Webhooks → Secret'
                                        );
                               ?>"
                               autocomplete="new-password">
                        <p class="waamini-ec-field-hint">
                            <?php if ($gatewayCurrent === 'stripe'): ?>
                                Paste the <strong>endpoint signing secret</strong> (starts with <code>whsec_</code>) from Stripe Webhooks so Waamini can verify events and mark orders paid.
                            <?php else: ?>
                                Use the <strong>signing secret</strong> from Razorpay Webhooks (not your API Key Secret).
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <div id="upi_fields" class="gateway-fields waamini-ec-upi-block" <?php echo ($gatewayCurrent === 'upi' || $gatewayCurrent === 'razorpay') ? '' : 'hidden'; ?> style="<?php echo ($gatewayCurrent === 'upi' || $gatewayCurrent === 'razorpay') ? '' : 'display:none;'; ?>">
                <div class="waamini-ec-subheading">
                    <span>UPI details</span>
                </div>
                <div class="waamini-ec-form-grid">
                    <div class="form-group">
                        <label for="ec_upi_id">UPI ID (VPA)</label>
                        <input type="text" name="upi_id" id="ec_upi_id" class="form-control" value="<?php echo htmlspecialchars($settings['upi_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="business@okicici" autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="ec_upi_name">Payee name</label>
                        <input type="text" name="upi_name" id="ec_upi_name" class="form-control" value="<?php echo htmlspecialchars($settings['upi_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your business name" autocomplete="organization">
                    </div>
                </div>
                <div class="waamini-ec-form-grid waamini-ec-form-grid--full" style="margin-top:12px;">
                    <div class="form-group">
                        <label for="ec_upi_alert_numbers">UPI payment alert number(s)</label>
                        <input type="text" name="upi_alert_numbers" id="ec_upi_alert_numbers" class="form-control"
                               value="<?php echo htmlspecialchars($settings['upi_alert_numbers'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="919003876516 (comma separated)">
                        <p class="waamini-ec-field-hint">When a customer chooses UPI payment, Waamini will send a WhatsApp alert to these numbers (country code, no +).</p>
                    </div>
                </div>
            </div>

            <div class="waamini-ec-form-grid waamini-ec-currency-row">
                <div class="form-group">
                    <label for="ec_currency">Settlement currency</label>
                    <select name="currency" id="ec_currency" class="form-control waamini-ec-select-pill">
                        <option value="INR" <?php echo ($settings['currency'] ?? 'INR') === 'INR' ? 'selected' : ''; ?>>INR (₹)</option>
                        <option value="USD" <?php echo ($settings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                    </select>
                </div>
            </div>

            <div class="waamini-ec-actions">
                <div class="switch-control">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo ($settings['is_active'] ?? 0) ? 'checked' : ''; ?>>
                    <label for="is_active" class="waamini-ec-switch-label">Enable live checkout</label>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
                    <button type="submit" name="test_webhook" value="1" class="btn btn-outline-secondary waamini-ec-save-btn">Test webhook</button>
                    <button type="submit" name="save" class="btn btn-primary waamini-ec-save-btn">Save settings</button>
                </div>
            </div>
        </form>
    </div>

    <aside class="waamini-ec-aside">
        <div class="info-card neumorphic waamini-ec-aside-card">
            <h4 class="waamini-ec-aside-title">Webhook URL</h4>
            <p class="waamini-ec-help">In Razorpay Dashboard → Webhooks, add this URL and subscribe to <code>payment_link.paid</code> (and optionally <code>payment_link.updated</code>).</p>
            <div class="waamini-ec-code-snippet" id="webhookUrlText"><?php echo $webhookUrlFull !== '' ? htmlspecialchars($webhookUrlFull, ENT_QUOTES, 'UTF-8') : 'Use a public HTTPS domain in production.'; ?></div>
            <?php if ($webhookUrlFull !== ''): ?>
            <button type="button" class="btn btn-sm waamini-ec-copy-btn" id="copyWebhookBtn" aria-label="Copy webhook URL to clipboard">Copy URL</button>
            <?php endif; ?>
            <form method="POST" style="margin-bottom:1rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="gateway_for_test" value="<?php echo htmlspecialchars($gatewayCurrent, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="test_webhook" value="1" class="btn btn-sm waamini-ec-copy-btn" style="width:100%;">Run signed webhook test</button>
            </form>
            <h5 class="waamini-ec-tips-title">Tips</h5>
            <ul class="info-list waamini-ec-tips-list">
                <li><strong>Razorpay:</strong> Best for Indian customers (cards, UPI, wallets).</li>
                <li><strong>Stripe:</strong> Use for international cards; secret keys are never shown after save.</li>
                <li><strong>Direct UPI:</strong> Sends a QR or link on WhatsApp; you confirm payment manually if needed.</li>
            </ul>
        </div>
    </aside>
</div>

<style>
/* This page must not rely on Bootstrap: client.css does not ship grid/d-flex utilities. */
.waamini-ec-status {
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--bg-card);
}
.waamini-ec-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem;
    font-size: 0.875rem;
}
.waamini-ec-muted { color: var(--text-muted); }
.waamini-ec-page-lead {
    color: var(--text-muted);
    margin: 0;
    font-size: 0.9375rem;
    line-height: 1.5;
}
.waamini-ec-field-hint {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin: 0.35rem 0 0;
    line-height: 1.45;
}
.waamini-ec-status-saved {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    font-size: 0.8125rem;
}

.waamini-ec-layout {
    display: grid;
    gap: 1.5rem;
    align-items: start;
}
@media (min-width: 992px) {
    .waamini-ec-layout {
        grid-template-columns: minmax(0, 1fr) minmax(260px, 340px);
    }
}

.waamini-ec-field-group { margin-bottom: 1.75rem; }
.waamini-ec-section-heading {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 800;
    color: var(--accent);
    font-size: 1rem;
    margin-bottom: 0.75rem;
}

.gateway-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(158px, 1fr));
    gap: 12px;
    width: 100%;
}
.gateway-option {
    display: block;
    cursor: pointer;
    margin: 0;
    position: relative;
}
.gateway-option input[type="radio"] {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.gateway-option:focus-within .option-content,
.gateway-option input:focus-visible + .option-content {
    outline: 2px solid var(--accent, #00b894);
    outline-offset: 3px;
}
.gateway-option .option-content {
    display: block;
    background: var(--bg-input);
    border: 2px solid var(--border);
    padding: 14px 16px;
    border-radius: 16px;
    transition: border-color 0.2s, background 0.2s;
    min-height: 4.5rem;
    box-sizing: border-box;
}
.gateway-option input:checked + .option-content {
    border-color: var(--accent);
    background: var(--accent-light);
}
.gateway-option .name {
    font-weight: 700;
    color: var(--text-primary);
    font-size: 13px;
    display: block;
    margin-bottom: 4px;
}
.gateway-option .desc {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.35;
    display: block;
}

.waamini-ec-form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
@media (min-width: 768px) {
    .waamini-ec-form-grid:not(.waamini-ec-form-grid--full) {
        grid-template-columns: 1fr 1fr;
    }
}
.waamini-ec-form-grid--full {
    grid-template-columns: 1fr;
}

.waamini-ec-upi-block { margin-top: 0.5rem; }
.waamini-ec-subheading {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 0.75rem;
}

.waamini-ec-currency-row {
    margin-top: 0.5rem;
    max-width: 320px;
}
.waamini-ec-select-pill {
    border-radius: 999px;
}

input:disabled, select:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--bg-input) !important;
}

.waamini-ec-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border);
}
.switch-control {
    display: flex;
    align-items: center;
    gap: 10px;
}
.waamini-ec-switch-label {
    font-size: 0.8125rem;
    font-weight: 700;
    margin-bottom: 0;
}
.waamini-ec-save-btn {
    padding-left: 2rem;
    padding-right: 2rem;
    border-radius: 999px;
}

.neumorphic {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
}

.waamini-ec-aside-card { height: 100%; }
.waamini-ec-aside-title { margin-bottom: 0.75rem; font-size: 1rem; font-weight: 700; }
.waamini-ec-help {
    font-size: 0.8125rem;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: 0.75rem;
}
.waamini-ec-help code { font-size: 0.75rem; }
.waamini-ec-code-snippet {
    padding: 10px 12px;
    background: #111;
    color: #fff;
    border-radius: 10px;
    font-family: ui-monospace, monospace;
    font-size: 11px;
    word-break: break-all;
    margin-bottom: 0.75rem;
    line-height: 1.45;
}
.waamini-ec-copy-btn {
    margin-bottom: 1.25rem;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-primary);
}
.waamini-ec-copy-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}
.waamini-ec-tips-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 0.5rem;
}
.waamini-ec-tips-list {
    padding: 0;
    margin: 0;
    list-style: none;
}
.waamini-ec-tips-list li {
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 10px;
    line-height: 1.45;
}

.waamini-sr-only {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
}
</style>

<script>
(function () {
    function syncPanels() {
        var v = document.querySelector('input[name="gateway"]:checked');
        var val = v ? v.value : 'razorpay';
        var rz = document.getElementById('razorpay_fields');
        var upi = document.getElementById('upi_fields');
        if (rz) {
            var showKeys = val === 'razorpay' || val === 'stripe';
            rz.style.display = showKeys ? '' : 'none';
            rz.hidden = !showKeys;
            rz.querySelectorAll('#ec_webhook_secret').forEach(function(el){
                // Webhook signing secret is needed for BOTH Razorpay and Stripe in production
                el.disabled = false;
                el.setAttribute('aria-disabled', 'false');
            });
            var secIn = document.getElementById('ec_api_secret');
            var keyLabel = document.querySelector('label[for="ec_api_key"]');
            var secLabel = document.querySelector('label[for="ec_api_secret"]');
            
            if (keyLabel) {
                keyLabel.textContent = val === 'stripe' ? 'Stripe secret key' : 'Key ID (public)';
            }
            if (secLabel) {
                secLabel.textContent = val === 'stripe' ? 'Not used for Stripe Checkout here' : 'Key Secret';
            }

            if (secIn) {
                secIn.disabled = val === 'stripe';
                secIn.setAttribute('aria-disabled', val === 'stripe' ? 'true' : 'false');
                if (val === 'stripe') {
                    secIn.placeholder = 'Optional for this integration';
                } else {
                    secIn.placeholder = '••••••••••••';
                }
            }
        }
        if (upi) {
            var showUpi = val === 'upi' || val === 'razorpay';
            upi.style.display = showUpi ? '' : 'none';
            upi.hidden = !showUpi;
        }
    }
    document.querySelectorAll('input[name="gateway"]').forEach(function (el) {
        el.addEventListener('change', syncPanels);
    });
    syncPanels();

    var btn = document.getElementById('copyWebhookBtn');
    var urlEl = document.getElementById('webhookUrlText');
    if (btn && urlEl && navigator.clipboard && navigator.clipboard.writeText) {
        btn.addEventListener('click', function () {
            var t = urlEl.textContent.trim();
            navigator.clipboard.writeText(t).then(function () {
                var prev = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = prev; }, 2000);
            }).catch(function () {});
        });
    }
})();
</script>

<?php include '../includes/client_footer.php'; ?>
