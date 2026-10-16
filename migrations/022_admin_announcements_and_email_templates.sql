-- Adds schema required by admin/announcements.php and admin/email_templates.php.
-- Same gap as 021: features built in code, never captured in a migration or the dump.

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
    target_plan_id INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO email_templates (name, slug, subject, body) VALUES
    ('Welcome Email', 'welcome', 'Welcome to WAAMINI, {{name}}!', 'Hi {{name}},\n\nWelcome to WAAMINI! Your account is ready to go.\n\nThanks,\nThe WAAMINI Team'),
    ('Subscription Expiry Reminder', 'subscription_expiry', 'Your {{plan_name}} plan expires on {{expiry_date}}', 'Hi {{name}},\n\nYour {{plan_name}} subscription expires on {{expiry_date}}. Renew now to avoid interruption.\n\nThanks,\nThe WAAMINI Team'),
    ('Payment Received', 'payment_received', 'Payment of {{amount}} received', 'Hi {{name}},\n\nWe have received your payment of {{amount}}. Thank you!\n\nThe WAAMINI Team');
