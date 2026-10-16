<?php
include '../includes/client_auth_check.php';
if (!has_feature('team_members')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Team Members';
$currentPage = 'team_members';

$success = '';
$error = '';

// Check and add role column if missing
try {
    // Migration handled schema change
}
catch (Exception $e) {
}

// Check and add permissions column if missing
try {
    // Migration handled schema change
}
catch (Exception $e) {
}

// Handle Add / Edit User
if (isset($_POST['save_user'])) {
    csrf_verify();

    // Check limit if adding new
    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    if ($userId == 0 && !can_use('team_members')) {
        $error = "Team member limit reached! Upgrade your plan.";
    }
    else {
        $name = sanitize($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $password = $_POST['password'] ?? '';
        $mobile = preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
        $status = sanitize($_POST['status'] ?? 'active');
        $role = sanitize($_POST['role'] ?? 'member');

        // Build permissions array (if not full Admin)
        $perms = [];
        if ($role === 'member' && isset($_POST['perms']) && is_array($_POST['perms'])) {
            foreach ($_POST['perms'] as $p) {
                $perms[] = sanitize($p);
            }
        }
        $permsJson = json_encode($perms);

        // Validate email uniqueness
        $check = $conn->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $check->bind_param("si", $email, $userId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Email address is already in use.";
        }
        else {
            if ($userId > 0) {
                // UPDATE
                if (!empty($password)) {
                    $pwHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, mobile=?, password=?, status=?, role=?, permissions=? WHERE id=? AND company_id=?");
                    $stmt->bind_param("ssssssii", $name, $email, $mobile, $pwHash, $status, $role, $permsJson, $userId, $companyId);
                }
                else {
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, mobile=?, status=?, role=?, permissions=? WHERE id=? AND company_id=?");
                    $stmt->bind_param("ssssssii", $name, $email, $mobile, $status, $role, $permsJson, $userId, $companyId);
                }
                $stmt->execute();
                $success = "Team member updated successfully!";
            }
            else {
                // INSERT
                if (empty($password)) {
                    $error = "Password is required for new users.";
                }
                else {
                    $pwHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (company_id, name, email, mobile, password, role, permissions, status) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("isssssss", $companyId, $name, $email, $mobile, $pwHash, $role, $permsJson, $status);
                    $stmt->execute();
                    $success = "Team member added successfully!";
                }
            }
        }
    }
}

// Handle Delete (VULN-003 FIX: Converted from GET to POST + CSRF)
if (isset($_POST['delete_user'])) {
    csrf_verify();
    $did = intval($_POST['delete_user_id']);
    // Don't allow deleting self or primary owner
    if ($did != $_SESSION['user_id']) {
        $delStmt = $conn->prepare("DELETE FROM users WHERE id=? AND company_id=? AND role='member'");
        $delStmt->bind_param("ii", $did, $companyId);
        $delStmt->execute();
        header("Location: team_members.php?msg=deleted");
        exit();
    }
    else {
        $error = "You cannot delete your own account.";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success = "Team member removed.";
}

$memStmt = $conn->prepare("SELECT * FROM users WHERE company_id=? ORDER BY id ASC");
$memStmt->bind_param("i", $companyId);
$memStmt->execute();
$members = $memStmt->get_result();

include '../includes/client_header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php
endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Team Members</h2>
    <?php if (can_use('team_members')): ?>
    <div class="action-btns">
        <button onclick="openModal()" class="btn btn-primary" style="border-radius:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Team Member</button>
    </div>
    <?php
else: ?>
    <div class="action-btns">
        <span class="badge badge-suspended" style="padding:10px 14px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Member Limit Reached</span>
    </div>
    <?php
endif; ?>
</div>

<div class="data-table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name & Email</th>
                <th>Role</th>
                <th>Access Levels</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($members && $members->num_rows > 0):
    while ($m = $members->fetch_assoc()):
        $isOwner = ($m['role'] === 'client');
        $perms = json_decode($m['permissions'] ?? '[]', true);
        if (!is_array($perms))
            $perms = [];
?>
            <tr>
                <td>
                    <div style="font-weight:600;color:var(--text-primary);"><?php echo htmlspecialchars($m['name']); ?></div>
                    <div style="font-size:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($m['email']); ?></div>
                </td>
                <td>
                    <?php if ($isOwner): ?>
                        <span class="badge" style="background:rgba(156,39,176,0.1);color:#ba68c8;border:1px solid rgba(156,39,176,0.2);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Owner / Full Admin</span>
                    <?php
        else: ?>
                        <span class="badge badge-active"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Member</span>
                    <?php
        endif; ?>
                </td>
                <td>
                    <?php if ($isOwner): ?>
                        <span style="font-size:12px;color:var(--text-muted);">Has access to all modules including billing.</span>
                    <?php
        else: ?>
                        <div style="display:flex;flex-wrap:wrap;gap:4px;">
                            <?php if (empty($perms)): ?>
                                <span style="font-size:12px;color:var(--text-muted);">Dashboard only</span>
                            <?php
            endif; ?>
                            <?php foreach ($perms as $p):
                $labels = [
                    'chat' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Chat Reply',
                    'campaigns' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Campaigns',
                    'templates' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Templates',
                    'contacts' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Contacts'
                ];
                if (isset($labels[$p])):
?>
                            <span class="badge" style="background:var(--bg-input);color:var(--text-secondary);"><?php echo $labels[$p]; ?></span>
                            <?php
                endif;
            endforeach; ?>
                        </div>
                    <?php
        endif; ?>
                </td>
                <td>
                    <?php if ($m['status'] == 'active'): ?>
                        <span class="badge badge-active">Active</span>
                    <?php
        else: ?>
                        <span class="badge badge-suspended">Disabled</span>
                    <?php
        endif; ?>
                </td>
                <td>
                    <?php if (!$isOwner && $m['id'] != $_SESSION['user_id']): ?>
                    <div class="action-btns">
                        <button onclick='editUser(<?php echo json_encode($m); ?>)' class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete member?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_user_id" value="<?php echo $m['id']; ?>">
                            <button type="submit" name="delete_user" class="btn btn-sm btn-danger" style="padding:5px 8px;border-radius:4px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                        </form>
                    </div>
                    <?php
        else: ?>
                    <span style="font-size:11px;color:var(--text-muted);">N/A</span>
                    <?php
        endif; ?>
                </td>
            </tr>
            <?php
    endwhile;
else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>No team members found.</p></div></td></tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="userModal" class="modal-overlay hidden">
    <div class="modal-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 id="modalTitle" style="font-size:16px;font-weight:700;color:var(--text-primary);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Team Member</h3>
            <button onclick="closeModal()" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
        </div>
        
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="formUserId" value="0">
            <input type="hidden" name="role" value="member">
            
            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" id="formName" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" id="formEmail" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Mobile Number (For WhatsApp Auth)</label>
                <input type="text" name="mobile" id="formMobile" class="form-control" placeholder="919876543210">
            </div>
            
            <div class="form-group">
                <label>Password <span id="pwReq" style="color:var(--danger);">*</span></label>
                <input type="password" name="password" id="formPassword" class="form-control">
                <small id="pwHelp" style="color:var(--text-muted);display:none;">Leave blank to keep existing password.</small>
            </div>
            
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="formRole" class="form-control" onchange="toggleAgentNote()">
                    <option value="member">Standard Member</option>
                    <option value="agent">Support Agent (Live Chat Focus)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" id="formStatus" class="form-control">
                    <option value="active">Active</option>
                    <option value="disabled">Disabled (Cannot login)</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-top:24px;background:var(--bg-dark);padding:16px;border-radius:10px;border:1px solid var(--border);">
                <label style="margin-bottom:12px;display:block;color:#00b894;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg> Allowed Access Permissions</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);cursor:pointer;">
                        <input type="checkbox" name="perms[]" value="chat" class="perm-check"> 
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> WhatsApp Chat (Reply to users)
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);cursor:pointer;">
                        <input type="checkbox" name="perms[]" value="campaigns" class="perm-check"> 
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Send Campaigns
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);cursor:pointer;">
                        <input type="checkbox" name="perms[]" value="templates" class="perm-check"> 
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Create Templates
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);cursor:pointer;">
                        <input type="checkbox" name="perms[]" value="contacts" class="perm-check"> 
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Manage Contacts
                    </label>
                </div>
                <p style="font-size:11px;color:var(--text-muted);margin-top:12px;line-height:1.5;">
                    Note: Members <strong>never</strong> have access to Billing, API Settings, or Company Settings.
                </p>
            </div>
            
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:24px;">
                <button type="button" class="btn btn-edit" onclick="closeModal()">Cancel</button>
                <button type="submit" name="save_user" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Member</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); }
