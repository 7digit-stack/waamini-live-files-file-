<?php
/**
 * Landing Page CMS — Database Setup
 * Run once: http://localhost:8888/waamini/landing-admin/setup.php
 * Creates all lp_* tables and seeds default data + first admin account.
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../db.php';

$log = [];
$ok  = true;

function run(mysqli $conn, string $sql, string $label, array &$log, bool &$ok): void {
    if ($conn->query($sql)) {
        $log[] = ['ok',  $label];
    } else {
        $log[] = ['err', $label . ' — ' . $conn->error];
        $ok = false;
    }
}

/* ── 1. lp_admins ─────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_admins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    email      VARCHAR(200) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    is_active  TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_admins', $log, $ok);

/* ── 2. lp_settings ───────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_settings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    `key`      VARCHAR(100) NOT NULL UNIQUE,
    `value`    LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_settings', $log, $ok);

/* ── 3. lp_hero ───────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_hero (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    page             VARCHAR(50) NOT NULL DEFAULT 'home',
    announce_text    VARCHAR(500),
    headline         VARCHAR(500),
    highlight_word   VARCHAR(200),
    subtext          TEXT,
    cta_primary_text VARCHAR(100)  DEFAULT 'Start Free Trial',
    cta_primary_url  VARCHAR(255)  DEFAULT '/register.php',
    cta_sec_text     VARCHAR(100)  DEFAULT 'See How It Works',
    cta_sec_url      VARCHAR(255)  DEFAULT '#features',
    stats_json       LONGTEXT,
    is_active        TINYINT(1)   DEFAULT 1,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_page (page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_hero', $log, $ok);

/* ── 4. lp_blog_categories ────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_blog_categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(120) NOT NULL UNIQUE,
    color      VARCHAR(20)  DEFAULT '#00d2ff',
    sort_order INT          DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_blog_categories', $log, $ok);

/* ── 5. lp_blogs ──────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_blogs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    category_id  INT DEFAULT NULL,
    title        VARCHAR(500) NOT NULL,
    slug         VARCHAR(520) NOT NULL UNIQUE,
    excerpt      TEXT,
    body         LONGTEXT,
    cover_image  VARCHAR(500),
    author_name  VARCHAR(200) DEFAULT 'Waamini Team',
    author_avatar VARCHAR(500) DEFAULT NULL,
    read_time    VARCHAR(20)  DEFAULT '5 min',
    status       ENUM('draft','published') DEFAULT 'draft',
    featured     TINYINT(1)  DEFAULT 0,
    views        INT          DEFAULT 0,
    meta_title   VARCHAR(500) DEFAULT NULL,
    meta_desc    TEXT         DEFAULT NULL,
    published_at DATETIME     DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug(255)),
    INDEX idx_status (status),
    INDEX idx_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_blogs', $log, $ok);

/* ── 6. lp_media ──────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_media (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    filename      VARCHAR(500) NOT NULL,
    original_name VARCHAR(500),
    mime_type     VARCHAR(100),
    file_size     INT DEFAULT 0,
    width         INT DEFAULT NULL,
    height        INT DEFAULT NULL,
    alt_text      VARCHAR(500) DEFAULT '',
    url           VARCHAR(600),
    uploaded_by   INT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_media', $log, $ok);

/* ── 7. lp_features ───────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_features (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    icon        VARCHAR(10)  DEFAULT '⚡',
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    badge       VARCHAR(100) DEFAULT NULL,
    sort_order  INT          DEFAULT 0,
    is_active   TINYINT(1)  DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_features', $log, $ok);

/* ── 8. lp_pricing ────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_pricing (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    tagline        VARCHAR(200) DEFAULT NULL,
    price_monthly  DECIMAL(10,2) DEFAULT 0.00,
    price_yearly   DECIMAL(10,2) DEFAULT 0.00,
    currency       VARCHAR(10)  DEFAULT 'INR',
    is_popular     TINYINT(1)  DEFAULT 0,
    features_json  LONGTEXT,
    cta_text       VARCHAR(100) DEFAULT 'Get Started',
    cta_url        VARCHAR(255) DEFAULT '/register.php',
    sort_order     INT          DEFAULT 0,
    is_active      TINYINT(1)  DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_pricing', $log, $ok);

/* ── 9. lp_testimonials ───────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_testimonials (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200),
    company    VARCHAR(200),
    role       VARCHAR(200),
    avatar_url VARCHAR(500) DEFAULT NULL,
    content    TEXT,
    rating     TINYINT DEFAULT 5,
    industry   VARCHAR(100) DEFAULT NULL,
    is_active  TINYINT(1)  DEFAULT 1,
    sort_order INT          DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_testimonials', $log, $ok);

/* ── 10. lp_page_sections ─────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_page_sections (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    page        VARCHAR(50)  NOT NULL,
    section_key VARCHAR(100) NOT NULL,
    title       VARCHAR(500) DEFAULT NULL,
    content     LONGTEXT,
    image_url   VARCHAR(500) DEFAULT NULL,
    data_json   LONGTEXT,
    is_active   TINYINT(1)  DEFAULT 1,
    sort_order  INT          DEFAULT 0,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_page_sec (page, section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_page_sections', $log, $ok);

/* ── 11. lp_alerts ────────────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_alerts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    type             VARCHAR(50)  NOT NULL DEFAULT 'info',
    message          TEXT         NOT NULL,
    link_text        VARCHAR(200) DEFAULT NULL,
    link_url         VARCHAR(500) DEFAULT NULL,
    btn_text         VARCHAR(100) DEFAULT NULL,
    btn_url          VARCHAR(500) DEFAULT NULL,
    countdown_label  VARCHAR(100) DEFAULT NULL,
    countdown_target DATETIME     DEFAULT NULL,
    start_date       DATETIME     DEFAULT NULL,
    end_date         DATETIME     DEFAULT NULL,
    bg_color         VARCHAR(20)  DEFAULT '#1e40af',
    text_color       VARCHAR(20)  DEFAULT '#ffffff',
    is_active        TINYINT(1)  DEFAULT 1,
    is_dismissible   TINYINT(1)  DEFAULT 1,
    sort_order       INT          DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_alerts', $log, $ok);

/* ── 12. lp_offer_counters ─────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_offer_counters (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) DEFAULT NULL,
    subtitle         VARCHAR(500) DEFAULT NULL,
    countdown_target DATETIME     DEFAULT NULL,
    countdown_label  VARCHAR(100) DEFAULT 'Offer ends in',
    bg_color         VARCHAR(20)  DEFAULT '#1e40af',
    text_color       VARCHAR(20)  DEFAULT '#ffffff',
    accent_color     VARCHAR(20)  DEFAULT '#fbbf24',
    btn_text         VARCHAR(100) DEFAULT 'Claim Offer',
    btn_url          VARCHAR(500) DEFAULT '/register.php',
    position         VARCHAR(20)  DEFAULT 'bottom' COMMENT 'bottom, floating',
    is_sticky        TINYINT(1)  DEFAULT 1,
    is_active        TINYINT(1)  DEFAULT 1,
    is_dismissible   TINYINT(1)  DEFAULT 1,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_offer_counters', $log, $ok);

/* ── 13. lp_alert_popups ───────────────────────────────────────────── */
run($conn, "CREATE TABLE IF NOT EXISTS lp_alert_popups (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) DEFAULT NULL,
    message          TEXT,
    image_url        VARCHAR(500) DEFAULT NULL,
    btn_text         VARCHAR(100) DEFAULT 'Get Started',
    btn_url          VARCHAR(500) DEFAULT '/register.php',
    bg_color         VARCHAR(20)  DEFAULT '#ffffff',
    text_color       VARCHAR(20)  DEFAULT '#0f172a',
    accent_color     VARCHAR(20)  DEFAULT '#006df9',
    show_delay       INT          DEFAULT 3 COMMENT 'Seconds delay before showing',
    show_frequency   VARCHAR(20)  DEFAULT 'once' COMMENT 'once, session, always',
    is_active        TINYINT(1)  DEFAULT 1,
    is_dismissible   TINYINT(1)  DEFAULT 1,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'lp_alert_popups', $log, $ok);

