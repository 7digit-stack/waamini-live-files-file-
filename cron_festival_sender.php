<?php
/**
 * WAAMINI Festival Campaign Sender (cron URL kept for existing server setups).
 * Schedule: hourly or daily, e.g. 0 9 * * *
 *
 * Sending now lives in includes/festival_engine.php and also runs every minute from
 * workers/automation_queue_worker.php. Running both is safe: each contact is queued once per festival.
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: '';
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(55);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/festival_engine.php';

$r = festival_dispatch_due($conn, 10);
echo "Done. Festival campaigns: {$r['campaigns']}, messages queued: {$r['queued']}\n";
