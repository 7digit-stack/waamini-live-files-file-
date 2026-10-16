-- Reverses 028_waamini_bot.sql (drops only the tables that migration created)
DROP TABLE IF EXISTS ai_reply_jobs;
DROP TABLE IF EXISTS ai_usage_monthly;
DROP TABLE IF EXISTS ai_runs;
DROP TABLE IF EXISTS ai_conversation_state;
DROP TABLE IF EXISTS ai_qa_pairs;
DROP TABLE IF EXISTS ai_knowledge_chunks;
DROP TABLE IF EXISTS ai_knowledge_sources;
DROP TABLE IF EXISTS ai_bot_versions;
DROP TABLE IF EXISTS ai_bots;
DROP TABLE IF EXISTS ai_platform_settings;
