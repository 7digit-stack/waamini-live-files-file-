<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_wa_health')) {
    header("Location: dashboard.php");
    exit();
}

require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../includes/whatsapp_api_helper.php';

$companyId = (int)($_SESSION['company_id'] ?? 0);
$waConfig = null;
$cst = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id = ? LIMIT 1");
if ($cst) { $cst->bind_param('i', $companyId); $cst->execute(); $waConfig = $cst->get_result()->fetch_assoc() ?: null; }

// Live report from Meta (null when Meta cannot be reached)
$healthData = null;
$connected = $waConfig && !empty($waConfig['access_token']) && !empty($waConfig['phone_number_id']);
if ($connected) {
    $token = decrypt_secret($waConfig['access_token']) ?? $waConfig['access_token'];
    $healthData = get_whatsapp_health($token, $waConfig['phone_number_id']);
}
$isLive = is_array($healthData);

$qr     = strtoupper((string)($healthData['quality_rating'] ?? ($waConfig['quality_rating'] ?? 'UNKNOWN')));
$tier   = strtoupper((string)($healthData['messaging_limit_tier'] ?? 'TIER_UNKNOWN'));
$status = strtoupper((string)($healthData['status'] ?? ($waConfig['status'] ?? 'UNKNOWN')));
if ($status === 'ACTIVE') { $status = 'CONNECTED'; }

// API activity from our own logs (this company only)
$apiOk24 = 0; $apiErr24 = 0;
$aq = $conn->prepare("SELECT COALESCE(SUM(http_code BETWEEN 200 AND 299),0) AS ok, COALESCE(SUM(http_code NOT BETWEEN 200 AND 299),0) AS err FROM whatsapp_api_logs WHERE company_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
if ($aq) { $aq->bind_param('i', $companyId); $aq->execute(); $ar = $aq->get_result()->fetch_assoc(); $apiOk24 = (int)($ar['ok'] ?? 0); $apiErr24 = (int)($ar['err'] ?? 0); }
$dayMap = [];
$dq = $conn->prepare("SELECT DATE(created_at) AS d, SUM(http_code BETWEEN 200 AND 299) AS ok, SUM(http_code NOT BETWEEN 200 AND 299) AS err FROM whatsapp_api_logs WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)");
if ($dq) { $dq->bind_param('i', $companyId); $dq->execute(); $dr = $dq->get_result(); while ($dr && ($x = $dr->fetch_assoc())) { $dayMap[$x['d']] = [(int)$x['ok'], (int)$x['err']]; } }
$dLabels = []; $dOk = []; $dErr = [];
for ($i = 13; $i >= 0; $i--) { $t = strtotime("-$i days"); $k = date('Y-m-d', $t); $dLabels[] = date('d M', $t); $dOk[] = $dayMap[$k][0] ?? 0; $dErr[] = $dayMap[$k][1] ?? 0; }
$calls24 = $apiOk24 + $apiErr24;
$successRate = $calls24 > 0 ? round($apiOk24 / $calls24 * 100) : 100;

$risk = 'LOW'; $riskPill = 'is-ok';
if ($apiErr24 > 50) { $risk = 'CRITICAL'; $riskPill = 'is-bad'; }
elseif ($apiErr24 > 10) { $risk = 'MEDIUM'; $riskPill = 'is-warn'; }

$pageTitle = 'Number Health';
$currentPage = 'wa_health';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'pulse'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'star'   => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'gear'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'off'    => '<path d="M12 20h.01"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/><path d="M5 12.859a10 10 0 0 1 5.17-2.69"/><path d="M19 12.859a10 10 0 0 0-2.007-1.523"/><path d="M2 8.82a15 15 0 0 1 4.177-2.643"/><path d="M22 8.82a15 15 0 0 0-11.288-3.764"/><path d="m2 2 20 20"/>',
    'tag'    => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
];
$tiers = ['TIER_50' => '50', 'TIER_250' => '250', 'TIER_1K' => '1K', 'TIER_10K' => '10K', 'TIER_100K' => '100K', 'TIER_UNLIMITED' => 'Unlimited'];
$tierKeys = array_keys($tiers);
$tierIdx = array_search($tier, $tierKeys, true);
$tierLabel = $tiers[$tier] ?? ($tier === 'TIER_UNKNOWN' ? '—' : str_replace('TIER_', '', $tier));
$qPill = $qr === 'GREEN' ? 'is-ok' : ($qr === 'YELLOW' ? 'is-warn' : ($qr === 'RED' ? 'is-bad' : 'is-off'));
$qIc = $qr === 'GREEN' ? 'is-green' : ($qr === 'YELLOW' ? 'is-amber' : ($qr === 'RED' ? 'is-pink' : 'is-blue'));
$sOk = in_array($status, ['CONNECTED', 'APPROVED'], true);
?>

