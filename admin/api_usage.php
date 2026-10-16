<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'API Monitor';
$currentPage = 'api_usage';

// ── Range filter (whitelisted — never interpolated from user input) ──────────
$ranges = [
    '24h' => ['label' => '24 hours', 'sql' => 'INTERVAL 24 HOUR', 'bucket' => 'hour'],
    '7d'  => ['label' => '7 days',   'sql' => 'INTERVAL 7 DAY',   'bucket' => 'day', 'n' => 7],
    '30d' => ['label' => '30 days',  'sql' => 'INTERVAL 30 DAY',  'bucket' => 'day', 'n' => 30],
    '90d' => ['label' => '90 days',  'sql' => 'INTERVAL 90 DAY',  'bucket' => 'day', 'n' => 90],
    'all' => ['label' => 'All time', 'sql' => null,               'bucket' => 'month'],
];
$rk = (string)($_GET['range'] ?? '24h');
if (!isset($ranges[$rk])) { $rk = '24h'; }
$R = $ranges[$rk];
$w = $R['sql'] ? "created_at >= DATE_SUB(NOW(), {$R['sql']})" : '1=1';
$wl = $R['sql'] ? "l.created_at >= DATE_SUB(NOW(), {$R['sql']})" : '1=1';

$rows = static function (mysqli $db, string $sql): array {
    try {
        $r = $db->query($sql); $o = [];
        if ($r) { while ($x = $r->fetch_assoc()) { $o[] = $x; } }
        return $o;
    } catch (Throwable $e) {
        error_log('[WAAMINI api_usage] ' . $e->getMessage());
        return [];
    }
};

