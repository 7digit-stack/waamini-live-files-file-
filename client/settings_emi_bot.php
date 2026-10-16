<?php
include '../includes/client_auth_check.php';
$pageTitle = 'EMI Calculator Bot';
$currentPage = 'settings_emi_bot';

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $active=isset($_POST['is_active'])?1:0;
    $trigger=strtolower(sanitize($_POST['trigger_keyword']??'emi'));
    $rate=floatval($_POST['interest_rate']??12);
    $tenures=sanitize($_POST['available_tenures']??'3,6,12,24,36');
    $msg=trim($_POST['message_template']??'');
    $stmt=$conn->prepare("INSERT INTO emi_bot_settings (company_id,is_active,trigger_keyword,interest_rate,available_tenures,message_template) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),trigger_keyword=VALUES(trigger_keyword),interest_rate=VALUES(interest_rate),available_tenures=VALUES(available_tenures),message_template=VALUES(message_template)");
    $stmt->bind_param("iisdss",$companyId,$active,$trigger,$rate,$tenures,$msg);
    $stmt->execute();
    header("Location: settings_emi_bot.php?msg=saved");exit();
}
$stmt_s=$conn->prepare("SELECT * FROM emi_bot_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId);$stmt_s->execute();
$s=$stmt_s->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<?php if(isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> EMI Calculator Bot</h2></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group" style="display:flex;gap:10px;align-items:center;">
        <input type="checkbox" name="is_active" id="emi_a" <?php echo ($s['is_active']??1)?'checked':''; ?>>
        <label for="emi_a" style="margin:0;font-weight:600;">Enable EMI Bot</label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Trigger Keyword</label><input type="text" name="trigger_keyword" class="form-control" value="<?php echo htmlspecialchars($s['trigger_keyword']??'emi'); ?>"></div>
        <div class="form-group"><label>Interest Rate (%/year)</label><input type="number" name="interest_rate" class="form-control" value="<?php echo $s['interest_rate']??12; ?>" step="0.01"></div>
    </div>
    <div class="form-group"><label>Available Tenures (months, comma separated)</label><input type="text" name="available_tenures" class="form-control" value="<?php echo htmlspecialchars($s['available_tenures']??'3,6,12,24,36'); ?>"></div>
    <div class="form-group"><label>Custom Message (optional)</label><textarea name="message_template" class="form-control" rows="3" placeholder="Leave blank for default format"><?php echo htmlspecialchars($s['message_template']??''); ?></textarea></div>
    <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </form>
</div>
<div class="form-card" style="max-width:none;">
    <h3 style="font-size:13px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> How it works</h3>
    <div style="font-size:12px;color:var(--text-secondary);line-height:2.2;">
        <div>1 Customer sends: <code>emi 15000</code></div>
        <div>2 Bot replies with EMI breakdown for 3,6,12,24,36 months</div>
        <div>3 Customer can ask: <code>emi 50000 12</code> for specific tenure</div>
    </div>
    <div style="margin-top:16px;background:var(--bg-input);border-radius:8px;padding:12px;font-size:12px;">
        <strong>Sample Response:</strong><br><br>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> EMI for ₹15,000 @ 12% p.a.<br><br>
        3 months: ₹5,110/month<br>
        6 months: ₹2,579/month<br>
        12 months: ₹1,334/month<br>
        24 months: ₹705/month<br><br>
        Reply with tenure to confirm!
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
