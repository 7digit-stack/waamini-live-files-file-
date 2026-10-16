<?php
include '../includes/client_auth_check.php';

if (!has_feature('feat_crm_analytics')) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle = 'Advanced CRM Analytics';
$currentPage = 'premium_analytics';

// Active subscription (same pattern as dashboard.php — powers subscription card)
$sub = null;
$subStmt = $conn->prepare("
    SELECT s.*, sp.name AS plan_name
    FROM subscriptions s
    JOIN subscription_plans sp ON s.plan_id = sp.id
    WHERE s.company_id = ? AND s.status IN ('active','trial')
    ORDER BY s.expires_at DESC
    LIMIT 1
");
if ($subStmt) {
    $subStmt->bind_param('i', $companyId);
    $subStmt->execute();
    $subRes = $subStmt->get_result();
    if ($subRes) {
        $sub = $subRes->fetch_assoc();
    }
}

// Helper: single-column count with prepared statement
function pa_count(mysqli $db, string $sql, string $types, ...$params): int {
    $st = $db->prepare($sql);
    if (!$st) return 0;
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    return (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
}

// 1. Overview Stats — prepared statements (no raw $companyId interpolation)
$contactCount  = pa_count($conn, "SELECT COUNT(*) as c FROM contacts WHERE company_id=?", 'i', $companyId);
$campaignCount = pa_count($conn, "SELECT COUNT(*) as c FROM campaigns WHERE company_id=?", 'i', $companyId);
$dripCount     = pa_count($conn, "SELECT COUNT(*) as c FROM drip_campaigns WHERE company_id=? AND status='active'", 'i', $companyId);
$totalMsgLog   = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=?", 'i', $companyId);

// 2. Campaign delivery success — aligned with campaign_queue + webhook wa_delivery_status
//    (cron_campaign_sender records API sends as status='sent'; Meta webhooks fill wa_delivery_status.)
$camStmt = $conn->prepare("
    SELECT c.name,
           (SELECT COUNT(*) FROM campaign_queue q
            WHERE q.campaign_id = c.id AND q.company_id = c.company_id AND q.status = 'sent') AS total_sent,
           (SELECT COUNT(*) FROM campaign_queue q
            WHERE q.campaign_id = c.id AND q.company_id = c.company_id
              AND (
                    q.wa_delivery_status IN ('delivered','read')
                 OR q.status IN ('delivered','read')
              )) AS total_success
    FROM campaigns c
    WHERE c.company_id = ?
      AND EXISTS (
          SELECT 1 FROM campaign_queue q
          WHERE q.campaign_id = c.id AND q.company_id = c.company_id
      )
    ORDER BY c.created_at DESC
    LIMIT 5
");
$campaigns = null;
if ($camStmt) {
    $camStmt->bind_param('i', $companyId);
    $camStmt->execute();
    $campaigns = $camStmt->get_result();
}

// 3. Lead Growth (Last 30 days) — prepared statement
$leadGrowthLabels = [];
$leadGrowthData   = [];
$lgStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM contacts WHERE company_id=? AND DATE(created_at) = ?");
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $leadGrowthLabels[] = date('M d', strtotime("-$i days"));
    if ($lgStmt) {
        try {
            $lgStmt->bind_param("is", $companyId, $d);
            $lgStmt->execute();
            $leadGrowthData[] = (int)($lgStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        } catch (\Throwable $e) {
            // If schema missing or DB hiccups, keep chart usable (0) and log once per request.
            static $_lgLogged = false;
            if (!$_lgLogged) {
                $_lgLogged = true;
                error_log('[premium_analytics] lead growth query failed: ' . $e->getMessage());
            }
            $leadGrowthData[] = 0;
        }
    } else {
        $leadGrowthData[] = 0;
    }
}
if ($lgStmt) {
    try { $lgStmt->close(); } catch (\Throwable $e) {}
}

// 4. Message Engagement — prepared statements
$statRead      = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND status='read'", 'i', $companyId);
$statDelivered = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND status='delivered'", 'i', $companyId);
$statFailed    = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND status='failed'", 'i', $companyId);
$statSent      = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND status='sent'", 'i', $companyId);

// 5. Bot Interactions — prepared statements
$botIncoming = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='incoming'", 'i', $companyId);
$botOutgoing = pa_count($conn, "SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='outgoing'", 'i', $companyId);
$botTotal    = $botIncoming + $botOutgoing;
$botIncomingShare = $botTotal > 0 ? round($botIncoming / $botTotal * 100) : 0;
$botOutgoingShare = $botTotal > 0 ? 100 - $botIncomingShare : 0;
$botChartCircumference = 2 * pi() * 84;
$botChartGap = 26;
$botChartUsable = $botChartCircumference - (2 * $botChartGap);
$botIncomingArc = $botTotal > 0 ? $botChartUsable * $botIncoming / $botTotal : 0;
$botOutgoingArc = $botTotal > 0 ? $botChartUsable - $botIncomingArc : 0;
$botIncomingDash = $botTotal > 0 ? $botIncomingArc / $botChartCircumference : 0;
$botOutgoingDash = $botTotal > 0 ? $botOutgoingArc / $botChartCircumference : 0;
$botOutgoingOffset = -($botIncomingArc + $botChartGap) / $botChartCircumference;

// ── Extra analysis for the dashboard layout ──────────────────────────────────
$newThisMonth = pa_count($conn, "SELECT COUNT(*) as c FROM contacts WHERE company_id=? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", 'i', $companyId);
$newLastMonth = pa_count($conn, "SELECT COUNT(*) as c FROM contacts WHERE company_id=? AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')", 'i', $companyId);
$leads30 = array_sum($leadGrowthData);

// New leads by group for the 3D donut (7 / 14 / 30 days): top 5 groups, the rest as "Others", ungrouped as "No group"
$leadGroups = [7 => [], 14 => [], 30 => []];
foreach ([7, 14, 30] as $__days) {
    $__back = $__days - 1;
    $__q = $conn->prepare("SELECT COALESCE(g.name, 'No group') AS name, COUNT(*) AS c
                           FROM contacts c LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
                           WHERE c.company_id = ? AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                           GROUP BY COALESCE(g.name, 'No group') ORDER BY c DESC");
    if (!$__q) continue;
    $__q->bind_param('ii', $companyId, $__back);
    $__q->execute();
    $__r = $__q->get_result();
    $__rows = [];
    while ($__r && ($__x = $__r->fetch_assoc())) { $__rows[] = ['name' => (string)$__x['name'], 'c' => (int)$__x['c']]; }
    $__q->close();
    if (count($__rows) > 6) {
        $__rest = array_sum(array_column(array_slice($__rows, 5), 'c'));
        $__rows = array_slice($__rows, 0, 5);
        $__rows[] = ['name' => 'Others', 'c' => $__rest];
    }
    $leadGroups[$__days] = $__rows;
}

// Daily message volume, last 30 days (incoming vs outgoing)
$msgIn = []; $msgOut = [];
$mvMap = [];
$mv = $conn->prepare("SELECT DATE(created_at) AS d, SUM(direction='incoming') AS i, SUM(direction='outgoing') AS o FROM messages WHERE company_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)");
if ($mv) {
    $mv->bind_param('i', $companyId);
    $mv->execute();
    $mr = $mv->get_result();
    while ($mr && ($x = $mr->fetch_assoc())) { $mvMap[$x['d']] = [(int)$x['i'], (int)$x['o']]; }
}
for ($i = 29; $i >= 0; $i--) {
    $k = date('Y-m-d', strtotime("-$i days"));
    $msgIn[] = $mvMap[$k][0] ?? 0;
    $msgOut[] = $mvMap[$k][1] ?? 0;
}

$sentAll      = $statSent + $statDelivered + $statRead;
$deliveredAll = $statDelivered + $statRead;
$deliveryRate = $sentAll > 0 ? round($deliveredAll / $sentAll * 100, 1) : 0;
$readRate     = $deliveredAll > 0 ? round($statRead / $deliveredAll * 100, 1) : 0;
$failRate     = ($sentAll + $statFailed) > 0 ? round($statFailed / ($sentAll + $statFailed) * 100, 1) : 0;
$replyRatio   = $botIncoming > 0 ? round($botOutgoing / $botIncoming * 100) : 0;

// Top tags
$tagCounts = [];
$tq = $conn->prepare("SELECT tags FROM contacts WHERE company_id=? AND tags IS NOT NULL AND tags <> '' LIMIT 2000");
if ($tq) {
    $tq->bind_param('i', $companyId);
    $tq->execute();
    $tr = $tq->get_result();
    while ($tr && ($row = $tr->fetch_assoc())) {
        foreach (explode(',', (string)$row['tags']) as $t) {
            $t = trim($t);
            if ($t !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $t)) { $k = mb_strtolower($t); $tagCounts[$k] = ['label' => $t, 'n' => ($tagCounts[$k]['n'] ?? 0) + 1]; }
        }
    }
}
uasort($tagCounts, static fn($a, $b) => $b['n'] <=> $a['n']);
$topTags = array_slice(array_values($tagCounts), 0, 6);

$campRows = [];
if ($campaigns) { while ($c = $campaigns->fetch_assoc()) { $campRows[] = $c; } }

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
    'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'tpl'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'gear'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'user'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'tag'    => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
];
$chg = static function (int $now, int $prev): string {
    if ($prev <= 0) { return $now > 0 ? '<span class="db-chip is-up">New</span>' : '<span class="db-chip">0%</span>'; }
    $p = round(($now - $prev) / $prev * 100);
    return '<span class="db-chip ' . ($p >= 0 ? 'is-up' : 'is-down') . '">' . ($p >= 0 ? '&#9650; ' : '&#9660; ') . abs($p) . '%</span>';
};
$maxTag = 1; foreach ($topTags as $t0) { $maxTag = max($maxTag, $t0['n']); }
$daysLeft = $sub ? max(0, (int)((strtotime((string)$sub['expires_at']) - time()) / 86400)) : 0;
?>

<div class="db pax">

  <div class="db-head">
    <div>
      <h1>CRM Analytics</h1>
      <p>Leads, message delivery, campaigns and bot activity for your workspace.</p>
    </div>
    <div class="db-head__actions">
      <?php if (has_feature('campaigns')): ?><a href="campaign_create.php" class="db-btn db-btn--primary"><?php echo $__ic($I['mega'], 16); ?> New campaign</a><?php endif; ?>
    </div>
  </div>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2 id="paTitle">Lead growth</h2>
            <div class="db-legend" id="paLegend"><span><i style="background:#128C7E"></i>New leads: <b id="paSum"><?php echo number_format($leads30); ?></b></span></div>
          </div>
          <div class="db-seg" id="paSeg">
            <button type="button" class="is-active" data-m="leads">Leads</button>
            <button type="button" data-m="msgs">Messages</button>
          </div>
        </div>
        <div class="pax-range" id="paRange">
          <button type="button" data-d="7">7d</button><button type="button" data-d="14">14d</button><button type="button" class="is-active" data-d="30">30d</button>
        </div>
        <div class="pax3d">
          <div class="pax3d__cv"><canvas id="msgChart" aria-label="New leads by group" role="img"></canvas></div>
          <div class="pax3d__legend"><h3 id="paLegHead">New leads by group</h3><div id="paLeg3d"></div></div>
        </div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['users'], 19); ?></div><div class="db-kpi__l">Contacts</div><div class="db-kpi__v"><?php echo number_format($contactCount); ?></div><div class="db-kpi__d">This month <?php echo $chg($newThisMonth, $newLastMonth); ?> +<?php echo number_format($newThisMonth); ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($I['mega'], 19); ?></div><div class="db-kpi__l">Campaigns</div><div class="db-kpi__v"><?php echo number_format($campaignCount); ?></div><div class="db-kpi__d">All time</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['bolt'], 19); ?></div><div class="db-kpi__l">Active drips</div><div class="db-kpi__v"><?php echo number_format($dripCount); ?></div><div class="db-kpi__d">Running automations</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['chat'], 19); ?></div><div class="db-kpi__l">Total messages</div><div class="db-kpi__v"><?php echo number_format($totalMsgLog); ?></div><div class="db-kpi__d"><?php echo number_format($botIncoming); ?> in &middot; <?php echo number_format($botOutgoing); ?> out</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Message status</h2><span class="db-pill <?php echo $deliveryRate >= 90 ? 'is-ok' : ($deliveryRate >= 70 ? 'is-warn' : 'is-bad'); ?>"><?php echo $esc($deliveryRate); ?>% delivered</span></div>
      <div class="db-donut__chart">
        <canvas id="paDonut" width="200" height="200" aria-label="Message status" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($sentAll + $statFailed); ?></b><span>outgoing</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Read: <?php echo number_format($statRead); ?></span>
        <span><i style="background:#3B82F6"></i>Delivered: <?php echo number_format($statDelivered); ?></span>
        <span><i style="background:#F59E0B"></i>Sent: <?php echo number_format($statSent); ?></span>
        <span><i style="background:#E5483A"></i>Failed: <?php echo number_format($statFailed); ?></span>
      </div>
    </section>
  </div>

  <!-- row 2: delivery performance (wide) + plan and shortcuts (right column), like the Dashboard -->
  <div class="db-row db-row--bottom">
    <section class="db-card pax-card pax-fill">
      <div class="pax-ch"><h2>Delivery performance</h2><p>How your messages and broadcasts are doing</p></div>
      <div class="pax-2col">
        <div class="pax-trend">
          <div class="pax-trend__top">
            <h3 class="pax-sh">Delivery over time</h3>
            <label class="pax-sel"><select id="paDYear" aria-label="Year"><option><?php echo (int)date('Y'); ?></option></select></label>
          </div>
          <div class="pax-chips" id="paChips">
            <?php foreach ([['sent', 'Sent', '#F59E0B'], ['delivered', 'Delivered', '#3B82F6'], ['read', 'Read', '#25D366'], ['failed', 'Failed', '#E5483A']] as $i => [$k, $lab, $col]): ?>
              <button type="button" class="pax-chip is-on" data-ds="<?php echo $i; ?>" style="--c:<?php echo $col; ?>">
                <span class="pax-chip__l"><i></i><?php echo $lab; ?></span>
                <b id="paD_<?php echo $k; ?>">0</b>
                <small id="paDn_<?php echo $k; ?>"></small>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="pax-trend__canvas"><canvas id="paDelivery" aria-label="Messages sent, delivered, read and failed per month" role="img"></canvas><div class="pax-trend__empty" id="paDEmpty">No delivery data in this year yet</div></div>
        </div>
        <div>
          <h3 class="pax-sh">Campaign delivery</h3>
          <?php if ($campRows): ?>
            <div class="pax-camps-chart" style="height:<?php echo max(150, count($campRows) * 54 + 30); ?>px"><canvas id="paCamps" aria-label="Delivery rate per campaign" role="img"></canvas></div>
            <p class="pax-camps-note">Share of each campaign's messages that were delivered. Hover a bar for the counts.</p>
          <?php else: ?>
            <div class="pax-none"><?php echo $__ic($I['mega'], 26); ?><b>No campaign activity yet</b><p>Send a broadcast to see delivery results here.</p><?php if (has_feature('campaigns')): ?><a href="campaign_create.php" class="db-btn db-btn--primary">New campaign</a><?php endif; ?></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <div class="pax-stack">
      <section class="db-card">
        <div class="db-card__head"><div><h2>Current plan</h2><p><?php echo $sub ? $esc($sub['plan_name'] ?? 'Plan') : 'No active subscription'; ?></p></div>
          <?php if ($sub): ?><span class="db-pill is-ok"><?php echo $esc(ucfirst((string)$sub['status'])); ?></span><?php endif; ?>
        </div>
        <div class="db-meter">
          <?php if ($sub && !empty($sub['expires_at'])): ?>
            <div class="db-meter__row"><span>Expires</span><b><?php echo $esc(date('d M Y', strtotime((string)$sub['expires_at']))); ?></b></div>
            <div class="db-meter__row" style="border-bottom:0;padding-bottom:0"><span>Days remaining</span><b><?php echo (int)$daysLeft; ?> days</b></div>
            <div class="db-bar"><i style="width:<?php echo min(100, max(3, round($daysLeft / 365 * 100))); ?>%;<?php echo $daysLeft <= 7 ? 'background:#e5484d' : ($daysLeft <= 30 ? 'background:#F59E0B' : ''); ?>"></i></div>
          <?php else: ?>
            <div class="db-meter__row" style="border-bottom:0"><span>Pick a plan to unlock features</span></div>
          <?php endif; ?>
          <a href="subscription.php" class="db-btn db-btn--primary" style="width:100%;justify-content:center;margin-top:14px"><?php echo $sub ? 'Manage subscription' : 'View plans'; ?></a>
        </div>
      </section>

      <section class="db-card">
        <div class="db-card__head"><div><h2>Quick actions</h2></div></div>
        <div class="db-list" style="padding-top:6px">
          <?php if (has_feature('contacts')): ?><a class="db-li" href="contacts.php" style="text-decoration:none;color:inherit"><span class="db-li__ic"><?php echo $__ic($I['users'], 19); ?></span><div class="db-li__t"><b>Contacts</b><small>Manage your audience</small></div><span class="db-li__r"><?php echo $__ic($I['arrow'], 15); ?></span></a><?php endif; ?>
          <?php if (has_feature('campaigns')): ?><a class="db-li" href="campaign_create.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-g"><?php echo $__ic($I['mega'], 19); ?></span><div class="db-li__t"><b>New campaign</b><small>Send a broadcast</small></div><span class="db-li__r"><?php echo $__ic($I['arrow'], 15); ?></span></a><?php endif; ?>
          <?php if (has_feature('whatsapp_chat')): ?><a class="db-li" href="whatsapp.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-amber"><?php echo $__ic($I['chat'], 19); ?></span><div class="db-li__t"><b>WhatsApp chat</b><small>Reply to customers</small></div><span class="db-li__r"><?php echo $__ic($I['arrow'], 15); ?></span></a><?php endif; ?>
          <?php if (has_feature('templates')): ?><a class="db-li" href="templates.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-g"><?php echo $__ic($I['tpl'], 19); ?></span><div class="db-li__t"><b>Templates</b><small>Create and manage</small></div><span class="db-li__r"><?php echo $__ic($I['arrow'], 15); ?></span></a><?php endif; ?>
          <a class="db-li" href="settings_whatsapp.php" style="text-decoration:none;color:inherit"><span class="db-li__ic"><?php echo $__ic($I['gear'], 19); ?></span><div class="db-li__t"><b>WhatsApp setup</b><small>Connect your number</small></div><span class="db-li__r"><?php echo $__ic($I['arrow'], 15); ?></span></a>
        </div>
      </section>
    </div>
  </div>

  <!-- row 3: bot conversations + engagement meters -->
  <div class="db-row db-row--bottom">
    <section class="db-card pax-card pax-fill">
      <div class="pax-ch"><h2>Bot conversations</h2><p>Incoming and outgoing WhatsApp messages</p></div>
      <div class="pax-2col">
        <div>
          <div class="pax-convo">
            <div class="pax-convo__chart">
              <svg id="paBotDonut" viewBox="0 0 200 200" role="img" aria-label="Message split: <?php echo number_format($botIncoming); ?> incoming and <?php echo number_format($botOutgoing); ?> outgoing">
                <circle class="pax-convo__track" cx="100" cy="100" r="84"></circle>
                <?php if ($botTotal > 0): ?>
                  <circle class="pax-convo__arc is-in" data-series="incoming" cx="100" cy="100" r="84" pathLength="1" transform="rotate(-90 100 100)"
                    style="--dasharray:<?php echo number_format($botIncomingDash, 6, '.', ''); ?> <?php echo number_format(1 - $botIncomingDash, 6, '.', ''); ?>;--dash-end:0"></circle>
                  <circle class="pax-convo__arc is-out" data-series="outgoing" cx="100" cy="100" r="84" pathLength="1" transform="rotate(-90 100 100)"
                    style="--dasharray:<?php echo number_format($botOutgoingDash, 6, '.', ''); ?> <?php echo number_format(1 - $botOutgoingDash, 6, '.', ''); ?>;--dash-end:<?php echo number_format($botOutgoingOffset, 6, '.', ''); ?>"></circle>
                <?php endif; ?>
              </svg>
              <div class="pax-convo__center"><b><?php echo number_format($botTotal); ?></b><span>Total messages</span></div>
            </div>
            <div class="pax-convo__details">
              <div class="pax-convo__sync"><i></i>Synced from message history</div>
              <div class="pax-convo__legend">
                <button type="button" class="pax-convo__item" data-series="incoming" aria-label="Incoming: <?php echo number_format($botIncoming); ?> messages, <?php echo $botIncomingShare; ?> percent"><span><i class="is-in"></i>Incoming</span><b><?php echo number_format($botIncoming); ?></b><small><?php echo $botIncomingShare; ?>%</small></button>
                <button type="button" class="pax-convo__item" data-series="outgoing" aria-label="Outgoing: <?php echo number_format($botOutgoing); ?> messages, <?php echo $botOutgoingShare; ?> percent"><span><i class="is-out"></i>Outgoing</span><b><?php echo number_format($botOutgoing); ?></b><small><?php echo $botOutgoingShare; ?>%</small></button>
              </div>
              <div class="pax-convo__ratio"><span>Outgoing / incoming</span><b><?php echo $replyRatio; ?>%</b></div>
            </div>
          </div>
        </div>
        <div>
          <h3 class="pax-sh">Top contact tags</h3>
          <?php if ($topTags): ?>
            <div class="pax-bars">
              <?php foreach ($topTags as $t): ?>
                <div class="pax-bar">
                  <div class="pax-bar__t"><span><i style="background:#128C7E"></i><?php echo $esc($t['label']); ?></span><b><?php echo number_format($t['n']); ?></b></div>
                  <div class="pax-track"><u style="width:<?php echo round($t['n'] / $maxTag * 100); ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="pax-none"><?php echo $__ic($I['tag'], 26); ?><b>No tags yet</b><p>Add tags to contacts to see your top segments.</p></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="db-card">
      <div class="db-card__head"><div><h2>Health check</h2><p>Key rates at a glance</p></div></div>
      <div class="pax-rates">
        <?php foreach ([['Delivery rate', $deliveryRate, true], ['Read rate', $readRate, true], ['Failure rate', $failRate, false], ['Reply ratio', min(100, $replyRatio), true]] as [$l, $v, $good]):
            $col = $good ? ($v >= 80 ? '#25D366' : ($v >= 50 ? '#F59E0B' : '#E5483A')) : ($v <= 5 ? '#25D366' : ($v <= 15 ? '#F59E0B' : '#E5483A')); ?>
          <div class="pax-rate">
            <div class="pax-rate__t"><span><?php echo $l; ?></span><b><?php echo $esc($v); ?>%</b></div>
            <div class="pax-track"><u style="width:<?php echo min(100, max(2, (float)$v)); ?>%;background:<?php echo $col; ?>"></u></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>

