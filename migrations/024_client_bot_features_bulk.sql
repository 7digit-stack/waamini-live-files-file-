-- Bulk schema catch-up for ~20 more client/*.php pages that were 500ing
-- with "Call to a member function bind_param() on false" (missing table or
-- column). Same root cause as 021-023: features built in code, schema
-- never captured in a migration or the dump. Grouped by page.

-- contacts: ai_lead_scores.php, pincode_campaign.php, polls.php, wa_polls.php
ALTER TABLE contacts
    ADD COLUMN ai_lead_score INT NULL,
    ADD COLUMN ai_sentiment VARCHAR(30) NULL,
    ADD COLUMN ai_score_updated DATETIME NULL,
    ADD COLUMN pincode VARCHAR(10) NULL,
    ADD COLUMN city VARCHAR(100) NULL,
    ADD COLUMN area VARCHAR(150) NULL,
    ADD COLUMN opted_in TINYINT(1) NOT NULL DEFAULT 1;

-- messages: analytics_agents.php, wa_health_monitor.php stats
ALTER TABLE messages
    ADD COLUMN responded_by INT NULL,
    ADD COLUMN response_time_seconds INT NULL;

-- companies: addon_packs.php credit balance
ALTER TABLE companies
    ADD COLUMN addon_message_credits INT NOT NULL DEFAULT 0;

-- ecommerce_orders: analytics_revenue.php / includes/upsell_handler.php
ALTER TABLE ecommerce_orders
    ADD COLUMN source_campaign_id INT NULL;

-- festival_campaigns: festival_calendar.php
ALTER TABLE festival_campaigns
    ADD COLUMN festival_region VARCHAR(30) NOT NULL DEFAULT 'all',
    ADD COLUMN days_before INT NOT NULL DEFAULT 1;

-- ab_testing.php
CREATE TABLE IF NOT EXISTS ab_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    message_a TEXT NOT NULL,
    message_b TEXT NOT NULL,
    split_pct INT NOT NULL DEFAULT 50,
    status ENUM('draft','running','completed') NOT NULL DEFAULT 'draft',
    sent_a INT NOT NULL DEFAULT 0,
    sent_b INT NOT NULL DEFAULT 0,
    winner ENUM('a','b','tie') NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ab_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- addon_packs.php
CREATE TABLE IF NOT EXISTS addon_packs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    messages_count INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS addon_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    pack_id INT NOT NULL,
    messages_count INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    gateway_order_id VARCHAR(100) NULL,
    gateway_payment_id VARCHAR(100) NULL,
    status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_addonp_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- form_builder.php / form_responses.php
CREATE TABLE IF NOT EXISTS wa_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    trigger_keyword VARCHAR(50) NOT NULL,
    welcome_message TEXT NULL,
    fields TEXT NULL,
    success_message TEXT NULL,
    notify_email VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_waforms_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    company_id INT NOT NULL,
    contact_id INT NULL,
    data TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wafs_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- gst_invoice_view.php / gst_invoices.php
CREATE TABLE IF NOT EXISTS gst_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    items_json TEXT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    cgst DECIMAL(10,2) NOT NULL DEFAULT 0,
    sgst DECIMAL(10,2) NOT NULL DEFAULT 0,
    igst DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('draft','sent','paid') NOT NULL DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gst_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings_booking.php / bookings.php
CREATE TABLE IF NOT EXISTS booking_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    vertical VARCHAR(30) NOT NULL DEFAULT 'restaurant',
    business_name VARCHAR(200) NULL,
    slots_per_day INT NOT NULL DEFAULT 10,
    slot_duration_mins INT NOT NULL DEFAULT 30,
    open_time TIME NOT NULL DEFAULT '09:00:00',
    close_time TIME NOT NULL DEFAULT '21:00:00',
    booking_message TEXT NULL,
    confirmation_message TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    booking_date DATE NOT NULL,
    booking_time TIME NULL,
    vertical VARCHAR(30) NULL,
    status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bookings_company (company_id),
    INDEX idx_bookings_date (booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings_cart_recovery.php
CREATE TABLE IF NOT EXISTS cart_recovery_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    delay_minutes INT NOT NULL DEFAULT 30,
    message1 TEXT NULL,
    message2 TEXT NULL,
    message3 TEXT NULL,
    discount_code VARCHAR(50) NULL,
    discount_pct INT NOT NULL DEFAULT 10,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings_emi_bot.php
CREATE TABLE IF NOT EXISTS emi_bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    trigger_keyword VARCHAR(50) NOT NULL DEFAULT 'emi',
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 12,
    available_tenures VARCHAR(100) NOT NULL DEFAULT '3,6,12,24,36',
    message_template TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings_order_tracking.php
CREATE TABLE IF NOT EXISTS order_tracking_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    trigger_keyword VARCHAR(50) NOT NULL DEFAULT 'order',
    tracking_message TEXT NULL,
    not_found_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings_school_fees.php (generic fee reminder system also used by cron_fee_reminder.php)
CREATE TABLE IF NOT EXISTS fee_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    school_name VARCHAR(200) NULL,
    reminder_days_before INT NOT NULL DEFAULT 3,
    reminder_message TEXT NULL,
    overdue_message TEXT NULL,
    receipt_prefix VARCHAR(20) NOT NULL DEFAULT 'RCP',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NULL,
    student_name VARCHAR(150) NOT NULL,
    class_section VARCHAR(50) NULL,
    parent_phone VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_feestu_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
    reminded_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_feerec_company (company_id),
    INDEX idx_feerec_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- stock_alerts.php
CREATE TABLE IF NOT EXISTS products_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(80) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prodcat_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    contact_id INT NOT NULL,
    notified TINYINT(1) NOT NULL DEFAULT 0,
    notified_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_waitlist_product (product_id),
    INDEX idx_waitlist_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- digital_vcard.php
CREATE TABLE IF NOT EXISTS digital_vcards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    name VARCHAR(150) NULL,
    designation VARCHAR(150) NULL,
    business_name VARCHAR(200) NULL,
    phone VARCHAR(20) NULL,
    whatsapp VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    website VARCHAR(255) NULL,
    address VARCHAR(500) NULL,
    tagline VARCHAR(255) NULL,
    logo_url VARCHAR(500) NULL,
    color_primary VARCHAR(20) NULL,
    services TEXT NULL,
    social_fb VARCHAR(255) NULL,
    social_ig VARCHAR(255) NULL,
    social_linkedin VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- polls.php AND wa_polls.php both target wa_polls with different column
-- names for the type/question field and different response tables
-- (poll_responses vs wa_poll_responses) — both included so neither breaks.
CREATE TABLE IF NOT EXISTS wa_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(200) NULL,
    type VARCHAR(20) NULL,
    poll_type VARCHAR(20) NULL,
    question TEXT NULL,
    options TEXT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wapolls_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    contact_id INT NULL,
    response TEXT NULL,
    score INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pollresp_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_poll_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    contact_id INT NULL,
    response TEXT NULL,
    score INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wapollresp_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
