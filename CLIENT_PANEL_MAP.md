# WAAMINI Client Panel — Complete A–Z Study & Route Map

**Review date:** 10 October 2026
**Scope:** every file under `client/` (146 PHP files + 2 JSON schemas), plus the shared shell and access-control helpers that drive them: `includes/client_header.php`, `includes/client_footer.php`, `includes/client_auth_check.php`, `includes/feature_check.php`, `includes/security.php`.
**Review type:** static source study. This is a reference/memory document for the client panel — what exists, how it is wired, and how it is gated. It does not assert that any page or integration was exercised at runtime, and it intentionally does **not** copy credentials or account data.

Companion machine-readable matrix: **`CLIENT_PANEL_ROUTES.csv`** (one row per file: area, sidebar, feature gate, type, CSRF, methods, tables, purpose).

---

## 1. What the client panel is

WAAMINI is a multi-tenant SaaS WhatsApp Business CRM + automation platform (PHP + MySQL + Meta Cloud API). The **client panel** is the per-company workspace each subscribed business logs into.

- Users log in as role `client` and carry a `company_id` in session. Every data query is expected to scope to that company.
- The panel spans acquisition, messaging, service, commerce, finance, vertical reminders, team, analytics, and account settings.
- **Size:** 146 PHP files (112 full pages, 34 endpoints/aliases/helpers) + `chatbot_schema.json` and `schema_nodes.json`.
- **Language/conventions:** PHP 8, mysqli (`db.php` global `$conn`), a shared sidebar/topbar shell, Bootstrap-era custom CSS (`assets/css/client.css`, `sidebar-pro.css`, `ecom-ui.css`, `settings-ui.css`).

### Counts at a glance

| Metric | Value |
|---|---|
| Total client PHP files | 146 |
| Full pages (include `client_header.php`) | 112 |
| Endpoints / AJAX / helpers / aliases | 34 |
| Sidebar destinations | 54 |
| Files that call `has_feature(...)` internally | 61 |
| Files referencing a CSRF helper | 102 |
| Route-matrix rows in `CLIENT_PANEL_ROUTES.csv` | 146 |

---

## 2. Shell, layout and shared chrome

`includes/client_header.php` (589 lines) is the spine of every page. Each page sets two variables **before** including it:

```php
$pageTitle   = 'Contacts';
$currentPage = 'contacts';
require_once __DIR__ . '/../includes/client_header.php';
```

`$currentPage` drives sidebar active-state highlighting; `$pageTitle` renders in the topbar and `<title>`.

What the header does on every load:

1. **Company identity** — loads `companies.name` (session-cached) and shows a company badge + plan name (`get_plan_name()`).
2. **Impersonation banner** — if `$_SESSION['impersonating']` is set, shows "Admin Impersonation Active" with a return link to `/admin/stop_impersonate.php`.
3. **Active announcements** — pulls rows from `announcements` matching `target_plan_id = 0` or the company's plan id; renders dismissible alerts.
4. **Unread badge** — counts unread incoming `messages` for the company (session-cached 60 s) for the WhatsApp Chat nav item.
5. **WhatsApp connection notice** — on most pages (except `settings_whatsapp`, `whatsapp`, `wa_health`) includes `wa_connection_notice.php` to nudge the user to connect a number.
6. **Per-section skinning** — E-Commerce pages and the Settings pages reuse `/assets/css/ecom-ui.css` and `/assets/css/settings-ui.css`.
7. **Sidebar scroll** — a small inline script centres the active nav item.

Every page ends with `includes/client_footer.php`.

### Sidebar structure (group → item → route → gate)

Legend: **gate** is the `has_feature()` key (or keys) that must be truthy for the item to be clickable; locked items still render, dimmed and non-clickable, with a lock icon.

