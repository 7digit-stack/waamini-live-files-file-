-- ============================================================
-- WAAMINI - Run ALL Migrations in Order
-- Paste this entire file into phpMyAdmin SQL tab and Execute
-- Database must already exist: waamini_db
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Core tables first
CREATE TABLE IF NOT EXISTS companies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) DEFAULT NULL,
    phone      VARCHAR(20)  DEFAULT NULL,
    status     VARCHAR(50)  DEFAULT 'active',
    welcome_msg         TEXT         DEFAULT NULL,
    welcome_enabled     TINYINT(1)   DEFAULT 0,
    welcome_media_url   VARCHAR(255) DEFAULT NULL,
    referral_points_value INT        DEFAULT 10,
    brochure_url        VARCHAR(255) DEFAULT NULL,
    fb_verify_token     VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    company_id  INT          DEFAULT NULL,
    company     VARCHAR(255) DEFAULT NULL,
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    mobile      VARCHAR(20)  DEFAULT NULL,
    role        VARCHAR(50)  DEFAULT 'admin',
    status      VARCHAR(50)  DEFAULT 'active',
    two_factor_enabled TINYINT(1) DEFAULT 0,
    two_factor_secret  VARCHAR(128) DEFAULT NULL,
    last_login  DATETIME     DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email  (email),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_plans (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(100) NOT NULL,
    price    DECIMAL(10,2) DEFAULT 0.00,
    features JSON         DEFAULT NULL,
    created_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO subscription_plans (id, name, price) VALUES (1, 'Free Trial', 0.00);

CREATE TABLE IF NOT EXISTS subscriptions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT         NOT NULL,
    plan_id    INT         DEFAULT 1,
    status     ENUM('trial','active','expired','cancelled') DEFAULT 'trial',
    starts_at  DATETIME    DEFAULT NULL,
    expires_at DATETIME    DEFAULT NULL,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          NOT NULL,
    name            VARCHAR(255) DEFAULT NULL,
    first_name      VARCHAR(100) DEFAULT NULL,
    last_name       VARCHAR(100) DEFAULT NULL,
    phone           VARCHAR(20)  NOT NULL,
    email           VARCHAR(255) DEFAULT NULL,
    tags            TEXT         DEFAULT NULL,
    source          VARCHAR(50)  DEFAULT NULL,
    group_id        INT          DEFAULT NULL,
    assigned_to     INT          DEFAULT NULL,
    status          VARCHAR(50)  DEFAULT 'active',
    bot_enabled     TINYINT(1)   DEFAULT 0,
    loyalty_points  INT          DEFAULT 0,
    last_message_at TIMESTAMP    NULL DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company  (company_id),
    INDEX idx_phone    (phone),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    company_id          INT          NOT NULL,
    contact_id          INT          NOT NULL,
    campaign_id         INT          DEFAULT NULL,
    direction           ENUM('incoming','outgoing') NOT NULL,
    type                VARCHAR(50)  DEFAULT 'text',
    message             TEXT,
    media_url           VARCHAR(255) DEFAULT NULL,
    status              VARCHAR(50)  DEFAULT 'sent',
    is_read             TINYINT(1)   DEFAULT 0,
    whatsapp_message_id VARCHAR(255) DEFAULT NULL,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_contact (company_id, contact_id),
    INDEX idx_wa_msg_id (whatsapp_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_conversations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    company_id       INT          NOT NULL,
    contact_id       INT          NOT NULL,
    last_message     TEXT,
    last_message_at  TIMESTAMP    NULL DEFAULT NULL,
    unread_count     INT          DEFAULT 0,
    ticket_status    VARCHAR(50)  DEFAULT 'open',
    assigned_agent_id INT         DEFAULT NULL,
    UNIQUE KEY unique_conv (company_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_config (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    company_id       INT          NOT NULL UNIQUE,
    phone_number_id  VARCHAR(100) DEFAULT NULL,
    access_token     TEXT         DEFAULT NULL,
    waba_id          VARCHAR(100) DEFAULT NULL,
    api_version      VARCHAR(10)  DEFAULT 'v20.0',
    status           VARCHAR(50)  DEFAULT 'active',
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    company_id  INT          DEFAULT NULL,
    name        VARCHAR(255) NOT NULL,
    category    VARCHAR(50)  DEFAULT 'MARKETING',
    language    VARCHAR(10)  DEFAULT 'en',
    header_text TEXT         DEFAULT NULL,
    body_text   TEXT         DEFAULT NULL,
    body        TEXT         DEFAULT NULL,
    footer_text TEXT         DEFAULT NULL,
    status      VARCHAR(50)  DEFAULT 'pending',
    wa_status   VARCHAR(50)  DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    company_id       INT          DEFAULT NULL,
    name             VARCHAR(255) NOT NULL,
    template_id      INT          DEFAULT NULL,
    target_type      VARCHAR(50)  DEFAULT 'all',
    target_value     VARCHAR(255) DEFAULT NULL,
    group_id         INT          DEFAULT NULL,
    total_contacts   INT          DEFAULT 0,
    total_recipients INT          DEFAULT 0,
    sent_count       INT          DEFAULT 0,
    status           VARCHAR(50)  DEFAULT 'draft',
    scheduled_at     DATETIME     DEFAULT NULL,
    started_at       DATETIME     DEFAULT NULL,
    completed_at     DATETIME     DEFAULT NULL,
    created_by       INT          DEFAULT NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_queue (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          NOT NULL,
    campaign_id     INT          NOT NULL,
    contact_id      INT          DEFAULT NULL,
    phone           VARCHAR(20)  NOT NULL,
    message_payload TEXT         DEFAULT NULL,
    status          ENUM('pending','processing','sent','delivered','read','failed') DEFAULT 'pending',
    scheduled_at    DATETIME     DEFAULT NULL,
    processed_at    DATETIME     DEFAULT NULL,
    error_message   TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status     (status),
    INDEX idx_campaign   (campaign_id),
    INDEX idx_company    (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          DEFAULT NULL,
    campaign_id     INT          DEFAULT NULL,
    contact_id      INT          DEFAULT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    status          VARCHAR(50)  DEFAULT 'queued',
    message_payload TEXT         DEFAULT NULL,
    sent_at         DATETIME     DEFAULT NULL,
    delivered_at    DATETIME     DEFAULT NULL,
    error_message   TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chatbot_rules (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    company_id       INT          DEFAULT NULL,
    name             VARCHAR(255) NOT NULL,
    pattern          TEXT         NOT NULL,
    response_type    VARCHAR(50)  DEFAULT 'text',
    response_content TEXT         DEFAULT NULL,
    priority         INT          DEFAULT 0,
    status           VARCHAR(50)  DEFAULT 'active',
    case_sensitive   TINYINT(1)   DEFAULT 0,
    exact_match      TINYINT(1)   DEFAULT 0,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chatbot_flows (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          DEFAULT NULL,
    name            VARCHAR(150) NOT NULL,
    description     TEXT         DEFAULT NULL,
    trigger_keyword VARCHAR(100) DEFAULT NULL,
    steps           JSON         DEFAULT NULL,
    action_type     VARCHAR(50)  DEFAULT NULL,
    action_config   JSON         DEFAULT NULL,
    status          VARCHAR(50)  DEFAULT 'active',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    company_id   INT          NOT NULL,
    contact_id   INT          DEFAULT NULL,
    type         VARCHAR(50)  DEFAULT 'instant',
    phone        VARCHAR(20)  DEFAULT NULL,
    message      TEXT         DEFAULT NULL,
    media_url    VARCHAR(255) DEFAULT NULL,
    scheduled_at DATETIME     NULL DEFAULT NULL,
    status       ENUM('pending','processing','sent','failed') DEFAULT 'pending',
    error_msg    TEXT         DEFAULT NULL,
    sent_at      DATETIME     DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_type_status (company_id, type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_groups (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    company_id  INT          NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ip_lockouts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,
    attempts     INT          NOT NULL DEFAULT 1,
    last_attempt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL,
    attempts     INT          NOT NULL DEFAULT 1,
    last_attempt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    selector   VARCHAR(64)  NOT NULL,
    token      VARCHAR(128) NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_selector (selector)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drip_campaigns (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,
    name       VARCHAR(255) NOT NULL,
    status     VARCHAR(50)  DEFAULT 'active',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quick_replies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,
    shortcut   VARCHAR(50)  NOT NULL,
    message    TEXT         NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    company_id  INT          NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT         DEFAULT NULL,
    price       DECIMAL(10,2) DEFAULT 0.00,
    image_url   VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gst_invoices (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    company_id     INT          NOT NULL,
    contact_id     INT          DEFAULT NULL,
    invoice_number VARCHAR(50)  NOT NULL,
    invoice_date   DATE         NOT NULL,
    items_json     JSON         DEFAULT NULL,
    subtotal       DECIMAL(10,2) DEFAULT 0.00,
    cgst_amount    DECIMAL(10,2) DEFAULT 0.00,
    sgst_amount    DECIMAL(10,2) DEFAULT 0.00,
    igst_amount    DECIMAL(10,2) DEFAULT 0.00,
    total_amount   DECIMAL(10,2) DEFAULT 0.00,
    status         VARCHAR(50)  DEFAULT 'draft',
    wa_sent        TINYINT(1)   DEFAULT 0,
    wa_sent_at     DATETIME     DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gst_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    company_id    INT          NOT NULL UNIQUE,
    business_name VARCHAR(200) NOT NULL,
    gstin         VARCHAR(20)  DEFAULT NULL,
    address       TEXT         DEFAULT NULL,
    city          VARCHAR(100) DEFAULT NULL,
    state         VARCHAR(100) DEFAULT NULL,
    upi_id        VARCHAR(100) DEFAULT NULL,
    bank_name     VARCHAR(100) DEFAULT NULL,
    account_number VARCHAR(30) DEFAULT NULL,
    ifsc_code     VARCHAR(20)  DEFAULT NULL,
    footer_note   TEXT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_keys (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,
    api_key    VARCHAR(64)  NOT NULL UNIQUE,
    is_active  TINYINT(1)   DEFAULT 1,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,
    contact_id INT          NOT NULL,
    item_name  VARCHAR(255) NOT NULL,
    price      DECIMAL(10,2) DEFAULT 0.00,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bot_settings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    company_id   INT          NOT NULL UNIQUE,
    ai_enabled   TINYINT(1)   DEFAULT 0,
    ai_provider  VARCHAR(50)  DEFAULT 'openai',
    ai_model     VARCHAR(100) DEFAULT 'gpt-4o-mini',
    max_tokens   INT          DEFAULT 500,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_bot_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          NOT NULL UNIQUE,
    openai_api_key  TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_automation_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT          NOT NULL UNIQUE,
    followup_msg_1  TEXT         DEFAULT NULL,
    followup_days_1 INT          DEFAULT 3,
    followup_msg_2  TEXT         DEFAULT NULL,
    followup_days_2 INT          DEFAULT 7,
    thanks_msg      TEXT         DEFAULT NULL,
    converted_msg   TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Insert a default super_admin user (CHANGE PASSWORD after setup)
-- Default login: admin@waamini.com / Admin@123
-- ============================================================
INSERT IGNORE INTO companies (id, name, email, status) VALUES (1, 'Waamini Admin', 'admin@waamini.com', 'active');
INSERT IGNORE INTO users (id, company_id, name, email, password, role, status)
VALUES (1, 1, 'Super Admin', 'admin@waamini.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'super_admin', 'active');
