<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
require_once '../includes/google_meet_helper.php';
require_once '../includes/demo_wa.php'; // per-client WhatsApp sender + business name

$pageTitle = 'Demo Bookings';
$currentPage = 'demo_bookings';

// Ensure the base table exists for older installations. The versioned migration
// adds automation columns; this compatibility block only fills them when needed.
$conn->query(
    "CREATE TABLE IF NOT EXISTS demo_bookings (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        customer_phone VARCHAR(20) NOT NULL,
        preferred_time VARCHAR(100) NOT NULL,
        topic VARCHAR(255) DEFAULT 'Waamini full demo',
        status ENUM('scheduled','confirmed','completed','cancelled','missed') DEFAULT 'scheduled',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_demo (company_id, customer_phone, status)
    ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
);

// Compatibility for databases upgraded before migration 020 was introduced.
foreach ([
    "ALTER TABLE demo_bookings ADD COLUMN demo_datetime DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN reminder_1h_sent TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE demo_bookings ADD COLUMN reminder_5m_sent TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE demo_bookings ADD COLUMN rescheduled_from DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN rescheduled_to DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN reschedule_reason VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN reschedule_count TINYINT UNSIGNED NOT NULL DEFAULT 0",
    "ALTER TABLE demo_bookings ADD COLUMN missed_at DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN meet_link VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN calendar_event_id VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE demo_bookings MODIFY COLUMN status ENUM('scheduled','confirmed','completed','cancelled','missed') NOT NULL DEFAULT 'scheduled'",
] as $schemaSql) {
    try { $conn->query($schemaSql); } catch (Throwable $e) {
        error_log('[WAAMINI demo bookings schema] ' . $e->getMessage());
    }
}

// Google Calendar connection status for this company
$gcalEmail = gmeet_connected($conn, $companyId);
// off = never logged in, on = working, broken = saved login that Google no longer accepts (needs a new login)
$gState = 'off';
if ($gcalEmail !== null) {
    $gKey = 'gmeet_ok_' . (int)$companyId;
    $gFresh = isset($_SESSION[$gKey]) && ($_SESSION[$gKey]['t'] ?? 0) > time() - 300 && !isset($_GET['gcal']);
    if ($gFresh) {
        $gState = !empty($_SESSION[$gKey]['ok']) ? 'on' : 'broken';
    } else {
        $gState = gmeet_access_token($conn, $companyId) ? 'on' : 'broken';
        $_SESSION[$gKey] = ['ok' => $gState === 'on', 't' => time()];
    }
}
$gReady = gmeet_oauth_config($conn, (int)$companyId)['ready']; // has the administrator added the Google keys?

/**
 * Send a WhatsApp text via Meta Cloud API (.env credentials).
 * Returns [ok(bool), error(string)]
 */
function db_send_whatsapp(string $to, string $body): array {
    global $conn, $companyId;
    return demo_wa_send($conn, (int)$companyId, $to, $body);
}

/** Log an outgoing message into inbox (messages + chat_conversations preview) */
function db_log_outgoing(mysqli $conn, int $companyId, string $phone, string $body): void {
    $last10 = substr(preg_replace('/[^0-9]/', '', $phone), -10);
    $like = '%' . $last10;
    $st = $conn->prepare(
        "INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at)
         SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW()
         FROM contacts WHERE company_id = ? AND phone LIKE ? LIMIT 1"
    );
    if ($st) { $st->bind_param('isis', $companyId, $body, $companyId, $like); $st->execute(); $st->close(); }

    $preview = mb_substr($body, 0, 200, 'UTF-8');
    $st2 = $conn->prepare(
        "UPDATE chat_conversations cc
         JOIN contacts c ON c.id = cc.contact_id AND c.company_id = cc.company_id
         SET cc.last_message = ?, cc.last_message_at = NOW()
         WHERE cc.company_id = ? AND c.phone LIKE ?"
    );
    if ($st2) { $st2->bind_param('sis', $preview, $companyId, $like); $st2->execute(); $st2->close(); }
}

// ── Chat button: resolve contact (create if missing) then open the WhatsApp chat page ──
if (isset($_GET['open_chat'])) {
    $obid = intval($_GET['open_chat']);
    $st = $conn->prepare("SELECT customer_name, customer_phone FROM demo_bookings WHERE id=? AND company_id=? LIMIT 1");
    $st->bind_param('ii', $obid, $companyId);
    $st->execute();
    $bk = $st->get_result()->fetch_assoc();
    $st->close();

    if ($bk) {
        $digits = preg_replace('/[^0-9]/', '', $bk['customer_phone']);
        $last10 = substr($digits, -10);
        $like = '%' . $last10;

        // Find the contact (fuzzy match), create if missing
        $cid = 0;
        $st = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND phone LIKE ? LIMIT 1");
        $st->bind_param('is', $companyId, $like);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $cid = (int)$row['id'];
        } else {
            $st = $conn->prepare("INSERT INTO contacts (company_id, name, phone, created_at) VALUES (?, ?, ?, NOW())");
            $st->bind_param('iss', $companyId, $bk['customer_name'], $digits);
            if ($st->execute()) $cid = (int)$st->insert_id;
            $st->close();
        }

        if ($cid > 0) {
            // Ensure the conversation appears in the WhatsApp chat list
            $st = $conn->prepare(
                "INSERT IGNORE INTO chat_conversations (company_id, contact_id, last_message, last_message_at, unread_count)
                 VALUES (?, ?, '', NOW(), 0)"
            );
            $st->bind_param('ii', $companyId, $cid);
            $st->execute();
            $st->close();

            header("Location: whatsapp.php?c=" . $cid);
            exit();
        }
    }
    header("Location: demo_bookings.php");
    exit();
}

