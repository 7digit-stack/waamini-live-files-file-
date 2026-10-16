<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_pdf_quotes')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'PDF & Quotation Settings';
$currentPage = 'pdf_settings';

$success = '';
$error = '';

$settings = [];

function waamini_logo_src_for_ui(string $stored): string {
    $stored = trim($stored);
    if ($stored === '') return '';
    if (stripos($stored, 'http://') === 0 || stripos($stored, 'https://') === 0 || strpos($stored, '/') === 0) {
        return $stored;
    }
    return '/' . ltrim($stored, '/');
}

if (isset($_POST['remove_logo'])) {
    csrf_verify();
    $stmt = $conn->prepare("UPDATE company_pdf_settings SET logo_url = '' WHERE company_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $companyId);
        if ($stmt->execute()) {
            $success = "Logo removed successfully.";
        }
    }
}

if (isset($_POST['save_pdf_settings'])) {
    csrf_verify();
    $name = trim((string)($_POST['company_name'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = sanitize_email((string)($_POST['email'] ?? ''));
    $gstin = trim((string)($_POST['gstin'] ?? ''));
    $bankDetails = trim((string)($_POST['bank_details'] ?? ''));
    $terms = trim((string)($_POST['terms'] ?? ''));

    if ($name === '' || $address === '' || $phone === '' || $email === '') {
        $error = 'Please fill all required fields (name, address, phone, email).';
    }

    $name = sanitize($name);
    $address = sanitize($address);
    $phone = function_exists('sanitize_phone') ? sanitize_phone($phone) : sanitize($phone);
    $terms = sanitize($terms);
    
    $logoUrl = '';
    $stPdfOld = $conn->prepare("SELECT logo_url FROM company_pdf_settings WHERE company_id = ?");
    if ($stPdfOld) {
        $stPdfOld->bind_param("i", $companyId);
        $stPdfOld->execute();
        $oldSettings = $stPdfOld->get_result()->fetch_assoc();
        $logoUrl = is_array($oldSettings) ? (string)($oldSettings['logo_url'] ?? '') : '';
    }

    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] == UPLOAD_ERR_OK) {
        $maxBytes = 2 * 1024 * 1024;
        if ((int)($_FILES['logo_file']['size'] ?? 0) > $maxBytes) {
            $error = "Logo too large. Max 2MB.";
        }
        $uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (!$error) {
            if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                $tmp = (string)($_FILES['logo_file']['tmp_name'] ?? '');
                $imgInfo = $tmp !== '' ? @getimagesize($tmp) : false;
                if ($imgInfo === false) {
                    $error = "Invalid logo file.";
                } else {
                    $mime = strtolower((string)($imgInfo['mime'] ?? ''));
                    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
                        $error = "Invalid logo format. Use PNG or JPG.";
                    } else {
                        $filename = 'logo_' . $companyId . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                            $logoUrl = 'uploads/logos/' . $filename;
                        } else {
                            $error = "Failed to upload logo.";
                        }
                    }
                }
            } else {
                $error = "Invalid logo format. Use PNG or JPG.";
            }
        }
    }

    if (!$error) {
        $stmt = $conn->prepare("INSERT INTO company_pdf_settings (company_id, company_name, address, phone, email, gstin, bank_details, logo_url, terms) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                company_name = VALUES(company_name), 
                                address = VALUES(address), 
                                phone = VALUES(phone), 
                                email = VALUES(email), 
                                gstin = VALUES(gstin),
                                bank_details = VALUES(bank_details),
                                logo_url = VALUES(logo_url), 
                                terms = VALUES(terms)");
        $stmt->bind_param("issssssss", $companyId, $name, $address, $phone, $email, $gstin, $bankDetails, $logoUrl, $terms);
        if ($stmt && $stmt->execute()) {
            $success = "Settings updated successfully!";
        } else {
            $error = "Error updating settings.";
        }
    }
}

$stPdfSet = $conn->prepare("SELECT * FROM company_pdf_settings WHERE company_id = ?");
if ($stPdfSet) {
    $stPdfSet->bind_param("i", $companyId);
    $stPdfSet->execute();
    $settings = $stPdfSet->get_result()->fetch_assoc();
    $settings = is_array($settings) ? $settings : [];
}

$logoSrc = waamini_logo_src_for_ui((string)($settings['logo_url'] ?? ''));

include '../includes/client_header.php';
?>

