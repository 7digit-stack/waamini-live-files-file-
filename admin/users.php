<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Users';
$currentPage = 'users';

$error = '';
$success = '';

/**
 * Bind dynamic int parameters for mysqli prepared statements (portable).
 */
function waamini_bind_int_params(mysqli_stmt $stmt, array $ints): bool {
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

/**
 * Company IDs tied to these user rows (before delete).
 *
 * @return int[]
 */
function waamini_user_company_ids_for_ids(mysqli $conn, array $userIds): array {
    if ($userIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT DISTINCT company_id FROM users WHERE id IN ($placeholders) AND company_id IS NOT NULL";
    $stmt = $conn->prepare($sql);
    if (!$stmt || !waamini_bind_int_params($stmt, $userIds)) {
        return [];
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (int)($row['company_id'] ?? 0);
        if ($cid > 0) {
            $out[] = $cid;
        }
    }

    return array_values(array_unique($out));
}

/** Remove vendor (company) rows that no longer have any users. */
function waamini_prune_empty_companies(mysqli $conn, array $companyIds): void {
    foreach (array_unique(array_filter($companyIds)) as $cid) {
        if ($cid <= 0) {
            continue;
        }
        $chk = $conn->prepare('SELECT COUNT(*) AS c FROM users WHERE company_id=?');
        if (!$chk) {
            continue;
        }
        $chk->bind_param('i', $cid);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if ((int)($row['c'] ?? 0) !== 0) {
            continue;
        }
        $del = $conn->prepare('DELETE FROM companies WHERE id=?');
        if ($del) {
            $del->bind_param('i', $cid);
            $del->execute();
        }
    }
}

function waamini_count(mysqli $conn, string $sql): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Users page count prepare failed: ' . $conn->error);
        return 0;
    }
    if (!$stmt->execute()) {
        error_log('Users page count execute failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// SQL fragment: never delete super_admin accounts (NULL role is allowed to delete — legacy rows).
$sqlNotSuperAdmin = '(role IS NULL OR role <> \'super_admin\')';

// Handle state-changing actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    // Bulk delete (never current session user; never super_admin rows)
    if ($action === 'bulk_delete') {
        $idsRaw = $_POST['ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [];
        }
        $ids = [];
        foreach ($idsRaw as $raw) {
            $i = (int)$raw;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
        $ids = array_values(array_unique($ids));
        $myId = (int)($_SESSION['user_id'] ?? 0);
        $ids = array_values(array_filter($ids, static function ($uid) use ($myId) {
            return $uid > 0 && $uid !== $myId;
        }));

        $deleted = 0;
        if ($ids !== []) {
            $companyIds = waamini_user_company_ids_for_ids($conn, $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM users WHERE id IN ($placeholders) AND {$sqlNotSuperAdmin}";
            $stmt = $conn->prepare($sql);
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
                $deleted = (int)$stmt->affected_rows;
            }
            if ($deleted > 0 && $companyIds !== []) {
                waamini_prune_empty_companies($conn, $companyIds);
            }
        }
        header('Location: users.php?msg=bulk_deleted&n=' . $deleted);
        exit();
    }

    // Delete
    if ($action === 'delete' && $id > 0) {
        $aff = 0;
        if ($id !== (int)($_SESSION['user_id'] ?? 0)) {
            $sel = $conn->prepare("SELECT company_id FROM users WHERE id=? AND {$sqlNotSuperAdmin}");
            $companyId = 0;
            if ($sel) {
                $sel->bind_param('i', $id);
                $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                if ($row) {
                    $companyId = (int)($row['company_id'] ?? 0);
                }
            }
            $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND {$sqlNotSuperAdmin}");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $aff = (int)$stmt->affected_rows;
            }
            if ($aff > 0 && $companyId > 0) {
                waamini_prune_empty_companies($conn, [$companyId]);
            }
        }
        header('Location: users.php?msg=' . ($aff > 0 ? 'deleted' : 'noop'));
        exit();
    }

    // Enable/disable toggle
    if ($action === 'toggle' && $id > 0) {
        if ($id !== (int)($_SESSION['user_id'] ?? 0)) {
            $stmt_sel = $conn->prepare("SELECT status FROM users WHERE id=? AND {$sqlNotSuperAdmin}");
            if ($stmt_sel) {
                $stmt_sel->bind_param('i', $id);
                $stmt_sel->execute();
                $user = $stmt_sel->get_result()->fetch_assoc();
                $newStatus = ($user && ($user['status'] ?? 'active') === 'active') ? 'disabled' : 'active';

                $stmt_upd = $conn->prepare("UPDATE users SET status=? WHERE id=? AND {$sqlNotSuperAdmin}");
                if ($stmt_upd) {
                    $stmt_upd->bind_param('si', $newStatus, $id);
                    $stmt_upd->execute();
                }
            }
        }
        header('Location: users.php?msg=toggled');
        exit();
    }
}

// Handle quick password reset
if (isset($_POST['quick_reset'])) {
    csrf_verify();
    $uid = (int)($_POST['user_id'] ?? 0);
    $newPass = (string)($_POST['quick_password'] ?? '');
    if ($uid <= 0) {
        $error = 'Invalid user id.';
    } elseif (strlen($newPass) >= 6) {
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=? AND {$sqlNotSuperAdmin}");
        if ($stmt) {
            $stmt->bind_param('si', $hashed, $uid);
            $stmt->execute();
            $success = 'Password reset successfully!';
        } else {
            $error = 'Could not reset password right now.';
        }
    } else {
        $error = 'Password must be at least 6 characters!';
    }
}

// Handle login-as-user (GET allowed but requires CSRF token)
if (isset($_GET['login_as'])) {
    $token = (string)($_GET['_token'] ?? '');
    csrf_verify($token);
    $uid = intval($_GET['login_as']);
    $stmt = $conn->prepare('SELECT * FROM users WHERE id=?');
    $targetUser = null;
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
    }
    // Extra guard: never impersonate another super_admin.
    if ($targetUser && ($targetUser['role'] ?? '') === 'super_admin') {
        header('Location: users.php?msg=forbidden');
        exit();
    }
    if ($targetUser && $uid != $_SESSION['user_id']) {
        // Save admin session to return later
        $_SESSION['admin_original_id'] = $_SESSION['user_id'];
        $_SESSION['admin_original_name'] = $_SESSION['user_name'];
        $_SESSION['admin_original_role'] = $_SESSION['role'];

        // Switch to client
        $_SESSION['user_id'] = $targetUser['id'];
        $_SESSION['user_name'] = $targetUser['name'];
        $_SESSION['company_id'] = $targetUser['company_id'];
        $_SESSION['role'] = $targetUser['role'];
        $_SESSION['impersonating'] = true;

        header('Location: /dashboard.php');
        exit();
    }
}

// Add status column if not exists
$check = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if ($check && $check->num_rows === 0) {
    // Schema migration handled elsewhere.
}

// Fetch all users with company name and usage
$users = null;
try {
    $users = $conn->query("
        SELECT u.*, c.name as company_name, c.id as cid, c.status as company_status,
               COALESCE(mm.msgs_month, 0) as msgs_month
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        LEFT JOIN (
            SELECT company_id, COUNT(*) as msgs_month
            FROM messages
            WHERE direction='outgoing'
              AND DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
            GROUP BY company_id
        ) mm ON mm.company_id = u.company_id
        ORDER BY u.id DESC
    ");
} catch (Throwable $e) {
    error_log('Users page list query failed: ' . $e->getMessage());
    $users = null;
}

// Stats
$totalUsers = waamini_count($conn, 'SELECT COUNT(*) as c FROM users');
$adminCount = waamini_count($conn, "SELECT COUNT(*) as c FROM users WHERE role='super_admin'");
// Vendor-side portal users (registration sets role='admin'; invitations may use 'client')
$clientCount = waamini_count($conn, "SELECT COUNT(*) as c FROM users WHERE role IN ('client','admin')");
$disabledCount = waamini_count($conn, "SELECT COUNT(*) as c FROM users WHERE status='disabled'");

include '../includes/admin_header.php';

$flashMsg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$flashBulkN = isset($_GET['n']) ? (int)$_GET['n'] : 0;
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'brief'  => '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
    'ban'    => '<path d="M22 2 2 22"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'userplus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
    'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'key'    => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    'login'  => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
    'power'  => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'card'   => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
    'x'      => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'eyeoff' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
    'dice'   => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M12 12h.01"/><path d="M8 16h.01"/>',
];
$userRows = [];
if ($users instanceof mysqli_result) { while ($r = $users->fetch_assoc()) { $userRows[] = $r; } }
$roleLabels = ['super_admin' => 'Super admin', 'client' => 'Client', 'admin' => 'Vendor owner'];

// flash
$flashClass = 'is-ok'; $flashText = '';
if ($flashMsg !== '') {
    if ($flashMsg === 'forbidden') { $flashClass = 'is-err'; $flashText = 'That action is not allowed.'; }
    elseif ($flashMsg === 'bulk_deleted') { $flashText = $flashBulkN > 0 ? 'Deleted ' . $flashBulkN . ' user' . ($flashBulkN === 1 ? '' : 's') . '.' : 'No users were deleted (nothing selected or none eligible).'; }
    elseif ($flashMsg === 'deleted') { $flashText = 'User deleted. Empty vendor rows were removed when this was the last login for that vendor.'; }
    elseif ($flashMsg === 'noop') { $flashClass = 'is-err'; $flashText = 'Nothing was deleted (already removed, protected account, or the server could not apply the delete).'; }
    elseif ($flashMsg === 'toggled') { $flashText = 'User status updated.'; }
    else { $flashText = 'Done: ' . $flashMsg; }
}
?>

<div class="db us">

  <div class="db-head">
    <div>
      <h1>Users &amp; access</h1>
      <p>Manage vendor logins, roles and portal access. Super admin accounts cannot be removed from this screen.</p>
    </div>
    <div class="db-head__actions">
      <a href="companies.php" class="db-btn"><?php echo $__ic($P['building'], 16); ?> Vendors</a>
      <a href="user_add.php" class="db-btn"><?php echo $__ic($P['userplus'], 16); ?> Add user</a>
      <a href="client_create.php" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> Create client</a>
    </div>
  </div>

  <?php if ($flashText !== ''): ?>
    <div class="us-alert <?php echo $flashClass; ?>" role="status"><?php echo $__ic($flashClass === 'is-ok' ? $P['check'] : $P['alert'], 18); ?><div><?php echo $esc($flashText); ?></div></div>
  <?php endif; ?>
  <?php if ($error): ?><div class="us-alert is-err" role="alert"><?php echo $__ic($P['alert'], 18); ?><div><?php echo $esc($error); ?></div></div><?php endif; ?>
  <?php if ($success): ?><div class="us-alert is-ok" role="status"><?php echo $__ic($P['check'], 18); ?><div><?php echo $esc($success); ?></div></div><?php endif; ?>

  <!-- summary -->
  <section class="us-stats">
    <div class="us-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['users'], 20); ?></span><div><span>Total users</span><b><?php echo (int)$totalUsers; ?></b></div></div>
    <div class="us-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['shield'], 20); ?></span><div><span>Super admins</span><b><?php echo (int)$adminCount; ?></b></div></div>
    <div class="us-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['brief'], 20); ?></span><div><span>Vendor users</span><b><?php echo (int)$clientCount; ?></b></div></div>
    <div class="us-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['ban'], 20); ?></span><div><span>Disabled</span><b><?php echo (int)$disabledCount; ?></b></div></div>
  </section>

  <!-- directory -->
  <section class="db-card db-tablecard">
    <div class="db-card__head"><div><h2>Directory</h2><p>Search, filter, select and manage accounts</p></div></div>

    <div class="us-tools">
      <label class="us-search" for="userSearch"><?php echo $__ic($P['search'], 16); ?><input type="search" id="userSearch" placeholder="Search name, email, company…" autocomplete="off"></label>
      <div class="db-seg" id="usFilter">
        <button type="button" class="is-active" data-role="all">All</button>
        <button type="button" data-role="super_admin">Super admins</button>
        <button type="button" data-role="vendor">Vendor users</button>
        <button type="button" data-role="disabled">Disabled</button>
      </div>
      <span class="us-count" id="userVisibleCount"></span>
    </div>

    <!-- Do not wrap the table in this form: inner row forms would invalidate HTML and browsers drop bulk fields from POST -->
    <form method="POST" action="users.php" id="bulkDeleteForm" aria-hidden="true" style="display:none">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="bulk_delete">
    </form>

    <div class="us-bulk" id="bulkBar" aria-live="polite">
      <div><b id="bulkSelectedLabel">0</b> selected</div>
      <button type="submit" form="bulkDeleteForm" class="us-danger js-bulk-delete-btn"><?php echo $__ic($P['trash'], 15); ?> Delete selected</button>
    </div>

    <div class="us-scroll">
      <table class="db-table us-table">
        <thead>
          <tr>
            <th style="width:44px;text-align:center"><input type="checkbox" id="selectAllUsers" title="Select visible" aria-label="Select all visible users"></th>
            <th>User</th><th>Company</th><th>Usage</th><th>Role</th><th>Status</th><th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody id="usersTableBody">
        <?php if ($userRows): foreach ($userRows as $u):
            $isYou = ($u['id'] == $_SESSION['user_id']);
            $isDisabled = ($u['status'] ?? 'active') === 'disabled';
            $rawRole = (string)($u['role'] ?? '');
            $roleShown = $roleLabels[$rawRole] ?? ($rawRole !== '' ? $rawRole : '—');
            $isSuper = ($rawRole === 'super_admin');
            $canBulk = !$isYou && !$isSuper;
            $msgs = (int)($u['msgs_month'] ?? 0);
            $usePct = min(100, (int)round($msgs / 1000 * 100));
            $roleKey = $isSuper ? 'super_admin' : 'vendor';
            $searchBlob = strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['mobile'] ?? '') . ' ' . ($u['company_name'] ?? '') . ' ' . $roleShown);
            $companyActive = (($u['company_status'] ?? 'active') === 'active'); ?>
          <tr class="js-user-row<?php echo $isDisabled ? ' users-row-muted' : ''; ?>"
              data-search="<?php echo $esc($searchBlob); ?>" data-role="<?php echo $roleKey; ?>" data-disabled="<?php echo $isDisabled ? '1' : '0'; ?>">
            <td style="text-align:center">
              <?php if ($canBulk): ?>
                <input type="checkbox" form="bulkDeleteForm" class="js-bulk-check" name="ids[]" value="<?php echo (int)$u['id']; ?>" aria-label="Select <?php echo $esc($u['name']); ?>">
              <?php else: ?><span class="us-dash" title="Cannot bulk-select this account">—</span><?php endif; ?>
            </td>
            <td>
              <div class="db-who">
                <span class="db-av <?php echo $isSuper ? 'is-sa' : ''; ?>"><?php echo $esc(strtoupper(mb_substr((string)$u['name'], 0, 1))); ?></span>
                <div style="min-width:0">
                  <b><a href="client_view.php?id=<?php echo (int)$u['id']; ?>" class="us-name"><?php echo $esc($u['name']); ?></a><?php if ($isYou): ?> <span class="us-you">you</span><?php endif; ?></b>
                  <small><?php echo $esc($u['email']); ?></small>
                  <?php if (($u['mobile'] ?? '') !== ''): ?><small><?php echo $esc($u['mobile']); ?></small><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <b class="us-co"><?php echo $esc($u['company_name'] ?? '—'); ?></b>
              <?php if ($u['cid']): ?>
                <form method="POST" action="companies.php" class="us-inline"><?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$u['cid']; ?>">
                  <button type="submit" class="us-link <?php echo $companyActive ? 'is-warn' : 'is-ok'; ?>"><?php echo $companyActive ? 'Suspend vendor' : 'Activate vendor'; ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <div class="us-use <?php echo $msgs > 1000 ? 'is-over' : ''; ?>"><b><?php echo number_format($msgs); ?></b><span>/ month</span></div>
              <div class="us-bar"><i style="width:<?php echo $usePct; ?>%"></i></div>
            </td>
            <td><span class="db-pill <?php echo $isSuper ? 'is-warn' : 'is-ok'; ?>"><?php echo $esc($roleShown); ?></span></td>
            <td><span class="db-pill <?php echo $isDisabled ? 'is-bad' : 'is-ok'; ?>"><?php echo $isDisabled ? 'Disabled' : 'Active'; ?></span></td>
            <td>
              <div class="us-acts">
                <a href="client_view.php?id=<?php echo (int)$u['id']; ?>" class="us-ico" title="View" aria-label="View user"><?php echo $__ic($P['eye'], 16); ?></a>
                <a href="user_edit.php?id=<?php echo (int)$u['id']; ?>" class="us-ico" title="Edit" aria-label="Edit user"><?php echo $__ic($P['edit'], 16); ?></a>
                <?php if (!$isYou): ?>
                  <button type="button" class="us-ico js-reset-pwd" data-uid="<?php echo (int)$u['id']; ?>" data-uname="<?php echo $esc($u['name']); ?>" title="Reset password" aria-label="Reset password"><?php echo $__ic($P['key'], 16); ?></button>
                  <?php if (!$isSuper): ?>
                    <a href="users.php?login_as=<?php echo (int)$u['id']; ?>&_token=<?php echo urlencode(csrf_get_token()); ?>" class="us-ico js-confirm"
                       data-confirm="Login as <?php echo $esc($u['name']); ?>? You will be redirected to their panel." title="Login as this user" aria-label="Login as this user"><?php echo $__ic($P['login'], 16); ?></a>
                    <?php if ($u['cid']): ?><a href="vendor_subscription.php?company_id=<?php echo (int)$u['cid']; ?>" class="us-ico" title="Subscription plan" aria-label="Subscription plan"><?php echo $__ic($P['card'], 16); ?></a><?php endif; ?>
                  <?php endif; ?>
                  <form method="POST" action="users.php" class="us-inline"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                    <button type="submit" class="us-ico <?php echo $isDisabled ? 'is-on' : 'is-warn'; ?> js-confirm" data-confirm="<?php echo $isDisabled ? 'Enable' : 'Disable'; ?> this user?"
                            title="<?php echo $isDisabled ? 'Enable user' : 'Disable user'; ?>" aria-label="<?php echo $isDisabled ? 'Enable user' : 'Disable user'; ?>"><?php echo $__ic($P['power'], 16); ?></button>
                  </form>
                  <?php if (!$isSuper): ?>
                    <form method="POST" action="users.php" class="us-inline"><?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <button type="submit" class="us-ico is-danger js-confirm" data-confirm="Permanently delete this user?" title="Delete user" aria-label="Delete user"><?php echo $__ic($P['trash'], 16); ?></button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <div class="db-empty" id="usEmpty" <?php echo $userRows ? 'hidden' : ''; ?>><?php echo $userRows ? 'No users match your search.' : 'No users yet.'; ?> <?php if (!$userRows): ?><a href="user_add.php" class="db-link">Add a user</a><?php endif; ?></div>
    </div>
  </section>