.modal-overlay.hidden { display: none; }
.modal-card { background: var(--bg-card); padding: 24px; border-radius: 14px; width: 100%; max-width: 500px; border: 1px solid var(--border); box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
</style>

<script>
function openModal() {
    document.getElementById('modalTitle').textContent = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Team Member';
    document.getElementById('formUserId').value = '0';
    document.getElementById('formName').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formMobile').value = '';
    document.getElementById('formPassword').required = true;
    document.getElementById('pwReq').style.display = 'inline';
    document.getElementById('pwHelp').style.display = 'none';
    document.getElementById('formStatus').value = 'active';
    
    // Uncheck all perms
    document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
    
    document.getElementById('userModal').classList.remove('hidden');
}

function editUser(user) {
    document.getElementById('modalTitle').textContent = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit Team Member';
    document.getElementById('formUserId').value = user.id;
    document.getElementById('formName').value = user.name;
    document.getElementById('formEmail').value = user.email;
    document.getElementById('formMobile').value = user.mobile || '';
    document.getElementById('formPassword').required = false;
    document.getElementById('pwReq').style.display = 'none';
    document.getElementById('pwHelp').style.display = 'block';
    document.getElementById('formStatus').value = user.status;
    
    // Parse perms and check boxes
    const perms = user.permissions ? JSON.parse(user.permissions) : [];
    document.querySelectorAll('.perm-check').forEach(cb => {
        cb.checked = perms.includes(cb.value);
    });
    
    document.getElementById('userModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('userModal').classList.add('hidden');
}
</script>

<?php include '../includes/client_footer.php'; ?>
