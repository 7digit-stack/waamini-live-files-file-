<?php
/**
 * Landing Admin — Create / Edit Blog Post
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

/* ── Draft count (sidebar badge) ─────────────────────────────────── */
$stDC = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'");
$draftCount = (int)($stDC->fetch_assoc()['n'] ?? 0);

/* ── Load existing post? ──────────────────────────────────────────── */
$id   = max(0, (int)($_GET['id'] ?? 0));
$post = null;

if ($id > 0) {
    $stLoad = $conn->prepare("SELECT * FROM lp_blogs WHERE id=? LIMIT 1");
    $stLoad->bind_param('i', $id);
    $stLoad->execute();
    $post = $stLoad->get_result()->fetch_assoc();
    $stLoad->close();
    if (!$post) {
        la_flash_set('error', 'Post not found.');
        header('Location: /landing-admin/blogs.php');
        exit;
    }
}

$laTitle = $id > 0 ? 'Edit Post' : 'New Post';
$laPage  = 'blogs';

/* ── Load categories ──────────────────────────────────────────────── */
$categories = [];
$stCats = $conn->query("SELECT id, name, color FROM lp_blog_categories ORDER BY sort_order ASC, name ASC");
if ($stCats) while ($c = $stCats->fetch_assoc()) $categories[] = $c;

