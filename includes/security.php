<?php
/**
 * WAAMINI - Security Helpers
 * Central CSRF, sanitization, simple rate limiting, and security utilities.
 */

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/**
 * Global Security Headers
 */
if (!headers_sent()) {
    // 1. Prevent Clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // 2. Prevent MIME-sniffing
    header('X-Content-Type-Options: nosniff');
    // 3. Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // 4. Content Security Policy (Relaxed for compatibility)
    if (empty($_SESSION['_csp_nonce'])) {
        $_SESSION['_csp_nonce'] = bin2hex(random_bytes(16));
    }
    $nonce = $_SESSION['_csp_nonce'];
    /* 
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'nonce-$nonce' https://cdn.jsdelivr.net https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-src 'self' https://www.google.com; connect-src 'self' https://www.google-analytics.com;");
    */
}

function csrf_get_token(): string {
    if (empty($_SESSION['csrf_token']) || time() > (int)($_SESSION['csrf_expires'] ?? 0)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_expires'] = time() + 3600;
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_token(): string {
    return csrf_get_token();
}

function csrf_field(): string {
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(?string $providedToken = null): bool {
    $token = $providedToken;

    if ($token === null) {
        $jsonBody = null;
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $jsonBody = $decoded;
                }
            }
        }

        $token = $_POST['_token']
            ?? $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($jsonBody['_token'] ?? null)
            ?? ($jsonBody['csrf_token'] ?? null);
    }

    if (!is_string($token) || $token === '' || !hash_equals(csrf_get_token(), $token)) {
        http_response_code(403);
        if (php_sapi_name() !== 'cli') {
            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
            if (strpos($accept, 'application/json') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh the page.']);
                exit;
            }
            echo 'Security validation failed. Please refresh the page.';
            exit;
        }
        return false;
    }

    return true;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitize_email(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: '';
}

function sanitize_phone(string $phone): string {
    $phone = trim($phone);
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    $normalized = ltrim($digits, '0');
    if ($normalized === '') {
        return '';
    }
    return '+' . $normalized;
}

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_phone(string $phone): bool {
    $phone = preg_replace('/[^\d+]/', '', $phone ?? '');
    return is_string($phone) && preg_match('/^\+[1-9]\d{9,14}$/', $phone) === 1;
}

function rate_limit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): array {
    $rlKey = 'rl_' . md5($key);
    $now = time();

    if (!isset($_SESSION[$rlKey]) || !is_array($_SESSION[$rlKey])) {
        $_SESSION[$rlKey] = ['attempts' => 0, 'start_time' => $now];
    }

    $data = &$_SESSION[$rlKey];

    if (($now - (int)$data['start_time']) > $windowSeconds) {
        $data = ['attempts' => 0, 'start_time' => $now];
    }

    $attempts = (int)$data['attempts'];
    $allowed = $attempts < $maxAttempts;

    return [
        'allowed' => $allowed,
        'attempts' => $attempts,
        'remaining' => max(0, $maxAttempts - $attempts),
        'retry_after' => $allowed ? 0 : max(0, $windowSeconds - ($now - (int)$data['start_time']))
    ];
}

function rate_limit_hit(string $key): void {
    $rlKey = 'rl_' . md5($key);
    if (!isset($_SESSION[$rlKey]) || !is_array($_SESSION[$rlKey])) {
        $_SESSION[$rlKey] = ['attempts' => 0, 'start_time' => time()];
    }
    $_SESSION[$rlKey]['attempts'] = (int)$_SESSION[$rlKey]['attempts'] + 1;
}

function rate_limit_clear(string $key): void {
    unset($_SESSION['rl_' . md5($key)]);
}

function get_client_ip(): string {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') {
            continue;
        }
        $ips = explode(',', (string)$value);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function ip_check_lockout(): void {
    global $conn;
    if (!($conn instanceof mysqli)) {
        return;
    }

    $ip = get_client_ip();
    // Never lock out localhost (tests/dev)
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return;
    }
    $maxAttempts = 10;
    $lockoutMinutes = 15;

    $stmt = $conn->prepare('SELECT attempts, last_attempt FROM ip_lockouts WHERE ip_address = ?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res && (int)$res['attempts'] >= $maxAttempts) {
        $lastTime = strtotime((string)$res['last_attempt']);
        if ($lastTime !== false && (time() - $lastTime) < ($lockoutMinutes * 60)) {
            http_response_code(429);
            echo 'Too many failed attempts. Try again later.';
            exit;
        }

        $del = $conn->prepare('DELETE FROM ip_lockouts WHERE ip_address = ?');
        if ($del) {
            $del->bind_param('s', $ip);
            $del->execute();
        }
    }
}

function ip_log_failed_attempt(): void {
    global $conn;
    if (!($conn instanceof mysqli)) {
        return;
    }

    $ip = get_client_ip();
    // Never track localhost in DB lockouts (tests/dev)
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return;
    }
    $stmt = $conn->prepare(
        'INSERT INTO ip_lockouts (ip_address, attempts, last_attempt) VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()'
    );
    if ($stmt) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }
}

function ip_clear_failed_attempts(): void {
    global $conn;
    if (!($conn instanceof mysqli)) {
        return;
    }

    $ip = get_client_ip();
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return;
    }
    $del = $conn->prepare('DELETE FROM ip_lockouts WHERE ip_address = ?');
    if ($del) {
        $del->bind_param('s', $ip);
        $del->execute();
    }
}

