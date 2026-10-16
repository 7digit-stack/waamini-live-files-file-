<?php
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/whatsapp_settings.php';

$pageTitle = 'WhatsApp Onboarding';
$currentPage = 'settings_whatsapp';

// Handle form submit (upsert: first install may have no rows — plain UPDATE would save nothing)
if (isset($_POST['save_wa'])) {
    csrf_verify();
    $fields = ['wa_default_api_version', 'wa_webhook_verify_token', 'system_wa_token', 'system_wa_phone_id', 'wa_onboarding_guide', 'campaign_send_rate_limit_mps'];
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'whatsapp') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = 'whatsapp'");
    $existingSettings = [];
    $existingResult = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='whatsapp' OR setting_key IN ('wa_default_api_version','wa_webhook_verify_token','system_wa_token','system_wa_phone_id','wa_onboarding_guide','campaign_send_rate_limit_mps')");
    if ($existingResult) {
        while ($row = $existingResult->fetch_assoc()) {
            $existingSettings[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    if ($stmt) {
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim((string) $_POST[$key]);
                // For rate limit, validate it's a number
                if ($key === 'campaign_send_rate_limit_mps') {
                    $val = (string)max(1, min(100, intval($val ?? 15)));
                } elseif ($key === 'system_wa_token') {
                    $val = waamini_whatsapp_store_secret($val, $existingSettings[$key] ?? '');
                } elseif ($key === 'wa_webhook_verify_token') {
                    if ($val === '') {
                        $val = $existingSettings[$key] ?? '';
                    }
                }
                $stmt->bind_param('ss', $key, $val);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
    header("Location: settings_whatsapp.php?msg=saved");
    exit();
}

// Fetch settings (by group; also pick up legacy rows saved without group)
$settings = waamini_whatsapp_load_system_settings($conn);

// A token row that exists but reads back empty was encrypted with another server's key
$tokenUnreadable = false;
if (empty($settings['system_wa_token'])) {
    $tr = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'system_wa_token' AND setting_value <> '' LIMIT 1");
    $tokenUnreadable = $tr && $tr->num_rows > 0;
}

// Set defaults
if (!isset($settings['campaign_send_rate_limit_mps'])) {
    $settings['campaign_send_rate_limit_mps'] = 15;
}

// Fetch connected WhatsApp accounts
$waAccounts = $conn->query("
    SELECT wc.*, c.name as company_name 
    FROM whatsapp_config wc 
    JOIN companies c ON wc.company_id = c.id 
    ORDER BY wc.created_at DESC
");

include '../includes/admin_header.php';
?>
<?php
// --- connected accounts summary ---
$allAccounts = [];
$totalAccounts = $activeAccounts = $pendingAccounts = 0;
if ($waAccounts) {
    while ($a = $waAccounts->fetch_assoc()) {
        $allAccounts[] = $a;
        $totalAccounts++;
        if ($a['status'] === 'active') $activeAccounts++;
        if ($a['status'] === 'pending') $pendingAccounts++;
    }
}
$__scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$__callback = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . (function_exists('app_url') ? app_url('webhook.php') : '/webhook.php') . '?company_id={ID}';
$__tokenSaved = !empty($settings['system_wa_token']);
$__phoneSaved = !empty($settings['system_wa_phone_id']);
$__rate = (int)($settings['campaign_send_rate_limit_mps'] ?? 15);
$__guideOn = ($settings['wa_onboarding_guide'] ?? '1') === '1';
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="wo-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'phone'  => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'key'    => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'gauge'  => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
    'book'   => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
    'copy'   => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'dice'   => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M12 12h.01"/><path d="M8 16h.01"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'list'   => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
];
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="wo-toast" role="status"><?php echo $__ic($P['check'], 18); ?> WhatsApp settings saved successfully.</div>
<?php endif; ?>

<div class="wo">

  <a class="wo-back" href="settings.php"><?php echo $__ic($P['back'], 14); ?> Back to Configurations</a>

  <header class="wo-head">
    <div class="wo-head__ic"><?php echo $__ic($P['phone'], 26); ?></div>
    <div class="wo-head__txt">
      <h1>WhatsApp Onboarding</h1>
      <p>Connect the platform's WhatsApp sender, set up the webhook and control how fast campaigns are sent.</p>
    </div>
    <div class="wo-head__chips">
      <span class="wo-chip <?php echo $__tokenSaved ? 'is-ok' : 'is-warn'; ?>"><i></i><?php echo $__tokenSaved ? 'Token saved' : ($tokenUnreadable ? 'Token unreadable' : 'Token missing'); ?></span>
      <span class="wo-chip <?php echo $__phoneSaved ? 'is-ok' : 'is-warn'; ?>"><i></i><?php echo $__phoneSaved ? 'Phone ID set' : 'Phone ID missing'; ?></span>
    </div>
  </header>

  <!-- summary -->
  <section class="wo-stats">
    <div class="wo-stat"><span class="wo-stat__ic is-green"><?php echo $__ic($P['phone'], 20); ?></span><div><b><?php echo $totalAccounts; ?></b><span>Total WhatsApp accounts</span></div></div>
    <div class="wo-stat"><span class="wo-stat__ic is-teal"><?php echo $__ic($P['check'], 20); ?></span><div><b><?php echo $activeAccounts; ?></b><span>Active &amp; connected</span></div></div>
    <div class="wo-stat"><span class="wo-stat__ic is-amber"><?php echo $__ic($P['clock'], 20); ?></span><div><b><?php echo $pendingAccounts; ?></b><span>Pending setup</span></div></div>
  </section>

  <div class="wo-layout">

    <!-- ============ FORM ============ -->
    <form method="POST" class="wo-form" autocomplete="off">
      <?php echo csrf_field(); ?>

      <!-- 1. Master account -->
      <section class="wo-card">
        <div class="wo-card__head">
          <span class="wo-card__n">1</span>
          <div><h2>Master system account</h2><p>The platform sender used for admin broadcasts and subscription reminders.</p></div>
          <span class="wo-badge <?php echo ($__tokenSaved && $__phoneSaved) ? 'is-ok' : 'is-warn'; ?>"><?php echo ($__tokenSaved && $__phoneSaved) ? 'Configured' : 'Needs setup'; ?></span>
        </div>

        <div class="wo-grid2">
          <div class="wo-field">
            <label for="waApiVersion">Default API version</label>
            <select name="wa_default_api_version" id="waApiVersion" class="wo-input">
              <?php $__curVer = (string)($settings['wa_default_api_version'] ?? ''); foreach (array_unique(array_filter(array_merge(['v24.0', 'v23.0', 'v22.0', 'v21.0', 'v20.0', 'v19.0', 'v18.0'], [$__curVer]))) as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($__curVer !== '' ? $__curVer : 'v24.0') === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?><?php echo $v === 'v24.0' ? ' (latest)' : (in_array($v, ['v18.0', 'v19.0', 'v17.0'], true) ? ' (retired by Meta)' : ''); ?></option>
              <?php endforeach; ?>
            </select>
            <small>Use the latest version. Meta retires each version about two years after release.</small>
          </div>
          <div class="wo-field">
            <label for="waPhoneId">System phone number ID</label>
            <input type="text" name="system_wa_phone_id" id="waPhoneId" class="wo-input wo-mono" value="<?php echo htmlspecialchars($settings['system_wa_phone_id'] ?? ''); ?>" placeholder="1234567890">
            <small>The numeric ID from Meta, not the phone number itself.</small>
          </div>
        </div>

        <div class="wo-field">
          <label for="systemWaToken">System WhatsApp access token</label>
          <div class="wo-row">
            <input type="password" name="system_wa_token" id="systemWaToken" class="wo-input wo-mono" value="" autocomplete="new-password"
                   placeholder="<?php echo $__tokenSaved ? 'Token saved. Paste a new token to replace it' : 'EAAL...'; ?>">
            <button type="button" id="toggleTokenBtn" class="wo-btn wo-btn--ghost" aria-label="Show or hide token"><?php echo $__ic($P['eye'], 16); ?> <span>Show</span></button>
          </div>
          <small>Stored encrypted and never shown again. Leave blank to keep the current token.</small>
          <?php if ($tokenUnreadable): ?>
          <div class="wo-alert"><?php echo $__ic($P['info'], 17); ?><span><b>A token is saved but this server cannot read it.</b> It was most likely saved on another server with a different encryption key. Paste the access token again and save, otherwise admin broadcasts and plan reminders cannot be sent from here.</span></div>
          <?php endif; ?>
        </div>

        <div class="wo-note">
          <?php echo $__ic($P['info'], 18); ?>
          <div>
            <b>Using the same Meta app as a client company?</b> Copy these values from <b>Client &rarr; WhatsApp Setup</b>:
            <ol>
              <li>API version, matching the field above.</li>
              <li>The <b>Permanent Access Token</b>, pasted into the token field.</li>
              <li>The <b>Phone Number ID</b>, pasted into the phone ID field.</li>
            </ol>
            Facebook App ID and WABA ID aren't needed here. Sending only needs the token, phone number ID and version.
          </div>
        </div>
      </section>

      <!-- 2. Webhook -->
      <section class="wo-card">
        <div class="wo-card__head">
          <span class="wo-card__n">2</span>
          <div><h2>Webhook</h2><p>Meta calls this URL when customers message your clients' numbers.</p></div>
        </div>

        <div class="wo-field">
          <label for="verifyToken">Default webhook verify token</label>
          <div class="wo-row">
            <input type="text" name="wa_webhook_verify_token" id="verifyToken" class="wo-input wo-mono" value="<?php echo htmlspecialchars($settings['wa_webhook_verify_token'] ?? ''); ?>" placeholder="waamini_verify_xxxxx">
            <button type="button" id="generateTokenBtn" class="wo-btn wo-btn--ghost"><?php echo $__ic($P['dice'], 16); ?> <span>Generate</span></button>
          </div>
          <small>Accepted by the WhatsApp webhook for every company, alongside each company's own token, and used by the Facebook Lead Ads webhook. Paste it in the Meta developer portal when verifying a callback URL.</small>
        </div>

        <div class="wo-field">
          <label>Callback URL</label>
          <div class="wo-row">
            <code class="wo-code" id="cbUrl"><?php echo htmlspecialchars($__callback); ?></code>
            <button type="button" id="copyCbBtn" class="wo-btn wo-btn--ghost"><?php echo $__ic($P['copy'], 16); ?> <span>Copy</span></button>
          </div>
          <small>Replace <code>{ID}</code> with the company ID. Each client gets a unique URL.</small>
        </div>

        <div class="wo-field">
          <label>Required Meta permissions</label>
          <div class="wo-tags">
            <?php foreach (['whatsapp_business_management', 'whatsapp_business_messaging', 'business_management'] as $p): ?>
              <span class="wo-tag"><?php echo $__ic($P['shield'], 13); ?><?php echo htmlspecialchars($p); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- 3. Sending -->
      <section class="wo-card">
        <div class="wo-card__head">
          <span class="wo-card__n">3</span>
          <div><h2>Sending &amp; onboarding</h2><p>Protect your number from spam flags and control the client setup guide.</p></div>
        </div>

        <div class="wo-field">
          <label for="rateInput">Campaign send rate (messages per second)</label>
          <div class="wo-rate">
            <input type="range" id="rateRange" min="1" max="100" value="<?php echo $__rate; ?>" aria-label="Send rate">
            <input type="number" name="campaign_send_rate_limit_mps" id="rateInput" class="wo-input wo-num" min="1" max="100" value="<?php echo $__rate; ?>">
            <span class="wo-unit">msg/sec</span>
          </div>
          <div class="wo-scale"><span>Safer</span><span>Default: 15</span><span>Faster</span></div>
          <small>Lower is slower but safer. Stay at or below 20 for new accounts. Go higher only if Meta approved higher limits (Meta's range is about 20&ndash;80).</small>
        </div>

        <div class="wo-field">
          <label for="waGuide">Show onboarding guide to clients</label>
          <select name="wa_onboarding_guide" id="waGuide" class="wo-input">
            <option value="1" <?php echo $__guideOn ? 'selected' : ''; ?>>Yes, show the step-by-step guide</option>
            <option value="0" <?php echo !$__guideOn ? 'selected' : ''; ?>>No, hide the guide</option>
          </select>
          <small>Shows the step-by-step setup guide on a client's WhatsApp Setup page until their number is connected.</small>
        </div>
      </section>

      <!-- save bar -->
      <div class="wo-savebar">
        <span><?php echo $__ic($P['shield'], 16); ?> Saves API version, webhook token, master token &amp; phone ID, guide and send rate.</span>
        <div>
          <a href="settings.php" class="wo-btn wo-btn--ghost">Cancel</a>
          <button type="submit" name="save_wa" value="1" class="wo-btn wo-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save settings</button>
        </div>
      </div>
    </form>

    <!-- ============ SIDE ============ -->
    <aside class="wo-side">
      <section class="wo-card wo-card--side">
        <h3><?php echo $__ic($P['list'], 17); ?> How onboarding works</h3>
        <ol class="wo-steps">
          <li><b>Client enters their details</b><span>Facebook App ID and Phone Number ID.</span></li>
          <li><b>System creates a callback URL</b><span>One unique URL per company.</span></li>
          <li><b>Client adds it to Meta</b><span>Paste the URL and verify token in the developer portal.</span></li>
          <li><b>Client pastes the access token</b><span>Stored encrypted.</span></li>
          <li><b>Connection is verified</b><span>The account turns Active.</span></li>
        </ol>
      </section>

      <section class="wo-card wo-card--side">
        <h3><?php echo $__ic($P['book'], 17); ?> Master vs client settings</h3>
        <p><b>Master system account</b> (this page, stored in <code>system_settings</code>) is the platform sender for admin broadcasts and subscription-expiry reminders.</p>
        <p><b>Client &rarr; WhatsApp Setup</b> saves each vendor's own line in <code>whatsapp_config</code>. It powers their chat, webhooks, reminders and automations.</p>
        <p class="wo-muted">Use the same credentials in both places only if one number intentionally serves both, for example your own test company. Vendors normally keep their own config.</p>
      </section>
    </aside>
  </div>

  <!-- ============ ACCOUNTS ============ -->
  <section class="wo-card wo-table-card">
    <div class="wo-card__head">
      <span class="wo-card__ic"><?php echo $__ic($P['list'], 18); ?></span>
      <div><h2>Connected WhatsApp accounts</h2><p><?php echo $totalAccounts; ?> account<?php echo $totalAccounts === 1 ? '' : 's'; ?> across all companies.</p></div>
    </div>
    <div class="wo-tablewrap">
      <table class="wo-table">
        <thead><tr><th>Company</th><th>Phone number ID</th><th>API version</th><th>Status</th><th>Connected</th></tr></thead>
        <tbody>
        <?php if (!empty($allAccounts)): ?>
          <?php foreach ($allAccounts as $a):
              $st = (string)($a['status'] ?? '');
              $cls = ['active' => 'is-ok', 'inactive' => 'is-off', 'pending' => 'is-warn'][$st] ?? 'is-off';
              $nm = (string)($a['company_name'] ?? ''); ?>
          <tr>
            <td><span class="wo-co"><i><?php echo htmlspecialchars(strtoupper(substr($nm, 0, 1))); ?></i><?php echo htmlspecialchars($nm); ?></span></td>
            <td><code class="wo-pid"><?php echo htmlspecialchars($a['phone_number_id'] ?? '—'); ?></code></td>
            <td><?php echo htmlspecialchars($a['api_version'] ?? ''); ?></td>
            <td><span class="wo-badge <?php echo $cls; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
            <td class="wo-muted"><?php echo date('d M Y', strtotime($a['created_at'])); ?></td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="5"><div class="wo-empty"><?php echo $__ic($P['phone'], 30); ?><p>No WhatsApp accounts connected yet. Clients can set up their accounts from the Client Panel.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<style>
.wo-alert{display:flex;gap:10px;align-items:flex-start;margin-top:10px;padding:12px 14px;border-radius:12px;background:#FFF4DE;color:#8a5300;font-size:13px;line-height:1.5}
.wo-alert .wo-ic{flex:none;margin-top:1px}
.wo{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#4b5d57;--mu:#7a8c86;--line:#DDEFE7;--soft:#F3FBF7;max-width:none;margin:0;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.wo *{box-sizing:border-box}
.wo-ic{flex:none;display:inline-block;vertical-align:middle}
.wo-toast{max-width:none;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:var(--gd);font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px}
.wo-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:13px;color:var(--tx);text-decoration:none}
.wo-back:hover{color:var(--g)}

.wo-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.wo-head__ic{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;display:grid;place-items:center;box-shadow:0 12px 24px -10px rgba(18,140,126,.6)}
.wo-head__txt{flex:1;min-width:240px}
.wo-head h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}
.wo-head p{margin:0;font-size:14px;color:var(--tx);line-height:1.5}
.wo-head__chips{display:flex;gap:8px;flex-wrap:wrap}
.wo-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;background:#fff;border:1px solid var(--line);color:var(--tx)}
.wo-chip i{width:8px;height:8px;border-radius:50%;background:#c0cbc7}
.wo-chip.is-ok i{background:var(--g2);box-shadow:0 0 0 3px rgba(37,211,102,.2)}.wo-chip.is-ok{color:var(--gd)}
.wo-chip.is-warn i{background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.2)}.wo-chip.is-warn{color:#92580a}

.wo-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
.wo-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px}
.wo-stat b{display:block;font-size:26px;font-weight:800;line-height:1.1}
.wo-stat span{font-size:12.5px;color:var(--tx)}
.wo-stat__ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}
.wo-stat__ic.is-green{background:#E3F7EE;color:var(--g)}.wo-stat__ic.is-teal{background:#DDF3F0;color:#0e7490}.wo-stat__ic.is-amber{background:#FEF3DC;color:#b7791f}

.wo-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px;align-items:start;margin-bottom:22px}
.wo-form{display:flex;flex-direction:column;gap:18px;min-width:0}
.wo-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 14px 34px -26px rgba(18,140,126,.45)}
.wo-card__head{display:flex;align-items:flex-start;gap:14px;margin-bottom:20px}
.wo-card__head h2{margin:0 0 3px;font-size:17px;font-weight:800;color:var(--ink)}
.wo-card__head p{margin:0;font-size:13px;color:var(--tx);line-height:1.5}
.wo-card__head>div{flex:1;min-width:0}
.wo-card__n{width:30px;height:30px;border-radius:50%;background:var(--g);color:#fff;font-weight:800;font-size:14px;display:grid;place-items:center;flex:none}
.wo-card__ic{width:34px;height:34px;border-radius:10px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex:none}

.wo-badge{display:inline-flex;align-items:center;padding:4px 11px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap;background:#eef2f1;color:var(--tx)}
.wo-badge.is-ok{background:#E3F7EE;color:var(--gd)}.wo-badge.is-warn{background:#FEF3DC;color:#92580a}.wo-badge.is-off{background:#eef2f1;color:#6b7b76}

.wo-field{margin-bottom:18px}
.wo-field:last-child{margin-bottom:0}
.wo-field label{display:block;margin-bottom:7px;font-size:13px;font-weight:700;color:var(--ink)}
.wo-field small{display:block;margin-top:7px;font-size:12px;line-height:1.5;color:var(--mu)}
.wo-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.wo-grid2 .wo-field{margin-bottom:0}
.wo-input{width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font:500 14px Inter,system-ui,sans-serif;transition:border-color .2s,box-shadow .2s}
.wo-input:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.wo-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.wo-row{display:flex;gap:10px;align-items:stretch}
.wo-row .wo-input{flex:1;min-width:0}
.wo-code{flex:1;min-width:0;display:block;padding:11px 14px;border:1.5px dashed #BFEBD8;border-radius:11px;background:var(--soft);font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:var(--gd);word-break:break-all}

.wo-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:11px;font:700 13.5px Inter,system-ui,sans-serif;cursor:pointer;text-decoration:none;border:1.5px solid transparent;white-space:nowrap;transition:background .2s,border-color .2s,color .2s,transform .2s,box-shadow .2s}
.wo-btn--ghost{background:#fff;border-color:var(--line);color:var(--ink)}
.wo-btn--ghost:hover{border-color:var(--g);color:var(--g)}
.wo-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;box-shadow:0 10px 22px -10px rgba(18,140,126,.7)}
.wo-btn--primary:hover{transform:translateY(-1px);box-shadow:0 14px 26px -10px rgba(18,140,126,.8)}

.wo-note{display:flex;gap:12px;padding:14px 16px;border-radius:13px;background:var(--soft);border:1px solid var(--line);font-size:13px;line-height:1.6;color:var(--tx)}
.wo-note .wo-ic{color:var(--g);margin-top:2px}
.wo-note b{color:var(--ink)}
.wo-note ol{margin:8px 0;padding-left:18px}

.wo-tags{display:flex;flex-wrap:wrap;gap:8px}
.wo-tag{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:9px;background:var(--soft);border:1px solid var(--line);font:600 12px ui-monospace,Menlo,Consolas,monospace;color:var(--gd)}

.wo-rate{display:flex;align-items:center;gap:14px}
.wo-rate input[type=range]{flex:1;accent-color:var(--g)}
.wo-num{width:90px;text-align:center}
.wo-unit{font-size:12.5px;color:var(--tx);white-space:nowrap}
.wo-scale{display:flex;justify-content:space-between;margin-top:6px;font-size:11px;font-weight:600;color:var(--mu);padding-right:150px}

.wo-savebar{position:sticky;bottom:12px;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 18px;border-radius:16px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid var(--line);box-shadow:0 18px 40px -16px rgba(18,140,126,.45)}
.wo-savebar>span{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--tx)}
.wo-savebar>div{display:flex;gap:10px}

.wo-side{display:flex;flex-direction:column;gap:18px;position:sticky;top:calc(var(--topbar-height,64px) + 16px)}
.wo-card--side{padding:20px}
.wo-card--side h3{display:flex;align-items:center;gap:8px;margin:0 0 14px;font-size:15px;font-weight:800;color:var(--ink)}
.wo-card--side h3 .wo-ic{color:var(--g)}
.wo-card--side p{margin:0 0 10px;font-size:13px;line-height:1.6;color:var(--tx)}
.wo-card--side code{padding:1px 6px;border-radius:5px;background:var(--soft);font-size:12px;color:var(--gd)}
.wo-steps{list-style:none;margin:0;padding:0;counter-reset:s}
.wo-steps li{position:relative;padding:0 0 16px 38px;counter-increment:s}
.wo-steps li:last-child{padding-bottom:0}
.wo-steps li::before{content:counter(s);position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;background:#E3F7EE;color:var(--g);font-weight:800;font-size:12.5px;display:grid;place-items:center}
.wo-steps li:not(:last-child)::after{content:'';position:absolute;left:12px;top:28px;bottom:2px;width:2px;background:var(--line)}
.wo-steps b{display:block;font-size:13.5px;color:var(--ink)}
.wo-steps span{display:block;margin-top:2px;font-size:12.5px;color:var(--tx);line-height:1.5}
.wo-muted{color:var(--mu)!important}

.wo-table-card{padding:22px 0 6px}
.wo-table-card .wo-card__head{padding:0 22px}
.wo-tablewrap{overflow-x:auto}
.wo-table{width:100%;border-collapse:collapse;font-size:13.5px}
.wo-table th{padding:11px 22px;text-align:left;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mu);background:var(--soft);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.wo-table td{padding:14px 22px;border-bottom:1px solid #EDF5F1;color:var(--ink);vertical-align:middle}
.wo-table tbody tr:hover{background:#FAFEFC}
.wo-co{display:inline-flex;align-items:center;gap:11px;font-weight:700}
.wo-co i{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;font-style:normal;font-weight:800;display:grid;place-items:center;font-size:13px}
.wo-pid{padding:4px 9px;border-radius:7px;background:var(--soft);border:1px solid var(--line);font-size:12px;color:var(--gd)}
.wo-empty{display:grid;justify-items:center;gap:10px;padding:34px 16px;color:var(--mu);text-align:center}
.wo-empty p{margin:0;font-size:13.5px;max-width:420px}

@media (max-width:1024px){.wo-layout{grid-template-columns:1fr}.wo-side{position:static;flex-direction:row;flex-wrap:wrap}.wo-side>*{flex:1 1 300px}}
@media (max-width:720px){.wo-stats{grid-template-columns:1fr}.wo-grid2{grid-template-columns:1fr}.wo-row{flex-wrap:wrap}.wo-scale{padding-right:0}.wo-rate{flex-wrap:wrap}.wo-savebar{bottom:6px}.wo-savebar>div{width:100%}.wo-savebar .wo-btn{flex:1}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };

  // generate a webhook verify token
  $('generateTokenBtn').addEventListener('click', function () {
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789', t = 'waamini_verify_';
    for (var i = 0; i < 16; i++) t += chars.charAt(Math.floor(Math.random() * chars.length));
    $('verifyToken').value = t;
  });

  // show / hide the access token
  $('toggleTokenBtn').addEventListener('click', function () {
    var input = $('systemWaToken'), show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.querySelector('span').textContent = show ? 'Hide' : 'Show';
  });

  // copy callback URL
  $('copyCbBtn').addEventListener('click', function () {
    var btn = this, text = $('cbUrl').textContent.trim(), done = function () {
      btn.querySelector('span').textContent = 'Copied';
      setTimeout(function () { btn.querySelector('span').textContent = 'Copy'; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done);
    else { var r = document.createRange(); r.selectNode($('cbUrl')); getSelection().removeAllRanges(); getSelection().addRange(r); document.execCommand('copy'); done(); }
  });

  // keep the rate slider and number box in sync
  var range = $('rateRange'), num = $('rateInput');
  range.addEventListener('input', function () { num.value = range.value; });
  num.addEventListener('input', function () { var v = Math.max(1, Math.min(100, parseInt(num.value || '1', 10))); range.value = v; });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
