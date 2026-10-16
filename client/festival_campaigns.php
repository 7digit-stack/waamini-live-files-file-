<?php
include '../includes/client_auth_check.php';

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

if (!has_feature('festival_reminder')) {
    http_response_code(403);
    $pageTitle = 'Festival Campaign Calendar';
    $currentPage = 'festival_campaigns';
    include '../includes/client_header.php';
    ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=2'); ?>">
    <div class="su"><div class="su-card" style="align-items:center;text-align:center;padding:48px 24px;">
        <div class="su-ico" style="width:64px;height:64px;"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 28); ?></div>
        <h2 style="justify-content:center;">Festival reminders are not in your plan</h2>
        <p class="sub" style="margin:0">Upgrade to send automatic festival wishes to your customers.</p>
        <a href="subscription.php" class="su-btn">View plans</a>
    </div></div>
    <?php
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'Festival Campaign Calendar';
$currentPage = 'festival_campaigns';
require_once __DIR__ . '/../includes/festival_engine.php';

festival_ensure_schema($conn);
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ist = new DateTimeZone('Asia/Kolkata');
$today = (new DateTime('now', $ist))->format('Y-m-d');

// Older rows: icon markup stored inside names/messages (repair once)
$stmt_fix = $conn->prepare("SELECT id, festival_name, message FROM festival_campaigns WHERE company_id=? AND (festival_name LIKE '%<%' OR message LIKE '%<svg%')");
if ($stmt_fix) {
    $stmt_fix->bind_param('i', $companyId); $stmt_fix->execute();
    $stmt_fx = $conn->prepare("UPDATE festival_campaigns SET festival_name=?, message=? WHERE id=? AND company_id=?");
    foreach ($stmt_fix->get_result()->fetch_all(MYSQLI_ASSOC) as $fr) {
        $cleanName = festival_plain_name(strip_tags((string)$fr['festival_name']));
        $msg = stripos((string)$fr['message'], '<svg') !== false ? festival_default_message($cleanName) : (string)$fr['message'];
        $fid = (int)$fr['id'];
        if ($stmt_fx) { $stmt_fx->bind_param('ssii', $cleanName, $msg, $fid, $companyId); $stmt_fx->execute(); }
    }
}

// Correct dates of festivals not sent yet (Diwali, Holi, Eid ... move every year)
$fixedDates = festival_fix_dates($conn, $companyId);

$back = static function (string $msg, string $tone = 'ok', string $qs = '') {
    $_SESSION['festival_flash'] = [$tone, $msg];
    header('Location: festival_campaigns.php' . $qs); exit();
};

// Create a wish for a festival picked on the calendar
if (isset($_POST['add_festival'])) {
    csrf_verify();
    $key = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['festival_key'] ?? ''));
    $date = (string)($_POST['festival_date'] ?? '');
    $ev = null;
    if ($key !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        foreach (festival_events($date, $date) as $e) { if ($e['key'] === $key) { $ev = $e; break; } }
    }
    if (!$ev) $back('That festival was not found in the calendar.', 'err');
    $chk = $conn->prepare("SELECT id FROM festival_campaigns WHERE company_id=? AND festival_key=? AND festival_date=? LIMIT 1");
    $chk->bind_param('iss', $companyId, $key, $date); $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if ($existing) $back('This festival already has a wish.', 'ok', '?edit=' . (int)$existing['id'] . '&m=' . substr($date, 0, 7));
    $name = $ev['name'];
    $msg = festival_default_message(festival_plain_name($name));
    $ins = $conn->prepare("INSERT INTO festival_campaigns (company_id, festival_key, festival_name, festival_date, message, is_active, festival_region, days_before, auto_send, festival_type) VALUES (?, ?, ?, ?, ?, 1, ?, 0, 0, ?)");
    $region = $ev['region']; $type = $ev['type'];
    $ins->bind_param('issssss', $companyId, $key, $name, $date, $msg, $region, $type);
    $ins->execute();
    $back('Wish created for ' . festival_plain_name($name) . '. Review it and switch on "Send automatically".', 'ok', '?edit=' . (int)$conn->insert_id . '&m=' . substr($date, 0, 7));
}

// Save a wish
if (isset($_POST['save_campaign'])) {
    csrf_verify();
    $fid = (int)($_POST['festival_id'] ?? 0);
    $msg = trim((string)($_POST['message'] ?? ''));
    $days = max(0, min(7, (int)($_POST['days_before'] ?? 0)));
    $auto = isset($_POST['auto_send']) ? 1 : 0;
    $tpl = trim((string)($_POST['template_name'] ?? ''));
    $tpls = festival_templates($conn, $companyId);
    if ($tpl !== '' && !isset($tpls[$tpl])) $tpl = '';
    $lang = $tpl !== '' ? (string)$tpls[$tpl]['language'] : null;
    $tplVal = $tpl !== '' ? $tpl : null;
    if ($msg === '') $back('The message cannot be empty.', 'err', '?edit=' . $fid);
    $st = $conn->prepare("UPDATE festival_campaigns SET message=?, days_before=?, auto_send=?, is_active=1, template_name=?, template_lang=? WHERE id=? AND company_id=? AND sent_at IS NULL");
    $st->bind_param('siissii', $msg, $days, $auto, $tplVal, $lang, $fid, $companyId);
    $st->execute();
    $back($auto ? 'Saved. It will be sent automatically.' : 'Saved. Automatic sending is off for this festival.');
}

// Remove a wish that was not sent
if (isset($_POST['delete_campaign'])) {
    csrf_verify();
    $fid = (int)($_POST['festival_id'] ?? 0);
    $st = $conn->prepare("DELETE FROM festival_campaigns WHERE id=? AND company_id=? AND sent_at IS NULL");
    $st->bind_param('ii', $fid, $companyId); $st->execute();
    $back('Wish removed.');
}

// Send now (same rules as automatic sending: template, or customers inside the 24 h window)
if (isset($_POST['send_now'])) {
    csrf_verify();
    $fid = (int)($_POST['festival_id'] ?? 0);
    $st = $conn->prepare("SELECT * FROM festival_campaigns WHERE id=? AND company_id=? AND sent_at IS NULL");
    $st->bind_param('ii', $fid, $companyId); $st->execute();
    $fc = $st->get_result()->fetch_assoc();
    if (!$fc) $back('This wish was already sent.', 'err');
    $tot = ['queued' => 0, 'skipped' => 0, 'note' => ''];
    for ($i = 0; $i < 20; $i++) { // up to 5,000 contacts in one click; the rest continue automatically
        $r = festival_queue_campaign($conn, $fc);
        $tot['queued'] += $r['queued']; $tot['skipped'] += $r['skipped']; $tot['note'] = $r['note'];
        if ($r['done'] || ($r['queued'] + $r['skipped']) === 0) break;
    }
    $back('Queued ' . $tot['queued'] . ' message' . ($tot['queued'] === 1 ? '' : 's') . ($tot['skipped'] ? ', ' . $tot['skipped'] . ' customer' . ($tot['skipped'] === 1 ? '' : 's') . ' skipped' : '') . '.' . ($tot['note'] ? ' ' . $tot['note'] : ''), $tot['queued'] ? 'ok' : 'warn');
}

// ---- Personal occasions -------------------------------------------------
require_once __DIR__ . '/../includes/occasion_engine.php';
occasion_ensure_schema($conn);

