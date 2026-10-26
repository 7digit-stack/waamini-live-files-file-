<?php
/**
 * Admin > Website CMS: opens the landing-page editor (/landing-admin/) already signed in.
 *
 * The CMS keeps its own login (lp_admins table, "la_session" cookie). A signed-in super admin is
 * matched to the CMS account with the same email; if there is none yet, one is created with an
 * unusable random password (so it can only be entered through this page, not the CMS login form).
 */
include '../includes/auth_check.php'; // super_admin only
include '../db.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
$st = $conn->prepare("SELECT name, email FROM users WHERE id = ? AND role = 'super_admin' AND status = 'active' LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$st->close();
if (!$me || trim((string)$me['email']) === '') {
    header('Location: /admin/dashboard.php');
    exit();
}
$email = trim((string)$me['email']);
$name  = trim((string)$me['name']) !== '' ? (string)$me['name'] : 'Admin';

$st = $conn->prepare("SELECT id, name, email, is_active FROM lp_admins WHERE email = ? LIMIT 1");
$st->bind_param('s', $email);
$st->execute();
$la = $st->get_result()->fetch_assoc();
$st->close();

if (!$la) {
    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    $st = $conn->prepare("INSERT INTO lp_admins (name, email, password, is_active) VALUES (?, ?, ?, 1)");
    $st->bind_param('sss', $name, $email, $unusable);
    $st->execute();
    $la = ['id' => (int)$st->insert_id, 'name' => $name, 'email' => $email, 'is_active' => 1];
    $st->close();
} elseif ((int)$la['is_active'] !== 1) {
    $pageTitle = 'Website CMS';
    $currentPage = 'website_cms';
    include '../includes/admin_header.php';
    echo '<div class="card" style="padding:24px;max-width:640px">The website CMS account for <b>' . htmlspecialchars($email) . '</b> is switched off. Turn it on again in the lp_admins table (is_active = 1).</div>';
    include '../includes/admin_footer.php';
    exit();
}

// Switch to the CMS session (separate cookie) and sign in there.
session_write_close();
session_name('la_session');
if (empty($_COOKIE['la_session'])) session_id(session_create_id());
session_start();
session_regenerate_id(true);
$_SESSION['la_admin_id']    = (int)$la['id'];
$_SESSION['la_admin_name']  = (string)$la['name'];
$_SESSION['la_admin_email'] = (string)$la['email'];
session_write_close();

header('Location: /landing-admin/');
exit();
