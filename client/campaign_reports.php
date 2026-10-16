<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_campaign_reports')) {
    header("Location: dashboard.php");
    exit();
}
$companyId = (int)($_SESSION['company_id'] ?? 0);

$g = ['sent' => 0, 'delivered' => 0, 'reads' => 0, 'failed' => 0, 'n' => 0];
$gq = $conn->prepare("SELECT COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(delivered_count),0) AS delivered, COALESCE(SUM(read_count),0) AS reads, COALESCE(SUM(failed_count),0) AS failed, COUNT(*) AS n FROM campaigns WHERE company_id = ?");
if ($gq) { $gq->bind_param('i', $companyId); $gq->execute(); $g = array_map('intval', $gq->get_result()->fetch_assoc() ?: $g); }

$statusCounts = [];
$sq = $conn->prepare("SELECT status, COUNT(*) AS c FROM campaigns WHERE company_id = ? GROUP BY status");
if ($sq) { $sq->bind_param('i', $companyId); $sq->execute(); $sr = $sq->get_result(); while ($sr && ($x = $sr->fetch_assoc())) { $statusCounts[$x['status']] = (int)$x['c']; } }

// Latest 50 campaigns (all statuses, completed first in the default filter)
$rows = [];
$lq = $conn->prepare("SELECT id, name, status, sent_count, delivered_count, read_count, failed_count, total_contacts, created_at FROM campaigns WHERE company_id = ? ORDER BY id DESC LIMIT 50");
if ($lq) { $lq->bind_param('i', $companyId); $lq->execute(); $lr = $lq->get_result(); while ($lr && ($x = $lr->fetch_assoc())) { $rows[] = $x; } }

// Replies per campaign in ONE query
$replies = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $rp = $conn->prepare("SELECT campaign_id, COUNT(*) AS c FROM messages WHERE company_id = ? AND direction = 'incoming' AND campaign_id IN ($ids) GROUP BY campaign_id");
    if ($rp) { $rp->bind_param('i', $companyId); $rp->execute(); $rr = $rp->get_result(); while ($rr && ($x = $rr->fetch_assoc())) { $replies[(int)$x['campaign_id']] = (int)$x['c']; } }
}

// Per-day totals of the last 14 days (by campaign creation date)
$dMap = [];
$dq = $conn->prepare("SELECT DATE(created_at) AS d, COALESCE(SUM(sent_count),0) AS s, COALESCE(SUM(delivered_count),0) AS dl, COALESCE(SUM(read_count),0) AS r FROM campaigns WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)");
if ($dq) { $dq->bind_param('i', $companyId); $dq->execute(); $dr = $dq->get_result(); while ($dr && ($x = $dr->fetch_assoc())) { $dMap[$x['d']] = [(int)$x['s'], (int)$x['dl'], (int)$x['r']]; } }
$dLabels = []; $dS = []; $dD = []; $dR = [];
for ($i = 13; $i >= 0; $i--) { $t = strtotime("-$i days"); $k = date('Y-m-d', $t); $dLabels[] = date('d M', $t); $dS[] = $dMap[$k][0] ?? 0; $dD[] = $dMap[$k][1] ?? 0; $dR[] = $dMap[$k][2] ?? 0; }

$deliveryRate = $g['sent'] > 0 ? round($g['delivered'] / $g['sent'] * 100, 1) : 0;
$readRate = $g['delivered'] > 0 ? round($g['reads'] / $g['delivered'] * 100, 1) : 0;
$failRate = ($g['sent'] + $g['failed']) > 0 ? round($g['failed'] / ($g['sent'] + $g['failed']) * 100, 1) : 0;
$totalReplies = array_sum($replies);
$notRead = max(0, $g['delivered'] - $g['reads']);
$notDelivered = max(0, $g['sent'] - $g['delivered']);