if (isset($_POST['add_occasion'])) {
    csrf_verify();
    $cid = (int)($_POST['contact_id'] ?? 0);
    $type = (string)($_POST['occasion_type'] ?? '');
    $date = (string)($_POST['occasion_date'] ?? '');
    $label = $type === 'custom' ? trim(mb_substr((string)($_POST['label'] ?? ''), 0, 100)) : '';
    $noYear = isset($_POST['no_year']);
    if (!isset(occasion_types()[$type]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        $back('Please choose an occasion and a valid date.', 'err', '?v=occasions');
    }
    if ($type === 'custom' && $label === '') $back('Give the day a name, e.g. Shop opening day.', 'err', '?v=occasions');
    $own = $conn->prepare("SELECT id FROM contacts WHERE id=? AND company_id=?");
    $own->bind_param('ii', $cid, $companyId); $own->execute();
    if (!$own->get_result()->num_rows) $back('Pick the customer from the list.', 'err', '?v=occasions');
    // Without a year, keep only day and month (stored in a leap year so 29 Feb is possible)
    $stored = $noYear ? '2000-' . substr($date, 5) : $date;
    $yk = $noYear ? 0 : 1;
    $st = $conn->prepare("INSERT INTO contact_occasions (company_id, contact_id, occasion_type, label, occasion_date, year_known) VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE occasion_date=VALUES(occasion_date), year_known=VALUES(year_known)");
    $st->bind_param('iisssi', $companyId, $cid, $type, $label, $stored, $yk);
    $st->execute();
    $back('Date saved.', 'ok', '?v=occasions');
}
if (isset($_POST['delete_occasion'])) {
    csrf_verify();
    $oid = (int)($_POST['occasion_id'] ?? 0);
    $st = $conn->prepare("DELETE FROM contact_occasions WHERE id=? AND company_id=?");
    $st->bind_param('ii', $oid, $companyId); $st->execute();
    $back('Date removed.', 'ok', '?v=occasions');
}
if (isset($_POST['save_occasion_wish'])) {
    csrf_verify();
    $type = (string)($_POST['occasion_type'] ?? '');
    if (!isset(occasion_types()[$type])) $back('Unknown occasion.', 'err', '?v=occasions');
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') $back('The message cannot be empty.', 'err', '?v=occasions');
    $days = max(0, min(7, (int)($_POST['days_before'] ?? 0)));
    $auto = isset($_POST['auto_send']) ? 1 : 0;
    $tpl = trim((string)($_POST['template_name'] ?? ''));
    $tpls = festival_templates($conn, $companyId);
    if ($tpl !== '' && !isset($tpls[$tpl])) $tpl = '';
    $tplVal = $tpl !== '' ? $tpl : null;
    $lang = $tpl !== '' ? (string)$tpls[$tpl]['language'] : null;
    $st = $conn->prepare("INSERT INTO occasion_wishes (company_id, occasion_type, message, template_name, template_lang, days_before, auto_send) VALUES (?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE message=VALUES(message), template_name=VALUES(template_name), template_lang=VALUES(template_lang), days_before=VALUES(days_before), auto_send=VALUES(auto_send)");
    $st->bind_param('issssii', $companyId, $type, $msg, $tplVal, $lang, $days, $auto);
    $st->execute();
    $back($auto ? 'Saved. ' . occasion_types()[$type]['label'] . ' wishes will be sent automatically.' : 'Saved. Automatic sending is off for ' . strtolower(occasion_types()[$type]['label']) . 's.', 'ok', '?v=occasions');
}

$flash = $_SESSION['festival_flash'] ?? null;
unset($_SESSION['festival_flash']);

// Selected day (?d=), or a month from ?m= (used after saving), else today
$sel = $today;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['d'] ?? '')) && strtotime((string)$_GET['d'])) {
    $sel = (string)$_GET['d'];
} elseif (preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? ''))) {
    $sel = $_GET['m'] === substr($today, 0, 7) ? $today : $_GET['m'] . '-01';
}
$view = in_array($_GET['v'] ?? '', ['week', 'month', 'list', 'occasions'], true) ? $_GET['v'] : 'week';
$filter = in_array($_GET['f'] ?? '', ['national', 'holiday', 'observance', 'special', 'tamil', 'personal'], true) ? $_GET['f'] : '';
$matches = static fn(array $e) => !$filter || ($filter === 'personal' ? false : ($filter === 'tamil' ? $e['region'] === 'tamil' : $e['type'] === $filter));

