<?php
include '../includes/client_auth_check.php';
if (!has_feature('quick_replies')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Quick Replies';
$currentPage = 'whatsapp';

$success = '';
$error = '';

// Handle Delete (VULN-005 FIX: Converted from GET to POST + CSRF)
if (isset($_POST['delete_reply'])) {
    csrf_verify();
    $did = intval($_POST['delete_id']);
    $stDel = $conn->prepare("DELETE FROM quick_replies WHERE id = ? AND company_id = ?");
    $stDel->bind_param("ii", $did, $companyId);
    $stDel->execute();
    header("Location: quick_replies.php?msg=deleted");
    exit();
}

// Handle Add/Edit
if (isset($_POST['save'])) {
    csrf_verify();
    $shortcut = sanitize($_POST['shortcut']);
    $message = sanitize($_POST['message']);
    $rid = intval($_POST['id'] ?? 0);

    if (empty($shortcut) || empty($message)) {
        $error = "All fields are required.";
    }
    else {
        if ($rid > 0) {
            $stmt = $conn->prepare("UPDATE quick_replies SET shortcut=?, message=? WHERE id=? AND company_id=?");
            $stmt->bind_param("ssii", $shortcut, $message, $rid, $companyId);
        }
        else {
            $stmt = $conn->prepare("INSERT INTO quick_replies (company_id, shortcut, message) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $companyId, $shortcut, $message);
        }

        if ($stmt->execute()) {
            $success = "Quick Reply saved!";
        }
        else {
            $error = "Failed to save.";
        }
    }
}

$stQr = $conn->prepare("SELECT * FROM quick_replies WHERE company_id = ? ORDER BY shortcut ASC");
$stQr->bind_param("i", $companyId);
$stQr->execute();
$replies = $stQr->get_result();

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Quick Replies (Canned Responses)</h2>
    <div class="action-btns">
        <button onclick="openModal()" class="btn btn-sm btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create New Reply</button>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Quick reply deleted!</div><?php
endif; ?>
<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>

<div class="data-table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Shortcut</th>
                <th>Message Preview</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($replies && $replies->num_rows > 0):
    while ($r = $replies->fetch_assoc()): ?>
                <tr>
                    <td style="font-weight:700; color:var(--accent);">/<?php echo htmlspecialchars($r['shortcut']); ?></td>
                    <td style="font-size:13px; color:var(--text-secondary);"><?php echo htmlspecialchars(substr($r['message'], 0, 80)) . (strlen($r['message']) > 80 ? '...' : ''); ?></td>
                    <td style="font-size:12px;"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    <td>
                        <div class="action-btns">
                            <button onclick='editReply(<?php echo json_encode($r); ?>)' class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this reply?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" name="delete_reply" class="btn btn-sm btn-danger" style="padding:5px 8px;border-radius:4px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php
    endwhile;
else: ?>
                <tr><td colspan="4"><div class="empty-state"><p>No quick replies created yet.</p></div></td></tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="replyModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; display:none; justify-content:center; align-items:center;">
    <div class="form-card" style="width:100%; max-width:500px; padding:24px;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Add Quick Reply</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="replyId">
            <div class="form-group">
                <label>Shortcut (e.g. "price", "address")</label>
                <div style="display:flex; align-items:center;">
                    <span style="padding:12px; background:var(--bg-input); border-radius:8px 0 0 8px; border:1px solid var(--border); border-right:none;">/</span>
                    <input type="text" name="shortcut" id="replyShortcut" class="form-control" style="border-radius:0 8px 8px 0;" placeholder="price" required>
                </div>
            </div>
            <div class="form-group" style="margin-top:15px;">
                <label>Message Content</label>
                <textarea name="message" id="replyMessage" class="form-control" rows="5" placeholder="Enter the full message text..." required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" onclick="closeModal()" class="btn btn-sm btn-edit">Cancel</button>
                <button type="submit" name="save" class="btn btn-sm btn-primary">Save Reply</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('replyModal');
function openModal() {
    document.getElementById('modalTitle').innerText = 'Add Quick Reply';
    document.getElementById('replyId').value = '';
    document.getElementById('replyShortcut').value = '';
    document.getElementById('replyMessage').value = '';
    modal.style.display = 'flex';
}
function closeModal() {
    modal.style.display = 'none';
}
function editReply(data) {
    document.getElementById('modalTitle').innerText = 'Edit Quick Reply';
    document.getElementById('replyId').value = data.id;
    document.getElementById('replyShortcut').value = data.shortcut;
    document.getElementById('replyMessage').value = data.message;
    modal.style.display = 'flex';
}
window.onclick = function(event) {
    if (event.target == modal) closeModal();
}
</script>

<?php include '../includes/client_footer.php'; ?>
