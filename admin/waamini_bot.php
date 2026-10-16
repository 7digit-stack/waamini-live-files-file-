<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';
require_once '../includes/waamini_bot/bootstrap.php';

$pageTitle = 'WAAMINI Bot Settings';
$currentPage = 'waamini_bot';

$flash = $_SESSION['wb_flash'] ?? null;
unset($_SESSION['wb_flash']);
$setFlash = static function (bool $ok, string $msg): void { $_SESSION['wb_flash'] = [$ok, $msg]; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'save_key' || $act === 'save_provider_key') {
        $provider = (string)($_POST['provider'] ?? 'openai');
        [$ok, $err] = $provider === 'openai'
            ? WB_Store::setKey($conn, (string)($_POST['provider_key'] ?? $_POST['openai_key'] ?? ''))
            : WB_Store::setProviderKey($conn, $provider, (string)($_POST['provider_key'] ?? ''));
        $setFlash($ok, $ok ? 'Provider key saved securely. Select that provider and run Test connection.' : $err);
    } elseif ($act === 'clear_provider_key') {
        $provider = (string)($_POST['provider'] ?? '');
        if ($provider === 'openai') { WB_Store::clearKey($conn); }
        elseif (isset(WB_Store::providers()[$provider])) { WB_Store::clearProviderKey($conn, $provider); $conn->query("UPDATE ai_platform_settings SET key_ok=0, is_enabled=0 WHERE id=1"); }
        $setFlash(true, 'Provider key removed. WAAMINI Bot is paused until the selected provider is tested again.');
    } elseif ($act === 'clear_key') {
        WB_Store::clearKey($conn);
        $setFlash(true, 'The key was removed and every client bot is now off.');
    } elseif ($act === 'test') {
        [$tok, $terr] = WB_OpenAI::test($conn);
        WB_Store::setTestResult($conn, $tok, $terr);
        $setFlash($tok, $tok ? 'Connection test passed.' . ($terr !== '' ? ' ' . $terr : '') : 'Connection test failed: ' . $terr);
    } elseif ($act === 'save_settings') {
        $provider = (string)($_POST['chat_provider'] ?? 'openai');
        $chat = trim((string)($_POST['chat_model'] ?? ''));
        $emb = trim((string)($_POST['embedding_model'] ?? ''));
        $rules = trim((string)($_POST['platform_rules'] ?? ''));
        $enable = isset($_POST['is_enabled']) ? 1 : 0;
        if (!isset(WB_Store::providers()[$provider]) || !preg_match('/^[A-Za-z0-9_.:\-\/]{2,255}$/', $chat) || !preg_match('/^[A-Za-z0-9_.:\-]{2,80}$/', $emb)) {
            $setFlash(false, 'Model names can only contain letters, numbers, dots, dashes and underscores.');
        } else {
            $s = WB_Store::settings($conn);
            $modelsChanged = ($provider !== ($s['chat_provider'] ?? 'openai') || $chat !== $s['chat_model'] || $emb !== $s['embedding_model']);
            $chatHasKey = WB_Store::providerKeyStatus($conn, $provider)['has_key'];
            if ($enable && !$chatHasKey) { $enable = 0; $setFlash(false, 'Save an API key for the selected chat provider before turning the bot on.'); }
            elseif ($enable && !$s['key_ok'] && !$modelsChanged) { $enable = 0; $setFlash(false, 'Run Test connection successfully before turning the bot on.'); }
            $st = $conn->prepare("UPDATE ai_platform_settings SET chat_provider = ?, chat_model = ?, embedding_model = ?, platform_rules = ?, is_enabled = ?" . ($modelsChanged ? ", key_ok = 0, key_tested_at = NULL" : "") . " WHERE id = 1");
            if (!$st) {
                error_log('[WAAMINI Bot] settings update prepare failed (db code ' . (int)$conn->errno . ').');
                $setFlash(false, 'Could not save the provider/model settings. Check that migration 029 is installed and try again.');
            } else {
                $st->bind_param('ssssi', $provider, $chat, $emb, $rules, $enable);
                if (!$st->execute()) {
                    error_log('[WAAMINI Bot] settings update failed (db code ' . (int)$st->errno . ').');
                    $setFlash(false, 'The database did not save the selected provider/model. Apply the latest WAAMINI Bot migrations, then try again.');
                } elseif ($modelsChanged) {
                // new models must be proven before the platform is considered ready
                    [$tok, $terr] = WB_OpenAI::test($conn);
                    WB_Store::setTestResult($conn, $tok, $terr);
                    if (!$tok) { $conn->query("UPDATE ai_platform_settings SET is_enabled = 0 WHERE id = 1"); }
                    $setFlash($tok, $tok ? 'Settings saved and the new models passed the test.' . ($terr !== '' ? ' ' . $terr : '') : 'Provider/model saved, but the connection test failed: ' . $terr);
                } elseif (!isset($_SESSION['wb_flash'])) {
                    $setFlash(true, 'Settings saved.');
                }
            }
        }
    } elseif ($act === 'reindex') {
        [$done, $left, $rerr] = WB_Knowledge::reindex($conn, 20);
        if ($rerr !== '') { $setFlash(false, 'Re-index stopped: ' . $rerr); }
        elseif ($left > 0) { $setFlash(true, 'Re-indexed ' . $done . ' items. ' . $left . ' left, press Re-index again to continue.'); }
        else { $setFlash(true, 'Knowledge index is up to date (' . $done . ' items refreshed).'); }
    } elseif ($act === 'save_limits') {
        $cfg = [];
        foreach ((array)($_POST['lim'] ?? []) as $pid => $row) {
            if (!is_array($row) || !preg_match('/^(default|\d+)$/', (string)$pid)) { continue; }
            $cfg[(string)$pid] = [
                'replies' => max(0, (int)($row['replies'] ?? 0)),
                'pages'   => max(0, (int)($row['pages'] ?? 0)),
                'kb_kb'   => max(0, (int)($row['kb_kb'] ?? 0)),
                'docs'    => max(0, (int)($row['docs'] ?? 0)),
            ];
        }
        $json = json_encode($cfg);
        $st = $conn->prepare("UPDATE ai_platform_settings SET plan_limits = ? WHERE id = 1");
        $st->bind_param('s', $json);
        $st->execute();
        $setFlash(true, 'Plan limits saved.');
    } elseif ($act === 'toggle_client') {
        $cid = (int)($_POST['company_id'] ?? 0);
        $on = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? 1 : 0;
        if ($cid > 0) {
            $st = $conn->prepare("INSERT INTO ai_bots (company_id, is_admin_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_admin_enabled = VALUES(is_admin_enabled)");
            $st->bind_param('ii', $cid, $on);
            $st->execute();
            $setFlash(true, $on ? 'Bot enabled for this client.' : 'Bot disabled for this client.');
        }
    }
    $returnProvider = (string)($_POST['provider'] ?? ($provider ?? ''));
    if (!isset(WB_Store::providers()[$returnProvider])) { $returnProvider = 'openai'; }
    $returnTo = 'waamini_bot.php';
    if (in_array($act, ['save_key', 'save_provider_key', 'clear_provider_key'], true)) {
        $returnTo .= '?credential_provider=' . rawurlencode($returnProvider);
    }
    header('Location: ' . $returnTo);
    exit();
}