/* ═══ SEED DEFAULT DATA ═══════════════════════════════════════════ */

/* Default admin — email: admin@waamini.com / password: Admin@123 */
$defPass = password_hash('Admin@123', PASSWORD_DEFAULT);
$conn->query("INSERT IGNORE INTO lp_admins (name,email,password) VALUES ('Landing Admin','admin@waamini.com','$defPass')");
$log[] = ['ok', 'Default admin: admin@waamini.com / Admin@123'];

/* Default settings */
$defaults = [
    'site_name'    => 'Waamini',
    'tagline'      => 'WhatsApp CRM & Automation Platform',
    'support_email'=> 'support@waamini.com',
    'logo_url'     => '',
    'favicon_url'  => '',
    'ga_id'        => '',
    'footer_text'  => '© 2026 Waamini. All rights reserved.',
    'og_image'     => '',
    'twitter_handle'=> '',
    'linkedin_url' => '',
    'instagram_url'=> '',
];
foreach ($defaults as $k => $v) {
    $ks = $conn->real_escape_string($k);
    $vs = $conn->real_escape_string($v);
    $conn->query("INSERT IGNORE INTO lp_settings (`key`,`value`) VALUES ('$ks','$vs')");
}
$log[] = ['ok', 'Default settings seeded'];

/* Default alerts */
$alertSt = $conn->prepare("INSERT IGNORE INTO lp_alerts (type,message,link_text,link_url,bg_color,text_color,is_active,is_dismissible,sort_order) VALUES (?,?,?,?,?,?,1,1,0)");
if ($alertSt) {
    $alerts = [
        ['offer', '🚀 Get 50% OFF on Annual Plan — Limited Time Offer!', 'Claim Now', '/register.php', '#dc2626', '#ffffff'],
        ['info',  '💬 WhatsApp Business API is now live for all Indian businesses.', 'Learn More', '/features.php', '#1e40af', '#ffffff'],
        ['login', '👋 Welcome back! Log in to manage your campaigns.', 'Log In', '/login.php', '#065f46', '#ffffff'],
    ];
    foreach ($alerts as $a) {
        $alertSt->bind_param('ssssss', $a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
        $alertSt->execute();
    }
    $alertSt->close();
    $log[] = ['ok', 'Default alerts seeded (3)'];
}

/* Default offer counter — sticky bottom bar */
$offerSt = $conn->prepare("INSERT IGNORE INTO lp_offer_counters (title,subtitle,countdown_target,countdown_label,bg_color,text_color,accent_color,btn_text,btn_url,position,is_sticky,is_active,is_dismissible) VALUES (?,?,?,?,?,?,?,?,?,?,1,1,1)");
if ($offerSt) {
    $offers = [
        ['🎉 Limited Time Offer', 'Get 50% OFF on Annual Plan + Free Setup. Offer ends soon!', date('Y-m-d H:i:s', strtotime('+7 days')), 'Offer ends in', '#1e40af', '#ffffff', '#fbbf24', 'Claim 50% OFF', '/register.php', 'bottom'],
    ];
    foreach ($offers as $o) {
        $offerSt->bind_param('ssssssssss', $o[0], $o[1], $o[2], $o[3], $o[4], $o[5], $o[6], $o[7], $o[8], $o[9]);
        $offerSt->execute();
    }
    $offerSt->close();
    $log[] = ['ok', 'Default offer counter seeded (1)'];
}

/* Default alert popup — modal with image */
$popupSt = $conn->prepare("INSERT IGNORE INTO lp_alert_popups (title,message,image_url,btn_text,btn_url,bg_color,text_color,accent_color,show_delay,show_frequency,is_active,is_dismissible) VALUES (?,?,?,?,?,?,?,?,?,?,1,1)");
if ($popupSt) {
    $popups = [
        ['🚀 WhatsApp API is Live!', 'Connect your WhatsApp Business number in 5 minutes and start sending bulk campaigns, automated replies, and more — with zero markup on Meta rates.', '', 'Start Free Trial', '/register.php', '#ffffff', '#0f172a', '#006df9', 5, 'once'],
    ];
    foreach ($popups as $p) {
        $popupSt->bind_param('ssssssssis', $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9]);
        $popupSt->execute();
    }
    $popupSt->close();
    $log[] = ['ok', 'Default alert popup seeded (1)'];
}

