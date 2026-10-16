<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Edit User';
$currentPage = 'users';

if (!isset($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$id = intval($_GET['id']);
$user = null;
$stmt_u = $conn->prepare("SELECT * FROM users WHERE id=?");
if ($stmt_u) {
    $stmt_u->bind_param("i", $id);
    $stmt_u->execute();
    $user = $stmt_u->get_result()->fetch_assoc();
}

if (!$user) {
    header("Location: users.php");
    exit();
}

$companies = $conn->query("SELECT id, name FROM companies WHERE status='active' ORDER BY name");
$error = '';
$success = '';

// Handle update
if (isset($_POST['update'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $mobile = sanitize_phone($_POST['mobile'] ?? '');
    $companyId = intval($_POST['company_id']);
    $role = in_array($_POST['role'], ['client', 'super_admin']) ? $_POST['role'] : 'client';

    if (empty($name) || empty($email)) {
        $error = "Name and email are required!";
    }
    elseif (!validate_email($email)) {
        $error = "Invalid email format!";
    }
    else {
        // Check duplicate email (excluding current user)
        $check = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $check->bind_param("si", $email, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Email already used by another user!";
        }
        else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, mobile=?, company_id=?, role=? WHERE id=?");
            $stmt->bind_param("sssisi", $name, $email, $mobile, $companyId, $role, $id);
            $stmt->execute();

            // Update Subscription Alerts if company set
            if ($companyId > 0 && isset($_POST['sub_alert_number'])) {
                $subAlertNum = sanitize_phone($_POST['sub_alert_number']);
                $subAlertDays = intval($_POST['sub_alert_days'] ?? 3);
                $subRemEnabled = isset($_POST['sub_reminder_enabled']) ? 1 : 0;
                
                $updS = $conn->prepare("UPDATE subscriptions SET whatsapp_number=?, alert_days_advance=?, reminder_enabled=? WHERE company_id=? AND status IN ('active','trial')");
                if ($updS) {
                    $updS->bind_param("siii", $subAlertNum, $subAlertDays, $subRemEnabled, $companyId);
                    $updS->execute();
                }
            }

            $success = "User updated successfully!";
            // Refresh user data
            $stmt_u2 = $conn->prepare("SELECT * FROM users WHERE id=?");
            if ($stmt_u2) {
                $stmt_u2->bind_param("i", $id);
                $stmt_u2->execute();
                $user = $stmt_u2->get_result()->fetch_assoc();
            }
        }
    }
}

// Handle password reset
if (isset($_POST['reset_password'])) {
    csrf_verify();
    $newPass = $_POST['new_password'];
    if (strlen($newPass) < 6) {
        $error = "Password must be at least 6 characters!";
    }
    else {
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hashed, $id);
        $stmt->execute();
        $success = "Password reset successfully!";
    }
}

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'user'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'bell'   => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
    'key'    => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'dice'   => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M12 12h.01"/><path d="M8 16h.01"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
];
$roleLabels = ['super_admin' => 'Super admin', 'client' => 'Client', 'admin' => 'Vendor owner'];
$roleShown = $roleLabels[(string)$user['role']] ?? (string)$user['role'];
$isDisabled = (($user['status'] ?? 'active') === 'disabled');
$isSuper = ($user['role'] === 'super_admin');

// subscription alert settings (only when the user belongs to a company that has a live plan)
$sub = null;
if ($user['company_id'] > 0) {
    $stS = $conn->prepare("SELECT id, whatsapp_number, reminder_enabled, alert_days_advance FROM subscriptions WHERE company_id=? AND status IN ('active','trial') ORDER BY id DESC LIMIT 1");
    if ($stS) {
        $stS->bind_param("i", $user['company_id']);
        $stS->execute();
        $sub = $stS->get_result()->fetch_assoc();
    }
}
$companyName = '—';
$companyRows = [];
if ($companies) { while ($c = $companies->fetch_assoc()) { $companyRows[] = $c; if ($user['company_id'] == $c['id']) $companyName = $c['name']; } }
?>

<div class="db ue">

  <a class="ue-back" href="users.php"><?php echo $__ic($P['back'], 14); ?> Back to users</a>

  <div class="db-head">
    <div class="ue-title">
      <span class="db-av ue-av <?php echo $isSuper ? 'is-sa' : ''; ?>"><?php echo $esc(strtoupper(mb_substr((string)$user['name'], 0, 1))); ?></span>
      <div>
        <h1>Edit user</h1>
        <p><?php echo $esc($user['name']); ?> · #<?php echo (int)$id; ?>
          <span class="db-pill <?php echo $isSuper ? 'is-warn' : 'is-ok'; ?>" style="margin-left:6px"><?php echo $esc($roleShown); ?></span>
          <span class="db-pill <?php echo $isDisabled ? 'is-bad' : 'is-ok'; ?>"><?php echo $isDisabled ? 'Disabled' : 'Active'; ?></span>
        </p>
      </div>
    </div>
  </div>

  <?php if ($error): ?><div class="ue-alert is-err" role="alert"><?php echo $__ic($P['alert'], 18); ?><div><?php echo $esc($error); ?></div></div><?php endif; ?>
  <?php if ($success): ?><div class="ue-alert is-ok" role="status"><?php echo $__ic($P['check'], 18); ?><div><?php echo $esc($success); ?></div></div><?php endif; ?>

  <div class="ue-layout">

    <!-- ===== main form ===== -->
    <form method="POST" class="ue-main" autocomplete="off">
      <?php echo csrf_field(); ?>

      <section class="db-card">
        <div class="db-card__head"><div class="ue-ch"><span class="ue-ci"><?php echo $__ic($P['user'], 19); ?></span><div><h2>User information</h2><p>Name, contact details, company and role</p></div></div></div>
        <div class="ue-body">
          <div class="ue-grid2">
            <div class="ue-f"><label for="ueName">Full name <i>*</i></label><input type="text" name="name" id="ueName" class="ue-in" value="<?php echo $esc($user['name']); ?>" required></div>
            <div class="ue-f"><label for="ueEmail">Email <i>*</i></label><input type="email" name="email" id="ueEmail" class="ue-in" value="<?php echo $esc($user['email']); ?>" required></div>
            <div class="ue-f"><label for="ueMobile">Mobile</label><input type="text" name="mobile" id="ueMobile" class="ue-in" value="<?php echo $esc($user['mobile'] ?? ''); ?>" placeholder="e.g. 919876543210"></div>
            <div class="ue-f"><label for="ueCompany">Company</label>
              <select name="company_id" id="ueCompany" class="ue-in">
                <option value="0">No company</option>
                <?php foreach ($companyRows as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo $user['company_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo $esc($c['name']); ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="ue-f"><label for="ueRole">Role</label>
              <select name="role" id="ueRole" class="ue-in">
                <option value="client" <?php echo $user['role'] === 'client' ? 'selected' : ''; ?>>Client</option>
                <option value="super_admin" <?php echo $user['role'] === 'super_admin' ? 'selected' : ''; ?>>Super admin</option>
              </select>
              <small>Super admins can access this whole admin panel.</small></div>
          </div>
        </div>
      </section>

      <?php if ($sub): ?>
      <section class="db-card">
        <div class="db-card__head"><div class="ue-ch"><span class="ue-ci is-amber"><?php echo $__ic($P['bell'], 19); ?></span><div><h2>Subscription alerts</h2><p>WhatsApp reminders before this company's plan expires</p></div></div></div>
        <div class="ue-body">
          <div class="ue-grid2">
            <div class="ue-f"><label for="ueAlertNum">Alert WhatsApp number</label><input type="text" name="sub_alert_number" id="ueAlertNum" class="ue-in" value="<?php echo $esc($sub['whatsapp_number'] ?? ''); ?>" placeholder="e.g. 919876543210"><small>Number where expiration alerts will be sent.</small></div>
            <div class="ue-f"><label for="ueAlertDays">Alert days in advance</label><input type="number" name="sub_alert_days" id="ueAlertDays" class="ue-in" value="<?php echo $esc($sub['alert_days_advance'] ?? 3); ?>" min="1" max="30"><small>Send the alert this many days before the plan expires.</small></div>
          </div>
          <label class="ue-switch" for="rem_en">
            <input type="checkbox" name="sub_reminder_enabled" id="rem_en" <?php echo !empty($sub['reminder_enabled']) ? 'checked' : ''; ?>>
            <span class="ue-switch__t"></span>
            <span class="ue-switch__l"><b>Enable expiration reminders</b><em>Send automatic WhatsApp alerts for this subscription</em></span>
          </label>
        </div>
      </section>
      <?php endif; ?>

      <div class="ue-savebar">
        <span><?php echo $__ic($P['shield'], 15); ?> Changes apply immediately after saving.</span>
        <div>
          <a href="users.php" class="db-btn">Cancel</a>
          <button type="submit" name="update" value="1" class="db-btn db-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save changes</button>
        </div>
      </div>
    </form>

    <!-- ===== side ===== -->
    <aside class="ue-side">

      <section class="db-card">
        <div class="db-card__head"><div><h2>Account summary</h2></div></div>
        <div class="db-meter">
          <div class="db-meter__row"><span>User ID</span><b>#<?php echo (int)$id; ?></b></div>
          <div class="db-meter__row"><span>Role</span><b><?php echo $esc($roleShown); ?></b></div>
          <div class="db-meter__row"><span>Status</span><b><?php echo $isDisabled ? 'Disabled' : 'Active'; ?></b></div>
          <div class="db-meter__row"><span>Company</span><b class="ue-trunc"><?php echo $esc($companyName); ?></b></div>
          <div class="db-meter__row"><span>Login method</span><b><?php echo !empty($user['google_id']) ? 'Google' : 'Email & password'; ?></b></div>
          <div class="db-meter__row" style="border-bottom:0"><span>Joined</span><b><?php echo !empty($user['created_at']) ? $esc(date('d M Y', strtotime($user['created_at']))) : '—'; ?></b></div>
        </div>
      </section>

      <section class="db-card">
        <div class="db-card__head"><div class="ue-ch"><span class="ue-ci is-pink"><?php echo $__ic($P['key'], 19); ?></span><div><h2>Reset password</h2><p>Set a new password for this user</p></div></div></div>
        <form method="POST" class="ue-body" autocomplete="off">
          <?php echo csrf_field(); ?>
          <div class="ue-f"><label for="uePass">New password</label>
            <div class="ue-row">
              <input type="password" name="new_password" id="uePass" class="ue-in" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
              <button type="button" class="ue-ico" id="uePassToggle" aria-label="Show or hide password"><?php echo $__ic($P['eye'], 16); ?></button>
            </div>
            <small>At least 6 characters. The user can change it after logging in.</small>
          </div>
          <div class="ue-gen"><button type="button" class="db-btn" id="uePassGen"><?php echo $__ic($P['dice'], 15); ?> Generate</button><code id="uePassShow"></code></div>
          <button type="submit" name="reset_password" value="1" class="db-btn ue-reset"><?php echo $__ic($P['key'], 16); ?> Reset password</button>
        </form>
      </section>
    </aside>
  </div>
</div>

<style>
.ue{max-width:none;margin:0}
.ue-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:14px;color:var(--tx);text-decoration:none}
.ue-back:hover{color:var(--g)}
.ue-title{display:flex;align-items:center;gap:16px}
.ue-av{width:56px;height:56px;border-radius:16px;font-size:22px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff}
.ue-av.is-sa{background:linear-gradient(135deg,#F59E0B,#d98300)}
.ue-title h1{margin:0 0 4px!important}
.ue-title p{display:flex;align-items:center;flex-wrap:wrap;gap:6px}
.ue-alert{display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;padding:13px 16px;border-radius:13px;font-size:14.5px;line-height:1.5;font-weight:600}
.ue-alert .db-ic{margin-top:2px}
.ue-alert.is-ok{background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E}.ue-alert.is-err{background:#FEF0EE;border:1px solid #F8C9C2;color:#B42318}

.ue-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start}
.ue-main{display:flex;flex-direction:column;gap:18px;min-width:0}
.ue-side{display:flex;flex-direction:column;gap:18px;min-width:0;position:sticky;top:calc(var(--topbar-height,64px) + 16px)}
.ue-ch{display:flex;align-items:center;gap:13px}
.ue-ci{width:40px;height:40px;border-radius:11px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex:none}
.ue-ci.is-amber{background:#FEF3DC;color:#b7791f}.ue-ci.is-pink{background:#FDE7F1;color:#d1356f}
.ue-body{padding:18px 20px 22px}
.ue-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
.ue-f label{display:block;margin-bottom:7px;font-size:14px;font-weight:700;color:var(--ink)}
.ue-f label i{color:#e5484d;font-style:normal}
.ue-f small{display:block;margin-top:7px;font-size:12.5px;line-height:1.5;color:var(--mu)}
.ue-in{width:100%;height:46px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font:500 15px Inter,system-ui,sans-serif;transition:border-color .2s,box-shadow .2s}
.ue-in:focus{outline:0;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
select.ue-in{appearance:none;-webkit-appearance:none;padding-right:38px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 13px center}

.ue-switch{display:flex;align-items:center;gap:14px;margin-top:20px;padding:14px 16px;border:1.5px solid var(--line);border-radius:13px;background:var(--soft);cursor:pointer}
.ue-switch input{position:absolute;opacity:0;pointer-events:none}
.ue-switch__t{position:relative;flex:none;width:46px;height:26px;border-radius:999px;background:#cbd5d1;transition:background .2s}
.ue-switch__t::after{content:'';position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.ue-switch input:checked+.ue-switch__t{background:var(--g)}
.ue-switch input:checked+.ue-switch__t::after{transform:translateX(20px)}
.ue-switch input:focus-visible+.ue-switch__t{outline:2px solid var(--g);outline-offset:2px}
.ue-switch__l b{display:block;font-size:14.5px;color:var(--ink)}
.ue-switch__l em{display:block;margin-top:2px;font-style:normal;font-size:12.5px;color:var(--tx)}

.ue-savebar{position:sticky;bottom:12px;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 18px;border-radius:16px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid var(--line);box-shadow:0 18px 40px -16px rgba(18,140,126,.45)}
.ue-savebar>span{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--tx)}
.ue-savebar>div{display:flex;gap:10px}
.ue-trunc{max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.ue-row{display:flex;gap:10px}
.ue-row .ue-in{flex:1;min-width:0}
.ue-ico{width:46px;height:46px;flex:none;border-radius:11px;border:1.5px solid var(--line);background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer;transition:border-color .2s,color .2s}
.ue-ico:hover{border-color:var(--g);color:var(--g)}
.ue-gen{display:flex;align-items:center;gap:12px;margin:14px 0 16px;flex-wrap:wrap}
.ue-gen code{font:600 13.5px ui-monospace,Menlo,Consolas,monospace;color:#0B6B5E;word-break:break-all}
.ue-reset{width:100%;justify-content:center;height:46px;color:#b7791f;border-color:#F6DFA6;background:#FFF8E8}
.ue-reset:hover{background:#F59E0B;border-color:#F59E0B;color:#fff}

@media (max-width:1000px){.ue-layout{grid-template-columns:1fr}.ue-side{position:static}}
@media (max-width:600px){.ue-grid2{grid-template-columns:1fr}.ue-savebar>div{width:100%}.ue-savebar .db-btn{flex:1;justify-content:center}.ue-title{align-items:flex-start}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var $ = function (id) { return document.getElementById(id); };
    $('uePassToggle').addEventListener('click', function () {
        var el = $('uePass'); el.type = el.type === 'password' ? 'text' : 'password';
    });
    $('uePassGen').addEventListener('click', function () {
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$', pass = '';
        for (var i = 0; i < 10; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
        $('uePass').value = pass; $('uePass').type = 'text'; $('uePassShow').textContent = pass;
    });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