$S = WB_Store::settings($conn);
$providers = WB_Store::providers();
$selectedProvider = (string)($S['chat_provider'] ?? 'openai');
$credentialProvider = (string)($_GET['credential_provider'] ?? $selectedProvider);
if (!isset($providers[$credentialProvider])) { $credentialProvider = $selectedProvider; }
$activeKeyStatus = WB_Store::providerKeyStatus($conn, $selectedProvider);
$credentialKeyStatus = WB_Store::providerKeyStatus($conn, $credentialProvider);
$credentialIsActive = $credentialProvider === $selectedProvider;
$displayChatProvider = $credentialKeyStatus['has_key'] ? $credentialProvider : $selectedProvider;
$providerLabels = array_map(static fn($p) => $p['label'], $providers);
$ready = WB_Store::platformReady($S);
$limitsCfg = WB_Store::limitsConfig($S);
$defLimits = WB_Store::defaultLimits();

$plans = [];
$pr = $conn->query("SELECT id, name FROM subscription_plans ORDER BY sort_order, id");
while ($pr && ($x = $pr->fetch_assoc())) { $plans[] = $x; }

$month = date('Y-m');
$clients = [];
$cr = $conn->query("SELECT c.id, c.name, b.id AS bot_id, b.is_admin_enabled, b.is_live, b.tested_at, b.profile_confirmed_at,
        COALESCE(u.replies, 0) AS replies, COALESCE(u.tokens, 0) AS tokens
    FROM companies c
    LEFT JOIN ai_bots b ON b.company_id = c.id
    LEFT JOIN ai_usage_monthly u ON u.company_id = c.id AND u.month = '" . $conn->real_escape_string($month) . "'
    ORDER BY (b.is_live = 1) DESC, u.replies DESC, c.name ASC");
while ($cr && ($x = $cr->fetch_assoc())) { $x['limit'] = WB_Store::limitsFor($conn, (int)$x['id'])['replies']; $clients[] = $x; }
$totReplies = array_sum(array_map('intval', array_column($clients, 'replies')));
$totTokens = array_sum(array_map('intval', array_column($clients, 'tokens')));
$liveN = count(array_filter($clients, static fn($c) => (int)($c['is_live'] ?? 0) === 1 && (int)($c['is_admin_enabled'] ?? 1) === 1));

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'bot'    => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'key'    => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'cpu'    => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
    'users'  => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
];
$limRows = [['default', 'Default (plans not listed)']];
foreach ($plans as $p) { $limRows[] = [(string)$p['id'], $p['name']]; }
?>

