-- Abandoned-cart table for client/settings_cart_recovery.php stats,
-- api/cart_event.php (writes from external storefronts), and
-- cron_cart_recovery.php (the actual recovery-message sender).

CREATE TABLE IF NOT EXISTS abandoned_carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    cart_data TEXT NULL,
    cart_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    recovery_status ENUM('pending','send1_pending','sent1','sent2','sent3','recovered') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cart_company_contact (company_id, contact_id),
    INDEX idx_abcarts_status (recovery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
