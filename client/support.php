<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('set_support')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
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
    // Category and urgency chosen by the client (added later; old tickets have none)
    foreach (['category' => "VARCHAR(40) NULL", 'priority' => "VARCHAR(12) NOT NULL DEFAULT 'normal'"] as $col => $def) {
        try {
            $r = $conn->query("SHOW COLUMNS FROM support_tickets LIKE '$col'");
            if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE support_tickets ADD COLUMN $col $def");
        } catch (\Throwable $e) { error_log('[support.php] add column ' . $col . ': ' . $e->getMessage()); }
    }
    $done = true;
}

waamini_support_ensure_tables($conn);

/* Ticket categories and urgency levels offered in the form (whitelists: anything else is ignored) */
$supportCategories = [
    'whatsapp'  => 'WhatsApp number & API',
    'templates' => 'Templates & approval',
    'campaigns' => 'Campaigns & broadcasts',
    'automation'=> 'Bots, flows & automation',
    'integrations' => 'Integrations & webhooks',
    'billing'   => 'Billing & subscription',
    'other'     => 'Something else',
];
$supportPriorities = [
    'normal'   => ['Normal', 'Question or how-to'],
    'high'     => ['High', 'Something is not working'],
    'critical' => ['Critical', 'Messages are not going out'],
];

/* Support desk hours: Monday to Saturday, 9 AM to 7 PM IST */
$__ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$__dow = (int)$__ist->format('N');
$__hr  = (int)$__ist->format('G') + (int)$__ist->format('i') / 60;
$deskOpen = $__dow <= 6 && $__hr >= 9 && $__hr < 19;
$deskBack = $deskOpen ? '' : (($__dow <= 6 && $__hr < 9) ? 'today' : ($__dow <= 5 ? 'tomorrow' : 'on Monday'));

$success = '';
$error = '';

$userId = (int)($_SESSION['user_id'] ?? 0);

// Handle creating new ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    csrf_verify();

    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 65535);
    $category = (string)($_POST['category'] ?? '');
    $category = isset($supportCategories[$category]) ? $category : 'other';
    $priority = (string)($_POST['priority'] ?? '');
    $priority = isset($supportPriorities[$priority]) ? $priority : 'normal';

    if ($subject === '' || $message === '') {
        $error = 'Subject and message are required.';
    } elseif ($userId <= 0) {
        $error = 'Your session is invalid. Please log in again.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO support_tickets (company_id, user_id, subject, category, priority) VALUES (?, ?, ?, ?, ?)');
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('iisss', $companyId, $userId, $subject, $category, $priority);
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
            $firstMessage = 'Category: ' . $supportCategories[$category] . ' · Urgency: ' . $supportPriorities[$priority][0] . "

" . $message;
            $msgStmt->bind_param('iis', $ticketId, $userId, $firstMessage);
            if (!$msgStmt->execute()) {
                throw new RuntimeException($msgStmt->error);
            }
            $msgStmt->close();

            $conn->commit();
            header('Location: support.php?ticket=' . $ticketId . '&msg=created#hcTickets');
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
                        header('Location: support.php?ticket=' . $ticketId . '#hcTickets');
                        exit();
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Client marks their own ticket as resolved (a reply reopens it)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    csrf_verify();
    $ticketId = isset($_GET['ticket']) ? (int)$_GET['ticket'] : 0;
    if ($ticketId > 0 && $userId > 0) {
        $stClose = $conn->prepare("UPDATE support_tickets SET status='closed' WHERE id=? AND company_id=? AND status<>'closed'");
        if ($stClose) {
            $stClose->bind_param('ii', $ticketId, $companyId);
            $stClose->execute();
            $changed = $stClose->affected_rows > 0;
            $stClose->close();
            if ($changed) {   // leave a note in the thread so the support team sees who closed it
                $note = 'Marked as resolved by the customer.';
                $stNote = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'client', ?, ?)");
                if ($stNote) { $stNote->bind_param('iis', $ticketId, $userId, $note); $stNote->execute(); $stNote->close(); }
            }
        }
    }
    header('Location: support.php?ticket=' . $ticketId . '&msg=closed#hcTickets');
    exit();
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

/* ── View helpers ── */
$sx = static fn(string $p, int $s = 18): string => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
// The first message of a ticket starts with "Category: … · Urgency: …" for the admin inbox; the client sees chips instead
$cleanMsg = static function (string $m): string {
    return preg_match('/^Category: [^\n]*· Urgency: [^\n]*\R\R/u', $m) ? (string)preg_replace('/^Category: [^\n]*· Urgency: [^\n]*\R\R/u', '', $m, 1) : $m;
};
$fmtDur = static function (int $sec): string {
    if ($sec < 3600) return max(1, (int)round($sec / 60)) . ' min';
    if ($sec < 86400) return round($sec / 3600, 1) . ' h';
    return round($sec / 86400, 1) . ' days';
};
$resolvedPct = $statTotal > 0 ? (int)round($statClosed * 100 / $statTotal) : 0;
$waText = rawurlencode('Hi WAAMINI Support, I need help with my account.');
$I = [
    'wa'     => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'book'   => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    'inbox'  => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'check'  => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
    'zap'    => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'ticket' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    'help'   => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'back'   => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'x'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
];

/* ── Help-centre data: the client's own guides (same plan filter as the User Guide) ── */
require_once __DIR__ . '/guide_catalog.php';
$hcCats = waamini_guide_categories();
$hcGuides = waamini_guides_for_plan();
$hcByCat = [];
foreach ($hcGuides as $g) { $hcByCat[$g['cat']][] = $g; }
$hcByKey = array_column($hcGuides, null, 'key');
$hcDesc = [
    'start'      => 'Connect your number, set up your business and learn the dashboard.',
    'messaging'  => 'Reply to chats, get templates approved and send campaigns.',
    'contacts'   => 'Add, import and group customers, and respect opt-outs.',
    'automation' => 'Auto-replies, the WAAMINI AI bot, flows and follow-ups.',
    'sales'      => 'Catalogue, orders, payments, quotations and invoices.',
    'growth'     => 'Ad leads, analytics and the health of your number.',
    'reminders'  => 'Automatic fee, service and medicine reminders.',
    'connect'    => 'Webhooks, API keys, Google Sheets and other apps.',
    'account'    => 'Team members, security, alerts, plan and billing.',
];
$hcTone = ['start' => 't-green', 'messaging' => 't-blue', 'contacts' => 't-rose', 'automation' => 't-violet', 'sales' => 't-amber', 'growth' => 't-blue', 'reminders' => 't-amber', 'connect' => 't-violet', 'account' => 't-green'];
$hcPopular = array_values(array_filter(['templates', 'campaigns', 'connect-whatsapp', 'bot-replies', 'contacts', 'waamini-bot', 'bulk-import'], static fn($k) => isset($hcByKey[$k])));
$hcSearch = array_map(static fn($g) => ['k' => $g['key'], 't' => $g['title'], 'c' => $hcCats[$g['cat']][0] ?? '', 'q' => mb_strtolower($g['title'] . ' ' . $g['summary'] . ' ' . strip_tags(implode(' ', $g['steps'])))], $hcGuides);

