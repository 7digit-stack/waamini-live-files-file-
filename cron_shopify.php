<?php
/**
 * Shopify abandoned-checkout reminders (runs every minute with the other workers).
 * Chained into process_ai_queue.php, so no separate cron entry is needed.
 * Include-safe: own lock and top-level `return`. Also runnable alone: php cron_shopify.php
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/cron_guard.php';
waamini_cron_guard();
require_once __DIR__ . '/includes/shopify.php';

$shLock = fopen(sys_get_temp_dir() . '/waamini_shopify.lock', 'c');
if (!$shLock || !flock($shLock, LOCK_EX | LOCK_NB)) {
    echo "Shopify worker already running\n";
    return;
}
try {
    $n = shopify_process_due($conn);
    if ($n > 0) echo "Shopify: {$n} checkout reminder(s) queued\n";
} catch (\Throwable $e) {
    error_log('[cron_shopify] ' . $e->getMessage());
}
flock($shLock, LOCK_UN);
fclose($shLock);
return;
