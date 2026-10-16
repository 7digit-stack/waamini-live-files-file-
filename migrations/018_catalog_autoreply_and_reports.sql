-- Migration 018: Catalog auto-reply, interaction tracking & reports
-- Date: 2026-07-14

-- Track every Pay Now / Enquire button click
CREATE TABLE IF NOT EXISTS catalog_interactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT DEFAULT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    interaction_type ENUM('pay_now','enquire','buy_now','more_products','catalog_view') NOT NULL,
    auto_reply_sent TINYINT(1) DEFAULT 0,
    auto_reply_message TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cat_int_company (company_id, interaction_type),
    INDEX idx_cat_int_product (product_id),
    INDEX idx_cat_int_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Auto-reply templates per company
CREATE TABLE IF NOT EXISTS catalog_auto_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    reply_type ENUM('pay_now','enquire','buy_now','catalog_view','order_confirm') NOT NULL,
    message_template TEXT NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_company_type (company_id, reply_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Insert default auto-reply templates for all companies
-- (These will be used as fallbacks; companies can customize via settings)
