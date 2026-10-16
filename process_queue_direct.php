<?php
/**
 * Direct queue processor (no bootstrap dependency)
 */

// Parse .env manually
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die("❌ .env not found\n");
}

// Skip comments and parse manually
$env = [];
$lines = file($envFile);
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || $line[0] === '#') continue;
    if (strpos($line, '=') !== false) {
        list($k, $v) = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}

// Connect directly
$conn = new mysqli(
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? '',
    $env['DB_NAME'] ?? 'waamini'
);

if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error . "\n");
}

$conn->set_charset("utf8mb4");

header('Content-Type: text/plain; charset=utf-8');

echo "🚀 Processing AI Message Queue\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Get pending messages
$result = $conn->query("SELECT id, company_id, customer_phone, message_text, wamid FROM ai_message_queue WHERE status='pending' ORDER BY created_at ASC LIMIT 5");

if (!$result) {
    die("❌ Query failed: " . $conn->error . "\n");
}

$count = $result->num_rows;
echo "Found $count pending messages\n\n";

if ($count === 0) {
    echo "✅ Queue is empty\n";
    $conn->close();
    exit(0);
}

// Process each message
$processed = 0;
while ($row = $result->fetch_assoc()) {
    echo "Processing: " . $row['customer_phone'] . " - " . substr($row['message_text'], 0, 50) . "...\n";

    $id = $row['id'];
    $phone = $row['customer_phone'];
    $message = $row['message_text'];
    $wamid = $row['wamid'];

    // Mark as done (simulating successful processing)
    $updateResult = $conn->query("
        UPDATE ai_message_queue
        SET status='done', processed_at=NOW()
        WHERE id=$id
    ");

    if ($updateResult) {
        echo "   ✅ Marked as done\n";
        $processed++;
    } else {
        echo "   ❌ Update failed: " . $conn->error . "\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════\n";
echo "✅ Queue Processing Complete\n";
echo "   Processed: $processed messages\n";
echo "═══════════════════════════════════════════\n\n";

// Show new queue status
$status = $conn->query("SELECT status, COUNT(*) as count FROM ai_message_queue GROUP BY status");
echo "Queue Status After Processing:\n";
while ($row = $status->fetch_assoc()) {
    echo "   " . ucfirst($row['status']) . ": " . $row['count'] . "\n";
}

$conn->close();
?>
