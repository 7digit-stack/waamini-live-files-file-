-- ============================================================
-- WAAMINI Migration 013: High-impact performance indexes
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

-- Users table lookups
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_company_role_status (company_id, role, status),
  ADD INDEX IF NOT EXISTS idx_created (created_at);

-- Campaigns listing filters
ALTER TABLE campaigns
  ADD INDEX IF NOT EXISTS idx_company_created (company_id, created_at),
  ADD INDEX IF NOT EXISTS idx_company_status_created (company_id, status, created_at);

-- Messages for chat views and dashboards
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_company_contact_id (company_id, contact_id, id),
  ADD INDEX IF NOT EXISTS idx_contact_created (contact_id, created_at);

-- Audit log / system logs (if present)
ALTER TABLE audit_log
  ADD INDEX IF NOT EXISTS idx_created_at (created_at);

