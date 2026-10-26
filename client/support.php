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
$faqs = [
    ['Why was my WhatsApp template rejected?', 'Meta rejects templates that look promotional but are filed as Utility, that use variables without sample values, or that start or end with a variable. Edit the wording or category under Templates and submit again.', 'templates.php', 'Open Templates'],
    ['How do I connect or reconnect my WhatsApp number?', 'Go to Settings → WhatsApp Setup and follow the steps there. If messages stopped suddenly, check that the number is still connected and has a valid template for the message type.', 'settings_whatsapp.php', 'Open WhatsApp Setup'],
    ['Where can I see if my messages were delivered?', 'Every campaign shows sent, delivered, read and failed counts. Open the campaign from Campaigns to see each contact\'s status and the reason for any failure.', 'campaigns.php', 'Open Campaigns'],
    ['How do I renew or upgrade my plan?', 'Open My Subscription to see your plan, its end date and the upgrade options. Payments and invoices are listed under Billing & Wallet.', 'subscription.php', 'Open My Subscription'],
];
?>
<style>
/* ═══ Help & Support (client) · professional help-desk layout · all numbers are live ═══ */
.sx{--g9:#064e3b;--g8:#065f46;--g7:#047857;--g6:#059669;--g5:#10b981;--g1:#d1fae5;--g0:#ecfdf5;
  --ink:#0f172a;--tx:#334155;--mu:#64748b;--li:#94a3b8;--bd:#e2e8f0;--bd2:#eef2f6;--sf:#f8fafc;
  max-width:1240px;margin:0 auto;display:grid;gap:20px;color:var(--ink);font-size:14px}
