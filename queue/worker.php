<?php
/**
 * WAAMINI Generic Queue Worker (DB-backed)
 *
 * Run:
 *   php queue/worker.php
 * or via cron:
 *   * * * * * php /path/to/waamini/queue/worker.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/logging.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(300);

function q_now(): string { return date('Y-m-d H:i:s'); }

function q_lock_job(mysqli $conn, int $id, string $token): bool {
    $st = $conn->prepare("UPDATE queue_jobs
        SET status='processing', locked_at=NOW(), lock_token=?, updated_at=NOW()
        WHERE id=? AND status IN ('pending','retrying')
          AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
    if (!$st) return false;
    $st->bind_param('si', $token, $id);
    $st->execute();
    return (int)$conn->affected_rows === 1;
}

function q_set_result(mysqli $conn, int $id, string $status, ?string $err = null, ?string $runAt = null): void {
    $status = in_array($status, ['pending','processing','retrying','failed','done'], true) ? $status : 'failed';
    $st = $conn->prepare("UPDATE queue_jobs
        SET status=?, attempts=attempts+1, last_error=?, run_at=?, locked_at=NULL, lock_token=NULL, updated_at=NOW()
        WHERE id=?");
    if (!$st) return;
    $st->bind_param('sssi', $status, $err, $runAt, $id);
    $st->execute();
}

function q_run_php_script(string $scriptPath, array $env = []): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("define('WAAMINI_QUEUE_WORKER', 1); require " . var_export($scriptPath, true) . ";");
    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes, dirname($scriptPath), $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'exit' => 1, 'out' => '', 'err' => 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok' => $exit === 0, 'exit' => (int)$exit, 'out' => $out, 'err' => $err];
}

// Ensure table exists (no fatal if migration not applied yet)
$chk = $conn->query("SHOW TABLES LIKE 'queue_jobs'");
if (!$chk || (int)$chk->num_rows <= 0) {
    fwrite(STDERR, "queue_jobs table missing. Apply migrations/010_queue_jobs_and_api_logs.sql\n");
    exit(2);
}

$limit = 25;
$now = q_now();
$st = $conn->prepare("SELECT id, type, payload_json, attempts, max_attempts
    FROM queue_jobs
    WHERE status IN ('pending','retrying')
      AND (run_at IS NULL OR run_at <= ?)
    ORDER BY created_at ASC
    LIMIT ?");
$st->bind_param('si', $now, $limit);
$st->execute();
$res = $st->get_result();

$processed = 0;
while ($res && ($job = $res->fetch_assoc())) {
    $id = (int)$job['id'];
    $type = (string)$job['type'];
    $attempts = (int)($job['attempts'] ?? 0);
    $maxAttempts = (int)($job['max_attempts'] ?? 3);

    $token = bin2hex(random_bytes(16));
    if (!q_lock_job($conn, $id, $token)) {
        continue;
    }

    $payload = [];
    $pj = $job['payload_json'] ?? null;
    if (is_string($pj) && $pj !== '') {
        $decoded = json_decode($pj, true);
        if (is_array($decoded)) $payload = $decoded;
    } elseif (is_array($pj)) {
        $payload = $pj;
    }

    $ok = false;
    $err = null;

    try {
        switch ($type) {
            case 'cron_automation_sender':
                $r = q_run_php_script(APP_ROOT . '/cron_automation_sender.php');
                $ok = (bool)$r['ok'];
                $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                break;
            case 'cron_fee_reminder':
                $r = q_run_php_script(APP_ROOT . '/cron_fee_reminder.php');
                $ok = (bool)$r['ok'];
                $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                break;
            case 'cron_pulse_analyzer':
                $r = q_run_php_script(APP_ROOT . '/cron_pulse_analyzer.php');
                $ok = (bool)$r['ok'];
                $err = trim(($r['err'] ?? '') . "\n" . ($r['out'] ?? ''));
                break;
            default:
                $ok = false;
                $err = "Unknown job type: {$type}";
        }
    } catch (Throwable $e) {
        $ok = false;
        $err = $e->getMessage();
    }

    $processed++;
    if ($ok) {
        q_set_result($conn, $id, 'done', null, null);
        continue;
    }

    $attemptsNext = $attempts + 1;
    $errShort = $err ? substr($err, 0, 2000) : 'job_failed';
    if ($attemptsNext >= $maxAttempts) {
        q_set_result($conn, $id, 'failed', $errShort, null);
    } else {
        $delaySeconds = min(900, 60 * $attemptsNext); // 60s, 120s, 180s... up to 15m
        $runAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        q_set_result($conn, $id, 'retrying', $errShort, $runAt);
    }

    // Monitoring log for failed jobs
    try {
        $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
        $path = $root . '/storage/logs/queue-failures.log';
        $line = json_encode([
            'ts' => date('c'),
            'queue' => 'queue_jobs',
            'id' => $id,
            'type' => $type,
            'attempts_next' => $attemptsNext,
            'max_attempts' => $maxAttempts,
            'error' => $errShort,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($line)) wa_log_append($path, $line, 5 * 1024 * 1024, 7);
    } catch (\Throwable $e) {}
}

echo "queue worker done: processed={$processed} at " . q_now() . PHP_EOL;

