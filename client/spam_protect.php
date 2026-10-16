<?php
include '../includes/client_auth_check.php';

$pageTitle = 'Spam Protection';
$currentPage = '';

$success = '';
$error = '';

// Handle Unblock
if (isset($_GET['unblock'])) {
    $cid = intval($_GET['unblock']);
    $stmt = $conn->prepare("UPDATE contacts SET status = 'active' WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $cid, $companyId);
    if ($stmt->execute()) {
        $success = "Contact unblocked successfully!";
    }
    else {
        $error = "Failed to unblock contact.";
    }
}

// Fetch blocked contacts
$stSp1 = $conn->prepare("SELECT * FROM contacts WHERE company_id = ? AND status = 'blocked' ORDER BY updated_at DESC");
$stSp1->bind_param("i", $companyId);
$stSp1->execute();
$blocked = $stSp1->get_result();

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg> Spam Protection Manager</h2>
    <p style="color:var(--text-muted); font-size:14px;">Manage contacts who have opted out of your messages via the 'STOP' command.</p>
</div>

<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php
endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

<div class="data-table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Opt-out Date</th>
                <th>Notes</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($blocked && $blocked->num_rows > 0): ?>
                <?php while ($c = $blocked->fetch_assoc()): ?>
                    <tr>
                        <td style="color:var(--text-primary); font-weight:500;"><?php echo htmlspecialchars($c['name']); ?></td>
                        <td><?php echo htmlspecialchars($c['phone']); ?></td>
                        <td><?php echo date('d M Y, h:i A', strtotime($c['updated_at'])); ?></td>
                        <td style="max-width:300px; font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($c['notes'] ?? '-'); ?></td>
                        <td>
                            <a href="spam_protect.php?unblock=<?php echo $c['id']; ?>" class="btn btn-sm" style="background:var(--primary); color:#fff; text-decoration:none;" onclick="return confirm('Allow messages to this contact again?')">Unblock</a>
                        </td>
                    </tr>
                <?php
    endwhile; ?>
            <?php
else: ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <div class="icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg></div>
                            <p>No blocked contacts found. Your list is clean!</p>
                        </div>
                    </td>
                </tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<div class="form-card" style="margin-top:24px; border:1px dashed var(--border); background:rgba(255,255,255,0.01);">
    <h3 style="font-size:16px; margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg> How Spam Protect Works</h3>
    <p style="color:var(--text-secondary); font-size:13px; line-height:1.6;">
        When a customer replies with <strong>"STOP"</strong>, our system automatically updates their status to <strong>'blocked'</strong>. 
        The Pulse Automation Engine will skip sending any further messages to these contacts to ensure your number remains safe and compliant with WhatsApp policies.
    </p>
</div>

<?php include '../includes/client_footer.php'; ?>