/* Default hero for home page */
$stats = json_encode([
    ['value'=>'2,400+','label'=>'Businesses'],
    ['value'=>'98%','label'=>'Open Rate'],
    ['value'=>'10×','label'=>'ROI Average'],
    ['value'=>'24/7','label'=>'Support'],
]);
$hs = $conn->real_escape_string($stats);
$conn->query("INSERT IGNORE INTO lp_hero (page,announce_text,headline,highlight_word,subtext,stats_json)
VALUES ('home',
'⭐ Rated 4.9/5 by 2,400+ businesses across India',
'Close More Deals with WhatsApp Automation',
'WhatsApp Automation',
'Turn WhatsApp into your most powerful sales & support channel. Automate follow-ups, broadcast campaigns, and delight customers — all from one smart dashboard.',
'$hs')");
$log[] = ['ok', 'Default hero content seeded'];

/* Default blog categories */
$cats = [
    ['WhatsApp API', 'whatsapp-api', '#00b894'],
    ['Automation',   'automation',   '#a29bfe'],
    ['Campaigns',    'campaigns',    '#fdcb6e'],
    ['Growth Tips',  'growth-tips',  '#00d2ff'],
    ['Case Studies', 'case-studies', '#ff7675'],
];
foreach ($cats as [$name,$slug,$color]) {
    $n = $conn->real_escape_string($name);
    $s = $conn->real_escape_string($slug);
    $c = $conn->real_escape_string($color);
    $conn->query("INSERT IGNORE INTO lp_blog_categories (name,slug,color) VALUES ('$n','$s','$c')");
}
$log[] = ['ok', 'Blog categories seeded (5)'];

/* Default features */
$features = [
    ['💬', 'Multi-Agent Inbox',      'Route conversations to the right team member automatically.',      'Live',   1],
    ['🚀', 'Broadcast Campaigns',    'Send approved WhatsApp templates to thousands — instantly.',       'Hot',    2],
    ['🤖', 'AI Chatbot Builder',     'Build no-code chatbots that qualify leads and close sales 24/7.',  'AI',     3],
    ['📊', 'Real-Time Analytics',    'Track open rates, reply rates, and conversion metrics live.',      null,     4],
    ['🔗', 'CRM Integrations',       'Connect with Zoho, HubSpot, Shopify, and 1000+ tools.',           null,     5],
    ['🛡️', 'Meta Verified Platform', 'Officially approved by Meta for Business. Your number is safe.',  'Secure', 6],
];
foreach ($features as [$icon,$title,$desc,$badge,$sort]) {
    $icon  = $conn->real_escape_string($icon);
    $title = $conn->real_escape_string($title);
    $desc  = $conn->real_escape_string($desc);
    $badge = $badge ? "'".$conn->real_escape_string($badge)."'" : 'NULL';
    $conn->query("INSERT IGNORE INTO lp_features (icon,title,description,badge,sort_order) VALUES ('$icon','$title','$desc',$badge,$sort)");
}
$log[] = ['ok', 'Default features seeded (6)'];

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Landing CMS Setup</title>
<style>body{font-family:system-ui;background:#090d18;color:#f0f4ff;padding:40px;max-width:600px;margin:0 auto;}
h1{color:#00d2ff;margin-bottom:24px;}.ok{color:#00e676;}.err{color:#ff4757;}
li{padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;}
.box{background:#101724;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:24px;margin-top:24px;}
a{color:#00d2ff;}</style></head><body>
<h1>🚀 Landing CMS Setup</h1><ul>';
foreach ($log as [$type, $msg]) {
    echo '<li class="'.$type.'">'.($type==='ok'?'✅':'❌').' '.htmlspecialchars($msg).'</li>';
}
echo '</ul><div class="box"><strong>'.($ok?'✅ All done!':'⚠️ Some errors — check above.').'</strong><br><br>';
if ($ok) {
    echo 'Default login: <strong>admin@waamini.com</strong> / <strong>Admin@123</strong><br><br>';
    echo '<a href="/landing-admin/login.php">→ Open Landing Admin</a><br><br>';
    echo '<small style="color:#6b7a99;">⚠️ Delete or protect setup.php after first run.</small>';
}
echo '</div></body></html>';
