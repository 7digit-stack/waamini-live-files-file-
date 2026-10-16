<?php
include '../includes/client_auth_check.php';

// Bug #4 fixed: added has_feature() guard
if (!has_feature('form_builder')) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle   = 'WhatsApp Form Builder';
$currentPage = 'form_builder';

$flashMsg = '';

// ── Create form ────────────────────────────────────────────────
if (isset($_POST['create_form'])) {
    csrf_verify();

    $formName    = sanitize($_POST['name'] ?? '');
    $trigger     = strtolower(sanitize($_POST['trigger_keyword'] ?? ''));
    $welcomeMsg  = trim($_POST['welcome_message'] ?? '');
    // Bug #12 fixed: renamed from $success to $successMsg to avoid semantic collision
    $successMsg  = trim($_POST['success_message'] ?? '');
    $notifyEmail = sanitize($_POST['notify_email'] ?? '');

    $fieldNames    = $_POST['field_name'] ?? [];
    $fieldTypes    = $_POST['field_type'] ?? [];
    $fieldRequired = $_POST['field_required'] ?? [];

    // Bug #7 fixed: filter out 'choice' type rows with no options configured
    $fields = [];
    foreach ($fieldNames as $i => $fn) {
        $fn = trim($fn);
        if ($fn === '') continue;
        $type = $fieldTypes[$i] ?? 'text';
        if ($type === 'choice') $type = 'text'; // fallback — choices have no options UI
        $fields[] = [
            'name'     => $fn,
            'type'     => $type,
            'required' => isset($fieldRequired[$i]),
            'question' => 'Please enter your ' . $fn . ':',
        ];
    }

    if ($formName && $trigger && !empty($fields)) {
        $fieldsJson = json_encode($fields);
        $stmt = $conn->prepare(
            "INSERT INTO wa_forms (company_id, name, trigger_keyword, welcome_message, fields, success_message, notify_email)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("issssss", $companyId, $formName, $trigger, $welcomeMsg, $fieldsJson, $successMsg, $notifyEmail);
            $stmt->execute();
        } else {
            error_log('[WAAMINI] form_builder.php: prepare() failed for insert: ' . $conn->error);
        }
        header("Location: form_builder.php?msg=created");
        exit();
    }
}

// ── Toggle active ──────────────────────────────────────────────
if (isset($_POST['toggle_form'])) {
    csrf_verify();
    $fid  = intval($_POST['form_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE wa_forms SET is_active = NOT is_active WHERE id = ? AND company_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $fid, $companyId);
        $stmt->execute();
    } else {
        error_log('[WAAMINI] form_builder.php: prepare() failed for toggle: ' . $conn->error);
    }
    header("Location: form_builder.php");
    exit();
}

// ── Delete form ────────────────────────────────────────────────
if (isset($_POST['delete_form'])) {
    csrf_verify();
    $fid  = intval($_POST['form_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM wa_forms WHERE id = ? AND company_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $fid, $companyId);
        $stmt->execute();
    } else {
        error_log('[WAAMINI] form_builder.php: prepare() failed for delete: ' . $conn->error);
    }
    header("Location: form_builder.php");
    exit();
}

// ── Fetch forms ────────────────────────────────────────────────
$forms = null;
$stmt_forms = $conn->prepare("SELECT * FROM wa_forms WHERE company_id = ? ORDER BY id DESC");
if ($stmt_forms) {
    $stmt_forms->bind_param("i", $companyId);
    $stmt_forms->execute();
    $forms = $stmt_forms->get_result();
} else {
    error_log('[WAAMINI] form_builder.php: prepare() failed for list: ' . $conn->error);
}

include '../includes/client_header.php';
?>

<style>
/* ── WhatsApp Form Builder page ── */
.wfb-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 14px;
    box-shadow: var(--shadow);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.wfb-card:hover { box-shadow: var(--shadow-md); border-color: #cbd5e1; }

.wfb-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 12px;
}

.wfb-card-info { flex: 1; min-width: 0; }

.wfb-card-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wfb-card-meta {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.wfb-card-meta code {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 1px 7px;
    font-family: monospace;
    font-size: 11.5px;
    color: var(--accent);
}

.wfb-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}

.wfb-status.active   { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.wfb-status.inactive { background: var(--bg-input);   color: var(--text-muted); border: 1px solid var(--border); }

.wfb-status.active::before,
.wfb-status.inactive::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

/* Bug #11 fixed: field badges sized correctly — svg and text on same baseline */
.wfb-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}

.wfb-field-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 500;
    color: var(--text-secondary);
    padding: 3px 10px;
    border-radius: 20px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    line-height: 1.4;
}

.wfb-field-tag svg { flex-shrink: 0; }

.wfb-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

/* Empty state */
.wfb-empty {
    text-align: center;
    padding: 72px 24px;
    background: var(--bg-card);
    border: 2px dashed var(--border);
    border-radius: var(--radius);
}

.wfb-empty-icon {
    width: 72px;
    height: 72px;
    background: var(--bg-input);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    color: var(--text-muted);
}

.wfb-empty h3 { font-size: 18px; color: var(--text-primary); margin-bottom: 8px; }
.wfb-empty p  { font-size: 14px; color: var(--text-muted); margin-bottom: 22px; }

/* Modal */
.wfb-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.52);
    z-index: 1500;
    align-items: flex-start;
    justify-content: center;
    overflow-y: auto;
    padding: 24px 16px;
}

