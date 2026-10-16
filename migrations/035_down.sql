-- Reverses 035_ads_attribution.sql (drops only what that migration created)
ALTER TABLE contacts DROP INDEX IF EXISTS idx_company_lead_source;
ALTER TABLE contacts DROP COLUMN IF EXISTS lead_source;
ALTER TABLE contacts DROP COLUMN IF EXISTS first_ad_referral_id;
ALTER TABLE contacts DROP COLUMN IF EXISTS last_ad_referral_id;
ALTER TABLE contacts DROP COLUMN IF EXISTS latest_ctwa_clid;
ALTER TABLE contacts DROP COLUMN IF EXISTS lead_stage;
ALTER TABLE contacts DROP COLUMN IF EXISTS lead_stage_at;
ALTER TABLE contacts DROP COLUMN IF EXISTS won_value;
DROP TABLE IF EXISTS ad_catalog;
DROP TABLE IF EXISTS ad_referrals;
