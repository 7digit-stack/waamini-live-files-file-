<?php
include '../includes/auth_check.php';
include '../db.php';

// GET: show impersonation UI
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $pageTitle = 'Impersonate';
    $currentPage = 'companies';

    $companies = $conn->query("SELECT id, name FROM companies WHERE status='active' ORDER BY name ASC");

    include '../includes/admin_header.php';
    ?>

    <div class="page-header">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Impersonate a Company</h2>
        <a href="companies.php" class="btn btn-sm btn-edit">← Back</a>
    </div>

    <div class="form-card" style="max-width:640px;">
        <p style="color:var(--text-muted);font-size:13px;line-height:1.6;">
            Select a company to impersonate. This will switch your session into that tenant’s client dashboard.
        </p>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Company</label>
                <select name="company_id" class="form-control" required>
                    <option value="">— Select company —</option>
                    <?php if ($companies): while ($c = $companies->fetch_assoc()): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Start Impersonation</button>
        </form>
    </div>

    <?php
    include '../includes/admin_footer.php';
    exit();
}

// POST or GET with token: perform impersonation
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['company_id']) && isset($_GET['_token']))) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $cid = intval($_POST['company_id'] ?? 0);
    } else {
        csrf_verify($_GET['_token']);
        $cid = intval($_GET['company_id']);
    }

    if (!$cid) {
        header("Location: impersonate.php");
        exit();
    }

// Verify company exists
$stmt = $conn->prepare("SELECT c.id, c.name, u.id as user_id, u.email, u.name as uname FROM companies c JOIN users u ON u.company_id = c.id AND u.role = 'client' WHERE c.id = ? LIMIT 1");
$stmt->bind_param("i", $cid);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    header("Location: companies.php?msg=not_found");
    exit();
}

// AUDIT LOG: Record impersonation before session swap
if (function_exists('waamini_audit_log')) {
    waamini_audit_log($conn, 'IMPERSONATE_START', $cid, [
        'admin_id'       => $_SESSION['user_id'],
        'admin_name'     => $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Admin',
        'target_company' => $company['name'],
        'target_user_id' => $company['user_id'],
    ]);
}

// Save original admin session, start impersonation
$_SESSION['impersonating']         = true;
$_SESSION['original_admin_id']     = $_SESSION['user_id'];
$_SESSION['original_admin_role']   = $_SESSION['role'];
$_SESSION['original_admin_name']   = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Admin';

$_SESSION['user_id']    = $company['user_id'];
$_SESSION['user_name']  = $company['uname'];
$_SESSION['role']       = 'client';
$_SESSION['company_id'] = $company['id'];
$_SESSION['name']       = $company['uname'];
$_SESSION['email']      = $company['email'];

session_regenerate_id(true);

header("Location: /client/dashboard.php");
exit();
}
