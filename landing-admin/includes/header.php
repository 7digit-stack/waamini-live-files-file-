<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo isset($laTitle) ? e($laTitle).' — ' : ''; ?>Landing CMS · Waamini</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="../assets/css/landing-admin.css">
<?php if (!empty($laExtraCss)) echo $laExtraCss; ?>
</head>
<body>
<div class="la-layout">

<!-- ── Sidebar ─────────────────────────────────────────────────── -->
<aside class="la-sidebar">
    <a href="/landing-admin/" class="la-brand">
        <div class="la-brand-icon">W</div>
        <div>
            <div class="la-brand-text">WAAMINI</div>
            <div class="la-brand-sub">Landing CMS</div>
        </div>
    </a>

    <nav class="la-nav">
        <?php
        $cur = $laPage ?? '';
        function la_svg(string $name): string {
            $icons = [
                'dashboard'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>',
                'hero'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
                'blogs'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
                'categories'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
                'features'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>',
                'pricing'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
                'testimonials' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 10h.01"/><path d="M12 10h.01"/><path d="M16 10h.01"/></svg>',
                'alert-bar'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>',
                'offer-counter'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/></svg>',
                'alert-popup'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M13.916 2.314A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.74 7.327A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673 9 9 0 0 1-.585-.665"/><circle cx="18" cy="8" r="3"/></svg>',
                'media'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
                'settings'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>',
                'globe'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
                'external'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
                'newspaper'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>',
                'logout'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>',
                'plus'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
            ];
            return $icons[$name] ?? '';
        }
        function la_nav(string $href, string $iconName, string $label, string $cur, string $page, ?int $badge=null): void {
            $active = $cur === $page ? ' active' : '';
            $bdg = $badge !== null ? '<span class="la-nav-badge">'.$badge.'</span>' : '';
            echo '<a href="'.$href.'" class="la-nav-item'.$active.'"><span class="la-nav-icon">'.la_svg($iconName).'</span>'.htmlspecialchars($label).$bdg.'</a>';
        }
        ?>

        <div class="la-nav-section">Overview</div>
        <?php la_nav('/landing-admin/',                 'dashboard',    'Dashboard',      $cur, 'dashboard'); ?>

        <div class="la-nav-section">Content</div>
        <?php la_nav('/landing-admin/home-sections.php','hero','Home Page',$cur,'home-sections'); ?>
        <?php la_nav('/landing-admin/hero.php',         'hero',         'Hero Sections',  $cur, 'hero'); ?>
        <?php la_nav('/landing-admin/blogs.php',        'blogs',        'Blog Posts',     $cur, 'blogs',
            isset($draftCount) ? (int)$draftCount : null); ?>
        <?php la_nav('/landing-admin/categories.php',   'categories',   'Categories',     $cur, 'categories'); ?>
        <?php la_nav('/landing-admin/features.php',     'features',     'Features',       $cur, 'features'); ?>
        <?php la_nav('/landing-admin/pricing.php',      'pricing',      'Pricing Plans',  $cur, 'pricing'); ?>
        <?php la_nav('/landing-admin/testimonials.php', 'testimonials', 'Testimonials',   $cur, 'testimonials'); ?>
        <?php la_nav('/landing-admin/alert.php',        'alert-bar',    'Alert Bar',      $cur, 'alert'); ?>
        <?php la_nav('/landing-admin/offer-counter.php','offer-counter','Offer Counter',  $cur, 'offer-counter'); ?>
        <?php la_nav('/landing-admin/alert-popup.php',  'alert-popup',  'Alert Popup',    $cur, 'alert-popup'); ?>

        <div class="la-nav-section">Assets</div>
        <?php la_nav('/landing-admin/media.php',        'media',        'Media Library',  $cur, 'media'); ?>

        <div class="la-nav-section">Config</div>
        <?php la_nav('/landing-admin/settings.php',     'settings',     'Site Settings',  $cur, 'settings'); ?>

        <div class="la-nav-section">Links</div>
        <a href="/index.php" class="la-nav-item" target="_blank">
            <span class="la-nav-icon"><?php echo la_svg('globe'); ?></span> View Site
            <span style="margin-left:auto;color:var(--text-dim);"><?php echo la_svg('external'); ?></span>
        </a>
        <a href="/blogs.php" class="la-nav-item" target="_blank">
            <span class="la-nav-icon"><?php echo la_svg('newspaper'); ?></span> View Blog
            <span style="margin-left:auto;color:var(--text-dim);"><?php echo la_svg('external'); ?></span>
        </a>
    </nav>

    <div class="la-sidebar-footer">
        <div style="padding:8px 10px 10px;font-size:11px;color:var(--text-muted);">
            Signed in as<br>
            <strong style="color:var(--text-primary);font-size:12px;"><?php echo e(la_admin_email()); ?></strong>
        </div>
        <a href="/landing-admin/logout.php">
            <span class="la-nav-icon"><?php echo la_svg('logout'); ?></span> Sign out
        </a>
    </div>
</aside>

<!-- ── Main ──────────────────────────────────────────────────────── -->
<div class="la-main">
    <!-- Topbar -->
    <header class="la-topbar">
        <div class="la-topbar-title"><?php echo isset($laTitle) ? e($laTitle) : 'Dashboard'; ?></div>
        <div class="la-topbar-right">
            <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-primary la-btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                New Post
            </a>
            <div class="la-topbar-user">
                <div class="la-topbar-avatar"><?php echo strtoupper(substr(la_admin_name(),0,1)); ?></div>
                <span><?php echo e(la_admin_name()); ?></span>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="la-content">
