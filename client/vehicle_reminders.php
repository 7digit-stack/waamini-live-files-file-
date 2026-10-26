<?php
include '../includes/client_auth_check.php';

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

if (!has_feature('vehicle_reminder')) {
    http_response_code(403);
    $pageTitle = 'Vehicle Service Reminders';
    $currentPage = 'vehicle_reminders';
    include '../includes/client_header.php';
    ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
    <div class="su"><div class="su-card" style="align-items:center;text-align:center;padding:48px 24px;">
        <div class="su-ico" style="width:64px;height:64px;"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 28); ?></div>
        <h2 style="justify-content:center;">Vehicle reminders are not in your plan</h2>
        <p class="sub" style="margin:0">Upgrade to remind customers about services, insurance and PUC renewals.</p>
        <a href="subscription.php" class="su-btn">View plans</a>
    </div></div>
    <?php
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'Vehicle Service Reminders';
$currentPage = 'vehicle_reminders';

// Ensure required table exists (older installs may not have run latest migrations).
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            vehicle_number VARCHAR(30) NOT NULL,
            vehicle_name VARCHAR(150) DEFAULT NULL,
            vehicle_type VARCHAR(100) DEFAULT NULL,
            make_model VARCHAR(150) DEFAULT NULL,
            year INT DEFAULT NULL,
            last_service_date DATE DEFAULT NULL,
            next_service_date DATE DEFAULT NULL,
            service_km INT DEFAULT NULL,
            insurance_expiry DATE DEFAULT NULL,
            pollution_expiry DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_next_service (next_service_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) {
    // best-effort
}

