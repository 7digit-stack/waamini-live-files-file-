<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
include_once '../includes/GoogleAuthenticator.php';

$pageTitle = 'My Profile & Security';
$currentPage = 'profile';

// Database Updates (Ensure required tables and columns exist for 2FA just in case)
$userCols = [
    "ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(255) NULL",
    "ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0"
];
foreach ($userCols as $sql) {
    try {
        $conn->query($sql);
    }
    catch (Exception $e) {
    }
}

$user_id = intval($_SESSION['user_id']);
$success = '';
$error = '';

// Fetch current user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND company_id = ?");
if (!$stmt) {
    error_log('[profile.php] prepare user load failed: ' . $conn->error);
    header('Location: /client/dashboard.php');
    exit;
}
$stmt->bind_param("ii", $user_id, $companyId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: /client/dashboard.php');
    exit;
}

$ga = new GoogleAuthenticator();

// Handle 2FA Setup
if (isset($_POST['setup_2fa'])) {
    csrf_verify();
    $secret = $ga->createSecret();
    $stmt = $conn->prepare("UPDATE users SET two_factor_secret = ? WHERE id = ? AND company_id = ?");
    if ($stmt) {
        $stmt->bind_param("sii", $secret, $user_id, $companyId);
        if ($stmt->execute()) {
            $user['two_factor_secret'] = $secret;
            $success = "2FA setup initiated. Scan the QR code below and verify to enable it.";
        }
        else {
            $error = "Could not start 2FA setup. Please try again.";
        }
        $stmt->close();
    }
    else {
        $error = "Could not start 2FA setup. Please try again.";
    }
}

// Handle 2FA Verification and Enabling
if (isset($_POST['verify_enable_2fa'])) {
    csrf_verify();
    $code = preg_replace('/[^0-9]/', '', (string)($_POST['auth_code'] ?? ''));
    $secret = (string)($user['two_factor_secret'] ?? '');

    if (strlen($code) !== 6 || $secret === '') {
        $error = "Enter the 6-digit code from your authenticator app.";
    }
    elseif ($ga->verifyCode($secret, $code, 2)) {
        $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = 1 WHERE id = ? AND company_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $user_id, $companyId);
            if ($stmt->execute()) {
                $user['two_factor_enabled'] = 1;
                $success = "Two-Factor Authentication has been successfully enabled!";
            }
            else {
                $error = "Could not enable 2FA. Please try again.";
            }
            $stmt->close();
        }
        else {
            $error = "Could not enable 2FA. Please try again.";
        }
    }
    else {
        $error = "Invalid code. Please try again.";
    }
}

// Handle 2FA Disabling
if (isset($_POST['disable_2fa'])) {
    csrf_verify();
    $code = preg_replace('/[^0-9]/', '', (string)($_POST['auth_code'] ?? ''));
    $secret = (string)($user['two_factor_secret'] ?? '');

    if (strlen($code) !== 6 || $secret === '') {
        $error = "Enter the 6-digit code from your authenticator app to disable 2FA.";
    }
    elseif ($ga->verifyCode($secret, $code, 2)) {
        $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ? AND company_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $user_id, $companyId);
            if ($stmt->execute()) {
                $user['two_factor_enabled'] = 0;
                $user['two_factor_secret'] = null;
                $success = "Two-Factor Authentication has been successfully disabled.";
            }
            else {
                $error = "Could not disable 2FA. Please try again.";
            }
            $stmt->close();
        }
        else {
            $error = "Could not disable 2FA. Please try again.";
        }
    }
    else {
        $error = "Invalid code. 2FA was not disabled.";
    }
}

