<?php
include 'db.php';
include_once 'includes/security.php';

$message = "";
$validToken = false;

if (isset($_GET['token'])) {

  $rawToken = $_GET['token'];
  $hashedToken = hash('sha256', $rawToken);

  $stmt = $conn->prepare("
        SELECT * FROM password_resets
        WHERE token=? AND expires_at > NOW()
    ");
  $stmt->bind_param("s", $hashedToken);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    $validToken = true;
  }
  else {
    $message = "This reset link is invalid or has expired. Please request a new one.";
  }
}
if (isset($_POST['new_password'])) {
  // CSRF Check
  csrf_verify();

  $pass = $_POST['new_password'] ?? '';
  // Strong password check: min 8, at least one uppercase, one lowercase, one number, one special
  $strongCheck = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $pass);
  
  if (!$strongCheck) {
    $message = "Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number and a special character (@ $ ! % * ? &).";
    $validToken = true; // Keep form open
    $rawToken = $_POST['token'] ?? '';
  } else {
    $newPassword = password_hash($pass, PASSWORD_DEFAULT);

    // Get token from POST hidden field
    $rawToken = $_POST['token'] ?? '';
    $hashedToken = hash('sha256', $rawToken);

    // Get email from token + Validate Expiry again on POST
    $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token=? AND expires_at > NOW()");
    $stmt->bind_param("s", $hashedToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
      $data = $result->fetch_assoc();
      $email = $data['email'];

      // Update password & clear remember token
      $stmt2 = $conn->prepare("UPDATE users SET password=?, remember_token=NULL WHERE email=?");
      $stmt2->bind_param("ss", $newPassword, $email);
      $stmt2->execute();

      // Delete token (auto cleanup)
      $stmt3 = $conn->prepare("DELETE FROM password_resets WHERE token=?");
      $stmt3->bind_param("s", $hashedToken);
      $stmt3->execute();

      $message = "Password reset successful! You can now log in with your new password."; $msgOk = true;
      $validToken = false;
    }
    else {
      $message = "This reset link is invalid or has expired. Please request a new one.";
      $validToken = false;
    }
  }
}
require_once __DIR__ . '/includes/auth_layout.php';
auth_open('Reset password', [['Home', 'index.php'], ['Log in', 'login.php']]);
$done = !empty($msgOk);
?>
      <div class="auth__intro">
        <p class="eyebrow">Account recovery</p>
        <h1>Set a new password<span>.</span></h1>
        <p class="lead"><?php echo $done ? 'You are all set.' : 'Pick a password you have not used before.'; ?></p>
      </div>

      <?php auth_alert((string)$message, $done ? 'success' : 'error'); ?>

      <?php if ($validToken): ?>
      <form class="form" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo auth_e($rawToken); ?>">
        <?php auth_field('np', 'new_password', 'password', 'New password', 'e.g. Waamini@2026', 'lock', ['required' => 1, 'autofocus' => 1, 'minlength' => 8, 'autocomplete' => 'new-password']); ?>
        <div class="guide">
          <b>Your password must have</b>
          At least 8 characters, with an uppercase letter, a lowercase letter, a number and a special character (@ $ ! % * ? &amp;).
        </div>
        <div class="form__actions">
          <a class="btn btn--ghost" href="login.php">Cancel</a>
          <button class="btn btn--primary" type="submit">Reset password</button>
        </div>
      </form>
      <?php elseif ($done): ?>
        <div class="form__actions" style="margin-top:0"><a class="btn btn--primary" href="login.php">Go to login</a></div>
      <?php else: ?>
        <div class="form__actions" style="margin-top:0"><a class="btn btn--primary" href="forgot-password.php">Request a new link</a></div>
      <?php endif; ?>
<?php
auth_close();