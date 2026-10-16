<?php
/**
 * WAAMINI - Full-page cache for public pages (HTML)
 * - GET only, no session-auth pages
 * - Safe default: bypass when logged in, POST, or query contains sensitive params
 */

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

function wa_page_cache_dir(): string {
    $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
    $dir = $root . '/storage/page-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function wa_page_cache_key(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // normalize: remove fragments not present anyway; keep query (public pages use it rarely)
    $key = $proto . '://' . $host . $uri;
    return hash('sha256', $key);
}

function wa_page_cache_should_bypass(): bool {
    if (PHP_SAPI === 'cli') return true;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') return true;

    // never cache when logged in (client/admin)
    if (!empty($_SESSION['user_id'])) return true;

    // avoid caching anything that looks like auth/reset/token flows
    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    foreach (['login', 'register', 'logout', 'reset', 'verify', 'forgot', 'webhook', '/api/'] as $needle) {
        if (strpos($uri, $needle) !== false) return true;
    }

    return false;
}

function wa_page_cache_try_serve(int $ttlSeconds = 60): bool {
    if (wa_page_cache_should_bypass()) return false;
    $ttlSeconds = max(1, $ttlSeconds);
    $path = wa_page_cache_dir() . '/' . wa_page_cache_key() . '.html';
    if (!is_file($path)) return false;
    $mtime = @filemtime($path);
    if (!$mtime || (time() - (int)$mtime) > $ttlSeconds) return false;
    $html = @file_get_contents($path);
    if (!is_string($html) || $html === '') return false;

    header('X-WA-Cache: HIT');
    echo $html;
    return true;
}

function wa_page_cache_start(int $ttlSeconds = 60): void {
    if (wa_page_cache_should_bypass()) return;
    if (headers_sent()) return;

    if (wa_page_cache_try_serve($ttlSeconds)) {
        exit;
    }

    ob_start(function (string $buffer) use ($ttlSeconds) {
        // only store successful HTML responses
        $code = http_response_code();
        if ($code >= 200 && $code < 300 && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
            $path = wa_page_cache_dir() . '/' . wa_page_cache_key() . '.html';
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            @file_put_contents($tmp, $buffer, LOCK_EX);
            @rename($tmp, $path);
            header('X-WA-Cache: MISS');
        }
        return $buffer;
    });
}

