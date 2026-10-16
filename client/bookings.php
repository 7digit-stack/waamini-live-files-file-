<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Bookings';
$currentPage = 'bookings';

// Status update
if (isset($_POST['update_booking'])) {
    csrf_verify();
    $bid = intval($_POST['booking_id']);
    $st  = in_array($_POST['status'], ['pending','confirmed','cancelled','completed']) ? $_POST['status'] : 'confirmed';
    $stmt_u = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND company_id=?");
    $stmt_u->bind_param("sii", $st, $bid, $companyId);
    $stmt_u->execute();
    header("Location: bookings.php?msg=updated"); exit();
}

$dateFilter = sanitize($_GET['date'] ?? date('Y-m-d'));
$stmt_b = $conn->prepare("SELECT b.*, c.name as contact_name, c.phone FROM bookings b JOIN contacts c ON b.contact_id=c.id WHERE b.company_id=? AND b.booking_date=? ORDER BY b.booking_time ASC");
$bookings = null;
if ($stmt_b) {
    $stmt_b->bind_param("is", $companyId, $dateFilter);
    $stmt_b->execute();
    $bookings = $stmt_b->get_result();
} else {
    error_log('[WAAMINI] bookings.php: prepare() failed for bookings list (table missing?)');
}

$stmt_up = $conn->prepare("SELECT COUNT(*) as c FROM bookings WHERE company_id=? AND booking_date >= CURDATE() AND status='confirmed'");
$upcomingCount = 0;
if ($stmt_up) {
    $stmt_up->bind_param("i", $companyId);
    $stmt_up->execute();
    $upcomingCount = (int)($stmt_up->get_result()->fetch_assoc()['c'] ?? 0);
} else {
    error_log('[WAAMINI] bookings.php: prepare() failed for upcoming count (table missing?)');
}

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Booking updated!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Bookings</h2>
    <a href="settings_booking.php" class="btn btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Bot Settings</a>
</div>
<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="date" name="date" class="form-control" value="<?php echo $dateFilter; ?>" style="width:auto;" onchange="this.form.submit()">
    </form>
    <a href="bookings.php?date=<?php echo date('Y-m-d',strtotime('-1 day',strtotime($dateFilter))); ?>" class="btn btn-sm btn-edit">← Prev</a>
    <a href="bookings.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-edit">Today</a>
    <a href="bookings.php?date=<?php echo date('Y-m-d',strtotime('+1 day',strtotime($dateFilter))); ?>" class="btn btn-sm btn-edit">Next →</a>
    <span style="font-size:13px;color:var(--text-muted);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> <?php echo $upcomingCount; ?> upcoming confirmed</span>
</div>
<div class="data-table-wrapper">
<table class="data-table">
    <thead><tr><th>Time</th><th>Customer</th><th>Phone</th><th>Notes</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$bookings || $bookings->num_rows === 0): ?>
    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">No bookings for <?php echo date('d M Y', strtotime($dateFilter)); ?></td></tr>
    <?php else: while ($b = $bookings->fetch_assoc()):
        $stColors = ['pending'=>'#fdcb6e','confirmed'=>'#00b894','cancelled'=>'#e74c3c','completed'=>'#74b9ff'];
        $sc = $stColors[$b['status']] ?? '#95a5a6';
    ?>
    <tr>
        <td style="font-weight:700;"><?php echo date('g:i A', strtotime($b['booking_time'])); ?></td>
        <td><?php echo htmlspecialchars($b['contact_name']); ?></td>
        <td style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($b['phone']); ?></td>
        <td style="font-size:12px;"><?php echo htmlspecialchars($b['notes'] ?? '—'); ?></td>
        <td><span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:<?php echo $sc; ?>20;color:<?php echo $sc; ?>;"><?php echo ucfirst($b['status']); ?></span></td>
        <td>
            <form method="POST" style="display:flex;gap:4px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                <select name="status" class="form-control" style="padding:4px 8px;font-size:11px;height:auto;">
                    <?php foreach (['pending','confirmed','cancelled','completed'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $st===$b['status']?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="update_booking" class="btn btn-sm btn-edit">✓</button>
            </form>
        </td>
    </tr>
    <?php endwhile; endif; ?>
    </tbody>
</table>
</div>
<?php include '../includes/client_footer.php'; ?>
