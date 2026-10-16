<?php
require_once __DIR__ . '/config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle return to admin from impersonation
if (isset($_GET['return_to_admin']) && isset($_SESSION['admin_original_id'])) {
    $_SESSION['user_id'] = $_SESSION['admin_original_id'];
    $_SESSION['user_name'] = $_SESSION['admin_original_name'];
    $_SESSION['role'] = $_SESSION['admin_original_role'];
    unset($_SESSION['admin_original_id'], $_SESSION['admin_original_name'], $_SESSION['admin_original_role'], $_SESSION['impersonating']);
    header("Location: admin/users.php");
    exit();
}
?>

<?php if (isset($_SESSION['impersonating']) && $_SESSION['impersonating']): ?>
<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:linear-gradient(135deg,#6c5ce7,#a855f7);padding:10px 24px;display:flex;align-items:center;justify-content:space-between;font-family:'Inter',sans-serif;box-shadow:0 4px 20px rgba(108,92,231,0.4);">
    <div style="color:#fff;font-size:13px;font-weight:500;">
        👤 You are viewing as <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong> (Impersonation Mode)
    </div>
    <a href="/dashboard.php?return_to_admin=1" style="background:#fff;color:#6c5ce7;padding:6px 16px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700;">
        ← Return to Admin
    </a>
</div>
<div style="height:44px;"></div>
<?php
endif; ?>

<?php if ($_SESSION['role'] === 'super_admin' && !isset($_SESSION['impersonating'])) { ?>
    <p>🔥 Super Admin Access — <a href="admin/dashboard.php">Go to Admin Panel</a></p>
<?php
}
else {
    // Redirect clients to the client panel
    header("Location: client/dashboard.php");
    exit();
}?>