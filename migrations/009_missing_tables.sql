-- ============================================================
-- WAAMINI Migration 009: Missing Tables & Compatibility Views
-- Run once after initial setup. Safe to re-run (IF NOT EXISTS).
-- ============================================================

-- 1. IP lockout table (used by includes/security.php)
CREATE TABLE IF NOT EXISTS ip_lockouts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45) NOT NULL,
    attempts      INT         NOT NULL DEFAULT 1,
    last_attempt  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip_address),
    INDEX idx_last (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Login attempts table (used by includes/auth.php class)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL,
    attempts     INT          NOT NULL DEFAULT 1,
    last_attempt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email),
    INDEX idx_last (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. User remember-me tokens (used by includes/auth.php checkRememberMe)
CREATE TABLE IF NOT EXISTS user_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    selector   VARCHAR(64)  NOT NULL,
    token      VARCHAR(128) NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_selector (selector),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. message_logs compatibility VIEW (code still references this in analytics)
--    Points to the `messages` table so legacy queries don't break.
CREATE OR REPLACE VIEW message_logs AS
    SELECT
        id,
        company_id,
        contact_id,
        direction,
        type,
        message,
        media_url,
        status,
        is_read,
        whatsapp_message_id,
        NULL   AS campaign_id,
        created_at
    FROM messages;

-- 5. campaign_logs compatibility VIEW (cron_pulse_analyzer checks this)
CREATE OR REPLACE VIEW campaign_logs AS
    SELECT
        id,
        company_id,
        campaign_id,
        contact_id,
        phone,
        message_payload,
        status,
        scheduled_at,
        processed_at,
        error_message,
        created_at
    FROM campaign_queue;

-- 6. Ensure companies table has all columns used by the codebase
ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS welcome_msg         TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS welcome_enabled     TINYINT(1)   DEFAULT 0,
    ADD COLUMN IF NOT EXISTS welcome_media_url   VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS referral_points_value INT        DEFAULT 10,
    ADD COLUMN IF NOT EXISTS brochure_url        VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS fb_verify_token     VARCHAR(128) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS status              VARCHAR(50)  DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP;

-- 7. Ensure users table has all columns used at login and registration
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS company_id   INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS company      VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mobile       VARCHAR(20)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS role         VARCHAR(50)  DEFAULT 'admin',
    ADD COLUMN IF NOT EXISTS status       VARCHAR(50)  DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS two_factor_secret  VARCHAR(128) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_login   DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP;

-- 8. Ensure whatsapp_config has api_version column
ALTER TABLE whatsapp_config
    ADD COLUMN IF NOT EXISTS api_version VARCHAR(10) DEFAULT 'v18.0',
    ADD COLUMN IF NOT EXISTS status      VARCHAR(50) DEFAULT 'active';

-- 9. Ensure campaign_queue has processed_at column
ALTER TABLE campaign_queue
    ADD COLUMN IF NOT EXISTS processed_at DATETIME DEFAULT NULL;

-- 10. Ensure subscriptions table exists (used by client_auth_check + feature_check)
CREATE TABLE IF NOT EXISTS subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    company_id  INT         NOT NULL,
    plan_id     INT         DEFAULT NULL,
    status      ENUM('trial','active','expired','cancelled') DEFAULT 'trial',
    starts_at   DATETIME    DEFAULT NULL,
    expires_at  DATETIME    DEFAULT NULL,
    created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Ensure subscription_plans table exists
CREATE TABLE IF NOT EXISTS subscription_plans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    price       DECIMAL(10,2) DEFAULT 0.00,
    features    JSON         DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert a default free trial plan if none exist
INSERT IGNORE INTO subscription_plans (id, name, price)
VALUES (1, 'Free Trial', 0.00);

-- 12. Ensure chat_conversations has assigned_agent_id column
ALTER TABLE chat_conversations
    ADD COLUMN IF NOT EXISTS assigned_agent_id INT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ticket_status     VARCHAR(50) DEFAULT 'open';
