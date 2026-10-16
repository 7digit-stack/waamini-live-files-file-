<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'New Registrations';
$currentPage = 'new_registrations';

$ranges = ['24h' => 'INTERVAL 24 HOUR', '7d' => 'INTERVAL 7 DAY', '30d' => 'INTERVAL 30 DAY', 'all' => null];
$rk = (string)($_GET['range'] ?? '30d');
if (!array_key_exists($rk, $ranges)) { $rk = '30d'; }
$mk = (string)($_GET['m'] ?? 'all');
if (!in_array($mk, ['all', 'email', 'google'], true)) { $mk = 'all'; }
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 20;

$rows = static function (mysqli $db, string $sql, string $types = '', array $args = []): array {
    try {
        $st = $db->prepare($sql);
        if (!$st) { return []; }
        if ($types !== '') { $st->bind_param($types, ...$args); }
        $st->execute();
        $r = $st->get_result(); $o = [];
        if ($r) { while ($x = $r->fetch_assoc()) { $o[] = $x; } }
        $st->close();
        return $o;
    } catch (Throwable $e) {
        error_log('[WAAMINI new_registrations] ' . $e->getMessage());
        return [];
    }
};

// Clients only (never the platform admins)
$base = "u.role <> 'super_admin'";
$where = $base; $types = ''; $args = [];
if ($ranges[$rk]) { $where .= " AND u.created_at >= DATE_SUB(NOW(), {$ranges[$rk]})"; }
if ($mk === 'google') { $where .= " AND u.google_id IS NOT NULL AND u.google_id <> ''"; }
if ($mk === 'email')  { $where .= " AND (u.google_id IS NULL OR u.google_id = '')"; }
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ? OR u.company LIKE ?)";
    $like = '%' . $search . '%'; $types = 'ssss'; $args = [$like, $like, $like, $like];
}

