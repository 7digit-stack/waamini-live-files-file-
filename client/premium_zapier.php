<?php
include '../includes/client_auth_check.php';

if (!has_feature('api_webhook')) {
    header('Location: dashboard.php');
    exit();
}

// Bug #1 fixed: plain text title — was raw SVG string that rendered in <title> tag literally
$pageTitle   = 'Zapier & Webhook Integration';
$currentPage = 'premium_zapier';

$success = '';
$error   = '';

/**
 * Public base URL for this Waamini install (no trailing slash).
 * Prefers APP_PUBLIC_URL from .env; otherwise derived from script path.
 */
$waZapierBaseUrl = static function (): string {
    $env = trim((string)($_ENV['APP_PUBLIC_URL'] ?? getenv('APP_PUBLIC_URL') ?? ''));
    if ($env !== '') return rtrim($env, '/');
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $proto  = $https ? 'https' : 'http';
    $host   = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $root   = str_replace('\\', '/', dirname(dirname($script)));
    if ($root === '/' || $root === '.') $root = '';
    return $proto . '://' . $host . $root;
};

$buildZapierCatchUrl = static function (int $cid, string $key) use ($waZapierBaseUrl): string {
    return $waZapierBaseUrl() . '/api/zapier_catch.php?company_id=' . $cid . '&api_key=' . rawurlencode($key);
};

// Ensure an API key exists; auto-create if not
$apiKey = '';
$st10a  = $conn->prepare("SELECT api_key FROM api_keys WHERE company_id = ? LIMIT 1");
$apiRow = null;
if ($st10a) {
    $st10a->bind_param("i", $companyId);
    $st10a->execute();
    $apiRow = $st10a->get_result()->fetch_assoc();
}
if (!$apiRow) {
    $newKey = bin2hex(random_bytes(32));
    $stmt   = $conn->prepare("INSERT INTO api_keys (company_id, api_key, is_active) VALUES (?, ?, 1)");
    if ($stmt) { $stmt->bind_param("is", $companyId, $newKey); $stmt->execute(); }
    $apiKey = $newKey;
} else {
    $apiKey = $apiRow['api_key'];
}

// Ensure a zapier_webhooks row exists
$zapSetting = null;
$st10b      = $conn->prepare("SELECT * FROM zapier_webhooks WHERE company_id = ? LIMIT 1");
if ($st10b) {
    $st10b->bind_param("i", $companyId);
    $st10b->execute();
    $zapSetting = $st10b->get_result()->fetch_assoc();
}
if (!$zapSetting) {
    $webhookUrl = $buildZapierCatchUrl((int)$companyId, (string)$apiKey);
    $stmt       = $conn->prepare("INSERT INTO zapier_webhooks (company_id, webhook_url, trigger_event) VALUES (?, ?, 'new_lead')");
    if ($stmt) { $stmt->bind_param("is", $companyId, $webhookUrl); $stmt->execute(); }
    $st10c = $conn->prepare("SELECT * FROM zapier_webhooks WHERE company_id = ? LIMIT 1");
    if ($st10c) {
        $st10c->bind_param("i", $companyId);
        $st10c->execute();
        $zapSetting = $st10c->get_result()->fetch_assoc();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_webhook'])) {
    csrf_verify();
    $newKey = bin2hex(random_bytes(32));
    $s1     = $conn->prepare("UPDATE api_keys SET api_key = ? WHERE company_id = ?");
    if ($s1) { $s1->bind_param("si", $newKey, $companyId); $s1->execute(); }
    $newUrl = $buildZapierCatchUrl((int)$companyId, (string)$newKey);
    $s2     = $conn->prepare("UPDATE zapier_webhooks SET webhook_url = ? WHERE company_id = ?");
    if ($s2) { $s2->bind_param("si", $newUrl, $companyId); $s2->execute(); }
    $success                 = 'Webhook URL regenerated. Update all connected apps with the new URL.';
    $zapSetting['webhook_url'] = $newUrl;
}

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'link'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'key'     => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    'copy'    => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'eye'     => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'shield'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'check'   => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'   => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'code'    => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    'ext'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'bolt'    => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'users'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
];
$fullUrl = (string)($zapSetting['webhook_url'] ?? '');
$maskedUrl = $fullUrl !== '' ? preg_replace('/(api_key=)[^&]+/', '$1' . str_repeat('•', 24), $fullUrl) : '';
$secret = (string)$apiKey;
$maskedSecret = $secret !== '' ? str_repeat('•', 28) . substr($secret, -4) : '';
$endpoint = $fullUrl;

