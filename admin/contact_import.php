<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Import Contacts';
$currentPage = 'contacts';

$error = '';
$success = '';
$imported = 0;
$skipped = 0;
$errors = [];

if (isset($_POST['import']) && isset($_FILES['csv_file'])) {
    csrf_verify();
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Upload failed. Please try again.";
    }
    elseif ($file['size'] > 5 * 1024 * 1024) {
        $error = "File too large. Maximum 5MB allowed.";
    }
    else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = "Only CSV files are accepted.";
        }
        else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                $header = array_map(function ($h) {
                    return strtolower(trim($h));
                }, $header);

                $nameIdx = array_search('name', $header);
                $phoneIdx = array_search('phone', $header);
                $emailIdx = array_search('email', $header);
                $tagsIdx = array_search('tags', $header);
                $var1Idx = array_search('var1', $header);
                $var2Idx = array_search('var2', $header);
                $var3Idx = array_search('var3', $header);
                $var4Idx = array_search('var4', $header);
                $var5Idx = array_search('var5', $header);

                if ($nameIdx === false || $phoneIdx === false) {
                    $error = "CSV must have 'name' and 'phone' columns in the header row.";
                }
                else {
                    $stmt = $conn->prepare("INSERT INTO contacts (name, phone, email, tags, var1, var2, var3, var4, var5) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $checkStmt = $conn->prepare("SELECT id FROM contacts WHERE phone = ?");

                    $lineNum = 1;
                    while (($row = fgetcsv($handle)) !== false) {
                        $lineNum++;
                        $name = isset($row[$nameIdx]) ? trim($row[$nameIdx]) : '';
                        $phone = isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : '';
                        $email = ($emailIdx !== false && isset($row[$emailIdx])) ? trim($row[$emailIdx]) : '';
                        $tags = ($tagsIdx !== false && isset($row[$tagsIdx])) ? trim($row[$tagsIdx]) : '';
                        $v1 = ($var1Idx !== false && isset($row[$var1Idx])) ? trim($row[$var1Idx]) : '';
                        $v2 = ($var2Idx !== false && isset($row[$var2Idx])) ? trim($row[$var2Idx]) : '';
                        $v3 = ($var3Idx !== false && isset($row[$var3Idx])) ? trim($row[$var3Idx]) : '';
                        $v4 = ($var4Idx !== false && isset($row[$var4Idx])) ? trim($row[$var4Idx]) : '';
                        $v5 = ($var5Idx !== false && isset($row[$var5Idx])) ? trim($row[$var5Idx]) : '';

                        if (empty($name) || empty($phone)) {
                            $skipped++;
                            $errors[] = "Row $lineNum: Missing name or phone — skipped.";
                            continue;
                        }

                        $checkStmt->bind_param("s", $phone);
                        $checkStmt->execute();
                        if ($checkStmt->get_result()->num_rows > 0) {
                            $skipped++;
                            $errors[] = "Row $lineNum: Phone $phone already exists — skipped.";
                            continue;
                        }

                        $stmt->bind_param("sssssssss", $name, $phone, $email, $tags, $v1, $v2, $v3, $v4, $v5);
                        if ($stmt->execute()) {
                            $imported++;
                        }
                        else {
                            $skipped++;
                            $errors[] = "Row $lineNum: Database error — skipped.";
                        }
                    }

                    fclose($handle);

                    if ($imported > 0) {
                        $success = "Successfully imported $imported contacts!";
                        if ($skipped > 0)
                            $success .= " ($skipped skipped)";
                    }
                    elseif ($skipped > 0) {
                        $error = "No contacts imported. $skipped rows were skipped.";
                    }
                }
            }
        }
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" style="vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Import Contacts from CSV</h2>
    <div class="action-btns">
        <a href="/assets/sample_contacts.csv" download="sample_contacts.csv" class="btn btn-sm btn-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Download Sample CSV</a>
        <a href="contacts.php" class="btn btn-sm btn-edit">← Back to Contacts</a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div class="form-card" style="max-width: 800px;">
    <!-- Format Requirements -->
    <div style="margin-bottom: 24px;">
        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> CSV Format Requirements</h3>
        <div style="background: var(--bg-input); border-radius: 10px; padding: 16px; font-size: 13px; color: var(--text-secondary);">
            <p style="margin-bottom: 8px;">Your CSV file must have a <strong style="color: var(--text-primary);">header row</strong> with these columns:</p>
            <div style="background: var(--bg-dark); border-radius: 8px; padding: 12px 16px; font-family: monospace; font-size: 12px; margin: 10px 0; color: #a78bfa; overflow-x: auto;">
                name,phone,email,tags,var1,var2,var3,var4,var5
            </div>
            <ul style="padding-left: 20px; line-height: 1.8;">
                <li><strong style="color: var(--text-primary);">name</strong> — Required. Contact name</li>
                <li><strong style="color: var(--text-primary);">phone</strong> — Required. Phone number (must be unique)</li>
                <li><strong style="color: var(--text-primary);">email</strong> — Optional. Email address</li>
                <li><strong style="color: var(--text-primary);">tags</strong> — Optional. Comma-separated tags</li>
                <li><strong style="color: #f59e0b;">var1</strong> — Optional. Custom variable 1 (e.g. Customer ID)</li>
                <li><strong style="color: #f59e0b;">var2</strong> — Optional. Custom variable 2 (e.g. Order Number)</li>
                <li><strong style="color: #f59e0b;">var3</strong> — Optional. Custom variable 3 (e.g. Plan/Tier)</li>
                <li><strong style="color: #f59e0b;">var4</strong> — Optional. Custom variable 4 (e.g. City)</li>
                <li><strong style="color: #f59e0b;">var5</strong> — Optional. Custom variable 5 (e.g. Date)</li>
            </ul>
            <p style="margin-top: 8px; color: var(--text-muted);">Maximum file size: 5MB. Duplicate phone numbers will be skipped.</p>
        </div>
    </div>

    <!-- Custom Message Example -->
    <div style="margin-bottom: 24px;">
        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> How Custom Variables Work in Messages</h3>
        <div style="background: rgba(108,92,231,0.06); border: 1px solid rgba(108,92,231,0.15); border-radius: 10px; padding: 16px; font-size: 13px;">
            <p style="color: var(--text-secondary); margin-bottom: 10px;">Use variables in your WhatsApp templates to send personalized messages:</p>
            <div style="background: var(--bg-dark); border-radius: 8px; padding: 14px 16px; font-size: 13px; line-height: 1.8; border-left: 3px solid #25d366;">
                <div style="color: #25d366; font-weight: 600; margin-bottom: 6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> WhatsApp Message Preview:</div>
                <div style="color: var(--text-primary);">
                    Hi <span style="color: #f59e0b; font-weight: 600;">{{name}}</span>, your Customer ID is <span style="color: #f59e0b; font-weight: 600;">{{var1}}</span>.<br>
                    Your order <span style="color: #f59e0b; font-weight: 600;">{{var2}}</span> has been shipped! <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg><br>
                    Membership: <span style="color: #f59e0b; font-weight: 600;">{{var3}}</span> | City: <span style="color: #f59e0b; font-weight: 600;">{{var4}}</span>
                </div>
            </div>
            <div style="margin-top: 10px; color: var(--text-muted); font-size: 12px;">
                ↓ This becomes for Rahul Kumar:
            </div>
            <div style="background: var(--bg-dark); border-radius: 8px; padding: 14px 16px; font-size: 13px; line-height: 1.8; border-left: 3px solid #a78bfa;">
                <div style="color: var(--text-primary);">
                    Hi <span style="color: #00c853; font-weight: 600;">Rahul Kumar</span>, your Customer ID is <span style="color: #00c853; font-weight: 600;">19999</span>.<br>
                    Your order <span style="color: #00c853; font-weight: 600;">22323#</span> has been shipped! <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg><br>
                    Membership: <span style="color: #00c853; font-weight: 600;">Gold</span> | City: <span style="color: #00c853; font-weight: 600;">Chennai</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Sample CSV Preview -->
    <div style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
            <h3 style="font-size: 16px; font-weight: 600;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Sample CSV Preview</h3>
            <a href="/assets/sample_contacts.csv" download="sample_contacts.csv" 
               style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:rgba(0,200,83,0.1); border:1px solid rgba(0,200,83,0.25); color:#00c853; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; transition:all 0.3s;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Download This Format
            </a>
        </div>
        <div style="background: var(--bg-dark); border-radius: 8px; padding: 14px 16px; font-size: 11px; line-height: 1.8; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-family: monospace;">
                <tr style="color: #a78bfa; font-weight: 600; border-bottom: 1px solid var(--border);">
                    <td style="padding: 4px 8px;">name</td>
                    <td style="padding: 4px 8px;">phone</td>
                    <td style="padding: 4px 8px;">email</td>
                    <td style="padding: 4px 8px;">tags</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">var1</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">var2</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">var3</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">var4</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">var5</td>
                </tr>
                <tr style="color: var(--text-secondary);">
                    <td style="padding: 4px 8px;">Rahul Kumar</td>
                    <td style="padding: 4px 8px;">+919150802906</td>
                    <td style="padding: 4px 8px;">rahul@email.com</td>
                    <td style="padding: 4px 8px;">lead,premium</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">19999</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">22323#</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Gold</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Chennai</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">2025-01-15</td>
                </tr>
                <tr style="color: var(--text-secondary);">
                    <td style="padding: 4px 8px;">Priya Sharma</td>
                    <td style="padding: 4px 8px;">+919003876516</td>
                    <td style="padding: 4px 8px;">priya@email.com</td>
                    <td style="padding: 4px 8px;">customer</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">20001</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">22456#</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Silver</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Karaikudi</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">2025-02-10</td>
                </tr>
                <tr style="color: var(--text-secondary);">
                    <td style="padding: 4px 8px;">Vijay M</td>
                    <td style="padding: 4px 8px;">+918012345678</td>
                    <td style="padding: 4px 8px;">vijay@email.com</td>
                    <td style="padding: 4px 8px;">new,vip</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">20050</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">22789#</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Platinum</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">Madurai</td>
                    <td style="padding: 4px 8px; color:#f59e0b;">2025-03-01</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Upload Form -->
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Select CSV File *</label>
            <input type="file" name="csv_file" accept=".csv" class="form-control" required style="padding: 10px;">
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" name="import" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Import Contacts</button>
            <a href="/assets/sample_contacts.csv" download="sample_contacts.csv" class="btn btn-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:middle;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Download CSV Format</a>
        </div>
    </form>
</div>

<?php if (!empty($errors)): ?>
<div class="data-table-wrapper" style="max-width: 800px; margin-top: 20px;">
    <div class="table-header">
        <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:middle;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Import Warnings</h3>
    </div>
    <div style="padding: 16px;">
        <?php foreach ($errors as $err): ?>
            <div style="padding: 6px 0; font-size: 13px; color: var(--warning); border-bottom: 1px solid var(--border);">
                <?php echo htmlspecialchars($err); ?>
            </div>
        <?php
    endforeach; ?>
    </div>
</div>
<?php
endif; ?>

<?php include '../includes/admin_footer.php'; ?>
