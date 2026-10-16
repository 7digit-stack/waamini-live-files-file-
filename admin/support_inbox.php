<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Support Inbox';
$currentPage = 'support_inbox';

$success = '';
$error = '';
$activeStatuses = ['open', 'pending', 'resolved'];

// Handle status change (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_status_change'])) {
    csrf_verify();
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $st = ((string)($_POST['status'] ?? '') === 'closed') ? 'closed' : 'open';
    if ($tid > 0) {
        $stmt = $conn->prepare("UPDATE support_tickets SET status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("si", $st, $tid);
            $stmt->execute();
        }
    }
    header("Location: support_inbox.php?ticket=$tid");
    exit();
}

// Handle sending reply
if (isset($_POST['send_reply']) && isset($_GET['ticket'])) {
    csrf_verify();
    $ticketId = intval($_GET['ticket']);
    $message = sanitize($_POST['message']);

    $checkStmt = $conn->prepare("SELECT id FROM support_tickets WHERE id=?");
    $exists = false;
    if ($checkStmt) {
        $checkStmt->bind_param("i", $ticketId);
        $checkStmt->execute();
        $exists = (bool)$checkStmt->get_result()->fetch_assoc();
    }
    if ($exists && !empty($message)) {
        $stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)");
        $stmt->bind_param("iis", $ticketId, $_SESSION['user_id'], $message);
        $stmt->execute();

        // Re-open if admin replies to closed
        $reopen = $conn->prepare("UPDATE support_tickets SET status='open' WHERE id=?");
        if ($reopen) {
            $reopen->bind_param("i", $ticketId);
            $reopen->execute();
        }

        header("Location: support_inbox.php?ticket=$ticketId");
        exit();
    }
}


// ── Ticket list + stats ──────────────────────────────────────────────────────
$activeTicketId = isset($_GET['ticket']) ? intval($_GET['ticket']) : 0;
$statusFilter = isset($_GET['f']) ? sanitize((string)$_GET['f']) : 'open';
$statusFilter = in_array($statusFilter, ['open', 'closed', 'all'], true) ? $statusFilter : 'open';

$rowsOf = static function (mysqli $db, string $sql): array {
    try {
        $r = $db->query($sql); $o = [];
        if ($r) { while ($x = $r->fetch_assoc()) { $o[] = $x; } }
        return $o;
    } catch (Throwable $e) {
        error_log('[WAAMINI support_inbox] ' . $e->getMessage());
        return [];
    }
};

