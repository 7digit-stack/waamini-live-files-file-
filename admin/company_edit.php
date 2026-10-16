<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Edit Company';
$currentPage = 'companies';

if (!isset($_GET['id'])) {
    header("Location: companies.php");
    exit();
}

$id = intval($_GET['id']);
$company = null;
$stmt_c = $conn->prepare("SELECT * FROM companies WHERE id=?");
if ($stmt_c) {
    $stmt_c->bind_param("i", $id);
    $stmt_c->execute();
    $company = $stmt_c->get_result()->fetch_assoc();
}

if (!$company) {
    header("Location: companies.php");
    exit();
}

$error = '';

// Per-company add-ons (Ads & Attribution). Stored in companies.features_override, which
// includes/feature_check.php already applies on top of the plan: missing key = follow the plan.
$addonFlags = [
    'feat_ads_attribution' => ['Ad Lead Attribution', 'Which Click-to-WhatsApp ad brought each lead (Ad Leads page, lead source in chat).'],
    'feat_ads_capi'        => ['Conversions API', 'Send sales and qualified leads back to Meta (Conversion Events page).'],
    'feat_ads_insights'    => ['Ad Spend Insights', 'Spend, cost per lead and cost per sale from the ad account. Next release.'],
    'feat_ads_builder'     => ['Ad Builder', 'Create Click-to-WhatsApp ads from WAAMINI. Needs Meta app approval.'],
];
try {
    $c = $conn->query("SHOW COLUMNS FROM companies LIKE 'features_override'");
    if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE companies ADD COLUMN features_override TEXT NULL");
} catch (\Throwable $e) { error_log('[company_edit] features_override: ' . $e->getMessage()); }
$overrides = json_decode((string)($company['features_override'] ?? ''), true);
if (!is_array($overrides)) $overrides = [];

if (isset($_POST['submit'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_phone($_POST['phone'] ?? '');

    if (empty($name)) {
        $error = "Company name is required!";
    }
    else {
        $featNative = isset($_POST['feat_native_checkout']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE companies SET name=?, email=?, phone=?, feat_native_checkout=? WHERE id=?");
        $stmt->bind_param("sssii", $name, $email, $phone, $featNative, $id);
        $stmt->execute();

        // Add-ons: 'plan' removes the override, 'on' / 'off' force it for this company only
        foreach (array_keys($addonFlags) as $flag) {
            $v = (string)($_POST['addon'][$flag] ?? 'plan');
            if ($v === 'on') $overrides[$flag] = 1;
            elseif ($v === 'off') $overrides[$flag] = 0;
            else unset($overrides[$flag]);
        }
        $ovJson = $overrides ? json_encode($overrides) : null;
        $stOv = $conn->prepare("UPDATE companies SET features_override=? WHERE id=?");
        if ($stOv) { $stOv->bind_param('si', $ovJson, $id); $stOv->execute(); }
        if (in_array('feat_ads_attribution', array_keys(array_filter($overrides)), true)) {
            require_once __DIR__ . '/../includes/ads/schema.php';
            ads_ensure_schema($conn);   // tables ready before the company opens Ad Leads
        }
        header("Location: companies.php?msg=updated");
        exit();
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="vertical-align:middle;"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg> Edit Company #<?php echo $id; ?></h2>
    <a href="companies.php" class="btn btn-sm btn-edit">← Back to List</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Company Name *</label>
            <input type="text" name="name" class="form-control" 
                   value="<?php echo htmlspecialchars($company['name']); ?>" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" 
                   value="<?php echo htmlspecialchars($company['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" 
                   value="<?php echo htmlspecialchars($company['phone'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <div style="margin-top: 4px;">
                <span class="badge <?php echo $company['status'] === 'active' ? 'badge-active' : 'badge-suspended'; ?>" 
                      style="font-size: 13px; padding: 6px 14px;">
                    <?php echo ucfirst($company['status']); ?>
                </span>
                <form method="POST" action="companies.php" style="display:inline;margin-left:12px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <button type="submit"
                            class="btn btn-sm btn-warning js-confirm"
                            data-confirm="Change status?">
                        <?php echo $company['status'] === 'active' ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg> Suspend' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><polygon points="6 3 20 12 6 21 6 3"/></svg> Activate'; ?>
                    </button>
                </form>
        </div>
        
        <div class="form-group" style="margin-top:20px; padding:15px; background:rgba(108, 92, 231, 0.05); border:1px dashed rgba(108, 92, 231, 0.3); border-radius:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h4 style="font-size:14px; font-weight:700; color:var(--text-primary); margin:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg> Elite Order Management</h4>
                    <p style="font-size:12px; color:var(--text-secondary); margin:4px 0 0 0;">Enable advanced commerce features, native chatbot checkout, and automated order recovery.</p>
                </div>
                <div class="toggle-switch">
                    <input type="checkbox" name="feat_native_checkout" id="featNative" <?php echo ($company['feat_native_checkout'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="toggle-slider" for="featNative"></label>
                </div>
            </div>
        </div>

        <div class="form-group" style="margin-top:20px;padding:15px;background:rgba(18,140,126,0.05);border:1px dashed rgba(18,140,126,0.35);border-radius:10px;">
            <h4 style="font-size:14px;font-weight:700;color:var(--text-primary);margin:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> Add-ons for this company</h4>
            <p style="font-size:12px;color:var(--text-secondary);margin:4px 0 12px;">"Follow plan" uses the company's subscription plan. "On" or "Off" overrides it for this company only.</p>
            <?php foreach ($addonFlags as $flag => [$lbl, $desc]): $cur = array_key_exists($flag, $overrides) ? ((int)$overrides[$flag] ? 'on' : 'off') : 'plan'; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:10px 0;">
                <div><b style="font-size:13.5px;color:var(--text-primary);"><?php echo htmlspecialchars($lbl); ?></b><div style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($desc); ?></div></div>
                <select name="addon[<?php echo $flag; ?>]" class="form-control" style="width:150px;flex:none;">
                    <option value="plan"<?php echo $cur === 'plan' ? ' selected' : ''; ?>>Follow plan</option>
                    <option value="on"<?php echo $cur === 'on' ? ' selected' : ''; ?>>On</option>
                    <option value="off"<?php echo $cur === 'off' ? ' selected' : ''; ?>>Off</option>
                </select>
            </div>
            <?php endforeach; ?>
            <p style="font-size:11.5px;color:var(--text-muted);margin:6px 0 0;">The company sees the change within about 2 minutes, or right away after logging in again.</p>
        </div>

        <button type="submit" name="submit" class="btn btn-primary" style="margin-top:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Changes</button>
    </form>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('click', function(e) {
    var el = e.target.closest('.js-confirm[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});
</script>

<?php include '../includes/admin_footer.php'; ?>
