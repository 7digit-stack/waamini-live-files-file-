<?php
include '../includes/client_auth_check.php';

$pageTitle = 'Growth Accelerator';
$currentPage = 'growth_hub';

$success = '';
$error = '';

$gStmt = $conn->prepare("SELECT name, phone, welcome_msg, welcome_enabled, welcome_media_url, referral_points_value FROM companies WHERE id = ?");
$gStmt->bind_param("i", $companyId);
$gStmt->execute();
$company = $gStmt->get_result()->fetch_assoc();

$refStats = ['clicks' => 0, 'points' => 0];
$stRef = $conn->prepare("SELECT COUNT(*) as clicks FROM referral_logs WHERE company_id = ?");
$stRef->bind_param("i", $companyId);
$stRef->execute();
$resRef = $stRef->get_result()->fetch_assoc();
$refStats['clicks'] = (int)($resRef['clicks'] ?? 0);
$refStats['points'] = $refStats['clicks'] * (int)($company['referral_points_value'] ?? 0);

if (isset($_POST['save_welcome'])) {
    csrf_verify();
    $welcome_msg    = sanitize($_POST['welcome_msg']);
    $welcome_enabled = isset($_POST['welcome_enabled']) ? 1 : 0;
    $welcome_media  = sanitize($_POST['existing_welcome_media'] ?? '');

    if (isset($_FILES['welcome_media']) && $_FILES['welcome_media']['error'] === UPLOAD_ERR_OK) {
        $targetDir = "../storage/welcome/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

        $ext     = strtolower(pathinfo($_FILES['welcome_media']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif','mp4','mov','avi'];
        $maxSize = 15 * 1024 * 1024;

        if (in_array($ext, $allowed) && $_FILES['welcome_media']['size'] <= $maxSize) {
            $fileName = "welcome_{$companyId}_" . time() . ".{$ext}";
            if (move_uploaded_file($_FILES['welcome_media']['tmp_name'], $targetDir . $fileName)) {
                $welcome_media = "/storage/welcome/$fileName";
            }
        } else {
            $error = "Invalid file type or size exceeds 15 MB.";
        }
    }

    if (!$error) {
        $stmt = $conn->prepare("UPDATE companies SET welcome_msg=?, welcome_enabled=?, welcome_media_url=? WHERE id=?");
        $stmt->bind_param("sisi", $welcome_msg, $welcome_enabled, $welcome_media, $companyId);
        if ($stmt->execute()) {
            $success = "Welcome settings saved successfully.";
            $gStmt->execute();
            $company = $gStmt->get_result()->fetch_assoc();
        } else {
            $error = "Failed to save welcome settings.";
        }
    }
}

if (isset($_POST['save_referral'])) {
    csrf_verify();
    $ref_val = intval($_POST['referral_points_value']);
    $stmt    = $conn->prepare("UPDATE companies SET referral_points_value=? WHERE id=?");
    $stmt->bind_param("ii", $ref_val, $companyId);
    if ($stmt->execute()) {
        $success = "Referral points updated.";
        $gStmt->execute();
        $company = $gStmt->get_result()->fetch_assoc();
    } else {
        $error = "Failed to update referral settings.";
    }
}

$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host        = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST']);
    $vcardUrl    = "$protocol://$host/vcard.php?id=$companyId";
$referralLink = "$vcardUrl&ref=$companyId";

include '../includes/client_header.php';
?>

<style>
/* ── Growth Accelerator — matches the dashboard (.db-card) design system ── */
.ga{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;--amber:#F59E0B;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.ga *{box-sizing:border-box}

/* Hero */
.ga-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:22px 26px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.ga-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.ga-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.ga-stat-ico svg,.ga-card-ico svg,.ga-link-ico svg{width:20px;height:20px}
.ga-hero-ico svg{width:22px;height:22px}
.ga-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px;color:var(--ink)}
.ga-hero p{margin:0;font-size:14.5px;color:var(--tx)}
.ga-hero-actions{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.ga-btn{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.ga-btn:hover{border-color:var(--g);color:var(--g)}
.ga-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.ga-btn--primary:hover{color:#fff;transform:translateY(-1px)}

/* Layout */
.ga-stat-grid   { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
.ga-main-grid   { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; align-items:start; }
.ga-toolkit-row { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; padding:20px 22px; }

/* Stat cards */
.ga-stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
.ga-stat:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.ga-stat-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.ga-stat.is-blue .ga-stat-ico{background:#E4EFFD;color:#1d6fd6}
.ga-stat.is-amber .ga-stat-ico{background:#FEF3DC;color:#b7791f}
.ga-stat.is-off .ga-stat-ico{background:#EEF3F1;color:#7a8c86}
.ga-stat-val{font-size:26px;font-weight:800;color:var(--ink);line-height:1.1;letter-spacing:-.5px}
.ga-stat-val.sm{font-size:18px;letter-spacing:0}
.ga-stat-val.on{color:var(--gd)}
.ga-stat-val.off{color:#7a8c86}
.ga-stat-lbl{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

/* Section cards */
.ga-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.ga-card-hd{padding:18px 22px 6px;display:flex;align-items:center;justify-content:space-between;}
.ga-card-ico{width:42px;height:42px;background:#E3F7EE;border-radius:12px;display:grid;place-items:center;color:var(--g);flex-shrink:0}
.ga-card-title{font-size:16px;font-weight:800;color:var(--ink);letter-spacing:-.2px}
.ga-card-sub{font-size:12.5px;color:var(--mu);margin-top:2px}
.ga-card-bd{padding:22px}

/* Status pill */
.ga-pill{font-size:12px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.ga-pill-on{background:#E7F7F1;color:var(--gd)}
.ga-pill-off{background:#EEF3F1;color:#7a8c86}

/* Form */
.ga-label{font-size:13px;font-weight:700;color:var(--ink);display:block;margin-bottom:7px}
.ga .form-control{border-color:var(--line);border-radius:10px}
.ga .form-control:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(18,140,126,.14)}
.ga-hint{font-size:12.5px;color:var(--mu);margin-top:6px}

/* Referral link box */
.ga-refbox{background:var(--soft);border:0;border-radius:11px;padding:10px 12px;display:flex;align-items:center;gap:10px}
.ga-refbox input{background:none;border:none;font-size:13px;color:var(--gd);font-weight:600;flex:1;outline:none;overflow:hidden;text-overflow:ellipsis;min-width:0}
.ga-refbox .btn-primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent}

/* Mini stat box */
.ga-mini-stat{background:var(--soft);border:0;border-radius:13px;padding:16px;text-align:center}
.ga-mini-val{font-size:28px;font-weight:800;line-height:1;display:block;letter-spacing:-.5px}
.ga-mini-lbl{font-size:11px;color:var(--mu);margin-top:6px;text-transform:uppercase;letter-spacing:.6px;font-weight:700;display:block}

/* File drop zone */
.ga-dropzone{border:2px dashed #BFD8CF;border-radius:12px;padding:22px;text-align:center;font-size:13.5px;color:var(--mu);transition:border-color .2s,background .2s;cursor:pointer;position:relative;background:var(--soft)}
.ga-dropzone:hover,.ga-dropzone.active{border-color:var(--g);background:#E7F7F1;color:var(--gd)}
.ga-dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}

/* Preview panel */
.ga-preview{padding:20px;border:0;border-radius:14px;background:#EFE9E0;background-image:radial-gradient(rgba(18,140,126,.08) 1px,transparent 1px);background-size:14px 14px}
.ga-preview-lbl{font-size:12px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px}
.wa-mock{width:220px;border-radius:26px;border:7px solid #111;background:#111;overflow:hidden;box-shadow:0 14px 34px rgba(0,0,0,.2);margin:0 auto}
.wa-mock-hd{background:#075E54;padding:9px 12px;display:flex;align-items:center;gap:9px}
.wa-mock-av{width:28px;height:28px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0}
.wa-mock-body{background:#ece5dd;padding:10px;min-height:220px}
.wa-bubble{background:#fff;padding:8px 10px;border-radius:0 10px 10px 10px;font-size:11px;color:#111;line-height:1.4;max-width:92%;box-shadow:0 1px 2px rgba(0,0,0,.1)}

/* Toolkit */
.ga-link{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--soft);border:0;border-radius:13px;text-decoration:none;color:var(--ink);transition:background .18s,border-color .18s,transform .18s,box-shadow .18s}
.ga-link:hover{background:#E7F7F1;color:var(--ink);transform:translateY(-2px);box-shadow:0 12px 24px -16px rgba(18,140,126,.6)}
.ga-link-ico{width:42px;height:42px;background:#E3F7EE;border-radius:12px;display:grid;place-items:center;color:var(--g);flex-shrink:0}
.ga-link:nth-child(1) .ga-link-ico{background:#E4EFFD;color:#1d6fd6}
.ga-link:nth-child(2) .ga-link-ico{background:#EFE9FD;color:#6d44d8}
.ga-link:nth-child(3) .ga-link-ico{background:#FEF3DC;color:#b7791f}
.ga-link-title{font-size:14px;font-weight:700}
.ga-link-sub{font-size:12px;color:var(--mu);margin-top:2px}
.ga-chevron{margin-left:auto;flex-shrink:0;color:#9DB5AC;transition:transform .18s,color .18s}
.ga-link:hover .ga-chevron{color:var(--g);transform:translateX(3px)}

/* Card footer strip */
.ga-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 22px 20px}
.ga-footer label{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;color:var(--ink);cursor:pointer}
.ga-footer input[type=checkbox]{width:17px;height:17px;accent-color:var(--g);cursor:pointer}
.ga-footer .btn-primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}

.ga-charcount{font-size:12px;color:var(--mu);text-align:right;margin-top:5px}
.ga-divider{height:6px}

@keyframes ga-spin{to{transform:rotate(360deg)}}
.ga-spinning{animation:ga-spin .8s linear infinite;display:inline-block}

@media (max-width:1100px){.ga-toolkit-row{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:1000px){
    .ga-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .ga-main-grid{grid-template-columns:1fr}
}
@media (max-width:560px){
    .ga-hero{padding:18px}
    .ga-hero h1{font-size:21px}
    .ga-hero-actions,.ga-hero-actions .ga-btn{width:100%;justify-content:center}
    .ga-stat-grid{grid-template-columns:1fr}
    .ga-toolkit-row{grid-template-columns:1fr}
}
</style>

<div class="ga">

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:20px;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
    <?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:20px;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="ga-hero">
    <div class="ga-hero-l">
        <div class="ga-hero-ico"><?php echo cn_svg('growth'); ?></div>
        <div>
            <h1>Growth Accelerator</h1>
            <p>Welcome automation &amp; referral management to turn first contacts into loyal customers.</p>
        </div>
    </div>
    <div class="ga-hero-actions">
        <a href="https://wa.me/?text=<?php echo urlencode("Check out " . ($company['name'] ?? 'us') . "! " . $referralLink); ?>" target="_blank" rel="noopener" class="ga-btn">Share referral link</a>
        <a href="#welcomeForm" class="ga-btn ga-btn--primary">Edit welcome message</a>
    </div>
</div>

<!-- ── Stat Row ── -->
<?php $__wOn = !empty($company['welcome_enabled']); $__mOn = !empty($company['welcome_media_url']); ?>
<div class="ga-stat-grid">

    <div class="ga-stat is-blue">
        <div class="ga-stat-ico"><?php echo cn_svg('contacts'); ?></div>
        <div>
            <div class="ga-stat-val"><?php echo number_format($refStats['clicks']); ?></div>
            <div class="ga-stat-lbl">Referral Clicks</div>
        </div>
    </div>

    <div class="ga-stat is-amber">
        <div class="ga-stat-ico"><?php echo cn_svg('loyalty'); ?></div>
        <div>
            <div class="ga-stat-val"><?php echo number_format($refStats['points']); ?></div>
            <div class="ga-stat-lbl">Points Earned</div>
        </div>
    </div>

    <div class="ga-stat <?php echo $__wOn ? '' : 'is-off'; ?>">
        <div class="ga-stat-ico"><?php echo cn_svg('chat'); ?></div>
        <div>
            <div class="ga-stat-val sm <?php echo $__wOn ? 'on' : 'off'; ?>"><?php echo $__wOn ? 'Active' : 'Inactive'; ?></div>
            <div class="ga-stat-lbl">Welcome Message</div>
        </div>
    </div>

    <div class="ga-stat <?php echo $__mOn ? '' : 'is-off'; ?>">
        <div class="ga-stat-ico"><?php echo cn_svg('ocr'); ?></div>
        <div>
            <div class="ga-stat-val sm <?php echo $__mOn ? 'on' : 'off'; ?>"><?php echo $__mOn ? 'Attached' : 'None'; ?></div>
            <div class="ga-stat-lbl">Media Asset</div>
        </div>
    </div>

</div>

<!-- ── Main 2-col grid ── -->
<div class="ga-main-grid">

    <!-- Smart Welcome Engine -->
    <div class="ga-card">
        <div class="ga-card-hd">
            <div style="display:flex;align-items:center;gap:11px;">
                <div class="ga-card-ico"><?php echo cn_svg('chat'); ?></div>
                <div>
                    <div class="ga-card-title">Smart Welcome</div>
                    <div class="ga-card-sub">Auto-reply on first contact</div>
                </div>
            </div>
            <span class="ga-pill <?php echo $company['welcome_enabled'] ? 'ga-pill-on' : 'ga-pill-off'; ?>">
                <?php echo $company['welcome_enabled'] ? '● Active' : '○ Inactive'; ?>
            </span>
        </div>

        <form method="POST" enctype="multipart/form-data" id="welcomeForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="existing_welcome_media" value="<?php echo htmlspecialchars($company['welcome_media_url'] ?? ''); ?>">

            <div class="ga-card-bd">
                <!-- Message input -->
                <div style="margin-bottom:18px;">
                    <label class="ga-label">Welcome Message</label>
                    <textarea name="welcome_msg" id="waMsgInput" class="form-control" rows="5" placeholder="Type your welcome message here…"><?php echo htmlspecialchars($company['welcome_msg'] ?? ''); ?></textarea>
                    <div class="ga-charcount"><span id="charCount">0</span> / 1000 chars</div>
                </div>

                <!-- File upload -->
                <div style="margin-bottom:22px;">
                    <label class="ga-label">Media Attachment <span style="font-weight:400;color:var(--text-muted);">(JPG, PNG, WEBP, MP4 · max 15 MB)</span></label>
                    <div class="ga-dropzone" id="fileDropZone">
                        <input type="file" name="welcome_media" id="waMediaInput" accept="image/*,video/*">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 8px;opacity:.5;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                        <span id="fileNameLabel">Click or drag to upload</span>
                    </div>
                </div>

                <!-- WhatsApp phone preview -->
                <div class="ga-preview">
                    <div class="ga-preview-lbl">Live Preview</div>
                    <div class="wa-mock">
                        <div class="wa-mock-hd">
                            <div class="wa-mock-av"><?php echo strtoupper(substr($company['name'] ?? 'B', 0, 1)); ?></div>
                            <div>
                                <div style="color:#fff;font-size:11px;font-weight:600;"><?php echo htmlspecialchars($company['name'] ?? 'Your Business'); ?></div>
                                <div style="color:#25d366;font-size:9px;">online</div>
                            </div>
                        </div>
                        <div class="wa-mock-body">
                            <div class="wa-bubble">
                                <div id="waMediaPreviewContainer" style="<?php echo !empty($company['welcome_media_url']) ? '' : 'display:none;'; ?>margin-bottom:6px;border-radius:6px;overflow:hidden;">
                                    <?php $isVid = (str_contains($company['welcome_media_url'] ?? '', '.mp4') || str_contains($company['welcome_media_url'] ?? '', '.mov')); ?>
                                    <img id="waImgPreview" src="<?php echo htmlspecialchars($company['welcome_media_url'] ?? ''); ?>" style="width:100%;display:<?php echo ($isVid || empty($company['welcome_media_url'])) ? 'none' : 'block'; ?>;">
                                    <video id="waVideoPreview" src="<?php echo $isVid ? htmlspecialchars($company['welcome_media_url']) : ''; ?>" style="width:100%;display:<?php echo $isVid ? 'block' : 'none'; ?>;" muted autoplay loop playsinline></video>
                                </div>
                                <div id="waPreviewText" style="white-space:pre-wrap;"><?php echo htmlspecialchars($company['welcome_msg'] ?? 'Hello! Welcome.'); ?></div>
                                <div style="font-size:9px;color:#999;text-align:right;margin-top:3px;">10:42 AM <span style="color:#53bdeb;font-weight:700;">✓✓</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ga-footer">
                <label>
                    <input type="checkbox" name="welcome_enabled" <?php echo $company['welcome_enabled'] ? 'checked' : ''; ?>>
                    Enable Welcome Automation
                </label>
                <button type="submit" name="save_welcome" class="btn btn-primary" id="saveWelcomeBtn" style="padding:9px 20px;font-size:13px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:5px;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Referral Engine -->
    <div class="ga-card">
        <div class="ga-card-hd">
            <div style="display:flex;align-items:center;gap:11px;">
                <div class="ga-card-ico"><?php echo cn_svg('referral'); ?></div>
                <div>
                    <div class="ga-card-title">Referral Engine</div>
                    <div class="ga-card-sub">Reward customers for new leads</div>
                </div>
            </div>
        </div>

        <div class="ga-card-bd">

            <!-- Points config -->
            <form method="POST" style="margin-bottom:22px;">
                <?php echo csrf_field(); ?>
                <label class="ga-label">Points per Successful Referral</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="number" name="referral_points_value" class="form-control" value="<?php echo (int)($company['referral_points_value'] ?? 10); ?>" min="0" style="max-width:130px;">
                    <button type="submit" name="save_referral" class="btn btn-edit" style="flex-shrink:0;padding:9px 18px;font-size:13px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                        Update
                    </button>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">Each referral link click earns this many points for the sharer.</div>
            </form>

            <div class="ga-divider"></div>

            <!-- Referral link -->
            <div style="margin-bottom:20px;">
                <label class="ga-label">Your Referral Link</label>
                <div class="ga-refbox">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <input type="text" readonly value="<?php echo htmlspecialchars($referralLink); ?>" id="refLink">
                    <button onclick="copyRef()" class="btn btn-primary btn-sm" style="flex-shrink:0;padding:6px 14px;font-size:12px;" id="copyBtn">Copy</button>
                </div>
            </div>

            <!-- Mini stats -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px;">
                <div class="ga-mini-stat">
                    <span class="ga-mini-val" style="color:var(--accent);"><?php echo number_format($refStats['clicks']); ?></span>
                    <span class="ga-mini-lbl">Referral Clicks</span>
                </div>
                <div class="ga-mini-stat">
                    <span class="ga-mini-val" style="color:#ca8a04;"><?php echo number_format($refStats['points']); ?></span>
                    <span class="ga-mini-lbl">Points Earned</span>
                </div>
            </div>

            <div class="ga-divider"></div>

            <!-- WhatsApp share -->
            <a href="https://wa.me/?text=<?php echo urlencode("Check out " . ($company['name'] ?? 'us') . "! " . $referralLink); ?>" target="_blank" rel="noopener" class="btn w-100" style="background:#25D366;color:#fff;border-color:#25D366;justify-content:center;padding:12px;font-weight:600;font-size:13px;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" style="display:inline;vertical-align:middle;margin-right:8px;flex-shrink:0;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.335-1.662c1.72.937 3.659 1.435 5.621 1.435h.008c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                Share via WhatsApp
            </a>
        </div>
    </div>

</div>

<!-- ── Quick Access Toolkit ── -->
<div class="ga-card">
    <div class="ga-card-hd">
        <div style="display:flex;align-items:center;gap:11px;">
            <div class="ga-card-ico"><?php echo cn_svg('flows'); ?></div>
            <div>
                <div class="ga-card-title">Quick Access</div>
                <div class="ga-card-sub">Jump to related tools</div>
            </div>
        </div>
    </div>
    <div class="ga-toolkit-row">

        <a href="catalog.php" class="ga-link">
            <div class="ga-link-ico"><?php echo cn_svg('package'); ?></div>
            <div style="min-width:0;">
                <div class="ga-link-title">Product Catalog</div>
                <div class="ga-link-sub">Manage inventory</div>
            </div>
            <svg class="ga-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>

        <a href="lead_automation.php" class="ga-link">
            <div class="ga-link-ico"><?php echo cn_svg('ai'); ?></div>
            <div style="min-width:0;">
                <div class="ga-link-title">Lead Scoring</div>
                <div class="ga-link-sub">Qualify prospects</div>
            </div>
            <svg class="ga-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>

        <a href="brochure_sender.php" class="ga-link">
            <div class="ga-link-ico"><?php echo cn_svg('profile'); ?></div>
            <div style="min-width:0;">
                <div class="ga-link-title">Digital V-Card</div>
                <div class="ga-link-sub">Share your profile</div>
            </div>
            <svg class="ga-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>

        <a href="spam_protect.php" class="ga-link">
            <div class="ga-link-ico"><?php echo cn_svg('shield'); ?></div>
            <div style="min-width:0;">
                <div class="ga-link-title">Spam Protection</div>
                <div class="ga-link-sub">Block unwanted contacts</div>
            </div>
            <svg class="ga-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>

    </div>
</div>

</div><!-- .ga -->

<script>
(function() {
    // ── Character counter ──
    var msgInput    = document.getElementById('waMsgInput');
    var charCount   = document.getElementById('charCount');
    var waPreview   = document.getElementById('waPreviewText');

    function updateMsg() {
        var v = msgInput.value;
        charCount.textContent = v.length;
        waPreview.textContent = v || 'Type a message to preview…';
    }
    msgInput.addEventListener('input', updateMsg);
    updateMsg();

    // ── File upload / drop zone ──
    var mediaInput  = document.getElementById('waMediaInput');
    var dropZone    = document.getElementById('fileDropZone');
    var fileLabel   = document.getElementById('fileNameLabel');
    var imgPreview  = document.getElementById('waImgPreview');
    var vidPreview  = document.getElementById('waVideoPreview');
    var mediaBox    = document.getElementById('waMediaPreviewContainer');

    mediaInput.addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        fileLabel.textContent = file.name + ' (' + (file.size / (1024 * 1024)).toFixed(1) + ' MB)';
        dropZone.classList.add('active');

        var reader = new FileReader();
        reader.onload = function(e) {
            mediaBox.style.display = 'block';
            if (file.type.startsWith('video/')) {
                imgPreview.style.display = 'none';
                vidPreview.src  = e.target.result;
                vidPreview.style.display = 'block';
            } else {
                vidPreview.style.display = 'none';
                imgPreview.src  = e.target.result;
                imgPreview.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    });

    // ── Welcome form loading state ──
    document.getElementById('welcomeForm').addEventListener('submit', function() {
        var btn = document.getElementById('saveWelcomeBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ga-spinning" style="display:inline;vertical-align:middle;margin-right:5px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Saving…';
    });
})();

// ── Copy referral link ──
function copyRef() {
    var link = document.getElementById('refLink').value;
    var btn  = document.getElementById('copyBtn');
    navigator.clipboard.writeText(link).then(function() {
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>Copied!';
        btn.style.background    = 'var(--success)';
        btn.style.borderColor   = 'var(--success)';
        setTimeout(function() {
            btn.innerHTML        = orig;
            btn.style.background = '';
            btn.style.borderColor = '';
        }, 2500);
    });
}
</script>

<?php include '../includes/client_footer.php'; ?>