<div class="db wh">

  <div class="db-head">
    <div>
      <h1>WhatsApp Number Health</h1>
      <p>Quality rating, sending limits and API activity for your Meta WhatsApp Business number.</p>
    </div>
    <div class="db-head__actions">
      <a href="wa_health.php" class="db-btn"><?php echo $__ic($I['refresh'], 16); ?> Refresh</a>
      <a href="settings_whatsapp.php" class="db-btn"><?php echo $__ic($I['gear'], 16); ?> WhatsApp setup</a>
    </div>
  </div>

<?php if (!$connected): ?>
  <section class="db-card wh-empty">
    <span class="wh-empty__ic"><?php echo $__ic($I['off'], 34); ?></span>
    <h2>No connection detected</h2>
    <p>Save your WhatsApp credentials in WhatsApp Setup to see your number's health.</p>
    <a href="settings_whatsapp.php" class="db-btn db-btn--primary"><?php echo $__ic($I['gear'], 16); ?> Go to setup</a>
  </section>

<?php else: ?>

  <?php if (!$isLive): ?>
    <div class="wh-note" role="status"><?php echo $__ic($I['alert'], 18); ?> <span>Could not reach Meta for a live check, so this shows the last synced details. Press Refresh to try again.</span></div>
  <?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>API activity</h2>
            <div class="db-legend"><span><i style="background:#25D366"></i>Successful: <b><?php echo number_format(array_sum($dOk)); ?></b></span><span><i style="background:#E5483A"></i>Errors: <b><?php echo number_format(array_sum($dErr)); ?></b></span><span class="db-mut">Last 14 days</span></div>
          </div>
          <span class="db-pill <?php echo $isLive ? 'is-ok' : 'is-warn'; ?>"><?php echo $isLive ? 'Live from Meta' : 'Last synced'; ?></span>
        </div>
        <div class="db-canvas"><canvas id="whChart" aria-label="API calls per day" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $qIc; ?>"><?php echo $__ic($I['star'], 19); ?></div><div class="db-kpi__l">Quality rating</div><div class="db-kpi__v"><?php echo $esc($qr === 'UNKNOWN' ? '—' : ucfirst(strtolower($qr))); ?></div><div class="db-kpi__d"><span class="db-pill <?php echo $qPill; ?>">Meta standard</span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['layers'], 19); ?></div><div class="db-kpi__l">Messaging tier</div><div class="db-kpi__v"><?php echo $esc($tierLabel); ?></div><div class="db-kpi__d">Customers per 24h</div></div>
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $sOk ? 'is-green' : 'is-pink'; ?>"><?php echo $__ic($I['link'], 19); ?></div><div class="db-kpi__l">Connection</div><div class="db-kpi__v" style="font-size:22px"><?php echo $esc(ucfirst(strtolower($status))); ?></div><div class="db-kpi__d"><?php echo $sOk ? '<span class="db-chip is-up">Healthy</span>' : '<span class="db-chip is-down">Needs attention</span>'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $risk === 'LOW' ? 'is-green' : ($risk === 'MEDIUM' ? 'is-amber' : 'is-pink'); ?>"><?php echo $__ic($I['shield'], 19); ?></div><div class="db-kpi__l">Account risk</div><div class="db-kpi__v" style="font-size:24px"><?php echo $esc(ucfirst(strtolower($risk))); ?></div><div class="db-kpi__d"><?php echo number_format($apiErr24); ?> errors in 24h</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Last 24 hours</h2><span class="db-pill <?php echo $riskPill; ?>"><?php echo $esc(ucfirst(strtolower($risk))); ?> risk</span></div>
      <div class="db-donut__chart">
        <canvas id="whDonut" width="200" height="200" aria-label="API results in the last 24 hours" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo $successRate; ?>%</b><span>success</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Successful: <?php echo number_format($apiOk24); ?></span>
        <span><i style="background:#E5483A"></i>Errors: <?php echo number_format($apiErr24); ?></span>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card wh-card">
      <div class="wh-ch"><h2>Messaging tier</h2><p>How many different customers you can message in 24 hours. Meta raises it automatically.</p></div>
      <div class="wh-ladder">
        <?php foreach ($tiers as $key => $label): $i = array_search($key, $tierKeys, true);
            $cls = $tierIdx === false ? '' : ($i < $tierIdx ? 'is-done' : ($i === $tierIdx ? 'is-now' : '')); ?>
          <div class="wh-step <?php echo $cls; ?>"><span><?php echo $cls === 'is-done' ? '&#10003;' : ($i + 1); ?></span><b><?php echo $esc($label); ?></b><small><?php echo $cls === 'is-now' ? 'Your tier' : ($cls === 'is-done' ? 'Reached' : 'Next'); ?></small></div>
        <?php endforeach; ?>
      </div>
      <div class="wh-guide">
        <b>How to move up a tier</b>
        <ul>
          <li>Keep a <strong>Green</strong> quality rating.</li>
          <li>Send to at least half of your current limit within 7 days.</li>
          <li>Make sure your messages are not reported as spam.</li>
        </ul>
      </div>
    </section>

    <div class="wh-stack">
      <section class="db-card wh-card">
        <div class="wh-ch"><h2>Verified name</h2></div>
        <div class="wh-name">
          <span class="wh-name__av"><?php echo $__ic($I['check'], 22); ?></span>
          <div><b><?php echo $esc($waConfig['verified_name'] ?? 'Not synced'); ?></b><small><?php echo $esc($waConfig['display_phone'] ?? ''); ?></small></div>
        </div>
        <div class="wh-row"><span>Display name status</span><span class="db-pill is-info"><?php echo $esc(strtoupper((string)($waConfig['name_status'] ?? 'N/A'))); ?></span></div>
        <div class="wh-row"><span>Connection</span><span class="db-pill <?php echo $sOk ? 'is-ok' : 'is-bad'; ?>"><?php echo $esc($status); ?></span></div>
      </section>

      <section class="db-card wh-tip">
        <span class="wh-tip__ic"><?php echo $__ic($I['tag'], 20); ?></span>
        <div><b>Keep your rating green</b><p>Keep reply rates high, honour opt-outs quickly, and avoid sending to contacts who have not interacted with you recently.</p>
          <a href="blacklist.php">Open Opt-out Manager</a></div>
      </section>
    </div>
  </div>

