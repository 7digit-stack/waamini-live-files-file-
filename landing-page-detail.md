# Waamini — Landing Pages Analysis for Future UI Updates

> Generated: 2026-06-07
> Purpose: Reference for future UI/UX updates, redesigns, and feature additions.

---

## 1. Global Navigation (Public Site)

### Header — `includes/header.php` (311 lines)

**Desktop Nav (`.nav-menu`):**
| Item | Link | Notes |
|------|------|-------|
| Features | `features.php` | |
| Pricing | `pricing.php` | |
| Blog | `blogs.php` | |
| Support | `support.php` | |
| Register | `register.php` | Text link |
| Login | `login.php` | Gradient button `.nav-login` |

**Mobile Nav (`.mobile-overlay`):**
| Item | Link | Notes |
|------|------|-------|
| Home | `index.php` | Desktop has no "Home" link |
| Features | `features.php` | |
| Pricing | `pricing.php` | |
| Blog | `blogs.php` | |
| Support | `support.php` | |
| Login | `login.php` | Text link |
| Get Started Free | `register.php` | CTA button |

**Key details:**
- Sticky navbar with glassmorphism effect
- Sets SEO meta tags, OG/Twitter cards, GA4, JSON-LD schemas
- Async CSS loading via `$asyncStylesheets` array
- Inline critical CSS via `$pageCriticalCss`
- CSP nonce generation in `config/bootstrap.php`
- Auth pages set `$skipMarketingCSS = true` to skip heavy public CSS

### Footer — `includes/footer.php` (244 lines)

**4-column layout:**
| Column | Content |
|--------|---------|
| 1. Brand | Logo, description, badges (Meta Partner, SSL, GDPR) |
| 2. Platform | Features, Pricing, Blog, Support, FAQs |
| 3. Solutions | E-commerce, Real Estate, Healthcare, Education |
| 4. Contact | Email (info@7digit.in) |

**Bottom bar:** Copyright 2026 | Sitemap | Privacy Policy | Terms of Service

---

## 2. Landing Pages

### 2.1 Home Page — `index.php` (733+ lines)

**CSS:** `assets/css/index.css` (2459 lines)
**Schema:** SoftwareApplication with aggregateRating (4.9, 527 reviews)

| Section | Class/ID | Key Components |
|---------|----------|----------------|
| Hero | `.index-hero` | `.hero-content.glass-premium` (left text) + `.hero-visual.flow-wrap-interactive` (right animated SVG flowchart). CTAs: `btn-primary-glow` → register, `btn-secondary-glass` → features. Trust signals: Meta Partner badge, live signup counter, 4.9 stars |
| Achievement Strip | `.achievement-strip` | 4 metric cards: Zero markup, 30+ industries, 98% open rate, 200+ festivals |
| Growth Pipeline | (generic section) | 5-stage horizontal pipeline: Capture → Integrate → Automate → Analyze → Scale |
| Industry Cards | `.industry-focus-section` | 6 card-grid with chat previews: Restaurant, Real Estate, Education, Healthcare, E-commerce, Fitness |
| Bot Automation | (generic section) | "Stop Replying. Start Automating." with feature cards + timeline |
| Comparison Table | `.why-premium-section` | Side-by-side: Waamini (8 checks) vs WATI/AiSensy/Interakt (8 crosses) |
| Platform Showcase | `.showcase-section` | Tab-driven panels: Campaigns, AI Bot, GST Invoice, CRM & Leads, Festival Bot, Payments |

**Future UI notes:**
- Hero uses `neural-mesh` and `neural-flux` canvas animations — consider performance on lower-end devices
- Interactive SVG nodes with `onclick="showNodeInfo(...)"` — could be updated to a more modern animation library
- Comparison table could be extracted to a reusable component
- Showcase tabs could support video embeds

---

### 2.2 Features Page — `features.php` (423 lines)

**CSS:** `assets/css/features.css`
**Schema:** BreadcrumbList

