<?php
// admin_header.php - Included at top of every admin page
// Set $pageTitle and $currentPage before including this file
if (!isset($pageTitle))
    $pageTitle = 'Dashboard';
if (!isset($currentPage))
    $currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> &ndash; WAAMINI Admin</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=11">
    <link rel="stylesheet" href="../assets/css/sidebar-pro.css?v=5">
    <style>
    /* Real-time registration toasts */
    #wmn-toast-stack{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column-reverse;gap:10px;pointer-events:none;}
    .wmn-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:12px;background:#ffffff;border:1px solid #e2e8f0;border-left:3px solid #25d366;border-radius:12px;padding:14px 16px;min-width:300px;max-width:360px;box-shadow:0 8px 32px rgba(15,23,42,.12);animation:wmn-in .35s cubic-bezier(.34,1.56,.64,1) both;position:relative;overflow:hidden;cursor:pointer;}
    .wmn-toast.wmn-out{animation:wmn-slide-out .28s ease-in both;}
    .wmn-toast-avatar{width:36px;height:36px;border-radius:9px;background:rgba(37,211,102,.12);color:#10b981;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .wmn-toast-body{flex:1;min-width:0;}
    .wmn-toast-label{font-size:11px;font-weight:700;color:#10b981;letter-spacing:.4px;text-transform:uppercase;margin-bottom:2px;}
    .wmn-toast-name{font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .wmn-toast-sub{font-size:11px;color:#64748b;margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
    .wmn-mg{font-size:10px;font-weight:700;color:#2d7ce3;background:#eaf3ff;border:1px solid #bdd5f7;border-radius:5px;padding:1px 6px;}
    .wmn-me{font-size:10px;font-weight:700;color:#2d7ce3;background:#eaf3ff;border:1px solid #bdd5f7;border-radius:5px;padding:1px 6px;}
    .wmn-toast-x{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:0 2px;border-radius:4px;transition:color .15s;flex-shrink:0;align-self:flex-start;}
    .wmn-toast-x:hover{color:#0f172a;}
    .wmn-toast-bar{position:absolute;bottom:0;left:0;height:2px;background:#25d366;width:100%;transform-origin:left;animation:wmn-bar 7s linear forwards;}
    @keyframes wmn-in{from{opacity:0;transform:translateX(110%) scale(.9)}to{opacity:1;transform:translateX(0) scale(1)}}
    @keyframes wmn-slide-out{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(110%)}}
    @keyframes wmn-bar{from{transform:scaleX(1)}to{transform:scaleX(0)}}
    </style>
    <style>
    /* No-flicker page loads: no transitions while the page is being built, and a short cross-fade between pages that keeps the sidebar in place. */
    html.wm-preload *,html.wm-preload *::before,html.wm-preload *::after{transition:none!important}
    @view-transition{navigation:auto}
    ::view-transition-old(root),::view-transition-new(root){animation-duration:.18s}
    #sidebar{view-transition-name:wm-sidebar}
    @media (prefers-reduced-motion: reduce){@view-transition{navigation:none}}
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=5">
</head>
<body>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var d = document.documentElement;
  d.classList.add('wm-preload');
  // Remembered collapsed sidebar is applied before the first paint (no expand-then-collapse jump)
  try { if (localStorage.getItem('wmn_sidebar_collapsed') === '1' && window.matchMedia('(min-width: 1025px)').matches) document.body.classList.add('sb-collapsed'); } catch (e) {}
  window.addEventListener('load', function () {
    requestAnimationFrame(function () { requestAnimationFrame(function () { d.classList.remove('wm-preload'); }); });
  });
})();
</script>
<!-- SIDEBAR -->
<aside class="admin-sidebar" id="sidebar">
    <a href="/index.php" class="sidebar-brand" style="text-decoration: none;" aria-label="Waamini home">
        <img class="sb-logo-full" src="/assets/img/waamini-logo.png" alt="Waamini" width="174" height="34"><img class="sb-logo-mini" src="/assets/img/waamini-icon.png" alt="Waamini" width="42" height="42">
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="/admin/dashboard.php" class="nav-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></span> Dashboard
        </a>
        <a href="/admin/users.php" class="nav-item <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Vendors &amp; Users
        </a>
        <a href="/admin/companies.php" class="nav-item <?php echo $currentPage === 'companies' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg></span> Companies
        </a>
        <a href="/admin/announcements.php" class="nav-item <?php echo $currentPage === 'announcements' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg></span> System Notices
        </a>

        <div class="nav-section-title">Subscriptions</div>
        <input type="checkbox" id="sb-toggle-subscriptions" class="submenu-toggle" <?php echo $currentPage === 'subscriptions' ? 'checked' : ''; ?>>
        <label for="sb-toggle-subscriptions" class="nav-item has-submenu <?php echo $currentPage === 'subscriptions' ? 'active submenu-open' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></span> Subscriptions
            <span class="submenu-arrow">&#8250;</span>
        </label>
        <div class="submenu <?php echo $currentPage === 'subscriptions' ? 'open' : ''; ?>">
            <a href="/admin/subscriptions.php" class="nav-item sub-item <?php echo($currentPage === 'subscriptions' && basename($_SERVER['PHP_SELF']) === 'subscriptions.php') ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></span> Auto
            </a>
            <a href="/admin/plans.php" class="nav-item sub-item <?php echo($currentPage === 'subscriptions' && basename($_SERVER['PHP_SELF']) === 'plans.php') ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg></span> Plans
            </a>
        </div>

        <div class="nav-section-title">Modules</div>
        <a href="/admin/broadcast.php" class="nav-item <?php echo $currentPage === 'broadcast' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg></span> Broadcasts
        </a>
        <a href="/admin/contacts.php" class="nav-item <?php echo $currentPage === 'contacts' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Contacts
        </a>
        <a href="/admin/campaigns.php" class="nav-item <?php echo $currentPage === 'campaigns' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg></span> Client Campaigns
        </a>
        <a href="/admin/whatsapp.php" class="nav-item <?php echo $currentPage === 'whatsapp' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span> WhatsApp
        </a>

        <div class="nav-section-title">Configurations</div>
        <a href="/admin/google_setup.php" class="nav-item <?php echo $currentPage === 'google_setup' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg></span> Google Login (Meet)
        </a>
        <a href="/admin/settings_shopify.php" class="nav-item <?php echo $currentPage === 'settings_shopify' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span> Shopify app
        </a>
        <a href="/admin/settings.php" class="nav-item <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></span> Overview
        </a>
        <input type="checkbox" id="sb-toggle-settings" class="submenu-toggle" <?php echo in_array($currentPage, ['settings_general', 'settings_smtp', 'settings_payment', 'settings_whatsapp', 'email_templates']) ? 'checked' : ''; ?>>
        <label for="sb-toggle-settings" class="nav-item has-submenu <?php echo in_array($currentPage, ['settings_general', 'settings_smtp', 'settings_payment', 'settings_whatsapp', 'email_templates']) ? 'active submenu-open' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></span> Settings
            <span class="submenu-arrow">&#8250;</span>
        </label>
        <div class="submenu <?php echo in_array($currentPage, ['settings_general', 'settings_smtp', 'settings_payment', 'settings_whatsapp', 'email_templates']) ? 'open' : ''; ?>">
            <a href="/admin/settings_general.php" class="nav-item sub-item <?php echo $currentPage === 'settings_general' ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg></span> General
            </a>
            <a href="/admin/settings_smtp.php" class="nav-item sub-item <?php echo $currentPage === 'settings_smtp' ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span> Email / SMTP
            </a>
            <a href="/admin/settings_whatsapp.php" class="nav-item sub-item <?php echo $currentPage === 'settings_whatsapp' ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg></span> WhatsApp Onboarding
            </a>
            <a href="/admin/email_templates.php" class="nav-item sub-item <?php echo $currentPage === 'email_templates' ? 'active' : ''; ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></span> Email Templates
            </a>
        </div>

        <div class="nav-section-title">System</div>
        <a href="/admin/support_inbox.php" class="nav-item <?php echo $currentPage === 'support_inbox' ? 'active' : ''; ?>">
            <span class="icon" style="position:relative;">
                <svg viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                <?php
if (isset($conn)) {
    if (!isset($_SESSION['_admin_open_tickets_count']) || (time() - ($_SESSION['_admin_open_tickets_ts'] ?? 0)) > 60) {
        $openTicketsRes = $conn->query("SELECT COUNT(id) as c FROM support_tickets WHERE status IN ('open','pending','resolved')");
        $_SESSION['_admin_open_tickets_count'] = ($openTicketsRes ? (int)$openTicketsRes->fetch_assoc()['c'] : 0);
        $_SESSION['_admin_open_tickets_ts'] = time();
    }
    $openTicketsCount = $_SESSION['_admin_open_tickets_count'];
    if ($openTicketsCount > 0) {
        echo '<span style="position:absolute;top:-4px;right:-6px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:10px;line-height:1;">' . $openTicketsCount . '</span>';
    }
}
?>
            </span>
            Support Inbox
        </a>
        <a href="/admin/analytics.php" class="nav-item <?php echo $currentPage === 'analytics' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg></span> Analytics
        </a>
        <a href="/admin/pulse.php" class="nav-item <?php echo $currentPage === 'pulse' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg></span> Pulse AI Monitor
        </a>
        <a href="/admin/waamini_bot.php" class="nav-item <?php echo $currentPage === 'waamini_bot' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg></span> WAAMINI Bot
        </a>
        <a href="/admin/ads_overview.php" class="nav-item <?php echo $currentPage === 'ads_overview' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></span> Ads &amp; Attribution
        </a>
        <a href="/admin/api_usage.php" class="nav-item <?php echo $currentPage === 'api_usage' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></span> API Monitor
        </a>
        <a href="/admin/queue_health.php" class="nav-item <?php echo $currentPage === 'queue_health' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span> Queue Monitor
        </a>
        <a href="/admin/new_registrations.php" class="nav-item <?php echo $currentPage === 'new_registrations' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg></span> New Registrations
        </a>
        <a href="/admin/audit_log.php" class="nav-item <?php echo $currentPage === 'audit_log' ? 'active' : ''; ?>">
            <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></span> Audit Log
        </a>
    </nav>
</aside>
<script src="../assets/js/sidebar-collapse.js?v=3"></script>

<script>
// Standard sidebar: submenu expand/collapse is pure CSS (checkbox + label),
// so it works even under a strict CSP that blocks inline scripts.
document.addEventListener('DOMContentLoaded', function () {
    // Keep the SELECTED page visible in the sidebar (don't reset to top on navigation).
    // 1) Restore last scroll position instantly; 2) ensure the active item is in view.
    var nav = document.querySelector('.admin-sidebar nav') || document.querySelector('.admin-sidebar');
    if (nav) {
        var saved = sessionStorage.getItem('wmn_sidebar_scroll');
        if (saved !== null) nav.scrollTop = parseInt(saved, 10) || 0;

        var active = nav.querySelector('.nav-item.active');
        if (active) {
            var navRect = nav.getBoundingClientRect();
            var actRect = active.getBoundingClientRect();
            if (actRect.top < navRect.top || actRect.bottom > navRect.bottom) {
                active.scrollIntoView({ block: 'center' });
            }
        }
        nav.addEventListener('scroll', function () {
            sessionStorage.setItem('wmn_sidebar_scroll', String(nav.scrollTop));
        });
    }
});
</script>

<!-- TOPBAR -->
<header class="admin-topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle hamburger-menu" id="hamburger" aria-label="Toggle menu">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
        </button>
        <h1><?php echo $pageTitle; ?></h1>
    </div>
    <div class="topbar-right">

        <?php
        $wmnInitAuditId = 0;
        if (isset($conn)) {
            $midRes = $conn->query("SELECT COALESCE(MAX(id),0) as mid FROM audit_log");
            if ($midRes) $wmnInitAuditId = (int)$midRes->fetch_assoc()['mid'];
        }
        ?>

        <?php
        $notifUsers = [];
        $notifCount = 0;
        if (isset($conn)) {
            if (!isset($_SESSION['_admin_notif_ts']) || (time() - $_SESSION['_admin_notif_ts']) > 60) {
                $nStmt = $conn->prepare(
                    "SELECT u.id, u.name, u.email, u.google_id, u.created_at, c.name AS company_name
                     FROM users u
                     LEFT JOIN companies c ON u.company_id = c.id
                     WHERE u.created_at >= NOW() - INTERVAL 24 HOUR
                     ORDER BY u.created_at DESC LIMIT 10"
                );
                if ($nStmt && $nStmt->execute()) {
                    $nRes = $nStmt->get_result();
                    while ($row = $nRes->fetch_assoc()) {
                        $notifUsers[] = $row;
                    }
                    $nStmt->close();
                }
                $_SESSION['_admin_notif_users'] = $notifUsers;
                $_SESSION['_admin_notif_ts']    = time();
            } else {
                $notifUsers = $_SESSION['_admin_notif_users'] ?? [];
            }
            $notifCount = count($notifUsers);
        }
        ?>

        <div class="notif-bell-wrap" id="notifWrap">
            <button id="notifBtn" aria-label="Notifications">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>
            </button>

            <div id="notifDropdown">
                <div class="notif-head">
                    <span class="notif-head-title">New Registrations</span>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="notif-head-sub">Last 24 h</span>
                        <button id="notifMarkRead" class="notif-mark-btn" title="Mark all read">Clear</button>
                    </div>
                </div>

                <div id="notifList" style="max-height:320px;overflow-y:auto;">
                    <?php if (empty($notifUsers)): ?>
                    <div id="notifEmpty" style="padding:32px 18px;text-align:center;color:#64748b;font-size:13px;">
                        No new registrations in the last 24 hours
                    </div>
                    <?php else: ?>
                    <?php foreach ($notifUsers as $nu):
                        $isG = !empty($nu['google_id']);
                    ?>
                    <a href="/admin/client_view.php?id=<?php echo (int)$nu['id']; ?>" class="notif-row">
                        <div class="notif-avatar" style="background:<?php echo $isG ? '#eaf3ff' : 'rgba(45,124,227,.12)'; ?>;color:<?php echo $isG ? '#2d7ce3' : '#2d7ce3'; ?>;">
                            <?php echo strtoupper(substr($nu['name'] ?? '?', 0, 1)); ?>
                        </div>
                        <div class="notif-info">
                            <div class="notif-name"><?php echo htmlspecialchars($nu['name'] ?? ''); ?></div>
                            <div class="notif-email"><?php echo htmlspecialchars($nu['email']); ?></div>
                        </div>
                        <?php if ($isG): ?>
                        <span class="notif-badge notif-badge-g">G Google</span>
                        <?php else: ?>
                        <span class="notif-badge notif-badge-e">Email</span>
                        <?php endif; ?>
                        <div class="notif-time"><?php
                            $diff = time() - strtotime($nu['created_at']);
                            echo $diff < 3600 ? floor($diff/60).'m' : (floor($diff/3600).'h');
                        ?></div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="notif-footer">
                    <a href="/admin/users.php">View All Users &rarr;</a>
                    <a href="/admin/client_create.php" style="color:#10b981;">+ Create Client</a>
                </div>
            </div>
        </div>

        <style>
        /* Notification bell & dropdown - light theme */
        .notif-bell-wrap{position:relative;display:inline-flex;align-items:center;}
        #notifBtn{background:none;border:none;cursor:pointer;padding:8px;border-radius:9px;color:#64748b;transition:background .2s;position:relative;display:flex;align-items:center;justify-content:center;}
        #notifBtn:hover{background:#f1f5f9;color:#0f172a;}
        #notifBtn [data-notif-badge]{position:absolute;top:2px;right:2px;background:#ef4444;color:#fff;font-size:9px;font-weight:800;min-width:16px;height:16px;border-radius:10px;line-height:16px;text-align:center;padding:0 3px;border:2px solid #ffffff;pointer-events:none;animation:badge-pop .25s cubic-bezier(.34,1.56,.64,1);}
        @keyframes badge-pop{from{transform:scale(0)}to{transform:scale(1)}}
        #notifDropdown{
            display:none;position:absolute;top:calc(100% + 12px);right:0;
            width:330px;background:#ffffff;
            border:1px solid #e2e8f0;border-radius:14px;
            box-shadow:0 10px 30px rgba(15,23,42,.12);z-index:99999;overflow:hidden;
        }
        @media(max-width:480px){
            #notifDropdown{position:fixed;top:64px;left:8px;right:8px;width:auto;}
        }
        .notif-head{padding:14px 16px 12px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
        .notif-head-title{font-size:13px;font-weight:700;color:#0f172a;}
        .notif-head-sub{font-size:11px;color:#64748b;}
        .notif-mark-btn{background:none;border:none;cursor:pointer;font-size:11px;font-weight:700;color:#2d7ce3;padding:3px 8px;border-radius:6px;transition:background .15s;}
        .notif-mark-btn:hover{background:#eaf3ff;}
        .notif-row{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid #f1f5f9;text-decoration:none;transition:background .15s;cursor:pointer;}
        .notif-row:last-child{border-bottom:none;}
        .notif-row:hover{background:#f8fafc;}
        .notif-row.notif-row-new{background:#eaf3ff;}
        .notif-avatar{width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
        .notif-info{flex:1;min-width:0;}
        .notif-name{font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .notif-email{font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .notif-badge{font-size:9px;font-weight:700;padding:2px 6px;border-radius:5px;white-space:nowrap;flex-shrink:0;}
        .notif-badge-g{background:#eaf3ff;color:#2d7ce3;border:1px solid #bdd5f7;}
        .notif-badge-e{background:rgba(45,124,227,.1);color:#2d7ce3;border:1px solid #bdd5f7;}
        .notif-time{font-size:10px;color:#94a3b8;flex-shrink:0;}
        .notif-footer{padding:11px 16px;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
        .notif-footer a{font-size:12px;font-weight:700;color:#2d7ce3;text-decoration:none;}
        .notif-footer a:hover{text-decoration:underline;}
        #notifList::-webkit-scrollbar{width:4px;}
        #notifList::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:4px;}
        </style>

        <div class="topbar-user" id="topbarUser">
            <div class="user-info">
                <div class="user-name"><?php echo $_SESSION['user_name'] ?? 'Admin'; ?></div>
                <div class="user-role">Super Admin</div>
            </div>
            <div class="avatar"><?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?></div>
        </div>

        <div class="topbar-dropdown" id="topbarDropdown">
            <a href="/admin/profile.php">
                <svg viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <a href="/logout.php" class="danger">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                Sign Out
            </a>
        </div>
    </div>

    <script>
    /* Notification bell */
    (function(){
        const POLL_MS    = 25000;
        const POLL_KEY   = 'wmn_notif_last_id';
        const SEEN_KEY   = 'wmn_notif_seen_id';
        const API        = '/api/admin_new_registrations.php';
        const PHP_MAX    = <?php echo (int)$wmnInitAuditId; ?>;
        const PHP_COUNT  = <?php echo (int)$notifCount; ?>;
        const MAX_TOASTS = 4;

        const btn       = document.getElementById('notifBtn');
        const drop      = document.getElementById('notifDropdown');
        const markBtn   = document.getElementById('notifMarkRead');
        const listEl    = document.getElementById('notifList');
        if (!btn || !drop) return;

        let unreadCount = 0;

        function getBadgeEl() { return btn.querySelector('[data-notif-badge]'); }

        function renderBadge(n) {
            let b = getBadgeEl();
            if (n <= 0) { if (b) b.remove(); return; }
            if (!b) {
                b = document.createElement('span');
                b.setAttribute('data-notif-badge', '1');
                btn.appendChild(b);
            }
            b.textContent = n > 9 ? '9+' : String(n);
        }

        function addToUnread(n) {
            unreadCount += n;
            renderBadge(unreadCount);
        }

        function clearUnread() {
            unreadCount = 0;
            renderBadge(0);
            localStorage.setItem(SEEN_KEY, localStorage.getItem(POLL_KEY) || String(PHP_MAX));
        }

        const storedPoll = localStorage.getItem(POLL_KEY);
        let lastId = storedPoll !== null
            ? Math.max(parseInt(storedPoll, 10) || 0, 0)
            : PHP_MAX;
        if (lastId < PHP_MAX) lastId = PHP_MAX;
        localStorage.setItem(POLL_KEY, lastId);

        const storedSeen = localStorage.getItem(SEEN_KEY);
        const seenId = storedSeen !== null ? (parseInt(storedSeen, 10) || 0) : 0;

        if (!seenId) {
            unreadCount = PHP_COUNT;
        }
        renderBadge(unreadCount);

        function openDrop()  { drop.style.display = 'block'; clearUnread(); }
        function closeDrop() { drop.style.display = 'none';  }
        function isOpen()    { return drop.style.display !== 'none'; }

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            isOpen() ? closeDrop() : openDrop();
        });
        document.addEventListener('click', function(e) {
            if (!drop.contains(e.target) && e.target !== btn) closeDrop();
        });
        drop.addEventListener('click', function(e) { e.stopPropagation(); });

        if (markBtn) {
            markBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                clearUnread();
                closeDrop();
            });
        }

        function prependToDropdown(item) {
            if (!listEl) return;
            const empty = document.getElementById('notifEmpty');
            if (empty) empty.remove();

            const isG    = item.method === 'google';
            const init   = (item.name || '?').charAt(0).toUpperCase();
            const avatBg = isG ? '#eaf3ff' : 'rgba(45,124,227,.12)';
            const avatCl = '#2d7ce3';
            const badge  = isG
                ? '<span class="notif-badge notif-badge-g">G Google</span>'
                : '<span class="notif-badge notif-badge-e">Email</span>';

            function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

            const row = document.createElement('a');
            row.className  = 'notif-row notif-row-new';
            row.href       = '/admin/client_view.php';
            row.innerHTML  =
                '<div class="notif-avatar" style="background:'+avatBg+';color:'+avatCl+';">'+init+'</div>'+
                '<div class="notif-info">'+
                    '<div class="notif-name">'+esc(item.name)+'</div>'+
                    '<div class="notif-email">'+esc(item.email)+'</div>'+
                '</div>'+
                badge+
                '<div class="notif-time">just now</div>';

            listEl.insertBefore(row, listEl.firstChild);
            setTimeout(() => row.classList.remove('notif-row-new'), 5000);

            const rows = listEl.querySelectorAll('.notif-row');
            if (rows.length > 10) rows[rows.length - 1].remove();
        }

        function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

        function dismissToast(el) {
            el.classList.add('wmn-out');
            el.addEventListener('animationend', () => el.remove(), {once:true});
        }

        function showToast(item) {
            const stack = document.getElementById('wmn-toast-stack');
            if (!stack) return;
            const visible = stack.querySelectorAll('.wmn-toast:not(.wmn-out)');
            if (visible.length >= MAX_TOASTS) dismissToast(visible[0]);

            const init   = (item.name || '?').charAt(0).toUpperCase();
            const badge  = item.method === 'google'
                ? '<span class="wmn-mg">G Google</span>'
                : '<span class="wmn-me">Email</span>';
            const ts = item.time
                ? new Date(item.time.replace(' ','T')).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})
                : '';

            const t = document.createElement('div');
            t.className = 'wmn-toast';
            t.setAttribute('role', 'alert');
            t.innerHTML =
                '<div class="wmn-toast-avatar">'+init+'</div>'+
                '<div class="wmn-toast-body">'+
                    '<div class="wmn-toast-label">New Client Registered</div>'+
                    '<div class="wmn-toast-name">'+esc(item.name)+'</div>'+
                    '<div class="wmn-toast-sub">'+esc(item.email)+' '+badge+(ts?'<span>'+ts+'</span>':'')+
                    '</div>'+
                '</div>'+
                '<button class="wmn-toast-x" aria-label="Dismiss">&times;</button>'+
                '<div class="wmn-toast-bar"></div>';

            t.addEventListener('click', function(e){
                if (!e.target.closest('.wmn-toast-x'))
                    window.location.href = '/admin/users.php';
            });
            t.querySelector('.wmn-toast-x').addEventListener('click', function(e){
                e.stopPropagation(); dismissToast(t);
            });
            setTimeout(() => { if (t.parentElement) dismissToast(t); }, 7000);
            stack.appendChild(t);
        }

        async function poll() {
            try {
                const r = await fetch(API + '?since_id=' + lastId, {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                if (!r.ok) return;
                const d = await r.json();

                if (d.items && d.items.length) {
                    d.items.forEach(function(item) {
                        showToast(item);
                        prependToDropdown(item);
                        if (typeof window.wmnFeedPrepend === 'function') window.wmnFeedPrepend(item);
                    });
                    if (!isOpen()) addToUnread(d.items.length);
                }
                if (d.last_id && d.last_id > lastId) {
                    lastId = d.last_id;
                    localStorage.setItem(POLL_KEY, lastId);
                }
            } catch(_) {}
        }

        setTimeout(function() { poll(); setInterval(poll, POLL_MS); }, 8000);

        /* ── Topbar user dropdown ── */
        const userBtn = document.getElementById('topbarUser');
        const userDrop = document.getElementById('topbarDropdown');
        if (userBtn && userDrop) {
            userBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userDrop.classList.toggle('open');
            });
            document.addEventListener('click', function(e) {
                if (!userDrop.contains(e.target) && e.target !== userBtn) {
                    userDrop.classList.remove('open');
                }
            });
        }
    })();
    </script>
</header>

<div id="wmn-toast-stack" aria-live="polite" aria-atomic="false"></div>

<?php
$wmTabs = [
    ['dashboard', 'Home', '/admin/dashboard.php', '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>'],
    ['companies', 'Vendors', '/admin/companies.php', '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h1"/><path d="M14 9h1"/><path d="M9 13h1"/><path d="M14 13h1"/><path d="M10 21v-4h4v4"/>', ['users', 'client_view', 'client_create', 'company_add', 'company_edit', 'user_add', 'user_edit']],
    ['subscriptions', 'Plans', '/admin/subscriptions.php', '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>', ['plans', 'plan_edit', 'subscription_add', 'subscription_view', 'subscriptions_manual', 'revenue', 'coupons']],
    ['support_inbox', 'Support', '/admin/support_inbox.php', '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'],
];
include __DIR__ . '/mobile_tabbar.php';
?>
<main class="admin-main">
