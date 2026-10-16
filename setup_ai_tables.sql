-- ============================================================
-- WAAMINI AI Tables Setup
-- Run this in phpMyAdmin or mysql CLI
-- ============================================================

-- 1. Conversation memory log (UTF8MB4 for Tamil)
CREATE TABLE IF NOT EXISTS ai_conversation_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  customer_phone VARCHAR(20) NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_conv (company_id, customer_phone, id)
) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Message queue for async AI processing
CREATE TABLE IF NOT EXISTS ai_message_queue (
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
) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. AI configuration per company
CREATE TABLE IF NOT EXISTS ai_settings (
  company_id INT PRIMARY KEY,
  ai_enabled TINYINT(1) DEFAULT 0,
  max_replies_per_hour INT DEFAULT 10,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 4. Convert existing tables to UTF8MB4 (Tamil-safe)
ALTER TABLE contacts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 5. Enable AI for company 2 (YOUR COMPANY)
INSERT INTO ai_settings (company_id, ai_enabled, max_replies_per_hour)
VALUES (2, 1, 10)
ON DUPLICATE KEY UPDATE ai_enabled=1;
