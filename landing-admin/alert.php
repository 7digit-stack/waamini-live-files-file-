<?php
/**
 * Landing Admin — Alert Bar Manager
 * CRUD for top announcement bar across landing pages.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Alert Bar';
$laPage  = 'alert';

$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

$editId = isset($_GET['edit']) ? max(0, (int)$_GET['edit']) : 0;
$deleteId = isset($_GET['delete']) ? max(0, (int)$_GET['delete']) : 0;

/* ── Delete ──────────────────────────────────────────────────────────── */
if ($deleteId) {
    la_csrf_verify();
    $st = $conn->prepare("DELETE FROM lp_alerts WHERE id=?");
    $st->bind_param('i', $deleteId);
    $st->execute();
    $st->close();
    la_flash_set('success', 'Alert deleted.');
    header('Location: alert.php'); exit;
}

/* ── Handle POST (create/update) ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    la_csrf_verify();
    $id              = max(0, (int)($_POST['id'] ?? 0));
    $type            = in_array($_POST['type'] ?? 'info', ['login','registration','offer','info','warning','announcement']) ? $_POST['type'] : 'info';
    $message         = trim($_POST['message'] ?? '');
    $link_text       = trim($_POST['link_text'] ?? '');
    $link_url        = trim($_POST['link_url'] ?? '');
    $btn_text        = trim($_POST['btn_text'] ?? '');
    $btn_url         = trim($_POST['btn_url'] ?? '');
    $countdown_label = trim($_POST['countdown_label'] ?? '');
    $countdown_target= trim($_POST['countdown_target'] ?? '');
    $start_date      = trim($_POST['start_date'] ?? '');
    $end_date        = trim($_POST['end_date'] ?? '');
    $bg_color        = trim($_POST['bg_color'] ?? '#1e40af');
    $text_color      = trim($_POST['text_color'] ?? '#ffffff');
    $is_active       = isset($_POST['is_active']) ? 1 : 0;
    $is_dismissible  = isset($_POST['is_dismissible']) ? 1 : 0;
    $sort_order      = (int)($_POST['sort_order'] ?? 0);

    if (!$message) {
        la_flash_set('error', 'Message is required.');
    } else {
        $countdown_target = $countdown_target ?: null;
        $start_date = $start_date ?: null;
        $end_date   = $end_date ?: null;

        if ($id) {
            $st = $conn->prepare("UPDATE lp_alerts SET type=?, message=?, link_text=?, link_url=?, btn_text=?, btn_url=?, countdown_label=?, countdown_target=?, start_date=?, end_date=?, bg_color=?, text_color=?, is_active=?, is_dismissible=?, sort_order=? WHERE id=?");
            $st->bind_param('sssssssssssiiii', $type, $message, $link_text, $link_url, $btn_text, $btn_url, $countdown_label, $countdown_target, $start_date, $end_date, $bg_color, $text_color, $is_active, $is_dismissible, $sort_order, $id);
        } else {
            $st = $conn->prepare("INSERT INTO lp_alerts (type,message,link_text,link_url,btn_text,btn_url,countdown_label,countdown_target,start_date,end_date,bg_color,text_color,is_active,is_dismissible,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sssssssssssiii', $type, $message, $link_text, $link_url, $btn_text, $btn_url, $countdown_label, $countdown_target, $start_date, $end_date, $bg_color, $text_color, $is_active, $is_dismissible, $sort_order);
        }
        $st->execute();
        $st->close();
        la_flash_set('success', $id ? 'Alert updated.' : 'Alert created.');
        header('Location: alert.php'); exit;
    }
}

/* ── Load edit row ──────────────────────────────────────────────────── */
$editRow = null;
if ($editId) {
    $st = $conn->prepare("SELECT * FROM lp_alerts WHERE id=?");
    $st->bind_param('i', $editId);
    $st->execute();
    $editRow = $st->get_result()->fetch_assoc();
    $st->close();
}

/* ── Load all alerts ────────────────────────────────────────────────── */
$alerts = [];
$res = $conn->query("SELECT * FROM lp_alerts ORDER BY sort_order ASC, id DESC");
if ($res) while ($r = $res->fetch_assoc()) $alerts[] = $r;

