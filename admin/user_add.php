<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Add User';
$currentPage = 'users';

$error = '';

// Fetch companies for dropdown
$companies = $conn->query("SELECT id, name FROM companies WHERE status='active' ORDER BY name");

if (isset($_POST['submit'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $mobile = sanitize_phone($_POST['mobile'] ?? '');
    $password = $_POST['password'];
    $companyId = intval($_POST['company_id']);
    $role = in_array($_POST['role'], ['client', 'super_admin']) ? $_POST['role'] : 'client';

    if (empty($name) || empty($email) || empty($password)) {
        $error = "Name, email and password are required!";
    }
    elseif (!validate_email($email)) {
        $error = "Invalid email format!";
    }
    elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    }
    else {
        // Check duplicate email
        $check = $conn->prepare("SELECT id FROM users WHERE email=?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Email already exists!";
        }
        else {
            $hashedPass = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, mobile, company_id, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssis", $name, $email, $hashedPass, $mobile, $companyId, $role);
            $stmt->execute();
            header("Location: users.php?msg=created");
            exit();
        }
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2>+ Add New User</h2>
    <a href="users.php" class="btn btn-sm btn-edit">← Back to List</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="name" class="form-control" placeholder="Enter full name" required>
        </div>
        <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" class="form-control" placeholder="user@example.com" required>
        </div>
        <div class="form-group">
            <label>Password *</label>
            <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
        </div>
        <div class="form-group">
            <label>Mobile</label>
            <input type="text" name="mobile" class="form-control" placeholder="Phone number">
        </div>
        <div class="form-group">
            <label>Company *</label>
            <select name="company_id" class="form-control" required>
                <option value="">Select Company</option>
                <?php while ($c = $companies->fetch_assoc()): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php
endwhile; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Role *</label>
            <select name="role" class="form-control" required>
                <option value="client">Client</option>
                <option value="super_admin">Super Admin</option>
            </select>
        </div>
        <button type="submit" name="submit" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Create User</button>
    </form>
</div>

<?php include '../includes/admin_footer.php'; ?>
