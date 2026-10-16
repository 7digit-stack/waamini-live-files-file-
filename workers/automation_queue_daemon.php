<?php
/**
 * WAAMINI Automation Queue Daemon (no cron dependency)
 *
 * Run:
 *   php workers/automation_queue_daemon.php
 *
 * Stop gracefully:
 *   create file: storage/STOP_AUTOMATION_DAEMON
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/QueueWorker.php';
require_once __DIR__ . '/../includes/flow/worker.php';
require_once __DIR__ . '/../includes/logging.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(0);
ini_set('memory_limit', '512M');

$root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
$stopFile = $root . '/storage/STOP_AUTOMATION_DAEMON';
$logFile  = $root . '/storage/logs/daemon.log';

function daemon_log(string $file, array $data): void {
    $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($line)) return;
    wa_log_append($file, $line, 5 * 1024 * 1024, 7);
}

daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'start', 'pid' => getmypid()]);

$worker = new QueueWorker($conn);
$sleepMsIdle = (int)($_ENV['AUTOMATION_DAEMON_IDLE_MS'] ?? getenv('AUTOMATION_DAEMON_IDLE_MS') ?: 250);
$batch = (int)($_ENV['AUTOMATION_DAEMON_BATCH'] ?? getenv('AUTOMATION_DAEMON_BATCH') ?: 75);
$stuckMinutes = (int)($_ENV['AUTOMATION_STUCK_MINUTES'] ?? getenv('AUTOMATION_STUCK_MINUTES') ?: 10);

$shutdown = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shutdown, $logFile) {
        $shutdown = true;
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'sigterm']);
    });
    pcntl_signal(SIGINT, function () use (&$shutdown, $logFile) {
        $shutdown = true;
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'sigint']);
    });
}

$loops = 0;
while (true) {
    if ($shutdown) {
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'shutdown']);
        break;
    }
    if (is_file($stopFile)) {
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'stop_file']);
        break;
    }

    // Timeout protection: reset stuck processing jobs
    try {
        $st = $conn->prepare("UPDATE automation_queue
            SET status='retrying', scheduled_at=DATE_ADD(NOW(), INTERVAL 60 SECOND), last_error='timeout_reset'
            WHERE status='processing' AND (processed_at IS NULL OR processed_at='0000-00-00 00:00:00')
              AND (
                (processing_started_at IS NOT NULL AND processing_started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
                OR (processing_started_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
              )");
        if ($st) {
            $st->bind_param('ii', $stuckMinutes, $stuckMinutes);
            $st->execute();
            $n = (int)$conn->affected_rows;
            if ($n > 0) {
                daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'reset_stuck', 'count' => $n]);
            }
        }
    } catch (\Throwable $e) {}

    $t0 = microtime(true);
    $worker->process($batch);
    FlowJobWorker::process($conn, $batch);
    $dt = microtime(true) - $t0;
    $loops++;

    if ($dt >= 1.0) {
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'slow_loop', 'seconds' => round($dt, 3)]);
    }

    // Leak prevention: periodic GC and memory telemetry
    if (($loops % 200) === 0) {
        if (function_exists('gc_collect_cycles')) {
            @gc_collect_cycles();
        }
        $mem = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'mem', 'bytes' => $mem, 'peak_bytes' => $peak]);
    }

    usleep(max(10, $sleepMsIdle) * 1000);
}

daemon_log($logFile, ['ts' => date('c'), 'daemon' => 'automation_queue', 'event' => 'exit']);

