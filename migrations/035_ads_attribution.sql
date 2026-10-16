-- Ads & Attribution — Phase 1: Click-to-WhatsApp referral capture.
-- IDs are INT to match contacts.id / companies.id. Also applied automatically by includes/ads/schema.php.
CREATE TABLE IF NOT EXISTS ad_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  contact_id INT NOT NULL,
  conversation_id INT NULL,
  message_id VARCHAR(128) NOT NULL,
  source_type VARCHAR(20) NOT NULL,
  source_id VARCHAR(64) NULL,
  source_url VARCHAR(512) NULL,
  headline VARCHAR(255) NULL,
  body TEXT NULL,
  media_type VARCHAR(20) NULL,
  media_url TEXT NULL,
  thumbnail_url TEXT NULL,
  local_media_path VARCHAR(512) NULL,
  media_status ENUM('none','pending','processing','done','failed') NOT NULL DEFAULT 'none',
  media_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ctwa_clid VARCHAR(512) NULL,
  raw_json LONGTEXT NULL,
  clicked_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_message (company_id, message_id),
  KEY idx_company_source (company_id, source_id),
  KEY idx_company_contact (company_id, contact_id),
  KEY idx_company_clicked (company_id, clicked_at),
  KEY idx_media_status (media_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ad_catalog (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  source_id VARCHAR(64) NOT NULL,
  source_type VARCHAR(20) NOT NULL,
  headline VARCHAR(255) NULL,
  media_type VARCHAR(20) NULL,
  local_thumbnail_path VARCHAR(512) NULL,
  ad_name VARCHAR(255) NULL,
  campaign_id VARCHAR(64) NULL,
  campaign_name VARCHAR(255) NULL,
  adset_id VARCHAR(64) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_company_ad (company_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE contacts ADD COLUMN IF NOT EXISTS lead_source ENUM('organic','ad','post','import','manual') NOT NULL DEFAULT 'organic';
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS first_ad_referral_id INT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS last_ad_referral_id INT NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS latest_ctwa_clid VARCHAR(512) NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS lead_stage ENUM('new','qualified','won','lost') NOT NULL DEFAULT 'new';
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS lead_stage_at DATETIME NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS won_value DECIMAL(12,2) NULL;
ALTER TABLE contacts ADD INDEX IF NOT EXISTS idx_company_lead_source (company_id, lead_source);

-- Stage from the outcome labels already set in WhatsApp Chat
UPDATE contacts SET lead_stage = CASE
    WHEN LOWER(chat_labels) LIKE '%converted%' THEN 'won'
    WHEN LOWER(chat_labels) LIKE '%declined%' OR LOWER(chat_labels) LIKE '%not interested%' THEN 'lost'
    WHEN LOWER(chat_labels) LIKE '%follow-up%' OR LOWER(chat_labels) LIKE '%follow up%' THEN 'qualified'
    ELSE lead_stage END
WHERE lead_stage = 'new' AND chat_labels IS NOT NULL AND chat_labels <> '';