| Group | Sidebar label | Route | Feature gate |
|---|---|---|---|
| Main | Dashboard | `client/dashboard.php` | — |
| Main | QR Code | `client/qr_code.php` | `qr_code` |
| Main | WhatsApp Chat | `client/whatsapp.php` | `whatsapp_chat` |
| Main | Campaigns | `client/campaigns.php` | `campaigns` |
| Main | Integrations | `client/integrations.php` | `indiamart` OR `fb_lead_ads` |
| Main | Contacts | `client/contacts.php` | `contacts` |
| Main | Groups | `client/contact_groups.php` | `contacts` |
| Main | Templates | `client/templates.php` | `templates` |
| Main | Bot Replies | `client/bot_replies.php` | `bot_replies` |
| Main | Demo Bookings | `client/demo_bookings.php` | `bot_replies` |
| Main | WAAMINI Bot | `client/waamini_bot.php` | `ai_chatbot` |
| Main | └ Knowledge | `client/waamini_bot_knowledge.php` | `ai_chatbot` |
| Main | └ Bot Settings | `client/waamini_bot_settings.php` | `ai_chatbot` |
| Main | Team Members | `client/team_members.php` | `team_members` |
| Main | My Subscription | `client/subscription.php` | — |
| Premium Growth | Zapier Webhooks | `client/premium_zapier.php` | `api_webhook_access` |
| Premium Growth | WA Native Forms | `client/premium_flows.php` | `whatsapp_flows` |
| Premium Growth | AI Image Scanner | `client/premium_ocr.php` | `feat_ocr_scanner` |
| Premium Growth | Drip Campaigns | `client/premium_drip.php` | `feat_drip_campaign` |
| Premium Growth | CRM Analytics | `client/premium_analytics.php` | `feat_crm_analytics` |
| Premium Growth | AI Voice Note | `client/premium_voice.php` | `feat_voice_analyzer` |
| Premium Growth | Multi-Agent Chat | `client/live_chat.php` | `feat_multi_agent` |
| E-Commerce | Order Analytics | `client/order_dashboard.php` | `feat_native_checkout` |
| E-Commerce | Manage Orders | `client/orders.php` | `feat_native_checkout` |
| E-Commerce | Product Catalog | `client/catalog.php` | `feat_native_checkout` |
| E-Commerce | Catalog Reports | `client/catalog_reports.php` | `feat_native_checkout` |
| E-Commerce | Cart Recovery | `client/premium_abandoned_cart.php` | `ecommerce_cart_recovery` |
| E-Commerce | Checkout Settings | `client/ecommerce_settings.php` | `feat_native_checkout` |
| E-Commerce | PDF Quotations | `client/pdf_settings.php` | `feat_pdf_quotes` |
| E-Commerce | Loyalty Program | `client/premium_loyalty.php` | `feat_loyalty_points` |
| Finance & Trust | GST Invoices | `client/gst_invoices.php` | `feat_gst_invoice` |
| Finance & Trust | Opt-out Manager | `client/blacklist.php` | `feat_blacklist` |
| Finance & Trust | Number Health | `client/wa_health.php` | `feat_wa_health` |
| Pro Modules | Flow Builder | `client/chatbot_flows.php` | `feat_chatbot_builder` |
| Pro Modules | Scheduled Blasts | `client/scheduled_messages.php` | `feat_scheduled_msg` |
| Pro Modules | Quick Blast | `client/quick_broadcast.php` | `feat_quick_broadcast` |
| Pro Modules | ROI Reports | `client/campaign_reports.php` | `feat_campaign_reports` |
| Pro Modules | Bulk Import/Export | `client/contact_bulk.php` | `feat_contact_import` |
| Growth & Security | Referral Program | `client/referrals.php` | `feat_referral` |
| Growth & Security | Alerts & Preferences | `client/notifications.php` | `feat_notifications` |
| Growth & Security | Security Center | `client/security.php` | `feat_2fa` |
| Reminder Packs | Festival Reminders | `client/festival_campaigns.php` | `festival_reminder` |
| Reminder Packs | School Fee Reminders | `client/school_fees.php` | `school_fee_reminder` |
| Reminder Packs | Vehicle Reminders | `client/vehicle_reminders.php` | `vehicle_reminder` |
| Reminder Packs | Medicine Reminders | `client/medicine_reminders.php` | `medicine_reminder` |
| (free-standing) | Billing & Wallet | `client/billing.php` | — |
| (free-standing) | Discover More | `client/premium_features.php` | — |
| Help & Support | User Guide | `client/manual.php` | — |
| Settings | General | `client/settings_general.php` | — |
| Settings | WhatsApp Setup | `client/settings_whatsapp.php` | — |
| Settings | Google Sheets | `client/settings_gsheets.php` | `google_sheets` |
| Settings | Google Calendar & Meet | `client/google_calendar.php` | — |
| Settings | Sales Tracker | `client/settings_sales_tracker.php` | — |
| Settings | API & Webhook | `client/settings_api.php` | `api_webhook` |
| Settings | Account Health | `client/pulse.php` | `pulse` |
| Settings | Support | `client/support.php` | — |

> Many working pages are **not** in the sidebar — they are reached from a nav page (e.g. `campaign_create.php` from Campaigns, `contact_import.php` from Contacts, `gst_invoice_view.php` from GST Invoices). They still carry the same header/auth, so they are full pages with `In Sidebar = no` in the CSV.

---

## 3. Access control & the restriction model

`includes/client_auth_check.php` (214 lines) is included at the top of every real client page. Order of checks:

1. **Cron bypass** — if `X-Cron-Secret` matches `CRON_SECRET_KEY`, sets `$GLOBALS['companyId']` from `company_id` and returns early (used by cron scripts that reuse client handlers).
2. **Login required** — no `$_SESSION['user_id']` → redirect to `login.php` (or `403 JSON` for `/api/` paths).
3. **Timeout** — `$_SESSION['timed_out']` → destroy session, redirect `login.php?msg=timeout`.
4. **Admin redirect** — `role = super_admin` and not impersonating → redirect to `admin/dashboard.php`.
5. **Disabled account** — cached user status (5 min); `disabled` → destroy + redirect `login.php?msg=disabled`.
6. **Maintenance mode** — `system_settings.maintenance_mode = 1` → 503 page (JSON for `/api/`), **unless** impersonating (admins can still inspect). Cached 30 s.
7. **Company scope** — `$companyId = intval($_SESSION['company_id'] ?? 0)`.
8. **Restricted (new registration)** — `$isRestricted = true` when `company_id <= 0` **or** the company has **zero** rows in `subscriptions`. Restricted sessions render only the Dashboard nav; the feature block in the sidebar is hidden (`if (!$isRestricted)`).
9. **Expiry / view-only** — `is_subscription_expired()`; expired plans enter view-only mode.

