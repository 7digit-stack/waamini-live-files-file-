<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Analytics';
$currentPage = 'analytics';

// ── Cache all expensive analytics queries for 5 minutes ──────────────────────
include_once '../includes/cache.php';
$_cacheKey = 'admin:analytics:stats';
$_cached   = waamini_cache_get($_cacheKey, 300); // 5-minute TTL

if (is_array($_cached)) {
    // Serve from cache
    extract($_cached);
} else {
    // System-wide stats (no user input — safe direct queries)
    $totalCompanies     = (int)($conn->query("SELECT COUNT(*) as c FROM companies")->fetch_assoc()['c'] ?? 0);
    $activeCompanies    = (int)($conn->query("SELECT COUNT(*) as c FROM companies WHERE status='active'")->fetch_assoc()['c'] ?? 0);
    $totalUsers         = (int)($conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] ?? 0);
    $totalContacts      = (int)($conn->query("SELECT COUNT(*) as c FROM contacts")->fetch_assoc()['c'] ?? 0);
    $totalCampaigns     = (int)($conn->query("SELECT COUNT(*) as c FROM campaigns")->fetch_assoc()['c'] ?? 0);
    $completedCampaigns = (int)($conn->query("SELECT COUNT(*) as c FROM campaigns WHERE status='completed'")->fetch_assoc()['c'] ?? 0);
    $totalMessages      = (int)($conn->query("SELECT COUNT(*) as c FROM chat_messages")->fetch_assoc()['c'] ?? 0);
    $totalTemplates     = (int)($conn->query("SELECT COUNT(*) as c FROM message_templates")->fetch_assoc()['c'] ?? 0);
    $activeSubscriptions= (int)($conn->query("SELECT COUNT(*) as c FROM subscriptions WHERE status='active'")->fetch_assoc()['c'] ?? 0);
    $totalRevenue       = (int)($conn->query("SELECT COALESCE(SUM(amount),0) as c FROM subscription_payments WHERE status='success'")->fetch_assoc()['c'] ?? 0);
    $totalSent          = (int)($conn->query("SELECT COALESCE(SUM(sent_count),0) as c FROM campaigns")->fetch_assoc()['c'] ?? 0);
    $totalFailed        = (int)($conn->query("SELECT COALESCE(SUM(failed_count),0) as c FROM campaigns")->fetch_assoc()['c'] ?? 0);
    $successRate        = $totalSent > 0 ? round((($totalSent - $totalFailed) / $totalSent) * 100, 1) : 0;
    $currencyRow        = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='payment' AND setting_key IN ('currency','currency_symbol')");
    $currencyCode       = 'INR';
    $storedCurrencySymbol = '';
    if ($currencyRow) {
        while ($row = $currencyRow->fetch_assoc()) {
            if (($row['setting_key'] ?? '') === 'currency') {
                $currencyCode = trim((string)($row['setting_value'] ?? '')) ?: 'INR';
            } elseif (($row['setting_key'] ?? '') === 'currency_symbol') {
                $storedCurrencySymbol = trim((string)($row['setting_value'] ?? ''));
            }
        }
    }
    $currencySymbols = [
        'INR' => '₹',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'د.إ',
        'SAR' => '﷼',
        'SGD' => 'S$',
        'AUD' => 'A$',
    ];
    $currSymbol = $currencySymbols[$currencyCode] ?? '₹';
    if ($storedCurrencySymbol !== '' && $storedCurrencySymbol !== '?') {
        $currSymbol = $storedCurrencySymbol;
    }
    $currSymbolDisplay = ($currencyCode === 'INR') ? 'Rs. ' : $currSymbol;

    // Monthly message data — use a single query per table instead of 12 separate queries
    $monthLabels = $msgMonthData = $contactMonthData = [];
    $monthMap = $msgMap = $contactMap = [];

    // Build list of last-12-month YYYY-MM strings
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $months[] = date('Y-m', strtotime("-$i months"));
        $monthLabels[] = date('M Y', strtotime("-$i months"));
    }
    $monthStart = $months[0] . '-01';

    // Single aggregate query for messages (safe — no user input)
    $msgStmt = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as c FROM chat_messages WHERE created_at >= ? GROUP BY mo");
    if ($msgStmt) {
        $msgStmt->bind_param('s', $monthStart);
        $msgStmt->execute();
        $rMsg = $msgStmt->get_result();
        if ($rMsg) { while ($row = $rMsg->fetch_assoc()) $msgMap[$row['mo']] = (int)$row['c']; }
    }

    // Single aggregate query for contacts
    $conStmt = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as c FROM contacts WHERE created_at >= ? GROUP BY mo");
    if ($conStmt) {
        $conStmt->bind_param('s', $monthStart);
        $conStmt->execute();
        $rCon = $conStmt->get_result();
        if ($rCon) { while ($row = $rCon->fetch_assoc()) $contactMap[$row['mo']] = (int)$row['c']; }
    }

    foreach ($months as $mo) {
        $msgMonthData[]     = $msgMap[$mo]     ?? 0;
        $contactMonthData[] = $contactMap[$mo] ?? 0;
    }

    // Top companies by contacts
    $topCompaniesData = [];
    $rTop = $conn->query("SELECT c.name, COUNT(ct.id) as contact_count FROM companies c LEFT JOIN contacts ct ON c.id = ct.company_id GROUP BY c.id ORDER BY contact_count DESC LIMIT 5");
    if ($rTop) { while ($row = $rTop->fetch_assoc()) $topCompaniesData[] = $row; }

    // Cache everything
    waamini_cache_set($_cacheKey, compact(
        'totalCompanies','activeCompanies','totalUsers','totalContacts',
        'totalCampaigns','completedCampaigns','totalMessages','totalTemplates',
        'activeSubscriptions','totalRevenue','totalSent','totalFailed',
        'successRate','currSymbol','monthLabels','msgMonthData','contactMonthData','topCompaniesData'
    ), 300);
}
// Rebuild $topCompanies as iterable from cached array
$topCompanies = $topCompaniesData ?? [];