$m = substr($sel, 0, 7);
$monthStart = $m . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$weekStart = date('Y-m-d', strtotime($sel . ' -' . ((int)date('N', strtotime($sel)) - 1) . ' days'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

// Calendar events
$monthEvents = array_values(array_filter(festival_events($monthStart, $monthEnd), $matches));
$weekEvents = array_values(array_filter(festival_events($weekStart, $weekEnd), $matches));
$upcoming = array_values(array_filter(festival_events($today, date('Y-m-d', strtotime($today . ' +60 days'))), $matches));
$yearEvents = festival_events(date('Y-m-d', strtotime($today . ' -30 days')), date('Y-m-d', strtotime($today . ' +365 days')));
$miniEvents = festival_events(date('Y-m-d', strtotime($monthStart . ' -7 days')), date('Y-m-d', strtotime($monthEnd . ' +7 days')));
$byDate = [];
foreach ($miniEvents as $e) $byDate[$e['date']][] = $e;

// Personal occasions shown on the calendar (hidden when a festival-only filter is on)
$occTypes = occasion_types();
$occWishes = occasion_wishes($conn, $companyId);
$occIco = [
    'birthday' => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/>',
    'anniversary' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    'business_anniversary' => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
    'custom' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
];
$showOcc = !$filter || $filter === 'personal';
$occFrom = min($weekStart, date('Y-m-d', strtotime($monthStart . ' -7 days')));
$occTo = max($weekEnd, date('Y-m-d', strtotime($monthEnd . ' +7 days')));
$occByDate = [];
if ($showOcc) foreach (occasion_between($conn, $companyId, $occFrom, $occTo) as $o) $occByDate[$o['date']][] = $o;
$occUpcoming = occasion_between($conn, $companyId, $today, date('Y-m-d', strtotime($today . ' +60 days')));
$occAll = [];
$st = $conn->prepare("SELECT o.*, c.name, c.phone FROM contact_occasions o JOIN contacts c ON c.id=o.contact_id AND c.company_id=o.company_id WHERE o.company_id=?");
$st->bind_param('i', $companyId); $st->execute();
$occAll = $st->get_result()->fetch_all(MYSQLI_ASSOC);
// Every saved date with its next occurrence, soonest first
$occAllSorted = [];
foreach ($occAll as $o) {
    $next = occasion_date_in_year((string)$o['occasion_date'], (int)substr($today, 0, 4));
    if ($next < $today) $next = occasion_date_in_year((string)$o['occasion_date'], (int)substr($today, 0, 4) + 1);
    $what = $o['occasion_type'] === 'custom' && trim((string)$o['label']) !== '' ? trim((string)$o['label']) : ($occTypes[$o['occasion_type']]['label'] ?? 'Occasion');
    $occAllSorted[] = $o + ['next' => $next, 'what' => $what, 'years' => (int)$o['year_known'] ? (int)substr($next, 0, 4) - (int)substr((string)$o['occasion_date'], 0, 4) : 0];
}
usort($occAllSorted, static fn($a, $b) => strcmp($a['next'], $b['next']));
$occContacts = [];
$st = $conn->prepare("SELECT id, name, phone FROM contacts WHERE company_id=? AND status<>'blocked' ORDER BY name LIMIT 3000");
$st->bind_param('i', $companyId); $st->execute();
$occContacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$occJson = static fn(array $o) => json_encode(['type' => $o['type'], 'title' => $o['title'], 'date' => $o['date'], 'what' => $o['what'],
    'years' => $o['years'], 'name' => $o['name']]);
$occSub = static fn(array $o) => $o['years'] > 0 ? ($o['type'] === 'birthday' ? 'Turns ' . $o['years'] : trim(occasion_ordinal($o['years'])) . ' ' . strtolower($o['what'])) : $o['what'];
$occPill = static fn(array $o) => (int)($occWishes[$o['type']]['auto_send'] ?? 0) ? ['Wish on', 'is-auto'] : ['Wish off', 'is-draft'];

// This company's wishes
$camps = [];
$st = $conn->prepare("SELECT * FROM festival_campaigns WHERE company_id=? ORDER BY festival_date");
$st->bind_param('i', $companyId); $st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $k = ((string)$c['festival_key'] !== '' ? $c['festival_key'] : festival_key(festival_plain_name((string)$c['festival_name']))) . '|' . $c['festival_date'];
    $camps[$k] = $c;
}
$campFor = static fn(array $e) => $camps[$e['key'] . '|' . $e['date']] ?? null;
$editId = (int)($_GET['edit'] ?? 0);

// Header numbers
$audience = 0;
$st = $conn->prepare("SELECT COUNT(*) FROM contacts WHERE company_id=? AND opted_in=1 AND status<>'blocked' AND phone<>''");
$st->bind_param('i', $companyId); $st->execute(); $audience = (int)$st->get_result()->fetch_row()[0];
$scheduled = 0; $nextWish = null;
foreach ($camps as $c) {
    if ((int)$c['auto_send'] === 1 && $c['sent_at'] === null && $c['festival_date'] >= $today) {
        $scheduled++;
        if (!$nextWish || $c['festival_date'] < $nextWish['festival_date']) $nextWish = $c;
    }
}
$templates = festival_templates($conn, $companyId);

$typeLabel = ['national' => 'National', 'holiday' => 'Public holiday', 'observance' => 'Observance', 'special' => 'Special day'];
$sendDate = static fn(array $c) => date('d M', strtotime($c['festival_date'] . ' -' . (int)$c['days_before'] . ' days'));
$daysTo = static fn(string $d) => (int)round((strtotime($d) - strtotime($today)) / 86400);
$url = static fn(array $p) => '?' . http_build_query(array_merge(['d' => $sel, 'v' => $view, 'f' => $filter], $p));
$typeText = static fn(array $e) => $typeLabel[$e['type']] . ($e['region'] === 'tamil' ? ' · Tamil Nadu' : '');
$pickJson = static function (array $e) use ($campFor, $typeText): string {
    $c = $campFor($e);
    return json_encode(['key' => $e['key'], 'name' => festival_plain_name($e['name']), 'date' => $e['date'], 'type' => $typeText($e),
                        'tentative' => $e['tentative'], 'camp' => $c ? (int)$c['id'] : 0]);
};
$status = static function (array $e) use ($campFor, $sendDate): array {
    $c = $campFor($e);
    if ($c && $c['sent_at']) return ['Sent', 'is-sent'];
    if ($c && (int)$c['auto_send']) return ['Sends ' . $sendDate($c), 'is-auto'];
    if ($c) return ['Saved, not on', 'is-draft'];
    return ['Add wish', 'is-add'];
};
// Search data: next 12 months (and the last month)
$searchData = array_map(static fn($e) => json_decode($pickJson($e), true) + ['t' => $e['type'], 'r' => $e['region']], $yearEvents);

$ico = static fn(string $p, int $s = 18) => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$I = [
    'cal' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'week' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>',
    'month' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
    'list' => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
    'occasions' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'prev' => '<path d="m15 18-6-6 6-6"/>', 'next' => '<path d="m9 18 6-6-6-6"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'send' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
];

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">

<div class="db fe">
  <?php if ($flash): ?><div class="fe-toast is-<?php echo $esc($flash[0]); ?>"><?php echo $esc($flash[1]); ?></div><?php endif; ?>
  <?php if ($fixedDates > 0): ?><div class="fe-toast is-ok">Corrected the date of <?php echo $fixedDates; ?> festival<?php echo $fixedDates === 1 ? '' : 's'; ?> from the live calendar (festivals like Diwali and Eid move every year).</div><?php endif; ?>

  <div class="fe-shell">
    <!-- ================= Main board ================= -->
    <section class="fe-main">
      <div class="fe-top">
        <div class="fe-brand"><span class="fe-brand__ic"><?php echo $ico($I['cal'], 18); ?></span><span><b>Festival</b>Calendar</span></div>
        <nav class="fe-views" aria-label="View">
          <?php foreach (['week' => 'Week', 'month' => 'Month', 'list' => 'List', 'occasions' => 'Occasions'] as $v => $lbl): ?>
            <a href="<?php echo $esc($url(['v' => $v])); ?>" class="<?php echo $view === $v ? 'is-on' : ''; ?>" title="<?php echo $lbl; ?> view" aria-label="<?php echo $lbl; ?> view"><?php echo $ico($I[$v], 18); ?><span><?php echo $lbl; ?></span></a>
          <?php endforeach; ?>
        </nav>
        <div class="fe-stats">
          <span title="Opted-in customers who receive wishes"><?php echo $ico($I['users'], 15); ?> <?php echo number_format($audience); ?> customers</span>
          <span class="<?php echo $scheduled ? 'is-on' : ''; ?>" title="<?php echo $nextWish ? 'Next: ' . $esc(festival_plain_name($nextWish['festival_name'])) . ' on ' . $sendDate($nextWish) : 'No wish switched on yet'; ?>"><?php echo $ico($I['send'], 15); ?> <?php echo $scheduled; ?> scheduled</span>
        </div>
      </div>

      <div class="fe-bar">
        <?php if ($view === 'week'):
          $label = date('d M', strtotime($weekStart)) . ' – ' . date('d M Y', strtotime($weekEnd)); ?>
          <a class="fe-arr" href="<?php echo $esc($url(['d' => date('Y-m-d', strtotime($sel . ' -7 days'))])); ?>" aria-label="Previous week"><?php echo $ico($I['prev'], 16); ?></a>
          <h2><?php echo $label; ?></h2>
          <a class="fe-arr" href="<?php echo $esc($url(['d' => date('Y-m-d', strtotime($sel . ' +7 days'))])); ?>" aria-label="Next week"><?php echo $ico($I['next'], 16); ?></a>
        <?php elseif ($view === 'month'): ?>
          <a class="fe-arr" href="<?php echo $esc($url(['d' => date('Y-m-01', strtotime($monthStart . ' -1 month'))])); ?>" aria-label="Previous month"><?php echo $ico($I['prev'], 16); ?></a>
          <h2><?php echo date('F Y', strtotime($monthStart)); ?></h2>
          <a class="fe-arr" href="<?php echo $esc($url(['d' => date('Y-m-01', strtotime($monthStart . ' +1 month'))])); ?>" aria-label="Next month"><?php echo $ico($I['next'], 16); ?></a>
        <?php elseif ($view === 'occasions'): ?>
          <h2>Personal occasions</h2>
        <?php else: ?>
          <h2>Next 60 days</h2>
        <?php endif; ?>
        <a class="fe-todaybtn" href="<?php echo $esc($url(['d' => $today])); ?>">Today</a>
        <div class="fe-filters">
          <?php foreach (['' => 'All', 'national' => 'National', 'holiday' => 'Holidays', 'observance' => 'Observances', 'special' => 'Special days', 'tamil' => 'Tamil Nadu', 'personal' => 'Personal'] as $k => $lbl): ?>
            <a class="fe-chip<?php echo $filter === $k ? ' is-on' : ''; ?>" href="<?php echo $esc($url(['f' => $k])); ?>"><?php if ($k): ?><i class="t-<?php echo $k; ?>"></i><?php endif; ?><?php echo $lbl; ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($view === 'week'): ?>
        <!-- Week board: one column per day, festivals as floating cards -->
        <div class="fe-board">
          <?php for ($i = 0; $i < 7; $i++):
            $d = date('Y-m-d', strtotime($weekStart . " +$i days"));
            $evs = array_values(array_filter($weekEvents, static fn($e) => $e['date'] === $d));
            $dayOcc = $occByDate[$d] ?? []; ?>
            <div class="fe-col<?php echo $d === $today ? ' is-today' : ''; ?><?php echo $d === $sel ? ' is-sel' : ''; ?><?php echo $d < $today ? ' is-past' : ''; ?>">
              <a class="fe-col__h" href="<?php echo $esc($url(['d' => $d])); ?>">
                <span><?php echo date('l', strtotime($d)); ?></span>
                <b><?php echo date('j', strtotime($d)); ?></b>
              </a>
              <div class="fe-col__b">
                <?php if ($d === $today): ?><span class="fe-now" aria-hidden="true"></span><?php endif; ?>
                <?php foreach ($evs as $e): [$stLbl, $stCls] = $status($e); ?>
                  <button type="button" class="fe-card t-<?php echo $e['type']; ?><?php echo $e['region'] === 'tamil' ? ' is-tn' : ''; ?>" data-pick='<?php echo $esc($pickJson($e)); ?>'>
                    <i class="fe-card__bar"></i>
                    <b><?php echo $esc(festival_plain_name($e['name'])); ?></b>
                    <small><?php echo $typeText($e); ?><?php echo $e['tentative'] ? ' · date may change' : ''; ?></small>
                    <span class="fe-pill <?php echo $stCls; ?>"><?php echo $stCls === 'is-add' ? $ico($I['plus'], 12) : ''; ?><?php echo $stLbl; ?></span>
                  </button>
                <?php endforeach; ?>
                <?php foreach ($dayOcc as $o): [$pl, $pc] = $occPill($o); ?>
                  <button type="button" class="fe-card t-personal" data-occ='<?php echo $esc($occJson($o)); ?>'>
                    <i class="fe-card__bar"></i>
                    <b><?php echo $esc($o['title']); ?></b>
                    <small><?php echo $esc($occSub($o)); ?></small>
                    <span class="fe-pill <?php echo $pc; ?>"><?php echo $pl; ?></span>
                  </button>
                <?php endforeach; ?>
                <?php if (!$evs && !$dayOcc): ?><span class="fe-quiet">Nothing on this day</span><?php endif; ?>
              </div>
            </div>
          <?php endfor; ?>
        </div>

      <?php elseif ($view === 'month'): ?>
        <div class="fe-week"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><span><?php echo $d; ?></span><?php endforeach; ?></div>
        <div class="fe-days">
          <?php
          $lead = ((int)date('N', strtotime($monthStart))) - 1;
          for ($i = 0; $i < $lead; $i++) echo '<div class="fe-day is-out"></div>';
          $dim = (int)date('t', strtotime($monthStart));
          for ($dd = 1; $dd <= $dim; $dd++):
            $date = sprintf('%s-%02d', $m, $dd);
            $evs = array_values(array_filter($monthEvents, static fn($e) => $e['date'] === $date));
            $dayOcc = $occByDate[$date] ?? [];
            $room = max(0, 3 - count($evs)); ?>
            <div class="fe-day<?php echo $date === $today ? ' is-today' : ''; ?><?php echo $date < $today ? ' is-past' : ''; ?>">
              <a class="fe-num" href="<?php echo $esc($url(['d' => $date, 'v' => 'week'])); ?>"><?php echo $dd; ?></a>
              <?php foreach (array_slice($evs, 0, 3) as $e): [, $stCls] = $status($e); ?>
                <button type="button" class="fe-ev t-<?php echo $e['type']; ?> <?php echo $stCls; ?>" data-pick='<?php echo $esc($pickJson($e)); ?>' title="<?php echo $esc(festival_plain_name($e['name']) . ' · ' . $typeText($e)); ?>"><span><?php echo $esc(festival_plain_name($e['name'])); ?></span></button>
              <?php endforeach; ?>
              <?php foreach (array_slice($dayOcc, 0, $room) as $o): ?>
                <button type="button" class="fe-ev t-personal" data-occ='<?php echo $esc($occJson($o)); ?>' title="<?php echo $esc($o['title']); ?>"><span><?php echo $esc($o['title']); ?></span></button>
              <?php endforeach; ?>
              <?php $hidden = max(0, count($evs) - 3) + max(0, count($dayOcc) - $room); ?>
              <?php if ($hidden > 0): ?><a class="fe-more" href="<?php echo $esc($url(['d' => $date, 'v' => 'week'])); ?>">+<?php echo $hidden; ?> more</a><?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>

      <?php elseif ($view === 'occasions'): ?>
        <!-- Personal occasions: one wish per type, dates per customer -->
        <div class="oc-types">
          <?php foreach ($occTypes as $type => $t):
            $w = $occWishes[$type];
            $people = count(array_filter($occAll, static fn($o) => $o['occasion_type'] === $type));
            $next = null;
            foreach ($occUpcoming as $o) { if ($o['type'] === $type) { $next = $o; break; } } ?>
            <div class="oc-type t-personal">
              <span class="oc-type__ic"><?php echo $ico($occIco[$type], 20); ?></span>
              <b><?php echo $esc($t['label']); ?></b>
              <small><?php echo $people; ?> customer<?php echo $people === 1 ? '' : 's'; ?><?php echo $next ? ' · next ' . date('d M', strtotime($next['date'])) : ''; ?></small>
              <div class="oc-type__f">
                <span class="fe-pill <?php echo (int)$w['auto_send'] ? 'is-auto' : 'is-draft'; ?>"><?php echo (int)$w['auto_send'] ? 'Sends automatically' : 'Automatic sending off'; ?></span>
                <button type="button" class="oc-link" data-ocw="<?php echo $type; ?>">Edit wish</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="oc-two">
          <form method="POST" class="oc-add">
            <?php echo csrf_field(); ?>
            <h3>Add a date</h3>
            <p>Save a customer's birthday, anniversary or any yearly day you want to wish them on.</p>
            <label class="fe-f">Customer
              <input type="text" list="ocContacts" id="ocContactPick" placeholder="Type a name or number" autocomplete="off" required>
              <input type="hidden" name="contact_id" id="ocContactId">
              <datalist id="ocContacts">
                <?php foreach ($occContacts as $c): ?><option value="<?php echo $esc(trim((string)$c['name']) !== '' ? $c['name'] . ' · ' . $c['phone'] : $c['phone']); ?>" data-id="<?php echo (int)$c['id']; ?>"></option><?php endforeach; ?>
              </datalist>
            </label>
            <div class="oc-row">
              <label class="fe-f">Occasion
                <select name="occasion_type" id="ocType">
                  <?php foreach ($occTypes as $type => $t): ?><option value="<?php echo $type; ?>"><?php echo $esc($t['label']); ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="fe-f">Date
                <input type="date" name="occasion_date" required max="<?php echo date('Y-m-d', strtotime($today . ' +1 year')); ?>">
              </label>
            </div>
            <label class="fe-f" id="ocLabelWrap" hidden>Name of the day
              <input type="text" name="label" maxlength="100" placeholder="e.g. Shop opening day">
            </label>
            <label class="oc-check"><input type="checkbox" name="no_year" value="1"> I don't know the year (only day and month are used)</label>
            <button type="submit" name="add_occasion" class="db-btn db-btn--primary fe-wide">Save date</button>
          </form>

          <div class="oc-list">
            <div class="oc-list__h"><h3>Saved dates</h3><small><?php echo count($occAll); ?> in total · next 60 days shown first</small></div>
            <?php if (!$occAll): ?>
              <p class="fe-quiet">No dates yet. Add your customers' birthdays and anniversaries on the left.</p>
            <?php endif; ?>
            <?php foreach ($occAllSorted as $o): $dt = $daysTo($o['next']); ?>
              <div class="oc-li">
                <span class="fe-date"><b><?php echo date('d', strtotime($o['next'])); ?></b><small><?php echo date('M', strtotime($o['next'])); ?></small></span>
                <span class="oc-li__t"><b><?php echo $esc(trim((string)$o['name']) !== '' ? $o['name'] : $o['phone']); ?></b>
                  <small><?php echo $esc($o['what']); ?><?php echo $o['years'] > 0 ? ' · ' . ($o['occasion_type'] === 'birthday' ? 'turns ' . $o['years'] : trim(occasion_ordinal($o['years'])) . ' year') : ''; ?> · <?php echo $dt === 0 ? 'today' : ($dt === 1 ? 'tomorrow' : 'in ' . $dt . ' days'); ?></small></span>
                <form method="POST" onsubmit="return confirm('Remove this date?');"><?php echo csrf_field(); ?><input type="hidden" name="occasion_id" value="<?php echo (int)$o['id']; ?>"><button name="delete_occasion" class="oc-x" aria-label="Remove">&times;</button></form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="fe-listv">
          <?php foreach ($upcoming as $e): [$stLbl, $stCls] = $status($e); $dt = $daysTo($e['date']); ?>
            <button type="button" class="fe-row t-<?php echo $e['type']; ?>" data-pick='<?php echo $esc($pickJson($e)); ?>'>
              <span class="fe-date"><b><?php echo date('d', strtotime($e['date'])); ?></b><small><?php echo date('M', strtotime($e['date'])); ?></small></span>
              <i class="fe-row__bar"></i>
              <span class="fe-row__t"><b><?php echo $esc(festival_plain_name($e['name'])); ?></b><small><?php echo $typeText($e); ?> · <?php echo $dt === 0 ? 'today' : ($dt === 1 ? 'tomorrow' : 'in ' . $dt . ' days'); ?></small></span>
              <span class="fe-pill <?php echo $stCls; ?>"><?php echo $stLbl; ?></span>
            </button>
          <?php endforeach; ?>
          <?php if (!$upcoming && !($showOcc && $occUpcoming)): ?><p class="fe-quiet">Nothing in the next 60 days for this filter.</p><?php endif; ?>
          <?php if ($showOcc): foreach ($occUpcoming as $o): [$pl, $pc] = $occPill($o); $dt = $daysTo($o['date']); ?>
            <button type="button" class="fe-row t-personal" data-occ='<?php echo $esc($occJson($o)); ?>'>
              <span class="fe-date"><b><?php echo date('d', strtotime($o['date'])); ?></b><small><?php echo date('M', strtotime($o['date'])); ?></small></span>
              <i class="fe-row__bar"></i>
              <span class="fe-row__t"><b><?php echo $esc($o['title']); ?></b><small><?php echo $esc($occSub($o)); ?> · <?php echo $dt === 0 ? 'today' : ($dt === 1 ? 'tomorrow' : 'in ' . $dt . ' days'); ?></small></span>
              <span class="fe-pill <?php echo $pc; ?>"><?php echo $pl; ?></span>
            </button>
          <?php endforeach; endif; ?>
        </div>
      <?php endif; ?>

      <div class="fe-legend">
        <span><i class="t-national"></i>National</span><span><i class="t-holiday"></i>Public holiday</span><span><i class="t-observance"></i>Observance</span><span><i class="t-special"></i>Special day</span><span><i class="t-tamil"></i>Tamil Nadu</span><span><i class="t-personal"></i>Personal</span>
      </div>
    </section>

    <!-- ================= Side panel ================= -->
    <aside class="fe-side">
      <div class="fe-search">
        <?php echo $ico($I['search'], 17); ?>
        <input type="search" id="feSearch" placeholder="Search festivals" autocomplete="off" aria-label="Search festivals">
        <div class="fe-results" id="feResults" hidden></div>
      </div>

      <div class="fe-mini">
        <div class="fe-mini__h">
          <b><?php echo date('F Y', strtotime($monthStart)); ?></b>
          <span>
            <a href="<?php echo $esc($url(['d' => date('Y-m-01', strtotime($monthStart . ' -1 month'))])); ?>" aria-label="Previous month"><?php echo $ico($I['prev'], 16); ?></a>
            <a href="<?php echo $esc($url(['d' => date('Y-m-01', strtotime($monthStart . ' +1 month'))])); ?>" aria-label="Next month"><?php echo $ico($I['next'], 16); ?></a>
          </span>
        </div>
        <div class="fe-mini__w"><?php foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $d): ?><span><?php echo $d; ?></span><?php endforeach; ?></div>
        <div class="fe-mini__d">
          <?php
          $gridStart = date('Y-m-d', strtotime($monthStart . ' -' . ((int)date('N', strtotime($monthStart)) - 1) . ' days'));
          for ($i = 0; $i < 42; $i++):
            $d = date('Y-m-d', strtotime($gridStart . " +$i days"));
            if ($i >= 35 && substr($d, 0, 7) !== $m) break;
            $evs = $byDate[$d] ?? [];
            $occD = $occByDate[$d] ?? []; ?>
            <a href="<?php echo $esc($url(['d' => $d])); ?>" class="<?php echo substr($d, 0, 7) !== $m ? 'is-out' : ''; ?><?php echo $d === $sel ? ' is-sel' : ''; ?><?php echo $d === $today ? ' is-today' : ''; ?>" title="<?php echo $esc(implode(', ', array_merge(array_map(static fn($e) => festival_plain_name($e['name']), $evs), array_map(static fn($o) => $o['title'], $occD)))); ?>">
              <?php echo (int)substr($d, 8, 2); ?>
              <?php if ($evs || $occD): ?><em><?php foreach (array_slice($evs, 0, $occD ? 2 : 3) as $e): ?><i class="d-<?php echo $e['region'] === 'tamil' ? 'tamil' : $e['type']; ?>"></i><?php endforeach; ?><?php if ($occD): ?><i class="d-personal"></i><?php endif; ?></em><?php endif; ?>
            </a>
          <?php endfor; ?>
        </div>
      </div>

      <div class="fe-daycard">
        <?php $selEvs = $byDate[$sel] ?? []; $selOcc = $occByDate[$sel] ?? []; ?>
        <div class="fe-daycard__h"><b><?php echo date('l, d M', strtotime($sel)); ?></b><small><?php echo $sel === $today ? 'Today' : ($daysTo($sel) > 0 ? 'In ' . $daysTo($sel) . ' days' : abs($daysTo($sel)) . ' days ago'); ?></small></div>
        <?php if ($selEvs || $selOcc): ?>
          <div class="fe-daylist">
            <?php foreach ($selEvs as $e): [$stLbl, $stCls] = $status($e); ?>
              <button type="button" class="fe-dayitem t-<?php echo $e['type']; ?>" data-pick='<?php echo $esc($pickJson($e)); ?>'>
                <i></i><span><b><?php echo $esc(festival_plain_name($e['name'])); ?></b><small><?php echo $typeText($e); ?></small></span>
                <span class="fe-pill <?php echo $stCls; ?>"><?php echo $stLbl; ?></span>
              </button>
            <?php endforeach; ?>
            <?php foreach ($selOcc as $o): [$pl, $pc] = $occPill($o); ?>
              <button type="button" class="fe-dayitem t-personal" data-occ='<?php echo $esc($occJson($o)); ?>'>
                <i></i><span><b><?php echo $esc($o['title']); ?></b><small><?php echo $esc($occSub($o)); ?></small></span>
                <span class="fe-pill <?php echo $pc; ?>"><?php echo $pl; ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="fe-none">Nothing on this day.</p>
          <div class="fe-coming"><small>Coming up</small>
            <?php foreach (array_slice($upcoming, 0, 4) as $e): ?>
              <button type="button" class="fe-dayitem t-<?php echo $e['type']; ?>" data-pick='<?php echo $esc($pickJson($e)); ?>'>
                <i></i><span><b><?php echo $esc(festival_plain_name($e['name'])); ?></b><small><?php echo date('d M', strtotime($e['date'])); ?> · <?php echo $typeText($e); ?></small></span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!$templates): ?>
          <div class="fe-tip"><b>Reach every customer</b>Create an approved template in <a href="templates.php">Templates</a>, e.g. <code>Hi {{1}}, wishing you a very happy {{2}}! – {{3}}</code>. Without one, wishes only reach customers who chatted in the last 24 hours.</div>
        <?php else: ?>
          <div class="fe-tip"><b><?php echo count($templates); ?> approved template<?php echo count($templates) === 1 ? '' : 's'; ?></b>Choose one in a festival's settings so the wish reaches all <?php echo number_format($audience); ?> customers.</div>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>