/* Go-live checklist from the client's real data */
$hcOn = static fn(string $k): bool => !function_exists('has_feature') || has_feature($k);
$hcCount = static function (string $sql) use ($conn, $companyId): int {
    try { $st = $conn->prepare($sql); if (!$st) return 0; $cid = (int)$companyId; $st->bind_param('i', $cid); $st->execute(); $r = $st->get_result()->fetch_row(); $st->close(); return (int)($r[0] ?? 0); }
    catch (\Throwable $e) { return 0; }
};
$hcWaOk = true;
if ($hcOn('set_whatsapp')) {
    try { require_once __DIR__ . '/../includes/wa_connection_notice.php'; $hcWaOk = function_exists('wa_connection_state') ? wa_connection_state($conn, (int)$companyId) === 'ok' : true; } catch (\Throwable $e) { $hcWaOk = true; }
}
$hcCheck = [];
if ($hcOn('set_whatsapp')) $hcCheck[] = ['Connect your WhatsApp number', 'Link your WhatsApp Business number under Settings → WhatsApp Setup.', '5 min', $hcWaOk, 'settings_whatsapp.php', 'connect-whatsapp'];
if ($hcOn('set_general'))  $hcCheck[] = ['Add your business details', 'Business name, logo and contact details used across WAAMINI.', '5 min', null, 'settings_general.php', 'business-profile'];
if ($hcOn('contacts'))     $hcCheck[] = ['Add your first contacts', 'Add customers one by one, or import a CSV file.', '10 min', $hcCount('SELECT COUNT(*) FROM contacts WHERE company_id = ?') > 0, 'contacts.php', 'contacts'];
if ($hcOn('templates'))    $hcCheck[] = ['Get a template approved', 'Create a template and submit it to Meta for review.', 'Minutes to 24 hrs', $hcCount("SELECT COUNT(*) FROM message_templates WHERE company_id = ? AND UPPER(wa_status) = 'APPROVED'") > 0, 'templates.php', 'templates'];
if ($hcOn('campaigns'))    $hcCheck[] = ['Send your first campaign', 'Send an approved template to a group of contacts.', '10 min', $hcCount('SELECT COUNT(*) FROM campaigns WHERE company_id = ?') > 0, 'campaigns.php', 'campaigns'];
$hcDone = count(array_filter($hcCheck, static fn($c) => $c[3] === true));
$hcTotal = count(array_filter($hcCheck, static fn($c) => $c[3] !== null));

/* Latest ticket for the hero preview */
$hcLatest = $ticketRows[0] ?? null;
$hcLatestMsgs = [];
if ($hcLatest) {
    $stL = $conn->prepare('SELECT sender_type, message, created_at FROM support_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 3');
    if ($stL) { $lid = (int)$hcLatest['id']; $stL->bind_param('i', $lid); $stL->execute(); $rL = $stL->get_result(); while ($x = $rL->fetch_assoc()) { $hcLatestMsgs[] = $x; } $stL->close(); }
    $hcLatestMsgs = array_reverse($hcLatestMsgs);
}
$hcMsgText = static function (array $m) use ($cleanMsg): string {
    $t = (string)$m['message'];
    if (($m['sender_type'] ?? '') === 'admin') { $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8'); }   // older admin replies were stored escaped
    return $cleanMsg($t);
};
$avgReplyTxt = $replyCount > 0 ? $fmtDur((int)round($replySeconds / $replyCount)) : '';

$faqs = [
    ['Why was my WhatsApp template rejected?', 'Meta rejects templates that look promotional but are filed as Utility, that use variables without sample values, or that start or end with a variable. Change the wording or category under Templates and submit it again.', 'templates.php', 'Open Templates'],
    ['What is the 24-hour customer service window?', 'When a customer messages you, a 24-hour window opens. Inside it your team can reply freely from Chats. Once it closes, you can only start the conversation again with an approved template.', 'whatsapp.php', 'Open Chats'],
    ['Why are some campaign messages not delivered?', 'Common reasons are numbers that are not on WhatsApp, a missing country code, your daily messaging limit, or Meta limiting marketing messages to people who receive many of them. Open the campaign to see the reason for each contact.', 'campaigns.php', 'Open Campaigns'],
    ['How do I connect or reconnect my WhatsApp number?', 'Go to Settings → WhatsApp Setup and follow the steps there. If messages stopped suddenly, check that the number is still connected and that your access token has not expired.', 'settings_whatsapp.php', 'Open WhatsApp Setup'],
    ['How do I get the green tick on my business profile?', 'The verified badge is granted by Meta, not by WAAMINI. Your business needs a verified Meta Business account and public press coverage of the brand. Raise a ticket and we will help you prepare the request.', '#hcNew', 'Raise a ticket'],
    ['How do I renew or upgrade my plan?', 'Open My Subscription to see your plan, its end date and the upgrade options. Payments are listed under Billing & Wallet.', 'subscription.php', 'Open My Subscription'],
];
$I['copy'] = '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>';
$I['cal']  = '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>';
$I['code'] = '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>';
$I['doc']  = '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 13H8"/><path d="M16 17H8"/>';
$I['search'] = '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>';
$I['chev'] = '<path d="m9 18 6-6-6-6"/>';
?>
<style>
/* ═══ Help & Support (client) · SaaS help-centre layout · every number and list is live ═══ */
.hc{--bg:#F6F8FA;--sf:#fff;--ink:#0F1E2E;--mu:#5B6878;--line:#E3E8EE;--line2:#CDD5DE;
  --ac:#128C7E;--ac-d:#0B6B5E;--ac-s:#E7F4EE;--ok:#14804A;
  --bl:#2563B8;--bl-s:#EAF1FB;--am:#A8650A;--am-s:#FDF3E2;--vi:#5B45B8;--vi-s:#F0EDFB;--ro:#B4344A;--ro-s:#FCECEE;
  --sh:0 1px 2px rgba(15,30,46,.04),0 4px 14px rgba(15,30,46,.05);
  max-width:1240px;margin:0 auto;color:var(--ink);font-family:Inter,system-ui,sans-serif;font-size:14.5px;line-height:1.6;display:flex;flex-direction:column;gap:22px}
.hc *{box-sizing:border-box}
.hc a{color:inherit;text-decoration:none}
.hc svg{flex-shrink:0}
.hc h1,.hc h2,.hc h3,.hc h4{margin:0;letter-spacing:-.01em;text-wrap:balance}
.hc :focus-visible{outline:2px solid var(--ac);outline-offset:2px;border-radius:6px}
.hc-card{background:var(--sf);border:1px solid var(--line);border-radius:14px}
.hc-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:9px;font:600 14px Inter,system-ui,sans-serif;border:1px solid transparent;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s}
.hc-btn--pri{background:var(--ac);color:#fff}.hc-btn--pri:hover{background:var(--ac-d);color:#fff}
.hc-btn--ghost{background:var(--sf);color:var(--ink);border-color:var(--line2)}.hc-btn--ghost:hover{border-color:var(--ink)}
.hc-btn--block{width:100%}
.hc-eyebrow{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ac);margin-bottom:6px}
.hc-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.hc-head h2{font-size:clamp(21px,2.3vw,26px);font-weight:800}
.hc-head p{margin:4px 0 0;color:var(--mu)}
.hc-link{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:14px;color:var(--ac)}
.hc-link:hover{gap:9px}
.hc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.dot{width:8px;height:8px;border-radius:50%;background:#94a3b8;display:inline-block;flex:none}
.dot.on{background:var(--ok);box-shadow:0 0 0 3px rgba(20,128,74,.22)}

/* hero */
.hc-hero{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:44px;align-items:center;padding:clamp(24px,3.4vw,44px);border-radius:16px;background:var(--sf);border:1px solid var(--line)}
.hc-status{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ok);background:var(--ac-s);padding:5px 12px 5px 10px;border-radius:99px}
.hc-status.off{color:var(--am);background:var(--am-s)}
.hc-status.off .dot{background:var(--am)}
.hc-hero h1{font-size:clamp(28px,3.6vw,40px);line-height:1.15;font-weight:800;margin:16px 0 10px}
.hc-lead{color:var(--mu);font-size:16.5px;margin:0 0 24px;max-width:52ch}
.hc-search{position:relative;max-width:600px}
.hc-sbox{display:flex;align-items:center;gap:12px;height:56px;padding:0 7px 0 18px;background:var(--sf);border:1.5px solid var(--line2);border-radius:12px;box-shadow:var(--sh);cursor:text}
.hc-sbox:focus-within{border-color:var(--ac);box-shadow:0 0 0 4px var(--ac-s)}
.hc-sbox svg{color:var(--mu)}
.hc-sbox input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 15.5px Inter,system-ui,sans-serif;color:var(--ink)}
.hc-sbox kbd{font:500 12px ui-monospace,monospace;color:var(--mu);border:1px solid var(--line2);border-bottom-width:2px;border-radius:5px;padding:1px 7px;background:var(--bg)}
.hc-results{position:absolute;left:0;right:0;top:64px;background:var(--sf);border:1px solid var(--line2);border-radius:12px;box-shadow:0 12px 32px rgba(15,30,46,.14);padding:6px;z-index:20;max-height:360px;overflow:auto}
.hc-results a{display:flex;gap:12px;align-items:flex-start;padding:10px 12px;border-radius:8px}
.hc-results a:hover,.hc-results a.on{background:var(--bg)}
.hc-results svg{margin-top:3px;color:var(--mu)}
.hc-results b{display:block;font-size:14px;font-weight:600}
.hc-results small{font-size:12px;color:var(--mu)}
.hc-results .empty{padding:14px 12px;color:var(--mu);font-size:14px}
.hc-results mark{background:var(--ac-s);color:var(--ac-d);border-radius:3px;padding:0 1px}
.hc-popular{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:16px;font-size:13px;color:var(--mu)}
.hc-chip{border:1px solid var(--line);background:var(--bg);color:var(--ink);padding:5px 12px;border-radius:99px;font-size:13px;font-weight:500}
.hc-chip:hover{border-color:var(--ac);color:var(--ac)}