**Allowed pages when restricted or expired:** `dashboard.php`, `subscription.php`, `logout.php`, `profile.php`, `contacts.php`, `campaigns.php`, `templates.php`, `message_log.php`, `whatsapp.php`, `bot_replies.php`. Any other page redirects to `dashboard.php?msg=restricted|expired`. `/api/` paths are always allowed so `fetch()` receives JSON rather than an HTML redirect.

Feature helpers (`feature_check.php`) and `security.php` are loaded by this guard, so `has_feature()`, `csrf_*()` etc. are available with no extra include.

> Entitlement note: sidebar gating is **presentation**. The same helpers are used inside pages, but this study only records where a gate is referenced, not whether a route is fully enforced server-side.

---

## 4. Feature entitlement model (`includes/feature_check.php`)

One row of `subscriptions` joined to `subscription_plans` for the company defines entitlements, cached in session (`_plan_cache_*`, TTL 120 s) and in-request memory.

Resolution order (later wins):

1. Active/trial subscription plan row (columns `feat_*`, `limit_*`, `ai_chatbot`, `api_webhook_access`, `name`).
2. Fallback to the latest subscription row if no active/trial row exists.
3. Merge `subscription_plans.features` JSON keys that don't collide with real columns.
4. Merge `companies.features_override` JSON — **overrides both flags and limits**.

Public helpers:

| Helper | Purpose |
|---|---|
| `has_feature($key)` | Boolean flag; maps short names → plan columns. |
| `get_limit($resource)` | Numeric limit (`0` = unlimited). |
| `get_usage($resource)` | Current usage count (`contacts`, `campaigns`, `bot_replies`, `team_members`, `messages_month`). |
| `can_use($resource)` | `limit === 0` or `usage < limit`. |
| `get_plan_name()` / `get_plan_meta()` | Plan name / `{name, expires_at, sub_status}`. |
| `get_enabled_features()` | Flat list of enabled `feat_*` keys. |
| `is_subscription_expired()` | Expiry check cached 1 h (fails open if it cannot query). |

### Short-name → column map (`has_feature`)

| Key | Column | Key | Column |
|---|---|---|---|
| `whatsapp_chat` | `feat_whatsapp_chat` | `gst_invoice` | `feat_gst_invoice` |
| `contacts` | `feat_contacts` | `blacklist` | `feat_blacklist` |
| `campaigns` | `feat_campaigns` | `wa_health` | `feat_wa_health` |
| `templates` | `feat_templates` | `campaign_reports` | `feat_campaign_reports` |
| `bot_replies` | `feat_bot_replies` | `contact_import` | `feat_contact_import` |
| `team_members` | `feat_team_members` | `referral` | `feat_referral` |
| `auto_followup` | `feat_auto_followup` | `security_2fa` | `feat_2fa` |
| `lead_scoring` | `feat_lead_scoring` | `notifications` | `feat_notifications` |
| `message_log` | `feat_message_log` | `quick_broadcast` | `feat_quick_broadcast` |
| `qr_code` | `feat_qr_code` | `chatbot_builder` | `feat_chatbot_builder` |
| `indiamart` | `feat_indiamart` | `scheduled_msg` | `feat_scheduled_msg` |
| `fb_lead_ads` | `feat_fb_lead_ads` | `native_checkout` | `feat_native_checkout` |
| `drip_campaign` | `feat_drip_campaign` | `festival_reminder` | `feat_festival_reminder` |
| `language_detect` | `feat_language_detect` | `school_fee_reminder` | `feat_school_fee_reminder` |
| `ai_chatbot` | `ai_chatbot` | `vehicle_reminder` | `feat_vehicle_reminder` |
| `api_webhook` | `api_webhook_access` | `medicine_reminder` | `feat_medicine_reminder` |
| `google_sheets` | `feat_google_sheets` | `vcard` | `feat_vcard` |
| `pulse` | `feat_pulse` | `quick_replies` | `feat_quick_replies` |
| `crm_advance` | `feat_crm_advance` | | |

Keys used directly with a `feat_` prefix in the sidebar (e.g. `feat_ocr_scanner`, `feat_drip_campaign`, `feat_crm_analytics`, `feat_voice_analyzer`, `feat_multi_agent`, `feat_pdf_quotes`, `feat_loyalty_points`, `whatsapp_flows`, `ecommerce_cart_recovery`) map to themselves — i.e. the key **is** the plan column/JSON key.

---

## 5. Functional areas A–Z

Each area lists the sidebar entry point(s), the working pages, and the async/endpoint files, with the tables they touch. Full per-file detail is in `CLIENT_PANEL_ROUTES.csv`; the appendix indexes everything alphabetically.

