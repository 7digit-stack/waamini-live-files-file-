-- Migration 016: Drip Champion Schema Extensions v2
-- Adds per-step randomization configuration, message variation tracking,
-- campaign-level anti-spam controls, and contact drip_meta column.
--
-- Safe to run multiple times (all ALTERs use IF NOT EXISTS style via TRY/CATCH).

-- ── drip_steps: per-step randomization config ───────────────────────────────
-- randomization_mode: none | minimal | human | ghost | chaos
--   none        → base delay only, no jitter (backward-safe default)
--   minimal     → pool-based tight jitter (1-5min windows)
--   human       → pool-based randomized intervals with business hour enforcement
--   ghost       → wide scatter + 15% silent gaps (4-24hrs)
--   chaos       → full non-linear: burst clusters + silence patterns
--
-- jitter_range_seconds: additional ± seconds cap on top of mode jitter
-- message_variations: JSON array of pre-generated text variants (index → text)
-- max_variants: cap on how many variants the engine generates per step

ALTER TABLE drip_steps
    ADD COLUMN randomization_mode   ENUM('none','minimal','human','ghost','chaos')
        NOT NULL DEFAULT 'human' AFTER delay_minutes,
    ADD COLUMN jitter_range_seconds INT NOT NULL DEFAULT 300 AFTER randomization_mode,
    ADD COLUMN message_variations   JSON        NULL             AFTER jitter_range_seconds,
    ADD COLUMN max_variants         TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER message_variations;

-- ── drip_executions: tracking per-execution variation state ──────────────────
-- visited_variants: JSON set of variant indices already sent for this execution
-- delivered_variant: last variant index that was actually delivered (for audit)
-- send_attempted_at: timestamp of last send attempt (NULL = never attempted)

ALTER TABLE drip_executions
    ADD COLUMN visited_variants   JSON        NULL             AFTER error_msg,
    ADD COLUMN delivered_variant  TINYINT UNSIGNED NULL     AFTER visited_variants,
    ADD COLUMN send_attempted_at  DATETIME    NULL             AFTER delivered_variant;

-- ── drip_campaigns: campaign-level anti-spam / scheduling controls ──────────
-- global_randomization: overrides all step modes if not 'none'
-- respect_business_hours: force business-hour delivery (default 1)
-- max_messages_per_day: anti-spam throttle per contact per 24h (default 10)
-- cooldown_hours: minimum hours between ANY company messages to same contact (default 4)

ALTER TABLE drip_campaigns
    ADD COLUMN global_randomization   ENUM('none','minimal','human','ghost','chaos')
        NOT NULL DEFAULT 'none' AFTER status,
    ADD COLUMN respect_business_hours TINYINT(1) NOT NULL DEFAULT 1 AFTER global_randomization,
    ADD COLUMN max_messages_per_day   TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER respect_business_hours,
    ADD COLUMN cooldown_hours        TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER max_messages_per_day;

-- ── contacts: drip enrollment history ──────────────────────────────────────
ALTER TABLE contacts
    ADD COLUMN drip_meta JSON NULL AFTER notes;

-- ── Indexes for performance ─────────────────────────────────────────────────

ALTER TABLE drip_executions
    ADD INDEX idx_company_attempted
        (company_id, send_attempted_at);

ALTER TABLE drip_steps
    ADD INDEX idx_campaign_randomization
        (drip_campaign_id, randomization_mode);

ALTER TABLE contacts
    ADD INDEX idx_company_drip_meta
        (company_id, (CAST(JSON_EXTRACT(drip_meta, '$') AS CHAR(255) ARRAY)));