$fields = [
    ['phone', 'Required', 'Customer number with country code, digits only. Example: 919876543210'],
    ['message', 'Required', 'The text message to send on WhatsApp.'],
    ['name', 'Optional', 'Saved on the contact. Defaults to "Zapier Lead" for new contacts.'],
    ['email', 'Optional', 'Saved on the contact for new leads.'],
];
$apps = ['Zapier', 'Pabbly Connect', 'Make.com', 'n8n', 'Integrately', 'ActivePieces', 'Node.js', 'Python', 'Postman'];
$cases = [
    ['Facebook Lead Ads', 'WhatsApp every new lead within seconds of submission.'],
    ['Shopify orders', 'Send the order confirmation and tracking link automatically.'],
    ['WooCommerce', 'Trigger order updates: confirmed, shipped, delivered.'],
    ['Google Sheets', 'Add a row and send a personalised message to that contact.'],
    ['Typeform / Tally', 'New form submission, instant WhatsApp follow-up.'],
];
?>

<div class="db zp">

  <div class="db-head">
    <div>
      <h1>Zapier &amp; Webhook Integration</h1>
      <p>Send WhatsApp messages from 5,000+ apps through Zapier, Pabbly Connect, Make.com or your own code.</p>
    </div>
    <div class="db-head__actions">
      <a href="integrations.php" class="db-btn"><?php echo $__ic($I['users'], 16); ?> All integrations</a>
      <a href="https://zapier.com/apps/webhook/integrations" target="_blank" rel="noopener" class="db-btn"><?php echo $__ic($I['ext'], 16); ?> Zapier webhook guide</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="zp-toast is-ok" role="status"><?php echo $__ic($I['check'], 18); ?> <span><?php echo $esc($success); ?></span></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="zp-toast is-err" role="alert"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($error); ?></span></div>
  <?php endif; ?>

  <section class="zp-stats">
    <div class="zp-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($I['bolt'], 20); ?></span><div><span>Endpoint</span><b>Active</b></div></div>
    <div class="zp-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($I['link'], 20); ?></span><div><span>Method</span><b>POST &middot; JSON</b></div></div>
    <div class="zp-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($I['shield'], 20); ?></span><div><span>Security</span><b>HMAC signed</b></div></div>
    <div class="zp-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($I['key'], 20); ?></span><div><span>Key ends in</span><b><?php echo $esc(substr($secret, -4)); ?></b></div></div>
  </section>

  <div class="zp-grid">
    <div class="zp-main">

      <!-- credentials -->
      <section class="db-card zp-card">
        <div class="zp-ch"><h2>Your webhook</h2><p>Paste these into your automation app. Keep them private: anyone with them can send messages from your number.</p></div>

        <div class="zp-lab">Webhook URL</div>
        <div class="zp-copy">
          <span class="zp-copy__ic"><?php echo $__ic($I['link'], 16); ?></span>
          <input type="text" id="zpUrl" readonly value="<?php echo $esc($maskedUrl); ?>" aria-label="Webhook URL (secret hidden)">
          <button type="button" class="zp-mini" id="zpUrlShow" aria-pressed="false"><?php echo $__ic($I['eye'], 14); ?> <span>Show</span></button>
          <button type="button" class="zp-mini zp-mini--p" data-copy="url"><?php echo $__ic($I['copy'], 14); ?> <span>Copy</span></button>
        </div>

        <div class="zp-lab" style="margin-top:16px">Signing secret <span class="zp-opt">used to sign every request</span></div>
        <div class="zp-copy">
          <span class="zp-copy__ic"><?php echo $__ic($I['key'], 16); ?></span>
          <input type="text" id="zpSecret" readonly value="<?php echo $esc($maskedSecret); ?>" aria-label="Signing secret (hidden)">
          <button type="button" class="zp-mini" id="zpSecShow" aria-pressed="false"><?php echo $__ic($I['eye'], 14); ?> <span>Show</span></button>
          <button type="button" class="zp-mini zp-mini--p" data-copy="secret"><?php echo $__ic($I['copy'], 14); ?> <span>Copy</span></button>
        </div>

        <div class="zp-note"><?php echo $__ic($I['shield'], 16); ?><span>Requests must include an <b>X-WAAMINI-Signature</b> header: the HMAC-SHA256 of the raw request body, using the signing secret, in hex. Requests without a valid signature are rejected with 403. Examples are below.</span></div>
      </section>

      <!-- fields -->
      <section class="db-card zp-card">
        <div class="zp-ch"><h2>Request body</h2><p>Send a JSON <b>POST</b> to the webhook URL.</p></div>
        <div style="overflow-x:auto">
          <table class="zp-table"><thead><tr><th>Field</th><th>Required</th><th>What it does</th></tr></thead><tbody>
            <?php foreach ($fields as [$f, $req, $d]): ?>
              <tr><td><code><?php echo $esc($f); ?></code></td><td><span class="db-pill <?php echo $req === 'Required' ? 'is-bad' : 'is-off'; ?>"><?php echo $esc($req); ?></span></td><td><?php echo $esc($d); ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <div class="zp-lab" style="margin-top:18px">Example body</div>
        <pre class="zp-code" id="zpBody">{
  "phone": "919876543210",
  "message": "Hello from Zapier!",
  "name": "John Doe",
  "email": "john@example.com"
}</pre>
        <div class="zp-res">
          <div><span class="db-pill is-ok">200</span> <code>{"status":"success"}</code> The message was accepted and queued for delivery.</div>
          <div><span class="db-pill is-bad">400</span> <code>phone</code> or <code>message</code> is missing.</div>
          <div><span class="db-pill is-bad">403</span> Wrong key or missing or invalid signature.</div>
        </div>
      </section>

      <!-- code samples -->
      <section class="db-card zp-card">
        <div class="zp-ch"><h2>Code examples</h2><p>Each one signs the request for you. Replace the message and phone as needed.</p></div>
        <div class="zp-tabs" id="zpTabs">
          <button type="button" class="is-active" data-t="curl">cURL</button>
          <button type="button" data-t="node">Node.js</button>
          <button type="button" data-t="python">Python</button>
          <button type="button" data-t="php">PHP</button>
          <button type="button" data-t="zapier">Zapier Code step</button>
        </div>
        <div class="zp-codewrap">
          <button type="button" class="zp-mini zp-mini--p zp-codecopy" data-copy="code"><?php echo $__ic($I['copy'], 14); ?> <span>Copy</span></button>
          <pre class="zp-code" id="zpCode"></pre>
        </div>
        <p class="zp-hint">Samples use your real URL and secret only after you press <b>Show</b> above, otherwise they use placeholders. Never paste your secret in public places.</p>
      </section>
    </div>

    <aside class="zp-side">
      <section class="db-card zp-card">
        <div class="zp-ch"><h2>Setup guide</h2><p>Zapier, Pabbly Connect or Make.com</p></div>
        <ol class="zp-steps">
          <li><span>1</span><div><b>Copy the URL and secret</b><p>Use the Show and Copy buttons on the left.</p></div></li>
          <li><span>2</span><div><b>Create a Zap or scenario</b><p>Pick your trigger app, such as Facebook Lead Ads, Shopify or Google Sheets.</p></div></li>
          <li><span>3</span><div><b>Add a webhook or code step</b><p>"Webhooks by Zapier" can post JSON. Because requests must be signed, add a Code step first (see Zapier Code step) or use Pabbly or Make.com's HMAC option.</p></div></li>
          <li><span>4</span><div><b>Map your fields</b><p>Send the customer's number as <code>phone</code> and your text as <code>message</code>.</p></div></li>
          <li><span>5</span><div><b>Test and turn on</b><p>Run a test. A new contact tagged "Zapier Lead" appears and the message is delivered within moments.</p></div></li>
        </ol>
      </section>

      <section class="db-card zp-card">
        <div class="zp-ch"><h2>Common uses</h2></div>
        <div class="zp-uses">
          <?php foreach ($cases as [$t, $d]): ?><div><b><?php echo $esc($t); ?></b><p><?php echo $esc($d); ?></p></div><?php endforeach; ?>
        </div>
        <div class="zp-apps"><?php foreach ($apps as $a): ?><span><?php echo $esc($a); ?></span><?php endforeach; ?></div>
      </section>

      <section class="db-card zp-card zp-danger">
        <div class="zp-ch"><h2>Regenerate credentials</h2><p>Creates a new URL and secret. The old ones stop working immediately, so every connected app must be updated.</p></div>
        <form method="POST" id="zpRegen">
          <?php echo csrf_field(); ?>
          <button type="submit" name="regenerate_webhook" class="zp-regen"><?php echo $__ic($I['refresh'], 16); ?> Regenerate</button>
        </form>
      </section>
    </aside>
  </div>
