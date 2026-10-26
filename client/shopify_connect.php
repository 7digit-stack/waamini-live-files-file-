<?php
/**
 * Integrations > Shopify. Also the Shopify app's "App URL": when a merchant opens WAAMINI from Shopify
 * (?shop=...&hmac=...) the connection starts straight away.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/shopify.php';
shopify_ensure_schema($conn);

$allowed = has_feature('shopify');
$companyId = (int)$companyId;
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/** Sends the merchant to Shopify's approval screen. */
$startOAuth = static function (string $shop) use ($companyId): void {
    $state = bin2hex(random_bytes(16));
    $_SESSION['shopify_oauth'] = ['state' => $state, 'shop' => $shop, 'company' => $companyId, 'at' => time()];
    $q = http_build_query([
        'client_id' => shopify_cfg($companyId)['client_id'], 'scope' => SHOPIFY_SCOPES,
        'redirect_uri' => shopify_redirect_url(), 'state' => $state,
    ]);
    header('Location: https://' . $shop . '/admin/oauth/authorize?' . $q);
    exit;
};
$go = static function (array $q = []): void { header('Location: shopify_connect.php' . ($q ? '?' . http_build_query($q) : '')); exit; };

$store = shopify_store_for_company($conn, $companyId);
$active = $store && $store['status'] === 'active' && !empty($store['access_token']);

