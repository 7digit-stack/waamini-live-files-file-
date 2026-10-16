<?php
// Minimal cron gateway (tests expect this route)
define('WAAMINI_APP', true);
define('WAAMINI_CRON', true);

$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../db.php';

if (!$isCli) {
    header('Content-Type: application/json');
    $expectedKey = defined('CRON_SECRET_KEY') ? (string)CRON_SECRET_KEY : '';
    $providedKey = (string)($_GET['key'] ?? $_GET['cron_key'] ?? '');

    if ($expectedKey === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Cron key not configured']);
        exit;
    }

    if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

echo json_encode(['ok' => true, 'timestamp' => date('c')]);

