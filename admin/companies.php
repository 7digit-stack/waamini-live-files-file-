<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Companies';
$currentPage = 'companies';

function waamini_company_count(mysqli $conn, string $sql): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Companies page count prepare failed: ' . $conn->error);
        return 0;
    }
    if (!$stmt->execute()) {
        error_log('Companies page count execute failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// Handle state-changing actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM companies WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
        header("Location: companies.php?msg=deleted");
        exit();
    }

    if ($action === 'toggle' && $id > 0) {
        $stmt_sel = $conn->prepare("SELECT status FROM companies WHERE id=?");
        if ($stmt_sel) {
            $stmt_sel->bind_param("i", $id);
            $stmt_sel->execute();
            $company = $stmt_sel->get_result()->fetch_assoc();
            $newStatus = (($company['status'] ?? 'active') === 'active') ? 'suspended' : 'active';
            $stmt = $conn->prepare("UPDATE companies SET status=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $id);
                $stmt->execute();
            }
        }
        header("Location: companies.php?msg=updated");
        exit();
    }
}

// Handle Clear Cache
if (isset($_POST['clear_cache'])) {
    csrf_verify();
    $cid = intval($_POST['company_id']);
    include_once '../includes/monitor_handler.php';
    clear_vendor_cache($cid);
    header("Location: companies.php?msg=cache_cleared");
    exit();
}

