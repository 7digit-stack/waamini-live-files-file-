<?php
/**
 * Google Calendar & Meet — one-click setup page.
 * Any client signs in with their own Google account; Waamini then creates a Google Meet for every
 * demo booked on WhatsApp. The sign-in itself is client/google_calendar_connect.php + auth/google-callback.php.
 */
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('google_calendar')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
require_once __DIR__ . '/../includes/google_meet_helper.php';

$pageTitle = 'Google Calendar & Meet';
$currentPage = 'google_calendar';
$companyId = (int)$companyId;

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

$flash = null;      // [type, text]
$testLink = '';

// ── Is Google sign-in configured on this server? ──
$gcfg = gmeet_oauth_config($conn, $companyId);
$ownApp = gmeet_client_app($conn, $companyId);
$meetAllowed = $ownApp['meet_enabled'];
$envId = $gcfg['client_id'];
$envSecret = $gcfg['client_secret'];
$envRedirect = $gcfg['redirect_uri'];
$serverReady = $gcfg['ready'];
$redirectHost = strtolower((string)parse_url((string)$envRedirect, PHP_URL_HOST));
$thisHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$hostMismatch = $serverReady && $redirectHost !== '' && $thisHost !== '' && $redirectHost !== $thisHost;

// ── Messages coming back from the Google sign-in ──
$whyText = [
    'state_mismatch'     => 'The sign-in took too long or was opened in another tab. Please click Connect again.',
    'no_company'         => 'Your session expired. Please sign in to Waamini again and retry.',
    'no_code'            => 'Google did not return a sign-in code. Please try again.',
    'token_exchange'     => 'Google rejected the sign-in. Please try again. If it keeps failing, ask your administrator to check the Google settings.',
    'no_refresh_token'   => 'Google did not give Waamini long-term access. Open myaccount.google.com/permissions, remove "Waamini", then click Connect again.',
    'disabled'           => 'Google Meet has been switched off for your account by the administrator. Please contact them.',
    'not_configured'     => 'Google login is not switched on for this Waamini yet. The administrator can switch it on in Admin > Google Login.',
    'missing_permission' => 'The calendar permission was not ticked on the Google screen. Click Connect again and keep every box ticked.',
];
if (($_GET['gcal'] ?? '') === 'connected') {
    $flash = ['ok', 'Google Calendar connected. Every demo booked on WhatsApp now gets a Google Meet link.'];
} elseif (($_GET['gcal'] ?? '') === 'error') {
    $why = (string)($_GET['why'] ?? '');
    if (strpos($why, 'denied_') === 0) {
        $flash = ['err', 'You cancelled the Google sign-in, so nothing was connected. Click Connect when you are ready.'];
    } else {
        $flash = ['err', $whyText[$why] ?? 'Google Calendar could not be connected. Please try again.'];
    }
}

// ── Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['save_own_app'])) {
        $oid = trim((string)($_POST['own_client_id'] ?? ''));
        $osec = trim((string)($_POST['own_client_secret'] ?? ''));
        if ($oid === '' || !preg_match('/^[A-Za-z0-9\-_.]+\.apps\.googleusercontent\.com$/', $oid)) {
            $flash = ['err', 'The Client ID should end with ".apps.googleusercontent.com". Copy it again from Google Cloud.'];
        } elseif ($osec === '' && $ownApp['client_secret'] === '') {
            $flash = ['err', 'Please paste the Client secret from Google Cloud.'];
        } elseif ($osec !== '' && strlen($osec) < 10) {
            $flash = ['err', 'That Client secret looks too short. Copy it again from Google Cloud.'];
        } elseif (gmeet_save_client_app($conn, $companyId, $oid, $osec, gmeet_default_redirect())) {
            gmeet_disconnect($conn, $companyId); // a login made with the old app is no longer valid
            header('Location: google_calendar.php?gcal=ownsaved');
            exit;
        } else {
            $flash = ['err', 'Could not save your Google app. Please try again.'];
        }
    }
    if (isset($_POST['clear_own_app'])) {
        gmeet_clear_client_app($conn, $companyId);
        gmeet_disconnect($conn, $companyId);
        header('Location: google_calendar.php?gcal=ownremoved');
        exit;
    }
    if (isset($_POST['disconnect'])) {
        gmeet_disconnect($conn, $companyId);
        header('Location: google_calendar.php?gcal=disconnected');
        exit;
    }
    if (isset($_POST['test_meet'])) {
        $res = gmeet_create_demo_event($conn, $companyId, 'Waamini test', '0000000000', 'tomorrow 11:00 AM', 'Connection test');
        if (!empty($res['meet_link'])) {
            $testLink = (string)$res['meet_link'];
            if (!empty($res['event_id'])) { gmeet_delete_event($conn, $companyId, (string)$res['event_id']); } // keep the calendar clean
            $flash = ['ok', 'It works. Google created a Meet link for a test meeting, then removed the test from your calendar.'];
        } else {
            $err = (string)($res['error'] ?? '');
            $flash = ['err', stripos($err, 'not connected') !== false
                ? 'Google Calendar is not connected yet. Click Connect with Google first.'
                : 'Google would not create a Meet link' . ($err !== '' ? ' (' . $err . ')' : '') . '. Click Reconnect and keep every permission box ticked.'];
        }
    }
}
if (($_GET['gcal'] ?? '') === 'ownsaved') {
    $flash = ['ok', 'Your own Google app is saved. Now click Login with Google to connect your account.'];
}
if (($_GET['gcal'] ?? '') === 'ownremoved') {
    $flash = ['ok', 'Your own Google app was removed. Waamini will use its standard Google login again. Click Login with Google to reconnect.'];
}
if (($_GET['gcal'] ?? '') === 'disconnected') {
    $flash = ['ok', 'Google Calendar disconnected. New demos will use the default demo link until you connect again.'];
}

