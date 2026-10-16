<?php
include '../includes/client_auth_check.php';

// Replaced by School Fee Settings (client/school_fee_settings.php), which the School Fee Manager uses.
header('Location: ' . app_url('client/school_fee_settings.php'));
exit();
if (!has_feature('school_fee_reminder')) {
    http_response_code(403);
    include '../includes/client_header.php';
    echo '<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> School Fee Reminders are not enabled in your current plan.</div>';
    echo '<a href="subscription.php" class="btn btn-primary">View plans</a>';
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'School Fee Settings';
$currentPage = 'school_fees';

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $school  = sanitize($_POST['school_name'] ?? '');
    $days    = max(1, intval($_POST['reminder_days_before'] ?? 3));
    $remMsg  = sanitize($_POST['reminder_message'] ?? '');
    $ovdMsg  = sanitize($_POST['overdue_message'] ?? '');
    $prefix  = sanitize($_POST['receipt_prefix'] ?? 'RCP');
    $active  = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO fee_settings (company_id, school_name, reminder_days_before, reminder_message, overdue_message, receipt_prefix, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           school_name=VALUES(school_name),
           reminder_days_before=VALUES(reminder_days_before),
           reminder_message=VALUES(reminder_message),
           overdue_message=VALUES(overdue_message),
           receipt_prefix=VALUES(receipt_prefix),
           is_active=VALUES(is_active)"
    );
    // 7 columns: company_id(i), school_name(s), reminder_days(i), reminder_message(s), overdue_message(s), receipt_prefix(s), is_active(i)
    $stmt->bind_param("isissssi", $companyId, $school, $days, $remMsg, $ovdMsg, $prefix, $active);
    $stmt->execute();
    header("Location: settings_school_fees.php?msg=saved"); exit();
}
$stmt_s = $conn->prepare("SELECT * FROM fee_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> School Fee Settings</h2><a href="school_fees.php" class="btn btn-edit">← Back</a></div>
<div class="form-card" style="max-width:600px;">
<form method="POST">
<?php echo csrf_field(); ?>
<div class="form-group"><label>School / Institution Name</label><input type="text" name="school_name" class="form-control" value="<?php echo htmlspecialchars($s['school_name']??''); ?>"></div>
<div class="form-group"><label>Send Reminder (days before due)</label><input type="number" name="reminder_days_before" class="form-control" value="<?php echo $s['reminder_days_before']??3; ?>" min="1" max="30"></div>
<div class="form-group"><label>Receipt Prefix</label><input type="text" name="receipt_prefix" class="form-control" value="<?php echo htmlspecialchars($s['receipt_prefix']??'RCP'); ?>" placeholder="RCP"></div>
<div class="form-group">
    <label>Reminder Message Template</label>
    <textarea name="reminder_message" class="form-control" rows="4" placeholder="Dear {{parent_name}}, {{student_name}}'s {{fee_type}} of {{amount}} is due on {{due_date}}."><?php echo htmlspecialchars($s['reminder_message']??''); ?></textarea>
    <small style="color:var(--text-muted);">Variables: {{student_name}} {{parent_name}} {{amount}} {{due_date}} {{fee_type}}</small>
</div>
<div class="form-group">
    <label>Overdue Message Template</label>
    <textarea name="overdue_message" class="form-control" rows="4" placeholder="URGENT: {{student_name}}'s fee is overdue since {{due_date}}."><?php echo htmlspecialchars($s['overdue_message']??''); ?></textarea>
</div>
<div class="form-group" style="display:flex;gap:10px;align-items:center;">
    <input type="checkbox" name="is_active" id="fa" <?php echo ($s['is_active']??1)?'checked':''; ?>>
    <label for="fa" style="margin:0;">Enable Auto-Reminders via Cron</label>
</div>
<button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
</form>
</div>
<?php include '../includes/client_footer.php'; ?>
