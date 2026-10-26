<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_blacklist')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$flash = $_SESSION['bl_flash'] ?? null;
unset($_SESSION['bl_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Add one or many numbers (one per line)
    if (isset($_POST['add_phone'])) {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') { $reason = 'Manual addition'; }
        $reason = mb_substr($reason, 0, 190);
        $lines = preg_split('/[\r\n,;]+/', (string)($_POST['phone'] ?? ''));
        $added = 0; $dups = 0; $bad = 0;
        $chk = $conn->prepare("SELECT id FROM blacklist WHERE company_id = ? AND phone = ? LIMIT 1");
        $ins = $conn->prepare("INSERT INTO blacklist (company_id, phone, reason) VALUES (?, ?, ?)");
        foreach (array_unique(array_filter(array_map('trim', $lines), 'strlen')) as $raw) {
            $phone = preg_replace('/[^0-9]/', '', $raw);
            if (strlen($phone) < 10 || strlen($phone) > 15) { $bad++; continue; }
            $chk->bind_param('is', $companyId, $phone);
            $chk->execute();
            if ($chk->get_result()->fetch_row()) { $dups++; continue; }
            $ins->bind_param('iss', $companyId, $phone, $reason);
            if ($ins->execute()) { $added++; }
        }
        if ($added > 0) {
            $msg = $added . ' number' . ($added === 1 ? '' : 's') . ' blocked.' . ($dups ? " $dups already on the list." : '') . ($bad ? " $bad invalid skipped." : '');
            $_SESSION['bl_flash'] = [true, $msg];
        } elseif ($dups > 0 && $bad === 0) {
            $_SESSION['bl_flash'] = [false, $dups === 1 ? 'That number is already on the list.' : 'Those numbers are already on the list.'];
        } else {
            $_SESSION['bl_flash'] = [false, 'Enter a valid phone number with country code (10 to 15 digits).'];
        }
        header('Location: blacklist.php');
        exit();
    }

    // Remove a number (POST + CSRF, scoped to this company)
    if (isset($_POST['remove_id'])) {
        $id = (int)$_POST['remove_id'];
        $stmt = $conn->prepare("DELETE FROM blacklist WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $_SESSION['bl_flash'] = [true, 'Number removed. It can receive messages again.'];
        header('Location: blacklist.php');
        exit();
    }
}

$scalar = static function (mysqli $db, string $sql, int $cid): int {
    $st = $db->prepare($sql);
    if (!$st) { return 0; }
    $st->bind_param('i', $cid);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    return (int)($row[0] ?? 0);
};
$digits = "REPLACE(REPLACE(REPLACE(REPLACE(c.phone,'+',''),' ',''),'-',''),'(','')";
$totalBlocked = $scalar($conn, "SELECT COUNT(*) FROM blacklist WHERE company_id = ?", $companyId);
$addedWeek = $scalar($conn, "SELECT COUNT(*) FROM blacklist WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $companyId);
$totalContacts = $scalar($conn, "SELECT COUNT(*) FROM contacts WHERE company_id = ?", $companyId);
$matchedContacts = $scalar($conn, "SELECT COUNT(DISTINCT c.id) FROM contacts c JOIN blacklist b ON b.company_id = c.company_id AND b.phone = $digits WHERE c.company_id = ?", $companyId);
$reachable = max(0, $totalContacts - $matchedContacts);
$blockedPct = $totalContacts > 0 ? round($matchedContacts / $totalContacts * 100, 1) : 0;

// Opt-outs added per day, last 30 days
$dMap = [];
$dq = $conn->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM blacklist WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)");
if ($dq) { $dq->bind_param('i', $companyId); $dq->execute(); $dr = $dq->get_result(); while ($dr && ($x = $dr->fetch_assoc())) { $dMap[$x['d']] = (int)$x['n']; } }
$dLabels = []; $dData = [];
for ($i = 29; $i >= 0; $i--) { $t = strtotime("-$i days"); $dLabels[] = date('d M', $t); $dData[] = $dMap[date('Y-m-d', $t)] ?? 0; }

// The list, with the matching contact name when there is one
$rows = [];
$lq = $conn->prepare("SELECT b.id, b.phone, b.reason, b.created_at,
        (SELECT c.name FROM contacts c WHERE c.company_id = b.company_id AND $digits = b.phone LIMIT 1) AS contact_name
    FROM blacklist b WHERE b.company_id = ? ORDER BY b.id DESC LIMIT 200");
if ($lq) { $lq->bind_param('i', $companyId); $lq->execute(); $lr = $lq->get_result(); while ($lr && ($x = $lr->fetch_assoc())) { $rows[] = $x; } }

$pageTitle = 'Opt-out Manager';
$currentPage = 'blacklist';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'ban'    => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'pct'    => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
];
?>

<div class="db bl">

  <div class="db-head">
    <div>
      <h1>Opt-out Manager</h1>
      <p>Numbers on this list never receive campaigns or drip messages. Honouring opt-outs protects your Meta quality rating.</p>
    </div>
    <div class="db-head__actions">
      <a href="contacts.php" class="db-btn"><?php echo $__ic($I['users'], 16); ?> Contacts</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="bl-toast <?php echo $flash[0] ? 'is-ok' : 'is-err'; ?>" role="status"><?php echo $__ic($flash[0] ? $I['check'] : $I['alert'], 18); ?> <span><?php echo $esc($flash[1]); ?></span></div>
  <?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Opt-outs added</h2>
            <div class="db-legend"><span><i style="background:#E5483A"></i>Last 30 days: <b><?php echo number_format(array_sum($dData)); ?></b></span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="blChart" aria-label="Opt-outs added per day" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-pink"><?php echo $__ic($I['ban'], 19); ?></div><div class="db-kpi__l">Blocked numbers</div><div class="db-kpi__v"><?php echo number_format($totalBlocked); ?></div><div class="db-kpi__d">On your opt-out list</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['cal'], 19); ?></div><div class="db-kpi__l">Added this week</div><div class="db-kpi__v"><?php echo number_format($addedWeek); ?></div><div class="db-kpi__d">Last 7 days</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['users'], 19); ?></div><div class="db-kpi__l">Contacts blocked</div><div class="db-kpi__v"><?php echo number_format($matchedContacts); ?></div><div class="db-kpi__d">Matching saved contacts</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($I['pct'], 19); ?></div><div class="db-kpi__l">Share of audience</div><div class="db-kpi__v"><?php echo $esc($blockedPct); ?>%</div><div class="db-kpi__d">Of <?php echo number_format($totalContacts); ?> contacts</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Audience reach</h2><span class="db-pill <?php echo $blockedPct <= 5 ? 'is-ok' : ($blockedPct <= 15 ? 'is-warn' : 'is-bad'); ?>"><?php echo $blockedPct <= 5 ? 'Healthy' : ($blockedPct <= 15 ? 'Watch' : 'High'); ?></span></div>
      <div class="db-donut__chart">
        <canvas id="blDonut" width="200" height="200" aria-label="Reachable vs blocked contacts" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($reachable); ?></b><span>reachable</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Reachable: <?php echo number_format($reachable); ?></span>
        <span><i style="background:#E5483A"></i>Blocked: <?php echo number_format($matchedContacts); ?></span>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Restricted numbers</h2><p><span id="blShown"><?php echo count($rows); ?></span> shown &middot; latest 200</p></div>
        <label class="bl-search"><?php echo $__ic($I['search'], 16); ?><input type="search" id="blSearch" placeholder="Search number or reason" aria-label="Search blocked numbers"></label>
      </div>
      <div class="db-tablewrap">
        <table class="db-table bl-table" id="blTable">
          <thead><tr><th>Number</th><th>Reason</th><th>Added</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr data-q="<?php echo $esc(strtolower($r['phone'] . ' ' . $r['reason'] . ' ' . ($r['contact_name'] ?? ''))); ?>">
              <td><div class="db-who"><span class="db-av bl-av"><?php echo $__ic($I['ban'], 17); ?></span><div><b class="bl-phone">+<?php echo $esc($r['phone']); ?></b><small><?php echo $r['contact_name'] ? $esc($r['contact_name']) : 'Not in your contacts'; ?></small></div></div></td>
              <td><span class="bl-reason"><?php echo $esc($r['reason']); ?></span></td>
              <td class="db-mut" style="white-space:nowrap"><?php echo $esc(date('d M Y', strtotime((string)$r['created_at']))); ?></td>
              <td style="text-align:right">
                <form method="POST" class="bl-rm" data-confirm="Allow this number to receive messages again?">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="remove_id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="bl-remove"><?php echo $__ic($I['trash'], 15); ?> Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="db-empty" id="blEmpty" style="<?php echo $rows ? 'display:none' : ''; ?>">
          <?php if ($rows): ?>No numbers match your search.<?php else: ?>
            <span class="bl-empty-ic"><?php echo $__ic($I['shield'], 30); ?></span><b>No blocked numbers</b><br>Numbers you add to the opt-out list will appear here.
          <?php endif; ?>
        </div>
      </div>
    </section>

    <div class="bl-stack">
      <section class="db-card bl-form">
        <div class="bl-ch"><h2>Add to opt-out list</h2><p>One number per line, with country code.</p></div>
        <form method="POST">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="add_phone" value="1">
          <div class="bl-f"><label for="bl_phone">Phone numbers</label>
            <textarea name="phone" id="bl_phone" rows="4" placeholder="919876543210&#10;919812345678" required autocomplete="off"></textarea>
            <p class="bl-hint">Spaces, + and dashes are ignored. Paste a whole column to add many at once.</p></div>
          <div class="bl-f"><label for="bl_reason">Reason <span>optional</span></label>
            <input type="text" name="reason" id="bl_reason" placeholder="e.g. Customer asked to stop" maxlength="190"></div>
          <button type="submit" class="db-btn db-btn--primary bl-wide"><?php echo $__ic($I['ban'], 16); ?> Block numbers</button>
        </form>
      </section>
      <section class="db-card bl-tip">
        <span class="bl-tip__ic"><?php echo $__ic($I['shield'], 20); ?></span>
        <div><b>Protect your quality rating</b><p>Blocked numbers are skipped by campaigns and drip sequences even if they stay in your contacts. Add anyone who asks to stop, or replies STOP.</p></div>
      </section>
    </div>
  </div>
</div>

<style>
.bl{max-width:none}
.bl-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.bl-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.bl-toast.is-err{background:#FDE4E1;color:#c0372b}.bl-toast svg{margin-top:2px;flex:none}
.bl .db-canvas{height:270px}
.bl .db-donut__chart canvas{max-width:200px;max-height:200px}
.bl-search{display:flex;align-items:center;gap:9px;width:260px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.bl-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.bl-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.bl-table td{border-bottom:0}
.bl-table tbody tr:nth-child(even) td{background:#F8FCFA}
.bl-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}.bl-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.bl-av{background:#FDE4E1!important;color:#c0372b!important}
.bl-phone{font-variant-numeric:tabular-nums}
.bl-reason{display:inline-block;max-width:340px;padding:4px 11px;border-radius:999px;background:var(--soft);font-size:13px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.bl-rm{margin:0}
.bl-remove{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:0;border-radius:9px;background:var(--soft);color:var(--tx);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.bl-remove:hover{background:#FDE4E1;color:#c0372b}
.bl-empty-ic{display:inline-grid;place-items:center;width:64px;height:64px;border-radius:50%;background:#E3F7EE;color:#128C7E;margin-bottom:10px}
.bl-empty-ic+b{color:var(--ink);font-size:16px}
.bl-stack{display:flex;flex-direction:column;gap:18px;min-width:0}
.bl-form{padding:22px}
.bl-ch{margin-bottom:16px}
.bl-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.bl-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.bl-f{margin-bottom:14px}
.bl-f label{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.bl-f label span{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:4px}
.bl-f input,.bl-f textarea{width:100%;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.bl-f input{height:44px}
.bl-f textarea{padding:12px 14px;line-height:1.6;resize:vertical}
.bl-f input:focus,.bl-f textarea:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.bl-hint{margin:6px 0 0;font-size:12.5px;color:var(--mu);line-height:1.5}
.bl-wide{width:100%;justify-content:center}
.bl-tip{display:flex;gap:14px;padding:18px 20px;background:#F3FBF7}
.bl-tip__ic{width:42px;height:42px;border-radius:12px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.bl-tip b{display:block;font-size:15px;color:var(--ink)}
.bl-tip p{margin:4px 0 0;font-size:13px;line-height:1.55;color:var(--tx)}
@media (max-width:1100px){.bl .db-row{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  $('blSearch').addEventListener('input', function () {
    var q = this.value.toLowerCase().trim(), n = 0, rows = document.querySelectorAll('#blTable tbody tr');
    rows.forEach(function (r) { var ok = !q || (r.getAttribute('data-q') || '').indexOf(q) !== -1; r.style.display = ok ? '' : 'none'; if (ok) { n++; } });
    $('blShown').textContent = n; var e = $('blEmpty'); if (rows.length) { e.style.display = n ? 'none' : 'block'; e.textContent = 'No numbers match your search.'; }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('form.bl-rm[data-confirm]');
    if (f && !confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
  if (typeof Chart === 'undefined') { return; }
  var cv = $('blChart'), ctx = cv.getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270); g.addColorStop(0, 'rgba(229,72,58,.28)'); g.addColorStop(1, 'rgba(229,72,58,.02)');
  new Chart(ctx, {type: 'line',
    data: {labels: <?php echo json_encode($dLabels); ?>, datasets: [{label: 'Opt-outs', data: <?php echo json_encode($dData); ?>, borderColor: '#E5483A', backgroundColor: g, borderWidth: 3, fill: true, tension: .38, pointRadius: 0, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderColor: '#E5483A', pointBorderWidth: 2.5}]},
    options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false}},
      scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}}, y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var vals = [<?php echo (int)$reachable; ?>, <?php echo (int)$matchedContacts; ?>], total = vals[0] + vals[1];
  new Chart($('blDonut'), {type: 'doughnut',
    data: {labels: ['Reachable', 'Blocked'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#E5483A'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>
