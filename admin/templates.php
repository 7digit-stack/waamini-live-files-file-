<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Templates';
$currentPage = 'campaigns';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM message_templates WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
        header("Location: templates.php?msg=deleted");
        exit();
    }
}

if (isset($_POST['add_template'])) {
    csrf_verify();
    $name     = sanitize($_POST['name'] ?? '');
    $category = in_array($_POST['category'] ?? '', ['marketing', 'utility', 'authentication']) ? $_POST['category'] : 'marketing';
    $header   = sanitize($_POST['header_text'] ?? '');
    $body     = trim($_POST['body_text'] ?? '');
    $footer   = sanitize($_POST['footer_text'] ?? '');
    if ($name && $body) {
        $stmt = $conn->prepare("INSERT INTO message_templates (name, category, header_text, body_text, footer_text, status) VALUES (?, ?, ?, ?, ?, 'approved')");
        $stmt->bind_param("sssss", $name, $category, $header, $body, $footer);
        $stmt->execute();
        header("Location: templates.php?msg=added");
        exit();
    }
}

$templates = $conn->query("SELECT * FROM message_templates ORDER BY id DESC");
$total     = $conn->query("SELECT COUNT(*) as c FROM message_templates")->fetch_assoc()['c'] ?? 0;

$catMeta = [
    'marketing'      => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.3)', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:3px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>'],
    'utility'        => ['color' => '#29b6f6', 'bg' => 'rgba(41,182,246,0.12)', 'border' => 'rgba(41,182,246,0.3)', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:3px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
    'authentication' => ['color' => '#e91e63', 'bg' => 'rgba(233,30,99,0.12)',  'border' => 'rgba(233,30,99,0.3)',  'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:3px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'],
];

include '../includes/admin_header.php';
?>

<style>
/* ── Templates page ── */
/* ── Header action buttons ── */
.tpl-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* Shared action-button base — overrides .btn defaults for consistent height */
.tpl-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    line-height: 1;
}

/* Ghost — Back link */
.tpl-btn-ghost {
    background: transparent;
    color: var(--text-secondary);
    border-color: var(--border);
}
.tpl-btn-ghost:hover {
    background: rgba(255,255,255,0.06);
    color: var(--text-primary);
    border-color: rgba(255,255,255,0.15);
}

/* Outlined neutral — Meta link */
.tpl-btn-meta {
    background: rgba(41,182,246,0.08);
    color: var(--info);
    border-color: rgba(41,182,246,0.25);
}
.tpl-btn-meta:hover {
    background: rgba(41,182,246,0.16);
    border-color: rgba(41,182,246,0.5);
}

/* Outlined success — Sync */
.tpl-btn-sync {
    background: rgba(0,200,83,0.08);
    color: var(--success);
    border-color: rgba(0,200,83,0.25);
}
.tpl-btn-sync:hover:not(:disabled) {
    background: rgba(0,200,83,0.16);
    border-color: rgba(0,200,83,0.5);
}
.tpl-btn-sync:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

/* Solid primary — Create CTA */
.tpl-btn-primary {
    background: linear-gradient(135deg, var(--accent) 0%, #a855f7 100%);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 2px 12px rgba(108,92,231,0.35);
}
.tpl-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 20px rgba(108,92,231,0.5);
}
.tpl-btn-primary:active { transform: translateY(0); }

.tpl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
    align-items: start;
}

.tpl-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: var(--transition);
    position: relative;
}

.tpl-card:hover {
    transform: translateY(-3px);
    border-color: rgba(108,92,231,0.35);
    box-shadow: 0 8px 32px rgba(0,0,0,0.35);
}

.tpl-card-stripe {
    height: 3px;
    width: 100%;
    flex-shrink: 0;
}

.tpl-card-body {
    padding: 18px 18px 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tpl-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}

.tpl-card-name {
    font-size: 14.5px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
    word-break: break-word;
}

.tpl-cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    white-space: nowrap;
    flex-shrink: 0;
    border: 1px solid;
}

.tpl-delete-btn {
    padding: 5px 8px;
    font-size: 14px;
    line-height: 1;
    border-radius: 7px;
    cursor: pointer;
    background: rgba(255,71,87,0.08);
    border: 1px solid rgba(255,71,87,0.18);
    color: var(--danger);
    transition: var(--transition);
    flex-shrink: 0;
}

.tpl-delete-btn:hover {
    background: rgba(255,71,87,0.2);
    border-color: rgba(255,71,87,0.4);
}

.tpl-preview-header {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    padding: 7px 12px;
    background: var(--bg-input);
    border-radius: 7px;
    border: 1px solid var(--border);
}

