<?php
include '../includes/client_auth_check.php';

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

if (!has_feature('medicine_reminder')) {
    http_response_code(403);
    $pageTitle = 'Medicine Reminders';
    $currentPage = 'medicine_reminders';
    include '../includes/client_header.php';
    ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
    <div class="su"><div class="su-card" style="align-items:center;text-align:center;padding:48px 24px;">
        <div class="su-ico" style="width:64px;height:64px;"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 28); ?></div>
        <h2 style="justify-content:center;">Medicine reminders are not in your plan</h2>
        <p class="sub" style="margin:0">Upgrade to remind patients to take their medicines on time.</p>
        <a href="subscription.php" class="su-btn">View plans</a>
    </div></div>
    <?php
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'Medicine Reminders';
$currentPage = 'medicine_reminders';

// Ensure required table exists (older installs may not have run latest migrations).
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS medicine_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            patient_name VARCHAR(150) DEFAULT NULL,
            medicine_name VARCHAR(200) DEFAULT NULL,
            dosage VARCHAR(100) DEFAULT NULL,
            frequency VARCHAR(30) DEFAULT NULL,
            medicines JSON DEFAULT NULL,
            reminder_times JSON DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            refill_reminder_days INT DEFAULT 3,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_sent_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) {
    // best-effort
}

if (isset($_POST['add_reminder'])) {
    csrf_verify();
    $contactId = intval($_POST['contact_id'] ?? 0);
    $patName = sanitize($_POST['patient_name'] ?? '');
    $meds = array_filter(array_map('trim', explode("\n", (string)($_POST['medicines'] ?? ''))));
    $times = array_filter(array_map('trim', explode(',', (string)($_POST['reminder_times'] ?? '08:00'))));
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    if ($contactId && $patName && $meds) {
        $stmt = $conn->prepare("INSERT INTO medicine_reminders (company_id,contact_id,patient_name,medicines,reminder_times,start_date,end_date) VALUES (?,?,?,?,?,?,?)");
        if ($stmt) {
            $medsJson = json_encode(array_values($meds));
            $timesJson = json_encode(array_values($times));
            $stmt->bind_param("iisssss", $companyId, $contactId, $patName, $medsJson, $timesJson, $startDate, $endDate);
            $stmt->execute();
        }
    }
    header("Location: medicine_reminders.php?msg=added"); exit();
}

if (isset($_POST['toggle_reminder'])) {
    csrf_verify();
    $rid = intval($_POST['reminder_id'] ?? 0);
    $stmt_tgl = $conn->prepare("UPDATE medicine_reminders SET is_active=NOT is_active WHERE id=? AND company_id=?");
    if ($stmt_tgl) { $stmt_tgl->bind_param("ii", $rid, $companyId); $stmt_tgl->execute(); }
    header("Location: medicine_reminders.php?msg=updated"); exit();
}

if (isset($_POST['delete_reminder'])) {
    csrf_verify();
    $rid = intval($_POST['reminder_id'] ?? 0);
    $stmt_del = $conn->prepare("DELETE FROM medicine_reminders WHERE id=? AND company_id=?");
    if ($stmt_del) { $stmt_del->bind_param("ii", $rid, $companyId); $stmt_del->execute(); }
    header("Location: medicine_reminders.php?msg=deleted"); exit();
}

$reminders = [];
$stmt_r = $conn->prepare("SELECT mr.*, c.name as contact_name, c.phone FROM medicine_reminders mr JOIN contacts c ON mr.contact_id=c.id WHERE mr.company_id=? ORDER BY mr.id DESC");
if ($stmt_r) { $stmt_r->bind_param("i", $companyId); $stmt_r->execute(); $reminders = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC); }

$contacts = [];
$stmt_ct = $conn->prepare("SELECT id,name,phone FROM contacts WHERE company_id=? ORDER BY name LIMIT 500");
if ($stmt_ct) { $stmt_ct->bind_param("i", $companyId); $stmt_ct->execute(); $contacts = $stmt_ct->get_result()->fetch_all(MYSQLI_ASSOC); }

$today = date('Y-m-d');
$cActive = $cPaused = $cExpired = 0;
foreach ($reminders as &$r) {
    $r['_meds'] = json_decode((string)$r['medicines'], true) ?: [];
    $r['_times'] = json_decode((string)$r['reminder_times'], true) ?: [];
    $r['_expired'] = !empty($r['end_date']) && $r['end_date'] < $today;
    if ($r['_expired']) $cExpired++; elseif (!empty($r['is_active'])) $cActive++; else $cPaused++;
}
unset($r);

$flash = ['added' => 'Reminder added.', 'updated' => 'Reminder updated.', 'deleted' => 'Reminder deleted.'][$_GET['msg'] ?? ''] ?? (isset($_GET['msg']) ? 'Done.' : '');

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>', 25); ?></div>
        <div>
            <h1>Medicine Reminders</h1>
            <p>Help patients stay on track. Send WhatsApp reminders at the exact times they need to take their medicines.</p>
        </div>
    </div>
    <button type="button" class="su-btn" data-open="addReminderModal"><?php echo $lI('<path d="M5 12h14"/><path d="M12 5v14"/>'); ?> Add reminder</button>
</div>

