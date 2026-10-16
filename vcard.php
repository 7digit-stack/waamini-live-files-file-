<?php
include 'db.php';

$cid = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($cid <= 0) {
    die("Invalid Business Card ID.");
}

// Fetch Company Info
$stmt_c = $conn->prepare("SELECT * FROM companies WHERE id = ?");
$stmt_c->bind_param("i", $cid);
$stmt_c->execute();
$company = $stmt_c->get_result()->fetch_assoc();

if (!$company) {
    die("Business not found.");
}

// Fetch vCard Settings
$stmt_s = $conn->prepare("SELECT * FROM vcard_settings WHERE company_id = ?");
$stmt_s->bind_param("i", $cid);
$stmt_s->execute();
$settings = $stmt_s->get_result()->fetch_assoc();

if (!$settings) {
    // Default settings if not configured
    $settings = [
        'theme_color' => '#00b894',
        'business_bio' => 'Welcome to our official business card. Feel free to reach out to us!',
        'logo_url' => '',
        'social_fb' => '', 'social_ig' => '', 'social_tw' => '', 'social_li' => '', 'social_yt' => ''
    ];
}

// Increment View Count
$stmt_upd = $conn->prepare("UPDATE vcard_settings SET view_count = view_count + 1 WHERE company_id = ?");
$stmt_upd->bind_param("i", $cid);
$stmt_upd->execute();

$themeColor = $settings['theme_color'] ?: '#00b894';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company['name']); ?> – Business Card</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: <?php echo $themeColor; ?>; --bg: #0f172a; --card-bg: #1e293b; --text: #f8fafc; --text-muted: #94a3b8; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); color: var(--text); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .vcard { background: var(--card-bg); width: 100%; max-width: 400px; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.05); }
        .vcard-header { background: linear-gradient(135deg, var(--primary), #6366f1); height: 120px; position: relative; }
        .vcard-logo { width: 100px; height: 100px; background: #fff; border-radius: 50%; position: absolute; bottom: -50px; left: 50%; transform: translateX(-50%); border: 4px solid var(--card-bg); display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 800; color: var(--primary); overflow: hidden; }
        .vcard-logo img { width: 100%; height: 100%; object-fit: cover; }
        .vcard-body { padding: 60px 24px 30px; text-align: center; }
        .vcard-name { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .vcard-tagline { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; }
        .vcard-bio { font-size: 15px; line-height: 1.6; color: #cbd5e1; margin-bottom: 24px; padding: 0 10px; }
        .vcard-actions { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 30px; }
        .btn { padding: 14px; border-radius: 12px; font-weight: 600; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 10px; transition: 0.2s; }
        .btn-whatsapp { background: #25d366; color: #fff; }
        .btn-whatsapp:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -10px #25d366aa; }
        .btn-call { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.1); }
        .btn-call:hover { background: rgba(255,255,255,0.1); }
        .social-links { display: flex; justify-content: center; gap: 15px; margin-bottom: 30px; }
        .social-icon { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 20px; text-decoration: none; color: #fff; transition: 0.2s; }
        .social-icon:hover { background: var(--primary); transform: scale(1.1); }
        .footer-brand { font-size: 12px; color: var(--text-muted); margin-top: 20px; }
        .footer-brand span { color: var(--primary); font-weight: 700; }
    </style>
</head>
<body>

<div class="vcard">
    <div class="vcard-header">
        <div class="vcard-logo">
            <?php if ($settings['logo_url']): ?>
                <img src="<?php echo htmlspecialchars($settings['logo_url']); ?>" alt="Logo">
            <?php
else: ?>
                <?php echo strtoupper(substr($company['name'], 0, 1)); ?>
            <?php
endif; ?>
        </div>
    </div>
    
    <div class="vcard-body">
        <h1 class="vcard-name"><?php echo htmlspecialchars($company['name']); ?></h1>
        <p class="vcard-tagline">Official Business Contact</p>
        
        <p class="vcard-bio"><?php echo nl2br(htmlspecialchars($settings['business_bio'])); ?></p>
        
        <div class="vcard-actions">
            <a href="https://wa.me/<?php echo $company['phone']; ?>" class="btn btn-whatsapp">
                <svg style="width:20px;height:20px;" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.301-.144-1.767-.872-2.04-.967-.272-.098-.47-.144-.666.144-.199.299-.765.959-.937 1.157-.174.196-.346.223-.646.076-.302-.145-1.272-.469-2.426-1.493-.894-.799-1.496-1.791-1.674-2.09-.176-.298-.019-.459.131-.603.137-.132.302-.345.453-.518.151-.174.2-.299.302-.497.1-.198.05-.371-.026-.519-.075-.149-.665-1.611-.914-2.207-.242-.579-.487-.5-.669-.51-.177-.008-.383-.012-.583-.012-.2 0-.52.074-.794.374-.274.296-1.045 1.015-1.045 2.479 0 1.463 1.066 2.877 1.213 3.076.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414-.076-.124-.272-.2-.574-.343zM12.016 1.812c-5.54 0-10.048 4.506-10.048 10.045 0 1.772.463 3.504 1.341 5.03l-1.424 5.216 5.344-1.402c1.472.801 3.13 1.223 4.819 1.224 5.541 0 10.048-4.506 10.048-10.045 0-2.684-1.045-5.207-2.943-7.106-1.897-1.897-4.42-2.942-7.105-2.942z"/></svg>
                Message on WhatsApp
            </a>
            <a href="tel:<?php echo $company['phone']; ?>" class="btn btn-call">
                📞 Call Now
            </a>
        </div>
        
        <div class="social-links">
            <?php if ($settings['social_fb']): ?><a href="<?php echo htmlspecialchars($settings['social_fb']); ?>" class="social-icon">f</a><?php
endif; ?>
            <?php if ($settings['social_ig']): ?><a href="<?php echo htmlspecialchars($settings['social_ig']); ?>" class="social-icon">ig</a><?php
endif; ?>
            <?php if ($settings['social_li']): ?><a href="<?php echo htmlspecialchars($settings['social_li']); ?>" class="social-icon">in</a><?php
endif; ?>
        </div>
        
        <div class="footer-brand">
            Powered by <span>WAAMINI</span>
        </div>
    </div>
</div>

</body>
</html>
