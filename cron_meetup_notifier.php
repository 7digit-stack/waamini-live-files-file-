<?php
/**
 * WAAMINI Meetup/Demo Notification Cron
 * Schedule: * * * * * (every minute)
 *
 * Sends WhatsApp alerts for ALL upcoming demo bookings:
 *   • 1 HOUR before  → Tanglish + English reminder with time & Meet link
 *   • 5 MINUTES before → Tanglish + English "join now" alert
 *
 * Covers ALL statuses: scheduled, confirmed
 * Covers ALL bookings — new, old, everything with a demo_datetime
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/whatsapp_api_helper.php';

// Align the MySQL session timezone with IST so NOW()-based reminder windows are exact.
try { $conn->query("SET time_zone = '+05:30'"); } catch (Throwable $e) {}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

function mn_log(string $m): void {
    error_log('[MEETUP NOTIFIER] ' . $m);
    echo $m . "\n";
}

// ── Lock ──
$lockFile = sys_get_temp_dir() . '/waamini_meetup_notifier.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Already running\n";
    return;
}

set_time_limit(55);
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/demo_wa.php'; // per-client WhatsApp sender + business name
$fail1h = 0;
$fail5m = 0;

// ── Shared send function ──
function mn_send_wa(int $companyId, string $phone, string $message): bool {
    global $conn;
    [$ok, $err] = demo_wa_send($conn, $companyId, $phone, $message);
    if (!$ok) mn_log("send failed (company {$companyId}): {$err}");
    return $ok;
}

// ── Log to messages table ──
function mn_log_msg(mysqli $conn, int $companyId, string $phone, string $body): void {
    $last10 = substr(preg_replace('/[^0-9]/', '', $phone), -10);
    $like = '%' . $last10;
    $st = $conn->prepare(
        "INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at)
         SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW()
         FROM contacts WHERE company_id = ? AND phone LIKE ? LIMIT 1"
    );
    if ($st) {
        $st->bind_param('isis', $companyId, $body, $companyId, $like);
        $st->execute();
        $st->close();
    }
}

// ── Log to cron_logs ──
function mn_log_cron(mysqli $conn, string $status, int $processed, int $failed): void {
    $st = $conn->prepare(
        "INSERT INTO cron_logs (cron_name, started_at, finished_at, messages_processed, messages_failed, status, notes)
         VALUES ('meetup_notifier', NOW(), NOW(), ?, ?, ?, '')"
    );
    if ($st) {
        $st->bind_param('iis', $processed, $failed, $status);
        $st->execute();
        $st->close();
    }
}

// ══════════════════════════════════════════════════════════════
// Message templates (Tanglish + English combined)
// ══════════════════════════════════════════════════════════════

function mn_msg_1hour(string $name, string $timeNice, string $meetLink, string $dateNice, string $brand = 'Our team'): string {
    $meetBlock = $meetLink !== ''
        ? "🔗 *Join Google Meet:*\n{$meetLink}\n\nPlease join using this link at the scheduled time."
        : "📱 Our team will connect with you on WhatsApp";

    return "⏰ *{$brand} — Demo Reminder*\n\nHello {$name}, your demo is scheduled in one hour.\n\n📅 *Date:* {$dateNice}\n🕒 *Time:* {$timeNice}\n\n{$meetBlock}\n\nPlease keep some time free. We look forward to meeting you. 🙏\n— Team {$brand}";
}

function mn_msg_5min(string $name, string $timeNice, string $meetLink, string $brand = 'Our team'): string {
    $meetBlock = $meetLink !== ''
        ? "👇 *JOIN GOOGLE MEET NOW:*\n{$meetLink}"
        : "📱 Our team is connecting with you on WhatsApp";

    return "🔔 *{$brand} — Demo Starting Soon*\n\nHello {$name}, your demo starts in approximately 5 minutes.\n\n🕒 *Time:* {$timeNice}\n\n{$meetBlock}\n\nPlease join now. Our team is ready for you. 🙏\n— Team {$brand}";
}

// ══════════════════════════════════════════════════════════════
// 1-HOUR REMINDER: demo within next 60 min (but > 5 min away)
// ══════════════════════════════════════════════════════════════

$q = $conn->prepare(
    "SELECT * FROM demo_bookings
     WHERE status IN ('scheduled','confirmed')
       AND reminder_1h_sent = 0
       AND demo_datetime IS NOT NULL
       AND demo_datetime > DATE_ADD(NOW(), INTERVAL 5 MINUTE)
       AND demo_datetime <= DATE_ADD(NOW(), INTERVAL 60 MINUTE)"
);
if ($q) {
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($rows as $b) {
        $name     = $b['customer_name'];
        $timeNice = date('h:i A', strtotime($b['demo_datetime']));
        $dateNice = date('d M Y', strtotime($b['demo_datetime']));
        $meet     = trim((string)($b['meet_link'] ?? ''));
        $msg      = mn_msg_1hour($name, $timeNice, $meet, $dateNice, demo_brand($conn, (int)$b['company_id']));

        if (mn_send_wa((int)$b['company_id'], $b['customer_phone'], $msg)) {
            $conn->query("UPDATE demo_bookings SET reminder_1h_sent=1 WHERE id=" . (int)$b['id']);
            mn_log_msg($conn, (int)$b['company_id'], $b['customer_phone'], $msg);
            $sent1h++;
            mn_log("1h sent → {$name} ({$b['customer_phone']}) for {$b['demo_datetime']}");
        } else {
            $fail1h++;
            mn_log("1h FAILED → {$name} ({$b['customer_phone']})");
        }
    }
}

// ══════════════════════════════════════════════════════════════
// 5-MINUTE ALERT: demo within next 5 min (grace: up to 10 min past)
// ══════════════════════════════════════════════════════════════

$q = $conn->prepare(
    "SELECT * FROM demo_bookings
     WHERE status IN ('scheduled','confirmed')
       AND reminder_5m_sent = 0
       AND demo_datetime IS NOT NULL
       AND demo_datetime > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
       AND demo_datetime <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)"
);
if ($q) {
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($rows as $b) {
        $name     = $b['customer_name'];
        $timeNice = date('h:i A', strtotime($b['demo_datetime']));
        $meet     = trim((string)($b['meet_link'] ?? ''));
        $msg      = mn_msg_5min($name, $timeNice, $meet, demo_brand($conn, (int)$b['company_id']));

        if (mn_send_wa((int)$b['company_id'], $b['customer_phone'], $msg)) {
            $conn->query("UPDATE demo_bookings SET reminder_5m_sent=1 WHERE id=" . (int)$b['id']);
            mn_log_msg($conn, (int)$b['company_id'], $b['customer_phone'], $msg);
            $sent5m++;
            mn_log("5m sent → {$name} ({$b['customer_phone']}) for {$b['demo_datetime']}");
        } else {
            $fail5m++;
            mn_log("5m FAILED → {$name} ({$b['customer_phone']})");
        }
    }
}

// ── Cron log ──
$totalProcessed = $sent1h + $sent5m;
$totalFailed    = $fail1h + $fail5m;
$status         = $totalFailed > 0 && $totalProcessed === 0 ? 'failed' : 'completed';
mn_log_cron($conn, $status, $totalProcessed, $totalFailed);

mn_log("Done: {$sent1h} one-hour sent, {$sent5m} five-min sent, {$totalFailed} failed at {$now}");

// ── Update last run ──
$conn->query("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('cron_meetup_notifier_last_run', NOW(), 'cron') ON DUPLICATE KEY UPDATE setting_value=NOW()");

flock($lock, LOCK_UN);
fclose($lock);
