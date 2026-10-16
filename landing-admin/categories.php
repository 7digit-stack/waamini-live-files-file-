<?php
/**
 * Landing Admin — Blog Categories
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Blog Categories';
$laPage  = 'categories';

/* ── Draft count (sidebar badge) ─────────────────────────────────── */
$stDC = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'");
$draftCount = (int)($stDC->fetch_assoc()['n'] ?? 0);

/* ── Preset colors ────────────────────────────────────────────────── */
$presetColors = ['#00d2ff','#00b894','#a29bfe','#fdcb6e','#ff7675','#fd79a8','#0984e3','#e17055'];

/* ── Handle POST ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();

    $action = $_POST['action'] ?? '';

    /* CREATE */
    if ($action === 'create') {
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#00d2ff');
        $slug  = trim($_POST['slug']  ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') {
            la_flash_set('error', 'Category name is required.');
        } else {
            /* Generate/normalise slug */
            if ($slug === '') $slug = la_slugify($name);
            /* Ensure unique slug for categories */
            $baseSlug = $slug; $i = 1;
            while (true) {
                $stChk = $conn->prepare("SELECT id FROM lp_blog_categories WHERE slug=? LIMIT 1");
                $stChk->bind_param('s', $slug);
                $stChk->execute();
                if ($stChk->get_result()->num_rows === 0) { $stChk->close(); break; }
                $slug = $baseSlug . '-' . (++$i);
                $stChk->close();
            }
            $st = $conn->prepare("INSERT INTO lp_blog_categories (name, slug, color, sort_order) VALUES (?,?,?,?)");
            $st->bind_param('sssi', $name, $slug, $color, $sort);
            $st->execute();
            $st->close();
            la_flash_set('success', "Category \"$name\" created.");
        }
        header('Location: /landing-admin/categories.php');
        exit;
    }

    /* UPDATE */
    if ($action === 'update') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $name  = trim($_POST['name']    ?? '');
        $color = trim($_POST['color']   ?? '#00d2ff');
        $slug  = trim($_POST['slug']    ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if (!$catId || $name === '') {
            la_flash_set('error', 'Invalid request.');
        } else {
            if ($slug === '') $slug = la_slugify($name);
            /* Ensure unique slug (exclude self) */
            $baseSlug = $slug; $i = 1;
            while (true) {
                $stChk = $conn->prepare("SELECT id FROM lp_blog_categories WHERE slug=? AND id!=? LIMIT 1");
                $stChk->bind_param('si', $slug, $catId);
                $stChk->execute();
                if ($stChk->get_result()->num_rows === 0) { $stChk->close(); break; }
                $slug = $baseSlug . '-' . (++$i);
                $stChk->close();
            }
            $st = $conn->prepare("UPDATE lp_blog_categories SET name=?, slug=?, color=?, sort_order=? WHERE id=?");
            $st->bind_param('sssii', $name, $slug, $color, $sort, $catId);
            $st->execute();
            $st->close();
            la_flash_set('success', "Category updated.");
        }
        header('Location: /landing-admin/categories.php');
        exit;
    }

    /* DELETE */
    if ($action === 'delete') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        if ($catId) {
            /* Check if any posts reference it */
            $stRef = $conn->prepare("SELECT COUNT(*) AS n FROM lp_blogs WHERE category_id=? LIMIT 1");
            $stRef->bind_param('i', $catId);
            $stRef->execute();
            $refCount = (int)($stRef->get_result()->fetch_assoc()['n'] ?? 0);
            $stRef->close();

            if ($refCount > 0) {
                la_flash_set('error', "Cannot delete: $refCount post(s) are using this category. Reassign them first.");
            } else {
                $st = $conn->prepare("DELETE FROM lp_blog_categories WHERE id=?");
                $st->bind_param('i', $catId);
                $st->execute();
                $st->close();
                la_flash_set('success', 'Category deleted.');
            }
        }
        header('Location: /landing-admin/categories.php');
        exit;
    }
}

