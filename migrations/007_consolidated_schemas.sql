-- Source: C:\xampp\htdocs\waamini\admin\plan_edit.php
ALTER TABLE subscription_plans ADD COLUMN `$_col` $_def;

-- Source: C:\xampp\htdocs\waamini\admin\profile.php
ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(255) NULL;

-- Source: C:\xampp\htdocs\waamini\admin\profile.php
ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\admin\users.php
ALTER TABLE users ADD COLUMN status ENUM(;

-- Source: C:\xampp\htdocs\waamini\api\process_automation_queue.php
CREATE TABLE IF NOT EXISTS automation_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        type VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\ab_testing.php
CREATE TABLE IF NOT EXISTS ab_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    message_a TEXT NOT NULL,
    message_b TEXT NOT NULL,
    split_pct INT DEFAULT 50 COMMENT;

-- Source: C:\xampp\htdocs\waamini\client\ai_lead_scores.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_lead_score INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\ai_lead_scores.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_sentiment ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\ai_lead_scores.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_score_updated DATETIME DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\analytics_agents.php
ALTER TABLE messages ADD COLUMN IF NOT EXISTS responded_by INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\analytics_agents.php
ALTER TABLE messages ADD COLUMN IF NOT EXISTS response_time_seconds INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\analytics_revenue.php
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS source_campaign_id INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\analytics_revenue.php
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS source_type VARCHAR(50) DEFAULT NULL COMMENT;

-- Source: C:\xampp\htdocs\waamini\client\bot_replies.php
ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS keyword VARCHAR(255) NOT NULL DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\bot_replies.php
ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS reply_content TEXT;

-- Source: C:\xampp\htdocs\waamini\client\bot_replies.php
ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS reply_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE campaigns ADD COLUMN group_id INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE campaigns ADD COLUMN scheduled_at DATETIME DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE campaigns ADD COLUMN media_url VARCHAR(500) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE campaigns ADD COLUMN media_type VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE campaigns ADD COLUMN send_type VARCHAR(20) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\campaign_create.php
ALTER TABLE contacts ADD COLUMN group_id INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\chatbot_builder.php
CREATE TABLE IF NOT EXISTS chatbot_nodes (id INT AUTO_INCREMENT PRIMARY KEY, flow_id INT NOT NULL, node_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\chatbot_flows.php
CREATE TABLE IF NOT EXISTS chatbot_flows (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, name VARCHAR(150) NOT NULL, trigger_keyword VARCHAR(100) DEFAULT NULL, trigger_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\chatbot_flows.php
CREATE TABLE IF NOT EXISTS chatbot_sessions (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, flow_id INT NOT NULL, current_node_id INT DEFAULT NULL, status ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\contact_groups.php
CREATE TABLE IF NOT EXISTS contact_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\contact_groups.php
ALTER TABLE contacts ADD COLUMN group_id INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\contact_groups.php
ALTER TABLE contacts ADD INDEX idx_contacts_group (group_id);

-- Source: C:\xampp\htdocs\waamini\client\contact_import.php
ALTER TABLE contacts ADD COLUMN group_id INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\digital_vcard.php
CREATE TABLE IF NOT EXISTS digital_vcards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    designation VARCHAR(150) DEFAULT NULL,
    business_name VARCHAR(200) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    whatsapp VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    website VARCHAR(300) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    tagline VARCHAR(300) DEFAULT NULL,
    logo_url VARCHAR(500) DEFAULT NULL,
    color_primary VARCHAR(10) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\festival_calendar.php
CREATE TABLE IF NOT EXISTS festival_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    festival_name VARCHAR(150) NOT NULL,
    festival_date DATE NOT NULL,
    festival_region VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\festival_campaigns.php
CREATE TABLE IF NOT EXISTS festival_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    festival_key VARCHAR(100) NOT NULL,
    festival_name VARCHAR(150) NOT NULL,
    festival_date DATE NOT NULL,
    message TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sent TINYINT(1) DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_date (festival_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\form_builder.php
CREATE TABLE IF NOT EXISTS wa_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    trigger_keyword VARCHAR(100) NOT NULL,
    welcome_message TEXT DEFAULT NULL,
    fields JSON NOT NULL,
    success_message TEXT DEFAULT NULL,
    notify_email VARCHAR(150) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    submissions INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_trigger (trigger_keyword)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\form_builder.php
CREATE TABLE IF NOT EXISTS wa_form_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    current_field INT DEFAULT 0,
    responses JSON DEFAULT NULL,
    status ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\form_builder.php
CREATE TABLE IF NOT EXISTS wa_form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    data JSON NOT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\growth.php
ALTER TABLE companies ADD COLUMN IF NOT EXISTS welcome_media_url VARCHAR(255) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\growth.php
ALTER TABLE companies ADD COLUMN IF NOT EXISTS brochure_url VARCHAR(255) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\growth.php
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS media_url VARCHAR(255) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\industry_bots.php
CREATE TABLE IF NOT EXISTS chatbot_flows (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, name VARCHAR(150) NOT NULL, trigger_keyword VARCHAR(100) DEFAULT NULL, trigger_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\industry_bots.php
CREATE TABLE IF NOT EXISTS chatbot_nodes (id INT AUTO_INCREMENT PRIMARY KEY, flow_id INT NOT NULL, node_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN assigned_to INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN bot_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN reply_bot_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN chat_labels VARCHAR(255) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN notes TEXT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN last_message_at TIMESTAMP NULL DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\live_chat.php
ALTER TABLE contacts ADD COLUMN status VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\medicine_reminder.php
CREATE TABLE IF NOT EXISTS medicine_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    medicine_name VARCHAR(200) NOT NULL,
    dosage VARCHAR(100) DEFAULT NULL,
    frequency ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\medicine_reminders.php
CREATE TABLE IF NOT EXISTS medicine_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    patient_name VARCHAR(150) NOT NULL,
    medicines JSON NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    reminder_times JSON NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_sent_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\missed_call.php
CREATE TABLE IF NOT EXISTS missed_call_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    message TEXT DEFAULT NULL,
    delay_seconds INT DEFAULT 5,
    track_calls TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\missed_call.php
CREATE TABLE IF NOT EXISTS missed_calls_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    contact_id INT DEFAULT NULL,
    wa_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\pincode_campaign.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS pincode VARCHAR(10) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\pincode_campaign.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\pincode_campaign.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS area VARCHAR(100) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\polls.php
CREATE TABLE IF NOT EXISTS wa_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\polls.php
CREATE TABLE IF NOT EXISTS poll_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    response TEXT DEFAULT NULL,
    score INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_poll (poll_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\premium_ai_bot.php
CREATE TABLE IF NOT EXISTS ai_bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 0,
    openai_api_key VARCHAR(255) DEFAULT NULL,
    bot_persona TEXT DEFAULT NULL,
    fallback_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\premium_ai_bot.php
CREATE TABLE IF NOT EXISTS automation_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT DEFAULT NULL,
    type VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\profile.php
ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(255) NULL;

-- Source: C:\xampp\htdocs\waamini\client\profile.php
ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\review_bot.php
CREATE TABLE IF NOT EXISTS review_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    google_review_url VARCHAR(500) DEFAULT NULL,
    trigger_event ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\review_bot.php
CREATE TABLE IF NOT EXISTS review_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    followup_sent_at DATETIME DEFAULT NULL,
    reviewed TINYINT(1) DEFAULT 0,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\school_fees.php
CREATE TABLE IF NOT EXISTS school_students (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, student_name VARCHAR(150) NOT NULL, class_name VARCHAR(50), section VARCHAR(10), roll_number VARCHAR(20), parent_name VARCHAR(150), parent_phone VARCHAR(20) NOT NULL, is_active TINYINT(1) DEFAULT 1, admission_number VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_company (company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\school_fees.php
CREATE TABLE IF NOT EXISTS school_fees (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, student_id INT NOT NULL, fee_type VARCHAR(100) NOT NULL, amount DECIMAL(10,2) NOT NULL, due_date DATE NOT NULL, paid_date DATE DEFAULT NULL, paid_amount DECIMAL(10,2) DEFAULT 0, status ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\school_fees.php
CREATE TABLE IF NOT EXISTS school_fee_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, school_name VARCHAR(200), reminder_days_before INT DEFAULT 3, reminder_message TEXT, overdue_message TEXT, receipt_message TEXT, auto_remind TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_birthday.php
CREATE TABLE IF NOT EXISTS birthday_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    birthday_message TEXT NOT NULL DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_booking.php
CREATE TABLE IF NOT EXISTS booking_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, vertical ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\settings_booking.php
CREATE TABLE IF NOT EXISTS bookings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, booking_date DATE NOT NULL, booking_time TIME NOT NULL, notes TEXT, status ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
CREATE TABLE IF NOT EXISTS bot_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        ai_enabled TINYINT(1) DEFAULT 0,
        ai_provider VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN ai_provider VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN ai_model VARCHAR(100) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN ai_api_key VARCHAR(500) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN system_prompt TEXT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN max_tokens INT DEFAULT 500;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN ai_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE bot_settings ADD COLUMN demo_alert_number VARCHAR(100) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_bot.php
ALTER TABLE companies ADD COLUMN feat_native_checkout TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\settings_cart_recovery.php
CREATE TABLE IF NOT EXISTS cart_recovery_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    delay_minutes INT DEFAULT 30,
    message1 TEXT DEFAULT NULL,
    message2 TEXT DEFAULT NULL,
    message3 TEXT DEFAULT NULL,
    discount_code VARCHAR(50) DEFAULT NULL,
    discount_pct INT DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_cart_recovery.php
CREATE TABLE IF NOT EXISTS abandoned_carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    cart_data JSON DEFAULT NULL,
    cart_total DECIMAL(10,2) DEFAULT 0,
    recovery_status ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\settings_emi_bot.php
CREATE TABLE IF NOT EXISTS emi_bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    trigger_keyword VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_general.php
ALTER TABLE companies ADD COLUMN $col $type DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\settings_gsheets.php
ALTER TABLE companies ADD COLUMN google_sheet_webhook VARCHAR(255) NULL;

-- Source: C:\xampp\htdocs\waamini\client\settings_gst.php
CREATE TABLE IF NOT EXISTS gst_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, business_name VARCHAR(200) NOT NULL, gstin VARCHAR(20) DEFAULT NULL, address TEXT DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, state VARCHAR(100) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_missed_call.php
CREATE TABLE IF NOT EXISTS missed_call_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    message TEXT DEFAULT NULL,
    virtual_number VARCHAR(20) DEFAULT NULL,
    provider VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_missed_call.php
CREATE TABLE IF NOT EXISTS missed_call_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    caller_number VARCHAR(20) NOT NULL,
    called_at DATETIME DEFAULT NULL,
    wa_sent TINYINT(1) DEFAULT 0,
    wa_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_order_tracking.php
CREATE TABLE IF NOT EXISTS order_tracking_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, is_active TINYINT(1) DEFAULT 1, trigger_keyword VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_review_bot.php
CREATE TABLE IF NOT EXISTS review_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    google_review_url VARCHAR(500) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    send_after_days INT DEFAULT 1,
    min_orders_before INT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_review_bot.php
CREATE TABLE IF NOT EXISTS review_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    clicked TINYINT(1) DEFAULT 0,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_school_fees.php
CREATE TABLE IF NOT EXISTS fee_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, school_name VARCHAR(200) DEFAULT NULL, reminder_days_before INT DEFAULT 3, reminder_message TEXT DEFAULT NULL, overdue_message TEXT DEFAULT NULL, receipt_prefix VARCHAR(20) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\settings_upsell.php
CREATE TABLE IF NOT EXISTS upsell_settings (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE, is_active TINYINT(1) DEFAULT 1, delay_minutes INT DEFAULT 30, message TEXT DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\settings_upsell.php
CREATE TABLE IF NOT EXISTS upsell_products (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, name VARCHAR(150) NOT NULL, price DECIMAL(10,2) NOT NULL, description VARCHAR(300) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\stock_alerts.php
CREATE TABLE IF NOT EXISTS products_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT 0,
    stock_qty INT DEFAULT 0,
    image_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\stock_alerts.php
CREATE TABLE IF NOT EXISTS stock_waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    contact_id INT NOT NULL,
    notified TINYINT(1) DEFAULT 0,
    notified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_waitlist (company_id, product_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\team_members.php
ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\team_members.php
ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\vehicle_reminder.php
CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    vehicle_number VARCHAR(20) NOT NULL,
    vehicle_name VARCHAR(150) DEFAULT NULL,
    vehicle_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\vehicle_reminders.php
CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    vehicle_number VARCHAR(30) NOT NULL,
    vehicle_type VARCHAR(100) DEFAULT NULL,
    make_model VARCHAR(150) DEFAULT NULL,
    year INT DEFAULT NULL,
    last_service_date DATE DEFAULT NULL,
    next_service_date DATE DEFAULT NULL,
    service_km INT DEFAULT NULL,
    insurance_expiry DATE DEFAULT NULL,
    pollution_expiry DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_next_service (next_service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\wa_health.php
CREATE TABLE IF NOT EXISTS wa_health_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    check_time DATETIME NOT NULL,
    quality_rating VARCHAR(20) DEFAULT NULL,
    messaging_limit VARCHAR(50) DEFAULT NULL,
    display_phone_number VARCHAR(30) DEFAULT NULL,
    verified_name VARCHAR(200) DEFAULT NULL,
    account_status VARCHAR(50) DEFAULT NULL,
    blocked_count INT DEFAULT 0,
    spam_rate DECIMAL(5,2) DEFAULT 0,
    delivery_rate DECIMAL(5,2) DEFAULT 0,
    raw_response TEXT DEFAULT NULL,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\wa_health_monitor.php
CREATE TABLE IF NOT EXISTS wa_health_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    quality_rating VARCHAR(20) DEFAULT NULL,
    messaging_limit VARCHAR(50) DEFAULT NULL,
    account_status VARCHAR(50) DEFAULT NULL,
    banned_contacts INT DEFAULT 0,
    delivery_rate DECIMAL(5,2) DEFAULT 0,
    read_rate DECIMAL(5,2) DEFAULT 0,
    failed_rate DECIMAL(5,2) DEFAULT 0,
    risk_level ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\wa_polls.php
CREATE TABLE IF NOT EXISTS wa_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    poll_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\client\wa_polls.php
CREATE TABLE IF NOT EXISTS wa_poll_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    company_id INT NOT NULL,
    contact_id INT DEFAULT NULL,
    response VARCHAR(200) NOT NULL,
    score INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN assigned_to INT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN bot_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN reply_bot_enabled TINYINT(1) DEFAULT 0;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN chat_labels VARCHAR(255) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN notes TEXT DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN last_message_at TIMESTAMP NULL DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN status VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE contacts ADD COLUMN language VARCHAR(50) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE automation_queue ADD COLUMN sent_at DATETIME NULL;

-- Source: C:\xampp\htdocs\waamini\client\whatsapp.php
ALTER TABLE automation_queue ADD COLUMN error_msg VARCHAR(255) NULL;

-- Source: C:\xampp\htdocs\waamini\cron_winback.php
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS winback_sent_at DATETIME DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\cron_winback.php
CREATE TABLE IF NOT EXISTS winback_settings (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1, winback_message TEXT DEFAULT NULL,
    inactive_days INT DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: C:\xampp\htdocs\waamini\fix_queue.php
ALTER TABLE campaign_queue ADD COLUMN error_message TEXT;

-- Source: C:\xampp\htdocs\waamini\fix_schema.php
ALTER TABLE campaign_queue ADD COLUMN company_id INT(11) AFTER id;

-- Source: C:\xampp\htdocs\waamini\fix_schema.php
ALTER TABLE campaign_queue ADD COLUMN message_payload TEXT AFTER phone;

-- Source: C:\xampp\htdocs\waamini\includes\booking_bot_handler.php
CREATE TABLE IF NOT EXISTS chatbot_sessions (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, flow_id INT NOT NULL, current_node_id INT DEFAULT NULL, context_data JSON DEFAULT NULL, status ENUM(;

-- Source: C:\xampp\htdocs\waamini\migrate_smart_trigger.php
ALTER TABLE campaign_queue ADD COLUMN trigger_on_activity TINYINT(1) DEFAULT 0 AFTER scheduled_at;

-- Source: C:\xampp\htdocs\waamini\migrate_smart_trigger.php
ALTER TABLE campaigns ADD COLUMN trigger_on_activity TINYINT(1) DEFAULT 0 AFTER scheduled_at;

-- Source: C:\xampp\htdocs\waamini\temp_upgrade_chat.php
ALTER TABLE chat_conversations ADD COLUMN company_id INT AFTER id;

-- Source: C:\xampp\htdocs\waamini\temp_upgrade_chat.php
ALTER TABLE chat_conversations ADD COLUMN ticket_status ENUM(;

-- Source: C:\xampp\htdocs\waamini\temp_upgrade_chat.php
ALTER TABLE chat_conversations ADD COLUMN assigned_agent_id INT NULL AFTER ticket_status;

-- Source: C:\xampp\htdocs\waamini\temp_upgrade_chat.php
ALTER TABLE chat_conversations ADD CONSTRAINT fk_assigned_agent FOREIGN KEY (assigned_agent_id) REFERENCES users(id) ON DELETE SET NULL;

-- Source: C:\xampp\htdocs\waamini\update_bot_nodes.php
ALTER TABLE chatbot_nodes MODIFY COLUMN node_type ENUM(;

-- Source: C:\xampp\htdocs\waamini\update_bot_nodes.php
ALTER TABLE chatbot_nodes ADD COLUMN product_id INT DEFAULT NULL AFTER save_response_as;

-- Source: C:\xampp\htdocs\waamini\update_bot_nodes.php
ALTER TABLE chatbot_nodes ADD COLUMN action_type VARCHAR(50) DEFAULT;

-- Source: C:\xampp\htdocs\waamini\update_growth_db.php
ALTER TABLE companies ADD COLUMN IF NOT EXISTS welcome_media_url VARCHAR(255) DEFAULT NULL;

-- Source: C:\xampp\htdocs\waamini\update_growth_db.php
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS media_url VARCHAR(255) DEFAULT NULL;

