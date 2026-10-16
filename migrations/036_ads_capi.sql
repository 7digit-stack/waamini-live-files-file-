-- Ads & Attribution — Phase 2: Conversions API for Business Messaging.
-- Also applied automatically by includes/ads/schema.php (ads_ensure_capi_schema).
CREATE TABLE IF NOT EXISTS capi_settings (
  company_id INT NOT NULL PRIMARY KEY,
  dataset_id VARCHAR(64) NULL,
  access_token_enc TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  mapping_json TEXT NULL,
  send_value TINYINT(1) NOT NULL DEFAULT 1,
  window_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
  test_event_code VARCHAR(64) NULL,
  last_test_at DATETIME NULL,
  last_test_result TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS capi_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  contact_id INT NOT NULL,
  ad_referral_id INT NULL,
  stage VARCHAR(20) NULL,
  event_name VARCHAR(64) NOT NULL,
  event_id VARCHAR(191) NOT NULL,
  event_time INT UNSIGNED NOT NULL,
  value DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status ENUM('pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  run_at DATETIME NULL,
  last_error TEXT NULL,
  response_json TEXT NULL,
  fbtrace_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_company_event (company_id, event_id),
  KEY idx_company_status (company_id, status),
  KEY idx_due (status, run_at),
  KEY idx_company_contact (company_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
