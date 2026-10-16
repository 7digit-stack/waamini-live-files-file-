<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_contact_import')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$result = $_SESSION['cb_result'] ?? null;
unset($_SESSION['cb_result']);
$formError = '';

// Groups for the optional "add to group" picker
$groups = [];
$gq = $conn->prepare("SELECT id, name FROM contact_groups WHERE company_id = ? ORDER BY name");
if ($gq) { $gq->bind_param('i', $companyId); $gq->execute(); $gr = $gq->get_result(); while ($gr && ($x = $gr->fetch_assoc())) { $groups[(int)$x['id']] = $x['name']; } }

$limit = (int)get_limit('contacts');          // 0 = unlimited
$usage = (int)get_usage('contacts');

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    csrf_verify();
    $file = $_FILES['csv_file'];
    $groupId = (int)($_POST['group_id'] ?? 0);
    if ($groupId > 0 && !isset($groups[$groupId])) { $groupId = 0; }
    $defaultTag = trim((string)($_POST['default_tag'] ?? ''));
    if ($defaultTag === '') { $defaultTag = 'Imported'; }
    $defaultTag = mb_substr($defaultTag, 0, 60);

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $formError = 'The file could not be uploaded. Please try again.';
    } elseif (!in_array($ext, ['csv', 'txt'], true)) {
        $formError = 'Please upload a .csv file.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $formError = 'The file is larger than 5 MB. Split it into smaller files.';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $first = fgetcsv($handle);
        $rowsRead = 0; $imported = 0; $dups = 0; $invalid = 0; $overLimit = 0; $badRows = [];

        // existing numbers (digits only) so duplicates are skipped
        $existing = [];
        $eq = $conn->prepare("SELECT phone FROM contacts WHERE company_id = ?");
        if ($eq) { $eq->bind_param('i', $companyId); $eq->execute(); $er = $eq->get_result(); while ($er && ($x = $er->fetch_row())) { $existing[preg_replace('/\D+/', '', (string)$x[0])] = true; } }
        $remaining = $limit > 0 ? max(0, $limit - $usage) : PHP_INT_MAX;

        if ($groupId > 0) {
            $stmt = $conn->prepare("INSERT INTO contacts (company_id, name, phone, email, tags, group_id) VALUES (?, ?, ?, ?, ?, ?)");
        } else {
            $stmt = $conn->prepare("INSERT INTO contacts (company_id, name, phone, email, tags) VALUES (?, ?, ?, ?, ?)");
        }

        $process = static function (array $data, int $line) use (&$rowsRead, &$imported, &$dups, &$invalid, &$overLimit, &$badRows, &$existing, &$remaining, $stmt, $companyId, $groupId, $defaultTag): void {
            if (count($data) === 1 && trim((string)$data[0]) === '') { return; }
            $rowsRead++;
            $name = trim((string)($data[0] ?? ''));
            $phone = preg_replace('/\D+/', '', (string)($data[1] ?? ''));
            $emailRaw = trim((string)($data[2] ?? ''));
            $email = ($emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) ? $emailRaw : '';
            $tags = trim((string)($data[3] ?? '')) !== '' ? trim((string)$data[3]) : $defaultTag;
            if ($name === '' || strlen($phone) < 10 || strlen($phone) > 15) { $invalid++; if (count($badRows) < 6) { $badRows[] = $line; } return; }
            if (isset($existing[$phone])) { $dups++; return; }
            if ($remaining <= 0) { $overLimit++; return; }
            if ($groupId > 0) { $stmt->bind_param('issssi', $companyId, $name, $phone, $email, $tags, $groupId); }
            else { $stmt->bind_param('issss', $companyId, $name, $phone, $email, $tags); }
            if ($stmt->execute()) { $imported++; $existing[$phone] = true; $remaining--; } else { $invalid++; }
        };

        $line = 1;
        // The first row is a header unless its second column already looks like a phone number
        if ($first !== false && count($first) >= 2 && strlen(preg_replace('/\D+/', '', (string)$first[1])) >= 10) { $process($first, $line); }
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($rowsRead >= 5000) { break; }
            $process($data, $line);
        }
        fclose($handle);
        $_SESSION['cb_result'] = ['file' => (string)$file['name'], 'read' => $rowsRead, 'imported' => $imported, 'dups' => $dups, 'invalid' => $invalid, 'over' => $overLimit, 'bad' => $badRows, 'capped' => $rowsRead >= 5000];
        header('Location: contact_bulk.php');
        exit();
    }
}

