CREATE TABLE IF NOT EXISTS addon_packs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        messages_count INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) DEFAULT;
CREATE TABLE IF NOT EXISTS addon_purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        pack_id INT NOT NULL,
        messages_count INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        gateway_order_id VARCHAR(100) DEFAULT NULL,
        gateway_payment_id VARCHAR(100) DEFAULT NULL,
        status ENUM(;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS addon_message_credits INT DEFAULT 0;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS dob DATE DEFAULT NULL AFTER email;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS birthday_last_sent YEAR DEFAULT NULL AFTER dob;
CREATE TABLE IF NOT EXISTS birthday_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        sent_at DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT;
CREATE TABLE IF NOT EXISTS chatbot_flows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        trigger_keyword VARCHAR(100) DEFAULT NULL COMMENT;
CREATE TABLE IF NOT EXISTS chatbot_nodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        flow_id INT NOT NULL,
        node_type ENUM(;
CREATE TABLE IF NOT EXISTS chatbot_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        flow_id INT NOT NULL,
        current_node_id INT DEFAULT NULL,
        context_data JSON DEFAULT NULL COMMENT;
ALTER TABLE contacts ADD COLUMN lead_score INT DEFAULT 0 AFTER phone;
ALTER TABLE contacts ADD COLUMN opted_in TINYINT(1) DEFAULT 1 AFTER lead_score;
ALTER TABLE subscription_plans ADD COLUMN feat_drip_campaigns BOOLEAN DEFAULT FALSE;
CREATE TABLE IF NOT EXISTS drip_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        trigger_label VARCHAR(100) DEFAULT NULL,
        status ENUM(;
CREATE TABLE IF NOT EXISTS drip_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        drip_campaign_id INT NOT NULL,
        delay_days INT DEFAULT 0,
        delay_hours INT DEFAULT 0,
        template_id INT DEFAULT NULL,
        message_text TEXT DEFAULT NULL,
        step_order INT DEFAULT 1,
        FOREIGN KEY (drip_campaign_id) REFERENCES drip_campaigns(id) ON DELETE CASCADE
    );
CREATE TABLE IF NOT EXISTS drip_executions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        drip_step_id INT NOT NULL,
        status ENUM(;
CREATE TABLE IF NOT EXISTS gst_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL UNIQUE,
        business_name VARCHAR(200) NOT NULL,
        gstin VARCHAR(20) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        city VARCHAR(100) DEFAULT NULL,
        state VARCHAR(100) DEFAULT;
CREATE TABLE IF NOT EXISTS gst_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        order_id INT DEFAULT NULL,
        invoice_number VARCHAR(50) NOT NULL,
        invoice_date DATE NOT NULL,
        due_date DATE DEFAULT NULL,
        items_json JSON NOT NULL,
        subtotal DECIMAL(10,2) DEFAULT 0,
        cgst_amount DECIMAL(10,2) DEFAULT 0,
        sgst_amount DECIMAL(10,2) DEFAULT 0,
        igst_amount DECIMAL(10,2) DEFAULT 0,
        total_amount DECIMAL(10,2) DEFAULT 0,
        status ENUM(;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS invoice_id INT DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS customer_gstin VARCHAR(20) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS order_number VARCHAR(50) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS gstin VARCHAR(20) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS pan_number VARCHAR(15) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS business_address TEXT DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS state VARCHAR(100) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS pincode VARCHAR(10) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS invoice_prefix VARCHAR(20) DEFAULT;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS invoice_counter INT DEFAULT 1;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS bank_account VARCHAR(30) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS bank_ifsc VARCHAR(15) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS upi_id VARCHAR(100) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS logo_url VARCHAR(300) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS gst_rate DECIMAL(5,2) DEFAULT 18.00;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(10,2) DEFAULT 0;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) DEFAULT 0;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS customer_gstin VARCHAR(20) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS billing_address TEXT DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS invoice_sent TINYINT(1) DEFAULT 0;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS invoice_sent_at DATETIME DEFAULT NULL;
CREATE TABLE IF NOT EXISTS gst_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    contact_id INT NOT NULL,
    subtotal DECIMAL(10,2) DEFAULT 0,
    gst_rate DECIMAL(5,2) DEFAULT 18.00,
    gst_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    items_json JSON DEFAULT NULL,
    customer_gstin VARCHAR(20) DEFAULT NULL,
    billing_address TEXT DEFAULT NULL,
    sent_via_whatsapp TINYINT(1) DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_lead_score INT DEFAULT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_lead_label ENUM(;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS ai_lead_scored_at DATETIME DEFAULT NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS sentiment ENUM(;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS last_sentiment ENUM(;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS agent_id INT DEFAULT NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS response_time_seconds INT DEFAULT NULL;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS ab_variant ENUM(;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS ab_parent_id INT DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS campaign_id INT DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS attributed_to ENUM(;
CREATE TABLE IF NOT EXISTS industry_bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    industry ENUM(;
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    booking_type ENUM(;
CREATE TABLE IF NOT EXISTS property_listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    type ENUM(;
CREATE TABLE IF NOT EXISTS upsell_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trigger_keyword VARCHAR(200) DEFAULT NULL COMMENT;
CREATE TABLE IF NOT EXISTS winback_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    inactive_days INT DEFAULT 30,
    message TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS abandoned_carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    cart_items JSON DEFAULT NULL,
    total_amount DECIMAL(10,2) DEFAULT NULL,
    recovery_sent_at DATETIME DEFAULT NULL,
    status ENUM(;
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    type VARCHAR(50) DEFAULT;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS pincode VARCHAR(10) DEFAULT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS area VARCHAR(100) DEFAULT NULL;
ALTER TABLE subscription_plans ADD COLUMN feat_ocr_scanner TINYINT DEFAULT 0 AFTER ecommerce_cart_recovery;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS order_number VARCHAR(50) DEFAULT NULL AFTER id;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS customer_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS tracking_url VARCHAR(500) DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS estimated_delivery DATE DEFAULT NULL;
ALTER TABLE ecommerce_orders ADD COLUMN IF NOT EXISTS status_notes VARCHAR(300) DEFAULT NULL;
ALTER TABLE ecommerce_orders MODIFY COLUMN status ENUM(;
ALTER TABLE ecommerce_orders ADD UNIQUE INDEX IF NOT EXISTS idx_order_number (company_id, order_number);
CREATE TABLE IF NOT EXISTS order_tracking_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL UNIQUE,
        is_active TINYINT(1) DEFAULT 1,
        trigger_keyword VARCHAR(50) DEFAULT;
ALTER TABLE subscription_plans ADD COLUMN whatsapp_flows TINYINT DEFAULT 0 AFTER api_webhook_access;
ALTER TABLE subscription_plans ADD COLUMN ecommerce_cart_recovery TINYINT DEFAULT 0 AFTER whatsapp_flows;
CREATE TABLE IF NOT EXISTS message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(50) DEFAULT;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS language VARCHAR(10) DEFAULT;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS header_text TEXT DEFAULT NULL;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS body TEXT DEFAULT NULL;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS footer TEXT DEFAULT NULL;
ALTER TABLE message_templates ADD COLUMN IF NOT EXISTS wa_status VARCHAR(50) DEFAULT;
