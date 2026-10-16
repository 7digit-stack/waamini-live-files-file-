-- Adds schema required by Google OAuth login (auth/google-callback.php,
-- admin/dashboard.php, admin/client_view.php, includes/admin_header.php,
-- api/admin_new_registrations.php), the audit_log table used across the
-- admin panel and register.php, and the admin/coupons.php coupon manager.
-- None of these were previously captured in a migration or the production dump.

ALTER TABLE users
    ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER password,
    ADD COLUMN avatar_url VARCHAR(500) NULL AFTER google_id;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    company_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_company (company_id),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    value DECIMAL(10,2) NOT NULL,
    expires_at DATE NULL,
    max_uses INT NOT NULL DEFAULT 0,
    used_count INT NOT NULL DEFAULT 0,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
