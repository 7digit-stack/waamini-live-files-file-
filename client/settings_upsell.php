<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Upsell Bot';
$currentPage = 'settings_upsell';

// Migration handled schema change
// Migration handled schema change

if (isset($_POST['save_settings'])) {
    csrf_verify();
    $active = isset($_POST['is_active']) ? 1 : 0;
    $delay  = max(0, intval($_POST['delay_minutes']??30));
    $msg    = trim($_POST['message']);
    $stmt   = $conn->prepare("INSERT INTO upsell_settings (company_id,is_active,delay_minutes,message) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),delay_minutes=VALUES(delay_minutes),message=VALUES(message)");
    $stmt->bind_param("iiis",$companyId,$active,$delay,$msg);
    $stmt->execute();
    header("Location: settings_upsell.php?msg=saved"); exit();
}
if (isset($_POST['add_product'])) {
    csrf_verify();
    $name = sanitize($_POST['name']); $price = floatval($_POST['price']); $desc = sanitize($_POST['description']??'');
    if ($name && $price > 0) {
        $stmt = $conn->prepare("INSERT INTO upsell_products (company_id,name,price,description) VALUES (?,?,?,?)");
        $stmt->bind_param("isds",$companyId,$name,$price,$desc);
        $stmt->execute();
    }
    header("Location: settings_upsell.php"); exit();
}
if (isset($_POST['delete_product'])) {
    csrf_verify();
    $did = intval($_POST['product_id']);
    $stmt = $conn->prepare("DELETE FROM upsell_products WHERE id=? AND company_id=?");
    $stmt->bind_param("ii",$did,$companyId); $stmt->execute();
    header("Location: settings_upsell.php"); exit();
}

$stmt_s = $conn->prepare("SELECT * FROM upsell_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$settings = $stmt_s->get_result()->fetch_assoc();

$stmt_p = $conn->prepare("SELECT * FROM upsell_products WHERE company_id=? AND is_active=1 ORDER BY sort_order,id");
$stmt_p->bind_param("i",$companyId); $stmt_p->execute();
$products = $stmt_p->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m18 15-6-6-6 6"/></svg> Upsell Bot</h2></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div>
        <div class="form-card" style="max-width:none;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Settings</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="is_active" id="ua" <?php echo ($settings['is_active']??1)?'checked':''; ?>>
                    <label for="ua" style="margin:0;font-size:13px;font-weight:600;">Enable after every paid order</label>
                </div>
                <div class="form-group"><label>Send after (minutes)</label><input type="number" name="delay_minutes" class="form-control" value="<?php echo $settings['delay_minutes']??30; ?>" min="0"></div>
                <div class="form-group"><label>Message Template</label>
                    <textarea name="message" class="form-control" rows="5"><?php echo htmlspecialchars($settings['message']??"Thanks for your order, {{first_name}}! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><path d='M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'/></svg>\n\nYou might also love:\n\n{{products}}\n\nReply to order!"); ?></textarea>
                    <small style="color:var(--text-muted);">Use {{first_name}}, {{products}}</small>
                </div>
                <button type="submit" name="save_settings" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
            </form>
        </div>
        <div class="form-card" style="max-width:none;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Upsell Product</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group"><label>Product Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label>Price (₹)</label><input type="number" name="price" class="form-control" step="0.01" required></div>
                <div class="form-group"><label>Short Description</label><input type="text" name="description" class="form-control" placeholder="Optional"></div>
                <button type="submit" name="add_product" class="btn btn-primary btn-sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add</button>
            </form>
        </div>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Upsell Products</h3>
        <?php while ($p = $products->fetch_assoc()): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px;background:var(--bg-input);border-radius:8px;margin-bottom:8px;">
            <div style="flex:1;"><strong style="font-size:13px;"><?php echo htmlspecialchars($p['name']); ?></strong><br><span style="font-size:12px;color:var(--accent);">₹<?php echo number_format($p['price'],2); ?></span><?php if($p['description']): ?><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($p['description']); ?></small><?php endif; ?></div>
            <form method="POST"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><?php echo csrf_field(); ?><button type="submit" name="delete_product" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button></form>
        </div>
        <?php endwhile; ?>
    </div>
</div>
<?php include '../includes/client_footer.php'; ?>
