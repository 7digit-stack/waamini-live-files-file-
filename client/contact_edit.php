<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/drip_engine.php';

if (!has_feature('contacts')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Edit Contact';
$currentPage = 'contacts';

$id = intval($_GET['id'] ?? 0);
$ceStmt = $conn->prepare("SELECT * FROM contacts WHERE id=? AND company_id=?");
$ceStmt->bind_param("ii", $id, $companyId);
$ceStmt->execute();
$contact = $ceStmt->get_result()->fetch_assoc();
if (!$contact) {
    header("Location: contacts.php");
    exit();
}

if (isset($_POST['update'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $phone = sanitize_phone($_POST['phone']);
    $email = sanitize_email($_POST['email'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $clientCompany = sanitize($_POST['client_company'] ?? '');
    
    // Save tags as plain comma-separated text (e.g. "vip,lead,customer")
    $tags_input = sanitize($_POST['tags'] ?? '');
    $tags_parts = [];
    foreach(explode(',', $tags_input) as $t) {
        $t = trim($t);
        if(!empty($t)) $tags_parts[] = $t;
    }
    $tags_plain = implode(',', $tags_parts); // plain CSV, no spaces around commas

    $assigned_to = intval($_POST['assigned_to'] ?? 0);
    $assigned_val = $assigned_to > 0 ? $assigned_to : null;

    $stmt = $conn->prepare("UPDATE contacts SET name=?, phone=?, email=?, address=?, client_company=?, tags=?, assigned_to=? WHERE id=? AND company_id=?");
    $stmt->bind_param("ssssssiii", $name, $phone, $email, $address, $clientCompany, $tags_plain, $assigned_val, $id, $companyId);
    $stmt->execute();

    // Trigger drips based on updated tags (drip engine handles duplicate/already-running checks)
    trigger_drip_campaigns($conn, (int)$companyId, (int)$id, (string)$tags_plain);

    header("Location: contacts.php?msg=updated");
    exit();
}

// Fetch team members for assignment — prepared statement
$tmStmt = $conn->prepare("SELECT id, name FROM users WHERE company_id=? AND status='active'");
$tmStmt->bind_param("i", $companyId);
$tmStmt->execute();
$teamRes = $tmStmt->get_result();

// Parse existing tags
$existing_tags = '';
if (!empty($contact['tags'])) {
    $decoded = json_decode($contact['tags'], true);
    if (is_array($decoded)) {
        $existing_tags = implode(', ', $decoded);
    } else {
        $existing_tags = $contact['tags'];
    }
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit Contact</h2>
    <a href="contacts.php" class="btn btn-sm btn-edit">← Back</a>
</div>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($contact['name']); ?>" required></div>
        <div class="form-group"><label>Phone *</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($contact['phone']); ?>" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($contact['email'] ?? ''); ?>"></div>
        <div class="form-group"><label>Client Business Name</label><input type="text" name="client_company" class="form-control" value="<?php echo htmlspecialchars($contact['client_company'] ?? ''); ?>" placeholder="e.g. Acme Corp"></div>
        <div class="form-group"><label>Billing Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($contact['address'] ?? ''); ?></textarea></div>
        
        <div class="form-group">
            <label>Assigned To</label>
            <select name="assigned_to" class="form-control">
                <option value="0">-- Unassigned --</option>
                <?php if ($teamRes): while($m = $teamRes->fetch_assoc()): ?>
                    <option value="<?php echo $m['id']; ?>" <?php echo ($contact['assigned_to'] == $m['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($m['name']); ?>
                    </option>
                <?php endwhile; endif; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Tags (comma separated)</label>
            <input type="text" name="tags" class="form-control" placeholder="e.g. leads, vip, offer" value="<?php echo htmlspecialchars($existing_tags); ?>">
        </div>
        
        <button type="submit" name="update" class="btn btn-primary" style="margin-top:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Update Contact</button>
    </form>
</div>

<?php include '../includes/client_footer.php'; ?>