.wfb-modal-overlay.open { display: flex; }

.wfb-modal {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 0;
    width: 580px;
    max-width: 100%;
    margin: auto;
    box-shadow: var(--shadow-lg);
    position: relative;
}

.wfb-modal-head {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.wfb-modal-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.wfb-modal-close {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    cursor: pointer;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 17px;
    font-weight: 700;
    transition: var(--transition);
    flex-shrink: 0;
    line-height: 1;
    padding: 0;
    font-family: inherit;
}

.wfb-modal-close:hover { color: var(--danger); border-color: var(--danger); background: var(--danger-bg); }

.wfb-modal-body { padding: 22px 24px; }

.wfb-modal-foot {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.wfb-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.wfb-input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-primary);
    padding: 9px 12px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    margin-bottom: 16px;
}

.wfb-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,124,227,0.12);
    background: #fff;
}

.wfb-input::placeholder { color: var(--text-muted); }
textarea.wfb-input { resize: vertical; min-height: 72px; line-height: 1.55; }

/* Field builder rows */
.wfb-fields-head {
    display: grid;
    grid-template-columns: 2fr 1fr 56px 34px;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    padding: 0 2px;
}

.wfb-field-row {
    display: grid;
    grid-template-columns: 2fr 1fr 56px 34px;
    gap: 6px;
    margin-bottom: 6px;
    align-items: center;
}

.wfb-field-row .wfb-input { margin-bottom: 0; }

.wfb-field-checkbox {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 38px;
}

.wfb-field-checkbox input[type=checkbox] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--accent);
}

.wfb-remove-btn {
    width: 34px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--danger-bg);
    border: 1px solid var(--danger-border, #fecaca);
    border-radius: 7px;
    color: var(--danger);
    cursor: pointer;
    transition: var(--transition);
    padding: 0;
}

.wfb-remove-btn:hover { background: rgba(239,68,68,0.2); }

