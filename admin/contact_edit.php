<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Edit Contact';
$currentPage = 'contacts';

if (!isset($_GET['id'])) {
    header("Location: contacts.php");
    exit();
}

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM contacts WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$contact = $stmt->get_result()->fetch_assoc();

if (!$contact) {
    header("Location: contacts.php");
    exit();
}

$companies = $conn->query("SELECT id, name FROM companies WHERE status='active' ORDER BY name");
$error = '';

if (isset($_POST['submit'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $phone = sanitize_phone($_POST['phone']);
    $email = sanitize_email($_POST['email'] ?? '');
    $tags = sanitize($_POST['tags'] ?? '');
    $company_id = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
    $notes = sanitize($_POST['notes'] ?? '');
    $status = in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($name) || empty($phone)) {
        $error = "Name and Phone are required!";
    }
    elseif (!validate_phone($phone)) {
        $error = "Invalid phone number format!";
    }
    elseif (!empty($email) && !validate_email($email)) {
        $error = "Invalid email format!";
    }
    else {
        // Check duplicate phone (exclude current)
        $check = $conn->prepare("SELECT id FROM contacts WHERE phone = ? AND id != ?");
        $check->bind_param("si", $phone, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Another contact with this phone number already exists!";
        }
        else {
            $stmt = $conn->prepare("UPDATE contacts SET name=?, phone=?, email=?, tags=?, company_id=?, notes=?, status=? WHERE id=?");
            $stmt->bind_param("ssssissi", $name, $phone, $email, $tags, $company_id, $notes, $status, $id);
            $stmt->execute();
            header("Location: contacts.php?msg=updated");
            exit();
        }
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="vertical-align:middle;"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg> Edit Contact #<?php echo $id; ?></h2>
    <a href="contacts.php" class="btn btn-sm btn-edit">← Back to Contacts</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Name *</label>
            <input type="text" name="name" class="form-control" 
                   value="<?php echo htmlspecialchars($contact['name']); ?>" required>
        </div>
        <div class="form-group">
            <label>Phone *</label>
            <input type="text" name="phone" class="form-control" 
                   value="<?php echo htmlspecialchars($contact['phone']); ?>" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" 
                   value="<?php echo htmlspecialchars($contact['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Tags <small style="color: var(--text-muted);">(comma separated)</small></label>
            <input type="text" name="tags" class="form-control" 
                   value="<?php echo htmlspecialchars($contact['tags'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Company</label>
            <select name="company_id" class="form-control">
                <option value="">— No Company —</option>
                <?php while ($co = $companies->fetch_assoc()): ?>
                    <option value="<?php echo $co['id']; ?>" <?php echo($contact['company_id'] == $co['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($co['name']); ?>
                    </option>
                <?php
endwhile; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="active" <?php echo $contact['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $contact['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($contact['notes'] ?? ''); ?></textarea>
        </div>
        <button type="submit" name="submit" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Changes</button>
    </form>
</div>

<?php include '../includes/admin_footer.php'; ?>
