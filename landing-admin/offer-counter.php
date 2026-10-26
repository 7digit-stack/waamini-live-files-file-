<?php
/** Landing Admin — Offer Counter */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Offer Counter';
$laPage  = 'offer-counter';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

$editId = isset($_GET['edit']) ? max(0, (int)$_GET['edit']) : 0;
$deleteId = isset($_GET['delete']) ? max(0, (int)$_GET['delete']) : 0;

if ($deleteId) {
    la_csrf_verify();
    $st = $conn->prepare("DELETE FROM lp_offer_counters WHERE id=?");
    $st->bind_param('i', $deleteId);
    $st->execute(); $st->close();
    la_flash_set('success', 'Offer counter deleted.');
    header('Location: offer-counter.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    la_csrf_verify();
    $id = max(0, (int)($_POST['id'] ?? 0));
    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $countdown_target = trim($_POST['countdown_target'] ?? '');
    $countdown_label = trim($_POST['countdown_label'] ?? 'Offer ends in');
    $bg_color = trim($_POST['bg_color'] ?? '#1e40af');
    $text_color = trim($_POST['text_color'] ?? '#ffffff');
    $accent_color = trim($_POST['accent_color'] ?? '#fbbf24');
    $btn_text = trim($_POST['btn_text'] ?? 'Claim Offer');
    $btn_url = trim($_POST['btn_url'] ?? '/register.php');
    $position = in_array($_POST['position'] ?? '', ['bottom','floating']) ? $_POST['position'] : 'bottom';
    $is_sticky = isset($_POST['is_sticky']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_dismissible = isset($_POST['is_dismissible']) ? 1 : 0;

    $countdown_target = $countdown_target ?: null;
    if (!$title) {
        la_flash_set('error', 'Title is required.');
    } else {
        if ($id) {
            $st = $conn->prepare("UPDATE lp_offer_counters SET title=?, subtitle=?, countdown_target=?, countdown_label=?, bg_color=?, text_color=?, accent_color=?, btn_text=?, btn_url=?, position=?, is_sticky=?, is_active=?, is_dismissible=? WHERE id=?");
            $st->bind_param('ssssssssssiiii', $title, $subtitle, $countdown_target, $countdown_label, $bg_color, $text_color, $accent_color, $btn_text, $btn_url, $position, $is_sticky, $is_active, $is_dismissible, $id);
        } else {
            $st = $conn->prepare("INSERT INTO lp_offer_counters (title,subtitle,countdown_target,countdown_label,bg_color,text_color,accent_color,btn_text,btn_url,position,is_sticky,is_active,is_dismissible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('ssssssssssiii', $title, $subtitle, $countdown_target, $countdown_label, $bg_color, $text_color, $accent_color, $btn_text, $btn_url, $position, $is_sticky, $is_active, $is_dismissible);
        }
        $st->execute(); $st->close();
        la_flash_set('success', $id ? 'Offer counter updated.' : 'Offer counter created.');
        header('Location: offer-counter.php'); exit;
    }
}

$editRow = null;
if ($editId) {
    $st = $conn->prepare("SELECT * FROM lp_offer_counters WHERE id=?");
    $st->bind_param('i', $editId);
    $st->execute();
    $editRow = $st->get_result()->fetch_assoc();
    $st->close();
}

$items = [];
$res = $conn->query("SELECT * FROM lp_offer_counters ORDER BY id DESC");
if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

require __DIR__ . '/includes/header.php';
echo la_flash_html();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Offer Counter</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Offer Counter</span></div>
    </div>
    <?php if (!$editRow): ?><a href="?edit=new" class="la-btn la-btn-primary la-btn-sm">+ New Counter</a><?php endif; ?>
</div>

<?php if ($editRow || (($_GET['edit'] ?? '') === 'new')):
    $e = $editRow ?: [];
    $fTitle = $e['title'] ?? '';
    $fSubtitle = $e['subtitle'] ?? '';
    $fTarget = $e['countdown_target'] ?? '';
    $fLabel = $e['countdown_label'] ?? 'Offer ends in';
    $fBg = $e['bg_color'] ?? '#1e40af';
    $fFg = $e['text_color'] ?? '#ffffff';
    $fAccent = $e['accent_color'] ?? '#fbbf24';
    $fBtnText = $e['btn_text'] ?? 'Claim Offer';
    $fBtnUrl = $e['btn_url'] ?? '/register.php';
    $fPos = $e['position'] ?? 'bottom';
    $fSticky = (int)($e['is_sticky'] ?? 1);
    $fActive = (int)($e['is_active'] ?? 1);
    $fDismiss = (int)($e['is_dismissible'] ?? 1);
    $fId = $e['id'] ?? 0;
?>
<div class="la-card">
    <div class="la-card-hd"><span><?php echo $fId ? 'Edit Offer Counter' : 'New Offer Counter'; ?></span><?php if ($fId): ?><a href="offer-counter.php" class="la-btn la-btn-outline la-btn-sm">← Back</a><?php endif; ?></div>
    <form method="POST" action="offer-counter.php" style="padding:24px;display:flex;flex-direction:column;gap:18px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $fId; ?>">
        <input type="hidden" name="save" value="1">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Title <span style="color:var(--danger);">*</span></label>
                <input type="text" name="title" class="la-input" value="<?php echo e($fTitle); ?>" placeholder="🎉 Limited Time Offer!">
            </div>
            <div class="la-form-group">
                <label class="la-label">Countdown Label</label>
                <input type="text" name="countdown_label" class="la-input" value="<?php echo e($fLabel); ?>" placeholder="Offer ends in">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Subtitle</label>
            <textarea name="subtitle" class="la-textarea" rows="2" placeholder="Get 50% OFF on Annual Plan — Don't miss out!"><?php echo e($fSubtitle); ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Countdown Target</label>
                <input type="datetime-local" name="countdown_target" class="la-input" value="<?php echo $fTarget ? date('Y-m-d\TH:i', strtotime($fTarget)) : ''; ?>">
            </div>
            <div class="la-form-group">
                <label class="la-label">Position</label>
                <select name="position" class="la-input">
                    <option value="bottom" <?php echo $fPos === 'bottom' ? 'selected' : ''; ?>>Sticky Bottom Bar</option>
                    <option value="floating" <?php echo $fPos === 'floating' ? 'selected' : ''; ?>>Floating Widget</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Button Text</label>
                <input type="text" name="btn_text" class="la-input" value="<?php echo e($fBtnText); ?>" placeholder="Claim Offer">
            </div>
            <div class="la-form-group">
                <label class="la-label">Button URL</label>
                <input type="text" name="btn_url" class="la-input" value="<?php echo e($fBtnUrl); ?>" placeholder="/register.php">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Background Color</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="bg_color" value="<?php echo e($fBg); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" class="la-input" value="<?php echo e($fBg); ?>" style="font-family:monospace;flex:1;" onchange="this.previousElementSibling.value=this.value">
                </div>
            </div>
            <div class="la-form-group">
                <label class="la-label">Text Color</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="text_color" value="<?php echo e($fFg); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" class="la-input" value="<?php echo e($fFg); ?>" style="font-family:monospace;flex:1;" onchange="this.previousElementSibling.value=this.value">
                </div>
            </div>
            <div class="la-form-group">
                <label class="la-label">Accent Color</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="accent_color" value="<?php echo e($fAccent); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" class="la-input" value="<?php echo e($fAccent); ?>" style="font-family:monospace;flex:1;" onchange="this.previousElementSibling.value=this.value">
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Active</label>
                <label class="la-toggle"><input type="checkbox" name="is_active" <?php echo $fActive ? 'checked' : ''; ?>><span class="la-toggle-slider"></span></label>
            </div>
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Dismissible</label>
                <label class="la-toggle"><input type="checkbox" name="is_dismissible" <?php echo $fDismiss ? 'checked' : ''; ?>><span class="la-toggle-slider"></span></label>
            </div>
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Sticky</label>
                <label class="la-toggle"><input type="checkbox" name="is_sticky" <?php echo $fSticky ? 'checked' : ''; ?>><span class="la-toggle-slider"></span></label>
            </div>
        </div>

        <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid var(--border);">
            <button type="submit" class="la-btn la-btn-primary"><?php echo $fId ? 'Update' : 'Create'; ?> Offer Counter</button>
            <a href="offer-counter.php" class="la-btn la-btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="la-card">
    <div class="la-table-wrap">
        <table class="la-table">
            <thead><tr><th>#</th><th>Title</th><th>Position</th><th>Target</th><th>Active</th><th>Updated</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="7" style="padding:40px;text-align:center;color:var(--text-muted);">No offer counters yet. <a href="?edit=new" style="color:var(--accent);">Create one</a>.</td></tr>
                <?php else: foreach ($items as $a): ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?php echo $a['id']; ?></td>
                    <td><div style="font-weight:500;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($a['title']); ?></div></td>
                    <td><span class="la-badge" style="text-transform:capitalize;"><?php echo e($a['position']); ?></span></td>
                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;"><?php echo $a['countdown_target'] ? date('d M Y H:i', strtotime($a['countdown_target'])) : '—'; ?></td>
                    <td><?php echo $a['is_active'] ? '<span class="la-badge la-badge-published">Active</span>' : '<span class="la-badge la-badge-draft">Inactive</span>'; ?></td>
                    <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?php echo la_time_ago($a['updated_at']); ?></td>
                    <td style="white-space:nowrap;">
                        <a href="?edit=<?php echo $a['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                        <a href="?delete=<?php echo $a['id']; ?>&_la_csrf=<?php echo urlencode(la_csrf_token()); ?>" class="la-btn la-btn-outline la-btn-sm" style="border-color:var(--danger-border);color:var(--danger);" onclick="return confirm('Delete?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif;
require __DIR__ . '/includes/footer.php'; ?>