// Best campaign by read rate (needs real volume)
$best = null;
foreach ($rows as $r) {
    if ((int)$r['delivered_count'] >= 5) {
        $rate = $r['read_count'] / $r['delivered_count'];
        if (!$best || $rate > $best['rate']) { $best = ['name' => $r['name'], 'rate' => $rate, 'id' => (int)$r['id']]; }
    }
}

$pageTitle = 'Campaign Reports';
$currentPage = 'campaign_reports';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'x'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'star'   => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
];
$pill = ['completed' => 'is-ok', 'running' => 'is-info', 'scheduled' => 'is-warn', 'draft' => 'is-off', 'failed' => 'is-bad', 'cancelled' => 'is-off'];
$statusOrder = ['completed', 'running', 'scheduled', 'draft', 'failed', 'cancelled'];
?>

<div class="db cr">

  <div class="db-head">
    <div>
      <h1>Campaign Reports</h1>
      <p>How your broadcasts perform: delivery, reads and replies.</p>
    </div>
    <div class="db-head__actions">
      <?php if (has_feature('campaigns')): ?><a href="campaigns.php" class="db-btn"><?php echo $__ic($I['mega'], 16); ?> All campaigns</a><a href="campaign_create.php" class="db-btn db-btn--primary"><?php echo $__ic($I['send'], 16); ?> New campaign</a><?php endif; ?>
    </div>
  </div>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Performance over time</h2>
            <div class="db-legend"><span><i style="background:#F59E0B"></i>Sent</span><span><i style="background:#3B82F6"></i>Delivered</span><span><i style="background:#25D366"></i>Read</span><span class="db-mut">Last 14 days</span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="crChart" aria-label="Sent, delivered and read per day" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['send'], 19); ?></div><div class="db-kpi__l">Sent</div><div class="db-kpi__v"><?php echo number_format($g['sent']); ?></div><div class="db-kpi__d">Across <?php echo number_format($g['n']); ?> campaigns</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['check'], 19); ?></div><div class="db-kpi__l">Delivered</div><div class="db-kpi__v"><?php echo number_format($g['delivered']); ?></div><div class="db-kpi__d"><span class="db-chip <?php echo $deliveryRate >= 90 ? 'is-up' : 'is-down'; ?>"><?php echo $esc($deliveryRate); ?>%</span> of sent</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['eye'], 19); ?></div><div class="db-kpi__l">Read</div><div class="db-kpi__v"><?php echo number_format($g['reads']); ?></div><div class="db-kpi__d"><span class="db-chip is-up"><?php echo $esc($readRate); ?>%</span> of delivered</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-pink"><?php echo $__ic($I['x'], 19); ?></div><div class="db-kpi__l">Failed</div><div class="db-kpi__v"><?php echo number_format($g['failed']); ?></div><div class="db-kpi__d"><span class="db-chip <?php echo $failRate <= 5 ? 'is-up' : 'is-down'; ?>"><?php echo $esc($failRate); ?>%</span> failure rate</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Outcome</h2><span class="db-pill <?php echo $deliveryRate >= 90 ? 'is-ok' : ($deliveryRate >= 70 ? 'is-warn' : 'is-bad'); ?>"><?php echo $esc($deliveryRate); ?>% delivered</span></div>
      <div class="db-donut__chart">
        <canvas id="crDonut" width="200" height="200" aria-label="Campaign outcome" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($g['sent'] + $g['failed']); ?></b><span>messages</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Read: <?php echo number_format($g['reads']); ?></span>
        <span><i style="background:#3B82F6"></i>Delivered, unread: <?php echo number_format($notRead); ?></span>
        <span><i style="background:#F59E0B"></i>Not delivered: <?php echo number_format($notDelivered); ?></span>
        <span><i style="background:#E5483A"></i>Failed: <?php echo number_format($g['failed']); ?></span>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Campaigns</h2><p><span id="crShown"><?php echo count($rows); ?></span> shown &middot; latest 50</p></div>
        <label class="cr-search"><?php echo $__ic($I['search'], 16); ?><input type="search" id="crSearch" placeholder="Search campaigns" aria-label="Search campaigns"></label>
      </div>
      <div class="cr-chips" id="crChips">
        <button type="button" class="is-active" data-s="completed">Completed <em><?php echo $statusCounts['completed'] ?? 0; ?></em></button>
        <?php foreach (array_slice($statusOrder, 1) as $s): if (!empty($statusCounts[$s])): ?><button type="button" data-s="<?php echo $s; ?>"><?php echo ucfirst($s); ?> <em><?php echo $statusCounts[$s]; ?></em></button><?php endif; endforeach; ?>
        <button type="button" data-s="all">All <em><?php echo $g['n']; ?></em></button>
      </div>
      <div class="db-tablewrap">
        <table class="db-table cr-table" id="crTable">
          <thead><tr><th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read rate</th><th>Replies</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $readPct = (int)$r['delivered_count'] > 0 ? round($r['read_count'] / $r['delivered_count'] * 100, 1) : 0;
              $delPct = (int)$r['sent_count'] > 0 ? round($r['delivered_count'] / $r['sent_count'] * 100) : 0;
              $rep = $replies[(int)$r['id']] ?? 0; ?>
            <tr data-s="<?php echo $esc($r['status']); ?>" data-q="<?php echo $esc(strtolower($r['name'])); ?>">
              <td><b><?php echo $esc($r['name']); ?></b><small class="cr-sub"><?php echo $esc(date('d M Y', strtotime((string)$r['created_at']))); ?></small></td>
              <td><b><?php echo number_format((int)$r['sent_count']); ?></b><small class="cr-sub">of <?php echo number_format((int)$r['total_contacts']); ?></small></td>
              <td><b><?php echo number_format((int)$r['delivered_count']); ?></b><small class="cr-sub"><?php echo $delPct; ?>%</small></td>
              <td style="min-width:150px"><div class="cr-meter"><div class="cr-track"><u style="width:<?php echo min(100, $readPct); ?>%"></u></div><span><?php echo $esc($readPct); ?>%</span></div></td>
              <td><b><?php echo number_format($rep); ?></b></td>
              <td><span class="db-pill <?php echo $pill[$r['status']] ?? 'is-off'; ?>"><?php echo $esc(ucfirst((string)$r['status'])); ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="db-empty" id="crEmpty" style="display:none"></div>
      </div>
    </section>

    <div class="cr-stack">
      <section class="db-card cr-card">
        <div class="cr-ch"><h2>Insights</h2></div>
        <div class="cr-ins">
          <div><span>Delivery rate</span><b><?php echo $esc($deliveryRate); ?>%</b></div>
          <div><span>Read rate</span><b><?php echo $esc($readRate); ?>%</b></div>
          <div><span>Replies received</span><b><?php echo number_format($totalReplies); ?></b></div>
          <div><span>Reply rate (of delivered)</span><b><?php echo $g['delivered'] > 0 ? $esc(round($totalReplies / $g['delivered'] * 100, 1)) : 0; ?>%</b></div>
        </div>
      </section>
      <section class="db-card cr-card">
        <div class="cr-ch"><h2>Top campaign</h2><p>Best read rate (5+ delivered)</p></div>
        <?php if ($best): ?>
          <div class="cr-best"><span><?php echo $__ic($I['star'], 20); ?></span><div><b><?php echo $esc($best['name']); ?></b><small><?php echo $esc(round($best['rate'] * 100, 1)); ?>% read rate</small></div></div>
        <?php else: ?>
          <div class="db-empty" style="padding:22px 8px">Send more campaigns to see your best performer here.</div>
        <?php endif; ?>
        <?php if (has_feature('campaigns')): ?><a href="campaign_create.php" class="db-btn cr-wide">Create a campaign <?php echo $__ic($I['arrow'], 15); ?></a><?php endif; ?>
      </section>
    </div>
  </div>
