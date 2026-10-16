
<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

if (!isset($_GET['id'])) {
    header("Location: groups.php");
    exit();
}

$groupId = intval($_GET['id']);
$stmt_g = $conn->prepare("SELECT * FROM contact_groups WHERE id=?");
$group = null;
if ($stmt_g) {
    $stmt_g->bind_param("i", $groupId);
    $stmt_g->execute();
    $group = $stmt_g->get_result()->fetch_assoc();
}
if (!$group) {
    header("Location: groups.php");
    exit();
}

$pageTitle = 'Manage: ' . $group['name'];
$currentPage = 'contacts';

// Handle remove member (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'remove') {
        $cid = (int)($_POST['contact_id'] ?? 0);
        if ($cid > 0) {
            $stmt = $conn->prepare("DELETE FROM contact_group_members WHERE group_id=? AND contact_id=?");
            if ($stmt) {
                $stmt->bind_param("ii", $groupId, $cid);
                $stmt->execute();
            }
        }
        header("Location: group_manage.php?id=$groupId&msg=removed");
        exit();
    }
}

// Handle bulk add
if (isset($_POST['bulk_add'])) {
    $contactIds = $_POST['contact_ids'] ?? [];
    if (!empty($contactIds)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (?, ?)");
        $added = 0;
        foreach ($contactIds as $cid) {
            $cid = intval($cid);
            $stmt->bind_param("ii", $groupId, $cid);
            if ($stmt->execute())
                $added++;
        }
        header("Location: group_manage.php?id=$groupId&msg=added&count=$added");
        exit();
    }
}

// Handle bulk add ALL (filtered)
if (isset($_POST['add_all'])) {
    $search = trim($_POST['search_all'] ?? '');
    $tag = trim($_POST['tag_all'] ?? '');

    $where = "c.id NOT IN (SELECT contact_id FROM contact_group_members WHERE group_id=?)";
    $params = [$groupId];
    $types = "i";

    if ($search) {
        $where .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    if ($tag) {
        $where .= " AND c.tags LIKE ?";
        $tagTerm = "%$tag%";
        $params[] = $tagTerm;
        $types .= "s";
    }

    $stmt_sel = $conn->prepare("SELECT c.id FROM contacts c WHERE $where");
    $stmt_sel->bind_param($types, ...$params);
    $stmt_sel->execute();
    $result = $stmt_sel->get_result();
    $stmt = $conn->prepare("INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (?, ?)");
    $added = 0;
    while ($row = $result->fetch_assoc()) {
        $cid = $row['id'];
        $stmt->bind_param("ii", $groupId, $cid);
        if ($stmt->execute())
            $added++;
    }
    header("Location: group_manage.php?id=$groupId&msg=added&count=$added");
    exit();
}

// Search non-members
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchTerm = '%' . $search . '%';
$nonMembersSql = "
    SELECT c.* FROM contacts c
    WHERE c.id NOT IN (SELECT contact_id FROM contact_group_members WHERE group_id=?)
";
$nonMembersTypes = 'i';
$nonMembersParams = [$groupId];
if ($search !== '') {
    $nonMembersSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)
";
    $nonMembersTypes .= 'sss';
    $nonMembersParams[] = $searchTerm;
    $nonMembersParams[] = $searchTerm;
    $nonMembersParams[] = $searchTerm;
}
$nonMembersSql .= " ORDER BY c.name ASC LIMIT 50";
$nonMembersStmt = $conn->prepare($nonMembersSql);
if ($nonMembersStmt) {
    $nonMembersStmt->bind_param($nonMembersTypes, ...$nonMembersParams);
    $nonMembersStmt->execute();
    $nonMembers = $nonMembersStmt->get_result();
} else {
    $nonMembers = false;
}

$countNonMembersStmt = $conn->prepare("SELECT COUNT(*) as c FROM contacts c WHERE c.id NOT IN (SELECT contact_id FROM contact_group_members WHERE group_id=?)");
$totalNonMembers = 0;
if ($countNonMembersStmt) {
    $countNonMembersStmt->bind_param('i', $groupId);
    $countNonMembersStmt->execute();
    $totalNonMembers = (int)($countNonMembersStmt->get_result()->fetch_assoc()['c'] ?? 0);
}

