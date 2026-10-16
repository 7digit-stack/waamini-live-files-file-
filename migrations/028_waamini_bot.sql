-- ============================================================================
-- 028  WAAMINI Bot: one AI bot per client, shared OpenAI key set by super admin
-- Reversible: see 028_down.sql. Creates new tables only, no existing table is altered.
-- ============================================================================

CREATE TABLE IF NOT EXISTS ai_platform_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  openai_key_encrypted TEXT NULL,
  key_last4 VARCHAR(8) NULL,
  key_ok TINYINT(1) NOT NULL DEFAULT 0,
  key_tested_at DATETIME NULL,
  key_error VARCHAR(300) NULL,
  chat_model VARCHAR(80) NOT NULL DEFAULT 'gpt-4o-mini',
  embedding_model VARCHAR(80) NOT NULL DEFAULT 'text-embedding-3-small',
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  platform_rules TEXT NULL,
  plan_limits LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_bots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT 'WAAMINI Bot',
  is_admin_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_live TINYINT(1) NOT NULL DEFAULT 0,
  website_url VARCHAR(500) NULL,
  business_profile LONGTEXT NULL,
  profile_confirmed_at DATETIME NULL,
  role_text TEXT NULL,
  tone_text TEXT NULL,
  scope LONGTEXT NULL,
  out_of_scope LONGTEXT NULL,
  language_mode VARCHAR(20) NOT NULL DEFAULT 'same',
  reply_mode VARCHAR(20) NOT NULL DEFAULT 'always',
  welcome_line TEXT NULL,
  handoff_line TEXT NULL,
  handoff_target INT NULL,
  tested_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_bot_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  bot_id INT NOT NULL,
  version_no INT NOT NULL,
  snapshot LONGTEXT NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bot (company_id, bot_id, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  bot_id INT NOT NULL,
  type ENUM('url','file','qa') NOT NULL DEFAULT 'url',
  uri VARCHAR(700) NOT NULL,
  title VARCHAR(255) NULL,
  status ENUM('queued','training','success','failed') NOT NULL DEFAULT 'queued',
  bytes INT NOT NULL DEFAULT 0,
  error VARCHAR(300) NULL,
  trained_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company_status (company_id, bot_id, status),
  UNIQUE KEY uq_source (company_id, bot_id, type, uri(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_chunks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  source_id INT NOT NULL,
  chunk_no INT NOT NULL DEFAULT 0,
  content MEDIUMTEXT NOT NULL,
  embedding MEDIUMBLOB NULL,
  token_count INT NOT NULL DEFAULT 0,
  source_url VARCHAR(700) NULL,
  KEY idx_company_source (company_id, source_id),
  FULLTEXT KEY ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_qa_pairs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  bot_id INT NOT NULL,
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  embedding MEDIUMBLOB NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company (company_id, bot_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_conversation_state (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  conversation_id INT NOT NULL DEFAULT 0,
  contact_id INT NOT NULL,
  state ENUM('open','resolved','escalated','dropped') NOT NULL DEFAULT 'open',
  paused_until DATETIME NULL,
  fail_count INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact (company_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  conversation_id INT NOT NULL DEFAULT 0,
  contact_id INT NOT NULL DEFAULT 0,
  message_id BIGINT NULL,
  wamid VARCHAR(120) NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  used_chunk_ids LONGTEXT NULL,
  tool_calls LONGTEXT NULL,
  prompt_tokens INT NOT NULL DEFAULT 0,
  completion_tokens INT NOT NULL DEFAULT 0,
  latency_ms INT NOT NULL DEFAULT 0,
  outcome VARCHAR(40) NOT NULL DEFAULT '',
  error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company_time (company_id, created_at),
  KEY idx_wamid (company_id, wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_monthly (
  company_id INT NOT NULL,
  month CHAR(7) NOT NULL,
  replies INT NOT NULL DEFAULT 0,
  tokens BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (company_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bot reply queue. wamid is unique so Meta's duplicate deliveries never create two jobs.
CREATE TABLE IF NOT EXISTS ai_reply_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  contact_id INT NOT NULL,
  wamid VARCHAR(120) NOT NULL,
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  run_at DATETIME NOT NULL,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wamid (company_id, wamid),
  KEY idx_due (status, run_at),
  KEY idx_contact (company_id, contact_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_platform_settings (id) VALUES (1);
