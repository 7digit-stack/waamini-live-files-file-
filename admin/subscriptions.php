<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Auto Subscriptions';
$currentPage = 'subscriptions';

function waamini_bind_int_params(mysqli_stmt $stmt, array $ints): bool {
    $ints = array_values($ints);
    if ($ints === []) {
        return true;
    }
    $types = str_repeat('i', count($ints));
    $params = [$types];
    foreach ($ints as $k => $_) {
        $params[] = &$ints[$k];
    }
    return call_user_func_array([$stmt, 'bind_param'], $params);
}

function safe_count(mysqli $db, string $sql): int {
    try {
        $r = $db->query($sql);
        return ($r && $row = $r->fetch_assoc()) ? (int)($row['c'] ?? 0) : 0;
    } catch (Throwable $e) {
        error_log('Subscriptions page count query failed: ' . $e->getMessage());
        return 0;
    }
}

function safe_sum(mysqli $db, string $sql): string {
    try {
        $r = $db->query($sql);
        return ($r && $row = $r->fetch_assoc()) ? (string)($row['t'] ?? '0') : '0';
    } catch (Throwable $e) {
        error_log('Subscriptions page sum query failed: ' . $e->getMessage());
        return '0';
    }
}

// Filters
$filterPlan   = isset($_GET['plan']) ? intval($_GET['plan']) : 0;
// Whitelist status to prevent SQL injection — only these exact values are valid
$_allowedStatuses = ['active', 'trial', 'expired', 'cancelled'];
$filterStatus = in_array($_GET['status'] ?? '', $_allowedStatuses, true) ? ($_GET['status']) : '';

$filterParams = [];
if ($filterStatus !== '') {
    $filterParams['status'] = $filterStatus;
}
if ($filterPlan) {
    $filterParams['plan'] = $filterPlan;
}
$filterQuery = $filterParams !== [] ? http_build_query($filterParams) : '';

// Build safe WHERE using bound parameters only
$where = "1=1";
$whereParams = [];
$whereTypes = '';
if ($filterPlan) {
    $where .= " AND s.plan_id=?";
    $whereParams[] = $filterPlan;
    $whereTypes .= 'i';
}
if ($filterStatus) {
    $where .= " AND s.status=?";
    $whereParams[] = $filterStatus;
    $whereTypes .= 's';
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    csrf_verify();
    $idsRaw = $_POST['ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $ids = [];
    foreach ($idsRaw as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));

    $deleted = 0;
    $status = 'noop';

    if ($ids !== []) {
        try {
            $conn->begin_transaction();

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $conn->prepare("DELETE FROM subscription_payments WHERE subscription_id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
            }

            $stmt = $conn->prepare("DELETE FROM subscription_reminders WHERE subscription_id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
            }

            $stmt = $conn->prepare("DELETE FROM subscriptions WHERE id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
                $deleted = (int)$stmt->affected_rows;
            }

            $conn->commit();
            $status = $deleted > 0 ? 'bulk_deleted' : 'noop';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Subscriptions bulk delete failed: ' . $e->getMessage());
            $status = 'bulk_failed';
        }
    }

    $redirect = 'subscriptions.php';
    if ($filterQuery !== '') {
        $redirect .= '?' . $filterQuery . '&';
    } else {
        $redirect .= '?';
    }
    $redirect .= 'msg=' . urlencode($status) . '&n=' . $deleted;
    header('Location: ' . $redirect);
    exit();
}

$totalActive  = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE status='active'");
$totalTrial   = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE status='trial'");
$totalExpired = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE status='expired'");
$totalRevenue = safe_sum($conn, "SELECT COALESCE(SUM(amount),0) as t FROM subscriptions WHERE status='active'");

