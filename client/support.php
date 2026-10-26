<?php
include '../includes/client_auth_check.php';
if (!has_feature('set_support')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
include_once '../includes/schema_guard.php';

$pageTitle = 'Help & Support';
$currentPage = 'support';

/** Create ticketing tables with InnoDB for foreign keys. */
function waamini_support_ensure_tables(mysqli $conn): void {
    static $done = false;
    if ($done || !($conn instanceof mysqli)) {
        return;
    }
    $conn->query("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            user_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('open', 'closed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS support_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_type ENUM('client', 'admin') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

waamini_support_ensure_tables($conn);

$success = '';
$error = '';

$userId = (int)($_SESSION['user_id'] ?? 0);

// Handle creating new ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    csrf_verify();

    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 65535);

    if ($subject === '' || $message === '') {
        $error = 'Subject and message are required.';
    } elseif ($userId <= 0) {
        $error = 'Your session is invalid. Please log in again.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO support_tickets (company_id, user_id, subject) VALUES (?, ?, ?)');
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('iis', $companyId, $userId, $subject);
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $ticketId = (int)$stmt->insert_id;
            $stmt->close();

            if ($ticketId <= 0) {
                throw new RuntimeException('Could not create ticket.');
            }

            $msgStmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'client', ?, ?)");
            if (!$msgStmt) {
                throw new RuntimeException($conn->error);
            }
            $msgStmt->bind_param('iis', $ticketId, $userId, $message);
            if (!$msgStmt->execute()) {
                throw new RuntimeException($msgStmt->error);
            }
            $msgStmt->close();

            $conn->commit();
            header('Location: support.php?ticket=' . $ticketId . '&msg=created');
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Could not create ticket. Please try again.';
            if ($conn->errno !== 0) {
                error_log('[support.php] create_ticket: ' . $e->getMessage());
            }
        }
    }
}

