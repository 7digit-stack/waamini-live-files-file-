-- WhatsApp Native Forms: Meta flow tracking + structured responses.
-- Also applied automatically at runtime by waf_ensure_schema() in includes/wa_flows.php.
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS meta_flow_id VARCHAR(64) NULL;
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS meta_status VARCHAR(20) NULL;
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS category VARCHAR(40) NOT NULL DEFAULT 'OTHER';
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS last_error TEXT NULL;
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS published_at DATETIME NULL;
ALTER TABLE whatsapp_flows ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS whatsapp_flow_responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  flow_id INT NULL,
  contact_id INT NULL,
  phone VARCHAR(32) NULL,
  wa_message_id VARCHAR(128) NULL,
  flow_token VARCHAR(128) NULL,
  response_json LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company_flow (company_id, flow_id),
  UNIQUE KEY uq_wamid (company_id, wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
