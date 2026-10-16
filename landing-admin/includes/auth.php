<?php
/**
 * Landing Admin Auth Guard
 * Include at top of every landing-admin page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('la_session');
    session_start();
}

function la_is_logged_in(): bool {
    return !empty($_SESSION['la_admin_id']) && !empty($_SESSION['la_admin_email']);
}

function la_require_login(): void {
    if (!la_is_logged_in()) {
        header('Location: /landing-admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

function la_admin_name(): string  { return (string)($_SESSION['la_admin_name']  ?? 'Admin'); }
function la_admin_email(): string { return (string)($_SESSION['la_admin_email'] ?? ''); }
function la_admin_id(): int       { return (int)($_SESSION['la_admin_id'] ?? 0); }

/**
 * CSRF helpers (separate from the main app CSRF)
 */
function la_csrf_token(): string {
    if (empty($_SESSION['la_csrf'])) {
        $_SESSION['la_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['la_csrf'];
}
function la_csrf_field(): string {
    return '<input type="hidden" name="_la_csrf" value="' . htmlspecialchars(la_csrf_token(), ENT_QUOTES) . '">';
}
function la_csrf_verify(): void {
    $token   = (string)($_POST['_la_csrf']  ?? $_GET['_la_csrf']  ?? '');
    $session = (string)($_SESSION['la_csrf'] ?? '');
    if (!$session || !hash_equals($session, $token)) {
        http_response_code(403);
        die('<h1>403 — Invalid security token. Go back and try again.</h1>');
    }
}
