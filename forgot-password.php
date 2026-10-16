<?php
require_once __DIR__ . '/config/bootstrap.php';
include 'db.php';
include_once 'includes/security.php';
include 'smtp.php';

$message = "";

if (isset($_POST['email'])) {

    // 🔐 CSRF Validation
    if (!csrf_verify()) {
        $message = "Your session expired. Please refresh the page and try again.";
    }
    else {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    }
    else {

        // 1️⃣ Check user exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            // 2️⃣ Rate limiting (Max 3 active tokens)
            $rateStmt = $conn->prepare("
                SELECT COUNT(*) as total 
                FROM password_resets 
                WHERE email=? AND expires_at > NOW()
            ");
            $rateStmt->bind_param("s", $email);
            $rateStmt->execute();
            $rateResult = $rateStmt->get_result()->fetch_assoc();

            if ($rateResult['total'] >= 3) {
                $message = "Too many reset attempts. Try again later.";
            }
            else {

                // 3️⃣ Generate Secure Token
                $rawToken = bin2hex(random_bytes(32));
                $hashedToken = hash('sha256', $rawToken);
                $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

                // 4️⃣ Delete old tokens
                $deleteStmt = $conn->prepare("DELETE FROM password_resets WHERE email=?");
                $deleteStmt->bind_param("s", $email);
                $deleteStmt->execute();

                // 5️⃣ Insert new hashed token
                $insertStmt = $conn->prepare("
                    INSERT INTO password_resets (email,token,expires_at)
                    VALUES (?,?,?)
                ");
                $insertStmt->bind_param("sss", $email, $hashedToken, $expiry);
                $insertStmt->execute();

                $protocol = isset($_SERVER['HTTPS']) ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $resetLink = "$protocol://$host/reset-password.php?token=$rawToken";

                // 6️⃣ Send Email
                $mailSent = sendResetMail($email, $resetLink);
                if (!$mailSent) {
                    error_log('[WAAMINI] Password reset email NOT sent to ' . $email . ' - check Admin > Email / SMTP Settings');
                    // Local development only: show the link so the flow can still be tested without SMTP.
                    if (in_array(explode(':', (string)$host)[0], ['localhost', '127.0.0.1'], true)) {
                        $devLink = $resetLink;
                    }
                }

                // 7️⃣ Audit Log
                $ip = $_SERVER['REMOTE_ADDR'];
                $action = "Password reset requested";

                $logStmt = $conn->prepare("
                    INSERT INTO audit_logs (user_email,action,ip_address)
                    VALUES (?,?,?)
                ");
                $logStmt->bind_param("sss", $email, $action, $ip);
                $logStmt->execute();
            }

        }
        // Security: Always return generic response to prevent user discovery.
        $message = "If this email exists in our system, you will receive a reset link shortly.";
        $msgOk = true;
    }
    }
}
require_once __DIR__ . '/includes/auth_layout.php';
auth_open('Forgot password', [['Home', 'index.php'], ['Log in', 'login.php']]);
$sent = !empty($msgOk);
?>
      <div class="auth__intro">
        <p class="eyebrow">Account recovery</p>
        <h1>Forgot password<span>?</span></h1>
        <p class="lead"><?php echo $sent ? 'Check your inbox and spam folder. The link is valid for 1 hour.' : 'Enter the email you signed up with and we will send you a link to set a new password.'; ?></p>
      </div>

      <?php auth_alert((string)$message, $sent ? 'success' : 'error'); ?>
      <?php if (!empty($devLink)): ?>
        <?php auth_alert('<b>Local test mode:</b> email is not set up on this server (Admin &rarr; Email / SMTP Settings), so nothing was sent. <a class="link" href="' . auth_e($devLink) . '">Open the reset link</a>', 'warn', true); ?>
      <?php endif; ?>

      <?php if (!$sent): ?>
      <form class="form" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <?php auth_field('email', 'email', 'email', 'Email', 'you@company.com', 'mail', ['required' => 1, 'autofocus' => 1, 'autocomplete' => 'email', 'value' => (string)($_POST['email'] ?? '')]); ?>
        <div class="form__actions">
          <a class="btn btn--ghost" href="login.php">Back to login</a>
          <button class="btn btn--primary" type="submit">Send reset link</button>
        </div>
      </form>
      <?php else: ?>
        <div class="form__actions" style="margin-top:0"><a class="btn btn--primary" href="login.php">Back to login</a></div>
      <?php endif; ?>
<?php
auth_close();