$biz = demo_brand($conn, (int)$companyId); // this client's business name, used in customer messages

// ── Handle status actions (confirm / complete / cancel) — WhatsApp-synced ──
$flash = '';
$flashType = 'success';

// Google Calendar connect result (redirect back from OAuth)
if (isset($_GET['gcal'])) {
    if ($_GET['gcal'] === 'connected') {
        $gcalEmail = gmeet_connected($conn, $companyId); // re-read after connect
        $flash = "Google Calendar connected ✅ — demos will now auto-create Google Meet links" . ($gcalEmail && $gcalEmail !== 'connected' ? " ({$gcalEmail})" : "");
    } elseif ($_GET['gcal'] === 'error') {
        $why = (string)($_GET['why'] ?? '');
        $flash = strpos($why, 'denied_') === 0
            ? 'You cancelled the Google login, so nothing was connected. Click "Login with Google" when you are ready.'
            : 'Google login did not finish (' . htmlspecialchars($why !== '' ? $why : 'unknown') . '). Please click "Login with Google" and try again. Details are on the Google Calendar & Meet page.';
        $flashType = 'warning';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    csrf_verify();
    $bid = intval($_POST['booking_id'] ?? 0);
    $action = $_POST['booking_action'];

    $st = $conn->prepare("SELECT * FROM demo_bookings WHERE id=? AND company_id=? LIMIT 1");
    $st->bind_param('ii', $bid, $companyId);
    $st->execute();
    $booking = $st->get_result()->fetch_assoc();
    $st->close();

    if ($booking) {
        $name = $booking['customer_name'];
        $phone = $booking['customer_phone'];
        $time = $booking['preferred_time'];
        $topic = $booking['topic'];

        if ($action === 'confirm' && $booking['status'] === 'scheduled') {
            // 1. Update status + parse the real demo datetime (used by the reminder worker)
            $parsedDt = gmeet_parse_time($time);
            $dtStr = $parsedDt ? $parsedDt->format('Y-m-d H:i:s') : null;
            $up = $conn->prepare("UPDATE demo_bookings SET status='confirmed', demo_datetime=? WHERE id=? AND company_id=?");
            $up->bind_param('sii', $dtStr, $bid, $companyId);
            $up->execute();
            $up->close();

            // 2. AUTO-CREATE Google Calendar event + Meet link (if calendar connected)
            $meetLink = (string)($booking['meet_link'] ?? '');
            $meetNote = '';
            // AI-created bookings already have an event and Meet link. Do not
            // create another event on confirmation, or the two schedules can
            // drift apart.
            if ($gcalEmail && empty($booking['calendar_event_id']) && empty($booking['meet_link'])) {
                $gm = gmeet_create_demo_event($conn, $companyId, $name, $phone, $time, $topic, $biz);
                if (!empty($gm['meet_link'])) {
                    $meetLink = $gm['meet_link'];
                    $st2 = $conn->prepare("UPDATE demo_bookings SET meet_link=?, calendar_event_id=? WHERE id=? AND company_id=?");
                    $st2->bind_param('ssii', $gm['meet_link'], $gm['event_id'], $bid, $companyId);
                    $st2->execute();
                    $st2->close();
                    $meetNote = ' + Google Meet created 📹';
                } else {
                    $meetNote = ' (Meet skipped: ' . htmlspecialchars($gm['error'] ?? 'unknown') . ')';
                }
            }

            // 3. AUTO-SEND WhatsApp confirmation to the customer (with Meet link when available)
            $meetBlock = $meetLink !== ''
                ? "\n🔗 *Join Google Meet:*\n{$meetLink}\n\nPlease join using this link at the scheduled time.\n"
                : "\nOur team will contact you on WhatsApp at the scheduled time.\n";
            $msg = "✅ *{$biz} — Demo Confirmed*\n\nHello {$name}, your demo has been confirmed.\n\n📅 *Date and time:* {$time}\n{$meetBlock}\nOur team will walk you through {$topic}.\n\nWe look forward to meeting you. 🙏\n— Team {$biz}";

            [$sent, $waErr] = db_send_whatsapp($phone, $msg);
            if ($sent) {
                db_log_outgoing($conn, $companyId, $phone, $msg);
                $flash = "Demo #{$bid} confirmed — WhatsApp confirmation sent to {$name} ✅{$meetNote}";
            } else {
                $flash = "Demo #{$bid} confirmed{$meetNote}, but WhatsApp send failed: " . htmlspecialchars($waErr);
                $flashType = 'warning';
            }

        } elseif ($action === 'complete' && $booking['status'] === 'confirmed') {
            $up = $conn->prepare("UPDATE demo_bookings SET status='completed' WHERE id=? AND company_id=?");
            $up->bind_param('ii', $bid, $companyId);
            $up->execute();
            $up->close();

            // Thank-you + feedback follow-up on WhatsApp
            $msg = "🙏 Thank you {$name}!\n\nThank you for joining the demo with {$biz}. If you have any questions or would like to go ahead, just reply here and we will help you right away. 🚀\n— Team {$biz}";

            [$sent, $waErr] = db_send_whatsapp($phone, $msg);
            if ($sent) db_log_outgoing($conn, $companyId, $phone, $msg);
            $flash = "Demo #{$bid} marked completed" . ($sent ? " — thank-you message sent ✅" : " (WhatsApp send failed)");
            if (!$sent) $flashType = 'warning';

        } elseif ($action === 'cancel' && in_array($booking['status'], ['scheduled', 'confirmed'])) {
            $up = $conn->prepare("UPDATE demo_bookings SET status='cancelled' WHERE id=? AND company_id=?");
            $up->bind_param('ii', $bid, $companyId);
            $up->execute();
            $up->close();

            $msg = "Hi {$name}, your demo scheduled for {$time} has been cancelled. To reschedule, just reply here with a new date and time 🙏\n— Team {$biz}";

            [$sent, $waErr] = db_send_whatsapp($phone, $msg);
            if ($sent) db_log_outgoing($conn, $companyId, $phone, $msg);
            $flash = "Demo #{$bid} cancelled" . ($sent ? " — customer notified on WhatsApp ✅" : "");
        }
    }
}

// ── Stats ──
$stats = ['scheduled' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'missed' => 0];
$stRes = $conn->prepare("SELECT status, COUNT(*) c FROM demo_bookings WHERE company_id=? GROUP BY status");
$stRes->bind_param('i', $companyId);
$stRes->execute();
$r = $stRes->get_result();
while ($row = $r->fetch_assoc()) $stats[$row['status']] = (int)$row['c'];
$stRes->close();
$totalBookings = array_sum($stats);
$rescheduledCount = 0;
try {
    $rc = $conn->prepare("SELECT COUNT(*) c FROM demo_bookings WHERE company_id=? AND reschedule_count > 0");
    if ($rc) {
        $rc->bind_param('i', $companyId);
        $rc->execute();
        $rescheduledCount = (int)($rc->get_result()->fetch_assoc()['c'] ?? 0);
        $rc->close();
    } else {
        error_log('[WAAMINI demo bookings] reschedule_count query unavailable: ' . $conn->error);
    }
} catch (Throwable $e) {}

// ── Filter + list ──
$filter = $_GET['status'] ?? 'all';
$allowed = ['all', 'scheduled', 'confirmed', 'completed', 'cancelled', 'missed'];
if (!in_array($filter, $allowed)) $filter = 'all';

// Join contacts to resolve contact_id for the live-chat link (fuzzy last-10-digit match)
$baseSql = "SELECT db.*,
              (SELECT c.id FROM contacts c
               WHERE c.company_id = db.company_id
                                 AND c.phone COLLATE utf8mb4_unicode_ci LIKE
                                         CONCAT('%', RIGHT(REPLACE(REPLACE(db.customer_phone, '+', ''), ' ', ''), 10)) COLLATE utf8mb4_unicode_ci
               LIMIT 1) AS contact_id
            FROM demo_bookings db WHERE db.company_id=?";
if ($filter === 'all') {
    $list = $conn->prepare($baseSql . " ORDER BY FIELD(db.status,'scheduled','confirmed','completed','cancelled'), db.created_at DESC LIMIT 100");
    $list->bind_param('i', $companyId);
} else {
    $list = $conn->prepare($baseSql . " AND db.status=? ORDER BY db.created_at DESC LIMIT 100");
    $list->bind_param('is', $companyId, $filter);
}
$list->execute();
$bookings = $list->get_result();

// Upcoming Google Meet sessions (next 5, soonest first)
$upcomingMeets = [];
try {
    $um = $conn->prepare("SELECT customer_name, customer_phone, demo_datetime, meet_link, status FROM demo_bookings
                          WHERE company_id=? AND status IN ('scheduled','confirmed') AND demo_datetime IS NOT NULL AND demo_datetime >= NOW()
                          ORDER BY demo_datetime ASC LIMIT 5");
    if ($um) {
        $um->bind_param('i', $companyId);
        $um->execute();
        $umr = $um->get_result();
        while ($umr && ($row = $umr->fetch_assoc())) { $upcomingMeets[] = $row; }
        $um->close();
    }
} catch (Throwable $e) {}
$mfI = static function (string $p, int $sz = 18): string {
    return '<svg width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0">' . $p . '</svg>';
};

include '../includes/client_header.php';
?>

<style>
/* ── Demo Bookings page ── */
.db-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.db-stat {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.db-stat .num { font-size: 26px; font-weight: 700; line-height: 1; }
.db-stat .lbl { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); font-weight: 600; }
.db-stat.scheduled .num { color: #d97706; }
.db-stat.confirmed .num { color: var(--accent); }
.db-stat.completed .num { color: var(--success); }
.db-stat.cancelled .num { color: #dc2626; }

.db-filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
.db-filter {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition);
}
.db-filter:hover { border-color: var(--accent); color: var(--accent); }
.db-filter.active { background: var(--accent); border-color: var(--accent); color: #fff; }

.db-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 16px;
}
.db-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    box-shadow: var(--shadow);
    transition: box-shadow .2s ease, border-color .2s ease;
}
.db-card:hover { box-shadow: var(--shadow-md); border-color: #cbd5e1; }

.db-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.db-cust { min-width: 0; }
.db-cust .name { font-weight: 700; font-size: 15px; color: var(--text-primary); }
.db-cust .phone { font-size: 12.5px; color: var(--text-muted); font-family: monospace; margin-top: 2px; }

.db-status {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
    padding: 4px 10px; border-radius: 20px; white-space: nowrap; flex-shrink: 0;
}
.db-status.scheduled { color: #d97706; background: #fffbeb; border: 1px solid #fde68a; }
.db-status.confirmed { color: var(--accent); background: var(--accent-light); border: 1px solid #bdd5f7; }
.db-status.completed { color: var(--success); background: #ecfdf5; border: 1px solid #a7f3d0; }
.db-status.cancelled { color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; }
.db-status.missed { color: #9a3412; background: #fff7ed; border: 1px solid #fed7aa; }
.db-status.rescheduled { color: #7c3aed; background: #f5f3ff; border: 1px solid #ddd6fe; }

.db-stat.rescheduled { color: #7c3aed; }
.db-stat.missed { color: #9a3412; }
.db-rescheduled-row { background: #f5f3ff; padding: 4px 8px; border-radius: 6px; }
.db-reschedule-reason { font-size: 11.5px; color: var(--text-muted); }
.db-reschedule-reason .v { font-style: italic; }

.db-meta {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
    color: var(--text-secondary);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.db-meta .row { display: flex; gap: 8px; align-items: baseline; }
.db-meta .k { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); font-weight: 700; width: 58px; flex-shrink: 0; }
.db-meta .v { word-break: break-word; }
.db-meet { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; }
.db-meet-link { color: #fff; background: var(--accent); border-radius: 6px; padding: 4px 8px; font-size: 11px; font-weight: 700; text-decoration: none; }
.db-meet-link:hover { opacity: .88; color: #fff; }
.db-meet code { width: 100%; color: var(--text-muted); font-size: 10.5px; overflow-wrap: anywhere; }

.db-foot {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 10px; border-top: 1px solid var(--border); gap: 8px; flex-wrap: wrap;
}
.db-created { font-size: 11.5px; color: var(--text-muted); }
.db-actions { display: flex; gap: 6px; flex-wrap: wrap; }

.db-btn {
    padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
    border: 1px solid var(--border); background: var(--bg-input); color: var(--text-secondary);
    cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 5px;
}
.db-btn:hover { border-color: #cbd5e1; }
.db-btn.confirm { color: #fff; background: var(--accent); border-color: var(--accent); }
.db-btn.confirm:hover { opacity: .9; }
.db-btn.complete { color: #fff; background: var(--success); border-color: var(--success); }
.db-btn.complete:hover { opacity: .9; }
.db-btn.cancel { color: #dc2626; }
.db-btn.cancel:hover { background: #fef2f2; border-color: #fecaca; }
.db-btn.chat { color: var(--accent); }
.db-btn.chat:hover { background: var(--accent-light); border-color: #bdd5f7; }

.db-wa-note {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--success); font-weight: 600;
}

.db-empty {
    grid-column: 1 / -1; text-align: center; padding: 64px 24px;
    background: var(--bg-card); border: 2px dashed var(--border); border-radius: var(--radius);
}
.db-empty-icon {
    width: 56px; height: 56px; background: var(--bg-input); border-radius: 50%;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; color: var(--text-muted);
}

.db-alert {
    padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13.5px; font-weight: 600;
}
.db-alert.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; }
.db-alert.warning { background: #fffbeb; border: 1px solid #fde68a; color: #b45309; }

/* Clean, line-free look: soft tinted tiles, no borders or divider lines */
.db-stat, .db-card { border: 0; box-shadow: 0 10px 28px -20px rgba(15,38,34,.45); }
.db-stat { padding: 18px 20px; gap: 8px; }
.db-stat .num { font-size: 30px; letter-spacing: -.5px; }
.db-stat.scheduled { background: #FFF8E8; }
.db-stat.confirmed { background: #EAF2FF; }
.db-stat.completed { background: #E8F8EF; }
.db-stat.cancelled { background: #FEEDED; }
.db-stat.rescheduled { background: #F3EEFF; }
.db-stat.missed { background: #FFF1E6; }
.db-card { padding: 20px; gap: 14px; border-radius: 16px; }
.db-card:hover { box-shadow: 0 16px 34px -20px rgba(15,38,34,.5); transform: translateY(-2px); }
.db-card { transition: box-shadow .2s ease, transform .2s ease; }
.db-cust .name { font-size: 16px; }
.db-status { border: 0 !important; padding: 5px 12px; font-size: 10.5px; }
.db-meta { border: 0; border-radius: 12px; padding: 14px 16px; background: #F4F8F6; gap: 9px; }
.db-card.scheduled .db-meta { background: #FFF8E8; }
.db-card.confirmed .db-meta { background: #EAF2FF; }
.db-card.completed .db-meta { background: #E8F8EF; }
.db-card.cancelled .db-meta { background: #FEEDED; }
.db-card.missed .db-meta { background: #FFF1E6; }
.db-card.rescheduled .db-meta { background: #F3EEFF; }
.db-meet-link { border-radius: 8px; padding: 5px 11px; }
.db-foot { border-top: 0; padding-top: 2px; }
.db-btn { border: 0; background: #EEF3F1; padding: 8px 14px; border-radius: 10px; }
.db-btn:hover { background: #E2EBE8; }
.db-btn.confirm, .db-btn.complete { background: #128C7E; }
.db-btn.confirm:hover, .db-btn.complete:hover { opacity: .9; }
.db-btn.cancel { background: #FEEDED; } .db-btn.cancel:hover { background: #FBD9D9; }
.db-btn.chat { background: #EAF2FF; } .db-btn.chat:hover { background: #D9E8FD; }
.db-filter { border: 0; background: #fff; box-shadow: 0 6px 16px -12px rgba(15,38,34,.5); padding: 8px 16px; }
.db-filter.active { box-shadow: none; }
.db-alert.success, .db-alert.warning { border: 0; }
.db-empty { border: 0; background: #F4F8F6; }

/* Buttons use the sidebar palette: mint #E6FCF2 with deep-green text, solid green for the main action */
.db-btn, .db-btn.cancel, .db-btn.chat, .db-filter, .page-header .btn { background: #E6FCF2; color: #0B6B5E; border: 0; box-shadow: none; }
.db-btn:hover, .db-btn.cancel:hover, .db-btn.chat:hover, .db-filter:hover, .page-header .btn:hover { background: #CDF5E3; color: #0B6B5E; }
.db-btn.confirm, .db-btn.complete, .db-filter.active, .page-header .btn-primary { background: #128C7E; color: #fff; }
.db-btn.confirm:hover, .db-btn.complete:hover, .db-filter.active:hover, .page-header .btn-primary:hover { background: #0B6B5E; color: #fff; opacity: 1; }
.db-meet-link { background: #128C7E; color: #fff; }
.db-meet-link:hover { background: #0B6B5E; opacity: 1; }

/* ── Google button in the page header ── */
.gl-hbtn { display:inline-flex; align-items:center; gap:9px; height:38px; padding:0 16px; border-radius:11px; background:#fff; color:#1f2d29; font-size:13.5px; font-weight:800; text-decoration:none; box-shadow:0 0 0 1.5px #D5E3DD inset; }
.gl-hbtn:hover { background:#E6FCF2; color:#0B6B5E; }
.gl-hbtn-main { box-shadow:0 0 0 2px #128C7E inset, 0 10px 22px -14px rgba(18,140,126,.6); }

/* ── Login with Google card ── */
.gl { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:24px; align-items:center; background:#fff; border-radius:18px; padding:24px 26px; margin-bottom:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.gl h3 { margin:0 0 6px; font-size:18px; font-weight:800; color:#0F2622; }
.gl p { margin:0 0 10px; font-size:14px; color:#34453f; line-height:1.55; max-width:640px; }
.gl ol { margin:0; padding-left:20px; font-size:13.5px; color:#34453f; line-height:1.8; }
.gl-btn { display:inline-flex; align-items:center; gap:12px; height:54px; padding:0 28px; border-radius:14px; background:#fff; color:#1f2d29; font-size:16px; font-weight:800; text-decoration:none; box-shadow:0 0 0 2px #128C7E inset, 0 14px 30px -18px rgba(18,140,126,.6); white-space:nowrap; }
.gl-btn:hover { background:#E6FCF2; color:#0B6B5E; }
@media (max-width:800px) { .gl { grid-template-columns:minmax(0,1fr); } }

/* ── Google Meet schedule flow ── */
.mf { background:#fff; border-radius:18px; padding:22px 24px; margin-bottom:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.mf-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.mf-head h3 { margin:0; font-size:17px; font-weight:800; color:#0F2622; display:flex; align-items:center; gap:10px; }
.mf-head h3 i { width:34px; height:34px; border-radius:10px; background:#E3F7EE; color:#128C7E; display:grid; place-items:center; font-style:normal; }
.mf-conn { display:inline-flex; align-items:center; gap:8px; padding:7px 14px; border-radius:999px; font-size:13px; font-weight:800; background:#E3F7EE; color:#0B6B5E; text-decoration:none; }
.mf-conn.off { background:#FEF3DC; color:#8a5a00; }
.mf-conn b { width:8px; height:8px; border-radius:50%; background:#25D366; }
.mf-conn.off b { background:#f59e0b; }
.mf-steps { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; counter-reset:mf; }
.mf-step { position:relative; background:#F3FBF7; border-radius:16px; padding:16px 14px 14px; }
.mf-step .n { width:30px; height:30px; border-radius:50%; background:#128C7E; color:#fff; font-weight:800; font-size:13px; display:grid; place-items:center; margin-bottom:10px; }
.mf-step strong { display:block; font-size:14px; color:#0F2622; margin-bottom:3px; }
.mf-step span { display:block; font-size:12.5px; color:#34453f; line-height:1.45; }
.mf-step em { display:inline-block; margin-top:8px; font-style:normal; font-size:12px; font-weight:800; padding:3px 10px; border-radius:999px; background:#fff; color:#0B6B5E; }
.mf-step:not(:last-child)::after { content:''; position:absolute; right:-10px; top:50%; width:8px; height:8px; border-top:2.5px solid #128C7E; border-right:2.5px solid #128C7E; transform:translateY(-50%) rotate(45deg); opacity:.55; }
.mf-up { margin-top:18px; }
.mf-up h4 { margin:0 0 10px; font-size:14px; font-weight:800; color:#0F2622; }
.mf-up-list { display:flex; flex-direction:column; gap:8px; }
.mf-up-row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:14px; align-items:center; background:#F3FBF7; border-radius:14px; padding:11px 14px; font-size:14px; }
.mf-up-row .w { font-weight:800; color:#0F2622; }
.mf-up-row .c { font-size:12.5px; color:#586a63; }
.mf-up-row .t { font-weight:700; color:#0B6B5E; white-space:nowrap; }
.mf-empty { background:#F3FBF7; border-radius:14px; padding:14px 16px; font-size:13.5px; color:#34453f; }
@media (max-width:1100px) { .mf-steps { grid-template-columns:repeat(2,minmax(0,1fr)); } .mf-step::after { display:none; } }
@media (max-width:560px) { .mf-steps { grid-template-columns:minmax(0,1fr); } .mf-up-row { grid-template-columns:minmax(0,1fr) auto; } .mf-up-row .t { grid-column:1/-1; } }

/* progress track on each booking */
.db-track { display:flex; align-items:center; gap:6px; margin:2px 0 0; }
.db-track span { flex:1; text-align:center; font-size:11.5px; font-weight:800; padding:5px 0; border-radius:999px; background:#EEF1F0; color:#7b8a85; }
.db-track span.on { background:#E3F7EE; color:#0B6B5E; }
.db-track span.cur { background:#128C7E; color:#fff; }
.db-track.bad span { background:#EEF1F0; color:#7b8a85; }
.db-track.bad span.cur { background:#FDE7E7; color:#b3261e; }
</style>

<?php if ($flash): ?>
    <div class="db-alert <?php echo $flashType; ?>"><?php echo $flash; ?></div>
<?php endif; ?>

<div class="page-header">
    <h2><?php echo function_exists('cn_svg') ? cn_svg('scheduled') : '📅'; ?> Demo Bookings
        <span style="font-size:14px;color:var(--text-muted);font-weight:400;">(<?php echo $totalBookings; ?> booking<?php echo $totalBookings !== 1 ? 's' : ''; ?> — AI scheduled from WhatsApp)</span>
    </h2>
    <div class="action-btns">
        <?php if ($gState === 'on'): ?>
            <span class="db-wa-note" title="Google Calendar connected<?php echo $gcalEmail !== 'connected' ? ': ' . htmlspecialchars($gcalEmail) : ''; ?>">📹 Google Meet: ON</span>
            <a class="gl-hbtn" href="google_calendar_connect.php?return=demo_bookings"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg> Switch Google account</a>
        <?php else: ?>
            <a class="gl-hbtn gl-hbtn-main" href="google_calendar_connect.php?return=demo_bookings" title="Login with your Google account to create Google Meet links for demos"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg> <?php echo $gState === 'broken' ? 'Login with Google again' : 'Login with Google'; ?></a>
        <?php endif; ?>
        <span class="db-wa-note">✅ Actions auto-send WhatsApp to customer</span>
    </div>
</div>

<?php if ($gState !== 'on'): ?>
<section class="gl" aria-label="Login with Google">
    <div class="gl-txt">
        <h3><?php echo $gState === 'broken' ? 'Your Google login has expired. Login again to keep creating Meet links' : 'Login with your own Google account to create Google Meet links'; ?></h3>
        <?php if (!$gReady): ?><p style="background:#FEF3DC;color:#6f4a00;border-radius:10px;padding:8px 12px;font-weight:700">One setup step is missing: the administrator must paste the Google keys in Admin → Google Login (Meet). The button works after that.</p><?php endif; ?>
        <p>Use your personal Google ID or your Google Business (Workspace) account. Every demo your customers book on WhatsApp then gets its own Meet link, created on <strong>your</strong> Google Calendar.</p>
        <ol>
            <li>Click <b>Login with Google</b> and choose your account</li>
            <li>Allow calendar access and keep every box ticked</li>
            <li>You come back here and Meet links start automatically</li>
        </ol>
    </div>
    <a class="gl-btn" href="google_calendar_connect.php?return=demo_bookings">
        <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.5 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
        Login with Google
    </a>
</section>
<?php endif; ?>

<section class="mf" aria-label="Google Meet scheduling flow">
    <div class="mf-head">
        <h3><i><?php echo $mfI('<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>'); ?></i> Google Meet scheduling flow</h3>
        <?php if ($gState === 'on'): ?>
            <span class="mf-conn"><b></b> Google Meet hosted by<?php echo ($gcalEmail !== 'connected' && $gcalEmail !== true) ? ' ' . htmlspecialchars((string)$gcalEmail) : ' your Google account'; ?></span>
            <a class="mf-conn off" href="google_calendar_connect.php?return=demo_bookings" style="background:#EEF1F0;color:#34453f">Switch Google account</a>
        <?php else: ?>
            <a class="mf-conn off" href="google_calendar_connect.php?return=demo_bookings"><b></b> Connect Google Calendar to create Meet links</a>
        <?php endif; ?>
    </div>
    <div class="mf-steps">
        <div class="mf-step"><div class="n">1</div><strong>Customer asks</strong><span>A customer asks for a demo or meeting on WhatsApp.</span><em><?php echo (int)$totalBookings; ?> total</em></div>
        <div class="mf-step"><div class="n">2</div><strong>Bot collects the time</strong><span>The bot asks for name, service and a date and time.</span><em>Automatic</em></div>
        <div class="mf-step"><div class="n">3</div><strong>Google Meet is created</strong><span>The slot is booked on your calendar and a Meet link is made.</span><em><?php echo $gState === 'on' ? 'Calendar on' : 'Login needed'; ?></em></div>
        <div class="mf-step"><div class="n">4</div><strong>You confirm + notify</strong><span>One tap sends the confirmation and link to the customer.</span><em><?php echo (int)$stats['scheduled']; ?> waiting</em></div>
        <div class="mf-step"><div class="n">5</div><strong>Reminder and demo</strong><span>The customer gets reminders, then you mark the demo done.</span><em><?php echo (int)$stats['confirmed']; ?> confirmed · <?php echo (int)$stats['completed']; ?> done</em></div>
    </div>
    <div class="mf-up">
        <h4>Upcoming Meet sessions</h4>
        <?php if ($upcomingMeets): ?>
        <div class="mf-up-list">
            <?php foreach ($upcomingMeets as $um): $ts = strtotime((string)$um['demo_datetime']); ?>
            <div class="mf-up-row">
                <div><div class="w"><?php echo htmlspecialchars((string)$um['customer_name']); ?></div><div class="c"><?php echo htmlspecialchars((string)$um['customer_phone']); ?> · <?php echo htmlspecialchars((string)$um['status']); ?></div></div>
                <div class="t"><?php echo $ts ? date('D, d M · h:i A', $ts) : '—'; ?></div>
                <?php if (!empty($um['meet_link'])): ?><a class="db-meet-link" href="<?php echo htmlspecialchars((string)$um['meet_link']); ?>" target="_blank" rel="noopener noreferrer">Open meeting</a><?php else: ?><span class="c">No link yet</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="mf-empty">No upcoming sessions. When a customer books a time on WhatsApp, it will appear here with its Google Meet link.</div>
        <?php endif; ?>
    </div>
</section>

<div class="db-stats">
    <div class="db-stat scheduled"><span class="num"><?php echo $stats['scheduled']; ?></span><span class="lbl">⏳ Awaiting Confirm</span></div>
    <div class="db-stat confirmed"><span class="num"><?php echo $stats['confirmed']; ?></span><span class="lbl">📅 Confirmed</span></div>
    <div class="db-stat completed"><span class="num"><?php echo $stats['completed']; ?></span><span class="lbl">✅ Completed</span></div>
    <div class="db-stat cancelled"><span class="num"><?php echo $stats['cancelled']; ?></span><span class="lbl">❌ Cancelled</span></div>
    <div class="db-stat rescheduled"><span class="num"><?php echo $rescheduledCount; ?></span><span class="lbl">🔄 Auto-Rescheduled</span></div>
    <div class="db-stat missed"><span class="num"><?php echo $stats['missed']; ?></span><span class="lbl">⚠️ Missed</span></div>
</div>

<div class="db-filters">
    <?php foreach ($allowed as $f): ?>
        <a class="db-filter <?php echo $filter === $f ? 'active' : ''; ?>" href="?status=<?php echo $f; ?>">
            <?php echo ucfirst($f); ?><?php echo $f !== 'all' ? ' (' . ($stats[$f] ?? 0) . ')' : ''; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="db-grid">
    <?php if ($bookings && $bookings->num_rows > 0): while ($b = $bookings->fetch_assoc()): ?>
        <div class="db-card <?php echo htmlspecialchars((string)$b['status']); ?>">
            <div class="db-card-top">
                <div class="db-cust">
                    <div class="name"><?php echo htmlspecialchars($b['customer_name']); ?></div>
                    <div class="phone">📱 <?php echo htmlspecialchars($b['customer_phone']); ?></div>
                </div>
                <span class="db-status <?php echo $b['status']; ?>"><?php echo $b['status']; ?></span>
            </div>

            <div class="db-meta">
                <div class="row"><span class="k">When</span><span class="v">🕒 <?php echo htmlspecialchars($b['preferred_time']); ?></span></div>
                <div class="row"><span class="k">Topic</span><span class="v"><?php echo htmlspecialchars($b['topic']); ?></span></div>
                <?php if (!empty($b['meet_link'])): ?>
                    <div class="row"><span class="k">Meeting</span><span class="v db-meet"><span>📹 Google Meet</span><a class="db-meet-link" href="<?php echo htmlspecialchars($b['meet_link']); ?>" target="_blank" rel="noopener">Open meeting</a><code><?php echo htmlspecialchars($b['meet_link']); ?></code></span></div>
                <?php elseif (in_array((string)$b['status'], ['scheduled', 'confirmed'], true)): ?>
                    <div class="row"><span class="k">Meeting</span><span class="v" style="color:#8a5a00;font-weight:700">No Meet link yet. <a href="google_calendar_connect.php?return=demo_bookings" style="color:#0B6B5E">Login with Google</a>, then press Confirm + Notify to create it.</span></div>
                <?php endif; ?>
                <?php if (!empty($b['rescheduled_from']) && !empty($b['rescheduled_to'])): ?>
                    <div class="row db-rescheduled-row"><span class="k">🔄 Rescheduled</span><span class="v">From <?php echo date('d M h:i A', strtotime($b['rescheduled_from'])); ?> &rarr; <?php echo date('d M h:i A', strtotime($b['rescheduled_to'])); ?></span></div>
                    <?php if (!empty($b['reschedule_reason'])): ?>
                        <div class="row db-reschedule-reason"><span class="k">Reason</span><span class="v"><?php echo htmlspecialchars($b['reschedule_reason']); ?></span></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php
                $stNow = (string)$b['status'];
                $isBad = in_array($stNow, ['cancelled', 'missed'], true);
                $order = ['scheduled' => 1, 'confirmed' => 2, 'completed' => 3];
                $lvl = $order[$stNow] ?? 0;
            ?>
            <div class="db-track<?php echo $isBad ? ' bad' : ''; ?>" aria-label="Progress: <?php echo htmlspecialchars($stNow); ?>">
                <?php if ($isBad): ?>
                    <span>Booked</span><span class="cur"><?php echo $stNow === 'missed' ? 'Missed' : 'Cancelled'; ?></span>
                <?php else: ?>
                    <span class="<?php echo $lvl >= 1 ? ($lvl === 1 ? 'cur' : 'on') : ''; ?>">Booked</span>
                    <span class="<?php echo $lvl >= 2 ? ($lvl === 2 ? 'cur' : 'on') : ''; ?>">Confirmed</span>
                    <span class="<?php echo $lvl >= 3 ? 'cur' : ''; ?>">Completed</span>
                <?php endif; ?>
            </div>

            <div class="db-foot">
                <span class="db-created">Booked <?php echo date('d M, h:i A', strtotime($b['created_at'])); ?></span>
                <div class="db-actions">
                    <a class="db-btn chat" href="demo_bookings.php?open_chat=<?php echo (int)$b['id']; ?>" title="Open WhatsApp chat with <?php echo htmlspecialchars($b['customer_name']); ?>">💬 Chat</a>

                    <?php if ($b['status'] === 'scheduled'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Confirm this demo? A WhatsApp confirmation will be sent to <?php echo htmlspecialchars($b['customer_name']); ?> automatically.');">
                            <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                            <button class="db-btn confirm" type="submit" name="booking_action" value="confirm">✅ Confirm + Notify</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'confirmed'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Mark demo as completed? A thank-you message will be sent on WhatsApp.');">
                            <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                            <button class="db-btn complete" type="submit" name="booking_action" value="complete">🏁 Completed</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($b['status'], ['scheduled', 'confirmed'])): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this demo? Customer will be notified on WhatsApp.');">
                            <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                            <button class="db-btn cancel" type="submit" name="booking_action" value="cancel">✕</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endwhile; else: ?>
        <div class="db-empty">
            <div class="db-empty-icon" style="font-size:24px;">📅</div>
            <h3 style="margin:0 0 6px;">No demo bookings yet</h3>
            <p style="color:var(--text-muted);font-size:13.5px;margin:0;">
                When customers ask for a demo on WhatsApp, the AI bot books it automatically and it appears here.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/client_footer.php'; ?>