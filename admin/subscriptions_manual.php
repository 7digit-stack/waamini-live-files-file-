<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Manual Subscriptions';
$currentPage = 'subscriptions';

function waamini_bind_int_params(mysqli_stmt $stmt, array $ints): bool {
    $ints = array_values($ints);
    if ($ints === []) {
        return true;
    }
    $types = str_repeat('i', count($ints));
    $params = [$types];
    foreach ($ints as $k => $_) {
        $params[] = &$ints[$k];
    }
    return call_user_func_array([$stmt, 'bind_param'], $params);
}

function safe_count(mysqli $db, string $sql): int {
    try {
        $r = $db->query($sql);
        return ($r && $row = $r->fetch_assoc()) ? (int)($row['c'] ?? 0) : 0;
    } catch (Throwable $e) {
        error_log('Manual subscriptions page count query failed: ' . $e->getMessage());
        return 0;
    }
}

function safe_sum(mysqli $db, string $sql): string {
    try {
        $r = $db->query($sql);
        return ($r && $row = $r->fetch_assoc()) ? (string)($row['t'] ?? '0') : '0';
    } catch (Throwable $e) {
        error_log('Manual subscriptions page sum query failed: ' . $e->getMessage());
        return '0';
    }
}

// Whitelist status to prevent SQL injection
$_allowedStatuses = ['active', 'expired', 'pending', 'cancelled'];
$filterStatus = in_array($_GET['status'] ?? '', $_allowedStatuses, true) ? ($_GET['status']) : '';
$filterQuery = $filterStatus !== '' ? 'status=' . urlencode($filterStatus) : '';

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    csrf_verify();
    $idsRaw = $_POST['ids'] ?? [];
    if (!is_array($idsRaw)) {
        $idsRaw = [];
    }
    $ids = [];
    foreach ($idsRaw as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));

    $deleted = 0;
    $status = 'noop';

    if ($ids !== []) {
        try {
            $conn->begin_transaction();

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $conn->prepare("DELETE FROM subscription_payments WHERE subscription_id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
            }

            $stmt = $conn->prepare("DELETE FROM subscription_reminders WHERE subscription_id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
            }

            $stmt = $conn->prepare("DELETE FROM subscriptions WHERE id IN ($placeholders)");
            if ($stmt && waamini_bind_int_params($stmt, $ids)) {
                $stmt->execute();
                $deleted = (int)$stmt->affected_rows;
            }

            $conn->commit();
            $status = $deleted > 0 ? 'bulk_deleted' : 'noop';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Manual subscriptions bulk delete failed: ' . $e->getMessage());
            $status = 'bulk_failed';
        }
    }

    $redirect = 'subscriptions_manual.php';
    if ($filterQuery !== '') {
        $redirect .= '?' . $filterQuery . '&';
    } else {
        $redirect .= '?';
    }
    $redirect .= 'msg=' . urlencode($status) . '&n=' . $deleted;
    header('Location: ' . $redirect);
    exit();
}

$where = "s.type='manual'";
$whereParams = [];
$whereTypes = '';
if ($filterStatus) {
    $where .= " AND s.status=?";
    $whereParams[] = $filterStatus;
    $whereTypes .= 's';
}

$totalManual = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE type='manual'");
$totalActive = safe_count($conn, "SELECT COUNT(*) as c FROM subscriptions WHERE type='manual' AND status='active'");
$totalAmount = safe_sum($conn, "SELECT COALESCE(SUM(amount),0) as t FROM subscriptions WHERE type='manual' AND status='active'");

