<?php
/**
 * WAAMINI Fee Reminder (older cron URL, kept for existing server setups).
 * Schedule: 0 9 * * *
 *
 * The School Fee Manager now sends through includes/school_fee_engine.php (also every minute from the
 * queue worker). This URL runs the same job; running both is safe.
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli' && !defined('WAAMINI_QUEUE_WORKER')) {
    $provided = $_GET['key'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(55);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/school_fee_engine.php';

$queued = sf_dispatch_due($conn);
echo "Done. Reminders queued: $queued\n";
