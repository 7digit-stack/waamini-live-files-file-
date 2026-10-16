<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Order Tracking Bot';
$currentPage = 'settings_order_tracking';

// Migration handled schema change

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();
    $kw     = sanitize($_POST['trigger_keyword']) ?: 'order';
    $msg    = trim($_POST['tracking_message']);
    $notfnd = trim($_POST['not_found_message']);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $stmt   = $conn->prepare("INSERT INTO order_tracking_settings (company_id, is_active, trigger_keyword, tracking_message, not_found_message) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), trigger_keyword=VALUES(trigger_keyword), tracking_message=VALUES(tracking_message), not_found_message=VALUES(not_found_message)");
    $stmt->bind_param("iisss", $companyId, $active, $kw, $msg, $notfnd);
    $stmt->execute();
    header("Location: settings_order_tracking.php?msg=saved");
    exit();
}

$stmt_s = $conn->prepare("SELECT * FROM order_tracking_settings WHERE company_id=?");
$stmt_s->bind_param("i", $companyId);
$stmt_s->execute();
$settings = $stmt_s->get_result()->fetch_assoc();

$defaultMsg = "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><line x1=\"16.5\" y1=\"9.4\" x2=\"7.5\" y2=\"4.21\"/><path d=\"M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z\"/><polyline points=\"3.27 6.96 12 12.01 20.73 6.96\"/><line x1=\"12\" y1=\"22.08\" x2=\"12\" y2=\"12\"/></svg> *Order Status for {{order_number}}*\n\nStatus: *{{status}}*\n{{#tracking_url}}<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><rect x=\"1\" y=\"3\" width=\"15\" height=\"13\"/><polygon points=\"16 8 20 8 23 11 23 16 16 16 16 8\"/><circle cx=\"5.5\" cy=\"18.5\" r=\"2.5\"/><circle cx=\"18.5\" cy=\"18.5\" r=\"2.5\"/></svg> Track: {{tracking_url}}\n{{/tracking_url}}{{#estimated_delivery}}<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"/><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"/><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"/></svg> Est. Delivery: {{estimated_delivery}}\n{{/estimated_delivery}}{{#status_notes}}<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#016df8\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"12\" y1=\"8\" x2=\"12\" y2=\"12\"/><line x1=\"12\" y1=\"16\" x2=\"12.01\" y2=\"16\"/></svg> {{status_notes}}\n{{/status_notes}}\nThank you for shopping with us! <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><path d=\"M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z\"/></svg>";
$defaultNotFound = "Sorry, we couldn't find order *{{query}}*. Please check your order number and try again, or contact support.";

include '../includes/client_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Settings saved!</div>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Order Tracking Bot</h2>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div class="form-card" style="max-width:none;">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Enable Order Tracking Bot</label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo ($settings['is_active'] ?? 1) ? 'checked' : ''; ?>>
                    <label for="is_active" style="margin:0;font-size:13px;">Active</label>
                </div>
            </div>
            <div class="form-group">
                <label>Trigger Keyword</label>
                <input type="text" name="trigger_keyword" class="form-control" value="<?php echo htmlspecialchars($settings['trigger_keyword'] ?? 'order'); ?>" placeholder="order">
                <small style="color:var(--text-muted);">Customer sends "order ORD-000123" → bot replies with status</small>
            </div>
            <div class="form-group">
                <label>Order Found Message Template</label>
                <textarea name="tracking_message" class="form-control" rows="8"><?php echo htmlspecialchars($settings['tracking_message'] ?? $defaultMsg); ?></textarea>
                <small style="color:var(--text-muted);">Variables: {{order_number}}, {{status}}, {{tracking_url}}, {{estimated_delivery}}, {{status_notes}}, {{customer_name}}</small>
            </div>
            <div class="form-group">
                <label>Order Not Found Message</label>
                <textarea name="not_found_message" class="form-control" rows="3"><?php echo htmlspecialchars($settings['not_found_message'] ?? $defaultNotFound); ?></textarea>
                <small style="color:var(--text-muted);">Variables: {{query}}</small>
            </div>
            <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Settings</button>
        </form>
    </div>

    <div>
        <div class="form-card" style="max-width:none;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> How it Works</h3>
            <ul style="font-size:13px;color:var(--text-secondary);line-height:2;list-style:none;padding:0;">
                <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Customer sends: <code>order ORD-000123</code></li>
                <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg> Bot looks up the order in your database</li>
                <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Replies with real-time order status</li>
                <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Includes tracking link if available</li>
            </ul>
        </div>
        <div class="form-card" style="max-width:none;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Recent Orders</h3>
            <?php
            $stmt_ro = $conn->prepare("SELECT id AS order_number, buyer_name AS customer_name, status, created_at FROM ecommerce_orders WHERE company_id=? ORDER BY id DESC LIMIT 5");
            $stmt_ro->bind_param("i", $companyId);
            $stmt_ro->execute();
            $recentOrders = $stmt_ro->get_result();
            ?>
            <table style="width:100%;font-size:12px;">
                <thead><tr style="color:var(--text-muted);"><th style="text-align:left;padding:4px 0;">Order #</th><th>Customer</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($o = $recentOrders->fetch_assoc()): ?>
                <tr>
                    <td style="padding:4px 0;font-weight:600;"><?php echo htmlspecialchars($o['order_number'] ?? '—'); ?></td>
                    <td style="color:var(--text-secondary);"><?php echo htmlspecialchars($o['customer_name'] ?? '—'); ?></td>
                    <td><span style="font-size:10px;padding:2px 8px;border-radius:10px;background:rgba(0,184,148,0.1);color:var(--accent);"><?php echo ucfirst($o['status']); ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/client_footer.php'; ?>
