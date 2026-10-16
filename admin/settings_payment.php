<?php
include '../includes/auth_check.php';
include '../db.php';
if (file_exists(__DIR__ . '/../services/EncryptionService.php')) {
    require_once __DIR__ . '/../services/EncryptionService.php';
}

$pageTitle = 'Payment Gateway Settings';
$currentPage = 'settings_payment';

if (isset($_POST['save_payment'])) {
    csrf_verify();
    $fields = ['payment_gateway', 'razorpay_key_id', 'razorpay_key_secret', 'stripe_public_key', 'stripe_secret_key', 'currency', 'currency_symbol'];
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'payment') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group='payment'");
    if ($stmt) {
        foreach ($fields as $key) {
            $raw = $_POST[$key] ?? null;
            if ($key === 'razorpay_key_secret' || $key === 'stripe_secret_key') {
                if ($raw === '********' || $raw === null) {
                    continue;
                }
                $val = trim((string)$raw);
                if (function_exists('encrypt_secret') && $val !== '') {
                    $val = encrypt_secret($val);
                }
            } else {
                $val = trim((string)($raw ?? ''));
            }
            $stmt->bind_param('ss', $key, $val);
            $stmt->execute();
        }
        $stmt->close();
    }
    header("Location: settings_payment.php?msg=saved");
    exit();
}

$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='payment'");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$currencies = [
    'INR' => ['symbol' => "\u{20B9}", 'name' => 'Indian Rupee'],
    'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
    'EUR' => ['symbol' => "\u{20AC}", 'name' => 'Euro'],
    'GBP' => ['symbol' => "\u{00A3}", 'name' => 'British Pound'],
    'AED' => ['symbol' => "\u{062F}.\u{0625}", 'name' => 'UAE Dirham'],
    'SAR' => ['symbol' => "\u{FDFC}", 'name' => 'Saudi Riyal'],
    'SGD' => ['symbol' => 'S$', 'name' => 'Singapore Dollar'],
    'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar'],
];

