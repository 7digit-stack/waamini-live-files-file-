<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Coupon Manager';
$currentPage = 'coupons';

// Handle Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_coupon'])) {
    csrf_verify();
    $code = strtoupper(sanitize($_POST['code']));
    $type = $_POST['type'];
    $val = floatval($_POST['value']);
    $expiry = $_POST['expiry'];
    $limit = intval($_POST['max_uses']);
    
    $stmt = $conn->prepare("INSERT INTO coupons (code, type, value, expires_at, max_uses) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsi", $code, $type, $val, $expiry, $limit);
    $stmt->execute();
    header("Location: coupons.php?msg=created");
    exit();
}

// Fetch Coupons
$coupons = $conn->query("SELECT * FROM coupons ORDER BY id DESC");

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg> Coupon Forge</h2>
    <button class="btn btn-primary" onclick="document.getElementById('couponModal').style.display='flex'">+ New Coupon</button>
</div>

<div class="glass-card" style="padding:0; overflow:hidden;">
    <table class="premium-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Discount</th>
                <th>Usage</th>
                <th>Expiry</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php while($c = $coupons->fetch_assoc()): ?>
            <tr>
                <td><strong style="letter-spacing:1px; color:var(--rev-accent);"><?php echo $c['code']; ?></strong></td>
                <td><?php echo $c['type'] === 'percentage' ? $c['value'].'%' : '₹'.$c['value']; ?></td>
                <td><?php echo $c['used_count']; ?> / <?php echo $c['max_uses'] ?: '∞'; ?></td>
                <td style="font-size:12px;"><?php echo date('M d, Y', strtotime($c['expires_at'])); ?></td>
                <td>
                    <span class="badge <?php echo $c['status'] === 'active' ? 'badge-active' : 'badge-suspended'; ?>">
                        <?php echo strtoupper($c['status']); ?>
                    </span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Simple Modal -->
<div id="couponModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:1000; align-items:center; justify-content:center;">
    <div class="glass-card" style="width:400px; padding:30px;">
        <h3 style="margin-top:0;">Create Coupon</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Code</label>
                <input type="text" name="code" class="form-control" required placeholder="SAVE50">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed Amount</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value</label>
                    <input type="number" name="value" class="form-control" step="0.01" required>
                </div>
            </div>
            <div class="form-group">
                <label>Expiry Date</label>
                <input type="date" name="expiry" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Max Uses (0 = unlimited)</label>
                <input type="number" name="max_uses" class="form-control" value="0">
            </div>
            <button type="submit" name="create_coupon" class="btn btn-primary" style="width:100%; margin-top:15px;">Forgre Coupon</button>
            <button type="button" class="btn btn-sm btn-edit" style="width:100%; margin-top:10px;" onclick="document.getElementById('couponModal').style.display='none'">Cancel</button>
        </form>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