<div class="db wb">

  <div class="db-head">
    <div>
      <h1>WAAMINI Bot</h1>
      <p>One AI bot per client. Choose a chat provider and model. Knowledge search uses embeddings when the provider (or an OpenAI key) offers them, and keywords otherwise.</p>
    </div>
    <div class="db-head__actions">
      <span class="db-pill <?php echo $ready ? 'is-ok' : 'is-bad'; ?>"><?php echo $ready ? 'Platform on' : 'Platform off'; ?></span>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="wb-toast <?php echo $flash[0] ? 'is-ok' : 'is-err'; ?>" role="status"><?php echo $__ic($flash[0] ? $P['check'] : $P['alert'], 18); ?> <span><?php echo $esc($flash[1]); ?></span></div>
  <?php endif; ?>

  <section class="wb-stats">
    <div class="wb-stat"><span class="db-kpi__ic <?php echo $ready ? 'is-green' : 'is-pink'; ?>"><?php echo $__ic($P['key'], 20); ?></span><div><span><?php echo $esc($providerLabels[$selectedProvider] ?? 'AI'); ?> chat connection</span><b><?php echo $activeKeyStatus['has_key'] ? ($S['key_ok'] ? 'Connected' : 'Not tested') : 'No key'; ?></b></div></div>
    <div class="wb-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['users'], 20); ?></span><div><span>Live clients</span><b><?php echo number_format($liveN); ?></b></div></div>
    <div class="wb-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['chat'], 20); ?></span><div><span>Replies this month</span><b><?php echo number_format($totReplies); ?></b></div></div>
    <div class="wb-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['cpu'], 20); ?></span><div><span>Tokens this month</span><b><?php echo number_format($totTokens); ?></b></div></div>
  </section>

  <div class="db-row db-row--even">
    <!-- key -->
    <section class="db-card wb-card">
      <div class="wb-ch"><h2>Provider API key</h2><p>Shared by every client bot. Keys are encrypted and never shown in full.</p></div>
      <div class="wb-keybox">
        <span class="wb-keybox__ic"><?php echo $__ic($P['key'], 20); ?></span>
        <div>
          <b id="wbKeyState"><?php echo $credentialKeyStatus['has_key'] ? '••••••••' . $esc($credentialKeyStatus['last4']) : 'No key saved'; ?></b>
          <small><?php
            if (!$credentialKeyStatus['has_key']) { echo 'Choose a provider and save its API key.'; }
            elseif ($credentialIsActive && $S['key_ok']) { echo 'Chat and knowledge connections passed' . ($S['key_tested_at'] ? ' on ' . $esc(date('d M Y, H:i', strtotime((string)$S['key_tested_at']))) : '') . '.'; }
            elseif (!$credentialIsActive) { echo 'Key saved for this provider. Select it under Chat provider, choose a model, then save and test.'; }
            else { echo 'Key is saved. Select a model and run the connection test.'; }
          ?></small>
        </div>
        <span class="db-pill <?php echo $credentialKeyStatus['has_key'] ? ($credentialIsActive && $S['key_ok'] ? 'is-ok' : 'is-warn') : 'is-off'; ?>"><?php echo $credentialKeyStatus['has_key'] ? ($credentialIsActive && $S['key_ok'] ? 'Working' : 'Saved') : 'Missing'; ?></span>
      </div>
      <form method="POST" class="wb-form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_provider_key">
        <label class="wb-lab" for="wbCredentialProvider">Provider</label>
        <select id="wbCredentialProvider" name="provider" class="wb-input">
          <?php foreach ($providers as $key => $p): ?><option value="<?php echo $esc($key); ?>" <?php echo $credentialProvider === $key ? 'selected' : ''; ?>><?php echo $esc($p['label']); ?></option><?php endforeach; ?>
        </select>
        <label class="wb-lab" for="wbKey">API key</label>
        <input id="wbKey" type="password" name="provider_key" class="wb-input" placeholder="Paste provider API key" autocomplete="new-password" spellcheck="false" required>
        <div class="wb-btns">
          <button type="submit" class="db-btn db-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save provider key</button>
        </div>
      </form>
      <?php if ($credentialKeyStatus['has_key'] && $credentialIsActive): ?>
      <div class="wb-btns wb-btns--row">
        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="test"><button type="submit" class="db-btn"><?php echo $__ic($P['refresh'], 16); ?> Test connection</button></form>
        <form method="POST" class="js-confirm" data-confirm="Remove this provider key? The bot will stop until a working provider is selected and tested."><?php echo csrf_field(); ?><input type="hidden" name="action" value="clear_provider_key"><input type="hidden" name="provider" value="<?php echo $esc($credentialProvider); ?>"><button type="submit" class="db-btn wb-danger"><?php echo $__ic($P['trash'], 16); ?> Remove key</button></form>
      </div>
      <?php endif; ?>
      <p class="wb-note">Knowledge search uses the chat provider's embeddings (OpenAI, Gemini, OpenRouter) or an OpenAI key if you saved one. With neither, the bot searches knowledge by keywords and still answers.</p>
    </section>

    <!-- models + switch + rules -->
    <section class="db-card wb-card">
      <div class="wb-ch"><h2>Models and platform rules</h2><p>Change models any time, no code change needed.</p></div>
      <form method="POST" class="wb-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">
        <label class="wb-switch">
          <input type="checkbox" name="is_enabled" value="1" <?php echo $S['is_enabled'] ? 'checked' : ''; ?>>
          <i></i><span><b>WAAMINI Bot for the whole platform</b><small>When off, every client bot is off.</small></span>
        </label>
        <div class="wb-two">
          <div><label class="wb-lab" for="wbProvider">Chat provider</label><select id="wbProvider" name="chat_provider" class="wb-input" data-current-provider="<?php echo $esc($selectedProvider); ?>" data-pending-provider="<?php echo $esc($displayChatProvider); ?>" data-current-model="<?php echo $esc($S['chat_model']); ?>"><?php foreach ($providers as $key => $p): ?><option value="<?php echo $esc($key); ?>" <?php echo $displayChatProvider === $key ? 'selected' : ''; ?>><?php echo $esc($p['label']); ?></option><?php endforeach; ?></select></div>
          <div><label class="wb-lab" for="wbChat">Chat model</label><select id="wbChat" name="chat_model" class="wb-input" required><option value="<?php echo $esc($S['chat_model']); ?>"><?php echo $esc($S['chat_model']); ?></option></select><small id="wbModelStatus" class="wb-note">Loading models…</small></div>
          <div><label class="wb-lab" for="wbEmb">Embedding model</label><input id="wbEmb" name="embedding_model" class="wb-input" value="<?php echo $esc($S['embedding_model']); ?>" required></div>
        </div>
        <label class="wb-lab" for="wbRules">Platform rules (added to every client's bot)</label>
        <textarea id="wbRules" name="platform_rules" class="wb-input wb-area" rows="5"><?php echo $esc($S['platform_rules']); ?></textarea>
        <?php if ($credentialKeyStatus['has_key'] && !$credentialIsActive): ?><p class="wb-note" role="status"><?php echo $esc($providerLabels[$credentialProvider]); ?> key is saved. The bot still uses <?php echo $esc($providerLabels[$selectedProvider] ?? 'the current provider'); ?> until you choose a model and click Save settings below.</p><?php endif; ?>
        <p class="wb-note">Model options are loaded from the selected provider's API catalog. The core safety rules cannot be removed.</p>
        <div class="wb-btns"><button id="wbSaveSettings" type="submit" class="db-btn db-btn--primary" disabled><?php echo $__ic($P['save'], 16); ?> Save settings</button></div>
      </form>
    </section>
  </div>

  <!-- knowledge index -->
  <?php $ix = WB_Knowledge::indexStatus($conn); ?>
  <section class="db-card wb-card" style="margin-bottom:18px">
    <div class="wb-ch"><h2>Knowledge index</h2><p>Semantic search needs vectors made by the current embedding model. After you switch providers or embedding models, re-index so old knowledge is searched accurately. Until then it falls back to keyword search, so nothing breaks.</p></div>
    <?php if (!$ix['available']): ?>
      <div class="wb-note">The selected provider offers no embeddings and no OpenAI key is saved, so knowledge uses keyword search. Add an OpenAI, Gemini or OpenRouter key for semantic search.</div>
    <?php else: ?>
      <div class="wb-keybox"><span class="wb-keybox__ic"><?php echo $__ic($P['cpu'], 20); ?></span>
        <div><b><?php echo $esc($ix['provider'] . ' / ' . $ix['model']); ?></b><small><?php echo number_format($ix['total']); ?> knowledge items &middot; <?php echo $ix['stale'] > 0 ? number_format($ix['stale']) . ' need indexing' : 'all up to date'; ?></small></div>
        <span class="db-pill <?php echo $ix['stale'] > 0 ? 'is-warn' : 'is-ok'; ?>"><?php echo $ix['stale'] > 0 ? 'Needs re-index' : 'Up to date'; ?></span></div>
      <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reindex"><button type="submit" class="db-btn<?php echo $ix['stale'] > 0 ? ' db-btn--primary' : ''; ?>"><?php echo $__ic($P['refresh'], 16); ?> Re-index knowledge</button></form>
    <?php endif; ?>
  </section>

  <!-- limits -->
  <section class="db-card db-tablecard">
    <div class="db-card__head"><div><h2>Plan limits</h2><p>Per plan, applied to each client on that plan</p></div></div>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save_limits">
      <div class="db-tablewrap">
        <table class="db-table wb-table">
          <thead><tr><th>Plan</th><th>AI replies / month</th><th>Website pages</th><th>Knowledge size (KB)</th><th>Documents</th></tr></thead>
          <tbody>
          <?php foreach ($limRows as [$pid, $pname]):
              $row = $limitsCfg[$pid] ?? []; ?>
            <tr>
              <td><b><?php echo $esc($pname); ?></b></td>
              <?php foreach (['replies', 'pages', 'kb_kb', 'docs'] as $k): ?>
                <td><input type="number" min="0" class="wb-input wb-num" name="lim[<?php echo $esc($pid); ?>][<?php echo $k; ?>]" value="<?php echo (int)($row[$k] ?? $defLimits[$k]); ?>"></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="wb-btns wb-btns--pad"><button type="submit" class="db-btn db-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save limits</button></div>
    </form>
  </section>

  <!-- clients -->
  <section class="db-card db-tablecard" style="margin-top:18px">
    <div class="db-card__head"><div><h2>Clients</h2><p>Bot status and usage for <?php echo $esc(date('F Y')); ?></p></div></div>
    <div class="db-tablewrap">
      <table class="db-table wb-table">
        <thead><tr><th>Company</th><th>Status</th><th>Replies</th><th>Tokens</th><th>Limit</th><th>Admin switch</th></tr></thead>
        <tbody>
        <?php if ($clients): foreach ($clients as $c):
            $enabled = $c['bot_id'] === null ? true : (int)$c['is_admin_enabled'] === 1;
            $live = (int)($c['is_live'] ?? 0) === 1;
            if (!$enabled) { $st = ['is-off', 'Disabled by admin']; }
            elseif ($live) { $st = $ready ? ['is-ok', 'Live'] : ['is-warn', 'On (platform off)']; }
            elseif ($c['bot_id'] === null) { $st = ['is-off', 'Not set up']; }
            else { $st = ['is-info', empty($c['tested_at']) ? 'Setting up' : 'Ready, switched off']; }
            $pct = $c['limit'] > 0 ? min(100, round($c['replies'] / $c['limit'] * 100)) : 0; ?>
          <tr>
            <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$c['name'], 0, 1))); ?></span><div><b><?php echo $esc($c['name']); ?></b></div></div></td>
            <td><span class="db-pill <?php echo $st[0]; ?>"><?php echo $esc($st[1]); ?></span></td>
            <td><b><?php echo number_format((int)$c['replies']); ?></b></td>
            <td><?php echo number_format((int)$c['tokens']); ?></td>
            <td style="min-width:150px"><div class="wb-meter"><div class="wb-track"><u style="width:<?php echo $pct; ?>%;background:<?php echo $pct >= 100 ? '#E5483A' : 'linear-gradient(90deg,#25D366,#128C7E)'; ?>"></u></div><span><?php echo number_format($c['limit']); ?></span></div></td>
            <td>
              <form method="POST" class="wb-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle_client">
                <input type="hidden" name="company_id" value="<?php echo (int)$c['id']; ?>">
                <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
                <button type="submit" class="wb-tg<?php echo $enabled ? ' is-on' : ''; ?>" aria-label="<?php echo $enabled ? 'Disable' : 'Enable'; ?> bot for <?php echo $esc($c['name']); ?>" title="<?php echo $enabled ? 'Click to disable' : 'Click to enable'; ?>"><i></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6"><div class="db-empty">No clients yet.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function(){
  var provider=document.getElementById('wbProvider'), model=document.getElementById('wbChat'), credential=document.getElementById('wbCredentialProvider');
  var saveButton=document.getElementById('wbSaveSettings'), modelStatus=document.getElementById('wbModelStatus');
  var token=<?php echo json_encode(csrf_get_token()); ?>;
  var selected=provider.value===provider.dataset.currentProvider?provider.dataset.currentModel:'';
  var keepaliveMs=<?php echo max(60000, min(600000, (int)floor(waamini_default_session_lifetime_seconds()/3)*1000)); ?>;
  window.setInterval(function(){if(document.visibilityState==='visible'){fetch('waamini_bot_models.php?action=keepalive',{method:'GET',credentials:'same-origin',cache:'no-store'}).catch(function(){});}},keepaliveMs);
  function loadModels(){var p=provider.value;model.disabled=true;saveButton.disabled=true;modelStatus.textContent='Loading models from '+provider.options[provider.selectedIndex].text+'…';model.innerHTML='<option>Loading available models…</option>';var data=new URLSearchParams({provider:p,csrf_token:token});
    fetch('waamini_bot_models.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':token},body:data.toString()})
      .then(function(r){return r.json()}).then(function(j){model.innerHTML='';if(!j.ok){model.add(new Option(j.error||'Could not load models',''));model.disabled=true;modelStatus.textContent=j.error||'Could not load models. Save a valid provider key first.';return;}
        var found=false,modelIds=Object.keys(j.models||{});modelIds.forEach(function(id){model.add(new Option(j.models[id]+' — '+id,id));if(id===selected)found=true;});
        if(!found&&selected){model.add(new Option(selected+' (saved selection)',selected));}
        if(selected){model.value=selected;}   // keep the saved/chosen model selected; without this the browser falls back to the first option
        if(!model.options.length){model.add(new Option('No chat models returned',''));model.disabled=true;modelStatus.textContent='No chat models were returned for this provider.';return;}
        model.disabled=false;selected=model.value;saveButton.disabled=!model.value;modelStatus.textContent=modelIds.length+' chat models available. Select one, then save settings.';
      }).catch(function(){model.innerHTML='<option value="">Model catalog unavailable</option>';model.disabled=true;saveButton.disabled=true;modelStatus.textContent='Model catalog unavailable. Check provider key and try again.';});
  }
  provider.addEventListener('change',function(){selected='';credential.value=provider.value;loadModels();});
  model.addEventListener('change',function(){selected=model.value;saveButton.disabled=!model.value;});
  credential.addEventListener('change',function(){var status=document.getElementById('wbKeyState');if(status)status.textContent='Key status will update after saving.';});
  loadModels();
})();
</script>

