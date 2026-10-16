-- Migration 015: Drip campaign production reliability fixes
-- Safe to run multiple times (IF NOT EXISTS / IGNORE used throughout)

-- Add retry_count column for automatic retry of transient failures
ALTER TABLE drip_executions ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER status;
-- Ignore error if column already exists

-- Add error_msg column if missing (for debugging failed sends)
ALTER TABLE drip_executions ADD COLUMN error_msg TEXT NULL;
-- Ignore error if column already exists

-- Add last_http_code for API debugging
ALTER TABLE drip_executions ADD COLUMN last_http_code INT NULL;
-- Ignore error if column already exists

-- Add created_at if missing
ALTER TABLE drip_executions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
-- Ignore error if column already exists

-- Add sent_at if missing
ALTER TABLE drip_executions ADD COLUMN sent_at DATETIME NULL;
-- Ignore error if column already exists

-- Critical indexes for cron performance
-- Index on (status, scheduled_for) — the main cron query filter
CREATE INDEX idx_drip_exec_status_sched ON drip_executions (status, scheduled_for);
-- Ignore error if index already exists

-- Index on (contact_id, company_id) — for enrollment duplicate check
CREATE INDEX idx_drip_exec_contact_company ON drip_executions (contact_id, company_id);
-- Ignore error if index already exists

-- Index on drip_steps campaign lookup
CREATE INDEX idx_drip_steps_campaign ON drip_steps (drip_campaign_id);
-- Ignore error if index already exists

-- Add 'processing' to status enum if it's ENUM type (safe for VARCHAR too)
-- This prevents race conditions between cron_drip.php and cron_automation_sender.php
ALTER TABLE drip_executions MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending';
-- This changes ENUM to VARCHAR which accepts any value including 'processing'

-- Add trigger_label column to drip_campaigns if missing
ALTER TABLE drip_campaigns ADD COLUMN trigger_label VARCHAR(100) DEFAULT NULL;
-- Ignore error if column already exists

-- Add delay_minutes to drip_steps if missing
ALTER TABLE drip_steps ADD COLUMN delay_minutes INT DEFAULT 0;
-- Ignore error if column already exists