</div>

<!-- Quick reset password modal -->
<div class="us-modal" id="resetModal" hidden>
  <div class="us-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="usResetT">
    <div class="us-modal__head">
      <div><h3 id="usResetT">Reset password</h3><p>for <b id="resetUserName"></b></p></div>
      <button type="button" class="us-ico" id="usModalX" aria-label="Close"><?php echo $__ic($P['x'], 17); ?></button>
    </div>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="us-modal__body">
        <label for="quickPassword">New password</label>
        <div class="us-row">
          <input type="password" name="quick_password" id="quickPassword" class="us-input" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
          <button type="button" id="togglePassBtn" class="us-ico" title="Show / hide" aria-label="Show or hide password"><?php echo $__ic($P['eyeoff'], 16); ?></button>
        </div>
        <div class="us-gen">
          <button type="button" id="generatePassBtn" class="db-btn"><?php echo $__ic($P['dice'], 15); ?> Generate</button>
          <code id="generatedPass"></code>
        </div>
      </div>
      <div class="us-modal__foot">
        <button type="button" id="closeResetBtn" class="db-btn">Cancel</button>
        <button type="submit" name="quick_reset" value="1" class="db-btn db-btn--primary">Apply new password</button>
      </div>
    </form>
  </div>
</div>

<style>
.us{max-width:none;margin:0}
.us-alert{display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;padding:13px 16px;border-radius:13px;font-size:14.5px;line-height:1.5;font-weight:600}
.us-alert .db-ic{margin-top:2px}
.us-alert.is-ok{background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E}.us-alert.is-err{background:#FEF0EE;border:1px solid #F8C9C2;color:#B42318}
.us-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.us-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px}
.us-stat .db-kpi__ic{margin:0}
.us-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.us-stat b{display:block;margin-top:1px;font-size:28px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}