<style>
.wb{max-width:none;margin:0}
.wb-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45;word-break:break-word}
.wb-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.wb-toast.is-err{background:#FDE4E1;color:#c0372b}
.wb-toast svg{margin-top:2px;flex:none}
.wb-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.wb-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.wb-stat .db-kpi__ic{margin:0}
.wb-stat span:not(.db-kpi__ic){display:block;font-size:13px;font-weight:600;color:var(--mu)}
.wb-stat b{display:block;margin-top:1px;font-size:26px;font-weight:800;letter-spacing:-.4px;color:var(--ink);white-space:nowrap}
.wb-card{padding:22px}
.wb-ch{margin-bottom:16px}
.wb-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.wb-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.wb-keybox{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--soft);margin-bottom:16px}
.wb-keybox>div{flex:1;min-width:0}
.wb-keybox b{display:block;font:700 15px ui-monospace,Consolas,monospace;color:var(--ink);word-break:break-all}
.wb-keybox small{display:block;margin-top:3px;font-size:13px;color:var(--mu);word-break:break-word}
.wb-keybox__ic{width:42px;height:42px;border-radius:12px;background:#fff;color:var(--g);display:grid;place-items:center;flex:none}
.wb-form{display:flex;flex-direction:column;gap:12px}
.wb-lab{font-size:13.5px;font-weight:700;color:var(--tx)}
.wb-input{width:100%;height:44px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.wb-input:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.wb-area{height:auto;padding:12px 14px;resize:vertical;line-height:1.55}
.wb-num{max-width:130px;height:40px}
.wb-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.wb-note{margin:0;padding:11px 14px;border-radius:12px;background:var(--soft);font-size:12.5px;line-height:1.55;color:var(--mu)}
.wb-btns{display:flex;gap:10px;flex-wrap:wrap}
.wb-btns--row{margin-top:12px}
.wb-btns--row form{margin:0}
.wb-btns--pad{padding:6px 20px 20px}
.wb-danger{background:#FDE4E1;border-color:transparent;color:#c0372b}
.wb-danger:hover{background:#FBD1CC;color:#a02a20;border-color:transparent}
.wb-switch{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:var(--soft);cursor:pointer}
.wb-switch input{position:absolute;opacity:0;pointer-events:none}
.wb-switch i{position:relative;width:46px;height:26px;border-radius:99px;background:#C7D1CD;flex:none;transition:background .2s}
.wb-switch i::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.wb-switch input:checked+i{background:linear-gradient(135deg,#25D366,#128C7E)}
.wb-switch input:checked+i::after{transform:translateX(20px)}
.wb-switch input:focus-visible+i{box-shadow:0 0 0 4px rgba(18,140,126,.2)}
.wb-switch span b{display:block;font-size:14.5px;color:var(--ink)}
.wb-switch span small{display:block;margin-top:2px;font-size:12.5px;color:var(--mu)}
.wb-table td{border-bottom:0}
.wb-table tbody tr:nth-child(even) td{background:#F8FCFA}
.wb-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}
.wb-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.wb-meter{display:flex;align-items:center;gap:10px}
.wb-meter span{font-size:13px;font-weight:700;color:var(--tx);min-width:44px;text-align:right}
.wb-track{flex:1;height:8px;border-radius:99px;background:var(--soft);overflow:hidden;min-width:60px}
.wb-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}
.wb-inline{margin:0}
.wb-tg{position:relative;width:46px;height:26px;border:0;border-radius:99px;background:#C7D1CD;cursor:pointer;transition:background .2s;padding:0}
.wb-tg i{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.wb-tg.is-on{background:linear-gradient(135deg,#25D366,#128C7E)}
.wb-tg.is-on i{transform:translateX(20px)}
@media (max-width:1100px){.wb .db-row{grid-template-columns:1fr}.wb-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.wb-stats{grid-template-columns:1fr}.wb-two{grid-template-columns:1fr}}
</style>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener('submit', function (e) {
  var f = e.target.closest && e.target.closest('form.js-confirm[data-confirm]');
  if (f && !confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
});
</script>

<?php include '../includes/admin_footer.php'; ?>
