<?php
include '../includes/client_auth_check.php';
$pageTitle = 'GST Invoice Settings';
$currentPage = 'gst_settings';

// Get company info
$stmt_co = $conn->prepare("SELECT * FROM companies WHERE id=?");
$stmt_co->bind_param("i", $companyId);
$stmt_co->execute();
$company = $stmt_co->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gst'])) {
    csrf_verify();
    $gstin    = strtoupper(sanitize($_POST['gstin'] ?? ''));
    $pan      = strtoupper(sanitize($_POST['pan_number'] ?? ''));
    $address  = sanitize($_POST['business_address'] ?? '');
    $city     = sanitize($_POST['city'] ?? '');
    $state    = sanitize($_POST['state'] ?? '');
    $pincode  = sanitize($_POST['pincode'] ?? '');
    $prefix   = sanitize($_POST['invoice_prefix'] ?? 'INV');
    $bank     = sanitize($_POST['bank_name'] ?? '');
    $acc      = sanitize($_POST['bank_account'] ?? '');
    $ifsc     = strtoupper(sanitize($_POST['bank_ifsc'] ?? ''));
    $upi      = sanitize($_POST['upi_id'] ?? '');

    $stmt_upd = $conn->prepare("UPDATE companies SET gstin=?,pan_number=?,business_address=?,city=?,state=?,pincode=?,invoice_prefix=?,bank_name=?,bank_account=?,bank_ifsc=?,upi_id=? WHERE id=?");
    $stmt_upd->bind_param("sssssssssssi", $gstin,$pan,$address,$city,$state,$pincode,$prefix,$bank,$acc,$ifsc,$upi,$companyId);
    $stmt_upd->execute();
    header("Location: gst_settings.php?msg=saved"); exit();
}

$states = ["Andhra Pradesh","Arunachal Pradesh","Assam","Bihar","Chhattisgarh","Goa","Gujarat","Haryana","Himachal Pradesh","Jharkhand","Karnataka","Kerala","Madhya Pradesh","Maharashtra","Manipur","Meghalaya","Mizoram","Nagaland","Odisha","Punjab","Rajasthan","Sikkim","Tamil Nadu","Telangana","Tripura","Uttar Pradesh","Uttarakhand","West Bengal","Delhi","Jammu & Kashmir","Ladakh","Puducherry","Chandigarh"];

