 <?php
/**
 * Google OAuth — Step 2: Google redirects here with ?code=&state=
 * Exchanges code for tokens, fetches user info, logs in or registers.
 * URL: /waamini/auth/google-callback.php
 */
require_once __DIR__ . '/../config/bootstrap.php';
include __DIR__ . '/../includes/security.php';
include __DIR__ . '/../db.php';

// ── Helper: POST request via cURL ─────────────────────────────────────────────
function google_post(string $url, array $data): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    // Windows/XAMPP: point cURL to the bundled CA certificates
    $ca = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
    if (PHP_OS_FAMILY === 'Windows' && file_exists($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno) {
        error_log('[WAAMINI Google OAuth] cURL POST error ' . $errno . ': ' . curl_error($ch));
    }
    curl_close($ch);
    return ($body && !$errno) ? (json_decode($body, true) ?: null) : null;
}

// ── Helper: GET request via cURL ─────────────────────────────────────────────
function google_get(string $url, string $accessToken): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $ca = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
    if (PHP_OS_FAMILY === 'Windows' && file_exists($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno) {
        error_log('[WAAMINI Google OAuth] cURL GET error ' . $errno . ': ' . curl_error($ch));
    }
    curl_close($ch);
    return ($body && !$errno) ? (json_decode($body, true) ?: null) : null;
}

