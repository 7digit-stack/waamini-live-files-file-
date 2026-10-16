-- WAAMINI Bot: follow-up message after the customer stops replying (1-23 h, inside the 24-hour window)
-- and quick instructions ("Never share prices", ...). Also applied at runtime by WB_Store::ensureSchema().
ALTER TABLE ai_bots ADD COLUMN followup_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ai_bots ADD COLUMN followup_hours TINYINT NOT NULL DEFAULT 1;
ALTER TABLE ai_bots ADD COLUMN quick_rules LONGTEXT NULL;

-- one follow-up per silence: keyed on the customer's last message
CREATE TABLE IF NOT EXISTS ai_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  contact_id INT NOT NULL,
  last_incoming_id BIGINT NOT NULL,
  status VARCHAR(20) NOT NULL,
  message_id INT NULL,
  text TEXT NULL,
  error VARCHAR(300) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup (company_id, contact_id, last_incoming_id),
  KEY idx_company_time (company_id, created_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
