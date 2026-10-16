<?php
include '../includes/client_auth_check.php';
require_once __DIR__.'/../includes/whatsapp_settings.php';
$pageTitle = 'Stock Alert Bot';
$currentPage = 'stock_alerts';

// Migration handled schema change

// Migration handled schema change

// Handle product add/edit
if (isset($_POST['save_product'])) {
    csrf_verify();
    $pid = intval($_POST['product_id']??0);
    $name = sanitize($_POST['name']); $sku = sanitize($_POST['sku']??'');
    $price = floatval($_POST['price']??0); $qty = intval($_POST['stock_qty']??0);
    $imgUrl = sanitize($_POST['image_url']??'');
    if ($pid) {
        $stmt = $conn->prepare("UPDATE products_catalog SET name=?,sku=?,price=?,stock_qty=?,image_url=? WHERE id=? AND company_id=?");
        $stmt->bind_param("ssdisii",$name,$sku,$price,$qty,$imgUrl,$pid,$companyId);
    } else {
        $stmt = $conn->prepare("INSERT INTO products_catalog (company_id,name,sku,price,stock_qty,image_url) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("issdis",$companyId,$name,$sku,$price,$qty,$imgUrl);
    }
    $stmt->execute();
    // If stock > 0, notify waitlist
    if ($qty > 0) {
        $insertId = $pid ?: $stmt->insert_id;
        // Notify all waitlisted
        $stmt_wl = $conn->prepare("SELECT sw.*, c.phone, c.name as cname, pc.name as pname, pc.price as pprice FROM stock_waitlist sw JOIN contacts c ON sw.contact_id=c.id JOIN products_catalog pc ON sw.product_id=pc.id WHERE sw.product_id=? AND sw.company_id=? AND sw.notified=0");
        $stmt_wl->bind_param("ii",$insertId,$companyId); $stmt_wl->execute();
        $wl = $stmt_wl->get_result();
        $stmt_wc = $conn->prepare("SELECT access_token,phone_number_id,api_version FROM whatsapp_config WHERE company_id=? AND status='active' LIMIT 1");
        $stmt_wc->bind_param("i",$companyId); $stmt_wc->execute();
        $wc = $stmt_wc->get_result()->fetch_assoc();
        if ($wc) {
            while ($w = $wl->fetch_assoc()) {
                $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Good news, ' . explode(' ',$w['cname'])[0] . "!\n\n*{$w['pname']}* is back in stock!\n" . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Price: ' . "\xe2\x82\xb9" . number_format($w['pprice'],2) . "\n\nReply *ORDER* to buy now! " . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>';
                $apiVer = $wc['api_version']?:'v18.0';
                $accessToken = waamini_whatsapp_decrypt_config_token($wc);
                $payload = json_encode(['messaging_product'=>'whatsapp','to'=>$w['phone'],'type'=>'text','text'=>['body'=>$msg]]);
                $ch = curl_init("https://graph.facebook.com/{$apiVer}/{$wc['phone_number_id']}/messages");
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Content-Type: application/json']]);
                $r = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if ($code===200) {
                    $stmt_n = $conn->prepare("UPDATE stock_waitlist SET notified=1, notified_at=NOW() WHERE id=? AND company_id=?");
                    $wid = (int)$w['id'];
                    $stmt_n->bind_param("ii", $wid, $companyId);
                    $stmt_n->execute();
                }
                usleep(300000);
            }
        }
    }
    header("Location: stock_alerts.php?msg=saved"); exit();
}

// Handle delete
if (isset($_POST['delete_product'])) {
    csrf_verify();
    $pid = intval($_POST['product_id']);
    $stmt_del = $conn->prepare("DELETE FROM products_catalog WHERE id=? AND company_id=?");
    $stmt_del->bind_param("ii", $pid, $companyId);
    $stmt_del->execute();
    header("Location: stock_alerts.php");
    exit();
}

$stmt_p = $conn->prepare("SELECT pc.*, (SELECT COUNT(*) FROM stock_waitlist sw WHERE sw.product_id=pc.id AND sw.notified=0) as waitlist_count FROM products_catalog pc WHERE pc.company_id=? ORDER BY pc.name");
$stmt_p->bind_param("i",$companyId); $stmt_p->execute();
$products = $stmt_p->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved <?php echo isset($_GET['notified']) ? '& '.intval($_GET['notified']).' customers notified!' : ''; ?></div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Stock Alert Bot</h2>
    <button onclick="document.getElementById('addProductModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Product</button>
</div>

<div class="data-table-wrapper">
<table class="data-table">
    <thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Stock</th><th>Waitlist</th><th>Actions</th></tr></thead>
    <tbody>
    <?php while ($p = $products->fetch_assoc()): ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
        <td style="color:var(--text-muted);"><?php echo htmlspecialchars($p['sku']??'—'); ?></td>
        <td>₹<?php echo number_format($p['price'],2); ?></td>
        <td>
            <?php if ($p['stock_qty']<=0): ?><span style="color:#e74c3c;font-weight:700;">Out of Stock</span>
            <?php elseif ($p['stock_qty']<=5): ?><span style="color:#f39c12;font-weight:700;"><?php echo $p['stock_qty']; ?> left <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
            <?php else: ?><span style="color:#00b894;font-weight:700;"><?php echo $p['stock_qty']; ?> in stock</span>
            <?php endif; ?>
        </td>
        <td><?php if ($p['waitlist_count']>0): ?><span style="color:#6c63ff;font-weight:700;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> <?php echo $p['waitlist_count']; ?> waiting</span><?php else: ?>—<?php endif; ?></td>
        <td>
            <button onclick="editProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit</button>
            <form method="POST" style="display:inline;"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><?php echo csrf_field(); ?><button name="delete_product" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button></form>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>

<!-- Webhook info -->
<div style="margin-top:20px;padding:16px;background:rgba(108,99,255,0.05);border:1px solid rgba(108,99,255,0.15);border-radius:12px;font-size:13px;">
    <strong><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Stock Update API</strong> — Connect your website/POS:<br>
    <code style="font-size:11px;">POST /api/stock_update.php?api_key=YOUR_KEY {"sku":"PROD001","qty":50}</code>
</div>

<!-- Add/Edit Product Modal -->
<div id="addProductModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:24px;width:450px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;" id="productModalTitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Product</h3>
    <form method="POST">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="product_id" id="editProductId" value="0">
    <div class="form-group"><label>Product Name *</label><input type="text" name="name" id="editProductName" class="form-control" required></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label>SKU</label><input type="text" name="sku" id="editProductSku" class="form-control"></div>
        <div class="form-group"><label>Price (₹)</label><input type="number" name="price" id="editProductPrice" class="form-control" step="0.01" min="0"></div>
    </div>
    <div class="form-group"><label>Stock Qty</label><input type="number" name="stock_qty" id="editProductQty" class="form-control" min="0"></div>
    <div class="form-group"><label>Image URL (optional)</label><input type="text" name="image_url" id="editProductImg" class="form-control"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('addProductModal').style.display='none'" class="btn btn-edit">Cancel</button>
        <button type="submit" name="save_product" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </div>
    </form>
</div>
</div>
<script>
function editProduct(p) {
    document.getElementById('addProductModal').style.display='flex';
    document.getElementById('productModalTitle').textContent = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Edit Product';
    document.getElementById('editProductId').value = p.id;
    document.getElementById('editProductName').value = p.name;
    document.getElementById('editProductSku').value = p.sku||'';
    document.getElementById('editProductPrice').value = p.price;
    document.getElementById('editProductQty').value = p.stock_qty;
    document.getElementById('editProductImg').value = p.image_url||'';
}
</script>
<?php include '../includes/client_footer.php'; ?>
