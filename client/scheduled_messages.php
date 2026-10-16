<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_scheduled_msg')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
require_once __DIR__ . '/../includes/scheduled_sender.php';
sm_ensure_schema($conn);
$flash = $_SESSION['sm_flash'] ?? null;
unset($_SESSION['sm_flash']);
$formError = '';

// Approved templates for the picker
$templates = [];
// message_templates is the real template store (Templates page, Campaigns); whatsapp_templates was an empty stand-in.
$tq = $conn->prepare("SELECT id, name FROM message_templates WHERE company_id = ? AND UPPER(wa_status) = 'APPROVED' ORDER BY name");
if ($tq) { $tq->bind_param('i', $companyId); $tq->execute(); $tr = $tq->get_result(); while ($tr && ($x = $tr->fetch_assoc())) { $templates[(int)$x['id']] = $x['name']; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (isset($_POST['schedule_msg'])) {
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $msg = trim((string)($_POST['message'] ?? ''));
        $schedAt = (string)($_POST['scheduled_at'] ?? ''); // YYYY-MM-DDTHH:MM
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId > 0 && !isset($templates[$templateId])) { $templateId = 0; }
        $ts = strtotime($schedAt);

        if (strlen($phone) < 10 || strlen($phone) > 15) {
            $formError = 'Enter a valid phone number with country code (10 to 15 digits).';
        } elseif (!$ts) {
            $formError = 'Choose a delivery date and time.';
        } elseif ($ts < time() - 60) {
            $formError = 'The delivery time is in the past. Choose a future time.';
        } elseif ($templateId === 0 && $msg === '') {
            $formError = 'Type a message, or pick an approved template.';
        } else {
            $sched = date('Y-m-d H:i:s', $ts);
            $stmt = $conn->prepare("INSERT INTO scheduled_messages (company_id, phone, message, template_id, scheduled_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('issis', $companyId, $phone, $msg, $templateId, $sched);
            if ($stmt->execute()) {
                $_SESSION['sm_flash'] = [true, 'Message scheduled for ' . date('d M Y, H:i', $ts) . '.'];
                header('Location: scheduled_messages.php');
                exit();
            }
            $formError = 'Could not schedule the message. Please try again.';
        }
    }

    if (isset($_POST['cancel_id'])) {
        $id = (int)$_POST['cancel_id'];
        $stmt = $conn->prepare("UPDATE scheduled_messages SET status = 'cancelled' WHERE id = ? AND company_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $_SESSION['sm_flash'] = [true, 'Schedule cancelled.'];
        header('Location: scheduled_messages.php');
        exit();
    }
}

// Counts per status
$counts = ['pending' => 0, 'sent' => 0, 'cancelled' => 0, 'failed' => 0];
$cq = $conn->prepare("SELECT status, COUNT(*) AS c FROM scheduled_messages WHERE company_id = ? GROUP BY status");
if ($cq) { $cq->bind_param('i', $companyId); $cq->execute(); $cr = $cq->get_result(); while ($cr && ($x = $cr->fetch_assoc())) { $k = $x['status'] === 'processing' ? 'pending' : $x['status']; if (isset($counts[$k])) { $counts[$k] += (int)$x['c']; } } }
$totalAll = array_sum($counts);

// Next delivery + today
$nextAt = null; $todayN = 0;
$nq = $conn->prepare("SELECT MIN(scheduled_at) AS n, COALESCE(SUM(DATE(scheduled_at) = CURDATE()),0) AS t FROM scheduled_messages WHERE company_id = ? AND status = 'pending' AND scheduled_at >= NOW()");
if ($nq) { $nq->bind_param('i', $companyId); $nq->execute(); $nr = $nq->get_result()->fetch_assoc(); $nextAt = $nr['n'] ?? null; $todayN = (int)($nr['t'] ?? 0); }

// Upcoming per day, next 14 days
$upMap = [];
$uq = $conn->prepare("SELECT DATE(scheduled_at) AS d, COUNT(*) AS n FROM scheduled_messages WHERE company_id = ? AND status = 'pending' AND scheduled_at >= CURDATE() AND scheduled_at < DATE_ADD(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(scheduled_at)");
if ($uq) { $uq->bind_param('i', $companyId); $uq->execute(); $ur = $uq->get_result(); while ($ur && ($x = $ur->fetch_assoc())) { $upMap[$x['d']] = (int)$x['n']; } }
$uLabels = []; $uData = [];
for ($i = 0; $i < 14; $i++) { $t = strtotime("+$i days"); $uLabels[] = date('d M', $t); $uData[] = $upMap[date('Y-m-d', $t)] ?? 0; }

// List (latest 200, pending first by time).
// scheduled_messages and contacts can have different collations (the table was created with the server
// default), so comparing their phone columns directly fails with "Illegal mix of collations" and the list
// came back empty. Load the rows on their own, then look up contact names with an explicit collation.
$rows = [];
$lq = $conn->prepare("SELECT id, phone, message, template_id, scheduled_at, IF(status = 'processing', 'pending', status) AS status, error_message FROM scheduled_messages
    WHERE company_id = ? ORDER BY (status = 'pending') DESC, scheduled_at ASC LIMIT 200");
if ($lq) {
    $lq->bind_param('i', $companyId);
    if ($lq->execute()) { $lr = $lq->get_result(); while ($lr && ($x = $lr->fetch_assoc())) { $x['contact_name'] = ''; $rows[] = $x; } }
    else { error_log('[scheduled_messages] list: ' . $lq->error); }
}
if ($rows) {
    $phones = array_values(array_unique(array_map(static fn($r) => (string)$r['phone'], $rows)));
    $norm = "CONVERT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone,'+',''),' ',''),'-',''),'(',''),')','') USING utf8mb4) COLLATE utf8mb4_general_ci";
    $nq = $conn->prepare("SELECT c.name, $norm AS p FROM contacts c WHERE c.company_id = ? AND c.name <> '' AND $norm IN (" . implode(',', array_fill(0, count($phones), '?')) . ")");
    if ($nq) {
        $nq->bind_param('i' . str_repeat('s', count($phones)), $companyId, ...$phones);
        if ($nq->execute()) {
            $names = [];
            foreach ($nq->get_result()->fetch_all(MYSQLI_ASSOC) as $n) { $names[(string)$n['p']] ??= (string)$n['name']; }
            foreach ($rows as &$r) { $r['contact_name'] = $names[(string)$r['phone']] ?? ''; }
            unset($r);
        } else { error_log('[scheduled_messages] names: ' . $nq->error); }
    }
}

$pageTitle = 'Scheduled Blasts';
$currentPage = 'scheduled';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'x'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'doc'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    'tpl'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
];
$pill = ['pending' => 'is-warn', 'sent' => 'is-ok', 'cancelled' => 'is-off', 'failed' => 'is-bad'];
$until = static function (string $ts): string {
    $s = strtotime($ts) - time();
    if ($s <= 0) { return 'due now'; }
    if ($s < 3600) { return 'in ' . max(1, (int)floor($s / 60)) . ' min'; }
    if ($s < 86400) { return 'in ' . (int)floor($s / 3600) . ' h'; }
    return 'in ' . (int)floor($s / 86400) . ' d';
};
$minLocal = date('Y-m-d\TH:i', time() + 300);
?>

<div class="db sm">

  <div class="db-head">
    <div>
      <h1>Scheduled Blasts</h1>
      <p>Schedule individual WhatsApp messages for exact future delivery.</p>
    </div>
    <div class="db-head__actions">
      <?php if (has_feature('campaigns')): ?><a href="campaigns.php" class="db-btn"><?php echo $__ic($I['mega'], 16); ?> Campaigns</a><?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?><div class="sm-toast <?php echo $flash[0] ? 'is-ok' : 'is-err'; ?>" role="status"><?php echo $__ic($flash[0] ? $I['check'] : $I['alert'], 18); ?> <span><?php echo $esc($flash[1]); ?></span></div><?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Upcoming deliveries</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Pending: <b><?php echo number_format(array_sum($uData)); ?></b></span><span class="db-mut">Next 14 days</span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="smChart" aria-label="Scheduled messages per day" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['clock'], 19); ?></div><div class="db-kpi__l">Pending</div><div class="db-kpi__v"><?php echo number_format($counts['pending']); ?></div><div class="db-kpi__d">Waiting to be sent</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['cal'], 19); ?></div><div class="db-kpi__l">Next delivery</div><div class="db-kpi__v" style="font-size:22px"><?php echo $nextAt ? $esc(date('d M, H:i', strtotime($nextAt))) : '—'; ?></div><div class="db-kpi__d"><?php echo $nextAt ? $esc($until($nextAt)) : 'Nothing scheduled'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['send'], 19); ?></div><div class="db-kpi__l">Sent</div><div class="db-kpi__v"><?php echo number_format($counts['sent']); ?></div><div class="db-kpi__d"><?php echo $todayN; ?> due today</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-pink"><?php echo $__ic($I['x'], 19); ?></div><div class="db-kpi__l">Cancelled / failed</div><div class="db-kpi__v"><?php echo number_format($counts['cancelled'] + $counts['failed']); ?></div><div class="db-kpi__d"><?php echo $counts['failed']; ?> failed</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Status</h2><span class="db-pill is-off"><?php echo number_format($totalAll); ?> total</span></div>
      <div class="db-donut__chart">
        <canvas id="smDonut" width="200" height="200" aria-label="Messages by status" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($totalAll); ?></b><span>messages</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#F59E0B"></i>Pending: <?php echo $counts['pending']; ?></span>
        <span><i style="background:#25D366"></i>Sent: <?php echo $counts['sent']; ?></span>
        <span><i style="background:#9AA9A4"></i>Cancelled: <?php echo $counts['cancelled']; ?></span>
        <span><i style="background:#E5483A"></i>Failed: <?php echo $counts['failed']; ?></span>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Deliveries</h2><p><span id="smShown"><?php echo count($rows); ?></span> shown</p></div>
        <label class="sm-search"><?php echo $__ic($I['search'], 16); ?><input type="search" id="smSearch" placeholder="Search number, name or message" aria-label="Search scheduled messages"></label>
      </div>
      <div class="sm-chips" id="smChips">
        <button type="button" class="is-active" data-s="pending">Pending <em><?php echo $counts['pending']; ?></em></button>
        <button type="button" data-s="sent">Sent <em><?php echo $counts['sent']; ?></em></button>
        <button type="button" data-s="cancelled">Cancelled <em><?php echo $counts['cancelled']; ?></em></button>
        <button type="button" data-s="failed">Failed <em><?php echo $counts['failed']; ?></em></button>
        <button type="button" data-s="all">All <em><?php echo $totalAll; ?></em></button>
      </div>
      <div class="db-tablewrap">
        <table class="db-table sm-table" id="smTable">
          <thead><tr><th>Recipient</th><th>Message</th><th>Scheduled for</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $name = (string)($r['contact_name'] ?? '');
              $text = trim((string)$r['message']) !== '' ? (string)$r['message'] : ($r['template_id'] > 0 ? 'Template: ' . ($templates[(int)$r['template_id']] ?? 'message') : 'Template message'); ?>
            <tr data-s="<?php echo $esc($r['status']); ?>" data-q="<?php echo $esc(strtolower($r['phone'] . ' ' . $name . ' ' . $text)); ?>">
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr($name !== '' ? $name : '#', 0, 1))); ?></span><div><b><?php echo $esc($name !== '' ? $name : '+' . $r['phone']); ?></b><small><?php echo $name !== '' ? '+' . $esc($r['phone']) : 'Not in contacts'; ?></small></div></div></td>
              <td><span class="sm-msg" title="<?php echo $esc($text); ?>"><?php echo $esc($text); ?></span></td>
              <td style="white-space:nowrap"><b><?php echo $esc(date('d M Y, H:i', strtotime((string)$r['scheduled_at']))); ?></b><?php if ($r['status'] === 'pending'): ?><small class="sm-until"><?php echo $esc($until((string)$r['scheduled_at'])); ?></small><?php endif; ?></td>
              <td><span class="db-pill <?php echo $pill[$r['status']] ?? 'is-off'; ?>"><?php echo $esc(ucfirst((string)$r['status'])); ?></span><?php if ($r['status'] === 'failed' && !empty($r['error_message'])): ?><small class="sm-err"><?php echo $esc($r['error_message']); ?></small><?php endif; ?></td>
              <td style="text-align:right">
                <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" class="sm-cx" data-confirm="Cancel this scheduled delivery?">
                  <?php echo csrf_field(); ?><input type="hidden" name="cancel_id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="sm-cancel"><?php echo $__ic($I['x'], 15); ?> Cancel</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="db-empty" id="smEmpty" style="display:none"></div>
      </div>
    </section>

    <aside class="db-card sm-form">
      <div class="sm-ch"><h2>Schedule a message</h2><p>Sent automatically at the time you choose.</p></div>
      <?php if ($formError): ?><div class="sm-toast is-err" role="alert" style="margin-bottom:14px"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($formError); ?></span></div><?php endif; ?>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="schedule_msg" value="1">
        <div class="sm-f"><label for="sm_phone">Recipient number</label><input type="text" name="phone" id="sm_phone" placeholder="919876543210" inputmode="tel" required autocomplete="off" value="<?php echo $esc($_POST['phone'] ?? ''); ?>"><p>With country code, digits only.</p></div>
        <div class="sm-f"><label for="sm_template">Template <span>optional</span></label>
          <select name="template_id" id="sm_template"><option value="0">Plain text message</option>
            <?php foreach ($templates as $tid => $tn): ?><option value="<?php echo (int)$tid; ?>" <?php echo ((int)($_POST['template_id'] ?? 0) === $tid) ? 'selected' : ''; ?>><?php echo $esc($tn); ?></option><?php endforeach; ?>
          </select></div>
        <div class="sm-f"><label for="sm_message">Message</label><textarea name="message" id="sm_message" rows="4" maxlength="1000" placeholder="Type your message"><?php echo $esc($_POST['message'] ?? ''); ?></textarea><p><span id="smCount">0</span> / 1000</p></div>
        <div class="sm-note"><b>Which one to use?</b> A plain message only reaches people who messaged you in the last 24 hours (a WhatsApp rule). For anyone else, pick an approved <b>template</b>. Its {{1}} becomes the contact's name, or the text you type above.</div>
        <div class="sm-f"><label for="sm_sched">Delivery date and time</label><input type="datetime-local" name="scheduled_at" id="sm_sched" min="<?php echo $esc($minLocal); ?>" required value="<?php echo $esc($_POST['scheduled_at'] ?? ''); ?>"></div>
        <button type="submit" class="db-btn db-btn--primary sm-wide"><?php echo $__ic($I['clock'], 16); ?> Schedule message</button>
      </form>
    </aside>
  </div>
