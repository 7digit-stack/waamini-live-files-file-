<?php
/** Executes delayed Flow Engine jobs without blocking a PHP request. */
require_once __DIR__ . '/engine.php';

final class FlowJobWorker
{
    public static function process(mysqli $conn, int $limit = 50): int
    {
        flow_ensure_schema($conn);
        FlowEngine::expireIdleSessions($conn);
        $limit = max(1, min(200, $limit)); $done = 0;
        $st = $conn->prepare("SELECT id FROM flow_jobs WHERE status='pending' AND run_at<=NOW() ORDER BY run_at ASC LIMIT ?");
        $st->bind_param('i', $limit); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $claim = $conn->prepare("UPDATE flow_jobs SET status='running', attempts=attempts+1 WHERE id=? AND status='pending'");
            $claim->bind_param('i', $id); $claim->execute(); $won = $conn->affected_rows === 1; $claim->close();
            if (!$won) continue;
            $jobSt = $conn->prepare("SELECT * FROM flow_jobs WHERE id=? AND status='running' LIMIT 1"); $jobSt->bind_param('i', $id); $jobSt->execute(); $job = $jobSt->get_result()->fetch_assoc(); $jobSt->close();
            if (!$job) continue;
            try {
                $contactSt = $conn->prepare('SELECT phone FROM contacts WHERE id=? AND company_id=? LIMIT 1');
                $contactSt->bind_param('ii', $job['contact_id'], $job['company_id']); $contactSt->execute(); $contact = $contactSt->get_result()->fetch_assoc(); $contactSt->close();
                if (!$contact || trim((string)$contact['phone']) === '') throw new RuntimeException('contact_not_found');
                $sender = new FlowWaSender($conn, (int)$job['company_id'], (int)$job['contact_id'], (string)$contact['phone']);
                $ok = (new FlowEngine($conn, (int)$job['company_id'], (int)$job['contact_id'], $sender))->runJob($job);
                $u = $conn->prepare("UPDATE flow_jobs SET status=?, error=NULL WHERE id=?"); $status = $ok ? 'done' : 'failed'; $u->bind_param('si', $status, $id); $u->execute(); $u->close();
                $done++;
            } catch (Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 255);
                $u = $conn->prepare("UPDATE flow_jobs SET status='failed', error=? WHERE id=?"); $u->bind_param('si', $err, $id); $u->execute(); $u->close();
                error_log('[FlowJobWorker] ' . $err);
            }
        }
        return $done;
    }
}

/**
 * Start the background tick (cron_flow_tick.php) without waiting for it, at most once every 45 seconds.
 * Called when a customer message arrives, so overdue delays, "no reply" follow-ups and the chat auto follow-up are
 * processed even where the scheduled task is slow or missing. The scheduled task / open dashboard remain the main triggers.
 */
if (!function_exists('flow_kick_worker')) {
    function flow_kick_worker(): void
    {
        try {
            $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waamini_flow_kick.stamp';
            if (@is_file($stamp) && (time() - (int)@filemtime($stamp)) < 45) return;
            @touch($stamp);
            $script = realpath(__DIR__ . '/../../cron_flow_tick.php');
            if (!$script) return;
            $php = 'php';
            foreach (['C:\xampp\php\php.exe', 'D:\xampp\php\php.exe', PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe', PHP_BINDIR . DIRECTORY_SEPARATOR . 'php'] as $cand) {
                if (@is_file($cand)) { $php = $cand; break; }
            }
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $h = @popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > NUL 2>&1', 'r');
                if (is_resource($h)) @pclose($h);
            } else {
                @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
            }
        } catch (\Throwable $e) { error_log('[flow kick] ' . $e->getMessage()); }
    }
}