| Section | Key Components |
|---------|----------------|
| Hero | Badge "Powered by WhatsApp Cloud API", headline, description |
| Stats Bar | 4 stats: 98% open rate, 45% conversions, 24/7 support, 5x response |
| Why WAAMINI | 3 feature cards: Engagement, Conversions, Support |
| Core Features | 12-card grid with scroll animation (`[data-anim]`) |
| Campaign Management | Showcase rows with alternating content + visual cards |
| API Capabilities | Feature table with 12 rows |
| Media Support | 4 cards: Images, Videos, Documents, Audio |
| Message Tracking | 3 cards: Sent, Delivered, Read |
| Final CTA | Link to `register.php` |

**Reusable components:** `feature-card[data-anim]`, `stats-bar`, `showcase-section`, `showcase-row`, `check-list`, `api-table`, `btn-cta`

---

### 2.3 Pricing Page — `pricing.php` (405 lines)

**CSS:** `assets/css/pricing.css`
**Schema:** SoftwareApplication + FAQPage

| Section | Key Components |
|---------|----------------|
| Hero | Monthly/Yearly toggle switch |
| Pricing Grid | 3 cards: Starter (₹999/mo), Growth (₹1,499/mo, featured "MOST POPULAR"), Enterprise (₹1,999/mo). Each has 10 feature bullets, discount ribbons |
| Message Pricing | Zero markup explanation + 4 message type cards (Marketing, Utility, Authentication, Service) |
| Bot Comparison Table | 7-row feature-depth comparison across 3 plans |
| FAQ | `.faq-section` with 5 toggle items |
| Final CTA | "Get Started Free" button |

**Future UI notes:**
- `pricing-toggle` JS handles monthly/yearly price switching
- `.faq-item` accordion pattern reused from support page
- Consider adding annual discount highlights and a plan comparison sticky header

---

### 2.4 Industry Pages (4 pages — identical pattern)

| Page | File | Hero Class |
|------|------|------------|
| E-commerce | `ecommerce.php` (161 lines) | `.hero-ecommerce` |
| Education | `education.php` (141 lines) | `.hero-education` |
| Healthcare | `healthcare.php` (141 lines) | `.hero-healthcare` |
| Real Estate | `real-estate.php` (141 lines) | `.hero-realestate` |

**CSS:** `assets/css/industries.css`

**Shared structure:**
1. **Hero** — Badge + headline + `.chat-preview-container` with 4 animated preview messages
2. **Key Benefits** — 4 `.i-feature-card` items
3. **Automation Workflow** — `.bot-timeline` with 3 `.timeline-step` items
4. **CTA** — `.premium-cta-section` with glass card and `.btn-premium-portal`

**Future UI notes:**
- Near-identical structure → could be converted to a single template with data-driven content
- Chat preview messages are hardcoded — could be dynamic
- Consider adding industry-specific testimonials or case studies

---

### 2.5 Support Center — `support.php` (205 lines)

**CSS:** `assets/css/support.css`
**Schema:** FAQPage

| Section | Key Components |
|---------|----------------|
| Hero | Badge + title |
| Contact Cards | 3 cards: Call (+91 9150802906 / +91 9003876516), Email (info@7digit.in), Office (Karaikudi - 630001) |
| FAQ | 4 accordion `.faq-item` components |
| Contact Form | POST form: Name, Email, Subject (dropdown), Message |
| Google Map | Embedded iframe with info overlay bar + "Get Directions" link |

---

### 2.6 Blog Page — `blogs.php` (651 lines)

| Section | Key Components |
|---------|----------------|
| Hero | Pill badge, heading, sub-text, search input |
| Category Filter | Buttons for all unique categories |
| Blog Grid | Responsive grid: `.blog-card` + `.blog-card-featured` |
| Read Modal | `.blog-modal-overlay` with full article content + CTA |

**Future UI notes:**
- 10 hardcoded articles — should be database-driven
- Dynamic JS filter by search + category
- Consider pagination or infinite scroll
- Modal pattern could be replaced with dedicated blog detail page

