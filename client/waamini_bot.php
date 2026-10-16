<?php
include '../includes/client_auth_check.php';
if (!has_feature('ai_chatbot')) {
    header('Location: dashboard.php');
    exit();
}
require_once '../includes/waamini_bot/bootstrap.php';

$pageTitle = 'WAAMINI Bot';
$currentPage = 'waamini_bot';

$S = WB_Store::settings($conn);
$platformOk = WB_Store::platformReady($S);
$bot = WB_Store::bot($conn, $companyId, true);
$available = $platformOk && $bot && $bot['is_admin_enabled'];

$prog = $available ? WB_Store::progress($conn, $companyId, $bot) : ['website' => false, 'profile' => false, 'instructions' => false, 'test' => false, 'complete' => false, 'urls_total' => 0, 'urls_ok' => 0];
$usage = WB_Store::usage($conn, $companyId);
$limits = WB_Store::limitsFor($conn, $companyId);

$sources = [];
$qaCount = 0;
if ($available) {
    $st = $conn->prepare("SELECT id, type, uri, title, status, bytes, error FROM ai_knowledge_sources WHERE company_id = ? AND bot_id = ? ORDER BY type, id");
    $botId = (int)$bot['id'];
    $st->bind_param('ii', $companyId, $botId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) { $sources[] = $x; }
    $q = $conn->prepare("SELECT COUNT(*) AS n FROM ai_qa_pairs WHERE company_id = ? AND bot_id = ? AND is_active = 1");
    $q->bind_param('ii', $companyId, $botId);
    $q->execute();
    $qaCount = (int)($q->get_result()->fetch_assoc()['n'] ?? 0);
}
$urlCount = count(array_filter($sources, static fn($s) => $s['type'] === 'url' && $s['status'] === 'success'));
$docCount = count(array_filter($sources, static fn($s) => $s['type'] === 'file' && $s['status'] === 'success'));

// Connected WhatsApp number (display only)
$waNumber = '';
try {
    $w = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id = ? LIMIT 1");
    $w->bind_param('i', $companyId);
    $w->execute();
    $wr = $w->get_result()->fetch_assoc();
    if ($wr && !empty($wr['phone_number_id'])) {
        $waNumber = (string)($wr['display_phone_number'] ?? $wr['phone_number'] ?? $wr['whatsapp_number'] ?? ('ID ' . $wr['phone_number_id']));
    }
} catch (Throwable $e) { /* optional */ }

$stepKeys = ['website', 'profile', 'instructions', 'test'];
$currentStep = 3;
foreach ($stepKeys as $i => $k) { if (!$prog[$k]) { $currentStep = $i; break; } }

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<link rel="stylesheet" href="../assets/css/waamini-bot.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'bot'    => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'book'   => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
    'gear'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'sparkle'=> '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'reset'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
];