/**
 * Default session cookie/browser lifetime (seconds). Matches bootstrap SESSION_LIFETIME.
 */
function waamini_default_session_lifetime_seconds(): int {
    $v = $_ENV['SESSION_LIFETIME'] ?? getenv('SESSION_LIFETIME');
    if ($v !== false && $v !== null && (string)$v !== '') {
        return max(300, (int)$v);
    }
    return 3600;
}

/**
 * "Remember me" cookie lifetime (seconds). Env REMEMBER_ME_LIFETIME, default 30 days.
 */
function waamini_remember_me_lifetime_seconds(): int {
    $v = $_ENV['REMEMBER_ME_LIFETIME'] ?? getenv('REMEMBER_ME_LIFETIME');
    if ($v !== false && $v !== null && (string)$v !== '') {
        return max(86400, (int)$v);
    }
    return 2592000;
}

/**
 * Re-send the session cookie with a new expiry (after login / session_regenerate_id).
 * Also raises session.gc_maxlifetime so the server-side session file is not garbage-collected early.
 */
function waamini_refresh_session_cookie(int $lifetimeSeconds): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $sid = session_id();
    if ($sid === '') {
        return;
    }
    $lifetimeSeconds = max(300, $lifetimeSeconds);
    $params = session_get_cookie_params();
    $name = session_name();
    $expires = time() + $lifetimeSeconds;

    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $sid, [
            'expires' => $expires,
            'path' => ($params['path'] !== '' && $params['path'] !== null) ? $params['path'] : '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie(
            $name,
            $sid,
            $expires,
            ($params['path'] !== '' && $params['path'] !== null) ? $params['path'] : '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    @ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);
}

if (!class_exists('Security')) {
    class Security {
        public static function escape(string $output): string {
            return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        }

        public static function sanitize(string $input): string {
            return sanitize($input);
        }

        public static function validateEmail(string $email): bool {
            return validate_email($email);
        }

        public static function validatePhone(string $phone): bool {
            return validate_phone($phone);
        }

        public static function sanitizePhone(string $phone): string {
            return sanitize_phone($phone);
        }

        public static function hashPassword(string $password): string {
            return password_hash($password, PASSWORD_DEFAULT);
        }

        public static function verifyPassword(string $password, string $hash): bool {
            return password_verify($password, $hash);
        }

        public static function passwordNeedsRehash(string $hash): bool {
            return password_needs_rehash($hash, PASSWORD_DEFAULT);
        }

        public static function generateToken(int $bytes = 32): string {
            return bin2hex(random_bytes(max(16, $bytes)));
        }

        public static function generateRandomString(int $length = 16): string {
            $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $maxIdx = strlen($alphabet) - 1;
            $buffer = '';
            for ($i = 0; $i < $length; $i++) {
                $buffer .= $alphabet[random_int(0, $maxIdx)];
            }
            return $buffer;
        }

        public static function initializeCsrf(): void {
            csrf_get_token();
        }

        public static function csrfField(): string {
            return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') . '">';
        }

        public static function csrfMeta(): string {
            return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') . '">';
        }

        public static function init(): void {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
            self::initializeCsrf();
        }

        public static function getCsrfToken(): string {
            return csrf_get_token();
        }

        public static function validateCsrf(string $token): bool {
            if ($token === '') {
                return false;
            }
            return hash_equals(csrf_get_token(), $token);
        }

        public static function validateMethod(string $method): bool {
            $current = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            return $current === strtoupper($method);
        }

        public static function isAjax(): bool {
            $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
            if ($requestedWith === 'xmlhttprequest') {
                return true;
            }

            $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
            if (strpos($contentType, 'application/json') !== false) {
                return true;
            }

            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
            return strpos($accept, 'application/json') !== false;
        }

        public static function hasSqlInjection(string $input): bool {
            $pattern = '/(\bUNION\b|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|--|#|;|\bOR\b\s+\d+=\d+|\bAND\b\s+\d+=\d+)/i';
            return preg_match($pattern, $input) === 1;
        }

        public static function getClientIp(): string {
            return get_client_ip();
        }

        public static function isHttps(): bool {
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                return true;
            }
            return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        }

        public static function encrypt(string $plainText): string {
            $key = self::getEncryptionKey();
            $iv = random_bytes(16);
            $cipher = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($cipher === false) {
                throw new RuntimeException('Failed to encrypt data.');
            }
            return base64_encode($iv . $cipher);
        }

        public static function decrypt(string $payload): string {
            $decoded = base64_decode($payload, true);
            if ($decoded === false || strlen($decoded) < 17) {
                return '';
            }
            $iv = substr($decoded, 0, 16);
            $cipherText = substr($decoded, 16);
            $plain = openssl_decrypt($cipherText, 'AES-256-CBC', self::getEncryptionKey(), OPENSSL_RAW_DATA, $iv);
            return $plain === false ? '' : $plain;
        }

        private static function getEncryptionKey(): string {
            $envKey = $_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY') ?: '';
            if ($envKey === '' || $envKey === 'CHANGE_ME_32_CHAR_SECRET_KEY!!!') {
                $envKey = 'waamini-default-local-dev-key-change-this';
            }
            return hash('sha256', $envKey, true);
        }
    }
}
