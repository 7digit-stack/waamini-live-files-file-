<?php
/**
 * Migration: Add priority column to bot_replies table
 * This column is required by SmartRouter for priority-based ordering.
 * Without it, the SmartRouter query fails silently and ALL messages
 * bypass bot_replies, going straight to AI Sales Bot.
 */
require_once __DIR__ . '/../db.php';

echo "=== Fix Smart Router: bot_replies.priority column ===\n\n";

// Add priority column if missing
$result = $conn->query("SHOW COLUMNS FROM bot_replies LIKE 'priority'");
if ($result && $result->num_rows === 0) {
    $conn->query("ALTER TABLE bot_replies ADD COLUMN priority INT DEFAULT 0 AFTER is_active");
    echo "✅ Added 'priority' column to bot_replies\n";
} else {
    echo "ℹ️  'priority' column already exists\n";
}

// Also ensure message_routing_log table exists for debug logging
$conn->query("CREATE TABLE IF NOT EXISTS message_routing_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT DEFAULT 0,
    incoming_message TEXT,
    routed_to VARCHAR(50),
    matched_rule_id INT DEFAULT NULL,
    matched_keyword VARCHAR(255) DEFAULT NULL,
    response_sent TEXT,
    response_time_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_created (company_id, created_at)
)");
echo "✅ Ensured message_routing_log table exists\n";

echo "\n=== Migration complete ===\n";
