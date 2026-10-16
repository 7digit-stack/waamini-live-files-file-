<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'WhatsApp';
$currentPage = 'whatsapp';

$activeContactId = isset($_GET['contact']) ? intval($_GET['contact']) : 0;

$conversations = $conn->query("
    SELECT cv.*, c.name, c.phone, c.tags, c.company_id, co.name as company_name
    FROM chat_conversations cv
    JOIN contacts c ON cv.contact_id = c.id
    LEFT JOIN companies co ON c.company_id = co.id
    ORDER BY cv.last_message_at DESC
");

$activeContact = null;
$messages = [];
if ($activeContactId > 0) {
    $stmt_c = $conn->prepare("SELECT c.*, co.name as company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id WHERE c.id=?");
    if ($stmt_c) {
        $stmt_c->bind_param("i", $activeContactId);
        $stmt_c->execute();
        $activeContact = $stmt_c->get_result()->fetch_assoc();
    }
    if ($activeContact) {
        $stmt_m = $conn->prepare("SELECT id, contact_id, direction, type, COALESCE(NULLIF(content,''), message) AS message_text, media_url, status, created_at FROM messages WHERE contact_id=? ORDER BY created_at ASC, id ASC LIMIT 200");
        if ($stmt_m) {
            $stmt_m->bind_param("i", $activeContactId);
            $stmt_m->execute();
            $r = $stmt_m->get_result();
            while ($m = $r->fetch_assoc()) $messages[] = $m;
        }
        $stmt_u = $conn->prepare("UPDATE chat_conversations SET unread_count=0 WHERE contact_id=?");
        if ($stmt_u) { $stmt_u->bind_param("i", $activeContactId); $stmt_u->execute(); }
    }
}

$newestMsgId = !empty($messages) ? end($messages)['id'] : 0;
$csrfToken   = csrf_get_token();

function wa_color(string $ch): string {
    $p = ['#1a9b6b','#e07b39','#9b59b6','#e74c3c','#2980b9','#16a085','#d35400','#2c3e50','#c0392b','#27ae60'];
    return $p[ord(strtoupper($ch)) % count($p)];
}
?>
<?php
// ---- view helpers (display only) ----
$convRows = [];
$totalUnread = 0;
if ($conversations) {
    while ($cv = $conversations->fetch_assoc()) {
        $convRows[] = $cv;
        $totalUnread += (int)($cv['unread_count'] ?? 0);
    }
}
$ic = static function (string $p, int $s = 24): string {
    return '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'grid'   => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    'gear'   => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'newchat'=> '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'dots'   => '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    'clip'   => '<path d="M13.234 20.252 21 12.3"/><path d="m16 6-8.414 8.586a2 2 0 0 0 0 2.828 2 2 0 0 0 2.828 0l8.414-8.586a4 4 0 0 0 0-5.656 4 4 0 0 0-5.656 0l-8.415 8.585a6 6 0 1 0 8.486 8.486"/>',
    'smile'  => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
];
$tickSent = '<svg viewBox="0 0 16 11" width="16" height="11" fill="none" stroke="#8696a0" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 6 5.5 9.5 13 2"/></svg>';
$tickDone = '<svg viewBox="0 0 16 11" width="16" height="11" fill="none" stroke="#53bdeb" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 6 4 9 10 2"/><polyline points="6 8 7.5 9 14 2"/></svg>';
$tickFail = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="#ef4444" stroke-width="1.8" stroke-linecap="round"><circle cx="8" cy="8" r="6.5"/><line x1="8" y1="4.5" x2="8" y2="8.5"/><line x1="8" y1="11" x2="8.01" y2="11"/></svg>';
$quick = [
    ['Hi!', 'Hi! How can I help you?'],
    ['Thanks', 'Thank you for choosing WAAMINI!'],
    ['Be right back', "I'll get back to you shortly."],
    ['Pricing', 'Please check our pricing at waamini.com/pricing'],
    ['More help?', 'Is there anything else I can help with?'],
    ['24h reply', "We'll respond within 24 hours."],
];
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WhatsApp – WAAMINI Admin</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:'Segoe UI',Helvetica Neue,Helvetica,Arial,sans-serif;background:#f0f2f5;color:#111b21;-webkit-font-smoothing:antialiased}
svg{display:block}
button{font-family:inherit}

/* ===== shell: rail | list | chat ===== */
.wa{display:flex;height:100vh;width:100vw;background:#fff}

/* ===== icon rail ===== */
.wa-rail{width:64px;flex:none;display:flex;flex-direction:column;align-items:center;justify-content:space-between;padding:12px 0;background:#f0f2f5;border-right:1px solid #e9edef}
.wa-rail__grp{display:flex;flex-direction:column;align-items:center;gap:6px}
.wa-rail__sep{width:30px;height:1px;background:#d1d7db;margin:6px 0}
.wa-rl{position:relative;width:44px;height:44px;border-radius:50%;display:grid;place-items:center;color:#54656f;text-decoration:none;transition:background .15s}
.wa-rl:hover{background:#e1e5e8}
.wa-rl.is-active{background:#d9dee1;color:#111b21}
.wa-rl__badge{position:absolute;top:2px;right:0;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:#25d366;color:#fff;font-size:11px;font-weight:700;display:grid;place-items:center;border:2px solid #f0f2f5}
.wa-rail__me{width:34px;height:34px;border-radius:50%;background:#25d366;color:#fff;font-weight:800;font-size:15px;display:grid;place-items:center;text-decoration:none}

/* ===== chat list ===== */
.wa-list{width:400px;flex:none;display:flex;flex-direction:column;background:#fff;border-right:1px solid #e9edef;min-width:0}
.wa-list__hd{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 6px 20px;flex:none}
.wa-list__hd h1{font-size:26px;font-weight:700;letter-spacing:-.2px;color:#111b21}
.wa-list__acts{display:flex;gap:4px}
.wa-ib{width:40px;height:40px;border-radius:50%;border:0;background:transparent;color:#54656f;display:grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s}
.wa-ib:hover{background:#f0f2f5}
.wa-ib--solid{background:#111b21;color:#fff}.wa-ib--solid:hover{background:#2a3942}

.wa-search{padding:8px 14px 6px;flex:none}
.wa-search label{display:flex;align-items:center;gap:14px;height:38px;padding:0 14px;border-radius:19px;background:#f0f2f5;color:#54656f;transition:background .15s,box-shadow .15s}
.wa-search label:focus-within{background:#fff;box-shadow:0 0 0 2px #00a884}
.wa-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font-size:16px;color:#111b21;font-family:inherit}
.wa-search input::placeholder{color:#667781}

.wa-chips{display:flex;gap:8px;padding:8px 14px 10px;flex:none;overflow-x:auto;scrollbar-width:none}
.wa-chips::-webkit-scrollbar{display:none}
.wa-chip{flex:none;height:36px;padding:0 14px;border:1px solid #d1d7db;border-radius:16px;background:#fff;color:#54656f;font-size:15.5px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .15s,border-color .15s,color .15s}
.wa-chip:hover{background:#f0f2f5}
.wa-chip.is-active{background:#d9fdd3;border-color:#d9fdd3;color:#005c4b}
.wa-chip em{font-style:normal;font-size:12px;font-weight:700}

.wa-rows{flex:1;overflow-y:auto}
.wa-rows::-webkit-scrollbar{width:6px}.wa-rows::-webkit-scrollbar-thumb{background:#c8cfd3;border-radius:3px}
.wa-row{display:flex;align-items:center;gap:15px;padding:0 15px 0 14px;height:80px;text-decoration:none;color:inherit;cursor:pointer;transition:background .1s}
.wa-row:hover{background:#f5f6f6}
.wa-row.is-active{background:#f0f2f5}
.wa-av{border-radius:50%;color:#fff;font-weight:600;display:grid;place-items:center;flex:none;text-transform:uppercase}
.wa-row__b{flex:1;min-width:0;height:100%;display:flex;flex-direction:column;justify-content:center;gap:2px;}
.wa-row:last-child .wa-row__b{border-bottom:0}
.wa-row__l1{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
.wa-row__nm{font-size:18px;font-weight:400;color:#111b21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-row__tm{font-size:13.5px;color:#667781;flex:none}
.wa-row.has-unread .wa-row__tm{color:#1fa855;font-weight:600}
.wa-row__l2{display:flex;align-items:center;justify-content:space-between;gap:8px}
.wa-row__pv{display:flex;align-items:center;gap:4px;min-width:0;font-size:15.5px;color:#667781}
.wa-row__pv span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-badge{min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#25d366;color:#fff;font-size:13px;font-weight:700;display:grid;place-items:center;flex:none}
.wa-co{display:inline-flex;align-self:flex-start;margin-top:1px;padding:1px 8px;border-radius:5px;background:#e7f8ec;color:#0b7a3b;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-none{padding:60px 24px;text-align:center;color:#667781;font-size:16px}

/* ===== chat pane ===== */
.wa-main{flex:1;min-width:0;display:flex;flex-direction:column;background:#efeae2}
.wa-ch{display:flex;align-items:center;gap:14px;height:60px;padding:0 16px;background:#f0f2f5;border-bottom:1px solid #e9edef;flex:none}
.wa-back{display:none}
.wa-ch__id{display:flex;align-items:center;gap:14px;min-width:0;flex:1;cursor:default}
.wa-ch__nm{font-size:18px;font-weight:500;color:#111b21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-ch__sub{font-size:15px;color:#667781;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.wa-ch__sub b{color:#1fa855;font-weight:600}
.wa-ch__acts{display:flex;align-items:center;gap:2px}
.wa-tag{padding:3px 10px;border-radius:12px;background:#e9edef;color:#54656f;font-size:13px;font-weight:600;margin-right:4px}

.wa-msgs{flex:1;overflow-y:auto;padding:14px 8%;display:flex;flex-direction:column;gap:3px;background-color:#efeae2;background-image:url(../assets/img/wa-doodle.jpg);background-size:380px auto;background-repeat:repeat}
.wa-msgs::-webkit-scrollbar{width:6px}.wa-msgs::-webkit-scrollbar-thumb{background:#c2bbab;border-radius:3px}
.wa-ds{align-self:center;margin:10px 0 8px}
.wa-ds span{display:inline-block;padding:6px 12px;border-radius:8px;background:#fff;color:#54656f;font-size:14px;font-weight:500;box-shadow:0 1px 1px rgba(11,20,26,.1)}
.wa-enc{align-self:center;max-width:520px;margin:6px 0 10px;padding:8px 14px;border-radius:8px;background:#ffeecd;color:#54656f;font-size:14px;text-align:center;line-height:1.45;box-shadow:0 1px 1px rgba(11,20,26,.08)}

.wa-msg{display:flex;max-width:65%;position:relative}
.wa-msg-o{align-self:flex-end}
.wa-msg-i{align-self:flex-start}
.wa-bbl{position:relative;padding:6px 8px 8px 9px;border-radius:7.5px;font-size:16.5px;line-height:1.45;color:#111b21;word-break:break-word;box-shadow:0 1px .5px rgba(11,20,26,.15)}
.wa-msg-o .wa-bbl{background:#d9fdd3;border-top-right-radius:0}
.wa-msg-i .wa-bbl{background:#fff;border-top-left-radius:0}
.wa-msg-o .wa-bbl::before,.wa-msg-i .wa-bbl::before{content:'';position:absolute;top:0;width:9px;height:12px}
.wa-msg-o .wa-bbl::before{right:-8px;background:#d9fdd3;clip-path:polygon(0 0,100% 0,0 100%)}
.wa-msg-i .wa-bbl::before{left:-8px;background:#fff;clip-path:polygon(100% 0,100% 100%,0 0)}
.wa-bbl .wa-txt{white-space:pre-wrap}
/* time + ticks sit inline at the bottom-right of the text (like WhatsApp Web) */
.wa-meta{float:right;display:inline-flex;align-items:center;gap:4px;margin:6px 0 -4px 12px;font-size:12.5px;color:#667781;line-height:1;position:relative;top:4px;white-space:nowrap}
.wa-bbl img{max-width:100%;border-radius:6px;margin-bottom:5px;display:block}

.wa-qr{position:relative;flex:none}
.wa-pop{position:absolute;left:14px;bottom:8px;z-index:20;min-width:260px;max-height:300px;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(11,20,26,.25);padding:6px;display:none}
.wa-pop.is-open{display:block}
.wa-pop__t{padding:8px 12px 6px;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#667781}
.wa-pop button{display:block;width:100%;text-align:left;padding:10px 12px;border:0;border-radius:8px;background:transparent;cursor:pointer;font-size:15.5px;color:#111b21}
.wa-pop button:hover{background:#f0f2f5}
.wa-pop button small{display:block;margin-top:2px;font-size:14px;color:#667781;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.wa-inp{display:flex;align-items:center;gap:6px;padding:8px 16px 10px;background:#f0f2f5;flex:none}
.wa-inp .wa-ib{width:42px;height:42px}
.wa-inp__q{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:1px solid #d1d7db;border-radius:18px;background:#fff;color:#54656f;font-size:15.5px;font-weight:500;cursor:pointer;flex:none}
.wa-inp__q:hover{background:#f7f8f8}
.wa-inp__q svg{color:#00a884}
.wa-inp__t{flex:1;min-width:0;height:46px;padding:0 16px;border:0;outline:0;border-radius:21px;background:#fff;font-size:17px;color:#111b21;font-family:inherit}
.wa-inp__t::placeholder{color:#667781}
.wa-snd{width:44px;height:44px;border:0;border-radius:50%;background:#00a884;color:#fff;display:grid;place-items:center;cursor:pointer;flex:none;transition:background .15s,transform .1s}
.wa-snd:hover{background:#008f72}.wa-snd:active{transform:scale(.94)}

/* empty state */
.wa-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:32px;text-align:center;background:#f0f2f5;border-bottom:6px solid #25d366}
.wa-empty__ic{width:120px;height:120px;border-radius:50%;background:#dfe5e7;color:#aebac1;display:grid;place-items:center;margin-bottom:6px}
.wa-empty h2{font-size:30px;font-weight:300;color:#41525d;letter-spacing:-.4px}
.wa-empty p{font-size:16px;color:#667781;max-width:380px;line-height:1.6}
.wa-empty a{display:inline-flex;align-items:center;gap:8px;margin-top:6px;padding:11px 24px;border-radius:24px;background:#00a884;color:#fff;font-size:14.5px;font-weight:600;text-decoration:none}
.wa-empty a:hover{background:#008f72}
.wa-empty small{display:flex;align-items:center;gap:6px;margin-top:22px;font-size:13px;color:#8696a0}

@media (max-width:1100px){.wa-list{width:340px}}
@media (max-width:820px){
  .wa-rail{display:none}
  .wa-list{width:100%;border-right:0}
  .wa-main{display:none}
  .wa.has-chat .wa-list{display:none}
  .wa.has-chat .wa-main{display:flex}
  .wa-back{display:grid}
  .wa-msgs{padding:12px 4%}.wa-msg{max-width:84%}
  .wa-inp__q span{display:none}
}
</style>
</head>
<body>
<div class="wa<?php echo $activeContact ? ' has-chat' : ''; ?>">

  <!-- ===== icon rail ===== -->
  <nav class="wa-rail" aria-label="Sections">
    <div class="wa-rail__grp">
      <a href="whatsapp.php" class="wa-rl is-active" title="Chats"><?php echo $ic($I['chat'], 24); ?><?php if ($totalUnread > 0): ?><span class="wa-rl__badge"><?php echo $totalUnread > 99 ? '99+' : (int)$totalUnread; ?></span><?php endif; ?></a>
      <a href="/admin/contacts.php" class="wa-rl" title="Contacts"><?php echo $ic($I['users'], 24); ?></a>
      <a href="/admin/broadcast.php" class="wa-rl" title="Broadcasts"><?php echo $ic($I['mega'], 24); ?></a>
      <span class="wa-rail__sep"></span>
      <a href="/admin/dashboard.php" class="wa-rl" title="Dashboard"><?php echo $ic($I['grid'], 24); ?></a>
    </div>
    <div class="wa-rail__grp">
      <a href="/admin/settings_whatsapp.php" class="wa-rl" title="WhatsApp setup"><?php echo $ic($I['gear'], 24); ?></a>
      <a href="/admin/dashboard.php" class="wa-rail__me" title="WAAMINI Admin">W</a>
    </div>
  </nav>

  <!-- ===== chat list ===== -->
  <section class="wa-list" aria-label="Conversations">
    <div class="wa-list__hd">
      <h1>WhatsApp</h1>
      <div class="wa-list__acts">
        <a href="/admin/contacts.php" class="wa-ib wa-ib--solid" title="New chat"><?php echo $ic($I['newchat'], 20); ?></a>
        <a href="/admin/settings_whatsapp.php" class="wa-ib" title="Menu"><?php echo $ic($I['dots'], 20); ?></a>
      </div>
    </div>

    <div class="wa-search"><label><?php echo $ic($I['search'], 18); ?><input type="text" id="chatSearch" placeholder="Search or start a new chat" autocomplete="off"></label></div>

    <div class="wa-chips" id="waChips">
      <button type="button" class="wa-chip is-active" data-f="all">All</button>
      <button type="button" class="wa-chip" data-f="unread">Unread<?php if ($totalUnread > 0): ?> <em><?php echo (int)$totalUnread; ?></em><?php endif; ?></button>
    </div>

    <div class="wa-rows" id="chatList">
      <?php if ($convRows): foreach ($convRows as $cv):
        $nm = (string)$cv['name'];
        $init = strtoupper(mb_substr($nm !== '' ? $nm : '?', 0, 1));
        $isAct = ($cv['contact_id'] == $activeContactId);
        $timeStr = $cv['last_message_at'] ? date('g:i A', strtotime($cv['last_message_at'])) : '';
        $unread = (int)($cv['unread_count'] ?? 0);
        $bgc = wa_color($init); ?>
        <a href="whatsapp.php?contact=<?php echo (int)$cv['contact_id']; ?>" class="wa-row<?php echo $isAct ? ' is-active' : ''; ?><?php echo ($unread > 0 && !$isAct) ? ' has-unread' : ''; ?>"
           data-name="<?php echo $h(strtolower($nm . ' ' . ($cv['phone'] ?? ''))); ?>" data-unread="<?php echo ($unread > 0 && !$isAct) ? '1' : '0'; ?>">
          <span class="wa-av" style="width:49px;height:49px;font-size:19px;background:<?php echo $bgc; ?>"><?php echo $h($init); ?></span>
          <div class="wa-row__b">
            <div class="wa-row__l1"><span class="wa-row__nm"><?php echo $h($nm); ?></span><span class="wa-row__tm"><?php echo $h($timeStr); ?></span></div>
            <div class="wa-row__l2">
              <div class="wa-row__pv"><span><?php echo $h(mb_substr((string)($cv['last_message'] ?? ''), 0, 48)); ?></span></div>
              <?php if ($unread > 0 && !$isAct): ?><span class="wa-badge"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
            </div>
            <?php if (!empty($cv['company_name'])): ?><span class="wa-co"><?php echo $h($cv['company_name']); ?></span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; else: ?>
        <div class="wa-none">No conversations yet.</div>
      <?php endif; ?>
      <div class="wa-none" id="waNoMatch" style="display:none">No chats match your search.</div>
    </div>
  </section>

  <!-- ===== chat pane ===== -->
  <main class="wa-main">
  <?php if ($activeContact):
      $cname = (string)$activeContact['name'];
      $ci = strtoupper(mb_substr($cname !== '' ? $cname : '?', 0, 1));
      $cb = wa_color($ci); ?>

    <header class="wa-ch">
      <a href="whatsapp.php" class="wa-ib wa-back" aria-label="Back to chats"><?php echo $ic($I['back'], 22); ?></a>
      <div class="wa-ch__id">
        <span class="wa-av" style="width:40px;height:40px;font-size:16px;background:<?php echo $cb; ?>"><?php echo $h($ci); ?></span>
        <div style="min-width:0">
          <div class="wa-ch__nm"><?php echo $h($cname); ?></div>
          <div class="wa-ch__sub"><?php echo $h($activeContact['phone']); ?><?php if (!empty($activeContact['company_name'])): ?> &middot; <b><?php echo $h($activeContact['company_name']); ?></b><?php endif; ?></div>
        </div>
      </div>
      <div class="wa-ch__acts">
        <?php if ($activeContact['tags']): foreach (array_slice(explode(',', $activeContact['tags']), 0, 2) as $tag): ?>
          <span class="wa-tag"><?php echo $h(trim($tag)); ?></span>
        <?php endforeach; endif; ?>
        <button type="button" class="wa-ib" title="Search in chat" id="waFind"><?php echo $ic($I['search'], 20); ?></button>
        <a href="contact_edit.php?id=<?php echo (int)$activeContact['id']; ?>" class="wa-ib" title="Edit contact"><?php echo $ic($I['edit'], 20); ?></a>
        <button type="button" class="wa-ib" title="More"><?php echo $ic($I['dots'], 20); ?></button>
      </div>
    </header>

    <div class="wa-msgs" id="chatMessages">
      <div class="wa-enc"><?php echo $ic($I['lock'], 12); ?> Messages are end-to-end encrypted. Only people in this chat can read them.</div>
      <?php
      $lastDate = '';
      foreach ($messages as $msg):
        $md = date('d M Y', strtotime($msg['created_at']));
        $mt = date('g:i A', strtotime($msg['created_at']));
        $out = ($msg['direction'] === 'outgoing');
        if ($md !== $lastDate):
          $lastDate = $md;
          $today = date('d M Y'); $yest = date('d M Y', strtotime('-1 day'));
          $dlab = ($md === $today) ? 'Today' : ($md === $yest ? 'Yesterday' : $md); ?>
        <div class="wa-ds"><span><?php echo $h($dlab); ?></span></div>
      <?php endif; ?>
        <div class="wa-msg <?php echo $out ? 'wa-msg-o' : 'wa-msg-i'; ?>">
          <div class="wa-bbl">
            <?php
            $__at = (string)($msg['type'] ?? 'text');
            $__am = (string)($msg['media_url'] ?? '');
            if ($__at === 'image' && $__am !== ''):
                $__isrc = (strpos($__am, 'http') === 0 || strpos($__am, 'uploads/') === 0)
                    ? $h($__am)
                    : '../client/ajax_media_proxy.php?id=' . urlencode($__am); ?>
              <img src="<?php echo $__isrc; ?>" alt="">
            <?php elseif (($__at === 'audio' || $__at === 'voice') && $__am !== ''): ?>
              <div style="padding:2px 0 6px;min-width:210px"><audio controls preload="none" src="../client/ajax_media_proxy.php?id=<?php echo urlencode($__am); ?>" style="display:block;width:230px;height:36px"></audio></div>
            <?php elseif ($__at === 'document' && $__am !== ''): ?>
              <a href="<?php echo $h($__am); ?>" target="_blank" rel="noopener" style="display:block;padding:2px 0 6px;color:#027eb5;text-decoration:none;font-weight:500">&#128196; Document</a>
            <?php endif; ?>
            <span class="wa-txt"><?php echo $h($msg['message_text']); ?></span>
            <span class="wa-meta"><span><?php echo $h($mt); ?></span><?php if ($out):
                $s = (string)$msg['status'];
                $tk = ($s === 'failed') ? $tickFail : (($s === 'pending' || $s === 'sent') ? $tickSent : $tickDone); ?>
              <span class="wa-ticks" data-s="<?php echo $h($s); ?>"><?php echo $tk; ?></span><?php endif; ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="wa-qr">
      <div class="wa-pop" id="waPop" role="menu">
        <div class="wa-pop__t">Quick replies</div>
        <?php foreach ($quick as [$label, $text]): ?>
          <button type="button" role="menuitem" data-reply="<?php echo $h($text); ?>"><?php echo $h($label); ?><small><?php echo $h($text); ?></small></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wa-inp">
      <button type="button" class="wa-ib" title="Emoji"><?php echo $ic($I['smile'], 24); ?></button>
      <button type="button" class="wa-ib" title="Attach"><?php echo $ic($I['clip'], 22); ?></button>
      <button type="button" class="wa-inp__q" id="waQuickBtn" aria-haspopup="true"><?php echo $ic($I['bolt'], 15); ?><span>Quick reply</span></button>
      <input type="text" id="msgInput" class="wa-inp__t" placeholder="Type a message" autocomplete="off">
      <button type="button" class="wa-snd" id="chatSendBtn" title="Send"><?php echo $ic($I['send'], 20); ?></button>
    </div>

  <?php else: ?>
    <div class="wa-empty">
      <span class="wa-empty__ic"><?php echo $ic($I['chat'], 54); ?></span>
      <h2>WAAMINI WhatsApp</h2>
      <p>Select a conversation from the left to start messaging, or open your contacts to begin a new chat.</p>
      <a href="/admin/contacts.php"><?php echo $ic($I['users'], 18); ?> View contacts</a>
      <small><?php echo $ic($I['lock'], 14); ?> Your messages are end-to-end encrypted</small>
    </div>
  <?php endif; ?>
  </main>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
var contactId = <?php echo $activeContactId ?: 0; ?>;
var csrfToken = <?php echo json_encode($csrfToken); ?>;
var lastMsgId = <?php echo (int)$newestMsgId; ?>;
var TICK_SENT = <?php echo json_encode($tickSent); ?>, TICK_DONE = <?php echo json_encode($tickDone); ?>, TICK_FAIL = <?php echo json_encode($tickFail); ?>;

function scrollBottom() {
    var el = document.getElementById('chatMessages');
    if (el) el.scrollTop = el.scrollHeight;
}
scrollBottom();

function appendMsg(text, dir) {
    var c = document.getElementById('chatMessages');
    if (!c) return null;
    var now = new Date();
    var t = now.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true});
    var outer = document.createElement('div');
    outer.className = 'wa-msg ' + (dir === 'outgoing' ? 'wa-msg-o' : 'wa-msg-i');
    var bbl = document.createElement('div');
    bbl.className = 'wa-bbl';
    var txt = document.createElement('span');
    txt.className = 'wa-txt';
    txt.textContent = text;
    bbl.appendChild(txt);
    var meta = document.createElement('span');
    meta.className = 'wa-meta';
    var ts = document.createElement('span');
    ts.textContent = t;
    meta.appendChild(ts);
    var tk = null;
    if (dir === 'outgoing') {
        tk = document.createElement('span');
        tk.className = 'wa-ticks';
        tk.innerHTML = TICK_SENT;
        meta.appendChild(tk);
    }
    bbl.appendChild(meta);
    outer.appendChild(bbl);
    c.appendChild(outer);
    scrollBottom();
    return tk;
}

function sendMessage(preset) {
    var inp = document.getElementById('msgInput');
    var txt = (typeof preset === 'string' ? preset : inp.value).trim();
    if (!txt || !contactId) return;
    var tk = appendMsg(txt, 'outgoing');
    inp.value = ''; inp.focus();
    fetch('/admin/chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({action: 'send', contact_id: contactId, message: txt, _token: csrfToken})
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!tk) return;
        if (!data.success) { tk.innerHTML = TICK_FAIL; }
        else if (data.wa_sent === true) { tk.innerHTML = TICK_DONE; }
    })
    .catch(function () { if (tk) tk.innerHTML = TICK_FAIL; });
}

// Polling for new messages
if (contactId > 0) {
    setInterval(function () {
        fetch('/admin/chat_api.php?action=poll&contact_id=' + contactId + '&last_id=' + lastMsgId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.messages && data.messages.length) {
                data.messages.forEach(function (m) {
                    if (m.id > lastMsgId) { appendMsg(m.message_text, m.direction); lastMsgId = m.id; }
                });
            }
        }).catch(function () {});
    }, 5000);
}

// Send / Enter
var sb = document.getElementById('chatSendBtn');
if (sb) sb.addEventListener('click', function () { sendMessage(); });
var mi = document.getElementById('msgInput');
if (mi) mi.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });

// Quick replies popover
var qb = document.getElementById('waQuickBtn'), pop = document.getElementById('waPop');
if (qb && pop) {
    qb.addEventListener('click', function (e) { e.stopPropagation(); pop.classList.toggle('is-open'); });
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#waPop button[data-reply]');
        if (item) { pop.classList.remove('is-open'); sendMessage(item.getAttribute('data-reply')); return; }
        if (!e.target.closest('#waPop')) pop.classList.remove('is-open');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') pop.classList.remove('is-open'); });
}

// Search + filter chips (All / Unread)
var filter = 'all';
function applyList() {
    var cs = document.getElementById('chatSearch'), q = cs ? cs.value.toLowerCase().trim() : '', shown = 0;
    document.querySelectorAll('#chatList .wa-row').forEach(function (el) {
        var ok = (!q || (el.dataset.name || '').indexOf(q) !== -1) && (filter === 'all' || el.dataset.unread === '1');
        el.style.display = ok ? 'flex' : 'none';
        if (ok) shown++;
    });
    var nm = document.getElementById('waNoMatch');
    if (nm) nm.style.display = (shown === 0 && document.querySelector('#chatList .wa-row')) ? 'block' : 'none';
}
var csEl = document.getElementById('chatSearch');
if (csEl) csEl.addEventListener('input', applyList);
var chips = document.getElementById('waChips');
if (chips) chips.addEventListener('click', function (e) {
    var b = e.target.closest('.wa-chip'); if (!b) return;
    chips.querySelectorAll('.wa-chip').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    filter = b.dataset.f; applyList();
});
var find = document.getElementById('waFind');
if (find) find.addEventListener('click', function () { var s = document.getElementById('chatSearch'); if (s) { s.focus(); } });
</script>
</body>
</html>
