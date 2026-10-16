<?php
/** Landing Admin — Features */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
la_require_login();

$laTitle = 'Features';
$laPage  = 'features';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id    = (int)($_POST['feat_id'] ?? 0);
        $icon  = trim($_POST['icon']  ?? '');
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $badge = trim($_POST['badge'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;

        if (!$title) { la_flash_set('error', 'Title is required.'); header('Location: features.php'); exit; }

        if ($action === 'create') {
            $st = $conn->prepare("INSERT INTO lp_features (icon,title,description,badge,sort_order,is_active) VALUES (?,?,?,?,?,?)");
            $st->bind_param('ssssii', $icon, $title, $desc, $badge, $sort, $active);
            $st->execute(); $st->close();
            la_flash_set('success', 'Feature created.');
        } else {
            $st = $conn->prepare("UPDATE lp_features SET icon=?,title=?,description=?,badge=?,sort_order=?,is_active=? WHERE id=?");
            $st->bind_param('ssssiii', $icon, $title, $desc, $badge, $sort, $active, $id);
            $st->execute(); $st->close();
            la_flash_set('success', 'Feature updated.');
        }
        header('Location: features.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['feat_id'] ?? 0);
        if ($id) { $st = $conn->prepare("DELETE FROM lp_features WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        la_flash_set('success', 'Feature deleted.'); header('Location: features.php'); exit;

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['feat_id'] ?? 0);
        if ($id) $conn->query("UPDATE lp_features SET is_active = NOT is_active WHERE id=$id");
        header('Location: features.php'); exit;

    } elseif ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        foreach ($order as $pos => $fid) {
            $fid = (int)$fid; $pos = (int)$pos + 1;
            $conn->query("UPDATE lp_features SET sort_order=$pos WHERE id=$fid");
        }
        echo json_encode(['ok' => true]); exit;
    }
}

/* ── Load features ────────────────────────────────────────────────────── */
$features = [];
$res = $conn->query("SELECT * FROM lp_features ORDER BY sort_order ASC, id ASC");
if ($res) while ($r = $res->fetch_assoc()) $features[] = $r;

$editId = (int)($_GET['edit'] ?? 0);
$editFeat = null;
foreach ($features as $f) if ((int)$f['id'] === $editId) { $editFeat = $f; break; }

$icons = ['📲','💬','📊','🔔','🤖','📱','📧','🔗','⚡','🎯','📋','🔐','💡','🚀','📥','✅','🌐','👥','📈','🛡️'];

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Features</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Features</span></div>
    </div>
    <a href="?edit=0" class="la-btn la-btn-primary">+ Add Feature</a>
</div>

<div style="display:grid;grid-template-columns:1fr <?php echo ($editFeat !== null || isset($_GET['edit'])) ? '360px' : '0px'; ?>;gap:20px;align-items:start;">

<!-- Feature List -->
<div class="la-card">
    <div class="la-card-hd">
        <span>All Features <span style="color:var(--text-muted);font-weight:400;">(<?php echo count($features); ?>)</span></span>
        <span style="font-size:11px;color:var(--text-muted);">Drag rows to reorder</span>
    </div>
    <?php if (!$features): ?>
        <div class="la-empty">
            <div class="la-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></div>
            <h3>No features yet</h3><p>Add your first product feature.</p>
        </div>
    <?php else: ?>
    <div class="la-table-wrap">
        <table class="la-table" id="featureTable">
            <thead><tr><th style="width:36px;"></th><th>Icon</th><th>Title</th><th>Badge</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="featureTbody">
            <?php foreach ($features as $f): ?>
            <tr data-id="<?php echo $f['id']; ?>" style="cursor:grab;">
                <td style="color:var(--text-dim);user-select:none;text-align:center;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/></svg></td>
                <td style="font-size:22px;text-align:center;"><?php echo e($f['icon']); ?></td>
                <td>
                    <div style="font-weight:500;"><?php echo e($f['title']); ?></div>
                    <div style="font-size:11px;color:var(--text-muted);"><?php echo e(mb_strimwidth($f['description'], 0, 60, '…')); ?></div>
                </td>
                <td><?php if ($f['badge']): ?><span class="la-badge la-badge-info"><?php echo e($f['badge']); ?></span><?php endif; ?></td>
                <td style="font-size:13px;color:var(--text-muted);text-align:center;"><?php echo (int)$f['sort_order']; ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <?php echo la_csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="feat_id" value="<?php echo $f['id']; ?>">
                        <button type="submit" class="la-toggle-btn <?php echo $f['is_active'] ? 'active' : ''; ?>"
                            title="<?php echo $f['is_active'] ? 'Active — click to disable' : 'Inactive — click to enable'; ?>">
                            <?php if ($f['is_active']): ?>
                                <span class="la-badge la-badge-published">Active</span>
                            <?php else: ?>
                                <span class="la-badge la-badge-draft">Off</span>
                            <?php endif; ?>
                        </button>
                    </form>
                </td>
                <td style="white-space:nowrap;">
                    <div style="display:flex;gap:6px;">
                        <a href="?edit=<?php echo $f['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete this feature?');" style="display:inline;">
                            <?php echo la_csrf_field(); ?>
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="feat_id" value="<?php echo $f['id']; ?>">
                            <button type="submit" class="la-btn la-btn-danger la-btn-sm la-btn-icon"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Edit / Create Form -->
<?php if (isset($_GET['edit'])): ?>
<div class="la-card">
    <div class="la-card-hd">
        <span><?php echo $editFeat ? 'Edit Feature' : 'New Feature'; ?></span>
        <a href="features.php" class="la-btn la-btn-outline la-btn-sm">Cancel</a>
    </div>
    <form method="POST" style="padding:20px;display:flex;flex-direction:column;gap:14px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="action"  value="<?php echo $editFeat ? 'update' : 'create'; ?>">
        <input type="hidden" name="feat_id" value="<?php echo $editFeat ? $editFeat['id'] : 0; ?>">

        <div class="la-form-group">
            <label class="la-label">Icon Emoji</label>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;" id="iconPicker">
                <?php foreach ($icons as $ic): ?>
                <button type="button" onclick="pickIcon('<?php echo $ic; ?>')"
                    style="background:<?php echo ($editFeat['icon'] ?? '') === $ic ? 'var(--accent)' : 'var(--bg-input)'; ?>;
                           border:1px solid var(--border);border-radius:6px;width:36px;height:36px;font-size:18px;cursor:pointer;transition:background .15s;"
                    class="icon-btn" data-icon="<?php echo $ic; ?>"><?php echo $ic; ?></button>
                <?php endforeach; ?>
            </div>
            <input type="text" id="iconInput" name="icon" class="la-input"
                value="<?php echo e($editFeat['icon'] ?? ''); ?>" placeholder="or type any emoji">
        </div>

        <div class="la-form-group">
            <label class="la-label">Title *</label>
            <input type="text" name="title" class="la-input" required
                value="<?php echo e($editFeat['title'] ?? ''); ?>" placeholder="e.g. Smart Automation">
        </div>

        <div class="la-form-group">
            <label class="la-label">Description</label>
            <textarea name="description" class="la-textarea" rows="3"
                placeholder="Short feature description (1-2 sentences)"><?php echo e($editFeat['description'] ?? ''); ?></textarea>
        </div>

        <div class="la-form-group">
            <label class="la-label">Badge <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
            <input type="text" name="badge" class="la-input"
                value="<?php echo e($editFeat['badge'] ?? ''); ?>" placeholder="e.g. New, Popular">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="la-form-group">
                <label class="la-label">Sort Order</label>
                <input type="number" name="sort_order" class="la-input" min="0"
                    value="<?php echo (int)($editFeat['sort_order'] ?? 0); ?>">
            </div>
            <div class="la-form-group">
                <label class="la-label">Status</label>
                <label style="display:flex;align-items:center;gap:8px;height:38px;cursor:pointer;">
                    <div class="la-toggle">
                        <input type="checkbox" name="is_active" <?php echo ($editFeat['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        <span class="la-toggle-slider"></span>
                    </div>
                    <span style="font-size:13px;">Active</span>
                </label>
            </div>
        </div>

        <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
            <?php echo $editFeat ? 'Update Feature' : 'Create Feature'; ?>
        </button>
    </form>
</div>
<?php endif; ?>
</div>

<script>
function pickIcon(icon) {
    document.getElementById('iconInput').value = icon;
    document.querySelectorAll('.icon-btn').forEach(btn => {
        btn.style.background = btn.dataset.icon === icon ? 'var(--accent)' : 'var(--bg-input)';
    });
}

// Drag-to-reorder
(function() {
    const tbody = document.getElementById('featureTbody');
    if (!tbody) return;
    let dragging = null;
    tbody.querySelectorAll('tr').forEach(tr => {
        tr.draggable = true;
        tr.addEventListener('dragstart', e => { dragging = tr; tr.style.opacity = '.4'; });
        tr.addEventListener('dragend',   e => { tr.style.opacity = '1'; saveOrder(); });
        tr.addEventListener('dragover',  e => { e.preventDefault(); const after = getDragAfterEl(tbody, e.clientY); tbody.insertBefore(dragging, after); });
    });
    function getDragAfterEl(container, y) {
        const els = [...container.querySelectorAll('tr:not([style*="opacity: 0.4"])')];
        return els.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height/2;
            return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    function saveOrder() {
        const ids = [...tbody.querySelectorAll('tr')].map(tr => tr.dataset.id);
        const fd = new FormData();
        fd.append('_la_csrf', document.querySelector('input[name=_la_csrf]')?.value || '');
        fd.append('action', 'reorder');
        ids.forEach((id, i) => fd.append('order[]', id));
        fetch('features.php', { method: 'POST', body: fd });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