// Stats
$scalar = static function (mysqli $db, string $sql, int $cid): int {
    $st = $db->prepare($sql);
    if (!$st) { return 0; }
    $st->bind_param('i', $cid);
    $st->execute();
    return (int)($st->get_result()->fetch_row()[0] ?? 0);
};
$totalContacts = $scalar($conn, "SELECT COUNT(*) FROM contacts WHERE company_id = ?", $companyId);
$new30 = $scalar($conn, "SELECT COUNT(*) FROM contacts WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", $companyId);
$imported = $scalar($conn, "SELECT COUNT(*) FROM contacts WHERE company_id = ? AND tags LIKE '%Imported%'", $companyId);
$dMap = [];
$dq = $conn->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM contacts WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)");
if ($dq) { $dq->bind_param('i', $companyId); $dq->execute(); $dr = $dq->get_result(); while ($dr && ($x = $dr->fetch_assoc())) { $dMap[$x['d']] = (int)$x['n']; } }
$dLabels = []; $dData = [];
for ($i = 29; $i >= 0; $i--) { $t = strtotime("-$i days"); $dLabels[] = date('d M', $t); $dData[] = $dMap[date('Y-m-d', $t)] ?? 0; }
$remainingTxt = $limit > 0 ? number_format(max(0, $limit - $usage)) : 'Unlimited';
$usedPct = $limit > 0 ? min(100, round($usage / $limit * 100)) : 0;

$pageTitle = 'Bulk Contacts';
$currentPage = 'contact_bulk';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    'down'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'tag'    => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'gauge'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'file'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
];
$cols = [
    ['name', 'Required', 'Full name of the contact', 'Priya Sharma'],
    ['phone', 'Required', 'Number with country code, 10 to 15 digits', '919876543210'],
    ['email', 'Optional', 'Valid email address', 'priya@example.com'],
    ['tags', 'Optional', 'Comma-separated labels, or the default tag is used', 'VIP, Lead'],
];
?>