---

## 3. Auth Pages

### 3.1 `login.php` (236 lines)

**CSS:** `assets/css/auth.css` (or `login.css`, `register.css`)
**Features:** CSRF, rate limiting (5 attempts/5 min), IP lockout, remember-me tokens, 2FA redirect

| Element | Detail |
|---------|--------|
| Container | `.auth-page` → `.auth-card` |
| Form fields | Email, Password |
| Extras | "Remember me" checkbox, "Forgot password?" link, password visibility toggle |
| Footer link | "Don't have an account? Register" → `register.php` |

### 3.2 `register.php` (238 lines)

| Element | Detail |
|---------|--------|
| Form fields | Company Name, First Name, Last Name, Mobile, Email, Password, Confirm Password |
| Validation | CSRF, phone (international format), password min 8 chars, duplicate email check |
| Transaction | Company + user created atomically |

### 3.3 `forgot-password.php` (108 lines)

CSRF, rate limited (max 3 active tokens), SHA-256 hashed tokens, 1-hour expiry, SMTP email, audit log.

### 3.4 `reset-password.php` (101 lines)

Token validation, strong password regex (upper + lower + digit + special), token deletion on use.

### 3.5 `verify_2fa.php`

Google Authenticator TOTP for two-factor authentication.

---

## 4. Content / Legal Pages

| Page | Sections |
|------|----------|
| `terms-of-service.php` | 8 sections: Agreement, WhatsApp API Compliance, IP Rights, User Representations, Fees, Liability, Termination, Contact |
| `privacy-policy.php` | 7 sections: Introduction, Information Collected, How We Use, Data Sharing, Data Retention, Security, Contact |

---

## 5. Key UI Patterns & Reusable Components

### Component Inventory

| Component Class | Used In | Description |
|----------------|---------|-------------|
| `.glass-premium` | index, industry pages | Glassmorphism card with backdrop-blur |
| `.hero-badge` + `.pulse-dot` | Multiple pages | Animated "badge" label with pulsing dot |
| `.btn-primary-glow` | index, features, pricing | Purple gradient CTA button with glow |
| `.btn-secondary-glass` | index, features | Glass-style secondary CTA |
| `.bot-timeline` / `.timeline-step` | features, industry pages | Animated 3-step workflow |
| `.chat-preview-container` / `.preview-msg` | Industry pages | Animated WhatsApp chat bubble |
| `.api-table` / `.api-table-wrapper` | features, pricing | Feature comparison tables |
| `.faq-item` / `.faq-question` / `.faq-answer` | support, pricing | Accordion FAQ with toggle |
| `.auth-page` / `.auth-card` / `.auth-form` | login, register | Auth page layout pattern |
| `.feature-card[data-anim]` | features | Scroll-triggered animated cards |
| `.industry-hero` / `.industry-feature-grid` / `.i-feature-card` | All 4 industry pages | Industry page layout |
| `.premium-cta-section` / `.premium-cta-card` / `.btn-premium-portal` | Industry pages | Bottom CTA glass cards |
| `.showcase-section` / `.showcase-row` | index, features | Alternating content showcase |
| `.pricing-card` / `.featured` / `.plan-cta` | pricing | Pricing tier cards |
| `.mobile-overlay` | header.php | Full-screen mobile nav overlay |
| `.nav-menu` / `.nav-login` | header.php | Desktop navigation |
| `.blog-card` / `.blog-card-featured` | blogs.php | Blog listing cards |
| `.blog-modal-overlay` | blogs.php | Blog reading modal |

### CSS Architecture