$s = $rows($conn, "SELECT
    COUNT(*) AS total_calls,
    COALESCE(SUM(http_code >= 200 AND http_code < 300),0) AS ok_calls,
    COALESCE(SUM(http_code >= 400 AND http_code < 500),0) AS c4,
    COALESCE(SUM(http_code >= 500),0) AS c5,
    COALESCE(SUM(http_code = 429),0) AS rate_limits,
    COALESCE(SUM(curl_error IS NOT NULL AND curl_error <> ''),0) AS net_err,
    COUNT(DISTINCT company_id) AS companies
    FROM whatsapp_api_logs WHERE $w");
$s = $s[0] ?? [];
$totalCalls = (int)($s['total_calls'] ?? 0);
$okCalls    = (int)($s['ok_calls'] ?? 0);
$c4         = (int)($s['c4'] ?? 0);
$c5         = (int)($s['c5'] ?? 0);
$rateLim    = (int)($s['rate_limits'] ?? 0);
$netErr     = (int)($s['net_err'] ?? 0);
$companiesN = (int)($s['companies'] ?? 0);
$errCalls   = $c4 + $c5;
$otherCalls = max(0, $totalCalls - $okCalls - $errCalls);
$errRate    = $totalCalls > 0 ? round($errCalls / $totalCalls * 100, 1) : 0;
$successRate = $totalCalls > 0 ? round($okCalls / $totalCalls * 100, 1) : 0;

$errorBreakdown = $rows($conn, "SELECT http_code, COUNT(*) AS cnt FROM whatsapp_api_logs WHERE http_code >= 400 AND $w GROUP BY http_code ORDER BY cnt DESC LIMIT 8");
$companyUsage = $rows($conn, "SELECT c.name, COUNT(*) AS cnt, SUM(l.http_code >= 400) AS errs
    FROM whatsapp_api_logs l JOIN companies c ON l.company_id = c.id
    WHERE $wl GROUP BY l.company_id, c.name ORDER BY cnt DESC LIMIT 10");
$endpoints = $rows($conn, "SELECT SUBSTRING_INDEX(endpoint,'/',-1) AS ep, direction, COUNT(*) AS cnt, SUM(http_code >= 400) AS errs
    FROM whatsapp_api_logs WHERE $w GROUP BY ep, direction ORDER BY cnt DESC LIMIT 6");
$recentFails = $rows($conn, "SELECT l.created_at, l.http_code, l.response_json, l.curl_error, l.endpoint, c.name AS company
    FROM whatsapp_api_logs l LEFT JOIN companies c ON l.company_id = c.id
    WHERE (l.http_code >= 400 OR l.curl_error <> '') AND $wl ORDER BY l.id DESC LIMIT 8");

// Traffic timeline
$bucketFmt = $R['bucket'] === 'hour' ? '%Y-%m-%d %H' : ($R['bucket'] === 'day' ? '%Y-%m-%d' : '%Y-%m');
$tl = $rows($conn, "SELECT DATE_FORMAT(created_at,'$bucketFmt') AS b, SUM(http_code >= 200 AND http_code < 300) AS ok, SUM(http_code >= 400) AS bad
    FROM whatsapp_api_logs WHERE $w GROUP BY b ORDER BY b");
$tlMap = [];
foreach ($tl as $x) { $tlMap[(string)$x['b']] = [(int)$x['ok'], (int)$x['bad']]; }
$tLabels = []; $tOk = []; $tBad = [];
if ($R['bucket'] === 'hour') {
    for ($i = 23; $i >= 0; $i--) { $t = strtotime("-$i hours"); $k = date('Y-m-d H', $t); $tLabels[] = date('ga', $t); $tOk[] = $tlMap[$k][0] ?? 0; $tBad[] = $tlMap[$k][1] ?? 0; }
} elseif ($R['bucket'] === 'day') {
    for ($i = $R['n'] - 1; $i >= 0; $i--) { $t = strtotime("-$i days"); $k = date('Y-m-d', $t); $tLabels[] = date('d M', $t); $tOk[] = $tlMap[$k][0] ?? 0; $tBad[] = $tlMap[$k][1] ?? 0; }
} else {
    foreach ($tlMap as $k => $v) { $tLabels[] = date('M Y', strtotime($k . '-01')); $tOk[] = $v[0]; $tBad[] = $v[1]; }
}

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'pulse'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'gauge'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'out'    => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
    'in'     => '<path d="M17 7 7 17"/><path d="M17 17H7V7"/>',
    'dash'   => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
];
$meaning = [
    400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found', 408 => 'Timeout',
    413 => 'Payload too large', 422 => 'Unprocessable', 429 => 'Rate limited',
    500 => 'Server error', 502 => 'Bad gateway', 503 => 'Unavailable', 504 => 'Gateway timeout',
];
$pct = static fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0;
$healthPill = $totalCalls === 0 ? ['is-off', 'No traffic'] : ($errRate < 2 ? ['is-ok', 'Healthy'] : ($errRate < 10 ? ['is-warn', 'Degraded'] : ['is-bad', 'Critical']));
$errMax = 1; foreach ($errorBreakdown as $e0) { $errMax = max($errMax, (int)$e0['cnt']); }
$epMax = 1; foreach ($endpoints as $e0) { $epMax = max($epMax, (int)$e0['cnt']); }
$apiMsg = static function (?string $json, ?string $curl): string {
    if ($curl !== null && $curl !== '') { return $curl; }
    $d = $json ? json_decode($json, true) : null;
    if (is_array($d) && isset($d['error']['message'])) { return (string)$d['error']['message']; }
    return 'No error message returned';
};
?>

<div class="db api">

  <div class="db-head">
    <div>
      <h1>API Usage Monitor</h1>
      <p>Real-time tracking of Meta API performance and error rates across all tenants.</p>
    </div>
    <div class="db-head__actions">
      <div class="db-seg" aria-label="Time range">
        <?php foreach ($ranges as $k => $r): ?>
          <a href="api_usage.php?range=<?php echo $k; ?>" class="api-seg<?php echo $k === $rk ? ' is-active' : ''; ?>"><?php echo $esc($k === 'all' ? 'All' : $k); ?></a>
        <?php endforeach; ?>
      </div>
      <a href="api_usage.php?range=<?php echo $esc($rk); ?>" class="db-btn"><?php echo $__ic($P['refresh'], 16); ?> Refresh</a>
    </div>
  </div>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>API traffic</h2>
            <div class="db-legend"><span><i style="background:#25D366"></i>Successful: <?php echo number_format($okCalls); ?></span><span><i style="background:#E5483A"></i>Errors: <?php echo number_format($errCalls); ?></span></div>
          </div>
          <span class="db-pill <?php echo $healthPill[0]; ?>"><?php echo $esc($healthPill[1]); ?> &middot; <?php echo $esc($R['label']); ?></span>
        </div>
        <div class="db-canvas"><canvas id="apiChart" aria-label="API traffic over time" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['pulse'], 19); ?></div><div class="db-kpi__l">Total calls</div><div class="db-kpi__v"><?php echo number_format($totalCalls); ?></div><div class="db-kpi__d"><?php echo number_format($companiesN); ?> active companies</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($P['check'], 19); ?></div><div class="db-kpi__l">Success rate</div><div class="db-kpi__v"><?php echo $esc($successRate); ?>%</div><div class="db-kpi__d"><span class="db-chip is-up"><?php echo number_format($okCalls); ?> ok</span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($P['alert'], 19); ?></div><div class="db-kpi__l">Error rate</div><div class="db-kpi__v"><?php echo $esc($errRate); ?>%</div><div class="db-kpi__d"><span class="db-chip <?php echo $errCalls > 0 ? 'is-down' : 'is-up'; ?>"><?php echo number_format($errCalls); ?> failed</span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($P['gauge'], 19); ?></div><div class="db-kpi__l">Rate limits (429)</div><div class="db-kpi__v"><?php echo number_format($rateLim); ?></div><div class="db-kpi__d"><?php echo number_format($netErr); ?> network errors</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Response classes</h2><a class="db-link" href="api_usage.php?range=<?php echo $esc($rk); ?>"><?php echo $esc($R['label']); ?></a></div>
      <div class="db-donut__chart">
        <canvas id="apiDonut" width="200" height="200" aria-label="Response classes" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($totalCalls); ?></b><span>calls</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>2xx: <?php echo number_format($okCalls); ?></span>
        <span><i style="background:#F59E0B"></i>4xx: <?php echo number_format($c4); ?></span>
        <span><i style="background:#E5483A"></i>5xx: <?php echo number_format($c5); ?></span>
        <?php if ($otherCalls > 0): ?><span><i style="background:#CBD5D1"></i>Other: <?php echo number_format($otherCalls); ?></span><?php endif; ?>
      </div>
    </section>
  </div>

  <div class="db-row db-row--even">
    <!-- error breakdown -->
    <section class="db-card api-card">
      <div class="db-card__head"><div><h2>HTTP error breakdown</h2><p>Most frequent failing status codes</p></div></div>
      <?php if ($errorBreakdown): ?>
        <div class="api-errs">
          <?php foreach ($errorBreakdown as $e):
              $code = (int)$e['http_code']; $cnt = (int)$e['cnt']; ?>
            <div class="api-err">
              <span class="api-code <?php echo $code >= 500 ? 'is-5' : ($code == 429 ? 'is-rl' : 'is-4'); ?>"><?php echo $code; ?></span>
              <div class="api-err__b">
                <div class="api-err__t"><b><?php echo $esc($meaning[$code] ?? 'Client error'); ?></b><span><?php echo number_format($cnt); ?> hits &middot; <?php echo $esc($pct($cnt, $errCalls)); ?>%</span></div>
                <div class="api-track"><u style="width:<?php echo round($cnt / $errMax * 100); ?>%;background:<?php echo $code >= 500 ? '#E5483A' : ($code == 429 ? '#8B5CF6' : '#F59E0B'); ?>"></u></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="api-ok"><span><?php echo $__ic($P['check'], 30); ?></span><b>No errors in this period</b><p>Every API call succeeded. Excellent!</p></div>
      <?php endif; ?>
    </section>

    <!-- top consumers -->
    <section class="db-card db-tablecard">
      <div class="db-card__head"><div><h2>Top API consumers</h2><p>Companies ranked by call volume</p></div></div>
      <div class="db-tablewrap">
        <table class="db-table api-table">
          <thead><tr><th>Company</th><th>Calls</th><th>Errors</th><th>Health</th></tr></thead>
          <tbody>
          <?php if ($companyUsage): foreach ($companyUsage as $c):
              $cnt = (int)$c['cnt']; $er = (int)$c['errs'];
              $health = $cnt > 0 ? round((1 - $er / $cnt) * 100) : 100;
              $hc = $health > 95 ? 'is-ok' : ($health > 80 ? 'is-warn' : 'is-bad'); ?>
            <tr>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$c['name'], 0, 1))); ?></span><div><b><?php echo $esc($c['name']); ?></b></div></div></td>
              <td><b><?php echo number_format($cnt); ?></b></td>
              <td><?php echo $er > 0 ? '<span class="api-bad">' . number_format($er) . '</span>' : '<span class="db-mut">0</span>'; ?></td>
              <td><span class="db-pill <?php echo $hc; ?>"><?php echo $health; ?>%</span></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4"><div class="db-empty">No API calls recorded in this period.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="db-row db-row--even">
    <!-- endpoints -->
    <section class="db-card api-card">
      <div class="db-card__head"><div><h2>Endpoints</h2><p>Where your traffic goes</p></div></div>
      <?php if ($endpoints): ?>
        <div class="api-eps">
          <?php foreach ($endpoints as $ep): $cnt = (int)$ep['cnt']; $er = (int)$ep['errs']; ?>
            <div class="api-ep">
              <span class="api-ep__ic"><?php echo $__ic(($ep['direction'] ?? '') === 'inbound' ? $P['in'] : $P['out'], 16); ?></span>
              <div class="api-ep__b">
                <div class="api-ep__t"><b>/<?php echo $esc($ep['ep'] !== '' ? $ep['ep'] : 'unknown'); ?></b><span><?php echo $esc(ucfirst((string)($ep['direction'] ?? ''))); ?></span></div>
                <div class="api-track"><u style="width:<?php echo round($cnt / $epMax * 100); ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div>
              </div>
              <div class="api-ep__n"><b><?php echo number_format($cnt); ?></b><small><?php echo $er > 0 ? number_format($er) . ' failed' : 'no errors'; ?></small></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="db-empty">No endpoint data for this period.</div>
      <?php endif; ?>
    </section>

    <!-- recent failures -->
    <section class="db-card api-card">
      <div class="db-card__head"><div><h2>Recent failures</h2><p>Latest failed calls with Meta's message</p></div></div>
      <?php if ($recentFails): ?>
        <div class="api-fails">
          <?php foreach ($recentFails as $f): $code = (int)$f['http_code']; ?>
            <article class="api-fail">
              <span class="api-code <?php echo $code >= 500 ? 'is-5' : ($code == 429 ? 'is-rl' : ($code === 0 ? 'is-net' : 'is-4')); ?>"><?php echo $code > 0 ? $code : 'ERR'; ?></span>
              <div class="api-fail__b">
                <p><?php echo $esc($apiMsg($f['response_json'] ?? null, $f['curl_error'] ?? null)); ?></p>
                <small><?php echo $esc($f['company'] ?? 'Unknown company'); ?> &middot; /<?php echo $esc(substr(strrchr('/' . (string)$f['endpoint'], '/'), 1)); ?> &middot; <?php echo $esc(date('d M, H:i', strtotime((string)$f['created_at']))); ?></small>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="api-ok"><span><?php echo $__ic($P['check'], 30); ?></span><b>No failed calls</b><p>Nothing to investigate for this period.</p></div>
      <?php endif; ?>
    </section>
  </div>
