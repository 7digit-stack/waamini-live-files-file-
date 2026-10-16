-- Migration 014: Sales Tracker tables
-- Adds sales_log and sales_tracker_settings for WhatsApp POS feature

CREATE TABLE IF NOT EXISTS `sales_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `quantity` INT(11) NOT NULL DEFAULT 1,
    `price` DECIMAL(10,2) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `synced_to_google` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_company_date` (`company_id`, `created_at`),
    KEY `idx_company_item` (`company_id`, `item_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_tracker_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `google_sheet_url` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
