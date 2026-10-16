-- ============================================================
-- WAAMINI Migration 012: API latency + scale indexes
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE whatsapp_api_logs
  ADD COLUMN IF NOT EXISTS duration_ms INT DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_company_code_time (company_id, http_code, created_at),
  ADD INDEX IF NOT EXISTS idx_company_latency (company_id, duration_ms, created_at);

-- Higher-volume message log patterns
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_company_direction_created (company_id, direction, created_at);

-- Queue scanning patterns
ALTER TABLE automation_queue
  ADD INDEX IF NOT EXISTS idx_company_status_sched (company_id, status, scheduled_at);

