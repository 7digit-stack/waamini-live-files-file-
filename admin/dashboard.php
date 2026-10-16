<?php
// Production-ready dashboard with security hardening
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Log errors, never display in production
ini_set('log_errors', '1');

include '../includes/auth_check.php';
include '../db.php';
require_once '../includes/server_health.php';
require_once __DIR__ . '/../includes/cache.php';

// Security headers for production
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
// Content-Security-Policy for production
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES) . "' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; upgrade-insecure-requests");

$serverHealth = getServerHealth($conn);

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

/**
 * Production-safe count function using prepared statements
 */
function waamini_count(mysqli $conn, string $table, ?string $condition = null): int {
    try {
        $sql = "SELECT COUNT(*) as c FROM " . $table;
        if ($condition) {
            $sql .= " WHERE " . $condition;
        }
        $sql .= " LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Dashboard: Failed to prepare count query - ' . $conn->error);
            return 0;
        }
        
        if (!$stmt->execute()) {
            error_log('Dashboard: Failed to execute count query - ' . $stmt->error);
            $stmt->close();
            return 0;
        }
        
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Dashboard: Failed to get result - ' . $stmt->error);
            $stmt->close();
            return 0;
        }
        
        $row = $result->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        error_log('Dashboard count error: ' . $e->getMessage());
        return 0;
    }
}

// Cache expensive dashboard counts briefly (10s) to keep navigation snappy on shared hosting / XAMPP.
$statsCacheKey = 'admin:dashboard:stats_v2';
$stats = waamini_cache_get($statsCacheKey, 10);
if (!is_array($stats)) {
    $stats = [];
    $stats['totalCompanies'] = waamini_count($conn, 'companies');
    $stats['totalUsers'] = waamini_count($conn, 'users');
    $stats['activeCompanies'] = waamini_count($conn, 'companies', "status='active'");
    $stats['suspendedCompanies'] = waamini_count($conn, 'companies', "status='suspended'");
    
    try {
        $stats['totalCampaigns'] = waamini_count($conn, 'campaigns');
    } catch (Throwable $_e) {
        error_log('Dashboard campaigns count error: ' . $_e->getMessage());
        $stats['totalCampaigns'] = 0;
    }
    
    try {
        $stats['totalWhatsapp'] = waamini_count($conn, 'whatsapp_config');
    } catch (Throwable $_e) {
        error_log('Dashboard whatsapp count error: ' . $_e->getMessage());
        $stats['totalWhatsapp'] = 0;
    }
    
    waamini_cache_set($statsCacheKey, $stats, 10);
}

$totalCompanies = (int)($stats['totalCompanies'] ?? 0);
$totalUsers = (int)($stats['totalUsers'] ?? 0);
$activeCompanies = (int)($stats['activeCompanies'] ?? 0);
$suspendedCompanies = (int)($stats['suspendedCompanies'] ?? 0);
$totalCampaigns = (int)($stats['totalCampaigns'] ?? 0);
$totalWhatsapp = (int)($stats['totalWhatsapp'] ?? 0);

// Monthly user registration data (last 12 months) - using prepared statements for security
$chartCacheKey = 'admin:dashboard:chart12m';
$chart = waamini_cache_get($chartCacheKey, 60);
if (!is_array($chart) || !isset($chart['monthLabels'], $chart['monthData'], $chart['companyMonthData'])) {
    // Build last-12-months label list and a start date for bulk queries
    $months = [];
    $monthLabels = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-$i months"));
        $months[] = $ym;
        $monthLabels[] = date('M Y', strtotime("-$i months"));
    }
    $monthStart = $months[0] . '-01';

    // Single aggregate query for users with prepared statement — replaces 12 individual queries
    $userMap = [];
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as c FROM users WHERE created_at >= ? GROUP BY mo LIMIT 100");
    if ($stmt) {
        $stmt->bind_param('s', $monthStart);
        if ($stmt->execute()) {
            $rU = $stmt->get_result();
            while ($row = $rU->fetch_assoc()) {
                $userMap[$row['mo']] = (int)$row['c'];
            }
        } else {
            error_log('Dashboard: User chart query failed - ' . $stmt->error);
        }
        $stmt->close();
    }

    // Single aggregate query for companies with prepared statement — replaces 12 individual queries
    $compMap = [];
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as c FROM companies WHERE created_at >= ? GROUP BY mo LIMIT 100");
    if ($stmt) {
        $stmt->bind_param('s', $monthStart);
        if ($stmt->execute()) {
            $rC = $stmt->get_result();
            while ($row = $rC->fetch_assoc()) {
                $compMap[$row['mo']] = (int)$row['c'];
            }
        } else {
            error_log('Dashboard: Company chart query failed - ' . $stmt->error);
        }
        $stmt->close();
    }

    $monthData = [];
    $companyMonthData = [];
    foreach ($months as $mo) {
        $monthData[]        = $userMap[$mo] ?? 0;
        $companyMonthData[] = $compMap[$mo] ?? 0;
    }

    $chart = [
        'monthLabels' => $monthLabels,
        'monthData' => $monthData,
        'companyMonthData' => $companyMonthData,
    ];
    waamini_cache_set($chartCacheKey, $chart, 60);
} else {
    $monthLabels = (array)$chart['monthLabels'];
    $monthData = (array)$chart['monthData'];
    $companyMonthData = (array)$chart['companyMonthData'];
}

