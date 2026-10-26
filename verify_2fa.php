<?php
/**
 * Second step after login when 2FA is enabled.
 * The code is entered in the popup on login.php; this endpoint checks it.
 *   - fetch() with "Accept: application/json"  -> JSON {ok, redirect | error, locked, remaining}
 *   - plain form POST (no JS)                  -> redirect to the dashboard, or back to the popup with the error
 *   - GET (old links / Google sign-in)         -> back to the popup on login.php
 * Must load bootstrap first so session cookie params match login.php.
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php';

const TWOFA_PENDING_TTL = 600; // the code popup stays valid for 10 minutes after the password step

$isAjax = strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false;
$rateKey2fa = 'verify_2fa:' . get_client_ip();
$rateKeyLogin = 'login:' . get_client_ip();

function twofa_clear_pending(): void
{
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_email'], $_SESSION['login_remember_me']);
}

/** Error reply: JSON for the popup, or flash + back to the popup for plain form posts. */
function twofa_fail(string $msg, bool $isAjax, array $extra = []): void
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg] + $extra);
        exit();
    }
    if (!empty($extra['restart'])) {
        header('Location: login.php?error2fa=expired');
        exit();
    }
    $_SESSION['twofa_flash'] = $msg;
    header('Location: login.php?2fa=1');
    exit();
}

// No pending password step -> start again
if (!isset($_SESSION['pending_2fa_user_id'])) {
    twofa_fail('Your sign-in step expired. Please log in again.', $isAjax, ['restart' => true]);
}
// Older entry points (Google sign-in) may not stamp the time yet
if (empty($_SESSION['pending_2fa_at'])) {
    $_SESSION['pending_2fa_at'] = time();
}
if (time() - (int)$_SESSION['pending_2fa_at'] > TWOFA_PENDING_TTL) {
    twofa_clear_pending();
    twofa_fail('Your sign-in step expired. Please log in again.', $isAjax, ['restart' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?2fa=1');
    exit();
}

csrf_verify();

if (isset($_POST['cancel'])) {
    twofa_clear_pending();
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'restart' => true]); exit(); }
    header('Location: login.php');
    exit();
}

$rateCheck = rate_limit($rateKey2fa, 5, 300);
if (!$rateCheck['allowed']) {
    $mins = max(1, (int)ceil(((int)($rateCheck['retry_after'] ?? 0)) / 60));
    twofa_fail('Too many attempts. Please wait about ' . $mins . ' minute(s) and try again.', $isAjax, ['locked' => true]);
}

$user_id = (int)$_SESSION['pending_2fa_user_id'];
$stmt = $conn->prepare('SELECT id, name, email, company_id, role, status, two_factor_enabled, two_factor_secret FROM users WHERE id=?');
if (!$stmt) {
    error_log('[verify_2fa] prepare failed: ' . $conn->error);
    twofa_fail('System temporarily unavailable. Please try again.', $isAjax);
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || (int)($user['two_factor_enabled'] ?? 0) !== 1 || ($user['status'] ?? 'active') === 'disabled') {
    twofa_clear_pending();
    twofa_fail('Your sign-in step expired. Please log in again.', $isAjax, ['restart' => true]);
}

// Popup sends auth_code; the no-JS form sends the six boxes as d[]
$raw = isset($_POST['auth_code']) ? (string)$_POST['auth_code'] : implode('', array_map('strval', (array)($_POST['d'] ?? [])));
$code = preg_replace('/[^0-9]/', '', $raw);
$secret = (string)($user['two_factor_secret'] ?? '');
$ga = new GoogleAuthenticator();

if (strlen($code) !== 6 || $secret === '' || !$ga->verifyCode($secret, $code, 2)) {
    rate_limit_hit($rateKey2fa);
    $left = (int)(rate_limit($rateKey2fa, 5, 300)['remaining'] ?? 0);
    $msg = strlen($code) !== 6 ? 'Enter the 6-digit code from your authenticator app.' : 'That code is not correct. Check your authenticator app and try again.';
    if ($left <= 0) {
        twofa_fail('Too many attempts. Please wait a few minutes and try again.', $isAjax, ['locked' => true]);
    }
    twofa_fail($msg, $isAjax, ['remaining' => $left]);
}

// ── Code is valid: finish the login ──
rate_limit_clear($rateKey2fa);
rate_limit_clear($rateKeyLogin);
$rememberAfter2fa = !empty($_SESSION['login_remember_me']);
twofa_clear_pending();

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_name'] = (string)($user['name'] ?? '');
$_SESSION['company_id'] = (int)($user['company_id'] ?? 0);
$_SESSION['role'] = (string)($user['role'] ?? 'client');

waamini_refresh_session_cookie(
    $rememberAfter2fa ? waamini_remember_me_lifetime_seconds() : waamini_default_session_lifetime_seconds()
);

// Generate persistent remember me token
if ($rememberAfter2fa) {
    $token = bin2hex(random_bytes(32));
    $selector = bin2hex(random_bytes(16));
    $hashedToken = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + waamini_remember_me_lifetime_seconds());

    $stmtToken = $conn->prepare("INSERT INTO user_tokens (user_id, selector, token, expires_at) VALUES (?, ?, ?, ?)");
    if ($stmtToken) {
        $stmtToken->bind_param("isss", $user['id'], $selector, $hashedToken, $expires);
        $stmtToken->execute();
    }

    setcookie('remember_me', $selector . ':' . $token, time() + waamini_remember_me_lifetime_seconds(), '/', '', Security::isHttps(), true);
    setcookie('remember_user', (string)$user['email'], time() + 2592000, '/', '', Security::isHttps(), true);
}

$redirect = $user['role'] === 'super_admin' ? 'admin/dashboard.php' : 'dashboard.php';
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
    exit();
}
header('Location: ' . $redirect);
exit();
