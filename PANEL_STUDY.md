# WAAMINI Admin and Client Panels — Complete Study

**Review date:** 8 October 2026  
**Scope:** Current source tree under `admin/`, `client/`, and the shared panel navigation/auth helpers.  
**Review type:** Static source study. This documents implemented entry points and visible product structure; it does not claim that every page, integration, cron, or live account was exercised.

## Executive summary

WAAMINI is a PHP/MySQL multi-tenant WhatsApp CRM and automation application. The two panels serve different jobs:

- **Admin panel:** operate the SaaS itself: customer accounts, plans and subscriptions, platform-wide messaging, support, payment/configuration, analytics, and service health.
- **Client panel:** help each business run WhatsApp operations: contacts, campaigns, inbox, bots, automations, commerce, reminders, invoices, integrations, staff, billing, and account settings.

The source contains **51 admin files** and **140 client files** (the client count includes JSON schemas and asynchronous/AJAX endpoints). Navigation is plan-aware: the shared client header checks feature flags and visibly locks selected items. The menu is not proof that direct requests enforce the same entitlement; each endpoint must enforce its own authorization and tenant scope.

This is a broad product surface with a strong operational feature set. The largest product risks to manage are the number of overlapping/legacy routes, consistent tenant and feature checks on every request, message/campaign safety and observability, and keeping documentation aligned with the shipped code. This study makes no claim of vulnerability absent an endpoint-by-endpoint security review.

## 1. Review method and evidence limits

Reviewed the source file inventory, `includes/admin_header.php`, `includes/client_header.php`, panel page names, and selected project documentation. Feature descriptions below are based on page names, navigation labels, and source structure. They are useful as a product and engineering map, not as proof that a page is complete or currently enabled in production.

No live browser session, authenticated user, production database, Meta account, payment gateway, or cron scheduler was exercised. Runtime availability, real tenant data, actual plan entitlements, delivery success rates, and visual/mobile behavior therefore remain unverified. Existing `PROJECT_STUDY.md` contains environment and user assertions that should not be treated as current facts without verification; this panel study intentionally avoids copying credentials or personal account data.

## 2. Product and role model

### Admin

The admin sidebar is a platform-operator workspace. Its primary areas are dashboard, vendors/users, notices, subscriptions/plans, broadcasts, contacts, client campaigns, WhatsApp administration, global configuration, support, analytics, pulse/AI monitoring, API/queue monitoring, and audit logs. Separate routes cover company CRUD, manual subscriptions, coupons, email templates, health tools, impersonation, and vendor subscription details.

Admin workflows can cross tenant boundaries by design. High-impact actions include creating or editing companies/users, assigning plans, broadcasting, changing global integration settings, viewing customer content, and impersonating a client. These should have narrow role checks, explicit confirmation for bulk or destructive actions, and auditable actor/target records.

### Client

A client is associated with a company and subscription. The shared client shell reads company identity and plan details from session/database state and shows announcements applicable to the plan. Feature flags determine which navigation destinations are clickable. A restricted-user condition (`$isRestricted`) hides the main feature navigation block. The exact restricted-role policy must be checked where that variable is set and at each page handler.

Client data should always be scoped to the authenticated company, including nested records, IDs supplied in query/body parameters, exports, AJAX calls, background jobs, and webhooks. Sidebar visibility improves discoverability but is not authorization.

### Impersonation

The client shell displays an “Admin Impersonation Active” strip when the session says it is impersonating, with a return route to `admin/stop_impersonate.php`. This is a privileged support feature. Recommended controls: reason capture, time-limited session, visible persistent indicator, audited start/stop, restricted access to sensitive data, and prevention of action attribution ambiguity.

## 3. Admin panel capability map

