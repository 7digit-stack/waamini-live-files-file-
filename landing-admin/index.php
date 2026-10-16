<?php
/**
 * Landing Admin — Dashboard
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Dashboard';
$laPage  = 'dashboard';

/* ── Stats ─────────────────────────────────────────────────────────── */
$stTotal = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs");
$totalPosts = (int)($stTotal->fetch_assoc()['n'] ?? 0);

$stPub = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='published'");
$publishedPosts = (int)($stPub->fetch_assoc()['n'] ?? 0);

$stDraft = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'");
$draftPosts = (int)($stDraft->fetch_assoc()['n'] ?? 0);

$stMedia = $conn->query("SELECT COUNT(*) AS n FROM lp_media");
$totalMedia = (int)($stMedia->fetch_assoc()['n'] ?? 0);

$stCats = $conn->query("SELECT COUNT(*) AS n FROM lp_blog_categories");
$totalCats = (int)($stCats->fetch_assoc()['n'] ?? 0);

$stFeat = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE featured=1");
$featuredPosts = (int)($stFeat->fetch_assoc()['n'] ?? 0);

$draftCount = $draftPosts;

/* ── Recent Blog Posts (last 8) ─────────────────────────────────────── */
$recentBlogs = [];
$stRecent = $conn->query("
    SELECT b.id, b.title, b.slug, b.status, b.featured, b.author_name,
           b.published_at, b.created_at, b.views,
           c.name AS cat_name, c.color AS cat_color
    FROM lp_blogs b
    LEFT JOIN lp_blog_categories c ON c.id = b.category_id
    ORDER BY b.id DESC
    LIMIT 8
");
if ($stRecent) {
    while ($row = $stRecent->fetch_assoc()) $recentBlogs[] = $row;
}

/* ── Recent Media (last 6) ───────────────────────────────────────────── */
$recentMedia = [];
$stMedia6 = $conn->query("SELECT id, url, original_name, alt_text FROM lp_media ORDER BY id DESC LIMIT 6");
if ($stMedia6) {
    while ($row = $stMedia6->fetch_assoc()) $recentMedia[] = $row;
}

include __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<!-- Stats Row -->
<div class="la-stats">
    <div class="la-stat">
        <div class="la-stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $totalPosts; ?></div>
        <div class="la-stat-lbl">Total Posts</div>
    </div>
    <div class="la-stat">
        <div class="la-stat-icon" style="background:#ecfdf5;color:#10b981;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $publishedPosts; ?></div>
        <div class="la-stat-lbl">Published</div>
    </div>
    <div class="la-stat">
        <div class="la-stat-icon" style="background:#fffbeb;color:#f59e0b;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $draftPosts; ?></div>
        <div class="la-stat-lbl">Drafts</div>
    </div>
    <div class="la-stat">
        <div class="la-stat-icon" style="background:#fdf4ff;color:#a855f7;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $featuredPosts; ?></div>
        <div class="la-stat-lbl">Featured</div>
    </div>
    <div class="la-stat">
        <div class="la-stat-icon" style="background:#fff7ed;color:#f97316;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $totalMedia; ?></div>
        <div class="la-stat-lbl">Media Files</div>
    </div>
    <div class="la-stat">
        <div class="la-stat-icon" style="background:#f0fdf4;color:#22c55e;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>
        </div>
        <div class="la-stat-val"><?php echo $totalCats; ?></div>
        <div class="la-stat-lbl">Categories</div>
    </div>
</div>

<?php
/* Which parts of the public website are driven by this CMS */
$lcRows = [];
$lcBlock = static function (mysqli $c, string $k): array {
    $st = $c->prepare("SELECT is_active, updated_at FROM lp_page_sections WHERE page='home' AND section_key=? LIMIT 1");
    $st->bind_param('s', $k); $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ? ['on' => (int)$r['is_active'] === 1, 'at' => $r['updated_at']] : ['on' => false, 'at' => ''];
};
$lcCount = static fn(string $sql) => (int)($conn->query($sql)->fetch_assoc()['n'] ?? 0);
$lcHero = $conn->query("SELECT is_active, updated_at FROM lp_hero WHERE page='home' LIMIT 1")->fetch_assoc();
$lcRows[] = ['Hero headline & buttons', 'Home', $lcHero && (int)$lcHero['is_active'] === 1, $lcHero['updated_at'] ?? '', 'hero.php'];
foreach (['hero_extra' => 'Hero words & bullets', 'trust_stats' => 'Trust strip numbers', 'feature_hub' => 'Platform features hub', 'home_pricing' => 'Home pricing cards', 'faq' => 'FAQ', 'final_cta' => 'Final call to action'] as $k => $l) {
    $b = $lcBlock($conn, $k);
    $lcRows[] = [$l, 'Home', $b['on'], $b['at'], 'home-sections.php#' . $k];
}
$lcRows[] = ['Testimonials', 'Home', $lcCount("SELECT COUNT(*) n FROM lp_testimonials WHERE is_active=1") >= 2, '', 'testimonials.php'];
$lcRows[] = ['Pricing page', 'Pricing', $lcCount("SELECT COUNT(*) n FROM lp_pricing WHERE is_active=1") > 0, '', 'pricing.php'];
$lcRows[] = ['Blog', 'Blog', $lcCount("SELECT COUNT(*) n FROM lp_blogs WHERE status='published'") > 0, '', 'blogs.php'];
$lcRows[] = ['Alert bar', 'All pages', $lcCount("SELECT COUNT(*) n FROM lp_alerts WHERE is_active=1") > 0, '', 'alert.php'];
$lcRows[] = ['Site name, footer, email, analytics', 'All pages', true, '', 'settings.php'];
?>
<div class="la-card" style="margin-bottom:20px;">
    <div class="la-card-hd"><span>Live on the website</span>
        <span style="display:flex;gap:8px;"><a href="/" target="_blank" rel="noopener" class="la-btn la-btn-outline la-btn-sm">View site</a><a href="home-sections.php" class="la-btn la-btn-primary la-btn-sm">Edit home page</a></span></div>
    <div style="padding:16px 24px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;">
        <?php foreach ($lcRows as [$lbl, $where, $on, $at, $href]): ?>
        <a href="<?php echo e($href); ?>" style="text-decoration:none;background:<?php echo $on ? '#E6FCF2' : '#F3F4F6'; ?>;border-radius:12px;padding:12px 14px;display:block;">
            <div style="font-size:13px;font-weight:600;color:#111827;"><?php echo e($lbl); ?></div>
            <div style="font-size:12px;color:<?php echo $on ? '#128C7E' : '#6B7280'; ?>;margin-top:2px;"><?php echo e($where); ?> · <?php echo $on ? 'driven by CMS' : 'built-in content'; ?><?php echo $at ? ' · ' . e(la_time_ago($at)) : ''; ?></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;margin-top:20px;">

<!-- Left column -->
<div>
    <!-- Recent Blog Posts -->
    <div class="la-card">
        <div class="la-card-hd">
            <span>Recent Blog Posts</span>
            <a href="/landing-admin/blogs.php" class="la-btn la-btn-outline la-btn-sm">View All</a>
        </div>
        <?php if (empty($recentBlogs)): ?>
            <div class="la-empty">
                <div class="la-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                </div>
                <h3>No blog posts yet</h3>
                <p>Create your first post to get started.</p>
                <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-primary" style="margin-top:12px;">Create First Post</a>
            </div>
        <?php else: ?>
        <div class="la-table-wrap">
            <table class="la-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Author</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBlogs as $b): ?>
                    <tr>
                        <td style="color:var(--text-muted);font-size:12px;"><?php echo $b['id']; ?></td>
                        <td>
                            <div style="font-weight:500;color:var(--text-primary);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($b['title']); ?>">
                                <?php echo e($b['title']); ?>
                            </div>
                            <div style="font-size:11px;color:var(--text-muted);">/<?php echo e($b['slug']); ?></div>
                        </td>
                        <td>
                            <?php if ($b['cat_name']): ?>
                                <span class="la-badge" style="background:<?php echo e($b['cat_color'] ?? '#2563eb'); ?>22;color:<?php echo e($b['cat_color'] ?? '#2563eb'); ?>;border-color:<?php echo e($b['cat_color'] ?? '#2563eb'); ?>44;">
                                    <?php echo e($b['cat_name']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:var(--text-secondary);"><?php echo e($b['author_name']); ?></td>
                        <td>
                            <?php if ($b['status'] === 'published'): ?>
                                <span class="la-badge la-badge-published">Published</span>
                            <?php else: ?>
                                <span class="la-badge la-badge-draft">Draft</span>
                            <?php endif; ?>
                            <?php if ($b['featured']): ?>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1" style="margin-left:4px;vertical-align:middle;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
                            <?php
                            $dt = $b['published_at'] ?? $b['created_at'];
                            echo $dt ? la_time_ago($dt) : '—';
                            ?>
                        </td>
                        <td>
                            <a href="/landing-admin/blog-edit.php?id=<?php echo $b['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Media -->
    <?php if (!empty($recentMedia)): ?>
    <div class="la-card" style="margin-top:20px;">
        <div class="la-card-hd">
            <span>Recent Media</span>
            <a href="/landing-admin/media.php" class="la-btn la-btn-outline la-btn-sm">Media Library</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;padding:16px;">
            <?php foreach ($recentMedia as $m): ?>
            <a href="/landing-admin/media.php" title="<?php echo e($m['original_name'] ?? $m['alt_text']); ?>" style="display:block;border-radius:8px;overflow:hidden;aspect-ratio:1;background:var(--bg-base);border:1px solid var(--border);">
                <img src="<?php echo e($m['url']); ?>" alt="<?php echo e($m['alt_text']); ?>"
                     style="width:100%;height:100%;object-fit:cover;display:block;"
                     onerror="this.style.display='none'">
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Right column — Quick Actions -->
<div>
    <div class="la-card">
        <div class="la-card-hd"><span>Quick Actions</span></div>
        <div style="display:flex;flex-direction:column;gap:8px;padding:16px 16px 20px;">
            <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-primary" style="justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                New Blog Post
            </a>
            <a href="/landing-admin/hero.php" class="la-btn la-btn-outline" style="justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                Manage Hero
            </a>
            <a href="/landing-admin/categories.php" class="la-btn la-btn-outline" style="justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>
                Categories
            </a>
            <a href="/landing-admin/media.php" class="la-btn la-btn-outline" style="justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                Media Library
            </a>
            <a href="/landing-admin/settings.php" class="la-btn la-btn-outline" style="justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                Site Settings
            </a>
        </div>
    </div>

    <!-- Content Overview -->
    <div class="la-card" style="margin-top:20px;">
        <div class="la-card-hd"><span>Content Overview</span></div>
        <div style="display:flex;flex-direction:column;padding:8px 12px 12px;">
            <?php
            $overview = [
                ['blogs',        'Blog Posts',  $totalPosts,     '/landing-admin/blogs.php'],
                ['published',    'Published',   $publishedPosts, '/landing-admin/blogs.php?status=published'],
                ['drafts',       'Drafts',      $draftPosts,     '/landing-admin/blogs.php?status=draft'],
                ['featured',     'Featured',    $featuredPosts,  '/landing-admin/blogs.php?status=featured'],
                ['categories',   'Categories',  $totalCats,      '/landing-admin/categories.php'],
                ['media',        'Media Files', $totalMedia,     '/landing-admin/media.php'],
            ];
            $ov_icons = [
                'blogs'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>',
                'published'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
                'drafts'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                'featured'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>',
                'categories' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
                'media'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
            ];
            foreach ($overview as [$key, $label, $count, $link]):
            ?>
            <a href="<?php echo $link; ?>" style="display:flex;align-items:center;justify-content:space-between;text-decoration:none;padding:9px 8px;border-radius:7px;transition:background .12s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='transparent'">
                <span style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);"><?php echo $ov_icons[$key]; ?> <?php echo $label; ?></span>
                <span style="font-weight:600;color:var(--text-primary);font-size:13px;"><?php echo $count; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Quick links to live site -->
    <div class="la-card" style="margin-top:20px;">
        <div class="la-card-hd"><span>View Live Site</span></div>
        <div style="display:flex;flex-direction:column;padding:8px 12px 12px;">
            <?php
            $pages = [
                ['/index.php',    'Home'],
                ['/blogs.php',    'Blog'],
                ['/pricing.php',  'Pricing'],
                ['/features.php', 'Features'],
            ];
            $extIcon = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4;"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>';
            foreach ($pages as [$href, $label]):
            ?>
            <a href="<?php echo $href; ?>" target="_blank" style="display:flex;align-items:center;justify-content:space-between;font-size:13px;color:var(--text-muted);text-decoration:none;padding:9px 8px;border-radius:7px;transition:background .12s,color .12s;" onmouseover="this.style.color='var(--accent)';this.style.background='var(--bg-hover)'" onmouseout="this.style.color='var(--text-muted)';this.style.background='transparent'">
                <?php echo $label; ?>
                <?php echo $extIcon; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</div><!-- /grid -->

<?php include __DIR__ . '/includes/footer.php'; ?>