.tpl-preview-body {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.65;
    white-space: pre-line;
    padding: 10px 12px;
    background: var(--bg-input);
    border-radius: 7px;
    border-left: 3px solid #25d366;
    border: 1px solid var(--border);
    border-left: 3px solid #25d366;
    max-height: 130px;
    overflow-y: auto;
    flex: 1;
}

.tpl-preview-footer {
    font-size: 11.5px;
    color: var(--text-muted);
    font-style: italic;
    padding: 0 2px;
}

.tpl-card-foot {
    padding: 10px 18px 14px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.tpl-status-dot {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--success);
}

.tpl-status-dot::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--success);
    flex-shrink: 0;
}

.tpl-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 72px 24px;
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    color: var(--text-muted);
}

.tpl-empty-icon { font-size: 40px; margin-bottom: 14px; }
.tpl-empty h3 { font-size: 18px; color: var(--text-secondary); margin-bottom: 8px; }
.tpl-empty p  { font-size: 14px; margin-bottom: 20px; }

/* Modal */
.tpl-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 1500;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.tpl-modal-overlay.show { display: flex; }

.tpl-modal {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
    width: 580px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}

.tpl-modal-head {
    padding: 22px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.tpl-modal-head h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: var(--text-primary);
}

.tpl-modal-close {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    cursor: pointer;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 18px;
    font-weight: 700;
    transition: var(--transition);
    flex-shrink: 0;
    line-height: 1;
    padding: 0;
}

.tpl-modal-close:hover { background: rgba(255,71,87,0.15); border-color: var(--danger); color: var(--danger); }

.tpl-modal-body { padding: 22px 24px 24px; }

.tpl-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.tpl-field { margin-bottom: 16px; }
.tpl-field:last-child { margin-bottom: 0; }

.tpl-field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 7px;
}

.tpl-field label small {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
}

.tpl-modal-foot {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
}

.btn-meta {
    background: rgba(41,182,246,0.1);
    color: var(--info);
    border: 1px solid rgba(41,182,246,0.25);
}

.btn-meta:hover { background: rgba(41,182,246,0.2); border-color: rgba(41,182,246,0.4); }

@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

@media (max-width: 640px) {
    .tpl-header-actions { width: 100%; justify-content: flex-start; }
    .tpl-field-row { grid-template-columns: 1fr; }
    .tpl-grid { grid-template-columns: 1fr; }
}
</style>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        Template <?php echo htmlspecialchars(((string)$_GET['msg'] === 'deleted') ? 'deleted' : 'added'); ?> successfully.
    </div>
<?php endif; ?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg> Message Templates
        <span style="font-size:14px;color:var(--text-muted);font-weight:400;margin-left:8px;"><?php echo $total; ?> template<?php echo $total !== 1 ? 's' : ''; ?></span>
    </h2>
    <div class="tpl-header-actions">
        <!-- Back — ghost style, SVG arrow replaces ← entity that rendered as < -->
        <a href="campaigns.php" class="tpl-btn tpl-btn-ghost">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            Campaigns
        </a>

        <!-- Meta link — info/blue outlined -->
        <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener" class="tpl-btn tpl-btn-meta">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
            Manage on Meta
        </a>

        <!-- Sync — success outlined; uses data-spans so JS never touches HTML that contains SVG -->
        <button type="button" id="syncBtn" class="tpl-btn tpl-btn-sync" onclick="syncTemplates(this)">
            <svg id="syncIcon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
            <span id="syncLabel">Sync Templates</span>
        </button>

        <!-- Create — solid primary CTA -->
        <button type="button" class="tpl-btn tpl-btn-primary" onclick="openModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Create Template
        </button>
    </div>
</div>