| File | Lines | Purpose |
|------|-------|---------|
| `assets/css/index.css` | 2459 | Home page |
| `assets/css/features.css` | — | Features page |
| `assets/css/pricing.css` | — | Pricing page |
| `assets/css/industries.css` | — | All 4 industry pages |
| `assets/css/support.css` | — | Support page |
| `assets/css/auth.css` | — | Login/Register |
| `assets/css/admin.css` | — | Admin panel |
| `assets/css/client.css` | — | Client dashboard |
| `assets/css/chat.css` | — | WhatsApp chat |
| `assets/css/modern-style.css` | — | Additional enhancements |
| `assets/css/premium-effects.css` | — | Premium effects |
| `assets/css/public.bundle.min.css` | — | Bundled public CSS |

### Shared Include Flow

```
header.php
  └─ config/bootstrap.php (session, CSP, autoload, error handling)
  └─ $page_title, $meta_desc, $meta_keywords (set by each page)
  └─ $asyncStylesheets (page-specific CSS)
  └─ $pageCriticalCss (inline critical CSS)
  └─ Nav + mobile overlay

[Page Content]

footer.php
  └─ 4-column footer
  └─ JS chat preview observer, timeline animation observer
```

---

## 6. Recommendations for Future UI Updates

### High Priority
1. **Convert industry pages to data-driven template** — All 4 industry pages are identical HTML. Use a single template with array-driven content.
2. **Blog CMS** — Replace 10 hardcoded articles with database-driven content with admin CRUD.
3. **Unify CSS** — Many CSS files with overlapping styles. Consider extracting common styles into a shared design system CSS file.
4. **Component extraction** — `.faq-item`, `.chat-preview`, `.bot-timeline`, `.pricing-card` are duplicated across files. Extract to include partials.

### Medium Priority
5. **Hero animation performance** — `neural-mesh` canvas animation may lag on mobile. Add a fallback static image.
6. **Pagination on blog** — Add load-more or pagination for blog articles.
7. **Pricing annual toggle** — Annual pricing shows 0% discount currently. Add dynamic discount calculation.
8. **Consistent CTA styling** — `.btn-primary-glow`, `.btn-premium-portal`, `.nav-login` all serve as primary CTAs but have slightly different styles.

### Low Priority
9. **Sticky table headers** — Comparison tables on pricing/features pages should have sticky headers on scroll.
10. **Dark mode** — No theme toggle exists for public pages (client/admin have none).
11. **Loading states** — No skeleton loaders or loading indicators on public pages.
12. **Form validation UX** — Auth forms could use inline validation (not just server-side errors).

---

## 7. File Reference Index

| File | Type | Lines | Key Role |
|------|------|-------|----------|
| `index.php` | Landing | 733+ | Main home page |
| `features.php` | Landing | 423 | Features overview |
| `pricing.php` | Landing | 405 | Pricing tiers |
| `ecommerce.php` | Industry | 161 | E-commerce solution |
| `education.php` | Industry | 141 | Education solution |
| `healthcare.php` | Industry | 141 | Healthcare solution |
| `real-estate.php` | Industry | 141 | Real estate solution |
| `support.php` | Utility | 205 | Contact & FAQ |
| `blogs.php` | Content | 651 | Blog listing |
| `login.php` | Auth | 236 | User login |
| `register.php` | Auth | 238 | User registration |
| `forgot-password.php` | Auth | 108 | Password reset request |
| `reset-password.php` | Auth | 101 | Password reset |
| `verify_2fa.php` | Auth | — | 2FA verification |
| `terms-of-service.php` | Legal | 115 | Legal terms |
| `privacy-policy.php` | Legal | 117 | Privacy policy |
| `includes/header.php` | Partial | 311 | Global header + nav |
| `includes/footer.php` | Partial | 244 | Global footer |
| `includes/admin_header.php` | Partial | 161 | Admin sidebar + topbar |
| `includes/admin_footer.php` | Partial | 7 | Admin footer |
| `includes/client_header.php` | Partial | 512 | Client sidebar + topbar |
| `includes/client_footer.php` | Partial | 85 | Client footer |
| `config/bootstrap.php` | Config | 184 | Session, CSP, autoload |
| `config/config.php` | Config | 136 | App configuration |
| `.htaccess` | Config | 151 | Security, caching, routing |