<!-- Personal occasion wish editor (one form per occasion type) -->
<div class="fe-ov db fe" id="ocOv" hidden>
  <div class="fe-panel" role="dialog" aria-modal="true" aria-labelledby="ocPanelTitle">
    <div class="fe-panel__h">
      <div><small id="ocPanelType">Personal occasion</small><h3 id="ocPanelTitle"></h3><p id="ocPanelDate"></p></div>
      <button type="button" class="fe-x" data-close aria-label="Close">&times;</button>
    </div>
    <?php foreach ($occTypes as $type => $t): $w = $occWishes[$type]; $tplSel = (string)($w['template_name'] ?? ''); ?>
      <div class="fe-panel__b oc-edit" data-octype="<?php echo $type; ?>" hidden>
        <form method="POST" class="fe-form">
          <?php echo csrf_field(); ?><input type="hidden" name="occasion_type" value="<?php echo $type; ?>">
          <p class="fe-p">This wish goes to every customer with a saved <?php echo $esc(strtolower($t['label'])); ?>, once a year.</p>
          <label class="fe-switch"><input type="checkbox" name="auto_send" value="1" <?php echo (int)$w['auto_send'] ? 'checked' : ''; ?>><span><b>Send automatically</b><small>Goes out between 9 am and 9 pm on the chosen day.</small></span></label>
          <label class="fe-f">When to send
            <select name="days_before">
              <?php foreach ([0 => 'On the day', 1 => '1 day before', 2 => '2 days before', 7 => '1 week before'] as $v => $lbl): ?>
                <option value="<?php echo $v; ?>"<?php echo (int)$w['days_before'] === $v ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="fe-f">Send as
            <select name="template_name">
              <option value="">Message below (only customers who chatted in the last 24 h)</option>
              <?php foreach ($templates as $tp): ?>
                <option value="<?php echo $esc($tp['name']); ?>"<?php echo $tplSel === $tp['name'] ? ' selected' : ''; ?>>Template: <?php echo $esc($tp['name']); ?> (reaches everyone)</option>
              <?php endforeach; ?>
            </select>
            <small class="fe-hint">Template values: {{1}} customer name, {{2}} occasion, {{3}} business name, {{4}} which year (e.g. 5th).</small>
          </label>
          <label class="fe-f">Message
            <textarea name="message" rows="7"><?php echo $esc($w['message']); ?></textarea>
            <small class="fe-hint">You can use {{first_name}}, {{name}}, {{occasion}}, {{years}} (e.g. "5th "), {{business_name}} and {{phone}}.</small>
          </label>
          <button type="submit" name="save_occasion_wish" class="db-btn db-btn--primary fe-wide">Save wish</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Festival panel -->