$waChat = static function (string $msgsId, string $inId, string $sendId, ?string $resetId) use ($esc, $bot, $__ic, $P): string {
    $smile = '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>';
    $clip = '<path d="M13.234 20.252 21 12.3"/><path d="m16 6-8.414 8.586a2 2 0 0 0 0 2.828 2 2 0 0 0 2.828 0l8.414-8.586a4 4 0 0 0 0-5.656 4 4 0 0 0-5.656 0l-8.415 8.585a6 6 0 1 0 8.486 8.486"/>';
    $lock = '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';
    $h = '<div class="wa-app"><header class="wa-app__hd"><span class="wa-app__av">' . $esc(strtoupper(mb_substr((string)$bot['name'], 0, 1))) . '</span>'
       . '<div class="wa-app__id"><b>' . $esc($bot['name']) . '</b><small><i></i>online &middot; test chat</small></div>';
    if ($resetId) { $h .= '<button type="button" class="wa-app__ib" id="' . $resetId . '" title="New chat" aria-label="New chat">' . $__ic($P['reset'], 20) . '</button>'; }
    $h .= '</header><div class="wbc-msgs" id="' . $msgsId . '"><div class="wa-sys" data-hint id="wbcHint">' . $__ic($lock, 13) . ' Test chat. You play the customer, nothing is sent to WhatsApp.</div></div>'
       . '<div class="wa-app__bar"><span class="wa-app__ic">' . $__ic($smile, 24) . '</span><span class="wa-app__ic">' . $__ic($clip, 22) . '</span>'
       . '<textarea id="' . $inId . '" rows="1" placeholder="Type a message" aria-label="Type a customer message"></textarea>'
       . '<button type="button" class="wa-app__send" id="' . $sendId . '" aria-label="Send">' . $__ic($P['send'], 20) . '</button></div></div>';
    return $h;
};
$profile = $bot['business_profile'] ?? [];
$profileFields = WB_Store::profileFields();
$longFields = ['about', 'products', 'policies', 'address', 'payment_methods', 'service_area'];
$renderProfile = static function (string $pfx) use ($esc, $profile, $profileFields, $longFields): string {
    $h = '';
    foreach ($profileFields as $k => $label) {
        $v = (string)($profile[$k] ?? '');
        $h .= '<div class="wbc-field"><label class="wbc-lab" for="' . $pfx . $k . '">' . $esc($label) . '</label>';
        $h .= in_array($k, $longFields, true)
            ? '<textarea class="wbc-area" id="' . $pfx . $k . '" data-pf="' . $k . '" rows="2">' . $esc($v) . '</textarea>'
            : '<input class="wbc-input" id="' . $pfx . $k . '" data-pf="' . $k . '" value="' . $esc($v) . '">';
        if ($k === 'hours') {
            $h .= '<div class="wbc-two" style="margin-top:8px"><div><label class="wbc-lab" for="' . $pfx . 'hs">Opens</label><input class="wbc-input" type="time" id="' . $pfx . 'hs" data-pf="hours_start" value="' . $esc($profile['hours_start'] ?? '') . '"></div>'
                . '<div><label class="wbc-lab" for="' . $pfx . 'he">Closes</label><input class="wbc-input" type="time" id="' . $pfx . 'he" data-pf="hours_end" value="' . $esc($profile['hours_end'] ?? '') . '"></div></div>'
                . '<p class="wbc-hint">Used by the "reply only outside business hours" setting.</p>';
        }
        $h .= '</div>';
    }
    return $h;
};
$toneTags = array_values(array_filter(array_map('trim', preg_split('/[,.;\n]+/', (string)($bot['tone_text'] ?? '')))));
$pctUse = $limits['replies'] > 0 ? min(100, round($usage['replies'] / $limits['replies'] * 100)) : 0;
?>

<div class="db wbc" data-wbc data-api="ajax_waamini_bot.php" data-token="<?php echo $esc(csrf_get_token()); ?>">

  <div class="db-head">
    <div>
      <h1>WAAMINI Bot</h1>
      <p>An AI assistant that learns your business and answers customers on WhatsApp.</p>
    </div>
    <?php if ($available): ?>
    <div class="db-head__actions">
      <a href="waamini_bot_knowledge.php" class="db-btn"><?php echo $__ic($P['book'], 16); ?> Knowledge</a>
      <a href="waamini_bot_settings.php" class="db-btn"><?php echo $__ic($P['gear'], 16); ?> Bot settings</a>
    </div>
    <?php endif; ?>
  </div>

  <div id="wbcToast" class="wbc-toast" role="status"></div>