| Area | Main pages / routes | Purpose and operational value |
|---|---|---|
| Platform overview | `dashboard.php`, `analytics.php`, `revenue.php` | Aggregate operational, customer, usage, and revenue visibility. Verify metric definitions and date/time boundaries before using for financial decisions. |
| Tenants and users | `companies.php`, `company_add.php`, `company_edit.php`, `client_create.php`, `client_view.php`, `users.php`, `user_add.php`, `user_edit.php` | Provision and maintain organizations and user accounts; inspect customer context. Separate company lifecycle from user lifecycle in support procedures. |
| Plans and subscriptions | `plans.php`, `plan_edit.php`, `subscriptions.php`, `subscription_add.php`, `subscription_view.php`, `subscriptions_manual.php`, `vendor_subscription.php`, `coupons.php` | Define offers, assign/inspect entitlements and subscription state, handle manual provisioning and discounts. Protect against mismatches between plan UI, stored features, and client checks. |
| Messaging operations | `broadcast.php`, `campaigns.php`, `campaign_create.php`, `campaign_view.php`, `contacts.php`, `contact_add.php`, `contact_edit.php`, `contact_import.php`, `groups.php`, `group_manage.php`, `templates.php`, `whatsapp.php` | Platform/admin-side messaging oversight and account-level contact/template operations. Any cross-tenant send or contact edit should show target company and recipient count before commit. |
| Customer support | `support_inbox.php`, `announcements.php` | Triage client support and publish system/plan notices. Support state and outbound communications should be auditable. |
| Global configuration | `settings.php`, `settings_general.php`, `settings_payment.php`, `settings_smtp.php`, `settings_whatsapp.php`, `email_templates.php` | Manage global service settings and outbound email/payment/WhatsApp defaults. Secrets should be masked, stored safely, and excluded from logs/UI responses. |
| Service health and usage | `pulse.php`, `queue_health.php`, `server_health.php`, `system_health.php`, `api_usage.php`, `audit_log.php` | Monitor platform signals, queues, server state, third-party API consumption, and security events. Define alert thresholds and retention operationally. |
| AI and advanced operations | `waamini_bot.php`, `fix_whatsapp_ui.php`, `chat_api.php` | Admin bot and specialized operational tools. Verify exposure, method restrictions, and authorization carefully for utility/debug routes. |
| Account access | `profile.php`, `impersonate.php`, `stop_impersonate.php` | Admin identity/profile and client support access. Impersonation should preserve admin attribution and create a tamper-resistant audit event. |

### Admin navigation observations

The shared admin header has clear sections for Main, Subscriptions, Modules, Configurations, and System. Some secondary pages are reached from list/detail workflows rather than a direct sidebar link. A navigation audit should confirm that every intended page is reachable, active-state highlighting works for related pages, and unlinked operational pages are either intentionally internal or documented.

## 4. Client panel capability map

