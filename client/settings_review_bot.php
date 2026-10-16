<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Google Review Bot';
$currentPage = 'settings_review_bot';

// Migration handled schema change

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $active = isset($_POST['is_active'])?1:0;
    $url = sanitize($_POST['google_review_url']);
    $msg = trim($_POST['message']);
    $days = max(0,intval($_POST['send_after_days']??1));
    $minOrders = max(1,intval($_POST['min_orders_before']??1));
    $stmt = $conn->prepare("INSERT INTO review_settings (company_id,is_active,google_review_url,message,send_after_days,min_orders_before) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),google_review_url=VALUES(google_review_url),message=VALUES(message),send_after_days=VALUES(send_after_days),min_orders_before=VALUES(min_orders_before)");
    $stmt->bind_param("iissii",$companyId,$active,$url,$msg,$days,$minOrders);
    $stmt->execute();
    header("Location: settings_review_bot.php?msg=saved"); exit();
}
$stmt_s = $conn->prepare("SELECT * FROM review_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

$stmt_st = $conn->prepare("SELECT COUNT(*) as c FROM review_requests WHERE company_id=?");
$stmt_st->bind_param("i",$companyId); $stmt_st->execute();
$sent = $stmt_st->get_result()->fetch_assoc()['c'];

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> Google Review Auto-Request Bot</h2></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group" style="display:flex;gap:10px;align-items:center;">
        <input type="checkbox" name="is_active" id="rv_active" <?php echo ($s['is_active']??1)?'checked':''; ?>>
        <label for="rv_active" style="margin:0;font-weight:600;">Enable Auto Review Request</label>
    </div>
    <div class="form-group">
        <label>Your Google Review URL</label>
        <input type="text" name="google_review_url" class="form-control" value="<?php echo htmlspecialchars($s['google_review_url']??''); ?>" placeholder="https://g.page/r/YOUR_ID/review">
        <small style="color:var(--text-muted);">Get from Google Business Profile → Get reviews</small>
    </div>
    <div class="form-group">
        <label>Message</label>
        <textarea name="message" class="form-control" rows="6"><?php echo htmlspecialchars($s['message']??"Hi {{first_name}}! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><circle cx='12' cy='12' r='10'/><path d='M8 14s1.5 2 4 2 4-2 4-2'/><line x1='9' x2='9.01' y1='9' y2='9'/><line x1='15' x2='15.01' y1='9' y2='9'/></svg>\n\nThank you for choosing *{{business_name}}*! We hope you had a great experience.\n\n<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> Could you spare 30 seconds to leave us a Google review? It helps us a lot!\n\n<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M5 12h14'/><path d='M12 5v14'/></svg> {{review_url}}\n\nThank you so much! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'/></svg>"); ?></textarea>
        <small style="color:var(--text-muted);">{{first_name}} {{business_name}} {{review_url}}</small>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Send After (days after order)</label><input type="number" name="send_after_days" class="form-control" value="<?php echo $s['send_after_days']??1; ?>" min="0"></div>
        <div class="form-group"><label>Min Orders Before Asking</label><input type="number" name="min_orders_before" class="form-control" value="<?php echo $s['min_orders_before']??1; ?>" min="1"></div>
    </div>
    <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </form>
</div>
<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;text-align:center;">
        <div style="font-size:36px;"><svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg></div>
        <div style="font-size:28px;font-weight:800;color:#f39c12;"><?php echo $sent; ?></div>
        <div style="font-size:12px;color:var(--text-muted);">Review Requests Sent</div>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> How it works</h3>
        <div style="font-size:12px;color:var(--text-secondary);line-height:2.2;">
            <div>1 Customer places order</div>
            <div>2 After X days → auto WhatsApp</div>
            <div>3 Customer clicks link → Google review page</div>
            <div>4 More 5-star reviews = better ranking!</div>
        </div>
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
