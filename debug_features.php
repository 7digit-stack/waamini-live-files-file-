<?php
require_once __DIR__ . '/wamini_crm_repositary/config/bootstrap.php';
require_once __DIR__ . '/wamini_crm_repositary/db.php';

session_start();
$_SESSION['company_id'] = 1;

header('Content-Type: text/plain');

echo "=== Feature Cache Debug ===\n\n";

// Check subscriptions
$sub = $conn->query("SELECT * FROM subscriptions WHERE company_id=1 ORDER BY id DESC LIMIT 3");
echo "Subscriptions:\n";
while ($row = $sub->fetch_assoc()) {
    echo "  ID: {$row['id']}, Plan: {$row['plan_id']}, Status: {$row['status']}, Expires: {$row['expires_at']}\n";
}

// Check subscription_plans
$plan = $conn->query("SELECT * FROM subscription_plans WHERE id=1 LIMIT 1");
echo "\nPlan ID 1:\n";
if ($plan && $row = $plan->fetch_assoc()) {
    foreach ($row as $k => $v) {
        echo "  $k: " . var_export($v, true) . "\n";
    }
} else {
    echo "  Not found\n";
}

// Check ai_settings
$ai = $conn->query("SELECT * FROM ai_settings WHERE company_id=1 LIMIT 1");
echo "\nAI Settings:\n";
if ($ai && $row = $ai->fetch_assoc()) {
    foreach ($row as $k => $v) {
        echo "  $k: " . var_export($v, true) . "\n";
    }
} else {
    echo "  Not found\n";
}

// Check bot_replies
$bot = $conn->query("SELECT COUNT(*) as c FROM bot_replies WHERE company_id=1 AND is_active=1");
echo "\nActive bot replies: " . $bot->fetch_assoc()['c'] . "\n";

// Check features
echo "\nFeature checks:\n";
$features = ['whatsapp_chat', 'campaigns', 'contacts', 'templates', 'bot_replies', 'automations', 'integrations', 'message_log', 'pulse', 'qr_code'];
foreach ($features as $f) {
    $_SESSION['company_id'] = 1;
    $result = has_feature($f);
    echo "  $f: " . ($result ? 'YES' : 'NO') . "\n";
}

$conn->close();
?>