.sx *{box-sizing:border-box}
.sx a{text-decoration:none}
.sx svg{flex:none}
.sx-card{background:#fff;border:1px solid var(--bd);border-radius:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}

/* hero */
.sx-banner{position:relative;overflow:hidden;border-radius:20px;padding:30px 32px;color:#fff;
  background:linear-gradient(110deg,var(--g9) 0%,var(--g8) 55%,#063f33 100%);box-shadow:0 18px 40px -22px rgba(6,78,59,.55)}
.sx-banner::before,.sx-banner::after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(40px)}
.sx-banner::before{right:-60px;bottom:-80px;width:300px;height:300px;background:rgba(16,185,129,.28)}
.sx-banner::after{right:240px;top:-60px;width:200px;height:200px;background:rgba(45,212,191,.14)}
.sx-banner__in{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}
.sx-banner__txt{max-width:640px}
.sx-banner__tags{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:10px}
.sx-tag{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;font-size:10.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);color:#a7f3d0}
.sx-desk{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:rgba(209,250,229,.9)}
.sx-desk i{width:8px;height:8px;border-radius:50%;background:#94a3b8}
.sx-desk.is-on i{background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.6);animation:sxPing 2s infinite}
@keyframes sxPing{70%{box-shadow:0 0 0 7px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.sx-banner h1{margin:0 0 8px;font-size:clamp(22px,2.6vw,30px);font-weight:800;letter-spacing:-.02em;line-height:1.2;color:#fff}
.sx-banner p{margin:0;font-size:14px;line-height:1.65;color:rgba(209,250,229,.82)}
.sx-banner__btns{display:flex;flex-wrap:wrap;gap:10px}
.sx-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:12px;font-size:13px;font-weight:700;line-height:1;border:1px solid transparent;cursor:pointer;transition:transform .15s,background .2s,box-shadow .2s;white-space:nowrap}
.sx-btn:active{transform:scale(.97)}
.sx-btn--wa{background:#10b981;color:#022c22;box-shadow:0 8px 18px -10px rgba(16,185,129,.9)}
.sx-btn--wa:hover{background:#34d399}
.sx-btn--glass{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.22);color:#fff}
.sx-btn--glass:hover{background:rgba(255,255,255,.18)}
.sx-btn--primary{background:var(--g8);color:#fff;box-shadow:0 8px 18px -12px rgba(6,95,70,.8)}
.sx-btn--primary:hover{background:var(--g9)}
.sx-btn--dark{background:var(--ink);color:#fff}
.sx-btn--dark:hover{background:#1e293b}
.sx-btn--soft{background:var(--sf);border-color:var(--bd);color:var(--tx)}
.sx-btn--soft:hover{background:#f1f5f9}
.sx-btn--block{width:100%}

/* metrics */
.sx-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.sx-kpi{padding:16px 18px}
.sx-kpi__top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;font-size:12.5px;font-weight:600;color:var(--mu)}
.sx-kpi__ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;border:1px solid}
.sx-ic-slate{background:var(--sf);border-color:var(--bd2);color:#475569}
.sx-ic-green{background:var(--g0);border-color:var(--g1);color:var(--g6)}
.sx-ic-teal{background:#f0fdfa;border-color:#ccfbf1;color:#0d9488}
.sx-ic-indigo{background:#eef2ff;border-color:#e0e7ff;color:#4f46e5}
.sx-kpi__val{display:flex;align-items:baseline;flex-wrap:wrap;gap:8px}
.sx-kpi__val b{font-size:26px;font-weight:800;letter-spacing:-.02em;line-height:1}
.sx-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;background:var(--sf);color:var(--mu)}
.sx-chip--green{background:var(--g0);color:var(--g7)}
.sx-chip--amber{background:#fffbeb;color:#b45309}
.sx-chip--red{background:#fef2f2;color:#b91c1c}
.sx-chip--teal{background:#f0fdfa;color:#0f766e}
.sx-chip--indigo{background:#eef2ff;color:#4338ca}
.sx-kpi p{margin:6px 0 0;font-size:11.5px;color:var(--li)}

/* channels */
.sx-chans{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.sx-chan{padding:22px;display:flex;flex-direction:column;gap:16px;transition:border-color .2s,box-shadow .2s}
.sx-chan:hover{border-color:#cbd5e1;box-shadow:0 14px 30px -24px rgba(15,23,42,.45)}
.sx-chan--wa{border:2px solid rgba(16,185,129,.35)}
.sx-chan--wa:hover{border-color:rgba(5,150,105,.6)}
.sx-chan__top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.sx-chan__ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;border:1px solid;transition:transform .2s}
.sx-chan:hover .sx-chan__ic{transform:scale(1.05)}
.sx-ic-blue{background:#eff6ff;border-color:#dbeafe;color:#2563eb}
.sx-ic-amber{background:#fffbeb;border-color:#fef3c7;color:#d97706}
.sx-pill{padding:4px 10px;border-radius:99px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;background:#f1f5f9;color:#475569}
.sx-pill--green{background:var(--g1);color:var(--g8)}
.sx-pill--amber{background:#fef3c7;color:#92400e}
.sx-chan h3{margin:0 0 6px;font-size:15.5px;font-weight:800}
.sx-chan p{margin:0;font-size:13px;line-height:1.6;color:var(--mu)}
.sx-facts{display:grid;gap:7px;padding:12px 14px;border-radius:12px;border:1px solid var(--bd2);background:var(--sf);font-size:12.5px}
.sx-facts--green{background:rgba(236,253,245,.6);border-color:var(--g1)}
.sx-facts--amber{background:rgba(255,251,235,.6);border-color:#fef3c7}
.sx-facts div{display:flex;justify-content:space-between;gap:10px}
.sx-facts span{color:var(--li)}
.sx-facts b{font-weight:700;color:var(--ink);text-align:right}
.sx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:700}
.sx-chan .sx-btn{margin-top:auto}

/* alerts */
.sx-alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;font-size:13.5px;font-weight:600}
.sx-alert--ok{background:var(--g0);border:1px solid var(--g1);color:var(--g8)}
.sx-alert--err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}

/* workspace */
.sx-work{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:20px;align-items:start}
.sx-tickets{overflow:hidden;display:flex;flex-direction:column}
.sx-tickets__head{padding:18px 20px 14px;border-bottom:1px solid var(--bd2)}
.sx-h2{display:flex;align-items:center;gap:8px;margin:0;font-size:15px;font-weight:800}
.sx-h2 svg{color:var(--g7)}
.sx-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.sx-count{font-size:12px;font-weight:600;padding:3px 10px;border-radius:99px;background:#f1f5f9;color:#475569}
.sx-seg{display:flex;gap:4px;padding:4px;margin-top:12px;border-radius:12px;background:#f1f5f9}
.sx-seg button{flex:1;padding:7px 6px;border:0;border-radius:9px;background:transparent;font:inherit;font-size:12.5px;font-weight:700;color:var(--mu);cursor:pointer;transition:background .2s,color .2s}
.sx-seg button:hover{color:var(--ink)}
.sx-seg button.is-on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.1)}
.sx-list{max-height:560px;overflow-y:auto}
.sx-tk{display:block;padding:15px 20px;border-bottom:1px solid var(--bd2);color:inherit;transition:background .15s;position:relative}
.sx-tk:hover{background:#f8fafc}
.sx-tk.is-active{background:var(--g0)}
.sx-tk.is-active::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:3px;border-radius:0 3px 3px 0;background:var(--g6)}
.sx-tk__meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:6px}
.sx-tk__id{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;font-weight:700;color:var(--li)}
.sx-st{padding:2px 8px;border-radius:6px;font-size:10.5px;font-weight:700;border:1px solid}
.sx-st--open{background:#fffbeb;border-color:#fde68a;color:#b45309}
.sx-st--closed{background:var(--g0);border-color:var(--g1);color:var(--g7)}
.sx-cat{font-size:11px;font-weight:600;color:var(--li)}
.sx-tk__date{margin-left:auto;font-size:11px;color:var(--li);white-space:nowrap}
.sx-tk h4{margin:0 0 3px;font-size:13.5px;font-weight:700;line-height:1.35;transition:color .15s}
.sx-tk:hover h4{color:var(--g7)}
.sx-tk__pre{margin:0;font-size:12.5px;color:var(--mu);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sx-tk__foot{display:flex;justify-content:space-between;gap:10px;margin-top:8px;font-size:11.5px;color:var(--li)}
.sx-tk__foot b{font-weight:700;color:var(--g7)}
.sx-tk__foot b.is-wait{color:#b45309}
.sx-list__empty{display:none;padding:28px 20px;text-align:center;font-size:13px;color:var(--li)}
.sx-tickets__foot{display:flex;align-items:center;gap:8px;padding:13px 20px;background:var(--sf);border-top:1px solid var(--bd2);font-size:12px;color:var(--mu)}
.sx-tickets__foot svg{color:var(--g6)}
.sx-empty{padding:40px 24px;text-align:center}
.sx-empty__ic{width:54px;height:54px;margin:0 auto 12px;border-radius:16px;display:grid;place-items:center;background:var(--g0);color:var(--g6)}
.sx-empty h3{margin:0 0 6px;font-size:15px;font-weight:800}
.sx-empty p{margin:0;font-size:13px;color:var(--mu)}

/* form */
.sx-form{padding:24px 26px}
.sx-form__head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding-bottom:16px;margin-bottom:18px;border-bottom:1px solid var(--bd2)}
.sx-form__head h2{margin:0;font-size:16px;font-weight:800}
.sx-form__head p{margin:4px 0 0;font-size:12.5px;color:var(--mu)}
.sx-form__badge{flex:none;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:700;background:var(--g0);color:var(--g8);border:1px solid var(--g1)}
.sx-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sx-f{display:grid;gap:6px;margin-bottom:14px}
.sx-f label{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#475569}
.sx-f label i{font-style:normal;color:#f43f5e}
.sx-f input,.sx-f select,.sx-f textarea{width:100%;padding:11px 13px;border:1px solid var(--bd);border-radius:12px;background:rgba(248,250,252,.6);font:inherit;font-size:13.5px;color:var(--ink);transition:border-color .2s,box-shadow .2s,background .2s}
.sx-f textarea{resize:vertical;min-height:130px;line-height:1.55}
.sx-f input:focus,.sx-f select:focus,.sx-f textarea:focus{outline:none;border-color:var(--g6);background:#fff;box-shadow:0 0 0 3px rgba(5,150,105,.14)}
.sx-f small{font-size:11.5px;color:var(--li)}
.sx-advice{display:flex;align-items:flex-start;gap:10px;margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--sf);border:1px solid var(--bd);font-size:12.5px;line-height:1.55;color:var(--tx)}
.sx-advice svg{color:var(--g6);margin-top:1px}
.sx-advice a{font-weight:700;color:var(--g7)}
.sx-advice a:hover{text-decoration:underline}

/* conversation */
.sx-convo{display:flex;flex-direction:column;min-height:560px}
.sx-convo__head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:18px 22px;border-bottom:1px solid var(--bd2)}
.sx-convo__head h2{margin:0 0 6px;font-size:16px;font-weight:800;line-height:1.35}
.sx-convo__meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:12px;color:var(--mu)}
.sx-stream{flex:1;max-height:470px;overflow-y:auto;padding:20px 22px;display:grid;gap:14px;align-content:start;background:linear-gradient(180deg,#fbfdfc,#fff)}
.sx-msg{max-width:82%;display:grid;gap:4px}
.sx-msg--me{justify-self:end;text-align:right}
.sx-msg__who{font-size:11.5px;font-weight:700;color:var(--mu)}
.sx-msg__bub{padding:11px 14px;border-radius:14px;font-size:13.5px;line-height:1.55;text-align:left;white-space:normal;word-wrap:break-word}
.sx-msg--them .sx-msg__bub{background:#f1f5f9;color:var(--ink);border-top-left-radius:4px}
.sx-msg--me .sx-msg__bub{background:var(--g8);color:#fff;border-top-right-radius:4px}
.sx-msg__time{font-size:11px;color:var(--li)}
.sx-composer{display:flex;align-items:flex-end;gap:10px;padding:14px 18px;border-top:1px solid var(--bd2)}
.sx-composer textarea{flex:1;min-height:48px;max-height:180px;padding:12px 14px;border:1px solid var(--bd);border-radius:12px;background:rgba(248,250,252,.6);font:inherit;font-size:13.5px;resize:vertical}
.sx-composer textarea:focus{outline:none;border-color:var(--g6);background:#fff;box-shadow:0 0 0 3px rgba(5,150,105,.14)}

/* faq */
.sx-faq{padding:22px 26px}
.sx-faq__head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;padding-bottom:14px;margin-bottom:16px;border-bottom:1px solid var(--bd2)}
.sx-faq__head h3{margin:0;font-size:15px;font-weight:800}
.sx-faq__head p{margin:3px 0 0;font-size:12.5px;color:var(--mu)}
.sx-faq__head a{font-size:12.5px;font-weight:700;color:var(--g7)}
.sx-faq__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.sx-q{padding:15px 16px;border-radius:14px;background:var(--sf);border:1px solid var(--bd2)}
.sx-q h4{display:flex;align-items:flex-start;gap:8px;margin:0 0 8px;font-size:13.5px;font-weight:700;line-height:1.4}
.sx-q h4 svg{color:var(--g6);margin-top:2px}
.sx-q p{margin:0 0 10px;font-size:12.5px;line-height:1.6;color:var(--mu)}
.sx-q a{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:var(--g7)}
.sx-q a:hover{text-decoration:underline}
.sx-foot{padding:4px 0 8px;text-align:center;font-size:12px;color:var(--li)}
.sx-foot b{color:#475569}

@media (max-width:1100px){.sx-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.sx-chans{grid-template-columns:1fr}.sx-work{grid-template-columns:1fr}}
@media (max-width:640px){
  .sx-banner{padding:24px 20px}
  .sx-banner__btns,.sx-banner__btns .sx-btn{width:100%}
  .sx-kpis{gap:10px}.sx-kpi{padding:14px}.sx-kpi__val b{font-size:22px}
  .sx-grid2,.sx-faq__grid{grid-template-columns:1fr}
  .sx-form,.sx-faq{padding:20px 18px}
  .sx-msg{max-width:92%}
  .sx-composer{flex-direction:column;align-items:stretch}
}
@media (prefers-reduced-motion:reduce){.sx-desk.is-on i{animation:none}}
</style>

<div class="sx">

  <!-- ── Hero ── -->
  <section class="sx-banner" aria-labelledby="sxTitle">
    <div class="sx-banner__in">
      <div class="sx-banner__txt">
        <div class="sx-banner__tags">
          <span class="sx-tag"><?php echo $sx($I['shield'], 12); ?> WAAMINI Support Desk</span>
          <span class="sx-desk<?php echo $deskOpen ? ' is-on' : ''; ?>"><i></i><?php echo $deskOpen ? 'Online now · Mon–Sat, 9 AM–7 PM IST' : 'Offline · back ' . $h($deskBack) . ' at 9 AM IST'; ?></span>
        </div>
        <h1 id="sxTitle">How can we help your business today?</h1>
        <p>Chat with our team on WhatsApp, raise a ticket and track every reply here, or find the answer yourself in the user guide.</p>
      </div>
      <div class="sx-banner__btns">
        <a class="sx-btn sx-btn--wa" href="https://wa.me/919150802906?text=<?php echo $waText; ?>" target="_blank" rel="noopener noreferrer"><?php echo $sx($I['wa'], 16); ?> WhatsApp support</a>
        <a class="sx-btn sx-btn--glass" href="support.php#sxForm"><?php echo $sx($I['plus'], 16); ?> Create a ticket</a>
      </div>
    </div>
  </section>

  <!-- ── Live metrics (from this account's tickets) ── -->
  <section class="sx-kpis" aria-label="Your support tickets">
    <div class="sx-card sx-kpi">
      <div class="sx-kpi__top">Tickets raised <span class="sx-kpi__ic sx-ic-slate"><?php echo $sx($I['inbox'], 16); ?></span></div>
      <div class="sx-kpi__val"><b><?php echo $statTotal; ?></b><span class="sx-chip">All time</span></div>
      <p>Every request you have sent us</p>
    </div>
    <div class="sx-card sx-kpi">
      <div class="sx-kpi__top">Open &amp; waiting <span class="sx-kpi__ic sx-ic-green"><?php echo $sx($I['clock'], 16); ?></span></div>
      <div class="sx-kpi__val"><b style="color:<?php echo $statOpen > 0 ? '#b45309' : '#059669'; ?>"><?php echo $statOpen; ?></b><?php echo $statOpen > 0 ? '<span class="sx-chip sx-chip--amber">In progress</span>' : '<span class="sx-chip sx-chip--green">All clear</span>'; ?></div>
      <p><?php echo $statOpen > 0 ? 'Our team is working on ' . ($statOpen === 1 ? 'it' : 'them') : 'Nothing waiting on our team'; ?></p>
    </div>
    <div class="sx-card sx-kpi">
      <div class="sx-kpi__top">Resolved <span class="sx-kpi__ic sx-ic-teal"><?php echo $sx($I['check'], 16); ?></span></div>
      <div class="sx-kpi__val"><b><?php echo $statClosed; ?></b><?php if ($statClosed > 0): ?><span class="sx-chip sx-chip--teal"><?php echo $resolvedPct; ?>% closed</span><?php endif; ?></div>
      <p><?php echo $statTotal > 0 ? 'Out of ' . $statTotal . ' ticket' . ($statTotal === 1 ? '' : 's') : 'No tickets yet'; ?></p>
    </div>
    <div class="sx-card sx-kpi">
      <div class="sx-kpi__top">Avg. first reply <span class="sx-kpi__ic sx-ic-indigo"><?php echo $sx($I['zap'], 16); ?></span></div>
      <div class="sx-kpi__val"><b><?php echo $replyCount > 0 ? $h($fmtDur((int)round($replySeconds / $replyCount))) : '—'; ?></b></div>
      <p><?php echo $replyCount > 0 ? 'Across ' . $replyCount . ' answered ticket' . ($replyCount === 1 ? '' : 's') : 'Shown after our first reply'; ?></p>
    </div>
  </section>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
  <div class="sx-alert sx-alert--ok" role="status"><?php echo $sx($I['check'], 18); ?> Ticket created. Our team has been notified and will reply in this thread.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <div class="sx-alert sx-alert--err" role="alert"><?php echo $sx($I['x'], 18); ?> <?php echo $h($error); ?></div>
  <?php endif; ?>

  <!-- ── Channels ── -->
  <section class="sx-chans" aria-label="Ways to reach us">
    <div class="sx-card sx-chan sx-chan--wa">
      <div class="sx-chan__top"><span class="sx-chan__ic sx-ic-green"><?php echo $sx($I['wa'], 22); ?></span><span class="sx-pill sx-pill--green">Fastest reply</span></div>
      <div><h3>WhatsApp support</h3><p>Chat directly with our team about campaigns, templates, setup or payments.</p></div>
      <div class="sx-facts sx-facts--green">
        <div><span>Number</span><b class="sx-mono">+91 91508 02906</b></div>
        <div><span>Hours</span><b>Mon–Sat, 9 AM–7 PM IST</b></div>
      </div>
      <a class="sx-btn sx-btn--primary sx-btn--block" href="https://wa.me/919150802906?text=<?php echo $waText; ?>" target="_blank" rel="noopener noreferrer"><?php echo $sx($I['wa'], 16); ?> Chat with us</a>
    </div>
    <div class="sx-card sx-chan">
      <div class="sx-chan__top"><span class="sx-chan__ic sx-ic-blue"><?php echo $sx($I['mail'], 22); ?></span><span class="sx-pill">Reply in 24 hrs</span></div>
      <div><h3>Email &amp; billing</h3><p>Custom requirements, invoices, billing questions or detailed technical issues.</p></div>
      <div class="sx-facts">
        <div><span>Inbox</span><b class="sx-mono">info@7digit.in</b></div>
        <div><span>Best for</span><b>Invoices &amp; detailed issues</b></div>
      </div>
      <a class="sx-btn sx-btn--dark sx-btn--block" href="mailto:info@7digit.in"><?php echo $sx($I['send'], 15); ?> Email info@7digit.in</a>
    </div>
    <div class="sx-card sx-chan">
      <div class="sx-chan__top"><span class="sx-chan__ic sx-ic-amber"><?php echo $sx($I['book'], 22); ?></span><span class="sx-pill sx-pill--amber">Self-serve</span></div>
      <div><h3>User guide</h3><p>Step-by-step help for every WAAMINI feature. Most answers are already here.</p></div>
      <div class="sx-facts sx-facts--amber">
        <div><span>Languages</span><b>English · தமிழ் · हिन्दी</b></div>
        <div><span>Available</span><b>Any time</b></div>
      </div>
      <a class="sx-btn sx-btn--soft sx-btn--block" href="manual.php"><?php echo $sx($I['book'], 15); ?> Open the user guide</a>
    </div>
  </section>

  <!-- ── Tickets + form / conversation ── -->
  <div class="sx-work">

    <section class="sx-card sx-tickets" aria-labelledby="sxTkTitle">
      <div class="sx-tickets__head">
        <div class="sx-row">
          <h2 class="sx-h2" id="sxTkTitle"><?php echo $sx($I['ticket'], 17); ?> My support tickets</h2>
          <span class="sx-count"><?php echo $statTotal; ?> total</span>
        </div>
        <?php if ($statTotal > 0): ?>
        <div class="sx-seg" role="tablist" aria-label="Filter tickets">
          <button type="button" class="is-on" data-f="all" role="tab" aria-selected="true">All (<?php echo $statTotal; ?>)</button>
          <button type="button" data-f="open" role="tab" aria-selected="false">Open (<?php echo $statOpen; ?>)</button>
          <button type="button" data-f="closed" role="tab" aria-selected="false">Resolved (<?php echo $statClosed; ?>)</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($statTotal > 0): ?>
      <div class="sx-list" id="sxList">
        <?php foreach ($ticketRows as $t):
          $isOpen = (($t['status'] ?? '') === 'open');
          $preview = trim($cleanMsg((string)($t['last_msg'] ?? '')));
          $preview = $preview === '' ? 'No messages yet.' : preg_replace('/\s+/', ' ', $preview);
          $when = !empty($t['last_at']) ? (string)$t['last_at'] : (string)$t['created_at'];
          $cat = $supportCategories[(string)($t['category'] ?? '')] ?? '';
          $pri = (string)($t['priority'] ?? 'normal');
          $firstReply = !empty($t['first_admin_reply']) ? max(0, strtotime((string)$t['first_admin_reply']) - strtotime((string)$t['created_at'])) : null;
        ?>
        <a href="support.php?ticket=<?php echo (int)$t['id']; ?>" class="sx-tk<?php echo $activeTicketId === (int)$t['id'] ? ' is-active' : ''; ?>" data-status="<?php echo $isOpen ? 'open' : 'closed'; ?>">
          <div class="sx-tk__meta">
            <span class="sx-tk__id">#TK-<?php echo (int)$t['id']; ?></span>
            <span class="sx-st <?php echo $isOpen ? 'sx-st--open' : 'sx-st--closed'; ?>"><?php echo $isOpen ? 'Open' : 'Resolved'; ?></span>
            <?php if ($pri !== 'normal' && isset($supportPriorities[$pri])): ?><span class="sx-chip <?php echo $pri === 'critical' ? 'sx-chip--red' : 'sx-chip--amber'; ?>"><?php echo $h($supportPriorities[$pri][0]); ?></span><?php endif; ?>
            <?php if ($cat !== ''): ?><span class="sx-cat"><?php echo $h($cat); ?></span><?php endif; ?>
            <span class="sx-tk__date"><?php echo $h(date('d M, g:i A', strtotime($when))); ?></span>
          </div>
          <h4><?php echo $h($t['subject']); ?></h4>
          <p class="sx-tk__pre"><?php echo $h(mb_strimwidth($preview, 0, 140, '…')); ?></p>
          <div class="sx-tk__foot">
            <span><?php echo (int)$t['msg_count']; ?> message<?php echo (int)$t['msg_count'] === 1 ? '' : 's'; ?></span>
            <?php if ($firstReply !== null): ?><b>First reply in <?php echo $h($fmtDur($firstReply)); ?></b><?php elseif ($isOpen): ?><b class="is-wait">Waiting for our reply</b><?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
        <div class="sx-list__empty" id="sxListEmpty">No tickets here.</div>
      </div>
      <div class="sx-tickets__foot"><?php echo $sx($I['shield'], 15); ?> Every conversation with our team is saved here.</div>
      <?php else: ?>
      <div class="sx-empty">
        <div class="sx-empty__ic"><?php echo $sx($I['ticket'], 24); ?></div>
        <h3>No tickets yet</h3>
        <p>When you raise a ticket, every reply from our team is collected here.</p>
      </div>
      <?php endif; ?>
    </section>

    <?php
    $ticketNotFound = false;
    $ticket = null;
    $msgs = null;
    if ($activeTicketId > 0) {
        $stSup4 = $conn->prepare('SELECT * FROM support_tickets WHERE id=? AND company_id=? LIMIT 1');
        if ($stSup4) {
            $stSup4->bind_param('ii', $activeTicketId, $companyId);
            if ($stSup4->execute()) { $ticket = $stSup4->get_result()->fetch_assoc(); }
            $stSup4->close();
        }
        if (is_array($ticket)) {
            $stSup5 = $conn->prepare("SELECT m.*, u.name as admin_name FROM support_messages m LEFT JOIN users u ON m.sender_id = u.id AND m.sender_type = 'admin' WHERE m.ticket_id=? ORDER BY m.id ASC");
            if ($stSup5) {
                $stSup5->bind_param('i', $activeTicketId);
                if ($stSup5->execute()) { $msgs = $stSup5->get_result(); }
                $stSup5->close();
            }
        } else {
            $ticketNotFound = true;
        }
    }
    ?>

    <?php if (is_array($ticket)):
      $tOpen = ($ticket['status'] === 'open');
      $tCat = $supportCategories[(string)($ticket['category'] ?? '')] ?? '';
      $tPri = (string)($ticket['priority'] ?? 'normal');
    ?>
    <section class="sx-card sx-convo" aria-labelledby="sxConvoTitle">
      <div class="sx-convo__head">
        <div>
          <h2 id="sxConvoTitle"><?php echo $h($ticket['subject']); ?></h2>
          <div class="sx-convo__meta">
            <span class="sx-tk__id">#TK-<?php echo (int)$ticket['id']; ?></span>
            <span class="sx-st <?php echo $tOpen ? 'sx-st--open' : 'sx-st--closed'; ?>"><?php echo $tOpen ? 'Open' : 'Resolved'; ?></span>
            <?php if ($tPri !== 'normal' && isset($supportPriorities[$tPri])): ?><span class="sx-chip <?php echo $tPri === 'critical' ? 'sx-chip--red' : 'sx-chip--amber'; ?>"><?php echo $h($supportPriorities[$tPri][0]); ?></span><?php endif; ?>
            <?php if ($tCat !== ''): ?><span class="sx-chip"><?php echo $h($tCat); ?></span><?php endif; ?>
            <span>Created <?php echo $h(date('d M Y, g:i A', strtotime((string)$ticket['created_at']))); ?></span>
          </div>
        </div>
        <a class="sx-btn sx-btn--soft" href="support.php#sxForm"><?php echo $sx($I['plus'], 14); ?> New ticket</a>
      </div>
      <div class="sx-stream" id="sxStream">
        <?php $any = false; if ($msgs): while ($m = $msgs->fetch_assoc()): $any = true; $isClient = ($m['sender_type'] === 'client'); ?>
        <div class="sx-msg <?php echo $isClient ? 'sx-msg--me' : 'sx-msg--them'; ?>">
          <div class="sx-msg__who"><?php echo $isClient ? 'You' : 'WAAMINI Support' . (!empty($m['admin_name']) ? ' · ' . $h($m['admin_name']) : ''); ?></div>
          <div class="sx-msg__bub"><?php echo nl2br($h($cleanMsg((string)$m['message']))); ?></div>
          <div class="sx-msg__time"><?php echo $h(date('d M, g:i A', strtotime((string)$m['created_at']))); ?></div>
        </div>
        <?php endwhile; endif; if (!$any): ?>
        <div class="sx-empty"><div class="sx-empty__ic"><?php echo $sx($I['clock'], 24); ?></div><h3>In the queue</h3><p>Our team will reply here shortly.</p></div>
        <?php endif; ?>
      </div>
      <form method="POST" action="support.php?ticket=<?php echo (int)$activeTicketId; ?>" class="sx-composer">
        <?php echo csrf_field(); ?>
        <textarea name="message" rows="2" placeholder="<?php echo $tOpen ? 'Write a reply…' : 'Reply to reopen this ticket…'; ?>" required maxlength="65535" aria-label="Your reply"></textarea>
        <button type="submit" name="send_reply" class="sx-btn sx-btn--primary"><?php echo $sx($I['send'], 15); ?> Send</button>
      </form>
    </section>

    <?php else: ?>
    <section class="sx-card sx-form" id="sxForm" aria-labelledby="sxFormTitle">
      <?php if ($ticketNotFound): ?>
      <div class="sx-alert sx-alert--err" role="alert" style="margin-bottom:16px"><?php echo $sx($I['x'], 18); ?> That ticket was not found. You can open a new one below.</div>
      <?php endif; ?>
      <div class="sx-form__head">
        <div>
          <h2 id="sxFormTitle">Create a support ticket</h2>
          <p>Your message goes straight to the WAAMINI team. Replies appear in this page.</p>
        </div>
        <span class="sx-form__badge">Replies in-app</span>
      </div>
      <form method="POST" action="support.php">
        <?php echo csrf_field(); ?>
        <div class="sx-grid2">
          <div class="sx-f">
            <label for="sxCat">Category <i>*</i></label>
            <select id="sxCat" name="category" required>
              <?php foreach ($supportCategories as $k => $label): ?><option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="sx-f">
            <label for="sxPri">Urgency <i>*</i></label>
            <select id="sxPri" name="priority" required>
              <?php foreach ($supportPriorities as $k => [$label, $hint]): ?><option value="<?php echo $h($k); ?>"><?php echo $h($label . ' · ' . $hint); ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="sx-f">
          <label for="sxSubj">Subject <i>*</i></label>
          <input type="text" id="sxSubj" name="subject" placeholder="e.g. Need help getting my festival template approved" required maxlength="255">
          <small>One line that sums up your question or issue.</small>
        </div>
        <div class="sx-f">
          <label for="sxMsg">Details <i>*</i></label>
          <textarea id="sxMsg" name="message" rows="6" placeholder="Describe what you need. Include the campaign or template name, a sample phone number or the invoice number if it helps." required maxlength="65535"></textarea>
        </div>
        <button type="submit" name="create_ticket" class="sx-btn sx-btn--primary sx-btn--block" style="padding:14px 18px"><?php echo $sx($I['send'], 16); ?> Submit ticket</button>
        <div class="sx-advice"><?php echo $sx($I['info'], 16); ?><span><b>Messages not going out right now?</b> WhatsApp us on <a href="https://wa.me/919150802906?text=<?php echo $waText; ?>" target="_blank" rel="noopener noreferrer">+91 91508 02906</a> for the quickest help during support hours.</span></div>
      </form>
    </section>
    <?php endif; ?>

  </div>

  <!-- ── Quick answers ── -->
  <section class="sx-card sx-faq" aria-labelledby="sxFaqTitle">
    <div class="sx-faq__head">
      <div><h3 id="sxFaqTitle">Quick answers</h3><p>The questions customers ask us most.</p></div>
      <a href="manual.php">Browse the full user guide &rarr;</a>
    </div>
    <div class="sx-faq__grid">
      <?php foreach ($faqs as [$q, $a, $href, $cta]): ?>
      <div class="sx-q">
        <h4><?php echo $sx($I['help'], 15); ?> <?php echo $h($q); ?></h4>
        <p><?php echo $h($a); ?></p>
        <a href="<?php echo $h($href); ?>"><?php echo $h($cta); ?> <?php echo $sx($I['arrow'], 13); ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="sx-foot">WAAMINI Help Desk · Powered by <b>7digit.in</b></div>
</div>

<script nonce="<?php echo $h($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var s = document.getElementById('sxStream');
  if (s) s.scrollTop = s.scrollHeight;
  var seg = document.querySelector('.sx-seg'); if (!seg) return;
  var rows = Array.prototype.slice.call(document.querySelectorAll('#sxList .sx-tk')), empty = document.getElementById('sxListEmpty');
  seg.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-f]'); if (!b) return;
    var f = b.getAttribute('data-f'), shown = 0;
    Array.prototype.forEach.call(seg.querySelectorAll('button'), function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    rows.forEach(function (r) { var show = f === 'all' || r.getAttribute('data-status') === f; r.style.display = show ? '' : 'none'; if (show) shown++; });
    if (empty) empty.style.display = shown ? 'none' : 'block';
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
