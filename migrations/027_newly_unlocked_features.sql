-- Schema for the 11 client-panel pages unlocked by migration 026
-- (they were previously unreachable, so these gaps were never hit).

-- gst_invoices.php uses a different column set than gst_invoice_view.php
-- on the same table (021-025 pattern) — add both so neither breaks.
ALTER TABLE gst_invoices
    ADD COLUMN subject VARCHAR(255) NULL,
    ADD COLUMN notes TEXT NULL,
    ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN cgst_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN sgst_amount DECIMAL(10,2) NOT NULL DEFAULT 0;

-- referrals.php
ALTER TABLE companies
    ADD COLUMN referral_code VARCHAR(20) NULL UNIQUE,
    ADD COLUMN referred_by INT NULL;

CREATE TABLE IF NOT EXISTS referral_earnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    referred_company_id INT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','paid') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refearn_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- notifications.php
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    alert_leads_email TINYINT(1) NOT NULL DEFAULT 1,
    alert_leads_wa TINYINT(1) NOT NULL DEFAULT 1,
    alert_campaign_email TINYINT(1) NOT NULL DEFAULT 1,
    alert_campaign_wa TINYINT(1) NOT NULL DEFAULT 1,
    alert_wallet_email TINYINT(1) NOT NULL DEFAULT 1,
    alert_wallet_wa TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scheduled_messages.php
CREATE TABLE IF NOT EXISTS scheduled_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    template_id INT NOT NULL DEFAULT 0,
    scheduled_at DATETIME NOT NULL,
    status ENUM('pending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedmsg_company (company_id),
    INDEX idx_schedmsg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scheduled_messages.php's template picker queries whatsapp_templates
-- rather than the existing message_templates table (naming mismatch,
-- same duplicate-implementation pattern as elsewhere in this codebase).
CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_watpl_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
