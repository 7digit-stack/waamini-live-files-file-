<?php
require_once __DIR__ . '/config/bootstrap.php';
include 'includes/security.php';
include 'db.php';
$disablePublicPageCache = true;

$message = "";

// Google OAuth error codes (redirected back here from callback)
$oauthErrors = [
    'oauth_config'           => 'Google sign-up is not configured. Please use the form below.',
    'oauth_state'            => 'Security check failed. Please try again.',
    'oauth_denied'           => 'Google sign-in was cancelled.',
    'oauth_no_code'          => 'No authorization code received from Google.',
    'oauth_token_fail'       => 'Could not verify with Google. Please try again.',
    'oauth_userinfo_fail'    => 'Could not fetch your Google profile. Please try again.',
    'oauth_no_email'         => 'Your Google account has no verified email address.',
    'oauth_unverified_email' => 'Your Google email is not verified.',
    'oauth_db_error'         => 'A database error occurred. Please try again.',
    'oauth_register_fail'    => 'Could not create your account via Google. Please use the form below.',
    'oauth_account_disabled' => 'Your account has been disabled. Contact support.',
];
if (isset($_GET['error']) && isset($oauthErrors[$_GET['error']])) {
    $message = $oauthErrors[$_GET['error']];
}

if (isset($_POST['register'])) {
  if (!csrf_verify()) {
    $message = "Invalid security token. Please refresh and try again.";
  } else {

    $company = sanitize($_POST['company'] ?? '');
    $fname = sanitize($_POST['first_name'] ?? '');
    $lname = sanitize($_POST['last_name'] ?? '');
    $mobile = sanitize_phone($_POST['mobile'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($company) || empty($fname) || empty($lname) || empty($mobile) || empty($email)) {
      $message = "All fields are required!";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $message = "Invalid email format!";
    }
    elseif (!validate_phone($mobile)) {
      $message = "Mobile number must be in international format (e.g. +919876543210).";
    }
    elseif (strlen($password) < 8) {
      $message = "Password must be at least 8 characters!";
    }
    elseif ($password !== $confirm) {
      $message = "Passwords do not match!";
    }
    else {

      $check = $conn->prepare("SELECT id FROM users WHERE email=?");
      if (!$check) {
        error_log('[WAAMINI Register] users prepare failed: ' . $conn->error);
        $message = "System temporarily unavailable. Please try again.";
      } else {
        $check->bind_param("s", $email);
        $check->execute();
        $res = $check->get_result();

      if ($res && $res->num_rows > 0) {
        $message = "Email already registered!";
      }
      else {
        $conn->begin_transaction();
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $fullName = trim($fname . ' ' . $lname);

            // 1. Create the company record first
            $compStmt = $conn->prepare(
                "INSERT INTO companies (name, email, phone, status, created_at) VALUES (?, ?, ?, 'active', NOW())"
            );
            if (!$compStmt) {
                throw new \RuntimeException('companies prepare failed');
            }
            $compStmt->bind_param("sss", $company, $email, $mobile);
            $compStmt->execute();
            $newCompanyId = $conn->insert_id;

            // 2. Create the owner user linked to the company
            $userStmt = $conn->prepare(
                "INSERT INTO users (company_id, company, name, email, password, mobile, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'admin', 'active', NOW())"
            );
            if (!$userStmt) {
                throw new \RuntimeException('users insert prepare failed');
            }
            $userStmt->bind_param("isssss", $newCompanyId, $company, $fullName, $email, $hashedPassword, $mobile);
            $userStmt->execute();
            $newUserId = $conn->insert_id;

            /* 
            // 3. Provision a free trial subscription (14 days)
            $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));
            $planStmt = $conn->prepare(
                "SELECT id FROM subscription_plans WHERE name LIKE '%trial%' OR name LIKE '%free%' OR price=0 ORDER BY id ASC LIMIT 1"
            );
            if ($planStmt) {
                $planStmt->execute();
                $planRow = $planStmt->get_result()->fetch_assoc();
                if ($planRow) {
                    $subStmt = $conn->prepare(
                        "INSERT INTO subscriptions (company_id, plan_id, status, starts_at, expires_at, created_at) VALUES (?, ?, 'trial', NOW(), ?, NOW())"
                    );
                    if ($subStmt) {
                        $subStmt->bind_param("iis", $newCompanyId, $planRow['id'], $trialEnds);
                        $subStmt->execute();
                    } else {
                        error_log('[WAAMINI Register] subscriptions prepare failed: ' . $conn->error);
                    }
                }
            } else {
                // Some installs may not have subscription tables yet; allow registration to succeed.
                error_log('[WAAMINI Register] subscription_plans prepare failed: ' . $conn->error);
            }
            */

            $conn->commit();
            $message = "success";

            // Log registration to audit_log for admin visibility
            $regIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $regDesc = 'New account registered via Email: ' . $email . ' (Company: ' . $company . ')';
            $auditStmt = $conn->prepare("INSERT INTO audit_log (user_id, action, description, ip_address, created_at) VALUES (?, 'email_register', ?, ?, NOW())");
            if ($auditStmt) {
                $auditStmt->bind_param("iss", $newUserId, $regDesc, $regIp);
                $auditStmt->execute();
                $auditStmt->close();
            }
            // Clear admin notification cache so bell updates immediately
            unset($_SESSION['_admin_notif_users'], $_SESSION['_admin_notif_ts']);

        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('[WAAMINI Register] ' . $e->getMessage());
            $message = "Registration failed. Please try again.";
        }
      }
      }
    }
  }
}

