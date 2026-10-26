<?php
/**
 * Client Auth Check
 * Include at the top of every client panel page.
 * Ensures user is logged in and NOT a super_admin.
 * Sets $companyId for data scoping.
 */
include_once __DIR__ . '/../config/bootstrap.php';

// Dynamic app base path — works at document root AND in any subdirectory
if (!function_exists('app_url')) {
    function app_url(string $path = ''): string {
        // Production: APP_URL in .env is the source of truth when it is the site being visited
        // (e.g. APP_URL=https://waamini.com -> base ''). Symlinked hosting folders can't confuse it.
        static $envBase = false;
        if ($envBase === false) {
            $envBase = null;
            $appUrl = (string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
            $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
            $u = $appUrl !== '' ? parse_url($appUrl) : false;
            if ($u && !empty($u['host']) && $host !== ''
                && preg_replace('/^www\./', '', strtolower($u['host'])) === preg_replace('/^www\./', '', $host)) {
                $envBase = rtrim((string)($u['path'] ?? ''), '/');
            }
        }
        if ($envBase !== null) return $envBase . '/' . ltrim($path, '/');

        // Base = URL path (SCRIPT_NAME) minus the script's path inside the app,
        // computed from the filesystem. E.g.:
        //   /waamini/client/dashboard.php -> /waamini   (subdirectory install)
        //   /client/dashboard.php         -> ''         (document-root install)
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $rootFs = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
        $fileFs = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        $base = null;
        if ($rootFs !== '' && $fileFs !== '' && strncasecmp($fileFs, $rootFs, strlen($rootFs)) === 0) {
            $inApp = substr($fileFs, strlen($rootFs)); // e.g. /client/dashboard.php
            if ($inApp !== '' && strlen($script) >= strlen($inApp)
                && substr_compare($script, $inApp, -strlen($inApp), strlen($inApp), true) === 0) {
                $base = substr($script, 0, strlen($script) - strlen($inApp));
            }
        }
        if ($base === null) {
            // Fallback (symlinked/aliased installs): first URL segment if it is a directory
            $parts = explode('/', trim($script, '/'));
            $appDirs = ['client', 'admin', 'api', 'auth', 'landing-admin', 'includes', 'assets'];
            $base  = (count($parts) > 1 && !in_array(strtolower($parts[0]), $appDirs, true)) ? '/' . $parts[0] : '';
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

// Check if this is a system cron job bypass
$cronSecret = $_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?? '';
$reqSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
$isCronBypass = ($cronSecret !== '' && $reqSecret !== '' && hash_equals($cronSecret, $reqSecret));

if ($isCronBypass) {
    $GLOBALS['companyId'] = (int)($_GET['company_id'] ?? 0);
    return; // Exit the include early, no further session checks needed
}

// Check if logged in
if (!isset($_SESSION['user_id']) && !$isCronBypass) {
    $currentPagePath = $_SERVER['PHP_SELF'] ?? '';
    $isApiRequest = (strpos($currentPagePath, '/api/') !== false);
    if ($isApiRequest) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthenticated']);
        exit();
    }
    header('Location: ' . app_url('login.php'));
    exit();
}

// Check for timeouts
if (!empty($_SESSION['timed_out'])) {
    session_destroy();
    header('Location: ' . app_url('login.php?msg=timeout'));
    exit();
}


// If super_admin and NOT impersonating, redirect to admin
if ($_SESSION['role'] === 'super_admin' && !isset($_SESSION['impersonating'])) {
    header('Location: ' . app_url('admin/dashboard.php'));
    exit();
}

// Check if user is disabled — cached in session for 5 minutes to avoid a DB hit on every page load.
// If an admin disables an account, the user will be kicked within 5 minutes at most.
include_once __DIR__ . '/../db.php';
$_now = time();
$_cacheValid = isset($_SESSION['_status_checked_at']) && ($_now - $_SESSION['_status_checked_at']) < 300;
if (!$_cacheValid) {
    $_userStmt = $conn->prepare("SELECT status FROM users WHERE id=?");
    $_userId = intval($_SESSION['user_id']);
    $_userStmt->bind_param("i", $_userId);
    $_userStmt->execute();
    $_userCheck = $_userStmt->get_result();
    if ($_userCheck && $_user = $_userCheck->fetch_assoc()) {
        $_SESSION['_user_status'] = $_user['status'] ?? 'active';
    }
    $_SESSION['_status_checked_at'] = $_now;
    unset($_userStmt, $_userId, $_userCheck, $_user);
}
if (($_SESSION['_user_status'] ?? 'active') === 'disabled') {
    session_destroy();
    header('Location: ' . app_url('login.php?msg=disabled'));
    exit();
}
unset($_now, $_cacheValid);

// System maintenance mode (applies to client panel only).
// Allow admin impersonation sessions to bypass so the admin can verify client pages while maintenance is enabled.
$_mp = $_SERVER['PHP_SELF'] ?? '';
$_isApiRequest = (strpos($_mp, '/api/') !== false);
$_maintenanceCacheValid = isset($_SESSION['_maint_checked_at']) && (time() - (int)$_SESSION['_maint_checked_at']) < 30;
if (!$_maintenanceCacheValid) {
    $_maint = ['mode' => '0', 'message' => 'We are performing scheduled maintenance. Please try again later.'];
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode','maintenance_message') LIMIT 2");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (($row['setting_key'] ?? '') === 'maintenance_mode') {
                $_maint['mode'] = (string)($row['setting_value'] ?? '0');
            }
            if (($row['setting_key'] ?? '') === 'maintenance_message') {
                $_maint['message'] = (string)($row['setting_value'] ?? '');
            }
        }
    }
    $_SESSION['_maint_mode'] = $_maint['mode'];
    $_SESSION['_maint_message'] = $_maint['message'];
    $_SESSION['_maint_checked_at'] = time();
}