// Fetch current members
$memberSearch = isset($_GET['msearch']) ? trim($_GET['msearch']) : '';
$memberTerm = '%' . $memberSearch . '%';
$memberSql = "
    SELECT c.*, gm.added_at as joined_at
    FROM contact_group_members gm
    JOIN contacts c ON gm.contact_id = c.id
    WHERE gm.group_id = ?
";
$memberTypes = 'i';
$memberParams = [$groupId];
if ($memberSearch !== '') {
    $memberSql .= " AND (c.name LIKE ? OR c.phone LIKE ?)
";
    $memberTypes .= 'ss';
    $memberParams[] = $memberTerm;
    $memberParams[] = $memberTerm;
}
$memberSql .= " ORDER BY gm.added_at DESC LIMIT 100";
$memberStmt = $conn->prepare($memberSql);
if ($memberStmt) {
    $memberStmt->bind_param($memberTypes, ...$memberParams);
    $memberStmt->execute();
    $members = $memberStmt->get_result();
} else {
    $members = false;
}

$totalMembersStmt = $conn->prepare("SELECT COUNT(*) as c FROM contact_group_members WHERE group_id=?");
$totalMembers = 0;
if ($totalMembersStmt) {
    $totalMembersStmt->bind_param('i', $groupId);
    $totalMembersStmt->execute();
    $totalMembers = (int)($totalMembersStmt->get_result()->fetch_assoc()['c'] ?? 0);
}

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php
    if ($_GET['msg'] === 'removed')
        echo 'Contact removed from group!';
    elseif ($_GET['msg'] === 'added')
        echo (int)($_GET['count'] ?? 0) . ' contacts added to group!';
?>
    </div>
<?php
endif; ?>

<div class="page-header">
    <h2 style="display:flex; align-items:center; gap:10px;">
        <span style="width:12px; height:12px; border-radius:3px; background:<?php echo (preg_match('/^#[0-9a-f]{6}$/i', $group['color']) ? htmlspecialchars($group['color'], ENT_QUOTES, 'UTF-8') : '#6c5ce7'); ?>;"></span>
        <?php echo htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'); ?> 
        <span style="font-size:14px; font-weight:400; color:var(--text-muted);">(<?php echo number_format($totalMembers); ?> members)</span>
    </h2>
    <div class="action-btns">
        <a href="groups.php" class="btn btn-sm btn-edit">← Back to Groups</a>
    </div>
</div>

<!-- ADD CONTACTS SECTION -->
<div class="data-table-wrapper" style="margin-bottom: 24px;">
    <div class="table-header">
        <h3><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Contacts to Group <span style="font-size:12px; color:var(--text-muted); font-weight:400;">(<?php echo number_format($totalNonMembers); ?> available)</span></h3>
        <form method="POST" style="display:flex; gap:6px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="search_all" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" name="add_all" class="btn btn-sm btn-success js-confirm"
                    data-confirm="Add all <?php echo number_format($totalNonMembers); ?> available contacts to this group?">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Add All (<?php echo number_format($totalNonMembers); ?>)
            </button>
        </form>
    </div>
    
    <!-- Search -->
    <div style="padding: 12px 20px; border-bottom: 1px solid var(--border);">
        <form method="GET" style="display: flex; gap: 8px;">
            <input type="hidden" name="id" value="<?php echo $groupId; ?>">
            <input type="text" name="search" class="filter-input" placeholder="Search contacts to add..." 
                   value="<?php echo htmlspecialchars($search); ?>" style="flex:1;">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            <?php if ($search): ?>
                <a href="group_manage.php?id=<?php echo $groupId; ?>" class="btn btn-sm btn-edit"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
            <?php
