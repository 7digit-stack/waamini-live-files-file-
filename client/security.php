<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_2fa')) {
    header("Location: dashboard.php");
    exit();
}

require_once __DIR__ . '/../includes/GoogleAuthenticator.php';

$userId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

$ga = new GoogleAuthenticator();

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $user = null;
    $stP = $conn->prepare("SELECT password FROM users WHERE id = ?");
    if ($stP) {
        $stP->bind_param("i", $userId);
        $stP->execute();
        $user = $stP->get_result()->fetch_assoc();
        $stP->close();
    }
    if ($user && password_verify($current, (string)$user['password'])) {
        if ($new === $confirm && strlen($new) >= 8) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stU = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stU && $stU->bind_param("si", $hashed, $userId) && $stU->execute()) {
                $success = "Password updated successfully!";
            } else {
                $error = "Could not update your password. Please try again.";
            }
        } else {
            $error = "New passwords do not match or are too short (min 8 chars).";
        }
    } else {
        $error = "Current password is incorrect.";
    }
}

// Handle 2FA Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_2fa'])) {
    csrf_verify();
    $enabled = isset($_POST['enable_2fa']) ? 1 : 0;
    if ($enabled) {
        $secret = $ga->createSecret();
        $st2 = $conn->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?");
        if ($st2 && $st2->bind_param("si", $secret, $userId) && $st2->execute()) {
            $success = "2FA enabled! Scan the QR code below with your Authenticator app.";
        } else {
            $error = "Could not enable two-factor authentication. Please try again.";
        }
    } else {
        $st2 = $conn->prepare("UPDATE users SET two_factor_enabled = 0 WHERE id = ?");
        if ($st2 && $st2->bind_param("i", $userId) && $st2->execute()) {
            $success = "2FA disabled.";
        } else {
            $error = "Could not disable two-factor authentication. Please try again.";
        }
    }
}

$userData = [];
$stD = $conn->prepare("SELECT * FROM users WHERE id = ?");
if ($stD) {
    $stD->bind_param("i", $userId);
    $stD->execute();
    $userData = $stD->get_result()->fetch_assoc() ?: [];
    $stD->close();
}
$twoFa = !empty($userData['two_factor_enabled']);
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

