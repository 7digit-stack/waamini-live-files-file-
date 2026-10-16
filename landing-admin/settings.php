<?php
/** Landing Admin — Site Settings */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
la_require_login();

$laTitle = 'Site Settings';
$laPage  = 'settings';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

/* ── Setting groups / tabs ────────────────────────────────────────────── */
$tabIcons = [
    'general'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
    'seo'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'social'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/></svg>',
    'blog'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>',
    'security' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
];
$tabs = [
    'general'  => ['label' => 'General',  'icon' => $tabIcons['general']],
    'seo'      => ['label' => 'SEO',      'icon' => $tabIcons['seo']],
    'social'   => ['label' => 'Social',   'icon' => $tabIcons['social']],
    'blog'     => ['label' => 'Blog',     'icon' => $tabIcons['blog']],
    'security' => ['label' => 'Security', 'icon' => $tabIcons['security']],
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $fields = $_POST['settings'] ?? [];
    if (is_array($fields)) {
        foreach ($fields as $k => $v) {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($k)));
            if ($k) la_set_setting($conn, $k, trim((string)$v));
        }
        la_flash_set('success', ucfirst($activeTab) . ' settings saved.');
    }
    header('Location: settings.php?tab=' . urlencode($activeTab)); exit;
}

/* ── Load all settings ────────────────────────────────────────────────── */
$all = [];
$res = $conn->query("SELECT `key`,`value` FROM lp_settings");
if ($res) while ($r = $res->fetch_assoc()) $all[$r['key']] = $r['value'];

function sval(array $all, string $key, string $default = ''): string {
    return $all[$key] ?? $default;
}

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="margin-bottom:20px;">
    <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Site Settings</h1>
    <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Settings</span></div>
</div>

<div style="display:grid;grid-template-columns:200px 1fr;gap:20px;align-items:start;">

<!-- Tab sidebar -->
<div class="la-card" style="padding:8px;">
    <?php foreach ($tabs as $key => $tab): ?>
    <a href="?tab=<?php echo $key; ?>"
       style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;text-decoration:none;color:<?php echo $activeTab === $key ? 'var(--accent)' : 'var(--text-secondary)'; ?>;background:<?php echo $activeTab === $key ? 'var(--bg-active)' : 'transparent'; ?>;font-size:13px;font-weight:<?php echo $activeTab === $key ? '600' : '400'; ?>;margin-bottom:2px;transition:background .12s;"
       onmouseover="<?php echo $activeTab !== $key ? "this.style.background='var(--bg-hover)'" : ''; ?>"
       onmouseout="<?php echo $activeTab !== $key ? "this.style.background='transparent'" : ''; ?>"
        <span><?php echo $tab['icon']; ?></span><?php echo $tab['label']; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Settings form -->
