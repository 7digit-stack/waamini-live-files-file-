<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Audit Trail';
$currentPage = 'audit_log';

// ── Filters (whitelisted, never interpolated from user input) ─────────────────
$ranges = [
    '24h' => ['label' => '24 hours', 'sql' => 'INTERVAL 24 HOUR', 'bucket' => 'hour'],
    '7d'  => ['label' => '7 days',   'sql' => 'INTERVAL 7 DAY',   'bucket' => 'day', 'n' => 7],
    '30d' => ['label' => '30 days',  'sql' => 'INTERVAL 30 DAY',  'bucket' => 'day', 'n' => 30],
    'all' => ['label' => 'All time', 'sql' => null,               'bucket' => 'month'],
];
$rk = (string)($_GET['range'] ?? '30d');
if (!isset($ranges[$rk])) { $rk = '30d'; }
$R = $ranges[$rk];
$w  = $R['sql'] ? "created_at >= DATE_SUB(NOW(), {$R['sql']})" : '1=1';
$wl = $R['sql'] ? "l.created_at >= DATE_SUB(NOW(), {$R['sql']})" : '1=1';

$cats = [
    'create' => ['Created', '#25D366', 'is-ok'],
    'update' => ['Updated', '#F59E0B', 'is-warn'],
    'delete' => ['Deleted / failed', '#E5483A', 'is-bad'],
    'auth'   => ['Sign-ins', '#3B82F6', 'is-info'],
    'other'  => ['Other', '#9AA9A4', 'is-off'],
];
$catOf = static function (string $a): string {
    $a = strtoupper($a);
    foreach (['DELETE', 'REMOVE', 'FAIL', 'DENY', 'DENIED', 'BLOCK', 'REJECT', 'SUSPEND', 'CANCEL'] as $k) { if (strpos($a, $k) !== false) { return 'delete'; } }
    foreach (['LOGIN', 'LOGOUT', 'AUTH', 'SIGNIN', 'SIGN_IN', 'PASSWORD', 'OTP'] as $k) { if (strpos($a, $k) !== false) { return 'auth'; } }
    foreach (['CREATE', 'ADD', 'REGISTER', 'INSERT', 'UPLOAD', 'SEND', 'IMPORT'] as $k) { if (strpos($a, $k) !== false) { return 'create'; } }
    foreach (['UPDATE', 'EDIT', 'CHANGE', 'SAVE', 'MODIFY', 'SET', 'ENABLE', 'DISABLE', 'APPROVE'] as $k) { if (strpos($a, $k) !== false) { return 'update'; } }
    return 'other';
};
$catKey = (string)($_GET['cat'] ?? 'all');
if ($catKey !== 'all' && !isset($cats[$catKey])) { $catKey = 'all'; }

$rows = static function (mysqli $db, string $sql): array {
    try {
        $r = $db->query($sql); $o = [];
        if ($r) { while ($x = $r->fetch_assoc()) { $o[] = $x; } }
        return $o;
    } catch (Throwable $e) {
        error_log('[WAAMINI audit_log] ' . $e->getMessage());
        return [];
    }
};

// Aggregates for the whole range
$tot = $rows($conn, "SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS u, COUNT(DISTINCT company_id) AS c, COUNT(DISTINCT ip_address) AS ips FROM audit_log WHERE $w");
$totalEvents = (int)($tot[0]['n'] ?? 0);
$uniqueUsers = (int)($tot[0]['u'] ?? 0);
$uniqueCos   = (int)($tot[0]['c'] ?? 0);
$uniqueIps   = (int)($tot[0]['ips'] ?? 0);
$catCounts = array_fill_keys(array_keys($cats), 0);
$actionTop = [];
foreach ($rows($conn, "SELECT action, COUNT(*) AS n FROM audit_log WHERE $w GROUP BY action ORDER BY n DESC") as $x) {
    $catCounts[$catOf((string)$x['action'])] += (int)$x['n'];
    if (count($actionTop) < 6) { $actionTop[] = ['a' => strtoupper(trim((string)$x['action'])), 'n' => (int)$x['n']]; }
}
$topUsers = $rows($conn, "SELECT COALESCE(u.name,'System') AS name, COUNT(*) AS n FROM audit_log l LEFT JOIN users u ON l.user_id = u.id WHERE $wl GROUP BY l.user_id, u.name ORDER BY n DESC LIMIT 5");

