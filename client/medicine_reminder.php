<?php
include '../includes/client_auth_check.php';
if (!has_feature('medicine_reminder')) {
    http_response_code(403);
    include '../includes/client_header.php';
    echo '<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Medicine Reminders are not enabled in your current plan.</div>';
    echo '<a href="subscription.php" class="btn btn-primary">View plans</a>';
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'Medicine Reminder Bot';
$currentPage = 'medicine_reminder';

// Schema migrations are managed via `migrations/` and should not run inline.

// Handle add
if (isset($_POST['add_reminder'])) {
    csrf_verify();
    $cid    = intval($_POST['contact_id']);
    $med    = sanitize($_POST['medicine_name'] ?? '');
    $dos    = sanitize($_POST['dosage'] ?? '');
    $freq   = in_array($_POST['frequency'] ?? '', ['daily','twice_daily','thrice_daily','weekly','custom'], true) ? $_POST['frequency'] : 'daily';
    $times  = array_filter(array_map('trim', explode(',', $_POST['reminder_times'] ?? '08:00')));
    $times  = array_values(array_filter($times, static fn($t) => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $t) === 1));
    if (!$times) {
        $times = ['08:00'];
    }
    $start  = $_POST['start_date'] ?? date('Y-m-d');
    $end    = $_POST['end_date'] ?? null;
    $start  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : date('Y-m-d');
    $end    = (is_string($end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) ? $end : null;
    $refill = max(0,intval($_POST['refill_reminder_days']??3));
    $notes  = sanitize($_POST['notes'] ?? '');
    $timesJson = json_encode(array_values($times));
    $stmt   = $conn->prepare("INSERT INTO medicine_reminders (company_id,contact_id,medicine_name,dosage,frequency,reminder_times,start_date,end_date,refill_reminder_days,notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iissssssis", $companyId, $cid, $med, $dos, $freq, $timesJson, $start, $end, $refill, $notes);
    $stmt->execute();
    header("Location: medicine_reminder.php?msg=added"); exit();
}

// Handle toggle
if (isset($_POST['toggle_reminder'])) {
    csrf_verify();
    $rid = intval($_POST['reminder_id']);
    $stmt = $conn->prepare("UPDATE medicine_reminders SET is_active=1-is_active WHERE id=? AND company_id=?");
    $stmt->bind_param("ii",$rid,$companyId); $stmt->execute();
    header("Location: medicine_reminder.php"); exit();
}

$stmt_r = $conn->prepare("SELECT mr.*, c.name as contact_name, c.phone FROM medicine_reminders mr JOIN contacts c ON mr.contact_id=c.id WHERE mr.company_id=? ORDER BY mr.is_active DESC, mr.id DESC");
$stmt_r->bind_param("i",$companyId); $stmt_r->execute(); $reminders = $stmt_r->get_result();

$stmt_ct = $conn->prepare("SELECT id, name, phone FROM contacts WHERE company_id=? ORDER BY name LIMIT 500");
$stmt_ct->bind_param("i",$companyId); $stmt_ct->execute(); $contacts = $stmt_ct->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Reminder <?php echo htmlspecialchars($_GET['msg']); ?>!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Medicine Reminder Bot</h2>
    <button onclick="document.getElementById('addModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Reminder</button>
</div>

<div class="data-table-wrapper">
<table class="data-table">
    <thead><tr><th>Patient</th><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Times</th><th>End Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php if ($reminders->num_rows===0): ?>
    <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No reminders yet. Add a medicine reminder to start! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg></td></tr>
    <?php else: while ($r=$reminders->fetch_assoc()):
        $times = json_decode($r['reminder_times']??'[]',true);
        $freqLabels=['daily'=>'Daily','twice_daily'=>'Twice Daily','thrice_daily'=>'3x Daily','weekly'=>'Weekly','custom'=>'Custom'];
        $isExpired = $r['end_date'] && $r['end_date']<date('Y-m-d');
    ?>
    <tr style="<?php echo !$r['is_active']||$isExpired?'opacity:0.6;':''; ?>">
        <td><strong><?php echo htmlspecialchars($r['contact_name']); ?></strong><br><small style="color:var(--text-muted);"><?php echo $r['phone']; ?></small></td>
        <td><strong><?php echo htmlspecialchars($r['medicine_name']); ?></strong><?php if($r['notes']): ?><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars(mb_strimwidth($r['notes'],0,40,'...')); ?></small><?php endif; ?></td>
        <td><?php echo htmlspecialchars($r['dosage']??'—'); ?></td>
        <td><?php echo $freqLabels[$r['frequency']]??$r['frequency']; ?></td>
        <td style="font-size:12px;"><?php echo implode(', ',$times); ?></td>
        <td style="font-size:12px;"><?php echo $r['end_date']?date('d M Y',strtotime($r['end_date'])):'Ongoing'; ?></td>
        <td>
            <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="reminder_id" value="<?php echo $r['id']; ?>">
                <button type="submit" name="toggle_reminder" style="padding:3px 12px;border-radius:20px;border:none;cursor:pointer;font-size:11px;font-weight:700;background:<?php echo $r['is_active']&&!$isExpired?'rgba(0,184,148,0.15)':'rgba(149,165,166,0.15)'; ?>;color:<?php echo $r['is_active']&&!$isExpired?'#00b894':'#95a5a6'; ?>;">
                    <?php echo $isExpired?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Expired':($r['is_active']?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Active':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg> Paused'); ?>
                </button>
            </form>
        </td>
    </tr>
    <?php endwhile; endif; ?>
    </tbody>
</table>
</div>

<!-- Add Modal -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:520px;max-width:90vw;max-height:90vh;overflow-y:auto;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Add Medicine Reminder</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Patient (Contact)</label>
            <select name="contact_id" class="form-control" required>
                <option value="">Select contact...</option>
                <?php foreach ($contacts as $ct): ?>
                <option value="<?php echo $ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?> — <?php echo $ct['phone']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Medicine Name</label><input type="text" name="medicine_name" class="form-control" required placeholder="e.g. Metformin 500mg"></div>
            <div class="form-group"><label>Dosage</label><input type="text" name="dosage" class="form-control" placeholder="e.g. 1 tablet"></div>
        </div>
        <div class="form-group">
            <label>Frequency</label>
            <select name="frequency" class="form-control" onchange="updateTimes(this.value)">
                <option value="daily">Daily (1x)</option>
                <option value="twice_daily">Twice Daily (2x)</option>
                <option value="thrice_daily">3 Times Daily</option>
                <option value="weekly">Weekly</option>
                <option value="custom">Custom</option>
            </select>
        </div>
        <div class="form-group"><label>Reminder Times <span style="color:var(--text-muted);font-size:11px;">(comma separated, 24hr format)</span></label><input type="text" name="reminder_times" id="reminderTimes" class="form-control" value="08:00" placeholder="08:00, 14:00, 20:00"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="form-group"><label>End Date (optional)</label><input type="date" name="end_date" class="form-control"></div>
        </div>
        <div class="form-group"><label>Refill Reminder (days before end)</label><input type="number" name="refill_reminder_days" class="form-control" value="3" min="0"></div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Doctor instructions, side effects to watch..."></textarea></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="add_reminder" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Add Reminder</button>
        </div>
    </form>
</div>
</div>
<script>
function updateTimes(v){
    var t={'daily':'08:00','twice_daily':'08:00, 20:00','thrice_daily':'08:00, 14:00, 20:00','weekly':'08:00','custom':''};
    if(t[v]!==undefined) document.getElementById('reminderTimes').value=t[v];
}
</script>
<?php include '../includes/client_footer.php'; ?>