if (isset($_POST['save_vehicle'])) {
    csrf_verify();
    $vid = intval($_POST['vehicle_id'] ?? 0);
    $cid = intval($_POST['contact_id'] ?? 0);
    $vnum = strtoupper(sanitize($_POST['vehicle_number'] ?? ''));
    $vtype = sanitize($_POST['vehicle_type'] ?? '');
    $model = sanitize($_POST['make_model'] ?? '');
    $year = intval($_POST['year'] ?? 0) ?: null;
    $lastSvc = !empty($_POST['last_service_date']) ? $_POST['last_service_date'] : null;
    $nextSvc = !empty($_POST['next_service_date']) ? $_POST['next_service_date'] : null;
    $insExp = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $pollExp = !empty($_POST['pollution_expiry']) ? $_POST['pollution_expiry'] : null;
    $notes = sanitize($_POST['notes'] ?? '');
    if ($cid > 0 && $vnum !== '') {
        if ($vid) {
            $stmt = $conn->prepare("UPDATE vehicles SET contact_id=?,vehicle_number=?,vehicle_type=?,make_model=?,year=?,last_service_date=?,next_service_date=?,insurance_expiry=?,pollution_expiry=?,notes=? WHERE id=? AND company_id=?");
            if ($stmt) { $stmt->bind_param("isssisssssii", $cid, $vnum, $vtype, $model, $year, $lastSvc, $nextSvc, $insExp, $pollExp, $notes, $vid, $companyId); }
        } else {
            $stmt = $conn->prepare("INSERT INTO vehicles (company_id,contact_id,vehicle_number,vehicle_type,make_model,year,last_service_date,next_service_date,insurance_expiry,pollution_expiry,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            if ($stmt) { $stmt->bind_param("iisssisssss", $companyId, $cid, $vnum, $vtype, $model, $year, $lastSvc, $nextSvc, $insExp, $pollExp, $notes); }
        }
        if (!empty($stmt)) $stmt->execute();
    }
    header("Location: vehicle_reminders.php?msg=saved"); exit();
}

if (isset($_POST['send_reminder'])) {
    csrf_verify();
    $vid = intval($_POST['vehicle_id'] ?? 0);
    $type = (string)($_POST['reminder_type'] ?? 'service');
    $result = 'failed';
    $stmt_v = $conn->prepare("SELECT v.*, c.name, c.phone FROM vehicles v JOIN contacts c ON v.contact_id=c.id WHERE v.id=? AND v.company_id=?");
    $v = null;
    if ($stmt_v) { $stmt_v->bind_param("ii", $vid, $companyId); $stmt_v->execute(); $v = $stmt_v->get_result()->fetch_assoc(); }
    if ($v) {
        $vModel = $v['make_model'] ?: 'vehicle';
        $msgs = [
            'service'   => "🔧 *Vehicle Service Reminder*\n\nDear {$v['name']},\n\nYour *{$vModel}* ({$v['vehicle_number']}) is due for service on *" . date('d M Y', strtotime($v['next_service_date'] ?? 'today')) . "*.\n\nBook your service slot today!\n\nStay safe on the roads! 🚗",
            'insurance' => "🛡️ *Insurance Renewal Reminder*\n\nDear {$v['name']},\n\nYour vehicle insurance for *{$v['vehicle_number']}* expires on *" . date('d M Y', strtotime($v['insurance_expiry'] ?? 'today')) . "*.\n\nRenew before expiry to stay protected!",
            'pollution' => "🌿 *PUC Certificate Reminder*\n\nDear {$v['name']},\n\nThe pollution certificate for *{$v['vehicle_number']}* expires on *" . date('d M Y', strtotime($v['pollution_expiry'] ?? 'today')) . "*.\n\nGet it renewed to avoid fines!",
        ];
        $msg = $msgs[$type] ?? $msgs['service'];
        $stmt_wc = $conn->prepare("SELECT access_token,phone_number_id,api_version FROM whatsapp_config WHERE company_id=? AND status='active' LIMIT 1");
        $wc = null;
        if ($stmt_wc) { $stmt_wc->bind_param("i", $companyId); $stmt_wc->execute(); $wc = $stmt_wc->get_result()->fetch_assoc(); }
        if ($wc) {
            $token = (string)$wc['access_token'];
            if (function_exists('decrypt_secret')) { $token = decrypt_secret($token) ?? $token; }
            $apiVer = $wc['api_version'] ?: 'v18.0';
            $payload = json_encode(['messaging_product' => 'whatsapp', 'to' => $v['phone'], 'type' => 'text', 'text' => ['body' => $msg]]);
            $ch = curl_init("https://graph.facebook.com/{$apiVer}/{$wc['phone_number_id']}/messages");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = ($code >= 200 && $code < 300) ? 'sent' : 'failed';
        } else {
            $result = 'nowa';
        }
    }
    header("Location: vehicle_reminders.php?msg=reminder_" . $result); exit();
}

if (isset($_POST['delete_vehicle'])) {
    csrf_verify(); $vid = intval($_POST['vehicle_id'] ?? 0);
    $stmt_dv = $conn->prepare("DELETE FROM vehicles WHERE id=? AND company_id=?");
    if ($stmt_dv) { $stmt_dv->bind_param("ii", $vid, $companyId); $stmt_dv->execute(); }
    header("Location: vehicle_reminders.php?msg=deleted"); exit();
}

$vehicles = [];
$stmt_v = $conn->prepare("SELECT v.*,c.name,c.phone FROM vehicles v JOIN contacts c ON v.contact_id=c.id WHERE v.company_id=? ORDER BY v.next_service_date IS NULL, v.next_service_date ASC");
if ($stmt_v) { $stmt_v->bind_param("i", $companyId); $stmt_v->execute(); $vehicles = $stmt_v->get_result()->fetch_all(MYSQLI_ASSOC); }

$contacts = [];
$stmt_ct = $conn->prepare("SELECT id,name,phone FROM contacts WHERE company_id=? ORDER BY name LIMIT 500");
if ($stmt_ct) { $stmt_ct->bind_param("i", $companyId); $stmt_ct->execute(); $contacts = $stmt_ct->get_result()->fetch_all(MYSQLI_ASSOC); }

// Days until a date (null when not set)
$daysUntil = static function (?string $d): ?int {
    if (!$d) return null;
    return (int)floor((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
};
$dueTag = static function (?string $d) use ($daysUntil): string {
    $n = $daysUntil($d);
    if ($n === null) return '<span style="color:#586a63">—</span>';
    $date = date('d M Y', strtotime($d));
    if ($n < 0) return '<span class="su-tag red">Overdue</span><br><small>' . $date . '</small>';
    if ($n <= 7) return '<span class="su-tag red">' . ($n === 0 ? 'Today' : $n . ' day' . ($n === 1 ? '' : 's')) . '</span><br><small>' . $date . '</small>';
    if ($n <= 30) return '<span class="su-tag amber">' . $n . ' days</span><br><small>' . $date . '</small>';
    return '<span class="su-tag green">' . $date . '</span>';
};
$cSvc = $cIns = $cPuc = 0;
foreach ($vehicles as $v) {
    $a = $daysUntil($v['next_service_date']); if ($a !== null && $a <= 30) $cSvc++;
    $b = $daysUntil($v['insurance_expiry']);  if ($b !== null && $b <= 30) $cIns++;
    $c = $daysUntil($v['pollution_expiry']);  if ($c !== null && $c <= 30) $cPuc++;
}

$flashMap = [
    'saved' => ['ok', 'Vehicle saved.'], 'deleted' => ['ok', 'Vehicle deleted.'], 'reminder_sent' => ['ok', 'Reminder sent on WhatsApp.'],
    'reminder_failed' => ['err', 'WhatsApp could not deliver the reminder. Check your connection settings and try again.'],
    'reminder_nowa' => ['err', 'Connect WhatsApp in Settings before sending reminders.'],
];
$flash = $flashMap[$_GET['msg'] ?? ''] ?? null;

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>', 25); ?></div>
        <div>
            <h1>Vehicle Service Reminders</h1>
            <p>Never miss a renewal. Remind customers about services, insurance and PUC dates on WhatsApp.</p>
        </div>
    </div>
    <button type="button" class="su-btn" id="addVehicleBtn"><?php echo $lI('<path d="M5 12h14"/><path d="M12 5v14"/>'); ?> Add vehicle</button>
</div>

<?php if ($flash): ?><div class="su-alert <?php echo $flash[0]; ?>" style="margin-bottom:18px"><?php echo $lI($flash[0] === 'ok' ? '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>' : '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> <?php echo htmlspecialchars($flash[1]); ?></div><?php endif; ?>

<div class="su-kpis">
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>'); ?></div><div><b><?php echo count($vehicles); ?></b><span>Vehicles</span></div></div>
    <div class="su-kpi amber"><div class="su-kpi-i"><?php echo $lI('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'); ?></div><div><b><?php echo (int)$cSvc; ?></b><span>Services due in 30 days</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'); ?></div><div><b><?php echo (int)$cIns; ?></b><span>Insurance expiring</span></div></div>
    <div class="su-kpi purple"><div class="su-kpi-i"><?php echo $lI('<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>'); ?></div><div><b><?php echo (int)$cPuc; ?></b><span>PUC expiring</span></div></div>
</div>

<section class="su-card">
    <div class="su-tbl-wrap">
    <table class="su-tbl">
        <thead><tr><th>Vehicle</th><th>Owner</th><th>Next service</th><th>Insurance</th><th>PUC</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$vehicles): ?>
        <tr><td colspan="6" class="su-tbl-empty">No vehicles yet. Add your first vehicle to start sending reminders.</td></tr>
        <?php else: foreach ($vehicles as $v): ?>
        <tr>
            <td><span class="nm"><?php echo htmlspecialchars($v['vehicle_number']); ?></span><br><small><?php echo htmlspecialchars(trim(($v['vehicle_type'] ? $v['vehicle_type'] . ' · ' : '') . ($v['make_model'] ?? ''))); ?></small></td>
            <td><strong><?php echo htmlspecialchars((string)$v['name']); ?></strong><br><small><?php echo htmlspecialchars((string)$v['phone']); ?></small></td>
            <td><?php echo $dueTag($v['next_service_date']); ?></td>
            <td><?php echo $dueTag($v['insurance_expiry']); ?></td>
            <td><?php echo $dueTag($v['pollution_expiry']); ?></td>
            <td style="text-align:right;white-space:nowrap">
                <form method="POST" class="su-inline">
                    <input type="hidden" name="vehicle_id" value="<?php echo (int)$v['id']; ?>"><?php echo csrf_field(); ?>
                    <select name="reminder_type" aria-label="Reminder type" style="height:34px;border:0;border-radius:9px;background:#fff;padding:0 10px;font-size:13px;font-weight:700;color:#0F2622;">
                        <option value="service">Service</option><option value="insurance">Insurance</option><option value="pollution">PUC</option>
                    </select>
                    <button name="send_reminder" class="su-btn xs"><?php echo $lI('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>'); ?> Send</button>
                </form>
                <button type="button" class="su-btn xs soft js-edit" aria-label="Edit vehicle"
                    data-v="<?php echo htmlspecialchars(json_encode(['id' => (int)$v['id'], 'contact_id' => (int)$v['contact_id'], 'vehicle_number' => $v['vehicle_number'], 'vehicle_type' => $v['vehicle_type'], 'make_model' => $v['make_model'], 'year' => $v['year'], 'last_service_date' => $v['last_service_date'], 'next_service_date' => $v['next_service_date'], 'insurance_expiry' => $v['insurance_expiry'], 'pollution_expiry' => $v['pollution_expiry']], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"><?php echo $lI('<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>'); ?></button>
                <form method="POST" class="su-inline" onsubmit="return confirm('Delete this vehicle?')">
                    <input type="hidden" name="vehicle_id" value="<?php echo (int)$v['id']; ?>"><?php echo csrf_field(); ?>
                    <button name="delete_vehicle" class="su-btn xs danger" aria-label="Delete vehicle"><?php echo $lI('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>'); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>
</div>

<!-- Add / edit vehicle -->
<div class="su-modal" id="vehicleModal" role="dialog" aria-modal="true" aria-labelledby="vmTitle">
  <div class="su-modal-box su">
    <h3><span class="ch"><?php echo $lI('<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>'); ?></span> <span id="vmTitle">Add vehicle</span></h3>
    <p class="sub">Dates are optional. Add the ones you want to remind about.</p>
    <form method="POST" id="vehicleForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="vehicle_id" id="vmId" value="0">
      <div class="su-fg su-f">
        <div class="su-f full"><label class="l" for="vmContact">Owner (contact) *</label>
          <select name="contact_id" id="vmContact" required><option value="">Select contact</option>
            <?php foreach ($contacts as $ct): ?><option value="<?php echo (int)$ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?> (<?php echo htmlspecialchars((string)$ct['phone']); ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="su-f"><label class="l" for="vmNum">Vehicle number *</label><input type="text" name="vehicle_number" id="vmNum" style="text-transform:uppercase" required placeholder="TN01AB1234"></div>
        <div class="su-f"><label class="l" for="vmType">Type</label><select name="vehicle_type" id="vmType"><option>Car</option><option>Bike</option><option>Truck</option><option>Auto</option><option>Other</option></select></div>
        <div class="su-f"><label class="l" for="vmModel">Make &amp; model</label><input type="text" name="make_model" id="vmModel" placeholder="Honda City"></div>
        <div class="su-f"><label class="l" for="vmYear">Year</label><input type="number" name="year" id="vmYear" placeholder="2020" min="1990" max="2035"></div>
        <div class="su-f"><label class="l" for="vmLast">Last service</label><input type="date" name="last_service_date" id="vmLast"></div>
        <div class="su-f"><label class="l" for="vmNext">Next service due</label><input type="date" name="next_service_date" id="vmNext"></div>
        <div class="su-f"><label class="l" for="vmIns">Insurance expiry</label><input type="date" name="insurance_expiry" id="vmIns"></div>
        <div class="su-f"><label class="l" for="vmPuc">PUC expiry</label><input type="date" name="pollution_expiry" id="vmPuc"></div>
      </div>
      <div class="su-modal-foot"><button type="button" class="su-btn soft" id="vmCancel">Cancel</button><button type="submit" name="save_vehicle" class="su-btn">Save vehicle</button></div>
    </form>
  </div>
</div>
<script>
(function () {
    var m = document.getElementById('vehicleModal');
    var f = document.getElementById('vehicleForm');
    function open(v) {
        f.reset();
        document.getElementById('vmTitle').textContent = v ? 'Edit vehicle' : 'Add vehicle';
        document.getElementById('vmId').value = v ? v.id : 0;
        if (v) {
            document.getElementById('vmContact').value = v.contact_id;
            document.getElementById('vmNum').value = v.vehicle_number || '';
            document.getElementById('vmType').value = v.vehicle_type || 'Car';
            document.getElementById('vmModel').value = v.make_model || '';
            document.getElementById('vmYear').value = v.year || '';
            document.getElementById('vmLast').value = v.last_service_date || '';
            document.getElementById('vmNext').value = v.next_service_date || '';
            document.getElementById('vmIns').value = v.insurance_expiry || '';
            document.getElementById('vmPuc').value = v.pollution_expiry || '';
        }
        m.classList.add('open');
    }
    function close() { m.classList.remove('open'); }
    document.getElementById('addVehicleBtn').addEventListener('click', function () { open(null); });
    document.querySelectorAll('.js-edit').forEach(function (b) {
        b.addEventListener('click', function () { var v = null; try { v = JSON.parse(b.dataset.v); } catch (e) {} open(v); });
    });
    document.getElementById('vmCancel').addEventListener('click', close);
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php include '../includes/client_footer.php'; ?>
