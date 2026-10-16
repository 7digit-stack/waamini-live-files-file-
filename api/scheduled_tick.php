<?php
/**
 * Scheduled Blasts tick (session-authenticated).
 * Sends this company's due scheduled messages while the panel is open, so they go out even when
 * the server cron (workers/automation_queue_worker.php) is not set up. Safe to call often.
 */
require_once __DIR__ . '/../includes/client_auth_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/scheduled_sender.php';

header('Content-Type: application/json; charset=utf-8');

if (!has_feature('feat_scheduled_msg')) {
    echo json_encode(['ok' => false, 'error' => 'feature_disabled']);
    exit;
}
$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_company']);
    exit;
}
// Release the session lock so the page stays responsive while messages are sent.
session_write_close();

$r = sm_process_due($conn, $companyId, 20);
echo json_encode(['ok' => true] + $r);