<div class="db cb">

  <div class="db-head">
    <div>
      <h1>Bulk Contacts</h1>
      <p>Import many contacts at once from a CSV file. Duplicates and invalid rows are skipped for you.</p>
    </div>
    <div class="db-head__actions">
      <a href="contact_add.php" class="db-btn"><?php echo $__ic($I['plus'], 16); ?> Add one contact</a>
      <a href="contacts.php" class="db-btn"><?php echo $__ic($I['users'], 16); ?> All contacts</a>
    </div>
  </div>

  <?php if ($formError): ?><div class="cb-toast is-err" role="alert"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($formError); ?></span></div><?php endif; ?>

  <?php if ($result): ?>
    <section class="db-card cb-res <?php echo $result['imported'] > 0 ? 'is-ok' : 'is-warn'; ?>">
      <div class="cb-res__top"><span class="cb-res__ic"><?php echo $__ic($result['imported'] > 0 ? $I['check'] : $I['alert'], 22); ?></span>
        <div><b><?php echo $result['imported'] > 0 ? number_format($result['imported']) . ' contact' . ($result['imported'] === 1 ? '' : 's') . ' imported' : 'No new contacts were imported'; ?></b><small>from <?php echo $esc($result['file']); ?> &middot; <?php echo number_format($result['read']); ?> rows read<?php echo $result['capped'] ? ' (first 5,000 only)' : ''; ?></small></div></div>
      <div class="cb-res__grid">
        <div><b><?php echo number_format($result['imported']); ?></b><span>Imported</span></div>
        <div><b><?php echo number_format($result['dups']); ?></b><span>Already existed</span></div>
        <div><b><?php echo number_format($result['invalid']); ?></b><span>Invalid rows</span></div>
        <div><b><?php echo number_format($result['over']); ?></b><span>Over plan limit</span></div>
      </div>
      <?php if ($result['bad']): ?><p class="cb-res__note">Rows with a missing name or an invalid number, for example lines <?php echo $esc(implode(', ', $result['bad'])); ?>. Fix them and upload again.</p><?php endif; ?>
      <?php if ($result['over'] > 0): ?><p class="cb-res__note">Your plan's contact limit was reached. <a href="subscription.php">Upgrade your plan</a> to add more.</p><?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>New contacts</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Added: <b><?php echo number_format(array_sum($dData)); ?></b></span><span class="db-mut">Last 30 days</span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="cbChart" aria-label="Contacts added per day" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['users'], 19); ?></div><div class="db-kpi__l">Total contacts</div><div class="db-kpi__v"><?php echo number_format($totalContacts); ?></div><div class="db-kpi__d">In your workspace</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['cal'], 19); ?></div><div class="db-kpi__l">Added in 30 days</div><div class="db-kpi__v"><?php echo number_format($new30); ?></div><div class="db-kpi__d">New contacts</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($I['upload'], 19); ?></div><div class="db-kpi__l">Imported</div><div class="db-kpi__v"><?php echo number_format($imported); ?></div><div class="db-kpi__d">Tagged "Imported"</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['gauge'], 19); ?></div><div class="db-kpi__l">Room on your plan</div><div class="db-kpi__v" style="font-size:24px"><?php echo $esc($remainingTxt); ?></div><div class="db-kpi__d"><?php echo $limit > 0 ? number_format($usage) . ' of ' . number_format($limit) . ' used' : 'No contact limit'; ?></div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Plan usage</h2><span class="db-pill <?php echo $usedPct >= 90 ? 'is-bad' : ($usedPct >= 70 ? 'is-warn' : 'is-ok'); ?>"><?php echo $limit > 0 ? $usedPct . '% used' : 'Unlimited'; ?></span></div>
      <div class="db-donut__chart">
        <canvas id="cbDonut" width="200" height="200" aria-label="Contact limit usage" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($usage); ?></b><span><?php echo $limit > 0 ? 'of ' . number_format($limit) : 'contacts'; ?></span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Used: <?php echo number_format($usage); ?></span>
        <?php if ($limit > 0): ?><span><i style="background:#E8F1ED"></i>Free: <?php echo number_format(max(0, $limit - $usage)); ?></span><?php endif; ?>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card cb-card">
      <div class="cb-ch"><h2>Upload a CSV file</h2><p>Up to 5,000 rows and 5 MB per file.</p></div>
      <form method="POST" enctype="multipart/form-data" id="cbForm">
        <?php echo csrf_field(); ?>
        <label class="cb-drop" id="cbDrop" for="csv_input">
          <?php echo $__ic($I['upload'], 34); ?>
          <b id="cbDropT">Drop your CSV here or click to browse</b>
          <span id="cbDropS">Columns: name, phone, email, tags</span>
          <input type="file" name="csv_file" id="csv_input" accept=".csv,text/csv" style="display:none" required>
        </label>
        <div class="cb-two">
          <div class="cb-f"><label for="cb_group">Add to group <span>optional</span></label>
            <select name="group_id" id="cb_group"><option value="0">No group</option><?php foreach ($groups as $gid => $gn): ?><option value="<?php echo (int)$gid; ?>"><?php echo $esc($gn); ?></option><?php endforeach; ?></select></div>
          <div class="cb-f"><label for="cb_tag">Default tag</label><input type="text" name="default_tag" id="cb_tag" value="Imported" maxlength="60"><p>Used when a row has no tags.</p></div>
        </div>
        <div class="cb-btns">
          <button type="submit" class="db-btn db-btn--primary" id="cbGo"><?php echo $__ic($I['upload'], 16); ?> Import contacts</button>
          <button type="button" class="db-btn" id="cbSample"><?php echo $__ic($I['down'], 16); ?> Download sample CSV</button>
        </div>
      </form>
    </section>

    <section class="db-card cb-card">
      <div class="cb-ch"><h2>CSV format</h2><p>First row is a header. Keep the column order.</p></div>
      <div style="overflow-x:auto">
        <table class="cb-table"><thead><tr><th>Column</th><th>Rule</th><th>Example</th></tr></thead><tbody>
          <?php foreach ($cols as [$c, $req, $d, $ex]): ?>
            <tr><td><code><?php echo $esc($c); ?></code> <span class="db-pill <?php echo $req === 'Required' ? 'is-bad' : 'is-off'; ?>"><?php echo $esc($req); ?></span></td><td><?php echo $esc($d); ?></td><td class="db-mut"><?php echo $esc($ex); ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <ul class="cb-tips">
        <li><?php echo $__ic($I['check'], 15); ?> Numbers that already exist in your contacts are skipped, so re-uploading is safe.</li>
        <li><?php echo $__ic($I['check'], 15); ?> Spaces, + and dashes in phone numbers are cleaned up automatically.</li>
        <li><?php echo $__ic($I['check'], 15); ?> In Excel use Save As, CSV (UTF-8) so names in any language import correctly.</li>
      </ul>
    </section>
  </div>
</div>

