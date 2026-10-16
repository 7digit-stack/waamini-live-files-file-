<?php
/**
 * WAAMINI Festival Auto-Campaign Cron (cron URL kept for existing server setups).
 * Schedule: 0 8 * * *
 *
 * Same job as cron_festival_sender.php: queue festival wishes that are due, through
 * includes/festival_engine.php. Safe to run alongside the queue worker (each contact is queued once).
 */
$cronKey = getenv('WAAMINI_CRON_KEY') ?: '';
if (PHP_SAPI !== 'cli') { $p = $_GET['key'] ?? ''; if (!$cronKey || !hash_equals($cronKey, $p)) { http_response_code(403); die('Forbidden'); } }
set_time_limit(55);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/festival_engine.php';

$r = festival_dispatch_due($conn, 10);
echo "Done. Festival campaigns: {$r['campaigns']}, messages queued: {$r['queued']}\n";
