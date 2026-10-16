<?php
/**
 * Ads & Attribution — Conversion Events (Meta Conversions API for Business Messaging, Phase 2).
 * Setup (dataset, mapping, test) and the log of every event sent to Meta, with manual retry.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/ads/helpers.php';
require_once __DIR__ . '/../services/CapiService.php';

$pageTitle = 'Conversion Events';
$currentPage = 'ads_capi';
$companyId = (int)($_SESSION['company_id'] ?? 0);
$enabled = has_feature('ads_capi');
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$flash = $_SESSION['capi_flash'] ?? null;
unset($_SESSION['capi_flash']);
$back = static function (bool $ok, string $msg, string $qs = '') { $_SESSION['capi_flash'] = [$ok, $msg]; header('Location: ads_capi.php' . $qs); exit(); };

if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        CapiService::saveSettings($conn, $companyId, [
            'dataset_id' => $_POST['dataset_id'] ?? '', 'token' => $_POST['token'] ?? '', 'enabled' => !empty($_POST['enabled']),
            'mapping' => (array)($_POST['mapping'] ?? []), 'send_value' => !empty($_POST['send_value']),
            'window_days' => (int)($_POST['window_days'] ?? 7), 'test_event_code' => $_POST['test_event_code'] ?? '',
        ]);
        $back(true, 'Settings saved.');
    }
    if ($act === 'remove_token') { $s = CapiService::settings($conn, $companyId); CapiService::saveSettings($conn, $companyId, ['token' => '-', 'enabled' => $s['enabled'], 'mapping' => $s['mapping'], 'send_value' => $s['send_value'], 'window_days' => $s['window_days'], 'test_event_code' => $s['test_event_code']]); $back(true, 'Separate token removed. Your WhatsApp access token is used again.'); }
    if ($act === 'connect') { $r = CapiService::connectDataset($conn, $companyId); $back($r['ok'], $r['message']); }
    if ($act === 'test') { $r = CapiService::testEvent($conn, $companyId); $back($r['ok'], $r['message']); }
    if ($act === 'retry') { $ok = CapiService::retry($conn, $companyId, (int)($_POST['id'] ?? 0)); $back($ok, $ok ? 'Queued again. It will be sent within a minute.' : 'Only failed events can be retried.', '?status=failed'); }
    if ($act === 'retry_all') {
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("UPDATE capi_events SET status='pending', attempts=0, run_at=?, last_error=NULL WHERE company_id=? AND status='failed'");
        $st->bind_param('si', $now, $companyId); $st->execute();
        $back(true, $st->affected_rows . ' failed event' . ($st->affected_rows === 1 ? '' : 's') . ' queued again.');
    }
}

$s = $enabled ? CapiService::settings($conn, $companyId) : null;
$cred = $enabled ? CapiService::credentials($conn, $companyId, $s) : null;
$statusF = in_array($_GET['status'] ?? '', ['sent', 'pending', 'failed', 'skipped'], true) ? $_GET['status'] : '';
$since = date('Y-m-d 00:00:00', strtotime('-29 days'));
$counts = $enabled ? (CapiService::stats($conn, $companyId, $since)[$companyId] ?? []) : [];
$log = [];
if ($enabled) {
    $sql = "SELECT e.*, c.name, c.phone, r.headline, r.source_id FROM capi_events e
            LEFT JOIN contacts c ON c.id = e.contact_id AND c.company_id = e.company_id
            LEFT JOIN ad_referrals r ON r.id = e.ad_referral_id AND r.company_id = e.company_id
            WHERE e.company_id = ?" . ($statusF === 'pending' ? " AND e.status IN ('pending','processing')" : ($statusF ? " AND e.status = ?" : "")) . "
            ORDER BY e.id DESC LIMIT 200";
    $st = $conn->prepare($sql);
    if ($statusF && $statusF !== 'pending') $st->bind_param('is', $companyId, $statusF); else $st->bind_param('i', $companyId);
    $st->execute();
    $log = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
$ico = static fn(string $p, int $sz = 18) => '<svg class="db-ic" viewBox="0 0 24 24" width="' . $sz . '" height="' . $sz . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$okI = '<path d="M20 6 9 17l-5-5"/>';
$noI = '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>';
$pill = ['sent' => ['Sent', 'is-ok'], 'pending' => ['Waiting', 'is-info'], 'processing' => ['Sending', 'is-info'], 'failed' => ['Failed', 'is-bad'], 'skipped' => ['Skipped', 'is-off']];

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<div class="db ads cp">
  <div class="db-head">
    <div>
      <h1>Conversion Events</h1>
      <p>Tell Meta which ad leads became customers. Ads Manager then shows sales per ad, and Meta finds more people like your buyers.</p>
    </div>
    <div class="db-head__actions">
      <?php if ($enabled): ?><span class="cp-state <?php echo $s['enabled'] ? 'is-live' : 'is-off'; ?>"><i></i><?php echo $s['enabled'] ? 'Sending to Meta' : 'Sending is off'; ?></span><?php endif; ?>
      <a class="db-btn" href="ads_leads.php"><?php echo $ico('<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>', 16); ?> Ad Leads</a>
    </div>
  </div>

  <?php if ($flash): ?><div class="cp-toast <?php echo $flash[0] ? 'is-ok' : 'is-err'; ?>"><?php echo $ico($flash[0] ? $okI : $noI, 17); ?><span><?php echo $esc($flash[1]); ?></span></div><?php endif; ?>

<?php if (!$enabled): ?>
  <section class="db-card ads-empty">
    <span class="cp-empty-ic"><?php echo $ico('<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>', 28); ?></span>
    <h2>Conversions API is part of the Ads &amp; Attribution add-on</h2>
    <p>Send your WhatsApp sales back to Meta so your Click-to-WhatsApp ads optimise for buyers, not just chats. Ask your account manager to switch it on.</p>
    <a class="db-btn db-btn--primary" href="subscription.php">View plans</a>
  </section>
<?php else:
  $steps = [
    ['WhatsApp connected', $cred['token'] !== '' && $cred['waba_id'] !== '', 'WhatsApp settings need the access token and WhatsApp Business Account ID.', 'settings_whatsapp.php'],
    ['Dataset linked', $s['dataset_id'] !== '', 'Click "Find my dataset" or paste the ID from Events Manager.', ''],
    ['Sending switched on', $s['enabled'], 'Tick "Send events to Meta" and save.', ''],
  ];
  $done = count(array_filter(array_column($steps, 1)));
  $canFind = $cred['token'] !== '' && $cred['waba_id'] !== '';
  $kpis = [
    ['sent', 'Sent to Meta', $counts['sent'] ?? 0, 'is-green', $okI, 'Accepted by Meta'],
    ['pending', 'Waiting', $counts['pending'] ?? 0, 'is-blue', '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', 'Queued, sends within a minute'],
    ['failed', 'Failed', $counts['failed'] ?? 0, 'is-pink', $noI, 'Meta rejected, can be retried'],
    ['skipped', 'Skipped', $counts['skipped'] ?? 0, 'is-amber', '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>', 'Not from an ad, or too old'],
  ];
  $total = (int)($counts['sent'] ?? 0) + (int)($counts['pending'] ?? 0) + (int)($counts['failed'] ?? 0) + (int)($counts['skipped'] ?? 0);
  $chip = static fn(string $txt, string $tone) => '<span class="cp-tag ' . $tone . '">' . $txt . '</span>';
?>
  <!-- Standalone actions. Their buttons sit inside the settings card and point here with form="…". -->
  <form id="cpConnect" method="post" hidden><?php echo csrf_field(); ?><input type="hidden" name="action" value="connect"></form>
  <form id="cpTest" method="post" hidden><?php echo csrf_field(); ?><input type="hidden" name="action" value="test"></form>
  <?php if ($s['has_token']): ?><form id="cpRmToken" method="post" hidden><?php echo csrf_field(); ?><input type="hidden" name="action" value="remove_token"></form><?php endif; ?>

  <?php if ($done === 3): ?>
  <section class="cp-ready">
    <span class="cp-ready__ic"><?php echo $ico($okI, 18); ?></span>
    <div class="cp-ready__t"><b>All set.</b> Events go to Meta automatically when you mark an ad lead in WhatsApp Chat.</div>
    <ul class="cp-ready__steps"><?php foreach ($steps as [$t]): ?><li><?php echo $ico($okI, 13); ?><?php echo $t; ?></li><?php endforeach; ?></ul>
  </section>
  <?php else: ?>
  <section class="db-card cp-progress">
    <div class="cp-progress__head">
      <div><h2>Finish setup</h2><p><?php echo $done; ?> of 3 steps done. Events are only sent once all three are complete.</p></div>
      <div class="cp-meter" aria-hidden="true"><i style="width:<?php echo round($done / 3 * 100); ?>%"></i></div>
    </div>
    <ol class="cp-steps">
      <?php foreach ($steps as $i => [$t, $ok, $hint, $link]): ?>
        <li class="<?php echo $ok ? 'ok' : ''; ?>">
          <span class="cp-steps__n"><?php echo $ok ? $ico($okI, 15) : $i + 1; ?></span>
          <div><b><?php echo $t; ?></b><small><?php if ($ok): ?>Done<?php else: echo $esc($hint); ?><?php if ($link): ?> <a href="<?php echo $link; ?>">Open</a><?php endif; endif; ?></small></div>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <div class="cp-kpis">
    <?php foreach ($kpis as [$key, $lbl, $n, $tone, $icon, $sub]): ?>
      <a class="cp-kpi<?php echo $statusF === $key ? ' is-on' : ''; ?>" href="ads_capi.php?status=<?php echo $key; ?>#cpLog" title="Show <?php echo strtolower($lbl); ?> events">
        <span class="db-kpi__ic <?php echo $tone; ?>"><?php echo $ico($icon, 18); ?></span>
        <span class="cp-kpi__t"><small><?php echo $lbl; ?></small><b class="db-kpi__v"><?php echo number_format((int)$n); ?></b></span>
        <em><?php echo $sub; ?></em>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="cp-stack<?php echo $done === 3 ? '' : ' is-setup-first'; ?>">

    <!-- ===================== Event log ===================== -->
    <section class="db-card db-tablecard cp-log" id="cpLog">
      <div class="db-card__head">
        <div><h2>Event log</h2><p>Every event WAAMINI sent to Meta or skipped, last 30 days.</p></div>
        <?php if (($counts['failed'] ?? 0) > 0): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="retry_all"><button class="db-btn"><?php echo $ico('<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>', 16); ?> Retry all failed</button></form><?php endif; ?>
      </div>
      <div class="cp-tabs" role="tablist" aria-label="Filter events">
        <?php foreach (['' => 'All', 'sent' => 'Sent', 'pending' => 'Waiting', 'failed' => 'Failed', 'skipped' => 'Skipped'] as $k => $lbl):
          $n = $k === '' ? $total : (int)($counts[$k] ?? 0); ?>
          <a class="ads-chip<?php echo $statusF === $k ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $statusF === $k ? 'true' : 'false'; ?>" href="ads_capi.php<?php echo $k ? '?status=' . $k : ''; ?>#cpLog"><?php echo $lbl; ?> <em><?php echo $n; ?></em></a>
        <?php endforeach; ?>
      </div>
      <div class="db-tablewrap">
        <?php if (!$log): ?>
          <div class="cp-none">
            <span class="cp-empty-ic"><?php echo $ico('<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>', 24); ?></span>
            <div class="cp-none__t">
              <b><?php echo $statusF ? 'Nothing in this filter' : 'No events yet'; ?></b>
              <p>Events appear here when an ad lead is marked <b>Follow-up</b> or <b>Converted</b> in WhatsApp Chat.</p>
            </div>
            <ol class="cp-flow">
              <li><span>1</span><div><b>Customer taps your ad</b><small>and sends you a WhatsApp message</small></div></li>
              <li><span>2</span><div><b>You mark the chat</b><small>Follow-up or Converted, with the sale value</small></div></li>
              <li><span>3</span><div><b>WAAMINI tells Meta</b><small>the sale shows against that ad in Ads Manager</small></div></li>
            </ol>
          </div>
        <?php else: ?>
        <table class="db-table ads-table">
          <thead><tr><th>Event</th><th>Customer</th><th>Ad</th><th class="num">Value</th><th>Status</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach ($log as $e): [$pl, $pc] = $pill[$e['status']] ?? ['?', 'is-off']; ?>
            <tr>
              <td><b><?php echo $esc(CapiService::EVENTS[$e['event_name']] ?? $e['event_name']); ?></b><small class="cp-sub"><?php echo $esc(ucfirst((string)$e['stage'])); ?></small></td>
              <td><a href="whatsapp.php?c=<?php echo (int)$e['contact_id']; ?>&amp;crm=open"><?php echo $esc(trim((string)$e['name']) !== '' ? $e['name'] : $e['phone']); ?></a></td>
              <td><?php echo $e['headline'] ? $esc(mb_strimwidth((string)$e['headline'], 0, 40, '…')) : '<span class="db-mut">—</span>'; ?></td>
              <td class="num"><?php echo $e['value'] !== null ? $esc(ads_inr($e['value'])) : '<span class="db-mut">—</span>'; ?></td>
              <td><span class="db-pill <?php echo $pc; ?>"><?php echo $pl; ?></span><?php if ((int)$e['attempts'] > 1): ?><small class="cp-sub"><?php echo (int)$e['attempts']; ?> attempts</small><?php endif; ?>
                <?php if ($e['last_error']): ?><small class="cp-err"><?php echo $esc($e['last_error']); ?></small><?php endif; ?>
                <?php if ($e['status'] === 'failed'): ?><form method="post" class="cp-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>"><button class="cp-link">Retry</button></form><?php endif; ?></td>
              <td style="white-space:nowrap"><?php echo $esc(date('d M, h:i A', strtotime((string)($e['sent_at'] ?: $e['created_at'])))); ?><?php if ($e['status'] === 'pending' && $e['run_at']): ?><small class="cp-sub">sends after <?php echo $esc(date('h:i A', strtotime((string)$e['run_at']))); ?></small><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===================== Settings ===================== -->
    <form method="post" class="db-card cp-settings" id="cpSettings">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="save">
      <div class="db-card__head cp-settings__head">
        <div><h2>Settings</h2><p>Where events go, which ones are sent, and the rules around them.</p></div>
      </div>

      <div class="cp-cols">
        <!-- 1. Dataset -->
        <div class="cp-col">
          <div class="cp-sec__h"><span class="cp-sec__n">1</span><div><h3>Dataset</h3><p>Where Meta stores events from your WhatsApp number.</p></div></div>
          <label class="cp-f">Dataset ID<input name="dataset_id" value="<?php echo $esc($s['dataset_id']); ?>" inputmode="numeric" placeholder="e.g. 1234567890123456"></label>
          <p class="cp-hint">Events Manager → Data sources → your WhatsApp dataset → Settings.</p>
          <div class="cp-tile">
            <div class="cp-tile__t"><b>Don't know the ID?</b><small>WAAMINI finds or creates the dataset linked to your WhatsApp Business Account.</small></div>
            <button type="submit" form="cpConnect" class="db-btn db-btn--sm" <?php echo $canFind ? '' : 'disabled'; ?>><?php echo $ico('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 15); ?> Find my dataset</button>
          </div>
          <div class="cp-tile">
            <div class="cp-tile__t"><b>Test the connection</b><small>Sends one sample event. Look for it in Events Manager → Test events.</small></div>
            <button type="submit" form="cpTest" class="db-btn db-btn--sm" <?php echo $s['dataset_id'] === '' ? 'disabled' : ''; ?>><?php echo $ico('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>', 15); ?> Send test event</button>
            <?php if ($s['last_test_at']): ?><div class="cp-last"><small>Last test · <?php echo $esc(date('d M, h:i A', strtotime((string)$s['last_test_at']))); ?></small><p><?php echo $esc($s['last_test_result']); ?></p></div><?php endif; ?>
          </div>
        </div>

        <!-- 2. What to send -->
        <div class="cp-col">
          <div class="cp-sec__h"><span class="cp-sec__n">2</span><div><h3>What to send</h3><p>The Meta event sent when you mark a chat in WhatsApp Chat.</p></div></div>
          <div class="cp-maps">
            <?php foreach (CapiService::STAGES as $stage => $lbl):
              [$short, $note] = array_pad(array_map('trim', explode('(', rtrim($lbl, ')'), 2)), 2, ''); ?>
              <label class="cp-map">
                <span class="cp-map__l"><b><?php echo $esc($short); ?></b><?php if ($note !== ''): ?><small><?php echo $esc(ucfirst($note)); ?></small><?php endif; ?></span>
                <span class="cp-map__arrow" aria-hidden="true"><?php echo $ico('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>', 15); ?></span>
                <select name="mapping[<?php echo $stage; ?>]" aria-label="<?php echo $esc($lbl); ?>">
                  <option value="">Don't send</option>
                  <?php foreach (CapiService::EVENTS as $ev => $evLbl): ?><option value="<?php echo $ev; ?>"<?php echo ($s['mapping'][$stage] ?? '') === $ev ? ' selected' : ''; ?>><?php echo $esc($evLbl); ?></option><?php endforeach; ?>
                </select>
              </label>
            <?php endforeach; ?>
          </div>
          <label class="cp-switch"><input type="checkbox" name="send_value" value="1" <?php echo $s['send_value'] ? 'checked' : ''; ?>><span><b>Include the sale value</b><small>Purchase carries the <b>Sale value ₹</b> from WhatsApp Chat, so Ads Manager can show revenue per ad. It waits up to an hour for the value.</small></span></label>
        </div>

        <!-- 3. Rules -->
        <div class="cp-col">
          <div class="cp-sec__h"><span class="cp-sec__n">3</span><div><h3>Rules</h3><p>Limits that keep your ad data accurate.</p></div></div>
          <label class="cp-f">Attribution window
            <span class="cp-unit"><input type="number" name="window_days" min="1" max="28" value="<?php echo (int)$s['window_days']; ?>"><i>days</i></span>
          </label>
          <p class="cp-hint">Only stage changes within this many days of the ad tap are sent. Meta's Click-to-WhatsApp window is 7 days today. <!-- VERIFY --></p>
          <label class="cp-f">Test event code <em>optional</em><input name="test_event_code" value="<?php echo $esc($s['test_event_code']); ?>" placeholder="TEST12345"></label>
          <?php if ($s['test_event_code'] !== ''): ?>
            <p class="cp-warn"><?php echo $ico($noI, 14); ?><span>Test mode is on: real events are counted as test events. Clear this code when testing is done.</span></p>
          <?php else: ?>
            <p class="cp-hint">From Events Manager → Test events. Only needed while testing.</p>
          <?php endif; ?>
          <details class="cp-adv"><summary>Advanced: separate access token</summary>
            <label class="cp-f">Conversions API token<input name="token" type="password" autocomplete="off" placeholder="<?php echo $s['has_token'] ? 'Saved (hidden). Paste a new one to replace it.' : 'Leave empty to use your WhatsApp access token'; ?>"></label>
            <p class="cp-hint">Only needed if your WhatsApp token has no access to the dataset. Stored encrypted and never shown again.</p>
            <?php if ($s['has_token']): ?><button type="submit" form="cpRmToken" class="cp-link">Remove the separate token</button><?php endif; ?>
          </details>
        </div>
      </div>

      <div class="cp-savebar">
        <label class="cp-switch cp-switch--bare"><input type="checkbox" name="enabled" value="1" <?php echo $s['enabled'] ? 'checked' : ''; ?>><span><b>Send events to Meta</b><small>Turn off to pause sending. Nothing is lost while paused.</small></span></label>
        <button type="submit" class="db-btn db-btn--primary"><?php echo $ico($okI, 16); ?> Save settings</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<style>
/* Conversion Events: flat colours, no gradients, no divider lines (spacing and tinted tiles instead) */
.ads .db-btn--primary{background:#128C7E;box-shadow:none}.ads .db-btn--primary:hover{background:#0B6B5E;transform:none}
.ads .db-btn[disabled]{opacity:.5;cursor:not-allowed}
.cp .db-btn--sm{height:36px;padding:0 13px;font-size:13px;border-radius:10px;gap:7px;white-space:nowrap}
.cp .db-head__actions{align-items:center}
.cp-state{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 14px;border-radius:11px;font:700 13.5px Inter,system-ui,sans-serif}
.cp-state i{width:8px;height:8px;border-radius:50%;background:currentColor}
.cp-state.is-live{background:#E6FCF2;color:#0B6B5E}.cp-state.is-off{background:#EEF2F1;color:#5f6f6a}
.cp-toast{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;font-weight:600;font-size:14px;margin-bottom:16px}
.cp-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.cp-toast.is-err{background:#FDE4E1;color:#c0372b}

/* all set bar */
.cp-ready{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 18px;margin-bottom:16px;border-radius:14px;background:#fff;border:1px solid var(--line);box-shadow:0 12px 30px -26px rgba(18,140,126,.5)}
.cp-ready__ic{width:34px;height:34px;flex:none;border-radius:50%;display:grid;place-items:center;background:#128C7E;color:#fff}
.cp-ready__t{flex:1;min-width:220px;font-size:14px;color:var(--tx)}
.cp-ready__t b{color:var(--ink)}
.cp-ready__steps{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
.cp-ready__steps li{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:99px;background:#EEFBF4;color:#0B6B5E;font-size:12.5px;font-weight:700}

/* setup progress (only while incomplete) */
.cp-progress{padding:20px 22px;margin-bottom:16px}
.cp-progress__head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.cp-progress h2{margin:0;font-size:17px;font-weight:800}
.cp-progress p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.cp-meter{width:220px;max-width:100%;height:8px;border-radius:99px;background:#E8F1ED;overflow:hidden}
.cp-meter i{display:block;height:100%;border-radius:99px;background:#128C7E}
.cp-steps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.cp-steps li{display:flex;gap:12px;align-items:flex-start;padding:14px;border-radius:12px;background:#FFF8EA}
.cp-steps li.ok{background:#EEFBF4}
.cp-steps__n{width:30px;height:30px;flex:none;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:13px;background:#fff;color:#a15c00}
.cp-steps li.ok .cp-steps__n{background:#128C7E;color:#fff}
.cp-steps b{display:block;font-size:14px;color:var(--ink)}
.cp-steps small{display:block;font-size:12.5px;color:var(--tx);margin-top:3px;line-height:1.45}
.cp-steps li.ok small{color:#0B6B5E;font-weight:600}
.cp-steps a{color:#0B6B5E;font-weight:700}

/* KPI cards (click to filter the log) */
.cp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:18px}
.cp-kpi{display:grid;grid-template-columns:42px minmax(0,1fr);column-gap:14px;row-gap:12px;align-items:center;padding:18px 20px;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);text-decoration:none;color:inherit;transition:box-shadow .2s,border-color .2s}
.cp-kpi:hover{box-shadow:0 16px 34px -22px rgba(18,140,126,.55)}
.cp-kpi.is-on{border-color:#128C7E;box-shadow:0 0 0 1px #128C7E}
.cp-kpi .db-kpi__ic{margin:0}
.cp-kpi__t small{display:block;font-size:13px;font-weight:600;color:var(--mu)}
.cp-kpi__t .db-kpi__v{display:block;margin:2px 0 0;font-size:28px;line-height:1.15}
.cp-kpi em{grid-column:1/-1;font-style:normal;padding:8px 10px;border-radius:9px;background:var(--soft);font-size:12.5px;color:var(--tx)}

/* page order: log first once set up, settings first while setting up */
.cp-stack{display:flex;flex-direction:column;gap:18px}
.cp-stack.is-setup-first .cp-settings{order:-1}

/* event log */
.cp-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:14px 20px 0}
.ads-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:999px;background:#F3FBF7;color:#34453f;font:700 13px Inter,system-ui,sans-serif;text-decoration:none}
.ads-chip em{font-style:normal;min-width:20px;padding:1px 6px;border-radius:99px;background:#fff;font-size:11.5px;text-align:center}
.ads-chip:hover{background:#E6FCF2;color:#0B6B5E}
.ads-chip.is-on{background:#128C7E;color:#fff}
.ads-chip.is-on em{background:rgba(255,255,255,.2);color:#fff}
.cp-none{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(0,1.6fr);gap:18px 22px;align-items:center;padding:18px 4px 8px}
.cp-empty-ic{width:56px;height:56px;border-radius:50%;background:#E6FCF2;color:#128C7E;display:grid;place-items:center}
.ads-empty .cp-empty-ic{margin:0 auto 14px}
.cp-none__t > b{display:block;font-size:16px;color:var(--ink)}
.cp-none__t p{margin:4px 0 0;font-size:13.5px;color:var(--tx);line-height:1.55}
.cp-flow{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.cp-flow li{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:12px;background:#F6FAF8}
.cp-flow span{width:24px;height:24px;flex:none;border-radius:8px;display:grid;place-items:center;background:#fff;color:#0B6B5E;font-weight:800;font-size:12px}
.cp-flow b{display:block;font-size:13px;color:var(--ink)}
.cp-flow small{display:block;margin-top:2px;font-size:12px;color:var(--mu);line-height:1.45}
.cp-sub{display:block;font-size:11.5px;color:var(--mu);margin-top:2px}
.cp-err{display:block;font-size:12px;color:#c0372b;margin-top:4px;max-width:340px;white-space:normal;line-height:1.4}
.ads-table th.num,.ads-table td.num{text-align:right}.ads-table td{border-bottom:0;vertical-align:top}
.ads-table tbody tr:nth-child(even) td{background:#F8FCFA}
.ads-table a{color:#0B6B5E;font-weight:600;text-decoration:none}
.ads-empty{text-align:center;padding:44px 28px}.ads-empty p{max-width:540px;margin:8px auto 18px;line-height:1.6}

/* settings card: three columns side by side */
.cp-settings{padding:0;overflow:hidden}
.cp-settings__head{padding:18px 22px 0}
.cp-cols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;padding:16px 22px 20px}
.cp-col{padding:18px;border-radius:14px;background:#F8FBFA;min-width:0}
.cp-sec__h{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.cp-sec__h h3{margin:0;font-size:15px;font-weight:800;color:var(--ink)}
.cp-sec__h p{margin:2px 0 0;font-size:12.5px;color:var(--mu);line-height:1.45}
.cp-sec__n{width:28px;height:28px;flex:none;border-radius:9px;display:grid;place-items:center;background:#128C7E;color:#fff;font-weight:800;font-size:13px}
.cp-f{display:block;font-size:13px;font-weight:700;color:var(--tx);margin:14px 0 0}
.cp-sec__h + .cp-f{margin-top:0}
.cp-f em{font-style:normal;font-weight:500;color:var(--mu)}
.cp-f input,.cp-map select{display:block;width:100%;height:42px;margin-top:6px;padding:0 12px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.cp-f input:focus,.cp-map select:focus{border-color:#128C7E;box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.cp-unit{display:flex;align-items:center;margin-top:6px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;transition:border-color .15s,box-shadow .15s}
.cp-unit:focus-within{border-color:#128C7E;box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.cp-unit input{margin:0;border:0;box-shadow:none!important;background:transparent;flex:1;min-width:0}
.cp-unit i{padding:0 14px 0 4px;font-style:normal;font-weight:600;font-size:13px;color:var(--mu)}
.cp-hint{margin:7px 0 0;font-size:12px;color:var(--mu);line-height:1.5}
.cp-warn{display:flex;gap:8px;align-items:flex-start;margin:8px 0 0;padding:9px 11px;border-radius:10px;background:#FFF4DE;color:#8a5300;font-size:12.5px;font-weight:600;line-height:1.45}
.cp-warn svg{margin-top:1px}
.cp-tile{margin-top:12px;padding:12px 14px;border-radius:12px;background:#fff;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.cp-tile__t{flex:1;min-width:170px}
.cp-tile__t b{display:block;font-size:13px;color:var(--ink)}
.cp-tile__t small{display:block;margin-top:2px;font-size:12px;color:var(--mu);line-height:1.45}
.cp-last{flex-basis:100%;padding:9px 11px;border-radius:10px;background:#F3F7F5}
.cp-last small{font-size:11.5px;font-weight:700;color:var(--mu)}
.cp-last p{margin:3px 0 0;font-size:12.5px;color:var(--tx);line-height:1.5}
.cp-maps{display:grid;gap:8px}
.cp-map{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(150px,1.2fr);gap:10px;align-items:center;padding:8px 8px 8px 12px;border-radius:12px;background:#fff;cursor:pointer}
.cp-map__l b{display:block;font-size:13px;color:var(--ink)}
.cp-map__l small{display:block;font-size:11.5px;color:var(--mu);margin-top:1px}
.cp-map__arrow{color:#9fb3ac;display:grid}
.cp-map select{margin:0;height:38px;background:#F6FAF8;border-color:transparent;cursor:pointer}
.cp-switch{display:flex;gap:12px;align-items:flex-start;margin-top:12px;padding:12px 14px;border-radius:12px;background:#fff;cursor:pointer}
.cp-switch input{appearance:none;-webkit-appearance:none;flex:none;width:38px;height:22px;margin:1px 0 0;border-radius:99px;background:#CBD8D3;position:relative;cursor:pointer;transition:background .2s}
.cp-switch input::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s}
.cp-switch input:checked{background:#128C7E}
.cp-switch input:checked::after{transform:translateX(16px)}
.cp-switch input:focus-visible{outline:2px solid #128C7E;outline-offset:2px}
.cp-switch b{display:block;font-size:13.5px;color:var(--ink)}
.cp-switch small{display:block;margin-top:2px;font-size:12px;color:var(--mu);line-height:1.45}
.cp-switch small b{display:inline;font-size:12px}
.cp-adv{margin-top:14px;padding:12px 14px;border-radius:12px;background:#fff}
.cp-adv summary{cursor:pointer;font-size:13px;font-weight:700;color:#0B6B5E}
.cp-adv .cp-link{margin-top:10px}
.cp-savebar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 22px;background:#EEFBF4}
.cp-switch--bare{margin:0;padding:0;background:none}
.cp-savebar .db-btn{min-width:180px;justify-content:center}
.cp-inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:8px 0 0}
.cp-link{border:0;background:none;color:#0B6B5E;font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer;padding:0}

@media (max-width:1560px){.cp-none{grid-template-columns:auto minmax(0,1fr)}.cp-flow{grid-column:1/-1}}
@media (max-width:1280px){.cp-cols{grid-template-columns:repeat(2,minmax(0,1fr))}.cp-cols .cp-col:last-child{grid-column:1/-1}}
@media (max-width:1180px){.cp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:860px){.cp-cols{grid-template-columns:1fr}.cp-steps,.cp-flow{grid-template-columns:1fr}.cp-savebar .db-btn{width:100%}}
@media (max-width:520px){.cp-kpis{grid-template-columns:1fr}.cp-none{grid-template-columns:1fr;text-align:center}.cp-none .cp-empty-ic{margin:0 auto}.cp-map{grid-template-columns:1fr}.cp-map__arrow{display:none}}
</style>
<?php include '../includes/client_footer.php'; ?>
