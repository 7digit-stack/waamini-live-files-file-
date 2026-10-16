<?php
/**
 * WAAMINI - Logging helper with lightweight rotation.
 * Rotation strategy:
 * - rotate when file exceeds max bytes
 * - keep N archives: file.log.1 .. file.log.N
 */

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

function wa_log_rotate(string $path, int $maxBytes = 5242880, int $keep = 5): void {
    $maxBytes = max(65536, $maxBytes);
    $keep = max(1, min(20, $keep));
    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size < $maxBytes) {
        return;
    }

    // Shift archives (N -> N+1)
    for ($i = $keep; $i >= 1; $i--) {
        $from = $path . '.' . $i;
        $to   = $path . '.' . ($i + 1);
        if (is_file($to)) @unlink($to);
        if (is_file($from)) @rename($from, $to);
    }

    @rename($path, $path . '.1');
}

/**
 * Append a JSON line (or plain string) with rotation.
 */
function wa_log_append(string $path, string $line, int $maxBytes = 5242880, int $keep = 5): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    wa_log_rotate($path, $maxBytes, $keep);
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