/* ── Helper for select options ──────────────────────────────────────── */
function selected(string $a, string $b): string { return $a === $b ? 'selected' : ''; }

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Alert Bar</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Alert Bar</span></div>
    </div>
    <?php if (!$editRow): ?>
    <a href="?edit=new" class="la-btn la-btn-primary la-btn-sm">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        New Alert
    </a>
    <?php endif; ?>
</div>

<?php if ($editRow || (($_GET['edit'] ?? '') === 'new')): ?>
<?php
    $e = $editRow ?: [];
    $fType            = $e['type'] ?? 'info';
    $fMessage         = $e['message'] ?? '';
    $fLinkText        = $e['link_text'] ?? '';
    $fLinkUrl         = $e['link_url'] ?? '';
    $fBtnText         = $e['btn_text'] ?? '';
    $fBtnUrl          = $e['btn_url'] ?? '';
    $fCountdownLabel  = $e['countdown_label'] ?? '';
    $fCountdownTarget = $e['countdown_target'] ?? '';
    $fStartDate       = $e['start_date'] ?? '';
    $fEndDate         = $e['end_date'] ?? '';
    $fBgColor         = $e['bg_color'] ?? '#1e40af';
    $fTextColor       = $e['text_color'] ?? '#ffffff';
    $fIsActive        = (int)($e['is_active'] ?? 1);
    $fIsDismissible   = (int)($e['is_dismissible'] ?? 1);
    $fSortOrder       = (int)($e['sort_order'] ?? 0);
    $fId              = $e['id'] ?? 0;

    $typeOptions = ['login'=>'👋 Login','registration'=>'📝 Registration','offer'=>'🎉 Offer','info'=>'💡 Info','warning'=>'⚠️ Warning','announcement'=>'📢 Announcement'];
