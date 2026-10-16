<?php
/**
 * Google OAuth — Step 1: Redirect user to Google's consent screen.
 * URL: /waamini/auth/google.php
 */
require_once __DIR__ . '/../config/bootstrap.php';

$clientId    = (string)\Waamini\Config\Env::get('GOOGLE_CLIENT_ID', '');
$redirectUri = (string)\Waamini\Config\Env::get('GOOGLE_REDIRECT_URI', '');

if ($clientId === '' || $redirectUri === '') {
    error_log('[WAAMINI Google OAuth] GOOGLE_CLIENT_ID or GOOGLE_REDIRECT_URI not set in .env');
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
