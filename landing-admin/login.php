<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if (la_is_logged_in()) { header('Location: /landing-admin/'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    $st = $conn->prepare("SELECT id,name,email,password,is_active FROM lp_admins WHERE email=? LIMIT 1");
    if ($st) {
        $st->bind_param('s', $email);
        $st->execute();
        $admin = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if (!$admin || !password_verify($pass, $admin['password'])) {
        $error = 'Invalid email or password.';
    } elseif (!$admin['is_active']) {
        $error = 'This account has been disabled.';
    } else {
        // Update last login
        $conn->query("UPDATE lp_admins SET last_login=NOW() WHERE id=".(int)$admin['id']);

        $_SESSION['la_admin_id']    = (int)$admin['id'];
        $_SESSION['la_admin_name']  = $admin['name'];
        $_SESSION['la_admin_email'] = $admin['email'];

        $next = (string)($_GET['next'] ?? '');
        $safe = $next && strpos($next, '/landing-admin/') === 0 ? $next : '/landing-admin/';
        header('Location: ' . $safe); exit;
    }
}
// Same look as the main login page (split-screen layout)
$GLOBALS['AUTH_BASE'] = '../';
require_once __DIR__ . '/../includes/auth_layout.php';
auth_open('Landing CMS login', [['Website', '../index.php'], ['Client login', '../login.php']]);
?>
      <div class="auth__intro">
        <p class="eyebrow">Content management</p>
        <h1>Landing CMS<span>.</span></h1>
        <p class="lead">Sign in to manage your website pages, blog and offers.</p>
      </div>

      <?php auth_alert((string)$error, 'error'); ?>

      <form class="form" method="POST" autocomplete="off">
        <?php auth_field('email', 'email', 'email', 'Email', 'admin@waamini.com', 'mail', ['autocomplete' => 'email', 'required' => 1, 'value' => (string)($_POST['email'] ?? '')]); ?>
        <?php auth_field('password', 'password', 'password', 'Password', 'Your password', 'lock', ['autocomplete' => 'current-password', 'required' => 1]); ?>
        <div class="form__actions">
          <a class="btn btn--ghost" href="../login.php">Client login</a>
          <button class="btn btn--primary" type="submit">Log in</button>
        </div>
      </form>
<?php
auth_close();