// Timeline
$bucketFmt = $R['bucket'] === 'hour' ? '%Y-%m-%d %H' : ($R['bucket'] === 'day' ? '%Y-%m-%d' : '%Y-%m');
$tlMap = [];
foreach ($rows($conn, "SELECT DATE_FORMAT(created_at,'$bucketFmt') AS b, COUNT(*) AS n FROM audit_log WHERE $w GROUP BY b ORDER BY b") as $x) { $tlMap[(string)$x['b']] = (int)$x['n']; }
$tLabels = []; $tData = [];
if ($R['bucket'] === 'hour') {
    for ($i = 23; $i >= 0; $i--) { $t = strtotime("-$i hours"); $tLabels[] = date('ga', $t); $tData[] = $tlMap[date('Y-m-d H', $t)] ?? 0; }
} elseif ($R['bucket'] === 'day') {
    for ($i = $R['n'] - 1; $i >= 0; $i--) { $t = strtotime("-$i days"); $tLabels[] = date('d M', $t); $tData[] = $tlMap[date('Y-m-d', $t)] ?? 0; }
} else {
    foreach ($tlMap as $k => $v) { $tLabels[] = date('M Y', strtotime($k . '-01')); $tData[] = $v; }
}

// Event list (latest 300 in range → category filter → show 100)
$all = $rows($conn, "SELECT l.*, u.name AS user_name, c.name AS company_name
    FROM audit_log l LEFT JOIN users u ON l.user_id = u.id LEFT JOIN companies c ON l.company_id = c.id
    WHERE $wl ORDER BY l.id DESC LIMIT 300");
$events = [];
foreach ($all as $l) {
    $l['_cat'] = $catOf((string)($l['action'] ?? ''));
    if ($catKey === 'all' || $l['_cat'] === $catKey) { $events[] = $l; }
}
$events = array_slice($events, 0, 100);

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'list'   => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'build'  => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
];
$q = static fn(array $over) => http_build_query(array_filter(array_merge(['range' => $rk, 'cat' => $catKey === 'all' ? null : $catKey], $over), static fn($v) => $v !== null));
$ago = static function (string $ts): string {
    $s = max(0, time() - strtotime($ts));
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' h ago'; }
    if ($s < 86400 * 30) { return floor($s / 86400) . ' d ago'; }
    return date('d M Y', strtotime($ts));
};
$catTotal = array_sum($catCounts);
$maxAct = 1; foreach ($actionTop as $a0) { $maxAct = max($maxAct, $a0['n']); }
$maxUsr = 1; foreach ($topUsers as $u0) { $maxUsr = max($maxUsr, (int)$u0['n']); }
?>

