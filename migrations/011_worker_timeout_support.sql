-- ============================================================
-- WAAMINI Migration 011: Worker timeout support & indexes
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

-- Track processing timeouts more accurately for automation_queue
ALTER TABLE automation_queue
  ADD COLUMN IF NOT EXISTS processing_started_at DATETIME DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_status_processing (status, processing_started_at);

