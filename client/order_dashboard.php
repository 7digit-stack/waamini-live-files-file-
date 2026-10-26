<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('ec_analytics')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client menu pages
include_once '../includes/schema_guard.php';
$pageTitle = 'Order Analytics Dashboard';
$currentPage = 'order_dashboard';

// Optional column (older DBs): analytics UI expects a source tag
// Run ONCE via schema guard instead of on every request
schema_ensure('order_dashboard_schema', function() use ($conn) {
    try {
        // MariaDB 10.4 does not support "ADD COLUMN IF NOT EXISTS" reliably across setups
        $chk = @$conn->query("SHOW COLUMNS FROM ecommerce_orders LIKE 'order_source'");
        if (!$chk || $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE ecommerce_orders ADD COLUMN order_source VARCHAR(64) DEFAULT NULL");
        }
    } catch (\Throwable $e) {}
});

// --- Metrics Calculation ---
$todayDate   = date('Y-m-d');
// Rolling "last 7 days" = today + previous 6 calendar days
$chartStart  = date('Y-m-d', strtotime('-6 days'));
$monthAgo    = date('Y-m-d', strtotime('-30 days'));

// Fixed 7-day axis (today + prior 6 days) — used even when queries fail
$chartDates = [];
for ($i = 6; $i >= 0; $i--) {
    $chartDates[] = date('Y-m-d', strtotime('-' . $i . ' days'));
}

$rev = ['rev_today' => 0.0, 'rev_week' => 0.0, 'rev_month' => 0.0, 'paid_orders_month' => 0];
$srcMap = [];
$statusMap = [];
$labels = [];
$botTrend = [];
$manTrend = [];
$dashDbError = null;
$botRevMonth = 0.0;

try {
    // 1. Revenue Summaries (paid orders only)
    $stmt_rev = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status = 'paid' AND created_at >= ? THEN amount ELSE 0 END) AS rev_today,
            SUM(CASE WHEN status = 'paid' AND DATE(created_at) >= ? THEN amount ELSE 0 END) AS rev_week,
            SUM(CASE WHEN status = 'paid' AND created_at >= ? THEN amount ELSE 0 END) AS rev_month,
            SUM(CASE WHEN status = 'paid' AND created_at >= ? THEN 1 ELSE 0 END) AS paid_orders_month
        FROM ecommerce_orders WHERE company_id = ?"
    );
    if (!$stmt_rev) {
        throw new \RuntimeException($conn->error ?: 'prepare failed');
    }
    // rev_week: same 7-day window as charts ($chartStart .. today)
    $stmt_rev->bind_param('ssssi', $todayDate, $chartStart, $monthAgo, $monthAgo, $companyId);
    $stmt_rev->execute();
    $rev = $stmt_rev->get_result()->fetch_assoc() ?: $rev;

    // 1b. Revenue by Source (lifetime paid — for source panel totals)
    $stmt_src = $conn->prepare(
        "SELECT COALESCE(NULLIF(TRIM(order_source), ''), 'Manual') AS src, SUM(amount) AS total
         FROM ecommerce_orders WHERE company_id = ? AND status = 'paid' GROUP BY src"
    );
    if ($stmt_src) {
        $stmt_src->bind_param('i', $companyId);
        $stmt_src->execute();
        $srcRes = $stmt_src->get_result();
        while ($s = $srcRes->fetch_assoc()) {
            $srcMap[$s['src'] ?: 'Manual'] = (float)$s['total'];
        }
    }

    // Bot share (last 30 days, paid only) — consistent window with rev_month
    $botRevMonth = 0.0;
    $stmt_bot_m = $conn->prepare(
        "SELECT SUM(amount) AS b FROM ecommerce_orders
         WHERE company_id = ? AND status = 'paid' AND created_at >= ?
           AND COALESCE(NULLIF(TRIM(order_source), ''), 'Manual') = 'Bot'"
    );
    if ($stmt_bot_m) {
        $stmt_bot_m->bind_param('is', $companyId, $monthAgo);
        $stmt_bot_m->execute();
        $botRevMonth = (float)($stmt_bot_m->get_result()->fetch_assoc()['b'] ?? 0);
    }

    // 2. Status Breakdown (all orders)
    $stmt_st = $conn->prepare('SELECT status, COUNT(*) AS cnt FROM ecommerce_orders WHERE company_id = ? GROUP BY status');
    if ($stmt_st) {
        $stmt_st->bind_param('i', $companyId);
        $stmt_st->execute();
        $statuses = $stmt_st->get_result();
        while ($s = $statuses->fetch_assoc()) {
            $statusMap[strtolower(trim((string)$s['status']))] = (int)$s['cnt'];
        }
    }

    // 3 & 4. Last 7 days — fixed calendar slots (fills zeros for quiet days)
    $lastChart = $chartDates[6];

    $stmt_src_trend = $conn->prepare(
        "SELECT DATE(created_at) AS d,
                COALESCE(NULLIF(TRIM(order_source), ''), 'Manual') AS src,
                SUM(amount) AS s_rev
         FROM ecommerce_orders
         WHERE company_id = ? AND status = 'paid'
           AND DATE(created_at) >= ? AND DATE(created_at) <= ?
         GROUP BY DATE(created_at), src"
    );
    $srcTrendMap = [];
    if ($stmt_src_trend) {
        $stmt_src_trend->bind_param('iss', $companyId, $chartDates[0], $lastChart);
        $stmt_src_trend->execute();
        $srcTrendDataList = $stmt_src_trend->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($srcTrendDataList as $st) {
            $srcTrendMap[$st['d']][$st['src'] ?: 'Manual'] = (float)$st['s_rev'];
        }
    }
    foreach ($chartDates as $dt) {
        $botTrend[] = $srcTrendMap[$dt]['Bot'] ?? 0.0;
        $manTrend[] = ($srcTrendMap[$dt]['Manual'] ?? 0.0) + ($srcTrendMap[$dt]['External'] ?? 0.0);
    }
    foreach ($chartDates as $d) {
        $labels[] = date('D', strtotime($d));
    }
} catch (\Throwable $e) {
    $dashDbError = 'Order analytics could not load. Ensure ecommerce tables exist (run migrations / create ecommerce_orders).';
    error_log('[WAAMINI order_dashboard] ' . $e->getMessage());
}

