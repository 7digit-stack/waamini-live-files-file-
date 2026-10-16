<?php
include '../includes/client_auth_check.php';
$pageTitle = 'GST Invoice Settings';
$currentPage = 'settings_gst';

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS gst_settings (company_id INT PRIMARY KEY, business_name VARCHAR(255), gstin VARCHAR(50), address TEXT, city VARCHAR(100), state VARCHAR(100), pincode VARCHAR(20), phone VARCHAR(20), email VARCHAR(100), logo_url TEXT, bank_name VARCHAR(255), account_number VARCHAR(50), ifsc_code VARCHAR(20), upi_id VARCHAR(100), invoice_prefix VARCHAR(20) DEFAULT 'INV', tax_type ENUM('GST', 'IGST', 'None') DEFAULT 'GST', cgst_pct DECIMAL(5,2) DEFAULT 9.00, sgst_pct DECIMAL(5,2) DEFAULT 9.00, igst_pct DECIMAL(5,2) DEFAULT 18.00, footer_note TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();
    $fields = ['business_name','gstin','address','city','state','pincode','phone','email','logo_url','bank_name','account_number','ifsc_code','upi_id','invoice_prefix','footer_note'];
    $vals = []; foreach ($fields as $f) $vals[$f] = sanitize($_POST[$f] ?? '');
    $taxType = in_array($_POST['tax_type'],['GST','IGST','None']) ? $_POST['tax_type'] : 'GST';
    $cgst = floatval($_POST['cgst_pct'] ?? 9);
    $sgst = floatval($_POST['sgst_pct'] ?? 9);
    $igst = floatval($_POST['igst_pct'] ?? 18);

    $stmt = $conn->prepare("INSERT INTO gst_settings (company_id,business_name,gstin,address,city,state,pincode,phone,email,logo_url,bank_name,account_number,ifsc_code,upi_id,invoice_prefix,tax_type,cgst_pct,sgst_pct,igst_pct,footer_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name),gstin=VALUES(gstin),address=VALUES(address),city=VALUES(city),state=VALUES(state),pincode=VALUES(pincode),phone=VALUES(phone),email=VALUES(email),logo_url=VALUES(logo_url),bank_name=VALUES(bank_name),account_number=VALUES(account_number),ifsc_code=VALUES(ifsc_code),upi_id=VALUES(upi_id),invoice_prefix=VALUES(invoice_prefix),tax_type=VALUES(tax_type),cgst_pct=VALUES(cgst_pct),sgst_pct=VALUES(sgst_pct),igst_pct=VALUES(igst_pct),footer_note=VALUES(footer_note)");
    $stmt->bind_param("isssssssssssssssddds", $companyId, $vals['business_name'],$vals['gstin'],$vals['address'],$vals['city'],$vals['state'],$vals['pincode'],$vals['phone'],$vals['email'],$vals['logo_url'],$vals['bank_name'],$vals['account_number'],$vals['ifsc_code'],$vals['upi_id'],$vals['invoice_prefix'],$taxType,$cgst,$sgst,$igst,$vals['footer_note']);
    $stmt->execute();
    $success = "Settings saved!";
}

$stmt_g = $conn->prepare("SELECT * FROM gst_settings WHERE company_id=?");
$stmt_g->bind_param("i", $companyId); $stmt_g->execute();
$g = $stmt_g->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<?php if (!empty($success)): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg> GST Invoice Settings</h2>
    <a href="gst_invoices.php" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> View Invoices</a>
</div>

<div class="form-card" style="max-width:700px;">
<form method="POST">
<?php echo csrf_field(); ?>

<h3 style="font-size:14px;font-weight:700;margin-bottom:16px;color:var(--accent);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg> Business Details</h3>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div class="form-group"><label>Business Name *</label><input type="text" name="business_name" class="form-control" value="<?php echo htmlspecialchars($g['business_name']??''); ?>" required></div>
    <div class="form-group"><label>GSTIN</label><input type="text" name="gstin" class="form-control" placeholder="22AAAAA0000A1Z5" value="<?php echo htmlspecialchars($g['gstin']??''); ?>" style="text-transform:uppercase;"></div>
    <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($g['phone']??''); ?>"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($g['email']??''); ?>"></div>