endif; ?>
        </form>
    </div>

    <!-- Checkbox form -->
    <form method="POST">
        <?php echo csrf_field(); ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Tags</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($nonMembers->num_rows > 0): ?>
                    <?php while ($c = $nonMembers->fetch_assoc()): ?>
                    <tr>
                        <td><input type="checkbox" name="contact_ids[]" value="<?php echo $c['id']; ?>" class="contact-check"></td>
                        <td style="color:var(--text-primary); font-weight:500;"><?php echo htmlspecialchars($c['name']); ?></td>
                        <td><?php echo htmlspecialchars($c['phone']); ?></td>
                        <td>
                            <?php if ($c['tags']): ?>
                                <?php foreach (explode(',', $c['tags']) as $tag): ?>
                                    <span class="tag-badge"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                <?php
            endforeach; ?>
                            <?php
        else: ?>—<?php
        endif; ?>
                        </td>
                    </tr>
                    <?php
    endwhile; ?>
                <?php
else: ?>
                    <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px;">
                        <?php echo $search ? 'No matching contacts found.' : 'All contacts are already in this group! <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-left:4px;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>'; ?>
                    </td></tr>
                <?php
endif; ?>
            </tbody>
        </table>
        <?php if ($nonMembers->num_rows > 0): ?>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border); display:flex; align-items:center; gap:10px;">
            <button type="submit" name="bulk_add" class="btn btn-sm btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Selected to Group</button>
            <span style="font-size: 12px; color: var(--text-muted);" id="selectedCount">0 selected</span>
        </div>
        <?php
endif; ?>
    </form>
</div>

<!-- CURRENT MEMBERS -->
<div class="data-table-wrapper">
    <div class="table-header">
        <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Current Members (<?php echo number_format($totalMembers); ?>)</h3>
    </div>
    
    <?php if ($totalMembers > 0): ?>
    <div style="padding: 12px 20px; border-bottom: 1px solid var(--border);">
        <form method="GET" style="display: flex; gap: 8px;">
            <input type="hidden" name="id" value="<?php echo $groupId; ?>">
            <input type="text" name="msearch" class="filter-input" placeholder="Search members..." 
                   value="<?php echo htmlspecialchars($memberSearch); ?>" style="flex:1;">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
        </form>
    </div>
    <?php
endif; ?>

    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Tags</th>
                <th>Added</th>
                <th style="width:80px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($members->num_rows > 0): ?>
                <?php while ($m = $members->fetch_assoc()): ?>
                <tr>
                    <td style="color:var(--text-primary); font-weight:500;"><?php echo htmlspecialchars($m['name']); ?></td>
                    <td><?php echo htmlspecialchars($m['phone']); ?></td>
                    <td>
                        <?php if ($m['tags']): ?>
                            <?php foreach (explode(',', $m['tags']) as $tag): ?>
                                <span class="tag-badge"><?php echo htmlspecialchars(trim($tag)); ?></span>
                            <?php
            endforeach; ?>
                        <?php
        else: ?>—<?php
        endif; ?>
                    </td>
                    <td style="font-size:12px;"><?php echo date('d M Y', strtotime($m['joined_at'])); ?></td>
                    <td>
                        <form method="POST" action="group_manage.php?id=<?php echo (int)$groupId; ?>" style="display:inline;margin:0;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="contact_id" value="<?php echo (int)$m['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger js-confirm" data-confirm="Remove from group?"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
                        </form>
                    </td>
                </tr>
                <?php
    endwhile; ?>
            <?php
else: ?>
                <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:30px;">
                    No members yet. Add contacts from above!
                </td></tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Select-all checkbox — addEventListener replaces inline onchange (CSP-safe)
var selectAllCb = document.getElementById('selectAll');
if (selectAllCb) {
    selectAllCb.addEventListener('change', function() {
        document.querySelectorAll('.contact-check').forEach(function(cb) { cb.checked = selectAllCb.checked; });
        updateCount();
    });
}
document.querySelectorAll('.contact-check').forEach(function(cb) { cb.addEventListener('change', updateCount); });
function updateCount() {
    var count = document.querySelectorAll('.contact-check:checked').length;
    var el = document.getElementById('selectedCount');
    if (el) el.textContent = count + ' selected';
}

// Confirm dialogs (CSP-safe)
document.addEventListener('click', function(e) {
    var el = e.target.closest('.js-confirm[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});
</script>

<?php include '../includes/admin_footer.php'; ?>
