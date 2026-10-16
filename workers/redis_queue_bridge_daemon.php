<?php
/**
 * Optional Redis queue bridge daemon.
 * Converts Redis list jobs into automation_queue (DB) for existing processing.
 *
 * Enable by setting QUEUE_DRIVER=redis and ext-redis installed.
 *
 * Run:
 *   php workers/redis_queue_bridge_daemon.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../services/RedisQueueBridge.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(0);

$driver = strtolower((string)($_ENV['QUEUE_DRIVER'] ?? getenv('QUEUE_DRIVER') ?: 'db'));
if ($driver !== 'redis') {
    echo "QUEUE_DRIVER not redis; exiting\n";
    exit(0);
}

$redis = wa_redis();
if (!($redis instanceof Redis)) {
    fwrite(STDERR, "Redis unavailable\n");
    exit(2);
}

$root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
$logFile = $root . '/storage/logs/daemon.log';
$stopFile = $root . '/storage/STOP_REDIS_BRIDGE';

$listKey = (string)($_ENV['REDIS_QUEUE_LIST'] ?? getenv('REDIS_QUEUE_LIST') ?: (wa_cache_prefix() . 'queue:automation'));
$idleMs = (int)($_ENV['REDIS_BRIDGE_IDLE_MS'] ?? getenv('REDIS_BRIDGE_IDLE_MS') ?: 200);
$batch = (int)($_ENV['REDIS_BRIDGE_BATCH'] ?? getenv('REDIS_BRIDGE_BATCH') ?: 200);

wa_log_append($logFile, json_encode(['ts' => date('c'), 'daemon' => 'redis_bridge', 'event' => 'start', 'list' => $listKey]), 5*1024*1024, 7);

$bridge = new RedisQueueBridge($redis, $conn, $listKey);
$loops = 0;
while (true) {
    if (is_file($stopFile)) {
        wa_log_append($logFile, json_encode(['ts' => date('c'), 'daemon' => 'redis_bridge', 'event' => 'stop_file']), 5*1024*1024, 7);
        break;
    }
    $n = $bridge->runOnce($batch);
    if ($n === 0) usleep(max(10, $idleMs) * 1000);
    $loops++;
    if (($loops % 200) === 0 && function_exists('gc_collect_cycles')) gc_collect_cycles();
}

wa_log_append($logFile, json_encode(['ts' => date('c'), 'daemon' => 'redis_bridge', 'event' => 'exit']), 5*1024*1024, 7);