<div class="db au">

  <div class="db-head">
    <div>
      <h1>Audit Trail</h1>
      <p>Real-time audit trail of all significant platform events.</p>
    </div>
    <div class="db-head__actions">
      <div class="db-seg" aria-label="Time range">
        <?php foreach ($ranges as $k => $r): ?>
          <a href="audit_log.php?<?php echo $esc($q(['range' => $k])); ?>" class="au-seg<?php echo $k === $rk ? ' is-active' : ''; ?>"><?php echo $esc($k === 'all' ? 'All' : $k); ?></a>
        <?php endforeach; ?>
      </div>
      <a href="audit_log.php?<?php echo $esc($q([])); ?>" class="db-btn"><?php echo $__ic($P['refresh'], 16); ?> Refresh</a>
    </div>
  </div>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Activity over time</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Events: <?php echo number_format($totalEvents); ?></span><span class="db-mut">Last <?php echo $esc(strtolower($R['label'])); ?></span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="auChart" aria-label="Audit events over time" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($P['list'], 19); ?></div><div class="db-kpi__l">Total events</div><div class="db-kpi__v"><?php echo number_format($totalEvents); ?></div><div class="db-kpi__d"><?php echo $esc($R['label']); ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($P['users'], 19); ?></div><div class="db-kpi__l">Active users</div><div class="db-kpi__v"><?php echo number_format($uniqueUsers); ?></div><div class="db-kpi__d">Distinct actors</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($P['build'], 19); ?></div><div class="db-kpi__l">Companies touched</div><div class="db-kpi__v"><?php echo number_format($uniqueCos); ?></div><div class="db-kpi__d">With activity</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['globe'], 19); ?></div><div class="db-kpi__l">Unique IPs</div><div class="db-kpi__v"><?php echo number_format($uniqueIps); ?></div><div class="db-kpi__d"><span class="db-chip <?php echo $catCounts['delete'] > 0 ? 'is-down' : 'is-up'; ?>"><?php echo number_format($catCounts['delete']); ?> risky</span></div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Action mix</h2><span class="db-pill is-off"><?php echo $esc($R['label']); ?></span></div>
      <div class="db-donut__chart">
        <canvas id="auDonut" width="200" height="200" aria-label="Action categories" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($catTotal); ?></b><span>events</span></div></div>
      </div>
      <div class="db-dleg">
        <?php foreach ($cats as $k => $c): if ($catCounts[$k] > 0): ?>
          <span><i style="background:<?php echo $c[1]; ?>"></i><?php echo $esc($c[0]); ?>: <?php echo number_format($catCounts[$k]); ?></span>
        <?php endif; endforeach; ?>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <!-- event log -->
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Event log</h2><p><span id="auShown"><?php echo count($events); ?></span> events shown &middot; newest first</p></div>
        <label class="au-search"><?php echo $__ic($P['search'], 16); ?><input type="search" id="auditSearch" placeholder="Search action, user, IP" aria-label="Search events"></label>
      </div>
      <div class="au-chips">
        <a href="audit_log.php?<?php echo $esc($q(['cat' => null])); ?>" class="au-chip<?php echo $catKey === 'all' ? ' is-active' : ''; ?>">All <em><?php echo number_format($catTotal); ?></em></a>
        <?php foreach ($cats as $k => $c): ?>
          <a href="audit_log.php?<?php echo $esc($q(['cat' => $k])); ?>" class="au-chip<?php echo $catKey === $k ? ' is-active' : ''; ?>"><i style="background:<?php echo $c[1]; ?>"></i><?php echo $esc($c[0]); ?> <em><?php echo number_format($catCounts[$k]); ?></em></a>
        <?php endforeach; ?>
      </div>

      <?php if ($events): ?>
      <div class="au-feed" id="auFeed">
        <?php foreach ($events as $l):
            $c = $cats[$l['_cat']];
            $action = strtoupper(trim((string)($l['action'] ?? '')));
            $user = $l['user_name'] ?? 'System';
            $detail = trim((string)($l['description'] ?? ''));
        ?>
        <article class="au-ev">
          <span class="au-av" style="background:<?php echo $c[1]; ?>1f;color:<?php echo $c[1]; ?>"><?php echo $esc(strtoupper(mb_substr((string)$user, 0, 1))); ?></span>
          <div class="au-ev__b">
            <div class="au-ev__top">
              <b><?php echo $esc($user); ?></b>
              <span class="db-pill <?php echo $c[2]; ?>"><?php echo $esc($action !== '' ? $action : 'EVENT'); ?></span>
              <span class="au-co"><?php echo $esc($l['company_name'] ?? 'Global'); ?></span>
            </div>
            <p><?php echo $detail !== '' ? $esc($detail) : '<span class="db-mut">No additional details</span>'; ?></p>
          </div>
          <div class="au-ev__r">
            <b><?php echo $esc($ago((string)$l['created_at'])); ?></b>
            <small><?php echo $esc(date('d M, H:i:s', strtotime((string)$l['created_at']))); ?></small>
            <code><?php echo $esc($l['ip_address'] ?? '—'); ?></code>
          </div>
        </article>
        <?php endforeach; ?>
        <div class="db-empty" id="auNoMatch" style="display:none">No events match your search.</div>
      </div>
      <?php else: ?>
        <div class="au-clear"><span><?php echo $__ic($P['shield'], 34); ?></span><b>No audit events</b><p>Nothing was recorded for this filter. Platform activity will appear here as it happens.</p></div>
      <?php endif; ?>
    </section>

    <aside class="au-side">
      <section class="db-card au-card">
        <div class="au-ch"><h2>Top actions</h2><p>Most frequent events</p></div>
        <?php if ($actionTop): ?>
          <div class="au-bars">
            <?php foreach ($actionTop as $a): $ck = $catOf($a['a']); ?>
              <div class="au-bar">
                <div class="au-bar__t"><span><i style="background:<?php echo $cats[$ck][1]; ?>"></i><?php echo $esc($a['a'] !== '' ? $a['a'] : 'EVENT'); ?></span><b><?php echo number_format($a['n']); ?></b></div>
                <div class="au-track"><u style="width:<?php echo round($a['n'] / $maxAct * 100); ?>%;background:<?php echo $cats[$ck][1]; ?>"></u></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="db-empty">No data yet</div><?php endif; ?>
      </section>

      <section class="db-card au-card">
        <div class="au-ch"><h2>Most active users</h2><p>By events in range</p></div>
        <?php if ($topUsers): ?>
          <div class="au-users">
            <?php foreach ($topUsers as $u): ?>
              <div class="au-u">
                <span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$u['name'], 0, 1))); ?></span>
                <div class="au-u__b"><div class="au-u__t"><b><?php echo $esc($u['name']); ?></b><span><?php echo number_format((int)$u['n']); ?></span></div><div class="au-track"><u style="width:<?php echo round($u['n'] / $maxUsr * 100); ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="db-empty">No data yet</div><?php endif; ?>
      </section>
    </aside>
  </div>