// Safe chart fallbacks if DB partially failed
if (count($labels) !== 7 || count($botTrend) !== 7 || count($manTrend) !== 7) {
    $labels = [];
    $botTrend = [];
    $manTrend = [];
    foreach ($chartDates as $d) {
        $labels[] = date('D', strtotime($d));
        $botTrend[] = 0.0;
        $manTrend[] = 0.0;
    }
}

$totalAllOrders  = array_sum($statusMap);
$paidOrdersMonth = (int)($rev['paid_orders_month'] ?? 0);
$revMonthNum     = (float)($rev['rev_month'] ?? 0);

// Bottom-row charts: previous 7 days revenue (for the change chip) and orders per day (read-only)
$revPrevWeek = 0.0;
$ordTrend    = array_fill(0, 7, 0);
$paidTrend   = array_fill(0, 7, 0);
try {
    $prevStart = date('Y-m-d', strtotime('-13 days'));
    $prevEnd   = date('Y-m-d', strtotime('-7 days'));
    $stPrev = $conn->prepare("SELECT SUM(amount) FROM ecommerce_orders WHERE company_id = ? AND status = 'paid' AND DATE(created_at) BETWEEN ? AND ?");
    if ($stPrev) {
        $stPrev->bind_param('iss', $companyId, $prevStart, $prevEnd);
        $stPrev->execute();
        $revPrevWeek = (float)($stPrev->get_result()->fetch_row()[0] ?? 0);
    }
    $stDay = $conn->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM(status = 'paid') AS p FROM ecommerce_orders WHERE company_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)");
    if ($stDay) {
        $stDay->bind_param('iss', $companyId, $chartDates[0], $chartDates[6]);
        $stDay->execute();
        $byDay = [];
        foreach ($stDay->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $byDay[$r['d']] = $r;
        foreach ($chartDates as $i => $dt) {
            $ordTrend[$i]  = (int)($byDay[$dt]['n'] ?? 0);
            $paidTrend[$i] = (int)($byDay[$dt]['p'] ?? 0);
        }
    }
} catch (\Throwable $e) {
    error_log('[WAAMINI order_dashboard] bottom charts: ' . $e->getMessage());
}

include '../includes/client_header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="../assets/css/wm-ring.css?v=1">

<style>
/* Order Analytics — full-width dashboard look: soft tiles, no borders or divider lines */
.odash-wrap { --accent:#128C7E; --accent-light:#E6FCF2; --g:#128C7E; --gd:#0B6B5E; --ink:#0F2622; --tx:#34453f; --mu:#586a63; --soft:#F3FBF7;
    max-width: none; width: 100%; margin: 0; padding: 4px 0 28px; color: var(--ink); }
.odash-wrap *, .odash-wrap *::before, .odash-wrap *::after { box-sizing: border-box; }