$subsStmt = $conn->prepare("
    SELECT s.*, p.name as plan_name, c.name as company_name
    FROM subscriptions s
    JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN companies c ON s.company_id = c.id
    WHERE $where
    ORDER BY s.created_at DESC
");
if ($subsStmt) {
    if ($whereParams !== []) {
        $subsStmt->bind_param($whereTypes, ...$whereParams);
    }
    $subsStmt->execute();
    $subs = $subsStmt->get_result();
} else {
    $subs = null;
}

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <?php
    $msg = (string)$_GET['msg'];
    $count = isset($_GET['n']) ? (int)$_GET['n'] : 0;
    if ($msg === 'bulk_deleted') {
        $message = $count > 0 ? 'Deleted ' . $count . ' subscription' . ($count === 1 ? '' : 's') . '.' : 'No subscriptions were deleted.';
        $alertClass = 'alert-success';
    } elseif ($msg === 'bulk_failed') {
        $message = 'Bulk delete failed. Nothing was removed.';
        $alertClass = 'alert-danger';
    } else {
        $message = 'Action completed.';
        $alertClass = 'alert-success';
    }
    ?>
    <div class="alert <?php echo $alertClass; ?> animate-fade-in" role="status">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>


<div class="page-header">
    <div class="header-content">
        <h2><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0;"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Manual / Prepaid Subscriptions</h2>
        <p style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">Track and manage non-automated billing and prepaid credits.</p>
    </div>
    <a href="subscription_add.php?type=manual" class="btn btn-primary shadow-lg"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Manual Subscription</a>
</div>

<!-- Stats -->
<div class="stats-grid animate-fade-in" style="margin-bottom:32px;">
    <div class="stat-card glass-card">
        <div class="stat-icon purple" style="background:rgba(108,92,231,0.15); color: var(--accent);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg></div>
        <div class="stat-value"><?php echo $totalManual; ?></div>
        <div class="stat-label">Total Records</div>
    </div>
    <div class="stat-card glass-card">
        <div class="stat-icon green" style="background:rgba(0,184,148,0.15); color: var(--success);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg></div>
        <div class="stat-value"><?php echo $totalActive; ?></div>
        <div class="stat-label">Currently Active</div>
    </div>
    <div class="stat-card glass-card">
        <div class="stat-icon orange" style="background:rgba(253,203,110,0.15); color: var(--warning);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="stat-value">₹<?php echo number_format($totalAmount); ?></div>
        <div class="stat-label">Manual Revenue</div>
    </div>
</div>

<div class="glass-card">
    <!-- Filters -->
    <div class="table-header" style="border-bottom: none; padding-bottom: 30px;">
        <div>
            <h3 style="font-size: 18px; font-weight: 700;">Payment History</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Register of manual transactions and settlements</p>
        </div>
        <form method="GET" style="display:flex; gap:12px; align-items:center;">
            <div class="search-container" style="max-width: 200px;">
                <select name="status" class="form-control js-autosubmit" style="border-radius: 30px; padding-left: 15px;">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expired" <?php echo $filterStatus === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>
        </form>
    </div>

    <form method="POST" action="subscriptions_manual.php<?php echo $filterQuery !== '' ? '?' . htmlspecialchars($filterQuery, ENT_QUOTES, 'UTF-8') : ''; ?>" id="bulkDeleteForm" class="subscriptions-bulk-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="bulk_delete" value="1">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="subscriptions-bulk-bar" id="bulkBar" aria-live="polite">
            <div>
                <strong id="bulkSelectedLabel">0</strong>
                <span style="color: var(--text-muted); font-size: 13px;"> selected</span>
            </div>
            <button type="submit" class="btn btn-sm btn-danger js-bulk-delete-btn">Delete selected</button>
        </div>
    </form>

    <!-- Table -->
    <div class="table-responsive">
        <table class="premium-table">
            <thead>
                <tr>
                    <th style="width:48px; text-align:center;">
                        <input type="checkbox" id="selectAllSubscriptions" aria-label="Select all subscriptions" title="Select all">
                    </th>
                    <th>Vendor / ID</th>
                    <th>Plan / Amount</th>
                    <th>Payment Details</th>
                    <th>Status</th>
                    <th>Period</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($subs && $subs->num_rows > 0): ?>
                <?php while ($s = $subs->fetch_assoc()):
        $statusBadges = ['active' => 'active', 'expired' => 'suspended', 'pending' => 'pending', 'cancelled' => 'suspended'];
        $dotClass = isset($statusBadges[$s['status']]) ? $statusBadges[$s['status']] : 'pending';
?>
                <tr>
                    <td style="text-align:center; vertical-align:middle;">
                        <input type="checkbox" form="bulkDeleteForm" class="js-sub-check" name="ids[]" value="<?php echo (int)$s['id']; ?>" aria-label="Select subscription #<?php echo (int)$s['id']; ?>">
                    </td>
                    <td>
                        <div style="font-weight: 700; color: var(--text-primary); font-size: 14px;"><?php echo htmlspecialchars($s['company_name'] ?? 'N/A'); ?></div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">Transaction #<?php echo $s['id']; ?></div>
                    </td>
                    <td>
                        <span class="tag-badge" style="background: rgba(108, 92, 231, 0.1); color: var(--accent); margin-bottom: 6px; display: inline-block;">
                            <?php echo htmlspecialchars($s['plan_name']); ?>
                        </span>
                        <div style="font-weight: 700; color: var(--text-primary);">₹<?php echo number_format($s['amount']); ?></div>
                    </td>
                    <td>
                        <div style="font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars(ucfirst($s['payment_method'])); ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($s['payment_ref'] ?? 'No Reference'); ?></div>
                    </td>
                    <td>
                        <div class="status-indicator">
                            <span class="status-dot <?php echo $dotClass; ?>"></span>
                            <span style="text-transform: capitalize;"><?php echo $s['status']; ?></span>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 12px; color: var(--text-secondary);">
                            <?php echo date('d M Y', strtotime($s['starts_at'])); ?> <br>
                            <span style="opacity: 0.5;">to</span> <?php echo $s['expires_at'] ? date('d M Y', strtotime($s['expires_at'])) : '∞'; ?>
                        </div>
                    </td>
                    <td>
                        <a href="subscription_view.php?id=<?php echo $s['id']; ?>" class="action-pill login" style="padding: 6px 12px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg> View
                        </a>
                    </td>
                </tr>
                <?php
    endwhile; ?>
                <?php
else: ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="icon" style="font-size: 50px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="50" height="50"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                            <p>No manual records found.</p>
                        </div>
                    </td>
                </tr>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.subscriptions-bulk-form {
    padding: 0 24px 12px;
}
.subscriptions-bulk-bar {
    display: none;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 16px;
    margin-bottom: 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 107, 107, 0.22);
    background: linear-gradient(90deg, rgba(255, 71, 87, 0.12), rgba(108, 92, 231, 0.08));
}
.subscriptions-bulk-bar.is-visible {
    display: flex;
}
.premium-table th:first-child,
.premium-table td:first-child {
    width: 48px;
    text-align: center;
}
.premium-table input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--accent);
    cursor: pointer;
}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Auto-submit selects (CSP-safe, replaces inline onchange)
document.querySelectorAll('select.js-autosubmit').forEach(function(sel) {
    sel.addEventListener('change', function() { this.form.submit(); });
});

