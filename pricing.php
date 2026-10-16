<?php
/* ── Plans come from the Landing CMS (Pricing Plans); built-in plans are the fallback ── */
if (!defined('WAAMINI_LOADED')) define('WAAMINI_LOADED', true);
$lp_plans = [];
try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/includes/cms_pricing.php';
    $lp_plans = cms_price_plans(isset($conn) && $conn instanceof mysqli ? $conn : null);
} catch (Throwable $lp_e) { require_once __DIR__ . '/includes/cms_pricing.php'; $lp_plans = cms_price_default_plans(); }
$lp_icons = cms_price_icons();
$lp_sym_map = ['INR'=>'₹','USD'=>'$','EUR'=>'€','GBP'=>'£'];
$lp_esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$page_title = "Simple & Transparent Pricing Plans";
$meta_desc = "Get the most out of WhatsApp Business API with Waamini's flexible pricing. Choose from Starter, Growth, or Enterprise plans with zero message markup.";
$meta_keywords = "WhatsApp API Pricing India, Waamini Subscription Plans, WhatsApp CRM Cost, Cheap WhatsApp Business API";
$asyncStylesheets = [
  'assets/css/pricing.css?v=3g',
  'assets/css/landing-neo.css?v=15g',
];
$bodyClass = 'lp-neo';
$pageCriticalCss = <<<'CSS'
/* Critical CSS: pricing hero (fast LCP) */
.pricing-hero{padding:100px 0 40px;text-align:center;position:relative;overflow:hidden;background:#f8fafc}
.pricing-hero::before{content:'';position:absolute;top:-120px;left:50%;transform:translateX(-50%);width:800px;height:400px;background:rgba(108,92,231,.10)}
.pricing-hero .badge-label{display:inline-block;padding:8px 20px;background:rgba(108,92,231,.15);border:1px solid rgba(108,92,231,.3);border-radius:50px;color:var(--primary);font-size:13px;font-weight:600;margin-bottom:20px}
.pricing-hero h1{font-size:46px;font-weight:800;letter-spacing:-1.5px;margin-bottom:16px;color:#fff}
.pricing-hero p{font-size:17px;color:var(--text-secondary);max-width:550px;margin:0 auto 36px;line-height:1.6}
@media(max-width:768px){.pricing-hero h1{font-size:32px}}
CSS;
include 'includes/header.php'; 
?>
<!-- pricing.css loaded async via header hook -->

<!-- PRICING & FAQ SCHEMA -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "SoftwareApplication",
      "name": "Waamini CRM",
      "applicationCategory": "BusinessApplication",
      "operatingSystem": "Web-based",
      "offers": {
        "@type": "AggregateOffer",
        "priceCurrency": "INR",
        "lowPrice": "999",
        "highPrice": "19990",
        "offerCount": "3"
      }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [{
        "@type": "Question",
        "name": "Can I switch plans anytime?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Yes! You can upgrade or downgrade your plan at any time. Changes take effect immediately and are prorated."
        }
      }, {
        "@type": "Question",
        "name": "Is there a free trial available?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "We offer a 7-day free trial on the Starter plan. No credit card required. Start building your campaigns right away!"
        }
      }]
    }
  ]
}
</script>

<!-- HERO -->
<section class="pricing-hero">
    <div class="container">
        <div class="badge-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Transparent Pricing</div>
        <div class="title-wrap"><h1>Simple Pricing, Powerful Features</h1></div>
        <p>Choose the perfect plan for your business. No hidden fees, no surprises. Scale as you grow.</p>

        <!-- Monthly / Yearly Toggle -->
        <div class="pricing-toggle">
            <span class="toggle-label active" id="label-monthly" onclick="setPricing('monthly')">Monthly</span>
            <div class="toggle-switch" id="toggleSwitch" onclick="togglePricing()">
                <div class="toggle-knob"></div>
            </div>
            <span class="toggle-label" id="label-yearly" onclick="setPricing('yearly')">Yearly</span>
            <span class="save-badge">Save up to 20%</span>
        </div>
    </div>
</section>

