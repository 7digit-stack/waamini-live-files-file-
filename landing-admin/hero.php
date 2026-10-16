<?php
/**
 * Landing Admin — Hero Sections Editor
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Hero Sections';
$laPage  = 'hero';

/* ── Draft count (sidebar badge) ─────────────────────────────────── */
$stDC = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'");
$draftCount = (int)($stDC->fetch_assoc()['n'] ?? 0);

/* ── Supported pages ──────────────────────────────────────────────── */
$heroPages = [
    'home'       => ['label' => 'Home',       'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>', 'url' => '/index.php'],
    'features'   => ['label' => 'Features',   'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>', 'url' => '/features.php'],
    'pricing'    => ['label' => 'Pricing',    'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>', 'url' => '/pricing.php'],
    'blogs'      => ['label' => 'Blog',       'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>', 'url' => '/blogs.php'],
    'industries' => ['label' => 'Industries', 'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>', 'url' => '/ecommerce.php'],
];

/* ── Active page tab ──────────────────────────────────────────────── */
$activePage = isset($_GET['page']) && isset($heroPages[$_GET['page']]) ? $_GET['page'] : 'home';

/* ── Handle POST ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();

    $savePage         = $heroPages[$_POST['page'] ?? ''] ? ($_POST['page']) : 'home';
    $announceText     = trim($_POST['announce_text']    ?? '');
    $headline         = trim($_POST['headline']         ?? '');
    $highlightWord    = trim($_POST['highlight_word']   ?? '');
    $subtext          = trim($_POST['subtext']          ?? '');
    $ctaPrimaryText   = trim($_POST['cta_primary_text'] ?? 'Start Free Trial');
    $ctaPrimaryUrl    = trim($_POST['cta_primary_url']  ?? '/register.php');
    $ctaSecText       = trim($_POST['cta_sec_text']     ?? 'See How It Works');
    $ctaSecUrl        = trim($_POST['cta_sec_url']      ?? '#features');
    $isActive         = isset($_POST['is_active']) ? 1 : 0;

    /* Assemble stats JSON */
    $stats = [];
    $statVals   = $_POST['stat_value'] ?? [];
    $statLabels = $_POST['stat_label'] ?? [];
    for ($i = 0; $i < 4; $i++) {
        $v = trim($statVals[$i]   ?? '');
        $l = trim($statLabels[$i] ?? '');
        if ($v !== '' || $l !== '') {
            $stats[] = ['value' => $v, 'label' => $l];
        }
    }
    $statsJson = !empty($stats) ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null;

    /* INSERT ... ON DUPLICATE KEY UPDATE */
    $st = $conn->prepare("
        INSERT INTO lp_hero
            (page, announce_text, headline, highlight_word, subtext,
             cta_primary_text, cta_primary_url, cta_sec_text, cta_sec_url,
             stats_json, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            announce_text    = VALUES(announce_text),
            headline         = VALUES(headline),
            highlight_word   = VALUES(highlight_word),
            subtext          = VALUES(subtext),
            cta_primary_text = VALUES(cta_primary_text),
            cta_primary_url  = VALUES(cta_primary_url),
            cta_sec_text     = VALUES(cta_sec_text),
            cta_sec_url      = VALUES(cta_sec_url),
            stats_json       = VALUES(stats_json),
            is_active        = VALUES(is_active),
            updated_at       = NOW()
    ");
    $st->bind_param(
        'ssssssssssi',
        $savePage, $announceText, $headline, $highlightWord, $subtext,
        $ctaPrimaryText, $ctaPrimaryUrl, $ctaSecText, $ctaSecUrl,
        $statsJson, $isActive
    );
    $st->execute();
    $st->close();

    la_flash_set('success', 'Hero section for "' . $heroPages[$savePage]['label'] . '" saved successfully.');
    header("Location: /landing-admin/hero.php?page=$savePage");
    exit;
}

/* ── Load hero data for active page ──────────────────────────────── */
$hero = null;
$stHero = $conn->prepare("SELECT * FROM lp_hero WHERE page=? LIMIT 1");
$stHero->bind_param('s', $activePage);
$stHero->execute();
$hero = $stHero->get_result()->fetch_assoc();
$stHero->close();

/* ── Decode stats JSON ────────────────────────────────────────────── */
$statsRows = [
    ['value' => '', 'label' => ''],
    ['value' => '', 'label' => ''],
    ['value' => '', 'label' => ''],
    ['value' => '', 'label' => ''],
];
if ($hero && $hero['stats_json']) {
    $decoded = json_decode($hero['stats_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $i => $s) {
            if ($i >= 4) break;
            $statsRows[$i] = [
                'value' => $s['value'] ?? '',
                'label' => $s['label'] ?? '',
            ];
        }
    }
}

/* ── Field values ────────────────────────────────────────────────── */
$fAnnounce      = $hero['announce_text']    ?? '';
$fHeadline      = $hero['headline']         ?? '';
$fHighlight     = $hero['highlight_word']   ?? '';
$fSubtext       = $hero['subtext']          ?? '';
$fCtaPText      = $hero['cta_primary_text'] ?? 'Start Free Trial';
$fCtaPUrl       = $hero['cta_primary_url']  ?? '/register.php';
$fCtaSText      = $hero['cta_sec_text']     ?? 'See How It Works';
$fCtaSUrl       = $hero['cta_sec_url']      ?? '#features';
$fIsActive      = $hero ? (int)$hero['is_active'] : 1;

include __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<!-- Page header -->
<div class="la-page-hd">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Hero Sections</h1>
        <div class="la-breadcrumb">
            <a href="/landing-admin/">Dashboard</a>
            <span>/</span>
            <span>Hero Sections</span>
        </div>
    </div>
    <a href="<?php echo $heroPages[$activePage]['url']; ?>" target="_blank" class="la-btn la-btn-outline">
        ↗ Preview: <?php echo $heroPages[$activePage]['label']; ?> Page
    </a>
</div>

<!-- Page tab row -->
<div style="display:flex;gap:4px;margin-bottom:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:6px;">
    <?php foreach ($heroPages as $pageKey => $pageInfo):
        $isTab  = $activePage === $pageKey;
        /* Check if this page has a hero row */
        $stHasHero = $conn->prepare("SELECT id, updated_at FROM lp_hero WHERE page=? LIMIT 1");
        $stHasHero->bind_param('s', $pageKey);
        $stHasHero->execute();
        $hasHero = $stHasHero->get_result()->fetch_assoc();
        $stHasHero->close();
    ?>
    <a href="/landing-admin/hero.php?page=<?php echo urlencode($pageKey); ?>"
       style="display:flex;align-items:center;gap:8px;padding:9px 16px;border-radius:7px;font-size:13px;font-weight:<?php echo $isTab ? '600' : '400'; ?>;background:<?php echo $isTab ? 'var(--accent)' : 'transparent'; ?>;color:<?php echo $isTab ? '#fff' : 'var(--text-secondary)'; ?>;text-decoration:none;transition:all .15s;flex:1;justify-content:center;"
       <?php if (!$isTab): ?>onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='transparent'"<?php endif; ?>>
        <span><?php echo $pageInfo['icon']; ?></span>
        <span><?php echo $pageInfo['label']; ?></span>
        <?php if ($hasHero): ?>
            <span style="width:6px;height:6px;border-radius:50%;background:<?php echo $isTab ? 'rgba(255,255,255,.7)' : 'var(--success)'; ?>;display:inline-block;" title="Has content"></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Last updated notice -->
<?php if ($hero && $hero['updated_at']): ?>
<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--info-bg);border:1px solid var(--info-border);border-radius:8px;margin-bottom:16px;font-size:13px;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent);flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
    <span style="color:var(--text-secondary);">Last updated: <strong style="color:var(--text-primary);"><?php echo la_time_ago($hero['updated_at']); ?></strong> (<?php echo date('d M Y, H:i', strtotime($hero['updated_at'])); ?>)</span>
