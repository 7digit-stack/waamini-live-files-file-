<?php
/**
 * WAAMINI Database Connection (mysqli)
 * Provides $conn global variable for legacy scripts
 */

if (!defined('WAAMINI_LOADED')) {
    // Block only direct HTTP access to db.php itself.
    $isCLI = (php_sapi_name() === 'cli');
    $isDirectAccess = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__);
    if (!$isCLI && $isDirectAccess) {
        http_response_code(403);
        echo 'Direct access forbidden';
        exit;
    }
    // Allow includes from legacy endpoints that don't load bootstrap.php.
    define('WAAMINI_LOADED', true);
}
// cron jobs: note when this script ran (Admin > System Report > Cron jobs)
require_once __DIR__ . '/includes/cron_runlog.php';

// Ensure env is loaded even for legacy scripts that include db.php directly
if (file_exists(__DIR__ . '/config/env.php')) {
    require_once __DIR__ . '/config/env.php';
    if (class_exists('\Waamini\Config\Env') && file_exists(__DIR__ . '/.env')) {
        try {
            \Waamini\Config\Env::load(__DIR__ . '/.env');
        } catch (\Throwable $e) {
            // swallow: we'll fall back to defaults below
        }
    }
}

// Fetch credentials securely from environment (loaded by bootstrap -> env.php)
// Prioritize static cache from Env class to avoid issues with getenv/$_ENV population
$dbHost = \Waamini\Config\Env::get('DB_HOST', '127.0.0.1');
$dbPort = (int)\Waamini\Config\Env::get('DB_PORT', 3307);
$dbName = \Waamini\Config\Env::get('DB_NAME', 'waamini');
$dbUser = \Waamini\Config\Env::get('DB_USER', 'root');
$dbPass = \Waamini\Config\Env::get('DB_PASS', '');
$dbSocket = \Waamini\Config\Env::get('DB_SOCKET', '');
$appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));

// Keep timezone consistent for scripts that only include db.php (cron/webhook/legacy endpoints)
$appTz = (string)($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
if ($appTz !== '') {
    @date_default_timezone_set($appTz);
}

// Local-dev safety: fallback from placeholder password to blank root password.
if (
    $appEnv === 'local'
    && in_array((string)$dbHost, ['127.0.0.1', 'localhost'], true)
    && (string)$dbUser === 'root'
    && in_array((string)$dbPass, ['your_secure_db_password_here', 'CHANGE_ME_STRONG_PASSWORD'], true)
) {
    $dbPass = '';
}

mysqli_report(MYSQLI_REPORT_OFF);

// Windows/XAMPP: ignore placeholder DB_SOCKET=MySQL (not a valid socket/pipe path)
$dbSocketTrim = trim((string)$dbSocket);
if (PHP_OS_FAMILY === 'Windows') {
    $sock = strtolower($dbSocketTrim);
    if ($sock === 'mysql' || $sock === 'mysqld' || $sock === 'mariadb') {
        $dbSocketTrim = '';
    }
}

/**
 * Windows/XAMPP: Prefer TCP (127.0.0.1) over hostname "localhost" unless a real socket/pipe path is set.
 * Using "localhost" often triggers named-pipe negotiation and/or IPv6/DNS resolution that can add seconds to every request (high TTFB).
 */
if ($dbSocketTrim === '' && PHP_OS_FAMILY === 'Windows' && strcasecmp((string)$dbHost, 'localhost') === 0) {
    $dbHost = '127.0.0.1';
}

$dbSocket = $dbSocketTrim;

// mysqli_init + options so we can set connect timeout before connecting
$conn = mysqli_init();
if ($conn) {
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    /**
     * Persistent connections (mysqli "p:" host prefix) reuse TCP connections per Apache worker — critical on Windows/XAMPP where new connects are costly.
     * Opt-out with DB_PERSISTENT=0 in .env | Opt-in explicitly with DB_PERSISTENT=1 (same as auto when enabled).
     */
    $dbPersistentEnv = strtolower(trim((string)($_ENV['DB_PERSISTENT'] ?? getenv('DB_PERSISTENT') ?: '')));
    $usePersistent = false;
    if (in_array($dbPersistentEnv, ['1', 'true', 'yes', 'on'], true)) {
        $usePersistent = true;
    } elseif (in_array($dbPersistentEnv, ['0', 'false', 'no', 'off'], true)) {
        $usePersistent = false;
    } else {
        $usePersistent = in_array($appEnv, ['local', 'development'], true)
            || (PHP_OS_FAMILY === 'Windows' && strcasecmp((string)$dbHost, '127.0.0.1') === 0);
    }

    $hostForConnect = (string)$dbHost;
    if ($usePersistent && $hostForConnect !== '' && stripos($hostForConnect, 'p:') !== 0) {
        $hostForConnect = 'p:' . $hostForConnect;
    }

    @mysqli_real_connect(
        $conn,
        $hostForConnect,
        $dbUser,
        $dbPass,
        $dbName,
        $dbPort ?: 3306,
        $dbSocket !== '' ? $dbSocket : null
    );
} else {
    $conn = null;
}

if (!($conn instanceof mysqli) || $conn->connect_error) {
    $dbErr = $conn instanceof mysqli ? $conn->connect_error : 'mysqli initialization failed';
    error_log(sprintf('[WAAMINI DB FATAL] %s', $dbErr));

    if (php_sapi_name() === 'cli') {
        echo "Database connection failed. Ensure XAMPP MySQL is running and .env is configured.\n";
        exit(1);
    }
    http_response_code(503);
    echo 'System temporarily unavailable. Please try again later.';
    exit;
}

// UTF-8MB4 for emoji and full Unicode
@$conn->set_charset('utf8mb4');

// Same SQL mode on every server. Hosts such as Hostinger enable ONLY_FULL_GROUP_BY / STRICT_TRANS_TABLES by
// default; many queries (e.g. "SELECT g.* ... GROUP BY g.id") were written and tested without them and fail there.
// Override with DB_SQL_MODE in .env if needed.
$dbSqlMode = (string)($_ENV['DB_SQL_MODE'] ?? getenv('DB_SQL_MODE') ?: 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION');
if (!preg_match('/^[A-Z_,]*$/', $dbSqlMode)) $dbSqlMode = 'NO_ENGINE_SUBSTITUTION';
@$conn->query("SET SESSION sql_mode = '" . $dbSqlMode . "'");

// MySQL NOW() must use the same clock as PHP date(). Hostinger's MySQL runs in UTC while the app runs in
// Asia/Kolkata; mixed values (run_at from PHP, NOW() from MySQL) made queued AI replies wait ~5.5 hours.
$appTzDb = (string)($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Asia/Kolkata'); // legacy scripts skip bootstrap.php
if (date_default_timezone_get() !== $appTzDb) { @date_default_timezone_set($appTzDb); }
@$conn->query("SET time_zone = '" . date('P') . "'");