<!-- PRICING CARDS -->
<section class="pricing-section">
    <div class="container">
        <div class="pricing-grid">

            <?php foreach ($lp_plans as $lp_i => $lp_plan):
                $lp_idx  = $lp_i + 1;
                $lp_sym  = $lp_sym_map[$lp_plan['currency'] ?? 'INR'] ?? '₹';
                $lp_pm   = (int)$lp_plan['price_monthly'];
                $lp_ex   = $lp_plan['extra'] ?? [];
                $lp_list = (int)($lp_ex['list_price'] ?? 0);
                $lp_pop  = !empty($lp_plan['is_popular']);
                $lp_rib  = trim((string)($lp_ex['ribbon_monthly'] ?? ''));
                $lp_cta_class = $lp_pop ? 'cta-fire' : ($lp_i === count($lp_plans) - 1 ? 'cta-gradient' : 'cta-outline');
                $lp_cta_icon = $lp_icons[$lp_ex['cta_icon'] ?? ''] ?? '';
            ?>
            <div class="pricing-card <?php echo $lp_pop ? 'featured' : ''; ?>">
                <?php if ($lp_pop): ?>
                <div class="recommended-badge"><?php echo $lp_icons['fire']; ?> MOST POPULAR</div>
                <?php endif; ?>
                <div class="discount-ribbon" id="ribbon<?php echo $lp_idx; ?>"<?php echo $lp_rib === '' ? ' style="display:none"' : ''; ?>><?php echo $lp_esc($lp_rib); ?></div>
                <div class="plan-header">
                    <div class="plan-name"><?php echo $lp_esc($lp_plan['name']); ?></div>
                    <?php if (!empty($lp_plan['tagline'])): ?><div class="plan-tagline"><?php echo $lp_esc($lp_plan['tagline']); ?></div><?php endif; ?>
                </div>
                <div class="plan-price">
                    <span class="original-price" id="orig<?php echo $lp_idx; ?>"><?php echo $lp_list > $lp_pm ? $lp_esc($lp_sym . number_format($lp_list) . '/mo') : ''; ?></span>
                    <div class="current-price">
                        <span class="currency"><?php echo $lp_esc($lp_sym); ?></span><span id="price<?php echo $lp_idx; ?>"><?php echo number_format($lp_pm, 0, '.', ','); ?></span><span class="period" id="period<?php echo $lp_idx; ?>">/mo</span>
                    </div>
                    <span class="yearly-note" id="note<?php echo $lp_idx; ?>">Billed monthly</span>
                </div>
                <ul class="plan-features">
                    <?php foreach ($lp_plan['features'] as $lp_feat): [$lp_fi, $lp_ft] = cms_price_feature($lp_feat); ?>
                    <li><span class="feat-icon"><?php echo $lp_icons[$lp_fi]; ?></span> <?php echo $lp_esc($lp_ft); ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?php echo $lp_esc($lp_plan['cta_url'] ?: 'register.php'); ?>" class="plan-cta <?php echo $lp_cta_class; ?>"><?php echo $lp_cta_icon . ($lp_cta_icon ? ' ' : ''); ?><?php echo $lp_esc($lp_plan['cta_text'] ?: 'Get Started'); ?></a>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<!-- WHATSAPP MESSAGE PRICING - PREMIUM SECTION -->