// Fetch all companies with their primary admin user details
// role IN includes all possible non-super-admin roles
$companies = $conn->query("
    SELECT c.*,
           u.id as user_id, u.name as admin_name, u.email as admin_email,
           u.mobile as admin_mobile, u.role as admin_role
    FROM companies c
    LEFT JOIN users u ON u.company_id = c.id
        AND u.role IN ('admin','client','member','agent')
        AND u.status = 'active'
    GROUP BY c.id
    ORDER BY c.id DESC
");
if (!$companies) {
    // Fallback: fetch companies without user join
    $companies = $conn->query("SELECT *, NULL as user_id, NULL as admin_name, email as admin_email, phone as admin_mobile, NULL as admin_role FROM companies ORDER BY id DESC");
}
// Fetch all companies with their primary admin user details.
// Use a deterministic subquery so the page works with stricter SQL modes.
$companies = null;
try {
    $companies = $conn->query("\n        SELECT c.*,\n               u.id as user_id, u.name as admin_name, u.email as admin_email,\n               u.mobile as admin_mobile, u.role as admin_role\n        FROM companies c\n        LEFT JOIN users u ON u.id = (\n            SELECT uu.id\n            FROM users uu\n            WHERE uu.company_id = c.id\n              AND uu.role IN ('admin','client','member','agent')\n              AND uu.status = 'active'\n            ORDER BY CASE WHEN uu.role = 'admin' THEN 0 WHEN uu.role = 'client' THEN 1 ELSE 2 END, uu.id ASC\n            LIMIT 1\n        )\n        ORDER BY c.id DESC\n    ");
} catch (Throwable $e) {
    error_log('Companies page list query failed: ' . $e->getMessage());
    $companies = null;
}
if (!$companies) {
    // Fallback: fetch companies without user join
    $companies = $conn->query("SELECT *, NULL as user_id, NULL as admin_name, email as admin_email, phone as admin_mobile, NULL as admin_role FROM companies ORDER BY id DESC");
}

$totalCompanies = waamini_company_count($conn, 'SELECT COUNT(*) as c FROM companies');
$activeCompanies = waamini_company_count($conn, "SELECT COUNT(*) as c FROM companies WHERE status='active'");
$suspendedCompanies = waamini_company_count($conn, "SELECT COUNT(*) as c FROM companies WHERE status='suspended'");
$companiesWithUsers = waamini_company_count($conn, 'SELECT COUNT(DISTINCT company_id) as c FROM users WHERE company_id IS NOT NULL');

// Current plan per vendor (latest active subscription)
$planOf = [];
try {
    $rp = $conn->query("SELECT s.company_id, p.name AS plan_name, s.expires_at
                        FROM subscriptions s LEFT JOIN subscription_plans p ON p.id = s.plan_id
                        WHERE s.status = 'active' ORDER BY s.id ASC");
    while ($rp && ($row = $rp->fetch_assoc())) $planOf[(int)$row['company_id']] = $row;
} catch (\Throwable $e) { /* plans are optional on this page */ }

include_once '../includes/monitor_handler.php';
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$svg = static fn(string $p, int $s = 16) => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$ic = [
    'store' => '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'pause' => '<circle cx="12" cy="12" r="10"/><line x1="10" x2="10" y1="15" y2="9"/><line x1="14" x2="14" y1="15" y2="9"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
    'card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
    'edit' => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'more' => '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'broom' => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
];
$msgText = ['deleted' => 'Vendor deleted.', 'updated' => 'Vendor status updated.', 'cache_cleared' => 'Temporary files cleared.'][$_GET['msg'] ?? ''] ?? '';

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">

<div class="db vd">
  <div class="db-head">
    <div>
      <h1>Vendors</h1>
      <p>Every business on WAAMINI: their plan, usage and account status.</p>
    </div>
    <div class="db-head__actions">
      <a href="company_add.php" class="db-btn db-btn--primary"><?php echo $svg($ic['plus'], 16); ?> Add vendor</a>
    </div>
  </div>

  <?php if ($msgText): ?><div class="vd-toast"><?php echo $svg($ic['check'], 17); ?> <?php echo $esc($msgText); ?></div><?php endif; ?>

  <div class="vd-kpis">
    <div class="vd-kpi"><span class="db-kpi__ic is-green"><?php echo $svg($ic['store'], 19); ?></span><div><small>Total vendors</small><b><?php echo number_format($totalCompanies); ?></b></div></div>
    <div class="vd-kpi"><span class="db-kpi__ic is-blue"><?php echo $svg($ic['check'], 19); ?></span><div><small>Active</small><b><?php echo number_format($activeCompanies); ?></b></div></div>
    <div class="vd-kpi"><span class="db-kpi__ic is-amber"><?php echo $svg($ic['pause'], 19); ?></span><div><small>Suspended</small><b><?php echo number_format($suspendedCompanies); ?></b></div></div>
    <div class="vd-kpi"><span class="db-kpi__ic is-purple"><?php echo $svg($ic['users'], 19); ?></span><div><small>With a login user</small><b><?php echo number_format($companiesWithUsers); ?></b></div></div>
  </div>

  <section class="db-card db-tablecard">
    <div class="db-card__head vd-head">
      <div><h2>Vendor directory</h2><p><span id="vdCount"><?php echo $companies ? $companies->num_rows : 0; ?></span> vendors</p></div>
      <div class="vd-tools">
        <div class="vd-chips" role="tablist" aria-label="Filter by status">
          <button type="button" class="vd-chip is-on" data-f="">All</button>
          <button type="button" class="vd-chip" data-f="active">Active</button>
          <button type="button" class="vd-chip" data-f="suspended">Suspended</button>
        </div>
        <label class="vd-search"><?php echo $svg($ic['search'], 16); ?><input type="search" id="vendorSearchInput" placeholder="Search name, email or phone" aria-label="Search vendors"></label>
      </div>
    </div>

    <div class="db-tablewrap">
      <table class="db-table vd-table">
        <thead>
          <tr><th>Vendor</th><th>Contact</th><th>Usage this month</th><th>Status</th><th>Joined</th><th class="vd-right">Actions</th></tr>
        </thead>
        <tbody id="vendorTableBody">
        <?php if ($companies && $companies->num_rows > 0): ?>
          <?php while ($c = $companies->fetch_assoc()):
            $cid = (int)$c['id'];
            $adminName = $c['admin_name'] ?: ($c['name'] . ' Admin');
            $email = $c['admin_email'] ?: ($c['email'] ?? '');
            $mobile = $c['admin_mobile'] ?: ($c['phone'] ?? '');
            $createdTs = !empty($c['created_at']) ? strtotime($c['created_at']) : time();
            $status = ($c['status'] ?? 'active') === 'active' ? 'active' : 'suspended';
            $usage = get_company_msg_usage($cid);
            $cacheSize = get_folder_size(dirname(__DIR__) . "/storage/vendor_" . $cid);
            $plan = $planOf[$cid] ?? null;
            $canLogin = !empty($c['user_id']); ?>
          <tr class="vendor-row" data-status="<?php echo $status; ?>">
            <td>
              <div class="db-who">
                <span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$c['name'], 0, 1))); ?></span>
                <div><b><?php echo $esc($c['name']); ?></b>
                  <small><?php if ($plan): ?><span class="vd-plan"><?php echo $esc($plan['plan_name'] ?: 'Plan'); ?></span><?php echo !empty($plan['expires_at']) ? ' until ' . $esc(date('d M Y', strtotime((string)$plan['expires_at']))) : ''; ?><?php else: ?><span class="vd-plan is-none">No active plan</span><?php endif; ?></small></div>
              </div>
            </td>
            <td>
              <div class="vd-contact"><b><?php echo $esc($adminName); ?></b><small><?php echo $esc($email); ?></small><small><?php echo $esc($mobile); ?></small></div>
            </td>
            <td>
              <div class="vd-usage"><b class="<?php echo $usage > 5000 ? 'is-high' : ''; ?>"><?php echo number_format($usage); ?></b><small>messages</small></div>
              <form method="POST" class="vd-cache" data-confirm="Clear temporary files for this vendor?">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="company_id" value="<?php echo $cid; ?>">
                <span>Cache <?php echo $esc(format_bytes($cacheSize)); ?></span>
                <button type="submit" name="clear_cache" title="Clear temporary files"><?php echo $svg($ic['broom'], 12); ?> Clear</button>
              </form>
            </td>
            <td><span class="db-pill <?php echo $status === 'active' ? 'is-ok' : 'is-warn'; ?>"><?php echo ucfirst($status); ?></span></td>
            <td class="vd-date"><?php echo $esc(date('d M Y', $createdTs ?: time())); ?></td>
            <td class="vd-right">
              <div class="vd-acts">
                <?php if ($canLogin): ?>
                  <a href="impersonate.php?company_id=<?php echo $cid; ?>&_token=<?php echo urlencode(csrf_get_token()); ?>" class="vd-btn js-confirm" title="Open this vendor's dashboard"
                     data-confirm="Login as <?php echo $esc($adminName); ?>? You will be redirected to the vendor dashboard."><?php echo $svg($ic['login'], 15); ?> Login</a>
                <?php else: ?>
                  <span class="vd-btn is-off" title="This vendor has no active login user yet"><?php echo $svg($ic['login'], 15); ?> Login</span>
                <?php endif; ?>
                <a href="vendor_subscription.php?company_id=<?php echo $cid; ?>" class="vd-btn" title="Manage subscription"><?php echo $svg($ic['card'], 15); ?> Plan</a>
                <a href="company_edit.php?id=<?php echo $cid; ?>" class="vd-btn" title="Edit vendor and add-ons"><?php echo $svg($ic['edit'], 15); ?> Edit</a>
                <div class="vd-more">
                  <button type="button" class="vd-btn vd-icon" aria-label="More actions" aria-haspopup="true"><?php echo $svg($ic['more'], 16); ?></button>
                  <div class="vd-menu" hidden>
                    <form method="POST" action="companies.php">
                      <?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $cid; ?>">
                      <button type="submit" class="js-confirm" data-confirm="<?php echo $status === 'active' ? 'Suspend' : 'Activate'; ?> this vendor?"><?php echo $svg($status === 'active' ? $ic['pause'] : $ic['check'], 15); ?> <?php echo $status === 'active' ? 'Suspend vendor' : 'Activate vendor'; ?></button>
                    </form>
                    <form method="POST" action="companies.php">
                      <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $cid; ?>">
                      <button type="submit" class="js-confirm is-danger" data-confirm="Delete this company and all its data? This cannot be undone."><?php echo $svg($ic['trash'], 15); ?> Delete vendor</button>
                    </form>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="6"><div class="db-empty">No vendors yet. <a href="company_add.php">Add your first vendor</a>.</div></td></tr>
        <?php endif; ?>
          <tr id="vdNone" hidden><td colspan="6"><div class="db-empty">No vendors match your search.</div></td></tr>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  // Search + status filter (client-side, same rows as before)
  var input = document.getElementById('vendorSearchInput'), status = '';
  function apply() {
    var q = (input && input.value || '').toLowerCase(), shown = 0;
    document.querySelectorAll('.vendor-row').forEach(function (row) {
      var ok = (!status || row.getAttribute('data-status') === status) && row.innerText.toLowerCase().indexOf(q) !== -1;
      row.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    var c = document.getElementById('vdCount'); if (c) c.textContent = shown;
    var none = document.getElementById('vdNone'); if (none) none.hidden = shown !== 0;
  }
  if (input) input.addEventListener('input', apply);
  document.querySelectorAll('.vd-chip').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.vd-chip').forEach(function (o) { o.classList.toggle('is-on', o === b); });
      status = b.getAttribute('data-f'); apply();
    });
  });
  // "More" menus
  document.querySelectorAll('.vd-more').forEach(function (m) {
    var btn = m.querySelector('.vd-icon'), menu = m.querySelector('.vd-menu');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.hidden;
      document.querySelectorAll('.vd-menu').forEach(function (x) { x.hidden = true; });
      menu.hidden = !open;
    });
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.vd-menu')) document.querySelectorAll('.vd-menu').forEach(function (x) { x.hidden = true; }); });
  // Login / suspend / delete ask first (their data-confirm text was never wired up before)
  document.querySelectorAll('.js-confirm[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) { if (!confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); } });
  });
  // Confirm dialogs (CSP-safe, same messages as before)
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) { if (!confirm(this.getAttribute('data-confirm'))) e.preventDefault(); });
  });
})();
</script>