<?php if ($flash): ?><div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="su-kpis">
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></div><div><b><?php echo count($reminders); ?></b><span>Patients</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<path d="M20 6 9 17l-5-5"/>'); ?></div><div><b><?php echo (int)$cActive; ?></b><span>Active</span></div></div>
    <div class="su-kpi amber"><div class="su-kpi-i"><?php echo $lI('<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>'); ?></div><div><b><?php echo (int)$cPaused; ?></b><span>Paused</span></div></div>
    <div class="su-kpi red"><div class="su-kpi-i"><?php echo $lI('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'); ?></div><div><b><?php echo (int)$cExpired; ?></b><span>Course finished</span></div></div>
</div>

<section class="su-card">
    <div class="su-tbl-wrap">
    <table class="su-tbl">
        <thead><tr><th>Patient</th><th>Contact</th><th>Medicines</th><th>Times</th><th>Period</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$reminders): ?>
        <tr><td colspan="7" class="su-tbl-empty">No reminders yet. Add the first one to get started.</td></tr>
        <?php else: foreach ($reminders as $r): ?>
        <tr>
            <td><span class="nm"><?php echo htmlspecialchars((string)$r['patient_name']); ?></span></td>
            <td><strong><?php echo htmlspecialchars((string)$r['contact_name']); ?></strong><br><small><?php echo htmlspecialchars((string)$r['phone']); ?></small></td>
            <td style="max-width:260px"><?php foreach (array_slice($r['_meds'], 0, 3) as $mname): ?><div style="font-size:13.5px"><?php echo htmlspecialchars((string)$mname); ?></div><?php endforeach; ?><?php if (count($r['_meds']) > 3): ?><small>+<?php echo count($r['_meds']) - 3; ?> more</small><?php endif; ?></td>
            <td><?php foreach ($r['_times'] as $t): ?><span class="su-tag blue" style="margin:0 4px 4px 0"><?php echo htmlspecialchars((string)$t); ?></span><?php endforeach; ?></td>
            <td style="white-space:nowrap"><?php echo date('d M', strtotime($r['start_date'])); ?><?php if (!empty($r['end_date'])): ?> → <?php echo date('d M', strtotime($r['end_date'])); ?><?php endif; ?></td>
            <td><?php if ($r['_expired']): ?><span class="su-tag">Finished</span><?php elseif (!empty($r['is_active'])): ?><span class="su-tag green">Active</span><?php else: ?><span class="su-tag amber">Paused</span><?php endif; ?></td>
            <td style="text-align:right;white-space:nowrap">
                <form method="POST" class="su-inline"><input type="hidden" name="reminder_id" value="<?php echo (int)$r['id']; ?>"><?php echo csrf_field(); ?>
                    <button name="toggle_reminder" class="su-btn xs soft"><?php echo !empty($r['is_active']) ? $lI('<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>') . ' Pause' : $lI('<polygon points="6 3 20 12 6 21 6 3"/>') . ' Resume'; ?></button></form>
                <form method="POST" class="su-inline" onsubmit="return confirm('Delete this reminder?')"><input type="hidden" name="reminder_id" value="<?php echo (int)$r['id']; ?>"><?php echo csrf_field(); ?>
                    <button name="delete_reminder" class="su-btn xs danger" aria-label="Delete reminder"><?php echo $lI('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>'); ?></button></form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>
</div>

<!-- Add reminder -->
<div class="su-modal" id="addReminderModal" role="dialog" aria-modal="true" aria-label="Add medicine reminder">
  <div class="su-modal-box su">
    <h3><span class="ch"><?php echo $lI('<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>'); ?></span> Add medicine reminder</h3>
    <p class="sub">Choose who to remind, what to take and when.</p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <div class="su-fg su-f">
        <div class="su-f full"><label class="l" for="mrContact">Patient's contact *</label>
          <select name="contact_id" id="mrContact" required><option value="">Select contact</option>
            <?php foreach ($contacts as $ct): ?><option value="<?php echo (int)$ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?> (<?php echo htmlspecialchars((string)$ct['phone']); ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="su-f full"><label class="l" for="mrName">Patient name *</label><input type="text" name="patient_name" id="mrName" required placeholder="e.g. Rajan (Father)"></div>
        <div class="su-f full"><label class="l" for="mrMeds">Medicines (one per line) *</label><textarea name="medicines" id="mrMeds" rows="4" required placeholder="Metformin 500mg - After breakfast&#10;Amlodipine 5mg - At night"></textarea></div>
        <div class="su-f full"><label class="l" for="mrTimes">Reminder times (comma separated)</label><input type="text" name="reminder_times" id="mrTimes" value="08:00, 14:00, 21:00" placeholder="08:00, 13:00, 21:00"><span class="hint">Use 24-hour time, for example 08:00 or 21:30.</span></div>
        <div class="su-f"><label class="l" for="mrStart">Start date</label><input type="date" name="start_date" id="mrStart" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="su-f"><label class="l" for="mrEnd">End date (optional)</label><input type="date" name="end_date" id="mrEnd"></div>
      </div>
      <div class="su-modal-foot"><button type="button" class="su-btn soft" data-close>Cancel</button><button type="submit" name="add_reminder" class="su-btn">Add reminder</button></div>
    </form>
  </div>
</div>
<script>
(function () {
    var m = document.getElementById('addReminderModal');
    function close() { m.classList.remove('open'); }
    document.querySelectorAll('[data-open]').forEach(function (b) { b.addEventListener('click', function () { m.classList.add('open'); }); });
    m.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php include '../includes/client_footer.php'; ?>
