<?php
/**
 * Automation Queue Worker Entry Point
 * Run this via cron: * * * * * php workers/automation_queue_worker.php
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../services/QueueWorker.php';
require_once __DIR__ . '/../includes/flow/worker.php';

// CLI Guard
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? '';
    $cronKey = $_ENV['CRON_SECRET_KEY'] ?? 'waamini_cron_secret_2024';
    if (!$cronKey || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        die("Unauthorized");
    }
}

$worker = new QueueWorker($conn);
$worker->process(50);
$flowJobs = FlowJobWorker::process($conn, 50);

// Scheduled Blasts (client/scheduled_messages.php): send messages whose time has come.
require_once __DIR__ . '/../includes/scheduled_sender.php';
$sched = sm_process_due($conn, 0, 100);

// Ads & Attribution: download Click-to-WhatsApp ad thumbnails before Meta's links expire.
require_once __DIR__ . '/../services/AttributionService.php';
$adMedia = AttributionService::processPendingMedia($conn, 0, 30);

// Ads & Attribution: send queued Conversions API events (sales / qualified leads) to Meta.
require_once __DIR__ . '/../services/CapiService.php';
$capi = CapiService::processDue($conn, 0, 100);

// Festival wishes (client/festival_campaigns.php): queue greetings that are due (9 am to 9 pm IST only).
require_once __DIR__ . '/../includes/festival_engine.php';
$fest = ['campaigns' => 0, 'queued' => 0];
try { $fest = festival_dispatch_due($conn); } catch (\Throwable $e) { error_log('[festival] ' . $e->getMessage()); }

echo "Queue processed at " . date('Y-m-d H:i:s') . "; flow jobs: $flowJobs; scheduled sent: {$sched['sent']}, failed: {$sched['failed']}; ad media: $adMedia; capi sent: {$capi['sent']}, failed: {$capi['failed']}; festival queued: {$fest['queued']}\n";
