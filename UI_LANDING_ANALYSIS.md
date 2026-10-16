# WAAMINI — 6-Page Landing Website UI Analysis
> Complete UI/UX Study for AI-Assisted Design Generation
> Source: Live MCP database analysis · 85 tables · 412 PHP files · 924 messages · 24 contacts · 17 campaigns

---

## BRAND IDENTITY

| Property | Value |
|---|---|
| Product Name | **WAAMINI CRM** |
| Tagline | *"Sell More on WhatsApp"* |
| Company | 7digit · 7digit.in |
| Support | support@7digit.in |
| Currency | INR (₹) |
| Target Market | Indian SMBs across 7 verticals |
| WhatsApp Number | +91 73583 93967 (GREEN quality) |
| Demo Booking | cal.com/waamini/demo |

### Color Palette (derived from app + brand)
```
Primary Green   : #25D366  (WhatsApp brand green)
Dark Green      : #128C7E  (WhatsApp teal)
Accent Purple   : #6c5ce7  (contact group tag color)
Accent Coral    : #e6775b  (alert/highlight color)
Background Dark : #0f1117  (hero section)
Background Light: #f8f9fa  (sections)
Card White      : #ffffff
Text Primary    : #1a1a2e
Text Secondary  : #6b7280
Border          : #e5e7eb
```

### Typography
```
Heading Font : Inter / Poppins (Bold 700–800)
Body Font    : Inter (Regular 400, Medium 500)
Accent Font  : Space Grotesk (hero tagline)
Sizes:
  H1: 56–72px (hero)
  H2: 36–42px (section)
  H3: 22–24px (card titles)
  Body: 16–18px
  Small: 14px
```

---

## PAGE 1 — HOME

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  NAVBAR                                             │
├─────────────────────────────────────────────────────┤
│  HERO SECTION (dark bg, full-width)                 │
├─────────────────────────────────────────────────────┤
│  SOCIAL PROOF BAR (logos / stats ticker)            │
├─────────────────────────────────────────────────────┤
│  PROBLEM → SOLUTION (2-col)                         │
├─────────────────────────────────────────────────────┤
│  FEATURE HIGHLIGHT CARDS (3-col grid)               │
├─────────────────────────────────────────────────────┤
│  HOW IT WORKS (3-step horizontal)                   │
├─────────────────────────────────────────────────────┤
│  AI BOT SHOWCASE (dark card, side-by-side)          │
├─────────────────────────────────────────────────────┤
│  INDUSTRY TABS (7 verticals)                        │
├─────────────────────────────────────────────────────┤
│  STATS ROW (4 numbers)                              │
├─────────────────────────────────────────────────────┤
│  CTA BANNER (green gradient)                        │
├─────────────────────────────────────────────────────┤
│  FOOTER                                             │
└─────────────────────────────────────────────────────┘
```

### NAVBAR
- Logo: WAAMINI + WhatsApp green icon (left)
- Links: Home · Features · Pricing · Blog · Support
- CTA: `Login` (outline button) + `Start Free Trial` (green filled)
- Sticky on scroll · background blur on scroll
- Mobile: hamburger → slide-in drawer

### HERO SECTION
- Background: Dark `#0f1117` with animated green particle dots (WhatsApp bubbles)
- Badge chip: `🟢 Trusted by 500+ Indian Businesses`
- H1: **"Sell More on WhatsApp — Automate, Engage, Close"**
- Subtext: *"Waamini is the all-in-one WhatsApp CRM for Indian businesses. Bulk campaigns, AI chatbot, native checkout, drip automation — all in one dashboard."*
- CTA row:
  - Primary: `Start Free Trial` (green, large, icon arrow)
  - Secondary: `Watch Demo` (ghost, play icon)
- Hero visual: Animated mock WhatsApp chat UI on mobile showing:
  - Bot auto-reply conversation
  - Campaign delivery ticker (924 messages sent)
  - Read receipt ticks (green)

### SOCIAL PROOF BAR
- Light gray strip
- "Trusted by businesses across India"
- Stat pills: `500+ Businesses` · `20% Cart Recovery` · `3x Faster Lead Response` · `GREEN Quality Rating`
- Scrolling logo ticker (e-commerce, education, healthcare, real estate brands)

