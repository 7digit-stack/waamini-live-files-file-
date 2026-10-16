# Changelog

## 2026-10-13 — Ads & Attribution, Phase 2 (Conversions API)

### Added
- **`services/CapiService.php`**:
  - queues an event when an ad lead's stage changes, using a stable `event_id` so each event is sent once
  - logs organic leads and taps outside the window as **skipped**
  - Purchase waits for the sale value
  - sends through `MetaGraphClient`, retrying up to 5 times with backoff
  - test event, **Find my dataset** (`/{waba_id}/dataset`, VERIFY), and stats
- **`ads_set_stage()`**: one place where a lead's stage changes. It's used by the WhatsApp Chat outcome buttons and by the AI auto-outcome (`includes/ai_crm_sync.php`), which before did not update the stage.
- **Client:**
  - **Ads → Conversion Events** (`client/ads_capi.php`): setup checklist, dataset, stage → event mapping, send value, attribution window, test event code, an optional encrypted separate token, and an event log with status filters, Retry and Retry all failed
  - **WhatsApp Chat:** the Lead source card shows what was sent to Meta
- **Admin:**
  - the Conversions API toggle is now **Live** in Plans and Company add-ons
  - the Ads & Attribution overview shows events sent and failed per company and the failure rate
- **Sending:** the every-minute worker sends due events. The panel tick (`api/ads_media_tick.php`) also sends them while WhatsApp Chat or Ad Leads is open.
- **Schema:** `capi_settings`, `capi_events`, and migration `036` with its down script.
- **Tests:** 7 payload checks in `tests/ads_unit_test.php` (now 41 in total) and `tests/ads_capi_test.php` (database integration with mocked Meta).

## 2026-10-13 — Ads & Attribution, Phases 0–1

### Added
- **Phase 0 (foundation)**:
  - `config/meta.php`: one setting for `META_GRAPH_VERSION`
  - `services/MetaGraphClient.php`: retries with backoff, rate-limit backoff, mapping of errors to plain language, token masking, `appsecret_proof` for the own app only
  - `services/MetaTokenVault.php`: token encryption through the existing EncryptionService
  - plan flags `feat_ads_attribution`, `feat_ads_capi`, `feat_ads_insights`, `feat_ads_builder`, with per-company overrides through `companies.features_override`
- **Phase 1 (Click-to-WhatsApp attribution)**:
  - `services/AttributionService.php`: stores the webhook `referral` once per message, updates the ad catalog, and records first and last touch plus the latest `ctwa_clid` on the contact
  - `services/MediaVaultService.php`: downloads ad thumbnails from Meta CDN hosts only, into private storage
- **Client panel**:
  - **Ads → Ad Leads** (`client/ads_leads.php`): KPIs, leads by ad, a list of each ad's customers, date presets in IST, CSV export
  - **WhatsApp Chat**: Lead source card and a Sale value field
  - **Contacts**: lead source and ad filters, plus an Ad badge
  - **Flow Builder**: new trigger "Lead came from a Click-to-WhatsApp ad"
- **Admin panel**:
  - Plans: Ads & Attribution add-on toggles
  - Companies → Edit: Add-ons (Follow plan / On / Off)
  - New Ads & Attribution overview page
- **Schema**:
  - `ad_referrals`, `ad_catalog`
  - `contacts.lead_source`, `first_ad_referral_id`, `last_ad_referral_id`, `latest_ctwa_clid`, `lead_stage`, `lead_stage_at`, `won_value`
  - migrations `034`/`035` with down scripts, plus self-installing schema
- **Tests and docs**:
  - `tests/ads_unit_test.php` (34 checks, no database) and `tests/ads_attribution_test.php` (database integration)
  - fixture `tests/fixtures/ctwa_referral.json`
  - `docs/META_SETUP.md`

### Changed
- `webhook.php`: one guarded `AttributionService::capture()` call per message that carries a referral. It doesn't call the network or change the webhook's response.
- WhatsApp Chat outcome buttons also set `contacts.lead_stage`: Follow-up → qualified, Converted → won, Declined / Not interested → lost.
- `workers/automation_queue_worker.php` also downloads pending ad thumbnails.
