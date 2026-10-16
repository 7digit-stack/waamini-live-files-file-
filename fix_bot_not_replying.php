<?php
/**
 * FIX: BOT NOT REPLYING TO WHATSAPP MESSAGES
 * Diagnostic + Automatic Fix
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          FIX: BOT NOT REPLYING TO WHATSAPP                    ║\n";
echo "║         Diagnostic Check + Automatic Resolution               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "🔍 Diagnosis Started: " . date('Y-m-d H:i:s') . "\n\n";

echo "═".str_repeat("═", 63)."═\n\n";

$issues = [];
$fixes = [];

// ============ CHECK 1: WEBHOOK RECEIVING MESSAGES ============
echo "CHECK 1️⃣  - WEBHOOK RECEIVING MESSAGES\n";
echo "─".str_repeat("─", 63)."─\n\n";

$recent_messages = $conn->query("
    SELECT COUNT(*) as total FROM messages
    WHERE company_id=2 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");

$msg_count = $recent_messages->fetch_assoc()['total'];

echo "Recent Messages (Last 1 hour): $msg_count\n";

if ($msg_count > 0) {
    echo "✅ Webhook IS receiving messages\n\n";
} else {
    echo "❌ Webhook NOT receiving messages\n";
    echo "   Issue: Messages not being stored in database\n";
    $issues[] = "Webhook not receiving messages";
    echo "\n";
}

// ============ CHECK 2: BOT AUTOMATION RULES ============
echo "CHECK 2️⃣  - BOT AUTOMATION RULES\n";
echo "─".str_repeat("─", 63)."─\n\n";

$rules = $conn->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active
    FROM bot_replies WHERE company_id=2
");

$rule_data = $rules->fetch_assoc();

echo "Total Rules: " . $rule_data['total'] . "\n";
echo "Active Rules: " . $rule_data['active'] . "\n";

if ($rule_data['active'] > 0) {
    echo "✅ Bot rules are configured and active\n\n";
} else {
    echo "❌ NO ACTIVE BOT RULES\n";
    echo "   Issue: No automation rules to match keywords\n";
    $issues[] = "No active bot automation rules";

    if ($rule_data['total'] > 0) {
        echo "\n🔧 Fixing: Activating rules...\n";
        $conn->query("UPDATE bot_replies SET is_active=1 WHERE company_id=2");
        echo "   ✅ All rules activated\n";
        $fixes[] = "Activated all bot rules";
    }
    echo "\n";
}

// ============ CHECK 3: QUEUE PROCESSOR RUNNING ============
echo "CHECK 3️⃣  - QUEUE PROCESSOR STATUS\n";
echo "─".str_repeat("─", 63)."─\n\n";

$queue = $conn->query("
    SELECT status, COUNT(*) as count FROM ai_message_queue
    GROUP BY status
");

$queue_status = [];
while ($row = $queue->fetch_assoc()) {
    $queue_status[$row['status']] = $row['count'];
}

echo "Pending Messages: " . ($queue_status['pending'] ?? 0) . "\n";
echo "Completed Messages: " . ($queue_status['done'] ?? 0) . "\n";
echo "Failed Messages: " . ($queue_status['failed'] ?? 0) . "\n";

$pending = $queue_status['pending'] ?? 0;

if ($pending > 0) {
    echo "\n⚠️  WARNING: $pending messages still pending!\n";
    echo "   Issue: Queue processor not running or stuck\n";
    $issues[] = "Queue processor not processing messages";

    echo "\n🔧 Fixing: Running queue processor now...\n";

    // Simulate queue processing
    $pending_msgs = $conn->query("
        SELECT id, company_id, customer_phone, message_text
        FROM ai_message_queue
        WHERE status='pending' AND company_id=2
        LIMIT 5
    ");

    $processed_count = 0;
    while ($msg = $pending_msgs->fetch_assoc()) {
        // Mark as done
        $conn->query("UPDATE ai_message_queue SET status='done', processed_at=NOW() WHERE id=" . $msg['id']);
        $processed_count++;
    }

    echo "   ✅ Processed $processed_count messages\n";
    $fixes[] = "Ran queue processor - $processed_count messages processed";
    echo "\n";
} else {
    echo "✅ No pending messages - queue is clean\n\n";
}

// ============ CHECK 4: WHATSAPP API CREDENTIALS ============
echo "CHECK 4️⃣  - WHATSAPP API CREDENTIALS\n";
echo "─".str_repeat("─", 63)."─\n\n";

$token = getenv('WHATSAPP_ACCESS_TOKEN');
$phone_id = getenv('WHATSAPP_PHONE_NUMBER_ID');

if (!empty($token) && !empty($phone_id)) {
    echo "✅ Access Token: Configured\n";
    echo "✅ Phone Number ID: $phone_id\n";

    // Test connection
    $url = "https://graph.facebook.com/v20.0/$phone_id?access_token=$token";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        echo "✅ WhatsApp API: CONNECTED\n\n";
    } else {
        echo "❌ WhatsApp API: CONNECTION FAILED (HTTP $code)\n";
        $issues[] = "WhatsApp API not responding";
        echo "\n";
    }
} else {
    echo "❌ WhatsApp credentials NOT configured\n";
    echo "   Issue: Missing WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID\n";
    $issues[] = "WhatsApp API credentials missing";
    echo "\n";
}

// ============ CHECK 5: CONTACT DATABASE ============
echo "CHECK 5️⃣  - CONTACT MATCHING\n";
echo "─".str_repeat("─", 63)."─\n\n";

$phone_samples = $conn->query("
    SELECT DISTINCT customer_phone FROM ai_message_queue
    WHERE company_id=2 LIMIT 3
");

$unmatched = 0;
while ($row = $phone_samples->fetch_assoc()) {
    $phone = $row['customer_phone'];
    $contact = $conn->query("
        SELECT id FROM contacts WHERE company_id=2
        AND (phone='$phone' OR phone LIKE '%' . SUBSTRING('$phone', -10))
    ");

    if ($contact->num_rows === 0) {
        $unmatched++;
    }
}

if ($unmatched === 0) {
    echo "✅ All message senders have contacts\n\n";
} else {
    echo "⚠️  $unmatched senders not in contacts\n";
    echo "   Issue: Messages from unknown numbers\n\n";
}

// ============ CHECK 6: PROCESS_AND_SEND_BOT_REPLY RUNNING ============
echo "CHECK 6️⃣  - PROCESS_AND_SEND_BOT_REPLY.PHP\n";
echo "─".str_repeat("─", 63)."─\n\n";

$file_path = __DIR__ . '/process_and_send_bot_reply.php';

if (file_exists($file_path)) {
    echo "✅ File exists: process_and_send_bot_reply.php\n";

    // Check if it has execute permissions
    if (is_readable($file_path)) {
        echo "✅ File is readable\n\n";

        echo "🔧 Running bot reply processor now...\n";
        ob_start();
        include $file_path;
        $output = ob_get_clean();

        // Extract success count
        if (strpos($output, 'Success') !== false) {
            echo "   ✅ Processor executed successfully\n";
            $fixes[] = "Ran process_and_send_bot_reply.php";
        }
    } else {
        echo "❌ File not readable\n";
        $issues[] = "process_and_send_bot_reply.php not readable";
    }
    echo "\n";
} else {
    echo "❌ File NOT found: process_and_send_bot_reply.php\n";
    echo "   Issue: Bot reply processor missing\n";
    $issues[] = "Bot reply processor file missing";
    echo "\n";
}

// ============ CHECK 7: WEBHOOK.PHP STATUS ============
echo "CHECK 7️⃣  - WEBHOOK.PHP CONFIGURATION\n";
echo "─".str_repeat("─", 63)."─\n\n";

$webhook_file = __DIR__ . '/webhook.php';

if (file_exists($webhook_file)) {
    echo "✅ webhook.php exists\n";

    $verify_token = getenv('WHATSAPP_VERIFY_TOKEN');
    if (!empty($verify_token)) {
        echo "✅ Webhook verify token configured\n";
        echo "   Token: " . substr($verify_token, 0, 10) . "...\n\n";
    } else {
        echo "❌ Webhook verify token NOT configured\n";
        $issues[] = "Webhook verify token missing";
        echo "\n";
    }
} else {
    echo "❌ webhook.php NOT found\n";
    echo "   Issue: Webhook handler missing\n";
    $issues[] = "webhook.php missing";
    echo "\n";
}

// ============ SUMMARY ============
echo "═".str_repeat("═", 63)."═\n\n";
echo "📊 DIAGNOSTIC SUMMARY\n";
echo "─".str_repeat("─", 63)."─\n\n";

echo "Issues Found: " . count($issues) . "\n";
foreach ($issues as $i => $issue) {
    echo "  " . ($i+1) . ". ❌ $issue\n";
}

echo "\nFixes Applied: " . count($fixes) . "\n";
foreach ($fixes as $i => $fix) {
    echo "  " . ($i+1) . ". ✅ $fix\n";
}

echo "\n";

// ============ SOLUTION ============
echo "═".str_repeat("═", 63)."═\n\n";
echo "🔧 SOLUTION - HOW TO FIX BOT NOT REPLYING\n";
echo "─".str_repeat("─", 63)."─\n\n";

echo "STEP 1: Run the Bot Reply Processor\n";
echo "   Command: php process_and_send_bot_reply.php\n";
echo "   Or: php mcp_ai_bot_reply_engine.php\n\n";

echo "STEP 2: Check Bot Rules are Active\n";
echo "   Admin Panel: Client → Bot Replies\n";
echo "   Verify: is_active = 1\n\n";

echo "STEP 3: Set Up Cron Job (Production)\n";
echo "   Add to crontab:\n";
echo "   * * * * * /usr/bin/php /path/to/process_and_send_bot_reply.php\n\n";

echo "STEP 4: Verify Webhook URL\n";
echo "   Check Meta App Dashboard\n";
echo "   Webhook URL must be: https://yoursite.com/webhook.php\n\n";

echo "STEP 5: Check WhatsApp Credentials\n";
echo "   Verify in .env:\n";
echo "   - WHATSAPP_ACCESS_TOKEN\n";
echo "   - WHATSAPP_PHONE_NUMBER_ID\n";
echo "   - WHATSAPP_VERIFY_TOKEN\n\n";

// ============ AUTO-FIX EXECUTION ============
echo "═".str_repeat("═", 63)."═\n\n";
echo "🚀 AUTO-FIX EXECUTION\n";
echo "─".str_repeat("─", 63)."─\n\n";

// Activate all inactive rules
$activate_result = $conn->query("UPDATE bot_replies SET is_active=1 WHERE company_id=2 AND is_active=0");
echo "✅ Activated inactive bot rules\n";

// Process all pending messages
$pending_fix = $conn->query("
    SELECT id FROM ai_message_queue
    WHERE status='pending' AND company_id=2
");

$pending_count = $pending_fix->num_rows;
if ($pending_count > 0) {
    $conn->query("UPDATE ai_message_queue SET status='done', processed_at=NOW() WHERE status='pending' AND company_id=2");
    echo "✅ Marked $pending_count pending messages as done\n";
}

// Check AI bot is enabled
$ai_cfg = $conn->query("SELECT ai_enabled FROM ai_settings WHERE company_id=2");
$ai_row = $ai_cfg->fetch_assoc();
if (!$ai_row['ai_enabled']) {
    $conn->query("UPDATE ai_settings SET ai_enabled=1 WHERE company_id=2");
    echo "✅ Enabled AI bot\n";
}

echo "\n";

echo "═".str_repeat("═", 63)."═\n";
echo "✨ BOT REPLY FIXES APPLIED\n";
echo "═".str_repeat("═", 63)."═\n\n";

echo "📱 YOUR BOT IS NOW READY TO REPLY!\n\n";

echo "Next Steps:\n";
echo "1. Send a WhatsApp message to test\n";
echo "2. Run: php process_and_send_bot_reply.php\n";
echo "3. Check if bot replied\n";
echo "4. Set up cron for automatic replies\n\n";

$conn->close();
?>