### 5.1 Daily workspace
**Entry:** Dashboard (`dashboard.php`) — KPIs, recent activity, quick links; reads `contacts`, `campaigns`, `message_templates`, `messages` and respects `contacts|campaigns|whatsapp_chat|templates|feat_native_checkout`.
Also here: `growth.php` (Growth Accelerator hub), `notifications.php`, `manual.php` (guide from `user_guide_data.php`), `premium_features.php`, `billing.php`, `subscription.php`, `addon_packs.php` (Razorpay pack purchase), `profile.php`, `security.php`, `support.php`, `referrals.php`, `settings_general.php`.
**Tables:** `companies`, `subscriptions`, `subscription_plans`, `system_settings`, `addon_packs`, `addon_purchases`, `notification_settings`, `support_tickets`, `support_messages`, `referral_earnings`, `referral_logs`, `users`.

### 5.2 WhatsApp setup & inbox
**Entry:** `whatsapp.php` (inbox + CRM side panel + AI lead bar), `settings_whatsapp.php` (Cloud API connection), `wa_health.php` (number health), `pulse.php` (account health), `qr_code.php`, `live_chat.php` (multi-agent).
Also: `quick_replies.php`, `message_log.php`, `wa_health_monitor.php`, `whatsapp_health.php` (alias).
**Tables:** `whatsapp_config`, `messages`, `chat_conversations`, `chat_messages`, `contact_groups`, `quick_replies`, `lead_automation_settings`, `team_members`, `wa_health_logs`, `whatsapp_api_logs`, `blacklist`.
**Endpoints:** `ajax_load_chat.php`, `ajax_media_proxy.php`, `ajax_ai_lead_analysis.php`, `ajax_voice_transcribe.php`, `ajax_waamini_bot.php`.

### 5.3 Contacts & audiences
**Entry:** `contacts.php` (list), `contact_groups.php` (Groups), `contact_bulk.php` (Bulk Import/Export), `blacklist.php` (Opt-out Manager).
Also: `contact_add.php`, `contact_edit.php`, `contact_import.php` (CSV/Excel mapping).
**Tables:** `contacts`, `contact_groups`, `contact_group_members`, `contact_notes`, `blacklist`, `users`, `lead_scores`, `message_templates`.

### 5.4 Campaigns & templates
**Entry:** `campaigns.php`, `templates.php`, `quick_broadcast.php` (Quick Blast), `scheduled_messages.php` (Scheduled Blasts), `campaign_reports.php` (ROI Reports).
Also: `campaign_create.php`, `campaign_view.php`, `template_create.php`, `brochure_sender.php`, `ai_campaign_writer.php`, `ab_testing.php`, `pincode_campaign.php`.
**Tables:** `campaigns`, `campaign_queue`, `message_templates`, `contact_groups`, `contacts`, `scheduled_messages`, `ab_tests`, `automation_queue`, `whatsapp_config`.
**Endpoints:** `ajax_templates.php`, `dashboard_trend.php`, `delivery_trend.php`.

### 5.5 Bots & automation
**Entry:** `bot_replies.php`, `waamini_bot.php` (+ `waamini_bot_knowledge.php`, `waamini_bot_settings.php`), `premium_drip.php` (Drip Campaigns), `premium_flows.php` (WA Native Forms), `chatbot_flows.php` (Flow Builder).
Also: `automations.php`, `lead_automation.php`, `chatbot_builder.php`, `chatbot_simple_builder.php`, `premium_drip_builder.php`, `industry_bots.php`, `drip_campaigns.php` (alias), `premium_ai_bot.php`/`settings_bot.php` (redirects to WAAMINI Bot).
**Tables:** `bot_replies`, `bot_settings`, `ai_bot_settings`, `ai_bots`, `ai_bot_versions`, `ai_knowledge_sources`, `ai_qa_pairs`, `ai_runs`, `chatbot_flows`, `chatbot_nodes`, `chatbot_node_connections`, `chatbot_sessions`, `flow_nodes`, `flow_sessions`, `flow_logs`, `drip_campaigns`, `drip_steps`, `drip_executions`, `lead_automation_settings`, `automation_queue`, `message_templates`, `users`.
**Endpoints:** `api_save_chatbot.php`, `flow_api.php`, `flow_builder.php`, `flow_upload.php`, `ajax_waamini_bot.php`, `ajax_run_processor.php`, `get_schema.php`, `get_chatbot_schema.php`.

### 5.6 Commerce & orders
**Entry:** `order_dashboard.php` (Order Analytics), `orders.php` (Manage Orders), `catalog.php` (Product Catalog), `catalog_reports.php` (Catalog Reports), `ecommerce_settings.php` (Checkout Settings), `premium_abandoned_cart.php` (Cart Recovery).
Also: `abandoned_cart.php` (alias), `settings_cart_recovery.php`, `settings_order_tracking.php`, `settings_upsell.php`, `settings_payment.php`, `stock_alerts.php`.
**Tables:** `products`, `products_catalog`, `product_enquiries`, `catalog_interactions`, `catalog_auto_replies`, `ecommerce_orders`, `ecommerce_carts`, `ecommerce_settings`, `order_tracking_settings`, `upsell_settings`, `upsell_products`, `cart_recovery_settings`, `stock_waitlist`, `contacts`, `api_keys`.
**Endpoints:** `ajax_send_order_payment.php`, `ajax_send_payment.php`, `ajax_mark_upi_paid.php`.