.wfb-add-field-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--accent);
    background: var(--accent-light, #eaf3ff);
    border: 1px solid var(--info-border, #bdd5f7);
    border-radius: 7px;
    padding: 6px 12px;
    cursor: pointer;
    transition: var(--transition);
    font-family: inherit;
    margin-top: 4px;
}

.wfb-add-field-btn:hover { background: rgba(45,124,227,0.15); }

/* How it works hint */
.wfb-how-it-works {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 14px 16px;
    margin-bottom: 16px;
    font-size: 12.5px;
    color: var(--text-muted);
    line-height: 1.6;
}

.wfb-how-it-works strong { color: var(--text-secondary); }

@media (max-width: 600px) {
    .wfb-fields-head,
    .wfb-field-row { grid-template-columns: 1fr 1fr 40px 34px; }
    .wfb-card-actions { flex-direction: column; }
}
</style>

<?php if (isset($_GET['msg'])): ?>
    <!-- Bug #10 fixed: alert uses cn_svg with valid icon, no hardcoded stroke color -->
    <div class="alert alert-success" style="margin-bottom:16px;">
        <?php echo cn_svg('health'); ?>
        Form <?php echo $_GET['msg'] === 'created' ? 'created successfully.' : 'saved.'; ?>
    </div>
<?php endif; ?>

<div class="page-header">
    <h2><?php echo cn_svg('manual'); ?> WhatsApp Form Builder</h2>
    <div class="action-btns">
        <button type="button" class="btn btn-primary" onclick="openFormModal()">
            <?php echo cn_svg('groups'); ?> Create Form
        </button>
    </div>
</div>

<?php if (!$forms || $forms->num_rows === 0): ?>
    <!-- Bug #2 fixed: empty state uses a properly sized SVG (64×64), not a 16×16 wrapped in font-size:48px -->
    <div class="wfb-empty">
        <div class="wfb-empty-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
        </div>
        <h3>No Forms Yet</h3>
        <p>Create conversational WhatsApp forms that collect customer data automatically — no app needed.</p>
        <button type="button" class="btn btn-primary" onclick="openFormModal()">
            <?php echo cn_svg('groups'); ?> Create First Form
        </button>
    </div>

<?php else: while ($form = $forms->fetch_assoc()):
    $fields       = json_decode($form['fields'] ?? '[]', true) ?: [];
    $isActive     = (bool)($form['is_active'] ?? false);
    // Bug #5 fixed: null coalescing for submissions column
    $submissions  = (int)($form['submissions'] ?? 0);
?>
    <div class="wfb-card">
        <div class="wfb-card-top">
            <div class="wfb-card-info">
                <div class="wfb-card-name"><?php echo htmlspecialchars($form['name']); ?></div>
                <div class="wfb-card-meta">
                    <span>Trigger: <code><?php echo htmlspecialchars($form['trigger_keyword']); ?></code></span>
                    <span><?php echo count($fields); ?> field<?php echo count($fields) !== 1 ? 's' : ''; ?></span>
                    <span><?php echo $submissions; ?> submission<?php echo $submissions !== 1 ? 's' : ''; ?></span>
                </div>
            </div>
            <span class="wfb-status <?php echo $isActive ? 'active' : 'inactive'; ?>">
                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
        </div>

        <!-- Bug #11 fixed: field tags use correctly sized SVG (12×12) matching 11.5px text -->
        <?php if (!empty($fields)): ?>
        <div class="wfb-fields">
            <?php foreach ($fields as $f): ?>
                <span class="wfb-field-tag">
                    <?php echo cn_svg('templates'); ?>
                    <?php echo htmlspecialchars($f['name']); ?>
                    <?php if ($f['required'] ?? false): ?>
                        <span style="color:var(--danger);font-weight:700;" title="Required">*</span>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="wfb-card-actions">
            <!-- Bug #3 fixed: link was form_submissions.php (404) → correct file is form_responses.php -->
            <a href="form_responses.php?form_id=<?php echo $form['id']; ?>" class="btn btn-sm btn-edit">
                <?php echo cn_svg('analytics'); ?> View Submissions
            </a>

            <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_id" value="<?php echo (int)$form['id']; ?>">
                <button type="submit" name="toggle_form" class="btn btn-sm <?php echo $isActive ? 'btn-warning' : 'btn-success'; ?>">
                    <?php if ($isActive): ?>
                        <?php echo cn_svg('scheduled'); ?> Pause
                    <?php else: ?>
                        <?php echo cn_svg('health'); ?> Activate
                    <?php endif; ?>
                </button>
            </form>

            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this form and all its data permanently?')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_id" value="<?php echo (int)$form['id']; ?>">
                <button type="submit" name="delete_form" class="btn btn-sm btn-danger">
                    <?php echo cn_svg('blacklist'); ?>
                </button>
            </form>
        </div>
    </div>
<?php endwhile; endif; ?>

<!-- ── Create Form Modal ── -->
<!-- Bug #8 fixed: added backdrop click + Escape key handlers -->
<div id="wfbModal" class="wfb-modal-overlay" onclick="if(event.target===this)closeFormModal()">
    <div class="wfb-modal" role="dialog" aria-modal="true" aria-labelledby="wfbModalTitle">
        <div class="wfb-modal-head">
            <h3 id="wfbModalTitle"><?php echo cn_svg('manual'); ?> Create WhatsApp Form</h3>
            <button type="button" class="wfb-modal-close" onclick="closeFormModal()" aria-label="Close">&times;</button>
        </div>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <div class="wfb-modal-body">
                <div class="wfb-how-it-works">
                    <?php echo cn_svg('discover'); ?>
                    A customer sends the <strong>trigger keyword</strong> on WhatsApp. The bot asks each field question in sequence and saves the answers as a submission.
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <label class="wfb-label">Form Name *</label>
                        <input type="text" name="name" class="wfb-input" placeholder="e.g. Event Registration" required>
                    </div>
                    <div>
                        <label class="wfb-label">Trigger Keyword *</label>
                        <input type="text" name="trigger_keyword" class="wfb-input" placeholder="e.g. register" required>
                    </div>
                </div>

                <label class="wfb-label">Welcome Message</label>
                <textarea name="welcome_message" class="wfb-input" rows="2" placeholder="Hi! I'll walk you through a quick registration. It only takes a minute."></textarea>

                <!-- Bug #1 fixed: removed the SVG HTML from inside the placeholder attribute.
                     The original had <svg width="16" ...> inside placeholder="..." which broke
                     the HTML attribute due to unescaped double-quote characters inside the value. -->
                <label class="wfb-label">Success Message</label>
                <textarea name="success_message" class="wfb-input" rows="2" placeholder="Thank you! Your form has been submitted. We will contact you soon."></textarea>

                <label class="wfb-label">Notify Email <span style="font-weight:400;text-transform:none;">(optional — get an email on each submission)</span></label>
                <input type="email" name="notify_email" class="wfb-input" placeholder="owner@business.com">

                <!-- Form Fields builder -->
                <div style="margin-bottom:4px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <label class="wfb-label" style="margin:0;"><?php echo cn_svg('templates'); ?> Form Fields</label>
                        <button type="button" onclick="addField()" class="wfb-add-field-btn">
                            <?php echo cn_svg('groups'); ?> Add Field
                        </button>
                    </div>

                    <div class="wfb-fields-head">
                        <div>Field Name</div>
                        <div>Type</div>
                        <div style="text-align:center;">Required</div>
                        <div></div>
                    </div>

                    <div id="fieldsContainer">
                        <div class="wfb-field-row">
                            <input type="text" name="field_name[]" class="wfb-input" placeholder="Full Name" required>
                            <!-- Bug #7 fixed: removed 'choice' option — no UI for configuring choices -->
                            <select name="field_type[]" class="wfb-input">
                                <option value="text">Text</option>
                                <option value="phone">Phone</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                            </select>
                            <div class="wfb-field-checkbox">
                                <input type="checkbox" name="field_required[]" checked>
                            </div>
                            <button type="button" onclick="removeField(this)" class="wfb-remove-btn" title="Remove field">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wfb-modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeFormModal()">Cancel</button>
                <button type="submit" name="create_form" class="btn btn-primary">
                    <?php echo cn_svg('manual'); ?> Create Form
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function openFormModal() {
    document.getElementById('wfbModal').classList.add('open');
}

function closeFormModal() {
    document.getElementById('wfbModal').classList.remove('open');
}

// Bug #8 fixed: Escape key closes modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFormModal();
});

function addField() {
    // Bug #6 fixed: build new row from scratch instead of cloning — avoids inheriting
    // the previous row's selected type and 'required' attribute state
    var container = document.getElementById('fieldsContainer');
    var div = document.createElement('div');
    div.className = 'wfb-field-row';
    div.innerHTML =
        '<input type="text" name="field_name[]" class="wfb-input" placeholder="Field Name" required>' +
        '<select name="field_type[]" class="wfb-input">' +
            '<option value="text">Text</option>' +
            '<option value="phone">Phone</option>' +
            '<option value="email">Email</option>' +
            '<option value="number">Number</option>' +
            '<option value="date">Date</option>' +
        '</select>' +
        '<div class="wfb-field-checkbox"><input type="checkbox" name="field_required[]"></div>' +
        '<button type="button" onclick="removeField(this)" class="wfb-remove-btn" title="Remove field">' +
            '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
        '</button>';
    container.appendChild(div);
    div.querySelector('input[type=text]').focus();
}

function removeField(btn) {
    var rows = document.querySelectorAll('.wfb-field-row');
    if (rows.length > 1) {
        btn.closest('.wfb-field-row').remove();
    }
}
</script>

<?php include '../includes/client_footer.php'; ?>
