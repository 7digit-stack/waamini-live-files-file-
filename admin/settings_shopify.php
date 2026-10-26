<?php
/**
 * Admin > Shopify app: the platform Shopify app keys (used by every client by default), optional per-client app
 * keys, the values to paste into the Shopify Dev Dashboard, and every client's Shopify connection in one table.
 */
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/shopify.php';
shopify_ensure_schema($conn);

$pageTitle = 'Shopify app';
$currentPage = 'settings_shopify';
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$go = static function (array $q): void { header('Location: settings_shopify.php?' . http_build_query($q)); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'platform') {
        $id = trim((string)($_POST['client_id'] ?? ''));
        $sec = trim((string)($_POST['client_secret'] ?? ''));
        $saved = shopify_platform_app($conn);
        if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $id)) $go(['err' => 'Enter the Client ID from the Shopify Dev Dashboard (your app > Settings).']);
        if ($sec === '' && $saved['client_secret'] === '') $go(['err' => 'Enter the Client secret.']);
        if ($sec !== '' && strlen($sec) < 16) $go(['err' => 'That Client secret looks too short. Copy it again from the Dev Dashboard.']);
        shopify_save_platform_app($conn, $id, $sec);
        $go(['ok' => 'Shopify app keys saved. All clients can now connect their stores.']);
    }
    if ($act === 'client_app') {
        $cid = (int)($_POST['company_id'] ?? 0);
        if ($cid <= 0) $go(['err' => 'Choose a client.']);
        if (isset($_POST['clear'])) {
            $st = $conn->prepare("UPDATE shopify_apps SET use_own = 0 WHERE company_id = ?");
            $st->bind_param('i', $cid); $st->execute();
            $go(['ok' => 'That client now uses the WAAMINI Shopify app. Their store must be connected again.', 'for' => $cid]);
        }
        $id = trim((string)($_POST['client_id'] ?? ''));
        $sec = trim((string)($_POST['client_secret'] ?? ''));
        $old = $conn->prepare("SELECT client_secret FROM shopify_apps WHERE company_id = ?");
        $old->bind_param('i', $cid); $old->execute(); $oldSec = (string)($old->get_result()->fetch_row()[0] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $id)) $go(['err' => 'Enter that client\'s Shopify app Client ID.', 'for' => $cid]);
        if ($sec === '' && $oldSec === '') $go(['err' => 'Enter that client\'s Shopify app Client secret.', 'for' => $cid]);
        $enc = $sec !== '' ? EncryptionService::encrypt_secret($sec) : $oldSec;
        $st = $conn->prepare("INSERT INTO shopify_apps (company_id, use_own, client_id, client_secret) VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE use_own = 1, client_id = VALUES(client_id), client_secret = VALUES(client_secret)");
        $st->bind_param('iss', $cid, $id, $enc); $st->execute();
        $go(['ok' => 'Saved. That client now connects with their own Shopify app.', 'for' => $cid]);
    }
    $go([]);
}

$cfg = shopify_cfg(0);
$platform = shopify_platform_app($conn);
$sourceTxt = ['admin' => 'Saved on this page', 'env' => 'From the .env file', 'none' => 'Not set'][$cfg['source']] ?? $cfg['source'];
$copyRows = [
    'App URL' => shopify_base_url() . '/client/shopify_connect.php',
    'Allowed redirection URL' => shopify_redirect_url(),
    'Scopes' => SHOPIFY_SCOPES,
    'Compliance webhooks (all 3)' => shopify_webhook_url(),
];

