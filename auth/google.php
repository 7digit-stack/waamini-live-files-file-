<?php
/**
 * Google OAuth — Step 1: Redirect user to Google's consent screen.
 * URL: /waamini/auth/google.php
 */
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Keys for "Continue with Google" on login/register.
 * .env first; otherwise the platform keys saved in Admin > Google setup (never a client's own app).
 */
if (!function_exists('waamini_google_login_keys')) {
function waamini_google_login_keys(): array
{
    $id       = (string)\Waamini\Config\Env::get('GOOGLE_CLIENT_ID', '');
    $secret   = (string)\Waamini\Config\Env::get('GOOGLE_CLIENT_SECRET', '');
    $redirect = (string)\Waamini\Config\Env::get('GOOGLE_REDIRECT_URI', '');
    if ($id === '' || $secret === '') {
        if (!isset($GLOBALS['conn'])) { include_once __DIR__ . '/../db.php'; if (isset($conn)) { $GLOBALS['conn'] = $conn; } }
        if (!function_exists('decrypt_secret') && file_exists(__DIR__ . '/../services/EncryptionService.php')) {
            require_once __DIR__ . '/../services/EncryptionService.php';
        }
        require_once __DIR__ . '/../includes/google_meet_helper.php';
        $cfg = gmeet_oauth_config($GLOBALS['conn'] ?? null, 0);
        if (!empty($cfg['ready'])) {
            $id = (string)$cfg['client_id'];
            $secret = (string)$cfg['client_secret'];
            $redirect = (string)$cfg['redirect_uri'];
        }
    }
    if ($id !== '' && $redirect === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $redirect = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/google-callback.php';
    }
    return ['client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => $redirect];
}
}
$gKeys       = waamini_google_login_keys();
$clientId    = $gKeys['client_id'];
$redirectUri = $gKeys['redirect_uri'];

if ($clientId === '' || $redirectUri === '') {
    error_log('[WAAMINI Google OAuth] GOOGLE_CLIENT_ID or GOOGLE_REDIRECT_URI not set in .env or Admin > Google setup');
    $back = str_contains((string)($_SERVER['HTTP_REFERER'] ?? ''), 'register.php') ? 'register.php' : 'login.php';
    header('Location: ../' . $back . '?error=oauth_config');
    exit;
}

// Already logged in — skip OAuth
if (!empty($_SESSION['user_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

// Remember which page initiated OAuth so the callback can redirect errors there
$_SESSION['oauth_origin'] = isset($_SERVER['HTTP_REFERER']) && str_contains((string)$_SERVER['HTTP_REFERER'], 'register.php')
    ? 'register'
    : 'login';

// Generate a cryptographically random state token (CSRF protection)
$state = bin2hex(random_bytes(24));
$_SESSION['oauth_google_state'] = $state;
$_SESSION['oauth_google_ts']    = time(); // for expiry check in callback

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'state'         => $state,
    'prompt'        => 'select_account',   // always show account chooser
]);

header('Location: ' . $authUrl);
exit;
