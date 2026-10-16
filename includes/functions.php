<?php
/**
 * WAAMINI - Global Helper Functions
 *
 * IMPORTANT: This file is loaded by config/bootstrap.php on EVERY request
 * (web, CLI, cron, webhook). Any helper that must be globally available
 * should be loaded here.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────────────────────────────────────
// Core encryption + WhatsApp token helpers — guaranteed globally available.
// These are needed by webhook.php, cron_*.php, QueueWorker, catalog checkout,
// and 10+ other files. Loading here once prevents "Call to undefined function"
// errors when any of those files forget their own require_once.
// ──────────────────────────────────────────────────────────────────────────────
(static function (): void {
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);

    $enc = $root . '/services/EncryptionService.php';
    if (file_exists($enc)) {
        require_once $enc;
    }

    $waSett = $root . '/includes/whatsapp_settings.php';
    if (file_exists($waSett)) {
        require_once $waSett;
    }

    $featCheck = $root . '/includes/feature_check.php';
    if (file_exists($featCheck)) {
        require_once $featCheck;
    }
})();

if (!function_exists('dd')) {
    /**
     * Dump and Die
     */
    function dd($var): void {
        echo '<pre>';
        var_dump($var);
        echo '</pre>';
        die;
    }
}

if (!function_exists('jsonResponse')) {
    /**
     * Send JSON Response
     */
    function jsonResponse(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('redirect')) {
    /**
     * Secure Redirect
     */
    function redirect(string $url): void {
        // Prevent open redirects
        $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: '';
        if (strpos($url, '://') !== false && ($appUrl === '' || strpos($url, $appUrl) !== 0)) {
            $url = '/';
        }
        header("Location: {$url}");
        exit;
    }
}

if (!function_exists('formatPhone')) {
    /**
     * Format phone for display
     */
    function formatPhone(string $phone): string {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($phone) > 8) {
            return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
        }
        return $phone;
    }
}

if (!function_exists('timeAgo')) {
    /**
     * Human readable time difference
     */
    function timeAgo(string $datetime): string {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';

        return 'just now';
    }
}

if (!function_exists('sanitizeOutput')) {
    /**
     * Recursive array sanitizer for output
     */
    function sanitizeOutput(array $data): array {
        return array_map(function($value) {
            if (is_array($value)) return sanitizeOutput($value);
            if (is_string($value)) return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            return $value;
        }, $data);
    }
}

if (!function_exists('assert_company_owns')) {
    /**
     * Multi-tenant IDOR guard.
     *
     * Verifies that the row $id in $table belongs to $companyId before a caller
     * mutates it. Aborts with 403 when it does not, so a request can never touch
     * another company's data. The table name is restricted to a strict identifier
     * whitelist because it is interpolated into SQL (identifiers cannot be bound).
     */
    function assert_company_owns($conn, string $table, int $id, int $companyId): bool {
        $abort = static function (int $code, string $message): void {
            if (!headers_sent()) {
                http_response_code($code);
            }
            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
            if (strpos($accept, 'application/json') !== false) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'message' => $message]);
            } else {
                echo $message;
            }
            exit;
        };

        if (!($conn instanceof mysqli) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) || $id <= 0 || $companyId <= 0) {
            $abort(403, 'Not authorised.');
        }

        $stmt = $conn->prepare("SELECT 1 FROM `{$table}` WHERE id=? AND company_id=? LIMIT 1");
        if (!$stmt) {
            error_log(sprintf('[WAAMINI assert_company_owns] prepare failed: %s', $conn->error));
            $abort(500, 'An internal error occurred.');
        }

        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = $res instanceof mysqli_result && $res->num_rows > 0;
        $stmt->close();

        if (!$ok) {
            $abort(403, 'Not authorised.');
        }

        return true;
    }
}
