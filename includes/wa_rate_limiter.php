<?php
/**
 * WAAMINI - File-based token bucket rate limiter for WhatsApp API
 * Safe for single-daemon worker on shared hosting.
 */

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

class WaaminiRateLimitedException extends RuntimeException {
    public int $retryAfterMs;
    public function __construct(string $message, int $retryAfterMs) {
        parent::__construct($message);
        $this->retryAfterMs = $retryAfterMs;
    }
}

function wa_rl_dir(): string {
    $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
    $dir = $root . '/storage/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Token bucket:
 * - capacity: burst
 * - refillRate: tokens per second
 */
function wa_rl_take(string $key, float $refillRate, int $burst = 10): void {
    $refillRate = max(0.0, $refillRate);
    $burst = max(1, $burst);
    $path = wa_rl_dir() . '/' . hash('sha256', $key) . '.json';
    $lockPath = $path . '.lock';

    $now = microtime(true);
    $state = ['t' => $now, 'tokens' => (float)$burst];

    // Deterministic local test override
    $env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    $force = (string)($_ENV['WAAMINI_TEST_RATE_LIMIT'] ?? getenv('WAAMINI_TEST_RATE_LIMIT') ?: '');
    if ($env === 'local' && in_array(strtolower($force), ['1','true','yes','on'], true)) {
        throw new WaaminiRateLimitedException('rate_limited_test', 500);
    }

    // File locking to avoid race conditions across multiple worker processes
    $lockFp = @fopen($lockPath, 'c+');
    if (is_resource($lockFp)) {
        @flock($lockFp, LOCK_EX);
    }
    try {
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['t'], $decoded['tokens'])) {
                $state['t'] = (float)$decoded['t'];
                $state['tokens'] = (float)$decoded['tokens'];
            }
        }

        $elapsed = max(0.0, $now - (float)$state['t']);
        $tokens = min((float)$burst, (float)$state['tokens'] + ($elapsed * $refillRate));

        if ($refillRate <= 0.0) {
            // Hard block (used in tests or emergency)
            throw new WaaminiRateLimitedException('rate_limited', 1000);
        }

        if ($tokens < 1.0) {
            $needed = 1.0 - $tokens;
            $retry = (int)ceil(($needed / $refillRate) * 1000);
            throw new WaaminiRateLimitedException('rate_limited', max(50, min(5000, $retry)));
        }

        $tokens -= 1.0;
        $state = ['t' => $now, 'tokens' => $tokens];

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        @file_put_contents($tmp, json_encode($state), LOCK_EX);
        @rename($tmp, $path);
    } finally {
        if (is_resource($lockFp)) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    }
}