<style>
    .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
    @media (max-width: 1100px) { .grid-container { grid-template-columns: 1fr; } }
    .preview-frame { position: sticky; top: 20px; transition: transform 0.3s ease; }
    .preview-frame:hover { transform: scale(1.01); }
    .btn-remove-logo { background: #ff7675; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-size: 11px; cursor: pointer; margin-top: 5px; }
    .btn-remove-logo:hover { background: #d63031; }
    @media print {
        body * { visibility: hidden; }
        .preview-mockup, .preview-mockup * { visibility: visible; }
        .preview-mockup { position: absolute; left: 0; top: 0; width: 100% !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; }
        .sidebar, .topbar, .page-header, .card:not(.preview-frame .card), .btn, .card-header { display: none !important; }
    }
</style>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> PDF & Quotation Settings</h2>
</div>

<div class="grid-container">
    <div class="card">
        <div class="card-header"><h3>Company Branding</h3></div>
        <div class="card-body">
            <?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label>Official Company Name</label>
                    <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label>Company Logo</label>
                    <div style="display:flex; align-items:center; gap:15px; margin-top:5px; flex-wrap:wrap;">
                        <?php if (!empty($settings['logo_url'])): ?>
                            <div style="text-align:center;">
                                <div style="background:var(--bg-dark); padding:10px; border-radius:8px; border:1px solid var(--border); display:inline-block;">
                                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo" style="height:40px; max-width:120px; object-fit:contain;">
                                </div><br>
                                <button type="submit" name="remove_logo" class="btn-remove-logo" onclick="return confirm('Remove logo?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg> Remove</button>
                            </div>
                        <?php endif; ?>
                        <div style="flex:1; min-width:200px;">
                            <input type="file" name="logo_file" class="form-control" accept="image/png, image/jpeg">
                            <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Max 2MB. Leave empty to keep existing.</p>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label>Business Address</label>
                    <textarea name="address" class="form-control" rows="3" required><?php echo htmlspecialchars($settings['address'] ?? ''); ?></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                    <div class="form-group"><label>Support Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>" required></div>
                    <div class="form-group"><label>Billing Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>" required></div>
                </div>

                <div style="display:grid; grid-template-columns:1fr; gap:20px; margin-bottom:20px;">
                    <div class="form-group">
                        <label>GSTIN (Tax ID)</label>
                        <input type="text" name="gstin" class="form-control" value="<?php echo htmlspecialchars($settings['gstin'] ?? ''); ?>" placeholder="e.g. 22AAAAA0000A1Z5">
                    </div>
                    <div class="form-group">
                        <label>Bank Account Details</label>
                        <textarea name="bank_details" class="form-control" rows="3" placeholder="Bank: HDFC Bank&#10;Acc: 5010023...&#10;IFSC: HDFC000..."><?php echo htmlspecialchars($settings['bank_details'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label>Terms & Conditions (Footer)</label>
                    <textarea name="terms" class="form-control" rows="3"><?php echo htmlspecialchars($settings['terms'] ?? ''); ?></textarea>
                </div>

                <button type="submit" name="save_pdf_settings" class="btn btn-primary" style="padding:12px 30px; border-radius:30px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Profile</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Quotation Catalog & Products</h3>
            <p style="font-size:11px; color:var(--text-muted); margin:0;">Define your items here to select them quickly in live chat.</p>
        </div>
        <div class="card-body">
            <?php
            // Handle Product Actions
            if (isset($_POST['add_product'])) {
                csrf_verify();
                $pName = trim((string)($_POST['p_name'] ?? ''));
                $pPrice = floatval($_POST['p_price'] ?? 0);
                if ($pName !== '') {
                    $stAddP = $conn->prepare("INSERT INTO products (company_id, name, price) VALUES (?, ?, ?)");
                    $stAddP->bind_param("isd", $companyId, $pName, $pPrice);
                    $stAddP->execute();
                }
            }
            if (isset($_POST['delete_product'])) {
                csrf_verify();
                $pid = intval($_POST['p_id']);
                $stDelP = $conn->prepare("DELETE FROM products WHERE id = ? AND company_id = ?");
                $stDelP->bind_param("ii", $pid, $companyId);
                $stDelP->execute();
            }

            // Fetch Products
            $prods = [];
            $stListP = $conn->prepare("SELECT * FROM products WHERE company_id = ? ORDER BY id DESC");
            if ($stListP) {
                $stListP->bind_param("i", $companyId);
                $stListP->execute();
                $resP = $stListP->get_result();
                while ($p = $resP->fetch_assoc()) $prods[] = $p;
            }
            ?>

            <form method="POST" style="display:flex; gap:10px; margin-bottom:20px; align-items:flex-end; background:rgba(0,0,0,0.1); padding:15px; border-radius:10px;">
                <?php echo csrf_field(); ?>
                <div style="flex:2;">
                    <label style="font-size:11px; color:var(--text-muted); margin-bottom:4px; display:block;">Item Name</label>
                    <input type="text" name="p_name" class="form-control" placeholder="e.g. Logo Design" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; color:var(--text-muted); margin-bottom:4px; display:block;">Standard Price (₹)</label>
                    <input type="number" step="0.01" name="p_price" class="form-control" placeholder="0.00">
                </div>
                <button type="submit" name="add_product" class="btn btn-primary" style="padding:10px 20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add</button>
            </form>

            <div style="max-height:400px; overflow-y:auto; border:1px solid var(--border); border-radius:10px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--bg-card); text-align:left; border-bottom:1px solid var(--border);">
                            <th style="padding:12px;">Item Name</th>
                            <th style="padding:12px;">Price</th>
                            <th style="padding:12px; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prods)): ?>
                            <tr><td colspan="3" style="padding:20px; text-align:center; color:var(--text-muted);">No products defined yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($prods as $p): ?>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:12px; font-weight:600;"><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td style="padding:12px; color:var(--primary); font-weight:700;">₹<?php echo number_format($p['price'], 2); ?></td>
                                    <td style="padding:12px; text-align:right;">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this product?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="p_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" name="delete_product" style="background:none; border:none; color:#ff7675; cursor:pointer; font-size:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="preview-frame" style="grid-column: 1 / -1;">
        <div class="card" style="background:var(--bg-dark); border:1px solid var(--border); overflow:hidden;">
            <div class="card-header" style="background:var(--bg-card); display:flex; justify-content:space-between; align-items:center;">
                <h3>PDF Preview (Mockup)</h3>
                <button onclick="window.print()" class="btn btn-edit" style="font-size:11px; padding:4px 10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg> Test Print</button>
            </div>
            
            <div class="card-body preview-mockup" style="background:#fff; color:#1e293b; margin:20px; border-radius:12px; padding:40px; box-shadow:0 10px 30px rgba(0,0,0,0.1); font-family:'Inter', sans-serif; overflow:hidden; border-top: 6px solid var(--primary);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:30px;">
                    <div style="max-height:60px;">
                        <?php if (!empty($settings['logo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($logoSrc); ?>" style="max-height:60px; max-width:180px; object-fit:contain;">
                        <?php else: ?>
                            <div style="font-size:20px; font-weight:800; color:var(--primary);">LOGO</div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:18px; font-weight:800; color:#000;"><?php echo htmlspecialchars((string)($settings['company_name'] ?? '') ?: 'BUSINESS NAME'); ?></div>
                        <div style="font-size:11px; color:#64748b; margin-top:4px; max-width:200px; margin-left:auto; line-height:1.4;"><?php echo nl2br(htmlspecialchars((string)($settings['address'] ?? '') ?: "Address...")); ?></div>
                        <?php if (!empty($settings['gstin'])): ?>
                            <div style="font-size:11px; color:var(--primary); font-weight:700; margin-top:4px;">GSTIN: <?php echo htmlspecialchars($settings['gstin']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:30px; display:flex; justify-content:space-between; align-items:flex-end;">
                    <h2 style="margin:0; font-size:24px; font-weight:800; color:var(--primary);">QUOTATION</h2>
                    <div style="text-align:right; font-size:12px; font-weight:600;">
                        <div style="margin-bottom:2px;"><span style="color:#64748b; font-weight:400; margin-right:8px;">Quotation#</span> QT-00042</div>
                        <div><span style="color:#64748b; font-weight:400; margin-right:8px;">Date</span> <?php echo date('d/m/Y'); ?></div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:30px; margin-bottom:30px;">
                    <div>
                        <h3 style="font-size:10px; text-transform:uppercase; color:#64748b; margin:0 0 8px 0; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Bill To</h3>
                        <div style="font-size:14px; font-weight:700; color:var(--primary);">Customer Name</div>
                        <div style="font-size:12px; color:#1e293b; margin-top:2px;">Client Address...</div>
                    </div>
                </div>

                <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
                    <thead>
                        <tr style="background:#374151; color:#fff;">
                            <th style="padding:10px; text-align:left; font-size:10px; text-transform:uppercase;">Item</th>
                            <th style="padding:10px; text-align:center; font-size:10px; text-transform:uppercase; width:40px;">Qty</th>
                            <th style="padding:10px; text-align:right; font-size:10px; text-transform:uppercase; width:80px;">Rate</th>
                            <th style="padding:10px; text-align:right; font-size:10px; text-transform:uppercase; width:80px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; font-size:13px;">Professional Service Item</td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:center; font-size:13px;">1</td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:13px;">₹1,500.00</td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:13px; font-weight:700;">₹1,500.00</td>
                        </tr>
                    </tbody>
                </table>

                <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
                    <div style="flex:1; padding-right:40px;">
                        <h4 style="font-size:10px; text-transform:uppercase; color:#64748b; margin:0 0 8px 0;">Payment Details</h4>
                        <div style="font-size:11px; color:#1e293b; line-height:1.6; white-space:pre-line;"><?php echo nl2br(htmlspecialchars((string)($settings['bank_details'] ?? '') ?: "Bank: Your Bank\nAcc: 123456789")); ?></div>
                    </div>
                    <div style="width:180px; text-align:right;">
                        <div style="font-size:18px; font-weight:800; color:var(--primary);">Total: ₹1,500.00</div>
                    </div>
                </div>

                <div style="border-top:1px solid #e2e8f0; padding-top:15px;">
                    <h4 style="font-size:10px; text-transform:uppercase; color:#64748b; margin:0 0 6px 0;">Notes & Terms</h4>
                    <p style="font-size:11px; color:#64748b; white-space:pre-line;"><?php echo htmlspecialchars((string)($settings['terms'] ?? '') ?: "Standard terms apply."); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/client_footer.php'; ?>
