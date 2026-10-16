<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Missed Call Auto-Reply';
$currentPage = 'settings_missed_call';

// Migration handled schema change

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $active = isset($_POST['is_active'])?1:0;
    $msg = trim($_POST['message']);
    $number = sanitize($_POST['virtual_number']??'');
    $provider = sanitize($_POST['provider']??'exotel');
    $key = sanitize($_POST['api_key']??'');
    $secret = sanitize($_POST['api_secret']??'');
    $stmt = $conn->prepare("INSERT INTO missed_call_settings (company_id,is_active,message,virtual_number,provider,api_key,api_secret) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),message=VALUES(message),virtual_number=VALUES(virtual_number),provider=VALUES(provider),api_key=VALUES(api_key),api_secret=VALUES(api_secret)");
    $stmt->bind_param("iisssss",$companyId,$active,$msg,$number,$provider,$key,$secret);
    $stmt->execute();
    header("Location: settings_missed_call.php?msg=saved"); exit();
}

$stmt_s = $conn->prepare("SELECT * FROM missed_call_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

// Logs
$stmt_l = $conn->prepare("SELECT * FROM missed_call_logs WHERE company_id=? ORDER BY id DESC LIMIT 20");
$stmt_l->bind_param("i",$companyId); $stmt_l->execute();
$logs = $stmt_l->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Missed Call → WhatsApp Auto-Reply</h2></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group" style="display:flex;gap:10px;align-items:center;">
        <input type="checkbox" name="is_active" id="mc_active" <?php echo ($s['is_active']??1)?'checked':''; ?>>
        <label for="mc_active" style="margin:0;font-weight:600;">Enable Missed Call Reply</label>
    </div>
    <div class="form-group">
        <label>Auto-Reply Message</label>
        <textarea name="message" class="form-control" rows="5"><?php echo htmlspecialchars($s['message']??'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg> Hi! We noticed you called *{{business_name}}*.' . "\n\nSorry we missed your call! " . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>' . "\n\nHow can we help you? Please reply here and we\'ll get back to you immediately! " . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>'); ?></textarea>
        <small style="color:var(--text-muted);">Variables: {{business_name}} {{first_name}}</small>
    </div>
    <div class="form-group">
        <label>Provider</label>
        <select name="provider" class="form-control">
            <option value="exotel" <?php echo ($s['provider']??'exotel')==='exotel'?'selected':''; ?>>Exotel</option>
            <option value="knowlarity" <?php echo ($s['provider']??'')==='knowlarity'?'selected':''; ?>>Knowlarity</option>
            <option value="servetel" <?php echo ($s['provider']??'')==='servetel'?'selected':''; ?>>Servetel</option>
            <option value="webhook" <?php echo ($s['provider']??'')==='webhook'?'selected':''; ?>>Custom Webhook</option>
        </select>
    </div>
    <div class="form-group"><label>Virtual Number</label><input type="text" name="virtual_number" class="form-control" value="<?php echo htmlspecialchars($s['virtual_number']??''); ?>" placeholder="e.g. 08039535500"></div>
    <div class="form-group"><label>API Key</label><input type="text" name="api_key" class="form-control" value="<?php echo htmlspecialchars($s['api_key']??''); ?>"></div>
    <div class="form-group"><label>API Secret</label><input type="password" name="api_secret" class="form-control" value="<?php echo htmlspecialchars($s['api_secret']??''); ?>"></div>
    <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </form>
</div>

<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Webhook URL</h3>
        <p style="font-size:12px;color:var(--text-secondary);">Configure this URL in your telecom provider:</p>
        <div style="background:var(--bg-input);border-radius:8px;padding:10px;font-size:11px;font-family:monospace;word-break:break-all;">
            https://yourdomain.com/api/missed_call_webhook.php?company_id=<?php echo $companyId; ?>
        </div>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Recent Missed Calls</h3>
        <?php while ($l = $logs->fetch_assoc()): ?>
        <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid var(--border);">
            <span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?php echo htmlspecialchars($l['caller_number']); ?></span>
            <span><?php echo $l['wa_sent']?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> WA Sent':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Pending'; ?></span>
            <span style="color:var(--text-muted);"><?php echo $l['called_at']?date('d M h:i',strtotime($l['called_at'])):'—'; ?></span>
        </div>
        <?php endwhile; ?>
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
