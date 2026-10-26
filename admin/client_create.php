<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle   = 'Create Client';
$currentPage = 'users';

$error   = '';
$success = '';
$newId   = 0;

// ── Handle form submission ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $fname   = trim((string)($_POST['first_name']  ?? ''));
    $lname   = trim((string)($_POST['last_name']   ?? ''));
    $company = trim((string)($_POST['company']     ?? ''));
    $email   = strtolower(trim((string)($_POST['email']     ?? '')));
    $mobile  = trim((string)($_POST['mobile']      ?? ''));
    $role    = in_array($_POST['role'] ?? '', ['admin','client']) ? $_POST['role'] : 'client';
    $rawPass = (string)($_POST['password'] ?? '');
    $sendGen = isset($_POST['send_generated']);  // checkbox: use auto-generated pwd

    $fullName = trim($fname . ' ' . $lname);

    // ── Validate ──────────────────────────────────────────────────────────────
    if (!$fname || !$lname || !$company || !$email) {
        $error = 'Name, Company, and Email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($rawPass) < 8 && !$sendGen) {
        $error = 'Password must be at least 8 characters, or tick "auto-generate".';
    } else {
        // Check duplicate email
        $ck = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $ck->bind_param('s', $email);
        $ck->execute();
        if ($ck->get_result()->num_rows > 0) {
            $error = 'An account with that email already exists.';
        } else {
            // Build password
            $plainPass = $sendGen
                ? bin2hex(random_bytes(8))   // 16-char hex auto password
                : $rawPass;
            $hashed = password_hash($plainPass, PASSWORD_DEFAULT);

            $conn->begin_transaction();
            try {
                // 1. Create company
                $cStmt = $conn->prepare(
                    "INSERT INTO companies (name, email, phone, status, created_at) VALUES (?, ?, ?, 'active', NOW())"
                );
                if (!$cStmt) throw new \RuntimeException('companies prepare: ' . $conn->error);
                $cStmt->bind_param('sss', $company, $email, $mobile);
                $cStmt->execute();
                $newCompId = (int)$conn->insert_id;
                $cStmt->close();

                // 2. Create user
                $uStmt = $conn->prepare(
                    "INSERT INTO users (company_id, company, name, email, password, mobile, role, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())"
                );
                if (!$uStmt) throw new \RuntimeException('users prepare: ' . $conn->error);
                $uStmt->bind_param('issssss', $newCompId, $company, $fullName, $email, $hashed, $mobile, $role);
                $uStmt->execute();
                $newId = (int)$conn->insert_id;
                $uStmt->close();

                $conn->commit();

                // 3. Audit log
                $adminId  = (int)$_SESSION['user_id'];
                $desc     = 'Admin created client account: ' . $email . ' (Company: ' . $company . ')';
                $ip       = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $aStmt = $conn->prepare(
                    "INSERT INTO audit_log (user_id, action, description, ip_address, created_at) VALUES (?, 'admin_create_client', ?, ?, NOW())"
                );
                if ($aStmt) { $aStmt->bind_param('iss', $newId, $desc, $ip); $aStmt->execute(); $aStmt->close(); }

                // Clear notification cache
                unset($_SESSION['_admin_notif_users'], $_SESSION['_admin_notif_ts']);

                $success = 'success';

            } catch (\Throwable $e) {
                $conn->rollback();
                error_log('[WAAMINI client_create] ' . $e->getMessage());
                $error = 'Could not create account. Please try again.';
            }
        }
    }
}

include '../includes/admin_header.php';
?>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
    <a href="users.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);text-decoration:none;background:rgba(255,255,255,.05);border:1px solid var(--border);padding:7px 14px;border-radius:8px;transition:background .15s;"
       onmouseover="this.style.background='rgba(255,255,255,.09)'" onmouseout="this.style.background='rgba(255,255,255,.05)'">
        ← Users
    </a>
    <span style="color:var(--text-muted);font-size:13px;">/</span>
    <span style="font-size:13px;color:var(--text-primary);font-weight:600;">Create Client</span>
</div>

<?php if ($success === 'success' && $newId > 0): ?>
<!-- ── Success state ─────────────────────────────────────────────────────────── -->
<div class="glass-card" style="padding:48px;text-align:center;max-width:520px;margin:0 auto;">
    <div style="margin-bottom:16px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="56" height="56"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></div>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:8px;">Client Created!</h2>
    <p style="color:var(--text-muted);font-size:14px;margin-bottom:28px;">
        The account is live and ready. You can now view their profile or create another.
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
        <a href="client_view.php?id=<?php echo $newId; ?>" class="btn btn-primary">View Profile</a>
        <a href="client_create.php" class="btn btn-sm btn-edit">Create Another</a>
        <a href="users.php" class="btn btn-sm btn-edit">All Users</a>
    </div>
</div>