### 5.7 Documents & finance
**Entry:** `gst_invoices.php` (GST Invoices), `pdf_settings.php` (PDF Quotations).
Also: `gst_invoice_create.php`, `gst_invoice_view.php`, `gst_settings.php`, `settings_gst.php`, `settings_pdf.php` (alias).
**Tables:** `gst_invoices`, `gst_settings`, `company_pdf_settings`, `quotations`, `contacts`, `companies`, `products`.
**Endpoints:** `ajax_update_gst_invoice.php`, `ajax_send_quote.php`.

### 5.8 Reminders & verticals
**Entry:** `festival_campaigns.php`, `school_fees.php`, `vehicle_reminders.php`, `medicine_reminders.php`, `demo_bookings.php`, `premium_ocr.php` (AI Image Scanner).
Also: `festival_calendar.php`, `school_fee_settings.php`, `settings_school_fees.php`, `vehicle_reminder.php`, `medicine_reminder.php`, `bookings.php`, `settings_booking.php`, `google_calendar.php`, `google_calendar_connect.php`, `missed_call.php`, `settings_missed_call.php`, `review_bot.php`, `settings_review_bot.php`, `settings_birthday.php`, `settings_emi_bot.php`, `polls.php`, `wa_polls.php`, `form_builder.php`, `form_responses.php`, `ocr.php` (alias), `digital_vcard.php`, `vcard_settings.php`, `vcard.php` (alias), `settings_vcard.php` (alias), `spam_protect.php`, `settings_spam.php` (alias), `ai_lead_scores.php`.
**Tables:** `festival_campaigns`, `school_students`, `school_fees`, `school_fee_settings`, `vehicles`, `medicine_reminders`, `bookings`, `demo_bookings`, `booking_settings`, `missed_call_settings`, `missed_calls_log`, `missed_call_logs`, `review_settings`, `review_requests`, `birthday_settings`, `emi_bot_settings`, `wa_polls`, `wa_poll_responses`, `poll_responses`, `wa_forms`, `wa_form_submissions`, `digital_vcards`, `vcard_settings`, `contacts`, `messages`, `automation_queue`.
**Endpoint:** `google_calendar_connect.php`.

### 5.9 Team & analytics
**Entry:** `team_members.php` (Team Members), `premium_analytics.php` (CRM Analytics), `premium_loyalty.php` (Loyalty Program), `premium_voice.php` (AI Voice Note).
Also: `analytics_agents.php`, `analytics_revenue.php`, `ai_lead_scores.php`.
**Tables:** `users`, `messages`, `chat_conversations`, `campaigns`, `campaign_queue`, `ecommerce_orders`, `contacts`, `contact_groups`, `drip_campaigns`, `loyalty_settings`, `loyalty_logs`, `ai_voice_jobs`, `message_templates`.
**Endpoints:** `analytics_*` feeds `dashboard_trend.php`, `delivery_trend.php`, `usage_trend.php`.

### 5.10 Integrations & account settings
**Entry:** `integrations.php` (hub), `settings_gsheets.php`, `settings_api.php`, `premium_zapier.php`, `settings_sales_tracker.php`, `settings_general.php`, `settings_whatsapp.php`, `google_calendar.php`, `pulse.php`, `support.php`.
Also: `settings_indiamart.php`, `settings_fb_leads.php`, `settings_zapier.php` (alias).
**Tables:** `companies`, `api_keys`, `zapier_webhooks`, `sales_tracker_settings`, `sales_log`, `google_sheet_webhook`, `system_errors`, `support_tickets`.
**Endpoints:** `ajax_media_proxy.php`, `ajax_run_processor.php`, `ajax_templates.php`, `ajax_waamini_bot.php`.

---

## 6. Endpoint & alias conventions

There is a consistent split inside `client/`:

- **Pages** include `client_auth_check.php` → `client_header.php` → `client_footer.php` and emit HTML.
- **Endpoints** (`ajax_*.php`, `flow_*.php`, `*_trend.php`, `api_save_chatbot.php`, `google_calendar_connect.php`) include `client_auth_check.php` only, respond with JSON/HTML fragments, and mostly call `csrf_verify()` on writes (e.g. `ajax_ai_lead_analysis.php`, `ajax_update_gst_invoice.php`, `ajax_send_payment.php`).
- **Compatibility aliases** are 6-line files that include the auth guard then `require_once` the canonical page. They exist so older links and Playwright routes keep working:
  `abandoned_cart.php → premium_abandoned_cart.php`, `drip_campaigns.php → premium_drip.php`, `ocr.php → premium_ocr.php`, `settings_pdf.php → pdf_settings.php`, `settings_spam.php → spam_protect.php`, `settings_vcard.php → vcard_settings.php`, `settings_zapier.php → premium_zapier.php`, `vcard.php → digital_vcard.php`, `whatsapp_health.php → wa_health.php`.
