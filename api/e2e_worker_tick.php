<?php
/**
 * WAAMINI – E2E Test Worker Tick Endpoint (LOCAL ONLY)
 * Used by Playwright specs (15-performance, 18-retry-logic) to run one
 * queue-worker pass without cron/Supervisor.
 *
 * Guards:
 *  - APP_ENV must be "local"
 *  - x-test-key header (or ?key=) must match CRON_SECRET_KEY
 */

define('WAAMINI_APP', true);
define('WAAMINI_CRON', true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/QueueWorker.php';

header('Content-Type: application/json');

$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
if ($appEnv !== 'local') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

$cronKey  = (string) ($_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?: '');
$provided = (string) ($_SERVER['HTTP_X_TEST_KEY'] ?? ($_GET['key'] ?? ''));
if ($cronKey === '' || !hash_equals($cronKey, $provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$which = strtolower((string) ($_GET['which'] ?? 'automation'));

$result = ['success' => true, 'which' => $which, 'timestamp' => date('Y-m-d H:i:s')];

try {
    switch ($which) {
        case 'automation':
        default:
            $worker = new QueueWorker($conn);
            $worker->process(50);
            $result['processed'] = true;
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $result = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($result);