</div>
<?php elseif (!$hero): ?>
<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:8px;margin-bottom:16px;font-size:13px;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--warning);flex-shrink:0;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
    <span style="color:#92400e;">No hero content saved for this page yet. Fill in the form below and save.</span>
</div>
<?php endif; ?>

<form method="POST" action="/landing-admin/hero.php" id="heroForm">
    <?php echo la_csrf_field(); ?>
    <input type="hidden" name="page" value="<?php echo e($activePage); ?>">

    <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">

    <!-- ── Left: Content ──────────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Headline Section -->
        <div class="la-card">
            <div class="la-card-hd">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:var(--text-primary);">
                        <?php echo $heroPages[$activePage]['icon']; ?>
                        <?php echo $heroPages[$activePage]['label']; ?> — Hero Content
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Main headline section displayed at the top of the page</div>
                </div>
            </div>

            <!-- Announce badge row -->
            <div style="display:grid;grid-template-columns:220px 1fr;gap:0;border-bottom:1px solid var(--border);">
                <div style="padding:16px 20px;border-right:1px solid var(--border);">
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:3px;">Announce Text</div>
                    <div style="font-size:11px;color:var(--text-muted);line-height:1.5;">Top badge/pill shown above the headline</div>
                </div>
                <div style="padding:14px 20px;display:flex;flex-direction:column;gap:5px;">
                    <input type="text" id="fAnnounce" name="announce_text" class="la-input"
                           value="<?php echo e($fAnnounce); ?>"
                           placeholder="e.g. Rated 4.9/5 by 2,400+ businesses">
                </div>
            </div>

            <!-- Headline row -->
            <div style="display:grid;grid-template-columns:220px 1fr;gap:0;border-bottom:1px solid var(--border);">
                <div style="padding:16px 20px;border-right:1px solid var(--border);">
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:3px;">
                        Headline <span style="color:var(--danger);font-weight:400;">*</span>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);line-height:1.5;">Main hero heading. Keep under 70 characters.</div>
                    <div id="headlineLen" style="font-size:11px;color:var(--text-muted);margin-top:6px;font-weight:500;"></div>
                </div>
                <div style="padding:14px 20px;">
                    <input type="text" id="fHeadline" name="headline" class="la-input"
                           value="<?php echo e($fHeadline); ?>"
                           placeholder="e.g. Close More Deals with WhatsApp Automation"
                           style="font-size:15px;font-weight:600;" required>
                </div>
            </div>

            <!-- Highlight word row -->
            <div style="display:grid;grid-template-columns:220px 1fr;gap:0;border-bottom:1px solid var(--border);">
                <div style="padding:16px 20px;border-right:1px solid var(--border);">
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:3px;">Highlight Phrase</div>
                    <div style="font-size:11px;color:var(--text-muted);line-height:1.5;">Exact substring of headline to color on the front end</div>
                </div>
                <div style="padding:14px 20px;">
                    <input type="text" id="fHighlight" name="highlight_word" class="la-input"
                           value="<?php echo e($fHighlight); ?>"
                           placeholder="e.g. WhatsApp Automation">
                </div>
            </div>

            <!-- Subtext row -->
            <div style="display:grid;grid-template-columns:220px 1fr;gap:0;">
                <div style="padding:16px 20px;border-right:1px solid var(--border);">
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:3px;">Subtext</div>
                    <div style="font-size:11px;color:var(--text-muted);line-height:1.5;">Supporting paragraph below the headline</div>
                </div>
                <div style="padding:14px 20px;">
                    <textarea id="fSubtext" name="subtext" class="la-textarea" rows="3"
                              placeholder="Supporting paragraph shown below the headline…"><?php echo e($fSubtext); ?></textarea>
                </div>
            </div>
        </div>

        <!-- CTA Buttons -->
        <div class="la-card">
            <div class="la-card-hd">
                <div>
                    <div style="font-size:14px;font-weight:600;color:var(--text-primary);">Call-to-Action Buttons</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Button text and links shown in the hero section</div>
                </div>
            </div>

            <!-- Primary CTA row -->
            <div style="display:grid;grid-template-columns:220px 1fr 1fr;gap:0;border-bottom:1px solid var(--border);">
                <div style="padding:14px 20px;border-right:1px solid var(--border);display:flex;align-items:center;gap:8px;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--accent);color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">P</span>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Primary Button</div>
                        <div style="font-size:11px;color:var(--text-muted);">Filled / main action</div>
                    </div>
                </div>
                <div style="padding:12px 16px;border-right:1px solid var(--border);">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Label</div>
                    <input type="text" id="fCtaPText" name="cta_primary_text" class="la-input"
                           value="<?php echo e($fCtaPText); ?>" placeholder="Start Free Trial">
                </div>
                <div style="padding:12px 16px;">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">URL</div>
                    <input type="text" id="fCtaPUrl" name="cta_primary_url" class="la-input"
                           value="<?php echo e($fCtaPUrl); ?>" placeholder="/register.php">
                </div>
            </div>

            <!-- Secondary CTA row -->
            <div style="display:grid;grid-template-columns:220px 1fr 1fr;gap:0;border-bottom:1px solid var(--border);">
                <div style="padding:14px 20px;border-right:1px solid var(--border);display:flex;align-items:center;gap:8px;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border);color:var(--text-muted);font-size:10px;font-weight:700;flex-shrink:0;">S</span>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--text-primary);">Secondary Button</div>
                        <div style="font-size:11px;color:var(--text-muted);">Outline / secondary action</div>
                    </div>
                </div>
                <div style="padding:12px 16px;border-right:1px solid var(--border);">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Label</div>
                    <input type="text" id="fCtaSText" name="cta_sec_text" class="la-input"
                           value="<?php echo e($fCtaSText); ?>" placeholder="See How It Works">
                </div>
                <div style="padding:12px 16px;">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">URL</div>
                    <input type="text" id="fCtaSUrl" name="cta_sec_url" class="la-input"
                           value="<?php echo e($fCtaSUrl); ?>" placeholder="#features">
                </div>
            </div>

            <!-- CTA preview -->
            <div style="padding:16px 20px;background:#fafbfc;border-radius:0 0 var(--radius-lg) var(--radius-lg);">
                <div style="font-size:11px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Live Preview</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <span id="prevCtaP" style="padding:9px 20px;border-radius:7px;background:var(--accent);color:#fff;font-weight:600;font-size:13px;display:inline-block;"></span>
                    <span id="prevCtaS" style="padding:9px 20px;border-radius:7px;border:1px solid var(--border);color:var(--text-secondary);font-size:13px;display:inline-block;"></span>
                </div>
            </div>
        </div>

        <!-- Stats Bar -->
        <div class="la-card">
            <div class="la-card-hd">
                <div>
                    <div style="font-size:14px;font-weight:600;color:var(--text-primary);">Stats Bar</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Up to 4 key metrics shown below the hero buttons</div>
                </div>
            </div>

            <!-- Stats header row -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border);background:#fafbfc;">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="padding:8px 16px;border-right:<?php echo $i < 3 ? '1px solid var(--border)' : 'none'; ?>;font-size:11px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;">
                    Stat <?php echo $i + 1; ?>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Value row -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border);">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="padding:10px 14px;border-right:<?php echo $i < 3 ? '1px solid var(--border)' : 'none'; ?>;">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);margin-bottom:5px;">VALUE</div>
                    <input type="text" name="stat_value[]" class="la-input"
                           value="<?php echo e($statsRows[$i]['value']); ?>"
                           placeholder="2,400+" style="font-size:15px;font-weight:700;text-align:center;">
                </div>
                <?php endfor; ?>
            </div>

            <!-- Label row -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border);">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="padding:10px 14px;border-right:<?php echo $i < 3 ? '1px solid var(--border)' : 'none'; ?>;">
                    <div style="font-size:10px;font-weight:600;color:var(--text-dim);margin-bottom:5px;">LABEL</div>
                    <input type="text" name="stat_label[]" class="la-input"
                           value="<?php echo e($statsRows[$i]['label']); ?>"
                           placeholder="Businesses" style="text-align:center;">
                </div>
                <?php endfor; ?>
            </div>

            <!-- Stats preview -->
            <div style="padding:16px 20px;background:#fafbfc;border-radius:0 0 var(--radius-lg) var(--radius-lg);">
                <div style="font-size:11px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Live Preview</div>
                <div id="statsPreview" style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;"></div>
            </div>
        </div>

    </div><!-- /left -->

    <!-- ── Right sidebar ──────────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Save + Active -->
        <div class="la-card">
            <div class="la-card-hd"><span>Publish</span></div>

            <div class="la-form-group" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <label class="la-label" style="margin:0;">Section Active</label>
                <label class="la-toggle">
                    <input type="checkbox" name="is_active" <?php echo $fIsActive ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </label>
            </div>

            <?php if ($hero && $hero['updated_at']): ?>
            <div style="padding:10px 12px;background:var(--accent-light);border:1px solid var(--info-border);border-radius:7px;margin:8px 0;">
                <div style="font-size:11px;color:var(--text-muted);">Last saved</div>
                <div style="font-size:12px;font-weight:600;color:var(--accent);"><?php echo date('d M Y, H:i', strtotime($hero['updated_at'])); ?></div>
            </div>
            <?php endif; ?>

            <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;margin-top:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                Save Hero
            </button>

            <a href="<?php echo $heroPages[$activePage]['url']; ?>" target="_blank"
               class="la-btn la-btn-outline" style="width:100%;text-align:center;margin-top:8px;display:block;">
                ↗ Preview Page
            </a>
        </div>

        <!-- Page tabs quick reference -->
        <div class="la-card">
            <div class="la-card-hd"><span>Other Pages</span></div>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <?php foreach ($heroPages as $pk => $pi):
                    if ($pk === $activePage) continue;
                ?>
                <a href="/landing-admin/hero.php?page=<?php echo urlencode($pk); ?>"
                   style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;color:var(--text-secondary);text-decoration:none;background:var(--bg-base);border:1px solid var(--border);transition:all .12s;"
                   onmouseover="this.style.background='var(--accent-light)';this.style.borderColor='var(--border-focus)';this.style.color='var(--accent)'"
                   onmouseout="this.style.background='var(--bg-base)';this.style.borderColor='var(--border)';this.style.color='var(--text-secondary)'">
                    <?php echo $pi['icon']; ?> <?php echo $pi['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Headline preview -->
        <div class="la-card">
            <div class="la-card-hd"><span>Live Preview</span></div>
            <div style="padding:16px 20px;min-height:80px;">
                <!-- Badge -->
                <div id="prevBadge" style="display:inline-block;padding:4px 12px;background:var(--accent-light);border:1px solid var(--info-border);border-radius:20px;font-size:11px;color:var(--accent);margin-bottom:10px;font-weight:500;"></div>
                <!-- Headline -->
                <div id="prevHeadline" style="font-size:17px;font-weight:700;color:var(--text-primary);line-height:1.35;margin-bottom:8px;"></div>
                <!-- Subtext -->
                <div id="prevSubtext" style="font-size:12px;color:var(--text-muted);line-height:1.6;"></div>
            </div>
        </div>

        <!-- Stats JSON viewer -->
        <?php if ($hero && $hero['stats_json']): ?>
        <div class="la-card">
            <div class="la-card-hd"><span>Stored JSON</span></div>
            <pre style="font-size:11px;color:var(--text-muted);background:var(--bg-base);border:1px solid var(--border);padding:12px 14px;border-radius:7px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:12px 16px 16px;"><?php echo e($hero['stats_json']); ?></pre>
        </div>
        <?php endif; ?>

    </div><!-- /sidebar -->
    </div><!-- /grid -->
</form>

<script>
/* ── Live preview updates ────────────────────────────────────────── */
function updatePreview() {
    const announce = document.getElementById('fAnnounce')?.value || '';
    const headline = document.getElementById('fHeadline')?.value || '';
    const highlight= document.getElementById('fHighlight')?.value || '';
    const subtext  = document.getElementById('fSubtext')?.value   || '';
    const ctaPText = document.getElementById('fCtaPText')?.value  || '';
    const ctaSText = document.getElementById('fCtaSText')?.value  || '';

    /* Badge */
    const badgeEl = document.getElementById('prevBadge');
    if (badgeEl) {
        badgeEl.textContent = announce;
        badgeEl.style.display = announce ? 'inline-block' : 'none';
    }

    /* Headline with highlight */
    const headlineEl = document.getElementById('prevHeadline');
    if (headlineEl) {
        if (highlight && headline.includes(highlight)) {
            headlineEl.innerHTML = headline.replace(
                highlight,
                '<span style="color:var(--accent);">' + escHtml(highlight) + '</span>'
            );
        } else {
            headlineEl.textContent = headline;
        }
    }

    /* Subtext */
    const subtextEl = document.getElementById('prevSubtext');
    if (subtextEl) subtextEl.textContent = subtext.slice(0, 160) + (subtext.length > 160 ? '…' : '');

    /* CTA buttons */
    const prevP = document.getElementById('prevCtaP');
    const prevS = document.getElementById('prevCtaS');
    if (prevP) prevP.textContent = ctaPText;
    if (prevS) prevS.textContent = ctaSText;

    /* Stats */
    updateStatsPreview();

    /* Headline char count */
    const lenEl = document.getElementById('headlineLen');
    if (lenEl) {
        const n = headline.length;
        lenEl.textContent = n + ' characters';
        lenEl.style.color = n > 100 ? '#fdcb6e' : 'var(--text-muted)';
    }
}

function updateStatsPreview() {
    const container = document.getElementById('statsPreview');
    if (!container) return;
    const values = document.querySelectorAll('input[name="stat_value[]"]');
    const labels = document.querySelectorAll('input[name="stat_label[]"]');
    let html = '';
    values.forEach((v, i) => {
        const val = v.value.trim();
        const lbl = labels[i]?.value.trim() || '';
        if (val || lbl) {
            html += `<div style="text-align:center;">
                <div style="font-size:20px;font-weight:700;color:var(--accent);">${escHtml(val)}</div>
                <div style="font-size:11px;color:var(--text-muted);">${escHtml(lbl)}</div>
            </div>`;
        }
    });
    container.innerHTML = html || '<span style="color:var(--text-muted);font-size:12px;">No stats set.</span>';
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Attach listeners */
['fAnnounce','fHeadline','fHighlight','fSubtext','fCtaPText','fCtaSText'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updatePreview);
});
document.querySelectorAll('input[name="stat_value[]"], input[name="stat_label[]"]').forEach(el => {
    el.addEventListener('input', updatePreview);
});

/* Initial render */
updatePreview();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