| Area | Representative routes | What the business user can do |
|---|---|---|
| Daily workspace | `dashboard.php`, `growth.php`, `notifications.php`, `manual.php` | Review business activity, growth entry points, notices, and usage guidance. Dashboard metric definitions and links should match actual permissions. |
| WhatsApp setup and inbox | `whatsapp.php`, `settings_whatsapp.php`, `live_chat.php`, `message_log.php`, `qr_code.php`, `wa_health.php`, `wa_health_monitor.php`, `whatsapp_health.php`, `pulse.php` | Configure or inspect WhatsApp connection, handle conversations, inspect messages/health, and share a QR contact entry point. The three health routes may overlap; define canonical status and intended audience. |
| Contacts and audiences | `contacts.php`, `contact_add.php`, `contact_edit.php`, `contact_import.php`, `contact_bulk.php`, `contact_groups.php`, `blacklist.php` | Maintain contacts, import/bulk edit, segment into groups, and suppress opted-out/problem recipients. Imports need validation, deduplication, consent provenance, and row-level error feedback. |
| Campaigns and templates | `campaigns.php`, `campaign_create.php`, `campaign_view.php`, `campaign_reports.php`, `quick_broadcast.php`, `scheduled_messages.php`, `templates.php`, `template_create.php`, `brochure_sender.php`, `ai_campaign_writer.php`, `ab_testing.php`, `pincode_campaign.php` | Draft, schedule, send, test, and analyze messages. Preview audience/template/cost, enforce platform policy and opt-out suppression, and distinguish queued, sent, delivered, read, and failed. |
| Bots and automation | `bot_replies.php`, `quick_replies.php`, `chatbot_simple_builder.php`, `chatbot_builder.php`, `chatbot_flows.php`, `api_save_chatbot.php`, `get_chatbot_schema.php`, `get_schema.php`, `automations.php`, `lead_automation.php`, `drip_campaigns.php`, `premium_drip.php`, `premium_drip_builder.php`, `premium_flows.php`, `premium_ai_bot.php`, `waamini_bot.php`, `waamini_bot_knowledge.php`, `waamini_bot_settings.php`, `industry_bots.php` | Configure keyword replies, flow builders, AI knowledge and behavior, lead workflows, and timed follow-up sequences. Provide versioning, test mode, loop limits, human handoff, and a clear pause/kill switch. |
| Commerce and orders | `catalog.php`, `orders.php`, `order_dashboard.php`, `catalog_reports.php`, `ecommerce_settings.php`, `abandoned_cart.php`, `premium_abandoned_cart.php`, `stock_alerts.php`, `settings_cart_recovery.php`, `settings_order_tracking.php`, `settings_upsell.php`, `settings_payment.php`, `ajax_send_order_payment.php`, `ajax_send_payment.php`, `ajax_mark_upi_paid.php` | Manage product/order flows and payment prompts, recover abandoned carts, and track commerce signals. Payment status must come from trusted gateway confirmation or controlled reconciliation, not an untrusted browser assertion. |
| Documents and finance | `gst_invoices.php`, `gst_invoice_create.php`, `gst_invoice_view.php`, `gst_settings.php`, `pdf_settings.php`, `ajax_update_gst_invoice.php`, `ajax_send_quote.php` | Create and deliver invoices/quotes and configure document defaults. Confirm numbering, tax calculations, edit history, access-controlled public links, and jurisdictional requirements with the business owner. |
| Business-specific reminders | `festival_calendar.php`, `festival_campaigns.php`, `school_fees.php`, `school_fee_settings.php`, `medicine_reminder.php`, `medicine_reminders.php`, `vehicle_reminder.php`, `vehicle_reminders.php`, `bookings.php`, `demo_bookings.php`, `google_calendar.php`, `missed_call.php`, `review_bot.php`, `polls.php`, `wa_polls.php`, `form_builder.php`, `form_responses.php`, `ocr.php`, `premium_ocr.php`, `digital_vcard.php`, `vcard.php`, `vcard_settings.php` | Industry workflows for reminders, bookings, lead capture, reviews, polls/forms, OCR and digital cards. Data minimization is especially important for health-related medicine reminders and student/fee records. |
| Team and analytics | `team_members.php`, `analytics_agents.php`, `analytics_revenue.php`, `premium_analytics.php`, `ai_lead_scores.php`, `ajax_ai_lead_analysis.php` | Manage agents and understand agent/revenue/lead performance. Document attribution, score inputs, confidence, and access to personal data. |
| Integrations | `integrations.php`, `settings_api.php`, `settings_gsheets.php`, `settings_zapier.php`, `settings_indiamart.php`, `settings_fb_leads.php`, `google_calendar.php`, `ajax_media_proxy.php` | Connect external tools and receive/send business data. Use scoped credentials, signed webhook validation, replay protection, and per-integration status/last-success timestamps. |
| Account, safety and help | `profile.php`, `settings_general.php`, `security.php`, `billing.php`, `subscription.php`, `addon_packs.php`, `premium_features.php`, `referrals.php`, `spam_protect.php`, `settings_spam.php`, `support.php`, `settings_sales_tracker.php` | Manage account security, subscription, add-ons, referrals, anti-spam controls, help requests, and sales settings. Keep billing and security actions strongly authenticated and auditable. |
| Async handlers / data schemas | `ajax_*`, `api_save_chatbot.php`, `get_schema.php`, `get_chatbot_schema.php`, `chatbot_schema.json`, `schema_nodes.json`, `user_guide_data.php` | Back the interactive panel with JSON actions, schema definitions, and in-product guide data. These routes require the same session, company, feature, CSRF, and input validation as full-page handlers. |

