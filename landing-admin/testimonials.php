<?php
/** Landing Admin — Testimonials */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
la_require_login();

$laTitle = 'Testimonials';
$laPage  = 'testimonials';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create','update'], true)) {
        $id       = (int)($_POST['tid'] ?? 0);
        $name     = trim($_POST['name']       ?? '');
        $role     = trim($_POST['role']       ?? '');
        $company  = trim($_POST['company']    ?? '');
        $avatar   = trim($_POST['avatar_url'] ?? '');
        $content  = trim($_POST['content']    ?? '');
        $rating   = min(5, max(1, (int)($_POST['rating'] ?? 5)));
        $industry = trim($_POST['industry']   ?? '');
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if (!$name || !$content) {
            la_flash_set('error', 'Name and testimonial text are required.');
            header('Location: testimonials.php'); exit;
        }

        if ($action === 'create') {
            $st = $conn->prepare("INSERT INTO lp_testimonials (name,role,company,avatar_url,content,rating,industry,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sssssisii', $name, $role, $company, $avatar, $content, $rating, $industry, $sort, $active);
            $st->execute(); $st->close();
            la_flash_set('success', 'Testimonial added.');
        } else {
            if (!$id) { la_flash_set('error', 'Invalid ID.'); header('Location: testimonials.php'); exit; }
            $st = $conn->prepare("UPDATE lp_testimonials SET name=?,role=?,company=?,avatar_url=?,content=?,rating=?,industry=?,sort_order=?,is_active=? WHERE id=?");
            $st->bind_param('sssssisiii', $name, $role, $company, $avatar, $content, $rating, $industry, $sort, $active, $id);
            $st->execute(); $st->close();
            la_flash_set('success', 'Testimonial updated.');
        }
        header('Location: testimonials.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['tid'] ?? 0);
        if ($id) {
            $st = $conn->prepare("DELETE FROM lp_testimonials WHERE id=?");
            $st->bind_param('i', $id); $st->execute(); $st->close();
        }
        la_flash_set('success', 'Testimonial deleted.'); header('Location: testimonials.php'); exit;

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['tid'] ?? 0);
        if ($id) $conn->query("UPDATE lp_testimonials SET is_active = NOT is_active WHERE id=$id");
        header('Location: testimonials.php'); exit;
    }
}

/* ── Load data ────────────────────────────────────────────────────────── */
$testimonials = [];
$res = $conn->query("SELECT * FROM lp_testimonials ORDER BY sort_order ASC, id DESC");
if ($res) while ($r = $res->fetch_assoc()) $testimonials[] = $r;

$editId = (int)($_GET['edit'] ?? 0);
$editT  = null;
foreach ($testimonials as $t) if ((int)$t['id'] === $editId) { $editT = $t; break; }

$industries = ['E-commerce','Healthcare','Real Estate','Education','Restaurant','Finance','SaaS','Retail','Travel','Other'];

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Testimonials</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Testimonials</span></div>
    </div>
    <a href="?edit=0" class="la-btn la-btn-primary">+ Add Testimonial</a>
</div>

<div style="display:grid;grid-template-columns:1fr <?php echo isset($_GET['edit']) ? '380px' : '0'; ?>;gap:20px;align-items:start;">

