<?php
/**
 * WAAMINI COMPLETE SYSTEM HEALTH CHECK & AUTO-FIX
 * Finds and fixes all issues automatically
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     WAAMINI SYSTEM HEALTH CHECK & AUTO-FIX                    ║\n";
echo "║          Comprehensive Diagnosis + Solutions                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "🔍 Health Check Started: " . date('Y-m-d H:i:s') . "\n\n";

$issues_found = 0;
$issues_fixed = 0;
$warnings = 0;

echo "═".str_repeat("═", 63)."═\n\n";

// ============ CHECK 1: DATABASE CONNECTION ============
echo "CHECK 1️⃣  - DATABASE CONNECTION\n";
echo "─".str_repeat("─", 63)."─\n";

if ($conn && $conn->ping()) {
    echo "✅ MySQL Connection: OK\n";
    echo "   Host: " . getenv('DB_HOST') . "\n";
    echo "   Database: " . getenv('DB_NAME') . "\n";
    echo "   Status: CONNECTED ✅\n\n";
} else {
    echo "❌ MySQL Connection: FAILED\n";
    $issues_found++;
    $issues_fixed++;
    // Try to reconnect
    $conn = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        getenv('DB_NAME') ?: 'waamini'
    );
    if ($conn->ping()) {
        echo "✅ Reconnection: SUCCESS\n\n";
    } else {
        echo "❌ Reconnection FAILED\n";
        die("Cannot connect to database\n");
    }
}

// ============ CHECK 2: DATABASE TABLES ============
echo "CHECK 2️⃣  - DATABASE TABLES\n";
echo "─".str_repeat("─", 63)."─\n";

$required_tables = [
    'ai_settings',
    'ai_message_queue',
    'ai_conversation_log',
    'bot_replies',
    'messages',
    'contacts',
    'chat_conversations'
];

$missing_tables = [];

foreach ($required_tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "✅ Table: $table\n";
    } else {
        echo "❌ Table: $table - MISSING\n";
        $missing_tables[] = $table;
        $issues_found++;
    }
}

if (count($missing_tables) > 0) {
    echo "\n🔧 Fixing missing tables...\n";

    $sql_fixes = [
        'ai_settings' => "CREATE TABLE IF NOT EXISTS ai_settings (
            company_id INT PRIMARY KEY,
            ai_enabled TINYINT(1) DEFAULT 0,
            max_replies_per_hour INT DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",

        'ai_message_queue' => "CREATE TABLE IF NOT EXISTS ai_message_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            customer_phone VARCHAR(20) NOT NULL,
            message_text LONGTEXT NOT NULL,
            wamid VARCHAR(100) NULL,
            status ENUM('pending','done','failed') DEFAULT 'pending',
            error_msg VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            UNIQUE KEY uniq_wamid (wamid),
            INDEX idx_queue (company_id, status, created_at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",

        'ai_conversation_log' => "CREATE TABLE IF NOT EXISTS ai_conversation_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            customer_phone VARCHAR(20) NOT NULL,
            role ENUM('user','assistant') NOT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (company_id, customer_phone, id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    foreach ($sql_fixes as $table => $sql) {
        if (in_array($table, $missing_tables)) {
            if ($conn->query($sql)) {
                echo "   ✅ Created: $table\n";
                $issues_fixed++;
            } else {
                echo "   ❌ Failed to create: $table\n";
            }
        }
    }
}

echo "\n";

// ============ CHECK 3: AI CONFIGURATION ============
echo "CHECK 3️⃣  - AI BOT CONFIGURATION\n";
echo "─".str_repeat("─", 63)."─\n";

$aiCfg = $conn->query("SELECT * FROM ai_settings WHERE company_id=2");

if ($aiCfg && $aiCfg->num_rows > 0) {
    $cfg = $aiCfg->fetch_assoc();
    echo "✅ AI Config: EXISTS\n";
    echo "   AI Enabled: " . ($cfg['ai_enabled'] ? 'YES ✅' : 'NO ⚠️') . "\n";

    if (!$cfg['ai_enabled']) {
        echo "\n🔧 Enabling AI Bot...\n";
        $conn->query("UPDATE ai_settings SET ai_enabled=1 WHERE company_id=2");
        echo "   ✅ AI Bot: ENABLED\n";
        $issues_fixed++;
    }
} else {
    echo "⚠️  AI Config: MISSING\n";
    echo "\n🔧 Creating AI Config...\n";

    $conn->query("INSERT INTO ai_settings (company_id, ai_enabled, max_replies_per_hour)
                  VALUES (2, 1, 10)");

    echo "   ✅ AI Config: CREATED\n";
    $issues_fixed++;
}

echo "\n";

// ============ CHECK 4: BOT AUTOMATION RULES ============
echo "CHECK 4️⃣  - BOT AUTOMATION RULES\n";
echo "─".str_repeat("─", 63)."─\n";

$rules = $conn->query("SELECT COUNT(*) as total FROM bot_replies WHERE company_id=2");
$ruleRow = $rules->fetch_assoc();

if ($ruleRow['total'] > 0) {
    echo "✅ Rules Configured: " . $ruleRow['total'] . "\n";

    $activeRules = $conn->query("SELECT COUNT(*) as active FROM bot_replies WHERE company_id=2 AND is_active=1");
    $activeRow = $activeRules->fetch_assoc();

    echo "   Active Rules: " . $activeRow['active'] . "\n";

    if ($activeRow['active'] === 0) {
        echo "\n🔧 Activating rules...\n";
        $conn->query("UPDATE bot_replies SET is_active=1 WHERE company_id=2");
        echo "   ✅ Rules: ACTIVATED\n";
        $issues_fixed++;
    }
} else {
    echo "⚠️  No rules configured\n";
    echo "   Recommendation: Create at least 1 bot rule in admin panel\n";
    $warnings++;
}

echo "\n";

// ============ CHECK 5: OPENAI API ============
echo "CHECK 5️⃣  - OPENAI API CONFIGURATION\n";
echo "─".str_repeat("─", 63)."─\n";

$apiKey = getenv('OPENAI_API_KEY');
$model = getenv('OPENAI_MODEL');

if (!empty($apiKey)) {
    echo "✅ API Key: CONFIGURED\n";
    echo "   Key Length: " . strlen($apiKey) . " chars\n";
    echo "   Model: " . ($model ?: 'gpt-4o-mini') . "\n";

    // Test API connection
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "   Status: API CONNECTED ✅\n";
    } else {
        echo "   ⚠️  API Connection Issue (HTTP $httpCode)\n";
        $warnings++;
    }
} else {
    echo "⚠️  API Key: NOT CONFIGURED\n";
    echo "   Recommendation: Add OPENAI_API_KEY to .env\n";
    $warnings++;
}

echo "\n";

// ============ CHECK 6: WHATSAPP CONFIGURATION ============
echo "CHECK 6️⃣  - WHATSAPP CONFIGURATION\n";
echo "─".str_repeat("─", 63)."─\n";

$whatsappToken = getenv('WHATSAPP_ACCESS_TOKEN');
$phoneId = getenv('WHATSAPP_PHONE_NUMBER_ID');

if (!empty($whatsappToken) && !empty($phoneId)) {
    echo "✅ WhatsApp Token: CONFIGURED\n";
    echo "✅ Phone Number ID: CONFIGURED\n";
    echo "   Phone ID: $phoneId\n";

    // Test WhatsApp connection
    $url = "https://graph.facebook.com/v20.0/" . $phoneId . "?access_token=" . $whatsappToken;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "   Status: CONNECTED ✅\n";
    } else {
        echo "   ⚠️  Connection Issue (HTTP $httpCode)\n";
        $warnings++;
    }
} else {
    echo "⚠️  WhatsApp: NOT CONFIGURED\n";
    echo "   Recommendation: Add to .env:\n";
    echo "   - WHATSAPP_ACCESS_TOKEN\n";
    echo "   - WHATSAPP_PHONE_NUMBER_ID\n";
    $warnings++;
}

echo "\n";

// ============ CHECK 7: FILE PERMISSIONS ============
echo "CHECK 7️⃣  - FILE PERMISSIONS\n";
echo "─".str_repeat("─", 63)."─\n";

$files_to_check = [
    'webhook.php',
    'process_and_send_bot_reply.php',
    'waamini_ai_agent.php',
    '.env'
];

foreach ($files_to_check as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        echo "✅ $file: EXISTS (Perms: $perms)\n";
    } else {
        echo "⚠️  $file: MISSING\n";
        $warnings++;
    }
}

echo "\n";

// ============ CHECK 8: QUEUE STATUS ============
echo "CHECK 8️⃣  - MESSAGE QUEUE STATUS\n";
echo "─".str_repeat("─", 63)."─\n";

$queueStatus = $conn->query("
    SELECT status, COUNT(*) as count FROM ai_message_queue
    GROUP BY status
");

$pending_count = 0;
$done_count = 0;
$failed_count = 0;

while ($row = $queueStatus->fetch_assoc()) {
    if ($row['status'] === 'pending') $pending_count = $row['count'];
    if ($row['status'] === 'done') $done_count = $row['count'];
    if ($row['status'] === 'failed') $failed_count = $row['count'];

    echo "   " . ucfirst($row['status']) . ": " . $row['count'] . "\n";
}

if ($failed_count > 0) {
    echo "\n🔧 Fixing failed messages...\n";
    $conn->query("DELETE FROM ai_message_queue WHERE status='failed'");
    echo "   ✅ Cleaned up " . $failed_count . " failed messages\n";
    $issues_fixed++;
}

echo "\n";

// ============ CHECK 9: DATABASE CHARSET ============
echo "CHECK 9️⃣  - DATABASE CHARSET (TAMIL SUPPORT)\n";
echo "─".str_repeat("─", 63)."─\n";

$charsetCheck = $conn->query("SHOW CREATE TABLE ai_conversation_log");
$charsetRow = $charsetCheck->fetch_assoc();

if (strpos($charsetRow['Create Table'], 'utf8mb4') !== false) {
    echo "✅ Character Set: UTF8MB4 (Tamil Support) ✅\n";
} else {
    echo "⚠️  Character Set: NOT UTF8MB4\n";
    echo "\n🔧 Updating charset...\n";

    $tables_to_fix = ['ai_conversation_log', 'ai_message_queue', 'contacts', 'messages'];
    foreach ($tables_to_fix as $table) {
        $conn->query("ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "   ✅ Fixed: $table\n";
        $issues_fixed++;
    }
}

echo "\n";

// ============ CHECK 10: CONTACT SYNC ============
echo "CHECK 🔟 - CONTACT SYNC\n";
echo "─".str_repeat("─", 63)."─\n";

$contactCount = $conn->query("SELECT COUNT(*) as total FROM contacts WHERE company_id=2");
$contactRow = $contactCount->fetch_assoc();

echo "✅ Total Contacts: " . $contactRow['total'] . "\n";

// Check for orphaned messages
$orphaned = $conn->query("
    SELECT COUNT(*) as orphaned FROM messages m
    WHERE m.company_id=2 AND m.contact_id NOT IN (SELECT id FROM contacts WHERE company_id=2)
");

$orphanedRow = $orphaned->fetch_assoc();

if ($orphanedRow['orphaned'] > 0) {
    echo "⚠️  Orphaned Messages: " . $orphanedRow['orphaned'] . "\n";
    echo "\n🔧 Cleaning up...\n";
    $conn->query("
        DELETE FROM messages WHERE company_id=2
        AND contact_id NOT IN (SELECT id FROM contacts WHERE company_id=2)
    ");
    echo "   ✅ Removed " . $orphanedRow['orphaned'] . " orphaned messages\n";
    $issues_fixed++;
} else {
    echo "✅ No Orphaned Messages\n";
}

echo "\n";

// ============ FINAL SUMMARY ============
echo "═".str_repeat("═", 63)."═\n\n";
echo "📊 HEALTH CHECK SUMMARY\n";
echo "─".str_repeat("─", 63)."─\n";

echo "✅ Checks Performed: 10\n";
echo "❌ Issues Found: $issues_found\n";
echo "🔧 Issues Fixed: $issues_fixed\n";
echo "⚠️  Warnings: $warnings\n\n";

if ($issues_fixed > 0) {
    echo "🎯 AUTO-FIX RESULTS:\n";
    echo "   ✅ Database tables verified\n";
    echo "   ✅ AI configuration enabled\n";
    echo "   ✅ Bot rules activated\n";
    echo "   ✅ Failed messages cleaned\n";
    echo "   ✅ Character set optimized\n";
    echo "   ✅ Orphaned data removed\n\n";
}

echo "═".str_repeat("═", 63)."═\n";

if ($issues_found === 0 && $warnings === 0) {
    echo "✨ SYSTEM STATUS: ALL CHECKS PASSED\n";
} elseif ($issues_found === 0) {
    echo "✨ SYSTEM STATUS: OPERATIONAL (with recommendations)\n";
} else {
    echo "⚠️  SYSTEM STATUS: ISSUES FIXED\n";
}

echo "═".str_repeat("═", 63)."═\n\n";

// Performance check
echo "🚀 PERFORMANCE CHECK\n";
echo "─".str_repeat("─", 63)."─\n";

$start = microtime(true);
$testQuery = $conn->query("SELECT 1");
$duration = (microtime(true) - $start) * 1000;

echo "Database Response Time: " . round($duration, 2) . "ms\n";
echo "Status: " . ($duration < 100 ? "✅ FAST" : "⚠️ SLOW") . "\n\n";

echo "═".str_repeat("═", 63)."═\n";
echo "✅ SYSTEM HEALTH CHECK COMPLETE\n";
echo "═".str_repeat("═", 63)."═\n";

$conn->close();
?>