require_once __DIR__ . '/includes/auth_layout.php';
auth_open('Create account', [['Home', 'index.php'], ['Log in', 'login.php']]);
?>
      <div class="auth__intro">
        <p class="eyebrow">Start for free</p>
        <h1>Create new account<span>.</span></h1>
        <p class="lead">Already a member? <a class="link" href="login.php">Log in</a></p>
      </div>

      <?php if ($message === 'success'): ?>
        <?php auth_alert('Account created! <a class="link" href="login.php">Log in now to continue &rarr;</a>', 'success', true); ?>
      <?php else: ?>
        <?php auth_alert((string)$message, 'error'); ?>
      <form class="form" method="POST" autocomplete="off" id="regForm">
        <?php echo csrf_field(); ?>
        <?php auth_field('company', 'company', 'text', 'Company name', 'Your business name', 'build', ['required' => 1, 'autocomplete' => 'organization', 'value' => (string)($_POST['company'] ?? '')]); ?>
        <div class="form__row">
          <?php auth_field('first_name', 'first_name', 'text', 'First name', 'Arun', 'user', ['required' => 1, 'autocomplete' => 'given-name', 'value' => (string)($_POST['first_name'] ?? '')]); ?>
          <?php auth_field('last_name', 'last_name', 'text', 'Last name', 'Kumar', 'user', ['required' => 1, 'autocomplete' => 'family-name', 'value' => (string)($_POST['last_name'] ?? '')]); ?>
        </div>
        <?php auth_field('mobile', 'mobile', 'text', 'Mobile number', '+91 98765 43210', 'phone', ['required' => 1, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'value' => (string)($_POST['mobile'] ?? '')]); ?>
        <?php auth_field('email', 'email', 'email', 'Email', 'you@company.com', 'mail', ['required' => 1, 'autocomplete' => 'email', 'value' => (string)($_POST['email'] ?? '')]); ?>
        <div class="form__row">
          <?php auth_field('reg_pass', 'password', 'password', 'Password', 'At least 8 characters', 'lock', ['required' => 1, 'minlength' => 8, 'autocomplete' => 'new-password']); ?>
          <?php auth_field('reg_confirm', 'confirm_password', 'password', 'Confirm password', 'Repeat password', 'lock', ['required' => 1, 'minlength' => 8, 'autocomplete' => 'new-password']); ?>
        </div>

        <div class="guide" id="pwGuide">
          <b>Choose a strong password</b>
          <div class="rule" data-r="len">At least 8 characters</div>
          <div class="rule" data-r="mix">A mix of letters and numbers</div>
          <div class="rule" data-r="match">Both passwords match</div>
          <div class="bar"><i id="pwBar"></i></div>
          <small id="pwLabel">Example: Waamini2026</small>
        </div>

        <div class="form__actions">
          <a class="btn btn--ghost" href="login.php">Log in instead</a>
          <button class="btn btn--primary" type="submit" name="register">Create account</button>
        </div>
        <?php auth_google_button('Sign up with Google'); ?>
      </form>
      <?php endif; ?>
<?php
auth_close(<<<'JS'
(function () {
  var p = document.getElementById('reg_pass'), c = document.getElementById('reg_confirm');
  if (!p) { return; }
  var bar = document.getElementById('pwBar'), lab = document.getElementById('pwLabel');
  function set(r, ok) { document.querySelector('.rule[data-r="' + r + '"]').classList.toggle('ok', ok); }
  function check() {
    var v = p.value, len = v.length >= 8, mix = /[A-Za-z]/.test(v) && /\d/.test(v), match = v !== '' && v === c.value;
    set('len', len); set('mix', mix); set('match', match);
    var score = v ? (len ? 1 : 0) + (mix ? 1 : 0) + (v.length >= 12 || /[^A-Za-z0-9]/.test(v) ? 1 : 0) : 0;
    bar.style.width = (score * 33.4) + '%';
    bar.style.background = ['#ef4444', '#f59e0b', '#84cc16', '#11887A'][score];
    lab.textContent = v ? ['Too weak', 'Weak', 'Good', 'Strong'][score] : 'Example: Waamini2026';
    return len && match;
  }
  p.addEventListener('input', check); c.addEventListener('input', check);
  p.form.addEventListener('submit', function (e) { if (!check()) { e.preventDefault(); p.focus(); } });
})();
JS);