/* Header */
.odash-hdr { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;
    background:#fff; border:0; border-radius:18px; padding:22px 26px; margin-bottom:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.odash-hdr::before { display:none; }
.odash-hdr-left { display:flex; align-items:center; gap:16px; }
.odash-hdr-icon { width:52px; height:52px; border-radius:14px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; flex:none; }
.odash-hdr-icon svg { width:24px; height:24px; }
.odash-hdr-title { font-size:24px; font-weight:800; letter-spacing:-.4px; color:var(--ink); margin:0 0 4px; }
.odash-hdr-sub { font-size:14px; color:var(--tx); margin:0; }
.odash-hdr-right { display:flex; gap:10px; flex-wrap:wrap; }
.odash-hdr-right .btn { display:inline-flex; align-items:center; gap:7px; height:42px; padding:0 18px; border-radius:12px; border:0; font-weight:700; background:#E6FCF2; color:var(--gd); }
.odash-hdr-right .btn:hover { background:#CDF5E3; }
.odash-hdr-right .btn-primary { background:var(--g); color:#fff; }
.odash-hdr-right .btn-primary:hover { background:var(--gd); }
.odash-hdr-right .btn svg { width:16px; height:16px; }

/* KPI tiles */
.odash-kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:18px; }
.odash-kpi { background:#fff; border:0; border-radius:18px; padding:20px 22px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); position:relative; overflow:hidden; transition:box-shadow .2s ease, transform .2s ease; }
.odash-kpi::before { display:none; }
.odash-kpi:hover { box-shadow:0 18px 38px -22px rgba(15,38,34,.5); transform:translateY(-2px); }
.odash-kpi-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.odash-kpi-icon { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; background:var(--kpi-bg, var(--soft)); color:var(--kpi-clr, var(--g)); }
.odash-kpi-icon svg { width:21px; height:21px; }
.odash-kpi-badge { font-size:11.5px; font-weight:800; padding:4px 11px; border-radius:999px; background:var(--kpi-bg, var(--soft)); color:var(--kpi-clr, var(--g)); }
.odash-kpi-val { font-size:30px; font-weight:800; color:var(--ink); letter-spacing:-.04em; line-height:1.05; margin-bottom:5px; }
.odash-kpi-label { font-size:12.5px; font-weight:700; color:var(--mu); text-transform:none; letter-spacing:0; }
.odash-kpi-foot { margin-top:14px; padding-top:0; border-top:0; }
.odash-kpi-foot a { font-size:13px; font-weight:700; color:var(--kpi-clr, var(--g)); text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.odash-kpi-foot a:hover { opacity:.75; }
.odash-kpi-foot a svg { width:12px; height:12px; }
.odash-kpi-foot span { font-size:12.5px !important; color:var(--mu) !important; }
.odash-kpi-wm { display:none; }

/* Layout rows: equal-height cards, no empty gaps */
.odash-charts-grid { display:grid; grid-template-columns:minmax(0,1fr) 380px; gap:16px; margin-bottom:18px; align-items:stretch; }
.odash-bottom-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; align-items:stretch; }

/* Cards */
.odash-card { background:#fff; border:0; border-radius:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); overflow:hidden; display:flex; flex-direction:column; }
.odash-card-hdr { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:20px 24px 0; border-bottom:0; background:transparent; }
.odash-card-title { font-size:17px; font-weight:800; color:var(--ink); letter-spacing:-.2px; }
.odash-card-hdr div[style] { font-size:13px !important; color:var(--mu) !important; margin-top:3px !important; }
.odash-card-body { padding:18px 24px 24px; flex:1; display:flex; flex-direction:column; }

.odash-live { display:inline-flex; align-items:center; gap:7px; padding:5px 12px; border-radius:999px; font-size:12px; font-weight:800; background:#E3F7EE; color:var(--gd); border:0; }
.odash-live::before { content:''; width:7px; height:7px; border-radius:50%; background:#25D366; animation:odashPulse 1.6s infinite; }
@keyframes odashPulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.35; transform:scale(.75); } }

.odash-legend { display:flex; gap:18px; margin-bottom:12px; }
.odash-legend-item { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--tx); }
.odash-ldot { width:11px; height:11px; border-radius:4px; flex-shrink:0; }
.odash-card-body canvas { flex:1; min-height:240px; }

/* Doughnut + status list */
.odash-donut-wrap { position:relative; max-width:210px; margin:0 auto 18px; width:100%; }
.odash-donut-wrap canvas { min-height:0; flex:none; }
.odash-donut-center { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; pointer-events:none; }
.odash-dc-num { font-size:28px; font-weight:800; color:var(--ink); display:block; letter-spacing:-.03em; }
.odash-dc-label { font-size:11px; font-weight:800; text-transform:uppercase; color:var(--mu); letter-spacing:.07em; }
.odash-status-list { display:flex; flex-direction:column; gap:8px; }
.odash-sll-row { display:flex; align-items:center; justify-content:space-between; background:var(--soft); border-radius:12px; padding:10px 14px; }
.odash-sll-left { display:flex; align-items:center; gap:9px; font-size:13.5px; font-weight:700; color:var(--tx); }
.odash-sll-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.odash-sll-cnt { font-size:15px; font-weight:800; color:var(--ink); }

/* Revenue by source */

/* Intelligence summary */

.odash-error { background:#FFF6E5; border:0; border-radius:14px; padding:14px 18px; margin-bottom:18px; font-size:13.5px; color:var(--tx); }
.odash-error strong { color:#b7791f; }

@media (max-width:1200px) { .odash-charts-grid { grid-template-columns:minmax(0,1fr); } }
@media (max-width:1100px) { .odash-kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .odash-bottom-grid { grid-template-columns:minmax(0,1fr); } }
@media (max-width:640px) { .odash-kpi-grid { grid-template-columns:minmax(0,1fr); } .odash-hdr { padding:18px; } .odash-hdr-title { font-size:21px; } }
/* Revenue Pulse card: ring overview (assets/css/wm-ring.css) */
.odash-pulse { position:relative; padding:18px 20px 20px; display:flex; flex-direction:column; justify-content:center; }
.odash-pulse__badge { position:absolute; top:16px; right:18px; z-index:3; }
.odash-pulse .wm-flow--card .wm-flow__svg { max-height:440px; }
@media (max-width:820px) { .odash-pulse__badge { position:static; align-self:flex-end; } }

/* Bottom chart cards: title + range, one figure with a change chip, chart, dot legend */
.oc-card { padding:20px 22px 18px; display:flex; flex-direction:column; }
.oc-head { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.oc-head h3 { margin:0; font-size:15px; font-weight:700; color:var(--ink, #0F2622); letter-spacing:-.01em; }
.oc-range { padding:6px 12px; border-radius:9px; border:1px solid #E3EBE8; font-size:12.5px; font-weight:600; color:#4A5B55; white-space:nowrap; }
.oc-figure { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:16px; }
.oc-figure b { font-size:28px; font-weight:700; letter-spacing:-.02em; color:var(--ink, #0F2622); font-variant-numeric:tabular-nums; line-height:1.1; }
.oc-chip { display:inline-flex; align-items:center; gap:3px; padding:3px 8px; border-radius:7px; font-size:12px; font-weight:700; white-space:nowrap; }
.oc-chip.is-up { background:#E3F6EC; color:#15803D; }
.oc-chip.is-down { background:#FDECEA; color:#C0372B; }
.oc-chip.is-flat { background:#F0F3F2; color:#5F6F6A; font-weight:600; }
.oc-sub { margin:4px 0 0; font-size:13px; color:#7A8A85; }
.oc-chart { position:relative; height:220px; margin-top:16px; }
.oc-legend { display:flex; justify-content:center; flex-wrap:wrap; gap:6px 20px; margin-top:12px; font-size:12.5px; color:#6B7A75; }
.oc-legend span { display:inline-flex; align-items:center; gap:7px; }
.oc-legend i { width:8px; height:8px; border-radius:50%; }
.oc-legend b { color:var(--ink, #0F2622); font-weight:700; }
.oc-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin-top:16px; }
.oc-stats div { padding:10px 12px; border-radius:11px; background:#F6FAF8; }
.oc-stats small { display:block; font-size:11.5px; color:#7A8A85; }
.oc-stats b { display:block; margin-top:2px; font-size:16px; font-weight:700; color:var(--ink, #0F2622); font-variant-numeric:tabular-nums; }
.oc-stats b.is-bad { color:#C0372B; }
.oc-empty { position:absolute; left:50%; top:42%; transform:translate(-50%,-50%); padding:7px 14px; border-radius:9px; background:#F6FAF8; font-size:12.5px; font-weight:600; color:#6B7A75; white-space:nowrap; pointer-events:none; }
@media (max-width:640px) { .oc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .oc-figure b { font-size:24px; } }
</style>

<div class="odash-wrap">

    <?php if ($dashDbError !== null): ?>
    <div class="odash-error">
        <strong>Notice:</strong> <?php echo htmlspecialchars($dashDbError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="odash-hdr">
        <div class="odash-hdr-left">
            <div class="odash-hdr-icon"><?php echo cn_svg('orders_chart'); ?></div>
            <div>
                <div class="odash-hdr-title">Order Analytics</div>
                <div class="odash-hdr-sub">Real-time insights for your WhatsApp commerce engine</div>
            </div>
        </div>
        <div class="odash-hdr-right">
            <a href="orders.php" class="btn" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
                <?php echo cn_svg('package'); ?> All Orders
            </a>
            <a href="ecommerce_settings.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
                <?php echo cn_svg('settings'); ?> Payment Config
            </a>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="odash-kpi-grid">

        <!-- Revenue Today -->
        <div class="odash-kpi" style="--kpi-accent:var(--success);--kpi-bg:var(--success-bg);--kpi-clr:var(--success);">
            <div class="odash-kpi-top">
                <div class="odash-kpi-icon"><?php echo cn_svg('sales'); ?></div>
                <div class="odash-kpi-badge">Today</div>
            </div>
            <div class="odash-kpi-val">₹<?php echo number_format($rev['rev_today'] ?? 0, 2); ?></div>
            <div class="odash-kpi-label">Revenue Today</div>
            <div class="odash-kpi-foot">
                <a href="orders.php">View Orders <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>
            </div>
            <div class="odash-kpi-wm" aria-hidden="true"><?php echo cn_svg('sales'); ?></div>
        </div>

        <!-- Weekly Revenue -->
        <div class="odash-kpi" style="--kpi-accent:var(--accent);--kpi-bg:var(--accent-light);--kpi-clr:var(--accent);">
            <div class="odash-kpi-top">
                <div class="odash-kpi-icon"><?php echo cn_svg('growth'); ?></div>
                <div class="odash-kpi-badge">7 Days</div>
            </div>
            <div class="odash-kpi-val">₹<?php echo number_format($rev['rev_week'] ?? 0, 0); ?></div>
            <div class="odash-kpi-label">Weekly Revenue</div>
            <div class="odash-kpi-foot">
                <a href="orders.php">Trend View <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>
            </div>
            <div class="odash-kpi-wm" aria-hidden="true"><?php echo cn_svg('growth'); ?></div>
        </div>

        <!-- Bot Revenue -->
        <div class="odash-kpi" style="--kpi-accent:#8b5cf6;--kpi-bg:rgba(139,92,246,0.1);--kpi-clr:#8b5cf6;">
            <div class="odash-kpi-top">
                <div class="odash-kpi-icon"><?php echo cn_svg('ai'); ?></div>
                <div class="odash-kpi-badge">AI</div>
            </div>
            <div class="odash-kpi-val">₹<?php echo number_format($srcMap['Bot'] ?? 0, 0); ?></div>
            <div class="odash-kpi-label">Bot Revenue</div>
            <div class="odash-kpi-foot">
                <span style="font-size:11px;color:var(--text-muted);">Bot-attributed paid revenue (all time)</span>
            </div>
            <div class="odash-kpi-wm" aria-hidden="true"><?php echo cn_svg('ai'); ?></div>
        </div>

        <!-- Pending Fulfillment -->
        <div class="odash-kpi" style="--kpi-accent:var(--warning);--kpi-bg:rgba(245,158,11,0.1);--kpi-clr:var(--warning);">
            <div class="odash-kpi-top">
                <div class="odash-kpi-icon"><?php echo cn_svg('automations'); ?></div>
                <div class="odash-kpi-badge">Action</div>
            </div>
            <div class="odash-kpi-val"><?php echo $statusMap['pending'] ?? 0; ?></div>
            <div class="odash-kpi-label">Pending Fulfillment</div>
            <div class="odash-kpi-foot">
                <a href="orders.php?status=pending">Process Now <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>
            </div>
            <div class="odash-kpi-wm" aria-hidden="true"><?php echo cn_svg('automations'); ?></div>
        </div>

    </div>

    <!-- Charts Row -->
    <div class="odash-charts-grid">

        <!-- Revenue Pulse: same ring overview as the Dashboard "Activity overview" -->
        <?php
        $__inrShort = static function (float $v): string {
            if ($v >= 10000000) return '₹' . rtrim(rtrim(number_format($v / 10000000, 1), '0'), '.') . 'Cr';
            if ($v >= 100000)   return '₹' . rtrim(rtrim(number_format($v / 100000, 1), '0'), '.') . 'L';
            if ($v >= 1000)     return '₹' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
            return '₹' . number_format($v);
        };
        $__wk      = (float)($rev['rev_week'] ?? 0);
        $__today   = (float)($rev['rev_today'] ?? 0);
        $__botWk   = (float)array_sum($botTrend);
        $__manWk   = (float)array_sum($manTrend);
        $__botPct  = ($__botWk + $__manWk) > 0 ? (int)round($__botWk / ($__botWk + $__manWk) * 100) : 0;
        $__paidN   = (int)($statusMap['paid'] ?? 0);
        $__allN    = (int)$totalAllOrders;
        $__paidPct = $__allN > 0 ? (int)round($__paidN / $__allN * 100) : 0;
        ?>
        <div class="odash-card odash-pulse">
            <div class="odash-pulse__badge"><span class="odash-live">Live Analysis</span></div>
            <section class="wm-flow wm-flow--card" data-wm-skip data-centre-name="<?php echo htmlspecialchars($__inrShort($__wk), ENT_QUOTES, 'UTF-8'); ?>" data-centre-sub="THIS WEEK">
                <div class="wm-flow__stage">
                    <svg class="wm-flow__svg" viewBox="0 0 900 720" aria-hidden="true" focusable="false"></svg>
                    <ol class="wm-flow__steps">
                        <li class="wm-step" data-sweep="215" data-icon="rupee" style="--c:#128C7E;--t:#FFFFFF;">
                            <h3 class="wm-step__title"><span class="wm-step__num">01</span>Revenue this week</h3>
                            <p class="wm-step__text"><?php echo '₹' . number_format($__wk); ?> paid · <?php echo '₹' . number_format($__today); ?> today</p>
                        </li>
                        <li class="wm-step" data-sweep="250" data-icon="bot" style="--c:#075E54;--t:#FFFFFF;">
                            <h3 class="wm-step__title"><span class="wm-step__num">02</span>Bot revenue</h3>
                            <p class="wm-step__text"><?php echo '₹' . number_format($__botWk); ?> · <?php echo $__botPct; ?>% of the week</p>
                        </li>
                        <li class="wm-step" data-sweep="270" data-icon="bag" style="--c:#0F2622;--t:#FFFFFF;">
                            <h3 class="wm-step__title"><span class="wm-step__num">03</span>Paid orders</h3>
                            <p class="wm-step__text"><?php echo number_format($__paidN); ?> of <?php echo number_format($__allN); ?> orders · <?php echo $__paidPct; ?>% paid</p>
                        </li>
                    </ol>
                    <div class="wm-flow__title">
                        <h2>Revenue <span>pulse</span></h2>
                        <p>Last 7 days · bot vs manual sales.</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Status Doughnut -->
        <div class="odash-card">
            <div class="odash-card-hdr">
                <div class="odash-card-title">Status Distribution</div>
            </div>
            <div class="odash-card-body">
                <div class="odash-donut-wrap">
                    <canvas id="statusChart" style="max-height:200px;"></canvas>
                    <div class="odash-donut-center">
                        <span class="odash-dc-num"><?php echo (int)$totalAllOrders; ?></span>
                        <span class="odash-dc-label">Total</span>
                    </div>
                </div>
                <div class="odash-status-list">
                    <?php
                    $legItems = [
                        ['label'=>'Paid',       'key'=>'paid',       'color'=>'#128C7E'],
                        ['label'=>'Processing', 'key'=>'processing', 'color'=>'#7c3aed'],
                        ['label'=>'Shipped',    'key'=>'shipped',    'color'=>'#1d6fd6'],
                        ['label'=>'Pending',    'key'=>'pending',    'color'=>'#f59e0b'],
                        ['label'=>'Failed',     'key'=>'failed',     'color'=>'#ef4444'],
                    ];
                    foreach ($legItems as $li):
                        $lk = strtolower($li['key']);
                    ?>
                    <div class="odash-sll-row">
                        <div class="odash-sll-left">
                            <span class="odash-sll-dot" style="background:<?php echo $li['color']; ?>;"></span>
                            <?php echo htmlspecialchars($li['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <span class="odash-sll-cnt"><?php echo (int)($statusMap[$lk] ?? 0); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Bottom Row: two chart cards -->
    <?php
    $paidCnt          = (int)($statusMap['paid'] ?? 0);
    $paidSharePct     = $totalAllOrders > 0 ? round(($paidCnt / $totalAllOrders) * 100, 1) : 0;
    $avgOrderMonth    = $paidOrdersMonth > 0 ? round($revMonthNum / $paidOrdersMonth, 2) : 0;
    $botShareMonthPct = $revMonthNum > 0.00001 ? round(($botRevMonth / $revMonthNum) * 100, 1) : 0;
    $delivered        = (int)($statusMap['delivered'] ?? 0);
    $shipped          = (int)($statusMap['shipped'] ?? 0);
    $failed           = (int)($statusMap['failed'] ?? 0);
    $weekRev          = (float)($rev['rev_week'] ?? 0);
    $weekBot          = (float)array_sum($botTrend);
    $weekMan          = (float)array_sum($manTrend);
    $weekOrders       = (int)array_sum($ordTrend);
    $weekPaid         = (int)array_sum($paidTrend);
    // Change vs the 7 days before: shown only when there is something to compare with
    $chg = $revPrevWeek > 0 ? round(($weekRev - $revPrevWeek) / $revPrevWeek * 100, 1) : null;
    $arrowUp = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>';
    $arrowDn = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 7 10 10"/><path d="M17 7v10H7"/></svg>';
    $inr = static fn(float $v, int $d = 0) => '₹' . number_format($v, $d);
    $dayTotals = array_map(static fn($b, $m) => (float)$b + (float)$m, $botTrend, $manTrend);
    $bestIdx   = array_search(max($dayTotals), $dayTotals, true);
    $bestDay   = max($dayTotals) > 0 ? ($labels[$bestIdx] ?? '—') : '—';
    ?>
    <div class="odash-bottom-grid">

        <!-- Revenue by source: grouped bars, last 7 days -->
        <div class="odash-card oc-card">
            <div class="oc-head">
                <h3>Revenue by source</h3>
                <span class="oc-range">Last 7 days</span>
            </div>
            <div class="oc-figure">
                <b><?php echo $inr($weekRev); ?></b>
                <?php if ($chg !== null): ?>
                    <span class="oc-chip <?php echo $chg >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo ($chg >= 0 ? '+' : '') . $chg; ?>% <?php echo $chg >= 0 ? $arrowUp : $arrowDn; ?></span>
                <?php else: ?>
                    <span class="oc-chip is-flat">No sales the week before</span>
                <?php endif; ?>
            </div>
            <p class="oc-sub">Paid revenue this week</p>
            <div class="oc-chart"><canvas id="srcBarChart" aria-label="Paid revenue per day by source" role="img"></canvas><?php if ($weekBot + $weekMan <= 0): ?><div class="oc-empty">No paid sales in the last 7 days</div><?php endif; ?></div>
            <div class="oc-legend">
                <span><i style="background:#128C7E"></i>AI bot <b><?php echo $inr($weekBot); ?></b></span>
                <span><i style="background:#A8DCCB"></i>Manual / External <b><?php echo $inr($weekMan); ?></b></span>
            </div>
            <div class="oc-stats">
                <div><small>Daily average</small><b><?php echo $inr($weekRev / 7); ?></b></div>
                <div><small>Best day</small><b><?php echo htmlspecialchars((string)$bestDay, ENT_QUOTES, 'UTF-8'); ?></b></div>
                <div><small>Paid, 30d</small><b><?php echo $inr($revMonthNum); ?></b></div>
                <div><small>Paid orders, 30d</small><b><?php echo $paidOrdersMonth; ?></b></div>
            </div>
        </div>

        <!-- Orders: smooth line, last 7 days -->
        <div class="odash-card oc-card">
            <div class="oc-head">
                <h3>Orders</h3>
                <span class="oc-range">Last 7 days</span>
            </div>
            <div class="oc-figure">
                <b><?php echo $inr($avgOrderMonth, 2); ?></b>
                <span class="oc-chip is-flat"><?php echo $paidSharePct; ?>% paid</span>
            </div>
            <p class="oc-sub">Average paid order, last 30 days</p>
            <div class="oc-chart"><canvas id="ordLineChart" aria-label="Orders and paid orders per day" role="img"></canvas><?php if ($weekOrders === 0): ?><div class="oc-empty">No orders in the last 7 days</div><?php endif; ?></div>
            <div class="oc-legend">
                <span><i style="background:#128C7E"></i>Orders <b><?php echo $weekOrders; ?></b></span>
                <span><i style="background:#B6C2BE"></i>Paid <b><?php echo $weekPaid; ?></b></span>
            </div>
            <div class="oc-stats">
                <div><small>In transit</small><b><?php echo $shipped; ?></b></div>
                <div><small>Delivered</small><b><?php echo $delivered; ?></b></div>
                <div><small>Failed</small><b<?php echo $failed > 0 ? ' class="is-bad"' : ''; ?>><?php echo $failed; ?></b></div>
                <div><small>Bot share, 30d</small><b><?php echo $botShareMonthPct; ?>%</b></div>
            </div>
        </div>

    </div>

</div>

<script>
(function () {
    if (typeof Chart === 'undefined') return;

    var rs      = getComputedStyle(document.documentElement);
    var accent  = rs.getPropertyValue('--accent').trim()      || '#2d7ce3';
    var border  = rs.getPropertyValue('--border').trim()      || '#e2e8f0';
    var muted   = rs.getPropertyValue('--text-muted').trim()  || '#64748b';
    var primary = rs.getPropertyValue('--text-primary').trim()|| '#0f172a';
    var bgCard  = rs.getPropertyValue('--bg-card').trim()     || '#ffffff';

    Chart.defaults.font.family = 'inherit';

    /* Shared look for the two bottom charts: quiet grid, white tooltip card */
    var J = function (v) { return v; };
    var tickInr = function (v) { return v >= 100000 ? '₹' + (v / 100000).toFixed(1).replace(/\.0$/, '') + 'L' : v >= 1000 ? '₹' + (v / 1000).toFixed(1).replace(/\.0$/, '') + 'K' : '₹' + v; };
    var tip = {
        backgroundColor: '#FFFFFF', borderColor: '#E3EBE8', borderWidth: 1,
        titleColor: '#0F2622', bodyColor: '#4A5B55', titleFont: { weight: '700' },
        padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true
    };
    var yAxis = function (cb) {
        return {
            beginAtZero: true, suggestedMax: 1,
            grid: { color: '#EEF2F0' }, border: { display: false },
            ticks: { color: '#8A9A95', font: { size: 11 }, maxTicksLimit: 5, precision: 0, padding: 8, callback: cb || J }
        };
    };
    var xAxis = { grid: { display: false }, border: { display: false }, ticks: { color: '#8A9A95', font: { size: 11.5 } } };
    var days = <?php echo json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    /* ── Revenue by source: grouped bars ── */
    var barEl = document.getElementById('srcBarChart');
    if (barEl) {
        new Chart(barEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: days,
                datasets: [
                    { label: 'AI bot', data: <?php echo json_encode($botTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                      backgroundColor: '#128C7E', hoverBackgroundColor: '#0B6B5E', borderRadius: 5, borderSkipped: 'bottom', categoryPercentage: 0.62, barPercentage: 0.82 },
                    { label: 'Manual / External', data: <?php echo json_encode($manTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                      backgroundColor: '#A8DCCB', hoverBackgroundColor: '#86CBB4', borderRadius: 5, borderSkipped: 'bottom', categoryPercentage: 0.62, barPercentage: 0.82 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tip, { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ₹' + Number(c.raw).toLocaleString('en-IN'); } } })
                },
                scales: { y: yAxis(tickInr), x: xAxis }
            }
        });
    }

    /* ── Orders: smooth line, paid as a dashed line ── */
    var lineEl = document.getElementById('ordLineChart');
    if (lineEl) {
        new Chart(lineEl.getContext('2d'), {
            type: 'line',
            data: {
                labels: days,
                datasets: [
                    { label: 'Orders', data: <?php echo json_encode($ordTrend); ?>,
                      borderColor: '#128C7E', backgroundColor: 'rgba(18,140,126,0.07)', fill: true,
                      borderWidth: 2.5, tension: 0.42, pointRadius: 0, pointHoverRadius: 5,
                      pointHoverBackgroundColor: '#FFFFFF', pointHoverBorderColor: '#128C7E', pointHoverBorderWidth: 2 },
                    { label: 'Paid', data: <?php echo json_encode($paidTrend); ?>,
                      borderColor: '#B6C2BE', borderDash: [5, 5], fill: false,
                      borderWidth: 2, tension: 0.42, pointRadius: 0, pointHoverRadius: 4,
                      pointHoverBackgroundColor: '#FFFFFF', pointHoverBorderColor: '#8A9A95', pointHoverBorderWidth: 2 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false }, tooltip: tip },
                scales: { y: yAxis(), x: xAxis }
            }
        });
    }

    /* ── Status Doughnut ── */
    var stEl = document.getElementById('statusChart');
    if (stEl) {
        new Chart(stEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Processing', 'Shipped', 'Pending', 'Failed'],
                datasets: [{
                    data: [
                        <?php echo (int)($statusMap['paid']       ?? 0); ?>,
                        <?php echo (int)($statusMap['processing'] ?? 0); ?>,
                        <?php echo (int)($statusMap['shipped']    ?? 0); ?>,
                        <?php echo (int)($statusMap['pending']    ?? 0); ?>,
                        <?php echo (int)($statusMap['failed']     ?? 0); ?>
                    ],
                    backgroundColor: ['#128C7E','#7c3aed','#1d6fd6','#f59e0b','#ef4444'],
                    borderColor: bgCard,
                    borderWidth: 3,
                    cutout: '78%',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: primary, borderColor: border, borderWidth: 1,
                        titleColor: bgCard, bodyColor: muted,
                        padding: 10, cornerRadius: 10
                    }
                }
            }
        });
    }
})();
</script>

<script src="../assets/js/wm-ring.js?v=1"></script>
<?php include '../includes/client_footer.php'; ?>