.us-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 20px 6px}
.us-search{display:flex;align-items:center;gap:9px;flex:1;min-width:230px;max-width:380px;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.us-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.us-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.us-count{margin-left:auto;font-size:13.5px;font-weight:600;color:var(--tx)}
.us-bulk{display:none;align-items:center;justify-content:space-between;gap:14px;margin:12px 20px 0;padding:11px 15px;border-radius:12px;background:#FFF4F2;border:1px solid #F8C9C2;font-size:14px;color:var(--ink)}
.us-bulk.is-visible{display:flex}
.us-danger{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border:0;border-radius:9px;background:#e5484d;color:#fff;font:700 13.5px Inter,system-ui,sans-serif;cursor:pointer}
.us-danger:hover{background:#cf3a3f}

.us-scroll{max-height:680px;overflow:auto;padding:10px 20px 18px}
.us-table{min-width:880px}
.us-table thead th{position:sticky;top:0;z-index:2}
.us-table input[type=checkbox]{width:17px;height:17px;accent-color:var(--g);cursor:pointer}
.users-row-hidden{display:none}
.users-row-muted{opacity:.62}
.db-av.is-sa{background:#FEF3DC;color:#b7791f}
.us-name{color:inherit;text-decoration:none}.us-name:hover{color:var(--g)}
.us-you{display:inline-block;margin-left:5px;padding:1px 8px;border-radius:999px;background:#E3F7EE;color:#0B6B5E;font-size:11px;font-weight:700;vertical-align:1px}
.us-co{display:block;font-size:14px;color:var(--ink)}
.us-inline{display:inline;margin:0}
.us-link{margin-top:3px;padding:0;border:0;background:none;font:600 12.5px Inter,system-ui,sans-serif;cursor:pointer;text-decoration:underline}
.us-link.is-warn{color:#b7791f}.us-link.is-ok{color:#12803f}
.us-dash{color:var(--mu)}
.us-use{display:flex;align-items:baseline;gap:6px;font-size:13px;color:var(--tx)}.us-use b{font-size:15px;color:#12803f}.us-use.is-over b{color:#c0372b}
.us-bar{height:5px;border-radius:3px;background:#E8F1ED;overflow:hidden;margin-top:6px;width:110px}
.us-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--g),var(--g2));border-radius:3px}
.us-acts{display:flex;justify-content:flex-end;align-items:center;gap:6px;flex-wrap:wrap}
.us-ico{width:34px;height:34px;border-radius:9px;border:1.5px solid var(--line);background:#fff;color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none;transition:border-color .2s,color .2s,background .2s}
.us-ico:hover{border-color:var(--g);color:var(--g);background:var(--soft)}
.us-ico.is-warn:hover{border-color:#F59E0B;color:#b7791f;background:#FFF8E8}
.us-ico.is-on{color:#12803f;border-color:#BFE7CE}
.us-ico.is-danger:hover{border-color:#e5484d;color:#e5484d;background:#FFF6F5}
.db-empty[hidden]{display:none}

.us-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,38,34,.55);backdrop-filter:blur(6px)}
.us-modal[hidden]{display:none}
.us-modal__dialog{width:100%;max-width:440px;background:#fff;border-radius:20px;box-shadow:0 40px 90px -20px rgba(0,0,0,.45)}
.us-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:22px 24px 14px;border-bottom:1px solid #EEF3F1}
.us-modal__head h3{margin:0 0 3px;font-size:19px;font-weight:800;color:var(--ink)}
.us-modal__head p{margin:0;font-size:13.5px;color:var(--tx)}.us-modal__head p b{color:var(--g)}
.us-modal__body{padding:18px 24px}
.us-modal__body label{display:block;margin-bottom:8px;font-size:14px;font-weight:700;color:var(--ink)}
.us-row{display:flex;gap:10px}
.us-input{flex:1;min-width:0;height:44px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;font:500 15px Inter,system-ui,sans-serif;color:var(--ink)}
.us-input:focus{outline:0;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.us-row .us-ico{width:44px;height:44px;border-radius:11px}
.us-gen{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}
.us-gen code{font:600 13.5px ui-monospace,Menlo,Consolas,monospace;color:#0B6B5E}
.us-modal__foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px 22px;border-top:1px solid #EEF3F1}

@media (max-width:1100px){.us-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.us-stats{grid-template-columns:1fr}.us-count{margin-left:0}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var modal = $('resetModal');

    function openResetModal(id, name) {
        $('resetUserId').value = id;
        $('resetUserName').textContent = name;
        $('quickPassword').value = '';
        $('quickPassword').type = 'password';
        $('generatedPass').textContent = '';
        modal.hidden = false;
        $('quickPassword').focus();
    }
    function closeResetModal() { modal.hidden = true; }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-reset-pwd');
        if (btn) { openResetModal(btn.getAttribute('data-uid'), btn.getAttribute('data-uname')); return; }
        var link = e.target.closest('.js-confirm[data-confirm]');
        if (link && !confirm(link.getAttribute('data-confirm'))) e.preventDefault();
    });
    $('togglePassBtn').addEventListener('click', function () {
        var el = $('quickPassword'); el.type = el.type === 'password' ? 'text' : 'password';
    });
    $('generatePassBtn').addEventListener('click', function () {
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$', pass = '';
        for (var i = 0; i < 10; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
        $('quickPassword').value = pass; $('quickPassword').type = 'text'; $('generatedPass').textContent = pass;
    });
    $('closeResetBtn').addEventListener('click', closeResetModal);
    $('usModalX').addEventListener('click', closeResetModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeResetModal(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeResetModal(); });

    // —— search, role filter, select-all, bulk bar ——
    var searchInput = $('userSearch'), tbody = $('usersTableBody'), selectAll = $('selectAllUsers');
    var bulkBar = $('bulkBar'), bulkLabel = $('bulkSelectedLabel'), countEl = $('userVisibleCount'), empty = $('usEmpty');
    var role = 'all';

    function rows() { return Array.prototype.slice.call(tbody.querySelectorAll('.js-user-row')); }
    function visibleRows() { return rows().filter(function (tr) { return !tr.classList.contains('users-row-hidden'); }); }
    function matchRole(tr) {
        if (role === 'all') return true;
        if (role === 'disabled') return tr.getAttribute('data-disabled') === '1';
        return tr.getAttribute('data-role') === role;
    }
    function apply() {
        var q = (searchInput.value || '').trim().toLowerCase();
        rows().forEach(function (tr) {
            var show = (!q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1) && matchRole(tr);
            tr.classList.toggle('users-row-hidden', !show);
            if (!show) { var cb = tr.querySelector('.js-bulk-check'); if (cb) cb.checked = false; }
        });
        var n = visibleRows().length;
        countEl.textContent = n + ' shown';
        if (empty) empty.hidden = n > 0 || rows().length === 0;
        syncSelectAll(); updateBulkBar();
    }
    function countChecked() { return tbody.querySelectorAll('.js-bulk-check:checked').length; }
    function updateBulkBar() { var c = countChecked(); bulkLabel.textContent = String(c); bulkBar.classList.toggle('is-visible', c > 0); }
    function syncSelectAll() {
        var checks = visibleRows().map(function (tr) { return tr.querySelector('.js-bulk-check'); }).filter(Boolean);
        var allOn = checks.length > 0 && checks.every(function (x) { return x.checked; });
        selectAll.checked = allOn;
        selectAll.indeterminate = !allOn && checks.some(function (x) { return x.checked; });
    }
    searchInput.addEventListener('input', apply);
    $('usFilter').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
        role = b.getAttribute('data-role'); apply();
    });
    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-bulk-check')) { updateBulkBar(); syncSelectAll(); }
    });
    selectAll.addEventListener('change', function () {
        visibleRows().forEach(function (tr) { var cb = tr.querySelector('.js-bulk-check'); if (cb) cb.checked = selectAll.checked; });
        updateBulkBar(); syncSelectAll();
    });
    var bulkBtn = document.querySelector('.js-bulk-delete-btn');
    if (bulkBtn) bulkBtn.addEventListener('click', function (e) {
        var c = countChecked();
        if (c === 0) { e.preventDefault(); return; }
        if (!confirm('Permanently delete ' + c + ' user(s)? Super admin rows are never removed.')) e.preventDefault();
    });
    apply();
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