</div>

<style>
.sm{max-width:none}
.sm-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.sm-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.sm-toast.is-err{background:#FDE4E1;color:#c0372b}.sm-toast svg{margin-top:2px;flex:none}
.sm .db-canvas{height:270px}
.sm .db-donut__chart canvas{max-width:200px;max-height:200px}
.sm-search{display:flex;align-items:center;gap:9px;width:270px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.sm-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.sm-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.sm-chips{display:flex;gap:8px;flex-wrap:wrap;padding:4px 20px 8px}
.sm-chips button{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 14px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.sm-chips button em{font-style:normal;font-size:12.5px;padding:1px 8px;border-radius:999px;background:#fff;color:var(--mu)}
.sm-chips button:hover{background:#E6FCF2;color:var(--gd)}
.sm-chips button.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.sm-chips button.is-active em{background:rgba(255,255,255,.22);color:#fff}
.sm-table td{border-bottom:0}
.sm-table tbody tr:nth-child(even) td{background:#F8FCFA}
.sm-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}.sm-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.sm-msg{display:block;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--tx);font-size:14px}
.sm-until{display:block;margin-top:2px;font-size:12.5px;color:var(--mu);font-weight:500}
.sm-cx{margin:0}
.sm-cancel{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:0;border-radius:9px;background:var(--soft);color:var(--tx);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.sm-cancel:hover{background:#FDE4E1;color:#c0372b}
.sm-form{padding:22px}
.sm-ch{margin-bottom:16px}
.sm-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.sm-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.sm-f{margin-bottom:14px}
.sm-f label{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.sm-f label span{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:4px}
.sm-f p{margin:5px 0 0;font-size:12.5px;color:var(--mu)}
.sm-f input,.sm-f select,.sm-f textarea{width:100%;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.sm-f input,.sm-f select{height:44px}
.sm-f textarea{padding:12px 14px;line-height:1.55;resize:vertical}
.sm-f input:focus,.sm-f select:focus,.sm-f textarea:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.sm-wide{width:100%;justify-content:center}
.sm-err{display:block;margin-top:5px;max-width:280px;font-size:12px;line-height:1.4;color:#c0372b;white-space:normal}
.sm-note{margin:-2px 0 16px;padding:11px 13px;border-radius:11px;background:#F3FBF7;font-size:12.5px;line-height:1.5;color:var(--tx)}
@media (max-width:1100px){.sm .db-row{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var status = 'pending';
  var NAMES = {pending: 'pending deliveries', sent: 'sent messages', cancelled: 'cancelled messages', failed: 'failed messages', all: 'messages'};
  function apply() {
    var q = $('smSearch').value.toLowerCase().trim(), n = 0, rows = document.querySelectorAll('#smTable tbody tr');
    rows.forEach(function (r) {
      var ok = (status === 'all' || r.getAttribute('data-s') === status) && (!q || (r.getAttribute('data-q') || '').indexOf(q) !== -1);
      r.style.display = ok ? '' : 'none'; if (ok) { n++; }
    });
    $('smShown').textContent = n;
    var e = $('smEmpty'); e.style.display = n ? 'none' : 'block';
    e.textContent = q ? 'No messages match your search.' : 'No ' + NAMES[status] + '.';
  }
  $('smSearch').addEventListener('input', apply);
  $('smChips').addEventListener('click', function (e) { var b = e.target.closest('button[data-s]'); if (!b) { return; } status = b.getAttribute('data-s'); this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); apply(); });
  apply();
  document.addEventListener('submit', function (e) { var f = e.target.closest && e.target.closest('form.sm-cx[data-confirm]'); if (f && !confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } });
  var ta = $('sm_message'), cnt = $('smCount'); function c() { cnt.textContent = ta.value.length; } ta.addEventListener('input', c); c();

  // Send due messages while this page is open (works even without the server cron), then refresh the list.
  function tick() {
    fetch('../api/scheduled_tick.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var typing = ($('sm_phone').value || ta.value) && document.activeElement && document.activeElement.closest && document.activeElement.closest('.sm-form');
      if (d && d.processed > 0 && !typing) { location.reload(); }
    }).catch(function () {});
  }
  tick();
  setInterval(tick, 30000);

  if (typeof Chart === 'undefined') { return; }
  var ctx = $('smChart').getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270); g.addColorStop(0, '#25D366'); g.addColorStop(1, '#128C7E');
  new Chart(ctx, {type: 'bar', data: {labels: <?php echo json_encode($uLabels); ?>, datasets: [{label: 'Scheduled', data: <?php echo json_encode($uData); ?>, backgroundColor: g, borderRadius: 7, maxBarThickness: 34}]},
    options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false}},
      scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}}, y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var vals = [<?php echo (int)$counts['pending']; ?>, <?php echo (int)$counts['sent']; ?>, <?php echo (int)$counts['cancelled']; ?>, <?php echo (int)$counts['failed']; ?>], total = vals.reduce(function (a, b) { return a + b; }, 0);
  new Chart($('smDonut'), {type: 'doughnut', data: {labels: ['Pending', 'Sent', 'Cancelled', 'Failed'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#F59E0B', '#25D366', '#9AA9A4', '#E5483A'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>
