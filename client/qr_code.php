<?php
include '../includes/client_auth_check.php';

$pageTitle = 'WhatsApp QR Code';
$currentPage = 'qr_code';

if (!has_feature('qr_code')) {
    include '../includes/client_header.php';
    ?>
    <div class="form-card" style="max-width:none;text-align:center;padding:40px;">
        <h2 style="margin-bottom:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Upgrade Required</h2>
        <p style="color:var(--text-secondary);margin:0 0 16px 0;">QR code tools are not available on your current plan. Upgrade to unlock this feature.</p>
        <a href="subscription.php" class="btn btn-primary">View plans</a>
    </div>
    <?php
    include '../includes/client_footer.php';
    exit();
}

// Default number: the connected WhatsApp number, else the company phone
$defaultPhone = '';
$businessName = '';
try {
    $w = $conn->prepare("SELECT display_phone, verified_name FROM whatsapp_config WHERE company_id = ? LIMIT 1");
    $w->bind_param('i', $companyId);
    $w->execute();
    $wr = $w->get_result()->fetch_assoc();
    if ($wr) {
        $defaultPhone = preg_replace('/\D+/', '', (string)($wr['display_phone'] ?? ''));
        $businessName = trim((string)($wr['verified_name'] ?? ''));
    }
    if ($defaultPhone === '' || $businessName === '') {
        $c = $conn->prepare("SELECT phone, name FROM companies WHERE id = ? LIMIT 1");
        $c->bind_param('i', $companyId);
        $c->execute();
        $cr = $c->get_result()->fetch_assoc();
        if ($defaultPhone === '') { $defaultPhone = preg_replace('/\D+/', '', (string)($cr['phone'] ?? '')); }
        if ($businessName === '') { $businessName = trim((string)($cr['name'] ?? '')); }
    }
} catch (Throwable $e) { /* optional */ }
$connected = ($wr['display_phone'] ?? '') !== '';

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'chat'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'gear'  => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'down'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'copy'  => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'print' => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
    'link'  => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'phone' => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    'check' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'spark' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
];
$tips = [
    ['Print it', 'Add the QR to flyers, shop windows, menus, packaging and invoices.'],
    ['Prefill the message', 'A ready message such as "Hi, I want to order" tells you why they came.'],
    ['Check contrast', 'Dark code on a light background scans best. Keep a quiet border around it.'],
    ['Test before printing', 'Scan the QR with a phone camera and confirm the right chat opens.'],
];
?>

