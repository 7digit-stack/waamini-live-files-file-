<?php
include '../includes/client_auth_check.php';

$pageTitle = 'Brochure Sender';
$currentPage = '';

$success = '';
$error = '';

// Fetch company brochure
$stBr = $conn->prepare("SELECT brochure_url FROM companies WHERE id = ?");
$stBr->bind_param("i", $companyId);
$stBr->execute();
$company = $stBr->get_result()->fetch_assoc();

// Handle Brochure Upload
if (isset($_POST['upload_brochure'])) {
    csrf_verify();

    if (isset($_FILES['brochure_file']) && $_FILES['brochure_file']['error'] === UPLOAD_ERR_OK) {
        $targetDir = "../storage/brochures/";
        if (!is_dir($targetDir))
            mkdir($targetDir, 0777, true);

        $fileExtension = strtolower(pathinfo($_FILES['brochure_file']['name'], PATHINFO_EXTENSION));
        if ($fileExtension === 'pdf') {
            $fileName = "brochure_" . $companyId . "_" . time() . ".pdf";
            $targetPath = $targetDir . $fileName;

            if (move_uploaded_file($_FILES['brochure_file']['tmp_name'], $targetPath)) {
                $brochureUrl = "/storage/brochures/" . $fileName;
                $stmt = $conn->prepare("UPDATE companies SET brochure_url = ? WHERE id = ?");
                $stmt->bind_param("si", $brochureUrl, $companyId);
                if ($stmt->execute()) {
                    $success = "Brochure uploaded successfully!";
                    $company['brochure_url'] = $brochureUrl;
                }
                else {
                    $error = "Failed to update database.";
                }
            }
            else {
                $error = "Failed to move uploaded file.";
            }
        }
        else {
            $error = "Only PDF files are allowed.";
        }
    }
    else {
        $error = "Please select a valid PDF file.";
    }
}

include '../includes/client_header.php';

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']);
$fullBrochureUrl = !empty($company['brochure_url']) ? $protocol . "://" . $host . $company['brochure_url'] : "";
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Brochure Sender</h2>
    <p style="color:var(--text-muted); font-size:14px;">Upload your business brochure (PDF) and share it instantly with your customers.</p>
</div>

<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
    
    <!-- Upload Section -->
    <div class="form-card">
        <h3 style="font-size:16px; margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Upload New Brochure</h3>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Select PDF File</label>
                <input type="file" name="brochure_file" class="form-control" accept=".pdf" required>
                <p style="font-size:11px; color:var(--text-muted); margin-top:8px;">Max size: 5MB. Only PDF format supported.</p>
            </div>
            <button type="submit" name="upload_brochure" class="btn btn-primary" style="margin-top:20px; width:100%;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Upload & Generate Link</button>
        </form>
    </div>

    <!-- Live Preview/Link Section -->
    <div class="form-card" style="background:rgba(255,255,255,0.02); border:1px dashed var(--border);">
        <h3 style="font-size:16px; margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Your Digital Brochure</h3>
        
        <?php if ($fullBrochureUrl): ?>
            <div style="text-align:center; padding:20px;">
                <div style="font-size:48px; margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></div>
                <p style="font-size:14px; font-weight:600; color:var(--text-primary);">Brochure is LIVE</p>
                <a href="<?php echo $fullBrochureUrl; ?>" target="_blank" style="font-size:12px; color:var(--accent); text-decoration:none;">View PDF →</a>
            </div>

            <div style="margin-top:20px; background:var(--bg-input); padding:16px; border-radius:12px; border:1px solid var(--border);">
                <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Public Sharing Link</div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" readonly value="<?php echo $fullBrochureUrl; ?>" id="brochureLink" style="background:none; border:none; color:var(--accent); font-size:12px; flex:1; font-weight:700;">
                    <button onclick="copyBrochure()" class="btn btn-sm btn-edit">Copy</button>
                </div>
            </div>
        <?php
else: ?>
            <div style="text-align:center; padding:40px; opacity:0.5;">
                <div style="font-size:48px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></div>
                <p style="font-size:13px; color:var(--text-muted); margin-top:10px;">No brochure uploaded yet.</p>
            </div>
        <?php
endif; ?>
    </div>

</div>

<script>
function copyBrochure() {
    var copyText = document.getElementById("brochureLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Brochure Link Copied!");
}
</script>

<?php include '../includes/client_footer.php'; ?>
