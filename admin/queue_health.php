<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Queue Monitor';
$currentPage = 'queue_health';

/**
 * Snapshot of both queues. Used for the first paint and for the live JSON refresh,
 * so the page works without exposing any cron/secret key to the browser.
 */
function qm_snapshot(mysqli $db): array
{
    $aq = ['pending' => 0, 'retrying' => 0, 'processing' => 0, 'failed' => 0, 'sent' => 0, 'oldest' => null, 'ok' => false];
    $sj = ['pending' => 0, 'retrying' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0, 'oldest' => null, 'ok' => false];
    try {
        $r = $db->query("SELECT COALESCE(SUM(status='pending'),0) p, COALESCE(SUM(status='retrying'),0) r, COALESCE(SUM(status='processing'),0) pr,
            COALESCE(SUM(status='failed'),0) f, COALESCE(SUM(status='sent'),0) s, MIN(created_at) o FROM automation_queue");
        if ($r && ($x = $r->fetch_assoc())) {
            $aq = ['pending' => (int)$x['p'], 'retrying' => (int)$x['r'], 'processing' => (int)$x['pr'], 'failed' => (int)$x['f'], 'sent' => (int)$x['s'], 'oldest' => $x['o'], 'ok' => true];
        }
    } catch (Throwable $e) { error_log('[WAAMINI queue_monitor] aq: ' . $e->getMessage()); }
    try {
        $chk = $db->query("SHOW TABLES LIKE 'queue_jobs'");
        if ($chk && $chk->num_rows > 0) {
            $r = $db->query("SELECT COALESCE(SUM(status='pending'),0) p, COALESCE(SUM(status='retrying'),0) r, COALESCE(SUM(status='processing'),0) pr,
                COALESCE(SUM(status='failed'),0) f, COALESCE(SUM(status='done'),0) d, MIN(created_at) o FROM queue_jobs");
            if ($r && ($x = $r->fetch_assoc())) {
                $sj = ['pending' => (int)$x['p'], 'retrying' => (int)$x['r'], 'processing' => (int)$x['pr'], 'failed' => (int)$x['f'], 'done' => (int)$x['d'], 'oldest' => $x['o'], 'ok' => true];
            }
        }
    } catch (Throwable $e) { error_log('[WAAMINI queue_monitor] sj: ' . $e->getMessage()); }
    $oldest = null;
    foreach ([$aq['oldest'], $sj['oldest']] as $o) { if ($o && ($oldest === null || $o < $oldest)) { $oldest = $o; } }
    return ['ts' => date('H:i:s'), 'aq' => $aq, 'sj' => $sj, 'oldest' => $oldest];
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + qm_snapshot($conn));
    exit;
}

$snap = qm_snapshot($conn);
$aq = $snap['aq']; $sj = $snap['sj'];
$recentFailed = [];
try {
    $r = $conn->query("(SELECT id, 'System job' AS src, type, attempts, max_attempts, last_error AS err, COALESCE(updated_at, created_at) AS ts FROM queue_jobs WHERE status='failed')
        UNION ALL
        (SELECT id, 'Automation' AS src, type, retry_count AS attempts, NULL AS max_attempts, COALESCE(NULLIF(last_error,''), error_msg) AS err, COALESCE(updated_at, created_at) AS ts FROM automation_queue WHERE status='failed')
        ORDER BY ts DESC LIMIT 8");
    if ($r) { while ($x = $r->fetch_assoc()) { $recentFailed[] = $x; } }
} catch (Throwable $e) { error_log('[WAAMINI queue_monitor] failed list: ' . $e->getMessage()); }

$pending    = $aq['pending'] + $sj['pending'];
$processing = $aq['processing'] + $sj['processing'];
$retrying   = $aq['retrying'] + $sj['retrying'];
$failed     = $aq['failed'] + $sj['failed'];
$done       = $aq['sent'] + $sj['done'];
$grand      = max(1, $pending + $processing + $retrying + $failed + $done);
$state = $failed > 0 ? ['is-bad', 'Needs action'] : ($pending > 100 ? ['is-warn', 'Backlog building'] : ['is-ok', 'Healthy']);

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'gear'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'x'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'db'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
    'pulse'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'pulsepg'=> '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
];
$stCols = ['pending' => '#3B82F6', 'processing' => '#8B5CF6', 'retrying' => '#F59E0B', 'failed' => '#E5483A', 'done' => '#25D366'];
?>

<div class="db qm">

  <div class="db-head">
    <div>
      <h1>Queue Monitor</h1>
      <p>Real-time pulse of automation and message delivery queues.</p>
    </div>
    <div class="db-head__actions">
      <span class="qm-live" id="qmLive"><i></i><span id="qmLiveTxt">Live &middot; <?php echo $esc($snap['ts']); ?></span></span>
      <label class="qm-auto" title="Refresh every 5 seconds"><input type="checkbox" id="qmAuto" checked><i></i><span>Auto-refresh</span></label>
      <button type="button" class="db-btn" id="qmRefresh"><?php echo $__ic($P['refresh'], 16); ?> Refresh now</button>
      <a href="pulse.php" class="db-btn db-btn--primary"><?php echo $__ic($P['pulsepg'], 16); ?> Open Pulse</a>
    </div>
  </div>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Live backlog</h2>
            <div class="db-legend"><span><i style="background:#3B82F6"></i>Pending</span><span><i style="background:#8B5CF6"></i>Processing</span><span><i style="background:#E5483A"></i>Failed</span></div>
          </div>
          <span class="db-pill <?php echo $state[0]; ?>" id="qmState"><?php echo $esc($state[1]); ?></span>
        </div>
        <div class="db-canvas"><canvas id="qmChart" aria-label="Live queue backlog" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($P['layers'], 19); ?></div><div class="db-kpi__l">Pending work</div><div class="db-kpi__v" id="kPending"><?php echo number_format($pending); ?></div><div class="db-kpi__d">Waiting to be picked up</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['gear'], 19); ?></div><div class="db-kpi__l">Processing</div><div class="db-kpi__v" id="kProc"><?php echo number_format($processing); ?></div><div class="db-kpi__d"><span id="kRetry"><?php echo number_format($retrying); ?></span> retrying</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-pink"><?php echo $__ic($P['x'], 19); ?></div><div class="db-kpi__l">Failed (needs action)</div><div class="db-kpi__v" id="kFail"><?php echo number_format($failed); ?></div><div class="db-kpi__d" id="kFailChip"><span class="db-chip <?php echo $failed > 0 ? 'is-down' : 'is-up'; ?>"><?php echo $failed > 0 ? 'Review below' : 'All clear'; ?></span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($P['check'], 19); ?></div><div class="db-kpi__l">Successful</div><div class="db-kpi__v" id="kDone"><?php echo number_format($done); ?></div><div class="db-kpi__d">Sent / completed</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Queue status</h2><span class="db-live"><i></i> Live</span></div>
      <div class="db-donut__chart">
        <canvas id="qmDonut" width="200" height="200" aria-label="Queue status split" role="img"></canvas>
        <div class="db-donut__center"><div><b id="qmTotal"><?php echo number_format($pending + $processing + $retrying + $failed + $done); ?></b><span>total jobs</span></div></div>
      </div>
      <div class="db-dleg">
        <?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'retrying' => 'Retrying', 'failed' => 'Failed', 'done' => 'Done'] as $k => $l): ?>
          <span><i style="background:<?php echo $stCols[$k]; ?>"></i><?php echo $l; ?></span>
        <?php endforeach; ?>
      </div>
      <div class="qm-old"><span><?php echo $__ic($P['clock'], 15); ?> Oldest item</span><b id="qmOldest"><?php echo $snap['oldest'] ? $esc(date('d M, H:i', strtotime((string)$snap['oldest']))) : 'None'; ?></b></div>
    </section>
  </div>

  <div class="db-row db-row--even">
    <?php
    $panels = [
        ['id' => 'aq', 'title' => 'Automation queue', 'sub' => 'Drip, reminders and automation sends', 'icon' => $P['bolt'], 'd' => $aq, 'doneKey' => 'sent', 'doneLbl' => 'Sent'],
        ['id' => 'sj', 'title' => 'System jobs', 'sub' => 'Background worker queue', 'icon' => $P['db'], 'd' => $sj, 'doneKey' => 'done', 'doneLbl' => 'Done'],
    ];
    foreach ($panels as $pn):
        $d = $pn['d'];
        $rowsDef = [['pending', 'Pending', $d['pending']], ['processing', 'Processing', $d['processing']], ['retrying', 'Retrying', $d['retrying']], ['failed', 'Failed', $d['failed']], ['done', $pn['doneLbl'], $d[$pn['doneKey']]]];
        $ptot = max(1, array_sum(array_column($rowsDef, 2)));
    ?>
    <section class="db-card qm-card" data-panel="<?php echo $pn['id']; ?>">
      <div class="qm-card__hd">
        <span class="qm-card__ic"><?php echo $__ic($pn['icon'], 20); ?></span>
        <div><h2><?php echo $esc($pn['title']); ?></h2><p><?php echo $esc($pn['sub']); ?></p></div>
        <?php if (!$d['ok']): ?><span class="db-pill is-off">Unavailable</span><?php endif; ?>
      </div>
      <div class="qm-rows">
        <?php foreach ($rowsDef as [$k, $lbl, $val]): ?>
          <div class="qm-r" data-k="<?php echo $k; ?>">
            <span><i style="background:<?php echo $stCols[$k]; ?>"></i><?php echo $esc($lbl); ?></span>
            <div class="qm-track"><u style="width:<?php echo round($val / $ptot * 100); ?>%;background:<?php echo $stCols[$k]; ?>"></u></div>
            <b><?php echo number_format($val); ?></b>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <section class="db-card db-tablecard">
    <div class="db-card__head">
      <div><h2>Recent failed jobs</h2><p>Latest jobs from both queues that could not be completed</p></div>
      <span class="db-pill <?php echo $failed > 0 ? 'is-bad' : 'is-ok'; ?>"><?php echo number_format($failed); ?> failed</span>
    </div>
    <div class="db-tablewrap">
      <table class="db-table qm-table">
        <thead><tr><th>Job</th><th>Queue</th><th>Type</th><th>Attempts</th><th>Last error</th><th>When</th></tr></thead>
        <tbody>
        <?php if ($recentFailed): foreach ($recentFailed as $f): ?>
          <tr>
            <td><b>#<?php echo (int)$f['id']; ?></b></td>
            <td><span class="db-pill <?php echo $f['src'] === 'Automation' ? 'is-ok' : 'is-info'; ?>"><?php echo $esc($f['src']); ?></span></td>
            <td><?php echo $esc($f['type'] !== null && $f['type'] !== '' ? $f['type'] : '—'); ?></td>
            <td><?php echo (int)$f['attempts']; ?><?php echo $f['max_attempts'] !== null ? ' / ' . (int)$f['max_attempts'] : ''; ?></td>
            <td class="qm-err"><?php echo $esc($f['err'] !== null && $f['err'] !== '' ? $f['err'] : '—'); ?></td>
            <td class="db-mut" style="white-space:nowrap"><?php echo $esc(date('d M, H:i', strtotime((string)$f['ts']))); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6"><div class="db-empty">No failed jobs. The queue is running smoothly.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<style>
