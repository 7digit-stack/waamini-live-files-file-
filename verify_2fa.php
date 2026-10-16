<?php
/**
 * Second step after login when 2FA is enabled.
 * Must load bootstrap first so session cookie params match login.php.
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php';

$error = '';
$rateLimited = false;
$rateKey2fa = 'verify_2fa:' . get_client_ip();
$rateKeyLogin = 'login:' . get_client_ip();

// If they shouldn't be here, redirect to login
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header('Location: login.php');
    exit();
}

if (isset($_POST['auth_code'])) {
    csrf_verify();

    $rateCheck = rate_limit($rateKey2fa, 5, 300);
    if (!$rateCheck['allowed']) {
        $retrySec = (int)($rateCheck['retry_after'] ?? 0);
        $mins = max(1, (int)ceil($retrySec / 60));
        $error = '🔒 Too many attempts. Please wait about ' . $mins . ' minute(s) and try again.';
        $rateLimited = true;
    }
    else {
        $user_id = intval($_SESSION['pending_2fa_user_id']);
        $stmt = $conn->prepare('SELECT id, name, email, company_id, role, status, two_factor_enabled, two_factor_secret FROM users WHERE id=?');
        if (!$stmt) {
            error_log('[verify_2fa] prepare failed: ' . $conn->error);
            $error = 'System temporarily unavailable. Please try again.';
        }
        else {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && (int)($user['two_factor_enabled'] ?? 0) === 1 && ($user['status'] ?? 'active') !== 'disabled') {
                $ga = new GoogleAuthenticator();
                $code = preg_replace('/[^0-9]/', '', (string)($_POST['auth_code'] ?? ''));
                $secret = (string)($user['two_factor_secret'] ?? '');

                if (strlen($code) !== 6 || $secret === '') {
                    $error = 'Enter the 6-digit code from your authenticator app.';
                    rate_limit_hit($rateKey2fa);
                }
                elseif ($ga->verifyCode($secret, $code, 2)) {
                    rate_limit_clear($rateKey2fa);
                    rate_limit_clear($rateKeyLogin);
                    $rememberAfter2fa = !empty($_SESSION['login_remember_me']);
                    unset($_SESSION['login_remember_me']);

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = (string)($user['name'] ?? '');
                    $_SESSION['company_id'] = (int)($user['company_id'] ?? 0);
                    $_SESSION['role'] = (string)($user['role'] ?? 'client');

                    unset($_SESSION['pending_2fa_user_id']);

                    waamini_refresh_session_cookie(
                        $rememberAfter2fa ? waamini_remember_me_lifetime_seconds() : waamini_default_session_lifetime_seconds()
                    );

                    // Generate persistent remember me token
                    if ($rememberAfter2fa) {
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

                    if ($user['role'] === 'super_admin') {
                        header('Location: admin/dashboard.php');
                    }
                    else {
                        header('Location: dashboard.php');
                    }
                    exit();
                }
                else {
                    $error = 'Invalid Authenticator Code.';
                    rate_limit_hit($rateKey2fa);
                }
            }
            else {
                unset($_SESSION['pending_2fa_user_id']);
                header('Location: login.php');
                exit();
            }
        }
    }
}

$rateInfo = rate_limit($rateKey2fa, 5, 300);
$skipMarketingCSS = true;
include 'includes/header.php';
?>

<div class="login-wrapper">
  <div class="login-card" style="text-align:center;">
    <div style="font-size:40px;margin-bottom:16px;">📱</div>
    <h4 style="margin-bottom:8px;">Two-Factor Authentication</h4>
    <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;">Open your authenticator app and enter the 6-digit code to continue securely.</p>

    <?php if ($error !== ''): ?>
        <p style="color:<?php echo $rateLimited ? '#ff9800' : 'red'; ?>;font-size:14px;margin-bottom:16px;">
          <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <?php if (!$rateLimited && $rateInfo['allowed']): ?>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="text" name="auth_code" placeholder="123456" required maxlength="6" autofocus inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9]{6}"
               style="letter-spacing:4px;font-size:24px;font-family:monospace;text-align:center;margin-bottom:16px;">

        <button type="submit">Verify & Login</button>

        <?php if (isset($rateInfo['remaining']) && $rateInfo['remaining'] <= 2): ?>
            <p style="color:#ff9800;font-size:12px;margin-top:8px;text-align:center;">
                ⚠️ <?php echo (int)$rateInfo['remaining']; ?> attempt(s) remaining
            </p>
        <?php endif; ?>
    </form>

    <div style="margin-top:24px;font-size:13px;">
        <a href="login.php" style="color:var(--text-muted);text-decoration:none;">Cancel and return to login</a>
    </div>

    <?php else: ?>
        <div style="padding:20px;color:#ff9800;">
            <p style="font-size:32px;margin-bottom:10px;">🔒</p>
            <p>Verification temporarily locked due to too many failed attempts.</p>
            <p style="font-size:13px;">Try again in <strong><?php
                $ra = (int)($rateInfo['retry_after'] ?? 60);
                echo $ra > 0 ? (int)ceil($ra / 60) : 1;
            ?> minute(s)</strong></p>
        </div>
        <div style="margin-top:16px;font-size:13px;">
            <a href="login.php" style="color:var(--text-muted);text-decoration:none;">Return to login page</a>
        </div>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>