<!-- List -->
<div class="la-card">
    <div class="la-card-hd">
        <span>All Testimonials <span style="color:var(--text-muted);font-weight:400;">(<?php echo count($testimonials); ?>)</span></span>
    </div>
    <?php if (!$testimonials): ?>
        <div class="la-empty">
            <div class="la-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
            <h3>No testimonials yet</h3><p>Add your first customer review.</p>
        </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;padding:16px;">
    <?php foreach ($testimonials as $t): ?>
        <div style="background:var(--bg-input);border-radius:10px;padding:16px;border:1px solid var(--border);<?php echo $t['is_active'] ? '' : 'opacity:.5;'; ?>">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <?php if ($t['avatar_url']): ?>
                    <img src="<?php echo e($t['avatar_url']); ?>" alt=""
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:var(--accent);color:#000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0;">
                        <?php echo strtoupper(substr($t['name'] ?? 'U', 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:var(--text-primary);"><?php echo e($t['name']); ?></div>
                    <div style="font-size:12px;color:var(--text-muted);">
                        <?php echo e($t['role']); ?><?php if ($t['company']): ?> · <?php echo e($t['company']); ?><?php endif; ?>
                        <?php if ($t['industry']): ?><span class="la-badge la-badge-info" style="margin-left:6px;"><?php echo e($t['industry']); ?></span><?php endif; ?>
                    </div>
                </div>
                <div style="color:#fdcb6e;font-size:14px;flex-shrink:0;"><?php echo str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5-(int)$t['rating']); ?></div>
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin:0 0 12px;font-style:italic;">"<?php echo e(mb_strimwidth($t['content'] ?? '', 0, 140, '…')); ?>"</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="?edit=<?php echo $t['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                <form method="POST" style="display:inline">
                    <?php echo la_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="tid"    value="<?php echo $t['id']; ?>">
                    <button type="submit" class="la-btn la-btn-outline la-btn-sm"><?php echo $t['is_active'] ? 'Disable' : 'Enable'; ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this testimonial?');" style="display:inline">
                    <?php echo la_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tid"    value="<?php echo $t['id']; ?>">
                    <button type="submit" class="la-btn la-btn-danger la-btn-sm">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Form -->
<?php if (isset($_GET['edit'])): ?>
<div class="la-card">
    <div class="la-card-hd">
        <span><?php echo $editT ? 'Edit Testimonial' : 'Add Testimonial'; ?></span>
        <a href="testimonials.php" class="la-btn la-btn-outline la-btn-sm">Cancel</a>
    </div>
    <form method="POST" style="padding:20px;display:flex;flex-direction:column;gap:14px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="action" value="<?php echo $editT ? 'update' : 'create'; ?>">
        <input type="hidden" name="tid"    value="<?php echo (int)($editT['id'] ?? 0); ?>">

        <div class="la-form-group">
            <label class="la-label">Customer Name *</label>
            <input type="text" name="name" class="la-input" required
                value="<?php echo e($editT['name'] ?? ''); ?>" placeholder="e.g. Sarah Johnson">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="la-form-group">
                <label class="la-label">Role / Title</label>
                <input type="text" name="role" class="la-input"
                    value="<?php echo e($editT['role'] ?? ''); ?>" placeholder="e.g. Marketing Manager">
            </div>
            <div class="la-form-group">
                <label class="la-label">Company</label>
                <input type="text" name="company" class="la-input"
                    value="<?php echo e($editT['company'] ?? ''); ?>" placeholder="e.g. Acme Corp">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Avatar URL</label>
            <input type="url" name="avatar_url" class="la-input"
                value="<?php echo e($editT['avatar_url'] ?? ''); ?>" placeholder="https://...">
        </div>

        <div class="la-form-group">
            <label class="la-label">Testimonial Text *</label>
            <textarea name="content" class="la-textarea" rows="4" required
                placeholder="What did they say about Waamini?"><?php echo e($editT['content'] ?? ''); ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="la-form-group">
                <label class="la-label">Industry</label>
                <select name="industry" class="la-select">
                    <option value="">— Select —</option>
                    <?php foreach ($industries as $ind): ?>
                    <option value="<?php echo $ind; ?>" <?php echo ($editT['industry'] ?? '') === $ind ? 'selected' : ''; ?>><?php echo $ind; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="la-form-group">
                <label class="la-label">Sort Order</label>
                <input type="number" name="sort_order" class="la-input" min="0"
                    value="<?php echo (int)($editT['sort_order'] ?? 0); ?>">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Rating</label>
            <div id="starRating" style="display:flex;gap:6px;align-items:center;">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <label style="cursor:pointer;font-size:28px;line-height:1;color:#fdcb6e;">
                    <input type="radio" name="rating" value="<?php echo $s; ?>"
                        <?php echo ((int)($editT['rating'] ?? 5)) === $s ? 'checked' : ''; ?>
                        style="display:none;">
                    <?php echo ((int)($editT['rating'] ?? 5)) >= $s ? '★' : '☆'; ?>
                </label>
                <?php endfor; ?>
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <div class="la-toggle">
                <input type="checkbox" name="is_active" <?php echo ($editT['is_active'] ?? 1) ? 'checked' : ''; ?>>
                <span class="la-toggle-slider"></span>
            </div>
            <span style="font-size:13px;">Active (visible on site)</span>
        </label>

        <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
            <?php echo $editT ? 'Update Testimonial' : 'Add Testimonial'; ?>
        </button>
    </form>
</div>
<?php endif; ?>

</div>

<script>
// Interactive star rating
document.querySelectorAll('#starRating label').forEach((lbl, i) => {
    lbl.addEventListener('mouseenter', () => highlight(i + 1));
    lbl.addEventListener('mouseleave', () => highlight(getChecked()));
    lbl.addEventListener('click', () => {});
});
function highlight(n) {
    document.querySelectorAll('#starRating label').forEach((lbl, i) => {
        lbl.textContent = i < n ? '★' : '☆';
    });
}
function getChecked() {
    const checked = document.querySelector('#starRating input[type=radio]:checked');
    return checked ? parseInt(checked.value) : 5;
}
document.querySelectorAll('#starRating input[type=radio]').forEach(r => {
    r.addEventListener('change', () => highlight(parseInt(r.value)));
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