.qm{max-width:none;margin:0}
.qm .db-head__actions{align-items:center}
.qm-live{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 14px;border-radius:11px;background:#fff;font-size:13.5px;font-weight:700;color:var(--gd)}
.qm-live i{width:9px;height:9px;border-radius:50%;background:#25D366;box-shadow:0 0 0 4px rgba(37,211,102,.22);animation:qmBeat 1.8s infinite}
.qm-live.is-off i{background:#C7D1CD;box-shadow:none;animation:none}
.qm-live.is-err i{background:#E5483A;box-shadow:0 0 0 4px rgba(229,72,58,.2)}
@keyframes qmBeat{50%{transform:scale(1.35)}}
.qm-auto{display:inline-flex;align-items:center;gap:9px;height:42px;padding:0 14px;border-radius:11px;background:#fff;font-size:13.5px;font-weight:700;color:var(--tx);cursor:pointer}
.qm-auto input{position:absolute;opacity:0;pointer-events:none}
.qm-auto i{position:relative;width:34px;height:20px;border-radius:99px;background:#C7D1CD;transition:background .2s;flex:none}
.qm-auto i::after{content:"";position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.qm-auto input:checked+i{background:linear-gradient(135deg,#25D366,#128C7E)}
.qm-auto input:checked+i::after{transform:translateX(14px)}
.qm .db-canvas{height:270px}
.qm .db-donut__chart canvas{max-width:200px;max-height:200px}
.qm-old{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px;padding:11px 14px;border-radius:12px;background:var(--soft);font-size:13.5px}
.qm-old span{display:inline-flex;align-items:center;gap:8px;color:var(--tx);font-weight:600}
.qm-old span svg{color:var(--g)}
.qm-old b{color:var(--ink);font-weight:800}
.qm-card{padding:20px}
.qm-card__hd{display:flex;align-items:center;gap:13px;margin-bottom:16px}
.qm-card__hd>div{flex:1;min-width:0}
.qm-card__hd h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.qm-card__hd p{margin:2px 0 0;font-size:13.5px;color:var(--mu)}
.qm-card__ic{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;flex:none;box-shadow:0 10px 20px -10px rgba(18,140,126,.8)}
.qm-rows{display:flex;flex-direction:column;gap:10px}
.qm-r{display:grid;grid-template-columns:116px minmax(0,1fr) 54px;align-items:center;gap:14px;padding:12px 15px;border-radius:13px;background:var(--soft);font-size:14px;color:var(--tx)}
.qm-r span{display:inline-flex;align-items:center;gap:8px;font-weight:600}
.qm-r span i{width:10px;height:10px;border-radius:3px;display:inline-block}
.qm-r b{text-align:right;font-size:16px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.qm-r[data-k="failed"] b.has{color:#c0372b}
.qm-track{height:8px;border-radius:99px;background:#fff;overflow:hidden;min-width:0}
.qm-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px;transition:width .5s ease}
.qm-table td{border-bottom:0}
.qm-table tbody tr:nth-child(even) td{background:#F8FCFA}
.qm-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}
.qm-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.qm-err{max-width:420px;word-break:break-word;font-size:13.5px;color:var(--tx)}
@media (max-width:1100px){.qm .db-row{grid-template-columns:1fr}}
@media (max-width:560px){.qm .db-head__actions{width:100%}.qm-r{grid-template-columns:96px minmax(0,1fr) 44px}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var snap = <?php echo json_encode($snap, JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  var COLS = <?php echo json_encode(array_values($stCols)); ?>;
  var $ = function (id) { return document.getElementById(id); };
  var fmt = function (n) { return Number(n || 0).toLocaleString(); };
  var MAXPTS = 24, labels = [], sPend = [], sProc = [], sFail = [];
  var line = null, donut = null;

  function totals(s) {
    var a = s.aq || {}, j = s.sj || {};
    return {
      pending: (a.pending | 0) + (j.pending | 0), processing: (a.processing | 0) + (j.processing | 0),
      retrying: (a.retrying | 0) + (j.retrying | 0), failed: (a.failed | 0) + (j.failed | 0),
      done: (a.sent | 0) + (j.done | 0)
    };
  }
  function fmtDate(d) {
    if (!d) { return 'None'; }
    var t = new Date(String(d).replace(' ', 'T'));
    if (isNaN(t)) { return d; }
    return t.toLocaleString('en-GB', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'});
  }
  function panel(id, d, doneKey) {
    var box = document.querySelector('[data-panel="' + id + '"]'); if (!box || !d) { return; }
    var vals = {pending: d.pending | 0, processing: d.processing | 0, retrying: d.retrying | 0, failed: d.failed | 0, done: d[doneKey] | 0};
    var sum = Math.max(1, vals.pending + vals.processing + vals.retrying + vals.failed + vals.done);
    Object.keys(vals).forEach(function (k) {
      var r = box.querySelector('.qm-r[data-k="' + k + '"]'); if (!r) { return; }
      r.querySelector('u').style.width = Math.round(vals[k] / sum * 100) + '%';
      var b = r.querySelector('b'); b.textContent = fmt(vals[k]);
      if (k === 'failed') { b.classList.toggle('has', vals[k] > 0); }
    });
  }
  function apply(s, push) {
    snap = s; var t = totals(s);
    $('kPending').textContent = fmt(t.pending); $('kProc').textContent = fmt(t.processing);
    $('kRetry').textContent = fmt(t.retrying); $('kFail').textContent = fmt(t.failed); $('kDone').textContent = fmt(t.done);
    $('kFailChip').innerHTML = '<span class="db-chip ' + (t.failed > 0 ? 'is-down">Review below' : 'is-up">All clear') + '</span>';
    $('qmTotal').textContent = fmt(t.pending + t.processing + t.retrying + t.failed + t.done);
    $('qmOldest').textContent = fmtDate(s.oldest);
    var st = $('qmState'), bad = t.failed > 0, warn = !bad && t.pending > 100;
    st.className = 'db-pill ' + (bad ? 'is-bad' : (warn ? 'is-warn' : 'is-ok'));
    st.textContent = bad ? 'Needs action' : (warn ? 'Backlog building' : 'Healthy');
    panel('aq', s.aq, 'sent'); panel('sj', s.sj, 'done');
    if (donut) { donut.data.datasets[0].data = [t.pending, t.processing, t.retrying, t.failed, t.done]; donut.update('none'); }
    if (push && line) {
      labels.push(s.ts || ''); sPend.push(t.pending); sProc.push(t.processing); sFail.push(t.failed);
      if (labels.length > MAXPTS) { labels.shift(); sPend.shift(); sProc.shift(); sFail.shift(); }
      line.update('none');
    }
  }
  function live(state, txt) { var el = $('qmLive'); el.className = 'qm-live' + (state ? ' ' + state : ''); $('qmLiveTxt').textContent = txt; }

  var busy = false;
  function tick() {
    if (busy) { return; } busy = true;
    fetch('queue_health.php?json=1', {credentials: 'same-origin', cache: 'no-store'})
      .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
      .then(function (j) { if (!j.ok) { throw new Error('bad'); } apply(j, true); live('', 'Live · ' + j.ts); })
      .catch(function () { live('is-err', 'Connection lost'); })
      .then(function () { busy = false; });
  }

  if (typeof Chart !== 'undefined') {
    var base = {borderWidth: 2.5, tension: .38, pointRadius: 0, pointHoverRadius: 5, fill: false};
    line = new Chart($('qmChart'), {
      type: 'line',
      data: {labels: labels, datasets: [
        Object.assign({label: 'Pending', data: sPend, borderColor: '#3B82F6'}, base),
        Object.assign({label: 'Processing', data: sProc, borderColor: '#8B5CF6'}, base),
        Object.assign({label: 'Failed', data: sFail, borderColor: '#E5483A'}, base)
      ]},
      options: {
        responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
        plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}},
        scales: {
          x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 6}},
          y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}
        }
      }
    });
    donut = new Chart($('qmDonut'), {type: 'doughnut',
      data: {labels: ['Pending', 'Processing', 'Retrying', 'Failed', 'Done'], datasets: [{data: [0, 0, 0, 0, 0], backgroundColor: COLS, borderWidth: 0, hoverOffset: 4}]},
      options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
  }
  apply(snap, true);

  var timer = setInterval(tick, 5000);
  $('qmAuto').addEventListener('change', function () {
    clearInterval(timer);
    if (this.checked) { timer = setInterval(tick, 5000); tick(); } else { live('is-off', 'Paused'); }
  });
  $('qmRefresh').addEventListener('click', tick);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && $('qmAuto').checked) { tick(); } });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