<?php
// Replies the bot wrote but WhatsApp refused to deliver in the last 24 hours (for example an expired access token)
$sendFail = ['n' => 0, 'err' => ''];
if ($available) {
    $sf = $conn->prepare("SELECT COUNT(*) AS n, SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(error, '') ORDER BY id DESC SEPARATOR '||'), '||', 1) AS last_err FROM ai_runs WHERE company_id = ? AND is_test = 0 AND outcome = 'send_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    if ($sf) { $sf->bind_param('i', $companyId); $sf->execute(); $sr = $sf->get_result()->fetch_assoc(); $sendFail = ['n' => (int)($sr['n'] ?? 0), 'err' => (string)($sr['last_err'] ?? '')]; }
}
if ($sendFail['n'] > 0): ?>
  <div class="wbc-toast is-err is-show" role="alert" style="display:flex">
    <?php echo $__ic($P['lock'], 18); ?>
    <span><b><?php echo (int)$sendFail['n']; ?> bot repl<?php echo $sendFail['n'] === 1 ? 'y was' : 'ies were'; ?> not delivered to customers in the last 24 hours.</b>
      <?php echo $esc($sendFail['err'] !== '' && $sendFail['err'] !== 'send_failed' ? $sendFail['err'] : 'WhatsApp refused the message. This usually means the WhatsApp access token has expired.'); ?>
      <a href="settings_whatsapp.php" style="color:inherit;font-weight:800">Open WhatsApp Setup</a></span>
  </div>
<?php endif; ?>

<?php if (!$available): ?>
  <section class="db-card wbc-off">
    <span><?php echo $__ic($P['lock'], 36); ?></span>
    <h2>Bot is not available</h2>
    <p>Bot is not available, contact support.</p>
  </section>

<?php else: ?>

  <!-- top bar -->
  <section class="db-card wbc-top">
    <div class="wbc-top__id">
      <span class="wbc-top__av"><?php echo $__ic($P['bot'], 24); ?></span>
      <div><h2><?php echo $esc($bot['name']); ?></h2><p><?php echo $prog['complete'] ? 'Set up and ready' : 'Setup in progress'; ?> &middot; Powered by <?php echo $esc((WB_Store::providers()[$S['chat_provider'] ?? 'openai']['label'] ?? 'AI') . ' · ' . ($S['chat_model'] ?? '')); ?></p></div>
    </div>
    <div class="wbc-wa">
      <label class="wbc-switch" title="<?php echo $prog['complete'] ? 'Turn the bot on or off for WhatsApp' : 'Finish the setup first'; ?>">
        <input type="checkbox" id="wbcLive" <?php echo $bot['is_live'] ? 'checked' : ''; ?> <?php echo $prog['complete'] ? '' : 'disabled'; ?>><i></i>
      </label>
      <div><b>WhatsApp <span id="wbcLiveTxt"><?php echo $bot['is_live'] ? 'On' : 'Off'; ?></span></b><small><?php echo $waNumber !== '' ? $esc($waNumber) : 'No WhatsApp number connected'; ?></small></div>
    </div>
    <div class="wbc-usage">
      <div class="wbc-usage__t"><span>Replies this month</span><b><?php echo number_format($usage['replies']); ?> / <?php echo number_format($limits['replies']); ?></b></div>
      <div class="wbc-track"><u class="<?php echo $pctUse >= 100 ? 'is-full' : ''; ?>" style="width:<?php echo $pctUse; ?>%"></u></div>
    </div>
    <?php if ($prog['complete']): ?>
      <button type="button" class="db-btn db-btn--primary" id="wbcSaveAll"><?php echo $__ic($P['save'], 16); ?> Save changes</button>
    <?php endif; ?>
  </section>

<?php if (!$prog['complete']): ?>
  <!-- ═════════ first-time setup ═════════ -->
  <div class="wbc-steps" id="wbcSteps">
    <?php foreach ([['Website', 'Train on your pages'], ['Business details', 'Review what was found'], ['Instructions', 'Role, tone and limits'], ['Test', 'Try it before going live']] as $i => [$t, $s]): ?>
      <button type="button" class="wbc-step <?php echo $i === $currentStep ? 'is-active' : ''; ?> <?php echo $prog[$stepKeys[$i]] ? 'is-done' : ''; ?>" data-step="<?php echo $i; ?>">
        <span class="wbc-step__n"><?php echo $prog[$stepKeys[$i]] ? '&#10003;' : ($i + 1); ?></span>
        <span><b><?php echo $esc($t); ?></b><small><?php echo $esc($s); ?></small></span>
      </button>
    <?php endforeach; ?>
  </div>

  <section class="db-card">
    <!-- step 1 -->
    <div class="wbc-pane <?php echo $currentStep === 0 ? 'is-active' : ''; ?>" data-pane="0">
      <h3>Add your website</h3>
      <p>Paste your website address. We read your pages, respect your robots.txt, and use a sitemap if you have one. Up to <b><?php echo (int)$limits['pages']; ?></b> pages on your plan.</p>
      <div class="wbc-fetch">
        <input class="wbc-input" id="wbcUrl" placeholder="https://yourbusiness.com" value="<?php echo $esc($bot['website_url'] ?? ''); ?>" autocomplete="url">
        <button type="button" class="db-btn db-btn--primary" id="wbcFetch"><?php echo $__ic($P['globe'], 16); ?> Fetch</button>
      </div>
      <div style="overflow-x:auto">
        <table class="wbc-table"><thead><tr><th>Page</th><th>Status</th><th>Size</th><th></th></tr></thead><tbody id="wbcSrc"></tbody></table>
      </div>
      <div class="wbc-btn-row" style="margin-top:18px"><button type="button" class="db-btn db-btn--primary" id="wbcNext0" disabled>Continue</button><span class="wbc-hint" id="wbcNext0Hint">Waiting for at least one page to finish training.</span></div>
    </div>

    <!-- step 2 -->
    <div class="wbc-pane <?php echo $currentStep === 1 ? 'is-active' : ''; ?>" data-pane="1">
      <h3>Review your business details</h3>
      <p>We drafted these from your website. Anything the site did not state is left empty, so please fill it in. The bot only uses what you confirm here.</p>
      <div class="wbc-btn-row" style="margin-bottom:16px"><button type="button" class="db-btn wbc-btn-sm" id="wbcDraft"><?php echo $__ic($P['sparkle'], 15); ?> Draft with AI</button><span class="wbc-hint" id="wbcDraftHint"></span></div>
      <div id="wbcProfile1"><?php echo $renderProfile('s1_'); ?></div>
      <div class="wbc-btn-row"><button type="button" class="db-btn db-btn--primary" id="wbcConfirm">Confirm and continue</button></div>
    </div>

    <!-- step 3 -->
    <div class="wbc-pane <?php echo $currentStep === 2 ? 'is-active' : ''; ?>" data-pane="2">
      <h3>Give your bot instructions</h3>
      <p>These are ready to use. Edit anything you like. Refunds, billing and legal, medical or financial advice always go to a human.</p>
      <div class="wbc-field"><label class="wbc-lab" for="s3_name">Bot name</label><input class="wbc-input" id="s3_name" value="<?php echo $esc($bot['name']); ?>"></div>
      <div class="wbc-field"><label class="wbc-lab" for="s3_role">Role</label><textarea class="wbc-area" id="s3_role" rows="3"><?php echo $esc($bot['role_text']); ?></textarea></div>
      <div class="wbc-field"><label class="wbc-lab" for="s3_tone">Tone</label><textarea class="wbc-area" id="s3_tone" rows="2"><?php echo $esc($bot['tone_text']); ?></textarea></div>
      <div class="wbc-two">
        <div class="wbc-field"><label class="wbc-lab" for="s3_scope">Scope (one per line)</label><textarea class="wbc-area" id="s3_scope" rows="5"><?php echo $esc(implode("\n", $bot['scope'])); ?></textarea></div>
        <div class="wbc-field"><label class="wbc-lab" for="s3_oos">Out of scope (one per line)</label><textarea class="wbc-area" id="s3_oos" rows="5"><?php echo $esc(implode("\n", $bot['out_of_scope'])); ?></textarea></div>
      </div>
      <div class="wbc-btn-row"><button type="button" class="db-btn db-btn--primary" id="wbcSaveInstr">Save and continue</button></div>
    </div>

    <!-- step 4 -->
    <div class="wbc-pane <?php echo $currentStep === 3 ? 'is-active' : ''; ?>" data-pane="3">
      <h3>Test your bot</h3>
      <p>Ask it a few questions a customer would ask. Nothing is sent to WhatsApp. The bot can be switched on once one test message has been answered.</p>
      <div class="db-card wbc-chat wa-host" style="height:640px"><?php echo $waChat('wbcMsgs4', 'wbcIn4', 'wbcSend4', null); ?></div>
      <div class="wbc-btn-row" style="margin-top:16px"><button type="button" class="db-btn db-btn--primary" id="wbcFinish" disabled>Finish setup</button><span class="wbc-hint" id="wbcFinishHint">Send one test message to unlock this.</span></div>
    </div>
  </section>

<?php else: ?>
  <!-- ═════════ workspace ═════════ -->
  <div class="wbc-grid">
    <section class="db-card wbc-chat wa-host"><?php echo $waChat('wbcMsgs', 'wbcIn', 'wbcSend', 'wbcReset'); ?></section>

    <aside class="db-card wbc-side">
      <div class="wbc-tabs"><button type="button" class="wbc-tab is-active" data-tab="overview">Overview</button><button type="button" class="wbc-tab" data-tab="instr">Instructions</button></div>

      <div class="wbc-tabpane is-active" data-tabpane="overview">
        <div class="wbc-sec"><h4>Knowledge</h4>
          <div class="wbc-counts">
            <a class="wbc-count" href="waamini_bot_knowledge.php"><b><?php echo $urlCount; ?></b><span>Pages</span></a>
            <a class="wbc-count" href="waamini_bot_knowledge.php?tab=docs"><b><?php echo $docCount; ?></b><span>Documents</span></a>
            <a class="wbc-count" href="waamini_bot_knowledge.php?tab=qa"><b><?php echo $qaCount; ?></b><span>Q&amp;As</span></a>
          </div>
        </div>
        <div class="wbc-sec"><h4>Tone</h4><div class="wbc-tags"><?php foreach ($toneTags as $t): ?><span class="wbc-tag"><?php echo $esc(mb_substr($t, 0, 40)); ?></span><?php endforeach; ?></div></div>
        <div class="wbc-sec"><h4>Scope</h4><ul class="wbc-list"><?php foreach ($bot['scope'] as $t): ?><li><?php echo $esc($t); ?></li><?php endforeach; ?></ul></div>
        <div class="wbc-sec"><h4>Out of scope</h4><ul class="wbc-list"><?php foreach ($bot['out_of_scope'] as $t): ?><li><?php echo $esc($t); ?></li><?php endforeach; ?></ul></div>
        <details class="wbc-det"><summary>Business details <span class="db-pill is-ok">Confirmed</span></summary><div id="wbcProfileW"><?php echo $renderProfile('w_'); ?></div></details>
      </div>

      <div class="wbc-tabpane" data-tabpane="instr">
        <div class="wbc-field"><label class="wbc-lab" for="w_name">Bot name</label><input class="wbc-input" id="w_name" value="<?php echo $esc($bot['name']); ?>"></div>
        <div class="wbc-field"><label class="wbc-lab" for="w_role">Role</label><textarea class="wbc-area" id="w_role" rows="4"><?php echo $esc($bot['role_text']); ?></textarea></div>
        <div class="wbc-field"><label class="wbc-lab" for="w_tone">Tone</label><textarea class="wbc-area" id="w_tone" rows="2"><?php echo $esc($bot['tone_text']); ?></textarea></div>
        <div class="wbc-field"><label class="wbc-lab" for="w_scope">Scope (one per line)</label><textarea class="wbc-area" id="w_scope" rows="4"><?php echo $esc(implode("\n", $bot['scope'])); ?></textarea></div>
        <div class="wbc-field"><label class="wbc-lab" for="w_oos">Out of scope (one per line)</label><textarea class="wbc-area" id="w_oos" rows="4"><?php echo $esc(implode("\n", $bot['out_of_scope'])); ?></textarea></div>
        <div class="wbc-sec"><h4>Version history</h4><div class="wbc-ver" id="wbcVers"><span class="wbc-hint">Loading...</span></div></div>
      </div>
    </aside>
  </div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if ($available): ?>
<script src="../assets/js/waamini-bot.js?v=1"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var W = window.WBC; if (!W) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var COMPLETE = <?php echo $prog['complete'] ? 'true' : 'false'; ?>;
  var sources = <?php echo json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

  function collectProfile(box) {
    var o = {};
    box.querySelectorAll('[data-pf]').forEach(function (el) { o[el.getAttribute('data-pf')] = el.value; });
    return o;
  }
  function fillProfile(box, f) {
    Object.keys(f || {}).forEach(function (k) { var el = box.querySelector('[data-pf="' + k + '"]'); if (el && f[k] !== '') { el.value = f[k]; } });
  }
  function lines(v) { return v.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean); }

  // Live toggle
  var live = $('wbcLive');
  if (live) { live.addEventListener('change', function () {
    var on = live.checked;
    W.api('toggle_live', {on: on}).then(function (r) {
      if (r.ok) { $('wbcLiveTxt').textContent = on ? 'On' : 'Off'; W.toast('WhatsApp bot is now ' + (on ? 'on.' : 'off.'), true); }
      else { live.checked = !on; W.toast(r.error || 'Could not change the switch.', false); }
    });
  }); }

  /* ───────── setup wizard ───────── */
  if (!COMPLETE) {
    var steps = document.querySelectorAll('.wbc-step'), panes = document.querySelectorAll('.wbc-pane');
    var progress = <?php echo json_encode($prog); ?>;
    function go(n) {
      steps.forEach(function (s, i) { s.classList.toggle('is-active', i === n); });
      panes.forEach(function (p, i) { p.classList.toggle('is-active', i === n); });
      if (n === 1) { maybeDraft(); }
      window.scrollTo({top: 0, behavior: 'smooth'});
    }
    function refreshSteps(p) {
      progress = p; var keys = ['website', 'profile', 'instructions', 'test'];
      steps.forEach(function (s, i) {
        var done = !!p[keys[i]];
        s.classList.toggle('is-done', done);
        s.querySelector('.wbc-step__n').innerHTML = done ? '&#10003;' : (i + 1);
        s.classList.toggle('is-click', done);
      });
    }
    steps.forEach(function (s, i) { s.addEventListener('click', function () { if (s.classList.contains('is-click') || s.classList.contains('is-done')) { go(i); } }); });
    refreshSteps(progress);

    // step 1: pages
    var tb = $('wbcSrc');
    function drawSources() {
      var rows = W.renderSources(tb, sources, 'url');
      var ok = rows.some(function (s) { return s.status === 'success'; });
      var busy = rows.some(function (s) { return s.status === 'queued' || s.status === 'training'; });
      $('wbcNext0').disabled = !ok;
      $('wbcNext0Hint').textContent = ok ? (busy ? 'More pages are still training. You can continue now.' : '') : (busy ? 'Training your pages...' : 'Waiting for at least one page to finish training.');
    }
    function pump() { W.tickLoop(function (r) { if (r && r.sources) { sources = r.sources; drawSources(); } if (r && r.progress) { refreshSteps(r.progress); } }); }
    drawSources();
    if (sources.some(function (s) { return s.status === 'queued' || s.status === 'training'; })) { pump(); }
    $('wbcFetch').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      W.api('fetch_site', {url: $('wbcUrl').value}).then(function (r) {
        btn.disabled = false;
        if (!r.ok) { W.toast(r.error || 'Could not start.', false); return; }
        W.toast(r.message, true); sources = r.sources; drawSources(); pump();
      });
    });
    tb.addEventListener('click', function (e) {
      var rt = e.target.closest('[data-retrain]'), dl = e.target.closest('[data-del]');
      if (rt) { W.api('retrain', {id: rt.getAttribute('data-retrain')}).then(function (r) { if (r.ok) { sources = r.sources; drawSources(); pump(); } }); }
      if (dl && confirm('Remove this page?')) { W.api('delete_source', {id: dl.getAttribute('data-del')}).then(function (r) { if (r.ok) { sources = r.sources; drawSources(); } }); }
    });
    $('wbcNext0').addEventListener('click', function () { go(1); });

    // step 2: profile
    var drafted = <?php echo $profile ? 'true' : 'false'; ?>;
    function maybeDraft() { if (!drafted && progress.website) { draft(); } }
    function draft() {
      var b = $('wbcDraft'); b.disabled = true; $('wbcDraftHint').innerHTML = '<span class="wbc-spin"></span> Reading your pages...';
      W.api('draft_profile').then(function (r) {
        b.disabled = false; drafted = true;
        if (r.ok) { fillProfile($('wbcProfile1'), r.fields); $('wbcDraftHint').textContent = 'Drafted. Please check every field.'; }
        else { $('wbcDraftHint').textContent = r.error || 'Could not draft. Fill the fields in by hand.'; }
      });
    }
    $('wbcDraft').addEventListener('click', draft);
    $('wbcConfirm').addEventListener('click', function () {
      var p = collectProfile($('wbcProfile1'));
      if (!(p.business_name || '').trim()) { W.toast('Please enter at least the business name.', false); return; }
      W.api('save_profile', {profile: p, confirm: true}).then(function (r) {
        if (r.ok) { progress.profile = true; refreshSteps(progress); go(2); } else { W.toast(r.error || 'Could not save.', false); }
      });
    });

    // step 3: instructions
    $('wbcSaveInstr').addEventListener('click', function () {
      W.api('save_instructions', {name: $('s3_name').value, role_text: $('s3_role').value, tone_text: $('s3_tone').value, scope: lines($('s3_scope').value), out_of_scope: lines($('s3_oos').value)}).then(function (r) {
        if (r.ok) { progress.instructions = true; refreshSteps(progress); go(3); } else { W.toast(r.error || 'Could not save.', false); }
      });
    });

    // step 4: test
    W.playground({msgs: $('wbcMsgs4'), input: $('wbcIn4'), send: $('wbcSend4'), onResult: function (r) {
      if (r.progress) { refreshSteps(r.progress); if (r.progress.test) { $('wbcFinish').disabled = false; $('wbcFinishHint').textContent = 'Great, your bot answered. You can finish setup.'; } }
    }});
    $('wbcFinish').addEventListener('click', function () { location.reload(); });
    if (progress.test) { $('wbcFinish').disabled = false; $('wbcFinishHint').textContent = 'Your bot has already answered a test message.'; }
    if (<?php echo $currentStep; ?> === 1) { maybeDraft(); }
  }

  /* ───────── workspace ───────── */
  if (COMPLETE) {
    W.playground({msgs: $('wbcMsgs'), input: $('wbcIn'), send: $('wbcSend'), reset: $('wbcReset'),
      
      hint: function () { return '<div class="wa-sys" data-hint id="wbcHint">Test chat. You play the customer, nothing is sent to WhatsApp.</div>'; }});

    document.querySelectorAll('.wbc-tab').forEach(function (t) { t.addEventListener('click', function () {
      document.querySelectorAll('.wbc-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
      document.querySelectorAll('.wbc-tabpane').forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-tabpane') === t.getAttribute('data-tab')); });
      if (t.getAttribute('data-tab') === 'instr') { loadVersions(); }
    }); });

    function loadVersions() {
      W.api('versions').then(function (r) {
        var box = $('wbcVers');
        if (!r.ok || !r.versions.length) { box.innerHTML = '<span class="wbc-hint">No saved versions yet.</span>'; return; }
        box.innerHTML = r.versions.map(function (v, i) {
          return '<div><span>Version ' + v.version_no + ' &middot; ' + W.esc(v.created_at.slice(0, 16)) + '</span>' + (i === 0 ? '<span class="db-pill is-ok">Current</span>' : '<button type="button" class="wbc-link" data-restore="' + v.id + '">Restore</button>') + '</div>';
        }).join('');
      });
    }
    $('wbcVers').addEventListener('click', function (e) {
      var b = e.target.closest('[data-restore]'); if (!b) { return; }
      if (!confirm('Restore this version? Your current instructions will be replaced (and kept in history).')) { return; }
      W.api('restore_version', {id: b.getAttribute('data-restore')}).then(function (r) { if (r.ok) { location.reload(); } else { W.toast(r.error || 'Could not restore.', false); } });
    });

    $('wbcSaveAll').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      W.api('save_instructions', {name: $('w_name').value, role_text: $('w_role').value, tone_text: $('w_tone').value, scope: lines($('w_scope').value), out_of_scope: lines($('w_oos').value)}).then(function (r) {
        if (!r.ok) { btn.disabled = false; W.toast(r.error || 'Could not save.', false); return; }
        return W.api('save_profile', {profile: collectProfile($('wbcProfileW'))}).then(function (r2) {
          btn.disabled = false;
          if (r2.ok) { W.toast('Changes saved.', true); setTimeout(function () { location.reload(); }, 700); } else { W.toast(r2.error || 'Could not save details.', false); }
        });
      });
    });
  }
})();
</script>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>
