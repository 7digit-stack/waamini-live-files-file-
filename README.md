# WAAMINI — Complete Project Study

> WhatsApp Business Automation & CRM Platform  
> Version: 2.1.0 | Stack: PHP + MySQL + Meta Cloud API  
> Environment: XAMPP (Windows) | URL: http://localhost:8888/waamini/

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [Directory Structure](#3-directory-structure)
4. [Database Architecture](#4-database-architecture)
5. [Configuration & Bootstrap Flow](#5-configuration--bootstrap-flow)
6. [Authentication & Authorization Flow](#6-authentication--authorization-flow)
7. [WhatsApp Integration & Webhook Flow](#7-whatsapp-integration--webhook-flow)
8. [Smart Router — 3-Tier Message Routing](#8-smart-router--3-tier-message-routing)
9. [Admin Panel — Full Feature Map](#9-admin-panel--full-feature-map)
10. [Client Panel — Full Feature Map](#10-client-panel--full-feature-map)
11. [API Endpoints](#11-api-endpoints)
12. [Automation Engine & Cron Jobs](#12-automation-engine--cron-jobs)
13. [Campaign System Flow](#13-campaign-system-flow)
14. [AI Features](#14-ai-features)
15. [E-Commerce & Payment Flow](#15-e-commerce--payment-flow)
16. [Drip Campaign Engine](#16-drip-campaign-engine)
17. [Security Architecture](#17-security-architecture)
18. [External Integrations](#18-external-integrations)
19. [Multi-Tenancy Model](#19-multi-tenancy-model)
20. [Full End-to-End Workflows](#20-full-end-to-end-workflows)

---

## 1. Project Overview

WAAMINI is a **multi-tenant SaaS WhatsApp Business CRM and Automation Platform** built in PHP. It allows businesses (clients) to:

- Send bulk WhatsApp campaigns to contact lists
- Run AI-powered chatbots and sales bots
- Automate follow-ups via drip campaigns
- Manage contacts, leads, and CRM pipelines
- Generate GST invoices and handle UPI/Razorpay payments
- Integrate with Google Sheets, Zapier, IndiaMART, Facebook Leads
- Run school fee reminders, birthday wishes, medicine reminders, vehicle reminders
- Provide a live chat inbox for human agent handoff

**Business Model:**  
- Super Admin creates companies and assigns subscription plans  
- Each company (client) gets their own WhatsApp Business API config  
- Clients are billed via subscription plans with message limits  
- Feature access is gated by subscription tier (free/basic/premium)

**Current Users (DB):**

| ID | Name | Email | Role |
|----|------|-------|------|
| 1 | Rahul | info@7digit.in | super_admin |
| 100 | Super Admin | admin@waamini.com | super_admin |
| 2 | Rahul | team7digit@gmail.com | client |

---

## 2. Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend Language | PHP 7.3+ with strict typing |
| Database ORM | mysqli (legacy) + PDO (modern, config/database.php) |
| Database | MySQL 5.7+ / MariaDB |
| Session Storage | File-based PHP sessions (storage/sessions/) |
| Frontend CSS | Bootstrap + custom CSS (admin.css, client.css, auth.css) |
| Frontend JS | Vanilla JS (theme.js, admin.js, auth-autofill-fix.js) |
| WhatsApp API | Meta Graph API v20.0 (Cloud API) |
| AI/LLM | OpenAI GPT (ai_campaign_writer, ai_smart_reply, ai_bot) |
| Payments | Razorpay + UPI (pay_upi.php) |
| Email | PHPMailer (vendor/PHPMailer/) via SMTP (Gmail) |
| HTTP Tunneling | ngrok (for local webhook dev) |
| Testing | Playwright (TypeScript) |
| Package Manager | Composer (PHP) + npm (Node) |
| Dev Server | XAMPP Apache (port 8888) + MySQL (port 3306) |

---

## 3. Directory Structure

```
waamini/
│
├── index.php                    # Public landing page (marketing site)
├── login.php                    # Auth entry point (admin + client)
├── logout.php                   # Session destroy + redirect
├── register.php                 # New client registration
├── forgot-password.php          # Password reset request
├── reset-password.php           # Password reset via token
├── verify_2fa.php               # Two-factor OTP verification
├── dashboard.php                # Role-based redirect hub
├── webhook.php                  # Meta WhatsApp webhook handler (CORE)
├── db.php                       # mysqli connection singleton
├── db_config.sample.php         # DB credentials template
├── db_production_template.php   # Production config template
├── smtp.php                     # SMTP config helper
├── .env                         # Live environment variables
├── .env.example                 # Env template
├── pay_upi.php                  # UPI payment page
├── vcard.php                    # Public vCard display
├── ecommerce.php                # Public e-commerce landing
├── blogs.php                    # Blog listing page
├── pricing.php                  # Pricing page
├── features.php                 # Features page
├── support.php                  # Public support page
├── privacy-policy.php           # Legal page
├── terms-of-service.php         # Legal page
│
├── admin/                       # Super Admin panel (51 files)
│   ├── dashboard.php            # Admin overview stats
│   ├── companies.php            # List all companies
│   ├── company_add.php          # Create new company
│   ├── company_edit.php         # Edit company details
│   ├── users.php                # All users list
│   ├── user_add.php             # Add user
│   ├── user_edit.php            # Edit user
│   ├── plans.php                # Subscription plans list
│   ├── plan_edit.php            # Edit plan
│   ├── subscriptions.php        # All subscriptions
│   ├── subscription_add.php     # Assign subscription
│   ├── subscription_view.php    # View subscription detail
│   ├── subscriptions_manual.php # Manual subscription entry
│   ├── campaigns.php            # All campaigns across companies
│   ├── campaign_create.php      # Create campaign (admin)
│   ├── campaign_view.php        # Campaign analytics
│   ├── contacts.php             # All contacts
│   ├── contact_add.php          # Add contact
│   ├── contact_edit.php         # Edit contact
│   ├── contact_import.php       # Bulk CSV import
│   ├── groups.php               # Contact groups
│   ├── group_manage.php         # Manage group members
│   ├── broadcast.php            # Bulk broadcast panel
│   ├── templates.php            # WhatsApp message templates
│   ├── analytics.php            # Platform-wide analytics
│   ├── revenue.php              # Revenue dashboard
│   ├── pulse.php                # System pulse / health
│   ├── queue_health.php         # Queue monitoring
│   ├── server_health.php        # Server resource monitor
│   ├── system_health.php        # Combined health dashboard
│   ├── audit_log.php            # Security audit log viewer
│   ├── announcements.php        # System announcements
│   ├── coupons.php              # Discount coupons
│   ├── email_templates.php      # Email template editor
│   ├── smart_router.php         # Smart router config (admin view)
│   ├── settings_general.php     # Global settings
│   ├── settings_payment.php     # Payment gateway settings
│   ├── settings_smtp.php        # SMTP settings
│   ├── settings_whatsapp.php    # Default WhatsApp config
│   ├── whatsapp.php             # WhatsApp config manager
│   ├── profile.php              # Admin profile
│   ├── api_usage.php            # API usage stats
│   ├── support_inbox.php        # Support ticket inbox
│   ├── impersonate.php          # Impersonate a client
│   ├── stop_impersonate.php     # End impersonation
│   └── vendor_subscription.php  # Vendor subscription view
│
├── client/                      # Client dashboard (110 files)
│   ├── dashboard.php            # Client overview
│   ├── contacts.php             # Contact list
│   ├── contact_add.php          # Add contact
│   ├── contact_edit.php         # Edit contact
│   ├── contact_import.php       # CSV import
│   ├── contact_groups.php       # Contact groups
│   ├── contact_bulk.php         # Bulk operations
│   ├── blacklist.php            # Blacklisted numbers
│   ├── campaigns.php            # Campaign list
│   ├── campaign_create.php      # Campaign builder
│   ├── campaign_view.php        # Campaign report
│   ├── campaign_reports.php     # Reports overview
│   ├── templates.php            # Message templates
│   ├── template_create.php      # Create template
│   ├── quick_broadcast.php      # Quick one-off broadcast
│   ├── scheduled_messages.php   # Scheduled message queue
│   ├── message_log.php          # All message logs
│   ├── live_chat.php            # Live chat inbox
│   ├── chatbot_builder.php      # Visual chatbot builder (Flow)
│   ├── chatbot_flows.php        # Chatbot flow list
│   ├── chatbot_simple_builder.php # Simple keyword bot
│   ├── bot_replies.php          # Keyword bot replies
│   ├── quick_replies.php        # Quick reply shortcuts
│   ├── automations.php          # Automation rules
│   ├── drip_campaigns.php       # Drip campaign manager
│   ├── lead_automation.php      # Lead automation rules
│   ├── ai_lead_scores.php       # AI lead scoring view
│   ├── ai_campaign_writer.php   # AI campaign content generator
│   ├── analytics_agents.php     # Agent performance analytics
│   ├── analytics_revenue.php    # Revenue analytics
│   ├── pulse.php                # WhatsApp health pulse
│   ├── wa_health.php            # WA health detail
│   ├── wa_health_monitor.php    # WA health monitoring
│   ├── whatsapp_health.php      # WA API health check
│   ├── whatsapp.php             # WA config page
│   ├── settings_whatsapp.php    # WA settings form
│   ├── settings_bot.php         # Bot settings
│   ├── settings_api.php         # API key settings
│   ├── settings_payment.php     # Payment settings
│   ├── settings_birthday.php    # Birthday campaign settings
│   ├── settings_booking.php     # Booking bot settings
│   ├── settings_cart_recovery.php # Cart recovery settings
│   ├── settings_emi_bot.php     # EMI bot settings
│   ├── settings_fb_leads.php    # FB Leads webhook settings
│   ├── settings_gsheets.php     # Google Sheets integration
│   ├── settings_gst.php         # GST settings
│   ├── settings_indiamart.php   # IndiaMART settings
│   ├── settings_missed_call.php # Missed call trigger settings
│   ├── settings_order_tracking.php # Order tracking settings
│   ├── settings_pdf.php         # PDF invoice settings
│   ├── settings_review_bot.php  # Review request bot
│   ├── settings_sales_tracker.php # Sales tracker config
│   ├── settings_school_fees.php # School fee settings
│   ├── settings_spam.php        # Spam protection settings
│   ├── settings_upsell.php      # Upsell bot settings
│   ├── settings_vcard.php       # Digital vCard settings
│   ├── settings_zapier.php      # Zapier webhook settings
│   ├── gst_invoices.php         # GST invoice list
│   ├── gst_invoice_create.php   # Create GST invoice
│   ├── gst_invoice_view.php     # View/print GST invoice
│   ├── gst_settings.php         # GST company settings
│   ├── orders.php               # E-commerce orders
│   ├── order_dashboard.php      # Order analytics
│   ├── ecommerce_settings.php   # E-commerce configuration
│   ├── catalog.php              # Product catalog
│   ├── festival_campaigns.php   # Festival message campaigns
│   ├── festival_calendar.php    # Festival calendar view
│   ├── school_fees.php          # School fee management
│   ├── school_fee_settings.php  # School fee config
│   ├── medicine_reminders.php   # Medicine reminder list
│   ├── medicine_reminder.php    # Medicine reminder builder
│   ├── vehicle_reminders.php    # Vehicle reminder list
│   ├── vehicle_reminder.php     # Vehicle reminder builder
│   ├── digital_vcard.php        # Digital vCard builder
│   ├── vcard.php                # vCard preview
│   ├── vcard_settings.php       # vCard config
│   ├── qr_code.php              # QR code generator
│   ├── referrals.php            # Referral program
│   ├── billing.php              # Billing & subscription
│   ├── subscription.php         # Subscription detail
│   ├── notifications.php        # Notification center
│   ├── profile.php              # Client profile
│   ├── security.php             # Security settings (2FA)
│   ├── support.php              # Support ticket system
│   ├── team_members.php         # Team member management
│   ├── integrations.php         # Integrations hub
│   ├── growth.php               # Growth tools
│   ├── bookings.php             # Booking management
│   ├── polls.php / wa_polls.php # WhatsApp polls
│   ├── form_builder.php         # Lead form builder
│   ├── form_responses.php       # Form responses viewer
│   ├── ocr.php / premium_ocr.php # OCR (Premium)
│   ├── premium_*.php            # Premium-gated features
│   └── ajax_*.php               # AJAX endpoint handlers
│
├── api/                         # REST API endpoints (50 files)
│   ├── auth.php                 # API authentication
│   ├── send_message.php         # Send WhatsApp message
│   ├── campaigns.php            # Campaign API
│   ├── contact.php              # Contact CRUD API
│   ├── contact_export.php       # Contact export
│   ├── chatbot.php              # Chatbot API
│   ├── drip_*.php               # Drip campaign APIs
│   ├── ai_*.php                 # AI feature APIs
│   ├── payment_*.php            # Payment APIs
│   ├── webhook APIs             # FB leads, missed call, Zapier
│   └── health.php               # Health check endpoint
│
├── includes/                    # Shared PHP utilities (46 files)
│   ├── auth.php                 # Auth class (PDO-based)
│   ├── auth_check.php           # Admin auth guard
│   ├── client_auth_check.php    # Client auth guard
│   ├── whatsappapi.php          # WhatsApp Cloud API class
│   ├── whatsapp_settings.php    # WA settings loader/decryptor
│   ├── chatbot_engine.php       # Chatbot keyword matching engine
│   ├── smart_router.php         # 3-tier message router (CORE)
│   ├── drip_engine.php          # Drip campaign scheduler
│   ├── CampaignProcessor.php    # Campaign send processor
│   ├── functions.php            # Global helper functions
│   ├── security.php             # CSRF, rate limiter, sanitizer
│   ├── ratelimiter.php          # IP/user rate limiting
│   ├── ai_lead_scorer.php       # AI lead scoring engine
│   ├── ai_usage.php             # AI usage tracker
│   ├── ai_language_reply.php    # Multilingual AI reply
│   ├── ai_voice_handler.php     # Voice message handler
│   ├── logging.php              # Structured logging
│   ├── audit_helper.php         # Audit log writer
│   ├── cache.php                # File-based cache (TTL)
│   ├── page_cache.php           # Page-level cache
│   ├── feature_check.php        # Plan feature gate
│   ├── GoogleAuthenticator.php  # TOTP 2FA
│   ├── admin_header.php         # Admin layout header
│   ├── admin_footer.php         # Admin layout footer
│   ├── client_header.php        # Client layout header
│   ├── client_footer.php        # Client layout footer
│   ├── sales_tracker.php        # Sales tracker processor
│   ├── loyalty_handler.php      # Loyalty points system
│   ├── upsell_handler.php       # Upsell bot handler
│   ├── booking_bot_handler.php  # Booking bot processor
│   ├── order_tracking_handler.php # Order tracking bot
│   ├── ecommerce_handler.php    # E-commerce checkout
│   ├── whatsapp_catalog_checkout.php # Catalog to checkout
│   ├── monitor_handler.php      # Monitoring helper
│   ├── wa_rate_limiter.php      # WhatsApp-specific rate limiter
│   ├── schema_guard.php         # DB schema compatibility guard
│   ├── server_health.php        # Server health metrics
│   ├── mail_settings.php        # Mail settings loader
│   ├── order_invoice_whatsapp.php # Invoice via WhatsApp
│   ├── campaign_trigger_helper.php # Campaign trigger logic
│   └── cron_order_recovery.php  # Cart recovery cron helper
│
├── config/                      # Configuration layer (5 files)
│   ├── bootstrap.php            # App bootstrap (sessions, autoload, env)
│   ├── config.php               # AppConfig singleton (env-based)
│   ├── database.php             # PDO Database class
│   ├── env.php                  # .env file parser
│   └── whatsapp.php             # WhatsApp config loader
│
├── services/                    # Service layer
│   └── EncryptionService.php    # AES encryption for stored tokens
│
├── migrations/                  # DB schema migrations (16 SQL + 2 PHP)
├── assets/css/                  # 17 CSS files
├── assets/js/                   # 4 JS files
├── storage/                     # Logs + sessions (runtime)
├── uploads/                     # User-uploaded files
├── queue/                       # Queue worker scripts
├── workers/                     # Background worker processes
├── scripts/                     # Utility/maintenance scripts
└── tests/                       # Playwright test suite
```

---

## 4. Database Architecture

**Database:** `waamini` (MySQL)  
**Connection:** mysqli via `db.php` (TCP 127.0.0.1:3306, persistent, UTF-8MB4)

### Core Tables (85 total)

#### Identity & Auth
| Table | Purpose |
|-------|---------|
| `users` | All users (super_admin + client). Fields: id, name, email, password (bcrypt), role, status, company_id, two_factor_secret, two_factor_enabled, permissions |
| `companies` | Tenant companies. Each client belongs to one company |
| `user_sessions` | Active session tracking |
| `user_tokens` | Remember-me tokens (30-day TTL) |
| `password_resets` | Password reset tokens |
| `ip_lockouts` | IP-based lockout records |
| `audit_log` / `audit_logs` | Security event log |

#### WhatsApp Core
| Table | Purpose |
|-------|---------|
| `whatsapp_config` | Per-company WhatsApp API credentials (phone_number_id, access_token, verify_token, api_version) |
| `messages` | All messages (inbound + outbound). Fields: company_id, contact_id, direction, type, content, message, media_url, status, whatsapp_message_id |
| `message_templates` | Approved WhatsApp message templates |
| `message_logs` | Detailed send logs |
| `whatsapp_api_logs` | Raw API call logs with latency |
| `wa_health_logs` | API health check history |
| `webhook_logs` | Raw incoming webhook payloads |
| `message_routing_log` | Smart router routing decisions |

#### Contacts & CRM
| Table | Purpose |
|-------|---------|
| `contacts` | All contacts per company. Fields: company_id, phone, name, email, source, status, last_message_at, notes |
| `contact_groups` | Contact group definitions |
| `contact_group_members` | Group membership mapping |
| `contact_notes` | CRM timeline notes |
| `contact_reminders` | Scheduled contact reminders |
| `blacklist` | Opted-out numbers |
| `lead_scores` | AI lead scoring results |
| `chat_conversations` | Inbox summary (last_message, unread_count) |
| `chat_messages` | Live chat message store |

#### Campaigns
| Table | Purpose |
|-------|---------|
| `campaigns` | Campaign definitions (name, type, status, sent/delivered/read/failed counts) |
| `campaign_queue` | Per-contact send queue with WA delivery status tracking |
| `scheduled_messages` | Time-scheduled messages |
| `drip_campaigns` | Drip campaign definitions |
| `drip_steps` | Drip campaign steps (delay + message) |
| `drip_executions` | Per-contact drip execution state |
| `festival_campaigns` | Festival-specific campaign templates |

#### Automation
| Table | Purpose |
|-------|---------|
| `automation_queue` | Async task queue (ai_reply, drip, etc.) |
| `queue_jobs` | General job queue |
| `bot_replies` | Keyword → response mapping |
| `bot_settings` | Per-company chatbot settings |
| `ai_bot_settings` | AI bot configuration (OpenAI key, prompt) |
| `chatbot_flows` | Visual flow definitions |
| `chatbot_nodes` | Individual flow nodes |
| `chatbot_node_connections` | Flow node edges |
| `chatbot_sessions` | Per-user flow session state |
| `quick_replies` | Quick reply templates |
| `lead_automation_settings` | Lead automation trigger rules |

#### Subscriptions & Billing
| Table | Purpose |
|-------|---------|
| `subscription_plans` | Plan definitions (features, limits, price) |
| `subscriptions` | Active company subscriptions |
| `subscription_payments` | Payment history |
| `subscription_reminders` | Expiry reminder logs |
| `coupons` | Discount coupon codes |
| `referral_earnings` / `referral_logs` | Referral program data |

#### E-Commerce
| Table | Purpose |
|-------|---------|
| `products` | Product catalog |
| `ecommerce_orders` | Customer orders |
| `ecommerce_carts` | Shopping cart state |
| `ecommerce_settings` | E-commerce config per company |
| `whatsapp_checkout_sessions` | Checkout flow state |

#### Financial
| Table | Purpose |
|-------|---------|
| `gst_invoices` | GST invoice records |
| `gst_settings` | Company GST configuration |
| `quotations` | Sales quotations |
| `sales_log` | Sales tracker events |
| `sales_tracker_settings` | Sales tracker config |

#### Specialty Modules
| Table | Purpose |
|-------|---------|
| `school_students` | School module student records |
| `school_fees` | Fee records |
| `school_fee_settings` | School fee module config |
| `vehicles` | Vehicle reminder records |
| `medicine_reminders` | Medicine reminder schedules |
| `birthday_settings` | Birthday campaign config |
| `loyalty_settings` / `loyalty_logs` | Loyalty points config + history |
| `vcard_settings` | Digital vCard config |
| `api_keys` | Client API key store |
| `indiamart_leads` | IndiaMART lead sync |
| `system_settings` | Platform-wide KV settings |
| `system_logs` / `system_errors` | Error/event logging |
| `cron_logs` | Cron execution history |
| `pulse_runs` | Health pulse history |
| `zapier_webhooks` | Zapier webhook configs |
| `whatsapp_flows` | Native WA Flow definitions |
| `announcements` | Admin announcements |
| `support_tickets` / `support_messages` | Support system |
| `notification_settings` | User notification prefs |

---

## 5. Configuration & Bootstrap Flow

Every PHP page begins with this chain:

```
Request hits PHP page
    │
    ▼
require_once config/bootstrap.php
    │
    ├── error_reporting(0), display_errors=0
    ├── mkdir storage/logs, storage/sessions (if missing)
    ├── Env::load('.env')           ← parse .env file into $_ENV
    ├── date_default_timezone_set('Asia/Kolkata')
    ├── session_set_save_path('storage/sessions')
    ├── session_start() with:
    │     cookie_httponly=true, cookie_samesite=Lax
    │     cookie_secure=false (local), lifetime=3600
    ├── Security headers (X-Frame-Options, X-Content-Type, etc.)
    ├── PSR-4 autoloader: Waamini\ → root/
    ├── error_handler + exception_handler + shutdown_handler
    └── Remember-me cookie check → auto-login if token valid
    │
    ▼
require_once db.php
    │
    ├── Env::get('DB_HOST', '127.0.0.1')
    ├── mysqli persistent connection ("p:127.0.0.1")
    ├── 3-second timeout, charset utf8mb4
    └── Returns global $conn (mysqli)
    │
    ▼
require_once includes/auth_check.php (admin pages)
  OR includes/client_auth_check.php (client pages)
    │
    ├── Check $_SESSION['timed_out'] → destroy + redirect login
    ├── Check $_SESSION['user_id'] → redirect login if missing
    └── Check $_SESSION['role'] === 'super_admin' (admin only)
```

### Environment Variables (.env)

```
DB_HOST=127.0.0.1 | DB_PORT=3306 | DB_NAME=waamini | DB_USER=root | DB_PASS=
APP_ENV=local | APP_URL=https://ngrok-tunnel/waamini
ENCRYPTION_KEY=9fK7xQ2LmP8aR4TzV6bN1C5Yh3E7uW0s
WHATSAPP_PHONE_NUMBER_ID=1075754625621345
WHATSAPP_ACCESS_TOKEN=EAAd....(Meta token)
WHATSAPP_API_VERSION=v20.0
FB_APP_SECRET=69e6bfef1a8363f7f0d9bffb5fb16fe4
WHATSAPP_VERIFY_TOKEN=waamini_f1199abf7f55f0d0
RAZORPAY_KEY_ID=... | RAZORPAY_KEY_SECRET=...
OPENAI_API_KEY=...
SESSION_LIFETIME=3600
RATE_LIMIT_MAX=60 | RATE_LIMIT_WINDOW=60
```

---

## 6. Authentication & Authorization Flow

### Login Flow (login.php → includes/auth.php)

```
User submits email + password
    │
    ▼
[Security checks]
    ├── CSRF token verification (POST only)
    ├── Rate limit check: 5 attempts / 5 minutes per IP
    └── IP lockout check (ip_lockouts table)
    │
    ▼
[Database lookup]
    SELECT * FROM users WHERE email=? (prepared statement)
    │
    ├── User not found → "Invalid credentials" (no enumeration)
    ├── Status = 'disabled' → "Account disabled"
    └── User found → continue
    │
    ▼
password_verify($input, $stored_hash)
    │
    ├── Fail → increment failed_attempts, check lockout threshold
    │         → lock account 15 min after 5 failures
    └── Pass → continue
    │
    ▼
[2FA Check]
    ├── two_factor_enabled = 1 → store pending_user_id in session
    │                          → redirect verify_2fa.php
    └── No 2FA → continue
    │
    ▼
[Session creation]
    session_regenerate_id(true)
    $_SESSION = [
        'user_id'    => $user['id'],
        'role'       => $user['role'],    // 'super_admin' or 'client'
        'company_id' => $user['company_id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
    ]
    │
    ▼
[Remember Me]
    if checked → generate random 32-byte token
               → store hash in user_tokens (30-day expiry)
               → set cookie 'remember_token' (30 days)
    │
    ▼
[Role-based redirect]
    super_admin → /waamini/admin/dashboard.php
    client      → /waamini/client/dashboard.php
```

### 2FA Flow (verify_2fa.php)

```
User submits 6-digit OTP
    │
    ├── Verify against GoogleAuthenticator TOTP (30-second window)
    ├── Pass → complete session + redirect dashboard
    └── Fail → error, re-prompt
```

### Authorization Guards

**Admin pages** (includes/auth_check.php):
- Must be logged in (`$_SESSION['user_id']` set)
- Must have `role = 'super_admin'`
- Session timeout check

**Client pages** (includes/client_auth_check.php):
- Must be logged in
- Any authenticated role (admin can impersonate client)
- Company context loaded from `$_SESSION['company_id']`

### Impersonation Flow (admin/impersonate.php)

```
Admin selects client → stores original admin session
→ injects client session data
→ client panel opens with client's company context
→ admin/stop_impersonate.php → restores admin session
```

---

## 7. WhatsApp Integration & Webhook Flow

### Setup

1. Client configures WhatsApp Business API in `client/settings_whatsapp.php`
2. Credentials stored (encrypted) in `whatsapp_config` table
3. Webhook URL set on Meta: `https://yourdomain.com/waamini/webhook.php?company_id=X`

### Webhook Verification (GET)

```
Meta sends GET with hub_mode, hub_verify_token, hub_challenge
    │
    ▼
WebhookHandler::verify()
    ├── Look up verify_token from whatsapp_config for company_id
    │   (falls back to ENV WHATSAPP_VERIFY_TOKEN)
    ├── hash_equals($token, $storedToken)
    └── Match → echo $challenge (HTTP 200)
        No match → HTTP 403
```

### Incoming Message Flow (POST)

```
Meta sends POST with JSON payload (message/status event)
    │
    ▼
[Security]
    ├── Validate X-Hub-Signature-256 HMAC (production only)
    │   sha256_hmac($payload, $FB_APP_SECRET)
    └── Fail → HTTP 403
    │
    ▼
WebhookHandler::handleEvent()
    │
    ▼
processEvent($data)
    ├── Iterate data['entry'] → data['changes']
    ├── If 'messages' → processMessage()
    └── If 'statuses' → processStatus()
    │
    ▼
processMessage()
    │
    ├── [Idempotency] Check static $processedIds (request scope)
    │   + DB check: SELECT from messages WHERE whatsapp_message_id=?
    │   Duplicate → skip
    │
    ├── [Tenant Resolution] resolveCompanyId()
    │   Priority 1: ?company_id= in webhook URL
    │   Priority 2: Contact phone → whatsapp_config cross-check
    │   Priority 3: phone_number_id → whatsapp_config fallback
    │
    ├── [Contact Upsert] ensureContactExists()
    │   SELECT contact by phone (±"+" prefix both checked)
    │   Not found → INSERT contact (source='whatsapp', status='active')
    │   Found → UPDATE last_message_at=NOW()
    │
    ├── [Message Storage] INSERT INTO messages
    │   Schema-aware: detects content/message column presence
    │   direction='incoming', status='received'
    │
    ├── [Inbox Update] UPSERT chat_conversations
    │   Updates last_message, last_message_at, unread_count++
    │
    ├── [Content Extraction by type]
    │   text → body
    │   image/video/document → caption + media_id
    │   audio → media_id only
    │   interactive/button_reply → JSON with type+id+title
    │   interactive/nfm_reply → WhatsApp Flow form submission
    │   → appendContactFlowNotes() → saves to contacts.notes
    │
    ├── [Sales Tracker] SalesTracker::process()
    │   Google Sheet sync + sales reporting bot
    │
    ├── [Catalog Checkout] waamini_catalog_checkout_handle()
    │   Product selection → quantity → UPI payment link flow
    │
    └── [Smart Router] SmartRouter::route()  ← see Section 8
    │
    ▼
processStatus()
    ├── UPDATE messages SET status=? WHERE whatsapp_message_id=?
    └── Campaign delivery tracking:
        delivered → campaigns.delivered_count++
        read      → campaigns.read_count++
        failed    → campaign_queue.status='failed',
                    campaigns.failed_count++
    │
    ▼
Always return HTTP 200 to Meta (prevent retries)
```

### Outgoing Message Flow (WhatsApp API Class)

```
WhatsAppAPI($companyId)
    ├── Load credentials from whatsapp_config WHERE company_id=?
    ├── Decrypt access_token via EncryptionService
    └── POST https://graph.facebook.com/v20.0/{phone_number_id}/messages

Methods:
    sendTextMessage(to, message)
    sendTemplateMessage(to, templateName, lang, components)
    sendImageMessage(to, imageUrl, caption)
    sendDocumentMessage(to, docUrl, filename)
    sendInteractiveButtons(to, body, buttons)
    sendInteractiveList(to, body, sections)
    markAsRead(messageId)
```

---

## 8. Smart Router — 3-Tier Message Routing

**File:** `includes/smart_router.php`  
**Triggered by:** Every incoming message that is not a catalog checkout or NFM reply

```
SmartRouter::route($message, $value, $companyId, $contactId)
    │
    ▼
PRIORITY 1: Bot Replies (Exact Keyword Match)
    SELECT keyword, response FROM bot_replies
    WHERE company_id=? AND is_active=1
    ├── Case-insensitive match of incoming text vs keyword
    ├── Match found → sendTextMessage(from, response)
    │               → log to messages (outgoing)
    │               → return {handled: true, routed_to: 'bot_reply'}
    └── No match → try next tier
    │
    ▼
PRIORITY 2: Flow Builder (Chatbot Engine)
    ChatbotEngine::processIncomingScoped($message, $value, $companyId, $contactId)
    ├── Load chatbot_flows WHERE company_id=? AND is_active=1
    ├── Match trigger keyword
    ├── Execute flow: traverse chatbot_nodes + chatbot_node_connections
    │   Node types: message, condition, wait, action
    ├── Maintain session state in chatbot_sessions
    ├── Match found → execute flow, send responses
    │               → return {handled: true, routed_to: 'flow_builder'}
    └── No match → try next tier
    │
    ▼
PRIORITY 3: AI Sales Bot (GPT Fallback)
    SELECT * FROM ai_bot_settings WHERE company_id=? AND is_active=1
    ├── Has OpenAI API key configured?
    │   Yes → INSERT automation_queue (type='ai_reply', status='pending')
    │         → return {handled: true, routed_to: 'ai_sales_bot'}
    └── No AI configured → {handled: false, routed_to: 'none'}
    │
    ▼
[After routing — always runs]
Drip Engine: trigger_drip_campaigns($conn, $companyId, $contactId)
    ← Schedules future follow-up messages
Mark message as read: WhatsAppAPI::markAsRead($messageId)
```

---

## 9. Admin Panel — Full Feature Map

**URL:** `/waamini/admin/`  
**Access:** super_admin role only

### Dashboard (admin/dashboard.php)
- Total companies, users, active/suspended companies
- Total campaigns, WhatsApp configs
- 10-second file cache for expensive queries
- Platform-wide stats display

### Company Management
- `companies.php` — List all tenant companies with status, plan, expiry
- `company_add.php` — Create company + auto-create company_id scope
- `company_edit.php` — Edit company name, status, plan assignment

### User Management
- `users.php` — All users across all companies
- `user_add.php` — Create user with role + company assignment
- `user_edit.php` — Edit user details, reset password, change role

### Subscription & Plans
- `plans.php` — Subscription plan list (free/basic/premium tiers)
- `plan_edit.php` — Edit plan: price, message limit, feature flags
- `subscriptions.php` — All active subscriptions
- `subscription_add.php` — Manually assign subscription to company
- `subscription_view.php` — View subscription detail + payment history

### Campaign Management
- `campaigns.php` — All campaigns across all companies
- `campaign_create.php` — Create broadcast campaign
- `campaign_view.php` — Delivery analytics (sent/delivered/read/failed)
- `broadcast.php` — Quick broadcast panel

### Analytics & Revenue
- `analytics.php` — Platform analytics
- `revenue.php` — Revenue dashboard (subscription payments)
- `api_usage.php` — API call statistics

### System Health
- `server_health.php` — CPU, RAM, disk usage
- `queue_health.php` — Queue backlog stats
- `system_health.php` — Combined health view
- `pulse.php` — Real-time system pulse

### Content Management
- `templates.php` — Approved WhatsApp message templates
- `email_templates.php` — Email template editor
- `announcements.php` — Platform announcements
- `coupons.php` — Discount coupon management

### Settings
- `settings_general.php` — Platform settings (name, logo, etc.)
- `settings_payment.php` — Razorpay gateway configuration
- `settings_smtp.php` — SMTP email configuration
- `settings_whatsapp.php` — Default WhatsApp credentials

### Security & Audit
- `audit_log.php` — Full security audit trail
- `impersonate.php` — Login as any client (for support)
- `support_inbox.php` — All client support tickets

---

## 10. Client Panel — Full Feature Map

**URL:** `/waamini/client/`  
**Access:** Authenticated users with any role

### Dashboard (client/dashboard.php)
- Contact count, campaign count, template count
- Monthly message usage vs. plan limit (percentage bar)
- Subscription expiry warning
- Recent message history (last 5)
- 60-second file cache (company-scoped namespace)

### Contact Management
- `contacts.php` — Full contact list with search/filter
- `contact_add.php` — Add single contact with custom fields
- `contact_edit.php` — Edit contact, add notes, set tags
- `contact_import.php` — Bulk CSV import with field mapping
- `contact_groups.php` — Create and manage contact groups
- `contact_bulk.php` — Bulk tag/delete/move operations
- `blacklist.php` — View and manage opted-out numbers

### Campaign System
- `campaigns.php` — Campaign list with status
- `campaign_create.php` — Campaign builder:
  - Select template or compose message
  - Choose contact group or segment
  - Schedule or send immediately
  - Attach media (image/document)
- `campaign_view.php` — Per-campaign analytics report
- `campaign_reports.php` — Cross-campaign analytics
- `quick_broadcast.php` — Send to individuals quickly
- `scheduled_messages.php` — Future-scheduled message queue
- `message_log.php` — All outbound message history

### Template Management
- `templates.php` — Message template list
- `template_create.php` — Create and submit template for Meta approval

### Live Chat & Inbox
- `live_chat.php` — Real-time chat inbox
  - Shows chat_conversations sorted by last_message_at
  - AJAX polling for new messages (api/get_chat_messages.php)
  - Agent can reply directly, assign/close conversations
  - Unread count badges

### Chatbot Builder
- `chatbot_simple_builder.php` — Keyword bot (bot_replies table)
  - Add keyword → auto-response pairs
  - Case-insensitive matching
- `chatbot_builder.php` — Visual flow builder
  - Drag-and-drop node canvas
  - Node types: send message, condition, API call, delay, end
  - Triggers: keyword, first message, button click
- `chatbot_flows.php` — Flow list + enable/disable
- `bot_replies.php` — Keyword reply management
- `quick_replies.php` — Agent quick reply shortcuts

### Automation
- `automations.php` — Automation rules list
- `lead_automation.php` — Lead-based automation triggers
- `drip_campaigns.php` — Drip campaign manager

### AI Features
- `ai_campaign_writer.php` — AI generates campaign message content
- `ai_lead_scores.php` — AI lead scoring dashboard

### Analytics
- `analytics_agents.php` — Team member performance
- `analytics_revenue.php` — Revenue tracking
- `pulse.php` / `wa_health.php` — WhatsApp API health status

### Specialty Modules
- **School Fees:** `school_fees.php`, `school_fee_settings.php`
  - Student records, fee dues, automatic WhatsApp reminders
- **Medicine Reminders:** `medicine_reminders.php`
  - Schedule medication reminders via WhatsApp
- **Vehicle Reminders:** `vehicle_reminders.php`
  - Insurance/service/RC renewal reminders
- **Birthday Campaigns:** `settings_birthday.php`
  - Auto-send birthday wishes at midnight
- **Festival Campaigns:** `festival_campaigns.php`, `festival_calendar.php`
  - Auto-send festival greetings on Indian holidays
- **Digital vCard:** `digital_vcard.php`, `vcard_settings.php`
  - WhatsApp-shareable digital business card

### E-Commerce
- `catalog.php` — Product catalog management
- `orders.php` — Order management
- `order_dashboard.php` — Order analytics
- `ecommerce_settings.php` — Store configuration

### GST & Invoicing
- `gst_invoices.php` — Invoice list
- `gst_invoice_create.php` — Create GST-compliant invoice
- `gst_invoice_view.php` — Print/send invoice via WhatsApp
- `gst_settings.php` — Company GST number + billing info

### Settings Pages
All settings in `client/settings_*.php`:
- WhatsApp API credentials
- Bot behavior and AI prompt
- Payment gateway (Razorpay/UPI)
- SMTP email
- Google Sheets sync
- Zapier webhook
- IndiaMART lead sync
- Facebook Leads webhook
- Review request bot
- Missed call trigger
- Cart recovery
- Upsell bot
- Spam protection
- PDF invoice branding
- Sales tracker

### Integrations Hub (client/integrations.php)
Central hub linking to all third-party integrations:
Zapier, Google Sheets, Facebook Leads, IndiaMART, Razorpay, UPI

### Billing (client/billing.php)
- View current plan + expiry
- Upgrade plan
- Payment history
- Referral link + earnings

### Team & Security
- `team_members.php` — Add/manage sub-users
- `security.php` — Enable/disable 2FA, change password
- `profile.php` — Profile settings

### Support
- `support.php` — Open ticket, view status, message thread

---

## 11. API Endpoints

**Base path:** `/waamini/api/`  
All API responses return JSON.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `auth.php` | POST | API key authentication |
| `send_message.php` | POST | Send WhatsApp message programmatically |
| `campaigns.php` | GET/POST | Campaign list + create |
| `campaign_tick.php` | POST | Process next campaign batch |
| `contact.php` | GET/POST/PUT/DELETE | Contact CRUD |
| `contact_export.php` | GET | Export contacts as CSV |
| `contact_notes_add.php` | POST | Add note to contact |
| `contact_notes_list.php` | GET | Get contact notes |
| `contact_reminders_add.php` | POST | Add contact reminder |
| `contact_reminders_list.php` | GET | List reminders |
| `contact_reminders_done.php` | POST | Mark reminder done |
| `save_contact.php` | POST | Public form → contact save |
| `chatbot.php` | POST | Chatbot interaction API |
| `drip_create.php` | POST | Create drip campaign |
| `drip_step_add.php` | POST | Add drip step |
| `drip_step_delete.php` | POST | Delete drip step |
| `drip_tick.php` | POST | Process drip queue |
| `drip_toggle.php` | POST | Enable/disable drip |
| `ai_campaign_writer.php` | POST | AI-generate campaign content |
| `ai_compose.php` | POST | AI compose message |
| `ai_smart_reply.php` | POST | AI suggest reply |
| `analytics_whatsapp.php` | GET | WA analytics data |
| `payment_success.php` | POST | Razorpay payment success callback |
| `payment_webhook.php` | POST | Razorpay webhook |
| `send_payment_link.php` | POST | Send UPI/Razorpay link via WA |
| `send_invoice.php` | POST | Send GST invoice via WA |
| `send_gst_invoice_wa.php` | POST | Send GST invoice (alt endpoint) |
| `missed_call_trigger.php` | POST | Missed call → WA message |
| `missed_call_webhook.php` | POST | Exotel missed call webhook |
| `fb_leads_webhook.php` | POST | Facebook Lead Ads webhook |
| `fb_webhook.php` | POST | General FB webhook |
| `zapier_catch.php` | POST | Zapier inbound webhook |
| `cart_event.php` | POST | E-commerce cart event |
| `ecommerce_catch.php` | POST | E-commerce event capture |
| `ocr_process.php` | POST | OCR image processing |
| `ocr_save.php` | POST | Save OCR result |
| `pincode_count.php` | GET | Pincode-based contact count |
| `process_automation_queue.php` | POST | Run automation queue |
| `get_chat_messages.php` | GET | Poll chat messages (AJAX) |
| `get_sidebar_updates.php` | GET | Sidebar notification updates |
| `debug_token.php` | GET | Debug WA token (dev only) |
| `health.php` | GET | API health check |
| `health_queue.php` | GET | Queue health check |
| `view_quote.php` | GET | Public quotation viewer |
| `settings.php` | GET/POST | Company settings API |
| `cron.php` | POST | Cron trigger (secret key protected) |
| `cron_drip.php` | POST | Drip cron trigger |

---

## 12. Automation Engine & Cron Jobs

All cron files live in the root directory and are called by server cron or via `api/cron.php` (protected by `CRON_SECRET_KEY`).

| Cron File | Frequency | Function |
|-----------|-----------|---------|
| `cron_campaign_sender.php` | Every 1 min | Process campaign_queue, send batches |
| `cron_automation_sender.php` | Every 1 min | Process automation_queue (AI replies, triggers) |
| `cron_drip_sender.php` (via api) | Every 5 min | Execute due drip_executions |
| `cron_birthday_sender.php` | Daily midnight | Send birthday wishes to contacts |
| `cron_festival_sender.php` | Daily | Send festival greetings |
| `cron_festival.php` | Daily | Update festival calendar |
| `cron_subscription_reminder.php` | Daily | Notify expiring subscriptions |
| `cron_subscription_alerts.php` | Daily | Alert admin on subscription status |
| `cron_trial_nudge.php` | Daily | Nudge trial users to upgrade |
| `cron_vehicle_reminder.php` | Daily | Send vehicle service/insurance reminders |
| `cron_medicine_reminder.php` | Daily | Send medicine schedule reminders |
| `cron_fee_reminder.php` | Daily | School fee due reminders |
| `cron_school_fee_reminder.php` | Weekly | School fee bulk reminders |
| `cron_review_request.php` | Daily | Post-purchase review request |
| `cron_cart_recovery.php` | Hourly | Abandoned cart recovery messages |
| `cron_indiamart_sync.php` | Every 30 min | Sync IndiaMART leads |
| `cron_eod_sheets.php` | Daily EOD | Sync daily data to Google Sheets |
| `cron_winback.php` | Weekly | Re-engage inactive contacts |
| `cron_pulse_analyzer.php` | Every 10 min | Health pulse check + log |

### Campaign Send Flow (cron_campaign_sender.php)

```
SELECT pending rows FROM campaign_queue
WHERE status='pending' AND scheduled_at <= NOW()
LIMIT batch_size (e.g. 50)
    │
    For each row:
    ├── Load campaign + company WhatsApp config
    ├── Build message payload (template or text)
    ├── WhatsAppAPI::sendTemplateMessage() or sendTextMessage()
    ├── Store returned whatsapp_message_id in campaign_queue
    ├── UPDATE campaign_queue SET status='sent'
    ├── UPDATE campaigns SET sent_count++
    └── Sleep (rate limit: ~1 msg/sec per phone number)
```

---

## 13. Campaign System Flow

### Creating a Campaign (client/campaign_create.php)

```
1. Client fills form:
   - Campaign name
   - Select template (from message_templates) OR compose text
   - Select recipients: contact group OR all contacts OR segment
   - Attach media (optional): image/document URL
   - Schedule: now OR future datetime

2. On submit (POST):
   - Validate template approval status
   - Check message quota (monthly_sent < plan_limit)
   - INSERT into campaigns table
   - INSERT batch into campaign_queue:
     For each contact in selected group:
       INSERT campaign_queue (campaign_id, company_id, contact_id,
                              status='pending', scheduled_at, message)

3. Cron picks up pending queue rows → sends → updates delivery status
```

### Campaign Analytics

```
campaigns table tracks:
    total_count    — total recipients enqueued
    sent_count     — successfully sent to WA
    delivered_count— confirmed delivered (from webhook status)
    read_count     — confirmed read (from webhook status)
    failed_count   — delivery failures
    expired_count  — template expired sends

campaign_view.php shows:
    Delivery rate = delivered / sent × 100%
    Read rate     = read / delivered × 100%
    Failure rate  = failed / total × 100%
    Timeline chart of send progress
```

---

## 14. AI Features

### AI Campaign Writer (client/ai_campaign_writer.php + api/ai_campaign_writer.php)

```
Client enters: product name, tone, language, target audience
    │
    ▼
POST api/ai_campaign_writer.php
    ├── Check AI usage quota (ai_usage_daily table)
    ├── Build GPT prompt with context
    ├── POST OpenAI /v1/chat/completions
    └── Return generated message variants
```

### AI Smart Reply (api/ai_smart_reply.php)

```
Agent views chat → requests AI reply suggestion
    ├── Last N messages as context
    ├── Company-specific system prompt (from ai_bot_settings)
    ├── GPT response → suggested reply shown in live chat UI
    └── Agent can edit + send or dismiss
```

### AI Sales Bot (automation_queue → api/process_automation_queue.php)

```
Incoming message → Smart Router (Priority 3)
    ├── INSERT automation_queue (type='ai_reply', status='pending')
    │
    ▼
Cron/api/process_automation_queue.php runs:
    ├── SELECT pending ai_reply tasks
    ├── Load ai_bot_settings (openai_api_key, system_prompt, tone)
    ├── Load recent message history for context
    ├── POST OpenAI with system prompt + conversation history
    ├── WhatsAppAPI::sendTextMessage(contact_phone, ai_response)
    ├── Log reply to messages table
    └── UPDATE automation_queue SET status='completed'
```

### AI Lead Scorer (includes/ai_lead_scorer.php)

```
Background scoring job:
    ├── Analyzes contact's message history, response rate, engagement
    ├── Sends to OpenAI for scoring (0-100)
    ├── Stores in lead_scores table
    └── Shown in client/ai_lead_scores.php
```

---

## 15. E-Commerce & Payment Flow

### Catalog Checkout (WhatsApp-native)

```
Customer sends product keyword to WhatsApp number
    │
    ▼
webhook.php → waamini_catalog_checkout_handle()
    ├── Match keyword to products table
    ├── Send product details + "Reply with quantity"
    │
Customer replies with quantity
    ├── Calculate total
    ├── Send payment options (UPI/Razorpay)
    │
Customer chooses UPI
    ├── Generate UPI deep link (upi://pay?pa=...)
    ├── Send as WhatsApp message
    ├── INSERT whatsapp_checkout_sessions (status='pending')
    │
Payment confirmed (api/payment_success.php or ajax_mark_upi_paid.php)
    ├── UPDATE ecommerce_orders SET status='paid'
    ├── Send order confirmation WhatsApp message
    └── Trigger order tracking bot
```

### Razorpay Payment Flow

```
api/send_payment_link.php:
    ├── Create Razorpay order via API
    ├── Generate payment link
    └── Send link via WhatsApp

api/payment_webhook.php (Razorpay webhook):
    ├── Verify HMAC signature
    ├── UPDATE order status to 'paid'
    └── Trigger post-payment automation
```

### GST Invoice Flow

```
client/gst_invoice_create.php:
    ├── Fill customer details, line items, GST %
    ├── INSERT gst_invoices with computed totals
    └── Option: Send via WhatsApp → api/send_gst_invoice_wa.php
        ├── Render invoice to HTML
        ├── Convert to PDF (if wkhtmltopdf/DomPDF)
        └── Send PDF as WhatsApp document message
```

---

## 16. Drip Campaign Engine

**Files:** `includes/drip_engine.php`, `client/drip_campaigns.php`

```
Setup (client creates drip):
    1. INSERT drip_campaigns (name, trigger_keyword, company_id)
    2. INSERT drip_steps (campaign_id, step_order, delay_hours, message)
       e.g. Step 1: 0h delay (immediate), Step 2: 24h, Step 3: 72h

Trigger (on every incoming message):
    webhook.php → trigger_drip_campaigns($conn, $companyId, $contactId)
        ├── Find matching drip by keyword
        ├── Check: contact not already in this drip
        └── INSERT drip_executions (campaign_id, contact_id, step=0, next_send_at=NOW())

Execution (cron_drip_sender.php / api/drip_tick.php):
    SELECT due rows FROM drip_executions
    WHERE next_send_at <= NOW() AND status='pending'
        ├── Send step message via WhatsApp
        ├── Log to messages table
        ├── If more steps: UPDATE next_send_at = NOW() + next_step.delay_hours
        └── If last step: UPDATE drip_executions SET status='completed'
```

---

## 17. Security Architecture

### Authentication Security
- Passwords hashed with `password_hash()` (bcrypt, cost 10+)
- Automatic rehash on login if cost factor outdated
- Account lockout: 15 minutes after 5 failed attempts
- Rate limiting: 5 login attempts per IP per 5 minutes
- Remember-me tokens: random 32 bytes, SHA-256 stored, 30-day expiry
- Session regeneration on login (`session_regenerate_id(true)`)
- Sessions stored in `storage/sessions/` (not system /tmp)

### Request Security
- CSRF tokens on all POST forms (synchronizer token pattern)
- Nonce-based Content Security Policy
- Input sanitization via `htmlspecialchars()` throughout
- Prepared statements (mysqli + PDO) preventing SQL injection
- File upload validation (type + size checks)

### HTTP Security Headers
```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{random}'
Referrer-Policy: strict-origin-when-cross-origin
```

### API Security
- Webhook HMAC signature validation (X-Hub-Signature-256)
- Cron endpoints protected by CRON_SECRET_KEY
- API endpoints use session OR API key auth
- Rate limiting on all public endpoints
- Audit logging on sensitive actions (audit_log table)

### Data Security
- WhatsApp access tokens encrypted at rest (EncryptionService.php, AES)
- DB credentials via .env (never hardcoded)
- `.env` and `db_config.php` blocked by .htaccess
- Impersonation logged to audit_log

---

## 18. External Integrations

| Service | Integration Point | Purpose |
|---------|------------------|---------|
| **Meta WhatsApp Cloud API** | webhook.php + WhatsAppAPI class | Core messaging |
| **OpenAI GPT** | api/ai_*.php, automation_queue | AI chatbot, campaign writer, smart reply |
| **Razorpay** | api/payment_webhook.php | Payment processing |
| **UPI** | pay_upi.php, api/send_payment_link.php | Indian UPI payments |
| **Google Sheets** | cron_eod_sheets.php, settings_gsheets.php | CRM data export/sync |
| **Facebook Lead Ads** | api/fb_leads_webhook.php | Lead capture from FB ads |
| **Zapier** | api/zapier_catch.php, settings_zapier.php | Workflow automation |
| **IndiaMART** | cron_indiamart_sync.php, settings_indiamart.php | B2B lead sync |
| **Exotel (Missed Call)** | api/missed_call_webhook.php | Missed call → WA trigger |
| **PHPMailer + Gmail SMTP** | vendor/PHPMailer, mail_settings.php | Transactional email |
| **Google Authenticator** | includes/GoogleAuthenticator.php | TOTP-based 2FA |
| **ngrok** | .env APP_URL | Local webhook tunnel (dev) |
| **Playwright** | tests/ | End-to-end test automation |

---

## 19. Multi-Tenancy Model

```
Platform (Super Admin)
    │
    ├── companies table (each = one business)
    │     company_id: primary tenant key
    │
    ├── users table → role='super_admin' (platform admin)
    │                 role='client' (company user)
    │                 company_id FK → companies
    │
    └── ALL data tables have company_id column
          contacts.company_id
          campaigns.company_id
          messages.company_id
          whatsapp_config.company_id
          drip_campaigns.company_id
          ... (every tenant-scoped table)

Data Isolation:
    Every client query is scoped: WHERE company_id = $_SESSION['company_id']
    No cross-tenant data access possible via UI

WhatsApp Isolation:
    Each company has its own whatsapp_config row
    Webhook URL includes ?company_id=X for routing
    Fallback: phone_number_id → company lookup
```

---

## 20. Full End-to-End Workflows

### Workflow A: New Client Onboarding

```
1. Super Admin logs in → admin/company_add.php
2. Creates company (name, plan assignment)
3. Creates user with role='client', company_id assigned
4. Client receives credentials (email)
5. Client logs in → redirected to client/dashboard.php
6. Client goes to client/settings_whatsapp.php
7. Enters Phone Number ID, Access Token, Verify Token
8. System saves to whatsapp_config (encrypted)
9. Client sets webhook URL on Meta: .../webhook.php?company_id=X
10. Meta sends GET verification → webhook.php::verify() → echoes challenge
11. WhatsApp is now active → client can send/receive messages
```

### Workflow B: Bulk Campaign Send

```
1. Client imports contacts via client/contact_import.php (CSV)
2. Contacts saved to contacts table (scoped to company_id)
3. Client creates group via client/contact_groups.php
4. Client goes to client/campaign_create.php
5. Selects approved template, selects group, schedules time
6. System validates message quota (plan limit check)
7. INSERTs campaign + campaign_queue rows (one per contact)
8. cron_campaign_sender.php runs every minute
9. Picks up pending queue rows in batches
10. Calls WhatsAppAPI::sendTemplateMessage() for each
11. Meta returns message_id → stored in campaign_queue
12. Meta sends delivery webhook → webhook.php::processStatus()
13. delivered_count / read_count updated in campaigns table
14. Client views report on client/campaign_view.php
```

### Workflow C: Inbound Message → AI Bot Reply

```
1. Customer sends WhatsApp message to business number
2. Meta POSTs webhook to .../webhook.php?company_id=5
3. HMAC signature validated
4. companyId=5 resolved from URL parameter
5. Contact looked up / created in contacts table
6. Message stored in messages table (direction='incoming')
7. chat_conversations updated (unread_count++)
8. Smart Router invoked:
   → Priority 1: Keyword bot check → no match
   → Priority 2: Flow builder check → no match
   → Priority 3: AI bot check → active, has OpenAI key
9. INSERT automation_queue (type='ai_reply', status='pending')
10. Drip engine runs: trigger_drip_campaigns() → schedules drip
11. Message marked as read on WhatsApp
12. api/process_automation_queue.php runs:
    → Loads ai_bot_settings (system prompt, API key)
    → Loads last 10 messages as context
    → POST to OpenAI GPT
    → Receives AI reply
    → WhatsAppAPI::sendTextMessage(customer, reply)
    → Logs outgoing reply to messages table
13. Customer receives AI reply on WhatsApp
```

### Workflow D: Sales Funnel via Drip

```
1. Customer texts "interested" to WA number
2. Smart Router matches: keyword bot → sends intro message
3. Drip Engine triggered: matches "interested" keyword
4. Enrolls contact in drip campaign:
   - Step 1 (0h): Immediate product brochure
   - Step 2 (24h): "Any questions? We're here."
   - Step 3 (72h): "Special offer ends soon!"
5. Each step sent automatically by cron_drip_sender.php
6. AI Lead Scorer runs overnight → scores contact (e.g. 78/100)
7. Score visible to agent in client/ai_lead_scores.php
8. Agent opens client/live_chat.php, selects high-score contact
9. Agent uses AI Smart Reply → AI suggests response
10. Agent edits + sends → logged + delivered
```

### Workflow E: UPI Payment via WhatsApp Catalog

```
1. Customer sends "buy shoes" to WA number
2. Catalog checkout handler matches keyword
3. Sends product details + price
4. Customer replies "2" (quantity)
5. System calculates total ₹1,998
6. Sends UPI payment link: upi://pay?pa=merchant@bank&am=1998
7. Customer pays via UPI app
8. Payment confirmed via Razorpay webhook or manual confirmation
9. INSERT ecommerce_orders (status='paid')
10. Send order confirmation + tracking ID via WhatsApp
11. Order tracking bot activated for future status queries
```

---

## Quick Reference

| URL | Purpose |
|-----|---------|
| http://localhost:8888/waamini/ | Public homepage |
| http://localhost:8888/waamini/login.php | Login (admin + client) |
| http://localhost:8888/waamini/admin/dashboard.php | Admin panel |
| http://localhost:8888/waamini/client/dashboard.php | Client panel |
| http://localhost:8888/waamini/webhook.php | WhatsApp webhook |
| http://localhost:8888/phpmyadmin | Database admin |

| Credential | Value |
|-----------|-------|
| Super Admin email | info@7digit.in |
| Super Admin email (alt) | admin@waamini.com |
| Client email | team7digit@gmail.com |
| DB name | waamini |
| DB user | root |
| DB pass | (empty) |
| Apache port | 8888 |
| MySQL port | 3306 |

---

*Generated: 2026-06-26 | Project: WAAMINI v2.1.0*
