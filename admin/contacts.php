<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Contacts';
$currentPage = 'contacts';

// Handle delete (CSRF protected via POST)
if (isset($_POST['delete_contact'])) {
    csrf_verify();
    try {
        $did = (int)($_POST['delete_contact'] ?? 0);
        if ($did > 0) {
            $stmt = $conn->prepare("DELETE FROM contacts WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $did);
                $stmt->execute();
                header("Location: contacts.php?msg=deleted");
            } else {
                header("Location: contacts.php?msg=error");
            }
        } else {
            header("Location: contacts.php?msg=error");
        }
    } catch (Throwable $e) {
        error_log('[WAAMINI contacts] Delete error: ' . $e->getMessage());
        header("Location: contacts.php?msg=error");
    }
    exit();
}

// Handle status toggle (CSRF protected via POST)
if (isset($_POST['toggle_contact'])) {
    csrf_verify();
    try {
        $tid = (int)($_POST['toggle_contact'] ?? 0);
        if ($tid > 0) {
            $stmt = $conn->prepare("UPDATE contacts SET status = IF(status='active','inactive','active') WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                header("Location: contacts.php?msg=updated");
            } else {
                header("Location: contacts.php?msg=error");
            }
        } else {
            header("Location: contacts.php?msg=error");
        }
    } catch (Throwable $e) {
        error_log('[WAAMINI contacts] Toggle error: ' . $e->getMessage());
        header("Location: contacts.php?msg=error");
    }
    exit();
}

// Search & Filter
$search = isset($_GET['search']) ? trim((string)($_GET['search'])) : '';
$tagFilter = isset($_GET['tag']) ? trim((string)($_GET['tag'])) : '';
$statusFilter = isset($_GET['status']) ? trim((string)($_GET['status'])) : '';

// Whitelist status filter
if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

