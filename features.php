<?php 
$page_title = "WhatsApp Marketing Features & CRM Capabilities";
$meta_desc = "Explore Waamini's powerful WhatsApp Business CRM features: Bulk campaigns, AI chatbots, visual flow builder, and official WhatsApp Cloud API integration.";
$meta_keywords = "WhatsApp Campaign Management, WhatsApp Bot Builder, WhatsApp CRM Features, WhatsApp Business API Solutions";
$asyncStylesheets = [
  'assets/css/features.css?v=10',
  'assets/css/landing-neo.css?v=16',
];
$bodyClass = 'lp-neo';
$pageCriticalCss = <<<'CSS'
/* Critical CSS: features hero (fast LCP) */
.features-hero{padding:100px 0 80px;text-align:center;position:relative;overflow:hidden;background:#f8fafc}
.features-hero::before{content:'';position:absolute;top:-150px;left:50%;transform:translateX(-50%);width:800px;height:400px;background:rgba(108,92,231,.10)}
.features-hero .badge-label{display:inline-block;padding:8px 20px;background:rgba(108,92,231,.15);border:1px solid rgba(108,92,231,.3);border-radius:50px;color:var(--primary);font-size:13px;font-weight:600;letter-spacing:.5px;margin-bottom:24px}
.features-hero h1{font-size:48px;font-weight:800;letter-spacing:-1.5px;line-height:1.2;max-width:700px;margin:0 auto 20px;color:var(--text-primary)}
.features-hero p{font-size:18px;color:var(--text-secondary);max-width:600px;margin:0 auto;line-height:1.7}
@media (max-width:768px){.features-hero h1{font-size:32px}}
CSS;
include 'includes/header.php'; 
?>
<!-- features.css loaded async via header hook -->

<!-- BREADCRUMB SCHEMA -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [{
    "@type": "ListItem",
    "position": 1,
    "name": "Home",
    "item": "https://waamini.in/index.php"
  },{
    "@type": "ListItem",
    "position": 2,
    "name": "Features",
    "item": "https://waamini.in/features.php"
  }]
}
</script>

<!-- HERO -->
<section class="features-hero">
    <div class="container">
        <div class="badge-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg> Powered by WhatsApp Cloud API</div>
        <div class="title-wrap"><h1>Everything You Need to Scale WhatsApp Marketing</h1></div>
        <p>Automate conversations, manage campaigns, and delight customers with WAAMINI's powerful WhatsApp Business API integration platform.</p>
    </div>
</section>

<!-- STATS -->
<section class="container">
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-number">98%</div>
            <div class="stat-text">Message Open Rate</div>
        </div>
        <div class="stat-item">
            <div class="stat-number">45%</div>
            <div class="stat-text">Higher Conversions</div>
        </div>
        <div class="stat-item">
            <div class="stat-number">24/7</div>
            <div class="stat-text">Automated Support</div>
        </div>
        <div class="stat-item">
            <div class="stat-number">5x</div>
            <div class="stat-text">Faster Response</div>
        </div>
    </div>
</section>

<!-- WHY WAAMINI -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">Why WAAMINI</div>
            <div class="title-wrap"><h2>Transform Your Customer Engagement</h2></div>
            <p>Connect with your customers where they already are — on WhatsApp. Drive results with personalized, real-time interactions.</p>
        </div>
        <div class="feature-grid">
            <div class="feature-card" data-anim>
                <div class="card-icon purple"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg></div>
                <h3>Increased Engagement</h3>
                <p>Connect directly with your customers in real-time on WhatsApp using WAAMINI's intuitive interface and seamless integration. Build lasting relationships that drive results.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon green"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></div>
                <h3>Higher Conversion Rates</h3>
                <p>Every chat is an opportunity. Transform casual conversations into meaningful interactions that lead to increased conversions through targeted messaging and personalized campaigns.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon blue"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg></div>
                <h3>24/7 Customer Support</h3>
                <p>Your customers deserve the best support. WAAMINI delivers with 24/7 automated responses that ensure you never miss a beat. Stay connected, stay responsive.</p>
            </div>
        </div>
    </div>
