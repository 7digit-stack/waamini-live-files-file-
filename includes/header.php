<?php
/**
 * Global Header - Waamini WhatsApp CRM
 * Modernized for technical SEO, rich results, and analytics.
 */

// Full-page cache for anonymous public pages (BEFORE session_start for speed).
// If a session cookie exists, we skip early caching to avoid serving a cached
// "logged-out" navbar to logged-in users (and to avoid session file locking).
include_once __DIR__ . '/page_cache.php';
if ((!isset($disablePublicPageCache) || $disablePublicPageCache !== true) && PHP_SAPI !== 'cli') {
    $hasSessionCookie = false;
    try {
        $sn = session_name();
        $hasSessionCookie = ($sn !== '' && isset($_COOKIE[$sn]));
    } catch (Throwable $e) {
        $hasSessionCookie = false;
    }
    if (!$hasSessionCookie) {
        $ttl = (int)(getenv('PUBLIC_PAGE_CACHE_TTL') ?: 60);
        wa_page_cache_start(max(10, $ttl));
    }
}

// Initialize session + env + security headers (only after cache attempt).
include_once __DIR__ . '/../config/bootstrap.php';
include_once __DIR__ . '/security.php';

// Landing CMS: site name, analytics id, share image and contact details are editable in the CMS (Site Settings)
if (!defined('WAAMINI_LOADED')) define('WAAMINI_LOADED', true);
try { include_once __DIR__ . '/../db.php'; include_once __DIR__ . '/cms.php'; } catch (Throwable $cmsE) { error_log('[CMS] ' . $cmsE->getMessage()); }
$cmsOn = function_exists('cms_setting');

// Default SEO variables (can be overridden in individual pages)
$site_name = $cmsOn ? cms_setting('site_name', 'Waamini') : "Waamini";
$page_title = isset($page_title) ? $page_title . " | " . $site_name : $site_name . " - WhatsApp Business API CRM Platform";
$meta_desc = isset($meta_desc) ? $meta_desc : "Waamini is India's most powerful WhatsApp Business API CRM. Automate marketing, manage leads, and drive growth with 24/7 AI chatbots and smart integrations.";
$meta_keywords = isset($meta_keywords) ? $meta_keywords : "WhatsApp API, WhatsApp CRM, WhatsApp Marketing, WhatsApp Automation, Meta Business Partner, AI Chatbot, WhatsApp Business API India";
$canonical_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