</div>

<style>
.au{max-width:none;margin:0}
.au .db-head__actions{align-items:center}
.au-seg{padding:6px 13px;border-radius:7px;font:700 13px Inter,system-ui,sans-serif;color:var(--tx);text-decoration:none}
.au-seg:hover{color:var(--gd)}
.au-seg.is-active{background:#fff;color:var(--gd);box-shadow:0 1px 5px rgba(18,140,126,.2)}
.au .db-canvas{height:270px}
.au .db-donut__chart canvas{max-width:200px;max-height:200px}
.au-search{display:flex;align-items:center;gap:9px;width:250px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.au-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.au-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.au-chips{display:flex;gap:8px;flex-wrap:wrap;padding:4px 20px 8px}
.au-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 15px;border-radius:999px;background:var(--soft);color:var(--tx);font-size:14px;font-weight:700;text-decoration:none;transition:background .15s,color .15s}
.au-chip i{width:9px;height:9px;border-radius:3px;display:inline-block}
.au-chip em{font-style:normal;font-size:12.5px;padding:1px 8px;border-radius:999px;background:#fff;color:var(--mu)}
.au-chip:hover{background:#E6FCF2;color:var(--gd)}
.au-chip.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.au-chip.is-active em{background:rgba(255,255,255,.22);color:#fff}
.au-feed{display:flex;flex-direction:column;gap:10px;padding:8px 20px 20px;max-height:720px;overflow-y:auto}
.au-ev{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:16px;align-items:center;padding:14px 16px;border-radius:14px;background:var(--soft);transition:background .15s,box-shadow .15s}
.au-ev:hover{background:#fff;box-shadow:0 10px 26px -16px rgba(18,140,126,.45)}
.au-av{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:17px;font-weight:800}
.au-ev__top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px}
.au-ev__top>b{font-size:15px;font-weight:800;color:var(--ink)}
.au-co{padding:3px 9px;border-radius:7px;background:#fff;font-size:12px;font-weight:700;color:var(--gd)}
.au-ev__b p{margin:0;font-size:14px;line-height:1.5;color:var(--tx);word-break:break-word}
.au-ev__r{text-align:right;display:flex;flex-direction:column;gap:2px;align-items:flex-end}
.au-ev__r b{font-size:13.5px;font-weight:800;color:var(--ink);white-space:nowrap}
.au-ev__r small{font-size:12.5px;color:var(--mu);white-space:nowrap}
.au-ev__r code{margin-top:3px;padding:2px 8px;border-radius:6px;background:#fff;font:600 12px ui-monospace,Consolas,monospace;color:var(--tx)}
.au-clear{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:46px 24px 54px}
.au-clear>span{width:76px;height:76px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin-bottom:6px}
.au-clear b{font-size:18px;color:var(--ink)}
.au-clear p{margin:0;max-width:340px;font-size:14.5px;color:var(--mu);line-height:1.55}
.au-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.au-card{padding:20px}
.au-ch{margin-bottom:14px}
.au-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.au-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.au-bars,.au-users{display:flex;flex-direction:column;gap:12px}
.au-bar{padding:12px 14px;border-radius:13px;background:var(--soft)}
.au-bar__t{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:13.5px}
.au-bar__t span{display:inline-flex;align-items:center;gap:8px;font-weight:700;color:var(--tx);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.au-bar__t i{width:9px;height:9px;border-radius:3px;flex:none}
.au-bar__t b{color:var(--ink);font-weight:800}
.au-track{height:8px;border-radius:99px;background:#fff;overflow:hidden;min-width:0}
.au-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}
.au-u{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:13px;background:var(--soft)}
.au-u .db-av{background:#fff;color:var(--gd);flex:none}
.au-u__b{flex:1;min-width:0}
.au-u__t{display:flex;justify-content:space-between;gap:8px;margin-bottom:7px;font-size:14px}
.au-u__t b{color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.au-u__t span{color:var(--gd);font-weight:800;font-size:13px}
@media (max-width:1100px){.au .db-row{grid-template-columns:1fr}}
@media (max-width:640px){.au-ev{grid-template-columns:44px minmax(0,1fr)}.au-ev__r{grid-column:2;text-align:left;align-items:flex-start}.au .db-head__actions{width:100%}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var s = document.getElementById('auditSearch');
  if (s) { s.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim(), n = 0, items = document.querySelectorAll('#auFeed .au-ev');
    items.forEach(function (el) {
      var ok = !q || el.textContent.toLowerCase().indexOf(q) !== -1;
      el.style.display = ok ? '' : 'none'; if (ok) { n++; }
    });
    var sh = document.getElementById('auShown'); if (sh) { sh.textContent = n; }
    var nm = document.getElementById('auNoMatch'); if (nm) { nm.style.display = (items.length && !n) ? 'block' : 'none'; }
  }); }
  if (typeof Chart === 'undefined') { return; }
  var cv = document.getElementById('auChart');
  if (cv) {
    var ctx = cv.getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270);
    g.addColorStop(0, 'rgba(37,211,102,.32)'); g.addColorStop(1, 'rgba(37,211,102,.02)');
    new Chart(ctx, {
      type: 'line',
      data: {labels: <?php echo json_encode($tLabels); ?>, datasets: [{label: 'Events', data: <?php echo json_encode($tData); ?>, borderColor: '#128C7E', backgroundColor: g, borderWidth: 3, fill: true, tension: .38, pointRadius: 0, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderColor: '#128C7E', pointBorderWidth: 2.5}]},
      options: {
        responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
        plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false}},
        scales: {
          x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}},
          y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}
        }
      }
    });
  }
  var dn = document.getElementById('auDonut');
  if (dn) {
    var vals = <?php echo json_encode(array_values($catCounts)); ?>, total = vals.reduce(function (a, b) { return a + b; }, 0);
    new Chart(dn, {type: 'doughnut',
      data: {labels: <?php echo json_encode(array_map(static fn($c) => $c[0], array_values($cats))); ?>, datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? <?php echo json_encode(array_map(static fn($c) => $c[1], array_values($cats))); ?> : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
      options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
  }
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
