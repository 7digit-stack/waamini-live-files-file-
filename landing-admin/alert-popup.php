<?php
/** Landing Admin — Alert Popup */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Alert Popup';
$laPage  = 'alert-popup';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

$__c = $conn->query("SHOW COLUMNS FROM lp_alert_popups LIKE 'dismiss_text'");
if ($__c && $__c->num_rows === 0) { $conn->query("ALTER TABLE lp_alert_popups ADD COLUMN dismiss_text VARCHAR(60) NOT NULL DEFAULT ''"); }

$editId = isset($_GET['edit']) ? max(0, (int)$_GET['edit']) : 0;
$deleteId = isset($_GET['delete']) ? max(0, (int)$_GET['delete']) : 0;

if ($deleteId) {
    la_csrf_verify();
    $st = $conn->prepare("DELETE FROM lp_alert_popups WHERE id=?");
    $st->bind_param('i', $deleteId);
    $st->execute(); $st->close();
    la_flash_set('success', 'Alert popup deleted.');
    header('Location: alert-popup.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    la_csrf_verify();
    $id = max(0, (int)($_POST['id'] ?? 0));
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $btn_text = trim($_POST['btn_text'] ?? 'Get Started');
    $btn_url = trim($_POST['btn_url'] ?? '/register.php');
    $dismiss_text = mb_substr(trim($_POST['dismiss_text'] ?? ''), 0, 60);
    $bg_color = trim($_POST['bg_color'] ?? '#ffffff');
    $text_color = trim($_POST['text_color'] ?? '#0f172a');
    $accent_color = trim($_POST['accent_color'] ?? '#006df9');
    $show_delay = max(0, (int)($_POST['show_delay'] ?? 3));
    $show_frequency = in_array($_POST['show_frequency'] ?? '', ['once','session','always']) ? $_POST['show_frequency'] : 'once';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_dismissible = isset($_POST['is_dismissible']) ? 1 : 0;

    if (!$title) {
        la_flash_set('error', 'Title is required.');
    } else {
        if ($id) {
            $st = $conn->prepare("UPDATE lp_alert_popups SET title=?, message=?, image_url=?, btn_text=?, btn_url=?, dismiss_text=?, bg_color=?, text_color=?, accent_color=?, show_delay=?, show_frequency=?, is_active=?, is_dismissible=? WHERE id=?");
            $st->bind_param('sssssssssisiii', $title, $message, $image_url, $btn_text, $btn_url, $dismiss_text, $bg_color, $text_color, $accent_color, $show_delay, $show_frequency, $is_active, $is_dismissible, $id);
        } else {
            $st = $conn->prepare("INSERT INTO lp_alert_popups (title,message,image_url,btn_text,btn_url,dismiss_text,bg_color,text_color,accent_color,show_delay,show_frequency,is_active,is_dismissible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sssssssssisii', $title, $message, $image_url, $btn_text, $btn_url, $dismiss_text, $bg_color, $text_color, $accent_color, $show_delay, $show_frequency, $is_active, $is_dismissible);
        }
        $st->execute(); $st->close();
        la_flash_set('success', $id ? 'Alert popup updated.' : 'Alert popup created.');
        header('Location: alert-popup.php'); exit;
    }
}

$editRow = null;
if ($editId) {
    $st = $conn->prepare("SELECT * FROM lp_alert_popups WHERE id=?");
    $st->bind_param('i', $editId);
    $st->execute();
    $editRow = $st->get_result()->fetch_assoc();
    $st->close();
}

$items = [];
$res = $conn->query("SELECT * FROM lp_alert_popups ORDER BY id DESC");
if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

require __DIR__ . '/includes/header.php';
echo la_flash_html();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Alert Popup</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Alert Popup</span></div>
    </div>
    <?php if (!$editRow): ?><a href="?edit=new" class="la-btn la-btn-primary la-btn-sm">+ New Popup</a><?php endif; ?>
</div>

<?php if ($editRow || $_GET['edit'] ?? '' === 'new'):
    $e = $editRow ?: [];
    $fTitle = $e['title'] ?? '';
    $fMessage = $e['message'] ?? '';
    $fImage = $e['image_url'] ?? '';
    $fBtnText = $e['btn_text'] ?? 'Get Started';
    $fBtnUrl = $e['btn_url'] ?? '/register.php';
    $fBg = $e['bg_color'] ?? '#ffffff';
    $fFg = $e['text_color'] ?? '#0f172a';
    $fAccent = $e['accent_color'] ?? '#006df9';
    $fDelay = (int)($e['show_delay'] ?? 3);
    $fFreq = $e['show_frequency'] ?? 'once';
    $fActive = (int)($e['is_active'] ?? 1);
    $fDismiss = (int)($e['is_dismissible'] ?? 1);
    $fId = $e['id'] ?? 0;
?>
<div class="la-card">
    <div class="la-card-hd"><span><?php echo $fId ? 'Edit Alert Popup' : 'New Alert Popup'; ?></span><?php if ($fId): ?><a href="alert-popup.php" class="la-btn la-btn-outline la-btn-sm">← Back</a><?php endif; ?></div>
    <form method="POST" action="alert-popup.php" style="padding:24px;display:flex;flex-direction:column;gap:18px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $fId; ?>">
        <input type="hidden" name="save" value="1">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Title <span style="color:var(--danger);">*</span></label>
                <input type="text" name="title" class="la-input" value="<?php echo e($fTitle); ?>" placeholder="🎉 Special Offer Just for You!">
            </div>
            <div class="la-form-group">
                <label class="la-label">Show Delay (seconds)</label>
                <input type="number" name="show_delay" class="la-input" value="<?php echo $fDelay; ?>" min="0" max="60">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Message</label>
            <textarea name="message" class="la-textarea" rows="3" placeholder="Get 50% off your first year. Limited time offer!"><?php echo e($fMessage); ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Image URL</label>
                <input type="url" name="image_url" class="la-input" value="<?php echo e($fImage); ?>" placeholder="https://...">
            </div>
            <div class="la-form-group">
                <label class="la-label">Show Frequency</label>
                <select name="show_frequency" class="la-input">
                    <option value="once" <?php echo $fFreq === 'once' ? 'selected' : ''; ?>>Once (cookie)</option>
                    <option value="session" <?php echo $fFreq === 'session' ? 'selected' : ''; ?>>Once per session</option>
                    <option value="always" <?php echo $fFreq === 'always' ? 'selected' : ''; ?>>Every page load</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Button Text</label>
                <input type="text" name="btn_text" class="la-input" value="<?php echo e($fBtnText); ?>" placeholder="Get Started">
            </div>
            <div class="la-form-group">
                <label class="la-label">Button URL</label>
                <input type="text" name="btn_url" class="la-input" value="<?php echo e($fBtnUrl); ?>" placeholder="/register.php">
            </div>
            <div class="la-form-group">
                <label class="la-label">Second button (closes the popup)</label>
                <input type="text" name="dismiss_text" class="la-input" value="<?php echo e($e['dismiss_text'] ?? ''); ?>" placeholder="Later / Dismiss" maxlength="60">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Background</label>
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
                <label class="la-label">Accent</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="accent_color" value="<?php echo e($fAccent); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" class="la-input" value="<?php echo e($fAccent); ?>" style="font-family:monospace;flex:1;" onchange="this.previousElementSibling.value=this.value">
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Active</label>
                <label class="la-toggle"><input type="checkbox" name="is_active" <?php echo $fActive ? 'checked' : ''; ?>><span class="la-toggle-slider"></span></label>
            </div>
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Dismissible</label>
                <label class="la-toggle"><input type="checkbox" name="is_dismissible" <?php echo $fDismiss ? 'checked' : ''; ?>><span class="la-toggle-slider"></span></label>
            </div>
        </div>

        <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid var(--border);">
            <button type="submit" class="la-btn la-btn-primary"><?php echo $fId ? 'Update' : 'Create'; ?> Alert Popup</button>
            <a href="alert-popup.php" class="la-btn la-btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="la-card">
    <div class="la-table-wrap">
        <table class="la-table">
            <thead><tr><th>#</th><th>Title</th><th>Frequency</th><th>Delay</th><th>Active</th><th>Updated</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="7" style="padding:40px;text-align:center;color:var(--text-muted);">No alert popups yet. <a href="?edit=new" style="color:var(--accent);">Create one</a>.</td></tr>
                <?php else: foreach ($items as $a): ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?php echo $a['id']; ?></td>
                    <td><div style="font-weight:500;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($a['title']); ?></div></td>
                    <td><span class="la-badge" style="text-transform:capitalize;"><?php echo e($a['show_frequency']); ?></span></td>
                    <td style="font-size:12px;color:var(--text-muted);"><?php echo $a['show_delay']; ?>s</td>
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
