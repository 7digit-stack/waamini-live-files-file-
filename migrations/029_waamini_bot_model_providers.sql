-- WAAMINI Bot: provider selection and encrypted API keys per provider.
-- OpenAI credentials remain in ai_platform_settings for the existing embedding index.
ALTER TABLE ai_platform_settings
  ADD COLUMN chat_provider VARCHAR(24) NOT NULL DEFAULT 'openai' AFTER key_error;

CREATE TABLE IF NOT EXISTS ai_provider_credentials (
  provider VARCHAR(24) NOT NULL PRIMARY KEY,
  key_encrypted TEXT NOT NULL,
  key_last4 VARCHAR(8) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
