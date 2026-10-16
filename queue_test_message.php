<?php
/** Queue a test customer message for end-to-end testing */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';

$phone = $argv[1] ?? '919092567609';
$text  = $argv[2] ?? 'Demo kudu';
$wamid = 'test_' . uniqid();

$stmt = $conn->prepare(
    "INSERT INTO ai_message_queue (company_id, customer_phone, message_text, wamid, status, created_at)
     VALUES (2, ?, ?, ?, 'pending', NOW())"
);
$stmt->bind_param('sss', $phone, $text, $wamid);
$stmt->execute();

echo "✅ Queued test message #" . $conn->insert_id . " from $phone: \"$text\"\n";
?>
