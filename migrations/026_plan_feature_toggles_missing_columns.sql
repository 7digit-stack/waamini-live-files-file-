-- 11 client-panel pages gate themselves with has_feature('feat_xxx') for
-- columns that were never added to subscription_plans, and admin/plan_edit.php
-- has no toggle UI for them either. Net effect: these features are
-- permanently locked for every client on every plan, with no admin control.
-- Default to enabled (1) since there was never a working "off" state to
-- preserve — this restores the features rather than changing behavior.

ALTER TABLE subscription_plans
    ADD COLUMN feat_gst_invoice TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_blacklist TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_wa_health TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_chatbot_builder TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_scheduled_msg TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_quick_broadcast TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_campaign_reports TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_contact_import TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_referral TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_notifications TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN feat_2fa TINYINT(1) NOT NULL DEFAULT 1;