// Fetch subscriptions
$subsStmt = $conn->prepare("
    SELECT s.*, p.name as plan_name, c.name as company_name
    FROM subscriptions s
    JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN companies c ON s.company_id = c.id
    WHERE $where
    ORDER BY s.created_at DESC
");
if ($subsStmt) {
    if ($whereParams !== []) {
        $subsStmt->bind_param($whereTypes, ...$whereParams);
    }
    $subsStmt->execute();
    $subs = $subsStmt->get_result();
} else {
    $subs = null;
}

$plans = $conn->query("SELECT id, name FROM subscription_plans ORDER BY id");
if (!$plans) $plans = null;

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'coin'   => '<circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
];
$subRows = [];
if ($subs) { while ($r = $subs->fetch_assoc()) { $subRows[] = $r; } }
$planList = [];
if ($plans) { while ($pl = $plans->fetch_assoc()) { $planList[] = $pl; } }
$totalCancelled = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE status='cancelled'");
$soon = [];
try {
    $rs = $conn->query("SELECT s.id, s.expires_at, c.name AS company_name, p.name AS plan_name FROM subscriptions s LEFT JOIN companies c ON s.company_id=c.id LEFT JOIN subscription_plans p ON s.plan_id=p.id WHERE s.status='active' AND s.expires_at IS NOT NULL AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) ORDER BY s.expires_at ASC LIMIT 6");
    if ($rs) { while ($x = $rs->fetch_assoc()) { $soon[] = $x; } }
} catch (Throwable $e) { error_log('Subs soon query: ' . $e->getMessage()); }
$statusCls = ['active' => 'is-ok', 'trial' => 'is-info', 'expired' => 'is-bad', 'cancelled' => 'is-off', 'pending' => 'is-warn'];

$flash = null;
if (isset($_GET['msg'])) {
    $m = (string)$_GET['msg']; $cnt = isset($_GET['n']) ? (int)$_GET['n'] : 0;
    if ($m === 'bulk_deleted') $flash = [true, $cnt > 0 ? 'Deleted ' . $cnt . ' subscription' . ($cnt === 1 ? '' : 's') . '.' : 'No subscriptions were deleted.'];
    elseif ($m === 'bulk_failed') $flash = [false, 'Bulk delete failed. Nothing was removed.'];
    else $flash = [true, 'Action completed.'];
}
?>