<style>
.pax{max-width:none;margin:0}
.pax .db-canvas{height:270px}
.pax .db-donut__chart canvas{max-width:200px;max-height:200px}
.pax-range{display:inline-flex;gap:6px;margin:10px 0 0}
.pax-range button{padding:5px 12px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.pax-range button.is-active{background:#fff;box-shadow:0 1px 5px rgba(18,140,126,.25);color:var(--gd)}
.pax-card{padding:22px}
.pax-ch{margin-bottom:16px}
.pax-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.pax-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.pax-bars,.pax-camps{display:flex;flex-direction:column;gap:12px}
.pax-bar,.pax-camp{padding:13px 15px;border-radius:13px;background:var(--soft)}
.pax-bar__t,.pax-camp__t{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:14px}
.pax-bar__t span{display:inline-flex;align-items:center;gap:8px;font-weight:700;color:var(--tx)}
.pax-bar__t i{width:10px;height:10px;border-radius:3px;display:inline-block}
.pax-bar__t b{color:var(--ink);font-weight:800}
.pax-bar__t small{font-weight:600;color:var(--mu);font-size:12px;margin-left:4px}
.pax-camp__t b{color:var(--ink);font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.pax-camp small{display:block;margin-top:7px;font-size:12.5px;color:var(--mu)}
.pax-track{height:8px;border-radius:99px;background:#fff;overflow:hidden;min-width:0}
.pax-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}
.pax-convo{display:grid;grid-template-columns:minmax(170px,220px) minmax(0,1fr);align-items:center;gap:20px;min-height:230px;padding:20px;border-radius:18px;background:linear-gradient(145deg,#F4FBF7,#F7FAFC);border:1px solid #E7F0EC}
.pax-convo__chart{position:relative;width:min(100%,210px);aspect-ratio:1;margin:auto}
.pax-convo__chart svg{display:block;width:100%;height:100%;overflow:visible}
.pax-convo__track,.pax-convo__arc{fill:none}
.pax-convo__track{stroke:#E3EEE9;stroke-width:20}
.pax-convo__arc{stroke-width:20;stroke-linecap:round;stroke-dasharray:var(--dasharray);stroke-dashoffset:var(--dash-end);transition:stroke-dasharray 1.15s cubic-bezier(.22,.61,.36,1),opacity .25s ease}
.pax-convo__arc.is-in{stroke:#3B82F6}
.pax-convo__arc.is-out{stroke:#25D366}
.pax-convo.is-animated .pax-convo__arc{stroke-dasharray:0 1;opacity:0}
.pax-convo.is-animated.is-in .pax-convo__arc{stroke-dasharray:var(--dasharray);opacity:1;transition-delay:.15s}
.pax-convo.has-active .pax-convo__arc:not(.is-active){opacity:.24}
.pax-convo__item{width:100%}
.pax-convo__item:hover,.pax-convo__item.is-active{background:#fff;transform:translateX(2px)}
.pax-convo__item:focus-visible{outline:3px solid rgba(18,140,126,.35);outline-offset:2px}
.pax-convo__center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.pax-convo__center b{font-size:25px;font-weight:800;line-height:1.15;letter-spacing:-.04em;color:var(--ink);font-variant-numeric:tabular-nums}
.pax-convo__center span{margin-top:4px;font-size:11px;font-weight:700;color:var(--mu)}
.pax-convo__details{min-width:0}
.pax-convo__sync{display:flex;align-items:center;gap:7px;margin-bottom:14px;color:var(--mu);font-size:12px;font-weight:600}
.pax-convo__sync i{width:8px;height:8px;border-radius:50%;background:#25D366;box-shadow:0 0 0 3px rgba(37,211,102,.13)}
.pax-convo__legend{display:flex;flex-direction:column;gap:9px}
.pax-convo__item{display:grid;grid-template-columns:minmax(0,1fr) auto 38px;align-items:center;gap:10px;padding:11px 12px;border:0;border-radius:12px;background:#fff;font:inherit;text-align:left;cursor:default;transition:background .15s,transform .15s}
.pax-convo__item span{display:flex;align-items:center;gap:8px;min-width:0;font-size:13px;font-weight:700;color:var(--tx)}
.pax-convo__item span i{width:10px;height:10px;border-radius:50%;flex:none}
.pax-convo__item span i.is-in{background:#3B82F6}
.pax-convo__item span i.is-out{background:#25D366}
.pax-convo__item b{font-size:15px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.pax-convo__item small{color:var(--mu);font-size:11px;font-weight:700;text-align:right}
.pax-convo__ratio{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;padding:0 2px}
.pax-convo__ratio span{font-size:12px;color:var(--mu);font-weight:600}
.pax-convo__ratio b{color:var(--gd);font-size:14px;font-weight:800}
.pax-stack{display:flex;flex-direction:column;gap:18px;min-width:0}
.pax-sub>div{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
.pax-sub b{font-size:18px;color:var(--ink)}
.pax-sub p{margin:0 0 14px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13.5px;color:var(--tx)}
.pax-sub p svg{color:var(--g)}
.pax-qa{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.pax-qa a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;background:var(--soft);color:var(--ink);font-size:14px;font-weight:700;text-decoration:none;transition:background .15s}
.pax-qa a:hover{background:#E6FCF2}
.pax-qa a span{width:34px;height:34px;border-radius:10px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.pax-fill{display:flex;flex-direction:column}
.pax-2col{display:grid;grid-template-columns:minmax(0,1.75fr) minmax(0,1fr);gap:28px;flex:1}
.pax3d{display:flex;flex-direction:column;gap:6px;min-height:300px}
.pax3d__cv{position:relative;height:300px;min-width:0}
.pax3d__cv canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
#paLeg3d{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2px 14px}
.pax3d__legend h3{margin:0 0 12px;font-size:14px;font-weight:800;color:var(--ink)}
.pax3d__row{display:grid;grid-template-columns:14px minmax(0,1fr) auto auto;align-items:center;gap:9px;padding:8px 10px;border-radius:11px;font-size:13.5px;color:var(--tx);cursor:default;transition:background .15s}
.pax3d__row i{width:12px;height:12px;border-radius:50%}
.pax3d__row span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.pax3d__row b{color:var(--ink);font-weight:800}
.pax3d__row small{color:var(--mu);font-size:12px;min-width:38px;text-align:right}
.pax3d__row:hover,.pax3d__row.is-hot{background:var(--soft)}
.pax3d__none{padding:14px;border-radius:12px;background:var(--soft);color:var(--mu);font-size:13px}
@media (max-width:560px){#paLeg3d{grid-template-columns:1fr}}
.pax-trend{display:flex;flex-direction:column;min-width:0}
.pax-trend__top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.pax-trend__top .pax-sh{margin:0}
.pax-sel select{appearance:none;-webkit-appearance:none;height:38px;min-width:104px;padding:0 36px 0 14px;border:0;border-radius:12px;background:#F1F6F4 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 11px center/16px;font:600 14px Inter,system-ui,sans-serif;color:#0F2622;cursor:pointer;outline:0;transition:background-color .15s,box-shadow .15s}
.pax-sel select:hover{background-color:#E6FCF2}.pax-sel select:focus{box-shadow:0 0 0 4px rgba(18,140,126,.14)}
.pax-chips{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:8px}
.pax-chip{display:flex;flex-direction:column;align-items:flex-start;gap:1px;padding:11px 13px;border:2px solid transparent;border-radius:14px;background:var(--soft);text-align:left;cursor:pointer;font-family:inherit;color:var(--ink);transition:background .15s,opacity .15s,border-color .15s}
.pax-chip:hover{background:#E9F8F1}
.pax-chip__l{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--tx)}
.pax-chip__l i{width:10px;height:10px;border-radius:50%;background:var(--c)}
.pax-chip b{font-size:21px;font-weight:800;letter-spacing:-.01em}
.pax-chip small{font-size:11.5px;color:var(--mu);font-weight:600;min-height:14px}
.pax-chip:not(.is-on){opacity:.5;background:#F4F6F5}.pax-chip:not(.is-on) .pax-chip__l i{background:#C4CFCB}
.pax-trend__canvas{position:relative;flex:1;min-height:250px}
.pax-trend__canvas canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
.pax-trend__empty{position:absolute;inset:0;display:none;align-items:center;justify-content:center;font-size:14px;color:#8a9a95;pointer-events:none}
.pax-camps-chart{position:relative}
.pax-camps-chart canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
.pax-camps-note{margin:10px 0 0;font-size:12.5px;color:var(--mu)}
@media (max-width:560px){.pax-chips{grid-template-columns:repeat(2,minmax(0,1fr))}}
.pax-sh{margin:0 0 12px;font-size:13px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--mu)}
.pax-none{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;padding:28px 16px;border-radius:14px;background:var(--soft);color:var(--g);height:calc(100% - 28px);min-height:180px}
.pax-none b{color:var(--ink);font-size:15px}
.pax-none p{margin:0 0 8px;color:var(--mu);font-size:13.5px;max-width:240px;line-height:1.5}
.pax-rates{display:flex;flex-direction:column;gap:14px;padding:6px 20px 22px}
.pax-rate{padding:13px 15px;border-radius:13px;background:var(--soft)}
.pax-rate__t{display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;font-weight:700;color:var(--tx)}
.pax-rate__t b{color:var(--ink);font-weight:800}
@media (max-width:1000px){.pax-2col{grid-template-columns:1fr}}
@media (max-width:1100px){.pax .db-row{grid-template-columns:1fr}}
@media (max-width:700px){.pax-convo{grid-template-columns:minmax(130px,180px) minmax(0,1fr);gap:14px;padding:16px}}
@media (max-width:560px){.pax-qa{grid-template-columns:1fr}.pax-convo{grid-template-columns:1fr}.pax-convo__chart{width:min(100%,190px)}.pax-convo__details{width:100%}}
@media (prefers-reduced-motion:reduce){.pax-convo__arc,.pax-convo__item{transition:none!important}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../assets/js/donut3d.js?v=1"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') { return; }
  var labels = <?php echo json_encode($leadGrowthLabels); ?>;
  var leads = <?php echo json_encode($leadGrowthData); ?>, mIn = <?php echo json_encode($msgIn); ?>, mOut = <?php echo json_encode($msgOut); ?>;
  var mode = 'leads', days = 30, $ = function (id) { return document.getElementById(id); };
  var cv = $('msgChart');
  var PALETTE = ['#128C7E', '#3B82F6', '#F59E0B', '#8B5CF6', '#EC4899', '#14B8A6'];
  var leadGroups = <?php echo json_encode($leadGroups, JSON_UNESCAPED_UNICODE); ?>;
  var tail = function (a) { return a.slice(a.length - days); };
  function sum(a) { return a.reduce(function (x, y) { return x + y; }, 0); }
  var items = [];
  var donut = Donut3D(cv, { flat: true, onHover: function (i) { var rows = document.querySelectorAll('#paLeg3d .pax3d__row'); rows.forEach(function (r, k) { r.classList.toggle('is-hot', k === i); }); } });
  function render() {
    var caption, head, total, legendTop;
    if (mode === 'leads') {
      var rows = leadGroups[days] || [];
      items = rows.map(function (r, i) { return { label: r.name, value: r.c, color: r.name === 'No group' ? '#94A3B8' : (r.name === 'Others' ? '#CBD5E1' : PALETTE[i % PALETTE.length]) }; });
      caption = 'new leads'; head = 'New leads by group'; $('paTitle').textContent = 'Lead growth';
    } else {
      items = [{ label: 'Incoming', value: sum(tail(mIn)), color: '#3B82F6' }, { label: 'Outgoing', value: sum(tail(mOut)), color: '#25D366' }];
      caption = 'messages'; head = 'Messages by direction'; $('paTitle').textContent = 'Message volume';
    }
    total = sum(items.map(function (d) { return d.value; }));
    $('paLegHead').textContent = head + ' · last ' + days + ' days';
    $('paLegend').innerHTML = items.length ? items.map(function (d) { return '<span><i style="background:' + d.color + '"></i>' + d.label.replace(/</g, '&lt;') + ': <b>' + d.value.toLocaleString() + '</b></span>'; }).slice(0, 2).join('') : '<span><i style="background:#128C7E"></i>' + (mode === 'leads' ? 'New leads' : 'Messages') + ': <b>0</b></span>';
    $('paLeg3d').innerHTML = total === 0 ? '<div class="pax3d__none">Nothing in the last ' + days + ' days yet.</div>' : items.map(function (d, i) {
      return '<div class="pax3d__row" data-i="' + i + '"><i style="background:' + d.color + '"></i><span>' + d.label.replace(/</g, '&lt;') + '</span><b>' + d.value.toLocaleString() + '</b><small>' + Math.round(d.value / total * 1000) / 10 + '%</small></div>';
    }).join('');
    donut.set(items, { center: { value: total.toLocaleString(), caption: caption } });
  }
  $('paLeg3d').addEventListener('mouseover', function (e) { var r = e.target.closest('.pax3d__row'); donut.highlight(r ? +r.dataset.i : -1); });
  $('paLeg3d').addEventListener('mouseleave', function () { donut.highlight(-1); });
  $('paSeg').addEventListener('click', function (e) { var b = e.target.closest('button[data-m]'); if (!b) { return; } mode = b.getAttribute('data-m'); this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); render(); });
  $('paRange').addEventListener('click', function (e) { var b = e.target.closest('button[data-d]'); if (!b) { return; } days = parseInt(b.getAttribute('data-d'), 10); this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); render(); });
  render();

  var vals = [<?php echo (int)$statRead; ?>, <?php echo (int)$statDelivered; ?>, <?php echo (int)$statSent; ?>, <?php echo (int)$statFailed; ?>];
  var total = sum(vals);
  new Chart($('paDonut'), {type: 'doughnut',
    data: {labels: ['Read', 'Delivered', 'Sent', 'Failed'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#3B82F6', '#F59E0B', '#E5483A'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});

})();
</script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var chart = document.querySelector('.pax-convo');
  if (!chart) return;
  var items = Array.prototype.slice.call(chart.querySelectorAll('.pax-convo__item'));
  var arcs = Array.prototype.slice.call(chart.querySelectorAll('.pax-convo__arc'));

  function highlight(series) {
    chart.classList.toggle('has-active', !!series);
    items.forEach(function (item) {
      var active = item.getAttribute('data-series') === series;
      item.classList.toggle('is-active', !!series && active);
    });
    arcs.forEach(function (arc) {
      arc.classList.toggle('is-active', !!series && arc.getAttribute('data-series') === series);
    });
  }

  items.forEach(function (item) {
    item.addEventListener('pointerenter', function (event) {
      if (event.pointerType !== 'touch') highlight(item.getAttribute('data-series'));
    });
    item.addEventListener('pointerleave', function (event) {
      if (event.pointerType !== 'touch') highlight('');
    });
    item.addEventListener('focus', function () { highlight(item.getAttribute('data-series')); });
    item.addEventListener('blur', function () { highlight(''); });
  });

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reducedMotion || !('IntersectionObserver' in window)) {
    chart.classList.add('is-in');
    return;
  }

  chart.classList.add('is-animated');
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      chart.classList.toggle('is-in', entry.isIntersecting && entry.intersectionRatio >= 0.3);
    });
  }, { threshold: [0, 0.3] });
  observer.observe(chart);
})();
</script>
<?php
$__camp = ['labels' => [], 'rate' => [], 'delivered' => [], 'sent' => []];
foreach ($campRows as $__c) {
    $__camp['labels'][] = (string)$__c['name'];
    $__camp['sent'][] = (int)$__c['total_sent'];
    $__camp['delivered'][] = (int)$__c['total_success'];
    $__camp['rate'][] = $__c['total_sent'] > 0 ? (int)min(100, round($__c['total_success'] / $__c['total_sent'] * 100)) : 0;
}
?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') return;
  var $ = function (id) { return document.getElementById(id); };
  var fmt = function (v) { return v >= 1000000 ? +(v / 1000000).toFixed(1) + 'M' : (v >= 1000 ? +(v / 1000).toFixed(1) + 'K' : String(v)); };
  var tip = { backgroundColor: '#0F2622', padding: { x: 12, y: 8 }, cornerRadius: 8, displayColors: false, titleFont: { size: 11, weight: '600' }, bodyFont: { size: 14, weight: '800' } };

  /* Delivery over time: real monthly counts from client/delivery_trend.php */
  var cv = $('paDelivery'), sel = $('paDYear');
  if (cv && sel) {
    var mk = function (label, color) { return { label: label, data: [], borderColor: color, backgroundColor: color, cubicInterpolationMode: 'monotone', borderWidth: 2.4, pointRadius: 4, pointHoverRadius: 6, pointBorderWidth: 0, spanGaps: false }; };
    var chart = new Chart(cv, {
      type: 'line', data: { labels: [], datasets: [mk('Sent', '#F59E0B'), mk('Delivered', '#3B82F6'), mk('Read', '#25D366'), mk('Failed', '#E5483A')] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'nearest', intersect: true },
        plugins: { legend: { display: false }, tooltip: Object.assign({}, tip, { callbacks: { title: function (i) { return i[0].label.toUpperCase() + ' · ' + i[0].dataset.label.toUpperCase(); }, label: function (c) { return c.parsed.y.toLocaleString('en-IN') + ' messages'; } } }) },
        scales: { x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                  y: { beginAtZero: true, border: { display: false }, grid: { color: '#EEF3F1' }, ticks: { precision: 0, maxTicksLimit: 5, font: { size: 12 }, callback: function (v) { return fmt(v); } } } } }
    });
    var tot = function (a) { return a.reduce(function (x, v) { return x + (v || 0); }, 0); };
    var pct = function (a, b) { return b > 0 ? (Math.round(a / b * 1000) / 10) + '%' : '0%'; };
    var load = function (year) {
      fetch('delivery_trend.php' + (year ? '?year=' + encodeURIComponent(year) : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.labels) return;
        if (sel.options.length !== d.years.length || sel.value !== String(d.year)) sel.innerHTML = d.years.slice().reverse().map(function (y) { return '<option value="' + y + '"' + (y === d.year ? ' selected' : '') + '>' + y + '</option>'; }).join('');
        chart.data.labels = d.labels; chart.data.datasets[0].data = d.sent; chart.data.datasets[1].data = d.delivered; chart.data.datasets[2].data = d.read; chart.data.datasets[3].data = d.failed; chart.update();
        var s = tot(d.sent), dl = tot(d.delivered), rd = tot(d.read), f = tot(d.failed);
        $('paD_sent').textContent = s.toLocaleString('en-IN'); $('paDn_sent').textContent = 'left WAAMINI';
        $('paD_delivered').textContent = dl.toLocaleString('en-IN'); $('paDn_delivered').textContent = pct(dl, s) + ' of sent';
        $('paD_read').textContent = rd.toLocaleString('en-IN'); $('paDn_read').textContent = pct(rd, dl) + ' of delivered';
        $('paD_failed').textContent = f.toLocaleString('en-IN'); $('paDn_failed').textContent = pct(f, s + f) + ' failed';
        $('paDEmpty').style.display = (s + f) === 0 ? 'flex' : 'none';
      }).catch(function () {});
    };
    $('paChips').addEventListener('click', function (e) {
      var b = e.target.closest('.pax-chip'); if (!b) return;
      var i = +b.dataset.ds, on = b.classList.toggle('is-on'); chart.setDatasetVisibility(i, on); chart.update();
    });
    sel.addEventListener('change', function () { load(sel.value); });
    load('');
  }

  /* Campaign delivery: delivery rate per campaign */
  var cc = $('paCamps');
  if (cc) {
    var C = <?php echo json_encode($__camp, JSON_UNESCAPED_UNICODE); ?>;
    var color = function (r) { return r >= 80 ? '#25D366' : (r >= 50 ? '#F59E0B' : '#E5483A'); };
    new Chart(cc, {
      type: 'bar',
      data: { labels: C.labels.map(function (n) { return n.length > 18 ? n.slice(0, 17) + '\u2026' : n; }), datasets: [{ data: C.rate, backgroundColor: C.rate.map(color), borderRadius: 8, barThickness: 22 }] },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: Object.assign({}, tip, { callbacks: { title: function (i) { return C.labels[i[0].dataIndex].toUpperCase(); }, label: function (c) { return C.rate[c.dataIndex] + '% delivered (' + C.delivered[c.dataIndex].toLocaleString('en-IN') + ' of ' + C.sent[c.dataIndex].toLocaleString('en-IN') + ' sent)'; } } }) },
        scales: { x: { min: 0, max: 100, border: { display: false }, grid: { color: '#EEF3F1' }, ticks: { stepSize: 25, callback: function (v) { return v + '%'; }, font: { size: 12 } } },
                  y: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12.5, weight: '600' }, color: '#34453f' } } } }
    });
  }
})();
</script>

<?php include '../includes/client_footer.php'; ?>