// every company with its Shopify access + connection
$rows = [];
$colOk = false;
try { $c = $conn->query("SHOW COLUMNS FROM subscription_plans LIKE 'feat_shopify'"); $colOk = $c && $c->num_rows > 0; } catch (\Throwable $e) {}
$q = $conn->query("SELECT c.id, c.name, c.features_override,
        (SELECT u.email FROM users u WHERE u.company_id = c.id AND u.role <> 'super_admin' ORDER BY u.id LIMIT 1) AS login,
        (SELECT sp.name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.company_id = c.id ORDER BY (s.status IN ('active','trial')) DESC, s.id DESC LIMIT 1) AS plan,
        (SELECT " . ($colOk ? 'sp.feat_shopify' : '0') . " FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.company_id = c.id ORDER BY (s.status IN ('active','trial')) DESC, s.id DESC LIMIT 1) AS plan_on,
        st.shop_domain, st.status AS st_status, st.webhooks_ok, st.last_event_at, ap.use_own, ap.client_id AS own_id
      FROM companies c
      LEFT JOIN shopify_stores st ON st.company_id = c.id
      LEFT JOIN shopify_apps ap ON ap.company_id = c.id
      ORDER BY (st.status = 'active') DESC, c.id DESC");
while ($q && ($x = $q->fetch_assoc())) {
    $o = json_decode((string)$x['features_override'], true);
    $x['access'] = (is_array($o) && array_key_exists('feat_shopify', $o)) ? !empty($o['feat_shopify']) : !empty($x['plan_on']);
    $rows[] = $x;
}
$for = (int)($_GET['for'] ?? 0);
$forRow = null; foreach ($rows as $r) if ((int)$r['id'] === $for) $forRow = $r;
$ok = (string)($_GET['ok'] ?? ''); $err = (string)($_GET['err'] ?? '');
$connectedN = count(array_filter($rows, fn($r) => $r['st_status'] === 'active'));
$accessN = count(array_filter($rows, fn($r) => $r['access']));

include '../includes/admin_header.php';
?>
<style>
.ss{--g:#128C7E;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#6B7C76;--soft:#F3FBF7;color:var(--ink)}
.ss *{box-sizing:border-box}
.ss-head{display:flex;align-items:center;gap:16px;padding:22px 24px;border-radius:18px;background:#fff;box-shadow:0 12px 30px -24px rgba(15,38,34,.5);margin-bottom:18px;flex-wrap:wrap}
.ss-head i{width:52px;height:52px;border-radius:15px;background:#95BF47;color:#fff;display:grid;place-items:center;flex:none}
.ss-head h1{margin:0 0 4px;font-size:24px;font-weight:800}.ss-head p{margin:0;color:var(--tx);font-size:14px;max-width:720px;line-height:1.5}
.ss-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.ss-kpi{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 12px 30px -24px rgba(15,38,34,.5)}
.ss-kpi > span{display:block;font-size:12.5px;color:var(--mu);font-weight:600}.ss-kpi b{font-size:20px}
.ss-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-bottom:18px}
.ss-card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 12px 30px -24px rgba(15,38,34,.5);display:flex;flex-direction:column;gap:14px;min-width:0}
.ss-card h2{margin:0;font-size:17px;font-weight:800}.ss-card p.sub{margin:-6px 0 0;color:var(--mu);font-size:13.5px;line-height:1.5}
.ss-f{display:flex;flex-direction:column;gap:6px}.ss-f label{font-size:13px;font-weight:700;color:var(--tx)}
.ss-f input,.ss-f select{width:100%;height:44px;padding:0 13px;border:0;border-radius:11px;background:#F1F6F4;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0}
.ss-f input:focus,.ss-f select:focus{background:#fff;box-shadow:0 0 0 2px rgba(18,140,126,.4)}
.ss-f small{font-size:12px;color:var(--mu)}
.ss-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 20px;border:0;border-radius:11px;background:var(--g);color:#fff;font:700 14px Inter,system-ui,sans-serif;cursor:pointer;align-self:flex-start;text-decoration:none}
.ss-btn.soft{background:#E6FCF2;color:var(--gd)}.ss-btn.danger{background:#FEEDED;color:#c62828}.ss-btn.xs{height:32px;padding:0 12px;font-size:12.5px;border-radius:9px}
.ss-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;font-size:12px;font-weight:800;white-space:nowrap}
.ss-pill.on{background:#E3F7EE;color:#0B6B5E}.ss-pill.off{background:#F1F3F2;color:#64748B}.ss-pill.warn{background:#FFF4E5;color:#B45309}
.ss-msg{padding:13px 16px;border-radius:14px;margin-bottom:16px;font-size:14px}.ss-msg.ok{background:#E6FCF2;color:#0B6B5E}.ss-msg.err{background:#FDECEC;color:#B42318}
.ss-copy{display:flex;flex-direction:column;gap:6px}
.ss-copy div{display:grid;grid-template-columns:180px minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px 10px;border-radius:10px;background:var(--soft)}
.ss-copy span{font-size:12.5px;font-weight:700}.ss-copy code{font-size:12.5px;color:var(--gd);overflow-wrap:anywhere}
.ss-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px}
.ss-tbl th{text-align:left;padding:10px 12px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--mu);background:#F3F7F5}
.ss-tbl th:first-child{border-radius:10px 0 0 10px}.ss-tbl th:last-child{border-radius:0 10px 10px 0}
.ss-tbl td{padding:11px 12px;vertical-align:middle}.ss-tbl tbody tr:nth-child(even) td{background:#F8FBFA}
.ss-tbl td small{display:block;color:var(--mu);font-size:12px}
@media (max-width:900px){.ss-grid,.ss-kpis{grid-template-columns:1fr}.ss-copy div{grid-template-columns:1fr auto}.ss-copy span{grid-column:1 / -1}}
</style>

<div class="ss">
  <div class="ss-head">
    <i><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></i>
    <div><h1>Shopify app</h1><p>The WAAMINI Shopify app keys used by every client to connect their store, optional per-client apps, and every client's Shopify connection.</p></div>
  </div>

  <?php if ($ok !== ''): ?><div class="ss-msg ok"><?php echo $h($ok); ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="ss-msg err"><?php echo $h($err); ?></div><?php endif; ?>

  <div class="ss-kpis">
    <div class="ss-kpi"><span>WAAMINI app keys</span><b><?php echo $cfg['source'] === 'none' ? '<span class="ss-pill warn">Not set</span>' : '<span class="ss-pill on">Ready</span>'; ?></b><span style="margin-top:4px"><?php echo $h($sourceTxt); ?></span></div>
    <div class="ss-kpi"><span>Clients with Shopify access</span><b><?php echo $accessN; ?></b></div>
    <div class="ss-kpi"><span>Stores connected</span><b><?php echo $connectedN; ?></b></div>
  </div>

  <div class="ss-grid">
    <form method="POST" class="ss-card">
      <?php echo csrf_field(); ?><input type="hidden" name="act" value="platform">
      <h2>WAAMINI Shopify app keys</h2>
      <p class="sub">From the Shopify Dev Dashboard &gt; your app &gt; Settings. Every client uses these unless they have their own app.</p>
      <div class="ss-f"><label for="ssId">Client ID</label><input id="ssId" name="client_id" value="<?php echo $h($platform['client_id'] ?: ($cfg['source'] === 'env' ? $cfg['client_id'] : '')); ?>" autocomplete="off" required></div>
      <div class="ss-f"><label for="ssSec">Client secret</label><input id="ssSec" type="password" name="client_secret" autocomplete="new-password" placeholder="<?php echo $platform['client_secret'] !== '' ? 'Saved (leave empty to keep)' : 'Paste the secret'; ?>"><small>Stored encrypted. Never shown again.</small></div>
      <button class="ss-btn" type="submit">Save keys</button>
    </form>
    <div class="ss-card">
      <h2>Paste these into the Shopify app</h2>
      <p class="sub">Dev Dashboard &gt; your app &gt; Versions. Untick "Embed app in Shopify admin", tick "Use legacy install flow", then Release.</p>
      <div class="ss-copy">
        <?php foreach ($copyRows as $label => $val): ?>
        <div><span><?php echo $h($label); ?></span><code><?php echo $h($val); ?></code><button type="button" class="ss-btn soft xs" data-copy="<?php echo $h($val); ?>">Copy</button></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="ss-card" style="margin-bottom:18px">
    <h2>Clients' Shopify connections</h2>
    <p class="sub">Shopify access comes from the plan (Plans &gt; Edit &gt; Shopify add-on) or Companies &gt; Edit &gt; Add-ons. "Own app" clients connect with their own Shopify app keys.</p>
    <div style="overflow-x:auto">
    <table class="ss-tbl">
      <thead><tr><th>Client</th><th>Plan</th><th>Access</th><th>Shopify app</th><th>Store</th><th>Last event</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?php echo $h($r['name']); ?></b><small>#<?php echo (int)$r['id']; ?> · <?php echo $h($r['login'] ?: 'no client login'); ?></small></td>
          <td><?php echo $h($r['plan'] ?: '—'); ?></td>
          <td><?php echo $r['access'] ? '<span class="ss-pill on">On</span>' : '<span class="ss-pill off">Off</span>'; ?></td>
          <td><?php echo !empty($r['use_own']) ? '<span class="ss-pill warn">Own app</span><small>' . $h(mb_substr((string)$r['own_id'], 0, 14)) . '…</small>' : 'WAAMINI app'; ?></td>
          <td><?php if ($r['shop_domain']): ?><?php echo $h($r['shop_domain']); ?><small><?php echo $r['st_status'] === 'active' ? ($r['webhooks_ok'] ? 'Connected · events OK' : 'Connected · events need attention') : 'Disconnected'; ?></small><?php else: ?><span style="color:var(--mu)">Not connected</span><?php endif; ?></td>
          <td><?php echo $r['last_event_at'] ? $h(date('d M, g:i A', strtotime((string)$r['last_event_at']))) : '—'; ?></td>
          <td><a class="ss-btn soft xs" href="settings_shopify.php?for=<?php echo (int)$r['id']; ?>#clientApp">Set app keys</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <form method="POST" class="ss-card" id="clientApp">
    <?php echo csrf_field(); ?><input type="hidden" name="act" value="client_app">
    <h2>Use a client's own Shopify app</h2>
    <p class="sub">Only when a client made their own app in the Shopify Dev Dashboard (no WAAMINI app review needed then). Give them the values from "Paste these into the Shopify app" above.</p>
    <div class="ss-f"><label for="ssFor">Client</label>
      <select id="ssFor" name="company_id" onchange="location.href='settings_shopify.php?for='+this.value+'#clientApp'">
        <option value="0">Choose a client…</option>
        <?php foreach ($rows as $r): ?><option value="<?php echo (int)$r['id']; ?>" <?php echo $for === (int)$r['id'] ? 'selected' : ''; ?>>#<?php echo (int)$r['id']; ?> <?php echo $h($r['name']); ?><?php echo $r['login'] ? ' (' . $h($r['login']) . ')' : ''; ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($forRow): ?>
      <div class="ss-f"><label for="ssCid">Client ID</label><input id="ssCid" name="client_id" value="<?php echo $h($forRow['own_id'] ?? ''); ?>" autocomplete="off"></div>
      <div class="ss-f"><label for="ssCsec">Client secret</label><input id="ssCsec" type="password" name="client_secret" autocomplete="new-password" placeholder="<?php echo !empty($forRow['own_id']) ? 'Saved (leave empty to keep)' : 'Paste the secret'; ?>"></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="ss-btn" type="submit">Save for this client</button>
        <?php if (!empty($forRow['use_own'])): ?><button class="ss-btn danger" type="submit" name="clear" value="1">Use WAAMINI app instead</button><?php endif; ?>
      </div>
    <?php endif; ?>
  </form>
</div>

<script nonce="<?php echo $h($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-copy]'); if (!b) return;
  var t = b.getAttribute('data-copy');
  if (navigator.clipboard) navigator.clipboard.writeText(t).catch(function () {});
  var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1400);
});
</script>
<?php include '../includes/admin_footer.php'; ?>
