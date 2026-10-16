<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';

$pageTitle = 'IndiaMART Integration';
$currentPage = 'integrations';

$success = '';
$error = '';

// Ensure columns exist (safe/idempotent)
foreach ([
    "ALTER TABLE companies ADD COLUMN im_auto_reply TINYINT(1) DEFAULT 0",
    "ALTER TABLE companies ADD COLUMN im_auto_reply_msg TEXT DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN im_last_sync_at DATETIME DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN im_last_sync_status VARCHAR(50) DEFAULT NULL",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// Fetch current config
$stSim1 = $conn->prepare("SELECT im_crm_key, im_mobile, im_auto_reply, im_auto_reply_msg, im_last_sync_at, im_last_sync_status FROM companies WHERE id=?");
$stSim1->bind_param("i", $companyId);
$stSim1->execute();
$company = $stSim1->get_result()->fetch_assoc();

if (isset($_POST['save_settings'])) {
    csrf_verify();
    $crmKey = sanitize($_POST['im_crm_key']);
    $mobile = sanitize($_POST['im_mobile']);
    $autoReply = isset($_POST['im_auto_reply']) ? 1 : 0;
    $autoMsg = trim((string)($_POST['im_auto_reply_msg'] ?? ''));

    $stmt = $conn->prepare("UPDATE companies SET im_crm_key=?, im_mobile=?, im_auto_reply=?, im_auto_reply_msg=? WHERE id=?");
    $stmt->bind_param("ssisi", $crmKey, $mobile, $autoReply, $autoMsg, $companyId);

    if ($stmt->execute()) {
        $success = "IndiaMART settings updated successfully!";
        $company['im_crm_key'] = $crmKey;
        $company['im_mobile'] = $mobile;
        $company['im_auto_reply'] = $autoReply;
        $company['im_auto_reply_msg'] = $autoMsg;
    }
    else {
        $error = "Failed to update settings: " . $conn->error;
    }
}

// Manual sync trigger
if (isset($_POST['run_sync'])) {
    csrf_verify();
    ob_start();
    $script = __DIR__ . '/../cron_indiamart_sync.php';
    if (file_exists($script)) {
        try { require $script; } catch (\Throwable $e) {}
    }
    ob_end_clean();
    header("Location: settings_indiamart.php?sync=1");
    exit();
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="integrations.php" class="btn btn-sm btn-edit" style="padding:8px; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center;">←</a>
        <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg> IndiaMART Sync</h2>
    </div>
</div>

<div class="form-card glass-card" style="max-width:600px;">
    <?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php
endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

    <p style="font-size:14px; color:var(--text-secondary); margin-bottom:24px;">
        Enter your IndiaMART CRM Key and registered mobile number to automatically sync leads to WAAMINI.
    </p>

    <form method="POST">
        <?php echo csrf_field(); ?>
        
        <div class="form-group" style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">IndiaMART CRM Key</label>
            <input type="text" name="im_crm_key" class="form-control" 
                   value="<?php echo htmlspecialchars($company['im_crm_key'] ?? ''); ?>" 
                   placeholder="e.g. mR50xxxxxxxxxxxxxxx" required
                   style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;">
            <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">
                Find this in your IndiaMART Seller Panel > Settings > CRM API.
            </small>
        </div>

        <div class="form-group" style="margin-bottom:24px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Registered Mobile Number</label>
            <input type="text" name="im_mobile" class="form-control" 
                   value="<?php echo htmlspecialchars($company['im_mobile'] ?? ''); ?>" 
                   placeholder="e.g. 91XXXXXXXXXX" required
                   style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;">
        </div>

        <div class="form-group" style="margin-bottom:18px;">
            <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;color:var(--text-primary);cursor:pointer;">
                <input type="checkbox" name="im_auto_reply" <?php echo !empty($company['im_auto_reply']) ? 'checked' : ''; ?> style="accent-color:var(--accent);width:16px;height:16px;">
                Auto-Reply new IndiaMART leads on WhatsApp
            </label>
            <small style="color:var(--text-muted); font-size:11px; margin-top:6px; display:block;">
                Requires WhatsApp configuration. This sends a single message immediately after importing a new lead.
            </small>
        </div>

        <div class="form-group" style="margin-bottom:24px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Auto-Reply Message</label>
            <textarea name="im_auto_reply_msg" class="form-control" rows="3"
                      style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;"
                      placeholder="Thanks for your enquiry! We received your request on IndiaMART…"><?php echo htmlspecialchars($company['im_auto_reply_msg'] ?? ''); ?></textarea>
        </div>

        <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%; justify-content:center; border-radius:12px; padding:12px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save & Connect IndiaMART
        </button>
    </form>

    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
        <div style="font-size:12px;color:var(--text-muted);">
            <div><strong>Last Sync:</strong> <?php echo !empty($company['im_last_sync_at']) ? htmlspecialchars($company['im_last_sync_at']) : '—'; ?></div>
            <div><strong>Status:</strong> <?php echo !empty($company['im_last_sync_status']) ? htmlspecialchars($company['im_last_sync_status']) : '—'; ?></div>
        </div>
        <form method="POST" style="margin:0;">
            <?php echo csrf_field(); ?>
            <button type="submit" name="run_sync" class="btn btn-sm btn-edit" style="border:1px solid var(--border);background:rgba(255,255,255,0.05);">
                Run Sync Now
            </button>
        </form>
    </div>

    <div style="margin-top:32px; padding:16px; background:rgba(0,184,148,0.05); border-radius:10px; border:1px dashed rgba(0,184,148,0.2);">
        <h4 style="font-size:13px; font-weight:700; color:#00b894; margin-bottom:8px;">How it works:</h4>
        <ul style="font-size:12px; color:var(--text-secondary); padding-left:20px; line-height:1.6;">
            <li>WAAMINI will poll IndiaMART (Pull API) every 10–15 minutes for new leads.</li>
            <li>New leads will be automatically added to your Contacts.</li>
            <li>If "Auto-Reply" is enabled, leads will receive an instant WhatsApp message.</li>
        </ul>
    </div>
</div>

<?php include '../includes/client_footer.php'; ?>