<?php else: ?>
<!-- ── Create form ───────────────────────────────────────────────────────────── -->

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:20px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="cc-layout" style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;max-width:900px;">

    <!-- Main form -->
    <div class="glass-card" style="padding:32px;">
        <h2 style="font-size:18px;font-weight:800;margin:0 0 6px;">New Client Account</h2>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 28px;">Create a login for a new client. They'll be able to sign in immediately.</p>

        <form method="POST" id="createForm" autocomplete="off">
            <?php echo csrf_field(); ?>

            <!-- Name row -->
            <div class="cc-two" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">First Name <span style="color:#e74c3c;">*</span></label>
                    <input type="text" name="first_name" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                           placeholder="Rahul" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Last Name <span style="color:#e74c3c;">*</span></label>
                    <input type="text" name="last_name" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                           placeholder="Kumar" required>
                </div>
            </div>

            <!-- Company -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Company / Business Name <span style="color:#e74c3c;">*</span></label>
                <input type="text" name="company" class="form-control"
                       value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>"
                       placeholder="Acme Pvt Ltd" required>
            </div>

            <!-- Email -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Email Address <span style="color:#e74c3c;">*</span></label>
                <input type="email" name="email" class="form-control"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       placeholder="rahul@acme.com" required autocomplete="off">
            </div>

            <!-- Mobile -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Mobile Number</label>
                <input type="text" name="mobile" class="form-control"
                       value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>"
                       placeholder="+91 98765 43210">
            </div>

            <!-- Role -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Role</label>
                <select name="role" class="form-control">
                    <option value="client" <?php echo ($_POST['role'] ?? 'client') === 'client' ? 'selected' : ''; ?>>Client — basic portal access</option>
                    <option value="admin"  <?php echo ($_POST['role'] ?? '') === 'admin'  ? 'selected' : ''; ?>>Admin — full vendor access</option>
                </select>
            </div>

            <!-- Password section -->
            <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px;margin-bottom:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">Password</label>
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;color:var(--accent);">
                        <input type="checkbox" name="send_generated" id="chkAutoGen"
                               <?php echo isset($_POST['send_generated']) ? 'checked' : ''; ?>
                               onchange="document.getElementById('passField').style.display=this.checked?'none':'block';">
                        Auto-generate a strong password
                    </label>
                </div>
                <div id="passField" style="<?php echo isset($_POST['send_generated']) ? 'display:none;' : ''; ?>">
                    <div style="position:relative;">
                        <input type="password" name="password" id="newPassInput" class="form-control"
                               placeholder="Minimum 8 characters" minlength="8" autocomplete="new-password"
                               value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
                        <button type="button" onclick="var i=document.getElementById('newPassInput');i.type=i.type==='password'?'text':'password';"
                                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    <button type="button" style="font-size:12px;color:var(--accent);background:none;border:none;cursor:pointer;padding:8px 0 0;display:block;"
                            onclick="(function(){var c='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';var p='';for(var i=0;i<12;i++)p+=c[Math.floor(Math.random()*c.length)];var el=document.getElementById('newPassInput');el.value=p;el.type='text';})()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Generate strong password
                    </button>
                </div>
                <?php if (isset($_POST['send_generated'])): ?>
                <div style="font-size:12px;color:var(--text-muted);background:rgba(108,92,231,.08);padding:10px 14px;border-radius:8px;border:1px solid rgba(108,92,231,.2);">
                    A random password will be generated. Share it with the client manually.
                </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:15px;font-weight:700;">
                Create Client Account
            </button>

        </form>
    </div>

    <!-- Side info panel -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <div class="glass-card" style="padding:22px;">
            <h4 style="font-size:13px;font-weight:700;margin:0 0 14px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">What happens next</h4>
            <div style="display:flex;flex-direction:column;gap:12px;font-size:13px;color:var(--text-primary);">
                <div style="display:flex;gap:10px;">
                    <span style="color:#25d366;font-size:16px;flex-shrink:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="currentColor" stroke="none" font-size="13" font-weight="700">1</text></svg></span>
                    <span>A company record is created in your system</span>
                </div>
                <div style="display:flex;gap:10px;">
                    <span style="color:#25d366;font-size:16px;flex-shrink:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="currentColor" stroke="none" font-size="13" font-weight="700">2</text></svg></span>
                    <span>The client gets a login at <strong>your domain/login.php</strong></span>
                </div>
                <div style="display:flex;gap:10px;">
                    <span style="color:#25d366;font-size:16px;flex-shrink:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="currentColor" stroke="none" font-size="13" font-weight="700">3</text></svg></span>
                    <span>Share the email + password with your client manually</span>
                </div>
                <div style="display:flex;gap:10px;">
                    <span style="color:#25d366;font-size:16px;flex-shrink:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" fill="currentColor" stroke="none" font-size="13" font-weight="700">4</text></svg></span>
                    <span>You can login-as them instantly from the profile page</span>
                </div>
            </div>
        </div>

        <div class="glass-card" style="padding:22px;border:1px solid rgba(252,211,77,.15);background:rgba(254,243,199,.03);">
            <h4 style="font-size:13px;font-weight:700;margin:0 0 10px;color:#FCD34D;">Tip</h4>
            <p style="font-size:12px;color:var(--text-muted);margin:0;line-height:1.6;">
                Use <strong>"Auto-generate"</strong> to create a secure random password.
                Copy it and send to your client — they can change it after first login.
            </p>
        </div>

        <div class="glass-card" style="padding:22px;">
            <h4 style="font-size:13px;font-weight:700;margin:0 0 12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Role Guide</h4>
            <div style="font-size:12px;color:var(--text-primary);display:flex;flex-direction:column;gap:10px;">
                <div><strong style="color:#a29bfe;">Client</strong><br><span style="color:var(--text-muted);">Basic access — dashboard, contacts, WhatsApp only</span></div>
                <div><strong style="color:#25d366;">Admin</strong><br><span style="color:var(--text-muted);">Full vendor access — billing, team, settings</span></div>
            </div>
        </div>
    </div>

</div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
