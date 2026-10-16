<?php
/**
 * WAAMINI Demo Reminder Worker
 * Sends WhatsApp reminders for confirmed demos:
 *   • 1 HOUR before  → friendly reminder with time + Meet link
 *   • 5 MINUTES before → urgent "join now" alert with Meet link
 *
 * Run every minute via Task Scheduler / cron, or it also runs
 * opportunistically after the AI queue worker.
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/google_meet_helper.php'; // for gmeet_parse_time()
require_once __DIR__ . '/includes/demo_wa.php';           // per-client WhatsApp sender + business name

// Align the MySQL session timezone with the IST business timezone so that
// NOW()-based reminder windows (1h / 5m) match the stored IST demo_datetime exactly.
try { $conn->query("SET time_zone = '+05:30'"); } catch (Throwable $e) {}

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

function rlog(string $m): void { error_log('[DEMO REMINDER] ' . $m); echo $m . "\n"; }

// Lock: one instance at a time
$lock = fopen(__DIR__ . '/storage/demo_reminder.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another reminder worker is running - exit.\n";
    return;
}

function reminder_send_wa(int $companyId, string $to, string $body): bool {
    global $conn;
    [$ok, $err] = demo_wa_send($conn, $companyId, $to, $body);
    if (!$ok) rlog("send failed (company {$companyId}): {$err}");
    return $ok;
}

function reminder_log_msg(mysqli $conn, int $companyId, string $phone, string $body): void {
    $last10 = substr(preg_replace('/[^0-9]/', '', $phone), -10);
    $like = '%' . $last10;
    $st = $conn->prepare(
        "INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at)
         SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW()
         FROM contacts WHERE company_id = ? AND phone LIKE ? LIMIT 1"
    );
    if ($st) { $st->bind_param('isis', $companyId, $body, $companyId, $like); $st->execute(); $st->close(); }
}

date_default_timezone_set('Asia/Kolkata');

// ── Ensure reminder columns exist (idempotent) ──
try { $conn->query("ALTER TABLE demo_bookings ADD COLUMN demo_datetime DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE demo_bookings ADD COLUMN reminder_1h_sent TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE demo_bookings ADD COLUMN reminder_5m_sent TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE demo_bookings ADD INDEX idx_reminder (status, demo_datetime)"); } catch (Throwable $e) {}

// ── Backfill demo_datetime for bookings that don't have it parsed yet ──
$res = $conn->query("SELECT id, preferred_time FROM demo_bookings WHERE demo_datetime IS NULL AND status IN ('scheduled','confirmed')");
while ($row = $res->fetch_assoc()) {
    $dt = gmeet_parse_time((string)$row['preferred_time']);
    if ($dt) {
        $s = $dt->format('Y-m-d H:i:s');
        $st = $conn->prepare("UPDATE demo_bookings SET demo_datetime=? WHERE id=?");
        $st->bind_param('si', $s, $row['id']);
        $st->execute();
        $st->close();
    }
}

$now = date('Y-m-d H:i:s');
$sent1h = 0; $sent5m = 0;

// ══════════════════════════════════════════════════════════════
// 1-HOUR REMINDER: demo within next 60 min (but > 5 min away)
// Covers ALL demos with a schedule — confirmed AND still-scheduled
// ══════════════════════════════════════════════════════════════
$q = $conn->prepare(
    "SELECT * FROM demo_bookings
     WHERE status IN ('scheduled','confirmed')
       AND reminder_1h_sent = 0
       AND demo_datetime IS NOT NULL
       AND demo_datetime > DATE_ADD(NOW(), INTERVAL 5 MINUTE)
       AND demo_datetime <= DATE_ADD(NOW(), INTERVAL 60 MINUTE)"
);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

foreach ($rows as $b) {
    $name = $b['customer_name'];
    $timeNice = date('h:i A', strtotime($b['demo_datetime']));
    $meet = trim((string)($b['meet_link'] ?? ''));
    $meetBlock = $meet !== '' ? "\n🔗 *Join Google Meet:*\n{$meet}\n\nPlease join using this link at the scheduled time.\n" : "\nOur team will contact you on WhatsApp at the scheduled time.\n";

    $brand = demo_brand($conn, (int)$b['company_id']);
    $msg = "⏰ *{$brand} — Demo Reminder*\n\nHello {$name}, your demo is scheduled in one hour.\n\n📅 *Date and time:* {$timeNice}\n{$meetBlock}\nPlease keep some time free. We look forward to meeting you. 🙏\n— Team {$brand}";

    if (reminder_send_wa((int)$b['company_id'], $b['customer_phone'], $msg)) {
        $conn->query("UPDATE demo_bookings SET reminder_1h_sent=1 WHERE id=" . (int)$b['id']);
        reminder_log_msg($conn, (int)$b['company_id'], $b['customer_phone'], $msg);
        $sent1h++;
        rlog("✅ 1-hour reminder sent → {$name} ({$b['customer_phone']}) for {$b['demo_datetime']}");
    } else {
        rlog("❌ 1-hour reminder FAILED → {$b['customer_phone']}");
    }
}

// ══════════════════════════════════════════════════════════════
// 5-MINUTE ALERT: demo within next 5 min (grace: up to 10 min past)
// Covers ALL demos with a schedule — confirmed AND still-scheduled
// ══════════════════════════════════════════════════════════════
$q = $conn->prepare(
    "SELECT * FROM demo_bookings
     WHERE status IN ('scheduled','confirmed')
       AND reminder_5m_sent = 0
       AND demo_datetime IS NOT NULL
       AND demo_datetime > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
       AND demo_datetime <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)"
);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

foreach ($rows as $b) {
    $name = $b['customer_name'];
    $timeNice = date('h:i A', strtotime($b['demo_datetime']));
    $meet = trim((string)($b['meet_link'] ?? ''));
    $meetBlock = $meet !== ''
        ? "👇 *JOIN GOOGLE MEET NOW:*\n{$meet}"
        : "Our team is connecting with you on WhatsApp right now.";

    $brand = demo_brand($conn, (int)$b['company_id']);
    $msg = "🔔 *{$brand} — Demo Starting Soon*\n\nHello {$name}, your demo starts in approximately 5 minutes.\n\n🕒 *Time:* {$timeNice}\n\n{$meetBlock}\n\nPlease join now. Our team is ready for you. 🙏\n— Team {$brand}";

    if (reminder_send_wa((int)$b['company_id'], $b['customer_phone'], $msg)) {
        $conn->query("UPDATE demo_bookings SET reminder_5m_sent=1 WHERE id=" . (int)$b['id']);
        reminder_log_msg($conn, (int)$b['company_id'], $b['customer_phone'], $msg);
        $sent5m++;
        rlog("✅ 5-min alert sent → {$name} ({$b['customer_phone']}) for {$b['demo_datetime']}");
    } else {
        rlog("❌ 5-min alert FAILED → {$b['customer_phone']}");
    }
}

rlog("🏁 Done: {$sent1h} one-hour reminder(s), {$sent5m} five-min alert(s) at {$now}");
return;
?>