// Opened from Shopify (App URL / after install)
if ($allowed && shopify_configured($companyId) && isset($_GET['shop'], $_GET['hmac']) && !isset($_GET['code'])) {
    $shop = shopify_normalize_shop((string)$_GET['shop']);
    if ($shop !== '' && shopify_verify_query($_GET, $companyId) && empty($viewOnlyMode)) {
        if (!$active || $store['shop_domain'] !== $shop) $startOAuth($shop);
        $go();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowed) {
    csrf_verify();
    if (!empty($viewOnlyMode)) $go(['err' => 'Your account is in view-only mode.']);
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'connect') {
        if (!shopify_configured($companyId)) $go(['err' => 'No Shopify app keys yet. Add your own app below, or ask the WAAMINI team to set up the WAAMINI Shopify app.']);
        $shop = shopify_normalize_shop((string)($_POST['shop'] ?? ''));
        if ($shop === '') $go(['err' => 'Enter your Shopify store address, for example mystore.myshopify.com']);
        $startOAuth($shop);
    }
    if ($act === 'app') {
        $useOwn = (string)($_POST['app_mode'] ?? 'waamini') === 'own' ? 1 : 0;
        $cid = trim((string)($_POST['app_client_id'] ?? ''));
        $sec = trim((string)($_POST['app_client_secret'] ?? ''));
        $cur = $conn->prepare("SELECT client_id, client_secret FROM shopify_apps WHERE company_id = ?");
        $cur->bind_param('i', $companyId); $cur->execute(); $old = $cur->get_result()->fetch_assoc() ?: [];
        if ($useOwn) {
            if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $cid)) $go(['err' => 'Enter the Client ID from your Shopify app.']);
            if ($sec === '' && empty($old['client_secret'])) $go(['err' => 'Enter the Client secret from your Shopify app.']);
        }
        $encSec = $sec !== '' ? EncryptionService::encrypt_secret($sec) : (string)($old['client_secret'] ?? '');
        $st = $conn->prepare("INSERT INTO shopify_apps (company_id, use_own, client_id, client_secret) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE use_own = VALUES(use_own), client_id = VALUES(client_id), client_secret = VALUES(client_secret)");
        $st->bind_param('iiss', $companyId, $useOwn, $cid, $encSec); $st->execute();
        $go(['ok' => $useOwn ? 'Your own Shopify app is saved. Click Connect Shopify to connect with it.' : 'Using the WAAMINI Shopify app.' ]);
    }
    if (!$store) $go();

    if ($act === 'save') {
        $templates = shopify_templates($conn, $companyId);
        $set = ['events' => [], 'cart_delay' => (int)($_POST['cart_delay'] ?? 60), 'add_contacts' => !empty($_POST['add_contacts'])];
        foreach (shopify_events() as $k => $e) {
            $tpl = (string)($_POST['tpl'][$k] ?? '');
            $on = !empty($_POST['on'][$k]);
            if ($tpl !== '' && !isset($templates[$tpl])) $tpl = '';
            $set['events'][$k] = ['on' => $on, 'template' => $tpl];
        }
        $json = json_encode($set);
        $u = $conn->prepare("UPDATE shopify_stores SET settings_json = ? WHERE id = ?");
        $u->bind_param('si', $json, $store['id']); $u->execute();
        $missing = array_filter($set['events'], static fn($e) => $e['on'] && $e['template'] === '');
        $go($missing ? ['ok' => 'Saved. Choose a template for every switched-on message, otherwise it is skipped.'] : ['ok' => 'Saved.']);
    }
    if ($act === 'recheck' && $active) {
        $r = shopify_register_webhooks($conn, $store);
        $go($r['ok'] ? ['ok' => 'All Shopify events are connected.'] : ['err' => 'Some events could not be connected: ' . mb_substr(implode('; ', $r['errors']), 0, 300)]);
    }
    if ($act === 'disconnect') {
        if ($active) {   // best effort: remove our webhooks from the store
            $tok = shopify_token($store);
            $ex = shopify_graphql($store['shop_domain'], $tok, '{ webhookSubscriptions(first: 50) { edges { node { id endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } } } } }');
            foreach ((array)($ex['data']['webhookSubscriptions']['edges'] ?? []) as $e) {
                if (($e['node']['endpoint']['callbackUrl'] ?? '') === shopify_webhook_url()) {
                    shopify_graphql($store['shop_domain'], $tok, 'mutation($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId } }', ['id' => $e['node']['id']]);
                }
            }
        }
        $u = $conn->prepare("UPDATE shopify_stores SET status = 'disconnected', access_token = NULL, webhooks_ok = 0, disconnected_at = NOW() WHERE id = ?");
        $u->bind_param('i', $store['id']); $u->execute();
        $go(['ok' => 'Disconnected. To remove the app completely, uninstall WAAMINI in Shopify > Settings > Apps.']);
    }
    if ($act === 'test') {
        $ev = (string)($_POST['event'] ?? 'order_created');
        $phone = wa_phone_normalize((string)($_POST['phone'] ?? ''), '91');
        if (!isset(shopify_events()[$ev]) || $phone === '') $go(['err' => 'Enter a valid WhatsApp number for the test.']);
        $samples = [
            'order_created' => ['Rahul', '#1001', '₹1,499.00'], 'order_cod' => ['Rahul', '#1001', '₹1,499.00'],
            'order_shipped' => ['Rahul', '#1001', 'https://track.example.com/AB123', 'Delhivery'],
            'order_cancelled' => ['Rahul', '#1001'], 'cart_reminder' => ['Rahul', 'https://' . $store['shop_domain'] . '/checkouts/test', '₹1,499.00'],
        ][$ev];
        $name = (string)($store['shop_name'] ?: $store['shop_domain']);
        if (in_array($ev, ['order_created', 'order_cod', 'cart_reminder'], true)) $samples[] = $name;
        if ($ev === 'order_cancelled') $samples[] = $name;
        $forced = $store; $s = shopify_settings($store); $s['events'][$ev]['on'] = true; $forced['settings_json'] = json_encode($s);
        $r = shopify_queue($conn, $forced, $ev, $phone, 0, $samples);
        $go($r['ok'] ? ['ok' => 'Test message queued for ' . $phone . '. It is sent within a minute.'] : ['err' => 'Test not sent: ' . $r['note']]);
    }
    $go();
}