// Handle sending reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    csrf_verify();

    $ticketId = isset($_GET['ticket']) ? (int)$_GET['ticket'] : 0;
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 65535);

    if ($ticketId <= 0) {
        $error = 'Invalid ticket.';
    } elseif ($message === '') {
        $error = 'Please enter a message.';
    } elseif ($userId <= 0) {
        $error = 'Your session is invalid. Please log in again.';
    } else {
        $stSup1 = $conn->prepare('SELECT id FROM support_tickets WHERE id=? AND company_id=? LIMIT 1');
        if (!$stSup1) {
            $error = 'Could not verify ticket.';
        } else {
            $stSup1->bind_param('ii', $ticketId, $companyId);
            $stSup1->execute();
            $check = $stSup1->get_result();
            $ok = $check && $check->num_rows > 0;
            $stSup1->close();

            if (!$ok) {
                $error = 'Ticket not found or access denied.';
            } else {
                $stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'client', ?, ?)");
                if (!$stmt) {
                    $error = 'Could not send reply.';
                } else {
                    $stmt->bind_param('iis', $ticketId, $userId, $message);
                    if (!$stmt->execute()) {
                        $error = 'Could not send reply. Please try again.';
                    } else {
                        $stSup2 = $conn->prepare("UPDATE support_tickets SET status='open' WHERE id=? AND company_id=?");
                        if ($stSup2) {
                            $stSup2->bind_param('ii', $ticketId, $companyId);
                            $stSup2->execute();
                            $stSup2->close();
                        }
                        header('Location: support.php?ticket=' . $ticketId);
                        exit();
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$activeTicketId = isset($_GET['ticket']) ? (int)$_GET['ticket'] : 0;

/* ── Ticket list with last-activity preview + reply stats ── */
$ticketRows = [];
$stSup3 = $conn->prepare("SELECT t.*,
        (SELECT m.message FROM support_messages m WHERE m.ticket_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_msg,
        (SELECT m.sender_type FROM support_messages m WHERE m.ticket_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
        (SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id=t.id) AS last_at,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS msg_count,
        (SELECT MIN(m.created_at) FROM support_messages m WHERE m.ticket_id=t.id AND m.sender_type='admin') AS first_admin_reply
    FROM support_tickets t
    WHERE t.company_id=?
    ORDER BY t.id DESC");
if ($stSup3) {
    $stSup3->bind_param('i', $companyId);
    if ($stSup3->execute()) {
        $resSup3 = $stSup3->get_result();
        while ($rowSup3 = $resSup3->fetch_assoc()) {
            $ticketRows[] = $rowSup3;
        }
    }
    $stSup3->close();
}

/* ── Page stats ── */
$statTotal  = count($ticketRows);
$statOpen   = 0;
$statClosed = 0;
$replySeconds = 0;
$replyCount   = 0;
foreach ($ticketRows as $tr) {
    if (($tr['status'] ?? '') === 'open') { $statOpen++; } else { $statClosed++; }
    if (!empty($tr['first_admin_reply'])) {
        $delta = strtotime((string)$tr['first_admin_reply']) - strtotime((string)$tr['created_at']);
        if ($delta >= 0) { $replySeconds += $delta; $replyCount++; }
    }
}
$avgReply = '';
if ($replyCount > 0) {
    $avg = $replySeconds / $replyCount;
    $avgReply = $avg >= 3600
        ? round($avg / 3600, 1) . 'h'
        : max(1, (int)round($avg / 60)) . 'm';
}

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
<style>
/* ═══════════════════════════════════════════
   WAAMINI Help & Support — redesigned UI
   Matches the Tailwind mockup, plain CSS
   ═══════════════════════════════════════════ */

/* Page shell */
.sp-page {
  --sp-green:   #008767;
  --sp-green-d: #0d7f6c;
  --sp-green-l: #f0fdf9;
  --sp-green-b: #ccfbf1;
  --sp-ink:     #0f172a;
  --sp-sub:     #475569;
  --sp-muted:   #94a3b8;
  --sp-border:  #e2e8f0;
  --sp-surface: #f8fafc;
  --sp-white:   #ffffff;
  --sp-radius:  16px;
  --sp-shadow:  0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --sp-shadow-md: 0 4px 16px -4px rgba(0,0,0,.08);
  font-family: Inter, "Segoe UI", system-ui, sans-serif;
  color: var(--sp-ink);
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding-bottom: 32px;
}
.sp-page *, .sp-page *::before, .sp-page *::after { box-sizing: border-box; }

/* ── Card base ── */
.sp-card {
  background: var(--sp-white);
  border: 1px solid var(--sp-border);
  border-radius: var(--sp-radius);
  box-shadow: var(--sp-shadow);
}

/* ── Top header ── */
.sp-header {
  padding: 24px 28px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
}
.sp-header-l {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}
.sp-header-ico {
  width: 48px; height: 48px;
  border-radius: 12px;
  background: var(--sp-green-l);
  border: 1px solid var(--sp-green-b);
  color: var(--sp-green-d);
  display: grid; place-items: center;
  flex-shrink: 0; margin-top: 2px;
}
.sp-header-ico svg { width: 22px; height: 22px; }
.sp-header h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; letter-spacing: -.02em; color: var(--sp-ink); line-height: 1.2; }
.sp-header p  { margin: 0; font-size: 14px; color: var(--sp-sub); line-height: 1.5; }
.sp-header-r  { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }

/* ── Status pill ── */
.sp-status-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 99px;
  font-size: 12px; font-weight: 600;
  background: #f1f5f9; color: #64748b;
  border: 1px solid var(--sp-border);
}
.sp-status-pill .dot {
  width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;
}
.sp-status-pill.active .dot { background: var(--sp-green); }
.sp-status-pill.active { color: var(--sp-green-d); background: var(--sp-green-l); border-color: var(--sp-green-b); }

/* ── Primary button ── */
.sp-btn-primary {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; border-radius: 12px;
  background: var(--sp-green-d); color: #fff;
  font-size: 13.5px; font-weight: 700;
  border: none; cursor: pointer; text-decoration: none;
  transition: background .2s, box-shadow .2s;
  box-shadow: 0 2px 8px rgba(13,127,108,.2);
}
.sp-btn-primary:hover { background: #0a6b5b; box-shadow: 0 4px 14px rgba(13,127,108,.28); }
.sp-btn-primary svg { width: 14px; height: 14px; flex-shrink: 0; }

/* soft secondary button */
.sp-btn-soft {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; border-radius: 12px;
  background: var(--sp-green-l); color: var(--sp-green-d);
  border: 1px solid var(--sp-green-b);
  font-size: 13.5px; font-weight: 700;
  cursor: pointer; text-decoration: none;
  transition: background .2s;
}
.sp-btn-soft:hover { background: #ccfbf1; }
.sp-btn-soft svg { width: 14px; height: 14px; flex-shrink: 0; }

/* block/full-width */
.sp-btn-primary.block, .sp-btn-soft.block { width: 100%; justify-content: center; padding: 13px 18px; font-size: 14.5px; }

/* ── Metrics row ── */
.sp-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0,1fr));
  gap: 14px;
}
.sp-metric {
  padding: 18px 20px;
  display: flex; align-items: center; gap: 14px;
  transition: box-shadow .2s, transform .2s;
}
.sp-metric:hover { box-shadow: var(--sp-shadow-md); transform: translateY(-1px); }
.sp-metric-ico {
  width: 46px; height: 46px; border-radius: 12px;
  display: grid; place-items: center; flex-shrink: 0;
}
.sp-metric-ico svg { width: 20px; height: 20px; }
.sp-metric-ico.teal   { background: #f0fdfa; color: #0d9488; }
.sp-metric-ico.sky    { background: #f0f9ff; color: #0284c7; }
.sp-metric-ico.amber  { background: #fffbeb; color: #d97706; }
.sp-metric-ico.purple { background: #faf5ff; color: #9333ea; }
.sp-metric b { display: block; font-size: 24px; font-weight: 800; letter-spacing: -.02em; color: var(--sp-ink); line-height: 1; }
.sp-metric span { display: block; font-size: 12px; font-weight: 500; color: var(--sp-muted); margin-top: 4px; }

/* ── Channels row ── */
.sp-channels {
  display: grid;
  grid-template-columns: repeat(3, minmax(0,1fr));
  gap: 16px;
}
.sp-channel {
  padding: 22px;
  display: flex; flex-direction: column; gap: 0;
  transition: box-shadow .2s, transform .2s;
}
.sp-channel:hover { box-shadow: var(--sp-shadow-md); transform: translateY(-2px); }
.sp-channel-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}
.sp-channel-ico {
  width: 42px; height: 42px; border-radius: 12px;
  display: grid; place-items: center;
}
.sp-channel-ico svg { width: 22px; height: 22px; }
.sp-channel-ico.green  { background: #ecfdf5; color: #059669; }
.sp-channel-ico.blue   { background: #eff6ff; color: #2563eb; }
.sp-channel-ico.amber  { background: #fffbeb; color: #d97706; }
.sp-channel-tag {
  display: inline-flex; align-items: center;
  padding: 3px 10px; border-radius: 99px;
  font-size: 11.5px; font-weight: 700;
}
.sp-channel-tag.green  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.sp-channel-tag.blue   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.sp-channel-tag.amber  { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.sp-channel h3 { margin: 0 0 6px; font-size: 15px; font-weight: 800; color: var(--sp-ink); }
.sp-channel p  { margin: 0 0 18px; font-size: 13px; color: var(--sp-sub); line-height: 1.55; flex: 1; }
.sp-channel .sp-btn-primary,
.sp-channel .sp-btn-soft { width: 100%; justify-content: center; margin-top: auto; }

/* ── Two-column split ── */
.sp-split {
  display: grid;
  grid-template-columns: minmax(0, 5fr) minmax(0, 8fr);
  gap: 18px;
  align-items: start;
}

/* ── Ticket list column ── */
.sp-ticket-col { padding: 22px; display: flex; flex-direction: column; gap: 14px; }
.sp-ticket-col-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.sp-ticket-col-head h2 {
  margin: 0; font-size: 16px; font-weight: 800; color: var(--sp-ink);
  display: flex; align-items: center; gap: 8px;
}
.sp-ticket-col-head h2 .ico {
  width: 32px; height: 32px; border-radius: 9px;
  background: #f0fdfa; color: #0d9488;
  display: grid; place-items: center;
}
.sp-ticket-col-head h2 .ico svg { width: 15px; height: 15px; }
.sp-col-count {
  padding: 3px 10px; border-radius: 99px;
  font-size: 11.5px; font-weight: 700;
  background: #f1f5f9; color: #64748b;
  border: 1px solid var(--sp-border);
}
.sp-ticket-col > p { margin: 0; font-size: 12.5px; color: var(--sp-muted); }
.sp-ticket-list { display: flex; flex-direction: column; gap: 8px; max-height: 520px; overflow-y: auto; padding-right: 2px; }
.sp-ticket-list::-webkit-scrollbar { width: 5px; }
.sp-ticket-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }

/* single ticket item */
.sp-tk-item {
  display: block; text-decoration: none; color: inherit;
  background: var(--sp-surface);
  border: 1px solid var(--sp-border);

  border-radius: 12px; padding: 13px 14px;
  transition: background .15s, box-shadow .15s, border-color .15s;
}
.sp-tk-item:hover  { background: #fff; box-shadow: var(--sp-shadow); }
.sp-tk-item.active { background: var(--sp-green-l); border-color: var(--sp-green); }
.sp-tk-item.open   { }
.sp-tk-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 5px; }
.sp-tk-subj { font-size: 13.5px; font-weight: 800; color: var(--sp-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.sp-tk-tag {
  padding: 2px 8px; border-radius: 6px;
  font-size: 11px; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}
.sp-tk-tag.open   { background: #ecfdf5; color: #065f46; }
.sp-tk-tag.closed { background: #f1f5f9; color: #475569; }
.sp-tk-preview { font-size: 12px; color: var(--sp-sub); font-style: italic; margin-bottom: 9px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; }
.sp-tk-foot { display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: 600; color: var(--sp-muted); border-top: 1px solid #e2e8f0; padding-top: 8px; }

/* empty state */
.sp-empty { text-align: center; padding: 32px 16px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.sp-empty-ico { width: 52px; height: 52px; border-radius: 14px; background: var(--sp-surface); display: grid; place-items: center; margin-bottom: 4px; }
.sp-empty-ico svg { width: 26px; height: 26px; color: var(--sp-muted); }
.sp-empty h3 { margin: 0; font-size: 15px; font-weight: 800; color: var(--sp-ink); }
.sp-empty p  { margin: 0; font-size: 13px; color: var(--sp-muted); max-width: 240px; line-height: 1.5; }

/* ── Right column: form / conversation ── */
.sp-form-col { padding: 26px 28px; }
.sp-form-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  padding-bottom: 18px; border-bottom: 1px solid #f1f5f9; margin-bottom: 22px;
}
.sp-form-head h2 { margin: 0 0 3px; font-size: 18px; font-weight: 800; color: var(--sp-ink); letter-spacing: -.01em; }
.sp-form-head p  { margin: 0; font-size: 13px; color: var(--sp-sub); }
.sp-replies-tag {
  padding: 5px 12px; border-radius: 99px;
  font-size: 11.5px; font-weight: 700;
  background: #ecfdf5; color: #065f46;
  border: 1px solid #a7f3d0; white-space: nowrap; flex-shrink: 0;
}

/* form fields */
.sp-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
.sp-field label {
  font-size: 11px; font-weight: 800; letter-spacing: .06em;
  text-transform: uppercase; color: #374151;
}
.sp-field input,
.sp-field textarea {
  width: 100%; background: #f8fafc;
  border: 1px solid var(--sp-border); border-radius: 12px;
  padding: 11px 15px; font-size: 14px; color: var(--sp-ink);
  font-family: inherit; outline: none;
  transition: border-color .2s, background .2s, box-shadow .2s;
  resize: vertical;
}
.sp-field input::placeholder, .sp-field textarea::placeholder { color: #94a3b8; }
.sp-field input:focus, .sp-field textarea:focus {
  background: #fff; border-color: var(--sp-green-d);
  box-shadow: 0 0 0 3px rgba(13,127,108,.1);
}
.sp-field .hint { font-size: 11.5px; color: var(--sp-muted); }

/* urgent callout */
.sp-urgent {
  display: flex; align-items: flex-start; gap: 12px;
  margin-top: 20px; padding: 14px 16px;
  background: #f0fdfa; border: 1px solid #99f6e4;
  border-radius: 12px;
}
.sp-urgent-ico {
  width: 22px; height: 22px; border-radius: 50%;
  background: #ccfbf1; color: var(--sp-green-d);
  display: grid; place-items: center; flex-shrink: 0; margin-top: 1px;
}
.sp-urgent-ico svg { width: 12px; height: 12px; }
.sp-urgent p { margin: 0; font-size: 12.5px; color: #374151; line-height: 1.6; }
.sp-urgent p strong { font-weight: 700; color: var(--sp-ink); }
.sp-urgent a { color: var(--sp-green-d); font-weight: 700; text-decoration: none; }
.sp-urgent a:hover { text-decoration: underline; }

/* ── Conversation view ── */
.sp-convo-hd {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
}
.sp-convo-hd h2 { margin: 0 0 3px; font-size: 17px; font-weight: 800; color: var(--sp-ink); }
.sp-convo-meta { font-size: 12px; color: var(--sp-muted); font-weight: 600; }
.sp-convo-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sp-open-pill  { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 99px; font-size: 11.5px; font-weight: 700; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.sp-open-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: #10b981; }
.sp-closed-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 99px; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #475569; border: 1px solid var(--sp-border); }
.sp-closed-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.sp-new-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 13px; border-radius: 10px; font-size: 12px; font-weight: 700;
  background: var(--sp-surface); color: var(--sp-sub);
  border: 1px solid var(--sp-border); text-decoration: none;
  transition: background .15s;
}
.sp-new-btn:hover { background: #fff; }
.sp-new-btn svg { width: 13px; height: 13px; }

/* message stream */
.sp-stream {
  background: #f0fdf9; border-radius: 14px;
  padding: 16px; display: flex; flex-direction: column; gap: 14px;
  max-height: 400px; overflow-y: auto; margin-bottom: 16px;
}
.sp-stream::-webkit-scrollbar { width: 5px; }
.sp-stream::-webkit-scrollbar-thumb { background: #99f6e4; border-radius: 5px; }
.sp-msg { display: flex; flex-direction: column; max-width: 80%; }
.sp-msg.me    { align-self: flex-end; align-items: flex-end; }
.sp-msg.them  { align-self: flex-start; align-items: flex-start; }
.sp-who { font-size: 10.5px; font-weight: 800; color: var(--sp-muted); margin-bottom: 4px; padding: 0 3px; letter-spacing: .03em; text-transform: uppercase; }
.sp-bub { padding: 10px 14px; border-radius: 14px; font-size: 13.5px; line-height: 1.55; word-break: break-word; }
.sp-msg.me   .sp-bub { background: linear-gradient(135deg,#008767,#065f46); color: #fff; border-bottom-right-radius: 4px; }
.sp-msg.them .sp-bub { background: #fff; color: var(--sp-ink); border-bottom-left-radius: 4px; box-shadow: 0 2px 8px -4px rgba(0,0,0,.12); }
.sp-time { font-size: 10px; font-weight: 600; color: var(--sp-muted); margin-top: 4px; padding: 0 3px; }

/* reply composer */
.sp-composer { display: flex; gap: 10px; align-items: flex-end; }
.sp-composer textarea { flex: 1; min-height: 50px; max-height: 140px; }

/* alert banners */
.sp-alert {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 12px; font-size: 13.5px; font-weight: 600;
  margin-bottom: 16px;
}
.sp-alert svg { width: 17px; height: 17px; flex-shrink: 0; }
.sp-alert.ok  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.sp-alert.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Responsive ── */
@media (max-width: 1100px) {
  .sp-split   { grid-template-columns: minmax(0,1fr); }
  .sp-metrics { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 740px) {
  .sp-channels { grid-template-columns: minmax(0,1fr); }
  .sp-metrics  { grid-template-columns: repeat(2,minmax(0,1fr)); }
  .sp-header   { padding: 18px 20px; }
  .sp-form-col { padding: 20px; }
  .sp-ticket-col { padding: 18px; }
}
@media (max-width: 480px) {
  .sp-metrics { grid-template-columns: minmax(0,1fr); }
  .sp-msg { max-width: 92%; }
  .sp-composer { flex-direction: column; align-items: stretch; }
  .sp-composer .sp-btn-primary { width: 100%; justify-content: center; }
}
</style>

<?php
$_svg = static function (string $p, int $s = 20): string {
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
?>

<div class="sp-page">

  <!-- ── Top header ── -->
  <header class="sp-card sp-header">
    <div class="sp-header-l">
      <div class="sp-header-ico">
        <?php echo $_svg('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 22); ?>
      </div>
      <div>
        <h1>Help &amp; Support</h1>
        <p>Chat with us on WhatsApp, drop an email, or raise a ticket and track every reply right inside your dashboard.</p>
      </div>
    </div>
    <div class="sp-header-r">
      <span class="sp-status-pill <?php echo $statOpen > 0 ? 'active' : ''; ?>">
        <span class="dot"></span>
        <?php echo $statOpen; ?> open ticket<?php echo $statOpen === 1 ? '' : 's'; ?>
      </span>
      <a href="support.php" class="sp-btn-primary">
        <?php echo $_svg('<path d="M5 12h14"/><path d="M12 5v14"/>', 15); ?>
        New ticket
      </a>
    </div>
  </header>

  <!-- ── Metrics row ── -->
  <section class="sp-metrics" aria-label="Support metrics">
    <div class="sp-card sp-metric">
      <div class="sp-metric-ico teal"><?php echo $_svg('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'); ?></div>
      <div><b><?php echo $statTotal; ?></b><span>Total tickets raised</span></div>
    </div>
    <div class="sp-card sp-metric">
      <div class="sp-metric-ico sky"><?php echo $_svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'); ?></div>
      <div><b><?php echo $statOpen; ?></b><span>Open, waiting on us</span></div>
    </div>
    <div class="sp-card sp-metric">
      <div class="sp-metric-ico amber"><?php echo $_svg('<path d="M20 6 9 17l-5-5"/>'); ?></div>
      <div><b><?php echo $statClosed; ?></b><span>Closed &amp; resolved</span></div>
    </div>
    <div class="sp-card sp-metric">
      <div class="sp-metric-ico purple"><?php echo $_svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'); ?></div>
      <div><b><?php echo $avgReply !== '' ? $avgReply : '—'; ?></b><span>Avg. first reply</span></div>
    </div>
  </section>

  <!-- ── Alert banners ── -->
  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
  <div class="sp-alert ok"><?php echo $_svg('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> Ticket created — our team has been notified and will reply here.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <div class="sp-alert err"><?php echo $_svg('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>', 18); ?> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <!-- ── Channel cards ── -->
  <section class="sp-channels" aria-label="Support channels">

    <div class="sp-card sp-channel">
      <div class="sp-channel-top">
        <div class="sp-channel-ico green">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
        </div>
        <span class="sp-channel-tag green">Fastest</span>
      </div>
      <h3>Instant WhatsApp</h3>
      <p>Demo doubts, campaign questions or payment issues — chat with our team directly for the fastest resolution.</p>
      <a href="https://wa.me/919150802906?text=Hi%20WAAMINI%20Support,%20I%20need%20help%20with%20my%20account." target="_blank" rel="noopener noreferrer" class="sp-btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
        Chat with +91 9150802906
      </a>
    </div>

    <div class="sp-card sp-channel">
      <div class="sp-channel-top">
        <div class="sp-channel-ico blue"><?php echo $_svg('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'); ?></div>
        <span class="sp-channel-tag blue">24 hrs</span>
      </div>
      <h3>Email support</h3>
      <p>Custom requirements, billing issues or detailed technical questions — write to us and we reply within a day.</p>
      <a href="mailto:info@7digit.in" class="sp-btn-soft">
        <?php echo $_svg('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>', 14); ?>
        info@7digit.in
      </a>
    </div>

    <div class="sp-card sp-channel">
      <div class="sp-channel-top">
        <div class="sp-channel-ico amber"><?php echo $_svg('<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>'); ?></div>
        <span class="sp-channel-tag amber">Self-serve</span>
      </div>
      <h3>User guide</h3>
      <p>Step-by-step docs for every WAAMINI feature in English, Tamil and Hindi — most answers live here already.</p>
      <a href="manual.php" class="sp-btn-soft">
        <?php echo $_svg('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>', 14); ?>
        Open the guide
      </a>
    </div>

  </section>

  <!-- ── Two-column split: ticket list + form/conversation ── -->
  <div class="sp-split">

    <!-- LEFT: ticket list -->
    <div class="sp-card sp-ticket-col">
      <div class="sp-ticket-col-head">
        <h2>
          <span class="ico"><?php echo $_svg('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>', 15); ?></span>
          My tickets
        </h2>
        <span class="sp-col-count"><?php echo $statTotal; ?> total</span>
      </div>
      <p>Every conversation with the WAAMINI team stays saved here.</p>

      <?php if ($statTotal > 0): ?>
      <div class="sp-ticket-list">
        <?php foreach ($ticketRows as $t):
          $isActive = ($activeTicketId === (int)$t['id']);
          $isOpen   = (($t['status'] ?? '') === 'open');
          $preview  = trim((string)($t['last_msg'] ?? ''));
          $preview  = $preview === '' ? 'No message yet.' : $preview;
          $when     = !empty($t['last_at']) ? (string)$t['last_at'] : (string)$t['created_at'];
        ?>
        <a href="support.php?ticket=<?php echo (int)$t['id']; ?>"
           class="sp-tk-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isOpen ? 'open' : ''; ?>">
          <div class="sp-tk-top">
            <span class="sp-tk-subj"><?php echo htmlspecialchars((string)$t['subject'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="sp-tk-tag <?php echo $isOpen ? 'open' : 'closed'; ?>"><?php echo $isOpen ? 'Open' : 'Closed'; ?></span>
          </div>
          <div class="sp-tk-preview">"<?php echo htmlspecialchars(mb_strimwidth($preview, 0, 100, '…'), ENT_QUOTES, 'UTF-8'); ?>"</div>
          <div class="sp-tk-foot">
            <span>#<?php echo (int)$t['id']; ?> · <?php echo (int)$t['msg_count']; ?> message<?php echo (int)$t['msg_count'] === 1 ? '' : 's'; ?></span>
            <span><?php echo htmlspecialchars(date('d M, g:i A', strtotime($when)), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="sp-empty">
        <div class="sp-empty-ico"><?php echo $_svg('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 26); ?></div>
        <h3>No tickets yet</h3>
        <p>When you raise a ticket, every reply from our team is collected here.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: conversation or new ticket form -->
    <div class="sp-card sp-form-col">
      <?php
      $ticketNotFound = false;
      $ticket = null;

      if ($activeTicketId > 0):
        $stSup4 = $conn->prepare('SELECT * FROM support_tickets WHERE id=? AND company_id=? LIMIT 1');
        if ($stSup4) {
          $stSup4->bind_param('ii', $activeTicketId, $companyId);
          if ($stSup4->execute()) { $ticket = $stSup4->get_result()->fetch_assoc(); }
          $stSup4->close();
        }
        if (is_array($ticket)):
          $stSup5 = $conn->prepare('SELECT m.*, u.name as admin_name
              FROM support_messages m
              LEFT JOIN users u ON m.sender_id = u.id AND m.sender_type = \'admin\'
              WHERE m.ticket_id=? ORDER BY m.id ASC');
          $msgs = null;
          if ($stSup5) {
            $stSup5->bind_param('i', $activeTicketId);
            if ($stSup5->execute()) { $msgs = $stSup5->get_result(); }
            $stSup5->close();
          }
      ?>
        <div class="sp-convo-hd">
          <div>
            <h2><?php echo htmlspecialchars((string)$ticket['subject'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="sp-convo-meta">Ticket #<?php echo (int)$ticket['id']; ?> · Created <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string)$ticket['created_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="sp-convo-actions">
            <?php if ($ticket['status'] === 'open'): ?>
              <span class="sp-open-pill"><span class="dot"></span> Open</span>
            <?php else: ?>
              <span class="sp-closed-pill"><span class="dot"></span> Closed</span>
            <?php endif; ?>
            <a href="support.php" class="sp-new-btn">
              <?php echo $_svg('<path d="M5 12h14"/><path d="M12 5v14"/>', 13); ?>
              New
            </a>
          </div>
        </div>

        <div class="sp-stream" id="spStream">
          <?php if ($msgs):
            while ($m = $msgs->fetch_assoc()):
              $isClient = ($m['sender_type'] === 'client'); ?>
          <div class="sp-msg <?php echo $isClient ? 'me' : 'them'; ?>">
            <div class="sp-who"><?php echo $isClient ? 'You' : 'WAAMINI Support — ' . htmlspecialchars((string)($m['admin_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="sp-bub"><?php echo nl2br(htmlspecialchars((string)$m['message'], ENT_QUOTES, 'UTF-8')); ?></div>
            <div class="sp-time"><?php echo htmlspecialchars(date('d M, h:i A', strtotime((string)$m['created_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php endwhile;
          else: ?>
          <div class="sp-empty" style="background:#fff;">
            <div class="sp-empty-ico"><?php echo $_svg('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 26); ?></div>
            <h3>No messages yet</h3>
            <p>Your ticket is in the queue — the team will reply shortly.</p>
          </div>
          <?php endif; ?>
        </div>

        <form method="POST" action="support.php?ticket=<?php echo (int)$activeTicketId; ?>" class="sp-composer">
          <?php echo csrf_field(); ?>
          <div class="sp-field" style="flex:1;margin:0;">
            <textarea name="message" rows="2" placeholder="Type your reply here…" required maxlength="65535"></textarea>
          </div>
          <button type="submit" name="send_reply" class="sp-btn-primary">
            Send <?php echo $_svg('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>', 16); ?>
          </button>
        </form>

      <?php
        else:
          $ticketNotFound = true;
        endif;
      endif;

      if ($activeTicketId <= 0 || !empty($ticketNotFound)): ?>

        <?php if (!empty($ticketNotFound)): ?>
        <div class="sp-alert err"><?php echo $_svg('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>', 18); ?> Ticket not found or you do not have access.</div>
        <?php endif; ?>

        <div class="sp-form-head">
          <div>
            <h2><?php echo !empty($ticketNotFound) ? 'Open a new ticket' : 'Create a support ticket'; ?></h2>
            <p>Send a message straight to the WAAMINI team — replies land back in this thread.</p>
          </div>
          <span class="sp-replies-tag">Replies in-app</span>
        </div>

        <form method="POST" action="support.php">
          <?php echo csrf_field(); ?>
          <div class="sp-field">
            <label for="sp_subject">Subject</label>
            <input type="text" id="sp_subject" name="subject" placeholder="e.g. Need help creating a template" required maxlength="255">
            <span class="hint">One line that sums up your question or issue.</span>
          </div>
          <div class="sp-field">
            <label for="sp_message">Message detail</label>
            <textarea id="sp_message" name="message" rows="6" placeholder="Please describe your issue or question in detail — add screenshots' description, campaign name or invoice number if relevant." required maxlength="65535"></textarea>
          </div>
          <button type="submit" name="create_ticket" class="sp-btn-primary block">
            <?php echo $_svg('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>', 15); ?>
            Submit ticket
          </button>
        </form>

        <div class="sp-urgent">
          <div class="sp-urgent-ico"><?php echo $_svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>', 12); ?></div>
          <p><strong>In a hurry?</strong> WhatsApp us on <a href="https://wa.me/919150802906">+91 9150802906</a> for a reply in minutes, or browse the <a href="manual.php">user guide</a> for instant self-help.</p>
        </div>

      <?php endif; ?>
    </div>

  </div>

  <!-- ── Footer ── -->
  <footer style="text-align:center;padding:4px 0 8px;font-size:12px;color:#94a3b8;font-weight:500;">
    WAAMINI Help Desk · Powered by <span style="color:#475569;font-weight:700;">7digit.in</span>
  </footer>

</div>

<script>
(function() {
  var s = document.getElementById('spStream');
  if (s) s.scrollTop = s.scrollHeight;
})();
</script>

<?php include '../includes/client_footer.php'; ?>