</div>

<style>
.cr{max-width:none}
.cr .db-canvas{height:270px}
.cr .db-donut__chart canvas{max-width:200px;max-height:200px}
.cr-search{display:flex;align-items:center;gap:9px;width:260px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.cr-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cr-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.cr-chips{display:flex;gap:8px;flex-wrap:wrap;padding:4px 20px 8px}
.cr-chips button{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 14px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.cr-chips button em{font-style:normal;font-size:12.5px;padding:1px 8px;border-radius:999px;background:#fff;color:var(--mu)}
.cr-chips button:hover{background:#E6FCF2;color:var(--gd)}
.cr-chips button.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.cr-chips button.is-active em{background:rgba(255,255,255,.22);color:#fff}
.cr-table td{border-bottom:0}
.cr-table tbody tr:nth-child(even) td{background:#F8FCFA}
.cr-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}.cr-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.cr-sub{display:block;margin-top:2px;font-size:12.5px;color:var(--mu);font-weight:500}
.cr-meter{display:flex;align-items:center;gap:10px}
.cr-meter span{font-size:13px;font-weight:800;color:var(--ink);min-width:44px}
.cr-track{flex:1;height:8px;border-radius:99px;background:var(--soft);overflow:hidden;min-width:60px}
.cr-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px;background:linear-gradient(90deg,#25D366,#128C7E)}
.cr-stack{display:flex;flex-direction:column;gap:18px;min-width:0}
.cr-card{padding:22px}
.cr-ch{margin-bottom:14px}
.cr-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.cr-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.cr-ins{display:flex;flex-direction:column;gap:9px}
.cr-ins div{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:14px;color:var(--tx);font-weight:600}
.cr-ins b{color:var(--ink);font-weight:800}
.cr-best{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--soft);margin-bottom:12px}
.cr-best>span{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;flex:none}
.cr-best b{display:block;font-size:15px;color:var(--ink)}
.cr-best small{display:block;margin-top:2px;font-size:13px;color:var(--mu)}
.cr-wide{width:100%;justify-content:center}
@media (max-width:1100px){.cr .db-row{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var status = 'completed';
  function apply() {
    var q = $('crSearch').value.toLowerCase().trim(), n = 0, rows = document.querySelectorAll('#crTable tbody tr');
    rows.forEach(function (r) { var ok = (status === 'all' || r.getAttribute('data-s') === status) && (!q || (r.getAttribute('data-q') || '').indexOf(q) !== -1); r.style.display = ok ? '' : 'none'; if (ok) { n++; } });
    $('crShown').textContent = n; var e = $('crEmpty'); e.style.display = n ? 'none' : 'block'; e.textContent = q ? 'No campaigns match your search.' : 'No ' + (status === 'all' ? '' : status + ' ') + 'campaigns yet.';
  }
  $('crSearch').addEventListener('input', apply);
  $('crChips').addEventListener('click', function (e) { var b = e.target.closest('button[data-s]'); if (!b) { return; } status = b.getAttribute('data-s'); this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); apply(); });
  apply();
  if (typeof Chart === 'undefined') { return; }
  var line = function (label, data, c) { return {label: label, data: data, borderColor: c, backgroundColor: c + '1a', borderWidth: 3, fill: false, tension: .38, pointRadius: 0, pointHoverRadius: 6}; };
  new Chart($('crChart'), {type: 'line', data: {labels: <?php echo json_encode($dLabels); ?>, datasets: [line('Sent', <?php echo json_encode($dS); ?>, '#F59E0B'), line('Delivered', <?php echo json_encode($dD); ?>, '#3B82F6'), line('Read', <?php echo json_encode($dR); ?>, '#25D366')]},
    options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}},
      scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}}, y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var vals = [<?php echo (int)$g['reads']; ?>, <?php echo (int)$notRead; ?>, <?php echo (int)$notDelivered; ?>, <?php echo (int)$g['failed']; ?>], total = vals.reduce(function (a, b) { return a + b; }, 0);
  new Chart($('crDonut'), {type: 'doughnut', data: {labels: ['Read', 'Delivered, unread', 'Not delivered', 'Failed'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#3B82F6', '#F59E0B', '#E5483A'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>
