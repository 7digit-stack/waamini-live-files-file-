<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Add Company';
$currentPage = 'companies';

$message = '';
$error = '';

if (isset($_POST['submit'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_phone($_POST['phone'] ?? '');

    if (empty($name)) {
        $error = "Company name is required!";
    }
    else {
        $stmt = $conn->prepare("INSERT INTO companies (name, email, phone) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $email, $phone);
        $stmt->execute();
        header("Location: companies.php?msg=created");
        exit();
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2>+ Add New Company</h2>
    <a href="companies.php" class="btn btn-sm btn-edit">← Back to List</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Company Name *</label>
            <input type="text" name="name" class="form-control" placeholder="Enter company name" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" placeholder="company@example.com">
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" placeholder="Phone number">
        </div>
        <button type="submit" name="submit" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9.5 8h5"/><path d="M9.5 12H16"/><path d="M9.5 16H14"/></svg> Create Company</button>
    </form>
</div>

<?php include '../includes/admin_footer.php'; ?>