<style>
.vd .db-btn--primary{background:#128C7E;box-shadow:none}.vd .db-btn--primary:hover{background:#0B6B5E;transform:none}
.vd-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;color:#0B6B5E;font-weight:600;font-size:14px}
.vd-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:18px}
.vd-kpi{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.vd-kpi .db-kpi__ic{margin:0;flex:none}
.vd-kpi small{display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.vd-kpi b{display:block;margin-top:2px;font-size:24px;font-weight:800;letter-spacing:-.02em;color:var(--ink)}
.vd-head{flex-wrap:wrap}
.vd-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.vd-chips{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:#F3F7F5}
.vd-chip{height:32px;padding:0 13px;border:0;border-radius:9px;background:none;font:700 12.5px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer}
.vd-chip.is-on{background:#fff;color:#0B6B5E;box-shadow:0 4px 10px -6px rgba(15,38,34,.35)}
.vd-search{display:flex;align-items:center;gap:8px;width:260px;max-width:100%;height:40px;padding:0 12px;border-radius:11px;background:#F3F7F5;color:var(--mu)}
.vd-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 13.5px Inter,system-ui,sans-serif;color:var(--ink)}
.vd-table td{border-bottom:0;vertical-align:middle}
.vd-table tbody tr.vendor-row:nth-child(even) td{background:#F8FCFA}
.vd-table th{white-space:nowrap}
.vd-table .vd-right{text-align:right}
.vd-table td:first-child{min-width:190px;max-width:230px}
.vd-table .db-who b{display:block;line-height:1.3}
.vd-table .db-who small{display:block;margin-top:3px;white-space:nowrap}
.vd-plan{display:inline-block;white-space:nowrap;padding:1px 8px;border-radius:6px;background:#E6FCF2;color:#0B6B5E;font-size:11.5px;font-weight:700}
.vd-plan.is-none{background:#EEF2F1;color:#5F6F6A}
.vd-contact b{display:block;font-size:13.5px;color:var(--ink)}
.vd-contact small{display:block;max-width:200px;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:var(--tx);white-space:nowrap}
.vd-usage{display:flex;align-items:baseline;gap:5px}
.vd-usage b{font-size:15px;color:var(--ink)}.vd-usage b.is-high{color:#c0372b}
.vd-usage small{font-size:12px;color:var(--mu)}
.vd-cache{display:flex;align-items:center;gap:8px;margin-top:3px;font-size:11.5px;color:var(--mu)}
.vd-cache button{display:inline-flex;align-items:center;gap:3px;padding:0;border:0;background:none;color:#0B6B5E;font:700 11.5px Inter,system-ui,sans-serif;cursor:pointer}
.vd-date{white-space:nowrap;color:var(--tx)}
.vd-acts{display:inline-flex;align-items:center;gap:6px}
.vd-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 11px;border:0;border-radius:10px;background:#F3F7F5;color:var(--ink);font:700 12.5px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;white-space:nowrap}
.vd-btn:hover{background:#E6FCF2;color:#0B6B5E}
.vd-btn.is-off{opacity:.45;cursor:not-allowed}
.vd-btn.is-off:hover{background:#F3F7F5;color:var(--ink)}
.vd-icon{width:34px;padding:0;justify-content:center}
.vd-more{position:relative}
.vd-menu{position:absolute;right:0;top:40px;z-index:20;min-width:190px;padding:6px;border-radius:14px;background:#fff;box-shadow:0 18px 40px -18px rgba(15,38,34,.5)}
.vd-menu form{margin:0}
.vd-menu button{display:flex;align-items:center;gap:8px;width:100%;padding:9px 10px;border:0;border-radius:9px;background:none;font:600 13px Inter,system-ui,sans-serif;color:var(--ink);text-align:left;cursor:pointer}
.vd-menu button:hover{background:#F3F7F5}
.vd-menu button.is-danger{color:#c0372b}.vd-menu button.is-danger:hover{background:#FDE4E1}
@media (max-width:1100px){.vd-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.vd-kpis{grid-template-columns:1fr}.vd-search{width:100%}}
</style>

<?php include '../includes/admin_footer.php'; ?>