The routes above are representative groupings; the exhaustive file inventory is in Appendix A.

## 5. Core user journeys

### Client onboarding to first campaign

1. Admin provisions company/user and selects subscription or trial.
2. Client signs in and completes WhatsApp setup and required Meta business/template configuration.
3. Client imports or adds contacts, normalizes numbers, segments into groups, and maintains suppression/consent data.
4. Client creates or chooses an approved template, selects a target segment, previews the audience, schedules/sends.
5. Queue/worker and WhatsApp API process delivery; webhook/status processing updates logs and reports.
6. Client follows responses in inbox, assigns an agent, and measures outcomes.

Key controls: duplicate-send prevention, company-scoped recipient selection, rate/plan limits, opt-out suppression, template approval state, retry policy, idempotency, and transparent delivery status.

### Inbound conversation to bot or agent

1. WhatsApp webhook receives inbound event and associates it with the correct company/contact.
2. Message is persisted and routed through enabled automation/bot rules.
3. A bot response is sent or conversation is made available in `live_chat.php` for a human agent.
4. Reply/status is persisted and visible in conversation history and logs.

Key controls: webhook signature verification, event deduplication, tenant resolution, safe media handling, bot pause/handoff, queue visibility, and no cross-company conversation lookup.

### Commerce and payment

1. Client configures catalogue/checkout/payment settings and products.
2. Customer order/quote/payment prompt is generated and sent over WhatsApp.
3. Gateway callback or verified staff reconciliation updates payment state.
4. Order and invoice views reflect canonical status; reminders/recovery can run from scheduled jobs.

Key controls: signed callbacks, server-side amount/currency validation, immutable payment event history, controlled UPI verification, and clear separation of order versus payment state.

### Subscription change

1. Admin edits plan/assigns subscription or client visits billing/subscription.
2. New entitlements and billing period take effect.
3. Client sidebar reflects available features; server-side checks enforce the same state.
4. Usage/add-on changes are reflected in limits and reporting.

Key controls: a single entitlement source of truth, clear trial/expired/grace states, proration/refund policy, and tests for direct URLs and async endpoints after downgrade.

## 6. Access control and data boundaries

The client shell uses company and plan context and calls `has_feature()` for several menu items. It also includes a restricted-user navigation branch. Treat these as presentation logic. For each protected request, verify all of the following server-side:

- authenticated session and correct role;
- company scope derived from trusted session/user membership, never solely from submitted `company_id`;
- object ownership for every record ID, including child records and exports;
- subscription/feature state and usage limits;
- CSRF token for state-changing browser requests;
- HTTP method and input schema validation;
- safe output encoding and controlled file/media access.

Admin routes need explicit `super_admin` (or narrower role) gates before queries/actions. AJAX and helper routes deserve the same review as page routes. Impersonation needs distinct actor and effective-user identities in logs.

## 7. Product and UX observations

- **Strong breadth:** the client journey spans acquisition, messaging, service, commerce, finance, and vertical reminders.
- **Feature discoverability:** locked sidebar items communicate plan gating. Ensure the upgrade path is contextual and entitlement checks match the UI.
- **Route overlap:** WhatsApp health has multiple pages; reminders have singular/plural variants; AI/drip/chatbot features have standard and premium variants. Clarify canonical routes and migration/deprecation status.
- **Navigation scale:** 140 client files imply a substantial menu and learning burden. Group by tasks, add search/favorites/recent items, and make onboarding guide users through setup prerequisites.
- **Action confidence:** bulk send, import, payment marking, invoice edits, and automation activation should present a review/preview step and a recoverable audit trail.
- **Responsive support:** headers contain sidebar/mobile behavior, but source inspection is not visual QA. Validate narrow screens, keyboard navigation, focus states, and long company/plan names in a browser.

## 8. Engineering and operational recommendations

### P0 — verify before production changes