// Live registrations feed (from audit_log, most recent 8)
$liveRegs = [];
$lrStmt = $conn->prepare(
    "SELECT al.id, al.action, al.created_at,
            u.name, u.email, u.google_id, u.avatar_url,
            c.name AS company_name
     FROM audit_log al
     LEFT JOIN users u ON al.user_id = u.id
     LEFT JOIN companies c ON u.company_id = c.id
     WHERE al.action IN ('email_register','google_register')
     ORDER BY al.id DESC LIMIT 8"
);
if ($lrStmt && $lrStmt->execute()) {
    $lrRes = $lrStmt->get_result();
    while ($row = $lrRes->fetch_assoc()) $liveRegs[] = $row;
    $lrStmt->close();
}

// Recent users with proper error handling
$recentUsers = null;
$stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.role, u.google_id, u.created_at, c.name as company_name
    FROM users u LEFT JOIN companies c ON u.company_id = c.id
    ORDER BY u.id DESC LIMIT 5");
if ($stmt && $stmt->execute()) {
    $recentUsers = $stmt->get_result();
} else {
    error_log('Dashboard: Failed to fetch recent users - ' . ($stmt ? $stmt->error : $conn->error));
    $recentUsers = null;
}

// Recent companies with proper error handling
$recentCompanies = null;
$stmt = $conn->prepare("SELECT id, name, email, status, created_at FROM companies ORDER BY id DESC LIMIT 5");
if ($stmt && $stmt->execute()) {
    $recentCompanies = $stmt->get_result();
} else {
    error_log('Dashboard: Failed to fetch recent companies - ' . ($stmt ? $stmt->error : $conn->error));
    $recentCompanies = null;
}

// Recent System Logs with prepared statement — table may not exist yet
$recentLogs = null;
try {
    $stmt = $conn->prepare("SELECT l.*, u.name as user_name FROM system_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 5");
    if ($stmt && $stmt->execute()) {
        $recentLogs = $stmt->get_result();
    } else {
        error_log('Dashboard: Failed to fetch recent logs - ' . ($stmt ? $stmt->error : $conn->error));
        $recentLogs = null;
    }
} catch (Throwable $_e) {
    error_log('Dashboard: System logs query exception - ' . $_e->getMessage());
    $recentLogs = null; // system_logs table not created yet
}

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'send'     => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'phone'    => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    'plus'     => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'pulse'    => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'cpu'      => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
];
$monthNow = (int)(end($monthData) ?: 0);
$compNow  = (int)(end($companyMonthData) ?: 0);
reset($monthData); reset($companyMonthData);
$otherCompanies = max(0, $totalCompanies - $activeCompanies - $suspendedCompanies);
$todayCnt = 0;
foreach ($liveRegs as $lr) { if (date('Y-m-d', strtotime($lr['created_at'])) === date('Y-m-d')) $todayCnt++; }
$ago = static function (string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return date('d M', strtotime($dt));
};
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>