.hc-convo{background:var(--bg);border:1px solid var(--line);border-radius:16px;padding:18px;display:grid;gap:12px;min-width:0}
.hc-convo__h{display:flex;align-items:center;gap:12px;padding-bottom:12px;border-bottom:1px solid var(--line)}
.hc-av{width:38px;height:38px;border-radius:50%;background:var(--ac);color:#fff !important;display:grid;place-items:center;font-weight:700;font-size:14px;flex:none}
.hc-convo__h b{display:block;font-size:14px}
.hc-convo__h span{font-size:12px;color:var(--mu)}
.hc-badge{margin-left:auto;font:500 11px ui-monospace,monospace;color:var(--mu);border:1px solid var(--line);border-radius:6px;padding:2px 8px;background:var(--sf);white-space:nowrap}
.hc-convo__t{font-size:13.5px;font-weight:700}
.hc-msg{max-width:88%;padding:10px 14px;border-radius:12px;font-size:13.5px;line-height:1.5;overflow-wrap:anywhere}
.hc-msg.in{background:var(--sf);border:1px solid var(--line);border-top-left-radius:4px}
.hc-msg.out{background:var(--ac-s);justify-self:end;border-top-right-radius:4px}
.hc-msg time{display:block;font:500 10.5px ui-monospace,monospace;color:var(--mu);margin-top:4px;text-align:right}
.hc-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.hc-meta div{background:var(--sf);border:1px solid var(--line);border-radius:10px;padding:10px 12px;min-width:0}
.hc-meta b{display:block;font-size:16.5px;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hc-meta span{font-size:11.5px;color:var(--mu)}
.hc-sample{font-size:11.5px;color:var(--mu);text-align:center}

.hc-alert{display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:10px;font-weight:600;font-size:14px}
.hc-alert--ok{background:var(--ac-s);color:#0B5A3C}.hc-alert--err{background:var(--ro-s);color:var(--ro)}

/* tickets */
.hc-desk{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:20px;align-items:start}
.hc-tk{display:flex;flex-direction:column;min-width:0}
.hc-tk__hd{padding:18px 20px 12px;border-bottom:1px solid var(--line)}
.hc-tk__hd h3{font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px}
.hc-tk__stats{display:flex;gap:18px;margin:8px 0 12px;font-size:13px;color:var(--mu)}
.hc-tk__stats b{color:var(--ink);font-size:15px;margin-right:3px}
.hc-seg{display:inline-flex;padding:3px;border-radius:9px;background:var(--bg);border:1px solid var(--line)}
.hc-seg button{all:unset;cursor:pointer;padding:6px 12px;border-radius:7px;font-size:13px;font-weight:600;color:var(--mu)}
.hc-seg button.on{background:var(--sf);color:var(--ink);box-shadow:0 1px 2px rgba(15,30,46,.1)}
.hc-seg button:focus-visible{outline:2px solid var(--ac)}
.hc-list{max-height:560px;overflow-y:auto}
.hc-row{display:block;padding:14px 20px;border-bottom:1px solid var(--line);transition:background .12s}
.hc-row:hover{background:var(--bg)}
.hc-row.on{background:var(--ac-s);box-shadow:inset 3px 0 0 var(--ac)}
.hc-row__m{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--mu)}
.hc-row__m .id{font-family:ui-monospace,monospace;font-weight:500}
.hc-row__m time{margin-left:auto}
.hc-row h4{margin:5px 0 3px;font-size:14.5px;font-weight:600;overflow-wrap:anywhere}
.hc-row p{margin:0;font-size:13px;color:var(--mu);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.hc-row__f{display:flex;justify-content:space-between;gap:10px;margin-top:7px;font-size:12px;color:var(--mu)}
.hc-row__f b{color:var(--ok);font-weight:600}.hc-row__f b.wait{color:var(--am)}
.hc-st{display:inline-flex;align-items:center;gap:5px;padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:700}
.hc-st--open{background:var(--bl-s);color:var(--bl)}.hc-st--closed{background:var(--ac-s);color:var(--ok)}
.hc-pr{padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:700}
.hc-pr--high{background:var(--am-s);color:var(--am)}.hc-pr--critical{background:var(--ro-s);color:var(--ro)}
.hc-tag{padding:1px 8px;border-radius:6px;background:var(--bg);border:1px solid var(--line);font-size:11.5px;font-weight:600;color:var(--mu)}
.hc-none{display:none;padding:22px;text-align:center;color:var(--mu);font-size:13.5px}
.hc-empty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:36px 22px}
.hc-empty .i{width:54px;height:54px;border-radius:50%;background:var(--ac-s);color:var(--ac);display:grid;place-items:center;margin-bottom:4px}
.hc-empty b{font-size:16px}.hc-empty p{margin:0;color:var(--mu);font-size:13.5px;max-width:34ch}

.hc-thread{display:flex;flex-direction:column;min-width:0}
.hc-thread__hd{display:flex;align-items:flex-start;gap:12px;padding:18px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.hc-thread__hd > div{flex:1;min-width:220px}
.hc-thread__hd h3{font-size:17px;font-weight:700;overflow-wrap:anywhere}
.hc-thread__hd .hc-row__m{margin-top:6px}
.hc-thread__acts{display:flex;gap:8px;flex-wrap:wrap}
.hc-thread__acts form{margin:0}
.hc-thread__acts .hc-btn{height:36px;padding:0 13px;font-size:13px}
.hc-stream{max-height:460px;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:14px;background:var(--bg)}
.hc-bub{display:flex;flex-direction:column;max-width:84%}
.hc-bub.me{align-self:flex-end;align-items:flex-end}
.hc-bub small{font-size:12px;font-weight:600;color:var(--mu);margin:0 4px 4px}
.hc-bub div{padding:11px 15px;border-radius:12px;font-size:14px;line-height:1.6;overflow-wrap:anywhere}
.hc-bub.them div{background:var(--sf);border:1px solid var(--line);border-top-left-radius:4px}
.hc-bub.me div{background:var(--ac);color:#fff;border-top-right-radius:4px}
.hc-bub time{font:500 11px ui-monospace,monospace;color:var(--mu);margin:4px 4px 0}
.hc-compose{display:flex;gap:10px;align-items:flex-end;padding:14px 20px 18px;border-top:1px solid var(--line)}
.hc-compose textarea{flex:1;min-width:0;resize:vertical;min-height:46px;padding:11px 14px;border:1.5px solid var(--line2);border-radius:10px;font:500 14px/1.5 Inter,system-ui,sans-serif;color:var(--ink);background:var(--sf);outline:0}
.hc-compose textarea:focus{border-color:var(--ac);box-shadow:0 0 0 4px var(--ac-s)}
.hc-closed-note{margin:0;padding:10px 20px;font-size:12.5px;color:var(--mu);background:var(--bg);border-top:1px solid var(--line)}

.hc-form{padding:22px}
.hc-form h3{font-size:18px;font-weight:700}
.hc-form > p{margin:4px 0 18px;color:var(--mu);font-size:14px}
.hc-g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hc-f{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.hc-f label{font-size:13px;font-weight:600}
.hc-f label i{color:var(--ro);font-style:normal}
.hc-f input,.hc-f select,.hc-f textarea{width:100%;padding:11px 13px;border:1.5px solid var(--line2);border-radius:9px;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);background:var(--sf);outline:0}
.hc-f textarea{resize:vertical;line-height:1.55}
.hc-f input:focus,.hc-f select:focus,.hc-f textarea:focus{border-color:var(--ac);box-shadow:0 0 0 4px var(--ac-s)}
.hc-f small{font-size:12px;color:var(--mu)}
.hc-pri{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.hc-pri label{position:relative;display:flex;flex-direction:column;gap:2px;padding:10px 12px;border:1.5px solid var(--line2);border-radius:9px;cursor:pointer;font-weight:600;font-size:13.5px}
.hc-pri label span{font-size:11.5px;font-weight:500;color:var(--mu)}
.hc-pri input{position:absolute;opacity:0;pointer-events:none}
.hc-pri label:has(input:checked){border-color:var(--ac);background:var(--ac-s)}
.hc-pri label:has(input:focus-visible){outline:2px solid var(--ac);outline-offset:2px}
.hc-advice{display:flex;gap:10px;margin-top:14px;padding:12px 14px;border-radius:10px;background:var(--am-s);color:#6B4A0F;font-size:13px;line-height:1.5}
.hc-advice svg{margin-top:2px}
.hc-advice a{font-weight:700;text-decoration:underline}

/* topics */
.hc-cats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.hc-cat{background:var(--sf);border:1px solid var(--line);border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:12px;transition:border-color .15s,box-shadow .15s,transform .15s;min-width:0}
.hc-cat:hover{border-color:var(--ac);box-shadow:var(--sh);transform:translateY(-2px)}
.hc-cat__top{display:flex;align-items:center;justify-content:space-between}
.hc-ico{width:44px;height:44px;border-radius:10px;display:grid;place-items:center}
.t-green{background:var(--ac-s);color:var(--ac)}.t-blue{background:var(--bl-s);color:var(--bl)}.t-amber{background:var(--am-s);color:var(--am)}.t-violet{background:var(--vi-s);color:var(--vi)}.t-rose{background:var(--ro-s);color:var(--ro)}
.hc-count{font:500 11.5px ui-monospace,monospace;color:var(--mu)}
.hc-cat h3{font-size:16px;font-weight:700}
.hc-cat > p{margin:-6px 0 0;color:var(--mu);font-size:13.5px;line-height:1.55}
.hc-cat ul{list-style:none;margin:auto 0 0;padding:12px 0 0;border-top:1px dashed var(--line);display:grid;gap:6px}
.hc-cat li a{display:flex;gap:8px;align-items:flex-start;font-size:13.5px;font-weight:500}
.hc-cat li a svg{margin-top:4px;color:var(--mu)}
.hc-cat li a:hover{color:var(--ac)}

/* checklist + popular */
.hc-split{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.hc-panel{padding:24px;min-width:0}
.hc-panel h3{font-size:18px;font-weight:700;margin-bottom:2px}
.hc-panel > p{color:var(--mu);font-size:14px;margin:0 0 18px}
.hc-steps{list-style:none;margin:0;padding:0}
.hc-steps li{display:grid;grid-template-columns:32px 1fr auto;gap:14px;padding:13px 0;border-top:1px solid var(--line);align-items:start}
.hc-steps li:first-child{border-top:0;padding-top:0}
.hc-num{width:32px;height:32px;border-radius:50%;border:1.5px solid var(--line2);display:grid;place-items:center;font-weight:700;font-size:13px;color:var(--mu)}
.hc-num.done{background:var(--ac);border-color:var(--ac);color:#fff}
.hc-steps a b{display:block;font-size:14.5px}
.hc-steps a:hover b{color:var(--ac)}
.hc-steps span.d{font-size:13px;color:var(--mu)}
.hc-dur{font:500 11.5px ui-monospace,monospace;color:var(--mu);white-space:nowrap;padding-top:3px}
.hc-dur.done{color:var(--ok);font-family:Inter,system-ui,sans-serif;font-weight:700}
.hc-prog{display:flex;align-items:center;gap:10px;margin:-6px 0 16px;font-size:13px;color:var(--mu)}
.hc-prog i{flex:1;height:6px;border-radius:99px;background:var(--line);overflow:hidden}
.hc-prog i::after{content:'';display:block;height:100%;width:var(--p);background:var(--ac);border-radius:99px}
.hc-arts{list-style:none;margin:0;padding:0}
.hc-arts a{display:flex;align-items:center;gap:14px;padding:12px 0;border-top:1px solid var(--line)}
.hc-arts li:first-child a{border-top:0;padding-top:0}
.hc-arts .i{width:34px;height:34px;border-radius:8px;background:var(--bg);border:1px solid var(--line);display:grid;place-items:center;color:var(--mu)}
.hc-arts .b{flex:1;min-width:0}
.hc-arts b{display:block;font-size:14.5px;font-weight:600}
.hc-arts small{font-size:12.5px;color:var(--mu)}
.hc-arts a:hover b{color:var(--ac)}
.hc-arts .ch{color:var(--line2)}

/* faq */
.hc-faq{display:grid;gap:10px;max-width:900px}
.hc-faq details{background:var(--sf);border:1px solid var(--line);border-radius:12px}
.hc-faq details[open]{border-color:var(--line2)}
.hc-faq summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 20px;font-weight:600;font-size:15px}
.hc-faq summary::-webkit-details-marker{display:none}
.hc-pm{width:26px;height:26px;border-radius:50%;border:1px solid var(--line2);display:grid;place-items:center;color:var(--mu);transition:transform .2s;flex:none}
.hc-faq details[open] .hc-pm{transform:rotate(45deg);border-color:var(--ac);color:var(--ac)}
.hc-faq .ans{padding:0 20px 18px;color:var(--mu);font-size:14.5px;max-width:72ch}
.hc-faq .ans a{display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-weight:600;color:var(--ac)}

/* contact */
.hc-contact{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.hc-cc{padding:22px;display:flex;flex-direction:column;gap:10px;min-width:0}
.hc-cc.pri{border-color:var(--ac);box-shadow:inset 0 0 0 1px var(--ac)}
.hc-cc h3{font-size:16.5px;font-weight:700}
.hc-cc p{margin:0;color:var(--mu);font-size:13.5px}
.hc-val{font:500 14px ui-monospace,monospace;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 8px 8px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto}
.hc-val span{overflow-wrap:anywhere;user-select:all}
.hc-copy{border:0;background:transparent;color:var(--ac);font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer;padding:4px 6px;border-radius:6px;display:inline-flex;align-items:center;gap:5px}
.hc-copy:hover{background:var(--ac-s)}
.hc-hours{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--mu)}
.hc-band{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;padding:26px 28px}
.hc-band h3{font-size:19px;font-weight:800}
.hc-band p{margin:3px 0 0;color:var(--mu)}
.hc-band .btns{display:flex;gap:10px;flex-wrap:wrap}
.hc-foot{text-align:center;font-size:12.5px;color:var(--mu);padding-bottom:8px}
.hc-toast{position:fixed;left:50%;bottom:calc(90px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:var(--ink);color:#fff;font-size:13px;font-weight:600;padding:9px 16px;border-radius:8px;z-index:10050}

@media (max-width:1100px){.hc-cats{grid-template-columns:repeat(2,minmax(0,1fr))}.hc-hero{grid-template-columns:1fr;gap:30px}.hc-desk{grid-template-columns:1fr}}
@media (max-width:820px){.hc-split,.hc-contact{grid-template-columns:1fr}}
@media (max-width:560px){.hc-cats{grid-template-columns:1fr}.hc-g2{grid-template-columns:1fr}.hc-pri{grid-template-columns:1fr}.hc-sbox kbd{display:none}.hc-sbox .hc-btn{display:none}
  .hc-steps li{grid-template-columns:32px 1fr}.hc-dur{grid-column:2;padding-top:0}.hc-meta b{font-size:14.5px}.hc-band .btns,.hc-band .hc-btn{width:100%}
  .hc-compose{flex-direction:column;align-items:stretch}.hc-stream{max-height:60vh}}
@media (prefers-reduced-motion:reduce){.hc *{transition:none!important}}
</style>

<div class="hc">

  <!-- ── Hero: search + latest ticket ── -->
  <section class="hc-hero">
    <div>
      <span class="hc-status<?php echo $deskOpen ? '' : ' off'; ?>"><span class="dot<?php echo $deskOpen ? ' on' : ''; ?>"></span><?php echo $deskOpen ? 'Support desk is open now' : 'Support desk is closed · back ' . $h($deskBack) . ' at 9 AM'; ?></span>
      <h1>How can we help you today?</h1>
      <p class="hc-lead">Step-by-step guides for everything in your plan, and a direct line to the WAAMINI team. Search below, browse by topic, or raise a ticket.</p>
      <div class="hc-search" id="hcSearch">
        <label class="hc-sbox" for="hcQ">
          <?php echo $sx($I['search'], 20); ?>
          <input id="hcQ" type="search" autocomplete="off" placeholder="Search e.g. template rejected, connect number" aria-label="Search help guides" aria-controls="hcResults" aria-expanded="false">
          <kbd>/</kbd>
          <button class="hc-btn hc-btn--pri" type="button" id="hcGo">Search</button>
        </label>
        <div class="hc-results" id="hcResults" role="listbox" hidden></div>
      </div>
      <?php if ($hcPopular): ?>
      <div class="hc-popular">
        <span>Popular:</span>
        <?php foreach (array_slice($hcPopular, 0, 4) as $k): ?><a class="hc-chip" href="manual.php#<?php echo $h($k); ?>"><?php echo $h($hcByKey[$k]['title']); ?></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <aside class="hc-convo" aria-label="<?php echo $hcLatest ? 'Your latest support ticket' : 'How a support conversation looks'; ?>">
      <div class="hc-convo__h">
        <span class="hc-av">W</span>
        <div><b>WAAMINI Support</b><span><?php echo $avgReplyTxt !== '' ? 'Your average first reply: ' . $h($avgReplyTxt) : 'Mon–Sat, 9 AM–7 PM IST'; ?></span></div>
        <?php if ($hcLatest): ?><a class="hc-badge" href="support.php?ticket=<?php echo (int)$hcLatest['id']; ?>#hcTickets">TICKET #TK-<?php echo (int)$hcLatest['id']; ?></a><?php else: ?><span class="hc-badge">EXAMPLE</span><?php endif; ?>
      </div>
      <?php if ($hcLatest): ?>
        <div class="hc-convo__t"><?php echo $h($hcLatest['subject']); ?></div>
        <?php foreach ($hcLatestMsgs as $m): $mine = $m['sender_type'] === 'client'; ?>
        <div class="hc-msg <?php echo $mine ? 'in' : 'out'; ?>"><?php echo $h(mb_strimwidth(preg_replace('/\s+/', ' ', trim($hcMsgText($m))), 0, 170, '…')); ?><time><?php echo $h(date('d M, H:i', strtotime((string)$m['created_at']))); ?></time></div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="hc-msg in">My marketing template got rejected. What should I change?<time>10:42</time></div>
        <div class="hc-msg out">Rejections usually come from a variable at the very start or end of the message, or missing sample values. The Templates guide shows how to fix and resubmit.<time>10:44</time></div>
        <div class="hc-sample">Your tickets with our team will show here.</div>
      <?php endif; ?>
      <div class="hc-meta">
        <div><b><?php echo count($hcGuides); ?></b><span>Guides for your plan</span></div>
        <div><b><?php echo $statOpen; ?> / <?php echo $statTotal; ?></b><span>Open / all tickets</span></div>
        <div><b>3</b><span>Guide languages: EN · தமிழ் · हिंदी</span></div>
      </div>
    </aside>
  </section>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
  <div class="hc-alert hc-alert--ok" role="status"><?php echo $sx($I['check'], 18); ?> Ticket created. Our team has been notified and will reply in this thread.</div>
  <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'closed'): ?>
  <div class="hc-alert hc-alert--ok" role="status"><?php echo $sx($I['check'], 18); ?> Ticket marked as resolved. Reply any time to reopen it.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <div class="hc-alert hc-alert--err" role="alert"><?php echo $sx($I['x'], 18); ?> <?php echo $h($error); ?></div>
  <?php endif; ?>

  <!-- ── My tickets + conversation / new ticket ── -->
  <section id="hcTickets" style="scroll-margin-top:16px">
    <div class="hc-head">
      <div><div class="hc-eyebrow">Your help desk</div><h2>My support tickets</h2><p>Every conversation with our team is saved here.</p></div>
      <?php if ($activeTicketId > 0): ?><a class="hc-btn hc-btn--pri" href="support.php#hcNew"><?php echo $sx($I['plus'], 15); ?> New ticket</a><?php endif; ?>
    </div>
    <div class="hc-desk">
      <div class="hc-card hc-tk">
        <div class="hc-tk__hd">
          <h3><?php echo $sx($I['ticket'], 17); ?> Tickets</h3>
          <div class="hc-tk__stats"><span><b><?php echo $statOpen; ?></b>open</span><span><b><?php echo $statClosed; ?></b>resolved</span><span><b><?php echo $avgReplyTxt !== '' ? $h($avgReplyTxt) : '—'; ?></b>avg. first reply</span></div>
          <?php if ($statTotal > 0): ?>
          <div class="hc-seg" role="tablist" aria-label="Filter tickets">
            <button type="button" class="on" data-f="all" role="tab" aria-selected="true">All (<?php echo $statTotal; ?>)</button>
            <button type="button" data-f="open" role="tab" aria-selected="false">Open (<?php echo $statOpen; ?>)</button>
            <button type="button" data-f="closed" role="tab" aria-selected="false">Resolved (<?php echo $statClosed; ?>)</button>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($statTotal > 0): ?>
        <div class="hc-list" id="hcList">
          <?php foreach ($ticketRows as $t):
            $isOpen = (($t['status'] ?? '') === 'open');
            $preview = trim($hcMsgText(['message' => (string)($t['last_msg'] ?? ''), 'sender_type' => (string)($t['last_sender'] ?? '')]));
            $preview = $preview === '' ? 'No messages yet.' : preg_replace('/\s+/', ' ', $preview);
            $when = !empty($t['last_at']) ? (string)$t['last_at'] : (string)$t['created_at'];
            $cat = $supportCategories[(string)($t['category'] ?? '')] ?? '';
            $pri = (string)($t['priority'] ?? 'normal');
            $firstReply = !empty($t['first_admin_reply']) ? max(0, strtotime((string)$t['first_admin_reply']) - strtotime((string)$t['created_at'])) : null;
            $waiting = $isOpen && ($t['last_sender'] ?? '') === 'client';
          ?>
          <a href="support.php?ticket=<?php echo (int)$t['id']; ?>#hcTickets" class="hc-row<?php echo $activeTicketId === (int)$t['id'] ? ' on' : ''; ?>" data-status="<?php echo $isOpen ? 'open' : 'closed'; ?>">
            <div class="hc-row__m">
              <span class="id">#TK-<?php echo (int)$t['id']; ?></span>
              <span class="hc-st <?php echo $isOpen ? 'hc-st--open' : 'hc-st--closed'; ?>"><?php echo $isOpen ? 'Open' : 'Resolved'; ?></span>
              <?php if ($pri !== 'normal' && isset($supportPriorities[$pri])): ?><span class="hc-pr hc-pr--<?php echo $h($pri); ?>"><?php echo $h($supportPriorities[$pri][0]); ?></span><?php endif; ?>
              <?php if ($cat !== ''): ?><span class="hc-tag"><?php echo $h($cat); ?></span><?php endif; ?>
              <time><?php echo $h(date('d M, g:i A', strtotime($when))); ?></time>
            </div>
            <h4><?php echo $h($t['subject']); ?></h4>
            <p><?php echo $h(mb_strimwidth($preview, 0, 160, '…')); ?></p>
            <div class="hc-row__f">
              <span><?php echo (int)$t['msg_count']; ?> message<?php echo (int)$t['msg_count'] === 1 ? '' : 's'; ?></span>
              <?php if ($waiting): ?><b class="wait">Waiting for our reply</b><?php elseif ($isOpen && ($t['last_sender'] ?? '') === 'admin'): ?><b>We replied — your turn</b><?php elseif ($firstReply !== null): ?><span>First reply in <?php echo $h($fmtDur($firstReply)); ?></span><?php endif; ?>
            </div>
          </a>
          <?php endforeach; ?>
          <div class="hc-none" id="hcNone">No tickets here.</div>
        </div>
        <?php else: ?>
        <div class="hc-empty"><span class="i"><?php echo $sx($I['ticket'], 24); ?></span><b>No tickets yet</b><p>When you raise a ticket, every reply from our team is collected here.</p></div>
        <?php endif; ?>
      </div>

      <?php
      $ticketNotFound = false; $ticket = null; $msgs = [];
      if ($activeTicketId > 0) {
          $stSup4 = $conn->prepare('SELECT * FROM support_tickets WHERE id=? AND company_id=? LIMIT 1');
          if ($stSup4) { $stSup4->bind_param('ii', $activeTicketId, $companyId); if ($stSup4->execute()) { $ticket = $stSup4->get_result()->fetch_assoc(); } $stSup4->close(); }
          if (is_array($ticket)) {
              $stSup5 = $conn->prepare("SELECT m.*, u.name as admin_name FROM support_messages m LEFT JOIN users u ON m.sender_id = u.id AND m.sender_type = 'admin' WHERE m.ticket_id=? ORDER BY m.id ASC");
              if ($stSup5) { $stSup5->bind_param('i', $activeTicketId); if ($stSup5->execute()) { $rs = $stSup5->get_result(); while ($x = $rs->fetch_assoc()) { $msgs[] = $x; } } $stSup5->close(); }
          } else { $ticketNotFound = true; }
      }
      ?>

      <?php if (is_array($ticket)):
        $tOpen = ($ticket['status'] === 'open');
        $tCat = $supportCategories[(string)($ticket['category'] ?? '')] ?? '';
        $tPri = (string)($ticket['priority'] ?? 'normal'); ?>
      <div class="hc-card hc-thread" aria-labelledby="hcThreadTitle">
        <div class="hc-thread__hd">
          <div>
            <h3 id="hcThreadTitle"><?php echo $h($ticket['subject']); ?></h3>
            <div class="hc-row__m">
              <span class="id">#TK-<?php echo (int)$ticket['id']; ?></span>
              <span class="hc-st <?php echo $tOpen ? 'hc-st--open' : 'hc-st--closed'; ?>"><?php echo $tOpen ? 'Open' : 'Resolved'; ?></span>
              <?php if ($tPri !== 'normal' && isset($supportPriorities[$tPri])): ?><span class="hc-pr hc-pr--<?php echo $h($tPri); ?>"><?php echo $h($supportPriorities[$tPri][0]); ?></span><?php endif; ?>
              <?php if ($tCat !== ''): ?><span class="hc-tag"><?php echo $h($tCat); ?></span><?php endif; ?>
              <span>Opened <?php echo $h(date('d M Y, g:i A', strtotime((string)$ticket['created_at']))); ?></span>
            </div>
          </div>
          <div class="hc-thread__acts">
            <?php if ($tOpen): ?>
            <form method="POST" action="support.php?ticket=<?php echo (int)$ticket['id']; ?>">
              <?php echo csrf_field(); ?>
              <button type="submit" name="close_ticket" class="hc-btn hc-btn--ghost"><?php echo $sx($I['check'], 15); ?> Mark as resolved</button>
            </form>
            <?php endif; ?>
            <a class="hc-btn hc-btn--ghost" href="support.php#hcTickets" aria-label="Close conversation"><?php echo $sx($I['x'], 15); ?></a>
          </div>
        </div>
        <div class="hc-stream" id="hcStream">
          <?php foreach ($msgs as $m): $isClient = ($m['sender_type'] === 'client'); ?>
          <div class="hc-bub <?php echo $isClient ? 'me' : 'them'; ?>">
            <small><?php echo $isClient ? 'You' : 'WAAMINI Support' . (!empty($m['admin_name']) ? ' · ' . $h($m['admin_name']) : ''); ?></small>
            <div><?php echo nl2br($h($hcMsgText($m))); ?></div>
            <time><?php echo $h(date('d M, g:i A', strtotime((string)$m['created_at']))); ?></time>
          </div>
          <?php endforeach; if (!$msgs): ?>
          <div class="hc-empty"><span class="i"><?php echo $sx($I['clock'], 24); ?></span><b>In the queue</b><p>Our team will reply here shortly.</p></div>
          <?php endif; ?>
        </div>
        <?php if (!$tOpen): ?><p class="hc-closed-note">This ticket is resolved. Sending a reply reopens it.</p><?php endif; ?>
        <form method="POST" action="support.php?ticket=<?php echo (int)$activeTicketId; ?>" class="hc-compose">
          <?php echo csrf_field(); ?>
          <textarea name="message" rows="2" placeholder="<?php echo $tOpen ? 'Write a reply… (Ctrl+Enter to send)' : 'Reply to reopen this ticket…'; ?>" required maxlength="65535" aria-label="Your reply"></textarea>
          <button type="submit" name="send_reply" class="hc-btn hc-btn--pri"><?php echo $sx($I['send'], 15); ?> Send</button>
        </form>
      </div>
      <?php else: ?>
      <div class="hc-card hc-form" id="hcNew" style="scroll-margin-top:16px">
        <?php if ($ticketNotFound): ?><div class="hc-alert hc-alert--err" role="alert" style="margin-bottom:14px"><?php echo $sx($I['x'], 18); ?> That ticket was not found. You can open a new one below.</div><?php endif; ?>
        <h3>Raise a support ticket</h3>
        <p>Your message goes straight to the WAAMINI team. Replies appear in this page.</p>
        <form method="POST" action="support.php">
          <?php echo csrf_field(); ?>
          <div class="hc-f">
            <label for="hcCat">Category <i>*</i></label>
            <select id="hcCat" name="category" required>
              <?php foreach ($supportCategories as $k => $label): ?><option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
            </select>
          </div>
          <fieldset class="hc-f" style="border:0;padding:0;margin:0 0 14px">
            <legend style="font-size:13px;font-weight:600;margin-bottom:6px;padding:0">Urgency <i style="color:var(--ro);font-style:normal">*</i></legend>
            <div class="hc-pri">
              <?php $first = true; foreach ($supportPriorities as $k => [$label, $hint]): ?>
              <label><input type="radio" name="priority" value="<?php echo $h($k); ?>"<?php echo $first ? ' checked' : ''; ?>><?php echo $h($label); ?><span><?php echo $h($hint); ?></span></label>
              <?php $first = false; endforeach; ?>
            </div>
          </fieldset>
          <div class="hc-f">
            <label for="hcSubj">Subject <i>*</i></label>
            <input type="text" id="hcSubj" name="subject" placeholder="e.g. Need help getting my festival template approved" required maxlength="255">
          </div>
          <div class="hc-f">
            <label for="hcMsg">Details <i>*</i></label>
            <textarea id="hcMsg" name="message" rows="5" placeholder="Describe what you need. Include the campaign or template name, a sample phone number or the invoice number if it helps." required maxlength="65535"></textarea>
            <small>Tip: check the guides above first — most answers are already there.</small>
          </div>
          <button type="submit" name="create_ticket" class="hc-btn hc-btn--pri hc-btn--block" style="height:48px"><?php echo $sx($I['send'], 16); ?> Submit ticket</button>
          <div class="hc-advice"><?php echo $sx($I['info'], 16); ?><span><b>Messages not going out right now?</b> WhatsApp us on <a href="https://wa.me/919150802906?text=<?php echo $waText; ?>" target="_blank" rel="noopener noreferrer">+91 91508 02906</a> for the quickest help during support hours.</span></div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Browse by topic ── -->
  <?php if ($hcByCat): ?>
  <section>
    <div class="hc-head">
      <div><div class="hc-eyebrow">Browse by topic</div><h2>Need help? We've got your back</h2><p>Guides for the features in your plan, in English, தமிழ் and हिंदी.</p></div>
      <a class="hc-link" href="manual.php">Open the user guide <?php echo $sx($I['arrow'], 14); ?></a>
    </div>
    <div class="hc-cats">
      <?php foreach ($hcCats as $ck => [$cName, $cIcon]): if (empty($hcByCat[$ck])) continue; ?>
      <div class="hc-cat">
        <div class="hc-cat__top"><span class="hc-ico <?php echo $hcTone[$ck] ?? 't-green'; ?>"><?php echo $sx($cIcon, 22); ?></span><span class="hc-count"><?php echo count($hcByCat[$ck]); ?> GUIDE<?php echo count($hcByCat[$ck]) === 1 ? '' : 'S'; ?></span></div>
        <h3><a href="manual.php#<?php echo $h($hcByCat[$ck][0]['key']); ?>"><?php echo $h($cName); ?></a></h3>
        <p><?php echo $h($hcDesc[$ck] ?? ''); ?></p>
        <ul><?php foreach (array_slice($hcByCat[$ck], 0, 3) as $g): ?><li><a href="manual.php#<?php echo $h($g['key']); ?>"><?php echo $sx($I['doc'], 13); ?><?php echo $h($g['title']); ?></a></li><?php endforeach; ?></ul>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── Go-live checklist + popular guides ── -->
  <section class="hc-split">
    <?php if ($hcCheck): ?>
    <div class="hc-card hc-panel">
      <div class="hc-eyebrow">Your account</div>
      <h3>Go-live checklist</h3>
      <p>Follow these in order to send your first WhatsApp campaign.</p>
      <?php if ($hcTotal > 0): ?><div class="hc-prog" style="--p:<?php echo (int)round($hcDone / $hcTotal * 100); ?>%"><i></i><span><?php echo $hcDone; ?> of <?php echo $hcTotal; ?> done</span></div><?php endif; ?>
      <ol class="hc-steps">
        <?php foreach ($hcCheck as $n => [$t, $d, $dur, $ok, $page, $gk]): ?>
        <li>
          <span class="hc-num<?php echo $ok === true ? ' done' : ''; ?>"><?php echo $ok === true ? $sx('<path d="M20 6 9 17l-5-5"/>', 15) : $n + 1; ?></span>
          <a href="<?php echo isset($hcByKey[$gk]) ? 'manual.php#' . $h($gk) : $h($page); ?>"><b><?php echo $h($t); ?></b><span class="d"><?php echo $h($d); ?></span></a>
          <span class="hc-dur<?php echo $ok === true ? ' done' : ''; ?>"><?php echo $ok === true ? 'Done' : $h($dur); ?></span>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
    <?php if ($hcPopular): ?>
    <div class="hc-card hc-panel">
      <div class="hc-eyebrow">Start here</div>
      <h3>Popular guides</h3>
      <p>The guides customers open most often.</p>
      <ul class="hc-arts">
        <?php foreach (array_slice($hcPopular, 0, 6) as $k): $g = $hcByKey[$k]; ?>
        <li><a href="manual.php#<?php echo $h($k); ?>"><span class="i"><?php echo $sx($g['icon'], 16); ?></span><span class="b"><b><?php echo $h($g['title']); ?></b><small><?php echo $h($hcCats[$g['cat']][0] ?? ''); ?> · <?php echo count($g['steps']); ?> steps</small></span><span class="ch"><?php echo $sx($I['chev'], 16); ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── FAQ ── -->
  <section>
    <div class="hc-head"><div><div class="hc-eyebrow">Quick answers</div><h2>Frequently asked questions</h2></div></div>
    <div class="hc-faq">
      <?php foreach ($faqs as $i => [$q, $a, $href, $cta]): ?>
      <details<?php echo $i === 0 ? ' open' : ''; ?>>
        <summary><?php echo $h($q); ?><span class="hc-pm"><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span></summary>
        <div class="ans"><?php echo $h($a); ?><br><a href="<?php echo $h($href); ?>"><?php echo $h($cta); ?> <?php echo $sx($I['arrow'], 13); ?></a></div>
      </details>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── Contact ── -->
  <section id="hcContact">
    <div class="hc-head"><div><div class="hc-eyebrow">Still stuck?</div><h2>Talk to our support team</h2><p>Real people from our Karaikudi team, in English and Tamil.</p></div></div>
    <div class="hc-contact">
      <div class="hc-card hc-cc pri">
        <span class="hc-ico t-green"><?php echo $sx($I['wa'], 22); ?></span>
        <h3>WhatsApp support</h3>
        <p>Fastest option. Share screenshots and get a reply in minutes during support hours.</p>
        <div class="hc-val"><span>+91 91508 02906</span><button class="hc-copy" type="button" data-copy="+91 91508 02906"><?php echo $sx($I['copy'], 13); ?> Copy</button></div>
        <a class="hc-btn hc-btn--pri" href="https://wa.me/919150802906?text=<?php echo $waText; ?>" target="_blank" rel="noopener noreferrer"><?php echo $sx($I['wa'], 16); ?> Chat on WhatsApp</a>
        <div class="hc-hours"><span class="dot<?php echo $deskOpen ? ' on' : ''; ?>"></span>Mon to Sat, 9 AM to 7 PM IST</div>
      </div>
      <div class="hc-card hc-cc">
        <span class="hc-ico t-blue"><?php echo $sx($I['mail'], 22); ?></span>
        <h3>Email us</h3>
        <p>For billing, invoices, account changes and detailed technical issues.</p>
        <div class="hc-val"><span>info@7digit.in</span><button class="hc-copy" type="button" data-copy="info@7digit.in"><?php echo $sx($I['copy'], 13); ?> Copy</button></div>
        <a class="hc-btn hc-btn--ghost" href="mailto:info@7digit.in"><?php echo $sx($I['send'], 15); ?> Write an email</a>
        <div class="hc-hours">Reply within 1 business day</div>
      </div>
      <div class="hc-card hc-cc">
        <span class="hc-ico t-violet"><?php echo $sx($I['cal'], 22); ?></span>
        <h3>Book an onboarding call</h3>
        <p>A screen-share with our team to set up your account with you.</p>
        <a class="hc-btn hc-btn--ghost" style="margin-top:auto" href="https://wa.me/919150802906?text=<?php echo rawurlencode('Hi WAAMINI Support, I would like to book an onboarding call.'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $sx($I['cal'], 15); ?> Request a time slot</a>
        <div class="hc-hours">We confirm the time on WhatsApp</div>
      </div>
    </div>
  </section>

  <?php if ($hcOn('api_webhook') || $hcOn('api_webhook_access')): ?>
  <div class="hc-card hc-band">
    <div><h3>Developer working on an integration?</h3><p>Your API key, webhooks and code examples are in the API &amp; Webhook hub.</p></div>
    <div class="btns">
      <a class="hc-btn hc-btn--ghost" href="<?php echo $hcOn('api_webhook') ? 'settings_api.php' : 'premium_zapier.php'; ?>"><?php echo $sx($I['code'], 15); ?> Open API &amp; webhooks</a>
      <a class="hc-btn hc-btn--pri" href="dashboard.php">Go to dashboard <?php echo $sx($I['arrow'], 14); ?></a>
    </div>
  </div>
  <?php endif; ?>

  <div class="hc-foot">WAAMINI Help Desk · Powered by <b>7digit.in</b></div>
</div>
<div class="hc-toast" id="hcToast" role="status" hidden></div>

<script nonce="<?php echo $h($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var G = <?php echo json_encode($hcSearch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

  // conversation: start at the newest message; Ctrl+Enter sends
  var s = $('hcStream'); if (s) s.scrollTop = s.scrollHeight;
  var ta = document.querySelector('.hc-compose textarea');
  if (ta) ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); this.form.querySelector('button[name="send_reply"]').click(); } });

  // ticket filter
  var seg = document.querySelector('.hc-seg');
  if (seg) seg.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-f]'); if (!b) return;
    var f = b.getAttribute('data-f'), n = 0;
    seg.querySelectorAll('button').forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.querySelectorAll('#hcList .hc-row').forEach(function (r) { var ok = f === 'all' || r.getAttribute('data-status') === f; r.style.display = ok ? '' : 'none'; if (ok) n++; });
    var none = $('hcNone'); if (none) none.style.display = n ? 'none' : 'block';
  });

  // guide search
  var q = $('hcQ'), res = $('hcResults'), box = $('hcSearch'), act = -1;
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  function run() {
    var v = q.value.trim().toLowerCase(); act = -1;
    if (!v) { res.hidden = true; q.setAttribute('aria-expanded', 'false'); return; }
    var w = v.split(/\s+/);
    var hits = G.filter(function (g) { return w.every(function (x) { return (g.q + ' ' + g.c.toLowerCase()).indexOf(x) !== -1; }); }).slice(0, 7);
    res.innerHTML = hits.length ? hits.map(function (g) {
      var t = esc(g.t);
      w.forEach(function (x) { if (x.length > 1) t = t.replace(new RegExp('(' + x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); });
      return '<a href="manual.php#' + encodeURIComponent(g.k) + '" role="option"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg><span><b>' + t + '</b><small>' + esc(g.c) + '</small></span></a>';
    }).join('') : '<div class="empty">No guides match “' + esc(q.value) + '”. Try fewer words, or <a href="#hcNew" style="color:var(--ac);font-weight:600">raise a ticket</a>.</div>';
    res.hidden = false; q.setAttribute('aria-expanded', 'true');
  }
  if (q) {
    q.addEventListener('input', run);
    $('hcGo').addEventListener('click', function () { run(); q.focus(); });
    q.addEventListener('keydown', function (e) {
      var items = res.querySelectorAll('a[role="option"]');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!items.length) return; e.preventDefault();
        act = (act + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach(function (a, i) { a.classList.toggle('on', i === act); });
      } else if (e.key === 'Enter') {
        e.preventDefault(); if (items.length) location.href = items[Math.max(0, act)].getAttribute('href');
      } else if (e.key === 'Escape') { res.hidden = true; }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) { res.hidden = true; q.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && document.activeElement !== q && !/input|textarea|select/i.test((document.activeElement || {}).tagName || '')) { e.preventDefault(); q.focus(); }
    });
  }

  // copy buttons
  var toast = $('hcToast'), tt;
  function show(m) { toast.textContent = m; toast.hidden = false; clearTimeout(tt); tt = setTimeout(function () { toast.hidden = true; }, 1800); }
  document.querySelectorAll('.hc-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-copy');
      var sel = function () { var r = document.createRange(); r.selectNodeContents(b.previousElementSibling); var s = getSelection(); s.removeAllRanges(); s.addRange(r); show('Selected — press Ctrl+C to copy'); };
      try { navigator.clipboard.writeText(v).then(function () { show('Copied ' + v); }, sel); } catch (e) { sel(); }
    });
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