// ── Deep-analysis data (cached 5 min, each query isolated so one failure never breaks the page) ──
$_deepKey = 'admin:analytics:deep';
$_deep = waamini_cache_get($_deepKey, 300);
if (!is_array($_deep)) {
    $q1 = static function (mysqli $db, string $sql): array {
        try { $r = $db->query($sql); $o = []; if ($r) { while ($x = $r->fetch_assoc()) { $o[] = $x; } } return $o; }
        catch (Throwable $e) { error_log('[WAAMINI analytics] ' . $e->getMessage()); return []; }
    };
    $dirIn = 0; $dirOut = 0;
    foreach ($q1($conn, "SELECT direction, COUNT(*) AS c FROM chat_messages GROUP BY direction") as $x) {
        if (($x['direction'] ?? '') === 'incoming') { $dirIn = (int)$x['c']; } elseif (($x['direction'] ?? '') === 'outgoing') { $dirOut = (int)$x['c']; }
    }
    $fn = ['targeted' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
    $cr = $q1($conn, "SELECT COALESCE(SUM(total_contacts),0) t, COALESCE(SUM(sent_count),0) s, COALESCE(SUM(delivered_count),0) d, COALESCE(SUM(read_count),0) r, COALESCE(SUM(failed_count),0) f FROM campaigns");
    if ($cr) { $fn = ['targeted' => (int)$cr[0]['t'], 'sent' => (int)$cr[0]['s'], 'delivered' => (int)$cr[0]['d'], 'read' => (int)$cr[0]['r'], 'failed' => (int)$cr[0]['f']]; }
    $campStatus = [];
    foreach ($q1($conn, "SELECT status, COUNT(*) AS c FROM campaigns GROUP BY status") as $x) { $campStatus[(string)$x['status']] = (int)$x['c']; }
    $subStatus = [];
    foreach ($q1($conn, "SELECT status, COUNT(*) AS c FROM subscriptions GROUP BY status") as $x) { $subStatus[(string)$x['status']] = (int)$x['c']; }
    $planMix = [];
    foreach ($q1($conn, "SELECT COALESCE(p.name,'No plan') AS name, COUNT(*) AS c FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id=p.id GROUP BY s.plan_id, p.name ORDER BY c DESC LIMIT 5") as $x) { $planMix[] = ['name' => (string)$x['name'], 'c' => (int)$x['c']]; }
    $revMap = [];
    foreach ($q1($conn, "SELECT DATE_FORMAT(paid_at,'%Y-%m') AS mo, COALESCE(SUM(amount),0) AS t FROM subscription_payments WHERE status='success' AND paid_at IS NOT NULL AND paid_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY mo") as $x) { $revMap[(string)$x['mo']] = (float)$x['t']; }
    $coMap = [];
    foreach ($q1($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS c FROM companies WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY mo") as $x) { $coMap[(string)$x['mo']] = (int)$x['c']; }
    $topMsg = [];
    foreach ($q1($conn, "SELECT co.name, COUNT(m.id) AS c FROM chat_messages m JOIN contacts ct ON m.contact_id=ct.id JOIN companies co ON ct.company_id=co.id GROUP BY co.id, co.name ORDER BY c DESC LIMIT 5") as $x) { $topMsg[] = ['name' => (string)$x['name'], 'c' => (int)$x['c']]; }
    $optIn = 0;
    $oi = $q1($conn, "SELECT COUNT(*) AS c FROM contacts WHERE opted_in = 1");
    if ($oi) { $optIn = (int)$oi[0]['c']; }
    $_deep = compact('dirIn', 'dirOut', 'fn', 'campStatus', 'subStatus', 'planMix', 'revMap', 'coMap', 'topMsg', 'optIn');
    waamini_cache_set($_deepKey, $_deep, 300);
}
extract($_deep);

// Month series (same 12 labels as above)
$monthKeys = [];
for ($i = 11; $i >= 0; $i--) { $monthKeys[] = date('Y-m', strtotime("first day of -$i months")); }
$revMonthData = []; $coMonthData = [];
foreach ($monthKeys as $mk) { $revMonthData[] = round((float)($revMap[$mk] ?? 0), 2); $coMonthData[] = (int)($coMap[$mk] ?? 0); }

$cur = count($msgMonthData) - 1;
$pctChg = static function (float $now, float $prev): ?float {
    if ($prev <= 0) { return $now > 0 ? null : 0.0; }
    return round((($now - $prev) / $prev) * 100, 1);
};
$msgChg = $pctChg((float)$msgMonthData[$cur], (float)($msgMonthData[$cur - 1] ?? 0));
$conChg = $pctChg((float)$contactMonthData[$cur], (float)($contactMonthData[$cur - 1] ?? 0));
$revChg = $pctChg((float)$revMonthData[$cur], (float)($revMonthData[$cur - 1] ?? 0));
$sum12Msg = array_sum($msgMonthData); $sum12Con = array_sum($contactMonthData); $sum12Rev = array_sum($revMonthData);

$avgContactsPerCo = $activeCompanies > 0 ? round($totalContacts / $activeCompanies, 1) : 0;
$msgsPerContact   = $totalContacts > 0 ? round($totalMessages / $totalContacts, 1) : 0;
$replyRatio       = $dirOut > 0 ? round($dirIn / $dirOut * 100) : 0;
$trialCount       = (int)($subStatus['trial'] ?? 0);
$paidCount        = (int)($subStatus['active'] ?? 0);
$convRate         = ($trialCount + $paidCount) > 0 ? round($paidCount / ($trialCount + $paidCount) * 100, 1) : 0;
$optRate          = $totalContacts > 0 ? round($optIn / $totalContacts * 100) : 0;
$bestIdx = 0; foreach ($msgMonthData as $i => $v) { if ($v > $msgMonthData[$bestIdx]) { $bestIdx = $i; } }
$bestMonth = $msgMonthData[$bestIdx] > 0 ? $monthLabels[$bestIdx] : '—';

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'building' => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'mega'     => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'coin'     => '<circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',
    'check'    => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'spark'    => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'bulb'     => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
];
$money = $currSymbol . number_format($totalRevenue);
$chip = static function (?float $c): string {
    if ($c === null) { return '<span class="db-chip is-up">New</span>'; }
    if ($c == 0) { return '<span class="db-chip">0%</span>'; }
    return '<span class="db-chip ' . ($c > 0 ? 'is-up' : 'is-down') . '">' . ($c > 0 ? '▲ ' : '▼ ') . abs($c) . '%</span>';
};
$pctOf = static fn($a, $b) => $b > 0 ? min(100, round($a / $b * 100, 1)) : 0;
$funnelRows = [
    ['Targeted', $fn['targeted'], '#128C7E'],
    ['Sent', $fn['sent'], '#25D366'],
    ['Delivered', $fn['delivered'], '#3B82F6'],
    ['Read', $fn['read'], '#8B5CF6'],
    ['Failed', $fn['failed'], '#E5483A'],
];
$subCols = ['active' => '#25D366', 'trial' => '#3B82F6', 'expired' => '#E5483A', 'cancelled' => '#C7D1CD', 'pending' => '#F59E0B'];
$campCols = ['completed' => '#128C7E', 'running' => '#25D366', 'scheduled' => '#3B82F6', 'draft' => '#9AA9A4', 'failed' => '#E5483A', 'cancelled' => '#C7D1CD'];
?>

<div class="db ax">

  <div class="db-head">
    <div>
      <h1>Analytics</h1>
      <p>Platform-wide growth, messaging, campaign and revenue analysis &middot; last 12 months.</p>
    </div>
    <div class="db-head__actions">
      <a href="dashboard.php" class="db-btn"><?php echo $__ic($P['spark'], 16); ?> Dashboard</a>
      <a href="campaigns.php" class="db-btn db-btn--primary"><?php echo $__ic($P['mega'], 16); ?> Campaigns</a>
    </div>
  </div>

  <!-- KPIs -->
  <section class="ax-kpis">
    <div class="ax-kpi"><span class="db-kpi__ic is-green"><?php echo $__ic($P['building'], 20); ?></span><div><span>Active companies</span><b><?php echo number_format($activeCompanies); ?><small> / <?php echo number_format($totalCompanies); ?></small></b><em><?php echo $pctOf($activeCompanies, $totalCompanies); ?>% of all companies</em></div></div>
    <div class="ax-kpi"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['users'], 20); ?></span><div><span>Total contacts</span><b><?php echo number_format($totalContacts); ?></b><em><?php echo $chip($conChg); ?> +<?php echo number_format($contactMonthData[$cur]); ?> this month</em></div></div>
    <div class="ax-kpi"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['chat'], 20); ?></span><div><span>Messages</span><b><?php echo number_format($totalMessages); ?></b><em><?php echo $chip($msgChg); ?> <?php echo number_format($msgMonthData[$cur]); ?> this month</em></div></div>
    <div class="ax-kpi"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['mega'], 20); ?></span><div><span>Campaigns done</span><b><?php echo number_format($completedCampaigns); ?><small> / <?php echo number_format($totalCampaigns); ?></small></b><em><?php echo $pctOf($completedCampaigns, $totalCampaigns); ?>% completion</em></div></div>
    <div class="ax-kpi"><span class="db-kpi__ic is-pink"><?php echo $__ic($P['coin'], 20); ?></span><div><span>Total revenue</span><b><?php echo $esc($money); ?></b><em><?php echo $chip($revChg); ?> <?php echo $esc($currSymbol . number_format($revMonthData[$cur])); ?> this month</em></div></div>
    <div class="ax-kpi"><span class="db-kpi__ic is-green"><?php echo $__ic($P['check'], 20); ?></span><div><span>Campaign success</span><b><?php echo $esc($successRate); ?>%</b><em><?php echo number_format($totalFailed); ?> failed of <?php echo number_format($totalSent); ?> sent</em></div></div>
  </section>

  <!-- Growth + direction donut -->
  <div class="db-row db-row--top">
    <section class="db-card ax-pad">
      <div class="db-chart__top">
        <div>
          <h2>Growth overview</h2>
          <div class="db-legend"><span><i style="background:#25D366"></i><b id="axTotal"><?php echo number_format($sum12Msg); ?></b>&nbsp;messages in 12 months</span></div>
        </div>
        <div class="db-seg" id="axSeg">
          <button type="button" class="is-active" data-chart="messages">Messages</button>
          <button type="button" data-chart="contacts">Contacts</button>
          <button type="button" data-chart="revenue">Revenue</button>
          <button type="button" data-chart="companies">Companies</button>
        </div>
      </div>
      <div class="db-canvas"><canvas id="analyticsChart" aria-label="Growth chart" role="img"></canvas></div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><div><h2>Conversation split</h2><p class="ax-sub">Incoming vs outgoing</p></div></div>
      <div class="db-donut__chart">
        <canvas id="axDir" aria-label="Message direction" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($dirIn + $dirOut); ?></b><span>messages</span></div></div>
      </div>
      <div class="db-dleg"><span><i style="background:#25D366"></i>Outgoing <?php echo number_format($dirOut); ?></span><span><i style="background:#3B82F6"></i>Incoming <?php echo number_format($dirIn); ?></span></div>
    </section>
  </div>

  <!-- Funnel + subscriptions -->
  <div class="db-row db-row--even">
    <section class="db-card ax-pad">
      <div class="ax-hd"><div><h2>Campaign funnel</h2><p class="ax-sub">From targeted contacts to reads</p></div></div>
      <div class="ax-bars">
        <?php foreach ($funnelRows as [$lab, $val, $col]): $w = $pctOf($val, max(1, $fn['targeted'], $fn['sent'])); ?>
          <div class="ax-bar">
            <div class="ax-bar__t"><span><i style="background:<?php echo $col; ?>"></i><?php echo $lab; ?></span><b><?php echo number_format($val); ?></b></div>
            <div class="ax-track"><u style="width:<?php echo $w; ?>%;background:<?php echo $col; ?>"></u></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($campStatus): ?>
      <div class="ax-tags">
        <?php foreach ($campStatus as $s => $n): ?>
          <span class="ax-tag"><i style="background:<?php echo $campCols[$s] ?? '#C7D1CD'; ?>"></i><?php echo $esc(ucfirst($s)); ?> <b><?php echo (int)$n; ?></b></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="db-card ax-pad">
      <div class="ax-hd"><div><h2>Subscriptions</h2><p class="ax-sub">Lifecycle and plan mix</p></div><span class="db-pill is-ok"><?php echo $esc($convRate); ?>% trial &rarr; paid</span></div>
      <div class="ax-sub-wrap">
        <div class="ax-sub-chart"><canvas id="axSub" aria-label="Subscription status" role="img"></canvas></div>
        <div class="ax-sub-leg">
          <?php if ($subStatus): foreach ($subStatus as $s => $n): ?>
            <span><i style="background:<?php echo $subCols[$s] ?? '#C7D1CD'; ?>"></i><?php echo $esc(ucfirst($s)); ?><b><?php echo (int)$n; ?></b></span>
          <?php endforeach; else: ?><span class="db-mut">No subscriptions yet</span><?php endif; ?>
        </div>
      </div>
      <?php if ($planMix): $pmax = max(array_column($planMix, 'c')); ?>
      <div class="ax-plans">
        <?php foreach ($planMix as $pm): ?>
          <div class="ax-plan"><span><?php echo $esc($pm['name']); ?></span><div class="ax-track"><u style="width:<?php echo $pctOf($pm['c'], $pmax); ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div><b><?php echo (int)$pm['c']; ?></b></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Top companies + insights -->
  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head"><div><h2>Top companies</h2><p>Ranked by contacts, with message volume</p></div></div>
      <div class="db-tablewrap">
        <table class="db-table ax-table">
          <thead><tr><th>#</th><th>Company</th><th>Contacts</th><th>Share of contacts</th></tr></thead>
          <tbody>
          <?php if (!empty($topCompanies)): $rank = 1; foreach ($topCompanies as $tc):
              $share = $totalContacts > 0 ? round(($tc['contact_count'] / $totalContacts) * 100, 1) : 0; ?>
            <tr>
              <td><span class="ax-rank<?php echo $rank <= 3 ? ' is-top' : ''; ?>"><?php echo $rank; ?></span></td>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$tc['name'], 0, 1))); ?></span><div><b><?php echo $esc($tc['name']); ?></b></div></div></td>
              <td><b><?php echo number_format((int)$tc['contact_count']); ?></b></td>
              <td style="min-width:180px"><div class="ax-share"><div class="ax-track"><u style="width:<?php echo (float)$share; ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div><span><?php echo $esc($share); ?>%</span></div></td>
            </tr>
          <?php $rank++; endforeach; else: ?>
            <tr><td colspan="4"><div class="db-empty">No data yet</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($topMsg): $mmax = max(array_column($topMsg, 'c')); ?>
      <div class="ax-hd ax-hd--sm"><div><h2>Most active by messages</h2></div></div>
      <div class="ax-plans ax-plans--pad">
        <?php foreach ($topMsg as $tm): ?>
          <div class="ax-plan"><span><?php echo $esc($tm['name']); ?></span><div class="ax-track"><u style="width:<?php echo $pctOf($tm['c'], $mmax); ?>%;background:linear-gradient(90deg,#3B82F6,#8B5CF6)"></u></div><b><?php echo number_format($tm['c']); ?></b></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <aside class="db-card ax-pad">
      <div class="ax-hd"><div><h2>Key insights</h2><p class="ax-sub">Calculated from live data</p></div><span class="ax-bulb"><?php echo $__ic($P['bulb'], 18); ?></span></div>
      <div class="ax-ins">
        <div><span>Avg contacts / active company</span><b><?php echo $esc($avgContactsPerCo); ?></b></div>
        <div><span>Messages per contact</span><b><?php echo $esc($msgsPerContact); ?></b></div>
        <div><span>Reply ratio (incoming / outgoing)</span><b><?php echo (int)$replyRatio; ?>%</b></div>
        <div><span>Opted-in contacts</span><b><?php echo (int)$optRate; ?>%</b></div>
        <div><span>Trial &rarr; paid conversion</span><b><?php echo $esc($convRate); ?>%</b></div>
        <div><span>Peak month (messages)</span><b><?php echo $esc($bestMonth); ?></b></div>
        <div><span>Total users</span><b><?php echo number_format($totalUsers); ?></b></div>
        <div><span>Templates</span><b><?php echo number_format($totalTemplates); ?></b></div>
        <div><span>Active subscriptions</span><b><?php echo number_format($activeSubscriptions); ?></b></div>
      </div>
    </aside>
  </div>

  <!-- Monthly breakdown -->
  <section class="db-card db-tablecard">
    <div class="db-card__head"><div><h2>Monthly breakdown</h2><p>Last 12 months &middot; newest first</p></div></div>
    <div class="db-tablewrap">
      <table class="db-table ax-table">
        <thead><tr><th>Month</th><th>Messages</th><th>vs prev.</th><th>New contacts</th><th>New companies</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php for ($i = count($monthLabels) - 1; $i >= 0; $i--):
            $mc = $pctChg((float)$msgMonthData[$i], (float)($msgMonthData[$i - 1] ?? 0)); ?>
          <tr>
            <td><b><?php echo $esc($monthLabels[$i]); ?></b></td>
            <td><?php echo number_format($msgMonthData[$i]); ?></td>
            <td><?php echo $i === 0 ? '<span class="db-mut">—</span>' : $chip($mc); ?></td>
            <td><?php echo number_format($contactMonthData[$i]); ?></td>
            <td><?php echo number_format($coMonthData[$i]); ?></td>
            <td><?php echo $esc($currSymbol . number_format($revMonthData[$i])); ?></td>
          </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot><tr><td><b>12-month total</b></td><td><b><?php echo number_format($sum12Msg); ?></b></td><td></td><td><b><?php echo number_format($sum12Con); ?></b></td><td><b><?php echo number_format(array_sum($coMonthData)); ?></b></td><td><b><?php echo $esc($currSymbol . number_format($sum12Rev)); ?></b></td></tr></tfoot>
      </table>
    </div>
  </section>