// Clean up GA_MEASUREMENT_ID placeholder - user will replace this
$ga_id = $cmsOn ? cms_setting('ga_id', "G-P8R67L3E2W") : "G-P8R67L3E2W"; // set in the CMS (Site Settings)
$isLocalhost = in_array(($_SERVER['HTTP_HOST'] ?? ''), ['localhost', '127.0.0.1'], true)
  || str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost:')
  || str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1:');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- PRIMARY SEO -->
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $meta_desc; ?>">
    <meta name="keywords" content="<?php echo $meta_keywords; ?>">
    <link rel="canonical" href="<?php echo $canonical_url; ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#25D366">

    <!-- OPEN GRAPH / FACEBOOK -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $canonical_url; ?>">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $meta_desc; ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($cmsOn ? cms_setting('og_image', 'assets/img/og-image.png') : 'assets/img/og-image.png', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($cmsOn && cms_setting('favicon_url') !== ''): ?><link rel="icon" href="<?php echo htmlspecialchars(cms_setting('favicon_url'), ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>

    <!-- TWITTER -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo $canonical_url; ?>">
    <meta property="twitter:title" content="<?php echo $page_title; ?>">
    <meta property="twitter:description" content="<?php echo $meta_desc; ?>">
    <meta property="twitter:image" content="assets/img/og-image.png">

    <!-- GOOGLE ANALYTICS (GA4) -->
    <?php if (!$isLocalhost && !empty($ga_id)): ?>
      <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($ga_id, ENT_QUOTES, 'UTF-8'); ?>"></script>
      <script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo addslashes($ga_id); ?>');
      </script>
    <?php endif; ?>

    <!-- GLOBAL SCHEMA (JSON-LD) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "Organization",
          "@id": "https://waamini.in/#organization",
          "name": "Waamini CRM",
          "url": "https://waamini.in/",
          "logo": {
            "@type": "ImageObject",
            "url": "https://waamini.in/assets/img/logo.png"
          },
          "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "+91-9150802906",
            "contactType": "sales",
            "areaServed": "IN",
            "availableLanguage": ["English", "Tamil", "Hindi"]
          },
          "sameAs": [
            "https://facebook.com/waaminicrm",
            "https://twitter.com/waaminicrm",
            "https://linkedin.com/company/waaminicrm"
          ]
        },
        {
          "@type": "WebSite",
          "@id": "https://waamini.in/#website",
          "url": "https://waamini.in/",
          "name": "Waamini",
          "publisher": {
            "@id": "https://waamini.in/#organization"
          },
          "potentialAction": {
            "@type": "SearchAction",
            "target": "https://waamini.in/search?q={search_term_string}",
            "query-input": "required name=search_term_string"
          }
        }
      ]
    }
    </script>

    <!-- Preconnect to external domains so TCP handshakes happen in parallel with HTML parsing -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@1,800&display=swap">
    <link rel="stylesheet" href="assets/css/navbar-pro.css?v=gx">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">

    <!-- Bootstrap: load async (non-blocking) so it never delays page render -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></noscript>

    <!-- Google Fonts Inter: load async with font-display=swap so text shows immediately -->
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>

    <!-- Marketing/public CSS: skipped on auth pages (login, register) for Lighthouse performance -->
    <?php if (empty($skipMarketingCSS)): ?>
      <?php
        // Prefer single bundled CSS to reduce requests and parse cost.
        $bundle = 'assets/css/public.bundle.min.css?v=10';
      ?>
      <link rel="stylesheet" href="<?php echo htmlspecialchars($bundle, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <?php
      // Page-level stylesheets hook (set $asyncStylesheets in page before include).
      // Loaded as normal blocking stylesheets, not preload+swap: these carry the
      // lp-neo theme (violet palette) that overrides hardcoded-blue defaults above,
      // so async-swapping them causes a visible flash of the old blue UI on every
      // navigation while the file downloads.
      if (!empty($asyncStylesheets) && is_array($asyncStylesheets)):
        foreach ($asyncStylesheets as $href):
          if (!is_string($href) || $href === '') continue;
    ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">
    <?php
        endforeach;
      endif;
    ?>

    <?php
      // Synchronous (render-blocking) stylesheets for small critical CSS (auth pages etc).
      // Prevents FOUC: browser won't paint until these styles are applied.
      if (!empty($syncStylesheets) && is_array($syncStylesheets)):
        foreach ($syncStylesheets as $href):
          if (!is_string($href) || $href === '') continue;
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">
    <?php
        endforeach;
      endif;
    ?>
    
    <style>
    <?php if (!empty($skipMarketingCSS)): ?>
    /* ==================================
       GLOBAL NAVBAR (Fallback for Auth Pages)
       ================================== */
    :root {
      --primary: #128C7E;
      --primary-dark: #0D6B5F;
      --bg-nav: rgba(255, 255, 255, 0.9);
      --text-primary: #0f172a;
      --text-secondary: #64748b;
      --border: rgba(0,0,0,0.06);
      --glow: rgba(0, 109, 249, 0.3);
      --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .main-nav { background: var(--bg-nav); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .container { width: 92%; max-width: 1200px; margin: auto; }
    .nav-inner { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; }
    .logo { display: inline-flex; align-items: center; gap: 11px; text-decoration: none !important; }
    .logo .logo-icon { width: 38px; height: 38px; background: linear-gradient(135deg, #128C7E 0%, #25D366 100%); border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 18px rgba(18,140,126,0.45); }
    .logo-word { font-family: 'Exo 2', 'Inter', sans-serif; font-size: 21px; font-weight: 800; font-style: italic; text-transform: uppercase; letter-spacing: 0.4px; line-height: 1; }
    .logo-wa, .logo-mini { color: #0B3D36; }
    .nav-menu { display: flex; align-items: center; gap: 2px; }
    .nav-menu a { padding: 7px 14px; text-decoration: none; color: #475569; font-weight: 500; font-size: 13.5px; transition: var(--transition); border-radius: 6px; position: relative; }
    .nav-menu a:hover { color: #128C7E; background: rgba(18,140,126,0.05); }
    .nav-login { background: transparent !important; color: #128C7E !important; padding: 7px 18px !important; border-radius: 6px !important; font-weight: 600 !important; font-size: 13.5px !important; border: 1.5px solid rgba(18,140,126,0.2) !important; text-decoration: none !important; transition: var(--transition) !important; margin-left: 6px; }
    .nav-login:hover { border-color: #128C7E !important; background: rgba(18,140,126,0.04) !important; transform: translateY(-1px) !important; }
    .nav-signup { background: linear-gradient(135deg, #128C7E, #0D6B5F) !important; color: #fff !important; padding: 7px 20px !important; border-radius: 6px !important; font-weight: 700 !important; font-size: 13.5px !important; box-shadow: 0 4px 14px rgba(18,140,126,0.3) !important; transition: var(--transition) !important; text-decoration: none !important; margin-left: 6px; }
    .nav-signup:hover { transform: translateY(-2px) !important; box-shadow: 0 6px 20px rgba(18,140,126,0.4) !important; }
    <?php endif; ?>

    /* ==================================
       GLOBAL MOBILE OVERLAY - Pre-render
       ================================== */
    .mobile-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100vh;
        background: rgba(255, 255, 255, 0.98);
        z-index: 1000;
        display: none; flex-direction: column; align-items: center; justify-content: center;
        gap: 20px; opacity: 0; transform: translateY(-20px);
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .mobile-overlay.active { display: flex; opacity: 1; transform: translateY(0); }
    .mobile-link { font-size: 22px; font-weight: 700; color: #0f172a; text-decoration: none; opacity: 0.5; transition: 0.3s; }
    .mobile-link:hover, .mobile-link.active { opacity: 1; color: #128C7E; }
    .mobile-cta {
        padding: 14px 40px; border-radius: 10px;
        background: linear-gradient(135deg, #128C7E, #0D6B5F);
        color: #fff; font-size: 16px; font-weight: 700; margin-top: 16px; text-decoration: none;
        box-shadow: 0 8px 24px rgba(18,140,126,0.3);
    }
    .nav-toggle {
        display: none; flex-direction: column; gap: 5px; cursor: pointer; z-index: 9999 !important;
        width: 28px !important; height: 22px !important; justify-content: center; align-items: center;
    }
    .nav-toggle span {
        display: block; width: 100% !important; height: 2.5px !important; background: #0f172a !important;
        border-radius: 3px !important; transition: 0.3s;
    }
    @media (max-width: 900px) {
        .nav-menu { display: none !important; }
        .nav-toggle { display: flex !important; }
    }
    /* Toggle Animation */
    .nav-toggle.active span:nth-child(1) { transform: translateY(7.5px) rotate(45deg); }
    .nav-toggle.active span:nth-child(2) { opacity: 0; }
    .nav-toggle.active span:nth-child(3) { transform: translateY(-7.5px) rotate(-45deg); }

    /* ==================================
       ALERT BAR — Top announcement
       ================================== */
    .wa-alert-bar {
        position: relative;
        width: 100%;
        padding: 0;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.4;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        z-index: 99;
    }
    .wa-alert-inner {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 9px 16px;
        max-width: 1200px;
        margin: 0 auto;
        flex-wrap: wrap;
        text-align: center;
    }
    .wa-alert-icon {
        font-size: 16px;
        flex-shrink: 0;
    }
    .wa-alert-msg {
        font-size: 13px;
        font-weight: 500;
    }
    .wa-alert-link {
        text-decoration: underline;
        font-weight: 600;
        font-size: 12.5px;
        opacity: 0.9;
        transition: opacity 0.2s;
        flex-shrink: 0;
    }
    .wa-alert-link:hover { opacity: 1; }
    .wa-alert-btn {
        display: inline-block;
        padding: 5px 14px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 12px;
        text-decoration: none;
        transition: transform 0.2s, box-shadow 0.2s;
        flex-shrink: 0;
    }
    .wa-alert-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .wa-alert-countdown {
        font-weight: 600;
        font-size: 12px;
        opacity: 0.9;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .wa-cd-display { font-weight: 700; }
    .wa-cd-display span { display: inline-block; min-width: 18px; text-align: center; }
    .wa-alert-close {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 14px;
        line-height: 1;
        padding: 2px 4px;
        opacity: 0.7;
        transition: opacity 0.2s;
        flex-shrink: 0;
    }
    .wa-alert-close:hover { opacity: 1; }
    .wa-alert-bar + .main-nav { top: 0; }
    @media (max-width: 640px) {
        .wa-alert-inner { gap: 6px; padding: 8px 12px; font-size: 12px; }
        .wa-alert-msg { font-size: 12px; }
        .wa-alert-btn { font-size: 11px; padding: 4px 10px; }
        .wa-alert-countdown { font-size: 11px; }
    }
    </style>

    <?php if (!empty($pageCriticalCss) && is_string($pageCriticalCss)): ?>
      <style><?php echo $pageCriticalCss; ?></style>
    <?php endif; ?>

    <!-- Light Nav Theme Override (applies to all pages) -->
    <style>
      .main-nav { background: rgba(255,255,255,0.92); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-bottom: 1px solid rgba(0,0,0,0.06); }
      .nav-inner { padding: 14px 0; }
      .logo { gap: 11px; }
      .logo .logo-icon { width: 38px; height: 38px; background: linear-gradient(135deg, #128C7E 0%, #25D366 100%); border-radius: 11px; box-shadow: 0 4px 18px rgba(18,140,126,0.45); }
      .logo-word { font-family: 'Exo 2', 'Inter', sans-serif; font-size: 21px; font-weight: 800; font-style: italic; text-transform: uppercase; letter-spacing: 0.4px; line-height: 1; background: none !important; -webkit-text-fill-color: unset !important; }
      .logo-wa, .logo-mini { color: #0B3D36 !important; -webkit-text-fill-color: #0B3D36 !important; }
      .nav-menu a { color: #475569; font-weight: 500; font-size: 13.5px; margin-left: 0; padding: 7px 14px; border-radius: 6px; }
      .nav-menu a::after { display: none; }
      .nav-menu a:hover { color: #128C7E; background: rgba(18,140,126,0.06); }
      .nav-login { background: transparent !important; color: #128C7E !important; padding: 7px 18px !important; border-radius: 6px !important; font-weight: 600 !important; font-size: 13.5px !important; border: 1.5px solid rgba(18,140,126,0.2) !important; box-shadow: none !important; }
      .nav-login:hover { border-color: #128C7E !important; background: rgba(18,140,126,0.04) !important; transform: translateY(-1px) !important; box-shadow: none !important; }
      .nav-signup { background: linear-gradient(135deg, #128C7E, #0D6B5F) !important; color: #fff !important; padding: 7px 20px !important; border-radius: 6px !important; font-weight: 700 !important; font-size: 13.5px !important; box-shadow: 0 4px 14px rgba(18,140,126,0.3) !important; }
      .nav-signup:hover { transform: translateY(-2px) !important; box-shadow: 0 6px 20px rgba(18,140,126,0.4) !important; }
      .nav-toggle span { background: #0f172a !important; }
    </style>

    <!-- GPU Glitch Fix for Windows/Chromium -->
    <style>
      .main-nav {
         -webkit-backface-visibility: hidden;
         backface-visibility: hidden;
      }
      .nav-login, .logo-icon, .btn-magnetic {
         -webkit-backface-visibility: hidden;
         backface-visibility: hidden;
         isolation: isolate; 
      }
    </style>
</head>
<body<?php if (!empty($bodyClass)) echo ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"'; ?>>

<?php include_once __DIR__ . '/alert_bar.php'; ?>
<?php include_once __DIR__ . '/alert_popup.php'; ?>

<?php
// Full-screen numbered nav overlay only on the lp-neo public marketing pages.
// Auth pages and landing-admin keep the old simple horizontal nav untouched.
$__waUseFullNav = ($bodyClass === 'lp-neo');
$__waCurrentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

if ($__waUseFullNav):
    $__waNavItems = [
        ['index.php',    'Home',     'Overview'],
        ['features.php', 'Features', 'Automation Suite'],
        ['pricing.php',  'Pricing',  'Plans & Billing'],
        ['blogs.php',    'Blog',     'Insights'],
        ['support.php',  'Support',  'Help Center'],
        ['login.php',    'Login',    'Client Portal'],
    ];
?>
<nav class="main-nav nav-pro">
    <div class="container nav-inner">
        <a href="index.php" class="logo" aria-label="Waamini Home">
            <span class="logo-icon">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.858 2 2.5 4.91 2.5 8.5c0 1.74.75 3.32 1.98 4.49L3.5 16.5l3.72-1.49C8.07 15.65 9.01 15.8 10 15.8c4.142 0 7.5-2.91 7.5-6.5S14.142 2 10 2z" fill="white" opacity="0.95"/>
                    <path d="M7.5 8.5h5M7.5 11h3" stroke="#25D366" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="logo-word"><span class="logo-wa">Waa</span><span class="logo-mini">mini</span></span>
        </a>

        <?php
        $__chev = '<svg class="np-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
        $__npProduct = is_file(__DIR__ . '/feature_pages_registry.php') ? (require __DIR__ . '/feature_pages_registry.php') : [];
        $__npMenus = [
            'Solutions' => ['ecommerce.php' => 'E-commerce', 'real-estate.php' => 'Real Estate', 'healthcare.php' => 'Healthcare', 'education.php' => 'Education'],
            'Product'   => $__npProduct,
            'Resources' => ['blogs.php' => 'Official Blog', 'support.php' => 'Support Center', 'support.php#faq' => 'FAQs'],
        ];
        ?>
        <div class="np-links" role="navigation" aria-label="Primary">
            <?php foreach ($__npMenus as $__label => $__links): ?>
            <div class="np-item">
                <button type="button" class="np-link" aria-haspopup="true"><?php echo $__label; ?><?php echo $__chev; ?></button>
                <div class="np-drop">
                    <?php foreach ($__links as $__href => $__text): ?>
                    <a href="<?php echo htmlspecialchars($__href, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($__text, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <a class="np-link" href="features.php">Features</a>
            <a class="np-link" href="pricing.php">Pricing</a>
        </div>
        <div class="np-actions">
            <a class="np-login" href="login.php">Login</a>
            <a class="np-btn np-btn-light" href="support.php">Book a demo</a>
            <a class="np-btn np-btn-dark" href="register.php">Try for free</a>
        </div>

        <div class="wa-menu-toggle" id="waNavToggle" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-controls="waNavOverlay" aria-label="Toggle navigation menu">
            <span class="wa-menu-icon"><span></span><span></span><span></span></span>
            <span class="wa-menu-toggle-wrapper">
                <span class="wa-open-label">Menu</span>
                <span class="wa-close-label">Close</span>
            </span>
        </div>
    </div>
</nav>

<div class="wa-nav-overlay" id="waNavOverlay" aria-hidden="true">
    <div class="wa-nav-items">
        <?php foreach ($__waNavItems as $__i => $__item):
            [$__file, $__title, $__tag] = $__item;
            $__active = ($__waCurrentPage === $__file) ? ' wa-active' : '';
        ?>
        <a href="<?php echo htmlspecialchars($__file, ENT_QUOTES, 'UTF-8'); ?>" class="wa-nav-item<?php echo $__active; ?>">
            <span class="wa-si-left">
                <span class="wa-si-num"><?php echo str_pad((string)($__i + 1), 2, '0', STR_PAD_LEFT); ?></span>
                <span class="wa-si-title"><?php echo htmlspecialchars($__title, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <span class="wa-si-right">
                <span class="wa-si-tag"><?php echo htmlspecialchars($__tag, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="wa-si-arrow">&#8599;</span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="wa-nav-footer">
        <div class="wa-nav-footer-item">
            <?php $cmsMail = $cmsOn ? cms_setting('support_email', 'support@waamini.com') : 'support@waamini.com'; ?>
            <div class="wa-nav-footer-item-header"><a href="mailto:<?php echo htmlspecialchars($cmsMail, ENT_QUOTES, 'UTF-8'); ?>">Email Us</a></div>
            <div class="wa-nav-footer-item-copy"><a href="mailto:<?php echo htmlspecialchars($cmsMail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cmsMail, ENT_QUOTES, 'UTF-8'); ?></a></div>
        </div>
        <div class="wa-nav-footer-item wa-nav-footer-spacer"></div>
        <div class="wa-nav-footer-item">
            <div class="wa-nav-footer-item-header"><a href="register.php" class="wa-nav-cta">Start Free Trial <span aria-hidden="true">&#8599;</span></a></div>
            <div class="wa-nav-footer-item-copy">No credit card required</div>
        </div>
    </div>
</div>
<?php else: ?>
<nav class="main-nav">
    <div class="container nav-inner">
        <a href="index.php" class="logo" aria-label="Waamini Home">
            <span class="logo-icon">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.858 2 2.5 4.91 2.5 8.5c0 1.74.75 3.32 1.98 4.49L3.5 16.5l3.72-1.49C8.07 15.65 9.01 15.8 10 15.8c4.142 0 7.5-2.91 7.5-6.5S14.142 2 10 2z" fill="white" opacity="0.95"/>
                    <path d="M7.5 8.5h5M7.5 11h3" stroke="#128C7E" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="logo-word"><span class="logo-wa">Waa</span><span class="logo-mini">mini</span></span>
        </a>

        <!-- Desktop Menu -->
        <div class="nav-menu">
            <a href="index.php" aria-label="Home">Home</a>
            <a href="features.php" aria-label="Waamini Features">Features</a>
            <a href="pricing.php" aria-label="Waamini Pricing">Pricing</a>
            <a href="blogs.php" aria-label="Waamini Blog">Blog</a>
            <a href="support.php" aria-label="Waamini Support">Support</a>
            <a href="login.php" class="nav-login" aria-label="Login to Waamini">Login</a>
            <a href="register.php" class="nav-signup" aria-label="Start Free Trial">Start Free Trial</a>
        </div>

        <!-- Mobile Toggle -->
        <div class="nav-toggle" id="navToggle">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </div>
</nav>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay">
    <a href="index.php" class="mobile-link">Home</a>
    <a href="features.php" class="mobile-link">Features</a>
    <a href="pricing.php" class="mobile-link">Pricing</a>
    <a href="blogs.php" class="mobile-link">Blog</a>
    <a href="support.php" class="mobile-link">Support</a>
    <a href="login.php" class="mobile-link">Login</a>
    <a href="register.php" class="mobile-cta">Start Free Trial</a>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function toggleMobileMenu() {
    const toggle = document.getElementById('navToggle');
    const overlay = document.getElementById('mobileOverlay');
    const isActive = toggle.classList.toggle('active');

    if (isActive) {
        overlay.style.display = 'flex';
        setTimeout(() => overlay.classList.add('active'), 10);
        document.body.style.overflow = 'hidden';
    } else {
        overlay.classList.remove('active');
        setTimeout(() => overlay.style.display = 'none', 400);
        document.body.style.overflow = '';
    }
}

// Close menu on link click
document.querySelectorAll('.mobile-link').forEach(link => {
    link.addEventListener('click', () => {
        if (document.getElementById('navToggle').classList.contains('active')) {
            toggleMobileMenu();
        }
    });
});

// Bind toggle handler without inline onclick (CSP-friendly + avoids main-thread work during parse)
window.addEventListener('load', () => {
    const toggle = document.getElementById('navToggle');
    if (toggle) toggle.addEventListener('click', toggleMobileMenu);
});
</script>
<?php endif; ?>
