CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    direction ENUM('incoming','outgoing') NOT NULL,
    type VARCHAR(50) DEFAULT 'text',
    message TEXT,
    media_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'sent',
    is_read TINYINT(1) DEFAULT 0,
    whatsapp_message_id VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_contact (company_id, contact_id),
    INDEX idx_wa_msg_id (whatsapp_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE messages ADD COLUMN IF NOT EXISTS whatsapp_message_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_wa_msg_id (whatsapp_message_id);

CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT NOT NULL,
    last_message TEXT,
    last_message_at TIMESTAMP NULL DEFAULT NULL,
    unread_count INT DEFAULT 0,
    ticket_status VARCHAR(50) DEFAULT 'open',
    UNIQUE KEY unique_conv (company_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contact_id INT DEFAULT NULL,
    type VARCHAR(50) DEFAULT 'instant',
    phone VARCHAR(20) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    media_url VARCHAR(255) DEFAULT NULL,
    scheduled_at DATETIME NULL DEFAULT NULL,
    status ENUM('pending','processing','sent','failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_type_status (company_id, type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS scheduled_at DATETIME NULL DEFAULT NULL;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS media_url VARCHAR(255) DEFAULT NULL;