<div class="db qrp">

  <div class="db-head">
    <div>
      <h1>WhatsApp QR Code</h1>
      <p>Create a QR code that opens a WhatsApp chat with your business, ready to print or share.</p>
    </div>
    <div class="db-head__actions">
      <?php if (has_feature('whatsapp_chat')): ?><a href="whatsapp.php" class="db-btn"><?php echo $__ic($P['chat'], 16); ?> WhatsApp Chat</a><?php endif; ?>
      <a href="settings_whatsapp.php" class="db-btn"><?php echo $__ic($P['gear'], 16); ?> WhatsApp Setup</a>
    </div>
  </div>

  <div class="qrp-grid">

    <!-- controls -->
    <section class="db-card qrp-card">
      <div class="qrp-ch"><h2>Customise</h2><p>The preview updates as you type.</p></div>

      <div class="qrp-field">
        <label class="qrp-lab" for="qPhone">WhatsApp number (with country code)</label>
        <div class="qrp-phone"><span><?php echo $__ic($P['phone'], 16); ?> +</span><input id="qPhone" inputmode="numeric" autocomplete="tel" placeholder="919876543210" value="<?php echo $esc($defaultPhone); ?>"></div>
        <p class="qrp-hint" id="qPhoneHint"><?php echo $connected ? 'Filled from your connected WhatsApp number.' : 'Digits only, include the country code (for example 91 for India).'; ?></p>
      </div>

      <div class="qrp-field">
        <label class="qrp-lab" for="qMsg">Pre-filled message (optional)</label>
        <textarea id="qMsg" rows="3" maxlength="500" placeholder="Hi! I would like to know more about your products."></textarea>
        <div class="qrp-chips" id="qSamples">
          <button type="button" data-s="Hi! I would like to know more about your products.">Product enquiry</button>
          <button type="button" data-s="Hi, I want to place an order.">Place an order</button>
          <button type="button" data-s="Hello, I need support.">Support</button>
          <button type="button" data-s="Hi, I want to book an appointment.">Book appointment</button>
        </div>
      </div>

      <div class="qrp-two">
        <div class="qrp-field">
          <label class="qrp-lab">QR colour</label>
          <div class="qrp-sw" id="qFgSw">
            <button type="button" data-c="#0F2622" style="background:#0F2622" aria-label="Black green"></button>
            <button type="button" data-c="#128C7E" style="background:#128C7E" aria-label="WhatsApp green" class="is-on"></button>
            <button type="button" data-c="#1d3a8a" style="background:#1d3a8a" aria-label="Navy"></button>
            <button type="button" data-c="#7a1f5c" style="background:#7a1f5c" aria-label="Plum"></button>
            <input type="color" id="qFg" value="#128C7E" aria-label="Custom QR colour">
          </div>
        </div>
        <div class="qrp-field">
          <label class="qrp-lab">Background</label>
          <div class="qrp-sw" id="qBgSw">
            <button type="button" data-c="#FFFFFF" style="background:#FFFFFF" class="is-on" aria-label="White"></button>
            <button type="button" data-c="#E6FCF2" style="background:#E6FCF2" aria-label="Mint"></button>
            <button type="button" data-c="#FFF7E0" style="background:#FFF7E0" aria-label="Cream"></button>
            <input type="color" id="qBg" value="#FFFFFF" aria-label="Custom background">
          </div>
        </div>
      </div>

      <div class="qrp-two">
        <div class="qrp-field">
          <label class="qrp-lab" for="qStyle">Style</label>
          <select id="qStyle"><option value="square">Square</option><option value="rounded" selected>Rounded</option><option value="dots">Dots</option></select>
        </div>
        <div class="qrp-field">
          <label class="qrp-lab" for="qEcl">Error correction</label>
          <select id="qEcl"><option value="L">Low (simplest code)</option><option value="M" selected>Medium</option><option value="Q">High</option><option value="H">Highest</option></select>
        </div>
      </div>

      <label class="qrp-switch"><input type="checkbox" id="qFrame" checked><i></i><span><b>Add a frame with a call to action</b><small>Used for the framed PNG and for printing.</small></span></label>
      <div class="qrp-field" id="qLabelWrap" style="margin-top:12px">
        <label class="qrp-lab" for="qLabel">Frame text</label>
        <input id="qLabel" maxlength="40" value="Scan to chat on WhatsApp">
      </div>
    </section>

    <!-- preview -->
    <section class="db-card qrp-card qrp-prev">
      <div class="qrp-ch"><h2>Preview</h2><p id="qStatus" class="qrp-status"></p></div>
      <div class="qrp-stage"><canvas id="qCanvas" aria-label="WhatsApp QR code preview" role="img"></canvas></div>

      <div class="qrp-linkbox">
        <span><?php echo $__ic($P['link'], 16); ?></span><input id="qLink" readonly aria-label="WhatsApp chat link">
        <button type="button" class="qrp-mini" id="qCopy"><?php echo $__ic($P['copy'], 14); ?> Copy</button>
      </div>

      <div class="qrp-dl">
        <label class="qrp-sz">Size
          <select id="qSize"><option value="512">512 px</option><option value="1024" selected>1024 px</option><option value="2048">2048 px</option></select>
        </label>
        <button type="button" class="db-btn db-btn--primary" id="qPng"><?php echo $__ic($P['down'], 16); ?> PNG</button>
        <button type="button" class="db-btn" id="qPoster"><?php echo $__ic($P['down'], 16); ?> Framed PNG</button>
        <button type="button" class="db-btn" id="qSvg"><?php echo $__ic($P['down'], 16); ?> SVG</button>
        <button type="button" class="db-btn" id="qPrint"><?php echo $__ic($P['print'], 16); ?> Print</button>
      </div>
      <p class="qrp-hint">The code is created in your browser. Nothing is sent to an outside service.</p>
    </section>
  </div>

  <section class="db-card qrp-card" style="margin-top:18px">
    <div class="qrp-ch"><h2>Tips for better scans</h2></div>
    <div class="qrp-tips">
      <?php foreach ($tips as $i => [$t, $d]): ?>
        <div class="qrp-tip"><span><?php echo $__ic($P['spark'], 18); ?></span><div><b><?php echo $esc($t); ?></b><p><?php echo $esc($d); ?></p></div></div>
      <?php endforeach; ?>
    </div>
    <p class="qrp-note">This QR opens a normal WhatsApp chat with your number. It does not connect or pair your account; to use the WhatsApp Business Cloud API, finish the connection in <a href="settings_whatsapp.php">WhatsApp Setup</a>.</p>
  </section>