$settings = shopify_settings($store);
$templates = $allowed ? shopify_templates($conn, $companyId) : [];
$events = [];
$pending = 0;
if ($store) {
    $st = $conn->prepare("SELECT topic, status, note, created_at FROM shopify_events WHERE company_id = ? ORDER BY id DESC LIMIT 15");
    $st->bind_param('i', $companyId); $st->execute(); $events = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $conn->prepare("SELECT COUNT(*) FROM shopify_checkouts WHERE company_id = ? AND completed = 0 AND reminded_at IS NULL");
    $st->bind_param('i', $companyId); $st->execute(); $pending = (int)$st->get_result()->fetch_row()[0];
}
$ok = (string)($_GET['ok'] ?? ''); $err = (string)($_GET['err'] ?? '');

$I = [
    'bag'   => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    'link'  => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'chat'  => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    'send'  => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    'list'  => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
    'copy'  => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
];
$ic = static fn(string $k, int $s = 18) => '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $I[$k] . '</svg>';

$pageTitle = 'Shopify';
$currentPage = 'shopify';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
<style>
.shp .su-head-ic{width:52px;height:52px;border-radius:15px;background:#95BF47;color:#fff;display:grid;place-items:center;flex:none}
.shp .pill{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:99px;font-size:12.5px;font-weight:800}
.shp .pill i{width:8px;height:8px;border-radius:50%;background:currentColor}
.shp .pill.on{background:#E3F7EE;color:#0B6B5E}.shp .pill.off{background:#F1F3F2;color:#64748B}.shp .pill.warn{background:#FFF4E5;color:#B45309}
.shp .msg{display:flex;gap:10px;align-items:flex-start;padding:13px 16px;border-radius:14px;margin-bottom:16px;font-size:14px;line-height:1.5}
.shp .msg.ok{background:#E6FCF2;color:#0B6B5E}.shp .msg.err{background:#FDECEC;color:#B42318}
.shp .steps{list-style:none;margin:0;padding:0;display:grid;gap:10px;counter-reset:s}
.shp .steps li{display:flex;gap:12px;font-size:14px;line-height:1.5;color:var(--tx)}
.shp .steps li::before{counter-increment:s;content:counter(s);flex:none;width:26px;height:26px;border-radius:50%;background:#E3F7EE;color:var(--gd);font-weight:800;font-size:13px;display:grid;place-items:center}
.shp .kv{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:14px}
.shp .kv span{color:var(--mu)}.shp .kv b{color:var(--ink);font-weight:700;min-width:0;overflow-wrap:anywhere}
.shp .ev{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(200px,260px);gap:14px;align-items:center;padding:14px;border-radius:14px;background:var(--soft)}
.shp .ev+.ev{margin-top:10px}
.shp .ev b{display:block;font-size:15px}.shp .ev small{display:block;color:var(--mu);font-size:12.5px;line-height:1.45;margin-top:2px}
.shp .ev details{grid-column:2 / -1;font-size:12.5px;color:var(--tx)}
.shp .ev details summary{cursor:pointer;color:var(--gd);font-weight:700}
.shp .ev pre{white-space:pre-wrap;margin:8px 0 0;padding:10px 12px;border-radius:10px;background:#fff;font:13px/1.5 Inter,system-ui,sans-serif}
.shp .vars{margin:6px 0 0;padding-left:18px}
.shp .tg{position:relative;width:46px;height:26px;flex:none}
.shp .tg input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2;margin:0}
.shp .tg span{position:absolute;inset:0;border-radius:99px;background:#C7D1CD;transition:background .2s}
.shp .tg span::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.shp .tg input:checked+span{background:#128C7E}.shp .tg input:checked+span::after{transform:translateX(20px)}
.shp .log{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.shp .log td{padding:9px 10px;vertical-align:top}.shp .log tr:nth-child(even) td{background:#F8FBFA}
.shp .log td:first-child{white-space:nowrap;color:var(--mu)}.shp .log code{font-size:12px;color:var(--gd)}
.shp .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:760px){.shp .log,.shp .log tbody,.shp .log tr,.shp .log td{display:block}.shp .log tr{padding:10px 12px;border-radius:12px;margin-bottom:6px;background:#F8FBFA}.shp .log tr:nth-child(even) td{background:transparent}.shp .log td{padding:2px 0}.shp .ev{grid-template-columns:auto minmax(0,1fr)}.shp .ev .su-f{grid-column:1 / -1}.shp .ev details{grid-column:1 / -1}.shp .row2{grid-template-columns:1fr}}
</style>

<div class="su shp">
  <div class="su-head">
    <div class="su-head-l">
      <span class="su-head-ic"><?php echo $ic('bag', 26); ?></span>
      <div>
        <h1>Shopify</h1>
        <p>Send WhatsApp order confirmations, COD checks, shipping updates and abandoned-checkout reminders automatically from your Shopify store.</p>
      </div>
    </div>
    <?php if ($active): ?><span class="pill on"><i></i>Connected</span>
    <?php elseif ($store): ?><span class="pill off"><i></i>Disconnected</span>
    <?php else: ?><span class="pill off"><i></i>Not connected</span><?php endif; ?>
  </div>

  <?php if ($ok !== ''): ?><div class="msg ok"><?php echo $ic('check'); ?><span><?php echo $h($ok); ?></span></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="msg err"><?php echo $ic('alert'); ?><span><?php echo $h($err); ?></span></div><?php endif; ?>

  <?php if (!$allowed): ?>
    <div class="su-card"><h2>Shopify is an add-on</h2><p class="sub">Your plan does not include Shopify yet. Upgrade your plan or ask the WAAMINI team to switch it on for your account.</p><a class="su-btn" href="subscription.php" style="align-self:flex-start">See plans</a></div>
  <?php elseif (!shopify_configured($companyId)): ?>
    <div class="msg err"><?php echo $ic('alert'); ?><span>No Shopify app keys yet. Use your own Shopify app (card below), or ask the WAAMINI team to add the WAAMINI Shopify app in Admin &gt; Shopify app.</span></div>
  <?php endif; ?>

  <?php if ($allowed && !$active): ?>
  <div class="su-grid">
    <div class="su-card">
      <h2><span class="ch"><?php echo $ic('link'); ?></span>Connect your store</h2>
      <p class="sub">You will be taken to Shopify to approve access, then brought back here.</p>
      <form method="POST" class="su-f">
        <?php echo csrf_field(); ?><input type="hidden" name="act" value="connect">
        <label class="l" for="shpShop">Store address</label>
        <input type="text" id="shpShop" name="shop" placeholder="mystore.myshopify.com" value="<?php echo $h($store['shop_domain'] ?? ''); ?>" required autocomplete="off">
        <span class="hint">Find it in Shopify admin &gt; Settings &gt; Domains (the address that ends with .myshopify.com).</span>
        <button class="su-btn" type="submit" style="margin-top:6px" <?php echo shopify_configured($companyId) ? '' : 'disabled'; ?>><?php echo $ic('link'); ?> Connect Shopify</button>
      </form>
    </div>
    <div class="su-card">
      <h2><span class="ch"><?php echo $ic('chat'); ?></span>What happens next</h2>
      <ol class="steps">
        <li>Approve WAAMINI on Shopify's screen (read-only access to orders, customers and checkouts).</li>
        <li>WAAMINI connects to your store's order, shipping and checkout events automatically.</li>
        <li>Pick an approved WhatsApp template for each message below and switch it on.</li>
        <li>Customers get WhatsApp updates from your own WhatsApp number.</li>
      </ol>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($allowed && $active): ?>
  <div class="su-grid even">
    <div class="su-card">
      <h2><span class="ch"><?php echo $ic('bag'); ?></span><?php echo $h($store['shop_name'] ?: $store['shop_domain']); ?></h2>
      <div class="kv">
        <span>Store</span><b><?php echo $h($store['shop_domain']); ?></b>
        <span>Connected</span><b><?php echo $h(date('d M Y, g:i A', strtotime((string)$store['connected_at']))); ?></b>
        <span>Events</span><b><?php echo $store['webhooks_ok'] ? '<span class="pill on"><i></i>' . $h($store['webhook_note'] ?: 'Connected') . '</span>' : '<span class="pill warn"><i></i>Needs attention</span>'; ?></b>
        <?php if (!$store['webhooks_ok'] && $store['webhook_note']): ?><span></span><b style="font-weight:500;color:#B42318;font-size:13px"><?php echo $h($store['webhook_note']); ?></b><?php endif; ?>
        <span>Last event</span><b><?php echo $store['last_event_at'] ? $h(date('d M, g:i A', strtotime((string)$store['last_event_at']))) : 'None yet: place a test order'; ?></b>
        <span>Checkouts waiting</span><b><?php echo $pending; ?></b>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="act" value="recheck"><button class="su-btn soft sm" type="submit">Re-check events</button></form>
        <form method="POST" data-confirm="Disconnect this Shopify store? WhatsApp updates stop until you connect again."><?php echo csrf_field(); ?><input type="hidden" name="act" value="disconnect"><button class="su-btn danger sm" type="submit">Disconnect</button></form>
      </div>
    </div>
    <div class="su-card">
      <h2><span class="ch"><?php echo $ic('send'); ?></span>Send a test</h2>
      <p class="sub">Queues the chosen message with sample order details to your WhatsApp.</p>
      <form method="POST" class="su-f" style="gap:12px">
        <?php echo csrf_field(); ?><input type="hidden" name="act" value="test">
        <div class="row2">
          <div class="su-f"><label class="l" for="shpTestPhone">Your WhatsApp number</label><input type="tel" id="shpTestPhone" name="phone" placeholder="+91 98765 43210" required></div>
          <div class="su-f"><label class="l" for="shpTestEv">Message</label>
            <select id="shpTestEv" name="event"><?php foreach (shopify_events() as $k => $e): ?><option value="<?php echo $k; ?>"><?php echo $h($e['label']); ?></option><?php endforeach; ?></select></div>
        </div>
        <button class="su-btn sm" type="submit" style="align-self:flex-start"><?php echo $ic('send', 16); ?> Send test</button>
      </form>
    </div>
  </div>

  <form method="POST" class="su-card" style="margin-top:18px">
    <?php echo csrf_field(); ?><input type="hidden" name="act" value="save">
    <h2><span class="ch"><?php echo $ic('chat'); ?></span>Automatic WhatsApp messages</h2>
    <p class="sub">Each message uses one of your <b>approved</b> WhatsApp templates. No template yet? Open the sample, create it in <a href="templates.php">Templates</a> with the same {{1}}, {{2}} order, and come back after Meta approves it.<?php if (!$templates): ?> <b style="color:#B45309">You have no approved templates yet.</b><?php endif; ?></p>
    <div>
    <?php foreach (shopify_events() as $k => $e): $cur = $settings['events'][$k]; ?>
      <div class="ev">
        <label class="tg" title="Switch on/off"><input type="checkbox" name="on[<?php echo $k; ?>]" value="1" <?php echo $cur['on'] ? 'checked' : ''; ?>><span></span></label>
        <div><b><?php echo $h($e['label']); ?></b><small><?php echo $h($e['desc']); ?></small></div>
        <div class="su-f"><select name="tpl[<?php echo $k; ?>]" aria-label="Template for <?php echo $h($e['label']); ?>">
          <option value="">Choose template…</option>
          <?php foreach ($templates as $tn => $t): ?><option value="<?php echo $h($tn); ?>" <?php echo $cur['template'] === $tn ? 'selected' : ''; ?>><?php echo $h($tn); ?> · <?php echo (int)$t['vars']; ?> vars</option><?php endforeach; ?>
          <?php if ($cur['template'] !== '' && !isset($templates[$cur['template']])): ?><option value="" selected><?php echo $h($cur['template']); ?> (not approved)</option><?php endif; ?>
        </select></div>
        <details><summary>Variables &amp; sample template (<?php echo $h(ucfirst(strtolower($e['category']))); ?>)</summary>
          <ol class="vars"><?php foreach ($e['vars'] as $i => $v): ?><li><code>{{<?php echo $i + 1; ?>}}</code> <?php echo $h($v); ?></li><?php endforeach; ?></ol>
          <pre><?php echo $h($e['sample']); ?></pre>
        </details>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="row2">
      <div class="su-f"><label class="l" for="shpDelay">Send the checkout reminder after</label>
        <select id="shpDelay" name="cart_delay"><?php foreach ([30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 720 => '12 hours', 1440 => '24 hours'] as $m => $l): ?><option value="<?php echo $m; ?>" <?php echo $settings['cart_delay'] === $m ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
        <span class="hint">Only if the shopper did not finish paying. Each checkout gets one reminder.</span></div>
      <div class="su-f"><label class="l">Contacts</label>
        <label style="display:flex;gap:10px;align-items:center;font-size:14px"><span class="tg"><input type="checkbox" name="add_contacts" value="1" <?php echo $settings['add_contacts'] ? 'checked' : ''; ?>><span></span></span> Add new Shopify customers to Contacts (label "Shopify")</label></div>
    </div>
    <button class="su-btn" type="submit" style="align-self:flex-start">Save messages</button>
  </form>

  <div class="su-card" style="margin-top:18px">
    <h2><span class="ch"><?php echo $ic('list'); ?></span>Recent Shopify activity</h2>
    <?php if (!$events): ?><p class="sub">Nothing yet. Place a test order in your store and it appears here within seconds.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="log">
      <?php foreach ($events as $ev): ?><tr><td><?php echo $h(date('d M, g:i:s A', strtotime((string)$ev['created_at']))); ?></td><td><code><?php echo $h($ev['topic']); ?></code></td><td><?php echo $h($ev['note'] ?: $ev['status']); ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($allowed):
    $ownRow = (function () use ($conn, $companyId) { $st = $conn->prepare("SELECT use_own, client_id, client_secret FROM shopify_apps WHERE company_id = ?"); $st->bind_param('i', $companyId); $st->execute(); return $st->get_result()->fetch_assoc() ?: []; })();
    $useOwn = !empty($ownRow['use_own']);
    $platformOk = shopify_configured(0);
    $copyRows = [
      'App URL' => shopify_base_url() . '/client/shopify_connect.php',
      'Allowed redirection URL' => shopify_redirect_url(),
      'Scopes' => SHOPIFY_SCOPES,
      'Compliance webhooks (all 3)' => shopify_webhook_url(),
    ];
  ?>
  <form method="POST" class="su-card" style="margin-top:18px" id="shpApp">
    <?php echo csrf_field(); ?><input type="hidden" name="act" value="app">
    <h2><span class="ch"><?php echo $ic('link'); ?></span>Shopify app</h2>
    <p class="sub">Choose which Shopify app connects your store. Most businesses use the WAAMINI app. Use your own app if you made one in the Shopify Dev Dashboard.</p>
    <div class="row2">
      <label class="shp-opt<?php echo !$useOwn ? ' on' : ''; ?>"><input type="radio" name="app_mode" value="waamini" <?php echo !$useOwn ? 'checked' : ''; ?>>
        <span><b>WAAMINI Shopify app</b><small><?php echo $platformOk ? 'Ready. Nothing else to set up.' : 'Not set up by the WAAMINI team yet.'; ?></small></span></label>
      <label class="shp-opt<?php echo $useOwn ? ' on' : ''; ?>"><input type="radio" name="app_mode" value="own" <?php echo $useOwn ? 'checked' : ''; ?>>
        <span><b>My own Shopify app</b><small>Use the Client ID and secret of an app you created.</small></span></label>
    </div>
    <div class="shp-own" <?php echo $useOwn ? '' : 'hidden'; ?>>
      <div class="row2">
        <div class="su-f"><label class="l" for="shpCid">Client ID</label><input type="text" id="shpCid" name="app_client_id" value="<?php echo $h($ownRow['client_id'] ?? ''); ?>" autocomplete="off" placeholder="From Dev Dashboard &gt; your app &gt; Settings"></div>
        <div class="su-f"><label class="l" for="shpSec">Client secret</label><input type="password" id="shpSec" name="app_client_secret" value="" autocomplete="new-password" placeholder="<?php echo !empty($ownRow['client_secret']) ? 'Saved (leave empty to keep)' : 'Paste the secret'; ?>"><span class="hint">Stored encrypted. Never shown again.</span></div>
      </div>
      <p class="sub" style="margin:4px 0 0">In your Shopify app's settings, paste these values exactly, untick "Embed app in Shopify admin" and tick "Use legacy install flow":</p>
      <div class="shp-copy">
        <?php foreach ($copyRows as $label => $val): ?>
        <div><span><?php echo $h($label); ?></span><code><?php echo $h($val); ?></code><button type="button" class="su-btn soft xs" data-copy="<?php echo $h($val); ?>"><?php echo $ic('copy', 14); ?> Copy</button></div>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="su-btn sm" type="submit" style="align-self:flex-start">Save Shopify app</button>
  </form>
  <style>
  .shp .shp-opt{display:flex;gap:12px;align-items:flex-start;padding:14px;border-radius:14px;background:var(--soft);cursor:pointer;box-shadow:inset 0 0 0 2px transparent}
  .shp .shp-opt.on{background:#E6FCF2;box-shadow:inset 0 0 0 2px #128C7E}
  .shp .shp-opt input{margin-top:3px;accent-color:#128C7E}
  .shp .shp-opt b{display:block;font-size:14.5px}.shp .shp-opt small{display:block;color:var(--mu);font-size:12.5px;margin-top:2px}
  .shp .shp-own{display:flex;flex-direction:column;gap:12px}.shp .shp-own[hidden]{display:none}
  .shp .shp-copy{display:flex;flex-direction:column;gap:6px}
  .shp .shp-copy div{display:grid;grid-template-columns:190px minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px 10px;border-radius:10px;background:var(--soft)}
  .shp .shp-copy span{font-size:12.5px;font-weight:700;color:var(--tx)}
  .shp .shp-copy code{font-size:12.5px;color:var(--gd);overflow-wrap:anywhere}
  @media (max-width:760px){.shp .shp-copy div{grid-template-columns:1fr auto}.shp .shp-copy span{grid-column:1 / -1}}
  </style>
  <?php endif; ?>
</div>

<script nonce="<?php echo $h($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('submit', function (e) {
  var f = e.target.closest && e.target.closest('form[data-confirm]');
  if (f && !confirm(f.getAttribute('data-confirm'))) e.preventDefault();
});
(function () {
  var form = document.getElementById('shpApp'); if (!form) return;
  var own = form.querySelector('.shp-own');
  form.querySelectorAll('input[name=app_mode]').forEach(function (r) {
    r.addEventListener('change', function () {
      own.hidden = form.querySelector('input[name=app_mode]:checked').value !== 'own';
      form.querySelectorAll('.shp-opt').forEach(function (o) { o.classList.toggle('on', o.querySelector('input').checked); });
    });
  });
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    var t = b.getAttribute('data-copy');
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).catch(function () {
      var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) {} ta.remove();
    }).then(function () {});
    var old = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = old; }, 1400);
  });
})();
</script>
<?php include '../includes/client_footer.php'; ?>