include '../includes/admin_header.php';
?>
<div style="margin-bottom:16px;">
    <a href="settings.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-secondary);text-decoration:none;transition:color 0.2s;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="m15 18-6-6 6-6"/></svg>
        Back to Configurations
    </a>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(255,152,0,0.12);color:#ff9800;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></div>
        <div class="stat-info"><div class="stat-number"><?php echo htmlspecialchars(ucfirst($settings['payment_gateway'] ?? 'Razorpay')); ?></div><div class="stat-label">Active Gateway</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(108,92,231,0.12);color:#a78bfa;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="stat-info"><div class="stat-number"><?php echo htmlspecialchars($settings['currency'] ?? 'INR'); ?></div><div class="stat-label">Currency</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,200,83,0.12);color:#00c853;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
        <div class="stat-info"><div class="stat-number"><?php echo !empty($settings['razorpay_key_id']) ? 'Set' : 'Not Set'; ?></div><div class="stat-label">Razorpay Keys</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(33,150,243,0.12);color:#2196f3;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
        <div class="stat-info"><div class="stat-number"><?php echo !empty($settings['stripe_public_key']) ? 'Set' : 'Not Set'; ?></div><div class="stat-label">Stripe Keys</div></div>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function() {
    function toggleGateway() {
        var val = document.getElementById('gatewaySelect').value;
        document.getElementById('razorpayCard').style.display = val === 'razorpay' ? 'block' : 'none';
        document.getElementById('stripeCard').style.display = val === 'stripe' ? 'block' : 'none';
    }
    function updateCurrencySymbol() {
        var sel = document.getElementById('currencySelect');
        var symbol = sel.options[sel.selectedIndex].dataset.symbol;
        document.getElementById('currencySymbol').value = symbol;
    }
    document.getElementById('gatewaySelect').addEventListener('change', toggleGateway);
    document.getElementById('currencySelect').addEventListener('change', updateCurrencySymbol);
    toggleGateway();
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;margin-right:8px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
    Payment settings saved successfully!
</div>
<?php endif; ?>

<div class="page-header">
    <h2>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        Payment Gateway Settings
    </h2>
</div>

<form method="POST">
    <?php echo csrf_field(); ?>
    <div class="settings-grid">
        <div class="form-card">
            <h3 class="settings-section-title">Payment Gateway</h3>
            <div class="form-group">
                <label class="form-label">Active Gateway</label>
                <select name="payment_gateway" class="form-control" id="gatewaySelect">
                    <option value="razorpay" <?php echo ($settings['payment_gateway'] ?? '') === 'razorpay' ? 'selected' : ''; ?>>Razorpay</option>
                    <option value="stripe" <?php echo ($settings['payment_gateway'] ?? '') === 'stripe' ? 'selected' : ''; ?>>Stripe</option>
                    <option value="paypal" <?php echo ($settings['payment_gateway'] ?? '') === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                </select>
            </div>
            <div class="info-box">
                <span class="info-icon">ℹ️</span>
                <span>Choose the payment gateway you want to use for processing transactions.</span>
            </div>
        </div>

        <div class="form-card">
            <h3 class="settings-section-title">Currency Settings</h3>
            <div class="form-group">
                <label class="form-label">Currency</label>
                <select name="currency" class="form-control" id="currencySelect">
                    <?php foreach ($currencies as $code => $info): ?>
                    <option value="<?php echo $code; ?>" data-symbol="<?php echo $info['symbol']; ?>" <?php echo ($settings['currency'] ?? 'INR') === $code ? 'selected' : ''; ?>>
                        <?php echo $code; ?> - <?php echo $info['name']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Currency Symbol</label>
                <input type="text" name="currency_symbol" class="form-control" id="currencySymbol" value="<?php echo htmlspecialchars($settings['currency_symbol'] ?? ''); ?>" placeholder="₹">
            </div>
        </div>
        <div class="form-card" id="razorpayCard">
            <h3 class="settings-section-title">Razorpay Configuration</h3>
            <div class="form-group">
                <label class="form-label">API Key ID</label>
                <input type="text" name="razorpay_key_id" class="form-control" value="<?php echo htmlspecialchars($settings['razorpay_key_id'] ?? ''); ?>" placeholder="rzp_live_xxxxxxxxxxxxx">
            </div>
            <div class="form-group">
                <label class="form-label">API Key Secret</label>
                <input type="password" name="razorpay_key_secret" class="form-control" value="<?php echo !empty($settings['razorpay_key_secret']) ? '********' : ''; ?>" placeholder="••••••••••••••••">
            </div>
            <div class="info-box">
                <span class="info-icon">🔗</span>
                <span>Get your Razorpay API keys from <a href="https://dashboard.razorpay.com/app/keys" target="_blank" style="color:var(--accent);">dashboard.razorpay.com → Settings → API Keys</a></span>
            </div>
        </div>

        <div class="form-card" id="stripeCard" style="display:none;">
            <h3 class="settings-section-title">Stripe Configuration</h3>
            <div class="form-group">
                <label class="form-label">Public Key</label>
                <input type="text" name="stripe_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['stripe_public_key'] ?? ''); ?>" placeholder="pk_live_xxxxxxxxxxxxx">
            </div>
            <div class="form-group">
                <label class="form-label">Secret Key</label>
                <input type="password" name="stripe_secret_key" class="form-control" value="<?php echo !empty($settings['stripe_secret_key']) ? '********' : ''; ?>" placeholder="sk_live_xxxxxxxxxxxxx">
            </div>
            <div class="info-box">
                <span class="info-icon">🔗</span>
                <span>Get your Stripe API keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank" style="color:var(--accent);">dashboard.stripe.com → Developers → API Keys</a></span>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" name="save_payment" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
            Save Payment Settings
        </button>
        <a href="settings.php" class="btn btn-edit">Back to Configurations</a>
    </div>
</form>

<div style="margin-top:16px;">
    <div class="info-box">
        <span class="info-icon">🔒</span>
        <span><strong>Note:</strong> Secret keys are stored encrypted and are masked in the UI. Leave the secret fields blank (or as ********) to keep existing secrets. Add webhook endpoints in your payment provider dashboard and point them to your client webhook URL.</span>
    </div>
</div>
<style nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
.settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
.settings-grid .form-card { max-width:none; }
.settings-section-title { font-size:15px; font-weight:600; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid var(--border); color:var(--text-primary); display:flex; align-items:center; gap:8px; }
.info-box { display:flex; gap:10px; padding:14px; background:var(--info-bg); border:1px solid var(--info-border); border-radius:var(--radius); font-size:13px; color:var(--text-secondary); line-height:1.5; }
.info-box .info-icon { font-size:18px; flex-shrink:0; }
.info-box strong { color:var(--text-primary); }
.form-actions { display:flex; gap:12px; align-items:center; margin-top:24px; }
@media (max-width:900px) { .settings-grid { grid-template-columns:1fr; } }
</style>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function() {
    function toggleGateway() {
        var val = document.getElementById('gatewaySelect').value;
        document.getElementById('razorpayCard').style.display = val === 'razorpay' ? 'block' : 'none';
        document.getElementById('stripeCard').style.display = val === 'stripe' ? 'block' : 'none';
    }
    function updateCurrencySymbol() {
        var sel = document.getElementById('currencySelect');
        var symbol = sel.options[sel.selectedIndex].dataset.symbol;
        document.getElementById('currencySymbol').value = symbol;
    }
    document.getElementById('gatewaySelect').addEventListener('change', toggleGateway);
    document.getElementById('currencySelect').addEventListener('change', updateCurrencySymbol);
    toggleGateway();
})();
</script>

<?php include '../includes/admin_footer.php'; ?>