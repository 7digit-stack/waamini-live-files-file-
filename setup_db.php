<?php
/**
 * Setup AI tables via PHP
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/setup_ai_tables.sql';
$sql = file_get_contents($sqlFile);

if (!$sql) {
    die("❌ Could not read setup_ai_tables.sql\n");
}

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));
$count = 0;
$errors = [];

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;

    try {
        if (!$conn->query($stmt)) {
            $errors[] = "Error: " . $conn->error . "\nSQL: " . substr($stmt, 0, 100);
        } else {
            $count++;
            echo "✅ " . substr($stmt, 0, 80) . "...\n";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

echo "\n";
if ($errors) {
    echo "⚠️  Warnings (tables may already exist):\n";
    foreach ($errors as $e) {
        echo "  - " . substr($e, 0, 100) . "\n";
    }
}

echo "\n✅ Setup complete: $count statements executed\n";

// Verify tables exist
echo "\nVerifying tables:\n";
$tables = ['ai_conversation_log', 'ai_message_queue', 'ai_settings'];
foreach ($tables as $t) {
    $result = $conn->query("SELECT 1 FROM $t LIMIT 1");
    echo $result ? "✅ $t\n" : "❌ $t\n";
}

// Check ai_settings for company 2
$check = $conn->query("SELECT ai_enabled FROM ai_settings WHERE company_id=2");
if ($check && $check->num_rows > 0) {
    echo "\n✅ AI enabled for company_id=2\n";
} else {
    echo "\n❌ AI not enabled for company_id=2\n";
}
?>
