<?php
include_once __DIR__ . '/../config/bootstrap.php';
// Include security helper (CSRF, rate limiting, sanitization, security headers, all 10 layers)
include_once __DIR__ . '/security.php';

// Check for timeouts
if (!empty($_SESSION['timed_out'])) {
    session_destroy();
    header("Location: /login.php?msg=timeout");
    exit();
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// Must be super_admin
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    header("Location: /dashboard.php");
    exit();
}

// Admin sessions use a sliding browser-cookie lifetime. Without refreshing the
// cookie on authenticated activity, long-lived settings forms can redirect to
// login when submitted even though the administrator is still working.
if (function_exists('waamini_refresh_session_cookie') && function_exists('waamini_default_session_lifetime_seconds')) {
    waamini_refresh_session_cookie(waamini_default_session_lifetime_seconds());
}
?>