1. Build a route access matrix for all admin/client pages and async endpoints: role, company scope, feature key, methods, and state-changing operations.
2. Confirm webhook signature validation, idempotency, replay handling, status callback mapping, and tenant routing for every Meta endpoint.
3. Review payment callbacks and manual “mark paid” paths; preserve verifiable event history and authorization evidence.
4. Confirm secrets are not committed, returned to the browser, included in logs, or exposed by health/debug routes. Rotate any credential found in repository history if confirmed exposed.
5. Ensure bulk sends respect consent/blacklist, plan limits, platform rate limits, and idempotent retry behavior.

### P1 — reliability and maintainability

1. Consolidate duplicated health/reminder/AI pages behind canonical routes; document legacy compatibility.
2. Create a central route/feature registry so sidebar, server authorization, plan editor, and help docs share feature keys.
3. Add queue dashboards for oldest pending job, retry/dead-letter counts, throughput, provider errors, and per-tenant backlog.
4. Standardize logs with correlation ID, tenant ID, actor, action, target, result, and redaction rules.
5. Add integration health status and last success/failure timestamp for Meta, SMTP, payment, Sheets, Zapier, IndiaMART, Facebook lead capture, and calendar.
6. Define backup/restore, retention, exports/deletion, cron ownership, and alert escalation procedures.

### P2 — product polish

1. Add client onboarding checklist and readiness score for WhatsApp, templates, contacts, and first automation.
2. Show delivery funnel definitions and annotate data latency/source.
3. Make feature upgrade explanations link to the matching plan/add-on with clear effect timing.
4. Improve accessibility and responsive QA for large tables, chat, builders, and setup forms.
5. Offer safe dry-run/test-recipient modes for campaigns and automations.

## 9. Suggested acceptance checklist

- [ ] Admin non-admin user cannot load or invoke any admin endpoint.
- [ ] Client A cannot read or mutate Client B records using guessed IDs, filters, exports, AJAX, or media URLs.
- [ ] Expired/downgraded plan loses access server-side consistently, including async routes.
- [ ] State-changing forms and AJAX reject missing/invalid CSRF tokens and wrong HTTP methods.
- [ ] Contact import reports invalid, duplicate, suppressed, and accepted rows accurately.
- [ ] Campaign preview equals queued recipients; blacklist/opt-out and policy states are applied.
- [ ] Retried jobs/webhooks do not duplicate messages, payments, invoices, or automation effects.
- [ ] Queue failures and provider errors are visible, actionable, and alertable.
- [ ] Payment updates are attributable and supported by verified evidence.
- [ ] Impersonation start/stop/actions are separately attributable and visibly disclosed.
- [ ] Exports, invoices, media, and public vCards follow explicit privacy/access rules.
- [ ] Mobile and keyboard-only flows work for navigation, forms, tables, chat, and builders.

## Appendix A — exhaustive source file inventory

Inventory generated from files currently present in `admin/` and `client/`; it includes support handlers and schemas, not only user-facing pages.

### Admin (51 files)

`analytics.php`, `announcements.php`, `api_usage.php`, `audit_log.php`, `broadcast.php`, `campaigns.php`, `campaign_create.php`, `campaign_view.php`, `chat_api.php`, `client_create.php`, `client_view.php`, `companies.php`, `company_add.php`, `company_edit.php`, `contacts.php`, `contact_add.php`, `contact_edit.php`, `contact_import.php`, `coupons.php`, `dashboard.php`, `email_templates.php`, `fix_whatsapp_ui.php`, `group_manage.php`, `groups.php`, `impersonate.php`, `plan_edit.php`, `plans.php`, `profile.php`, `pulse.php`, `queue_health.php`, `revenue.php`, `server_health.php`, `settings.php`, `settings_general.php`, `settings_payment.php`, `settings_smtp.php`, `settings_whatsapp.php`, `stop_impersonate.php`, `subscription_add.php`, `subscription_view.php`, `subscriptions.php`, `subscriptions_manual.php`, `support_inbox.php`, `system_health.php`, `templates.php`, `user_add.php`, `user_edit.php`, `users.php`, `vendor_subscription.php`, `waamini_bot.php`, `whatsapp.php`.

