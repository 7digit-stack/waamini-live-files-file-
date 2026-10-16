<?php
/**
 * Flow Builder + chat follow-up tick (runs every minute with the other workers).
 *
 * Chained into process_ai_queue.php (the scheduled "WaaminiWorker" task), so no separate cron entry is needed.
 * Include-safe: own lock and a top-level `return`. Also runnable alone: php cron_flow_tick.php
 *   1. Flow Builder: delayed steps and "no reply after N minutes" follow-ups that are due, idle sessions closed
 *   2. Account auto follow-up: customers whose last message is still unanswered after the account's chosen time (default 1 hour)
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

$ftTrusted = (defined('WAAMINI_TRUSTED_INTERNAL_RUN') && WAAMINI_TRUSTED_INTERNAL_RUN === true);
if (php_sapi_name() !== 'cli' && !$ftTrusted) {
    $ftKey = (string)($_GET['key'] ?? '');
    if ($ftKey === '' || !defined('CRON_SECRET_KEY') || !hash_equals((string)CRON_SECRET_KEY, $ftKey)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$ftLock = fopen(sys_get_temp_dir() . '/waamini_flow_tick.lock', 'c');
if (!$ftLock || !flock($ftLock, LOCK_EX | LOCK_NB)) {
    echo "Flow tick already running\n";
    return;
}
@set_time_limit(55);

try {
    require_once __DIR__ . '/includes/flow/worker.php';
    require_once __DIR__ . '/includes/chat_followup.php';
    $ftJobs = FlowJobWorker::process($conn, 100);          // also closes idle flow sessions
    $ftFollow = ChatFollowup::process($conn, 50);
    echo "flow tick: {$ftJobs} flow job(s), {$ftFollow} follow-up(s) sent\n";
} catch (\Throwable $e) {
    error_log('[flow tick] ' . $e->getMessage());
    echo "flow tick error: " . $e->getMessage() . "\n";
}

flock($ftLock, LOCK_UN);
fclose($ftLock);
return;