<div class="db">

  <div class="db-head">
    <div>
      <h1>Dashboard</h1>
      <p>Platform overview: companies, users, campaigns and system health.</p>
    </div>
    <div class="db-head__actions">
      <a href="/admin/users.php" class="db-btn"><?php echo $__ic($P['users'], 16); ?> Users</a>
      <a href="/admin/client_create.php" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> Create client</a>
    </div>
  </div>

  <!-- ===== row 1: growth report + KPIs | company status donut ===== -->
  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Platform growth</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Users: <?php echo (int)array_sum($monthData); ?></span><span><i style="background:#F59E0B"></i>Companies: <?php echo (int)array_sum($companyMonthData); ?></span></div>
          </div>
          <div class="db-seg" id="dbSeg"><button type="button" class="is-active" data-mode="both">Both</button><button type="button" data-mode="users">Users</button><button type="button" data-mode="companies">Companies</button></div>
        </div>
        <div class="db-canvas"><canvas id="dbGrowth"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($P['building'], 19); ?></div><div class="db-kpi__l">Total companies</div><div class="db-kpi__v"><?php echo number_format($totalCompanies); ?></div><div class="db-kpi__d">This month <span class="db-chip is-up">+<?php echo $compNow; ?></span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($P['users'], 19); ?></div><div class="db-kpi__l">Total users</div><div class="db-kpi__v"><?php echo number_format($totalUsers); ?></div><div class="db-kpi__d">This month <span class="db-chip is-up">+<?php echo $monthNow; ?></span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['send'], 19); ?></div><div class="db-kpi__l">Total campaigns</div><div class="db-kpi__v"><?php echo number_format($totalCampaigns); ?></div><div class="db-kpi__d">Across all companies</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($P['phone'], 19); ?></div><div class="db-kpi__l">WhatsApp accounts</div><div class="db-kpi__v"><?php echo number_format($totalWhatsapp); ?></div><div class="db-kpi__d">Connected numbers</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Company status</h2><a class="db-link" href="/admin/companies.php">Manage</a></div>
      <div class="db-donut__chart">
        <canvas id="dbDonut" width="200" height="200"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($totalCompanies); ?></b><span>Companies</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#128C7E"></i>Active: <?php echo $activeCompanies; ?></span>
        <span><i style="background:#F59E0B"></i>Suspended: <?php echo $suspendedCompanies; ?></span>
        <?php if ($otherCompanies > 0): ?><span><i style="background:#CBD5D1"></i>Other: <?php echo $otherCompanies; ?></span><?php endif; ?>
      </div>
    </section>
  </div>

  <!-- ===== row 2: recent users | live registrations ===== -->
  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head"><div><h2>Recent users</h2><p>Last 5 members who joined</p></div><a class="db-link" href="users.php">View all &rsaquo;</a></div>
      <div class="db-tablewrap">
        <table class="db-table">
          <thead><tr><th>User</th><th>Email</th><th>Company</th><th>Login</th><th>Role</th></tr></thead>
          <tbody>
          <?php if ($recentUsers instanceof mysqli_result && $recentUsers->num_rows > 0): ?>
            <?php while ($u = $recentUsers->fetch_assoc()): $g = !empty($u['google_id']); ?>
            <tr>
              <td><div class="db-who"><span class="db-av <?php echo $g ? 'is-g' : ''; ?>"><?php echo $esc(strtoupper(substr((string)$u['name'], 0, 1))); ?></span><div><b><?php echo $esc($u['name']); ?></b><small><?php echo !empty($u['created_at']) ? date('d M Y, H:i', strtotime($u['created_at'])) : ''; ?></small></div></div></td>
              <td class="db-mut"><?php echo $esc($u['email']); ?></td>
              <td class="db-mut"><?php echo $esc($u['company_name'] ?? '—'); ?></td>
              <td><span class="db-pill <?php echo $g ? 'is-info' : 'is-off'; ?>"><?php echo $g ? 'Google' : 'Email'; ?></span></td>
              <?php $__r = (string)($u['role'] ?? ''); $__rl = ['super_admin' => ['Super admin', 'is-warn'], 'client' => ['Client', 'is-ok'], 'admin' => ['Vendor owner', 'is-ok']][$__r] ?? ['No role', 'is-off']; ?>
              <td><span class="db-pill <?php echo $__rl[1]; ?>"><?php echo $esc($__rl[0]); ?></span></td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5"><div class="db-empty">No users yet.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="db-card">
      <div class="db-card__head"><div><h2>New registrations</h2><p>Today: <b id="wmn-today-count"><span><strong><?php echo $todayCnt; ?></strong></span></b></p></div><span class="db-live"><i></i>Live</span><a class="db-link" href="new_registrations.php">View all &rsaquo;</a></div>
      <div class="db-list" id="wmn-live-feed">
        <?php if (empty($liveRegs)): ?>
          <div class="db-empty" id="wmn-feed-empty">No registrations yet. The feed updates automatically.</div>
        <?php else: foreach ($liveRegs as $lr): $g = !empty($lr['google_id']); ?>
          <div class="db-li wmn-feed-row" data-id="<?php echo (int)$lr['id']; ?>">
            <span class="db-li__ic <?php echo $g ? 'is-g' : ''; ?>"><?php echo $esc(strtoupper(substr((string)($lr['name'] ?? '?'), 0, 1))); ?></span>
            <div class="db-li__t"><b><?php echo $esc($lr['name'] ?? 'Unknown'); ?></b><small><?php echo $esc($lr['email'] ?? ''); ?></small></div>
            <div class="db-li__r"><span class="db-pill <?php echo $g ? 'is-info' : 'is-off'; ?>"><?php echo $g ? 'Google' : 'Email'; ?></span><div style="margin-top:4px;font-weight:600;color:var(--mu)"><?php echo $ago($lr['created_at']); ?></div></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>

  <!-- ===== row 3: vendors | server health ===== -->
  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head"><div><h2>New vendor partners</h2><p>Latest onboardings</p></div><a class="db-link" href="companies.php">Manage vendors &rsaquo;</a></div>
      <div class="db-tablewrap">
        <table class="db-table">
          <thead><tr><th>Company</th><th>Contact email</th><th>Status</th><th>Onboarded</th></tr></thead>
          <tbody>
          <?php if ($recentCompanies instanceof mysqli_result && $recentCompanies->num_rows > 0): ?>
            <?php while ($c = $recentCompanies->fetch_assoc()): $on = ($c['status'] === 'active'); ?>
            <tr>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(substr((string)$c['name'], 0, 1))); ?></span><div><b><?php echo $esc($c['name']); ?></b></div></div></td>
              <td class="db-mut"><?php echo $esc($c['email'] ?? '—'); ?></td>
              <td><span class="db-pill <?php echo $on ? 'is-ok' : 'is-bad'; ?>"><?php echo $esc(ucfirst((string)$c['status'])); ?></span></td>
              <td class="db-mut"><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="4"><div class="db-empty">No vendor partners registered yet.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="db-card">
      <div class="db-card__head"><div><h2>Server health</h2><p>Live system status</p></div><span class="db-pill is-ok"><?php echo $__ic($P['pulse'], 13); ?> Online</span></div>
      <div class="db-meter">
        <div class="db-meter__row"><span>PHP version</span><b><?php echo $esc($serverHealth['php_version']); ?></b></div>
        <div class="db-meter__row"><span>MySQL status</span><b><?php echo $serverHealth['mysql_status']; ?></b></div>
        <div class="db-meter__row"><span>Memory (app)</span><b><?php echo $esc($serverHealth['php_memory']); ?></b></div>
        <div class="db-meter__row"><span>Server RAM</span><b><?php echo $esc($serverHealth['server_ram']); ?></b></div>
        <div class="db-meter__row"><span>CPU load</span><b><?php echo $esc($serverHealth['server_cpu']); ?></b></div>
        <div class="db-meter__row" style="border-bottom:0;padding-bottom:0"><span>Disk space</span><b><?php echo $esc($serverHealth['disk_pct']); ?>%</b></div>
        <div class="db-bar"><i style="width:<?php echo max(0, min(100, (float)$serverHealth['disk_pct'])); ?>%"></i></div>
        <div class="db-bar-note"><?php echo $esc($serverHealth['disk_used']); ?> / <?php echo $esc($serverHealth['disk_total']); ?> used</div>
      </div>
    </section>
  </div>

  <!-- ===== row 4: system activity ===== -->
  <section class="db-card" style="margin-bottom:6px">
    <div class="db-card__head"><div><h2>System activity</h2><p>Latest platform events</p></div></div>
    <div class="db-list" style="max-height:none;padding-top:8px">
      <?php if (isset($recentLogs) && $recentLogs && $recentLogs->num_rows > 0): while ($log = $recentLogs->fetch_assoc()): ?>
        <div class="db-li">
          <span class="db-li__ic is-g"><?php echo $__ic($P['pulse'], 17); ?></span>
          <div class="db-li__t"><b style="white-space:normal"><?php echo $esc($log['description']); ?></b><small><?php echo $log['user_name'] ? $esc($log['user_name']) : 'System'; ?></small></div>
          <div class="db-li__r"><?php echo date('M j, Y · H:i', strtotime($log['created_at'])); ?></div>
        </div>
      <?php endwhile; else: ?>
        <div class="db-empty">No system activity recorded yet.</div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES); ?>">
