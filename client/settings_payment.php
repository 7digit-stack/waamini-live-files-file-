<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/ecommerce_handler.php';

$pageTitle = 'Payment Settings';
$currentPage = 'settings_payment';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();

    $gatewayIn = trim((string)($_POST['gateway'] ?? 'razorpay'));
    $gateway = in_array($gatewayIn, ['razorpay', 'upi'], true) ? $gatewayIn : 'razorpay';

    $curIn = strtoupper(trim((string)($_POST['currency'] ?? 'INR')));
    $currency = in_array($curIn, ['INR', 'USD'], true) ? $curIn : 'INR';

    $upiId = trim((string)($_POST['upi_id'] ?? ''));
    $upiName = mb_substr(trim((string)($_POST['upi_name'] ?? '')), 0, 120);

    $apiKeyPlain = trim((string)($_POST['api_key'] ?? ''));
    $apiSecretPlain = trim((string)($_POST['api_secret'] ?? ''));
    $webhookSecretPlain = trim((string)($_POST['webhook_secret'] ?? ''));

    if ($upiId !== '' && !preg_match('/^[a-zA-Z0-9._-]{2,64}@[a-zA-Z0-9.-]{2,64}$/', $upiId)) {
        header("Location: settings_payment.php?error=invalid_upi");
        exit();
    }

    $storedKey = (string)($settings['api_key'] ?? '');
    if ($apiKeyPlain !== '') {
        $storedKey = function_exists('encrypt_secret') ? encrypt_secret($apiKeyPlain) : $apiKeyPlain;
    }

    $storedSecret = (string)($settings['api_secret'] ?? '');
    if ($apiSecretPlain !== '') {
        $storedSecret = function_exists('encrypt_secret') ? encrypt_secret($apiSecretPlain) : $apiSecretPlain;
    }

    $storedWebhookSecret = (string)($settings['webhook_secret'] ?? '');
    if ($webhookSecretPlain !== '') {
        $storedWebhookSecret = function_exists('encrypt_secret') ? encrypt_secret($webhookSecretPlain) : $webhookSecretPlain;
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
                header("Location: settings_payment.php?error=razorpay_incomplete");
                exit();
            }
        } elseif ($gateway === 'upi') {
            if ($upiId === '') {
                header("Location: settings_payment.php?error=upi_incomplete");
                exit();
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO ecommerce_settings (company_id, gateway, api_key, api_secret, webhook_secret, upi_id, upi_name, currency, is_active, updated_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
                           ON DUPLICATE KEY UPDATE 
                           gateway=VALUES(gateway), api_key=VALUES(api_key), api_secret=VALUES(api_secret), webhook_secret=VALUES(webhook_secret),
                           upi_id=VALUES(upi_id), upi_name=VALUES(upi_name), currency=VALUES(currency), is_active=VALUES(is_active), updated_at=NOW()");
    if (!$stmt) {
        error_log('[WAAMINI] settings_payment.php: prepare failed: ' . ($conn->error ?? 'unknown'));
        header("Location: settings_payment.php?error=save_failed");
        exit();
    }
    $stmt->bind_param("isssssssi", $companyId, $gateway, $storedKey, $storedSecret, $storedWebhookSecret, $upiId, $upiName, $currency, $active);
    if (!$stmt->execute()) {
        error_log('[WAAMINI] settings_payment.php: execute failed: ' . ($stmt->error ?? 'unknown'));
        header("Location: settings_payment.php?error=save_failed");
        exit();
    }

    $_SESSION['_flash_payment_saved'] = true;
    header("Location: settings_payment.php");
    exit();
}

$gatewayCurrentSp = $settings['gateway'] ?? 'razorpay';
$displayApiKey = waamini_ecommerce_decrypt_field($settings['api_key'] ?? null);
$maskApiKeySp = waamini_ecommerce_mask_api_key_in_form($gatewayCurrentSp, $displayApiKey);
$hasSecretSaved = (($settings['api_secret'] ?? '') !== '');
$hasWebhookSecretSaved = (($settings['webhook_secret'] ?? '') !== '');

$webhookHost = preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$webhookHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', $webhookHost);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$webhookProtocol = $isHttps ? 'https' : 'http';
$webhookUrl = ($webhookHost !== '')
    ? ($webhookProtocol . '://' . $webhookHost . '/api/payment_webhook.php?company_id=' . (int)$companyId)
    : '';

$showSavedBannerSp = !empty($_SESSION['_flash_payment_saved']);
if ($showSavedBannerSp) {
    unset($_SESSION['_flash_payment_saved']);
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <div class="header-content">
        <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> Payment Infrastructure</h2>
        <p class="text-muted">Configure your Razorpay, UPI, and local payment gateways.</p>
    </div>
</div>

<?php if ($showSavedBannerSp): ?>
<div class="alert alert-success" role="status" aria-live="polite">Settings saved successfully.</div>
<?php endif; ?>

<?php
$err = $_GET['error'] ?? '';
if ($err === 'invalid_upi'): ?>
<div class="alert alert-danger">Invalid UPI VPA format. Use form <code>name@bank</code>.</div>
<?php elseif ($err === 'razorpay_incomplete'): ?>
<div class="alert alert-danger">Razorpay requires Key ID and Secret Key (or previously saved values).</div>
<?php elseif ($err === 'upi_incomplete'): ?>
<div class="alert alert-danger">Direct UPI requires a valid UPI ID (VPA).</div>
<?php elseif ($err === 'save_failed'): ?>
<div class="alert alert-danger">Could not save settings. Please try again.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST" class="form-card neumorphic">
            <?php echo csrf_field(); ?>
            
            <div class="section-header mb-4">
                <i class="icon-shield"></i>
                <span>Gateway Configuration</span>
            </div>

            <div class="form-group mb-4">
                <label>Primary Gateway</label>
                <div class="gateway-selector d-flex gap-3 mt-2">
                    <label class="gateway-option">
                        <input type="radio" name="gateway" value="razorpay" <?php echo ($settings['gateway'] ?? 'razorpay') == 'razorpay' ? 'checked' : ''; ?>>
                        <div class="option-content">
                            <span class="name">Razorpay</span>
                            <span class="desc">Cards, Netbanking, UPI</span>
                        </div>
                    </label>
                    <label class="gateway-option">
                        <input type="radio" name="gateway" value="upi" <?php echo ($settings['gateway'] ?? '') == 'upi' ? 'checked' : ''; ?>>
                        <div class="option-content">
                            <span class="name">Direct UPI</span>
                            <span class="desc">Scan & Pay (0% Fee)</span>
                        </div>
                    </label>
                </div>
            </div>

            <div id="razorpay_fields" class="gateway-fields" style="<?php echo ($settings['gateway'] ?? 'razorpay') == 'razorpay' ? '' : 'display:none;'; ?>">
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>Key ID</label>
                        <input type="text" name="api_key" class="form-control" autocomplete="off" placeholder="<?php echo $maskApiKeySp ? 'Saved — enter new key only to replace' : 'rzp_live_...'; ?>"
                            <?php if ($maskApiKeySp): ?>value="" aria-describedby="sp_api_key_help"
                            <?php else: ?>value="<?php echo htmlspecialchars($displayApiKey, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php endif; ?>>
                        <?php if ($maskApiKeySp): ?>
                        <p id="sp_api_key_help" class="small text-muted mb-0 mt-1">Key is saved and hidden. Paste only when rotating keys.</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Key Secret</label>
                        <input type="password" name="api_secret" class="form-control" value="" placeholder="<?php echo $hasSecretSaved ? 'Leave blank to keep saved secret' : '••••••••••••'; ?>" autocomplete="new-password">
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12 form-group">
                        <label>Razorpay webhook signing secret</label>
                        <input type="password" name="webhook_secret" class="form-control" value="" placeholder="<?php echo $hasWebhookSecretSaved ? 'Leave blank to keep saved' : 'From Razorpay → Webhooks'; ?>" autocomplete="new-password">
                        <p class="small text-muted mb-0 mt-1">Signing secret from Razorpay Dashboard (recommended). URL: <code><?php echo $webhookUrl !== '' ? htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') : '…'; ?></code></p>
                    </div>
                </div>
            </div>

            <div id="upi_fields" class="gateway-fields mt-4">
                <div class="section-header sub mb-3">
                    <span>UPI Details</span>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>UPI ID (VPA)</label>
                        <input type="text" name="upi_id" class="form-control" value="<?php echo htmlspecialchars($settings['upi_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="business@okicici">
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Display Name</label>
                        <input type="text" name="upi_name" class="form-control" value="<?php echo htmlspecialchars($settings['upi_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Acme Solutions">
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-6 form-group">
                    <label>Base currency</label>
                    <select name="currency" class="form-control">
                        <option value="INR" <?php echo ($settings['currency'] ?? 'INR') === 'INR' ? 'selected' : ''; ?>>INR (₹)</option>
                        <option value="USD" <?php echo ($settings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                    </select>
                </div>
            </div>

            <div class="form-group mt-4 pt-3 border-top d-flex align-items-center justify-content-between">
                <div class="switch-control">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo ($settings['is_active'] ?? 0) ? 'checked' : ''; ?>>
                    <label for="is_active">Enable gateway for production checkout</label>
                </div>
                <button type="submit" name="save" class="btn btn-primary px-5">Synchronize Settings</button>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="info-card neumorphic">
            <h4><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> Integration Nodes</h4>
            <ul class="info-list">
                <li>
                    <strong>Razorpay:</strong> Automatically generates dynamic payment links and sends them to customers.
                </li>
                <li>
                    <strong>Direct UPI:</strong> Generates a static/dynamic QR code. No transaction fees, but 2FA manual verification recommended.
                </li>
                <li>
                    <strong>Security:</strong> API keys and webhook secrets are encrypted at rest; secrets are never echoed back in forms.
                </li>
            </ul>
        </div>
    </div>
</div>

<style>
.gateway-option {
    flex: 1;
    cursor: pointer;
    position: relative;
}
.gateway-option input {
    position: absolute;
    opacity: 0;
}
.gateway-option .option-content {
    background: var(--bg-card);
    border: 2px solid var(--border);
    padding: 15px;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    transition: 0.3s;
}
.gateway-option input:checked + .option-content {
    border-color: var(--accent);
    background: rgba(var(--accent-rgb), 0.05);
}
.gateway-option .name {
    font-weight: 700;
    color: var(--text-primary);
    font-size: 14px;
}
.gateway-option .desc {
    font-size: 11px;
    color: var(--text-muted);
}
.section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 700;
    color: var(--accent);
}
.section-header.sub {
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.neumorphic {
    background: var(--bg-card);
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 20px;
    padding: 30px;
}
.switch-control {
    display: flex;
    align-items: center;
    gap: 12px;
}
.info-card {
    padding: 24px;
}
.info-card h4 {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 16px;
}
.info-list {
    padding: 0;
    margin: 0;
    list-style: none;
}
.info-list li {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 12px;
    line-height: 1.6;
}
</style>

<script>
document.querySelectorAll('input[name="gateway"]').forEach(el => {
    el.addEventListener('change', function() {
        document.getElementById('razorpay_fields').style.display = (this.value == 'razorpay') ? 'block' : 'none';
        document.getElementById('upi_fields').style.display = (this.value == 'upi' || this.value == 'razorpay') ? 'block' : 'none';
    });
});
</script>

<?php include '../includes/client_footer.php'; ?>