<div class="fe-ov db fe" id="feOv" hidden>
  <div class="fe-panel" role="dialog" aria-modal="true" aria-labelledby="fePanelTitle">
    <div class="fe-panel__h">
      <div><small id="fePanelType"></small><h3 id="fePanelTitle"></h3><p id="fePanelDate"></p></div>
      <button type="button" class="fe-x" data-close aria-label="Close">&times;</button>
    </div>

    <!-- No wish yet -->
    <form method="POST" id="feAdd" class="fe-panel__b" hidden>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="festival_key" id="feAddKey"><input type="hidden" name="festival_date" id="feAddDate">
      <p class="fe-p">No wish for this festival yet. Create one with a ready message, then review it before switching on automatic sending.</p>
      <button type="submit" name="add_festival" class="db-btn db-btn--primary fe-wide">Create festival wish</button>
    </form>

    <!-- Edit wishes (one form per saved wish) -->
    <?php foreach ($camps as $c): $tplSel = (string)($c['template_name'] ?? ''); $sent = $c['sent_at'] !== null; ?>
      <div class="fe-panel__b fe-edit" data-camp="<?php echo (int)$c['id']; ?>" hidden>
        <?php if ($sent): ?>
          <div class="fe-sent">Sent on <?php echo $esc(date('d M Y, h:i A', strtotime((string)$c['sent_at']))); ?> · <?php echo (int)$c['queued_count']; ?> queued<?php echo (int)$c['skipped_count'] ? ', ' . (int)$c['skipped_count'] . ' skipped' : ''; ?>. Next year's wish is created automatically.</div>
        <?php endif; ?>
        <form method="POST" class="fe-form">
          <?php echo csrf_field(); ?><input type="hidden" name="festival_id" value="<?php echo (int)$c['id']; ?>">
          <label class="fe-switch"><input type="checkbox" name="auto_send" value="1" <?php echo (int)$c['auto_send'] ? 'checked' : ''; ?> <?php echo $sent ? 'disabled' : ''; ?>><span><b>Send automatically</b><small>Goes out between 9 am and 9 pm on the chosen day.</small></span></label>
          <label class="fe-f">When to send
            <select name="days_before" <?php echo $sent ? 'disabled' : ''; ?>>
              <?php foreach ([0 => 'On the festival day', 1 => '1 day before', 2 => '2 days before', 3 => '3 days before', 7 => '1 week before'] as $v => $lbl): ?>
                <option value="<?php echo $v; ?>"<?php echo (int)$c['days_before'] === $v ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="fe-f">Send as
            <select name="template_name" <?php echo $sent ? 'disabled' : ''; ?>>
              <option value="">Message below (only customers who chatted in the last 24 h)</option>
              <?php foreach ($templates as $t): ?>
                <option value="<?php echo $esc($t['name']); ?>"<?php echo $tplSel === $t['name'] ? ' selected' : ''; ?>>Template: <?php echo $esc($t['name']); ?> (reaches everyone)</option>
              <?php endforeach; ?>
            </select>
            <small class="fe-hint">Template values: {{1}} customer name, {{2}} festival, {{3}} business name, {{4}} business phone.</small>
          </label>
          <label class="fe-f">Message
            <textarea name="message" rows="7" <?php echo $sent ? 'disabled' : ''; ?>><?php echo $esc($c['message']); ?></textarea>
            <small class="fe-hint">You can use {{first_name}}, {{name}}, {{festival}}, {{business_name}} and {{phone}}.</small>
          </label>
          <?php if (!$sent): ?><button type="submit" name="save_campaign" class="db-btn db-btn--primary fe-wide">Save wish</button><?php endif; ?>
        </form>
        <?php if (!$sent): ?>
          <div class="fe-acts">
            <form method="POST" onsubmit="return confirm('Send this festival wish to your customers now?');"><?php echo csrf_field(); ?><input type="hidden" name="festival_id" value="<?php echo (int)$c['id']; ?>"><button name="send_now" class="db-btn">Send now</button></form>
            <form method="POST" onsubmit="return confirm('Remove this festival wish?');"><?php echo csrf_field(); ?><input type="hidden" name="festival_id" value="<?php echo (int)$c['id']; ?>"><button name="delete_campaign" class="fe-del">Remove</button></form>
          </div>
        <?php endif; ?>
        <?php if (!empty($c['last_note'])): ?><p class="fe-hint"><?php echo $esc($c['last_note']); ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<style>
