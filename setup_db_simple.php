<?php
/**
 * Simple DB setup (no bootstrap needed)
 */

// Load .env manually
$env = parse_ini_file(__DIR__ . '/.env');

$conn = new mysqli(
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? '',
    $env['DB_NAME'] ?? 'waamini'
);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

header('Content-Type: text/plain; charset=utf-8');

echo "Connected to database\n\n";

// Create tables
$sqls = [
    "CREATE TABLE IF NOT EXISTS ai_conversation_log (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      company_id INT NOT NULL,
      customer_phone VARCHAR(20) NOT NULL,
      role ENUM('user','assistant') NOT NULL,
      content LONGTEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_conv (company_id, customer_phone, id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS ai_message_queue (
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

    "CREATE TABLE IF NOT EXISTS ai_settings (
      company_id INT PRIMARY KEY,
      ai_enabled TINYINT(1) DEFAULT 0,
      max_replies_per_hour INT DEFAULT 10,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",

    "ALTER TABLE contacts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

    "INSERT INTO ai_settings (company_id, ai_enabled, max_replies_per_hour) VALUES (2, 1, 10) ON DUPLICATE KEY UPDATE ai_enabled=1",
];

$count = 0;
foreach ($sqls as $sql) {
    if ($conn->query($sql)) {
        $count++;
        echo "✅ " . substr($sql, 0, 60) . "...\n";
    } else {
        echo "⚠️  " . substr($sql, 0, 60) . "... (" . $conn->error . ")\n";
    }
}

echo "\n✅ Setup complete: $count operations executed\n";

// Verify
echo "\nVerifying...\n";
$check1 = $conn->query("SELECT COUNT(*) as c FROM ai_conversation_log")->fetch_assoc();
echo "✅ ai_conversation_log: " . $check1['c'] . " rows\n";

$check2 = $conn->query("SELECT COUNT(*) as c FROM ai_message_queue")->fetch_assoc();
echo "✅ ai_message_queue: " . $check2['c'] . " rows\n";

$check3 = $conn->query("SELECT ai_enabled FROM ai_settings WHERE company_id=2")->fetch_assoc();
echo "✅ AI enabled for company 2: " . ($check3['ai_enabled'] ? 'YES' : 'NO') . "\n";

$conn->close();
?>