<section class="wa-msg-pricing-section">
    <div class="wa-mp-orb wa-mp-orb-1"></div>
    <div class="wa-mp-orb wa-mp-orb-2"></div>
    <div class="container">
        <div class="wa-mp-grid">

            <!-- LEFT: Heading + Conversation Type Cards -->
            <div class="wa-mp-left">
                <div class="wa-mp-badge">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                    Zero Markup &mdash; Pay Meta Rates Directly
                </div>
                <div class="title-wrap"><h2 class="wa-mp-title">WhatsApp Official<br><span class="wa-mp-title-accent">Message Pricing</span></h2></div>
                <p class="wa-mp-desc">Waamini is a certified direct provider. We charge <strong>&#8377;0.00 markup</strong> &mdash; you pay only Meta's official conversation-based rates.</p>

                <div class="wa-conv-types">
                    <div class="wa-conv-type wct-marketing">
                        <div class="wct-icon-wrap">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        </div>
                        <div class="wct-body">
                            <div class="wct-name">Marketing</div>
                            <div class="wct-desc">Promos, campaigns &amp; offers</div>
                        </div>
                        <span class="wct-tag">High ROI</span>
                    </div>
                    <div class="wa-conv-type wct-utility">
                        <div class="wct-icon-wrap">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div class="wct-body">
                            <div class="wct-name">Utility</div>
                            <div class="wct-desc">Order updates &amp; alerts</div>
                        </div>
                        <span class="wct-tag">Transactional</span>
                    </div>
                    <div class="wa-conv-type wct-auth">
                        <div class="wct-icon-wrap">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </div>
                        <div class="wct-body">
                            <div class="wct-name">Authentication</div>
                            <div class="wct-desc">OTPs &amp; verification codes</div>
                        </div>
                        <span class="wct-tag">Secure</span>
                    </div>
                    <div class="wa-conv-type wct-service">
                        <div class="wct-icon-wrap">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>
                        </div>
                        <div class="wct-body">
                            <div class="wct-name">Service</div>
                            <div class="wct-desc">Customer support chats</div>
                        </div>
                        <span class="wct-tag">1K Free/mo</span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Stats + Tip Card -->
            <div class="wa-mp-right">
                <div class="wa-mp-tip-card">
                    <div class="wa-mp-tip-top">
                        <div class="wa-mp-tip-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                        </div>
                        <div>
                            <div class="wa-mp-tip-eyebrow">Meta's Free Tier</div>
                            <div class="wa-mp-tip-heading">1,000 Free Conversations</div>
                        </div>
                    </div>
                    <p class="wa-mp-tip-body">Every WhatsApp Business account gets <strong>1,000 free service conversations</strong> every month from Meta &mdash; at zero cost to you.</p>

                    <div class="wa-mp-stats-row">
                        <div class="wa-mp-stat">
                            <div class="wa-mp-stat-val">&#8377;0</div>
                            <div class="wa-mp-stat-lbl">Our Markup</div>
                        </div>
                        <div class="wa-mp-stat-div"></div>
                        <div class="wa-mp-stat">
                            <div class="wa-mp-stat-val">1,000</div>
                            <div class="wa-mp-stat-lbl">Free / Month</div>
                        </div>
                        <div class="wa-mp-stat-div"></div>
                        <div class="wa-mp-stat">
                            <div class="wa-mp-stat-val">Direct</div>
                            <div class="wa-mp-stat-lbl">Meta API</div>
                        </div>
                    </div>

                    <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing" target="_blank" class="wa-mp-cta-btn">
                        View Official Price List
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                    </a>
                </div>
            </div>

        </div>
    </div>
</section>

