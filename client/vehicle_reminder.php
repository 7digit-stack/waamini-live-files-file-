<?php
include '../includes/client_auth_check.php';
if (!has_feature('vehicle_reminder')) {
    http_response_code(403);
    include '../includes/client_header.php';
    echo '<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Vehicle Reminders are not enabled in your current plan.</div>';
    echo '<a href="subscription.php" class="btn btn-primary">View plans</a>';
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'Vehicle Service Reminder';
$currentPage = 'vehicle_reminder';

// Schema migrations are managed via migrations/ and should not run inline.

// Handle add
if (isset($_POST['add_vehicle'])) {
    csrf_verify();
    $cid  = intval($_POST['contact_id']);
    $vnum = strtoupper(sanitize($_POST['vehicle_number'] ?? ''));
    $vname= sanitize($_POST['vehicle_name'] ?? '');
    $vtype= in_array($_POST['vehicle_type'] ?? '', ['car','bike','truck','auto','other'], true) ? $_POST['vehicle_type'] : 'car';
    $lsd  = $_POST['last_service_date'] ?? null;
    $nsd  = $_POST['next_service_date'] ?? null;
    $skm  = isset($_POST['service_km']) && $_POST['service_km'] !== '' ? intval($_POST['service_km']) : null;
    $ins  = $_POST['insurance_expiry'] ?? null;
    $puc  = $_POST['pollution_expiry'] ?? null;
    $notes= sanitize($_POST['notes'] ?? '');

    $dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
    $lsd = (is_string($lsd) && preg_match($dateRegex, $lsd)) ? $lsd : null;
    $nsd = (is_string($nsd) && preg_match($dateRegex, $nsd)) ? $nsd : null;
    $ins = (is_string($ins) && preg_match($dateRegex, $ins)) ? $ins : null;
    $puc = (is_string($puc) && preg_match($dateRegex, $puc)) ? $puc : null;

    $stmt = $conn->prepare("INSERT INTO vehicles (company_id,contact_id,vehicle_number,vehicle_name,vehicle_type,last_service_date,next_service_date,service_km,insurance_expiry,pollution_expiry,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iisssssisss",$companyId,$cid,$vnum,$vname,$vtype,$lsd,$nsd,$skm,$ins,$puc,$notes);
    $stmt->execute();
    header("Location: vehicle_reminder.php?msg=added"); exit();
}

$stmt_v = $conn->prepare("SELECT v.*, c.name as owner_name, c.phone FROM vehicles v JOIN contacts c ON v.contact_id=c.id WHERE v.company_id=? ORDER BY v.next_service_date ASC");
$stmt_v->bind_param("i",$companyId); $stmt_v->execute(); $vehicles = $stmt_v->get_result();

$stmt_ct = $conn->prepare("SELECT id, name, phone FROM contacts WHERE company_id=? ORDER BY name LIMIT 500");
$stmt_ct->bind_param("i",$companyId); $stmt_ct->execute(); $contacts = $stmt_ct->get_result()->fetch_all(MYSQLI_ASSOC);

// Due soon stats
$stmt_due = $conn->prepare("SELECT COUNT(*) as c FROM vehicles WHERE company_id=? AND next_service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)");
$stmt_due->bind_param("i",$companyId); $stmt_due->execute(); $dueSoon = $stmt_due->get_result()->fetch_assoc()['c'];
$stmt_ins = $conn->prepare("SELECT COUNT(*) as c FROM vehicles WHERE company_id=? AND insurance_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)");
$stmt_ins->bind_param("i",$companyId); $stmt_ins->execute(); $insExpiring = $stmt_ins->get_result()->fetch_assoc()['c'];

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Vehicle <?php echo htmlspecialchars($_GET['msg']); ?>!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Vehicle Service Reminder</h2>
    <button onclick="document.getElementById('addVehicleModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Vehicle</button>
</div>

<!-- Alert banners -->
<?php if ($dueSoon>0): ?>
<div style="background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#e74c3c;font-size:13px;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> <strong><?php echo $dueSoon; ?> vehicles</strong> due for service in the next 7 days!
</div>
<?php endif; ?>
<?php if ($insExpiring>0): ?>
<div style="background:rgba(243,156,18,0.1);border:1px solid rgba(243,156,18,0.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#f39c12;font-size:13px;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg> <strong><?php echo $insExpiring; ?> vehicles</strong> with insurance expiring in 30 days!
</div>
<?php endif; ?>

<div class="data-table-wrapper">
<table class="data-table">
    <thead><tr><th>Vehicle</th><th>Owner</th><th>Next Service</th><th>Insurance</th><th>PUC</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($vehicles->num_rows===0): ?>
    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No vehicles yet. Add customer vehicles to send service reminders! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></td></tr>
    <?php else: while ($v=$vehicles->fetch_assoc()):
        $serviceAlert = $v['next_service_date'] && $v['next_service_date']<=date('Y-m-d',strtotime('+7 days'));
        $insAlert = $v['insurance_expiry'] && $v['insurance_expiry']<=date('Y-m-d',strtotime('+30 days'));
        $typeIcons=['car'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>','bike'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>','truck'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>','auto'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>','other'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>'];
    ?>
    <tr>
        <td>
            <span style="font-size:18px;"><?php echo $typeIcons[$v['vehicle_type']]??'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>'; ?></span>
            <strong style="margin-left:6px;"><?php echo htmlspecialchars($v['vehicle_number']); ?></strong><br>
            <small style="color:var(--text-muted);"><?php echo htmlspecialchars($v['vehicle_name']??''); ?></small>
        </td>
        <td><strong><?php echo htmlspecialchars($v['owner_name']); ?></strong><br><small style="color:var(--text-muted);"><?php echo $v['phone']; ?></small></td>
        <td>
            <?php if ($v['next_service_date']): ?>
            <span style="<?php echo $serviceAlert?'color:#e74c3c;font-weight:700;':''; ?>">
                <?php echo date('d M Y',strtotime($v['next_service_date'])); ?>
                <?php if ($serviceAlert): ?> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg><?php endif; ?>
            </span>
            <?php else: echo '—'; endif; ?>
        </td>
        <td>
            <?php if ($v['insurance_expiry']): ?>
            <span style="<?php echo $insAlert?'color:#f39c12;font-weight:700;':''; ?>">
                <?php echo date('d M Y',strtotime($v['insurance_expiry'])); ?>
                <?php if ($insAlert): ?> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg><?php endif; ?>
            </span>
            <?php else: echo '—'; endif; ?>
        </td>
        <td><?php echo $v['pollution_expiry']?date('d M Y',strtotime($v['pollution_expiry'])):'—'; ?></td>
        <td>
            <a href="whatsapp.php?contact_id=<?php echo $v['contact_id']; ?>" class="btn btn-sm btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Chat</a>
        </td>
    </tr>
    <?php endwhile; endif; ?>
    </tbody>
</table>
</div>

<!-- Add Vehicle Modal -->
<div id="addVehicleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:540px;max-width:90vw;max-height:90vh;overflow-y:auto;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Add Vehicle</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Owner (Contact)</label>
            <select name="contact_id" class="form-control" required>
                <option value="">Select contact...</option>
                <?php foreach ($contacts as $ct): ?>
                <option value="<?php echo $ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?> — <?php echo $ct['phone']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Vehicle Number</label><input type="text" name="vehicle_number" class="form-control" required placeholder="TN01AB1234" style="text-transform:uppercase;"></div>
            <div class="form-group"><label>Vehicle Type</label><select name="vehicle_type" class="form-control"><option value="car"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Car</option><option value="bike"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Bike</option><option value="truck"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Truck</option><option value="auto"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Auto</option><option value="other"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Other</option></select></div>
        </div>
        <div class="form-group"><label>Vehicle Name/Model</label><input type="text" name="vehicle_name" class="form-control" placeholder="e.g. Maruti Swift 2020"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Last Service Date</label><input type="date" name="last_service_date" class="form-control"></div>
            <div class="form-group"><label>Next Service Date</label><input type="date" name="next_service_date" class="form-control"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Insurance Expiry</label><input type="date" name="insurance_expiry" class="form-control"></div>
            <div class="form-group"><label>PUC Expiry</label><input type="date" name="pollution_expiry" class="form-control"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('addVehicleModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="add_vehicle" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg> Add Vehicle</button>
        </div>
    </form>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>