?>
<div class="la-card">
    <div class="la-card-hd">
        <span><?php echo $fId ? 'Edit Alert' : 'New Alert'; ?></span>
        <?php if ($fId): ?><a href="alert.php" class="la-btn la-btn-outline la-btn-sm">← Back</a><?php endif; ?>
    </div>
    <form method="POST" action="alert.php" style="padding:24px;display:flex;flex-direction:column;gap:18px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $fId; ?>">
        <input type="hidden" name="save" value="1">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Type</label>
                <select name="type" class="la-input" onchange="updatePreview()">
                    <?php foreach ($typeOptions as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo selected($fType, $k); ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="la-form-group">
                <label class="la-label">Sort Order</label>
                <input type="number" name="sort_order" class="la-input" value="<?php echo $fSortOrder; ?>" min="0" step="1">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Message <span style="color:var(--danger);">*</span></label>
            <textarea name="message" class="la-textarea" rows="2" placeholder="🚀 Get 50% OFF on Annual Plan — Limited Time Offer!" id="fMessage"><?php echo e($fMessage); ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Link Text (optional)</label>
                <input type="text" name="link_text" class="la-input" value="<?php echo e($fLinkText); ?>" placeholder="Claim Now" id="fLinkText">
            </div>
            <div class="la-form-group">
                <label class="la-label">Link URL</label>
                <input type="text" name="link_url" class="la-input" value="<?php echo e($fLinkUrl); ?>" placeholder="/register.php" id="fLinkUrl">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Button Text (optional)</label>
                <input type="text" name="btn_text" class="la-input" value="<?php echo e($fBtnText); ?>" placeholder="Shop Now" id="fBtnText">
            </div>
            <div class="la-form-group">
                <label class="la-label">Button URL</label>
                <input type="text" name="btn_url" class="la-input" value="<?php echo e($fBtnUrl); ?>" placeholder="/pricing.php" id="fBtnUrl">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Countdown Label (optional)</label>
                <input type="text" name="countdown_label" class="la-input" value="<?php echo e($fCountdownLabel); ?>" placeholder="Offer ends in" id="fCountdownLabel">
            </div>
            <div class="la-form-group">
                <label class="la-label">Countdown Target Date/Time</label>
                <input type="datetime-local" name="countdown_target" class="la-input" value="<?php echo $fCountdownTarget ? date('Y-m-d\TH:i', strtotime($fCountdownTarget)) : ''; ?>" id="fCountdownTarget">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Start Date (optional scheduling)</label>
                <input type="datetime-local" name="start_date" class="la-input" value="<?php echo $fStartDate ? date('Y-m-d\TH:i', strtotime($fStartDate)) : ''; ?>">
            </div>
            <div class="la-form-group">
                <label class="la-label">End Date (optional expiry)</label>
                <input type="datetime-local" name="end_date" class="la-input" value="<?php echo $fEndDate ? date('Y-m-d\TH:i', strtotime($fEndDate)) : ''; ?>">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;">
            <div class="la-form-group">
                <label class="la-label">Background Color</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="bg_color" value="<?php echo e($fBgColor); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" name="bg_color_text" class="la-input" value="<?php echo e($fBgColor); ?>" style="font-family:monospace;flex:1;" id="fBgColor" onchange="document.querySelector('input[name=bg_color]').value=this.value;updatePreview();">
                </div>
            </div>
            <div class="la-form-group">
                <label class="la-label">Text Color</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="color" name="text_color" value="<?php echo e($fTextColor); ?>" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:0;">
                    <input type="text" name="text_color_text" class="la-input" value="<?php echo e($fTextColor); ?>" style="font-family:monospace;flex:1;" id="fTextColor" onchange="document.querySelector('input[name=text_color]').value=this.value;updatePreview();">
                </div>
            </div>
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Active</label>
                <label class="la-toggle">
                    <input type="checkbox" name="is_active" <?php echo $fIsActive ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </label>
            </div>
            <div class="la-form-group" style="display:flex;flex-direction:column;gap:8px;">
                <label class="la-label" style="margin:0;">Dismissible</label>
                <label class="la-toggle">
                    <input type="checkbox" name="is_dismissible" <?php echo $fIsDismissible ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Live Preview -->
        <div class="la-card" style="margin-top:8px;">
            <div class="la-card-hd"><span>Live Preview</span></div>
            <div style="padding:20px 24px;background:#f0f2f5;border-radius:0 0 var(--radius-lg) var(--radius-lg);display:flex;justify-content:center;">
                <div id="alertPreview" style="display:flex;align-items:center;justify-content:center;gap:12px;padding:10px 24px;border-radius:10px;font-size:13px;font-weight:500;width:100%;max-width:700px;flex-wrap:wrap;transition:all .2s;">
                    <span id="previewIcon">💡</span>
                    <span id="previewMessage">Message preview</span>
                    <span id="previewLink" style="text-decoration:underline;cursor:default;opacity:.8;"></span>
                    <span id="previewBtn" style="padding:5px 14px;border-radius:6px;font-weight:600;font-size:12px;cursor:default;"></span>
                    <span id="previewCountdown" style="font-weight:700;font-size:12px;opacity:.85;"></span>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid var(--border);">
            <button type="submit" class="la-btn la-btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                <?php echo $fId ? 'Update Alert' : 'Create Alert'; ?>
            </button>
            <a href="alert.php" class="la-btn la-btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
const typeIcons = {'login':'👋','registration':'📝','offer':'🎉','info':'💡','warning':'⚠️','announcement':'📢'};
function updatePreview() {
    const type = document.querySelector('select[name="type"]').value;
    const msg = document.getElementById('fMessage')?.value || 'Your message here';
    const linkText = document.getElementById('fLinkText')?.value || '';
    const btnText = document.getElementById('fBtnText')?.value || '';
    const cdLabel = document.getElementById('fCountdownLabel')?.value || '';
    const bg = document.getElementById('fBgColor')?.value || '#1e40af';
    const fg = document.getElementById('fTextColor')?.value || '#ffffff';

    const preview = document.getElementById('alertPreview');
    preview.style.background = bg;
    preview.style.color = fg;

    document.getElementById('previewIcon').textContent = typeIcons[type] || '💡';
    document.getElementById('previewMessage').textContent = msg;
    document.getElementById('previewLink').textContent = linkText;
    document.getElementById('previewLink').style.display = linkText ? 'inline' : 'none';
    document.getElementById('previewBtn').textContent = btnText;
    document.getElementById('previewBtn').style.display = btnText ? 'inline-block' : 'none';
    if (btnText) {
        document.getElementById('previewBtn').style.background = 'rgba(255,255,255,.2)';
        document.getElementById('previewBtn').style.color = fg;
    }
    document.getElementById('previewCountdown').textContent = cdLabel ? cdLabel + ' ⏱️' : '';
    document.getElementById('previewCountdown').style.display = cdLabel ? 'inline' : 'none';
}
document.querySelector('select[name="type"]')?.addEventListener('change', updatePreview);
document.querySelectorAll('#fMessage,#fLinkText,#fBtnText,#fCountdownLabel,#fBgColor,#fTextColor').forEach(el => {
    el?.addEventListener('input', updatePreview);
});
updatePreview();
</script>

<?php else: ?>

<!-- Alerts List -->
<div class="la-card">
    <div class="la-table-wrap">
        <table class="la-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:80px;">Type</th>
                    <th>Message</th>
                    <th style="width:70px;">Link</th>
                    <th style="width:70px;">Button</th>
                    <th style="width:60px;">Order</th>
                    <th style="width:70px;">Active</th>
                    <th style="width:80px;">Updated</th>
                    <th style="width:100px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alerts)): ?>
                <tr><td colspan="9" class="la-empty" style="padding:40px;text-align:center;color:var(--text-muted);">No alerts yet. <a href="?edit=new" style="color:var(--accent);">Create one</a>.</td></tr>
                <?php else: foreach ($alerts as $a): ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?php echo $a['id']; ?></td>
                    <td>
                        <?php
                        $typeLabels = ['login'=>'👋','registration'=>'📝','offer'=>'🎉','info'=>'💡','warning'=>'⚠️','announcement'=>'📢'];
                        $typeColors = ['login'=>'#065f46','registration'=>'#7c3aed','offer'=>'#dc2626','info'=>'#1e40af','warning'=>'#b45309','announcement'=>'#2563eb'];
                        $tc = $typeColors[$a['type']] ?? '#1e40af';
                        ?>
                        <span class="la-badge" style="background:<?php echo $tc; ?>15;color:<?php echo $tc; ?>;border-color:<?php echo $tc; ?>33;font-size:15px;line-height:1;">
                            <?php echo $typeLabels[$a['type']] ?? '💡'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;" title="<?php echo e($a['message']); ?>"><?php echo e($a['message']); ?></div>
                        <?php if ($a['countdown_target']): ?>
                        <div style="font-size:11px;color:var(--accent);margin-top:2px;">
                            ⏱️ <?php echo e($a['countdown_label'] ?: 'Ends'); ?>: <?php echo date('d M Y', strtotime($a['countdown_target'])); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-secondary);"><?php echo $a['link_text'] ? e($a['link_text']) : '—'; ?></td>
                    <td style="font-size:12px;color:var(--text-secondary);"><?php echo $a['btn_text'] ? e($a['btn_text']) : '—'; ?></td>
                    <td style="text-align:center;font-size:12px;color:var(--text-muted);"><?php echo $a['sort_order']; ?></td>
                    <td>
                        <?php if ($a['is_active']): ?>
                            <span class="la-badge la-badge-published">Active</span>
                        <?php else: ?>
                            <span class="la-badge la-badge-draft">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?php echo la_time_ago($a['updated_at']); ?></td>
                    <td style="white-space:nowrap;">
                        <a href="?edit=<?php echo $a['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                        <a href="?delete=<?php echo $a['id']; ?>&_la_csrf=<?php echo urlencode(la_csrf_token()); ?>" class="la-btn la-btn-outline la-btn-sm" style="border-color:var(--danger-border);color:var(--danger);" onclick="return confirm('Delete this alert?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
