-- Reverses 034_ads_foundation.sql
ALTER TABLE subscription_plans DROP COLUMN IF EXISTS feat_ads_attribution;
ALTER TABLE subscription_plans DROP COLUMN IF EXISTS feat_ads_capi;
ALTER TABLE subscription_plans DROP COLUMN IF EXISTS feat_ads_insights;
ALTER TABLE subscription_plans DROP COLUMN IF EXISTS feat_ads_builder;