- **Deprecated redirects:** `premium_ai_bot.php` and `settings_bot.php` both `header('Location: waamini_bot.php')`.
- **Dev/maintenance helpers without the auth guard:** `get_schema.php` and `get_chatbot_schema.php` (they only `include '../db.php'` and write `schema_nodes.json` / `chatbot_schema.json`). `user_guide_data.php` is include-only and self-guards with a session check.

**CSRF:** 102 files reference `csrf_field()`, `csrf_token`, `csrf_get_token()` or `csrf_verify()`. State-changing pages call `csrf_verify()` in their POST branch. `has_feature()` is referenced in 61 files.

---

## 7. Core user journeys

**Onboarding → first campaign**
Admin provisions company/user + plan → client signs in → `settings_whatsapp.php` connects the number → `contact_import.php`/`contact_add.php` load contacts → `contact_groups.php` segments them → `template_create.php` submits a template → `campaign_create.php` previews audience and schedules → `campaigns.php`/`campaign_view.php` track delivery → replies land in `whatsapp.php`.

**Inbound conversation → bot or agent**
Webhook stores message → bot rules (`bot_replies.php`, `chatbot_flows.php`, `waamini_bot.php`) or AI (`ajax_waamini_bot.php`) reply → human handoff sits in `whatsapp.php`/`live_chat.php` with `quick_replies.php` and the AI lead bar.

**Commerce**
`catalog.php` products → customer order → `orders.php` + `ajax_send_order_payment.php` prompt → `ajax_mark_upi_paid.php`/gateway reconciles → `order_dashboard.php`/`catalog_reports.php` report; `premium_abandoned_cart.php` recovers dropped carts.

**Subscription change**
Admin edits plan → `feature_check.php` cache (≤120 s) refreshes → sidebar regates → `subscription.php` shows new limits; expired plans fall back to the allowed-page list and view-only mode.

---

## 8. Data-model cheat sheet (client-facing tables)

`contacts`, `contact_groups`, `contact_group_members`, `contact_notes`, `blacklist`, `lead_scores` · `whatsapp_config`, `messages`, `message_templates`, `chat_conversations`, `chat_messages`, `whatsapp_api_logs`, `wa_health_logs` · `campaigns`, `campaign_queue`, `scheduled_messages`, `ab_tests` · `bot_replies`, `bot_settings`, `ai_bot_settings`, `ai_bots`, `ai_bot_versions`, `ai_knowledge_sources`, `ai_qa_pairs`, `ai_runs`, `chatbot_flows`, `chatbot_nodes`, `chatbot_node_connections`, `chatbot_sessions`, `flow_nodes`, `flow_sessions`, `flow_logs`, `drip_campaigns`, `drip_steps`, `drip_executions`, `lead_automation_settings`, `automation_queue`, `quick_replies` · `products`, `products_catalog`, `product_enquiries`, `catalog_interactions`, `catalog_auto_replies`, `ecommerce_orders`, `ecommerce_carts`, `ecommerce_settings`, `order_tracking_settings`, `upsell_settings`, `upsell_products`, `cart_recovery_settings`, `stock_waitlist` · `gst_invoices`, `gst_settings`, `company_pdf_settings`, `quotations` · `festival_campaigns`, `school_students`, `school_fees`, `school_fee_settings`, `vehicles`, `medicine_reminders`, `bookings`, `demo_bookings`, `booking_settings`, `missed_call_settings`, `missed_calls_log`, `review_settings`, `review_requests`, `birthday_settings`, `emi_bot_settings`, `wa_polls`, `wa_poll_responses`, `wa_forms`, `wa_form_submissions`, `digital_vcards`, `vcard_settings` · `subscriptions`, `subscription_plans`, `addon_packs`, `addon_purchases`, `referral_earnings`, `referral_logs` · `users`, `companies`, `api_keys`, `zapier_webhooks`, `sales_tracker_settings`, `sales_log`, `notification_settings`, `support_tickets`, `support_messages`, `system_settings`, `system_errors`, `pulse_runs`, `announcements`.

---

## 9. Notes for future maintainers

- **Sidebar is the only index** of reachable features. Working pages with no sidebar entry (`campaign_create.php`, `contact_import.php`, `gst_invoice_view.php`, etc.) are discoverable only from their parent page — worth knowing when auditing navigation.
- **Duplicate/sibling routes** exist by design today: `bookings.php` vs `demo_bookings.php`; `medicine_reminder.php` vs `medicine_reminders.php`; `vehicle_reminder.php` vs `vehicle_reminders.php`; `school_fee_settings.php` vs `settings_school_fees.php`; `gst_settings.php` vs `settings_gst.php`; `polls.php` vs `wa_polls.php`; `wa_health.php` vs `wa_health_monitor.php` vs `whatsapp_health.php`. The alias files and the `client_header.php` active-state checks (`in_array($cp,['vehicle_reminders','vehicle_reminder'])` etc.) are the compatibility layer.
- **Two active-state keys are shared:** `wa_health_monitor.php` reports `currentPage = wa_health_monitor` (not a sidebar item), and `premium_features.php`/`billing.php` are highlighted only on their own keys.
- **Feature cache TTL:** plan changes propagate to the sidebar within ~120 s (feature cache) and expiry within ~1 h (expiry cache).

