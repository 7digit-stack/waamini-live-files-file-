<?php
/**
 * Drip Champion — schema guard (idempotent).
 * Ensures every column the Drip Champion system needs exists.
 * Cheap: runs the ALTERs once per process, errors swallowed when columns exist.
 */

if (!function_exists('drip_champion_ensure_schema')) {
    function drip_champion_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $ddl = [
            // seconds-level cadence
            "ALTER TABLE drip_steps ADD COLUMN delay_seconds INT DEFAULT 0",
            // per-step humanization
            "ALTER TABLE drip_steps ADD COLUMN randomization_mode VARCHAR(20) DEFAULT 'human'",
            "ALTER TABLE drip_steps ADD COLUMN jitter_range_seconds INT DEFAULT 300",
            "ALTER TABLE drip_steps ADD COLUMN message_variations LONGTEXT",
            "ALTER TABLE drip_steps ADD COLUMN max_variants TINYINT DEFAULT 3",
            // execution-level variant delivery metadata
            "ALTER TABLE drip_executions ADD COLUMN message_variations LONGTEXT",
            "ALTER TABLE drip_executions ADD COLUMN visited_variants VARCHAR(255) DEFAULT '[]'",
            "ALTER TABLE drip_executions ADD COLUMN delivered_variant TEXT",
            "ALTER TABLE drip_executions ADD COLUMN send_attempted_at DATETIME DEFAULT NULL",
            // DripOptimizerService inserts created_at; without it every enrollment silently failed
            "ALTER TABLE drip_executions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            // every sender claims rows as 'processing' and retries with retry_count; the original
            // enum had no 'processing' (claimed rows became '' and were never sent)
            "ALTER TABLE drip_executions MODIFY COLUMN status ENUM('pending','processing','sent','failed') DEFAULT 'pending'",
            "ALTER TABLE drip_executions ADD COLUMN retry_count INT NOT NULL DEFAULT 0",
            // rows stranded by the old enum go back to the queue
            "UPDATE drip_executions SET status='pending' WHERE status='' OR status IS NULL",
            // campaign-level guardrails
            "ALTER TABLE drip_campaigns ADD COLUMN global_randomization TINYINT(1) DEFAULT 1",
            "ALTER TABLE drip_campaigns ADD COLUMN respect_business_hours TINYINT(1) DEFAULT 1",
            "ALTER TABLE drip_campaigns ADD COLUMN max_messages_per_day INT DEFAULT 20",
            "ALTER TABLE drip_campaigns ADD COLUMN cooldown_hours INT DEFAULT 0",
        ];
        foreach ($ddl as $sql) {
            try { $conn->query($sql); } catch (\Throwable $e) { /* exists */ }
        }
    }
}
?>
