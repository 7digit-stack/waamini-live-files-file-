<?php
/**
 * WAAMINI Pulse Watchdog — automatic monitoring tick (every minute).
 *
 * Chained into process_ai_queue.php (WaaminiWorker scheduled task), so it runs
 * without anyone opening admin/pulse.php. Include-safe: own lock + top-level
 * return. Also runnable standalone: php cron_pulse_watchdog.php
 *
 * Each tick:
 *   1. Checks the waamini-mcp server (/health) and records status in
 *      system_settings (group=monitoring). Raises a deduped Pulse error
 *      while the MCP server is down.
 *   2. Runs the self-heal analyzer when the last run is stale
 *      (respects pulse_auto_heal_enabled / interval settings).
 *   3. Drains due queue_jobs by spawning one queue/worker.php cycle.
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

// Security for HTTP runs (CLI and trusted internal runs pass through)
$wdTrusted = (defined('WAAMINI_TRUSTED_INTERNAL_RUN') && WAAMINI_TRUSTED_INTERNAL_RUN === true);
if (php_sapi_name() !== 'cli' && !$wdTrusted) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !defined('CRON_SECRET_KEY') || !hash_equals((string)CRON_SECRET_KEY, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Lock (include-safe: return, not exit) ──
$wdLockFile = sys_get_temp_dir() . '/waamini_pulse_watchdog.lock';
$wdLock = fopen($wdLockFile, 'c');
if (!$wdLock || !flock($wdLock, LOCK_EX | LOCK_NB)) {
    echo "Watchdog already running\n";
    return;
}

set_time_limit(55);

$wdOut = static function (string $s): void {
    echo '[PULSE WATCHDOG] ' . $s . "\n";
};

$wdSetSetting = static function (string $key, string $value) use ($conn): void {
    $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'monitoring') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group='monitoring'");
    if ($st) {
        $st->bind_param('ss', $key, $value);
        $st->execute();
    }
};

$wdGetSettings = static function (array $keys) use ($conn): array {
    $out = [];
    $in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $keys)) . "'";
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='monitoring' AND setting_key IN ($in)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $out;
};

$wdSummary = [];

// ── 1. MCP server health ──
try {
    $prev = $wdGetSettings(['mcp_last_status']);
    $mcp = waamini_pulse_check_mcp();

    $wdSetSetting('mcp_last_status', $mcp['online'] ? 'online' : 'offline');
    $wdSetSetting('mcp_last_check_at', date('Y-m-d H:i:s'));
    $wdSetSetting('mcp_service', (string)$mcp['service']);
    $wdSetSetting('mcp_last_latency_ms', (string)($mcp['latency_ms'] ?? ''));

    if (!$mcp['online']) {
        waamini_pulse_capture('MCP Offline', 'waamini-mcp health check failed (http://127.0.0.1:8765/health unreachable or unhealthy).', __FILE__, 0);
        $wdSummary[] = 'MCP OFFLINE';
        $wdOut('MCP server is OFFLINE');
    } else {
        if (($prev['mcp_last_status'] ?? '') === 'offline') {
            error_log('[PULSE WATCHDOG] MCP server recovered (back online).');
        }
        $wdSummary[] = 'MCP ok ' . (int)$mcp['latency_ms'] . 'ms';
        $wdOut('MCP online (' . (int)$mcp['latency_ms'] . 'ms, service=' . $mcp['service'] . ')');
    }
} catch (Throwable $e) {
    error_log('[PULSE WATCHDOG] MCP check failed: ' . $e->getMessage());
}

// ── 2. Auto-heal when stale (same rules as admin/pulse.php scheduler) ──
try {
    $settings = $wdGetSettings(['pulse_auto_heal_enabled', 'pulse_auto_heal_interval_minutes']);
    $healEnabled = ((string)($settings['pulse_auto_heal_enabled'] ?? '1') === '1');
    $healInterval = max(5, min(1440, (int)($settings['pulse_auto_heal_interval_minutes'] ?? 60)));

    if ($healEnabled) {
        $lastRunAt = null;
        $res = $conn->query("SELECT MAX(last_run_at) AS last_run FROM pulse_runs");
        if ($res && ($row = $res->fetch_assoc())) {
            $lastRunAt = $row['last_run'] ?? null;
        }
        $isStale = true;
        if ($lastRunAt) {
            $isStale = ((time() - strtotime((string)$lastRunAt)) >= ($healInterval * 60));
        }

        if ($isStale) {
            if (!defined('WAAMINI_QUEUE_WORKER')) {
                define('WAAMINI_QUEUE_WORKER', true);
            }
            if (!defined('WAAMINI_TRUSTED_INTERNAL_RUN')) {
                define('WAAMINI_TRUSTED_INTERNAL_RUN', true);
            }
            ob_start();
            include __DIR__ . '/cron_pulse_analyzer.php';
            ob_end_clean();
            $wdSummary[] = 'self-heal ran';
            $wdOut('Self-heal analyzer executed (stale run detected).');
        } else {
            $wdSummary[] = 'heal fresh';
        }
    } else {
        $wdSummary[] = 'heal disabled';
    }
} catch (Throwable $e) {
    error_log('[PULSE WATCHDOG] Self-heal run failed: ' . $e->getMessage());
    waamini_pulse_capture('Watchdog Error', 'Self-heal run failed: ' . $e->getMessage(), __FILE__, 0);
}

// ── 3. Drain due queue_jobs (spawn one detached worker cycle) ──
try {
    $due = 0;
    $res = $conn->query("SELECT COUNT(*) AS c FROM queue_jobs WHERE status IN ('pending','retrying') AND (run_at IS NULL OR run_at <= NOW())");
    if ($res && ($row = $res->fetch_assoc())) {
        $due = (int)($row['c'] ?? 0);
    }

    if ($due > 0 && !is_file(__DIR__ . '/storage/STOP_QUEUE_DAEMON')) {
        $phpBin = (defined('PHP_BINARY') && PHP_BINARY && stripos(basename(PHP_BINARY), 'php') !== false)
            ? PHP_BINARY
            : 'C:\\xampp\\php\\php.exe';
        $workerPath = __DIR__ . '/queue/worker.php';
        if (is_file($workerPath)) {
            if (PHP_OS_FAMILY === 'Windows') {
                @pclose(@popen('start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($workerPath), 'r'));
            } else {
                @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($workerPath) . ' > /dev/null 2>&1 &');
            }
            $wdSummary[] = "worker spawned ($due due)";
            $wdOut("Spawned queue worker for $due due job(s).");
        }
    } else {
        $wdSummary[] = 'queue idle';
    }
} catch (Throwable $e) {
    error_log('[PULSE WATCHDOG] Queue drain failed: ' . $e->getMessage());
}

// ── Record tick so admin/pulse.php can show the watchdog is alive ──
try {
    $wdSetSetting('watchdog_last_run_at', date('Y-m-d H:i:s'));
    $wdSetSetting('watchdog_last_summary', mb_substr(implode(' | ', $wdSummary), 0, 250));
} catch (Throwable $e) {
    // non-fatal
}

$wdOut('Tick complete: ' . implode(' | ', $wdSummary));

flock($wdLock, LOCK_UN);
fclose($wdLock);
return;