$pageTitle = 'Security Center';
$currentPage = 'security';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=1'); ?>">
<style>
.sc-qr { text-align:center; background:var(--soft); border-radius:16px; padding:22px; }
.sc-qr img { width:180px; max-width:100%; border-radius:14px; background:#fff; padding:8px; }
.sc-qr p { margin:0 0 14px; font-size:14px; color:var(--tx); }
.sc-secret { display:inline-flex; align-items:center; gap:8px; margin-top:12px; padding:9px 16px; background:#fff; border-radius:12px; font-size:13px; color:var(--tx); }
.sc-secret code { font-family:ui-monospace,Consolas,monospace; color:var(--gd); font-weight:800; font-size:14px; background:transparent; word-break:break-all; }
.sc-dev { display:grid; grid-template-columns:44px minmax(0,1fr) auto; gap:14px; align-items:center; background:var(--soft); border-radius:16px; padding:16px; }
.sc-dev .di { width:44px; height:44px; border-radius:12px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; }
.sc-dev strong { display:block; font-size:15px; color:var(--ink); }
.sc-dev small { display:block; font-size:12.5px; color:var(--mu); line-height:1.45; word-break:break-word; margin-top:3px; }
.sc-dev .ip { font-weight:800; color:var(--gd); }
.sc-status { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; background:var(--soft); border-radius:16px; padding:16px 18px; }
.sc-status strong { display:block; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--mu); margin-bottom:6px; }
</style>

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>', 25); ?></div>
        <div>
            <h1>Security Center</h1>
            <p>Manage your password, two-factor authentication and the devices signed in to your account.</p>
        </div>
    </div>
    <span class="su-pill <?php echo $twoFa ? 'on' : 'bad'; ?>"><i></i> <?php echo $twoFa ? 'Two-factor is on' : 'Two-factor is off'; ?></span>
</div>

<div class="su-kpis k3">
    <div class="su-kpi <?php echo $twoFa ? '' : 'red'; ?>"><div class="su-kpi-i"><?php echo $lI('<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>'); ?></div><div><b><?php echo $twoFa ? 'On' : 'Off'; ?></b><span>Two-factor authentication</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>'); ?></div><div><b>1</b><span>Active session</span></div></div>
    <div class="su-kpi amber"><div class="su-kpi-i"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'); ?></div><div><b>8+</b><span>Characters needed in a password</span></div></div>
</div>

<?php if ($success): ?><div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="su-alert err" style="margin-bottom:18px"><?php echo $lI('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="su-grid even">

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'); ?></span> Change password</h2>
            <p class="sub">Use a strong password you don't use anywhere else.</p>
            <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="change_password" value="1">
                <div class="su-f"><label class="l" for="sec_current">Current password</label><input type="password" name="current_password" id="sec_current" required autocomplete="current-password"></div>
                <div class="su-f"><label class="l" for="sec_new">New password</label><input type="password" name="new_password" id="sec_new" required autocomplete="new-password" minlength="8"><span class="hint">At least 8 characters.</span></div>
                <div class="su-f"><label class="l" for="sec_confirm">Confirm new password</label><input type="password" name="confirm_password" id="sec_confirm" required autocomplete="new-password"></div>
                <button type="submit" class="su-btn" style="align-self:flex-start"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'); ?> Update password</button>
            </form>
        </section>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>'); ?></span> Password tips</h2>
            <ul class="su-list">
                <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> At least 8 characters long</li>
                <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> A mix of uppercase and lowercase letters</li>
                <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> At least one number or symbol</li>
                <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> Don't reuse your current password</li>
            </ul>
        </section>
    </div>

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>'); ?></span> Two-factor authentication</h2>
            <p class="sub">Add an extra layer of security with Google Authenticator or any similar app.</p>

            <div class="sc-status">
                <div>
                    <strong>Status</strong>
                    <span class="su-pill <?php echo $twoFa ? 'on' : 'bad'; ?>"><i></i> <?php echo $twoFa ? 'Active' : 'Inactive'; ?></span>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="toggle_2fa" value="1">
                    <?php if ($twoFa): ?>
                        <button type="submit" name="disable_2fa" class="su-btn danger" onclick="return confirm('Turn off two-factor authentication?')"><?php echo $lI('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?> Disable 2FA</button>
                    <?php else: ?>
                        <input type="hidden" name="enable_2fa" value="1">
                        <button type="submit" class="su-btn"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>'); ?> Enable 2FA</button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($twoFa && !empty($userData['two_factor_secret'])): ?>
            <div class="sc-qr">
                <p>Scan this QR code with <strong>Google Authenticator</strong> or any TOTP app:</p>
                <img src="<?php echo htmlspecialchars($ga->getQRCodeGoogleUrl((string)($userData['email'] ?? ''), (string)$userData['two_factor_secret'], 'Waamini'), ENT_QUOTES); ?>" alt="2FA QR code">
                <div><span class="sc-secret">Can't scan? Secret key: <code><?php echo htmlspecialchars((string)$userData['two_factor_secret']); ?></code></span></div>
            </div>
            <?php endif; ?>
        </section>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>'); ?></span> Active sessions</h2>
            <p class="sub">Devices that are signed in to your account right now.</p>
            <div class="sc-dev">
                <div class="di"><?php echo $lI('<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>'); ?></div>
                <div style="min-width:0">
                    <strong>Current device</strong>
                    <small><?php echo htmlspecialchars($ua); ?></small>
                    <small class="ip">IP: <?php echo htmlspecialchars($ip); ?></small>
                </div>
                <span class="su-pill on"><i></i> Online</span>
            </div>
            <div class="su-note">
                <?php echo $lI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>', 22); ?>
                <div>
                    <h4>Security tip</h4>
                    <p>If you see a device you don't recognize, change your password right away and turn on two-factor authentication.</p>
                </div>
            </div>
        </section>
    </div>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