</div>

<style>
.api{max-width:none;margin:0}
.api .db-head__actions{align-items:center}
.api-seg{padding:6px 13px;border-radius:7px;font:700 13px Inter,system-ui,sans-serif;color:var(--tx);text-decoration:none}
.api-seg:hover{color:var(--gd)}
.api-seg.is-active{background:#fff;color:var(--gd);box-shadow:0 1px 5px rgba(18,140,126,.2)}
.api .db-canvas{height:270px}
.api .db-donut__chart canvas{max-width:200px;max-height:200px}
.api-card{padding:20px}
.api-card .db-card__head{padding:0 0 14px}
.api-track{height:8px;border-radius:99px;background:var(--soft);overflow:hidden;min-width:0}
.api-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}
.api-code{flex:none;min-width:52px;height:38px;padding:0 10px;border-radius:10px;display:grid;place-items:center;font-size:14px;font-weight:800;font-variant-numeric:tabular-nums}
.api-code.is-4{background:#FEF3DC;color:#92580a}.api-code.is-5{background:#FDE4E1;color:#c0372b}.api-code.is-rl{background:#EFE9FD;color:#6d44d8}.api-code.is-net{background:#EEF2F1;color:#5f6f6a}
.api-errs,.api-eps,.api-fails{display:flex;flex-direction:column;gap:10px}
.api-err,.api-ep,.api-fail{display:flex;align-items:center;gap:14px;padding:13px 15px;border-radius:14px;background:var(--soft)}
.api-err__b,.api-ep__b,.api-fail__b{flex:1;min-width:0}
.api-err__t,.api-ep__t{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:14px}
.api-err__t b,.api-ep__t b{color:var(--ink);font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.api-err__t span,.api-ep__t span{font-size:12.5px;color:var(--mu);white-space:nowrap}
.api-ep__ic{width:38px;height:38px;border-radius:11px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.api-ep__n{text-align:right;flex:none}
.api-ep__n b{display:block;font-size:17px;font-weight:800;color:var(--ink)}
.api-ep__n small{font-size:12px;color:var(--mu)}
.api-fail{align-items:flex-start}
.api-fail__b p{margin:0 0 5px;font-size:14px;line-height:1.5;color:var(--ink);word-break:break-word;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.api-fail__b small{font-size:12.5px;color:var(--mu)}
.api-ok{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:34px 16px}
.api-ok>span{width:68px;height:68px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin-bottom:4px}
.api-ok b{font-size:17px;color:var(--ink)}
.api-ok p{margin:0;font-size:14.5px;color:var(--mu)}
.api-bad{font-weight:800;color:#c0372b}
.api-table td{border-bottom:0}
.api-table tbody tr:nth-child(even) td{background:#F8FCFA}
.api-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}
.api-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
@media (max-width:1100px){.api .db-row{grid-template-columns:1fr}}
@media (max-width:560px){.api .db-head__actions{width:100%}.api-ep{flex-wrap:wrap}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') { return; }
  var cv = document.getElementById('apiChart');
  if (cv) {
    new Chart(cv, {
      type: 'bar',
      data: {labels: <?php echo json_encode($tLabels); ?>, datasets: [
        {label: 'Successful', data: <?php echo json_encode($tOk); ?>, backgroundColor: '#25D366', borderRadius: 4, stack: 's', maxBarThickness: 22},
        {label: 'Errors', data: <?php echo json_encode($tBad); ?>, backgroundColor: '#E5483A', borderRadius: 4, stack: 's', maxBarThickness: 22}
      ]},
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: {mode: 'index', intersect: false},
        plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}},
        scales: {
          x: {stacked: true, grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}},
          y: {stacked: true, beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}
        }
      }
    });
  }
  var dn = document.getElementById('apiDonut');
  if (dn) {
    var vals = [<?php echo (int)$okCalls; ?>, <?php echo (int)$c4; ?>, <?php echo (int)$c5; ?>, <?php echo (int)$otherCalls; ?>];
    var total = vals.reduce(function (a, b) { return a + b; }, 0);
    new Chart(dn, {type: 'doughnut',
      data: {labels: ['2xx', '4xx', '5xx', 'Other'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#F59E0B', '#E5483A', '#CBD5D1'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
      options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
  }
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