// Handle Profile Update (Name, Email, Password)
if (isset($_POST['update_profile'])) {
    csrf_verify();

    $name = preg_replace('/\s+/u', ' ', trim(strip_tags((string)($_POST['name'] ?? ''))));
    $name = function_exists('mb_substr')
        ? mb_substr($name, 0, 190, 'UTF-8')
        : substr($name, 0, 190);
    $email = sanitize_email((string)($_POST['email'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
    $password = (string)($_POST['new_password'] ?? '');
    $minPwdLen = 8;

    if ($name === '' || $email === '') {
        $error = "Name and Email cannot be empty.";
    }
    elseif (!validate_email($email)) {
        $error = "Invalid email format.";
    }
    elseif ($password !== '' && strlen($password) < $minPwdLen) {
        $error = "New password must be at least {$minPwdLen} characters.";
    }
    else {
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if (!$chk) {
            $error = "Could not validate email. Please try again.";
        }
        else {
            $chk->bind_param("si", $email, $user_id);
            $chk->execute();
            $dup = $chk->get_result();
            $inUse = $dup && $dup->num_rows > 0;
            $chk->close();

            if ($inUse) {
                $error = "This email address is already in use.";
            }
            elseif ($password !== '') {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, mobile = ?, password = ? WHERE id = ? AND company_id = ?");
                if ($stmt) {
                    $stmt->bind_param("ssssii", $name, $email, $mobile, $hashed_password, $user_id, $companyId);
                    if ($stmt->execute()) {
                        $success = "Profile updated successfully!";
                        $_SESSION['user_name'] = $name;
                        $user['name'] = $name;
                        $user['email'] = $email;
                        $user['mobile'] = $mobile;
                    }
                    else {
                        $error = "Error updating profile.";
                    }
                    $stmt->close();
                }
                else {
                    $error = "Error updating profile.";
                }
            }
            else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, mobile = ? WHERE id = ? AND company_id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssii", $name, $email, $mobile, $user_id, $companyId);
                    if ($stmt->execute()) {
                        $success = "Profile updated successfully!";
                        $_SESSION['user_name'] = $name;
                        $user['name'] = $name;
                        $user['email'] = $email;
                        $user['mobile'] = $mobile;
                    }
                    else {
                        $error = "Error updating profile.";
                    }
                    $stmt->close();
                }
                else {
                    $error = "Error updating profile.";
                }
            }
        }
    }
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Security & Profile</h2>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars((string)$success, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="dashboard-grid">
    
    <!-- Profile Information -->
    <div class="form-card" style="max-width:none;margin-bottom:24px;">
        <h3 style="margin-bottom:16px;">Profile Details</h3>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px;">Update your username (email) and reset your password if needed. Leave the password field blank to keep your current password.</p>
        
        <form method="POST" style="max-width:400px;">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Email Address (Username)</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="form-group">
                <label>Mobile Number (For WhatsApp Commands)</label>
                <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>" placeholder="919876543210">
                <small style="color:var(--text-muted);">Include country code. This number is used to authorize WhatsApp sales commands.</small>
            </div>
            
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" placeholder="Optional. Enter new password to change">
            </div>
            
            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
        </form>
    </div>

    <!-- Two-Factor Authentication -->
    <div class="form-card" style="max-width:none;">
        <h3 style="margin-bottom:16px;">Two-Factor Authentication (2FA)</h3>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px;">Add an extra layer of security to your account by requiring a code from an authenticator app (like Google Authenticator or Authy) when you log in.</p>
        
        <?php if ((int)($user['two_factor_enabled'] ?? 0) === 1): ?>
            <div style="background:rgba(0,184,148,0.1);border:1px solid rgba(0,184,148,0.2);padding:16px;border-radius:12px;margin-bottom:24px;">
                <div style="color:#00b894;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                    2FA is currently ENABLED
                </div>
                <p style="font-size:13px;color:var(--text-secondary);margin:0;">Your account is highly secure. You will be prompted for a code every time you log in.</p>
            </div>
            
            <form method="POST" style="max-width:300px;">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>To disable, enter a code from your app</label>
                    <input type="text" name="auth_code" class="form-control" placeholder="123456" required maxlength="6" style="letter-spacing:2px;font-size:16px;font-family:monospace;">
                </div>
                <button type="submit" name="disable_2fa" class="btn btn-danger">Disable 2FA</button>
            </form>
            
        <?php
elseif (!empty($user['two_factor_secret'])): ?>
            <!-- Setup in progress -->
            <div style="background:var(--bg-active);border:1px solid var(--border);padding:24px;border-radius:12px;">
                <h4 style="margin-bottom:16px;">Scan QR Code</h4>
                <div style="display:flex;gap:32px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="background:#fff;padding:16px;border-radius:12px;display:inline-block;">
                        <?php
    $qrCodeUrl = $ga->getQRCodeGoogleUrl('WAAMINI Client', $user['two_factor_secret'], 'WAAMINI CRM');
?>
                        <img src="<?php echo htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Authenticator QR Code" width="150" height="150" loading="lazy" referrerpolicy="no-referrer">
                    </div>
                    <div style="flex:1;min-width:250px;">
                        <ol style="color:var(--text-secondary);font-size:14px;line-height:1.8;padding-left:20px;margin-bottom:24px;">
                            <li>Open Google Authenticator or Authy.</li>
                            <li>Tap the "+" button to add a new account.</li>
                            <li>Scan the QR code to the left.</li>
                            <li>If prompted, you can enter this secret manually:<br>
                            <code style="background:rgba(0,0,0,0.1);padding:4px 8px;border-radius:4px;font-size:15px;letter-spacing:1px;color:var(--text-primary);display:inline-block;margin-top:8px;"><?php echo htmlspecialchars((string)($user['two_factor_secret'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
                            </li>
                        </ol>
                        
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <div class="form-group">
                                <label style="font-weight:600;">Enter the 6-digit code from your app to verify</label>
                                <input type="text" name="auth_code" class="form-control" placeholder="123456" required maxlength="6" style="max-width:200px;letter-spacing:2px;font-size:18px;font-family:monospace;text-align:center;">
                            </div>
                            <button type="submit" name="verify_enable_2fa" class="btn btn-primary">Verify & Enable 2FA</button>
                        </form>
                    </div>
                </div>
            </div>
            
        <?php
else: ?>
            <!-- Not set up yet -->
            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="setup_2fa" class="btn btn-primary">Set up Two-Factor Authentication</button>
            </form>
        <?php
endif; ?>
    </div>
</div>

<?php include '../includes/client_footer.php'; ?>
