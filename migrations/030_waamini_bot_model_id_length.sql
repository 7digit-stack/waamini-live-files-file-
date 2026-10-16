-- Provider model IDs may be longer than the original OpenAI-only 80-char limit.
ALTER TABLE ai_platform_settings
  MODIFY COLUMN chat_model VARCHAR(255) NOT NULL DEFAULT 'gpt-4o-mini';
