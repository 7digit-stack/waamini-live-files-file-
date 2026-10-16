<?php
/**
 * Stress test: enqueue + process 1000 messages without blocking UI.
 *
 * Usage:
 *   APP_ENV=local WAAMINI_WA_SIMULATE=1 php scripts/stress_1000_messages.php
 *
 * This script:
 * - inserts 1000 automation_queue jobs for a company/contact
 * - runs QueueWorker in a tight loop until queue drains
 * - prints timing + memory stats
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/QueueService.php';
require_once __DIR__ . '/../services/QueueWorker.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
if ($env !== 'local') {
    fwrite(STDERR, "Run this only in APP_ENV=local\n");
    exit(2);
}

$companyId = (int)($_ENV['E2E_COMPANY_ID'] ?? getenv('E2E_COMPANY_ID') ?: 1);
$contactId = (int)($_ENV['E2E_CONTACT_ID'] ?? getenv('E2E_CONTACT_ID') ?: 1);
$phone = (string)($_ENV['E2E_PHONE'] ?? getenv('E2E_PHONE') ?: '919999999999');

$n = 1000;
$t0 = microtime(true);
for ($i = 0; $i < $n; $i++) {
    QueueService::enqueue($conn, [
        'company_id' => $companyId,
        'contact_id' => $contactId,
        'type' => 'text',
        'phone' => $phone,
        'message' => 'stress-' . $i,
        'payload' => ['source' => 'stress', 'i' => $i],
    ]);
}
$tEnq = microtime(true) - $t0;

echo "Enqueued {$n} jobs in " . round($tEnq, 3) . "s\n";

$worker = new QueueWorker($conn);
$processed = 0;
$t1 = microtime(true);

while (true) {
    $worker->process(200);
    $processed += 200;

    $res = $conn->query("SELECT COUNT(*) c FROM automation_queue WHERE status IN ('pending','retrying','processing')");
    $row = $res ? $res->fetch_assoc() : null;
    $remaining = (int)($row['c'] ?? 0);
    $mem = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);

    echo "Remaining={$remaining} mem=" . round($mem / (1024*1024), 1) . "MB peak=" . round($peak / (1024*1024), 1) . "MB\n";

    if ($remaining <= 0) break;
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    usleep(100000);
}

$tProc = microtime(true) - $t1;
echo "Processed queue in " . round($tProc, 3) . "s\n";

