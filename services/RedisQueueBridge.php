<?php
/**
 * Optional Redis queue bridge:
 * - Producers push jobs to Redis list
 * - Bridge pops and inserts into automation_queue (DB) for existing worker pipeline
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/QueueService.php';

class RedisQueueBridge {
    private Redis $redis;
    private mysqli $conn;
    private string $listKey;

    public function __construct(Redis $redis, mysqli $conn, string $listKey) {
        $this->redis = $redis;
        $this->conn = $conn;
        $this->listKey = $listKey;
    }

    public function runOnce(int $max = 200): int {
        $n = 0;
        while ($n < $max) {
            $raw = $this->redis->rPop($this->listKey);
            if (!is_string($raw) || $raw === '') break;
            $job = json_decode($raw, true);
            if (!is_array($job)) continue;
            QueueService::enqueue($this->conn, $job);
            $n++;
        }
        return $n;
    }
}