$where = $statusFilter === 'all' ? '1=1' : ($statusFilter === 'closed' ? "t.status = 'closed'" : "t.status IN ('open','pending','resolved')");
$tickets = $rowsOf($conn, "SELECT t.id, t.company_id, t.subject, t.status, t.created_at, c.name AS company_name,
        (SELECT m.message FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_msg,
        (SELECT m.sender_type FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
        (SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id = t.id) AS last_at,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS msg_count
    FROM support_tickets t LEFT JOIN companies c ON t.company_id = c.id
    WHERE $where ORDER BY COALESCE((SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id = t.id), t.created_at) DESC LIMIT 200");

$st = $rowsOf($conn, "SELECT
    COALESCE(SUM(status IN ('open','pending','resolved')),0) AS active_n,
    COALESCE(SUM(status = 'closed'),0) AS closed_n,
    COUNT(*) AS all_n FROM support_tickets");
$activeN = (int)($st[0]['active_n'] ?? 0);
$closedN = (int)($st[0]['closed_n'] ?? 0);
$allN    = (int)($st[0]['all_n'] ?? 0);
$awaiting = $rowsOf($conn, "SELECT COUNT(*) AS n FROM support_tickets t WHERE t.status IN ('open','pending','resolved')
    AND (SELECT m.sender_type FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) = 'client'");
$awaitingN = (int)($awaiting[0]['n'] ?? 0);
$msgTotal = $rowsOf($conn, "SELECT COUNT(*) AS n FROM support_messages");
$msgTotalN = (int)($msgTotal[0]['n'] ?? 0);

// Active conversation
$ticket = null; $msgs = [];
if ($activeTicketId > 0) {
    $tr = $rowsOf($conn, "SELECT t.*, c.name AS company_name FROM support_tickets t LEFT JOIN companies c ON t.company_id = c.id WHERE t.id = " . (int)$activeTicketId);
    $ticket = $tr[0] ?? null;
    if ($ticket) {
        $msgs = $rowsOf($conn, "SELECT m.*, u.name AS user_name FROM support_messages m LEFT JOIN users u ON m.sender_id = u.id AND m.sender_type = 'user' WHERE m.ticket_id = " . (int)$activeTicketId . " ORDER BY m.created_at ASC, m.id ASC");
    }
}

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'inbox'  => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'unlock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'build'  => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
];
$pill = ['open' => 'is-ok', 'pending' => 'is-warn', 'resolved' => 'is-info', 'closed' => 'is-off'];
$ago = static function (?string $ts): string {
    if (!$ts) { return ''; }
    $s = max(0, time() - strtotime($ts));
    if ($s < 60) { return 'now'; }
    if ($s < 3600) { return floor($s / 60) . 'm'; }
    if ($s < 86400) { return floor($s / 3600) . 'h'; }
    if ($s < 86400 * 7) { return floor($s / 86400) . 'd'; }
    return date('d M', strtotime($ts));
};
$fq = static fn(string $f, ?int $t = null) => 'support_inbox.php?f=' . $f . ($t ? '&ticket=' . $t : '');
?>

<div class="db si">

  <div class="db-head">
    <div>
      <h1>Support Inbox</h1>
      <p>Answer client tickets and keep response times low.</p>
    </div>
  </div>

  <section class="si-stats">
    <div class="si-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['inbox'], 20); ?></span><div><span>Active tickets</span><b><?php echo number_format($activeN); ?></b></div></div>
    <div class="si-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['clock'], 20); ?></span><div><span>Awaiting your reply</span><b><?php echo number_format($awaitingN); ?></b></div></div>
    <div class="si-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['check'], 20); ?></span><div><span>Closed</span><b><?php echo number_format($closedN); ?></b></div></div>
    <div class="si-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['chat'], 20); ?></span><div><span>Total messages</span><b><?php echo number_format($msgTotalN); ?></b></div></div>
  </section>

  <div class="si-layout<?php echo $ticket ? ' has-chat' : ''; ?>">

    <!-- ticket list -->
    <section class="db-card si-list">
      <div class="si-list__hd">
        <h2>Tickets</h2>
        <label class="si-search"><?php echo $__ic($P['search'], 16); ?><input type="search" id="siSearch" placeholder="Search tickets" aria-label="Search tickets"></label>
      </div>
      <div class="si-chips">
        <a href="<?php echo $esc($fq('open', $activeTicketId ?: null)); ?>" class="si-chip<?php echo $statusFilter === 'open' ? ' is-active' : ''; ?>">Active <em><?php echo $activeN; ?></em></a>
        <a href="<?php echo $esc($fq('closed', $activeTicketId ?: null)); ?>" class="si-chip<?php echo $statusFilter === 'closed' ? ' is-active' : ''; ?>">Closed <em><?php echo $closedN; ?></em></a>
        <a href="<?php echo $esc($fq('all', $activeTicketId ?: null)); ?>" class="si-chip<?php echo $statusFilter === 'all' ? ' is-active' : ''; ?>">All <em><?php echo $allN; ?></em></a>
      </div>
      <div class="si-rows" id="siRows">
        <?php if ($tickets): foreach ($tickets as $t):
            $tid2 = (int)$t['id'];
            $needs = ($t['last_sender'] === 'client' && $t['status'] !== 'closed');
            $company = (string)($t['company_name'] ?? 'Unknown');
            $when = $t['last_at'] ?: $t['created_at'];
        ?>
        <a href="<?php echo $esc($fq($statusFilter, $tid2)); ?>" class="si-row<?php echo $tid2 === $activeTicketId ? ' is-active' : ''; ?>" data-q="<?php echo $esc(strtolower($company . ' ' . ($t['subject'] ?? '') . ' ' . ($t['last_msg'] ?? ''))); ?>">
          <span class="si-av"><?php echo $esc(strtoupper(mb_substr($company, 0, 1))); ?></span>
          <div class="si-row__b">
            <div class="si-row__t"><b><?php echo $esc($company); ?></b><span><?php echo $esc($ago($when)); ?></span></div>
            <div class="si-row__s"><?php echo $esc($t['subject'] ?? ''); ?></div>
            <div class="si-row__m">
              <span class="si-prev"><?php echo $t['last_msg'] !== null ? ($t['last_sender'] === 'admin' ? 'You: ' : '') . $esc(mb_substr(preg_replace('/\s+/', ' ', (string)$t['last_msg']), 0, 60)) : 'No messages yet'; ?></span>
              <span class="db-pill <?php echo $pill[$t['status']] ?? 'is-off'; ?>"><?php echo $esc(ucfirst((string)$t['status'])); ?></span>
            </div>
            <?php if ($needs): ?><span class="si-need">Needs reply</span><?php endif; ?>
          </div>
        </a>
        <?php endforeach; endif; ?>
        <div class="db-empty" id="siNone" style="<?php echo $tickets ? 'display:none' : ''; ?>"><?php echo $tickets ? 'No tickets match your search.' : 'No tickets found.'; ?></div>
      </div>
    </section>

    <!-- conversation -->
    <section class="db-card si-chat">
    <?php if ($ticket):
        $closed = ($ticket['status'] === 'closed');
        $company = (string)($ticket['company_name'] ?? 'Unknown client'); ?>
      <header class="si-chat__hd">
        <a href="<?php echo $esc($fq($statusFilter)); ?>" class="si-back" aria-label="Back to tickets"><?php echo $__ic($P['back'], 20); ?></a>
        <span class="si-av is-lg"><?php echo $esc(strtoupper(mb_substr($company, 0, 1))); ?></span>
        <div class="si-chat__id">
          <h2><?php echo $esc($ticket['subject'] ?? ('Ticket #' . $activeTicketId)); ?></h2>
          <p><?php echo $__ic($P['build'], 13); ?> <?php echo $esc($company); ?> &middot; #<?php echo (int)$activeTicketId; ?> &middot; Opened <?php echo $esc(date('d M Y', strtotime((string)$ticket['created_at']))); ?></p>
        </div>
        <span class="db-pill <?php echo $pill[$ticket['status']] ?? 'is-off'; ?>"><?php echo $esc(ucfirst((string)$ticket['status'])); ?></span>
        <form method="POST">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="ticket_id" value="<?php echo (int)$activeTicketId; ?>">
          <input type="hidden" name="status" value="<?php echo $closed ? 'open' : 'closed'; ?>">
          <button type="submit" name="ticket_status_change" class="db-btn <?php echo $closed ? 'db-btn--primary' : ''; ?>"><?php echo $__ic($closed ? $P['unlock'] : $P['lock'], 16); ?> <?php echo $closed ? 'Re-open' : 'Close ticket'; ?></button>
        </form>
      </header>

      <div id="chatBox" class="si-msgs">
        <?php if ($msgs):
            $lastDay = '';
            foreach ($msgs as $m):
                $isAdmin = ($m['sender_type'] === 'admin');
                $day = date('d M Y', strtotime((string)$m['created_at']));
                if ($day !== $lastDay): $lastDay = $day; ?>
                  <div class="si-day"><span><?php echo $esc($day === date('d M Y') ? 'Today' : $day); ?></span></div>
                <?php endif; ?>
          <div class="si-m <?php echo $isAdmin ? 'is-admin' : 'is-client'; ?>">
            <div class="si-m__who"><?php echo $isAdmin ? 'You (Admin)' : $esc($m['user_name'] ?? $company); ?> <small><?php echo $esc(date('h:i A', strtotime((string)$m['created_at']))); ?></small></div>
            <div class="si-bubble"><?php echo nl2br($esc($m['message'])); ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="db-empty">No messages in this ticket yet. Send the first reply below.</div>
        <?php endif; ?>
      </div>

      <form method="POST" class="si-reply">
        <?php echo csrf_field(); ?>
        <textarea name="message" rows="2" placeholder="Type your reply to the client…" required></textarea>
        <button type="submit" name="send_reply" class="db-btn db-btn--primary"><?php echo $__ic($P['send'], 16); ?> Send reply</button>
      </form>
    <?php else: ?>
      <div class="si-empty">
        <span><?php echo $__ic($P['chat'], 38); ?></span>
        <h2>Select a ticket</h2>
        <p>Choose a conversation from the list to read it and reply to the client.</p>
      </div>
    <?php endif; ?>
    </section>
  </div>
</div>

<style>
.si{max-width:none;margin:0}
.si-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.si-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.si-stat .db-kpi__ic{margin:0}
.si-stat span:not(.db-kpi__ic){display:block;font-size:13px;font-weight:600;color:var(--mu)}
.si-stat b{display:block;margin-top:1px;font-size:28px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}

.si-layout{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px;align-items:stretch}
.si-list,.si-chat{display:flex;flex-direction:column;height:calc(100vh - 340px);min-height:520px;overflow:hidden}
.si-list__hd{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 18px 8px}
.si-list__hd h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.si-search{display:flex;align-items:center;gap:8px;flex:1;max-width:190px;height:38px;padding:0 12px;border:1.5px solid var(--line);border-radius:10px;background:#fff;color:var(--mu)}
.si-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.si-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14px Inter,system-ui,sans-serif;color:var(--ink)}
.si-chips{display:flex;gap:8px;padding:6px 18px 10px;flex-wrap:wrap}
.si-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;background:var(--soft);color:var(--tx);font-size:13.5px;font-weight:700;text-decoration:none;transition:background .15s,color .15s}
.si-chip em{font-style:normal;font-size:12px;padding:1px 7px;border-radius:999px;background:#fff;color:var(--mu)}
.si-chip:hover{background:#E6FCF2;color:var(--gd)}
.si-chip.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.si-chip.is-active em{background:rgba(255,255,255,.22);color:#fff}
.si-rows{flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:4px 14px 16px}
.si-row{display:flex;gap:12px;padding:13px 14px;border-radius:14px;background:var(--soft);text-decoration:none;color:inherit;transition:background .15s,box-shadow .15s}
.si-row:hover{background:#fff;box-shadow:0 8px 22px -14px rgba(18,140,126,.5)}
.si-row.is-active{background:#E6FCF2;box-shadow:inset 0 0 0 1.5px rgba(18,140,126,.35)}
.si-av{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;font-weight:800;font-size:17px;display:grid;place-items:center;flex:none}
.si-av.is-lg{width:48px;height:48px;font-size:19px;border-radius:13px}
.si-row__b{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.si-row__t{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
.si-row__t b{font-size:15px;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.si-row__t span{font-size:12px;color:var(--mu);flex:none}
.si-row__s{font-size:13.5px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.si-row__m{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:3px}
.si-prev{font-size:12.5px;color:var(--mu);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.si-row .db-pill{padding:3px 9px;font-size:11.5px;flex:none}
.si-need{align-self:flex-start;margin-top:4px;padding:2px 9px;border-radius:6px;background:#FEF3DC;color:#92580a;font-size:11.5px;font-weight:800}

.si-chat__hd{display:flex;align-items:center;gap:14px;padding:16px 20px;flex:none;background:var(--soft)}
.si-back{display:none;width:38px;height:38px;border-radius:50%;place-items:center;color:var(--tx);text-decoration:none;flex:none}
.si-chat__id{flex:1;min-width:0}
.si-chat__id h2{margin:0;font-size:17px;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.si-chat__id p{margin:3px 0 0;display:flex;align-items:center;gap:6px;font-size:13px;color:var(--mu);flex-wrap:wrap}
.si-chat__hd form{margin:0}
.si-msgs{flex:1;overflow-y:auto;padding:18px 22px;display:flex;flex-direction:column;gap:12px;background:#F7FBF9}
.si-day{align-self:center;margin:4px 0}
.si-day span{display:inline-block;padding:5px 13px;border-radius:999px;background:#fff;color:var(--mu);font-size:12.5px;font-weight:700;box-shadow:0 1px 3px rgba(18,140,126,.12)}
.si-m{display:flex;flex-direction:column;max-width:74%}
.si-m.is-admin{align-self:flex-end;align-items:flex-end}
.si-m.is-client{align-self:flex-start;align-items:flex-start}
.si-m__who{font-size:12.5px;font-weight:700;color:var(--tx);margin-bottom:4px;padding:0 4px}
.si-m__who small{font-weight:500;color:var(--mu);margin-left:6px}
.si-bubble{padding:11px 16px;border-radius:16px;font-size:14.5px;line-height:1.6;word-break:break-word}
.is-admin .si-bubble{background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;border-bottom-right-radius:5px;box-shadow:0 10px 20px -14px rgba(18,140,126,.9)}
.is-client .si-bubble{background:#fff;color:var(--ink);border-bottom-left-radius:5px;box-shadow:0 2px 8px -4px rgba(18,140,126,.25)}
.si-reply{display:flex;align-items:flex-end;gap:12px;padding:14px 20px 18px;flex:none;background:#fff}
.si-reply textarea{flex:1;min-width:0;resize:none;padding:12px 16px;border:1.5px solid var(--line);border-radius:14px;background:var(--soft);font:500 14.5px/1.5 Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s,background .15s}
.si-reply textarea:focus{background:#fff;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.si-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:40px;text-align:center}
.si-empty>span{width:92px;height:92px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin-bottom:8px}
.si-empty h2{margin:0;font-size:22px;font-weight:800;color:var(--ink)}
.si-empty p{margin:0;max-width:340px;font-size:14.5px;color:var(--mu);line-height:1.55}
@media (max-width:1000px){
  .si-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .si-layout{grid-template-columns:1fr}
  .si-list,.si-chat{height:auto;min-height:0}
  .si-list{max-height:520px}
  .si-chat{min-height:520px}
  .si-layout.has-chat .si-list{display:none}
  .si-layout:not(.has-chat) .si-chat{display:none}
  .si-back{display:grid}
  .si-msgs{max-height:55vh}
  .si-m{max-width:88%}
}
@media (max-width:560px){.si-chat__hd{flex-wrap:wrap}.si-reply{flex-direction:column;align-items:stretch}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var box = document.getElementById('chatBox');
  if (box) { box.scrollTop = box.scrollHeight; }
  var s = document.getElementById('siSearch');
  if (s) { s.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim(), n = 0, rows = document.querySelectorAll('#siRows .si-row');
    rows.forEach(function (r) {
      var ok = !q || (r.getAttribute('data-q') || '').indexOf(q) !== -1;
      r.style.display = ok ? '' : 'none'; if (ok) { n++; }
    });
    var no = document.getElementById('siNone');
    if (no) { no.style.display = (rows.length && !n) ? 'block' : 'none'; no.textContent = 'No tickets match your search.'; }
  }); }
  var ta = document.querySelector('.si-reply textarea');
  if (ta) { ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); this.form.querySelector('button[name="send_reply"]').click(); }
  }); }
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
