<?php
include '../includes/client_auth_check.php';

$pageTitle = 'Facebook Lead Ads';
$currentPage = 'integrations';

$success = '';
$error = '';

require_once __DIR__ . '/../services/EncryptionService.php';

// Ensure required columns exist (safe/idempotent)
foreach ([
    "ALTER TABLE companies ADD COLUMN fb_page_id VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_page_access_token TEXT DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_public_webhook_base_url VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN fb_lead_auto_reply_enabled TINYINT(1) DEFAULT 0",
    "ALTER TABLE companies ADD COLUMN fb_lead_auto_reply_message TEXT DEFAULT NULL",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// Fetch current config
$stSfl1 = $conn->prepare("SELECT fb_verify_token, fb_page_id, fb_page_access_token, fb_public_webhook_base_url, fb_lead_auto_reply_enabled, fb_lead_auto_reply_message FROM companies WHERE id=?");
$stSfl1->bind_param("i", $companyId);
$stSfl1->execute();
$company = $stSfl1->get_result()->fetch_assoc();

if (isset($_POST['save_settings'])) {
    csrf_verify();
    $verifyToken = sanitize($_POST['fb_verify_token']);
    $pageId = sanitize($_POST['fb_page_id']);
    $tokenPlain = trim((string)($_POST['fb_page_access_token'] ?? ''));
    $publicBaseRaw = trim((string)($_POST['fb_public_webhook_base_url'] ?? ''));
    $autoEnabled = isset($_POST['fb_lead_auto_reply_enabled']) ? 1 : 0;
    $autoMsg = trim((string)($_POST['fb_lead_auto_reply_message'] ?? ''));
    if ($autoMsg !== '') {
        // store raw, escape on output
        if (function_exists('mb_substr')) $autoMsg = mb_substr($autoMsg, 0, 3000, 'UTF-8');
        else $autoMsg = substr($autoMsg, 0, 3000);
    }

    // Normalize public base URL (ngrok/custom domain). Keep empty to auto-detect.
    $publicBase = '';
    if ($publicBaseRaw !== '') {
        $publicBaseRaw = preg_replace('/\s+/', '', $publicBaseRaw);
        $publicBaseRaw = rtrim($publicBaseRaw, '/');
        // Accept only http/https URLs without path (path allowed but will be stripped)
        $u = @parse_url($publicBaseRaw);
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $host = (string)($u['host'] ?? '');
        $port = isset($u['port']) ? (int)$u['port'] : null;
        if (in_array($scheme, ['https', 'http'], true) && $host !== '') {
            $publicBase = $scheme . '://' . $host . ($port ? (':' . $port) : '');
        }
    }

    // Empty token field = keep existing ciphertext
    $tokenStore = $company['fb_page_access_token'] ?? '';
    if ($tokenPlain !== '') {
        $tokenStore = encrypt_secret($tokenPlain);
    }

    $stmt = $conn->prepare("UPDATE companies SET fb_verify_token=?, fb_page_id=?, fb_page_access_token=?, fb_public_webhook_base_url=?, fb_lead_auto_reply_enabled=?, fb_lead_auto_reply_message=? WHERE id=?");
    // fb_verify_token(s), fb_page_id(s), fb_page_access_token(s), fb_public_webhook_base_url(s), enabled(i), message(s), id(i)
    $stmt->bind_param("ssssisi", $verifyToken, $pageId, $tokenStore, $publicBase, $autoEnabled, $autoMsg, $companyId);

    if ($stmt->execute()) {
        $success = "Facebook settings updated successfully!";
        $company['fb_verify_token'] = $verifyToken;
        $company['fb_page_id'] = $pageId;
        $company['fb_page_access_token'] = $tokenStore;
        $company['fb_public_webhook_base_url'] = $publicBase;
        $company['fb_lead_auto_reply_enabled'] = $autoEnabled;
        $company['fb_lead_auto_reply_message'] = $autoMsg;
    }
    else {
        $error = "Failed to update settings: " . $conn->error;
    }
}

// Generate a default verify token if empty
if (empty($company['fb_verify_token'])) {
    $company['fb_verify_token'] = 'waamini_' . substr(md5($companyId . time()), 0, 12);
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']);
$base = trim((string)($company['fb_public_webhook_base_url'] ?? ''));
if ($base === '') {
    $base = $protocol . '://' . $host;
}
    $webhookUrl = rtrim($base, '/') . "/api/fb_leads_webhook.php?cid=$companyId";

$hasToken = !empty($company['fb_page_access_token']);
$autoDefault = "Hi {{name}}, thanks for your interest. Our team will contact you shortly.\n\nPreferred ad language: Tamil / English / Both?";
$company['fb_lead_auto_reply_message'] = $company['fb_lead_auto_reply_message'] ?? $autoDefault;

include '../includes/client_header.php';
?>

<div class="page-header">
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="integrations.php" class="btn btn-sm btn-edit" style="padding:8px; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center;">←</a>
        <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> Facebook Lead Ads</h2>
    </div>
</div>

<div class="form-card glass-card" style="max-width:700px;">
    <?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php
endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

    <p style="font-size:14px; color:var(--text-secondary); margin-bottom:24px;">
        Connect your Facebook Lead Forms to WAAMINI via Webhook to trigger instant WhatsApp replies.
    </p>

    <div style="background:rgba(0,0,0,0.2); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:32px;">
        <h4 style="font-size:13px; font-weight:700; color:var(--text-primary); margin-bottom:12px;">Step 1: Configure Meta Webhook</h4>
        <p style="font-size:12px; color:var(--text-secondary); margin-bottom:16px;">Copy these values into your Facebook Developer App settings:</p>
        
        <div style="margin-bottom:16px;">
            <label style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700; display:block; margin-bottom:4px;">Callback URL</label>
            <div style="display:flex; gap:10px;">
                <code style="flex:1; background:#111; padding:10px; border-radius:6px; color:#00b894; font-size:12px; word-break:break-all;"><?php echo $webhookUrl; ?></code>
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700; display:block; margin-bottom:4px;">Verify Token</label>
            <div style="display:flex; gap:10px;">
                <code style="flex:1; background:#111; padding:10px; border-radius:6px; color:#00b894; font-size:12px;"><?php echo htmlspecialchars($company['fb_verify_token']); ?></code>
            </div>
        </div>
    </div>

    <form method="POST" style="border-top:1px solid var(--border); padding-top:24px;">
        <?php echo csrf_field(); ?>
        
        <h4 style="font-size:13px; font-weight:700; color:var(--text-primary); margin-bottom:12px;">Step 2: Save Integration Data</h4>

        <div class="form-group" style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Facebook Page ID</label>
            <input type="text" name="fb_page_id" class="form-control" 
                   value="<?php echo htmlspecialchars($company['fb_page_id'] ?? ''); ?>" 
                   placeholder="e.g. 104592031024xxx" required
                   style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;">
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Public Webhook Base URL (ngrok / domain)</label>
            <input type="text" name="fb_public_webhook_base_url" class="form-control"
                   value="<?php echo htmlspecialchars((string)($company['fb_public_webhook_base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="https://yourdomain.ngrok-free.app"
                   style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;">
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;line-height:1.6;">
                Paste your public HTTPS domain here to avoid <code>localhost</code>. This updates the Callback URL shown in Step 1.
            </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Facebook Page Access Token</label>
            <input type="password" name="fb_page_access_token" class="form-control"
                   value=""
                   placeholder="<?php echo $hasToken ? 'Saved (leave blank to keep existing)' : 'Paste Page Access Token'; ?>"
                   style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;">
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;line-height:1.6;">
                Required to fetch lead details from Meta Graph API. Keep it secret.
            </div>
        </div>

        <div class="form-group" style="margin-bottom:14px;">
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
                <input type="checkbox" name="fb_lead_auto_reply_enabled" value="1" <?php echo !empty($company['fb_lead_auto_reply_enabled']) ? 'checked' : ''; ?> style="margin-top:2px;accent-color:var(--primary);">
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--text-primary);">Auto WhatsApp Reply on New Lead</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Queues an instant WhatsApp message when a new lead arrives.</div>
                </div>
            </label>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Auto Reply Message</label>
            <textarea name="fb_lead_auto_reply_message" class="form-control" rows="4"
                style="background:rgba(0,0,0,0.2); border:1px solid var(--border); padding:12px;"><?php echo htmlspecialchars((string)($company['fb_lead_auto_reply_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">Variables: <code>{{name}}</code>, <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{phone}}</code>, <code>{{email}}</code></div>
        </div>

        <input type="hidden" name="fb_verify_token" value="<?php echo htmlspecialchars($company['fb_verify_token']); ?>">

        <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%; justify-content:center; border-radius:12px; padding:12px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save & Enable FB Integration
        </button>
    </form>
</div>

<?php include '../includes/client_footer.php'; ?>
