<?php
/**
 * Waamini Application Bootstrap
 * Starts secure session, loads environment, sets security headers.
 */

// Fix for Windows XAMPP: PCRE JIT memory allocation often fails in some PHP environments.
@ini_set('pcre.jit', '0');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

// 2. Error & Output Buffering Control (Prevent partial output on errors)
ini_set('display_errors', '0'); // NEVER display errors in production
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Use a flag file to avoid repeated is_dir() filesystem calls on every request.
// Directories are created once; after that we skip the check entirely.
$_storageFlagFile = APP_ROOT . '/storage/.initialized';
if (!file_exists($_storageFlagFile)) {
    @mkdir(APP_ROOT . '/storage/logs', 0775, true);
    @mkdir(APP_ROOT . '/storage/sessions', 0775, true);
    @file_put_contents($_storageFlagFile, date('Y-m-d H:i:s'));
}
unset($_storageFlagFile);

ini_set('session.save_path', APP_ROOT . '/storage/sessions');
ini_set('error_log', APP_ROOT . '/storage/logs/error.log');
ob_start();

// 3. Load Environment Variables
require_once APP_ROOT . '/config/env.php';
\Waamini\Config\Env::load(APP_ROOT . '/.env');

// Ensure consistent timezone across the app (affects date(), strtotime(), etc.)
// Default to India timezone for this project if not set.
$appTz = (string)\Waamini\Config\Env::get('APP_TIMEZONE', 'Asia/Kolkata');
if ($appTz !== '') {
    @date_default_timezone_set($appTz);
}

// 4. Secure Session Configuration
// Detect HTTPS dynamically: direct SSL, standard port, or proxy headers (ngrok, Cloudflare, etc.)
$envSecure = filter_var(\Waamini\Config\Env::get('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN);
$isHTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
$secure = $envSecure || $isHTTPS;
$httponly = filter_var(\Waamini\Config\Env::get('SESSION_HTTPONLY', 'true'), FILTER_VALIDATE_BOOLEAN);
$samesite = \Waamini\Config\Env::get('SESSION_SAMESITE', 'Lax');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
$cookieDomain = ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost') ? $host : '';

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => (int)\Waamini\Config\Env::get('SESSION_LIFETIME', '3600'),
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite
    ]);
} else {
    session_set_cookie_params((int)\Waamini\Config\Env::get('SESSION_LIFETIME', '3600'), '/; samesite='.$samesite, $cookieDomain, $secure, $httponly);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['_csp_nonce'])) {
    $_SESSION['_csp_nonce'] = base64_encode(random_bytes(16));
}

// Check for remember me cookie if not logged in
if (empty($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
    require_once APP_ROOT . '/db.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $parts = explode(':', $_COOKIE['remember_me']);
        if (count($parts) === 2) {
            $selector = $parts[0];
            $token = $parts[1];
            
            $stmt = $conn->prepare("SELECT ut.id as token_id, ut.token, u.id, u.name, u.company_id, u.role, u.status FROM user_tokens ut JOIN users u ON ut.user_id = u.id WHERE ut.selector = ? AND ut.expires_at > NOW() AND u.status = 'active'");
            if ($stmt) {
                $stmt->bind_param("s", $selector);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                
                if ($res && hash_equals($res['token'], hash('sha256', $token))) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$res['id'];
                    $_SESSION['user_name'] = (string)$res['name'];
                    $_SESSION['company_id'] = (int)$res['company_id'];
                    $_SESSION['role'] = (string)$res['role'];
                    
                    // Rotate token for security
                    $newToken = bin2hex(random_bytes(32));
                    $hashedToken = hash('sha256', $newToken);
                    $rememberTime = isset($_ENV['REMEMBER_ME_LIFETIME']) ? max(86400, (int)$_ENV['REMEMBER_ME_LIFETIME']) : 2592000;
                    $newExpiry = date('Y-m-d H:i:s', time() + $rememberTime);
                    
                    $upd = $conn->prepare("UPDATE user_tokens SET token = ?, expires_at = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("ssi", $hashedToken, $newExpiry, $res['token_id']);
                        $upd->execute();
                    }
                    
                    setcookie('remember_me', $selector . ':' . $newToken, time() + $rememberTime, '/', '', $secure, true);
                } else {
                    setcookie('remember_me', '', time() - 3600, '/', '', $secure, true);
                }
            }
        }
    }
}

// 5. Global Security Headers (Standard)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");


// 6. Autoload Core Classes (Simple PSR-4 simulation if needed, or manual includes)
spl_autoload_register(function ($class) {
    $prefix = 'Waamini\\';
    $base_dir = APP_ROOT . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

// 7. Global Helper Functions
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/pulse_monitor.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log(sprintf('[WAAMINI PHP ERROR] %s in %s:%d', $message, $file, $line));
    $pulseType = waamini_pulse_severity_type($severity);
    if ($pulseType !== null) {
        waamini_pulse_capture($pulseType, $message, $file, $line);
    }
    return true;
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('[WAAMINI EXCEPTION] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    waamini_pulse_capture('Uncaught Exception', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (php_sapi_name() !== 'cli') {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (strpos($accept, 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
            return;
        }
        echo 'An internal error occurred.';
    }
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf('[WAAMINI FATAL] %s in %s:%d', $error['message'] ?? 'Unknown fatal', $error['file'] ?? 'unknown', (int)($error['line'] ?? 0)));
    waamini_pulse_capture('Fatal Error', (string)($error['message'] ?? 'Unknown fatal'), (string)($error['file'] ?? ''), (int)($error['line'] ?? 0));

    if (php_sapi_name() === 'cli' || headers_sent()) {
        return;
    }

    http_response_code(500);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
        return;
    }

    echo 'An internal error occurred.';
});
