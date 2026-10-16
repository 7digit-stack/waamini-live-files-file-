<?php
/**
 * Ads & Attribution: download this company's pending ad thumbnails (session-authenticated).
 * Called in the background by Ad Leads and WhatsApp Chat, so thumbnails are saved even when the
 * server cron (workers/automation_queue_worker.php) is not set up. Safe to call often.
 */
require_once __DIR__ . '/../includes/client_auth_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/AttributionService.php';

header('Content-Type: application/json; charset=utf-8');
$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
session_write_close();   // do not hold the session lock while downloading

$n = AttributionService::processPendingMedia($conn, $companyId, 5);
$capi = ['sent' => 0];
if (has_feature('ads_capi')) {
    // Also send this company's due Conversions API events (fallback when the cron is not running).
    require_once __DIR__ . '/../services/CapiService.php';
    $capi = CapiService::processDue($conn, $companyId, 10);
}
echo json_encode(['ok' => true, 'downloaded' => $n, 'capi_sent' => $capi['sent']]);
