<?php
// Must be authenticated to use this endpoint
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

if (isset($_SESSION['impersonating']) && $_SESSION['impersonating']) {
    // Load dependencies for audit logging only
    include_once __DIR__ . '/../db.php';
    include_once __DIR__ . '/../includes/security.php';

    $targetCompany    = intval($_SESSION['company_id'] ?? 0);
    $originalAdminId  = intval($_SESSION['original_admin_id'] ?? 0);

    // AUDIT LOG: Record impersonation end
    waamini_audit_log($conn, 'IMPERSONATE_STOP', $targetCompany, [
        'admin_id'             => $originalAdminId,
        'returned_from_company' => $targetCompany,
    ]);

    // Restore original admin session
    $_SESSION['user_id']   = $_SESSION['original_admin_id'];
    $_SESSION['user_name'] = $_SESSION['original_admin_name'];
    $_SESSION['role']      = $_SESSION['original_admin_role'];
    unset(
        $_SESSION['impersonating'],
        $_SESSION['original_admin_id'],
        $_SESSION['original_admin_role'],
        $_SESSION['original_admin_name'],
        $_SESSION['company_id'],
        $_SESSION['email'],
        $_SESSION['name']
    );
    session_regenerate_id(true);
}

header("Location: /admin/companies.php");
exit();
