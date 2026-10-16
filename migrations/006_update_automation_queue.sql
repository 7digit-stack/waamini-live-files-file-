ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS payload_json TEXT DEFAULT NULL;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS last_error TEXT DEFAULT NULL;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS processed_at DATETIME DEFAULT NULL;
ALTER TABLE automation_queue ADD COLUMN IF NOT EXISTS wa_message_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE automation_queue MODIFY COLUMN status ENUM('pending','processing','sent','failed','retrying') DEFAULT 'pending';