<!-- Template grid -->
<div class="tpl-grid">
    <?php if (!$templates || $templates->num_rows === 0): ?>
        <div class="tpl-empty">
            <div class="tpl-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="40" height="40"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></div>
            <h3>No templates yet</h3>
            <p>Create your first message template to use in campaigns and bot replies.</p>
            <button type="button" class="tpl-btn tpl-btn-primary" onclick="openModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Create Template
            </button>
        </div>
    <?php else: while ($t = $templates->fetch_assoc()):
        $cm = $catMeta[$t['category']] ?? ['color' => '#a78bfa', 'bg' => 'rgba(167,139,250,0.12)', 'border' => 'rgba(167,139,250,0.3)', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:3px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'];
    ?>
        <div class="tpl-card">
            <div class="tpl-card-stripe" style="background:<?php echo $cm['color']; ?>;"></div>

            <div class="tpl-card-body">
                <div class="tpl-card-top">
                    <div>
                        <div class="tpl-card-name"><?php echo htmlspecialchars($t['name']); ?></div>
                        <div style="margin-top:5px;">
                            <span class="tpl-cat-badge" style="color:<?php echo $cm['color']; ?>;background:<?php echo $cm['bg']; ?>;border-color:<?php echo $cm['border']; ?>;">
                                <?php echo $cm['icon']; ?> <?php echo ucfirst($t['category']); ?>
                            </span>
                        </div>
                    </div>
                    <form method="POST" action="templates.php" style="margin:0;" onsubmit="return confirm('Delete this template?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                        <button type="submit" class="tpl-delete-btn" title="Delete template"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
                    </form>
                </div>

                <?php if (!empty($t['header_text'])): ?>
                    <div class="tpl-preview-header"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg> <?php echo htmlspecialchars($t['header_text']); ?></div>
                <?php endif; ?>

                <div class="tpl-preview-body"><?php echo htmlspecialchars($t['body_text']); ?></div>

                <?php if (!empty($t['footer_text'])): ?>
                    <div class="tpl-preview-footer">– <?php echo htmlspecialchars($t['footer_text']); ?></div>
                <?php endif; ?>
            </div>

            <div class="tpl-card-foot">
                <span class="tpl-status-dot"><?php echo ucfirst($t['wa_status'] ?? $t['status'] ?? 'approved'); ?></span>
                <span style="font-size:11px;color:var(--text-muted);">ID #<?php echo (int)$t['id']; ?></span>
            </div>
        </div>
    <?php endwhile; endif; ?>
</div>

<!-- Create Template Modal -->
<div class="tpl-modal-overlay" id="tplModal">
    <div class="tpl-modal">
        <div class="tpl-modal-head">
            <h3>New Message Template</h3>
            <button type="button" class="tpl-modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="templates.php">
            <?php echo csrf_field(); ?>
            <div class="tpl-modal-body">
                <div class="tpl-field-row">
                    <div class="tpl-field">
                        <label>Template Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Welcome Message" required>
                    </div>
                    <div class="tpl-field">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <option value="marketing"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg> Marketing</option>
                            <option value="utility"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> Utility</option>
                            <option value="authentication"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Authentication</option>
                        </select>
                    </div>
                </div>

                <div class="tpl-field">
                    <label>Header Text <small>(optional)</small></label>
                    <input type="text" name="header_text" class="form-control" placeholder="e.g. Welcome to {{1}}!">
                </div>

                <div class="tpl-field">
                    <label>Body Text * <small>— use {{1}}, {{2}} for variables</small></label>
                    <textarea name="body_text" class="form-control" rows="5" placeholder="Hi {{1}},&#10;&#10;Welcome to our store!" required></textarea>
                </div>

                <div class="tpl-field">
                    <label>Footer Text <small>(optional)</small></label>
                    <input type="text" name="footer_text" class="form-control" placeholder="e.g. Powered by WAAMINI">
                </div>
            </div>

            <div class="tpl-modal-foot">
                <button type="button" class="btn btn-sm btn-edit" onclick="closeModal()">Cancel</button>
                <button type="submit" name="add_template" class="btn btn-sm btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Template</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function openModal() {
    document.getElementById('tplModal').classList.add('show');
}

function closeModal() {
    document.getElementById('tplModal').classList.remove('show');
}

document.getElementById('tplModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function syncTemplates(btn) {
    /* Only mutate the label <span> — never btn.innerHTML — so the SVG icon stays intact
       and no ">" characters appear from innerHTML serialisation of attribute syntax */
    var label = document.getElementById('syncLabel');
    var icon  = document.getElementById('syncIcon');
    var orig  = label.textContent;

    label.textContent = 'Syncing...';
    icon.style.animation = 'spin 0.9s linear infinite';
    btn.disabled = true;

    fetch('sync_wa_templates.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=<?php echo csrf_get_token(); ?>'
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        icon.style.animation = '';
        label.textContent = d.success ? 'Synced!' : 'Failed';
        setTimeout(function() {
            label.textContent = orig;
            btn.disabled = false;
            if (d.success) location.reload();
        }, 1800);
    })
    .catch(function() {
        icon.style.animation = '';
        label.textContent = 'Error';
        setTimeout(function() {
            label.textContent = orig;
            btn.disabled = false;
        }, 1800);
    });
}
</script>

<?php include '../includes/admin_footer.php'; ?>