(function() {
    var rows = document.querySelectorAll('.js-sub-check');
    var selectAll = document.getElementById('selectAllSubscriptions');
    var bulkBar = document.getElementById('bulkBar');
    var bulkLabel = document.getElementById('bulkSelectedLabel');
    var bulkBtn = document.querySelector('.js-bulk-delete-btn');

    function checkedCount() {
        return document.querySelectorAll('.js-sub-check:checked').length;
    }

    function syncBulkBar() {
        var count = checkedCount();
        if (bulkLabel) bulkLabel.textContent = String(count);
        if (bulkBar) bulkBar.classList.toggle('is-visible', count > 0);
        if (selectAll) {
            var total = rows.length;
            var allChecked = total > 0 && count === total;
            selectAll.checked = allChecked;
            selectAll.indeterminate = count > 0 && !allChecked;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var on = selectAll.checked;
            rows.forEach(function(cb) {
                cb.checked = on;
            });
            syncBulkBar();
        });
    }

    rows.forEach(function(cb) {
        cb.addEventListener('change', syncBulkBar);
    });

    if (bulkBtn) {
        bulkBtn.addEventListener('click', function(e) {
            var count = checkedCount();
            if (count === 0) {
                e.preventDefault();
                return;
            }
            if (!confirm('Permanently delete ' + count + ' subscription(s)? Related payment and reminder logs will also be removed.')) {
                e.preventDefault();
            }
        });
    }

    syncBulkBar();
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