---

## Appendix A — full per-file index (A–Z)

One line per file. `[page]` = full page; `[ep]` = endpoint/AJAX; `[alias]`; `[redirect]`; `[data]`. Gate in parentheses. Full columns in `CLIENT_PANEL_ROUTES.csv`.

```
ab_testing.php [page] — A/B campaign testing (winner selection)                         ab_tests
abandoned_cart.php [alias] — → premium_abandoned_cart.php (ecommerce_cart_recovery)
addon_packs.php [page] — buy message add-on packs (Razorpay)
ai_campaign_writer.php [page] — AI campaign copywriter
ai_lead_scores.php [page] — AI lead scores list
ajax_ai_lead_analysis.php [ep] — run AI lead analysis on a conversation
ajax_load_chat.php [ep] — load chat thread/messages
ajax_mark_upi_paid.php [ep] — mark UPI payment paid
ajax_media_proxy.php [ep] — proxy WhatsApp media
ajax_run_processor.php [ep] — trigger bot processor (ai_chatbot)
ajax_send_order_payment.php [ep] — send order payment request
ajax_send_payment.php [ep] — send payment prompt
ajax_send_quote.php [ep] — send quotation
ajax_templates.php [ep] — fetch message templates
ajax_update_gst_invoice.php [ep] — update GST invoice
ajax_voice_transcribe.php [ep] — transcribe voice note
ajax_waamini_bot.php [ep] — WAAMINI bot actions (ai_chatbot)
analytics_agents.php [page] — agent performance
analytics_revenue.php [page] — revenue analytics
api_save_chatbot.php [ep] — persist chatbot flow/nodes
automations.php [page] — automation rules overview (auto_followup|drip_campaign)
billing.php [page] — billing & wallet dashboard
blacklist.php [page] — opt-out manager (feat_blacklist)
bookings.php [page] — bookings
bot_replies.php [page] — keyword bot replies (bot_replies)
brochure_sender.php [page] — send brochure
campaign_create.php [page] — campaign builder (campaigns)
campaign_reports.php [page] — ROI reports (feat_campaign_reports)
campaign_view.php [page] — campaign report (campaigns)
campaigns.php [page] — campaign list (campaigns)
catalog.php [page] — product catalog (feat_native_checkout)
catalog_reports.php [page] — catalog reports (feat_native_checkout)
chatbot_builder.php [page] — visual flow architect (feat_chatbot_builder)
chatbot_flows.php [page] — flow list (feat_chatbot_builder)
chatbot_simple_builder.php [page] — simple keyboard chatbot builder
contact_add.php [page] — add contact (contacts)
contact_bulk.php [page] — bulk import/export (feat_contact_import)
contact_edit.php [page] — edit contact (contacts)
contact_groups.php [page] — contact groups (contacts)
contact_import.php [page] — CSV/Excel import (contacts)
contacts.php [page] — contact list (contacts)
dashboard.php [page] — business overview
dashboard_trend.php [ep] — dashboard chart trend feed
delivery_trend.php [ep] — delivery trend feed
demo_bookings.php [page] — demo bookings (bot_replies)
digital_vcard.php [page] — digital visiting card
drip_campaigns.php [alias] — → premium_drip.php (feat_drip_campaign)
ecommerce_settings.php [page] — payments & checkout config (feat_native_checkout)
festival_calendar.php [page] — festival campaign calendar
festival_campaigns.php [page] — festival reminders (festival_reminder)
flow_api.php [ep] — flow builder API (feat_chatbot_builder)
flow_builder.php [ep] — flow builder data (feat_chatbot_builder)
flow_upload.php [ep] — flow upload (feat_chatbot_builder)
form_builder.php [page] — WhatsApp form builder
form_responses.php [page] — form responses
get_chatbot_schema.php [ep] — dump chatbot table schemas (dev)
get_schema.php [ep] — dump chatbot_nodes schema (dev)
google_calendar.php [page] — Google Calendar & Meet
google_calendar_connect.php [ep] — Google OAuth connect
growth.php [page] — growth accelerator hub
gst_invoice_create.php [page] — create GST invoice
gst_invoice_view.php [page] — view/print GST invoice
gst_invoices.php [page] — GST invoice list (feat_gst_invoice)
gst_settings.php [page] — GST settings
industry_bots.php [page] — industry bot templates
integrations.php [page] — integrations hub (indiamart|fb_lead_ads)
lead_automation.php [page] — lead automation rules (crm_advance)
live_chat.php [page] — multi-agent live chat (feat_multi_agent)
manual.php [page] — user guide
medicine_reminder.php [page] — medicine reminder builder (medicine_reminder)
medicine_reminders.php [page] — medicine reminders (medicine_reminder)
message_log.php [page] — message log (message_log)
missed_call.php [page] — missed call auto-reply
notifications.php [page] — notification preferences (feat_notifications)
ocr.php [alias] — → premium_ocr.php (feat_ocr_scanner)
order_dashboard.php [page] — order analytics (feat_native_checkout)
orders.php [page] — order management (feat_native_checkout)
pdf_settings.php [page] — PDF & quotation settings (feat_pdf_quotes)
pincode_campaign.php [page] — pincode/area campaign
polls.php [page] — polls & NPS surveys
premium_abandoned_cart.php [page] — cart recovery (ecommerce_cart_recovery)
premium_ai_bot.php [redirect] — → waamini_bot.php (deprecated)
premium_analytics.php [page] — advanced CRM analytics (feat_crm_analytics)
premium_drip.php [page] — drip campaigns (feat_drip_campaign)
premium_drip_builder.php [page] — drip step builder (feat_drip_campaign)
premium_features.php [page] — discover more
premium_flows.php [page] — WA native forms (whatsapp_flows)
premium_loyalty.php [page] — loyalty program (feat_loyalty_points)
premium_ocr.php [page] — AI image lead scanner (feat_ocr_scanner)
premium_voice.php [page] — AI voice note (feat_voice_analyzer)
premium_zapier.php [page] — Zapier webhooks (api_webhook)
profile.php [page] — my profile & security
pulse.php [page] — WAAMINI Pulse account health (pulse)
qr_code.php [page] — WhatsApp QR code (qr_code)
quick_broadcast.php [page] — quick blast (feat_quick_broadcast)
quick_replies.php [page] — quick replies (quick_replies)
referrals.php [page] — referral program (feat_referral)
review_bot.php [page] — Google review bot
scheduled_messages.php [page] — scheduled blasts (feat_scheduled_msg)
school_fee_settings.php [page] — school fee settings (school_fee_reminder)
school_fees.php [page] — school fee manager (school_fee_reminder)
security.php [page] — security center (feat_2fa)
settings_api.php [page] — API & webhook (api_webhook)
settings_birthday.php [page] — birthday message settings
settings_booking.php [page] — booking bot settings
settings_bot.php [redirect] — → waamini_bot.php (deprecated)
settings_cart_recovery.php [page] — cart recovery settings
settings_emi_bot.php [page] — EMI calculator bot settings
settings_fb_leads.php [page] — Facebook Lead Ads settings
settings_general.php [page] — general settings
settings_gsheets.php [page] — Google Sheets settings (google_sheets)
settings_gst.php [page] — GST settings form
settings_indiamart.php [page] — IndiaMART settings
settings_missed_call.php [page] — missed call settings
settings_order_tracking.php [page] — order tracking bot settings
settings_payment.php [page] — payment gateway settings
settings_pdf.php [alias] — → pdf_settings.php (feat_pdf_quotes)
settings_review_bot.php [page] — review bot settings
settings_sales_tracker.php [page] — sales tracker config
settings_school_fees.php [page] — school fee settings (school_fee_reminder)
settings_spam.php [alias] — → spam_protect.php
settings_upsell.php [page] — upsell bot settings
settings_vcard.php [alias] — → vcard_settings.php (vcard)
settings_whatsapp.php [page] — WhatsApp setup
settings_zapier.php [alias] — → premium_zapier.php (api_webhook)
spam_protect.php [page] — spam protection
stock_alerts.php [page] — stock alert bot
subscription.php [page] — my subscription
support.php [page] — help & support
team_members.php [page] — team members (team_members)
template_create.php [page] — create template (templates)
templates.php [page] — templates (templates)
usage_trend.php [ep] — usage/limit trend feed
user_guide_data.php [data] — multilingual guide content (include-only)
vcard.php [alias] — → digital_vcard.php
vcard_settings.php [page] — digital vCard settings (vcard)
vehicle_reminder.php [page] — vehicle reminder builder (vehicle_reminder)
vehicle_reminders.php [page] — vehicle reminders (vehicle_reminder)
wa_health.php [page] — number health (feat_wa_health)
wa_health_monitor.php [page] — WA account health monitor
wa_polls.php [page] — WhatsApp polls & surveys
waamini_bot.php [page] — WAAMINI bot console (ai_chatbot)
waamini_bot_knowledge.php [page] — bot knowledge sources (ai_chatbot)
waamini_bot_settings.php [page] — bot settings (ai_chatbot)
whatsapp.php [page] — WhatsApp chat inbox (whatsapp_chat)
whatsapp_health.php [alias] — → wa_health.php (feat_wa_health)
```

*JSON schemas:* `chatbot_schema.json`, `schema_nodes.json` (generated by the two `get_*schema.php` helpers).

---

*This study was produced by static analysis of the current source tree (`client/` + shared shell/helpers). It records structure and wiring; it does not certify runtime behaviour, security, or plan configuration.*