<?php endif; ?>
</div>

<style>
.wh{max-width:none}
.wh .db-canvas{height:270px}
.wh .db-donut__chart canvas{max-width:200px;max-height:200px}
.wh-note{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;background:#FEF3DC;color:#7a4608;font-size:14px;font-weight:600;line-height:1.45}
.wh-note svg{margin-top:2px;flex:none}
.wh-empty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:60px 24px}
.wh-empty__ic{width:84px;height:84px;border-radius:50%;background:#EEF2F1;color:#5f6f6a;display:grid;place-items:center;margin-bottom:6px}
.wh-empty h2{margin:0;font-size:22px;color:var(--ink)}
.wh-empty p{margin:0 0 10px;max-width:380px;color:var(--mu);line-height:1.6}
.wh-card{padding:22px}
.wh-ch{margin-bottom:16px}
.wh-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.wh-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu);line-height:1.5}
.wh-ladder{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:20px}
.wh-step{display:flex;flex-direction:column;align-items:center;gap:4px;padding:16px 8px;border-radius:14px;background:var(--soft);text-align:center}
.wh-step span{width:30px;height:30px;border-radius:50%;background:#fff;color:var(--mu);font-weight:800;font-size:13px;display:grid;place-items:center}
.wh-step b{font-size:17px;font-weight:800;color:var(--ink)}
.wh-step small{font-size:12px;color:var(--mu);font-weight:600}
.wh-step.is-done span{background:#DDF7E7;color:#12803f}
.wh-step.is-now{background:#E6FCF2;box-shadow:inset 0 0 0 2px #25D366}
.wh-step.is-now span{background:linear-gradient(135deg,#25D366,#128C7E);color:#fff}
.wh-step.is-now small{color:#12803f}
.wh-guide{padding:16px 18px;border-radius:14px;background:var(--soft);font-size:13.5px;color:var(--tx);line-height:1.6}
.wh-guide b{color:var(--ink);font-size:14.5px}
.wh-guide ul{margin:8px 0 0;padding-left:18px}
.wh-guide li{margin-bottom:4px}
.wh-stack{display:flex;flex-direction:column;gap:18px;min-width:0}
.wh-name{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--soft);margin-bottom:12px}
.wh-name__av{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;flex:none}
.wh-name b{display:block;font-size:16px;color:var(--ink)}
.wh-name small{display:block;margin-top:2px;font-size:13px;color:var(--mu)}
.wh-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:12px;background:var(--soft);margin-top:8px;font-size:14px;color:var(--tx);font-weight:600}
.wh-tip{display:flex;gap:14px;padding:18px 20px;background:#F3FBF7}
.wh-tip__ic{width:42px;height:42px;border-radius:12px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.wh-tip b{display:block;font-size:15px;color:var(--ink)}
.wh-tip p{margin:4px 0 8px;font-size:13px;line-height:1.55;color:var(--tx)}
.wh-tip a{font-size:13px;font-weight:700;color:#128C7E;text-decoration:none}
@media (max-width:1100px){.wh .db-row{grid-template-columns:1fr}}
@media (max-width:700px){.wh-ladder{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>

<?php if ($connected): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') { return; }
  new Chart(document.getElementById('whChart'), {type: 'bar',
    data: {labels: <?php echo json_encode($dLabels); ?>, datasets: [
      {label: 'Successful', data: <?php echo json_encode($dOk); ?>, backgroundColor: '#25D366', borderRadius: 4, stack: 's', maxBarThickness: 26},
      {label: 'Errors', data: <?php echo json_encode($dErr); ?>, backgroundColor: '#E5483A', borderRadius: 4, stack: 's', maxBarThickness: 26}]},
    options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}},
      scales: {x: {stacked: true, grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}},
               y: {stacked: true, beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var vals = [<?php echo (int)$apiOk24; ?>, <?php echo (int)$apiErr24; ?>], total = vals[0] + vals[1];
  new Chart(document.getElementById('whDonut'), {type: 'doughnut',
    data: {labels: ['Successful', 'Errors'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#E5483A'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>
