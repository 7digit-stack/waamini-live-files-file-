<?php
/**
 * WAAMINI - Login Page
 * Hardened against Clickjacking, XSS, and Concurrency DoS
 */

// Load global security (Headers, Sessions, Error Suppression)
require_once __DIR__ . '/config/bootstrap.php';

// Load legacy dependencies (db.php creates $conn using mysqli)
include 'includes/security.php';
include 'db.php';
$disablePublicPageCache = true;

$error = "";
$rateLimited = false;
$rateKey = 'login:' . get_client_ip();

// Google OAuth error codes
$oauthErrors = [
    'oauth_config'           => 'Google login is not configured. Please contact support.',
    'oauth_state'            => 'Security check failed. Please try again.',
    'oauth_denied'           => 'Google sign-in was cancelled.',
    'oauth_no_code'          => 'No authorization code received from Google.',
    'oauth_token_fail'       => 'Could not exchange token with Google. Please try again.',
    'oauth_userinfo_fail'    => 'Could not fetch your Google profile. Please try again.',
    'oauth_no_email'         => 'Your Google account has no verified email address.',
    'oauth_unverified_email' => 'Your Google email is not verified.',
    'oauth_db_error'         => 'A database error occurred. Please try again.',
    'oauth_register_fail'    => 'Could not create your account. Please try again.',
    'oauth_user_not_found'   => 'Account not found. Please try again.',
    'oauth_account_disabled' => 'Your account has been disabled. Contact support.',
];
if (isset($_GET['error']) && isset($oauthErrors[$_GET['error']])) {
    $error = $oauthErrors[$_GET['error']];
}

if (isset($_POST['email'])) {

  // CSRF Check
  csrf_verify();

  // Rate Limiting: 5 attempts per 5 minutes
  $rateCheck = rate_limit($rateKey, 5, 300);
  if (!$rateCheck['allowed']) {
    $error = "Too many failed attempts. Please wait.";
    $rateLimited = true;
  }
  else {
    $email = sanitize_email($_POST['email']);
    $password = $_POST['password'] ?? '';

    if (!validate_email($email)) {
      $error = "Invalid email format!";
      rate_limit_hit($rateKey);
    }
    else {
      // Check IP lockout before any processing
      ip_check_lockout();

      $stmt = $conn->prepare("SELECT id, name, email, password, company_id, role, status, two_factor_enabled, two_factor_secret FROM users WHERE email=?");
      if (!$stmt) {
        error_log('[WAAMINI Login] prepare failed: ' . $conn->error);
        $error = "System temporarily unavailable. Please try again.";
      } else {
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, (string)($user['password'] ?? ''))) {

          if (($user['status'] ?? 'active') === 'disabled') {
            $error = "Invalid email or password.";
            rate_limit_hit($rateKey);
            ip_log_failed_attempt();
          }
          else {
            // --- 2FA Check ---
            $has2FA = isset($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1;

            if ($has2FA) {
              $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
              $_SESSION['login_remember_me'] = !empty($_POST['remember']);
              header("Location: verify_2fa.php");
              exit();
            }

            // Success — clear rate limit and IP lockout
            rate_limit_clear($rateKey);
            ip_clear_failed_attempts();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = (string)($user['name'] ?? '');
            $_SESSION['company_id'] = (int)($user['company_id'] ?? 0);
            $_SESSION['role'] = (string)($user['role'] ?? 'client');
            waamini_refresh_session_cookie(
                !empty($_POST['remember']) ? waamini_remember_me_lifetime_seconds() : waamini_default_session_lifetime_seconds()
            );

            // Generate persistent remember me token
            if (!empty($_POST['remember'])) {
                $token = bin2hex(random_bytes(32));
                $selector = bin2hex(random_bytes(16));
                $hashedToken = hash('sha256', $token);
                $expires = date('Y-m-d H:i:s', time() + waamini_remember_me_lifetime_seconds());
                
                $stmtToken = $conn->prepare("INSERT INTO user_tokens (user_id, selector, token, expires_at) VALUES (?, ?, ?, ?)");
                if ($stmtToken) {
                    $stmtToken->bind_param("isss", $user['id'], $selector, $hashedToken, $expires);
                    $stmtToken->execute();
                }
                
                setcookie(
                    'remember_me',
                    $selector . ':' . $token,
                    time() + waamini_remember_me_lifetime_seconds(),
                    '/',
                    '',
                    Security::isHttps(),
                    true
                );
            }

            // Save persistent email for pre-filling
            if (!empty($_POST['remember'])) {
                setcookie('remember_user', $email, time() + 2592000, '/', '', Security::isHttps(), true);
            } else {
                setcookie('remember_user', '', time() - 3600, '/', '', Security::isHttps(), true);
            }

            if ($user['role'] === 'super_admin') {
              header("Location: admin/dashboard.php");
            }
            else {
              header("Location: dashboard.php");
            }
            exit();
          }
        }
        else {
          $error = "Invalid email or password.";
          rate_limit_hit($rateKey);
          ip_log_failed_attempt();
        }
      }
      else {
        $error = "Invalid email or password.";
        rate_limit_hit($rateKey);
        ip_log_failed_attempt();
      }
      $stmt->close();
      }
      
      // SECURITY FIX: Removed sleep() to prevent thread-blocking DoS attacks.
      // Your IP lockout & rate limiter already adequately block brute force.
    }
  }
}

$rateInfo = rate_limit($rateKey, 5, 300);
require_once __DIR__ . '/includes/auth_layout.php';
auth_open('Log in', [['Home', 'index.php'], ['Join', 'register.php']]);
?>
      <div class="auth__intro">
        <p class="eyebrow">Welcome back</p>
        <h1>Log in<span>.</span></h1>
        <p class="lead">New to WAAMINI? <a class="link" href="register.php">Create an account</a></p>
      </div>

      <?php auth_alert((string)$error, 'error'); ?>

      <?php if (!$rateLimited && $rateInfo['allowed']): ?>
      <form class="form" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <?php auth_field('email', 'email', 'email', 'Email', 'you@company.com', 'mail', ['autocomplete' => 'email', 'required' => 1, 'value' => (string)($_POST['email'] ?? $_COOKIE['remember_user'] ?? '')]); ?>
        <?php auth_field('password', 'password', 'password', 'Password', 'Your password', 'lock', ['autocomplete' => 'current-password', 'required' => 1]); ?>
        <div class="form__meta">
          <label class="check"><input type="checkbox" name="remember" value="1" <?php echo !empty($_POST['remember']) ? 'checked' : ''; ?>> Remember me</label>
          <a class="link" href="forgot-password.php">Forgot password?</a>
        </div>
        <div class="form__actions">
          <a class="btn btn--ghost" href="register.php">Create account</a>
          <button class="btn btn--primary" type="submit">Log in</button>
        </div>
        <?php auth_google_button('Continue with Google'); ?>
      </form>
      <?php else: ?>
        <div class="lock">
          <h2>Account locked</h2>
          <p style="margin:0">Too many failed attempts. Try again in <b><?php echo (int)ceil((int)($rateInfo['retry_after'] ?? 60) / 60); ?> minute(s)</b>.</p>
        </div>
      <?php endif; ?>
<?php
auth_close();