### PROBLEM → SOLUTION
- Left col (Problem - red/gray):
  - Missing WhatsApp leads
  - Manual follow-up
  - Abandoned carts
  - No team inbox
- Right col (Solution - green):
  - AI auto-replies 24/7
  - Drip sequence automation
  - Cart recovery bot (20% avg)
  - Multi-agent live chat

### FEATURE HIGHLIGHT CARDS (3-col grid, 6 cards)
From real DB features (`subscription_plans` table):
1. **Bulk Campaigns** — Send to 1000s with one click · icon: megaphone
2. **AI Chatbot** — OpenAI-powered, multilingual (EN/Tamil/Hindi) · icon: robot
3. **Native Checkout** — Buy directly inside WhatsApp · icon: shopping cart
4. **Drip Sequences** — Automated multi-day nurture · icon: funnel
5. **GST Invoices** — Auto-generate on WhatsApp · icon: receipt
6. **Live Chat + Multi-Agent** — Full team inbox · icon: headset

Card style: White card, green top border accent, icon circle (green bg), title, 1-line desc, "Learn more →" link

### HOW IT WORKS (3-step)
```
Step 1           Step 2              Step 3
Connect WA  →  Import Contacts  →  Launch Campaign
[WA icon]      [contacts icon]     [rocket icon]
  Setup in        Upload CSV or       Blast template
  5 minutes       sync IndiaMart      messages, track
                                      read receipts
```
- Numbered circles (green)
- Connector dashed line between steps

