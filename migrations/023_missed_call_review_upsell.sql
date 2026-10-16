-- Schema for the missed-call auto-reply, Google review bot, and upsell bot
-- features (client/missed_call.php, client/settings_missed_call.php,
-- client/review_bot.php, client/settings_review_bot.php,
-- client/settings_upsell.php, api/missed_call_trigger.php,
-- api/missed_call_webhook.php, includes/upsell_handler.php,
-- cron_review_request.php). None of these were captured in a migration or
-- the dump; each pair of duplicate pages below writes to the same table
-- under a different, non-overlapping column set, so columns from both are
-- included so neither page breaks.

CREATE TABLE IF NOT EXISTS missed_call_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    message TEXT NULL,
    delay_seconds INT NOT NULL DEFAULT 5,
    track_calls TINYINT(1) NOT NULL DEFAULT 0,
    virtual_number VARCHAR(30) NULL,
    provider VARCHAR(30) NULL,
    api_key VARCHAR(255) NULL,
    api_secret VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS missed_calls_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NULL,
    phone VARCHAR(20) NULL,
    wa_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mcl_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS missed_call_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NULL,
    phone VARCHAR(20) NULL,
    wa_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mclogs_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS review_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    google_review_url VARCHAR(500) NULL,
    trigger_event VARCHAR(30) NOT NULL DEFAULT 'order_complete',
    delay_hours INT NOT NULL DEFAULT 2,
    message TEXT NULL,
    followup_message TEXT NULL,
    followup_days INT NOT NULL DEFAULT 3,
    send_after_days INT NOT NULL DEFAULT 1,
    min_orders_before INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS review_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    reviewed TINYINT(1) NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_review_company_contact (company_id, contact_id),
    INDEX idx_rr_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS upsell_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    delay_minutes INT NOT NULL DEFAULT 30,
    message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS upsell_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_up_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
