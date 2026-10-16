<?php
/**
 * WAAMINI Generic Queue Daemon (queue_jobs) — no cron dependency
 *
 * Run:
 *   php queue/daemon.php
 *
 * Stop gracefully:
 *   create file: storage/STOP_QUEUE_DAEMON
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/logging.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(0);
ini_set('memory_limit', '512M');

$root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
$stopFile = $root . '/storage/STOP_QUEUE_DAEMON';
$logFile  = $root . '/storage/logs/daemon.log';
$failLog  = $root . '/storage/logs/queue-failures.log';

function qd_log(string $file, array $data): void {
    $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($line)) return;
    wa_log_append($file, $line, 5 * 1024 * 1024, 7);
}

qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'start', 'pid' => getmypid()]);

$chk = $conn->query("SHOW TABLES LIKE 'queue_jobs'");
if (!$chk || (int)$chk->num_rows <= 0) {
    fwrite(STDERR, "queue_jobs table missing. Apply migrations/010_queue_jobs_and_api_logs.sql\n");
    exit(2);
}

$sleepMsIdle = (int)($_ENV['QUEUE_DAEMON_IDLE_MS'] ?? getenv('QUEUE_DAEMON_IDLE_MS') ?: 500);
$batch = (int)($_ENV['QUEUE_DAEMON_BATCH'] ?? getenv('QUEUE_DAEMON_BATCH') ?: 10);
$timeoutSec = (int)($_ENV['QUEUE_JOB_TIMEOUT_SEC'] ?? getenv('QUEUE_JOB_TIMEOUT_SEC') ?: 300);

function qd_lock_job(mysqli $conn, int $id, string $token): bool {
    $st = $conn->prepare("UPDATE queue_jobs
        SET status='processing', locked_at=NOW(), lock_token=?, updated_at=NOW()
        WHERE id=? AND status IN ('pending','retrying')
          AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
    if (!$st) return false;
    $st->bind_param('si', $token, $id);
    $st->execute();
    return (int)$conn->affected_rows === 1;
}

function qd_set_result(mysqli $conn, int $id, string $status, ?string $err = null, ?string $runAt = null): void {
    $status = in_array($status, ['pending','processing','retrying','failed','done'], true) ? $status : 'failed';
    $st = $conn->prepare("UPDATE queue_jobs
        SET status=?, attempts=attempts+1, last_error=?, run_at=?, locked_at=NULL, lock_token=NULL, updated_at=NOW()
        WHERE id=?");
    if (!$st) return;
    $st->bind_param('sssi', $status, $err, $runAt, $id);
    $st->execute();
}

function qd_run_php_script(string $scriptPath): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("define('WAAMINI_QUEUE_WORKER', 1); require " . var_export($scriptPath, true) . ";");
    $descriptorspec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorspec, $pipes, dirname($scriptPath));
    if (!is_resource($proc)) return ['ok' => false, 'exit' => 1, 'out' => '', 'err' => 'proc_open failed'];
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok' => $exit === 0, 'exit' => (int)$exit, 'out' => $out, 'err' => $err];
}

$shutdown = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shutdown, $logFile) {
        $shutdown = true;
        qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'sigterm']);
    });
    pcntl_signal(SIGINT, function () use (&$shutdown, $logFile) {
        $shutdown = true;
        qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'sigint']);
    });
}

$loops = 0;
while (true) {
    if ($shutdown) {
        qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'shutdown']);
        break;
    }
    if (is_file($stopFile)) {
        qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'stop_file']);
        break;
    }

    // Timeout protection: unlock long-running processing jobs (requeue or fail)
    try {
        $stT = $conn->prepare("UPDATE queue_jobs
            SET status = CASE WHEN attempts+1 >= max_attempts THEN 'failed' ELSE 'retrying' END,
                last_error = 'timeout',
                run_at = CASE WHEN attempts+1 >= max_attempts THEN NULL ELSE DATE_ADD(NOW(), INTERVAL 60 SECOND) END,
                locked_at = NULL,
                lock_token = NULL,
                updated_at = NOW()
            WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        if ($stT) {
            $stT->bind_param('i', $timeoutSec);
            $stT->execute();
            $n = (int)$conn->affected_rows;
            if ($n > 0) qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'timeout_recover', 'count' => $n]);
        }
    } catch (\Throwable $e) {}

    $now = date('Y-m-d H:i:s');
    $st = $conn->prepare("SELECT id, type, attempts, max_attempts
        FROM queue_jobs
        WHERE status IN ('pending','retrying')
          AND (run_at IS NULL OR run_at <= ?)
        ORDER BY created_at ASC
        LIMIT ?");
    $st->bind_param('si', $now, $batch);
    $st->execute();
    $res = $st->get_result();

    $processed = 0;
    while ($res && ($job = $res->fetch_assoc())) {
        $id = (int)$job['id'];
        $type = (string)$job['type'];
        $attempts = (int)($job['attempts'] ?? 0);
        $maxAttempts = (int)($job['max_attempts'] ?? 3);

        $token = bin2hex(random_bytes(16));
        if (!qd_lock_job($conn, $id, $token)) continue;

        $ok = false;
        $err = null;

        try {
            switch ($type) {
                case 'cron_automation_sender':
                    $r = qd_run_php_script(APP_ROOT . '/cron_automation_sender.php');
                    $ok = (bool)$r['ok'];
                    $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                    break;
                case 'cron_fee_reminder':
                    $r = qd_run_php_script(APP_ROOT . '/cron_fee_reminder.php');
                    $ok = (bool)$r['ok'];
                    $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                    break;
                case 'cron_pulse_analyzer':
                    $r = qd_run_php_script(APP_ROOT . '/cron_pulse_analyzer.php');
                    $ok = (bool)$r['ok'];
                    $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                    break;
                default:
                    $ok = false;
                    $err = "Unknown job type: {$type}";
            }
        } catch (\Throwable $e) {
            $ok = false;
            $err = $e->getMessage();
        }

        $processed++;
        if ($ok) {
            qd_set_result($conn, $id, 'done', null, null);
            continue;
        }

        $attemptsNext = $attempts + 1;
        $errShort = $err ? substr($err, 0, 2000) : 'job_failed';
        if ($attemptsNext >= $maxAttempts) {
            qd_set_result($conn, $id, 'failed', $errShort, null);
        } else {
            $delaySeconds = min(900, 60 * $attemptsNext);
            $runAt = date('Y-m-d H:i:s', time() + $delaySeconds);
            qd_set_result($conn, $id, 'retrying', $errShort, $runAt);
        }

        qd_log($failLog, [
            'ts' => date('c'),
            'queue' => 'queue_jobs',
            'id' => $id,
            'type' => $type,
            'attempts_next' => $attemptsNext,
            'max_attempts' => $maxAttempts,
            'error' => $errShort,
        ]);
    }

    if ($processed === 0) {
        usleep(max(10, $sleepMsIdle) * 1000);
    }

    $loops++;
    if (($loops % 200) === 0) {
        if (function_exists('gc_collect_cycles')) {
            @gc_collect_cycles();
        }
        qd_log($logFile, [
            'ts' => date('c'),
            'daemon' => 'queue_jobs',
            'event' => 'mem',
            'bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
        ]);
    }
}

qd_log($logFile, ['ts' => date('c'), 'daemon' => 'queue_jobs', 'event' => 'exit']);