<style>
.cb{max-width:none}
.cb-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.cb-toast.is-err{background:#FDE4E1;color:#c0372b}.cb-toast svg{margin-top:2px;flex:none}
.cb .db-canvas{height:270px}
.cb .db-donut__chart canvas{max-width:200px;max-height:200px}
.cb-res{padding:20px 22px;margin-bottom:18px}
.cb-res.is-ok{background:#F3FBF7}.cb-res.is-warn{background:#FFF8F0}
.cb-res__top{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.cb-res__ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;flex:none;background:#fff}
.cb-res.is-ok .cb-res__ic{color:#12803f}.cb-res.is-warn .cb-res__ic{color:#b7791f}
.cb-res__top b{display:block;font-size:17px;color:var(--ink)}
.cb-res__top small{display:block;margin-top:2px;font-size:13px;color:var(--mu)}
.cb-res__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.cb-res__grid div{padding:12px 14px;border-radius:12px;background:#fff}
.cb-res__grid b{display:block;font-size:22px;font-weight:800;color:var(--ink)}
.cb-res__grid span{font-size:12.5px;color:var(--mu);font-weight:600}
.cb-res__note{margin:12px 0 0;font-size:13.5px;color:var(--tx);line-height:1.55}
.cb-res__note a{color:#128C7E;font-weight:700}
.cb-card{padding:22px}
.cb-ch{margin-bottom:16px}
.cb-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.cb-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.cb-drop{display:flex;flex-direction:column;align-items:center;gap:7px;padding:38px 16px;border:2px dashed #BFE3D6;border-radius:16px;background:var(--soft);text-align:center;color:var(--g);cursor:pointer;transition:background .15s,border-color .15s;margin-bottom:16px}
.cb-drop:hover,.cb-drop.is-over{background:#E6FCF2;border-color:var(--g)}
.cb-drop b{color:var(--ink);font-size:15.5px;word-break:break-all}
.cb-drop span{font-size:13px;color:var(--mu)}
.cb-drop.has-file{border-style:solid;background:#E6FCF2}
.cb-two{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:6px}
.cb-f{margin-bottom:12px}
.cb-f label{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.cb-f label span{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:4px}
.cb-f p{margin:5px 0 0;font-size:12.5px;color:var(--mu)}
.cb-f input,.cb-f select{width:100%;height:44px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.cb-f input:focus,.cb-f select:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cb-btns{display:flex;gap:10px;flex-wrap:wrap}
.cb-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
.cb-table th{padding:10px 14px;background:#F4F7F6;font-size:13px;font-weight:700;color:var(--tx);text-align:left}
.cb-table th:first-child{border-radius:10px 0 0 10px}.cb-table th:last-child{border-radius:0 10px 10px 0}
.cb-table td{padding:12px 14px;color:var(--tx);vertical-align:top}
.cb-table tbody tr:nth-child(even) td{background:#F8FCFA}
.cb-table code{font:700 13px ui-monospace,Consolas,monospace;color:var(--gd);margin-right:6px}
.cb-tips{list-style:none;margin:16px 0 0;padding:0;display:flex;flex-direction:column;gap:9px}
.cb-tips li{display:flex;gap:9px;align-items:flex-start;padding:11px 14px;border-radius:12px;background:var(--soft);font-size:13.5px;line-height:1.5;color:var(--tx)}
.cb-tips li svg{margin-top:2px;color:var(--g);flex:none}
@media (max-width:1100px){.cb .db-row{grid-template-columns:1fr}}
@media (max-width:640px){.cb-two{grid-template-columns:1fr}.cb-res__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var input = $('csv_input'), drop = $('cbDrop');
  function show() {
    var f = input.files && input.files[0];
    if (!f) { drop.classList.remove('has-file'); $('cbDropT').textContent = 'Drop your CSV here or click to browse'; $('cbDropS').textContent = 'Columns: name, phone, email, tags'; return; }
    drop.classList.add('has-file'); $('cbDropT').textContent = f.name; $('cbDropS').textContent = (f.size / 1024).toFixed(1) + ' KB, ready to import';
  }
  input.addEventListener('change', show);
  ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
  $('cbForm').addEventListener('submit', function () { $('cbGo').disabled = true; $('cbGo').lastChild.nodeValue = ' Importing...'; });
  $('cbSample').addEventListener('click', function () {
    var csv = 'name,phone,email,tags\nPriya Sharma,919876543210,priya@example.com,"VIP, Lead"\nRahul Verma,919812345678,,Customer\n';
    var url = URL.createObjectURL(new Blob([csv], {type: 'text/csv'})), a = document.createElement('a');
    a.href = url; a.download = 'contacts-sample.csv'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
  });
  if (typeof Chart === 'undefined') { return; }
  var ctx = $('cbChart').getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270); g.addColorStop(0, 'rgba(37,211,102,.32)'); g.addColorStop(1, 'rgba(37,211,102,.02)');
  new Chart(ctx, {type: 'line', data: {labels: <?php echo json_encode($dLabels); ?>, datasets: [{label: 'New contacts', data: <?php echo json_encode($dData); ?>, borderColor: '#128C7E', backgroundColor: g, borderWidth: 3, fill: true, tension: .38, pointRadius: 0, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderColor: '#128C7E', pointBorderWidth: 2.5}]},
    options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false}},
      scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}}, y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var used = <?php echo (int)$usage; ?>, lim = <?php echo (int)$limit; ?>, vals = lim > 0 ? [Math.min(used, lim), Math.max(0, lim - used)] : [used || 1, 0];
  new Chart($('cbDonut'), {type: 'doughnut', data: {labels: ['Used', 'Free'], datasets: [{data: vals, backgroundColor: ['#25D366', '#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>
