<?php
include '../includes/client_auth_check.php';
if (!has_feature('ai_chatbot')) {
    header('Location: dashboard.php');
    exit();
}
require_once '../includes/waamini_bot/bootstrap.php';

$pageTitle = 'WAAMINI Bot Settings';
$currentPage = 'waamini_bot_settings';

$S = WB_Store::settings($conn);
$bot = WB_Store::bot($conn, $companyId, true);
$available = WB_Store::platformReady($S) && $bot && $bot['is_admin_enabled'];

$team = [];
$tq = $conn->prepare("SELECT id, name FROM users WHERE company_id = ? AND (status IS NULL OR status <> 'disabled') ORDER BY name");
$tq->bind_param('i', $companyId);
$tq->execute();
$tr = $tq->get_result();
while ($x = $tr->fetch_assoc()) { $team[] = $x; }

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<link rel="stylesheet" href="../assets/css/waamini-bot.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$save = '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>';
$back = '<path d="m15 18-6-6 6-6"/>';
$lock = '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';
?>
<div class="db wbc" data-wbc data-api="ajax_waamini_bot.php" data-token="<?php echo $esc(csrf_get_token()); ?>">
  <div class="db-head">
    <div><h1>Bot settings</h1><p>Name, language, when to reply, follow-ups, instructions and hand-over to your team.</p></div>
    <div class="db-head__actions"><a href="waamini_bot.php" class="db-btn"><?php echo $__ic($back, 16); ?> Back to bot</a></div>
  </div>
  <div id="wbcToast" class="wbc-toast" role="status"></div>

<?php if (!$available): ?>
  <section class="db-card wbc-off"><span><?php echo $__ic($lock, 36); ?></span><h2>Bot is not available</h2><p>Bot is not available, contact support.</p></section>