</div>

<style>
.zp{max-width:none;margin:0}
.zp-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.zp-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.zp-toast.is-err{background:#FDE4E1;color:#c0372b}.zp-toast svg{margin-top:2px;flex:none}
.zp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.zp-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.zp-stat .db-kpi__ic{margin:0}
.zp-stat span:not(.db-kpi__ic){display:block;font-size:13px;font-weight:600;color:var(--mu)}
.zp-stat b{display:block;margin-top:1px;font-size:21px;font-weight:800;letter-spacing:-.3px;color:var(--ink);white-space:nowrap}
.zp-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;align-items:start}
.zp-main,.zp-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.zp-card{padding:22px}
.zp-ch{margin-bottom:16px}
.zp-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.zp-ch p{margin:4px 0 0;font-size:13.5px;color:var(--mu);line-height:1.55}
.zp-lab{margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.zp-opt{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:6px}
.zp-copy{display:flex;align-items:center;gap:8px;padding:8px 8px 8px 14px;border:1.5px solid var(--line);border-radius:12px;background:#fff}
.zp-copy:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.zp-copy__ic{color:var(--g);display:flex;flex:none}
.zp-copy input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:600 13px ui-monospace,Consolas,monospace;color:var(--ink);height:34px}
.zp-mini{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:0;border-radius:9px;background:var(--soft);color:var(--tx);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;white-space:nowrap;flex:none;transition:background .15s,color .15s}
.zp-mini:hover{background:#E6FCF2;color:var(--gd)}
.zp-mini--p{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.zp-mini--p:hover{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;filter:brightness(1.08)}
.zp-mini.is-done{background:#DDF7E7!important;color:#12803f!important}
.zp-note{display:flex;gap:10px;align-items:flex-start;margin-top:16px;padding:13px 16px;border-radius:12px;background:#E4EFFD;color:#1d3a6e;font-size:13px;line-height:1.55}
.zp-note svg{margin-top:2px;flex:none}
.zp-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
.zp-table th{padding:11px 14px;background:#F4F7F6;font-size:13px;font-weight:700;color:var(--tx);text-align:left}
.zp-table th:first-child{border-radius:10px 0 0 10px}.zp-table th:last-child{border-radius:0 10px 10px 0}
.zp-table td{padding:13px 14px;color:var(--tx);vertical-align:top}
.zp-table tbody tr:nth-child(even) td{background:#F8FCFA}
.zp-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}.zp-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.zp code{font:600 12.5px ui-monospace,Consolas,monospace;background:var(--soft);padding:2px 7px;border-radius:6px;color:var(--gd)}
.zp-vs{border-radius:12px;overflow:hidden;background:#1e1e1e;box-shadow:0 14px 30px -18px rgba(0,0,0,.6)}
.zp-vs__bar{display:flex;align-items:center;gap:7px;height:38px;padding:0 14px;background:#252526}
.zp-vs__bar i{width:11px;height:11px;border-radius:50%;display:inline-block}
.zp-vs__bar i:nth-child(1){background:#ff5f56}.zp-vs__bar i:nth-child(2){background:#ffbd2e}.zp-vs__bar i:nth-child(3){background:#27c93f}
.zp-vs__tab{margin-left:12px;padding:0 14px;height:38px;display:flex;align-items:center;background:#1e1e1e;color:#cccccc;font:500 12.5px Consolas,"Cascadia Mono","Courier New",monospace;border-top:1px solid #007acc}
.zp-code{margin:0;padding:18px 20px;border-radius:0;background:#1e1e1e;color:#d4d4d4;font:400 13.5px/1.7 Consolas,"Cascadia Mono","Courier New",monospace;overflow-x:auto;white-space:pre;tab-size:2}
.zp-code::selection,.zp-code *::selection{background:#264f78}
.vs-k{color:#9cdcfe}.vs-s{color:#ce9178}.vs-n{color:#b5cea8}.vs-kw{color:#569cd6}.vs-c{color:#6a9955;font-style:italic}.vs-f{color:#dcdcaa}.vs-v{color:#9cdcfe}.vs-p{color:#d4d4d4}.vs-m{color:#c586c0}.vs-t{color:#4ec9b0}
.zp-codewrap{position:relative}
.zp-codecopy{position:absolute;top:10px;right:10px}
.zp-res{display:flex;flex-direction:column;gap:8px;margin-top:16px}
.zp-res>div{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 14px;border-radius:12px;background:var(--soft);font-size:13.5px;color:var(--tx)}
.zp-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.zp-tabs button{padding:8px 15px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 13.5px Inter,system-ui,sans-serif;cursor:pointer}
.zp-tabs button.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.zp-hint{margin:12px 0 0;font-size:12.5px;color:var(--mu);line-height:1.55}
.zp-steps{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
.zp-steps li{display:flex;gap:12px}
.zp-steps li>span{width:28px;height:28px;border-radius:50%;background:#E6FCF2;color:var(--gd);font-weight:800;font-size:13px;display:grid;place-items:center;flex:none;margin-top:1px}
.zp-steps b{display:block;font-size:14.5px;color:var(--ink)}
.zp-steps p{margin:3px 0 0;font-size:13px;line-height:1.55;color:var(--tx)}
.zp-uses{display:flex;flex-direction:column;gap:10px}
.zp-uses div{padding:12px 14px;border-radius:12px;background:var(--soft)}
.zp-uses b{display:block;font-size:14px;color:var(--ink)}
.zp-uses p{margin:2px 0 0;font-size:13px;line-height:1.5;color:var(--tx)}
.zp-apps{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}
.zp-apps span{padding:5px 12px;border-radius:999px;background:#fff;border:1px solid var(--line);font-size:12.5px;font-weight:700;color:var(--tx)}
.zp-danger{background:#FFF8F7}
.zp-regen{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border:0;border-radius:11px;background:#FDE4E1;color:#c0372b;font:700 14px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s}
.zp-regen:hover{background:#FBD1CC}
@media (max-width:1100px){.zp-grid{grid-template-columns:1fr}.zp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.zp-stats{grid-template-columns:1fr}.zp-copy{flex-wrap:wrap}.zp-copy input{flex-basis:100%;order:2}.zp-mini span{display:none}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var FULL = {url: <?php echo json_encode($fullUrl, JSON_UNESCAPED_SLASHES); ?>, secret: <?php echo json_encode($secret); ?>};
  var MASK = {url: <?php echo json_encode($maskedUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>, secret: <?php echo json_encode($maskedSecret, JSON_UNESCAPED_UNICODE); ?>};
  var shown = {url: false, secret: false}, tab = 'curl';

  function copyText(text, btn) {
    var done = function () {
      var s = btn.querySelector('span'), old = s ? s.textContent : '';
      btn.classList.add('is-done'); if (s) { s.textContent = 'Copied'; }
      setTimeout(function () { btn.classList.remove('is-done'); if (s) { s.textContent = old; } }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
    function fallback() {
      var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t);
      t.select(); try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ } t.remove();
    }
  }

  function toggle(which, btn, input) {
    shown[which] = !shown[which];
    input.value = shown[which] ? FULL[which] : MASK[which];
    btn.setAttribute('aria-pressed', shown[which]); btn.querySelector('span').textContent = shown[which] ? 'Hide' : 'Show';
    render();
  }
  $('zpUrlShow').addEventListener('click', function () { toggle('url', this, $('zpUrl')); });
  $('zpSecShow').addEventListener('click', function () { toggle('secret', this, $('zpSecret')); });

  /* code samples */
  function sample() {
    var url = shown.url ? FULL.url : 'YOUR_WEBHOOK_URL';
    var sec = shown.secret ? FULL.secret : 'YOUR_SIGNING_SECRET';
    var body = '{"phone":"919876543210","message":"Hello from my app!","name":"John Doe"}';
    if (tab === 'curl') {
      return 'BODY=\'' + body + '\'\nSIG=$(printf \'%s\' "$BODY" | openssl dgst -sha256 -hmac "' + sec + '" | sed \'s/^.* //\')\n\ncurl -X POST "' + url + '" \\\n  -H "Content-Type: application/json" \\\n  -H "X-WAAMINI-Signature: $SIG" \\\n  -d "$BODY"';
    }
    if (tab === 'node') {
      return 'const crypto = require("crypto");\n\nconst body = JSON.stringify({ phone: "919876543210", message: "Hello from my app!", name: "John Doe" });\nconst sig = crypto.createHmac("sha256", "' + sec + '").update(body).digest("hex");\n\nconst res = await fetch("' + url + '", {\n  method: "POST",\n  headers: { "Content-Type": "application/json", "X-WAAMINI-Signature": sig },\n  body,\n});\nconsole.log(await res.json());';
    }
    if (tab === 'python') {
      return 'import hmac, hashlib, json, requests\n\nbody = json.dumps({"phone": "919876543210", "message": "Hello from my app!", "name": "John Doe"})\nsig = hmac.new(b"' + sec + '", body.encode(), hashlib.sha256).hexdigest()\n\nr = requests.post("' + url + '", data=body,\n                  headers={"Content-Type": "application/json", "X-WAAMINI-Signature": sig})\nprint(r.json())';
    }
    if (tab === 'php') {
      return '<' + '?php\n$body = json_encode(["phone" => "919876543210", "message" => "Hello from my app!", "name" => "John Doe"]);\n$sig  = hash_hmac("sha256", $body, "' + sec + '");\n\n$ch = curl_init("' + url + '");\ncurl_setopt_array($ch, [\n  CURLOPT_POST => true,\n  CURLOPT_RETURNTRANSFER => true,\n  CURLOPT_POSTFIELDS => $body,\n  CURLOPT_HTTPHEADER => ["Content-Type: application/json", "X-WAAMINI-Signature: " . $sig],\n]);\necho curl_exec($ch);';
    }
    return '// Zapier: add "Code by Zapier" (JavaScript) before the webhook step.\n// Input data: phone, message, name  (map them from your trigger)\nconst crypto = require("crypto");\nconst body = JSON.stringify({ phone: inputData.phone, message: inputData.message, name: inputData.name });\nconst signature = crypto.createHmac("sha256", "' + sec + '").update(body).digest("hex");\n\n// Output: use "body" as the raw request body and "signature" as the\n// X-WAAMINI-Signature header in the next "Webhooks by Zapier" (Custom Request) step.\noutput = { body, signature };';
  }
  /* VS Code (Dark+) style highlighting */
  function escHtml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  var KW = {
    json: 'true false null',
    sh: 'curl echo printf if then fi do done for in while export',
    js: 'const let var await async function return new if else for of in true false null undefined require',
    py: 'import from def return if else elif for in as with True False None print',
    php: 'echo return function if else foreach new true false null'
  };
  function highlight(code, lang) {
    var kw = {}; (KW[lang] || '').split(' ').forEach(function (w) { if (w) { kw[w] = 1; } });
    var re = lang === 'json'
      ? /("(?:[^"\\\n]|\\.)*")(\s*:)?|(-?\d+(?:\.\d+)?)|\b(true|false|null)\b|([{}\[\],:])/g
      : /(\/\/[^\n]*|#[^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*')|(\$\{?[A-Za-z_]\w*\}?)|(\b\d+(?:\.\d+)?\b)|(-{1,2}[A-Za-z][\w-]*)|([A-Za-z_]\w*)(\s*\()?|(=>|[{}\[\]();,=:.+\\])/g;
    var out = '', last = 0, m;
    while ((m = re.exec(code))) {
      out += escHtml(code.slice(last, m.index)); last = re.lastIndex;
      if (lang === 'json') {
        if (m[1]) { out += '<span class="' + (m[2] ? 'vs-k' : 'vs-s') + '">' + escHtml(m[1]) + '</span>' + (m[2] ? '<span class="vs-p">' + m[2] + '</span>' : ''); }
        else if (m[3]) { out += '<span class="vs-n">' + m[3] + '</span>'; }
        else if (m[4]) { out += '<span class="vs-kw">' + m[4] + '</span>'; }
        else { out += '<span class="vs-p">' + escHtml(m[5]) + '</span>'; }
      } else if (m[1]) {
        // "#" starts a comment in sh/python only; in php/js treat "#" lines as comments too
        out += '<span class="vs-c">' + escHtml(m[1]) + '</span>';
      } else if (m[2]) { out += '<span class="vs-s">' + escHtml(m[2]) + '</span>'; }
      else if (m[3]) { out += '<span class="vs-v">' + escHtml(m[3]) + '</span>'; }
      else if (m[4]) { out += '<span class="vs-n">' + m[4] + '</span>'; }
      else if (m[5]) { out += '<span class="vs-m">' + escHtml(m[5]) + '</span>'; }
      else if (m[6]) {
        if (kw[m[6]]) { out += '<span class="vs-kw">' + m[6] + '</span>' + escHtml(m[7] || ''); }
        else if (m[7]) { out += '<span class="vs-f">' + m[6] + '</span>' + escHtml(m[7]); }
        else if (/^[A-Z]/.test(m[6])) { out += '<span class="vs-t">' + m[6] + '</span>'; }
        else { out += '<span class="vs-v">' + m[6] + '</span>'; }
      } else { out += '<span class="vs-p">' + escHtml(m[8]) + '</span>'; }
    }
    return out + escHtml(code.slice(last));
  }
  var FILES = {curl: ['request.sh', 'sh'], node: ['webhook.js', 'js'], python: ['webhook.py', 'py'], php: ['webhook.php', 'php'], zapier: ['zapier-code-step.js', 'js']};
  function vsWrap(pre, name) {
    var w = document.createElement('div'); w.className = 'zp-vs';
    w.innerHTML = '<div class="zp-vs__bar"><i></i><i></i><i></i><span class="zp-vs__tab"></span></div>';
    w.querySelector('.zp-vs__tab').textContent = name;
    pre.parentNode.insertBefore(w, pre); w.appendChild(pre);
    return w.querySelector('.zp-vs__tab');
  }
  var bodyPre = $('zpBody');
  vsWrap(bodyPre, 'request.json');
  bodyPre.innerHTML = highlight(bodyPre.textContent, 'json');
  var codeTab = vsWrap($('zpCode'), 'request.sh');
  function render() { var f = FILES[tab]; codeTab.textContent = f[0]; $('zpCode').innerHTML = highlight(sample(), f[1]); }
  $('zpTabs').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-t]'); if (!b) { return; }
    tab = b.getAttribute('data-t');
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    render();
  });
  render();

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) { return; }
    var what = b.getAttribute('data-copy');
    if (what === 'url') { copyText(FULL.url, b); }
    else if (what === 'secret') { copyText(FULL.secret, b); }
    else if (what === 'code') { copyText($('zpCode').textContent, b); }
  });

  $('zpRegen').addEventListener('submit', function (e) {
    if (!confirm('Regenerate? The old URL and secret stop working immediately and every connected app must be updated.')) { e.preventDefault(); }
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