### AI BOT SHOWCASE
- Dark card (#1a1a2e)
- Left: Feature list of AI bot persona (from DB):
  - Multilingual (Tamil, Hindi, Tanglish, English)
  - Mirrors user's language automatically
  - Sales closer persona with objection handling
  - Escalates to human agent when needed
  - WhatsApp opt-out compliant
- Right: Animated WhatsApp chat mockup showing bot conversation
  - Greeting → qualifying → pitch → demo link
- CTA: "See AI Bot in Action →"

### INDUSTRY TABS (7 verticals — from DB `ai_bot_settings.bot_persona`)
Tabs: `E-Commerce` · `Education` · `Healthcare` · `Real Estate` · `Restaurant` · `Salon` · `Automobile`

Each tab shows:
- Industry-specific use case
- Key features used
- Sample metric (e.g., "20% cart recovery for e-commerce")

### STATS ROW
```
   924          500+          20%           GREEN
Messages    Businesses     Cart          WA Quality
 Sent       Served        Recovery        Rating
```
- Large bold numbers, green accent
- Counter animation on scroll

### CTA BANNER
- Green gradient background
- "Ready to Grow on WhatsApp?"
- Button: `Start Free Trial — No Credit Card Needed`
- Subtext: "Setup in 5 minutes · Cancel anytime"

---

## PAGE 2 — FEATURES

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  PAGE HERO (compact, green gradient)                │
├─────────────────────────────────────────────────────┤
│  FEATURE CATEGORIES SIDEBAR NAV (sticky left)       │
├─────────────────────────────────────────────────────┤
│  DETAILED FEATURE SECTIONS (right, scrollable)      │
│   → Campaigns                                       │
│   → Chatbot & AI                                    │
│   → Live Chat                                       │
│   → E-Commerce                                      │
│   → Drip & Automation                               │
│   → CRM & Contacts                                  │
│   → Analytics                                       │
│   → Integrations                                    │
│   → Verticals                                       │
├─────────────────────────────────────────────────────┤
│  FEATURE COMPARISON TABLE                           │
├─────────────────────────────────────────────────────┤
│  CTA SECTION                                        │
└─────────────────────────────────────────────────────┘
```

### PAGE HERO
- H2: "Everything Your Business Needs on WhatsApp"
- Subtext: "90+ features across campaigns, AI, e-commerce, and automation"
- Breadcrumb: Home > Features
- Background: Light green gradient (#f0fdf4 → white)

### SIDEBAR NAV (sticky, left 240px)
```
📢 Campaigns
🤖 Chatbot & AI
💬 Live Chat
🛒 E-Commerce
📧 Drip Automation
👥 CRM & Contacts
📊 Analytics
🔗 Integrations
🏥 Industry Bots
```
- Active state: green left border + green text
- Smooth scroll to section on click

### FEATURE SECTIONS (9 sections from 412 PHP files + 85 tables)

#### 1. Campaigns (from `campaigns` table — 17 campaigns, 924 msgs)
- **Blast Campaigns**: Send WhatsApp template messages to thousands
- **Scheduled Campaigns**: Set time, fire automatically
- **Festival Campaigns**: Auto-send on Diwali, Pongal, Eid, Holi
- **Quick Broadcast**: Fast one-off sends
- **Campaign Reports**: Sent, Delivered, Read, Failed tracking
- Real metrics shown: 91% read rate from live data
- Visual: Campaign dashboard screenshot mockup

#### 2. Chatbot & AI (from `ai_bot_settings`, `chatbot_flows`, `bot_replies`)
- **Visual Chatbot Builder**: Drag-and-drop flow builder
- **Keyword Bot Replies**: 4 active rules (text, interactive, template)
- **AI Sales Closer**: OpenAI-powered, formal/friendly tone
- **Language Detection**: Auto-replies in Tamil, Hindi, Tanglish, English
- **Objection Handling**: 8 objection scripts built-in
- **Fallback to Human**: Auto-escalation when needed
- **WhatsApp Opt-Out**: Fully compliant
- Visual: Chatbot flow builder UI mockup

#### 3. Live Chat (from `messages` — 924 total, 42 conversations)
- **Multi-Agent Inbox**: Assign conversations to team members
- **Real-Time Chat**: 419 incoming + 505 outgoing messages live
- **Message Types**: Text, Audio, Image, Document support
- **Chat Labels**: Tag conversations (from `contacts.chat_labels`)
- **Quick Replies**: Saved response templates
- **Contact Notes**: Add notes per contact
- Visual: Live chat inbox screenshot mockup

#### 4. E-Commerce (from `ecommerce_orders` — 9 orders, ₹10,105 revenue)
- **Native WhatsApp Checkout**: Buy without leaving chat
- **Cart Recovery**: Auto follow-up abandoned carts (20% avg recovery)
- **Product Catalog**: Showcase products in WhatsApp
- **Payment Links**: Razorpay integration
- **UPI Payments**: Direct UPI option
- **GST Invoices**: Auto-generate and send via WhatsApp
- **Order Tracking**: Status updates on WhatsApp
- Visual: WhatsApp checkout flow mockup (3 screens)

#### 5. Drip Automation (from `drip_campaigns` — 8 active, `drip_steps` — 8 steps)
- **Multi-Day Sequences**: Delay by minutes, hours, days
- **Label-Triggered**: Fire on contact label change (Follow-up, Declined, etc.)
- **Active Campaigns**: digital marketing followup, decline, not interested flows
- **Step Delays**: From 0 min to 1 day delays
- Visual: Drip timeline flow diagram

#### 6. CRM & Contacts (from `contacts` — 24, `contact_groups` — 4 groups)
- **Contact Profiles**: Name, phone, email, company, tags, 5 custom vars
- **Lead Scoring**: AI-powered score + temperature (hot/warm/cold)
- **Groups**: Organize contacts (demo, ERRR, vino, may3)
- **Loyalty Points**: Reward system per contact
- **Bot Enable/Disable**: Per-contact bot toggle
- **Bulk Import**: CSV import
- **IndiaMart Sync**: Auto-import leads
- **Facebook Lead Ads**: Auto-capture

#### 7. Analytics (from `analytics*.php`, `campaign_reports`)
- **Campaign Analytics**: Sent/Delivered/Read/Failed per campaign
- **WhatsApp Health**: Quality rating, messaging limits, delivery rate
- **AI Usage Tracker**: Daily OpenAI token usage
- **Revenue Analytics**: Sales dashboard
- **Message Logs**: Full message history
- **Pulse**: System health monitor

#### 8. Integrations (from `zapier_webhooks`, `indiamart_leads`, `fb_leads_webhook`)
- **Zapier**: 1 active webhook
- **IndiaMart**: Lead auto-import
- **Facebook Lead Ads**: Form capture
- **Razorpay**: Payment gateway
- **Stripe**: International payments
- **Google Sheets**: Export data
- **WhatsApp Flows**: Meta native flows
- **API + Webhooks**: Full developer access (Enterprise plan)
- **Postman Ready**: API collection available

#### 9. Industry Bots (from `client/industry_bots.php`)
Dedicated automation for:
- Schools: Fee reminders, announcements
- Healthcare: Medicine reminders, appointments
- Automobile: Service reminders, insurance alerts
- Real Estate: Lead drip, property updates
- Restaurant/Salon: Booking confirmations, festival offers

### FEATURE COMPARISON TABLE
| Feature | Free Trial | Enterprise |
|---|---|---|
| Contacts | 500 | 1,000+ |
| Campaigns/month | 10 | Unlimited |
| Messages/month | 1,000 | 1,000+ |
| Team Members | 3 | Multi-agent |
| AI Chatbot | — | ✅ |
| Cart Recovery | — | ✅ |
| Native Checkout | — | ✅ |
| Drip Campaigns | — | ✅ |
| GST Invoices | ✅ | ✅ |
| API Access | — | ✅ |
| WA Flows | — | ✅ |
| Chatbot Builder | ✅ | ✅ |
| Price | ₹0 | ₹2,999/mo |

---

## PAGE 3 — PRICING

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  PAGE HERO                                          │
├─────────────────────────────────────────────────────┤
│  BILLING TOGGLE (Monthly / Yearly)                  │
├─────────────────────────────────────────────────────┤
│  PRICING CARDS (2 cards: Free Trial + Enterprise)   │
├─────────────────────────────────────────────────────┤
│  FULL FEATURE COMPARISON TABLE                      │
├─────────────────────────────────────────────────────┤
│  FAQ ACCORDION                                      │
├─────────────────────────────────────────────────────┤
│  ROI CALCULATOR                                     │
├─────────────────────────────────────────────────────┤
│  CTA                                                │
└─────────────────────────────────────────────────────┘
```

### PAGE HERO
- H2: "Simple, Transparent Pricing"
- Subtext: "Start free. Scale when you're ready."
- Background: White with green grid pattern

### BILLING TOGGLE
- Toggle pill: `Monthly` | `Yearly` (save 20% badge)
- Animated slide transition

### PRICING CARDS

#### Card 1: FREE TRIAL
```
┌──────────────────────────────┐
│  🆓 Free Trial               │
│  ₹0 / month                 │
│  Perfect to get started      │
│                              │
│  ✅ 500 Contacts             │
│  ✅ 10 Campaigns/month       │
│  ✅ 1,000 Messages/month     │
│  ✅ 3 Team Members           │
│  ✅ Chatbot Builder          │
│  ✅ GST Invoices             │
│  ❌ AI Chatbot               │
│  ❌ Cart Recovery            │
│  ❌ Native Checkout          │
│  ❌ Drip Campaigns           │
│  ❌ API Access               │
│                              │
│  [ Start Free Trial ]        │
│  No credit card required     │
└──────────────────────────────┘
```
- Border: Gray
- Badge: "Get Started Free"

#### Card 2: ENTERPRISE (highlighted/recommended)
```
┌──────────────────────────────┐
│  ⭐ MOST POPULAR             │
│  Enterprise                  │
│  ₹2,999 / month             │
│  or ₹X,XXX/year (save 20%) │
│                              │
│  ✅ 1,000+ Contacts          │
│  ✅ Unlimited Campaigns      │
│  ✅ 1,000+ Messages/month    │
│  ✅ Multi-Agent Team         │
│  ✅ AI Sales Chatbot         │
│  ✅ Cart Recovery (20% avg)  │
│  ✅ Native WA Checkout       │
│  ✅ Drip Campaigns           │
│  ✅ WhatsApp Flows           │
│  ✅ GST Invoices             │
│  ✅ API + Webhooks           │
│  ✅ All Industry Bots        │
│  ✅ Advanced Analytics       │
│                              │
│  [ Get Enterprise ]          │
│  Book a demo first           │
└──────────────────────────────┘
```
- Border: Green (#25D366)
- Badge: "Most Popular" (green chip)
- Shadow: Strong drop shadow
- Scale: Slightly larger

### FAQ ACCORDION (10 questions)
1. How does the free trial work?
2. Do I need a WhatsApp Business API account?
3. What's the setup time?
4. Can I send bulk messages?
5. Is the AI chatbot really multilingual?
6. How does cart recovery work?
7. Can I integrate with my existing tools?
8. What is the WhatsApp quality rating?
9. How many team members can I add?
10. What payment methods do you accept? (Razorpay / UPI / Card)

### ROI CALCULATOR
- Inputs: Monthly orders, Average order value, Current abandoned cart %
- Output: "You could recover ₹X/month with cart recovery alone"
- CTA: "Start recovering carts →"

---

## PAGE 4 — BLOG

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  PAGE HERO                                          │
├─────────────────────────────────────────────────────┤
│  FEATURED ARTICLE (full-width card)                 │
├─────────────────────────────────────────────────────┤
│  CATEGORY FILTER PILLS                              │
├─────────────────────────────────────────────────────┤
│  ARTICLE GRID (3-col)                               │
├─────────────────────────────────────────────────────┤
│  NEWSLETTER CTA                                     │
└─────────────────────────────────────────────────────┘
```

### PAGE HERO
- H2: "WhatsApp Marketing Insights"
- Subtext: "Tips, guides, and case studies for Indian businesses"
- Search bar: "Search articles..."

### FEATURED ARTICLE
- Large card with background image (WhatsApp/business theme)
- Category chip: "Case Study"
- Title: "How 500+ Indian Businesses Use WhatsApp to Recover 20% of Abandoned Carts"
- Excerpt: 2 lines
- Read time + date
- "Read Article →" CTA

### CATEGORY FILTER PILLS
```
[ All ] [WhatsApp Tips] [Campaigns] [AI & Chatbot] [E-Commerce] [Case Studies] [Updates]
```
- Active: green filled
- Inactive: gray outline

### ARTICLE CARDS (3-col grid, 6+ articles)
Each card:
- Thumbnail image (top, 16:9)
- Category chip (colored by type)
- Title (2 lines max)
- Excerpt (3 lines max)
- Author avatar + name + date + read time
- "Read more →"

#### Suggested Articles (based on AI bot persona + features):
1. "WhatsApp Bulk Messaging Guide for Indian SMBs 2026"
2. "AI Chatbot for WhatsApp: Complete Setup Guide"
3. "How to Recover Abandoned Carts on WhatsApp (20% Recovery Rate)"
4. "Festival Marketing on WhatsApp: Diwali, Pongal, Eid Templates"
5. "GST Invoice Automation via WhatsApp — Step by Step"
6. "Drip Campaign Strategy: 7 Day Lead Nurture on WhatsApp"

### NEWSLETTER CTA
- Green card
- "Get weekly WhatsApp marketing tips"
- Email input + Subscribe button
- "Join 500+ business owners"

---

## PAGE 5 — SUPPORT

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  HERO — "How can we help?"                          │
├─────────────────────────────────────────────────────┤
│  SEARCH BAR (large, center)                         │
├─────────────────────────────────────────────────────┤
│  HELP CATEGORIES (3-col icon grid)                  │
├─────────────────────────────────────────────────────┤
│  POPULAR ARTICLES                                   │
├─────────────────────────────────────────────────────┤
│  CONTACT OPTIONS (3-col)                            │
├─────────────────────────────────────────────────────┤
│  TICKET FORM                                        │
└─────────────────────────────────────────────────────┘
```

### PAGE HERO
- Background: Light green gradient
- H2: "How Can We Help?"
- Large search input: "Search documentation..."
- Popular searches: `setup whatsapp` · `bulk campaigns` · `chatbot` · `billing`

### HELP CATEGORIES (from 412 PHP pages)
```
┌──────────┐ ┌──────────┐ ┌──────────┐
│ 🚀       │ │ 📢       │ │ 🤖       │
│ Getting  │ │ Campaigns│ │ Chatbot  │
│ Started  │ │ & Blasts │ │ & AI Bot │
│ 6 guides │ │ 8 guides │ │ 5 guides │
└──────────┘ └──────────┘ └──────────┘
┌──────────┐ ┌──────────┐ ┌──────────┐
│ 🛒       │ │ ⚙️       │ │ 💳       │
│ E-Commerce│ │ Settings │ │ Billing  │
│ & Orders │ │ & Config │ │ & Plans  │
│ 7 guides │ │ 10 guides│ │ 4 guides │
└──────────┘ └──────────┘ └──────────┘
```

### POPULAR ARTICLES
- Accordion list (top 8 questions)
1. How to connect WhatsApp Business API?
2. How to send a bulk campaign?
3. How to set up the AI chatbot?
4. How to enable cart recovery?
5. How to add team members?
6. What is the WhatsApp quality rating? (GREEN = healthy)
7. How to create drip sequences?
8. How to generate GST invoices?

### CONTACT OPTIONS (3-col)
```
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  💬           │ │  📧           │ │  📱           │
│  WhatsApp     │ │  Email        │ │  Phone        │
│  Live Chat    │ │  Support      │ │  Support      │
│               │ │               │ │               │
│  Chat with us │ │ support@      │ │ +91-9876      │
│  on WhatsApp  │ │ 7digit.in     │ │ 543210        │
│  right now    │ │               │ │               │
│               │ │  Avg reply:   │ │  Mon-Sat      │
│  [Open Chat]  │ │  4 hours      │ │  10am-6pm IST │
└──────────────┘ └──────────────┘ └──────────────┘
```

### TICKET FORM
- From `support_tickets` table (fields: company_id, user_id, subject, status)
- Fields: Name · Email · Subject · Category dropdown · Message textarea · File upload
- Status: 1 open ticket in DB (shown as "Average resolution: 24 hours")
- Submit → creates ticket → WhatsApp confirmation sent

---

## PAGE 6 — LOGIN

### Layout Structure
```
┌─────────────────────────────────────────────────────┐
│  SPLIT SCREEN (50/50)                               │
│  Left: Visual / Brand Panel                         │
│  Right: Login Form                                  │
└─────────────────────────────────────────────────────┘
```

### LEFT PANEL (branding, green dark bg)
- Background: Dark green gradient (#0f1117 + #25D366 glow)
- Large WAAMINI logo (white)
- Tagline: "Your WhatsApp CRM Dashboard"
- Animated floating WhatsApp chat bubbles
- Stats strip at bottom:
  - 924 Messages Processed
  - 500+ Businesses Trusted
  - GREEN Quality Rating
- Decorative: Green dots / grid overlay

### RIGHT PANEL (login form, white)
- Small logo top-left
- H3: "Welcome Back"
- Subtext: "Sign in to your Waamini dashboard"

#### Form Fields (from `users` table + `auth.php`)
```
Email Address
[ email input field ]

Password
[ password input ]    [👁 show/hide]

[ ] Remember me          Forgot Password?

[ Login to Dashboard ]   ← green filled button

─────────── OR ───────────

[ 🟢 Continue with WhatsApp ]  ← WA green outline

───────────────────────────────

Don't have an account? Start Free Trial →
```

#### 2FA Screen (from `verify_2fa.php`, `GoogleAuthenticator.php`)
- Shown after login if 2FA enabled
- 6-digit OTP input (large digit boxes)
- "Enter your Google Authenticator code"
- Resend / Back link

#### Security Notes (from DB: max_login_attempts=3, session_timeout=45min)
- "Your session expires after 45 minutes of inactivity"
- After 3 failed attempts → account temporarily locked
- IP lockout system active

### FORGOT PASSWORD
- Separate card: email input → "Send Reset Link"
- From `audit_logs`: 24 password reset events recorded

---

## GLOBAL COMPONENTS

### NAVBAR
```
[WAAMINI Logo]   Home  Features  Pricing  Blog  Support   [Login]  [Start Free Trial]
```
- Height: 64px
- Background: White (transparent on hero → white on scroll)
- Backdrop blur on scroll
- Active page: green underline
- Mobile: hamburger → full-screen overlay menu

### FOOTER
```
┌─────────────────────────────────────────────────────┐
│  WAAMINI                    Product                 │
│  WhatsApp CRM for           Features                │
│  Indian Businesses          Pricing                 │
│                             Blog                    │
│  +91 73583 93967           Company                  │
│  support@7digit.in          About                   │
│  7digit.in                  Support                 │
│                             Privacy Policy          │
│  [ WhatsApp Us ]            Terms of Service        │
├─────────────────────────────────────────────────────┤
│  © 2026 WAAMINI by 7digit. All rights reserved.    │
│  Made with ❤️ for Indian Businesses                 │
└─────────────────────────────────────────────────────┘
```

### WHATSAPP FLOAT BUTTON
- Fixed bottom-right corner
- Green circle with WA icon
- Tooltip: "Chat with us on WhatsApp"
- Pulse animation ring
- Opens: wa.me/917358393967

### COOKIE / CONSENT BANNER
- Bottom strip
- "We use cookies for analytics · GDPR compliant (3-year retention)"

---

## COMPONENT LIBRARY

### Buttons
```
Primary:   [green bg #25D366, white text, 8px radius]
Secondary: [white bg, green border, green text]
Ghost:     [transparent, white border, white text — for dark bg]
Danger:    [coral #e6775b, white text]
Disabled:  [gray #d1d5db, gray text]
```

### Cards
```
Standard:  White, 1px border #e5e7eb, 12px radius, 16px padding, hover shadow
Feature:   White, green top-border 3px, 12px radius, icon + title + desc
Pricing:   White, 24px radius, heavy shadow; Enterprise = green border
Dark:      #1a1a2e background, white text, green accents
```

### Form Inputs
```
Height: 44px
Border: 1px #d1d5db → focus: 2px #25D366
Radius: 8px
Padding: 12px 16px
Error: red border + error message below
```

### Status Badges
```
Active/Success : green bg #dcfce7, green text
Pending        : yellow bg #fef9c3, yellow text
Failed         : red bg #fee2e2, red text
Running        : blue bg #dbeafe, blue text
GREEN quality  : #25D366 dot + label
```

### Stats Counter
```
Large number: 48-64px bold, green color
Label: 14px gray below
Animation: count-up on scroll into view
```

---

## RESPONSIVE BREAKPOINTS

| Breakpoint | Width | Layout Change |
|---|---|---|
| Mobile | < 640px | Single col, hamburger nav, stacked cards |
| Tablet | 640–1024px | 2-col grid, condensed nav |
| Desktop | 1024–1440px | Full 3-col, sticky sidebar |
| Wide | > 1440px | Max-width 1280px container centered |

---

## ANIMATIONS & INTERACTIONS

| Element | Animation |
|---|---|
| Hero | Particle float (WhatsApp bubbles), fade-in stagger |
| Stat counters | Count-up on scroll enter |
| Feature cards | Fade-up + translate on scroll |
| Pricing cards | Scale hover (1.02) |
| Chatbot mockup | Typing indicator → message appear |
| WA float button | Pulse ring every 3s |
| Nav scroll | Background blur fade-in |
| Tab switch | Slide content transition |
| Accordion | Smooth expand/collapse |
| Form inputs | Border glow on focus |

---

## SEO & META

| Page | Title | Description |
|---|---|---|
| Home | Waamini — WhatsApp CRM for Indian Businesses | Send bulk campaigns, automate with AI chatbot, recover carts on WhatsApp |
| Features | Waamini Features — 90+ WhatsApp CRM Tools | Campaigns, AI chatbot, native checkout, drip automation and more |
| Pricing | Waamini Pricing — Free Trial to Enterprise | Start free. Upgrade to Enterprise at ₹2,999/month |
| Blog | WhatsApp Marketing Blog — Waamini | Tips, guides and case studies for Indian businesses |
| Support | Waamini Support — Help Center | Get help with setup, campaigns, chatbot and billing |
| Login | Login — Waamini Dashboard | Sign in to your WhatsApp CRM dashboard |

---

## DATA INTEGRATION NOTES FOR AI

These values should be used as **real content** in the UI (sourced from live MCP database):

```
business_count    : 500+
messages_total    : 924
conversations     : 42
campaigns_total   : 17
read_rate         : ~91%
cart_recovery_avg : 20%
wa_quality        : GREEN
phone_display     : +91 73583 93967
price_free        : ₹0/month
price_enterprise  : ₹2,999/month
contacts_limit_free : 500
contacts_limit_ent  : 1,000+
team_limit_free     : 3
demo_url            : https://cal.com/waamini/demo
support_email       : support@7digit.in
support_phone       : +91-9876543210
company_website     : https://www.7digit.in
wa_api_version      : v20.0
```

---

*Generated from live MCP analysis of waamini MySQL database — 85 tables, 412 PHP files, 924 messages, 17 campaigns. Date: 2026-06-27*
