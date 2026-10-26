<?php
/**
 * Public "Send us a message" form on /support.php.
 * Saves the message (website_enquiries) and emails it to the support inbox through the SMTP saved in
 * Admin > Email / SMTP. No login needed. The page is cached, so instead of a CSRF token this checks that
 * the request comes from this site, uses a hidden honeypot field and allows 5 messages per IP per hour.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sc_out(int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sc_out(405, ['ok' => false, 'error' => 'Method not allowed']);

// same-site only
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$from = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($from !== '' && strtolower((string)parse_url($from, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) {
    sc_out(403, ['ok' => false, 'error' => 'Please send the form from our website.']);
}

// bots fill every field: a filled honeypot gets a fake success and nothing is stored
if (trim((string)($_POST['website'] ?? '')) !== '') sc_out(200, ['ok' => true]);

$name    = mb_substr(trim(strip_tags((string)($_POST['name'] ?? ''))), 0, 120);
$email   = trim((string)($_POST['email'] ?? ''));
$phone   = mb_substr(preg_replace('/[^\d+\s-]/', '', (string)($_POST['phone'] ?? '')), 0, 25);
$topics  = ['general' => 'General question', 'sales' => 'Sales / pricing', 'technical' => 'Technical help', 'api' => 'WhatsApp API setup', 'billing' => 'Billing'];
$topic   = $topics[(string)($_POST['subject'] ?? '')] ?? 'General question';
$message = mb_substr(trim(strip_tags((string)($_POST['message'] ?? ''))), 0, 4000);

$errors = [];
if (mb_strlen($name) < 2) $errors['name'] = 'Please enter your name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email address.';
if (mb_strlen($message) < 10) $errors['message'] = 'Please write a little more (at least 10 characters).';
if ($errors) sc_out(422, ['ok' => false, 'error' => reset($errors), 'fields' => $errors]);

$ip = substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

try {
    $conn->query("CREATE TABLE IF NOT EXISTS website_enquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(25) NULL,
        topic VARCHAR(60) NOT NULL, message TEXT NOT NULL, ip VARCHAR(45) NULL,
        status ENUM('new','read','replied') NOT NULL DEFAULT 'new', emailed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_created (created_at), KEY idx_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $rl = $conn->prepare("SELECT COUNT(*) FROM website_enquiries WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $rl->bind_param('s', $ip); $rl->execute(); $rl->bind_result($recent); $rl->fetch(); $rl->close();
    if ($recent >= 5) sc_out(429, ['ok' => false, 'error' => 'You have sent several messages already. Please wait a while, or call us.']);

    $ins = $conn->prepare("INSERT INTO website_enquiries (name, email, phone, topic, message, ip) VALUES (?,?,?,?,?,?)");
    $ins->bind_param('ssssss', $name, $email, $phone, $topic, $message, $ip);
    $ins->execute(); $id = (int)$ins->insert_id; $ins->close();
} catch (\Throwable $e) {
    error_log('[support_contact] ' . $e->getMessage());
    sc_out(500, ['ok' => false, 'error' => 'Could not save your message. Please email info@7digit.in.']);
}

// email the support team (best effort: the message is already saved)
$emailed = false;
try {
    require_once __DIR__ . '/../includes/mail_settings.php';
    require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
    $cfg = waamini_mail_resolve_config(waamini_mail_load_settings($conn));
    if (!empty($cfg['host']) && !empty($cfg['from_email'])) {
        $to = (string)(\Waamini\Config\Env::get('SUPPORT_EMAIL', '') ?: 'info@7digit.in');
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        waamini_mail_apply_config($m, $cfg);
        $m->CharSet = 'UTF-8';
        $m->addAddress($to);
        $m->addReplyTo($email, $name);
        $m->Subject = 'Website enquiry #' . $id . ' - ' . $topic . ' - ' . $name;
        $m->isHTML(false);
        $m->Body = "New message from the support page (waamini.com/support.php)\n\n"
                 . "Name:    $name\nEmail:   $email\nPhone:   " . ($phone !== '' ? $phone : '-') . "\nTopic:   $topic\n"
                 . "Sent:    " . date('d M Y, h:i A') . "\nIP:      $ip\n\nMessage:\n$message\n\n"
                 . "Reply to this email to answer the customer directly.";
        $m->send();
        $emailed = true;
        $u = $conn->prepare("UPDATE website_enquiries SET emailed = 1 WHERE id = ?");
        $u->bind_param('i', $id); $u->execute();
    }
} catch (\Throwable $e) {
    error_log('[support_contact] mail: ' . $e->getMessage());
}

sc_out(200, ['ok' => true, 'id' => $id, 'emailed' => $emailed]);
