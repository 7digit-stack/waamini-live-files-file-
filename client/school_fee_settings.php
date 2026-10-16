<?php
include '../includes/client_auth_check.php';
if (!has_feature('school_fee_reminder')) {
    http_response_code(403);
    include '../includes/client_header.php';
    echo '<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> School Fee Reminders are not enabled in your current plan.</div>';
    echo '<a href="subscription.php" class="btn btn-primary">View plans</a>';
    include '../includes/client_footer.php';
    exit();
}
require_once __DIR__ . '/../includes/school_fee_engine.php';
$pageTitle = 'School Fee Settings';
$currentPage = 'school_fees';

sf_ensure_schema($conn);
$stmt_ss = $conn->prepare("SELECT * FROM school_fee_settings WHERE company_id=?");
$stmt_ss->bind_param("i", $companyId); $stmt_ss->execute();
$s = $stmt_ss->get_result()->fetch_assoc() ?: [];
$templates = festival_templates($conn, $companyId);

if (isset($_POST['save'])) {
    csrf_verify();
    $sname = sanitize($_POST['school_name'] ?? '');
    $days  = max(1, min(30, intval($_POST['reminder_days_before'] ?? 3)));
    $rmsg  = trim((string)($_POST['reminder_message'] ?? ''));
    $omsg  = trim((string)($_POST['overdue_message'] ?? ''));
    $rcmsg = trim((string)($_POST['receipt_message'] ?? ''));
    $auto  = isset($_POST['auto_remind']) ? 1 : 0;
    $rt = trim((string)($_POST['reminder_template'] ?? ''));
    $ct = trim((string)($_POST['receipt_template'] ?? ''));
    $rt = $rt !== '' && isset($templates[$rt]) ? $rt : null;
    $ct = $ct !== '' && isset($templates[$ct]) ? $ct : null;
    $stmt_sv = $conn->prepare("INSERT INTO school_fee_settings (company_id, school_name, reminder_days_before, reminder_message, overdue_message, receipt_message, auto_remind, reminder_template, receipt_template)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE school_name=VALUES(school_name), reminder_days_before=VALUES(reminder_days_before),
                                 reminder_message=VALUES(reminder_message), overdue_message=VALUES(overdue_message), receipt_message=VALUES(receipt_message),
                                 auto_remind=VALUES(auto_remind), reminder_template=VALUES(reminder_template), receipt_template=VALUES(receipt_template)");
    $stmt_sv->bind_param("isisssiss", $companyId, $sname, $days, $rmsg, $omsg, $rcmsg, $auto, $rt, $ct);
    $stmt_sv->execute();
    header("Location: school_fee_settings.php?msg=saved"); exit();
}
// Messages saved by the old form as "0" (wrong field type) count as empty
foreach (['reminder_message', 'overdue_message', 'receipt_message'] as $k) {
    if (isset($s[$k]) && trim((string)$s[$k]) === '0') $s[$k] = '';
}
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success">Settings saved.</div><?php endif; ?>
<div class="page-header"><h2>School Fee Settings</h2><a href="school_fees.php" class="btn btn-edit">&larr; Back</a></div>

<div class="form-card sfs" style="max-width:720px;">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group"><label>School / institution name</label><input type="text" name="school_name" class="form-control" value="<?php echo $esc($s['school_name'] ?? ''); ?>" placeholder="ABC Public School"></div>

        <div class="sfs-box">
            <label class="sfs-check"><input type="checkbox" name="auto_remind" <?php echo ($s['auto_remind'] ?? 1) ? 'checked' : ''; ?>>
                <span><b>Send reminders automatically</b><small>Between 9 am and 9 pm: first reminder before the due date, again on the due date, then once a week while the fee is overdue.</small></span></label>
            <div class="form-group" style="margin:12px 0 0"><label>First reminder (days before the due date)</label><input type="number" name="reminder_days_before" class="form-control" value="<?php echo (int)($s['reminder_days_before'] ?? 3); ?>" min="1" max="30"></div>
        </div>

        <div class="sfs-box">
            <b class="sfs-h">Reach every parent with an approved template</b>
            <p class="sfs-p">WhatsApp only allows free text to parents who messaged you in the last 24 hours. For everyone else, WAAMINI sends the approved template you pick here.</p>
            <div class="form-group"><label>Reminder template</label>
                <select name="reminder_template" class="form-control">
                    <option value="">None (only parents who messaged in the last 24 hours)</option>
                    <?php foreach ($templates as $t): ?><option value="<?php echo $esc($t['name']); ?>"<?php echo ($s['reminder_template'] ?? '') === $t['name'] ? ' selected' : ''; ?>><?php echo $esc($t['name']); ?></option><?php endforeach; ?>
                </select>
                <small class="sfs-hint">Values filled in: {{1}} student name, {{2}} amount, {{3}} due date, {{4}} fee type, {{5}} school name.<br>Example template: <code>Dear parent, the {{4}} of {{2}} for {{1}} is due on {{3}}. – {{5}}</code></small>
            </div>
            <div class="form-group" style="margin-bottom:0"><label>Receipt template</label>
                <select name="receipt_template" class="form-control">
                    <option value="">None (only parents who messaged in the last 24 hours)</option>
                    <?php foreach ($templates as $t): ?><option value="<?php echo $esc($t['name']); ?>"<?php echo ($s['receipt_template'] ?? '') === $t['name'] ? ' selected' : ''; ?>><?php echo $esc($t['name']); ?></option><?php endforeach; ?>
                </select>
                <small class="sfs-hint">Values filled in: {{1}} student name, {{2}} amount paid, {{3}} receipt number, {{4}} fee type, {{5}} school name.</small>
            </div>
            <?php if (!$templates): ?><p class="sfs-p" style="margin:10px 0 0">No approved templates yet. Create one in <a href="templates.php">Templates</a>; it appears here once Meta approves it.</p><?php endif; ?>
        </div>

        <div class="form-group"><label>Reminder message (free text)</label>
            <textarea name="reminder_message" class="form-control" rows="4" placeholder="Leave empty to use the standard reminder"><?php echo $esc($s['reminder_message'] ?? ''); ?></textarea>
            <small class="sfs-hint">Used for parents inside the 24-hour window. Variables: {{student_name}} {{class}} {{fee_type}} {{amount}} {{due_date}} {{school_name}}</small></div>
        <div class="form-group"><label>Overdue message (free text)</label>
            <textarea name="overdue_message" class="form-control" rows="4" placeholder="Leave empty to use the standard reminder"><?php echo $esc($s['overdue_message'] ?? ''); ?></textarea></div>
        <div class="form-group"><label>Receipt message (free text)</label>
            <textarea name="receipt_message" class="form-control" rows="4" placeholder="Leave empty to use the standard receipt"><?php echo $esc($s['receipt_message'] ?? ''); ?></textarea>
            <small class="sfs-hint">Variables: {{student_name}} {{class}} {{fee_type}} {{amount}} {{receipt}} {{school_name}}</small></div>
        <button type="submit" name="save" class="btn btn-primary">Save settings</button>
    </form>
</div>
<style>
.sfs-box{margin:0 0 18px;padding:14px 16px;border-radius:14px;background:#F3FBF7}
.sfs-check{display:flex;gap:10px;align-items:flex-start;cursor:pointer}
.sfs-check input{margin-top:3px;accent-color:#128C7E}
.sfs-check b{display:block;color:var(--text-primary)}
.sfs-check small{display:block;margin-top:2px;font-size:12.5px;color:var(--text-muted);line-height:1.45}
.sfs-h{display:block;margin-bottom:4px;color:var(--text-primary)}
.sfs-p{margin:0 0 12px;font-size:13px;color:var(--text-secondary);line-height:1.5}
.sfs-hint{display:block;margin-top:6px;font-size:12px;color:var(--text-muted);line-height:1.5}
.sfs-hint code{padding:1px 6px;border-radius:5px;background:#fff;color:#0B6B5E}
</style>
<?php include '../includes/client_footer.php'; ?>