</div>

<style>
.qrp{max-width:none;margin:0}
.qrp-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
.qrp-card{padding:22px}
.qrp-ch{margin-bottom:16px}
.qrp-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.qrp-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.qrp-field{margin-bottom:16px}
.qrp-lab{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.qrp-hint{margin:6px 0 0;font-size:12.5px;color:var(--mu);line-height:1.5}
.qrp-hint.is-bad{color:#c0372b;font-weight:600}
.qrp textarea,.qrp select,.qrp input[type=text],.qrp input:not([type]),.qrp input[inputmode]{width:100%;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.qrp select,.qrp input:not([type]),.qrp input[inputmode],.qrp input[type=text]{height:44px}
.qrp textarea{padding:12px 14px;line-height:1.55;resize:vertical}
.qrp textarea:focus,.qrp select:focus,.qrp input:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.qrp-phone{display:flex;align-items:center;border:1.5px solid var(--line);border-radius:11px;background:#fff;overflow:hidden}
.qrp-phone:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.qrp-phone span{display:inline-flex;align-items:center;gap:6px;padding:0 4px 0 14px;font-weight:700;color:var(--mu)}
.qrp-phone input{border:0!important;box-shadow:none!important;flex:1;min-width:0;padding-left:6px!important}
.qrp-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.qrp-chips button{padding:7px 13px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.qrp-chips button:hover{background:#E6FCF2;color:var(--gd)}
.qrp-two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.qrp-sw{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.qrp-sw button{width:34px;height:34px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1.5px var(--line);cursor:pointer;padding:0;transition:box-shadow .15s,transform .1s}
.qrp-sw button:hover{transform:scale(1.08)}
.qrp-sw button.is-on{box-shadow:0 0 0 2.5px var(--g)}
.qrp-sw input[type=color]{width:38px;height:34px;padding:2px;border:1.5px solid var(--line);border-radius:10px;background:#fff;cursor:pointer}
.qrp-switch{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--soft);cursor:pointer}
.qrp-switch input{position:absolute;opacity:0;pointer-events:none}
.qrp-switch i{position:relative;width:46px;height:26px;border-radius:99px;background:#C7D1CD;flex:none;transition:background .2s}
.qrp-switch i::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.qrp-switch input:checked+i{background:linear-gradient(135deg,#25D366,#128C7E)}
.qrp-switch input:checked+i::after{transform:translateX(20px)}
.qrp-switch input:focus-visible+i{box-shadow:0 0 0 4px rgba(18,140,126,.2)}
.qrp-switch b{display:block;font-size:14.5px;color:var(--ink)}
.qrp-switch small{display:block;margin-top:2px;font-size:12.5px;color:var(--mu)}
.qrp-prev{position:sticky;top:16px}
.qrp-status{display:flex;align-items:center;gap:6px}
.qrp-status.is-ok{color:#12803f}.qrp-status.is-bad{color:#c0372b;font-weight:600}
.qrp-stage{display:grid;place-items:center;padding:26px;border-radius:18px;background:linear-gradient(135deg,#F3FBF7,#E6FCF2);margin-bottom:16px}
.qrp-stage canvas{width:100%;max-width:340px;height:auto;border-radius:14px;box-shadow:0 18px 36px -22px rgba(15,38,34,.55);background:#fff}
.qrp-linkbox{display:flex;align-items:center;gap:10px;padding:8px 10px 8px 14px;border-radius:12px;background:var(--soft);margin-bottom:14px}
.qrp-linkbox span{color:var(--g);display:flex;flex:none}
.qrp-linkbox input{flex:1;min-width:0;border:0!important;background:transparent!important;box-shadow:none!important;height:34px!important;padding:0!important;font-size:13.5px!important;color:var(--tx)!important}
.qrp-mini{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:0;border-radius:9px;background:#fff;color:var(--gd);font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
.qrp-mini:hover{background:#E6FCF2}
.qrp-dl{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.qrp-sz{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:700;color:var(--tx)}
.qrp-sz select{width:auto;height:42px;padding:0 10px}
.qrp-tips{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.qrp-tip{display:flex;gap:12px;padding:16px;border-radius:14px;background:var(--soft)}
.qrp-tip>span{width:38px;height:38px;border-radius:11px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.qrp-tip b{display:block;font-size:14.5px;color:var(--ink)}
.qrp-tip p{margin:3px 0 0;font-size:13px;line-height:1.5;color:var(--tx)}
.qrp-note{margin:16px 0 0;padding:12px 14px;border-radius:12px;background:#FEF3DC;color:#7a4608;font-size:13px;line-height:1.55}
.qrp-note a{color:#7a4608;font-weight:700}
@media (max-width:1100px){.qrp-grid{grid-template-columns:1fr}.qrp-prev{position:static}.qrp-tips{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.qrp-two{grid-template-columns:1fr}.qrp-tips{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var BUSINESS = <?php echo json_encode($businessName, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  var cv = $('qCanvas'), ctx = cv.getContext('2d');
  var state = {matrix: null, link: ''};

  function digits() { return $('qPhone').value.replace(/\D+/g, ''); }
  function buildLink() {
    var d = digits(), m = $('qMsg').value.trim();
    return 'https://wa.me/' + d + (m ? '?text=' + encodeURIComponent(m) : '');
  }
  function lum(hex) {
    var n = parseInt(hex.slice(1), 16), c = [n >> 16 & 255, n >> 8 & 255, n & 255].map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); });
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2];
  }
  function contrast(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }

  function status(msg, kind) { var s = $('qStatus'); s.className = 'qrp-status' + (kind ? ' is-' + kind : ''); s.textContent = msg; }

  /** Build the module matrix for the current link. */
  function make() {
    var d = digits(), hint = $('qPhoneHint');
    if (d.length < 8 || d.length > 15) {
      hint.className = 'qrp-hint is-bad'; hint.textContent = 'Enter the full number with country code (8 to 15 digits).';
      state.matrix = null; status('Enter a valid number to create the code.', 'bad'); draw(); return;
    }
    hint.className = 'qrp-hint'; hint.textContent = 'Digits only, include the country code (for example 91 for India).';
    state.link = buildLink(); $('qLink').value = state.link;
    var qr;
    try { qr = qrcode(0, $('qEcl').value); qr.addData(state.link); qr.make(); }
    catch (e) {
      try { qr = qrcode(0, 'L'); qr.addData(state.link); qr.make(); } catch (e2) { state.matrix = null; status('The message is too long for a QR code. Shorten it.', 'bad'); draw(); return; }
    }
    var n = qr.getModuleCount(), m = [];
    for (var r = 0; r < n; r++) { m[r] = []; for (var c = 0; c < n; c++) { m[r][c] = qr.isDark(r, c); } }
    state.matrix = m;
    var low = contrast($('qFg').value, $('qBg').value) < 3;
    status(low ? 'Low contrast: the code may not scan. Use a darker colour.' : 'Ready to download.', low ? 'bad' : 'ok');
    draw();
  }

  function isFinder(r, c, n) { return (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7); }

  /** Draw the code into any 2D context at (x,y) with the given pixel size. */
  function paint(g, x, y, size, fg, bg, style) {
    var m = state.matrix, n = m.length, quiet = 2, cell = size / (n + quiet * 2);
    g.fillStyle = bg; g.fillRect(x, y, size, size);
    g.fillStyle = fg;
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (!m[r][c]) { continue; }
      var px = x + (c + quiet) * cell, py = y + (r + quiet) * cell;
      if (style === 'square' || isFinder(r, c, n) && style !== 'dots') {
        if (style === 'rounded' && isFinder(r, c, n)) { g.fillRect(px - .3, py - .3, cell + .6, cell + .6); }
        else { g.fillRect(px - .3, py - .3, cell + .6, cell + .6); }
      } else if (style === 'rounded') {
        var rad = cell * .38; g.beginPath(); g.moveTo(px + rad, py); g.arcTo(px + cell, py, px + cell, py + cell, rad); g.arcTo(px + cell, py + cell, px, py + cell, rad); g.arcTo(px, py + cell, px, py, rad); g.arcTo(px, py, px + cell, py, rad); g.closePath(); g.fill();
      } else {
        g.beginPath(); g.arc(px + cell / 2, py + cell / 2, cell * (isFinder(r, c, n) ? .5 : .42), 0, Math.PI * 2); g.fill();
      }
    }
  }

  function draw() {
    var size = 680;
    cv.width = size; cv.height = size;
    if (!state.matrix) { ctx.fillStyle = '#F3FBF7'; ctx.fillRect(0, 0, size, size); ctx.fillStyle = '#9AA9A4'; ctx.font = '600 30px Inter,system-ui,sans-serif'; ctx.textAlign = 'center'; ctx.fillText('QR preview', size / 2, size / 2); return; }
    paint(ctx, 0, 0, size, $('qFg').value, $('qBg').value, $('qStyle').value);
  }

  function plainCanvas(px) {
    var c = document.createElement('canvas'); c.width = c.height = px;
    paint(c.getContext('2d'), 0, 0, px, $('qFg').value, $('qBg').value, $('qStyle').value);
    return c;
  }
  function posterCanvas(px) {
    var pad = px * .09, head = px * .17, foot = px * .15, w = px + pad * 2, h = px + pad * 2 + head + foot;
    var c = document.createElement('canvas'); c.width = w; c.height = h; var g = c.getContext('2d');
    var fg = $('qFg').value, bg = $('qBg').value;
    g.fillStyle = fg; g.fillRect(0, 0, w, h);
    var r = px * .05; g.fillStyle = '#FFFFFF'; g.beginPath(); g.moveTo(r, 0); g.lineTo(w - r, 0); g.arcTo(w, 0, w, r, r); g.lineTo(w, h - r); g.arcTo(w, h, w - r, h, r); g.lineTo(r, h); g.arcTo(0, h, 0, h - r, r); g.lineTo(0, r); g.arcTo(0, 0, r, 0, r); g.closePath();
    g.save(); g.clip(); g.fillStyle = '#FFFFFF'; g.fillRect(0, 0, w, h); g.restore();
    var inset = px * .03; g.fillStyle = fg; g.fillRect(0, 0, w, head + pad * .6);
    g.fillStyle = '#FFFFFF'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.font = '800 ' + Math.round(px * .062) + 'px Inter,system-ui,sans-serif';
    g.fillText(($('qLabel').value.trim() || 'Scan to chat on WhatsApp'), w / 2, head * .62);
    var qy = head + pad * .6 + pad * .4;
    g.fillStyle = bg; g.fillRect(pad - inset, qy - inset, px + inset * 2, px + inset * 2);
    paint(g, pad, qy, px, fg, bg, $('qStyle').value);
    g.fillStyle = '#0F2622'; g.font = '800 ' + Math.round(px * .05) + 'px Inter,system-ui,sans-serif';
    g.fillText(BUSINESS || 'WhatsApp', w / 2, qy + px + pad * .55 + foot * .18);
    g.fillStyle = '#586a63'; g.font = '600 ' + Math.round(px * .04) + 'px Inter,system-ui,sans-serif';
    g.fillText('+' + digits(), w / 2, qy + px + pad * .55 + foot * .55);
    return c;
  }
  function svgText(px) {
    var m = state.matrix, n = m.length, quiet = 2, cell = px / (n + quiet * 2), style = $('qStyle').value, fg = $('qFg').value, bg = $('qBg').value, out = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (!m[r][c]) { continue; }
      var x = ((c + quiet) * cell).toFixed(2), y = ((r + quiet) * cell).toFixed(2), s = cell.toFixed(2);
      if (style === 'dots' || (style === 'rounded' && !isFinder(r, c, n))) {
        out += style === 'dots' ? '<circle cx="' + (parseFloat(x) + cell / 2).toFixed(2) + '" cy="' + (parseFloat(y) + cell / 2).toFixed(2) + '" r="' + (cell * (isFinder(r, c, n) ? .5 : .42)).toFixed(2) + '"/>' : '<rect x="' + x + '" y="' + y + '" width="' + s + '" height="' + s + '" rx="' + (cell * .38).toFixed(2) + '"/>';
      } else { out += '<rect x="' + x + '" y="' + y + '" width="' + s + '" height="' + s + '"/>'; }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + px + '" height="' + px + '" viewBox="0 0 ' + px + ' ' + px + '"><rect width="100%" height="100%" fill="' + bg + '"/><g fill="' + fg + '">' + out + '</g></svg>';
  }

  function save(blobOrUrl, name) { var a = document.createElement('a'); a.href = blobOrUrl; a.download = name; document.body.appendChild(a); a.click(); a.remove(); }
  function need() { if (!state.matrix) { status('Enter a valid number first.', 'bad'); return false; } return true; }
  var base = function () { return 'whatsapp-qr-' + digits(); };

  $('qPng').addEventListener('click', function () { if (!need()) { return; } save(plainCanvas(parseInt($('qSize').value, 10)).toDataURL('image/png'), base() + '.png'); });
  $('qPoster').addEventListener('click', function () { if (!need()) { return; } save(posterCanvas(parseInt($('qSize').value, 10)).toDataURL('image/png'), base() + '-framed.png'); });
  $('qSvg').addEventListener('click', function () {
    if (!need()) { return; }
    var url = URL.createObjectURL(new Blob([svgText(parseInt($('qSize').value, 10))], {type: 'image/svg+xml'})); save(url, base() + '.svg'); setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  });
  $('qCopy').addEventListener('click', function () {
    if (!need()) { return; }
    var b = this, done = function () { var t = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = t; }, 1400); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(state.link).then(done, function () { $('qLink').select(); document.execCommand('copy'); done(); }); }
    else { $('qLink').select(); document.execCommand('copy'); done(); }
  });
  $('qPrint').addEventListener('click', function () {
    if (!need()) { return; }
    var src = ($('qFrame').checked ? posterCanvas(1200) : plainCanvas(1200)).toDataURL('image/png');
    var f = document.createElement('iframe'); f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(f);
    var d = f.contentWindow.document; d.open();
    d.write('<!doctype html><html><head><title>WhatsApp QR</title><style>@page{margin:14mm}body{margin:0;display:flex;justify-content:center;align-items:flex-start}img{width:100%;max-width:150mm;height:auto}</style></head><body><img id="i" src="' + src + '"></body></html>');
    d.close();
    var go = function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { /* ignore */ } setTimeout(function () { f.remove(); }, 2000); };
    var im = d.getElementById('i'); if (im.complete) { go(); } else { im.onload = go; }
  });

  // controls
  ['qPhone', 'qMsg', 'qStyle', 'qEcl', 'qFg', 'qBg', 'qLabel'].forEach(function (id) { $(id).addEventListener('input', function () { if (id === 'qFg') { mark($('qFgSw'), this.value); } if (id === 'qBg') { mark($('qBgSw'), this.value); } make(); }); });
  $('qFrame').addEventListener('change', function () { $('qLabelWrap').style.display = this.checked ? '' : 'none'; });
  $('qSamples').addEventListener('click', function (e) { var b = e.target.closest('[data-s]'); if (b) { $('qMsg').value = b.getAttribute('data-s'); make(); } });
  function mark(box, color) { box.querySelectorAll('button').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-c').toLowerCase() === String(color).toLowerCase()); }); }
  $('qFgSw').addEventListener('click', function (e) { var b = e.target.closest('[data-c]'); if (b) { $('qFg').value = b.getAttribute('data-c'); mark(this, b.getAttribute('data-c')); make(); } });
  $('qBgSw').addEventListener('click', function (e) { var b = e.target.closest('[data-c]'); if (b) { $('qBg').value = b.getAttribute('data-c'); mark(this, b.getAttribute('data-c')); make(); } });

  if (typeof qrcode === 'undefined') { status('The QR library could not load. Check your connection and refresh.', 'bad'); } else { make(); }
})();
</script>

<?php include '../includes/client_footer.php'; ?>
