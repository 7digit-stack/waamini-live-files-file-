<?php
include '../includes/client_auth_check.php';
if (!has_feature('vcard')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Digital vCard Settings';
$currentPage = 'dashboard';

$success = '';
$error = '';

// Fetch existing settings
$stVc1 = $conn->prepare("SELECT * FROM vcard_settings WHERE company_id = ?");
$stVc1->bind_param("i", $companyId);
$stVc1->execute();
$settings = $stVc1->get_result()->fetch_assoc();

// Default values if no settings exist
if (!$settings) {
    $settings = [
        'theme_color' => '#00b894',
        'business_bio' => 'Welcome to our official business card. Feel free to reach out to us!',
        'logo_url' => '',
        'social_fb' => '', 'social_ig' => '', 'social_tw' => '', 'social_li' => '', 'social_yt' => ''
    ];
}

// Handle Form Submission
if (isset($_POST['save'])) {
    csrf_verify();

    $theme = sanitize($_POST['theme_color']);
    $bio = sanitize($_POST['business_bio']);
    $fb = sanitize($_POST['social_fb']);
    $ig = sanitize($_POST['social_ig']);
    $li = sanitize($_POST['social_li']);
    $tw = sanitize($_POST['social_tw']);
    $yt = sanitize($_POST['social_yt']);
    $logo = sanitize($_POST['existing_logo'] ?? '');

    // Handle File Upload
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $targetDir = "../storage/logos/";
        if (!is_dir($targetDir))
            mkdir($targetDir, 0777, true);

        $fileExtension = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $fileName = "logo_" . $companyId . "_" . time() . "." . $fileExtension;
            $targetPath = $targetDir . $fileName;

            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                $logo = "/storage/logos/" . $fileName;
            }
        }
    }

    $stmt = $conn->prepare("REPLACE INTO vcard_settings (company_id, theme_color, business_bio, social_fb, social_ig, social_li, social_tw, social_yt, logo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssss", $companyId, $theme, $bio, $fb, $ig, $li, $tw, $yt, $logo);

    if ($stmt->execute()) {
        $success = "vCard settings saved successfully!";
        $stVc2 = $conn->prepare("SELECT * FROM vcard_settings WHERE company_id = ?");
        $stVc2->bind_param("i", $companyId);
        $stVc2->execute();
        $settings = $stVc2->get_result()->fetch_assoc();
    }
    else {
        $error = "Failed to save settings.";
    }
}

// Public URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']);
    $publicUrl = $protocol . "://" . $host . "/vcard.php?id=" . $companyId;

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Digital vCard Settings</h2>
    <div class="action-btns">
        <a href="<?php echo $publicUrl; ?>" target="_blank" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg> View Your vCard</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $success; ?></div><?php
endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
    
    <div class="form-card">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="existing_logo" value="<?php echo htmlspecialchars($settings['logo_url'] ?? ''); ?>">
            
            <div style="display:flex; flex-direction:column; gap:20px;">
                
                <div style="display:grid; grid-template-columns:1fr 2fr; gap:16px;">
                    <div class="form-group">
                        <label>Theme Color</label>
                        <input type="color" name="theme_color" value="<?php echo $settings['theme_color']; ?>" class="form-control" style="height:45px; padding:4px;">
                    </div>
                    <div class="form-group">
                        <label>Upload Logo</label>
                        <input type="file" name="logo_file" class="form-control" accept="image/*">
                        <?php if (!empty($settings['logo_url'])): ?>
                            <div style="margin-top:10px; display:flex; align-items:center; gap:10px;">
                                <img src="<?php echo htmlspecialchars($settings['logo_url']); ?>" style="max-height:40px; border-radius:4px; border:1px solid var(--border);">
                                <span style="font-size:11px; color:var(--text-muted);">Current logo</span>
                            </div>
                        <?php
endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Business Bio / About Us</label>
                    <textarea name="business_bio" class="form-control" rows="4" maxlength="500"><?php echo htmlspecialchars($settings['business_bio']); ?></textarea>
                    <div style="text-align:right; font-size:10px; color:var(--text-muted);">Max 500 characters</div>
                </div>

                <div class="form-section-title">Social Media Links</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label>Facebook Profile/Page</label>
                        <input type="text" name="social_fb" value="<?php echo htmlspecialchars($settings['social_fb'] ?? ''); ?>" class="form-control" placeholder="https://facebook.com/u">
                    </div>
                    <div class="form-group">
                        <label>Instagram Handle</label>
                        <input type="text" name="social_ig" value="<?php echo htmlspecialchars($settings['social_ig'] ?? ''); ?>" class="form-control" placeholder="https://instagram.com/u">
                    </div>
                    <div class="form-group">
                        <label>LinkedIn URL</label>
                        <input type="text" name="social_li" value="<?php echo htmlspecialchars($settings['social_li'] ?? ''); ?>" class="form-control" placeholder="https://linkedin.com/u">
                    </div>
                    <div class="form-group">
                        <label>Twitter/X URL</label>
                        <input type="text" name="social_tw" value="<?php echo htmlspecialchars($settings['social_tw'] ?? ''); ?>" class="form-control" placeholder="https://twitter.com/u">
                    </div>
                    <div class="form-group">
                        <label>YouTube Channel</label>
                        <input type="text" name="social_yt" value="<?php echo htmlspecialchars($settings['social_yt'] ?? ''); ?>" class="form-control" placeholder="https://youtube.com/c/u">
                    </div>
                </div>

                <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save vCard Settings</button>
            </div>
        </form>
    </div>

    <!-- Live Link Share -->
    <div>
        <div class="form-card" style="background:rgba(255,255,255,0.02); border:1px dashed var(--border); text-align:center; padding:30px;">
            <div style="font-size:40px; margin-bottom:15px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
            <h3 style="margin-bottom:10px;">Share Your Card</h3>
            <p style="font-size:12px; color:var(--text-muted); margin-bottom:20px;">Use this URL on your WhatsApp Bio, Instagram, or Print on Business Cards.</p>
            
            <div style="background:var(--bg-input); padding:12px; border-radius:8px; display:flex; gap:10px; align-items:center;">
                <input type="text" readonly value="<?php echo $publicUrl; ?>" id="vcardUrl" style="background:none; border:none; color:var(--text-primary); font-size:12px; flex:1;">
                <button onclick="copyToClipboard()" class="btn btn-sm btn-edit">Copy</button>
            </div>
            
            <div style="margin-top:24px;">
                <div style="font-size:11px; color:var(--text-muted);">Total Card Views</div>
                <div style="font-size:24px; font-weight:800; color:var(--accent);"><?php echo number_format($settings['view_count'] ?? 0); ?></div>
            </div>
        </div>
    </div>

</div>

<script>
function copyToClipboard() {
    var copyText = document.getElementById("vcardUrl");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("URL Copied!");
}
</script>

<?php include '../includes/client_footer.php'; ?>