include '../includes/client_header.php';
?>
<?php if(isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> GST Settings saved!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg> GST Invoice Settings</h2>
    <div style="font-size:13px;color:var(--text-muted);">Setup once — auto-generate GST invoices for every order</div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div>
<div class="form-card" style="max-width:none;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg> Business Details</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>GSTIN Number</label>
            <input type="text" name="gstin" class="form-control" value="<?php echo htmlspecialchars($company['gstin']??''); ?>" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase;">
            <small style="color:var(--text-muted);">15 digit GST number</small>
        </div>
        <div class="form-group">
            <label>PAN Number</label>
            <input type="text" name="pan_number" class="form-control" value="<?php echo htmlspecialchars($company['pan_number']??''); ?>" placeholder="AAAAA0000A" maxlength="10" style="text-transform:uppercase;">
        </div>
        <div class="form-group">
            <label>Business Address</label>
            <textarea name="business_address" class="form-control" rows="2" placeholder="Door No, Street Name"><?php echo htmlspecialchars($company['business_address']??''); ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>City</label>
                <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($company['city']??''); ?>">
            </div>
            <div class="form-group">
                <label>Pincode</label>
                <input type="text" name="pincode" class="form-control" value="<?php echo htmlspecialchars($company['pincode']??''); ?>" maxlength="6">
            </div>
        </div>
        <div class="form-group">
            <label>State</label>
            <select name="state" class="form-control">
                <option value="">Select State</option>
                <?php foreach($states as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo ($company['state']??'')===$s?'selected':''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Invoice Prefix</label>
            <input type="text" name="invoice_prefix" class="form-control" value="<?php echo htmlspecialchars($company['invoice_prefix']??'INV'); ?>" placeholder="INV" maxlength="10">
            <small style="color:var(--text-muted);">Example: INV → INV-2024-001</small>
        </div>
        <h3 style="font-size:14px;font-weight:700;margin:20px 0 16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg> Bank Details</h3>
        <div class="form-group">
            <label>Bank Name</label>
            <input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($company['bank_name']??''); ?>">
        </div>
        <div class="form-group">
            <label>Account Number</label>
            <input type="text" name="bank_account" class="form-control" value="<?php echo htmlspecialchars($company['bank_account']??''); ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>IFSC Code</label>
                <input type="text" name="bank_ifsc" class="form-control" value="<?php echo htmlspecialchars($company['bank_ifsc']??''); ?>" style="text-transform:uppercase;">
            </div>
            <div class="form-group">
                <label>UPI ID</label>
                <input type="text" name="upi_id" class="form-control" value="<?php echo htmlspecialchars($company['upi_id']??''); ?>" placeholder="name@upi">
            </div>
        </div>
        <button type="submit" name="save_gst" class="btn btn-primary" style="width:100%;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Settings</button>
    </form>
</div>
</div>

<!-- Preview -->
<div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg> Invoice Preview</h3>
        <div style="background:white;border-radius:8px;padding:20px;color:#333;font-size:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
            <!-- Invoice Header -->
            <div style="display:flex;justify-content:space-between;border-bottom:2px solid #6c63ff;padding-bottom:12px;margin-bottom:12px;">
                <div>
                    <div style="font-size:18px;font-weight:800;color:#6c63ff;"><?php echo htmlspecialchars($company['name']??'Your Business'); ?></div>
                    <div style="color:#666;margin-top:4px;"><?php echo htmlspecialchars($company['business_address']??'Your Address'); ?></div>
                    <div style="color:#666;"><?php echo htmlspecialchars(($company['city']??'').' '.($company['state']??'').' '.($company['pincode']??'')); ?></div>
                    <?php if(!empty($company['gstin'])): ?><div style="margin-top:4px;"><strong>GSTIN:</strong> <?php echo $company['gstin']; ?></div><?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:20px;font-weight:800;color:#333;">TAX INVOICE</div>
                    <div style="color:#666;margin-top:4px;"><?php echo ($company['invoice_prefix']??'INV'); ?>-<?php echo date('Y'); ?>-001</div>
                    <div style="color:#666;">Date: <?php echo date('d M Y'); ?></div>
                </div>
            </div>
            <!-- Bill To -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
                <div style="background:#f8f9fa;padding:10px;border-radius:6px;">
                    <div style="font-weight:700;margin-bottom:4px;color:#6c63ff;">BILL TO:</div>
                    <div>Customer Name</div>
                    <div style="color:#666;">+91 98765 43210</div>
                </div>
                <div style="background:#f8f9fa;padding:10px;border-radius:6px;">
                    <div style="font-weight:700;margin-bottom:4px;color:#6c63ff;">FROM:</div>
                    <div><?php echo htmlspecialchars($company['name']??'Your Business'); ?></div>
                    <?php if(!empty($company['upi_id'])): ?><div style="color:#666;">UPI: <?php echo $company['upi_id']; ?></div><?php endif; ?>
                </div>
            </div>
            <!-- Items Table -->
            <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
                <thead><tr style="background:#6c63ff;color:white;">
                    <th style="padding:6px;text-align:left;">#</th>
                    <th style="padding:6px;text-align:left;">Item</th>
                    <th style="padding:6px;text-align:right;">Qty</th>
                    <th style="padding:6px;text-align:right;">Rate</th>
                    <th style="padding:6px;text-align:right;">Amount</th>
                </tr></thead>
                <tbody>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:6px;">1</td>
                        <td style="padding:6px;">Sample Product</td>
                        <td style="padding:6px;text-align:right;">2</td>
                        <td style="padding:6px;text-align:right;">₹500</td>
                        <td style="padding:6px;text-align:right;">₹1,000</td>
                    </tr>
                </tbody>
            </table>
            <!-- Totals -->
            <div style="display:flex;justify-content:flex-end;">
                <table style="width:200px;">
                    <tr><td style="padding:3px;color:#666;">Subtotal:</td><td style="text-align:right;padding:3px;">₹1,000</td></tr>
                    <tr><td style="padding:3px;color:#666;">CGST (9%):</td><td style="text-align:right;padding:3px;">₹90</td></tr>
                    <tr><td style="padding:3px;color:#666;">SGST (9%):</td><td style="text-align:right;padding:3px;">₹90</td></tr>
                    <tr style="font-weight:800;border-top:2px solid #6c63ff;"><td style="padding:4px;">TOTAL:</td><td style="text-align:right;padding:4px;color:#6c63ff;">₹1,180</td></tr>
                </table>
            </div>
            <?php if(!empty($company['bank_name'])): ?>
            <div style="margin-top:12px;padding:8px;background:#f0f0ff;border-radius:6px;font-size:11px;">
                <strong>Bank:</strong> <?php echo htmlspecialchars($company['bank_name']); ?> |
                <strong>A/C:</strong> <?php echo htmlspecialchars($company['bank_account']??''); ?> |
                <strong>IFSC:</strong> <?php echo htmlspecialchars($company['bank_ifsc']??''); ?>
            </div>
            <?php endif; ?>
            <div style="text-align:center;margin-top:12px;color:#999;font-size:11px;">Thank you for your business! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></div>
        </div>
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