/* ── Handle POST (save) ───────────────────────────────────────────── */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();

    $title       = trim($_POST['title']       ?? '');
    $slugInput   = trim($_POST['slug']        ?? '');
    $catId       = (int)($_POST['category_id'] ?? 0) ?: null;
    $authorName  = trim($_POST['author_name'] ?? 'Waamini Team');
    $readTime    = trim($_POST['read_time']   ?? '5 min');
    $newStatus   = in_array($_POST['status'] ?? '', ['draft','published']) ? $_POST['status'] : 'draft';
    $featured    = isset($_POST['featured']) ? 1 : 0;
    $coverImage  = trim($_POST['cover_image'] ?? '');
    $excerpt     = trim($_POST['excerpt']     ?? '');
    $body        = trim($_POST['body_html']   ?? '');
    $metaTitle   = trim($_POST['meta_title']  ?? '');
    $metaDesc    = trim($_POST['meta_desc']   ?? '');

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        /* Slug generation */
        if ($slugInput !== '') {
            $slug = la_slugify($slugInput);
            /* ensure unique */
            $base = $slug; $i = 1;
            while (true) {
                $stChk = $conn->prepare("SELECT id FROM lp_blogs WHERE slug=? AND id!=? LIMIT 1");
                $excludeId = $id ?: 0;
                $stChk->bind_param('si', $slug, $excludeId);
                $stChk->execute();
                if ($stChk->get_result()->num_rows === 0) { $stChk->close(); break; }
                $slug = $base . '-' . (++$i);
                $stChk->close();
            }
        } else {
            $slug = la_unique_slug($conn, $title, $id ?: 0);
        }

        /* published_at logic */
        $publishedAt = $post['published_at'] ?? null;
        if ($newStatus === 'published' && !$publishedAt) {
            $publishedAt = date('Y-m-d H:i:s');
        } elseif ($newStatus === 'draft') {
            /* keep existing published_at so re-publishing keeps original date */
        }

        if ($id > 0) {
            /* UPDATE */
            $st = $conn->prepare("
                UPDATE lp_blogs SET
                    category_id=?, title=?, slug=?, excerpt=?, body=?,
                    cover_image=?, author_name=?, read_time=?, status=?,
                    featured=?, meta_title=?, meta_desc=?, published_at=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            $st->bind_param(
                'issssssssisssi',
                $catId, $title, $slug, $excerpt, $body,
                $coverImage, $authorName, $readTime, $newStatus,
                $featured, $metaTitle, $metaDesc, $publishedAt,
                $id
            );
            $st->execute();
            $st->close();
            la_flash_set('success', 'Post updated successfully.');
            header("Location: /landing-admin/blog-edit.php?id=$id");
            exit;
        } else {
            /* INSERT */
            $st = $conn->prepare("
                INSERT INTO lp_blogs
                    (category_id, title, slug, excerpt, body, cover_image,
                     author_name, read_time, status, featured,
                     meta_title, meta_desc, published_at, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ");
            $st->bind_param(
                'issssssssisss',
                $catId, $title, $slug, $excerpt, $body,
                $coverImage, $authorName, $readTime, $newStatus,
                $featured, $metaTitle, $metaDesc, $publishedAt
            );
            $st->execute();
            $newId = (int)$conn->insert_id;
            $st->close();
            la_flash_set('success', 'Post created successfully.');
            header("Location: /landing-admin/blog-edit.php?id=$newId");
            exit;
        }
    }
}

/* ── Field values (from saved post or POST on error) ──────────────── */
$fTitle      = $_POST['title']        ?? ($post['title']       ?? '');
$fSlug       = $_POST['slug']         ?? ($post['slug']        ?? '');
$fCatId      = (int)($_POST['category_id'] ?? ($post['category_id'] ?? 0));
$fAuthor     = $_POST['author_name']  ?? ($post['author_name'] ?? 'Waamini Team');
$fReadTime   = $_POST['read_time']    ?? ($post['read_time']   ?? '5 min');
$fStatus     = $_POST['status']       ?? ($post['status']      ?? 'draft');
$fFeatured   = isset($_POST['featured']) ? 1 : (int)($post['featured'] ?? 0);
$fCoverImage = $_POST['cover_image']  ?? ($post['cover_image'] ?? '');
$fExcerpt    = $_POST['excerpt']      ?? ($post['excerpt']     ?? '');
$fBody       = $_POST['body_html']    ?? ($post['body']        ?? '');
$fMetaTitle  = $_POST['meta_title']   ?? ($post['meta_title']  ?? '');
$fMetaDesc   = $_POST['meta_desc']    ?? ($post['meta_desc']   ?? '');

/* Rich editor toolbar buttons */
$laExtraJs = <<<'JS'
<script>
/* ── Rich Text Editor ──────────────────────────────────────────── */
const editor  = document.getElementById('richEditor');
const hiddenB = document.getElementById('body_html');

function syncBody() {
    hiddenB.value = editor.innerHTML;
}
editor.addEventListener('input', syncBody);

function execCmd(cmd, val = null) {
    editor.focus();
    document.execCommand(cmd, false, val);
    syncBody();
}

function insertHeading(tag) {
    editor.focus();
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    const node = document.createElement(tag);
    const text = sel.toString() || 'Heading';
    node.textContent = text;
    range.deleteContents();
    range.insertNode(node);
    // move caret after
    range.setStartAfter(node);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    syncBody();
}

function insertBlockquote() {
    editor.focus();
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    const bq = document.createElement('blockquote');
    bq.textContent = sel.toString() || 'Blockquote text';
    range.deleteContents();
    range.insertNode(bq);
    range.setStartAfter(bq);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    syncBody();
}

function insertLink() {
    const url = prompt('Enter URL:', 'https://');
    if (url) execCmd('createLink', url);
}

function insertImage() {
    const url = prompt('Enter image URL:', 'https://');
    if (url) execCmd('insertImage', url);
}

/* Auto-generate slug from title */
const titleInput = document.getElementById('fTitle');
const slugInput  = document.getElementById('fSlug');
let slugManual = <?php echo ($fSlug && $id > 0) ? 'true' : 'false'; ?>;

titleInput?.addEventListener('input', function () {
    if (!slugManual) {
        slugInput.value = slugify(this.value);
    }
});
slugInput?.addEventListener('input', function () {
    slugManual = true;
});
function slugify(str) {
    return str.toLowerCase()
              .replace(/[^\w\s-]/g, '')
              .replace(/[\s_]+/g, '-')
              .replace(/^-+|-+$/g, '');
}

/* Cover image preview */
document.getElementById('fCoverImage')?.addEventListener('input', function () {
    const prev = document.getElementById('coverPreview');
    if (prev) prev.src = this.value;
});

/* Save before submit — make sure body is synced */
document.getElementById('postForm')?.addEventListener('submit', function () {
    syncBody();
});
</script>
JS;

include __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<!-- Error display -->
<?php if (!empty($errors)): ?>
<div class="la-alert la-alert-error" style="margin-bottom:16px;">
    <?php foreach ($errors as $err): ?>
        <div style="display:flex;align-items:center;gap:6px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php echo e($err); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="la-page-hd">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;"><?php echo $id > 0 ? 'Edit Post' : 'New Post'; ?></h1>
        <div class="la-breadcrumb">
            <a href="/landing-admin/">Dashboard</a>
            <span>/</span>
            <a href="/landing-admin/blogs.php">Blog Posts</a>
            <span>/</span>
            <span><?php echo $id > 0 ? e($fTitle ?: 'Edit') : 'New Post'; ?></span>
        </div>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($id > 0): ?>
        <a href="/blogs.php?slug=<?php echo urlencode($fSlug); ?>" target="_blank" class="la-btn la-btn-outline">↗ View</a>
        <?php endif; ?>
        <a href="/landing-admin/blogs.php" class="la-btn la-btn-outline">← Back</a>
    </div>
</div>

<form method="POST" id="postForm" action="/landing-admin/blog-edit.php<?php echo $id ? "?id=$id" : ''; ?>">
    <?php echo la_csrf_field(); ?>
    <!-- hidden field for rich editor body -->
    <input type="hidden" name="body_html" id="body_html" value="<?php echo e($fBody); ?>">

    <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">

    <!-- ── Left: Main content ──────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Title + Slug -->
        <div class="la-card">
            <div class="la-card-hd"><span>Post Content</span></div>

            <div class="la-form-group">
                <label class="la-label" for="fTitle">Title <span style="color:#ff7675;">*</span></label>
                <input type="text" id="fTitle" name="title" class="la-input"
                       value="<?php echo e($fTitle); ?>" placeholder="Enter post title…" required autofocus>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="fSlug">Slug</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span style="color:var(--text-muted);font-size:13px;white-space:nowrap;">/blogs/</span>
                    <input type="text" id="fSlug" name="slug" class="la-input"
                           value="<?php echo e($fSlug); ?>" placeholder="auto-generated-slug">
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Leave blank to auto-generate from title.</div>
            </div>

            <div class="la-form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="la-form-group">
                    <label class="la-label" for="fAuthor">Author Name</label>
                    <input type="text" id="fAuthor" name="author_name" class="la-input"
                           value="<?php echo e($fAuthor); ?>" placeholder="Waamini Team">
                </div>
                <div class="la-form-group">
                    <label class="la-label" for="fReadTime">Read Time</label>
                    <input type="text" id="fReadTime" name="read_time" class="la-input"
                           value="<?php echo e($fReadTime); ?>" placeholder="5 min">
                </div>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="fExcerpt">Excerpt</label>
                <textarea id="fExcerpt" name="excerpt" class="la-textarea" rows="3"
                          placeholder="Short description for listing pages and SEO…"><?php echo e($fExcerpt); ?></textarea>
            </div>
        </div>

        <!-- Rich Text Body -->
        <div class="la-card">
            <div class="la-card-hd"><span>Body Content</span></div>

            <!-- Toolbar -->
            <div style="display:flex;flex-wrap:wrap;gap:4px;padding:8px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid rgba(255,255,255,.07);margin-bottom:10px;">
                <?php
                $toolbarBtns = [
                    ['B',  'execCmd(\'bold\')',         'Bold',       'font-weight:700;'],
                    ['I',  'execCmd(\'italic\')',       'Italic',     'font-style:italic;'],
                    ['U',  'execCmd(\'underline\')',    'Underline',  'text-decoration:underline;'],
                    ['H2', 'insertHeading(\'h2\')',     'Heading 2',  ''],
                    ['H3', 'insertHeading(\'h3\')',     'Heading 3',  ''],
                    ['"  "', 'insertBlockquote()',        'Blockquote', ''],
                    ['UL', 'execCmd(\'insertUnorderedList\')', 'Bullet List', ''],
                    ['OL', 'execCmd(\'insertOrderedList\')',   'Ordered List',''],
                    ['Link', 'insertLink()',             'Insert Link',  ''],
                    ['Img',  'insertImage()',            'Insert Image', ''],
                ];
                foreach ($toolbarBtns as [$label, $onclick, $title, $extraStyle]):
                ?>
                <button type="button"
                        onclick="<?php echo $onclick; ?>"
                        title="<?php echo $title; ?>"
                        style="padding:5px 10px;font-size:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:5px;color:var(--text-primary);cursor:pointer;<?php echo $extraStyle; ?>"
                        onmouseover="this.style.background='rgba(0,210,255,.15)'"
                        onmouseout="this.style.background='rgba(255,255,255,.06)'">
                    <?php echo $label; ?>
                </button>
                <?php endforeach; ?>
                <button type="button" onclick="editor.innerHTML='';syncBody();" title="Clear" style="padding:5px 10px;font-size:12px;background:rgba(255,80,80,.1);border:1px solid rgba(255,80,80,.2);border-radius:5px;color:#ff7675;cursor:pointer;margin-left:auto;">Clear</button>
            </div>

            <!-- Editable area -->
            <div id="richEditor" contenteditable="true"
                 style="min-height:340px;padding:16px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:8px;color:var(--text-primary);font-size:14px;line-height:1.7;outline:none;overflow-y:auto;"
                 oninput="syncBody()"
                 onfocus="this.style.borderColor='var(--accent)'"
                 onblur="this.style.borderColor='rgba(255,255,255,.08)'"
            ><?php echo $fBody; ?></div>

            <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">HTML is stored directly. Paste text or use the toolbar above.</div>
        </div>

        <!-- Cover Image -->
        <div class="la-card">
            <div class="la-card-hd"><span>Cover Image</span></div>

            <div class="la-form-group">
                <label class="la-label" for="fCoverImage">Image URL</label>
                <input type="text" id="fCoverImage" name="cover_image" class="la-input"
                       value="<?php echo e($fCoverImage); ?>" placeholder="https://…/image.jpg">
            </div>

            <?php if ($fCoverImage): ?>
            <div style="margin-top:8px;">
                <img id="coverPreview" src="<?php echo e($fCoverImage); ?>" alt="Cover preview"
                     style="max-width:100%;max-height:200px;border-radius:8px;display:block;object-fit:cover;border:1px solid rgba(255,255,255,.08);"
                     onerror="this.style.display='none'">
            </div>
            <?php else: ?>
            <div id="coverPreviewWrap" style="margin-top:8px;display:none;">
                <img id="coverPreview" src="" alt="Cover preview"
                     style="max-width:100%;max-height:200px;border-radius:8px;display:block;object-fit:cover;border:1px solid rgba(255,255,255,.08);">
            </div>
            <script>
            document.getElementById('fCoverImage')?.addEventListener('input', function () {
                const wrap = document.getElementById('coverPreviewWrap');
                const prev = document.getElementById('coverPreview');
                if (this.value) { wrap.style.display='block'; prev.src=this.value; }
                else { wrap.style.display='none'; }
            });
            </script>
            <?php endif; ?>

            <div style="margin-top:12px;">
                <a href="/landing-admin/media.php" target="_blank" class="la-btn la-btn-outline la-btn-sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                    Browse Media Library
                </a>
            </div>
        </div>

        <!-- SEO -->
        <div class="la-card">
            <div class="la-card-hd"><span>SEO / Meta</span></div>

            <div class="la-form-group">
                <label class="la-label" for="fMetaTitle">Meta Title</label>
                <input type="text" id="fMetaTitle" name="meta_title" class="la-input"
                       value="<?php echo e($fMetaTitle); ?>" placeholder="SEO title (60 chars max recommended)">
                <div id="metaTitleLen" style="font-size:11px;color:var(--text-muted);margin-top:4px;"></div>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="fMetaDesc">Meta Description</label>
                <textarea id="fMetaDesc" name="meta_desc" class="la-textarea" rows="3"
                          placeholder="SEO description (160 chars max recommended)…"><?php echo e($fMetaDesc); ?></textarea>
                <div id="metaDescLen" style="font-size:11px;color:var(--text-muted);margin-top:4px;"></div>
            </div>
        </div>

    </div><!-- /left column -->

    <!-- ── Right sidebar ──────────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Publish Settings -->
        <div class="la-card">
            <div class="la-card-hd"><span>Publish</span></div>

            <div class="la-form-group">
                <label class="la-label" for="fStatus">Status</label>
                <select id="fStatus" name="status" class="la-select">
                    <option value="draft"     <?php echo $fStatus === 'draft'     ? 'selected' : ''; ?>>Draft</option>
                    <option value="published" <?php echo $fStatus === 'published' ? 'selected' : ''; ?>>Published</option>
                </select>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="fCat">Category</label>
                <select id="fCat" name="category_id" class="la-select">
                    <option value="">— No Category —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"
                            <?php echo $fCatId === (int)$cat['id'] ? 'selected' : ''; ?>>
                        <?php echo e($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="la-form-group" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <label class="la-label" style="margin:0;">Featured Post</label>
                <label class="la-toggle">
                    <input type="checkbox" name="featured" <?php echo $fFeatured ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </label>
            </div>

            <?php if ($post && $post['published_at']): ?>
            <div style="padding:10px;background:rgba(0,230,118,.06);border:1px solid rgba(0,230,118,.15);border-radius:6px;margin:8px 0;">
                <div style="font-size:11px;color:var(--text-muted);">Published</div>
                <div style="font-size:12px;color:#00e676;font-weight:500;"><?php echo date('d M Y, H:i', strtotime($post['published_at'])); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($post && $post['updated_at']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                Last updated: <?php echo la_time_ago($post['updated_at']); ?>
            </div>
            <?php endif; ?>

            <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
                <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    <?php echo $id > 0 ? 'Update Post' : 'Create Post'; ?>
                </button>
                <a href="/landing-admin/blogs.php" class="la-btn la-btn-outline" style="width:100%;text-align:center;">
                    Cancel
                </a>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="la-card">
            <div class="la-card-hd"><span>Quick Links</span></div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-outline la-btn-sm" style="text-align:center;">+ New Post</a>
                <a href="/landing-admin/categories.php" class="la-btn la-btn-outline la-btn-sm" style="text-align:center;">Manage Categories</a>
                <a href="/landing-admin/media.php" class="la-btn la-btn-outline la-btn-sm" style="text-align:center;">Media Library</a>
                <a href="/landing-admin/blogs.php" class="la-btn la-btn-outline la-btn-sm" style="text-align:center;">All Posts</a>
                <?php if ($id > 0 && $fSlug): ?>
                <a href="/blogs.php?slug=<?php echo urlencode($fSlug); ?>" target="_blank" class="la-btn la-btn-outline la-btn-sm" style="text-align:center;">↗ View Post</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Danger zone -->
        <?php if ($id > 0): ?>
        <div class="la-card" style="border-color:rgba(255,80,80,.2);">
            <div class="la-card-hd" style="color:#ff7675;"><span>Danger Zone</span></div>
            <form method="POST" action="/landing-admin/blogs.php" onsubmit="return confirm('Permanently delete this post?');">
                <?php echo la_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <button type="submit" class="la-btn la-btn-danger" style="width:100%;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                    Delete This Post
                </button>
            </form>
        </div>
        <?php endif; ?>

    </div><!-- /sidebar -->
    </div><!-- /grid -->
</form>

<?php
$laExtraJs .= <<<'JS2'
<script>
/* Meta char counters */
function mkCounter(inputId, counterId, warn, danger) {
    const el = document.getElementById(inputId);
    const ct = document.getElementById(counterId);
    if (!el || !ct) return;
    function update() {
        const n = el.value.length;
        ct.textContent = n + ' / ' + danger + ' chars';
        ct.style.color = n > danger ? '#ff7675' : (n > warn ? '#fdcb6e' : 'var(--text-muted)');
    }
    el.addEventListener('input', update);
    update();
}
mkCounter('fMetaTitle', 'metaTitleLen', 50, 60);
mkCounter('fMetaDesc',  'metaDescLen',  140, 160);
</script>
JS2;
echo $laExtraJs;
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
