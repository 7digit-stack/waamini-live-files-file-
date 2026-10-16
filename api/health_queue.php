<?php
/**
 * WAAMINI Queue Health Endpoint (JSON)
 * Protect with CRON_SECRET_KEY (header X-Health-Key or ?key=)
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$key = (string)($_SERVER['HTTP_X_HEALTH_KEY'] ?? ($_GET['key'] ?? ''));
$secret = (string)($_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?: '');
if ($secret === '' || !hash_equals($secret, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$out = [
    'ok' => true,
    'ts' => date('c'),
    'automation_queue' => null,
    'queue_jobs' => null,
];

try {
    $res = $conn->query("SELECT
        SUM(status='pending') pending,
        SUM(status='retrying') retrying,
        SUM(status='processing') processing,
        SUM(status='failed') failed,
        SUM(status='sent') sent,
        MIN(created_at) oldest_created_at
      FROM automation_queue");
    $row = $res ? $res->fetch_assoc() : null;
    $out['automation_queue'] = $row ?: [];
} catch (\Throwable $e) {
    $out['automation_queue'] = ['error' => 'unavailable'];
}

try {
    $chk = $conn->query("SHOW TABLES LIKE 'queue_jobs'");
    if ($chk && (int)$chk->num_rows > 0) {
        $res = $conn->query("SELECT
            SUM(status='pending') pending,
            SUM(status='retrying') retrying,
            SUM(status='processing') processing,
            SUM(status='failed') failed,
            SUM(status='done') done,
            MIN(created_at) oldest_created_at
          FROM queue_jobs");
        $row = $res ? $res->fetch_assoc() : null;
        $out['queue_jobs'] = $row ?: [];
    } else {
        $out['queue_jobs'] = ['error' => 'missing_table'];
    }
} catch (\Throwable $e) {
    $out['queue_jobs'] = ['error' => 'unavailable'];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