/* ── Load categories with post count ─────────────────────────────── */
$categories = [];
$stList = $conn->query("
    SELECT c.*, COUNT(b.id) AS post_count
    FROM lp_blog_categories c
    LEFT JOIN lp_blogs b ON b.category_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.name ASC
");
if ($stList) while ($row = $stList->fetch_assoc()) $categories[] = $row;

/* ── Editing a category? ──────────────────────────────────────────── */
$editId  = (int)($_GET['edit'] ?? 0);
$editCat = null;
if ($editId) {
    foreach ($categories as $c) {
        if ((int)$c['id'] === $editId) { $editCat = $c; break; }
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div class="la-page-hd">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Blog Categories</h1>
        <div class="la-breadcrumb">
            <a href="/landing-admin/">Dashboard</a>
            <span>/</span>
            <span>Categories</span>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

<!-- ── Left: Category list ────────────────────────────────────────── -->
<div class="la-card">
    <div class="la-card-hd">
        <span>All Categories <span style="color:var(--text-muted);font-weight:400;">(<?php echo count($categories); ?>)</span></span>
    </div>

    <?php if (empty($categories)): ?>
        <div class="la-empty">
            <div class="la-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg></div>
            <h3>No categories yet</h3><p>Create your first category to organize your posts.</p>
        </div>
    <?php else: ?>
    <div class="la-table-wrap">
        <table class="la-table">
            <thead>
                <tr>
                    <th style="width:36px;">Color</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th style="width:80px;text-align:center;">Posts</th>
                    <th style="width:80px;text-align:center;">Order</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr <?php echo $editId === (int)$cat['id'] ? 'style="background:rgba(0,210,255,.06);"' : ''; ?>>
                    <td>
                        <div style="width:24px;height:24px;border-radius:50%;background:<?php echo e($cat['color']); ?>;border:2px solid <?php echo e($cat['color']); ?>44;margin:0 auto;" title="<?php echo e($cat['color']); ?>"></div>
                    </td>
                    <td>
                        <span style="font-weight:500;color:var(--text-primary);"><?php echo e($cat['name']); ?></span>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);"><?php echo e($cat['slug']); ?></td>
                    <td style="text-align:center;">
                        <?php if ((int)$cat['post_count'] > 0): ?>
                            <a href="/landing-admin/blogs.php?cat=<?php echo $cat['id']; ?>"
                               style="color:var(--accent);font-weight:600;font-size:13px;"><?php echo $cat['post_count']; ?></a>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:13px;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-size:12px;color:var(--text-muted);"><?php echo (int)$cat['sort_order']; ?></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <a href="/landing-admin/categories.php?edit=<?php echo $cat['id']; ?>"
                               class="la-btn la-btn-outline la-btn-sm">Edit</a>
                            <?php if ((int)$cat['post_count'] === 0): ?>
                            <form method="POST" onsubmit="return confirm('Delete category \'<?php echo e(addslashes($cat['name'])); ?>\'?');">
                                <?php echo la_csrf_field(); ?>
                                <input type="hidden" name="action"  value="delete">
                                <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="la-btn la-btn-danger la-btn-sm la-btn-icon" title="Delete"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                            </form>
                            <?php else: ?>
                            <span class="la-btn la-btn-sm la-btn-icon" style="opacity:.3;cursor:not-allowed;" title="Cannot delete — has posts"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Right: Add / Edit form ──────────────────────────────────────── -->
<div>
    <div class="la-card">
        <div class="la-card-hd">
            <span><?php echo $editCat ? 'Edit Category' : 'Add New Category'; ?></span>
            <?php if ($editCat): ?>
                <a href="/landing-admin/categories.php" class="la-btn la-btn-outline la-btn-sm">+ New</a>
            <?php endif; ?>
        </div>

        <form method="POST" action="/landing-admin/categories.php" id="catForm">
            <?php echo la_csrf_field(); ?>
            <input type="hidden" name="action"  value="<?php echo $editCat ? 'update' : 'create'; ?>">
            <?php if ($editCat): ?>
                <input type="hidden" name="cat_id" value="<?php echo $editCat['id']; ?>">
            <?php endif; ?>

            <div class="la-form-group">
                <label class="la-label" for="catName">Name <span style="color:#ff7675;">*</span></label>
                <input type="text" id="catName" name="name" class="la-input"
                       value="<?php echo e($editCat['name'] ?? ''); ?>"
                       placeholder="e.g. Growth Tips" required autofocus>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="catSlug">Slug</label>
                <input type="text" id="catSlug" name="slug" class="la-input"
                       value="<?php echo e($editCat['slug'] ?? ''); ?>"
                       placeholder="auto-generated">
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Leave blank to auto-generate.</div>
            </div>

            <div class="la-form-group">
                <label class="la-label">Color</label>
                <!-- Preset swatches -->
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;" id="swatchRow">
                    <?php foreach ($presetColors as $pc): ?>
                    <div class="color-swatch"
                         data-color="<?php echo $pc; ?>"
                         onclick="pickColor('<?php echo $pc; ?>')"
                         style="width:28px;height:28px;border-radius:50%;background:<?php echo $pc; ?>;cursor:pointer;border:2px solid <?php echo ($editCat['color'] ?? '#00d2ff') === $pc ? '#fff' : 'transparent'; ?>;transition:border-color .15s;"
                         title="<?php echo $pc; ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Hex input -->
                <div style="display:flex;gap:8px;align-items:center;">
                    <div id="colorDot" style="width:32px;height:32px;border-radius:50%;background:<?php echo e($editCat['color'] ?? '#00d2ff'); ?>;border:2px solid rgba(255,255,255,.15);flex-shrink:0;"></div>
                    <input type="text" id="catColor" name="color" class="la-input"
                           value="<?php echo e($editCat['color'] ?? '#00d2ff'); ?>"
                           placeholder="#00d2ff" maxlength="7"
                           oninput="updateColorDot(this.value)"
                           style="font-family:monospace;">
                    <input type="color" value="<?php echo e($editCat['color'] ?? '#00d2ff'); ?>"
                           oninput="pickColor(this.value)"
                           title="Color picker"
                           style="width:36px;height:36px;padding:2px;border:none;background:transparent;cursor:pointer;">
                </div>
            </div>

            <div class="la-form-group">
                <label class="la-label" for="catSort">Sort Order</label>
                <input type="number" id="catSort" name="sort_order" class="la-input"
                       value="<?php echo (int)($editCat['sort_order'] ?? 0); ?>" min="0" step="1">
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Lower number = shown first.</div>
            </div>

            <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                <?php echo $editCat ? 'Update Category' : 'Create Category'; ?>
            </button>

            <?php if ($editCat): ?>
            <a href="/landing-admin/categories.php" class="la-btn la-btn-outline" style="width:100%;text-align:center;margin-top:8px;display:block;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($editCat && (int)$editCat['post_count'] > 0): ?>
    <div class="la-card" style="margin-top:16px;">
        <div class="la-card-hd"><span>Posts in this Category</span></div>
        <div style="text-align:center;padding:8px 0;">
            <span style="font-size:32px;font-weight:700;color:var(--accent);"><?php echo $editCat['post_count']; ?></span>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">blog posts</div>
            <a href="/landing-admin/blogs.php" class="la-btn la-btn-outline la-btn-sm" style="margin-top:10px;">View Posts</a>
        </div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /grid -->

<script>
/* Auto-slug from name */
const catNameEl = document.getElementById('catName');
const catSlugEl = document.getElementById('catSlug');
let slugManual = <?php echo $editCat ? 'true' : 'false'; ?>;

catNameEl?.addEventListener('input', function () {
    if (!slugManual) {
        catSlugEl.value = slugify(this.value);
    }
});
catSlugEl?.addEventListener('input', function () {
    slugManual = true;
});

function slugify(str) {
    return str.toLowerCase()
              .replace(/[^\w\s-]/g, '')
              .replace(/[\s_]+/g, '-')
              .replace(/^-+|-+$/g, '');
}

/* Color swatch picker */
function pickColor(hex) {
    document.getElementById('catColor').value = hex;
    document.getElementById('colorDot').style.background = hex;
    // update active swatch border
    document.querySelectorAll('.color-swatch').forEach(sw => {
        sw.style.borderColor = sw.dataset.color === hex ? '#fff' : 'transparent';
    });
}

function updateColorDot(hex) {
    if (/^#[0-9a-fA-F]{6}$/.test(hex)) {
        document.getElementById('colorDot').style.background = hex;
        document.querySelectorAll('.color-swatch').forEach(sw => {
            sw.style.borderColor = sw.dataset.color === hex ? '#fff' : 'transparent';
        });
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
