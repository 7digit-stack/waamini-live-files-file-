-- Reverses 029_waamini_bot_model_providers.sql.
DROP TABLE IF EXISTS ai_provider_credentials;
ALTER TABLE ai_platform_settings DROP COLUMN chat_provider;
