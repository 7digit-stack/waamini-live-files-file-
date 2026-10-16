<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Digital Visiting Card';
$currentPage = 'digital_vcard';

// Migration handled schema change

if (isset($_POST['save'])) {
    csrf_verify();
    $fields=['name','designation','business_name','phone','whatsapp','email','website','address','tagline','logo_url','color_primary','services','social_fb','social_ig','social_linkedin'];
    $vals=[];foreach($fields as $f)$vals[$f]=sanitize($_POST[$f]??'');
    $stmt=$conn->prepare("INSERT INTO digital_vcards (company_id,name,designation,business_name,phone,whatsapp,email,website,address,tagline,logo_url,color_primary,services,social_fb,social_ig,social_linkedin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),designation=VALUES(designation),business_name=VALUES(business_name),phone=VALUES(phone),whatsapp=VALUES(whatsapp),email=VALUES(email),website=VALUES(website),address=VALUES(address),tagline=VALUES(tagline),logo_url=VALUES(logo_url),color_primary=VALUES(color_primary),services=VALUES(services),social_fb=VALUES(social_fb),social_ig=VALUES(social_ig),social_linkedin=VALUES(social_linkedin)");
    if ($stmt) {
        $stmt->bind_param("isssssssssssssss", $companyId, ...array_values($vals));
        $stmt->execute();
    } else {
        error_log('[WAAMINI] digital_vcard.php: prepare() failed for upsert');
    }
    header("Location: digital_vcard.php?msg=saved");exit();
}
$stmt_v=$conn->prepare("SELECT * FROM digital_vcards WHERE company_id=?");
$v = null;
if ($stmt_v) {
    $stmt_v->bind_param("i", $companyId);
    $stmt_v->execute();
    $v = $stmt_v->get_result()->fetch_assoc();
} else {
    error_log('[WAAMINI] digital_vcard.php: prepare() failed for select');
}
    $cardUrl = "https://yourdomain.com/vcard.php?id=$companyId";
include '../includes/client_header.php';
?>
<?php if(isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Card saved!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg> Digital Visiting Card</h2>
    <?php if($v): ?><div style="display:flex;gap:8px;"><a href="../vcard.php?id=<?php echo $companyId; ?>" target="_blank" class="btn btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg> Preview</a><button onclick="shareCard()" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Share via WA</button></div><?php endif; ?>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
    <?php echo csrf_field(); ?>
    <h3 style="font-size:13px;font-weight:700;color:var(--accent);margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Personal Info</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label>Your Name *</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($v['name']??''); ?>" required></div>
        <div class="form-group"><label>Designation</label><input type="text" name="designation" class="form-control" value="<?php echo htmlspecialchars($v['designation']??''); ?>" placeholder="CEO, Manager..."></div>
        <div class="form-group"><label>Business Name</label><input type="text" name="business_name" class="form-control" value="<?php echo htmlspecialchars($v['business_name']??''); ?>"></div>
        <div class="form-group"><label>Tagline</label><input type="text" name="tagline" class="form-control" value="<?php echo htmlspecialchars($v['tagline']??''); ?>" placeholder="Your business tagline"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($v['phone']??''); ?>"></div>
        <div class="form-group"><label>WhatsApp</label><input type="text" name="whatsapp" class="form-control" value="<?php echo htmlspecialchars($v['whatsapp']??''); ?>"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($v['email']??''); ?>"></div>
        <div class="form-group"><label>Website</label><input type="text" name="website" class="form-control" value="<?php echo htmlspecialchars($v['website']??''); ?>" placeholder="https://..."></div>
    </div>
    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($v['address']??''); ?></textarea></div>
    <div class="form-group"><label>Services (one per line)</label><textarea name="services" class="form-control" rows="3" placeholder="Web Design&#10;Digital Marketing&#10;SEO"><?php echo htmlspecialchars($v['services']??''); ?></textarea></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label>Card Color</label><input type="color" name="color_primary" class="form-control" value="<?php echo htmlspecialchars($v['color_primary']??'#6c63ff'); ?>" style="height:40px;cursor:pointer;"></div>
        <div class="form-group"><label>Logo URL</label><input type="text" name="logo_url" class="form-control" value="<?php echo htmlspecialchars($v['logo_url']??''); ?>"></div>
    </div>
    <h3 style="font-size:13px;font-weight:700;color:var(--accent);margin:16px 0 12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Social Links</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
        <div class="form-group"><label>Facebook</label><input type="text" name="social_fb" class="form-control" value="<?php echo htmlspecialchars($v['social_fb']??''); ?>"></div>
        <div class="form-group"><label>Instagram</label><input type="text" name="social_ig" class="form-control" value="<?php echo htmlspecialchars($v['social_ig']??''); ?>"></div>
        <div class="form-group"><label>LinkedIn</label><input type="text" name="social_linkedin" class="form-control" value="<?php echo htmlspecialchars($v['social_linkedin']??''); ?>"></div>
    </div>
    <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Card</button>
    </form>
</div>
<?php if($v): ?>
<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Share Your Card</h3>
        <div style="background:var(--bg-input);border-radius:8px;padding:10px;font-size:11px;word-break:break-all;margin-bottom:10px;"><?php echo $cardUrl; ?></div>
        <button onclick="navigator.clipboard.writeText('<?php echo $cardUrl; ?>').then(()=>alert('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Link copied!'))" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy Link</button>
    </div>
    <div class="form-card" style="max-width:none;text-align:center;">
        <div style="font-size:28px;font-weight:800;color:#6c63ff;"><?php echo $v['views']??0; ?></div>
        <div style="font-size:12px;color:var(--text-muted);">Total Card Views</div>
    </div>
</div>
<?php endif; ?>
</div>
<script>
function shareCard(){
    var url='<?php echo $cardUrl; ?>';
    var msg='Hi! Check out my digital visiting card <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>\n\n'+url;
    window.open('https://wa.me/?text='+encodeURIComponent(msg),'_blank');
}
</script>
<?php include '../includes/client_footer.php'; ?>
