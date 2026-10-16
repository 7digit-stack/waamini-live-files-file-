-- Ads & Attribution — Phase 0: plan add-on flags (off by default).
-- Per-company overrides use the existing companies.features_override JSON (no new table).
-- Also applied automatically by includes/ads/schema.php.
ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS feat_ads_attribution TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS feat_ads_capi TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS feat_ads_insights TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS feat_ads_builder TINYINT(1) NOT NULL DEFAULT 0;
