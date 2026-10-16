<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Contact Groups';
$currentPage = 'contacts';

// Handle state-changing actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM contact_groups WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
        header("Location: groups.php?msg=deleted");
        exit();
    }
}

// Handle add
if (isset($_POST['add_group'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $desc = sanitize($_POST['description'] ?? '');
    $color = preg_match('/^#[a-fA-F0-9]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6c5ce7';
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO contact_groups (name, description, color) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $desc, $color);
        $stmt->execute();
        header("Location: groups.php?msg=added");
        exit();
    }
}

// Fetch groups with member count
$groups = $conn->query("
    SELECT g.*, COUNT(gm.id) as member_count 
    FROM contact_groups g 
    LEFT JOIN contact_group_members gm ON g.id = gm.group_id 
    GROUP BY g.id 
    ORDER BY g.id DESC
");

$totalContacts = $conn->query("SELECT COUNT(*) as c FROM contacts")->fetch_assoc()['c'];

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars(((string)$_GET['msg'] === 'deleted') ? 'Group deleted!' : (((string)$_GET['msg'] === 'added') ? 'Group created!' : 'Group updated!'), ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php
endif; ?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg> Contact Groups</h2>
    <a href="contacts.php" class="btn btn-sm btn-edit">← Back to Contacts</a>
</div>

<!-- Quick Add Group -->
<div class="form-card" style="max-width: 100%; margin-bottom: 24px;">
    <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create New Group</h3>
    <form method="POST" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
        <?php echo csrf_field(); ?>
        <div style="flex: 1; min-width: 200px;">
            <label style="display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;">Group Name *</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. VIP Customers" required>
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label style="display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;">Description</label>
            <input type="text" name="description" class="form-control" placeholder="Optional description">
        </div>
        <div style="width: 80px;">
            <label style="display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;">Color</label>
            <input type="color" name="color" value="#6c5ce7" style="width:100%; height:42px; border:1px solid var(--border); border-radius:8px; background:var(--bg-input); cursor:pointer;">
        </div>
        <button type="submit" name="add_group" class="btn btn-primary" style="height:42px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create</button>
    </form>
</div>

<!-- Groups Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <?php if ($groups->num_rows > 0): ?>
        <?php while ($g = $groups->fetch_assoc()): ?>
        <div class="stat-card" style="position: relative;">
            <div style="position:absolute; top:0; left:0; right:0; height:3px; background:<?php echo (preg_match('/^#[0-9a-f]{6}$/i', $g['color']) ? htmlspecialchars($g['color'], ENT_QUOTES, 'UTF-8') : '#6c5ce7'); ?>; border-radius:12px 12px 0 0;"></div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">
                        <?php echo htmlspecialchars($g['name']); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                        <?php echo htmlspecialchars($g['description'] ?? 'No description'); ?>
                    </div>
                </div>
                <div style="display:flex; gap:6px;">
                    <a href="group_manage.php?id=<?php echo $g['id']; ?>" class="btn btn-sm btn-edit" title="Manage members"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></a>
                    <form method="POST" action="groups.php" style="display:inline;margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger js-confirm" data-confirm="Delete this group? Members won't be deleted." title="Delete group"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
                    </form>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--text-primary);">
                    <?php echo number_format($g['member_count']); ?>
                </div>
                <div style="font-size: 13px; color: var(--text-secondary);">
                    contacts in this group
                </div>
            </div>
            <div style="margin-top: 12px; display: flex; gap: 8px;">
                <a href="group_manage.php?id=<?php echo $g['id']; ?>" class="btn btn-sm btn-primary" style="flex:1; justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Manage Members</a>
                <a href="contacts.php?group=<?php echo $g['id']; ?>" class="btn btn-sm btn-edit" style="flex:1; justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg> View Contacts</a>
            </div>
        </div>
        <?php
    endwhile; ?>
    <?php
else: ?>
        <div class="stat-card" style="grid-column: 1/-1;">
            <div class="empty-state">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:32px;height:32px;"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg></div>
                <p>No groups yet. Create your first group above to organize your <?php echo number_format($totalContacts); ?> contacts!</p>
            </div>
        </div>
    <?php
endif; ?>
</div>

<!-- Info Card -->
<div class="form-card" style="max-width: 100%; background: rgba(108,92,231,0.06); border-color: rgba(108,92,231,0.15);">
    <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> How Groups Work</h3>
    <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.8;">
        <p><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" style="width:8px;height:8px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/></svg> <strong>Create groups</strong> like "VIP Customers", "New Leads", "Karaikudi Zone" etc.</p>
        <p><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" style="width:8px;height:8px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/></svg> <strong>Add contacts</strong> to groups — one contact can be in multiple groups.</p>
        <p><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" style="width:8px;height:8px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/></svg> <strong>Bulk assign</strong> — select multiple contacts and add them to a group at once.</p>
        <p><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" style="width:8px;height:8px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/></svg> <strong>CSV import</strong> — you can assign a group during import.</p>
        <p><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" style="width:8px;height:8px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/></svg> <strong>Campaigns</strong> — (coming soon) send targeted campaigns to specific groups.</p>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('click', function(e) {
    var el = e.target.closest('.js-confirm[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});
</script>

<?php include '../includes/admin_footer.php'; ?>
