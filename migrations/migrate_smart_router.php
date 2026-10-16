<?php
/**
 * WAAMINI - Smart Message Router Migration
 * Adds/upgrades tables for the 3-tier routing system:
 *   Priority 1: Bot Replies (exact keyword match, case-insensitive)
 *   Priority 2: Flow Builder (keyword triggers for multi-step flows)
 *   Priority 3: AI Sales Bot (GPT/Gemini fallback)
 *
 * Run once: php migrations/migrate_smart_router.php
 */

require_once __DIR__ . '/../db.php';

echo "=== WAAMINI Smart Router Migration ===\n\n";

$queries = [];

// 1. Ensure bot_replies has all required columns
$queries[] = "ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS match_type ENUM('exact','contains','starts_with') DEFAULT 'exact' AFTER keyword";
$queries[] = "ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS priority INT DEFAULT 0 AFTER match_type";
$queries[] = "ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS reply_language VARCHAR(10) DEFAULT NULL AFTER reply_type";
$queries[] = "ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS professional_tone TINYINT(1) DEFAULT 1 AFTER reply_language";

// 2. Ensure chatbot_flows has proper trigger columns
$queries[] = "ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS trigger_type ENUM('keyword','manual','webhook') DEFAULT 'keyword'";
$queries[] = "ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS trigger_keyword VARCHAR(500) DEFAULT NULL";
$queries[] = "ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1";
$queries[] = "ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS company_id INT DEFAULT 0";
$queries[] = "ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS flow_type ENUM('step_by_step','single_response','interactive') DEFAULT 'step_by_step'";

// 3. Enhance ai_bot_settings for multi-provider support
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS ai_provider ENUM('openai','gemini') DEFAULT 'openai' AFTER is_active";
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS gemini_api_key VARCHAR(500) DEFAULT NULL AFTER openai_api_key";
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS ai_model VARCHAR(100) DEFAULT NULL AFTER gemini_api_key";
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS system_prompt TEXT DEFAULT NULL AFTER bot_persona";
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS max_context_messages INT DEFAULT 10 AFTER system_prompt";
$queries[] = "ALTER TABLE ai_bot_settings ADD COLUMN IF NOT EXISTS reply_language VARCHAR(20) DEFAULT 'auto' AFTER max_context_messages";

// 4. Message routing log for debugging
$queries[] = "CREATE TABLE IF NOT EXISTS message_routing_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    incoming_message TEXT,
    routed_to ENUM('bot_reply','flow_builder','ai_sales_bot','none') NOT NULL,
    matched_rule_id INT DEFAULT NULL,
    matched_keyword VARCHAR(255) DEFAULT NULL,
    response_sent TEXT,
    response_time_ms INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_created (company_id, created_at),
    INDEX idx_routed (routed_to, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// 5. Ensure chatbot_sessions has proper structure
$queries[] = "CREATE TABLE IF NOT EXISTS chatbot_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    flow_id INT NOT NULL,
    current_node_id INT DEFAULT NULL,
    session_data JSON DEFAULT NULL,
    status ENUM('active','completed','expired','cancelled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_active_session (company_id, contact_id, status),
    INDEX idx_flow (flow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Run all migrations
$success = 0;
$skipped = 0;
$errors = 0;

foreach ($queries as $sql) {
    try {
        if ($conn->query($sql)) {
            $success++;
            // Extract a short description
            if (preg_match('/(?:CREATE TABLE|ALTER TABLE)\s+(?:IF NOT EXISTS\s+)?(\S+)/i', $sql, $m)) {
                echo "  [OK] {$m[1]}\n";
            } else {
                echo "  [OK] Query executed\n";
            }
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
            $skipped++;
            echo "  [SKIP] Already exists\n";
        } else {
            $errors++;
            echo "  [ERROR] {$msg}\n";
        }
    }
}

echo "\n=== Migration Complete ===\n";
echo "Success: {$success} | Skipped: {$skipped} | Errors: {$errors}\n";
