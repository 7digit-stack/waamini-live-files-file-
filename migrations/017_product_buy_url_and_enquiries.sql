-- Migration 017: Add buy_url to products + formalize product_enquiries table
-- Date: 2026-07-14

-- Add buy_url column to products table
ALTER TABLE products ADD COLUMN buy_url VARCHAR(500) DEFAULT NULL AFTER image_url;

-- Formalize product_enquiries table (already created dynamically in whatsapp_catalog_checkout.php)
CREATE TABLE IF NOT EXISTS product_enquiries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT DEFAULT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_id INT DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('new','contacted','converted','closed') DEFAULT 'new',
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enq_company (company_id, status),
    INDEX idx_enq_phone (customer_phone),
    INDEX idx_enq_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