<style>
/* ============ WA MESSAGE PRICING SECTION ============ */
.wa-msg-pricing-section {
    position: relative;
    padding: 80px 0 90px;
    background: linear-gradient(135deg, #060c1f 0%, #0a1230 40%, #070d24 100%);
    overflow: hidden;
}
.wa-mp-orb {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}
.wa-mp-orb-1 {
    width: 600px; height: 600px;
    top: -180px; left: -140px;
    background: radial-gradient(circle, rgba(37,211,102,0.12) 0%, transparent 70%);
}
.wa-mp-orb-2 {
    width: 450px; height: 450px;
    bottom: -120px; right: -100px;
    background: radial-gradient(circle, rgba(79,172,254,0.10) 0%, transparent 70%);
}
.wa-mp-grid {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 64px;
    align-items: center;
    position: relative;
    z-index: 2;
}
.wa-mp-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    background: rgba(37,211,102,0.12);
    border: 1px solid rgba(37,211,102,0.30);
    color: #128C7E;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.3px;
    margin-bottom: 22px;
}
.wa-mp-title {
    font-size: clamp(28px, 4vw, 42px);
    font-weight: 800;
    color: #f1f5f9;
    line-height: 1.15;
    letter-spacing: -1px;
    margin-bottom: 16px;
}
.wa-mp-title-accent {
    background: linear-gradient(90deg, #25D366, #7DF0BC);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.wa-mp-desc { font-size: 15px; color: #94a3b8; line-height: 1.75; margin-bottom: 36px; max-width: 480px; }
.wa-mp-desc strong { color: #128C7E; font-weight: 700; }
.wa-conv-types { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.wa-conv-type {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    border: 1px solid rgba(37,211,102,0.12);
    background: rgba(37,211,102,0.04);
    backdrop-filter: blur(8px);
    transition: all 0.3s ease;
}
.wa-conv-type:hover { transform: translateY(-3px); border-color: rgba(37,211,102,0.28); background: rgba(37,211,102,0.08); box-shadow: 0 8px 24px rgba(37,211,102,0.12); }
.wct-icon-wrap { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wct-marketing .wct-icon-wrap { background: linear-gradient(135deg,#f59e0b,#d97706); color:#fff; }
.wct-utility   .wct-icon-wrap { background: linear-gradient(135deg,#25D366,#128C7E); color:#fff; }
.wct-auth      .wct-icon-wrap { background: linear-gradient(135deg,#25D366,#128C7E); color:#fff; }
.wct-service   .wct-icon-wrap { background: linear-gradient(135deg,#128C7E,#128C7E); color:#fff; }
.wct-body { flex: 1; min-width: 0; }
.wct-name { font-size: 14px; font-weight: 700; color: #f1f5f9; line-height: 1.3; }
.wct-desc { font-size: 12px; color: #64748b; margin-top: 2px; }
.wct-tag { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 100px; white-space: nowrap; letter-spacing: 0.3px; }
.wct-marketing .wct-tag { background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
.wct-utility   .wct-tag { background: rgba(37,211,102,0.14); color: #128C7E; border: 1px solid rgba(37,211,102,0.25); }
.wct-auth      .wct-tag { background: rgba(37,211,102,0.12); color: #128C7E; border: 1px solid rgba(37,211,102,0.2); }
.wct-service   .wct-tag { background: rgba(8,145,178,0.12); color: #7DF0BC; border: 1px solid rgba(8,145,178,0.22); }
.wa-mp-right { position: relative; }
.wa-mp-tip-card {
    background: rgba(37,211,102,0.05);
    border: 1px solid rgba(37,211,102,0.18);
    border-radius: 24px;
    padding: 32px;
    backdrop-filter: blur(12px);
    position: relative;
    overflow: hidden;
}
.wa-mp-tip-card::before {
    content: ';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, #25D366, #7DF0BC, #7DF0BC);
}
.wa-mp-tip-top { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 20px; }
.wa-mp-tip-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, rgba(37,211,102,0.2), rgba(79,172,254,0.15));
    border: 1px solid rgba(37,211,102,0.30);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #128C7E; flex-shrink: 0;
}
.wa-mp-tip-eyebrow { font-size: 11px; font-weight: 700; color: #128C7E; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
.wa-mp-tip-heading { font-size: 18px; font-weight: 800; color: #f1f5f9; line-height: 1.2; }
.wa-mp-tip-body { font-size: 14px; color: #94a3b8; line-height: 1.65; margin-bottom: 24px; }
.wa-mp-tip-body strong { color: #B3F5D9; font-weight: 700; }
.wa-mp-stats-row {
    display: flex; align-items: center;
    background: rgba(37,211,102,0.06);
    border: 1px solid rgba(37,211,102,0.14);
    border-radius: 14px;
    padding: 16px 0;
    margin-bottom: 24px;
}
.wa-mp-stat { flex: 1; text-align: center; padding: 0 12px; }
.wa-mp-stat-val { font-size: 22px; font-weight: 800; color: #128C7E; line-height: 1.1; margin-bottom: 4px; }
.wa-mp-stat-lbl { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.wa-mp-stat-div { width: 1px; height: 36px; background: rgba(37,211,102,0.18); flex-shrink: 0; }
.wa-mp-cta-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 14px 20px;
    background: linear-gradient(135deg, #25D366, #25D366);
    color: #fff; font-size: 14px; font-weight: 700;
    border-radius: 12px; text-decoration: none;
    transition: all 0.25s ease;
    box-shadow: 0 4px 20px rgba(37,211,102,0.35);
}
.wa-mp-cta-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(37,211,102,0.50); color: #fff; background: linear-gradient(135deg, #128C7E, #128C7E); }
@media (max-width: 900px) {
    .wa-mp-grid { grid-template-columns: 1fr; gap: 40px; }
    .wa-mp-right { max-width: 520px; margin: 0 auto; width: 100%; }
}
@media (max-width: 600px) {
    .wa-conv-types { grid-template-columns: 1fr; }
    .wa-msg-pricing-section { padding: 60px 0; }
}
</style>

<!-- DETAILED BOT COMPARISON -->
<section class="section" style="background: #f8fafc; padding: 80px 0;">
    <div class="container">
        <div class="section-header" style="text-align:center; margin-bottom:60px;">
            <div class="title-wrap"><h2 style="font-size:32px; color:#0F172A;">Bot Automation & Intelligence</h2></div>
            <p style="color:#64748B;">Detailed comparison of automation capabilities across plans.</p>
        </div>
        <div class="api-table-wrapper" style="max-width:1000px;">
            <table class="api-table">
                <thead>
                    <tr>
                        <th>Feature Depth</th>
                        <th>Starter</th>
                        <th>Professional</th>
                        <th>Enterprise</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Keyword Auto-Replies</td><td>50 Keywords</td><td>Unlimited</td><td>Unlimited + Fuzzy Match</td></tr>
                    <tr><td>Visual Flow Builder</td><td>Basic (3 Steps)</td><td>Advanced (Multi-path)</td><td>Recursive Flow Loops</td></tr>
                    <tr><td>Dynamic Variables</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> No</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> {name} only</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Full Metadata Injection</td></tr>
                    <tr><td>Interactive Buttons</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> 3 per msg</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> 3 per msg</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Buttons + List Menus</td></tr>
                    <tr><td>Webhook Integration</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> No</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> 5 Endpoints</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Unlimited + Auth</td></tr>
                    <tr><td>AI/NLP Integration</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> No</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> No</td><td><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Flowise/GPT Support</td></tr>
                    <tr><td>Human Handover</td><td>Manual</td><td>Smart Routing</td><td>Sentiment-based Auto</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="faq-section container">
    <div class="title-wrap"><h2><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg> Frequently Asked Questions</h2></div>

    <div class="faq-item open">
        <div class="faq-question" onclick="toggleFaq(this)">
            Can I switch plans anytime?
            <span class="faq-arrow">▾</span>
        </div>
        <div class="faq-answer">
            <p>Yes! You can upgrade or downgrade your plan at any time. Changes take effect immediately and are prorated.</p>
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question" onclick="toggleFaq(this)">
            What payment methods do you accept?
            <span class="faq-arrow">▾</span>
        </div>
        <div class="faq-answer">
            <p>We accept all major credit/debit cards, UPI, Net Banking, and PayPal. All payments are secured with 256-bit encryption.</p>
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question" onclick="toggleFaq(this)">
            Do I need a Meta Business Account?
            <span class="faq-arrow">▾</span>
        </div>
        <div class="faq-answer">
            <p>Yes, WhatsApp Business API requires a verified Meta Business Account. We help you set it up — our Embedded Signup makes it simple.</p>
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question" onclick="toggleFaq(this)">
            Is there a free trial available?
            <span class="faq-arrow">▾</span>
        </div>
        <div class="faq-answer">
            <p>We offer a 7-day free trial on the Starter plan. No credit card required. Start building your campaigns right away!</p>
        </div>
    </div>

    <div class="faq-item">
        <div class="faq-question" onclick="toggleFaq(this)">
            What happens when I exceed my contact limit?
            <span class="faq-arrow">▾</span>
        </div>
        <div class="faq-answer">
            <p>You can use Message Add-Ons on a pay-as-you-go basis, or upgrade to a higher plan for more capacity.</p>
        </div>
    </div>
</section>

<!-- CTA BOTTOM -->
<section style="text-align:center; padding: 60px 20px 100px; background: linear-gradient(135deg, #E6FCF2, #ffffff, #E6FCF2); position: relative;">
    <div class="title-wrap"><h2 style="font-size: 36px; font-weight: 800; color: var(--text-primary); margin-bottom: 14px;">Still Have Questions?</h2></div>
    <p style="color: var(--text-secondary); font-size: 16px; margin-bottom: 28px;">Our team is here to help you find the right plan for your business.</p>
    <a href="register.php" style="display:inline-flex; align-items:center; gap:10px; padding:16px 36px; background:linear-gradient(135deg, var(--primary), var(--secondary)); color:#fff; border-radius:14px; font-weight:700; font-size:16px; text-decoration:none; box-shadow:0 8px 30px rgba(108,92,231,0.3); transition:all 0.3s;" aria-label="Get started with Waamini for free"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg> Get Started Free</a>
</section>

<!-- 7digit-system port: same Lenis + GSAP ScrollTrigger reveal as the homepage -->
<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="assets/js/lp-theme.js?v=4" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>

<?php include 'includes/footer.php'; ?>

<script>
let isYearly = false;

<?php
    $lp_js_data = array_map(function($p) use ($lp_sym_map) {
        $ex = $p['extra'] ?? [];
        $pm = (int)$p['price_monthly']; $py = (int)$p['price_yearly'];
        return ['pm'=>$pm,'py'=>$py,'sym'=>$lp_sym_map[$p['currency'] ?? 'INR'] ?? '₹','list'=>(int)($ex['list_price'] ?? 0),
                'rm'=>trim((string)($ex['ribbon_monthly'] ?? '')),'ry'=>trim((string)($ex['ribbon_yearly'] ?? ''))];
    }, $lp_plans);
?>
const lpPlans = <?php echo json_encode(array_values($lp_js_data), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

function togglePricing() { isYearly = !isYearly; updatePricing(); }
function setPricing(type)  { isYearly = (type === 'yearly'); updatePricing(); }
function fmtNum(n) { return n.toLocaleString('en-IN'); }

function updatePricing() {
    const toggle = document.getElementById('toggleSwitch');
    if (isYearly) {
        toggle.classList.add('yearly');
        document.getElementById('label-yearly').classList.add('active');
        document.getElementById('label-monthly').classList.remove('active');
    } else {
        toggle.classList.remove('yearly');
        document.getElementById('label-monthly').classList.add('active');
        document.getElementById('label-yearly').classList.remove('active');
    }
    lpPlans.forEach(function(plan, i) {
        const idx = i + 1, sym = plan.sym;
        const pEl = document.getElementById('price' + idx), oEl = document.getElementById('orig' + idx);
        const perEl = document.getElementById('period' + idx), nEl = document.getElementById('note' + idx), rEl = document.getElementById('ribbon' + idx);
        if (!pEl) return;
        const useYear = isYearly && plan.py > 0;
        const ribbon = useYear ? plan.ry : plan.rm;
        const was = useYear ? plan.list * 12 : plan.list;
        pEl.textContent = fmtNum(useYear ? plan.py : plan.pm);
        if (perEl) perEl.textContent = useYear ? '/yr' : '/mo';
        if (oEl) oEl.textContent = (was > (useYear ? plan.pm * 12 : plan.pm)) ? sym + fmtNum(was) + (useYear ? '/yr' : '/mo') : '';
        if (nEl) nEl.textContent = useYear ? 'Save ' + sym + fmtNum(plan.pm * 12 - plan.py) + ' — Get 12 months for ' + sym + fmtNum(plan.py) : 'Billed monthly';
        if (rEl) { rEl.textContent = ribbon; rEl.style.display = ribbon ? '' : 'none'; }
    });
}

// FAQ
function toggleFaq(el) {
    const item = el.parentElement;
    const allItems = document.querySelectorAll('.faq-item');
    allItems.forEach(i => {
        if (i !== item) i.classList.remove('open');
    });
    item.classList.toggle('open');
}
</script>
