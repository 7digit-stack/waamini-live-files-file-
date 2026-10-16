<?php
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/google_meet_helper.php';

$pageTitle = 'Google Login for Meet';
$currentPage = 'google_setup';

$ok = ''; $err = '';

$selCompany = (int)($_POST['for_company'] ?? $_GET['for'] ?? 0); // which client the keys form is showing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['clear_client_keys']) || (isset($_POST['save_google']) && (int)($_POST['for_company'] ?? 0) > 0))) {
    csrf_verify();
    $kcid = (int)($_POST['for_company'] ?? $_POST['company_id'] ?? 0);
    if ($kcid <= 0) {
        $err = 'Choose a client first.';
    } elseif (isset($_POST['clear_client_keys'])) {
        gmeet_clear_client_app($conn, $kcid);
        gmeet_disconnect($conn, $kcid);
        $ok = 'That client now uses the standard Waamini Google login. They log in with Google again from Demo Bookings.';
    } else {
        $kid = trim((string)($_POST['client_id'] ?? ''));
        $ksec = trim((string)($_POST['client_secret'] ?? ''));
        $kold = gmeet_client_app($conn, $kcid);
        if ($kid === '' || !preg_match('/^[A-Za-z0-9\-_.]+\.apps\.googleusercontent\.com$/', $kid)) {
            $err = 'The Client ID should end with ".apps.googleusercontent.com". Copy it again from that client\'s Google Cloud.';
        } elseif ($ksec === '' && $kold['client_secret'] === '') {
            $err = 'Please paste the Client secret for that client.';
        } elseif ($ksec !== '' && strlen($ksec) < 10) {
            $err = 'That Client secret looks too short. Copy it again from Google Cloud.';
        } elseif (gmeet_save_client_app($conn, $kcid, $kid, $ksec, gmeet_default_redirect())) {
            gmeet_disconnect($conn, $kcid); // a login made with the old app is no longer valid
            $ok = 'Saved this client\'s own Google keys. Ask them to click Login with Google in Demo Bookings.';
        } else {
            $err = 'Could not save the keys. Please try again.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['toggle_meet']) || isset($_POST['disconnect_client']))) {
    csrf_verify();
    $cid2 = (int)($_POST['company_id'] ?? 0);
    if ($cid2 > 0) {
        if (isset($_POST['toggle_meet'])) {
            $on2 = ($_POST['enable'] ?? '1') === '1';
            $ok = gmeet_set_meet_enabled($conn, $cid2, $on2) ? ('Google Meet ' . ($on2 ? 'switched on' : 'switched off') . ' for that client.') : '';
            if ($ok === '') $err = 'Could not change that setting. Please try again.';
        } else {
            gmeet_disconnect($conn, $cid2);
            $ok = 'That client was disconnected from Google. They can log in again from Demo Bookings.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_google']) && (int)($_POST['for_company'] ?? 0) === 0) {
    csrf_verify();
    $id = trim((string)($_POST['client_id'] ?? ''));
    $secret = trim((string)($_POST['client_secret'] ?? ''));
    $redirect = trim((string)($_POST['redirect_uri'] ?? ''));
    $existing = gmeet_oauth_config($conn);
    if ($id === '' || !preg_match('/^[A-Za-z0-9\-_.]+\.apps\.googleusercontent\.com$/', $id)) {
        $err = 'The Client ID should end with ".apps.googleusercontent.com". Copy it again from Google Cloud > Credentials.';
    } elseif ($secret === '' && $existing['client_secret'] === '') {
        $err = 'Please paste the Client secret from Google Cloud.';
    } elseif ($secret !== '' && strlen($secret) < 10) {
        $err = 'That Client secret looks too short. Copy it again from Google Cloud.';
    } elseif (!filter_var($redirect, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $redirect) || substr($redirect, -strlen('/auth/google-callback.php')) !== '/auth/google-callback.php') {
        $err = 'The redirect address must be a full web address ending with /auth/google-callback.php';
    } elseif (gmeet_save_oauth_config($conn, $id, $secret, $redirect)) {
        $ok = 'Saved. Clients can now log in with Google from Demo Bookings.';
    } else {
        $err = 'Could not save the settings. Please try again.';
    }
}

$cfg = gmeet_oauth_config($conn);
$defaultRedirect = gmeet_default_redirect();
$shownRedirect = $cfg['redirect_uri'] !== '' ? $cfg['redirect_uri'] : $defaultRedirect;
$redirectMismatch = $cfg['ready'] && rtrim(strtolower($shownRedirect), '/') !== rtrim(strtolower($defaultRedirect), '/');

// Settings for each client: Google account, which Google app, and the Meet switch
$clients = [];
try {
    gmeet_ensure_table($conn);
    gmeet_ensure_apps_table($conn);
    $r = $conn->query("SELECT c.id, c.name, t.google_email, t.updated_at,
                              COALESCE(a.meet_enabled, 1) AS meet_enabled,
                              (a.client_id IS NOT NULL AND a.client_id <> '' AND a.client_secret IS NOT NULL AND a.client_secret <> '') AS own_app,
                              a.client_id AS own_client_id,
                              (t.company_id IS NOT NULL) AS has_login
                       FROM companies c
                       LEFT JOIN google_calendar_tokens t ON t.company_id = c.id
                       LEFT JOIN google_client_apps a ON a.company_id = c.id
                       ORDER BY has_login DESC, own_app DESC, c.name ASC LIMIT 200");
    while ($r && ($x = $r->fetch_assoc())) { $clients[] = $x; }
} catch (Throwable $e) { error_log('[WAAMINI admin google_setup] ' . $e->getMessage()); }
$connectedCount = count(array_filter($clients, static fn($c) => (int)$c['has_login'] === 1));

$I = static function (string $p, int $s = 18): string {
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0">' . $p . '</svg>';
};

include '../includes/admin_header.php';
?>
<style>
.gs { --g:#128C7E; --ink:#0F2622; --mu:#586a63; --soft:#F3F7FB; }
.gs-head { background:#fff; border-radius:16px; padding:20px 24px; margin-bottom:16px; box-shadow:0 10px 28px -22px rgba(15,23,42,.45); display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; }
.gs-head h1 { margin:0 0 4px; font-size:22px; font-weight:800; color:var(--ink); } .gs-head p { margin:0; font-size:13.5px; color:var(--mu); max-width:680px; }
.gs-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:999px; font-weight:800; font-size:13px; }
.gs-pill.on { background:#E3F7EE; color:#0B6B5E; } .gs-pill.off { background:#FDE7E7; color:#b3261e; }
.gs-grid { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,1fr); gap:16px; align-items:start; }
.gs-card { background:#fff; border-radius:16px; padding:22px; box-shadow:0 10px 28px -22px rgba(15,23,42,.45); margin-bottom:16px; }
.gs-card h2 { margin:0 0 14px; font-size:16px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:8px; }
.gs-step { display:grid; grid-template-columns:30px minmax(0,1fr); gap:12px; background:var(--soft); border-radius:14px; padding:14px; margin-bottom:10px; font-size:14px; color:#34453f; line-height:1.55; }
.gs-step .n { width:28px; height:28px; border-radius:50%; background:var(--g); color:#fff; font-weight:800; font-size:13px; display:grid; place-items:center; }
.gs-step b { color:var(--ink); } .gs-step a { color:#0B6B5E; font-weight:700; }

.gs-side { position:sticky; top:16px; }
.gg { background:#fff; border-radius:16px; padding:22px; box-shadow:0 10px 28px -22px rgba(15,23,42,.45); margin-bottom:16px; }
.gg-top { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:6px; }
.gg-h { margin:0 0 4px; font-size:18px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:8px; }
.gg-sub { margin:0; font-size:13.5px; color:var(--mu); line-height:1.55; max-width:560px; }
.gg-prog { min-width:150px; font-size:13px; color:var(--mu); font-weight:700; text-align:right; }
.gg-prog b { color:var(--g); font-size:20px; }
.gg-bar { height:8px; border-radius:99px; background:#E3F7EE; margin-top:6px; overflow:hidden; }
.gg-bar i { display:block; height:100%; width:0; background:var(--g); border-radius:99px; transition:width .3s; }
.gg-part { margin:20px 0 10px; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--g); }
.gg-step { background:var(--soft); border-radius:14px; margin-bottom:10px; overflow:hidden; }
.gg-step > summary, .gg-fix > summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:12px; padding:14px 16px; }
.gg-step > summary::-webkit-details-marker, .gg-fix > summary::-webkit-details-marker { display:none; }
.gg-step[open] > summary { background:#EAF6F1; }
.gg-num { width:30px; height:30px; border-radius:50%; background:var(--g); color:#fff; font-weight:800; font-size:13px; display:grid; place-items:center; flex:none; transition:background .2s; }
.gg-step.done .gg-num { background:#25D366; }
.gg-ttl { font-weight:800; font-size:15px; color:var(--ink); flex:1; min-width:0; }
.gg-step.done .gg-ttl { color:var(--mu); text-decoration:line-through; text-decoration-color:rgba(88,106,99,.5); }
.gg-min { font-size:12px; font-weight:700; color:var(--mu); background:#fff; border-radius:999px; padding:3px 10px; white-space:nowrap; }
.gg-chk { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:800; color:#34453f; cursor:pointer; }
.gg-chk input { width:18px; height:18px; accent-color:#128C7E; cursor:pointer; }
.gg-chev { width:9px; height:9px; border-right:2.5px solid #7b8a85; border-bottom:2.5px solid #7b8a85; transform:rotate(45deg); transition:transform .2s; flex:none; margin-left:4px; }
details[open] > summary > .gg-chev { transform:rotate(-135deg); }
.gg-body { padding:4px 18px 18px 58px; }
.gg-open { display:inline-flex; align-items:center; gap:7px; height:36px; padding:0 14px; border-radius:10px; background:var(--g); color:#fff; font-weight:800; font-size:13px; text-decoration:none; margin-bottom:12px; }
.gg-open:hover { background:#0B6B5E; color:#fff; }
.gg-do { margin:0 0 12px; padding-left:20px; font-size:14px; color:#34453f; line-height:1.7; }
.gg-do li { margin-bottom:4px; }
.gg-do b { color:var(--ink); }
.gg-copy { display:flex; align-items:center; gap:10px; background:#fff; border-radius:10px; padding:8px 10px 8px 12px; margin-bottom:8px; flex-wrap:wrap; }
.gg-cl { font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--mu); min-width:150px; }
.gg-copy code { flex:1; min-width:0; font-family:ui-monospace,Consolas,monospace; font-size:13px; color:#0B3D36; word-break:break-all; background:transparent; }
.gg-copy button { border:0; background:#E6FCF2; color:#0B6B5E; font-weight:800; font-size:12px; border-radius:8px; padding:6px 14px; cursor:pointer; font-family:inherit; }
.gg-copy button:hover { background:#CDF5E3; }
.gg-see, .gg-tip { display:flex; gap:10px; align-items:flex-start; border-radius:12px; padding:11px 14px; margin-top:10px; font-size:13.5px; line-height:1.55; }
.gg-see { background:#E3F7EE; color:#0B3D36; } .gg-tip { background:#FEF3DC; color:#6f4a00; }
.gg-see > div b, .gg-tip > div b { display:block; font-size:11.5px; text-transform:uppercase; letter-spacing:.06em; margin-bottom:2px; opacity:.85; }
.gg-see svg, .gg-tip svg { margin-top:2px; }
.gg-next { margin-top:14px; border:0; background:#fff; color:#0B6B5E; font-weight:800; font-size:13.5px; border-radius:10px; height:38px; padding:0 18px; cursor:pointer; font-family:inherit; box-shadow:0 0 0 1.5px #128C7E inset; }
.gg-next:hover { background:#E6FCF2; }
.gg-fixes { display:flex; flex-direction:column; gap:8px; }
.gg-fix { background:var(--soft); border-radius:12px; }
.gg-fix > summary { padding:12px 14px; font-weight:700; font-size:14px; color:var(--ink); }
.gg-fix > summary svg { color:#b7791f; }
.gg-fix > summary .gg-chev { margin-left:auto; }
.gg-fix p { margin:0; padding:0 16px 14px 44px; font-size:13.5px; color:#34453f; line-height:1.6; }
.gg-card .gg-ready { background:#E3F7EE; color:#0B3D36; border-radius:12px; padding:10px 14px; font-size:13.5px; margin-bottom:12px; }
.gg-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
.gg-list a { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:10px; background:var(--soft); color:#34453f; font-size:13.5px; font-weight:700; text-decoration:none; }
.gg-list a:hover { background:#EAF6F1; }
.gg-dot { width:24px; height:24px; border-radius:50%; background:#fff; color:var(--mu); font-size:12px; font-weight:800; display:grid; place-items:center; flex:none; }
.gg-list li.done a { color:var(--mu); }
.gg-list li.done .gg-dot { background:#25D366; color:#fff; }
@media (max-width:1000px) { .gs-side { position:static; } .gg-body { padding-left:18px; } .gg-min { display:none; } }
.gs-intro { margin:-4px 0 14px; font-size:14px; color:#34453f; line-height:1.6; }
.gs-tip { background:#E6FCF2; color:#0B3D36; border-radius:12px; padding:12px 14px; font-size:13.5px; line-height:1.55; margin-bottom:14px; }
.gs-sec { margin:18px 0 10px; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--g); }
.gs-do { margin:8px 0 0; padding-left:18px; font-size:13.5px; color:#34453f; line-height:1.7; }
.gs-do li { margin-bottom:4px; }
.gs-see { margin-top:10px; background:#fff; border-radius:10px; padding:9px 12px; font-size:13px; color:#0B6B5E; line-height:1.5; }
.gs-v { display:inline-block; background:#fff; border-radius:8px; padding:2px 10px; font-weight:800; color:#0B3D36; cursor:pointer; }
.gs-v:hover { background:#CDF5E3; }
.gs-fix { background:var(--soft); border-radius:12px; padding:11px 14px; margin-bottom:8px; font-size:13.5px; color:#34453f; line-height:1.5; }
.gs-fix b { display:block; color:var(--ink); margin-bottom:2px; }
.gs-guide small { color:var(--mu); font-size:12.5px; }
.gs-code { display:flex; align-items:center; gap:8px; background:#fff; border-radius:10px; padding:8px 12px; margin-top:6px; font-family:ui-monospace,Consolas,monospace; font-size:13px; color:#0B3D36; word-break:break-all; }
.gs-copy { margin-left:auto; border:0; background:#E6FCF2; color:#0B6B5E; font-weight:800; border-radius:8px; padding:5px 12px; cursor:pointer; font-size:12px; font-family:inherit; }
.gs-f { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
.gs-f label { font-size:13px; font-weight:800; color:#34453f; }
.gs-f input { height:44px; border:0; background:var(--soft); border-radius:10px; padding:0 14px; font-size:14px; color:var(--ink); font-family:inherit; }
.gs-f input:focus { outline:none; box-shadow:0 0 0 2px rgba(18,140,126,.4); background:#fff; }
.gs-f small { color:var(--mu); font-size:12.5px; }
.gs-btn { display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 22px; border:0; border-radius:10px; background:var(--g); color:#fff; font-weight:800; font-size:14px; cursor:pointer; text-decoration:none; font-family:inherit; }
.gs-btn.soft { background:#E6FCF2; color:#0B6B5E; }
.gs-alert { padding:12px 16px; border-radius:12px; font-size:14px; font-weight:600; margin-bottom:14px; }
.gs-alert.ok { background:#E3F7EE; color:#0B6B5E; } .gs-alert.err { background:#FDE7E7; color:#b3261e; } .gs-alert.warn { background:#FEF3DC; color:#6f4a00; }
.gs-row { display:flex; justify-content:space-between; gap:10px; align-items:center; background:var(--soft); border-radius:12px; padding:11px 14px; margin-bottom:8px; font-size:14px; }
.gs-row small { color:var(--mu); }
.gk-select { width:100%; height:44px; border:0; background:var(--soft); border-radius:10px; padding:0 14px; font-size:14px; font-weight:700; color:var(--ink); font-family:inherit; }
.gk-select:focus { outline:none; box-shadow:0 0 0 2px rgba(18,140,126,.4); background:#fff; }
.gk-mode { background:#E6FCF2; color:#0B3D36; border-radius:12px; padding:10px 14px; font-size:13.5px; line-height:1.5; margin-bottom:14px; }
.gk-mode.client { background:#E4EFFD; color:#1d4f9c; }
.gk-modal { display:none; position:fixed; inset:0; z-index:2000; background:rgba(15,23,42,.5); align-items:center; justify-content:center; padding:16px; }
.gk-modal.open { display:flex; }
.gk-box { background:#fff; border-radius:18px; padding:24px; width:520px; max-width:100%; max-height:92vh; overflow-y:auto; box-shadow:0 30px 70px -20px rgba(15,23,42,.5); }
.gk-box h3 { margin:0 0 6px; font-size:18px; font-weight:800; color:var(--ink); }
.gk-sub { margin:0 0 16px; font-size:13.5px; color:var(--mu); line-height:1.55; }
.gk-act { display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; }
.gs-tbl { width:100%; border-collapse:separate; border-spacing:0 6px; font-size:13.5px; }
.gs-tbl th { text-align:left; padding:4px 12px; font-size:11.5px; font-weight:800; color:var(--mu); text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
.gs-tbl td { padding:12px; background:var(--soft); vertical-align:middle; color:var(--ink); }
.gs-tbl td:first-child { border-radius:12px 0 0 12px; } .gs-tbl td:last-child { border-radius:0 12px 12px 0; }
.gs-tbl small { color:var(--mu); font-size:12px; }
.gs-tag { display:inline-flex; padding:3px 11px; border-radius:999px; font-size:12px; font-weight:800; background:#EEF1F0; color:#4a5a55; white-space:nowrap; }
.gs-tag.green { background:#E3F7EE; color:#0B6B5E; } .gs-tag.blue { background:#E4EFFD; color:#1d4f9c; } .gs-tag.red { background:#FDE7E7; color:#b3261e; }
.gs-empty { text-align:center; padding:26px; color:var(--mu); }
@media (max-width:1000px) { .gs-grid { grid-template-columns:minmax(0,1fr); } }
</style>

<div class="gs">

<div class="gs-head">
    <div>
        <h1>Google Login for Meet</h1>
        <p>One-time setup. After this, every client logs in with their own Google account (personal or Google Business) from <strong>Demo Bookings</strong>, and their demos get Google Meet links on their own calendar.</p>
    </div>
    <span class="gs-pill <?php echo $cfg['ready'] ? 'on' : 'off'; ?>"><?php echo $cfg['ready'] ? 'Ready' . ($cfg['source'] === 'env' ? ' (from .env)' : ' (saved here)') : 'Not set up yet'; ?></span>
</div>

<?php if ($ok): ?><div class="gs-alert ok"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
<?php if ($err): ?><div class="gs-alert err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<?php if ($redirectMismatch): ?><div class="gs-alert warn">The saved redirect address is <b><?php echo htmlspecialchars($shownRedirect); ?></b> but you are on <b><?php echo htmlspecialchars($defaultRedirect); ?></b>. Google will show "redirect_uri_mismatch" unless they match exactly.</div><?php endif; ?>

<div class="gs-grid">
<div>
<?php
// Steps of the guide: id, title, time, button to open in Google, what to do, values to copy, what you should see, tip
$redir = htmlspecialchars($defaultRedirect);
$guide = [
 ['id'=>'project','part'=>'Create the project','title'=>'Create a Google Cloud project','mins'=>'1 min',
  'link'=>['https://console.cloud.google.com/projectcreate','Open "New project"'],
  'do'=>['Sign in with the Google account that should <b>own</b> the app (your own Gmail is fine).','Type the project name below, then click <b>Create</b>.','Wait a few seconds until the name <b>Waamini</b> shows at the top left.'],
  'copy'=>[['Project name','Waamini']],
  'see'=>'The project name "Waamini" in the project box at the top of the page.','tip'=>''],
 ['id'=>'api','part'=>'Create the project','title'=>'Turn on the Google Calendar API','mins'=>'1 min',
  'link'=>['https://console.cloud.google.com/apis/library/calendar-json.googleapis.com','Open "Google Calendar API"'],
  'do'=>['Make sure the project <b>Waamini</b> is selected at the top.','Click the blue <b>Enable</b> button.'],
  'copy'=>[],'see'=>'A page called "API/Service details" with the word <b>Enabled</b> or a <b>Manage</b> button.','tip'=>''],
 ['id'=>'consent','part'=>'Tell Google about your app','title'=>'Set up the consent screen','mins'=>'3 min',
  'link'=>['https://console.cloud.google.com/auth/overview','Open "Google Auth Platform"'],
  'do'=>['Click <b>Get started</b> (older name: <i>APIs &amp; Services → OAuth consent screen</i>).','<b>App information:</b> enter the app name below and pick your email as support email. Click <b>Next</b>.','<b>Audience:</b> choose <b>External</b>. Click <b>Next</b>.','<b>Contact information:</b> type your email. Click <b>Next</b>.','Tick <b>I agree to the Google API Services User Data Policy</b>, then click <b>Continue</b> and <b>Create</b>.'],
  'copy'=>[['App name','Waamini']],
  'see'=>'An overview page with Branding, Audience, Clients and Data Access in the left menu.','tip'=>''],
 ['id'=>'scopes','part'=>'Tell Google about your app','title'=>'Allow the calendar permission','mins'=>'2 min',
  'link'=>['https://console.cloud.google.com/auth/scopes','Open "Data Access"'],
  'do'=>['In the left menu click <b>Data Access</b>, then <b>Add or remove scopes</b>.','In "Manually add scopes" paste the two permissions below, then click <b>Add to table</b>.','Click <b>Update</b>, then <b>Save</b> at the bottom.'],
  'copy'=>[['Permission 1','https://www.googleapis.com/auth/calendar.events'],['Permission 2','https://www.googleapis.com/auth/userinfo.email']],
  'see'=>'Both permissions in the list. <b>calendar.events</b> is shown under "Sensitive scopes". That is normal.','tip'=>''],
 ['id'=>'users','part'=>'Tell Google about your app','title'=>'Add the people who may log in','mins'=>'1 min',
  'link'=>['https://console.cloud.google.com/auth/audience','Open "Audience"'],
  'do'=>['Leave "Publishing status" as <b>Testing</b> while you set things up.','Under <b>Test users</b> click <b>Add users</b>.','Type the Gmail address of <b>every client</b> who will connect (Google Business emails work too) and click <b>Save</b>.'],
  'copy'=>[],'see'=>'Each client email listed under "Test users".','tip'=>'In Testing mode only listed people can log in (up to 100). Anyone else sees "Access blocked", so add a client here before they try.'],
 ['id'=>'client','part'=>'Create the keys','title'=>'Create the OAuth client','mins'=>'2 min',
  'link'=>['https://console.cloud.google.com/auth/clients/create','Open "Create client"'],
  'do'=>['Application type: <b>Web application</b>.','Name: use the name below.','Leave "Authorized JavaScript origins" empty.','Under <b>Authorized redirect URIs</b> click <b>Add URI</b> and paste the address below.','Click <b>Create</b>, then copy the <b>Client ID</b> and <b>Client secret</b>.'],
  'copy'=>[['Client name','Waamini web login'],['Redirect address (must match exactly)',$defaultRedirect]],
  'see'=>'A box "OAuth client created" with a <b>Client ID</b> (ends with .apps.googleusercontent.com) and a <b>Client secret</b> (starts with GOCSPX-).','tip'=>'The redirect address must match letter for letter: same http or https, same domain, no space and no slash at the end. You can find the keys again later under Clients.'],
 ['id'=>'paste','part'=>'Connect it to Waamini','title'=>'Paste the keys into Waamini','mins'=>'1 min',
  'link'=>null,
  'do'=>['Use the <b>"Paste your Google keys"</b> form on the right of this page.','Paste the <b>Client ID</b> and the <b>Client secret</b>. Keep the redirect address as shown.','Click <b>Save</b>.'],
  'copy'=>[],'see'=>'The message "Saved." and the status at the top turning green: <b>Ready</b>. No file to edit and no restart.','tip'=>''],
 ['id'=>'test','part'=>'Connect it to Waamini','title'=>'Test it as a client','mins'=>'2 min',
  'link'=>['/client/demo_bookings.php','Open client Demo Bookings'],
  'do'=>['Log in as a client and open <b>Demo Bookings</b>.','Click <b>Login with Google</b> and choose one of your test users. Allow the permissions.','Open <b>Settings → Google Calendar &amp; Meet</b> and click <b>Send a test meeting</b>.'],
  'copy'=>[],'see'=>'A link like https://meet.google.com/abc-defg-hij. That means everything works.','tip'=>''],
];
$totalSteps = count($guide);
$fixes = [
 ['redirect_uri_mismatch','The redirect address in Google (step 6) and the one in Waamini are different. Make them identical.'],
 ['Access blocked / not completed the Google verification process','That person\'s email is not in Test users (step 5). Add it and try again.'],
 ['"Google hasn\'t verified this app" warning','Normal for a new app. Click <b>Advanced</b>, then <b>Go to Waamini (unsafe)</b>.'],
 ['Login stops working after about a week','Apps in Testing mode lose access after 7 days. For daily use click <b>Publish app</b> under Audience and submit it for Google verification.'],
 ['A Google Workspace (business) account is blocked','Their company\'s Google admin has blocked outside apps. They can use a personal Gmail instead.'],
];
?>
    <div class="gg" id="gg">
        <div class="gg-top">
            <div>
                <h2 class="gg-h"><?php echo $I('<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>'); ?> Setup guide</h2>
                <p class="gg-sub">Do this once, in about 10 minutes. Open Google Cloud in a second tab and follow the steps in order. Tick each step when you finish it, and your progress is remembered.</p>
            </div>
            <div class="gg-prog"><b id="ggDone">0</b> of <?php echo $totalSteps; ?> done<div class="gg-bar"><i id="ggBar"></i></div></div>
        </div>

        <?php $lastPart = ''; foreach ($guide as $i => $g): $n = $i + 1;
            if ($g['part'] !== $lastPart): $lastPart = $g['part']; ?><div class="gg-part"><?php echo htmlspecialchars($g['part']); ?></div><?php endif; ?>
        <details class="gg-step" id="gg-<?php echo $g['id']; ?>" data-step="<?php echo $g['id']; ?>">
            <summary>
                <span class="gg-num"><?php echo $n; ?></span>
                <span class="gg-ttl"><?php echo htmlspecialchars($g['title']); ?></span>
                <span class="gg-min"><?php echo htmlspecialchars($g['mins']); ?></span>
                <label class="gg-chk" onclick="event.stopPropagation()"><input type="checkbox" class="gg-cb" data-id="<?php echo $g['id']; ?>" aria-label="Mark step <?php echo $n; ?> as done"><span>Done</span></label>
                <span class="gg-chev" aria-hidden="true"></span>
            </summary>
            <div class="gg-body">
                <?php if ($g['link']): ?><a class="gg-open" href="<?php echo htmlspecialchars($g['link'][0]); ?>" target="_blank" rel="noopener"><?php echo $I('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>', 15); ?> <?php echo htmlspecialchars($g['link'][1]); ?></a><?php endif; ?>
                <ol class="gg-do"><?php foreach ($g['do'] as $d): ?><li><?php echo $d; ?></li><?php endforeach; ?></ol>
                <?php foreach ($g['copy'] as $c): ?>
                <div class="gg-copy"><span class="gg-cl"><?php echo htmlspecialchars($c[0]); ?></span><code><?php echo htmlspecialchars($c[1]); ?></code><button type="button" onclick="ggCopy(this)">Copy</button></div>
                <?php endforeach; ?>
                <div class="gg-see"><?php echo $I('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?><div><b>You should see</b><?php echo $g['see']; ?></div></div>
                <?php if ($g['tip'] !== ''): ?><div class="gg-tip"><?php echo $I('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 18); ?><div><b>Good to know</b><?php echo $g['tip']; ?></div></div><?php endif; ?>
                <?php if ($n < $totalSteps): ?><button type="button" class="gg-next" onclick="ggNext('<?php echo $g['id']; ?>')">Done, next step &rarr;</button><?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>

        <div class="gg-part">If Google shows an error</div>
        <div class="gg-fixes">
            <?php foreach ($fixes as $f): ?>
            <details class="gg-fix"><summary><?php echo $I('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 16); ?> <?php echo htmlspecialchars($f[0]); ?><span class="gg-chev" aria-hidden="true"></span></summary><p><?php echo $f[1]; ?></p></details>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<div class="gs-side">
    <div class="gs-card gg-card">
        <h2><?php echo $I('<path d="M20 6 9 17l-5-5"/>'); ?> Your progress</h2>
        <?php if ($cfg['ready']): ?><div class="gg-ready">Google login is <b>ready</b>. Clients can log in from Demo Bookings.</div><?php endif; ?>
        <ul class="gg-list" id="ggList">
            <?php foreach ($guide as $i => $g): ?><li data-id="<?php echo $g['id']; ?>"><a href="#gg-<?php echo $g['id']; ?>" onclick="ggOpen('<?php echo $g['id']; ?>');return false;"><span class="gg-dot"><?php echo $i + 1; ?></span><?php echo htmlspecialchars($g['title']); ?></a></li><?php endforeach; ?>
        </ul>
        <button type="button" class="gs-btn soft" style="width:100%;justify-content:center;margin-top:10px;height:38px;font-size:13px" onclick="ggReset()">Start over</button>
    </div>
<?php
// Data for the switcher: the platform keys and each client's own keys
$swPlatform = ['cid' => $cfg['client_id'], 'hasSecret' => $cfg['client_secret'] !== '', 'source' => $cfg['source'], 'redirect' => $shownRedirect];
$swClients = [];
foreach ($clients as $c) { $swClients[(int)$c['id']] = ['name' => (string)$c['name'], 'cid' => (string)$c['own_client_id'], 'own' => (int)$c['own_app'] === 1]; }
?>
    <div class="gs-card" id="gkForm">
        <h2><?php echo $I('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'); ?> Paste your Google keys</h2>
        <form method="POST" autocomplete="off" id="gkF">
            <?php echo csrf_field(); ?>
            <div class="gs-f"><label for="gkFor">Which keys do you want to set?</label>
                <select name="for_company" id="gkFor" class="gk-select">
                    <option value="0" <?php echo $selCompany === 0 ? 'selected' : ''; ?>>All clients (platform keys)</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $selCompany === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$c['name']); ?><?php echo (int)$c['own_app'] === 1 ? '  ·  own keys' : ''; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="gk-mode" id="gkMode"></div>
            <?php if ($cfg['source'] === 'env'): ?><div class="gs-alert warn" id="gkEnv">Your .env file already has Google keys, and they take priority over the platform keys.</div><?php endif; ?>
            <div class="gs-f"><label for="cid">Client ID</label><input type="text" id="cid" name="client_id" placeholder="123456-abc.apps.googleusercontent.com" required></div>
            <div class="gs-f"><label for="csec">Client secret</label><input type="password" id="csec" name="client_secret" autocomplete="new-password"><small>Stored encrypted. It is never shown again.</small></div>
            <div class="gs-f"><label for="ruri2">Redirect address</label><input type="text" id="ruri2" name="redirect_uri" required><small id="ruriHelp">Must match what is added in Google Cloud exactly.</small></div>
            <div class="gk-act">
                <button type="submit" name="save_google" class="gs-btn"><?php echo $I('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?> Save</button>
                <button type="submit" name="clear_client_keys" id="gkClear" class="gs-btn" style="background:#FEEDED;color:#c62828;display:none" formnovalidate onclick="return confirm('Remove this client\'s own keys and use the platform keys?')">Use platform keys</button>
            </div>
        </form>
    </div>
<script>
(function () {
    var PLATFORM = <?php echo json_encode($swPlatform, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var CLIENTS = <?php echo json_encode($swClients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var DEFAULT_REDIRECT = <?php echo json_encode($defaultRedirect, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var sel = document.getElementById('gkFor');
    function $(id) { return document.getElementById(id); }
    function apply() {
        var id = parseInt(sel.value, 10) || 0;
        var mode = $('gkMode'), cid = $('cid'), sec = $('csec'), ru = $('ruri2'), help = $('ruriHelp');
        sec.value = '';
        if (id === 0) {
            cid.value = PLATFORM.cid || '';
            sec.placeholder = PLATFORM.hasSecret ? 'Saved. Leave blank to keep it' : 'GOCSPX-...';
            sec.required = !PLATFORM.hasSecret;
            ru.value = PLATFORM.redirect; ru.readOnly = false;
            help.textContent = 'Must match step 6 of the guide exactly. It is filled in for the site you are on now.';
            mode.className = 'gk-mode'; mode.innerHTML = '<b>Platform keys.</b> Used by every client who does not have their own keys.';
            $('gkClear').style.display = 'none';
            if ($('gkEnv')) $('gkEnv').style.display = '';
        } else {
            var c = CLIENTS[id] || { name: 'this client', cid: '', own: false };
            cid.value = c.cid || '';
            sec.placeholder = c.own ? 'Saved. Leave blank to keep it' : 'GOCSPX-...';
            sec.required = !c.own;
            ru.value = DEFAULT_REDIRECT; ru.readOnly = true;
            help.textContent = 'This client adds this address in their own Google Cloud (step 6 of the guide).';
            mode.className = 'gk-mode client';
            mode.innerHTML = '<b>' + c.name.replace(/</g, '&lt;') + '.</b> Only this client uses these keys. ' + (c.own ? 'They already have their own keys saved.' : 'They currently use the platform keys.');
            $('gkClear').style.display = c.own ? '' : 'none';
            if ($('gkEnv')) $('gkEnv').style.display = 'none';
        }
    }
    sel.addEventListener('change', apply);
    window.gkPick = function (id) { sel.value = String(id); apply(); $('gkForm').scrollIntoView({ behavior: 'smooth', block: 'center' }); $('cid').focus(); };
    apply();
})();
</script>

</div>
</div>

<div class="gs-card">
    <h2><?php echo $I('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?> Google settings for each client <span class="gs-pill <?php echo $connectedCount ? 'on' : 'off'; ?>" style="margin-left:6px"><?php echo (int)$connectedCount; ?> logged in</span></h2>
    <div style="overflow-x:auto">
    <table class="gs-tbl">
        <thead><tr><th>Client</th><th>Google account</th><th>Google app used</th><th>Google Meet</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$clients): ?><tr><td colspan="5" class="gs-empty">No clients yet.</td></tr><?php endif; ?>
        <?php foreach ($clients as $c): $on = (int)$c['meet_enabled'] === 1; ?>
        <tr>
            <td><strong><?php echo htmlspecialchars((string)$c['name']); ?></strong><br><small>ID <?php echo (int)$c['id']; ?></small></td>
            <td><?php if ((int)$c['has_login'] === 1): ?><span class="gs-tag green">Logged in</span><br><small><?php echo htmlspecialchars((string)($c['google_email'] ?: 'connected')); ?></small><?php else: ?><span class="gs-tag">Not logged in</span><?php endif; ?></td>
            <td><?php echo (int)$c['own_app'] === 1 ? '<span class="gs-tag blue">Own Google keys</span><br><small>' . htmlspecialchars((string)$c['own_client_id']) . '</small>' : '<span class="gs-tag">Platform keys</span>'; ?></td>
            <td><?php echo $on ? '<span class="gs-tag green">Allowed</span>' : '<span class="gs-tag red">Switched off</span>'; ?></td>
            <td style="text-align:right;white-space:nowrap">
                <button type="button" class="gs-btn" style="height:34px;padding:0 14px;font-size:13px" onclick="gkPick(<?php echo (int)$c['id']; ?>)"><?php echo (int)$c['own_app'] === 1 ? 'Edit keys' : 'Paste keys'; ?></button>
                <form method="POST" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="company_id" value="<?php echo (int)$c['id']; ?>"><input type="hidden" name="enable" value="<?php echo $on ? '0' : '1'; ?>"><button type="submit" name="toggle_meet" class="gs-btn soft" style="height:34px;padding:0 14px;font-size:13px"><?php echo $on ? 'Switch off' : 'Switch on'; ?></button></form>
                <?php if ((int)$c['has_login'] === 1): ?><form method="POST" style="display:inline" onsubmit="return confirm('Disconnect this client from Google? They can log in again from Demo Bookings.')"><?php echo csrf_field(); ?><input type="hidden" name="company_id" value="<?php echo (int)$c['id']; ?>"><button type="submit" name="disconnect_client" class="gs-btn" style="height:34px;padding:0 14px;font-size:13px;background:#FEEDED;color:#c62828">Disconnect</button></form><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div>

<script>
(function () {
    var KEY = 'waamini_google_guide_done';
    var steps = Array.prototype.slice.call(document.querySelectorAll('.gg-step'));
    var ready = <?php echo $cfg['ready'] ? 'true' : 'false'; ?>;
    var done = {};
    try { done = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { done = {}; }
    if (ready) { ['project','api','consent','scopes','users','client','paste'].forEach(function (id) { done[id] = true; }); }

    function save() { try { localStorage.setItem(KEY, JSON.stringify(done)); } catch (e) {} }
    function render() {
        var n = 0;
        steps.forEach(function (st) {
            var id = st.dataset.step, d = !!done[id];
            st.classList.toggle('done', d);
            st.querySelector('.gg-cb').checked = d;
            var li = document.querySelector('#ggList li[data-id="' + id + '"]');
            if (li) li.classList.toggle('done', d);
            if (d) n++;
        });
        document.getElementById('ggDone').textContent = n;
        document.getElementById('ggBar').style.width = Math.round(n / steps.length * 100) + '%';
    }
    function openOnly(id) {
        steps.forEach(function (st) { st.open = (st.dataset.step === id); });
        var el = document.getElementById('gg-' + id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function firstTodo() { for (var i = 0; i < steps.length; i++) { if (!done[steps[i].dataset.step]) return steps[i].dataset.step; } return null; }

    steps.forEach(function (st) {
        st.querySelector('.gg-cb').addEventListener('change', function () { done[st.dataset.step] = this.checked; save(); render(); });
    });
    window.ggOpen = function (id) { openOnly(id); };
    window.ggNext = function (id) {
        done[id] = true; save(); render();
        var idx = steps.findIndex(function (s2) { return s2.dataset.step === id; });
        if (idx > -1 && steps[idx + 1]) openOnly(steps[idx + 1].dataset.step);
    };
    window.ggReset = function () { done = {}; save(); render(); openOnly(steps[0].dataset.step); };
    window.ggCopy = function (btn) {
        var t = btn.parentNode.querySelector('code').textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t);
        var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = o; }, 1400);
    };
    render();
    var first = firstTodo();
    if (first) { var el = document.getElementById('gg-' + first); if (el) el.open = true; }
})();
</script>
<?php include '../includes/admin_footer.php'; ?>
