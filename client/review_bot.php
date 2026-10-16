<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Google Review Bot';
$currentPage = 'review_bot';

// Schema migrations are managed via `migrations/` and should not run inline.

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $url      = sanitize($_POST['google_review_url']??'');
    $trigger  = in_array($_POST['trigger_event'],['order_complete','manual','after_chat']) ? $_POST['trigger_event'] : 'order_complete';
    $delay    = max(0,intval($_POST['delay_hours']??2));
    $msg      = trim($_POST['message']);
    $fmsg     = trim($_POST['followup_message']??'');
    $fdays    = max(1,intval($_POST['followup_days']??3));
    $stmt     = $conn->prepare("INSERT INTO review_settings (company_id,is_active,google_review_url,trigger_event,delay_hours,message,followup_message,followup_days) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),google_review_url=VALUES(google_review_url),trigger_event=VALUES(trigger_event),delay_hours=VALUES(delay_hours),message=VALUES(message),followup_message=VALUES(followup_message),followup_days=VALUES(followup_days)");
    $stmt->bind_param("isssissi",$companyId,$active,$url,$trigger,$delay,$msg,$fmsg,$fdays);
    $stmt->execute();
    header("Location: review_bot.php?msg=saved"); exit();
}

$stmt_s = $conn->prepare("SELECT * FROM review_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

$stmt_st = $conn->prepare("SELECT COUNT(*) as total, SUM(reviewed) as reviewed FROM review_requests WHERE company_id=?");
$stmt_st->bind_param("i",$companyId); $stmt_st->execute();
$stats = $stmt_st->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> Google Review Auto-Request Bot</h2></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
            <input type="checkbox" name="is_active" id="rv_active" <?php echo ($s['is_active']??1)?'checked':''; ?>>
            <label for="rv_active" style="margin:0;font-weight:600;font-size:13px;">Enable Review Bot</label>
        </div>
        <div class="form-group">
            <label>Google Review URL</label>
            <input type="url" name="google_review_url" class="form-control" value="<?php echo htmlspecialchars($s['google_review_url']??''); ?>" placeholder="https://g.page/r/YOUR_PLACE_ID/review">
            <small style="color:var(--text-muted);">Google Maps → Your Business → Get Reviews Link</small>
        </div>
        <div class="form-group">
            <label>Send Review Request</label>
            <select name="trigger_event" class="form-control">
                <option value="order_complete" <?php echo ($s['trigger_event']??'')==='order_complete'?'selected':''; ?>>After order completed</option>
                <option value="after_chat" <?php echo ($s['trigger_event']??'')==='after_chat'?'selected':''; ?>>After chat resolved</option>
                <option value="manual" <?php echo ($s['trigger_event']??'')==='manual'?'selected':''; ?>>Manual only</option>
            </select>
        </div>
        <div class="form-group">
            <label>Delay (hours after trigger)</label>
            <input type="number" name="delay_hours" class="form-control" value="<?php echo $s['delay_hours']??2; ?>" min="0" max="72">
        </div>
        <div class="form-group">
            <label>Review Request Message</label>
            <textarea name="message" class="form-control" rows="4"><?php echo htmlspecialchars($s['message']??"Hi {{first_name}}! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><circle cx='12' cy='12' r='10'/><path d='M8 14s1.5 2 4 2 4-2 4-2'/><line x1='9' x2='9.01' y1='9' y2='9'/><line x1='15' x2='15.01' y1='9' y2='9'/></svg>\n\nThank you for choosing *{{business_name}}*!\n\nWe'd love to hear your feedback. Could you spare 30 seconds to leave us a Google review?\n\n<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> {{review_url}}\n\nYour review means a lot to us! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'/></svg>"); ?></textarea>
        </div>
        <div class="form-group">
            <label>Follow-up Message (if no review)</label>
            <textarea name="followup_message" class="form-control" rows="3"><?php echo htmlspecialchars($s['followup_message']??"Hi {{first_name}}! Just a gentle reminder <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'/></svg>\n\nYour Google review helps other customers find us.\n\n<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> {{review_url}}\n\nTakes only 30 seconds!"); ?></textarea>
        </div>
        <div class="form-group">
            <label>Follow-up after (days)</label>
            <input type="number" name="followup_days" class="form-control" value="<?php echo $s['followup_days']??3; ?>" min="1" max="30">
        </div>
        <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </form>
</div>

<div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#6c63ff;"><?php echo $stats['total']??0; ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Requests Sent</div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#f39c12;"><svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'/></svg> <?php echo $stats['reviewed']??0; ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Reviews Got</div>
        </div>
    </div>

    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> How to get Google Review URL</h3>
        <ol style="font-size:13px;color:var(--text-secondary);line-height:2;">
            <li>Google Maps-ல உங்க business search பண்ணுங்க</li>
            <li>"Write a review" button-ல right click பண்ணுங்க</li>
            <li>"Copy link address" select பண்ணுங்க</li>
            <li>அந்த URL-ஐ above field-ல paste பண்ணுங்க</li>
        </ol>
        <div style="background:rgba(0,184,148,0.1);border-radius:8px;padding:10px;margin-top:10px;font-size:12px;color:var(--accent);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> Review URL format: https://g.page/r/XXXXX/review
        </div>
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>