<div class="db sb">

  <div class="db-head">
    <div>
      <h1>Auto Subscriptions</h1>
      <p>Recurring billing and the automated subscription lifecycle.</p>
    </div>
    <div class="db-head__actions">
      <a href="plans.php" class="db-btn"><?php echo $__ic($P['layers'], 16); ?> Plans</a>
      <a href="subscription_add.php?type=auto" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> Add subscription</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="sb-toast <?php echo $flash[0] ? 'is-ok' : 'is-err'; ?>" role="status"><?php echo $__ic($flash[0] ? $P['check'] : $P['alert'], 18); ?> <?php echo $esc($flash[1]); ?></div>
  <?php endif; ?>

  <!-- summary -->
  <section class="sb-stats">
    <div class="sb-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['check'], 20); ?></span><div><span>Active</span><b><?php echo number_format($totalActive); ?></b></div></div>
    <div class="sb-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['clock'], 20); ?></span><div><span>In trial</span><b><?php echo number_format($totalTrial); ?></b></div></div>
    <div class="sb-stat"><span class="db-kpi__ic is-pink"><?php echo $__ic($P['alert'], 20); ?></span><div><span>Expired</span><b><?php echo number_format($totalExpired); ?></b></div></div>
    <div class="sb-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['coin'], 20); ?></span><div><span>Current MRR</span><b>₹<?php echo number_format((float)$totalRevenue); ?></b></div></div>
  </section>

  <div class="db-row db-row--bottom">

    <!-- ===== ledger ===== -->
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Subscription ledger</h2><p><span id="sbShown"><?php echo count($subRows); ?></span> of <?php echo count($subRows); ?> records<?php echo ($filterStatus || $filterPlan) ? ' (filtered)' : ''; ?></p></div>
      </div>

      <div class="sb-tools">
        <label class="sb-search"><?php echo $__ic($P['search'], 16); ?><input type="search" id="sbSearch" placeholder="Search company or plan" aria-label="Search subscriptions"></label>
        <form method="GET" class="sb-filters">
          <select name="status" class="sb-select js-autosubmit" aria-label="Filter by status">
            <option value="">All status</option>
            <?php foreach (['active' => 'Active', 'trial' => 'Trial', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $k => $l): ?>
              <option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
          </select>
          <select name="plan" class="sb-select js-autosubmit" aria-label="Filter by plan">
            <option value="">All plans</option>
            <?php foreach ($planList as $pl): ?>
              <option value="<?php echo (int)$pl['id']; ?>" <?php echo $filterPlan == $pl['id'] ? 'selected' : ''; ?>><?php echo $esc($pl['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($filterStatus || $filterPlan): ?><a href="subscriptions.php" class="sb-clear">Clear</a><?php endif; ?>
        </form>
      </div>

      <form method="POST" action="subscriptions.php<?php echo $filterQuery !== '' ? '?' . $esc($filterQuery) : ''; ?>" id="bulkDeleteForm" class="sb-bulk-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="bulk_delete" value="1">
        <input type="hidden" name="status" value="<?php echo $esc($filterStatus); ?>">
        <input type="hidden" name="plan" value="<?php echo (int)$filterPlan; ?>">
        <div class="sb-bulk" id="bulkBar" aria-live="polite">
          <div><b id="bulkSelectedLabel">0</b> selected</div>
          <button type="submit" class="sb-danger js-bulk-delete-btn"><?php echo $__ic($P['trash'], 15); ?> Delete selected</button>
        </div>
      </form>

      <div class="sb-scroll">
        <table class="db-table sb-table">
          <thead>
            <tr>
              <th style="width:44px;text-align:center"><input type="checkbox" id="selectAllSubscriptions" aria-label="Select all subscriptions"></th>
              <th>Subscription</th><th>Plan</th><th>Amount</th><th>Status</th><th>Period</th><th style="text-align:right">View</th>
            </tr>
          </thead>
          <tbody id="sbBody">
          <?php if ($subRows): foreach ($subRows as $s):
              $dl = $s['expires_at'] ? (int)round((strtotime($s['expires_at']) - time()) / 86400) : null;
              $st = (string)$s['status'];
              $cn = (string)($s['company_name'] ?? 'N/A'); ?>
            <tr data-q="<?php echo $esc(strtolower($cn . ' ' . $s['plan_name'] . ' #' . $s['id'])); ?>">
              <td style="text-align:center"><input type="checkbox" form="bulkDeleteForm" class="js-sub-check" name="ids[]" value="<?php echo (int)$s['id']; ?>" aria-label="Select subscription #<?php echo (int)$s['id']; ?>"></td>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr($cn, 0, 1))); ?></span><div><b><?php echo $esc($cn); ?></b><small>#<?php echo (int)$s['id']; ?> · Created <?php echo date('d M Y', strtotime($s['created_at'])); ?></small></div></div></td>
              <td><span class="db-pill is-off"><?php echo $esc($s['plan_name']); ?></span><small class="sb-sub"><?php echo $esc(ucfirst((string)$s['billing_cycle'])); ?></small></td>
              <td><b>₹<?php echo number_format((float)$s['amount']); ?></b></td>
              <td>
                <span class="db-pill <?php echo $statusCls[$st] ?? 'is-off'; ?>"><?php echo $esc(ucfirst($st)); ?></span>
                <?php if ($dl !== null && $dl >= 0 && $dl <= 7 && $st === 'active'): ?><small class="sb-sub sb-warn"><?php echo $dl; ?>d left</small><?php endif; ?>
              </td>
              <td class="sb-period"><?php echo date('d M Y', strtotime($s['starts_at'])); ?><?php echo $__ic($P['arrow'], 12); ?><?php echo $s['expires_at'] ? date('d M Y', strtotime($s['expires_at'])) : '—'; ?></td>
              <td style="text-align:right"><a href="subscription_view.php?id=<?php echo (int)$s['id']; ?>" class="sb-view" aria-label="View subscription #<?php echo (int)$s['id']; ?>"><?php echo $__ic($P['eye'], 16); ?> View</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div class="db-empty" id="sbEmpty" <?php echo $subRows ? 'hidden' : ''; ?>>No subscriptions found matching your filters.</div>
      </div>
    </section>

    <!-- ===== side ===== -->
    <div class="sb-side">
      <section class="db-card db-donut">
        <div class="db-donut__top"><h2>Status mix</h2></div>
        <div class="db-donut__chart">
          <canvas id="sbDonut" width="200" height="200"></canvas>
          <div class="db-donut__center"><div><b><?php echo number_format($totalActive + $totalTrial + $totalExpired + $totalCancelled); ?></b><span>Total</span></div></div>
        </div>
        <div class="db-dleg">
          <span><i style="background:#128C7E"></i>Active: <?php echo $totalActive; ?></span>
          <span><i style="background:#1d6fd6"></i>Trial: <?php echo $totalTrial; ?></span>
          <span><i style="background:#F59E0B"></i>Expired: <?php echo $totalExpired; ?></span>
          <span><i style="background:#CBD5D1"></i>Cancelled: <?php echo $totalCancelled; ?></span>
        </div>
      </section>

      <section class="db-card">
        <div class="db-card__head"><div><h2>Expiring soon</h2><p>Active plans ending in 14 days</p></div></div>
        <div class="db-list" style="padding-top:8px">
          <?php if ($soon): foreach ($soon as $x):
              $d = (int)ceil((strtotime($x['expires_at']) - time()) / 86400); ?>
            <a class="db-li" href="subscription_view.php?id=<?php echo (int)$x['id']; ?>" style="text-decoration:none;color:inherit">
              <span class="db-li__ic is-amber"><?php echo $esc(strtoupper(mb_substr((string)($x['company_name'] ?? '?'), 0, 1))); ?></span>
              <div class="db-li__t"><b><?php echo $esc($x['company_name'] ?? 'N/A'); ?></b><small><?php echo $esc($x['plan_name'] ?? ''); ?> · <?php echo date('d M', strtotime($x['expires_at'])); ?></small></div>
              <div class="db-li__r <?php echo $d <= 3 ? 'is-neg' : ''; ?>"><?php echo $d <= 0 ? 'Today' : $d . 'd'; ?></div>
            </a>
          <?php endforeach; else: ?>
            <div class="db-empty" style="padding:22px 8px">Nothing expiring in the next 14 days.</div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</div>

<style>
.sb{max-width:none;margin:0}
.sb-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;font-size:14.5px;font-weight:600}
.sb-toast.is-ok{background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E}.sb-toast.is-err{background:#FEF0EE;border:1px solid #F8C9C2;color:#B42318}
.sb-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.sb-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.sb-stat .db-kpi__ic{margin:0}
.sb-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.sb-stat b{display:block;margin-top:1px;font-size:27px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap}
.sb-side{display:flex;flex-direction:column;gap:18px;min-width:0}

.sb-tools{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px 4px}
.sb-search{display:flex;align-items:center;gap:9px;flex:1;min-width:210px;max-width:340px;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.sb-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.sb-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.sb-filters{display:flex;align-items:center;gap:10px;margin:0}
.sb-select{height:42px;padding:0 34px 0 13px;border:1.5px solid var(--line);border-radius:11px;font:600 14px Inter,system-ui,sans-serif;color:var(--ink);appearance:none;-webkit-appearance:none;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 11px center}
.sb-select:focus{outline:0;border-color:var(--g)}
.sb-clear{font-size:13.5px;font-weight:700;color:var(--g);text-decoration:none}

.sb-bulk-form{margin:0;padding:0 20px}
.sb-bulk{display:none;align-items:center;justify-content:space-between;gap:14px;margin:12px 0 0;padding:11px 15px;border-radius:12px;background:#FFF4F2;border:1px solid #F8C9C2;font-size:14px;color:var(--ink)}
.sb-bulk.is-visible{display:flex}
.sb-danger{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border:0;border-radius:9px;background:#e5484d;color:#fff;font:700 13.5px Inter,system-ui,sans-serif;cursor:pointer}
.sb-danger:hover{background:#cf3a3f}

.sb-scroll{max-height:640px;overflow:auto;padding:10px 20px 18px}
.sb-table{min-width:760px}
.sb-table thead th{position:sticky;top:0;z-index:2}
.sb-table input[type=checkbox]{width:17px;height:17px;accent-color:var(--g);cursor:pointer}
.sb-table tr[hidden]{display:none}
.sb-sub{display:block;margin-top:5px;font-size:12px;color:var(--tx)}
.sb-warn{color:#c0372b;font-weight:700}
.sb-period{white-space:nowrap;font-size:13.5px;color:var(--tx)}
.sb-period .db-ic{margin:0 6px;color:var(--mu)}
.sb-view{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border-radius:9px;background:#E3F7EE;color:#0B6B5E;font-size:13px;font-weight:700;text-decoration:none;transition:background .2s}
.sb-view:hover{background:#CFEFE0;color:#0B6B5E}
.db-empty[hidden]{display:none}

@media (max-width:1100px){.sb-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.sb-stats{grid-template-columns:1fr}.sb-filters{width:100%}.sb-select{flex:1}.sb-search{max-width:none}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// auto-submit the filter dropdowns (CSP-safe)
document.querySelectorAll('select.js-autosubmit').forEach(function (sel) {
    sel.addEventListener('change', function () { this.form.submit(); });
});

(function () {
    var rows = document.querySelectorAll('.js-sub-check');
    var selectAll = document.getElementById('selectAllSubscriptions');
    var bulkBar = document.getElementById('bulkBar');
    var bulkLabel = document.getElementById('bulkSelectedLabel');
    var bulkBtn = document.querySelector('.js-bulk-delete-btn');

    function checkedCount() { return document.querySelectorAll('.js-sub-check:checked').length; }
    function sync() {
        var count = checkedCount();
        if (bulkLabel) bulkLabel.textContent = String(count);
        if (bulkBar) bulkBar.classList.toggle('is-visible', count > 0);
        if (selectAll) {
            var vis = Array.prototype.filter.call(rows, function (cb) { return !cb.closest('tr').hidden; }).length;
            selectAll.checked = vis > 0 && count === vis;
            selectAll.indeterminate = count > 0 && count !== vis;
        }
    }
    if (selectAll) selectAll.addEventListener('change', function () {
        rows.forEach(function (cb) { if (!cb.closest('tr').hidden) cb.checked = selectAll.checked; });
        sync();
    });
    rows.forEach(function (cb) { cb.addEventListener('change', sync); });
    if (bulkBtn) bulkBtn.addEventListener('click', function (e) {
        var c = checkedCount();
        if (c === 0) { e.preventDefault(); return; }
        if (!confirm('Permanently delete ' + c + ' subscription(s)? Related payment and reminder logs will also be removed.')) e.preventDefault();
    });

    // live search
    var q = document.getElementById('sbSearch'), shown = document.getElementById('sbShown'), empty = document.getElementById('sbEmpty');
    if (q) q.addEventListener('input', function () {
        var term = q.value.trim().toLowerCase(), n = 0;
        document.querySelectorAll('#sbBody tr').forEach(function (tr) {
            var ok = !term || (tr.dataset.q || '').indexOf(term) !== -1;
            tr.hidden = !ok; if (ok) n++;
            if (!ok) { var cb = tr.querySelector('.js-sub-check'); if (cb) cb.checked = false; }
        });
        if (shown) shown.textContent = n;
        if (empty) empty.hidden = n > 0;
        sync();
    });
    sync();

    // status donut
    if (typeof Chart !== 'undefined') {
        var d = [<?php echo (int)$totalActive; ?>, <?php echo (int)$totalTrial; ?>, <?php echo (int)$totalExpired; ?>, <?php echo (int)$totalCancelled; ?>];
        var none = d.every(function (x) { return x === 0; });
        new Chart(document.getElementById('sbDonut'), {
            type: 'doughnut',
            data: { labels: ['Active', 'Trial', 'Expired', 'Cancelled'], datasets: [{ data: none ? [1] : d, backgroundColor: none ? ['#E3EEE9'] : ['#128C7E', '#1d6fd6', '#F59E0B', '#CBD5D1'], borderWidth: 0, spacing: 2 }] },
            options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: !none } } }
        });
    }
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