</section>

<!-- CORE FEATURES -->
<section class="section" style="background: #f8fafc;">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">Core Features</div>
            <div class="title-wrap"><h2>Tech Empowerment for WhatsApp Marketing</h2></div>
            <p>Features that make your life easier and your marketing smarter.</p>
        </div>
        <div class="feature-grid">
            <div class="feature-card" data-anim>
                <div class="card-icon purple"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
                <h3>Embedded Signup</h3>
                <p>Onboard customers with ease using our integrated Embedded Signup system. One-click WhatsApp Business API onboarding.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon blue"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></div>
                <h3>Template Management</h3>
                <p>Create, manage, and submit message templates directly within WAAMINI — no need to visit Meta Business Manager.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon green"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg></div>
                <h3>Multiple Phone Numbers</h3>
                <p>Support multiple phone numbers for the same WhatsApp Business Account. Manage all your numbers from one dashboard.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon cyan"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                <h3>WhatsApp Chat</h3>
                <p>WAAMINI's chat feature replicates the native WhatsApp interface, guaranteeing a seamless and familiar messaging experience.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon orange"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg></div>
                <h3>Bot Replies / Chatbot</h3>
                <p>Automate responses and engage customers 24/7 with intelligent bot replies. Build conversation flows with our visual bot builder.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon pink"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
                <h3>API Integration</h3>
                <p>RESTful APIs enable seamless connection between services, allowing data sharing and functionality integration with your existing tools.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon blue"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <h3>Contact Management</h3>
                <p>Import and export contacts using XLSX format. Add, edit, tag, and segment your audience for targeted messaging campaigns.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon green"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></div>
                <h3>Realtime Updates</h3>
                <p>Real-time message and campaign status updates. See your campaign performance with live sent, delivered, and read metrics.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon purple"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg></div>
                <h3>Analytics Dashboard</h3>
                <p>Instant visibility into your marketing campaign performance with comprehensive charts, metrics, and actionable insights.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon orange"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <h3>Team Members & Agents</h3>
                <p>Delegate work by creating team members with granular permissions. Assign chats, manage roles, and collaborate efficiently.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon pink"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></div>
                <h3>Interactive Messages</h3>
                <p>Send interactive buttons, lists, images, videos, and documents through your chatbot for enhanced user engagement.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon cyan"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg></div>
                <h3>Custom Fields</h3>
                <p>Personalize messages with user-specific information and custom fields tailored to your audience for maximum impact.</p>
            </div>
        </div>
    </div>
</section>

<!-- SHOWCASE: CAMPAIGN MANAGEMENT -->
<section class="showcase-section">
    <div class="showcase-row">
        <div class="showcase-content">
            <span class="tag">Campaign Management</span>
            <div class="title-wrap"><h2>Create, Schedule & Reach Instantly</h2></div>
            <p>Effortlessly manage your campaigns with our intuitive management tools. Create or schedule campaigns for all contacts or specific groups, allowing for immediate reach or strategic timing.</p>
            <ul class="check-list">
                <li>Instant broadcast to all contacts</li>
                <li>Schedule campaigns for optimal timing</li>
                <li>Target specific groups and segments</li>
                <li>Track sent, delivered, and read metrics</li>
                <li>Template-based campaigns with variables</li>
            </ul>
        </div>
        <div class="showcase-visual">
            <div class="visual-card">
                <span class="icon-large"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg></span>
                <div class="metric">10x</div>
                <div class="metric-label">Campaign reach compared to email</div>
            </div>
        </div>
    </div>
</section>