/* Festival Calendar: workspace + teal side panel. Flat colours, no gradients, no divider lines. */
.fe{--teal:#128C7E;--teal-d:#0B6B5E;--teal-dd:#08574D;--surface:#F4F7F6;--card-shadow:0 16px 30px -20px rgba(15,38,34,.42),0 2px 6px -2px rgba(15,38,34,.08)}
.fe .db-btn--primary{background:var(--teal);box-shadow:none}.fe .db-btn--primary:hover{background:var(--teal-d);transform:none}
.fe-toast{padding:12px 16px;border-radius:12px;font-weight:600;font-size:14px;margin-bottom:14px}
.fe-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.fe-toast.is-err{background:#FDE4E1;color:#c0372b}.fe-toast.is-warn{background:#FFF4DE;color:#8a5300}

/* type colours */
.t-national{--t:#2563EB}.t-holiday{--t:#128C7E}.t-observance{--t:#E58A00}.t-special{--t:#7C3AED}.t-tamil{--t:#C2410C}.t-personal{--t:#DB2777}
i.t-national,i.t-holiday,i.t-observance,i.t-special,i.t-tamil,i.t-personal{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--t)}

/* workspace */
.fe-shell{display:grid;grid-template-columns:minmax(0,1fr) 372px;gap:22px;padding:18px;background:#fff;border-radius:30px;box-shadow:0 30px 60px -40px rgba(15,38,34,.45)}
.fe-main{min-width:0;padding:8px 6px 6px 10px;display:flex;flex-direction:column;gap:16px}
.fe-top{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.fe-brand{display:flex;align-items:center;gap:10px;font-size:17px;color:var(--ink)}
.fe-brand b{font-weight:800}
.fe-brand__ic{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:#E6FCF2;color:var(--teal)}
.fe-views{display:flex;gap:4px;margin-left:auto;padding:4px;border-radius:14px;background:var(--surface)}
.fe-views a{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 13px;border-radius:10px;color:#7A8A85;text-decoration:none;font-size:13px;font-weight:700}
.fe-views a:hover{color:var(--teal-d)}
.fe-views a.is-on{background:#fff;color:var(--teal-d);box-shadow:0 6px 14px -8px rgba(15,38,34,.35)}
.fe-stats{display:flex;gap:8px}
.fe-stats span{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 12px;border-radius:11px;background:var(--surface);font-size:12.5px;font-weight:700;color:var(--tx)}
.fe-stats span.is-on{background:#E6FCF2;color:var(--teal-d)}

.fe-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fe-bar h2{margin:0 4px;font-size:19px;font-weight:800;letter-spacing:-.01em;color:var(--ink)}
.fe-arr{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--surface);color:var(--ink)}
.fe-arr:hover{background:#E6FCF2;color:var(--teal-d)}
.fe-todaybtn{height:34px;padding:0 12px;border-radius:10px;display:inline-flex;align-items:center;background:#E6FCF2;color:var(--teal-d);font-size:12.5px;font-weight:800;text-decoration:none}
.fe-filters{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
.fe-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:99px;background:var(--surface);color:var(--tx);font-size:12.5px;font-weight:700;text-decoration:none}
.fe-chip.is-on{background:var(--teal);color:#fff}.fe-chip.is-on i{box-shadow:0 0 0 2px #fff}

/* week board */
.fe-board{display:grid;grid-template-columns:repeat(7,minmax(96px,1fr));gap:8px;overflow-x:auto;padding-bottom:4px}
.fe-col{display:flex;flex-direction:column;min-height:440px;border-radius:18px;background:var(--surface)}
.fe-col.is-past{opacity:.6}
.fe-col__h{display:flex;flex-direction:column;align-items:center;gap:4px;padding:14px 8px 12px;text-decoration:none;color:#7A8A85}
.fe-col__h span{font-size:12.5px;font-weight:600}
.fe-col__h b{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-size:15px;color:var(--ink)}
.fe-col.is-sel .fe-col__h span{color:var(--ink)}
.fe-col.is-today .fe-col__h b{background:var(--teal);color:#fff;box-shadow:0 8px 16px -8px rgba(18,140,126,.8)}
.fe-col.is-sel:not(.is-today) .fe-col__h b{box-shadow:inset 0 0 0 2px var(--teal);color:var(--teal-d)}
.fe-col__b{position:relative;flex:1;display:flex;flex-direction:column;gap:12px;padding:10px 9px 12px}
.fe-now{position:absolute;left:0;right:0;top:0;height:2px;background:var(--teal);border-radius:2px}
.fe-now::before{content:'';position:absolute;left:-1px;top:-4px;width:10px;height:10px;border-radius:50%;background:var(--teal)}
.fe-card{display:flex;flex-direction:column;align-items:flex-start;gap:6px;width:100%;padding:13px 12px 12px;border:0;border-radius:16px;background:#fff;box-shadow:var(--card-shadow);text-align:left;cursor:pointer;font:inherit;color:inherit;transition:transform .18s,box-shadow .18s}
.fe-card:hover{transform:translateY(-2px);box-shadow:0 22px 34px -20px rgba(15,38,34,.5),0 2px 6px -2px rgba(15,38,34,.1)}
.fe-card__bar{width:22px;height:4px;border-radius:4px;background:var(--t)}
.fe-card b{font-size:13.5px;font-weight:700;line-height:1.3;color:var(--ink);overflow-wrap:break-word;hyphens:auto;-webkit-hyphens:auto}
.fe-card .fe-pill{white-space:normal;max-width:100%;line-height:1.25}
.fe-card small{font-size:11.5px;color:var(--mu);line-height:1.35}
.fe-card.is-tn small{color:#9A3412}
.fe-quiet{margin:auto 0;text-align:center;font-size:12px;color:#A3B1AC}

/* status pills */
.fe-pill{display:inline-flex;align-items:center;gap:4px;margin-top:2px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.fe-pill.is-add{background:#F1F5F4;color:var(--teal-d)}
.fe-pill.is-auto{background:var(--teal);color:#fff}
.fe-pill.is-draft{background:#FFF4DE;color:#8a5300}
.fe-pill.is-sent{background:#EEF2F1;color:#5F6F6A}

/* month view */
.fe-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
.fe-week span{font-size:11.5px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.05em;padding:0 8px}
.fe-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
.fe-day{min-height:104px;padding:8px;border-radius:14px;background:var(--surface);display:flex;flex-direction:column;gap:5px;min-width:0}
.fe-day.is-out{background:transparent}.fe-day.is-past{opacity:.55}
.fe-day.is-today{background:#E6FCF2;box-shadow:inset 0 0 0 2px var(--teal)}
.fe-num{font-size:12.5px;font-weight:700;color:var(--tx);text-decoration:none;align-self:flex-start}
.fe-ev{display:flex;align-items:center;width:100%;padding:5px 7px;border:0;border-radius:9px;background:#fff;box-shadow:0 6px 12px -9px rgba(15,38,34,.45);color:var(--ink);font:600 11.5px/1.3 Inter,system-ui,sans-serif;text-align:left;cursor:pointer;min-width:0;gap:6px}
.fe-ev::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--t);flex:none}
.fe-ev.is-auto::before{background:#fff}
.fe-ev span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fe-ev.is-auto{background:var(--t);color:#fff}
.fe-more{font-size:11px;font-weight:700;color:var(--mu);padding-left:4px;text-decoration:none}

/* list view */
.fe-listv{display:flex;flex-direction:column;gap:10px}
.fe-row{display:flex;align-items:center;gap:14px;width:100%;padding:12px 14px;border:0;border-radius:16px;background:#fff;box-shadow:var(--card-shadow);text-align:left;cursor:pointer;font:inherit;color:inherit}
.fe-row:hover{transform:translateY(-1px)}
.fe-date{width:46px;height:48px;flex:none;border-radius:12px;background:var(--surface);display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1}
.fe-date b{font-size:17px;color:var(--ink)}.fe-date small{font-size:10.5px;font-weight:700;color:var(--mu);text-transform:uppercase}
.fe-row__bar{width:10px;height:10px;border-radius:50%;background:var(--t);flex:none}
.fe-row__t{flex:1;min-width:0}
.fe-row__t b{display:block;font-size:14px;color:var(--ink)}
.fe-row__t small{display:block;font-size:12px;color:var(--mu)}

.fe-legend{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px;color:var(--tx)}
.fe-legend span{display:inline-flex;align-items:center;gap:6px}

/* side panel */
.fe-side{display:flex;flex-direction:column;gap:18px;padding:18px;border-radius:26px;background:var(--teal);color:#fff;min-width:0}
.fe-search{position:relative;display:flex;align-items:center;gap:10px;height:50px;padding:0 16px;border-radius:16px;background:var(--teal-d)}
.fe-search svg{color:rgba(255,255,255,.85);flex:none}
.fe-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:#fff;font:500 14px Inter,system-ui,sans-serif}
.fe-search input::placeholder{color:rgba(255,255,255,.7)}
.fe-search:focus-within{box-shadow:0 0 0 2px rgba(255,255,255,.55)}
.fe-results{position:absolute;left:0;right:0;top:56px;z-index:30;padding:6px;border-radius:16px;background:#fff;box-shadow:0 22px 44px -20px rgba(15,38,34,.6);max-height:340px;overflow-y:auto}
.fe-results button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border:0;border-radius:11px;background:none;text-align:left;cursor:pointer;font:inherit;color:var(--ink)}
.fe-results button:hover,.fe-results button.is-act{background:#EEFBF4}
.fe-results i{width:9px;height:9px;border-radius:50%;background:var(--t);flex:none}
.fe-results b{display:block;font-size:13.5px}.fe-results small{display:block;font-size:11.5px;color:var(--mu)}
.fe-results p{margin:0;padding:12px;font-size:13px;color:var(--mu)}

.fe-mini{padding:4px 6px 0}
.fe-mini__h{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.fe-mini__h b{font-size:17px;font-weight:700}
.fe-mini__h span{display:flex;gap:6px}
.fe-mini__h a{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;color:#fff}
.fe-mini__h a:hover{background:var(--teal-d)}
.fe-mini__w,.fe-mini__d{display:grid;grid-template-columns:repeat(7,1fr);gap:4px 2px;text-align:center}
.fe-mini__w span{font-size:12px;font-weight:600;color:rgba(255,255,255,.75);padding-bottom:6px}
.fe-mini__d a{position:relative;height:40px;display:flex;align-items:center;justify-content:center;border-radius:50%;width:40px;margin:0 auto;font-size:14px;font-weight:600;color:#fff;text-decoration:none;transition:background .15s}
.fe-mini__d a:hover{background:var(--teal-d)}
.fe-mini__d a.is-out{color:rgba(255,255,255,.38)}
.fe-mini__d a.is-today{box-shadow:inset 0 0 0 2px rgba(255,255,255,.75)}
.fe-mini__d a.is-sel{background:#fff;color:var(--teal-d);box-shadow:0 10px 20px -10px rgba(0,0,0,.45)}
.fe-mini__d em{position:absolute;left:0;right:0;bottom:3px;display:flex;justify-content:center;gap:2px}
.fe-mini__d em i{width:4px;height:4px;border-radius:50%;background:#fff}
.fe-mini__d em i.d-observance{background:#FFD27A}.fe-mini__d em i.d-national{background:#A9C8FF}.fe-mini__d em i.d-special{background:#E2CCFF}.fe-mini__d em i.d-tamil{background:#FFB59A}.fe-mini__d em i.d-personal{background:#FFC2DA}
.fe-mini__d a.is-sel em i{background:var(--teal)}

.fe-daycard{flex:1;display:flex;flex-direction:column;gap:12px;padding:20px;border-radius:24px;background:#fff;color:var(--ink);box-shadow:0 18px 40px -26px rgba(0,0,0,.5)}
.fe-daycard__h b{display:block;font-size:16.5px;font-weight:800}
.fe-daycard__h small{display:block;margin-top:2px;font-size:12.5px;color:var(--mu)}
.fe-daylist,.fe-coming{display:flex;flex-direction:column;gap:8px}
.fe-coming > small{font-size:11.5px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.06em}
.fe-dayitem{display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:14px;background:var(--surface);text-align:left;cursor:pointer;font:inherit;color:inherit}
.fe-dayitem:hover{background:#EEFBF4}
.fe-dayitem > i{width:9px;height:9px;border-radius:50%;background:var(--t);flex:none}
.fe-dayitem > span:not(.fe-pill){flex:1;min-width:0}
.fe-dayitem b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fe-dayitem small{display:block;font-size:11.5px;color:var(--mu)}
.fe-none{margin:0;font-size:13px;color:var(--mu)}
.fe-tip{margin-top:auto;padding:12px 14px;border-radius:14px;background:#F6FAF8;font-size:12.5px;color:var(--tx);line-height:1.5}
.fe-tip b{display:block;margin-bottom:2px;color:var(--ink)}
.fe-tip a{color:var(--teal-d);font-weight:700}
.fe-tip code{padding:1px 6px;border-radius:5px;background:#fff;font-size:11.5px;color:var(--teal-d)}

/* festival panel (editor) */
.fe-ov{position:fixed;inset:0;z-index:2000;background:rgba(15,38,34,.4);display:flex;justify-content:flex-end}
.fe-ov[hidden]{display:none}
.fe-panel{width:420px;max-width:100%;height:100%;background:#fff;display:flex;flex-direction:column;overflow:hidden;box-shadow:-20px 0 50px -24px rgba(15,38,34,.6)}
.fe-panel__h{display:flex;align-items:flex-start;gap:12px;padding:20px 20px 12px}
.fe-panel__h > div{flex:1}
.fe-panel__h small{font-size:11.5px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.06em}
.fe-panel__h h3{margin:2px 0 2px;font-size:20px;font-weight:800;color:var(--ink)}
.fe-panel__h p{margin:0;font-size:13px;color:var(--mu)}
.fe-x{width:36px;height:36px;border:0;border-radius:10px;background:#F3F7F5;font-size:22px;line-height:1;color:var(--tx);cursor:pointer}
.fe-panel__b{flex:1;overflow-y:auto;padding:4px 20px 20px;display:flex;flex-direction:column;gap:14px}
.fe-panel__b[hidden]{display:none}
.fe-form{display:flex;flex-direction:column;gap:14px}
.fe-p{margin:0;font-size:13.5px;color:var(--tx);line-height:1.55}
.fe-f{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700;color:var(--tx)}
.fe-f select,.fe-f textarea{width:100%;padding:9px 12px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;font:500 13.5px/1.5 Inter,system-ui,sans-serif;color:var(--ink)}
.fe-f select{height:42px;padding-top:0;padding-bottom:0}
.fe-f select:focus,.fe-f textarea:focus{outline:0;border-color:var(--teal);box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.fe-hint{display:block;font-size:11.5px;font-weight:500;color:var(--mu);line-height:1.45;margin:0}
.fe-switch{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border-radius:12px;background:#EEFBF4;cursor:pointer}
.fe-switch input{appearance:none;-webkit-appearance:none;flex:none;width:38px;height:22px;margin:1px 0 0;border-radius:99px;background:#CBD8D3;position:relative;cursor:pointer;transition:background .2s}
.fe-switch input::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s}
.fe-switch input:checked{background:var(--teal)}.fe-switch input:checked::after{transform:translateX(16px)}
.fe-switch b{display:block;font-size:13.5px;color:var(--ink)}.fe-switch small{display:block;margin-top:2px;font-size:12px;color:var(--mu)}
.fe-wide{width:100%;justify-content:center}
.fe-acts{display:flex;align-items:center;justify-content:space-between;gap:10px}
.fe-del{border:0;background:none;color:#c0372b;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
.fe-sent{padding:10px 12px;border-radius:10px;background:#EEF2F1;font-size:12.5px;color:var(--tx);line-height:1.5}

@media (max-width:900px){.fe-board{grid-template-columns:repeat(7,minmax(126px,1fr))}}
/* personal occasions view */
.oc-types{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.oc-type{display:flex;flex-direction:column;gap:6px;padding:16px;border-radius:18px;background:#fff;box-shadow:var(--card-shadow)}
.oc-type__ic{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#FDECF4;color:#DB2777;margin-bottom:4px}
.oc-type b{font-size:14.5px;color:var(--ink)}
.oc-type small{font-size:12px;color:var(--mu)}
.oc-type__f{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:6px}
.oc-type__f .fe-pill{margin:0}
.oc-link{border:0;background:none;padding:0;color:var(--teal-d);font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.oc-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:14px;align-items:start}
.oc-add,.oc-list{padding:18px;border-radius:18px;background:var(--surface);display:flex;flex-direction:column;gap:12px}
.oc-add h3,.oc-list h3{margin:0;font-size:16px;font-weight:800;color:var(--ink)}
.oc-add > p{margin:-6px 0 0;font-size:12.5px;color:var(--mu);line-height:1.5}
.oc-add .fe-f input{height:42px;padding:0 12px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;font:500 13.5px Inter,system-ui,sans-serif;color:var(--ink)}
.oc-add .fe-f input:focus{outline:0;border-color:var(--teal);box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.oc-add .fe-f[hidden]{display:none}
.oc-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.oc-check{display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--tx);cursor:pointer}
.oc-check input{accent-color:#128C7E}
.oc-list__h{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
.oc-list__h small{font-size:12px;color:var(--mu)}
.oc-li{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:14px;background:#fff}
.oc-li .fe-date{background:#FDECF4}
.oc-li__t{flex:1;min-width:0}
.oc-li__t b{display:block;font-size:13.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.oc-li__t small{display:block;font-size:12px;color:var(--mu)}
.oc-x{width:30px;height:30px;border:0;border-radius:9px;background:#F3F7F5;color:#9AA8A3;font-size:18px;line-height:1;cursor:pointer}
.oc-x:hover{background:#FDE4E1;color:#c0372b}
@media (max-width:1240px){.oc-types{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.oc-types,.oc-two,.oc-row{grid-template-columns:1fr}}
@media (max-width:1240px){.fe-shell{grid-template-columns:1fr}.fe-side{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start}.fe-search{grid-column:1/-1}}
@media (max-width:760px){.fe-shell{padding:12px;border-radius:22px}.fe-side{display:flex}.fe-views a span{display:none}.fe-filters{margin-left:0}.fe-day{min-height:64px;padding:5px}.fe-ev span{display:none}.fe-ev{min-height:8px;padding:3px}}
</style>

<script>
(function () {
  var ov = document.getElementById('feOv');
  var add = document.getElementById('feAdd');
  var DATA = <?php echo json_encode($searchData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  /* Festival panel */
  function open(d) {
    if (!ov) return;
    document.getElementById('fePanelType').textContent = d.type + (d.tentative ? ' · date may change' : '');
    document.getElementById('fePanelTitle').textContent = d.name;
    document.getElementById('fePanelDate').textContent = new Date(d.date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    ov.querySelectorAll('.fe-edit').forEach(function (e) { e.hidden = +e.getAttribute('data-camp') !== d.camp; });
    add.hidden = !!d.camp;
    if (!d.camp) { document.getElementById('feAddKey').value = d.key; document.getElementById('feAddDate').value = d.date; }
    ov.hidden = false;
  }
  function close() { if (ov) ov.hidden = true; }
  document.querySelectorAll('[data-pick]').forEach(function (b) {
    b.addEventListener('click', function () { try { open(JSON.parse(b.getAttribute('data-pick'))); } catch (e) {} });
  });
  if (ov) ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('[data-close]')) close(); });

  /* Search (next 12 months) */
  var input = document.getElementById('feSearch'), box = document.getElementById('feResults'), hits = [], act = -1;
  function fmt(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function cls(h) { return 't-' + (h.r === 'tamil' ? 'tamil' : h.t); }
  function render() {
    var q = input.value.trim().toLowerCase();
    if (q.length < 2) { box.hidden = true; return; }
    var today = '<?php echo $today; ?>';
    hits = DATA.filter(function (h) { return h.name.toLowerCase().indexOf(q) !== -1 && h.date >= today; }).slice(0, 8);
    act = -1;
    box.innerHTML = hits.length ? '' : '<p>No festival found in the next 12 months.</p>';
    hits.forEach(function (h, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = cls(h);
      b.innerHTML = '<i></i><span><b></b><small></small></span>';
      b.querySelector('b').textContent = h.name;
      b.querySelector('small').textContent = fmt(h.date) + ' · ' + h.type;
      b.addEventListener('click', function () { box.hidden = true; open(h); });
      box.appendChild(b);
    });
    box.hidden = false;
  }
  if (input) {
    input.addEventListener('input', render);
    input.addEventListener('keydown', function (e) {
      var items = box.querySelectorAll('button');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        act = Math.max(0, Math.min(items.length - 1, act + (e.key === 'ArrowDown' ? 1 : -1)));
        items.forEach(function (it, i) { it.classList.toggle('is-act', i === act); });
      } else if (e.key === 'Enter' && items.length) {
        e.preventDefault(); (items[act >= 0 ? act : 0]).click();
      } else if (e.key === 'Escape') { box.hidden = true; }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.fe-search')) box.hidden = true; });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  /* Narrow screens: scroll the week board to the selected day */
  var board = document.querySelector('.fe-board'), selCol = board && board.querySelector('.fe-col.is-sel');
  if (board && selCol && board.scrollWidth > board.clientWidth) board.scrollLeft = Math.max(0, selCol.offsetLeft - board.offsetLeft - 12);

  /* Personal occasions: open a type's wish editor */
  var ocOv = document.getElementById('ocOv');
  var OCC_LABELS = <?php echo json_encode(array_map(static fn($t) => $t['label'], $occTypes)); ?>;
  function openOcc(type, title, sub) {
    if (!ocOv) return;
    ocOv.querySelectorAll('.oc-edit').forEach(function (e) { e.hidden = e.getAttribute('data-octype') !== type; });
    document.getElementById('ocPanelType').textContent = title ? 'Personal occasion' : 'Wish for every customer';
    document.getElementById('ocPanelTitle').textContent = title || (OCC_LABELS[type] + ' wish');
    document.getElementById('ocPanelDate').textContent = sub || '';
    ocOv.hidden = false;
  }
  document.querySelectorAll('[data-ocw]').forEach(function (b) {
    b.addEventListener('click', function () { openOcc(b.getAttribute('data-ocw')); });
  });
  document.querySelectorAll('[data-occ]').forEach(function (b) {
    b.addEventListener('click', function () {
      try {
        var o = JSON.parse(b.getAttribute('data-occ'));
        var when = new Date(o.date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        openOcc(o.type, o.title, when + ' · ' + OCC_LABELS[o.type] + ' wish');
      } catch (e) {}
    });
  });
  if (ocOv) ocOv.addEventListener('click', function (e) { if (e.target === ocOv || e.target.closest('[data-close]')) ocOv.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ocOv) ocOv.hidden = true; });

  /* Add-a-date form: pick a customer from the list, show "Name of the day" for Other day */
  var pick = document.getElementById('ocContactPick'), pickId = document.getElementById('ocContactId'), list = document.getElementById('ocContacts');
  if (pick && pickId && list) {
    var sync = function () {
      var opt = Array.prototype.find.call(list.options, function (o) { return o.value === pick.value; });
      pickId.value = opt ? opt.getAttribute('data-id') : '';
      pick.setCustomValidity(opt ? '' : 'Choose a customer from the list');
    };
    pick.addEventListener('input', sync); pick.addEventListener('change', sync);
  }
  var ocType = document.getElementById('ocType'), labelWrap = document.getElementById('ocLabelWrap');
  if (ocType && labelWrap) {
    var tog = function () { labelWrap.hidden = ocType.value !== 'custom'; labelWrap.querySelector('input').required = ocType.value === 'custom'; };
    ocType.addEventListener('change', tog); tog();
  }

  /* After saving or creating a wish (?edit=ID), reopen it */
  var editId = <?php echo (int)$editId; ?>;
  if (editId) {
    var hit = DATA.filter(function (h) { return h.camp === editId; })[0];
    if (hit) open(hit);
  }
})();
</script>

<?php include '../includes/client_footer.php'; ?>