<?php else: ?>
  <section class="db-card" style="padding:24px;max-width:860px">
    <div class="wbc-field"><label class="wbc-lab" for="sName">Bot name</label><input class="wbc-input" id="sName" value="<?php echo $esc($bot['name']); ?>"></div>

    <div class="wbc-two">
      <div class="wbc-field"><label class="wbc-lab" for="sLang">Reply language</label>
        <select class="wbc-select" id="sLang">
          <?php foreach (['same' => 'Same as the customer', 'english' => 'English', 'tamil' => 'Tamil', 'tanglish' => 'Tanglish (Tamil in English letters)'] as $k => $l): ?>
            <option value="<?php echo $k; ?>" <?php echo $bot['language_mode'] === $k ? 'selected' : ''; ?>><?php echo $esc($l); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="wbc-field"><label class="wbc-lab" for="sMode">When to reply</label>
        <select class="wbc-select" id="sMode">
          <option value="always" <?php echo $bot['reply_mode'] === 'always' ? 'selected' : ''; ?>>Always</option>
          <option value="after_hours" <?php echo $bot['reply_mode'] === 'after_hours' ? 'selected' : ''; ?>>Only outside business hours</option>
        </select>
      </div>
    </div>

    <div class="wbc-two" id="sHours">
      <div class="wbc-field"><label class="wbc-lab" for="sHs">Business opens</label><input class="wbc-input" type="time" id="sHs" value="<?php echo $esc($bot['business_profile']['hours_start'] ?? ''); ?>"></div>
      <div class="wbc-field"><label class="wbc-lab" for="sHe">Business closes</label><input class="wbc-input" type="time" id="sHe" value="<?php echo $esc($bot['business_profile']['hours_end'] ?? ''); ?>"></div>
    </div>
    <p class="wbc-hint" style="margin:-4px 0 16px">Needed for "Only outside business hours". During these hours your team answers.</p>

    <div class="wbc-field"><label class="wbc-lab" for="sWelcome">Welcome line</label><textarea class="wbc-area" id="sWelcome" rows="2" placeholder="Hi! Welcome to our store. How can I help?"><?php echo $esc($bot['welcome_line']); ?></textarea>
      <p class="wbc-hint">Added before the first reply of a new conversation. Leave empty to skip.</p></div>

    <div class="wbc-field"><label class="wbc-lab" for="sHandoff">Hand-off line</label><textarea class="wbc-area" id="sHandoff" rows="2"><?php echo $esc($bot['handoff_line']); ?></textarea>
      <p class="wbc-hint">Sent when the bot passes the chat to your team.</p></div>

    <div class="wbc-field"><label class="wbc-lab" for="sTarget">Who receives handed-over chats</label>
      <select class="wbc-select" id="sTarget">
        <option value="0">No one in particular (chat stays unassigned for the team)</option>
        <?php foreach ($team as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo (int)$bot['handoff_target'] === (int)$u['id'] ? 'selected' : ''; ?>><?php echo $esc($u['name']); ?></option><?php endforeach; ?>
      </select>
    </div>

    <h2 class="fu-h">Follow-up messages</h2>
    <p class="fu-q">Should your bot send a follow-up message after someone stops responding?</p>
    <div class="fu-opts" role="radiogroup" aria-label="Follow-up messages">
      <label class="fu-radio"><input type="radio" name="fuOn" value="1" <?php echo !empty($bot['followup_enabled']) ? 'checked' : ''; ?>><span></span> Yes</label>
      <div class="fu-hours" id="fuHoursBox">
        <label class="fu-float" for="fuHours">Send follow-up message after&hellip;</label>
        <div class="fu-in"><input type="number" id="fuHours" min="1" max="23" value="<?php echo (int)$bot['followup_hours']; ?>"><span>hour(s)</span></div>
        <small id="fuHint">Enter a number between 1-23</small>
      </div>
      <label class="fu-radio"><input type="radio" name="fuOn" value="0" <?php echo empty($bot['followup_enabled']) ? 'checked' : ''; ?>><span></span> No</label>
    </div>
    <p class="wbc-hint" style="margin:6px 0 26px">The bot writes one short follow-up that continues the chat. It is sent once per silence, only if your business sent the last message, and always inside WhatsApp's 24-hour window.</p>

    <h2 class="fu-h">Instructions</h2>
    <div class="fu-add">
      <button type="button" class="fu-plus" id="fuAddBtn" aria-label="Add new instruction">+</button>
      <input class="wbc-input" id="fuAddIn" maxlength="200" placeholder="Add new instruction">
    </div>
    <p class="fu-sub">Instructions your bot follows</p>
    <ul class="fu-list" id="fuList">
      <?php
      $fuIc = [
          'smile' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>',
          'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
          'check' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
          'short' => '<path d="M17 6.1H3"/><path d="M21 12.1H3"/><path d="M15.1 18H3"/>',
          'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
          'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
          'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
          'pin' => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
      ];
      $chosen = (array)($bot['quick_rules']['presets'] ?? []);
      foreach (WB_Store::quickRules() as $rk => $rv): ?>
        <li class="fu-item"><span class="fu-ic"><?php echo $__ic($fuIc[$rv[2]] ?? $fuIc['check'], 22); ?></span><span class="fu-t"><?php echo $esc($rv[0]); ?></span>
          <label class="fu-sw"><input type="checkbox" class="fuPreset" value="<?php echo $esc($rk); ?>" <?php echo in_array($rk, $chosen, true) ? 'checked' : ''; ?> aria-label="<?php echo $esc($rv[0]); ?>"><i></i></label></li>
      <?php endforeach; ?>
      <li class="fu-item" id="fuFollowRow"><span class="fu-ic"><?php echo $__ic($fuIc['chat'], 22); ?></span><span class="fu-t" id="fuFollowTxt"></span></li>
      <?php foreach ((array)($bot['quick_rules']['custom'] ?? []) as $ct): ?>
        <li class="fu-item fu-custom"><span class="fu-ic"><?php echo $__ic($fuIc['pin'], 22); ?></span><span class="fu-t"><?php echo $esc($ct); ?></span><button type="button" class="fu-del" aria-label="Remove instruction">&times;</button></li>
      <?php endforeach; ?>
    </ul>
    <template id="fuCustomTpl"><li class="fu-item fu-custom"><span class="fu-ic"><?php echo $__ic($fuIc['pin'], 22); ?></span><span class="fu-t"></span><button type="button" class="fu-del" aria-label="Remove instruction">&times;</button></li></template>

    <div class="wbc-btn-row"><button type="button" class="db-btn db-btn--primary" id="sSave"><?php echo $__ic($save, 16); ?> Save settings</button></div>
  </section>
<?php endif; ?>
</div>

<style>
.fu-h{margin:30px 0 6px;font-size:18px;color:var(--ink,#0F2622)}
.fu-q{margin:0 0 14px;font-size:15px;font-weight:600;color:var(--ink,#0F2622);line-height:1.5}
.fu-opts{display:flex;flex-direction:column;gap:12px}
.fu-radio{display:flex;align-items:center;gap:14px;font-size:15px;font-weight:600;cursor:pointer;width:max-content}
.fu-radio input{position:absolute;opacity:0}
.fu-radio span{width:22px;height:22px;border-radius:50%;border:2px solid #9AA9A4;display:grid;place-items:center}
.fu-radio input:checked + span{border-color:#128C7E}
.fu-radio input:checked + span::after{content:"";width:11px;height:11px;border-radius:50%;background:#128C7E}
.fu-radio input:focus-visible + span{box-shadow:0 0 0 4px rgba(18,140,126,.2)}
.fu-hours{position:relative;margin:6px 0 4px 36px;max-width:420px}
.fu-float{position:absolute;top:-9px;left:12px;padding:0 6px;background:#fff;font-size:12px;color:#5F6F6A}
.fu-in{display:flex;align-items:center;border:1.5px solid #C9D6D1;border-radius:12px;padding:0 16px;height:54px}
.fu-hours.is-bad .fu-in{border-color:#E5483A}
.fu-in input{flex:1;border:0;outline:0;font:inherit;font-size:17px;background:transparent;min-width:0}
.fu-in span{color:#5F6F6A;font-size:15px}
.fu-hours small{display:block;margin:6px 0 0 14px;font-size:12px;color:#7a8c86}
.fu-hours.is-bad small{color:#E5483A}
.fu-add{display:flex;align-items:center;gap:14px;margin:10px 0 18px}
.fu-plus{width:56px;height:56px;flex:none;border:0;border-radius:14px;background:#EEF4F1;color:#0F2622;font-size:28px;cursor:pointer}
.fu-plus:hover{background:#E3F7EE;color:#128C7E}
.fu-sub{margin:0 0 6px;font-size:14px;font-weight:700;color:var(--ink,#0F2622)}
.fu-list{list-style:none;margin:0;padding:0}
.fu-item{display:flex;align-items:center;gap:16px;padding:12px 4px;border-radius:12px}
.fu-item:hover{background:#F6FAF8}
.fu-ic{color:#5F6F6A;display:grid;place-items:center;width:28px;flex:none}
.fu-t{flex:1;min-width:0;font-size:15px;color:var(--ink,#0F2622)}
.fu-del{border:0;background:transparent;font-size:22px;line-height:1;color:#9AA9A4;cursor:pointer;padding:4px 8px;border-radius:8px}
.fu-del:hover{color:#E5483A;background:#FDECEC}
.fu-sw{position:relative;width:44px;height:25px;flex:none}
.fu-sw input{position:absolute;opacity:0;width:0;height:0}
.fu-sw i{position:absolute;inset:0;border-radius:25px;background:#D5E3DD;cursor:pointer;transition:background .2s}
.fu-sw i::before{content:"";position:absolute;left:3px;top:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s}
.fu-sw input:checked + i{background:#128C7E}
.fu-sw input:checked + i::before{transform:translateX(19px)}
</style>
<?php if ($available): ?>
<script src="../assets/js/waamini-bot.js?v=1"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var W = window.WBC; if (!W) { return; }
  var $ = function (id) { return document.getElementById(id); };
  function hours() { $('sHours').style.display = $('sMode').value === 'after_hours' ? '' : 'none'; }
  $('sMode').addEventListener('change', hours); hours();
  // follow-ups + instructions
  function fuOn() { var r = document.querySelector('input[name="fuOn"]:checked'); return r && r.value === '1'; }
  function fuSync() {
    var on = fuOn(), h = parseInt($('fuHours').value, 10), ok = h >= 1 && h <= 23;
    $('fuHoursBox').style.display = on ? '' : 'none';
    $('fuHoursBox').classList.toggle('is-bad', on && !ok);
    $('fuFollowRow').style.display = on && ok ? '' : 'none';
    $('fuFollowTxt').textContent = 'Send follow-up message after ' + h + ' hour' + (h === 1 ? '' : 's');
    return !on || ok;
  }
  document.querySelectorAll('input[name="fuOn"]').forEach(function (r) { r.addEventListener('change', fuSync); });
  $('fuHours').addEventListener('input', fuSync); fuSync();
  function addCustom() {
    var v = $('fuAddIn').value.trim(); if (!v) { $('fuAddIn').focus(); return; }
    var li = $('fuCustomTpl').content.firstElementChild.cloneNode(true);
    li.querySelector('.fu-t').textContent = v;
    $('fuList').appendChild(li); $('fuAddIn').value = '';
  }
  $('fuAddBtn').addEventListener('click', addCustom);
  $('fuAddIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });
  $('fuList').addEventListener('click', function (e) { var d = e.target.closest('.fu-del'); if (d) { d.closest('li').remove(); } });
  function fuPayload() {
    return {
      followup_enabled: fuOn() ? 1 : 0, followup_hours: parseInt($('fuHours').value, 10) || 1,
      rule_presets: Array.prototype.map.call(document.querySelectorAll('.fuPreset:checked'), function (c) { return c.value; }),
      rule_custom: Array.prototype.map.call(document.querySelectorAll('.fu-custom .fu-t'), function (t) { return t.textContent; })
    };
  }
  $('sSave').addEventListener('click', function () {
    if (!fuSync()) { W.toast('Follow-up hours must be between 1 and 23.', false); $('fuHours').focus(); return; }
    var b = this; b.disabled = true;
    W.api('save_settings', Object.assign({name: $('sName').value, language_mode: $('sLang').value, reply_mode: $('sMode').value, hours_start: $('sHs').value, hours_end: $('sHe').value,
      welcome_line: $('sWelcome').value, handoff_line: $('sHandoff').value, handoff_target: $('sTarget').value}, fuPayload())).then(function (r) {
      b.disabled = false;
      W.toast(r.ok ? 'Settings saved.' : (r.error || 'Could not save.'), !!r.ok);
    });
  });
})();
</script>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>
