<?php
/** Show recent full AI replies for tone verification */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';

$res = $conn->query(
    "SELECT customer_phone, role, content, created_at
     FROM ai_conversation_log WHERE company_id=2
     ORDER BY id DESC LIMIT 6"
);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

foreach (array_reverse($rows) as $r) {
    $who = $r['role'] === 'user' ? '👤 CUSTOMER' : '🤖 WAAMINI AI';
    echo "$who ({$r['customer_phone']}):\n{$r['content']}\n";
    echo str_repeat('-', 60) . "\n";
}
?>