/* live-feed hook (called by the polling script in admin_header.php) */
window.wmnFeedPrepend = function (item) {
  var feed = document.getElementById('wmn-live-feed'); if (!feed) return;
  var empty = document.getElementById('wmn-feed-empty'); if (empty) empty.remove();
  function esc(s){ return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  var g = item.method === 'google';
  var row = document.createElement('div');
  row.className = 'db-li wmn-feed-row is-new';
  row.innerHTML = '<span class="db-li__ic ' + (g ? 'is-g' : '') + '">' + esc((item.name || '?').charAt(0).toUpperCase()) + '</span>' +
    '<div class="db-li__t"><b>' + esc(item.name) + '</b><small>' + esc(item.email) + '</small></div>' +
    '<div class="db-li__r"><span class="db-pill ' + (g ? 'is-info' : 'is-off') + '">' + (g ? 'Google' : 'Email') + '</span><div style="margin-top:4px;font-weight:600;color:var(--mu)">just now</div></div>';
  feed.insertBefore(row, feed.firstChild);
  setTimeout(function () { row.classList.remove('is-new'); }, 6000);
  var rows = feed.querySelectorAll('.wmn-feed-row'); if (rows.length > 8) rows[rows.length - 1].remove();
  var ctr = document.getElementById('wmn-today-count'), st = ctr && ctr.querySelector('strong');
  if (st) st.textContent = String((parseInt(st.textContent, 10) || 0) + 1);
};

(function () {
  if (typeof Chart === 'undefined') return;
  var labels = <?php echo json_encode(array_values($monthLabels)); ?>;
  var users = <?php echo json_encode(array_map('intval', array_values($monthData))); ?>;
  var comps = <?php echo json_encode(array_map('intval', array_values($companyMonthData))); ?>;
  var short = labels.map(function (l) { return String(l).split(' ')[0]; });
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = '#7a8c86';

  var bar = new Chart(document.getElementById('dbGrowth'), {
    type: 'bar',
    data: { labels: short, datasets: [
      { label: 'Users', data: users, backgroundColor: '#128C7E', borderRadius: 4, barPercentage: .9, categoryPercentage: .8, maxBarThickness: 24 },
      { label: 'Companies', data: comps, backgroundColor: '#F59E0B', borderRadius: 4, barPercentage: .9, categoryPercentage: .8, maxBarThickness: 24 }
    ] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, callbacks: { title: function (i) { return labels[i[0].dataIndex]; } } } },
      scales: { x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                y: { beginAtZero: true, border: { display: false }, grid: { color: '#EEF3F1', borderDash: [4, 4] }, ticks: { precision: 0, font: { size: 12 } } } }
    }
  });
  document.getElementById('dbSeg').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    var m = b.dataset.mode;
    bar.setDatasetVisibility(0, m !== 'companies');
    bar.setDatasetVisibility(1, m !== 'users');
    bar.update();
  });

  var active = <?php echo (int)$activeCompanies; ?>, susp = <?php echo (int)$suspendedCompanies; ?>, other = <?php echo (int)$otherCompanies; ?>;
  var empty = active + susp + other === 0;
  new Chart(document.getElementById('dbDonut'), {
    type: 'doughnut',
    data: { labels: ['Active', 'Suspended', 'Other'], datasets: [{ data: empty ? [1] : [active, susp, other], backgroundColor: empty ? ['#E3EEE9'] : ['#128C7E', '#F59E0B', '#CBD5D1'], borderWidth: 0, spacing: 2 }] },
    options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: !empty } } }
  });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