$k = $rows($conn, "SELECT
    SUM(DATE(u.created_at) = CURDATE()) AS today,
    SUM(u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS wk,
    SUM(u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS mo,
    COUNT(*) AS total,
    SUM(u.google_id IS NOT NULL AND u.google_id <> '') AS goog
    FROM users u WHERE $base")[0] ?? [];
$kToday = (int)($k['today'] ?? 0); $kWeek = (int)($k['wk'] ?? 0); $kMonth = (int)($k['mo'] ?? 0);
$kTotal = (int)($k['total'] ?? 0); $kGoogle = (int)($k['goog'] ?? 0); $kEmail = max(0, $kTotal - $kGoogle);

$cnt = (int)(($rows($conn, "SELECT COUNT(*) AS n FROM users u WHERE $where", $types, $args)[0]['n']) ?? 0);
$pages = max(1, (int)ceil($cnt / $per));
$page = min($page, $pages);
$off = ($page - 1) * $per;
$list = $rows($conn,
    "SELECT u.id, u.name, u.email, u.mobile, u.company, u.company_id, u.role, u.status, u.google_id, u.two_factor_enabled, u.created_at,
            c.name AS company_name, c.status AS company_status,
            (SELECT al.ip_address FROM audit_log al WHERE al.user_id = u.id AND al.action IN ('email_register','google_register') ORDER BY al.id DESC LIMIT 1) AS reg_ip
     FROM users u LEFT JOIN companies c ON c.id = u.company_id
     WHERE $where ORDER BY u.created_at DESC, u.id DESC LIMIT $per OFFSET $off", $types, $args);

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ago = static function (string $ts): string {
    $s = max(0, time() - strtotime($ts));
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' h ago'; }
    if ($s < 86400 * 30) { return floor($s / 86400) . ' d ago'; }
    return date('d M Y', strtotime($ts));
};
$q = static fn(array $o) => http_build_query(array_merge(['range' => $rk, 'm' => $mk, 'q' => $search], $o));
?>

<div class="db nr">

  <div class="db-head">
    <div>
      <h1>New Registrations</h1>
      <p>Everyone who signed up, with their contact, company and how they joined.</p>
    </div>
    <div class="db-head__actions">
      <span class="db-live"><i></i>Live</span>
      <a href="new_registrations.php?<?php echo $esc($q([])); ?>" class="db-btn">Refresh</a>
    </div>
  </div>

  <div class="nr-kpis">
    <div class="nr-k"><small>Today</small><b><?php echo $kToday; ?></b></div>
    <div class="nr-k"><small>Last 7 days</small><b><?php echo $kWeek; ?></b></div>
    <div class="nr-k"><small>Last 30 days</small><b><?php echo $kMonth; ?></b></div>
    <div class="nr-k"><small>All time</small><b><?php echo $kTotal; ?></b></div>
    <div class="nr-k"><small>Email / Google</small><b><?php echo $kEmail; ?> <span>/ <?php echo $kGoogle; ?></span></b></div>
  </div>

  <section class="db-card">
    <form method="get" class="nr-filter" id="nrFilter">
      <input type="search" name="q" value="<?php echo $esc($search); ?>" placeholder="Search name, email, mobile or company">
      <select name="range" aria-label="Period">
        <?php foreach (['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'] as $v => $l): ?>
          <option value="<?php echo $v; ?>"<?php echo $v === $rk ? ' selected' : ''; ?>><?php echo $l; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="m" aria-label="Sign-up method">
        <?php foreach (['all' => 'All methods', 'email' => 'Email', 'google' => 'Google'] as $v => $l): ?>
          <option value="<?php echo $v; ?>"<?php echo $v === $mk ? ' selected' : ''; ?>><?php echo $l; ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="db-tablewrap">
      <table class="db-table nr-table">
        <thead><tr><th>Person</th><th>Mobile</th><th>Company</th><th>Method</th><th>Status</th><th>Security</th><th>Sign-up IP</th><th>Registered</th></tr></thead>
        <tbody>
        <?php if (!$list): ?>
          <tr><td colspan="8"><div class="db-empty">No registrations match these filters.</div></td></tr>
        <?php else: foreach ($list as $r):
          $g = !empty($r['google_id']);
          $co = (string)($r['company_name'] ?: $r['company']);
          $active = ($r['status'] ?? '') === 'active';
        ?>
          <tr>
            <td><div class="db-who"><span class="db-av <?php echo $g ? 'is-g' : ''; ?>"><?php echo $esc(strtoupper(mb_substr((string)($r['name'] ?: '?'), 0, 1))); ?></span><div><b><?php echo $esc($r['name'] ?: 'Unknown'); ?></b><small><?php echo $esc($r['email']); ?></small></div></div></td>
            <td class="nr-nw"><?php echo $esc($r['mobile'] ?: '-'); ?></td>
            <td><?php echo $esc($co ?: '-'); ?></td>
            <td><span class="db-pill <?php echo $g ? 'is-info' : 'is-off'; ?>"><?php echo $g ? 'Google' : 'Email'; ?></span></td>
            <td><span class="db-pill <?php echo $active ? 'is-ok' : 'is-bad'; ?>"><?php echo $esc(ucfirst((string)($r['status'] ?: 'unknown'))); ?></span></td>
            <td><span class="db-pill <?php echo !empty($r['two_factor_enabled']) ? 'is-ok' : 'is-off'; ?>"><?php echo !empty($r['two_factor_enabled']) ? '2FA on' : '2FA off'; ?></span></td>
            <td class="db-mut nr-nw"><?php echo $esc($r['reg_ip'] === '::1' ? 'localhost' : ($r['reg_ip'] ?: '-')); ?></td>
            <td class="nr-nw" title="<?php echo $esc($r['created_at']); ?>"><b><?php echo $esc($ago((string)$r['created_at'])); ?></b><small class="nr-sub"><?php echo $esc(date('d M Y, H:i', strtotime((string)$r['created_at']))); ?></small></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="nr-pager">
        <?php if ($page > 1): ?><a class="db-btn" href="new_registrations.php?<?php echo $esc($q(['p' => $page - 1])); ?>">&lsaquo; Prev</a><?php endif; ?>
        <span>Page <?php echo $page; ?> of <?php echo $pages; ?> &middot; <?php echo $cnt; ?> people</span>
        <?php if ($page < $pages): ?><a class="db-btn" href="new_registrations.php?<?php echo $esc($q(['p' => $page + 1])); ?>">Next &rsaquo;</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<style>
.nr-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:16px}
.nr-k{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 1px 3px rgba(15,38,34,.06)}
.nr-k small{display:block;color:var(--mu,#64748b);font-size:12.5px;font-weight:600}
.nr-k b{display:block;font-size:26px;margin-top:4px;color:#0f2622}
.nr-k b span{font-size:16px;color:#64748b;font-weight:600}
.nr-filter{display:flex;gap:10px;flex-wrap:wrap;padding:18px 20px 0}
.nr-filter input,.nr-filter select{height:42px;padding:0 14px;border:0;border-radius:12px;background:#f1f6f4;font:inherit;font-size:14px}
.nr-filter input{flex:1;min-width:240px}
.nr-table{min-width:980px}
.nr-nw{white-space:nowrap}
.nr-sub{display:block;font-size:12px;color:#94a3b8;margin-top:2px}
.nr-table .db-who small{display:block}
.nr-pager{display:flex;gap:14px;align-items:center;justify-content:center;padding:4px 20px 20px;color:#64748b;font-size:13px}
@media (max-width:760px){.nr-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<script>(function(){var f=document.getElementById('nrFilter');if(!f)return;f.querySelectorAll('select').forEach(function(s){s.addEventListener('change',function(){f.submit();});});})();</script>

<?php include '../includes/admin_footer.php'; ?>
