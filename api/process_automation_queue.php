<?php
/**
 * WAAMINI – Unified Queue Processor
 * Handles both automation_queue and stalled messages.
 */

define('WAAMINI_APP', true);
define('WAAMINI_CRON', true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/client_auth_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/QueueWorker.php';
require_once __DIR__ . '/../includes/flow/worker.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
}

// 1. Process Automation Queue (AI replies, Drips, Follow-ups)
$worker = new QueueWorker($conn);
$worker->process(20); // Process up to 20 due items
$flowJobs = FlowJobWorker::process($conn, 20);
require_once __DIR__ . '/../includes/chat_followup.php';
$chatFollowups = ChatFollowup::process($conn, 20);

// 2. Also check for stalled 'queued' messages in the messages table
// and enqueue them if they aren't already in automation_queue.
// (In production, api/send_message.php already enqueues them, but this acts as a failsafe).

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'flow_jobs_processed' => $flowJobs,
    'chat_followups_sent' => $chatFollowups,
    'mode' => $isCli ? 'cli' : 'web'
]);