$where = "1=1";
$params = [];
$types = "";

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $s = "%$search%";
    $params[] = & $s;
    $params[] = & $s;
    $params[] = & $s;
    $types .= "sss";
}
if ($tagFilter) {
    $where .= " AND c.tags LIKE ?";
    $t = "%$tagFilter%";
    $params[] = & $t;
    $types .= "s";
}
if ($statusFilter) {
    $where .= " AND c.status = ?";
    $params[] = & $statusFilter;
    $types .= "s";
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Count total
$totalContacts = 0;
$totalPages = 1;
try {
    $countSql = "SELECT COUNT(*) as total FROM contacts c WHERE $where";
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if ($types) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        if ($countResult && $countRow = $countResult->fetch_assoc()) {
            $totalContacts = (int)($countRow['total'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('[WAAMINI contacts] Count error: ' . $e->getMessage());
    $totalContacts = 0;
}
$totalPages = $totalContacts > 0 ? ceil($totalContacts / $perPage) : 1;

// Fetch contacts
$contacts = null;
try {
    $sql = "SELECT c.*, co.name as company_name 
            FROM contacts c 
            LEFT JOIN companies co ON c.company_id = co.id 
            WHERE $where 
            ORDER BY c.id DESC 
            LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $contacts = $stmt->get_result();
    } else {
        error_log('[WAAMINI contacts] Prepare failed: ' . $conn->error);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI contacts] Fetch error: ' . $e->getMessage());
}
$contacts = $contacts ?? $conn->query("SELECT 1 WHERE 0"); // Return empty result set

// Get all unique tags for filter
$allTags = [];
try {
    $tagResult = $conn->query("SELECT DISTINCT tags FROM contacts WHERE tags IS NOT NULL AND tags != '' LIMIT 200");
    if ($tagResult) {
        while ($row = $tagResult->fetch_assoc()) {
            if (is_array($row) && isset($row['tags'])) {
                $tagsRaw = (string)($row['tags'] ?? '');
                if ($tagsRaw !== '') {
                    foreach (explode(',', $tagsRaw) as $tag) {
                        $t = trim($tag);
                        if ($t !== '' && !in_array($t, $allTags, true)) {
                            $allTags[] = $t;
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[WAAMINI contacts] Tag fetch error: ' . $e->getMessage());
    $allTags = [];
}
sort($allTags);

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <?php
    $msgType = (string)($_GET['msg'] ?? '');
    if ($msgType === 'deleted') {
        $alertMsg = 'Contact deleted!';
        $alertClass = 'alert-success';
    } elseif ($msgType === 'imported') {
        $alertMsg = 'CSV imported successfully!';
        $alertClass = 'alert-success';
    } elseif ($msgType === 'error') {
        $alertMsg = 'An error occurred. Please try again.';
        $alertClass = 'alert-danger';
    } else {
        $alertMsg = 'Contact updated!';
        $alertClass = 'alert-success';
    }
    ?>
    <div class="alert <?php echo htmlspecialchars($alertClass, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($alertMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php
endif; ?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" style="vertical-align:middle;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Contacts (<?php echo $totalContacts; ?>)</h2>
    <div class="action-btns">
        <a href="groups.php" class="btn btn-sm btn-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg> Groups</a>
        <a href="contact_import.php" class="btn btn-sm btn-edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Import CSV</a>
        <a href="contact_add.php" class="btn btn-sm btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Contact</a>
    </div>
</div>

<!-- Search & Filter -->
<div class="filter-bar">
    <form method="GET" class="filter-form">
        <input type="text" name="search" class="filter-input" placeholder="Search name, phone, email..." 
               value="<?php echo htmlspecialchars($search); ?>">
        <select name="tag" class="filter-select">
            <option value="">All Tags</option>
            <?php foreach ($allTags as $tag): ?>
                <option value="<?php echo htmlspecialchars($tag); ?>" <?php echo $tagFilter === $tag ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($tag); ?>
                </option>
            <?php
endforeach; ?>
        </select>
        <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="active" <?php echo ($statusFilter === 'active' ? 'selected' : ''); ?>>Active</option>
            <option value="inactive" <?php echo ($statusFilter === 'inactive' ? 'selected' : ''); ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        <?php if ($search || $tagFilter || $statusFilter): ?>
            <a href="contacts.php" class="btn btn-sm btn-edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear</a>
        <?php
endif; ?>
    </form>
</div>

<!-- Contacts Table -->
<div class="data-table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Tags</th>
                <th>Company</th>
                <th>Status</th>
                <th>Added</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($contacts && $contacts->num_rows > 0): ?>
                <?php while ($c = $contacts->fetch_assoc()): ?>
                    <?php if (is_array($c)): ?>
                <tr>
                    <td><?php echo (int)($c['id'] ?? 0); ?></td>
                    <td style="color: var(--text-primary); font-weight: 500;"><?php echo htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($c['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php
                        $tagsStr = (string)($c['tags'] ?? '');
                        if ($tagsStr !== ''): ?>
                            <?php foreach (explode(',', $tagsStr) as $tag): ?>
                                <span class="tag-badge"><?php echo htmlspecialchars(trim($tag), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)($c['company_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <span class="badge <?php echo ($c['status'] === 'active' ? 'badge-active' : 'badge-suspended'); ?>">
                            <?php echo htmlspecialchars(ucfirst((string)($c['status'] ?? 'inactive')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars(date('d M Y', strtotime((string)($c['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <div class="action-btns" style="display:flex; gap:6px; align-items:center;">
                            <a href="contact_edit.php?id=<?php echo (int)($c['id'] ?? 0); ?>" class="btn btn-sm btn-edit" style="height:32px;display:flex;align-items:center;" title="Edit contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg></a>
                            
                            <form method="POST" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="toggle_contact" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="Toggle status" style="height:32px;border:none;display:flex;align-items:center;">
                                    <?php echo $c['status'] === 'active' ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polygon points="6 3 20 12 6 21 6 3"/></svg>'; ?>
                                </button>
                            </form>

                            <form method="POST" style="margin:0;" data-confirm="Delete this contact?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_contact" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete contact" style="height:32px;border:none;display:flex;align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
                            </form>
                        </div>
                    </td>
                </tr>
                    <?php endif; ?>
                <?php endwhile; ?>
            <?php
else: ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="48" height="48"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                            <p>No contacts found. Add your first contact or import from CSV!</p>
                            <a href="contact_add.php" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Contact</a>
                        </div>
                    </td>
                </tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?php echo (int)($page - 1); ?>&search=<?php echo urlencode($search); ?>&tag=<?php echo urlencode($tagFilter); ?>&status=<?php echo urlencode($statusFilter); ?>" class="page-link">← Prev</a>
    <?php endif; ?>
    
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?php echo (int)$i; ?>&search=<?php echo urlencode($search); ?>&tag=<?php echo urlencode($tagFilter); ?>&status=<?php echo urlencode($statusFilter); ?>" 
           class="page-link <?php echo ($i === $page ? 'active' : ''); ?>"><?php echo (int)$i; ?></a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo (int)($page + 1); ?>&search=<?php echo urlencode($search); ?>&tag=<?php echo urlencode($tagFilter); ?>&status=<?php echo urlencode($statusFilter); ?>" class="page-link">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
