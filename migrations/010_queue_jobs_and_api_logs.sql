-- ============================================================
-- WAAMINI Migration 010: Generic Queue + WhatsApp API Logs
-- Safe to re-run (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- 1) Generic background jobs table (RabbitMQ-style, DB-backed)
CREATE TABLE IF NOT EXISTS queue_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(80) NOT NULL,
  payload_json JSON DEFAULT NULL,
  status ENUM('pending','processing','retrying','failed','done') NOT NULL DEFAULT 'pending',
  run_at DATETIME DEFAULT NULL,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  last_error TEXT DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  lock_token VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  INDEX idx_status_run (status, run_at),
  INDEX idx_type_status (type, status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) WhatsApp API request/response logging (for audits + debugging)
CREATE TABLE IF NOT EXISTS whatsapp_api_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT DEFAULT NULL,
  job_id BIGINT DEFAULT NULL,
  direction ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
  endpoint VARCHAR(255) DEFAULT NULL,
  http_code INT DEFAULT NULL,
  request_json MEDIUMTEXT DEFAULT NULL,
  response_json MEDIUMTEXT DEFAULT NULL,
  curl_error VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_company_created (company_id, created_at),
  INDEX idx_job (job_id),
  INDEX idx_http (http_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Performance indexes often used in dashboards/inbox
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_company_created (company_id, created_at),
  ADD INDEX IF NOT EXISTS idx_company_status_created (company_id, status, created_at);

ALTER TABLE chat_conversations
  ADD INDEX IF NOT EXISTS idx_company_last (company_id, last_message_at);

ALTER TABLE automation_queue
  ADD INDEX IF NOT EXISTS idx_status_scheduled (status, scheduled_at);