</div>
<div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($g['address']??''); ?></textarea></div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
    <div class="form-group"><label>City</label><input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($g['city']??''); ?>"></div>
    <div class="form-group"><label>State</label><input type="text" name="state" class="form-control" value="<?php echo htmlspecialchars($g['state']??'Tamil Nadu'); ?>"></div>
    <div class="form-group"><label>Pincode</label><input type="text" name="pincode" class="form-control" value="<?php echo htmlspecialchars($g['pincode']??''); ?>"></div>
</div>

<h3 style="font-size:14px;font-weight:700;margin:20px 0 16px;color:var(--accent);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Tax Settings</h3>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
    <div class="form-group">
        <label>Tax Type</label>
        <select name="tax_type" class="form-control" id="taxType" onchange="toggleTax()">
            <option value="GST" <?php echo ($g['tax_type']??'GST')==='GST'?'selected':''; ?>>GST (CGST+SGST)</option>
            <option value="IGST" <?php echo ($g['tax_type']??'')==='IGST'?'selected':''; ?>>IGST (Interstate)</option>
            <option value="None" <?php echo ($g['tax_type']??'')==='None'?'selected':''; ?>>No Tax</option>
        </select>
    </div>
    <div class="form-group" id="cgstBox"><label>CGST %</label><input type="number" name="cgst_pct" class="form-control" value="<?php echo $g['cgst_pct']??9; ?>" step="0.01"></div>
    <div class="form-group" id="sgstBox"><label>SGST %</label><input type="number" name="sgst_pct" class="form-control" value="<?php echo $g['sgst_pct']??9; ?>" step="0.01"></div>
    <div class="form-group" id="igstBox" style="display:none;"><label>IGST %</label><input type="number" name="igst_pct" class="form-control" value="<?php echo $g['igst_pct']??18; ?>" step="0.01"></div>
</div>

<h3 style="font-size:14px;font-weight:700;margin:20px 0 16px;color:var(--accent);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg> Bank Details</h3>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($g['bank_name']??''); ?>"></div>
    <div class="form-group"><label>Account Number</label><input type="text" name="account_number" class="form-control" value="<?php echo htmlspecialchars($g['account_number']??''); ?>"></div>
    <div class="form-group"><label>IFSC Code</label><input type="text" name="ifsc_code" class="form-control" value="<?php echo htmlspecialchars($g['ifsc_code']??''); ?>"></div>
    <div class="form-group"><label>UPI ID</label><input type="text" name="upi_id" class="form-control" placeholder="business@upi" value="<?php echo htmlspecialchars($g['upi_id']??''); ?>"></div>
</div>

<h3 style="font-size:14px;font-weight:700;margin:20px 0 16px;color:var(--accent);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Invoice Settings</h3>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div class="form-group"><label>Invoice Prefix</label><input type="text" name="invoice_prefix" class="form-control" placeholder="INV" value="<?php echo htmlspecialchars($g['invoice_prefix']??'INV'); ?>"></div>
    <div class="form-group"><label>Logo URL (optional)</label><input type="text" name="logo_url" class="form-control" placeholder="https://..." value="<?php echo htmlspecialchars($g['logo_url']??''); ?>"></div>
</div>
<div class="form-group"><label>Footer Note</label><textarea name="footer_note" class="form-control" rows="2" placeholder="Thank you for your business!"><?php echo htmlspecialchars($g['footer_note']??'Thank you for your business!'); ?></textarea></div>

<button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Settings</button>
</form>
</div>

<script>
function toggleTax() {
    var t = document.getElementById('taxType').value;
    document.getElementById('cgstBox').style.display = t==='GST' ? '' : 'none';
    document.getElementById('sgstBox').style.display = t==='GST' ? '' : 'none';
    document.getElementById('igstBox').style.display = t==='IGST' ? '' : 'none';
}
toggleTax();
</script>
<?php include '../includes/client_footer.php'; ?>