// ── Current state: not connected / working / needs reconnect ──
$email = gmeet_connected($conn, $companyId);
$state = 'off';
if ($email !== null) {
    $state = gmeet_access_token($conn, $companyId) ? 'on' : 'broken';
}

// Recent Meet bookings (proof it is working)
$recent = [];
$rs = $conn->prepare("SELECT customer_name, demo_datetime, meet_link, status FROM demo_bookings WHERE company_id=? AND meet_link IS NOT NULL AND meet_link <> '' AND meet_link NOT LIKE '%cal.com%' ORDER BY id DESC LIMIT 5");
if ($rs) {
    $rs->bind_param('i', $companyId);
    $rs->execute();
    $rr = $rs->get_result();
    while ($rr && ($r = $rr->fetch_assoc())) { $recent[] = $r; }
    $rs->close();
}

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
<style>
.gc-hero { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:24px; align-items:center; }
.gc-glogo { width:20px; height:20px; flex:none; }
.gc-gbtn { display:inline-flex; align-items:center; gap:12px; height:52px; padding:0 26px; border-radius:14px; background:#fff; color:#1f2d29; font-size:16px; font-weight:800; text-decoration:none; box-shadow:0 0 0 2px #128C7E inset, 0 14px 30px -18px rgba(18,140,126,.6); }
.gc-gbtn:hover { background:#E6FCF2; color:#0B6B5E; }
.gc-state { display:flex; align-items:center; gap:14px; background:var(--soft); border-radius:16px; padding:16px 18px; }
.gc-state .d { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; flex:none; background:#EEF1F0; color:#6b7a76; }
.gc-state.on .d { background:#E3F7EE; color:#0B6B5E; } .gc-state.broken .d { background:#FEF3DC; color:#8a5a00; }
.gc-state b { display:block; font-size:16px; color:var(--ink); } .gc-state span { font-size:13.5px; color:var(--mu); }
.gc-steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
.gc-step { background:var(--soft); border-radius:16px; padding:16px; }
.gc-step .n { width:30px; height:30px; border-radius:50%; background:var(--g); color:#fff; font-weight:800; display:grid; place-items:center; margin-bottom:10px; font-size:13px; }
.gc-step strong { display:block; font-size:14.5px; color:var(--ink); margin-bottom:4px; } .gc-step span { font-size:13px; color:var(--tx); line-height:1.5; }
.gc-meet { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background:#E3F7EE; border-radius:14px; padding:14px 16px; }
.gc-meet a { color:#0B6B5E; font-weight:800; word-break:break-all; }
.gc-row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:12px; align-items:center; background:var(--soft); border-radius:12px; padding:11px 14px; font-size:14px; }
@media (max-width:900px) { .gc-hero { grid-template-columns:minmax(0,1fr); } .gc-steps { grid-template-columns:minmax(0,1fr); } .gc-row { grid-template-columns:minmax(0,1fr) auto; } }
</style>

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>', 25); ?></div>
        <div>
            <h1>Google Calendar &amp; Meet</h1>
            <p>Sign in with Google once. After that, every demo booked on WhatsApp gets its own Google Meet link and appears on your calendar.</p>
        </div>
    </div>
    <span class="su-pill <?php echo $state === 'on' ? 'on' : ($state === 'broken' ? '' : 'bad'); ?>"><i></i> <?php echo $state === 'on' ? 'Connected' : ($state === 'broken' ? 'Needs reconnecting' : 'Not connected'); ?></span>
</div>

<?php if ($flash): ?><div class="su-alert <?php echo $flash[0]; ?>" style="margin-bottom:18px"><?php echo $lI($flash[0] === 'ok' ? '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>' : '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> <?php echo htmlspecialchars($flash[1]); ?></div><?php endif; ?>

<?php if (!$meetAllowed): ?>
<div class="su-alert err" style="margin-bottom:18px">Google Meet has been switched off for your account by the administrator, so Meet links are not created. Please contact them.</div>
<?php endif; ?>
<?php if (!$serverReady): ?>
<?php
    $suggestedRedirect = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-site.com') . app_url('auth/google-callback.php');
    $missingKeys = [];
    if ($envId === '') $missingKeys[] = 'GOOGLE_CLIENT_ID';
    if ($envSecret === '') $missingKeys[] = 'GOOGLE_CLIENT_SECRET';
    if ($envRedirect === '') $missingKeys[] = 'GOOGLE_REDIRECT_URI';
?>
<section class="su-card" style="margin-bottom:18px">
    <h2><span class="ch"><?php echo $lI('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'); ?></span> One-time setup by the administrator</h2>
    <p class="sub">Google sign-in is not switched on for this Waamini installation yet (missing: <strong><?php echo htmlspecialchars(implode(', ', $missingKeys)); ?></strong>). The administrator does this once, and then every client connects with one click.</p>
    <div class="gc-steps">
        <div class="gc-step"><div class="n">1</div><strong>Create the Google keys</strong><span>In Google Cloud Console open APIs &amp; Services, enable <b>Google Calendar API</b>, then create an <b>OAuth client ID</b> of type Web application.</span></div>
        <div class="gc-step"><div class="n">2</div><strong>Add this redirect address</strong><span>Under Authorized redirect URIs add exactly:<br><code style="word-break:break-all"><?php echo htmlspecialchars($suggestedRedirect); ?></code></span></div>
        <div class="gc-step"><div class="n">3</div><strong>Paste the keys in Admin</strong><span>Open <b>Admin &rarr; Google Login</b> and paste the Client ID and Client secret. No file editing or restart needed. Refresh this page.</span></div>
    </div>
    <span class="hint" style="font-size:12.5px;color:#586a63">Tip: while the Google app is in "Testing" mode, add each client's Google email under OAuth consent screen &rarr; Test users, or publish the app so any Google account can sign in.</span>
</section>
<?php elseif ($hostMismatch): ?>
<div class="su-alert err" style="margin-bottom:18px"><?php echo $lI('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> <span>Google is set up for <strong><?php echo htmlspecialchars($redirectHost); ?></strong> but you are on <strong><?php echo htmlspecialchars($thisHost); ?></strong>, so Google will show "redirect_uri_mismatch". The administrator must set GOOGLE_REDIRECT_URI to <code><?php echo htmlspecialchars((((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . app_url('auth/google-callback.php')); ?></code> and add the same address in Google Cloud under Authorized redirect URIs.</span></div>
<?php endif; ?>

<div class="su-grid">

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>'); ?></span> One-click setup</h2>
            <div class="gc-state <?php echo $state; ?>">
                <div class="d"><?php echo $lI($state === 'on' ? '<path d="M20 6 9 17l-5-5"/>' : ($state === 'broken' ? '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>' : '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>')); ?></div>
                <div>
                    <?php if ($state === 'on'): ?><b>Connected as <?php echo htmlspecialchars((string)$email); ?></b><span>Meet links are created on this Google account's calendar.</span>
                    <?php elseif ($state === 'broken'): ?><b>Google needs you to sign in again</b><span>The permission for <?php echo htmlspecialchars((string)$email); ?> expired or was removed. Click Reconnect.</span>
                    <?php else: ?><b>Not connected yet</b><span>Until you connect, demos use the default demo link instead of your own Meet.</span><?php endif; ?>
                </div>
            </div>

            <div class="su-actions">
                <?php if ($serverReady): ?>
                <a class="gc-gbtn" href="google_calendar_connect.php">
                    <svg class="gc-glogo" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
                    <?php echo $state === 'off' ? 'Connect with Google' : 'Reconnect with Google'; ?>
                </a>
                <?php endif; ?>
                <?php if ($email !== null): ?>
                <form method="POST" class="su-inline"><?php echo csrf_field(); ?><button type="submit" name="test_meet" class="su-btn soft"><?php echo $lI('<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>'); ?> Send a test meeting</button></form>
                <form method="POST" class="su-inline" onsubmit="return confirm('Disconnect Google Calendar? New demos will use the default demo link.')"><?php echo csrf_field(); ?><button type="submit" name="disconnect" class="su-btn danger"><?php echo $lI('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?> Disconnect</button></form>
                <?php endif; ?>
            </div>

            <?php if ($testLink !== ''): ?>
            <div class="gc-meet"><span>Test Meet link created by Google:</span><a href="<?php echo htmlspecialchars($testLink); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($testLink); ?></a></div>
            <?php endif; ?>

            <div class="gc-steps">
                <div class="gc-step"><div class="n">1</div><strong>Click Connect</strong><span>Choose the Google account whose calendar should hold your demos.</span></div>
                <div class="gc-step"><div class="n">2</div><strong>Allow calendar access</strong><span>Keep every box ticked. Waamini only adds and removes its own demo events.</span></div>
                <div class="gc-step"><div class="n">3</div><strong>You are done</strong><span>Use "Send a test meeting" to see it work. Real demos then get a Meet link automatically.</span></div>
            </div>
        </section>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'); ?></span> Google app for my account (optional)</h2>
            <p class="sub">By default you log in through Waamini's standard Google login. If you prefer, create your own Google app and use it for your account only.
                <?php if ($gcfg['source'] === 'client'): ?><strong>Currently using: your own app.</strong><?php else: ?><strong>Currently using: Waamini's standard login.</strong><?php endif; ?></p>
            <form method="POST" style="display:flex;flex-direction:column;gap:14px;" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="su-f"><label class="l" for="oc1">Client ID</label><input type="text" id="oc1" name="own_client_id" placeholder="123456-abc.apps.googleusercontent.com" value="<?php echo htmlspecialchars($ownApp['client_id']); ?>"></div>
                <div class="su-f"><label class="l" for="oc2">Client secret</label><input type="password" id="oc2" name="own_client_secret" autocomplete="new-password" placeholder="<?php echo $ownApp['client_secret'] !== '' ? 'Saved. Leave blank to keep it' : 'GOCSPX-...'; ?>"></div>
                <div class="su-f"><label class="l">Redirect address to add in Google Cloud</label><div class="gc-step" style="margin:0"><code style="word-break:break-all"><?php echo htmlspecialchars(gmeet_default_redirect()); ?></code></div><span class="hint">In Google Cloud &rarr; Credentials &rarr; your OAuth client &rarr; Authorized redirect URIs.</span></div>
                <div class="su-actions">
                    <button type="submit" name="save_own_app" class="su-btn soft">Save my Google app</button>
                    <?php if ($ownApp['client_id'] !== ''): ?><button type="submit" name="clear_own_app" class="su-btn danger" onclick="return confirm('Go back to the standard Waamini Google login? You will need to log in with Google again.')">Use standard login instead</button><?php endif; ?>
                </div>
            </form>
        </section>
    </div>

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>'); ?></span> Latest Meet links</h2>
            <?php if ($recent): ?>
            <div class="su-rows">
                <?php foreach ($recent as $b): $ts = strtotime((string)$b['demo_datetime']); ?>
                <div class="gc-row"><span><strong><?php echo htmlspecialchars((string)$b['customer_name']); ?></strong><br><small style="color:#586a63"><?php echo $ts ? date('d M Y, h:i A', $ts) : '—'; ?></small></span><span class="su-tag <?php echo $b['status'] === 'completed' ? 'green' : 'amber'; ?>"><?php echo htmlspecialchars(ucfirst((string)$b['status'])); ?></span><a class="su-btn xs" href="<?php echo htmlspecialchars((string)$b['meet_link']); ?>" target="_blank" rel="noopener noreferrer">Open</a></div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="su-empty"><div class="e-i"><?php echo $lI('<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>', 28); ?></div><h3>No Meet links yet</h3><p>Real Google Meet links will be listed here after your first demo is booked.</p></div>
            <?php endif; ?>
            <a href="demo_bookings.php" class="su-btn soft" style="align-self:flex-start">Open Demo Bookings</a>
        </section>

        <div class="su-note">
            <?php echo $lI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>', 22); ?>
            <div>
                <h4>Safe and private</h4>
                <p>You sign in on Google's own page, so Waamini never sees your Google password. You can disconnect here, or remove Waamini at myaccount.google.com/permissions, whenever you like.</p>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
