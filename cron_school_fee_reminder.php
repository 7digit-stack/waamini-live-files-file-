<?php
/**
 * WAAMINI School Fee Auto-Reminder (cron URL kept for existing server setups).
 * Schedule: 0 9 * * *
 *
 * Reminders are sent by includes/school_fee_engine.php, which also runs every minute from the queue
 * worker. Running this as well is safe: a fee is reminded at most once per due-date step.
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? '';
    if (!$cronKey || !hash_equals($cronKey, $provided)) { http_response_code(403); die('Forbidden'); }
}
set_time_limit(55);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/school_fee_engine.php';

$queued = sf_dispatch_due($conn);
$conn->query("INSERT INTO system_settings (setting_key,setting_value,setting_group) VALUES ('cron_school_fee_last_run',NOW(),'cron') ON DUPLICATE KEY UPDATE setting_value=NOW()");
echo "Done. Reminders queued: $queued\n";