// ── Helper: safe redirect with error code ─────────────────────────────────────
function oauth_fail(string $code): never
{
    $origin = (string)($_SESSION['oauth_origin'] ?? 'login');
    unset($_SESSION['oauth_origin']);
    $dest = ($origin === 'register') ? '../register.php' : '../login.php';
    header('Location: ' . $dest . '?error=' . rawurlencode($code));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// BRANCH: Google Calendar connect (state prefix 'gcal_') — for demo Meet scheduling
// Uses the same registered redirect URI; completely separate from login flow.
// ═══════════════════════════════════════════════════════════════════════
$incomingStateEarly = (string)($_GET['state'] ?? '');
if (strpos($incomingStateEarly, 'gcal_') === 0) {
    $gcalState = (string)($_SESSION['gcal_state'] ?? '');
    $gcalTs = (int)($_SESSION['gcal_ts'] ?? 0);
    $gcalCompanyId = (int)($_SESSION['gcal_company_id'] ?? 0);
    $gcalReturn = (string)($_SESSION['gcal_return'] ?? 'google_calendar');
    if (!in_array($gcalReturn, ['demo_bookings', 'google_calendar'], true)) $gcalReturn = 'google_calendar';
    unset($_SESSION['gcal_state'], $_SESSION['gcal_ts'], $_SESSION['gcal_company_id'], $_SESSION['gcal_return']);

    $gcalFail = function (string $why) use ($gcalReturn): never {
        error_log('[WAAMINI GCAL] connect failed: ' . $why);
        header('Location: ../client/' . $gcalReturn . '.php?gcal=error&why=' . rawurlencode($why));
        exit;
    };

    if ($gcalState === '' || !hash_equals($gcalState, $incomingStateEarly) || (time() - $gcalTs) > 600) {
        $gcalFail('state_mismatch');
    }
    if ($gcalCompanyId <= 0) $gcalFail('no_company');
    if (isset($_GET['error'])) $gcalFail('denied_' . $_GET['error']);

    $gcalCode = (string)($_GET['code'] ?? '');
    if ($gcalCode === '') $gcalFail('no_code');

    require_once __DIR__ . '/../includes/google_meet_helper.php';
    $gCfg = gmeet_oauth_config($conn, $gcalCompanyId);
    $gClientId = $gCfg['client_id'];
    $gClientSecret = $gCfg['client_secret'];
    $gRedirectUri = $gCfg['redirect_uri'];

    $gTok = google_post('https://oauth2.googleapis.com/token', [
        'code' => $gcalCode,
        'client_id' => $gClientId,
        'client_secret' => $gClientSecret,
        'redirect_uri' => $gRedirectUri,
        'grant_type' => 'authorization_code',
    ]);

    if (empty($gTok['access_token'])) $gcalFail('token_exchange');
    if (empty($gTok['refresh_token'])) $gcalFail('no_refresh_token');
    // The user can untick the calendar box on Google's screen; without it no Meet can be created
    if (isset($gTok['scope']) && strpos((string)$gTok['scope'], 'calendar') === false) $gcalFail('missing_permission');

    // Identify which Google account was connected (for display)
    $gInfo = google_get('https://www.googleapis.com/oauth2/v3/userinfo', $gTok['access_token']);
    $gEmail = (string)($gInfo['email'] ?? '');

    // Store tokens (upsert per company)
    require_once __DIR__ . '/../includes/google_meet_helper.php';
    gmeet_ensure_table($conn);

    $accessExpires = date('Y-m-d H:i:s', time() + (int)($gTok['expires_in'] ?? 3500));
    $st = $conn->prepare(
        "INSERT INTO google_calendar_tokens (company_id, google_email, refresh_token, access_token, access_expires_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE google_email=VALUES(google_email), refresh_token=VALUES(refresh_token),
                                 access_token=VALUES(access_token), access_expires_at=VALUES(access_expires_at)"
    );
    $st->bind_param('issss', $gcalCompanyId, $gEmail, $gTok['refresh_token'], $gTok['access_token'], $accessExpires);
    $st->execute();
    $st->close();

    header('Location: ../client/' . $gcalReturn . '.php?gcal=connected');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 1 — Validate state (prevents CSRF)
// ═══════════════════════════════════════════════════════════════════════
$sessionState = (string)($_SESSION['oauth_google_state'] ?? '');
$sessionTs    = (int)($_SESSION['oauth_google_ts']    ?? 0);
$incomingState = (string)($_GET['state'] ?? '');

// State must match AND be less than 10 minutes old
if (
    $sessionState === ''
    || !hash_equals($sessionState, $incomingState)
    || (time() - $sessionTs) > 600
) {
    unset($_SESSION['oauth_google_state'], $_SESSION['oauth_google_ts']);
    error_log('[WAAMINI Google OAuth] State mismatch or expired.');
    oauth_fail('oauth_state');
}
unset($_SESSION['oauth_google_state'], $_SESSION['oauth_google_ts']);

// ═══════════════════════════════════════════════════════════════════════
// STEP 2 — Check if Google returned an error
// ═══════════════════════════════════════════════════════════════════════
if (isset($_GET['error'])) {
    error_log('[WAAMINI Google OAuth] Google error: ' . htmlspecialchars($_GET['error']));
    oauth_fail('oauth_denied');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    oauth_fail('oauth_no_code');
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 3 — Exchange authorization code for tokens
// ═══════════════════════════════════════════════════════════════════════
$clientId     = (string)\Waamini\Config\Env::get('GOOGLE_CLIENT_ID', '');
$clientSecret = (string)\Waamini\Config\Env::get('GOOGLE_CLIENT_SECRET', '');
$redirectUri  = (string)\Waamini\Config\Env::get('GOOGLE_REDIRECT_URI', '');

$tokenData = google_post('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

if (empty($tokenData['access_token'])) {
    error_log('[WAAMINI Google OAuth] Token exchange failed. Response: ' . json_encode($tokenData));
    oauth_fail('oauth_token_fail');
}

$accessToken = $tokenData['access_token'];

// ═══════════════════════════════════════════════════════════════════════
// STEP 4 — Fetch user info from Google
// ═══════════════════════════════════════════════════════════════════════
$userInfo = google_get('https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);

if (empty($userInfo['sub'])) {
    error_log('[WAAMINI Google OAuth] userinfo fetch failed. Response: ' . json_encode($userInfo));
    oauth_fail('oauth_userinfo_fail');
}

$googleId       = (string)$userInfo['sub'];
$email          = strtolower(trim((string)($userInfo['email']   ?? '')));
$name           = trim((string)($userInfo['name']    ?? ''));
$avatarUrl      = (string)($userInfo['picture'] ?? '');
$emailVerified  = !empty($userInfo['email_verified']);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    oauth_fail('oauth_no_email');
}

if (!$emailVerified) {
    oauth_fail('oauth_unverified_email');
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 5 — Find or create the user in Waamini
// ═══════════════════════════════════════════════════════════════════════

// 5a. Look up by google_id (fastest — avoids email collision issues)
$stmt = $conn->prepare(
    "SELECT id, name, email, company_id, role, status, two_factor_enabled, two_factor_secret
     FROM users WHERE google_id = ? LIMIT 1"
);
if (!$stmt) {
    error_log('[WAAMINI Google OAuth] DB prepare error: ' . $conn->error);
    oauth_fail('oauth_db_error');
}
$stmt->bind_param("s", $googleId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 5b. Not found by Google ID — try by email (link existing account)
if (!$user) {
    $stmt = $conn->prepare(
        "SELECT id, name, email, company_id, role, status, two_factor_enabled, two_factor_secret
         FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Link Google ID to the existing email account
        $upd = $conn->prepare("UPDATE users SET google_id = ?, avatar_url = ? WHERE id = ?");
        $upd->bind_param("ssi", $googleId, $avatarUrl, $user['id']);
        $upd->execute();
        $upd->close();
    }
}

// 5c. No account at all — register a new one
if (!$user) {
    $conn->begin_transaction();
    try {
        // Create company record first (mirrors register.php flow)
        $companyName = $name ?: explode('@', $email)[0];
        $compStmt = $conn->prepare(
            "INSERT INTO companies (name, email, status, created_at) VALUES (?, ?, 'active', NOW())"
        );
        if (!$compStmt) throw new \RuntimeException('companies prepare: ' . $conn->error);
        $compStmt->bind_param("ss", $companyName, $email);
        $compStmt->execute();
        $newCompanyId = (int)$conn->insert_id;
        $compStmt->close();

        // Create user with a locked password (random — they use Google to sign in)
        $lockedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $userStmt = $conn->prepare(
            "INSERT INTO users (company_id, company, name, email, password, google_id, avatar_url, role, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'client', 'active', NOW())"
        );
        if (!$userStmt) throw new \RuntimeException('users prepare: ' . $conn->error);
        $userStmt->bind_param(
            "issssss",
            $newCompanyId, $companyName, $name, $email,
            $lockedPassword, $googleId, $avatarUrl
        );
        $userStmt->execute();
        $newUserId = (int)$conn->insert_id;
        $userStmt->close();

        $conn->commit();

        // Re-fetch full user row
        $stmt2 = $conn->prepare(
            "SELECT id, name, email, company_id, role, status, two_factor_enabled, two_factor_secret
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt2->bind_param("i", $newUserId);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('[WAAMINI Google OAuth] Registration failed: ' . $e->getMessage());
        oauth_fail('oauth_register_fail');
    }
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 6 — Sanity checks before logging in
// ═══════════════════════════════════════════════════════════════════════
if (!$user) {
    oauth_fail('oauth_user_not_found');
}

if (($user['status'] ?? '') !== 'active') {
    oauth_fail('oauth_account_disabled');
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 7 — Handle 2FA (if enabled on this account)
// ═══════════════════════════════════════════════════════════════════════
if (!empty($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1) {
    $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
    header('Location: ../verify_2fa.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 8 — Create authenticated session
// ═══════════════════════════════════════════════════════════════════════
session_regenerate_id(true);
$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['user_name']  = (string)$user['name'];
$_SESSION['company_id'] = (int)$user['company_id'];
$_SESSION['role']       = (string)$user['role'];

// ── Log to audit_log ─────────────────────────────────────────────────────────
$auditAction = isset($newUserId) ? 'google_register' : 'google_login';
$auditDesc   = isset($newUserId)
    ? 'New account created via Google OAuth: ' . $email
    : 'User signed in via Google OAuth: ' . $email;
$auditIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$auditStmt = $conn->prepare(
    "INSERT INTO audit_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())"
);
if ($auditStmt) {
    $auditStmt->bind_param("isss", $_SESSION['user_id'], $auditAction, $auditDesc, $auditIp);
    $auditStmt->execute();
    $auditStmt->close();
}

// ── Clear notification cache so admin bell updates immediately ────────────────
unset($_SESSION['_admin_notif_users'], $_SESSION['_admin_notif_ts'], $_SESSION['oauth_origin']);

$destination = ($user['role'] === 'super_admin')
    ? '../admin/dashboard.php'
    : '../dashboard.php';

header('Location: ' . $destination);
exit;