<!-- SHOWCASE: INTEGRATED CHAT -->
<section class="showcase-section">
    <div class="showcase-row reverse">
        <div class="showcase-content">
            <span class="tag">Integrated WhatsApp Chat</span>
            <div class="title-wrap"><h2>Enhance Customer Engagement & Support</h2></div>
            <p>The Integrated WhatsApp Chat provides a seamless messaging experience by faithfully replicating the native WhatsApp interface. Users can navigate effortlessly, leveraging their existing familiarity with WhatsApp's layout.</p>
            <ul class="check-list">
                <li>Native WhatsApp-like interface</li>
                <li>Real-time message delivery</li>
                <li>Media sharing — images, videos, documents</li>
                <li>Quick replies and canned responses</li>
                <li>Chat assignment to team members</li>
            </ul>
        </div>
        <div class="showcase-visual">
            <div class="visual-card">
                <span class="icon-large"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
                <div class="metric">24/7</div>
                <div class="metric-label">Always-on customer communication</div>
            </div>
        </div>
    </div>
</section>

<!-- SHOWCASE: BOT FLOW BUILDER -->
<style>
/* ── Bot Builder: Domain Switcher + WhatsApp Phone ── */
.bot-builder-grid{display:grid;grid-template-columns:1fr 1fr;gap:clamp(40px,7vw,100px);align-items:center}
.bb-right{display:flex;flex-direction:column;align-items:center;gap:16px;position:relative}
.bd-switch{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;width:100%;max-width:340px}
.bd-tab{padding:7px 15px;border-radius:50px;font-size:12px;font-weight:600;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer;transition:all 0.22s;white-space:nowrap;line-height:1}
.bd-tab:hover,.bd-tab.active{background:#25D366;border-color:#25D366;color:#fff;box-shadow:0 4px 12px rgba(37,211,102,0.3)}
/* Phone shell */
.f-wa-phone{width:285px;background:#fff;border-radius:40px;border:6px solid #1a1a2e;box-shadow:0 0 0 2px #fff,0 32px 70px -10px rgba(0,0,0,0.22),0 8px 20px rgba(0,0,0,0.10);overflow:hidden;position:relative;z-index:2;flex-shrink:0}
.f-wa-notch{height:18px;background:#1a1a2e;display:flex;align-items:center;justify-content:center}
.f-wa-pill{width:52px;height:5px;background:rgba(255,255,255,0.2);border-radius:50px}
.f-wa-status{display:flex;justify-content:space-between;align-items:center;padding:3px 14px 2px;font-size:10px;font-weight:700;color:#fff;background:#075E54}
.f-wa-header{display:flex;align-items:center;gap:8px;padding:8px 12px 9px;background:#075E54}
.f-wa-back{color:rgba(255,255,255,0.85);font-size:22px;line-height:1;padding-right:2px}
.f-wa-av{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.f-wa-info{flex:1}
.f-wa-info strong{display:block;font-size:13px;color:#fff;font-weight:600;line-height:1.2}
.f-wa-info span{font-size:11px;color:rgba(255,255,255,0.75);display:flex;align-items:center;gap:4px}
.f-wa-dot{width:6px;height:6px;border-radius:50%;background:#25D366;display:inline-block;animation:fwaDot 2s infinite}
@keyframes fwaDot{0%,100%{opacity:1}50%{opacity:0.3}}
.f-wa-menu{color:rgba(255,255,255,0.8);font-size:18px}
/* Chat panels */
.f-wa-chat{background:#ece5dd;padding:10px 10px 6px;min-height:310px;max-height:350px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;scrollbar-width:none}
.f-wa-chat::-webkit-scrollbar{display:none}
.bd-panel{display:none!important}
.bd-panel.bd-active{display:flex!important;animation:bdFade 0.3s ease}
@keyframes bdFade{from{opacity:0}to{opacity:1}}
.f-chat-date{text-align:center;font-size:10.5px;color:#54656f;background:rgba(255,255,255,0.85);border-radius:50px;padding:3px 12px;align-self:center;margin-bottom:2px;font-weight:500;box-shadow:0 1px 2px rgba(0,0,0,0.08);flex-shrink:0}
.f-chat-row{display:flex;flex-direction:column;max-width:83%}
.f-chat-row.f-out{align-self:flex-end;align-items:flex-end}
.f-chat-row.f-in{align-self:flex-start;align-items:flex-start}
.f-bubble{font-size:12.5px;line-height:1.5;padding:6px 10px 5px;border-radius:8px;word-break:break-word}
.f-out .f-bubble{background:#dcf8c6;color:#111b21;border-bottom-right-radius:2px;box-shadow:0 1px 2px rgba(0,0,0,0.10)}
.f-in .f-bubble{background:#fff;color:#111b21;border-bottom-left-radius:2px;box-shadow:0 1px 2px rgba(0,0,0,0.10)}
.f-bot-label{display:block;font-size:11px;font-weight:700;color:#075E54;margin-bottom:2px}
.f-price{color:#128C7E;font-weight:700}
.f-qr-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px;max-width:200px}
.f-qr{font-size:11px;font-weight:600;padding:4px 11px;border-radius:50px;border:1.5px solid #25D366;color:#075E54;background:rgba(255,255,255,0.95);cursor:pointer;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,0.08);transition:all 0.2s;line-height:1.4}
.f-qr:hover{background:#25D366;color:#fff;border-color:#25D366}
.f-meta{font-size:9.5px;color:#8696a0;padding:1px 4px;display:flex;align-items:center;gap:3px;flex-shrink:0}
.f-ticks{color:#53bdeb;font-size:10px;letter-spacing:-1px}
/* List card */
.f-list-card{background:#fff;border-radius:8px;overflow:hidden;max-width:87%;align-self:flex-start;box-shadow:0 1px 3px rgba(0,0,0,0.12);border:1px solid #e9edef;flex-shrink:0}
.f-list-head{padding:8px 12px;font-size:12.5px;font-weight:700;color:#fff;background:#128C7E}
.f-list-row{padding:5px 12px;font-size:12px;color:#3b4a54;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;gap:7px}
.f-list-row::before{content:'';width:4px;height:4px;border-radius:50%;background:#25D366;flex-shrink:0}
.f-list-row:last-of-type{border-bottom:none}
/* Input bar */
.f-wa-inputbar{display:flex;align-items:center;gap:6px;padding:6px 8px;background:#f0f2f5;border-top:1px solid #e9edef}
.f-wa-field{flex:1;background:#fff;border:none;border-radius:24px;padding:7px 14px;font-size:12px;color:#aebac1;outline:none;box-shadow:0 1px 2px rgba(0,0,0,0.06)}
.f-wa-mic{width:34px;height:34px;border-radius:50%;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.f-wa-send{width:34px;height:34px;border-radius:50%;border:none;background:#25D366;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(37,211,102,0.4)}
/* Glow */
.f-phone-glow{position:absolute;width:285px;height:200px;background:radial-gradient(circle,rgba(37,211,102,0.12) 0%,transparent 70%);border-radius:50%;bottom:-50px;left:50%;transform:translateX(-50%);z-index:0;pointer-events:none;animation:fglow 4s ease-in-out infinite alternate}
@keyframes fglow{from{opacity:0.4}to{opacity:0.9}}
/* Responsive */
@media(max-width:960px){
  .bot-builder-grid{grid-template-columns:1fr}
  .bb-right{order:-1}
  .f-wa-phone{width:270px}
}
@media(max-width:520px){
  .f-wa-phone{width:100%;max-width:300px;border-radius:30px}
  .f-wa-chat{min-height:270px;max-height:320px}
  .bd-switch{max-width:100%}
  .bd-tab{font-size:11px;padding:6px 12px}
}
</style>

<section class="showcase-section" id="bot-builder">
<div class="bot-builder-grid">

    <!-- LEFT: Steps -->
    <div class="bb-left">
        <span class="tag">Advanced Automation</span>
        <div class="title-wrap"><h2 class="bb-heading">Visualize, Build &amp;<br>Automate Every Chat</h2></div>
        <p class="bb-sub">No-code visual flow builder. Design complex customer journeys in minutes &mdash; map exactly how your bot responds to every keyword, button, and action.</p>

        <div class="bb-steps">
            <div class="bb-step" data-bd="0">
                <div class="bb-icon bb-icon-1">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                </div>
                <div class="bb-step-text">
                    <h4>&#9312; Keyword Trigger</h4>
                    <p>User sends a keyword. Bot matches via <em>Exact, Contains, or Fuzzy</em> logic &mdash; zero manual effort.</p>
                </div>
            </div>
            <div class="bb-step" data-bd="160">
                <div class="bb-icon bb-icon-2">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                </div>
                <div class="bb-step-text">
                    <h4>&#9313; Dynamic Branching</h4>
                    <p>Route customers to the right flow with live <strong>{name}</strong> &amp; variable substitution.</p>
                </div>
            </div>
            <div class="bb-step" data-bd="320">
                <div class="bb-icon bb-icon-3">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                </div>
                <div class="bb-step-text">
                    <h4>&#9314; Multi-Format Reply</h4>
                    <p>Send List Messages, attach PDFs, and fire <strong>Zapier webhooks</strong> &mdash; all in one step.</p>
                </div>
            </div>
        </div>

        <div class="bb-chips">
            <span class="bb-chip">Unlimited Keywords</span>
            <span class="bb-chip">Variable Substitution</span>
            <span class="bb-chip">Quick-Reply Buttons</span>
            <span class="bb-chip">Webhook &rarr; Zapier</span>
            <span class="bb-chip">Human Handover</span>
        </div>
    </div>

    <!-- RIGHT: Domain Switcher + WhatsApp Phone -->
    <div class="bb-right">

        <!-- Business domain tabs -->
        <div class="bd-switch">
            <button class="bd-tab active" data-domain="ecomm">&#x1F6D2; E-Commerce</button>
            <button class="bd-tab" data-domain="health">&#x1FA7A; Healthcare</button>
            <button class="bd-tab" data-domain="realty">&#x1F3E0; Real Estate</button>
            <button class="bd-tab" data-domain="edu">&#x1F393; Education</button>
        </div>

        <!-- Phone frame -->
        <div class="f-wa-phone">

            <div class="f-wa-notch"><div class="f-wa-pill"></div></div>

            <div class="f-wa-status">
                <span>9:41</span>
                <span style="letter-spacing:2px;font-size:9px;">&#9650; &#9679; &#9632;</span>
            </div>

            <div class="f-wa-header">
                <span class="f-wa-back">&#8249;</span>
                <div class="f-wa-av" id="bd-av">&#x1F916;</div>
                <div class="f-wa-info">
                    <strong id="bd-botname">ShopBot</strong>
                    <span><span class="f-wa-dot"></span>online</span>
                </div>
                <span class="f-wa-menu">&#8942;</span>
            </div>

            <!-- E-Commerce Chat -->
            <div class="f-wa-chat bd-panel bd-active" id="bd-ecomm">
                <div class="f-chat-date">Today</div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Price</div>
                    <div class="f-meta">10:42 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">
                        <span class="f-bot-label">ShopBot</span>
                        &#x1F44B; Hi! Welcome to Waamini. Are you a <strong>new</strong> or <strong>existing</strong> customer?
                    </div>
                    <div class="f-qr-row">
                        <span class="f-qr">&#x1F195; New</span>
                        <span class="f-qr">&#x1F504; Existing</span>
                    </div>
                    <div class="f-meta">10:42 AM</div>
                </div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">&#x1F195; New</div>
                    <div class="f-meta">10:43 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-list-card">
                    <div class="f-list-head">&#x1F4CB; Our Plans</div>
                    <div class="f-list-row">Starter &mdash; <span class="f-price">&#8377;2,999/mo</span></div>
                    <div class="f-list-row">Growth &mdash; <span class="f-price">&#8377;5,999/mo</span></div>
                    <div class="f-list-row">Enterprise &mdash; Custom</div>
                    <div class="f-meta" style="padding:6px 10px 4px;">10:43 AM</div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">&#x1F680; All plans include <strong>unlimited bots</strong>. Want a free demo?</div>
                    <div class="f-qr-row">
                        <span class="f-qr">&#x1F4C5; Book Demo</span>
                        <span class="f-qr">&#x1F4B0; Pricing</span>
                    </div>
                    <div class="f-meta">10:44 AM</div>
                </div>
            </div>

            <!-- Healthcare Chat -->
            <div class="f-wa-chat bd-panel" id="bd-health">
                <div class="f-chat-date">Today</div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Appointment</div>
                    <div class="f-meta">11:05 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">
                        <span class="f-bot-label">MedBot</span>
                        &#x1FA7A; Hi Rahul! Which department do you need?
                    </div>
                    <div class="f-qr-row">
                        <span class="f-qr">General</span>
                        <span class="f-qr">Dental</span>
                        <span class="f-qr">Ortho</span>
                    </div>
                    <div class="f-meta">11:05 AM</div>
                </div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Dental</div>
                    <div class="f-meta">11:06 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-list-card">
                    <div class="f-list-head">&#x1F9B7; Available Slots</div>
                    <div class="f-list-row">Dr. Priya &mdash; Tue 10:00 AM</div>
                    <div class="f-list-row">Dr. Arjun &mdash; Wed 2:00 PM</div>
                    <div class="f-list-row">Dr. Meena &mdash; Thu 4:30 PM</div>
                    <div class="f-meta" style="padding:6px 10px 4px;">11:06 AM</div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">&#x2705; Dr. Priya is available <strong>Tue 10 AM</strong>. Confirm booking?</div>
                    <div class="f-qr-row">
                        <span class="f-qr">&#x2705; Confirm</span>
                        <span class="f-qr">&#x1F4C5; Other Slots</span>
                    </div>
                    <div class="f-meta">11:07 AM</div>
                </div>
            </div>

            <!-- Real Estate Chat -->
            <div class="f-wa-chat bd-panel" id="bd-realty">
                <div class="f-chat-date">Today</div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">3BHK Flat</div>
                    <div class="f-meta">2:15 PM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">
                        <span class="f-bot-label">PropBot</span>
                        &#x1F3E0; Great! Which area are you looking in?
                    </div>
                    <div class="f-qr-row">
                        <span class="f-qr">Bandra</span>
                        <span class="f-qr">Powai</span>
                        <span class="f-qr">Andheri</span>
                    </div>
                    <div class="f-meta">2:15 PM</div>
                </div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Powai</div>
                    <div class="f-meta">2:16 PM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-list-card">
                    <div class="f-list-head">&#x1F4CB; Powai Listings</div>
                    <div class="f-list-row">Hiranandani &mdash; <span class="f-price">&#8377;1.5 Cr</span></div>
                    <div class="f-list-row">Seasons Ave &mdash; <span class="f-price">&#8377;1.2 Cr</span></div>
                    <div class="f-list-row">Green Valley &mdash; <span class="f-price">&#8377;98 L</span></div>
                    <div class="f-meta" style="padding:6px 10px 4px;">2:16 PM</div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">&#x1F4DE; Want to speak to an agent or schedule a site visit?</div>
                    <div class="f-qr-row">
                        <span class="f-qr">&#x1F4DE; Call Agent</span>
                        <span class="f-qr">&#x1F3D7; Site Visit</span>
                    </div>
                    <div class="f-meta">2:17 PM</div>
                </div>
            </div>

            <!-- Education Chat -->
            <div class="f-wa-chat bd-panel" id="bd-edu">
                <div class="f-chat-date">Today</div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Courses</div>
                    <div class="f-meta">9:30 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">
                        <span class="f-bot-label">EduBot</span>
                        &#x1F393; Hi! Choose your area of interest:
                    </div>
                    <div class="f-qr-row">
                        <span class="f-qr">Web Dev</span>
                        <span class="f-qr">Data Science</span>
                        <span class="f-qr">Design</span>
                    </div>
                    <div class="f-meta">9:30 AM</div>
                </div>
                <div class="f-chat-row f-out">
                    <div class="f-bubble">Web Dev</div>
                    <div class="f-meta">9:31 AM <span class="f-ticks">&#10003;&#10003;</span></div>
                </div>
                <div class="f-list-card">
                    <div class="f-list-head">&#x1F4BB; Full Stack Program</div>
                    <div class="f-list-row">Duration: 6 Months</div>
                    <div class="f-list-row">Fee: <span class="f-price">&#8377;24,999</span></div>
                    <div class="f-list-row">Next Batch: July 15</div>
                    <div class="f-meta" style="padding:6px 10px 4px;">9:31 AM</div>
                </div>
                <div class="f-chat-row f-in">
                    <div class="f-bubble">&#x26A1; <strong>Only 12 seats left!</strong> Ready to enroll or want the brochure?</div>
                    <div class="f-qr-row">
                        <span class="f-qr">&#x1F4DD; Enroll Now</span>
                        <span class="f-qr">&#x1F4CB; Brochure</span>
                    </div>
                    <div class="f-meta">9:32 AM</div>
                </div>
            </div>

            <!-- Input bar -->
            <div class="f-wa-inputbar">
                <button class="f-wa-mic">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>
                </button>
                <input class="f-wa-field" placeholder="Type a message..." readonly>
                <button class="f-wa-send">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>
                </button>
            </div>

        </div><!-- /f-wa-phone -->
        <div class="f-phone-glow"></div>
    </div>

</div><!-- /bot-builder-grid -->
</section>

<script>
(function(){
    // Step entrance animation
    document.querySelectorAll('.bb-step').forEach(function(el){
        var obs=new IntersectionObserver(function(entries){
            entries.forEach(function(e){
                if(e.isIntersecting){
                    var d=parseInt(e.target.getAttribute('data-bd'))||0;
                    setTimeout(function(){e.target.classList.add('bb-in');},d);
                    obs.unobserve(e.target);
                }
            });
        },{threshold:0.25});
        obs.observe(el);
    });

    // Domain switcher
    var botNames={ecomm:'ShopBot',health:'MedBot',realty:'PropBot',edu:'EduBot'};
    var botAv={ecomm:'&#x1F916;',health:'&#x1FA7A;',realty:'&#x1F3E0;',edu:'&#x1F393;'};
    document.querySelectorAll('.bd-tab').forEach(function(tab){
        tab.addEventListener('click',function(){
            var domain=this.getAttribute('data-domain');
            document.querySelectorAll('.bd-tab').forEach(function(t){t.classList.remove('active');});
            this.classList.add('active');
            document.querySelectorAll('.bd-panel').forEach(function(p){p.classList.remove('bd-active');});
            var panel=document.getElementById('bd-'+domain);
            if(panel){panel.classList.add('bd-active');panel.scrollTop=panel.scrollHeight;}
            var nameEl=document.getElementById('bd-botname');
            var avEl=document.getElementById('bd-av');
            if(nameEl)nameEl.textContent=botNames[domain]||'Waamini Bot';
            if(avEl)avEl.innerHTML=botAv[domain]||'&#x1F916;';
        });
    });

    // Auto-scroll each panel to bottom on load
    document.querySelectorAll('.bd-panel').forEach(function(p){
        p.scrollTop=p.scrollHeight;
    });
})();
</script>

<!-- SHOWCASE: QR CODE -->
<section class="showcase-section">
    <div class="showcase-row reverse">
        <div class="showcase-content">
            <span class="tag">QR Code Generation</span>
            <div class="title-wrap"><h2>Scan to Start a Conversation</h2></div>
            <p>Generate QR codes for your WhatsApp number instantly. Customers scan the code to initiate a conversation — perfect for in-store displays, marketing materials, and events.</p>
            <ul class="check-list">
                <li>Instant QR code generation</li>
                <li>Customizable welcome messages</li>
                <li>Print-ready high-resolution output</li>
                <li>Track scans and conversions</li>
            </ul>
        </div>
        <div class="showcase-visual">
            <div class="visual-card">
                <span class="icon-large"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg></span>
                <div class="metric">1-Tap</div>
                <div class="metric-label">Customer connection via QR scan</div>
            </div>
        </div>
    </div>
</section>

<!-- API CAPABILITIES -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">WhatsApp Cloud API</div>
            <div class="title-wrap"><h2>Complete API Capabilities</h2></div>
            <p>Built on the official WhatsApp Cloud API for reliability, security, and scalability.</p>
        </div>
        <div class="api-table-wrapper">
            <table class="api-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Text Messages</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Media (Image / Video / Document)</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Interactive Buttons & Lists</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Template Messages (HSM)</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Chatbot Integration</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Delivery & Read Receipts</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Product Catalog / Shopping</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>Broadcast Campaigns</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Template Only</td></tr>
                    <tr><td>End-to-End Encryption</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Built-in</td></tr>
                    <tr><td>Webhook Events</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Real-time</td></tr>
                    <tr><td>Multi-Agent Support</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                    <tr><td>AI Bot Integration (Flowise)</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Supported</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- MEDIA SUPPORT -->
<section class="section" style="background: #f8fafc;">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">Media Support</div>
            <div class="title-wrap"><h2>Rich Media Messaging</h2></div>
            <p>Send images, videos, documents, audio, and stickers to create engaging conversations.</p>
        </div>
        <div class="feature-grid" style="max-width: 800px;">
            <div class="feature-card" data-anim>
                <div class="card-icon purple"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></div>
                <h3>Images</h3>
                <p>JPG & PNG up to 5 MB. Perfect for product showcases and visual marketing.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon blue"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg></div>
                <h3>Videos</h3>
                <p>MP4 & 3GPP up to 16 MB. Share tutorials, demos, and promotional content.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon green"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></div>
                <h3>Documents</h3>
                <p>PDF, DOCX & more up to 100 MB. Send invoices, catalogs, and brochures.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon orange"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
                <h3>Audio</h3>
                <p>AAC, MP4, OGG up to 16 MB. Voice messages and audio content delivery.</p>
            </div>
        </div>
    </div>
</section>

<!-- MESSAGE TRACKING -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <div class="section-badge">Real-time Tracking</div>
            <div class="title-wrap"><h2>Know Exactly What's Happening</h2></div>
            <p>Track every message from send to read with real-time webhook events.</p>
        </div>
        <div class="feature-grid" style="max-width: 1000px;">
            <div class="feature-card" data-anim>
                <div class="card-icon blue"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg></div>
                <h3>Sent</h3>
                <p>Message dispatched from WAAMINI to WhatsApp servers successfully.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon green"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
                <h3>Delivered</h3>
                <p>Message reached the recipient's phone. Double grey ticks confirmed.</p>
            </div>
            <div class="feature-card" data-anim>
                <div class="card-icon purple"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></div>
                <h3>Read</h3>
                <p>Recipient opened and read the message. Double blue ticks confirmed.</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="container">
        <div class="title-wrap"><h2>Ready to Transform Your Business?</h2></div>
        <p>Join businesses already growing with WAAMINI's WhatsApp automation platform.</p>
        <a href="register.php" class="btn-cta" aria-label="Start your free Waamini trial and explore features"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg> Get Started Free</a>
    </div>
</section>

<!-- 7digit-system port: same Lenis + GSAP ScrollTrigger reveal as the homepage -->
<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="assets/js/lp-theme.js?v=5" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>

<?php include 'includes/footer.php'; ?>

<!-- SCROLL ANIMATION SCRIPT -->
<script>
// Defer non-critical effects until after full load (improves LCP)
window.addEventListener('load', function() {
    document.documentElement.classList.add('fx-ready');

    const cards = document.querySelectorAll('[data-anim]');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (!entry.isIntersecting) return;
            setTimeout(() => entry.target.classList.add('visible'), index * 80);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    cards.forEach(card => observer.observe(card));
});
</script>