$_maintOn = (string)($_SESSION['_maint_mode'] ?? '0') === '1';
$_isImpersonation = !empty($_SESSION['impersonating']);
if ($_maintOn && !$_isImpersonation) {
    if ($_isApiRequest) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'maintenance', 'message' => (string)($_SESSION['_maint_message'] ?? '')]);
        exit();
    }

    $msg = trim((string)($_SESSION['_maint_message'] ?? ''));
    if ($msg === '') {
        $msg = 'We are performing scheduled maintenance. Please try again later.';
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance – WAAMINI</title>';
    echo '<style>body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0a0c15;color:#e9ecff;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}';
    echo '.card{max-width:720px;width:100%;background:rgba(30,32,48,.78);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:28px 26px;box-shadow:0 18px 70px rgba(0,0,0,.45)}';
    echo 'h1{margin:0 0 10px;font-size:22px;letter-spacing:-.3px}p{margin:0;color:rgba(233,236,255,.75);line-height:1.55}';
    echo '.hint{margin-top:16px;font-size:12px;color:rgba(233,236,255,.55)}</style></head><body>';
    echo '<div class="card"><h1>🚧 Maintenance in progress</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><div class="hint">Please try again in a few minutes.</div></div>';
    echo '</body></html>';
    exit();
}

// Company ID for all queries
$companyId = intval($_SESSION['company_id'] ?? 0);

// NEW: Check if company has ANY subscription (active or inactive).
// If they have zero records, they are treated as a 'New Registration' and restricted to the sales contact view.
$isRestricted = ($companyId <= 0);
if (!$isRestricted) {
    $_subCountStmt = $conn->prepare("SELECT COUNT(*) as c FROM subscriptions WHERE company_id = ?");
    if ($_subCountStmt) {
        $_subCountStmt->bind_param('i', $companyId);
        $_subCountStmt->execute();
        $_subCountRes = $_subCountStmt->get_result();
        $_row = $_subCountRes ? $_subCountRes->fetch_assoc() : null;
        if (intval($_row['c'] ?? 0) === 0) {
            $isRestricted = true;
        }
    }
}

// Load feature helpers early to check subscription
include_once __DIR__ . '/feature_check.php';
include_once __DIR__ . '/security.php';

// Check for subscription expiration
$isExpired = is_subscription_expired();
$viewOnlyMode = $isExpired;

// If expired or restricted, define allowed pages
if ($viewOnlyMode || $isRestricted) {
    $currentPagePath = $_SERVER['PHP_SELF'];

    // API endpoints are always allowed for authenticated users to prevent AJAX
    // fetch() from following redirects and receiving HTML instead of JSON
    $isApiRequest = (strpos($currentPagePath, '/api/') !== false);

    if (!$isApiRequest) {
        // Basic allowed pages (Viewing only)
        $allowedBase = [
            app_url('client/dashboard.php'),
            app_url('client/subscription.php'),
            app_url('logout.php'),
            app_url('client/profile.php'),
            app_url('client/contacts.php'),
            app_url('client/campaigns.php'),
            app_url('client/templates.php'),
            app_url('client/message_log.php'),
            app_url('client/whatsapp.php'),
            app_url('client/bot_replies.php'),
        ];

        $isAllowed = false;
        foreach ($allowedBase as $ap) {
            if (strpos($currentPagePath, $ap) !== false) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $redir = $isRestricted ? "restricted" : "expired";
            header('Location: ' . app_url('client/dashboard.php?msg=' . $redir));
            exit();
        }
    }
}
?>
