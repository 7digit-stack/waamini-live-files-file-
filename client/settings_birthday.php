<?php
include '../includes/client_auth_check.php';

// Replaced by Personal occasions in the Festival Calendar (birthdays, anniversaries and more).
header('Location: ' . app_url('client/festival_campaigns.php?v=occasions'));
exit();
$pageTitle = 'Birthday Messages';
$currentPage = 'settings_birthday';

// Ensure birthday_settings table exists
// Migration handled schema change

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $msg    = sanitize($_POST['birthday_message']);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $stmt   = $conn->prepare("INSERT INTO birthday_settings (company_id, birthday_message, is_active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE birthday_message=VALUES(birthday_message), is_active=VALUES(is_active)");
    $stmt->bind_param("isi", $companyId, $msg, $active);
    $stmt->execute();
    header("Location: settings_birthday.php?msg=saved");
    exit();
}

$stmt_s = $conn->prepare("SELECT * FROM birthday_settings WHERE company_id=?");
$stmt_s->bind_param("i", $companyId);
$stmt_s->execute();
$settings = $stmt_s->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Birthday message settings saved!</div>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/></svg> Birthday Auto-Messages</h2>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">
    <div class="form-card" style="max-width:none;">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label style="font-size:13px;font-weight:600;">Birthday Message</label>
                <small style="color:var(--text-muted);display:block;margin-bottom:8px;">Use <code>{{name}}</code> or <code>{{first_name}}</code> for personalization</small>
                <textarea name="birthday_message" class="form-control" rows="5" required><?php echo htmlspecialchars($settings['birthday_message'] ?? 'Happy Birthday {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/></svg> Wishing you a wonderful day!'); ?></textarea>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                <input type="checkbox" name="is_active" id="is_active" <?php echo ($settings['is_active'] ?? 1) ? 'checked' : ''; ?>>
                <label for="is_active" style="margin:0;font-size:13px;">Enable birthday messages</label>
            </div>
            <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Settings</button>
        </form>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> How it works</h3>
        <ul style="font-size:13px;color:var(--text-secondary);line-height:2;list-style:none;padding:0;">
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Runs daily at 9:00 AM automatically</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Sends to contacts whose DOB matches today</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> One message per contact per year</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Add DOB when editing a contact</li>
        </ul>
        <div style="margin-top:16px;padding:12px;background:rgba(0,184,148,0.06);border-radius:8px;border:1px solid rgba(0,184,148,0.15);">
            <p style="font-size:12px;color:var(--text-muted);margin:0;"><strong>Preview:</strong><br><?php echo htmlspecialchars(str_replace(['{{name}}','{{first_name}}'], ['John Doe','John'], $settings['birthday_message'] ?? 'Happy Birthday {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/></svg>')); ?></p>
        </div>
    </div>
</div>

<?php include '../includes/client_footer.php'; ?>