### Client (140 files)

`ab_testing.php`, `abandoned_cart.php`, `addon_packs.php`, `ai_campaign_writer.php`, `ai_lead_scores.php`, `ajax_ai_lead_analysis.php`, `ajax_load_chat.php`, `ajax_mark_upi_paid.php`, `ajax_media_proxy.php`, `ajax_run_processor.php`, `ajax_send_order_payment.php`, `ajax_send_payment.php`, `ajax_send_quote.php`, `ajax_templates.php`, `ajax_update_gst_invoice.php`, `ajax_waamini_bot.php`, `analytics_agents.php`, `analytics_revenue.php`, `api_save_chatbot.php`, `automations.php`, `billing.php`, `blacklist.php`, `bookings.php`, `bot_replies.php`, `brochure_sender.php`, `campaign_create.php`, `campaign_reports.php`, `campaign_view.php`, `campaigns.php`, `catalog.php`, `catalog_reports.php`, `chatbot_builder.php`, `chatbot_flows.php`, `chatbot_schema.json`, `chatbot_simple_builder.php`, `contact_add.php`, `contact_bulk.php`, `contact_edit.php`, `contact_groups.php`, `contact_import.php`, `contacts.php`, `dashboard.php`, `demo_bookings.php`, `digital_vcard.php`, `drip_campaigns.php`, `ecommerce_settings.php`, `festival_calendar.php`, `festival_campaigns.php`, `form_builder.php`, `form_responses.php`, `get_chatbot_schema.php`, `get_schema.php`, `google_calendar.php`, `gst_invoice_create.php`, `gst_invoice_view.php`, `gst_invoices.php`, `gst_settings.php`, `growth.php`, `industry_bots.php`, `integrations.php`, `lead_automation.php`, `live_chat.php`, `manual.php`, `medicine_reminder.php`, `medicine_reminders.php`, `message_log.php`, `missed_call.php`, `notifications.php`, `ocr.php`, `order_dashboard.php`, `orders.php`, `pdf_settings.php`, `pincode_campaign.php`, `polls.php`, `premium_abandoned_cart.php`, `premium_ai_bot.php`, `premium_analytics.php`, `premium_drip.php`, `premium_drip_builder.php`, `premium_features.php`, `premium_flows.php`, `premium_loyalty.php`, `premium_ocr.php`, `premium_voice.php`, `premium_zapier.php`, `profile.php`, `pulse.php`, `qr_code.php`, `quick_broadcast.php`, `quick_replies.php`, `referrals.php`, `review_bot.php`, `scheduled_messages.php`, `schema_nodes.json`, `school_fee_settings.php`, `school_fees.php`, `security.php`, `settings_api.php`, `settings_birthday.php`, `settings_booking.php`, `settings_bot.php`, `settings_cart_recovery.php`, `settings_emi_bot.php`, `settings_fb_leads.php`, `settings_general.php`, `settings_gsheets.php`, `settings_gst.php`, `settings_indiamart.php`, `settings_missed_call.php`, `settings_order_tracking.php`, `settings_payment.php`, `settings_pdf.php`, `settings_review_bot.php`, `settings_sales_tracker.php`, `settings_school_fees.php`, `settings_spam.php`, `settings_upsell.php`, `settings_vcard.php`, `settings_whatsapp.php`, `settings_zapier.php`, `spam_protect.php`, `stock_alerts.php`, `subscription.php`, `support.php`, `team_members.php`, `template_create.php`, `templates.php`, `user_guide_data.php`, `vcard.php`, `vcard_settings.php`, `vehicle_reminder.php`, `vehicle_reminders.php`, `wa_health.php`, `wa_health_monitor.php`, `wa_polls.php`, `waamini_bot.php`, `waamini_bot_knowledge.php`, `waamini_bot_settings.php`, `whatsapp.php`, `whatsapp_health.php`.