<div class="la-card">
    <div class="la-card-hd">
        <span><?php echo $tabs[$activeTab]['icon']; ?> <?php echo $tabs[$activeTab]['label']; ?> Settings</span>
    </div>
    <form method="POST" action="?tab=<?php echo $activeTab; ?>" style="padding:24px;display:flex;flex-direction:column;gap:18px;">
        <?php echo la_csrf_field(); ?>

        <?php if ($activeTab === 'general'): ?>
        <div class="la-form-group">
            <label class="la-label">Site Name</label>
            <input type="text" name="settings[site_name]" class="la-input" value="<?php echo e(sval($all,'site_name','Waamini')); ?>" placeholder="Your brand name">
        </div>
        <div class="la-form-group">
            <label class="la-label">Tagline</label>
            <input type="text" name="settings[tagline]" class="la-input" value="<?php echo e(sval($all,'tagline')); ?>" placeholder="Short one-line description">
        </div>
        <div class="la-form-group">
            <label class="la-label">Support Email</label>
            <input type="email" name="settings[support_email]" class="la-input" value="<?php echo e(sval($all,'support_email')); ?>" placeholder="support@example.com">
        </div>
        <div class="la-form-group">
            <label class="la-label">Logo URL</label>
            <input type="url" name="settings[logo_url]" class="la-input" value="<?php echo e(sval($all,'logo_url')); ?>" placeholder="https://...">
            <?php if (sval($all,'logo_url')): ?><img src="<?php echo e(sval($all,'logo_url')); ?>" alt="Logo" style="height:40px;margin-top:8px;border-radius:4px;"><?php endif; ?>
        </div>
        <div class="la-form-group">
            <label class="la-label">Footer Text</label>
            <input type="text" name="settings[footer_text]" class="la-input" value="<?php echo e(sval($all,'footer_text')); ?>" placeholder="© 2026 Waamini. All rights reserved.">
        </div>

        <?php elseif ($activeTab === 'seo'): ?>
        <div class="la-form-group">
            <label class="la-label">Default Meta Title</label>
            <input type="text" name="settings[meta_title]" class="la-input" value="<?php echo e(sval($all,'meta_title')); ?>" placeholder="Page Title | Site Name" maxlength="70">
            <div class="la-char-counter" data-max="60">0 / 60 chars (title)</div>
        </div>
        <div class="la-form-group">
            <label class="la-label">Default Meta Description</label>
            <textarea name="settings[meta_description]" class="la-textarea" rows="3" maxlength="165"
                placeholder="Default description shown in Google results"><?php echo e(sval($all,'meta_description')); ?></textarea>
            <div class="la-char-counter" data-max="160">0 / 160 chars</div>
        </div>
        <div class="la-form-group">
            <label class="la-label">OG / Social Share Image URL</label>
            <input type="url" name="settings[og_image]" class="la-input" value="<?php echo e(sval($all,'og_image')); ?>" placeholder="https://...">
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Ideal size: 1200×630 px</div>
        </div>
        <div class="la-form-group">
            <label class="la-label">Google Analytics ID</label>
            <input type="text" name="settings[ga_id]" class="la-input" value="<?php echo e(sval($all,'ga_id')); ?>" placeholder="G-XXXXXXXXXX">
        </div>
        <div class="la-form-group">
            <label class="la-label">Google Search Console Verification</label>
            <input type="text" name="settings[gsc_verify]" class="la-input" value="<?php echo e(sval($all,'gsc_verify')); ?>" placeholder="content=xxxxxxxx (from meta tag)">
        </div>

        <?php elseif ($activeTab === 'social'): ?>
        <?php foreach ([
            'twitter_handle'  => ['Twitter / X Handle', 'e.g. @waamini', 'text'],
            'linkedin_url'    => ['LinkedIn URL', 'https://linkedin.com/company/...', 'url'],
            'instagram_url'   => ['Instagram URL', 'https://instagram.com/...', 'url'],
            'facebook_url'    => ['Facebook URL', 'https://facebook.com/...', 'url'],
            'youtube_url'     => ['YouTube URL', 'https://youtube.com/@...', 'url'],
            'whatsapp_number' => ['WhatsApp Business Number', '+91 XXXXXXXXXX', 'text'],
        ] as $key => [$label, $placeholder, $type]): ?>
        <div class="la-form-group">
            <label class="la-label"><?php echo $label; ?></label>
            <input type="<?php echo $type; ?>" name="settings[<?php echo $key; ?>]" class="la-input"
                value="<?php echo e(sval($all, $key)); ?>" placeholder="<?php echo e($placeholder); ?>">
        </div>
        <?php endforeach; ?>

        <?php elseif ($activeTab === 'blog'): ?>
        <div class="la-form-group">
            <label class="la-label">Posts Per Page</label>
            <input type="number" name="settings[blog_per_page]" class="la-input" min="1" max="50"
                value="<?php echo e(sval($all,'blog_per_page','9')); ?>">
        </div>
        <div class="la-form-group">
            <label class="la-label">Default Author Name</label>
            <input type="text" name="settings[blog_default_author]" class="la-input"
                value="<?php echo e(sval($all,'blog_default_author','Waamini Team')); ?>" placeholder="Waamini Team">
        </div>
        <div class="la-form-group">
            <label class="la-label">Blog Index Title</label>
            <input type="text" name="settings[blog_index_title]" class="la-input"
                value="<?php echo e(sval($all,'blog_index_title','WhatsApp Growth Insights')); ?>">
        </div>
        <div class="la-form-group">
            <label class="la-label">Blog Index Subtitle</label>
            <textarea name="settings[blog_index_subtitle]" class="la-textarea" rows="2"
                ><?php echo e(sval($all,'blog_index_subtitle','Expert tips & strategies for WhatsApp Business success')); ?></textarea>
        </div>

        <?php elseif ($activeTab === 'security'): ?>
        <div class="la-alert la-alert-info"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg> These settings apply to the public-facing landing pages only, not the main CRM admin.</div>
        <div class="la-form-group">
            <label class="la-label">Maintenance Mode</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <div class="la-toggle">
                    <input type="checkbox" name="settings[maintenance_mode]" value="1"
                        <?php echo sval($all,'maintenance_mode') === '1' ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </div>
                <span style="font-size:13px;">Enable Maintenance Mode (shows "coming soon" to visitors)</span>
            </label>
        </div>
        <div class="la-form-group">
            <label class="la-label">Maintenance Message</label>
            <textarea name="settings[maintenance_msg]" class="la-textarea" rows="3"
                placeholder="We're updating the site. Check back soon!"><?php echo e(sval($all,'maintenance_msg')); ?></textarea>
        </div>
        <div class="la-form-group">
            <label class="la-label">Allowed IPs (bypass maintenance)</label>
            <textarea name="settings[maintenance_ips]" class="la-textarea" rows="3"
                placeholder="One IP per line, e.g. 192.168.1.1"><?php echo e(sval($all,'maintenance_ips')); ?></textarea>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid var(--border);">
            <button type="submit" class="la-btn la-btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                Save <?php echo $tabs[$activeTab]['label']; ?> Settings
            </button>
            <a href="?tab=<?php echo $activeTab; ?>" class="la-btn la-btn-outline">Reset</a>
        </div>
    </form>
</div>

</div>

<script>
// Character counters
document.querySelectorAll('.la-char-counter').forEach(el => {
    const max = parseInt(el.dataset.max);
    const input = el.previousElementSibling;
    if (!input) return;
    function update() {
        const len = input.value.length;
        el.textContent = len + ' / ' + max + ' chars' + (el.textContent.includes('(') ? ' ' + el.textContent.split('chars')[1] : '');
        el.style.color = len > max ? '#ff7675' : len > max * .9 ? '#fdcb6e' : 'var(--text-muted)';
    }
    input.addEventListener('input', update);
    update();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