</div>

<style>
.ax{max-width:none;margin:0}
.ax-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.ax-kpi{display:flex;align-items:center;gap:14px;padding:18px 20px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.ax-kpi .db-kpi__ic{margin:0;flex:none}
.ax-kpi>div{min-width:0}
.ax-kpi>div>span{display:block;font-size:13.5px;font-weight:600;color:var(--mu)}
.ax-kpi b{display:block;margin:2px 0 4px;font-size:29px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap}
.ax-kpi b small{font-size:15px;font-weight:600;color:var(--mu);letter-spacing:0}
.ax-kpi em{display:flex;align-items:center;gap:7px;font-style:normal;font-size:12.5px;color:var(--mu);flex-wrap:wrap}
.ax-pad{padding:18px 20px 20px}
.ax-sub{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.ax-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.ax-hd h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.ax-hd--sm{padding:6px 20px 8px;margin:0}
.ax-hd--sm h2{font-size:15.5px}
.ax-bulb{width:36px;height:36px;border-radius:10px;background:#FEF3DC;color:#b7791f;display:grid;place-items:center;flex:none}
.ax .db-chart__top{margin-bottom:4px}
.ax .db-canvas{height:300px}
.ax .db-donut{padding:18px 20px 20px}
.ax .db-donut__chart{min-height:230px}

.ax-bars{display:flex;flex-direction:column;gap:14px}
.ax-bar__t{display:flex;align-items:center;justify-content:space-between;font-size:14.5px;margin-bottom:7px}
.ax-bar__t span{display:inline-flex;align-items:center;gap:8px;font-weight:600;color:var(--tx)}
.ax-bar__t i,.ax-tag i,.ax-sub-leg i{width:10px;height:10px;border-radius:3px;display:inline-block}
.ax-bar__t b{font-weight:800;color:var(--ink)}
.ax-track{height:9px;border-radius:99px;background:var(--soft);overflow:hidden;min-width:0}
.ax-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}
.ax-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.ax-tag{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;background:var(--soft);font-size:13.5px;font-weight:600;color:var(--tx)}
.ax-tag b{color:var(--ink);font-weight:800}

.ax-sub-wrap{display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.ax-sub-chart{position:relative;width:170px;height:170px;flex:none}
.ax-sub-leg{display:flex;flex-direction:column;gap:9px;min-width:150px;flex:1}
.ax-sub-leg span{display:flex;align-items:center;gap:9px;padding:9px 14px;border-radius:11px;background:var(--soft);font-size:14px;font-weight:600;color:var(--tx)}
.ax-sub-leg b{margin-left:auto;color:var(--ink);font-weight:800}
.ax-plans{display:flex;flex-direction:column;gap:11px;margin-top:20px}
.ax-plans--pad{padding:0 20px 20px;margin-top:0}
.ax-plan{display:grid;grid-template-columns:minmax(80px,150px) minmax(0,1fr) 44px;align-items:center;gap:12px;font-size:14px;color:var(--tx)}
.ax-plan span{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ax-plan b{text-align:right;color:var(--ink);font-weight:800}

.ax-table td{border-bottom:0}
.ax-table tbody tr:nth-child(even) td{background:#F8FCFA}
.ax-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}
.ax-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.ax-table tfoot td{padding:14px;background:#E6FCF2;color:var(--gd)}
.ax-table tfoot td:first-child{border-radius:10px 0 0 10px}.ax-table tfoot td:last-child{border-radius:0 10px 10px 0}
.ax-rank{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:9px;background:var(--soft);font-weight:800;font-size:13.5px;color:var(--tx)}
.ax-rank.is-top{background:linear-gradient(135deg,#25D366,#128C7E);color:#fff}
.ax-share{display:flex;align-items:center;gap:10px}
.ax-share .ax-track{flex:1}
.ax-share span{font-size:13px;font-weight:700;color:var(--tx);min-width:44px;text-align:right}
.ax-ins{display:flex;flex-direction:column;gap:9px}
.ax-ins div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:14px;color:var(--tx)}
.ax-ins b{font-size:16px;font-weight:800;color:var(--ink);white-space:nowrap}
@media (max-width:1100px){.ax-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ax .db-row{grid-template-columns:1fr}}
@media (max-width:560px){.ax-kpis{grid-template-columns:1fr}.ax-plan{grid-template-columns:90px minmax(0,1fr) 36px}.ax .db-seg{flex-wrap:wrap}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') { return; }
  var labels = <?php echo json_encode($monthLabels); ?>;
  var series = {
    messages:  {label: 'Messages',  data: <?php echo json_encode($msgMonthData); ?>,     color: '#25D366', rgb: '37,211,102', fmt: function (v) { return v.toLocaleString(); }},
    contacts:  {label: 'Contacts',  data: <?php echo json_encode($contactMonthData); ?>, color: '#3B82F6', rgb: '59,130,246', fmt: function (v) { return v.toLocaleString(); }},
    revenue:   {label: 'Revenue',   data: <?php echo json_encode($revMonthData); ?>,     color: '#F59E0B', rgb: '245,158,11', fmt: function (v) { return <?php echo json_encode($currSymbol); ?> + v.toLocaleString(); }},
    companies: {label: 'Companies', data: <?php echo json_encode($coMonthData); ?>,      color: '#8B5CF6', rgb: '139,92,246', fmt: function (v) { return v.toLocaleString(); }}
  };
  var noun = {messages: 'messages', contacts: 'new contacts', revenue: 'revenue', companies: 'new companies'};
  var cv = document.getElementById('analyticsChart');
  if (!cv) { return; }
  var ctx = cv.getContext('2d');
  function grad(rgb) {
    var g = ctx.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, 'rgba(' + rgb + ',.32)'); g.addColorStop(1, 'rgba(' + rgb + ',.02)');
    return g;
  }
  var cur = series.messages;
  var chart = new Chart(ctx, {
    type: 'line',
    data: {labels: labels, datasets: [{label: cur.label, data: cur.data, borderColor: cur.color, backgroundColor: grad(cur.rgb), borderWidth: 3, fill: true, tension: .38, pointBackgroundColor: '#fff', pointBorderColor: cur.color, pointBorderWidth: 2.5, pointRadius: 4, pointHoverRadius: 7}]},
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: {intersect: false, mode: 'index'},
      plugins: {
        legend: {display: false},
        tooltip: {backgroundColor: '#0F2622', titleColor: '#fff', bodyColor: '#CFE9DF', padding: 12, cornerRadius: 10, displayColors: false,
          callbacks: {label: function (c) { return cur.label + ': ' + cur.fmt(c.parsed.y); }}}
      },
      scales: {
        x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 12}, maxRotation: 0, autoSkip: true, maxTicksLimit: 12}},
        y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.07)'}, ticks: {color: '#586a63', font: {size: 12}, precision: 0, maxTicksLimit: 6}}
      }
    }
  });
  var totalEl = document.getElementById('axTotal');
  var legendTxt = totalEl ? totalEl.parentNode : null;
  function setSeries(k) {
    cur = series[k]; var d = chart.data.datasets[0];
    d.label = cur.label; d.data = cur.data; d.borderColor = cur.color; d.pointBorderColor = cur.color; d.backgroundColor = grad(cur.rgb);
    chart.update();
    if (totalEl) {
      var sum = cur.data.reduce(function (a, b) { return a + b; }, 0);
      totalEl.textContent = cur.fmt(sum);
      legendTxt.lastChild.nodeValue = ' ' + noun[k] + ' in 12 months';
      var dot = legendTxt.querySelector('i'); if (dot) { dot.style.background = cur.color; }
    }
  }
  var seg = document.getElementById('axSeg');
  if (seg) { seg.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-chart]'); if (!b) { return; }
    seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    setSeries(b.getAttribute('data-chart'));
  }); }

  function donut(id, vals, cols, labs) {
    var el = document.getElementById(id); if (!el) { return; }
    var total = vals.reduce(function (a, b) { return a + b; }, 0);
    new Chart(el, {type: 'doughnut',
      data: {labels: labs, datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? cols : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
      options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
  }
  donut('axDir', [<?php echo (int)$dirOut; ?>, <?php echo (int)$dirIn; ?>], ['#25D366', '#3B82F6'], ['Outgoing', 'Incoming']);
  donut('axSub', <?php echo json_encode(array_values(array_map('intval', $subStatus))); ?>, <?php echo json_encode(array_map(static fn($s) => $subCols[$s] ?? '#C7D1CD', array_keys($subStatus))); ?>, <?php echo json_encode(array_map('ucfirst', array_keys($subStatus))); ?>);
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
