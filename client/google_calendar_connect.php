<?php
/**
 * Google Calendar Connect — Step 1 (one-click: the "Connect with Google" button points here)
 * Redirects to Google consent for calendar.events scope (offline access).
 * Reuses the SAME registered redirect URI (auth/google-callback.php);
 * the callback branches on the 'gcal_' state prefix.
 */
include '../includes/client_auth_check.php';

require_once __DIR__ . '/../includes/google_meet_helper.php';
if (!gmeet_meet_enabled($conn, (int)$companyId)) {
    header('Location: google_calendar.php?gcal=error&why=disabled');
    exit;
}
$cfg = gmeet_oauth_config($conn, (int)$companyId);
$clientId = $cfg['client_id'];
$redirectUri = $cfg['redirect_uri'];

if (!$cfg['ready']) {
    // Not set up yet: send the person to the page that explains it, inside the normal panel layout
    header('Location: google_calendar.php?gcal=error&why=not_configured');
    exit;
}

// State: 'gcal_' prefix tells the shared callback this is a CALENDAR connect, not a login
$state = 'gcal_' . bin2hex(random_bytes(20));
$_SESSION['gcal_state'] = $state;
$_SESSION['gcal_ts'] = time();
$_SESSION['gcal_company_id'] = (int)$companyId;
// Where to land after Google (so "Login with Google" on the Demo Bookings page returns there)
$ret = (string)($_GET['return'] ?? '');
$_SESSION['gcal_return'] = in_array($ret, ['demo_bookings', 'google_calendar'], true) ? $ret : 'google_calendar';

$params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/userinfo.email',
    'access_type' => 'offline',   // required for refresh_token
    'prompt' => 'select_account consent', // let the client pick their own Google ID or Google Business (Workspace) account + force refresh_token
    'state' => $state,
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
?>
