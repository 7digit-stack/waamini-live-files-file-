<?php
/** Verify demo bookings + queue status */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';

echo "=== DEMO BOOKINGS ===\n";
$res = $conn->query("SELECT id, customer_name, customer_phone, preferred_time, topic, status, demo_datetime, meet_link, calendar_event_id, created_at FROM demo_bookings ORDER BY id DESC LIMIT 5");
if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
        echo "#{$r['id']} | {$r['customer_name']} | {$r['customer_phone']} | {$r['preferred_time']} | {$r['topic']} | {$r['status']} | demo_datetime={$r['demo_datetime']} | meet_link={$r['meet_link']} | calendar_event_id={$r['calendar_event_id']} | {$r['created_at']}\n";
    }
} else {
    echo "No bookings found\n";
}

echo "\n=== QUEUE STATUS ===\n";
$res = $conn->query("SELECT status, COUNT(*) c FROM ai_message_queue GROUP BY status");
while ($r = $res->fetch_assoc()) {
    echo "{$r['status']}: {$r['c']}\n";
}

echo "\n=== LAST 4 CONVERSATION MESSAGES ===\n";
$hasAiLog = $conn->query("SHOW TABLES LIKE 'ai_conversation_log'");
$hasAiLog = $hasAiLog && $hasAiLog->num_rows > 0;
$res = $hasAiLog
    ? $conn->query("SELECT role, LEFT(content, 90) t, created_at FROM ai_conversation_log WHERE company_id=2 ORDER BY id DESC LIMIT 4")
    : $conn->query("SELECT direction, LEFT(COALESCE(NULLIF(content,''), message), 90) t, created_at FROM messages WHERE company_id=2 ORDER BY id DESC LIMIT 4");
$rows = [];
while ($res && ($r = $res->fetch_assoc())) {
    if (!$hasAiLog) $r['role'] = ($r['direction'] ?? '') === 'incoming' ? 'user' : 'assistant';
    $rows[] = $r;
}
foreach (array_reverse($rows) as $r) {
    echo strtoupper($r['role']) . ": {$r['t']}\n";
}
?>
