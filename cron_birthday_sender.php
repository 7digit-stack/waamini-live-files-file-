<?php
/**
 * WAAMINI Birthday cron (URL kept for existing server setups).
 * Schedule: 0 9 * * *
 *
 * Birthdays, anniversaries and other personal dates now live in includes/occasion_engine.php
 * (Festival Calendar > Occasions) and are also sent every minute from the queue worker.
 * Running this as well is safe: each customer is wished once per occasion per year.
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(55);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/occasion_engine.php';

$queued = occasion_dispatch_due($conn);
echo "Done. Personal wishes queued: $queued\n";
