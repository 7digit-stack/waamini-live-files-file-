<?php
/**
 * WAAMINI BOT REPLY AUTOMATION
 * Process pending messages and send bot replies via WhatsApp API
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     WAAMINI BOT REPLY AUTOMATION - SEND TO WHATSAPP           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "📅 Started: " . date('Y-m-d H:i:s') . "\n";
echo "🗄️  Database: waamini\n";
echo "📱 WhatsApp Integration: ACTIVE\n\n";

echo "═".str_repeat("═", 63)."═\n\n";

// Get pending messages from ai_message_queue
$pending = $conn->query("
    SELECT id, company_id, customer_phone, message_text, wamid
    FROM ai_message_queue
    WHERE status = 'pending'
    ORDER BY created_at ASC
    LIMIT 5
");

if (!$pending || $pending->num_rows === 0) {
    echo "✅ No pending messages to process\n\n";
    $conn->close();
    exit(0);
}

echo "🔄 PROCESSING " . $pending->num_rows . " PENDING MESSAGES\n\n";

$processed = 0;
$sent = 0;
$failed = 0;

while ($msg = $pending->fetch_assoc()) {
    $queueId = $msg['id'];
    $companyId = $msg['company_id'];
    $phone = $msg['customer_phone'];
    $text = $msg['message_text'];
    $wamid = $msg['wamid'];

    echo "─".str_repeat("─", 63)."─\n";
    echo "Message #$queueId\n";
    echo "   From: $phone\n";
    echo "   Text: " . substr($text, 0, 60) . "...\n";
    echo "   WAMID: $wamid\n\n";

    // Step 1: Check bot automation rules
    echo "   Step 1: Checking bot automation rules...\n";

    $rules = $conn->query("
        SELECT id, keyword, reply_type, reply_content, template_id
        FROM bot_replies
        WHERE company_id = $companyId AND is_active = 1
    ");

    $matchedReply = null;

    while ($rule = $rules->fetch_assoc()) {
        $keywords = array_map('trim', explode(',', $rule['keyword']));
        $textLower = strtolower($text);

        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim($keyword));
            if (strpos($textLower, $keyword) !== false) {
                $matchedReply = $rule;
                echo "   ✅ MATCHED: Rule #{$rule['id']}\n";
                echo "      Keywords: {$rule['keyword']}\n";
                echo "      Reply Type: {$rule['reply_type']}\n";
                break 2;
            }
        }
    }

    if (!$matchedReply) {
        echo "   ⚠️  No bot rule matched - checking AI bot...\n";

        // Check if AI is enabled
        $aiCfg = $conn->query("SELECT ai_enabled FROM ai_settings WHERE company_id = $companyId");
        $aiRow = $aiCfg->fetch_assoc();

        if ($aiRow && $aiRow['ai_enabled']) {
            echo "   ℹ️  AI bot enabled - will process via AI\n";
            echo "   ℹ️  Skipping this message (requires async processing)\n\n";
            $processed++;
            continue;
        } else {
            echo "   ❌ No rule matched and AI not enabled\n";
            $conn->query("UPDATE ai_message_queue SET status='failed', error_msg='No matching rule or AI enabled' WHERE id=$queueId");
            $failed++;
            echo "\n";
            continue;
        }
    }

    // Step 2: Generate reply message
    echo "   Step 2: Generating reply message...\n";

    $replyText = '';
    if ($matchedReply['reply_type'] === 'text') {
        $replyText = $matchedReply['reply_content'];
    } elseif ($matchedReply['reply_type'] === 'template') {
        // For templates, use content or a default message
        $replyText = $matchedReply['reply_content'] ?: "Thank you for your message. We will respond shortly.";
    }

    if (empty($replyText)) {
        $replyText = "Thank you for reaching out! We appreciate your message.";
    }

    echo "   ✅ Reply text: " . substr($replyText, 0, 60) . "...\n\n";

    // Step 3: Send via WhatsApp API
    echo "   Step 3: Sending via WhatsApp API...\n";

    $accessToken = getenv('WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = getenv('WHATSAPP_PHONE_NUMBER_ID');

    if (!$accessToken || !$phoneNumberId) {
        echo "   ❌ WhatsApp credentials not configured\n";
        $conn->query("UPDATE ai_message_queue SET status='failed', error_msg='Missing WhatsApp credentials' WHERE id=$queueId");
        $failed++;
        echo "\n";
        continue;
    }

    // Send message via Meta WhatsApp API
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'text',
        'text' => [
            'body' => $replyText
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "   ✅ SENT TO WHATSAPP\n";
        echo "   ✅ HTTP Code: $httpCode\n";

        // Log reply in messages table — exact match first, then fuzzy last-10
        $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
        $phonePlus = '+' . $phoneNorm;
        $digits10 = substr($phoneNorm, -10);
        $like10 = '%' . $digits10;

        $insMsg = $conn->prepare(
            "INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, created_at)
             SELECT ?, id, 'outgoing', 'text', ?, ?, 'sent', NOW()
             FROM contacts WHERE company_id = ? AND (phone = ? OR phone = ?) LIMIT 1"
        );
        if ($insMsg) {
            $insMsg->bind_param('isssi', $companyId, $replyText, $replyText, $companyId, $phoneNorm, $phonePlus);
            $insMsg->execute();
            if ($insMsg->affected_rows === 0) {
                $insMsg->close();
                $insMsg = $conn->prepare(
                    "INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, created_at)
                     SELECT ?, id, 'outgoing', 'text', ?, ?, 'sent', NOW()
                     FROM contacts WHERE company_id = ? AND phone LIKE ? ORDER BY LENGTH(phone) DESC LIMIT 1"
                );
                $insMsg->bind_param('isssi', $companyId, $replyText, $replyText, $companyId, $like10);
                $insMsg->execute();
            }
            $insMsg->close();
        }

        // Mark as processed
        $conn->query("UPDATE ai_message_queue SET status='done', processed_at=NOW() WHERE id=$queueId");

        $sent++;
        echo "\n";
    } else {
        echo "   ❌ FAILED TO SEND\n";
        echo "   ❌ HTTP Code: $httpCode\n";

        $errorMsg = json_decode($response, true);
        $errorText = $errorMsg['error']['message'] ?? 'Unknown error';
        echo "   ❌ Error: $errorText\n";

        $conn->query("UPDATE ai_message_queue SET status='failed', error_msg='$errorText' WHERE id=$queueId");
        $failed++;
        echo "\n";
    }

    $processed++;
}

// Final Summary
echo "═".str_repeat("═", 63)."═\n\n";
echo "📊 PROCESSING SUMMARY\n";
echo "─".str_repeat("─", 63)."─\n";
echo "✅ Processed: $processed messages\n";
echo "✅ Sent: $sent replies\n";
echo "❌ Failed: $failed messages\n\n";

if ($sent > 0) {
    echo "✨ SUCCESS! $sent WhatsApp replies sent!\n\n";
} else {
    echo "⚠️  No messages were sent\n\n";
}

echo "═".str_repeat("═", 63)."═\n";
echo "✨ BOT REPLY AUTOMATION COMPLETE\n";
echo "═".str_repeat("═", 63)."═\n";

$conn->close();
?>
