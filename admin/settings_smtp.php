<?php
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/mail_settings.php';

$pageTitle = 'Email / SMTP Settings';
$currentPage = 'settings_smtp';

// Fetch settings early so save/test flows can use the current stored values.
$settings = waamini_mail_load_settings($conn);
$settings['smtp_password_dec'] = waamini_mail_secret_value($settings['smtp_password'] ?? null);

// Handle form submit (upsert and masked password support)
if (isset($_POST['save_smtp'])) {
    csrf_verify();
    $fields = ['smtp_provider', 'smtp_transport', 'smtp_region', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'smtp_reply_to'];
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'email') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group='email'");
    if ($stmt) {
        foreach ($fields as $key) {
            if ($key === 'smtp_password') {
                $raw = $_POST[$key] ?? '';
                if ($raw === '********' || $raw === '') {
                    continue;
                }
            }
            $val = trim((string)($_POST[$key] ?? ''));
            if ($key === 'smtp_password' && function_exists('encrypt_secret') && $val !== '') {
                $val = encrypt_secret($val);
            }
            $stmt->bind_param('ss', $key, $val);
            $stmt->execute();
        }
        $stmt->close();
    }
    header("Location: settings_smtp.php?msg=saved");
    exit();
}

// Test email (uses POST values when provided so admin can test unsaved configs)
if (isset($_POST['test_email'])) {
    csrf_verify();

    require_once '../vendor/PHPMailer/src/Exception.php';
    require_once '../vendor/PHPMailer/src/PHPMailer.php';
    require_once '../vendor/PHPMailer/src/SMTP.php';

    $smtpOverrides = [
        'smtp_provider' => $_POST['smtp_provider'] ?? null,
        'smtp_transport' => $_POST['smtp_transport'] ?? null,
        'smtp_region' => $_POST['smtp_region'] ?? null,
        'smtp_host' => $_POST['smtp_host'] ?? null,
        'smtp_port' => $_POST['smtp_port'] ?? null,
        'smtp_username' => $_POST['smtp_username'] ?? null,
        'smtp_encryption' => $_POST['smtp_encryption'] ?? null,
        'smtp_from_email' => $_POST['smtp_from_email'] ?? null,
        'smtp_from_name' => $_POST['smtp_from_name'] ?? null,
        'smtp_reply_to' => $_POST['smtp_reply_to'] ?? null,
    ];
    $postPass = trim((string)($_POST['smtp_password'] ?? ''));
    if ($postPass !== '' && $postPass !== '********') {
        $smtpOverrides['smtp_password'] = $postPass;
    }
    $testConfig = waamini_mail_resolve_config($settings, $smtpOverrides);
    $smtpHost = $testConfig['host'];
    $smtpPort = (int)$testConfig['port'];
    $smtpUser = $testConfig['username'];
    $smtpPass = $testConfig['password'];
    $smtpEnc = $testConfig['encryption'];
    $fromEmail = $testConfig['from_email'];
    $fromName = $testConfig['from_name'];
    $testTo = trim((string)($_POST['smtp_test_to'] ?? $fromEmail));

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        waamini_mail_apply_config($mail, $testConfig);

        $mail->addAddress($testTo);
        $mail->Subject = 'Waamini SMTP Test — ' . date('Y-m-d H:i:s');
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Body = "Hello,\n\n"
            . "This is a test message from your WAAMINI admin panel. If you are reading it, your email settings are working correctly.\n\n"
            . "Mail server: $smtpHost:$smtpPort (" . strtoupper((string)$smtpEnc) . ")\n"
            . "Sent as: $fromName <$fromEmail>\n"
            . "Sent at: " . date('d M Y, h:i A T') . "\n\n"
            . "Regards,\nWAAMINI Support Team";

        $mail->send();
        $testResult = 'success';
        $testMessage = 'Test email sent successfully to <strong>' . htmlspecialchars($testTo) . '</strong>.';
    } catch (Exception $e) {
        $testResult = 'error';
        $testMessage = 'Failed to send test email: ' . htmlspecialchars($mail->ErrorInfo);
    }
}

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/settings-pro.css?v=1">
<?php
?>
<?php
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="sm-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'port'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'user'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'flask'  => '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M6.453 15h11.094"/><path d="M8.5 2h7"/>',
];
$provider = (string)($settings['smtp_provider'] ?? 'custom');
$providerLabel = ['custom' => 'Custom SMTP', 'sendgrid' => 'SendGrid', 'ses' => 'Amazon SES'][$provider] ?? ucfirst($provider);
$host = (string)($settings['smtp_host'] ?? '');
$enc = strtoupper((string)($settings['smtp_encryption'] ?? 'TLS'));
$port = (string)($settings['smtp_port'] ?? '587');
$hasPass = !empty($settings['smtp_password_dec']);
$configured = $host !== '' && !empty($settings['smtp_username']) && !empty($settings['smtp_from_email']) && $hasPass;
$sel = static fn($a, $b) => ((string)$a === (string)$b) ? 'selected' : '';
?>

<div class="sm">

  <a class="sm-back" href="settings.php"><?php echo $__ic($P['back'], 14); ?> Back to Configurations</a>

  <header class="sm-head">
    <div class="sm-head__ic"><?php echo $__ic($P['mail'], 26); ?></div>
    <div class="sm-head__txt">
      <h1>Email / SMTP Settings</h1>
      <p>Configure how Waamini sends system emails: welcome messages, invoices and subscription reminders.</p>
    </div>
    <span class="sm-chip <?php echo $configured ? 'is-ok' : 'is-warn'; ?>"><i></i><?php echo $configured ? 'Configured' : 'Needs setup'; ?></span>
  </header>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
    <div class="sm-alert is-ok" role="status"><?php echo $__ic($P['check'], 18); ?><div>SMTP settings saved successfully.</div></div>
  <?php endif; ?>

  <?php if (isset($testResult)): ?>
    <div class="sm-alert <?php echo $testResult === 'success' ? 'is-ok' : 'is-err'; ?>" role="status">
      <?php echo $__ic($testResult === 'success' ? $P['check'] : $P['alert'], 18); ?>
      <div><?php echo $testMessage; ?></div>
    </div>
  <?php endif; ?>

  <!-- summary -->
  <section class="sm-stats">
    <div class="sm-stat"><span class="sm-stat__ic is-blue"><?php echo $__ic($P['mail'], 20); ?></span><div><span>Provider</span><b><?php echo htmlspecialchars($providerLabel); ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-green"><?php echo $__ic($P['server'], 20); ?></span><div><span>SMTP host</span><b title="<?php echo htmlspecialchars($host); ?>"><?php echo $host !== '' ? htmlspecialchars($host) : 'Not set'; ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-teal"><?php echo $__ic($P['lock'], 20); ?></span><div><span>Encryption</span><b><?php echo htmlspecialchars($enc); ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-amber"><?php echo $__ic($P['port'], 20); ?></span><div><span>Port</span><b><?php echo htmlspecialchars($port); ?></b></div></div>
  </section>

  <form method="POST" id="smtpForm" autocomplete="off">
    <?php echo csrf_field(); ?>

    <div class="sm-grid">

      <!-- Quick setup guide -->
      <section class="sm-card sm-card--wide sm-guide">
        <div class="sm-card__head">
          <span class="sm-card__ic"><?php echo $__ic($P['info'], 19); ?></span>
          <div><h2>Quick setup guide</h2><p>Why this matters: without it, password-reset links and trial reminders can never reach your clients. It takes about 3 minutes.</p></div>
        </div>

        <ol class="sm-steps">
          <li class="<?php echo $host !== '' ? 'is-done' : 'is-now'; ?>"><b>1</b><div><strong>Pick your mail provider</strong><small>Choose below and tap Fill settings.</small></div></li>
          <li class="<?php echo $configured ? 'is-done' : ($host !== '' ? 'is-now' : ''); ?>"><b>2</b><div><strong>Enter username and password</strong><small>The mailbox you send from.</small></div></li>
          <li class="<?php echo $configured ? 'is-now' : ''; ?>"><b>3</b><div><strong>Send a test email</strong><small>Confirm it arrives, then Save.</small></div></li>
        </ol>

        <div class="sm-tabs" role="tablist">
          <button type="button" class="is-active" data-prov="hostinger">Hostinger mailbox</button>
          <button type="button" data-prov="gmail">Gmail</button>
          <button type="button" data-prov="brevo">Brevo</button>
        </div>

        <div class="sm-pane is-active" data-pane="hostinger">
          <div class="sm-pane__txt">
            <p class="sm-tag">Best for a live website</p>
            <ol>
              <li>In Hostinger hPanel open <b>Emails</b> and create a mailbox, for example <b>support@yourdomain.com</b>.</li>
              <li>Use that full address as the <b>username</b> and its password as the <b>password</b>.</li>
              <li>Use the same address as <b>From email</b>.</li>
            </ol>
          </div>
          <div class="sm-pane__box"><span>Host</span><b>smtp.hostinger.com</b><span>Port / Encryption</span><b>465 &middot; SSL</b>
            <button type="button" class="sm-btn sm-btn--outline" data-fill="smtp.hostinger.com|465|ssl">Fill settings</button></div>
        </div>

        <div class="sm-pane" data-pane="gmail">
          <div class="sm-pane__txt">
            <p class="sm-tag">Quick for testing</p>
            <ol>
              <li>Open <b>myaccount.google.com &rarr; Security</b> and turn on <b>2-Step Verification</b>.</li>
              <li>Search <b>App passwords</b>, create one named WAAMINI and copy the 16-letter code.</li>
              <li>Username = your Gmail address. Password = the 16-letter app password, <b>not</b> your normal one.</li>
            </ol>
          </div>
          <div class="sm-pane__box"><span>Host</span><b>smtp.gmail.com</b><span>Port / Encryption</span><b>587 &middot; TLS</b>
            <button type="button" class="sm-btn sm-btn--outline" data-fill="smtp.gmail.com|587|tls">Fill settings</button></div>
        </div>

        <div class="sm-pane" data-pane="brevo">
          <div class="sm-pane__txt">
            <p class="sm-tag">Free plan, good deliverability</p>
            <ol>
              <li>Create a free account at <b>brevo.com</b> and open <b>SMTP &amp; API</b>.</li>
              <li>Copy the <b>SMTP login</b> as the username and generate an <b>SMTP key</b> as the password.</li>
              <li>Verify your sender address in Brevo, then use it as From email.</li>
            </ol>
          </div>
          <div class="sm-pane__box"><span>Host</span><b>smtp-relay.brevo.com</b><span>Port / Encryption</span><b>587 &middot; TLS</b>
            <button type="button" class="sm-btn sm-btn--outline" data-fill="smtp-relay.brevo.com|587|tls">Fill settings</button></div>
        </div>
      </section>
      <!-- Connection -->
      <section class="sm-card">
        <div class="sm-card__head">
          <span class="sm-card__ic"><?php echo $__ic($P['server'], 19); ?></span>
          <div><h2>Provider &amp; connection</h2><p>Presets fill in the host, port and encryption for you.</p></div>
        </div>

        <div class="sm-grid2">
          <div class="sm-field">
            <label for="providerSelect">SMTP provider</label>
            <select name="smtp_provider" id="providerSelect" class="sm-input">
              <option value="custom" <?php echo $sel($provider, 'custom'); ?>>Custom SMTP</option>
              <option value="sendgrid" <?php echo $sel($provider, 'sendgrid'); ?>>SendGrid</option>
              <option value="ses" <?php echo $sel($provider, 'ses'); ?>>Amazon SES</option>
            </select>
          </div>
          <div class="sm-field">
            <label for="transportSelect">Transport method</label>
            <select name="smtp_transport" id="transportSelect" class="sm-input">
              <option value="smtp" <?php echo $sel($settings['smtp_transport'] ?? '', 'smtp'); ?>>SMTP</option>
              <option value="sendmail" <?php echo $sel($settings['smtp_transport'] ?? '', 'sendmail'); ?>>Sendmail</option>
            </select>
          </div>
        </div>

        <div class="sm-field" id="regionGroup" style="display:none;">
          <label for="regionInput">AWS region <span>(for Amazon SES)</span></label>
          <input type="text" name="smtp_region" id="regionInput" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_region'] ?? 'us-east-1'); ?>" placeholder="us-east-1">
        </div>

        <div class="sm-field">
          <label for="hostInput">SMTP host</label>
          <input type="text" name="smtp_host" id="hostInput" class="sm-input" value="<?php echo htmlspecialchars($host); ?>" placeholder="smtp.example.com">
        </div>

        <div class="sm-grid2">
          <div class="sm-field">
            <label for="portInput">SMTP port</label>
            <input type="number" name="smtp_port" id="portInput" class="sm-input" value="<?php echo htmlspecialchars($port); ?>" placeholder="587">
          </div>
          <div class="sm-field">
            <label for="encryptionSelect">Encryption</label>
            <select name="smtp_encryption" id="encryptionSelect" class="sm-input">
              <option value="tls" <?php echo $sel($settings['smtp_encryption'] ?? '', 'tls'); ?>>TLS</option>
              <option value="ssl" <?php echo $sel($settings['smtp_encryption'] ?? '', 'ssl'); ?>>SSL</option>
              <option value="none" <?php echo $sel($settings['smtp_encryption'] ?? '', 'none'); ?>>None</option>
            </select>
          </div>
        </div>

        <div class="sm-note"><?php echo $__ic($P['info'], 17); ?><div>Typical setups: <b>TLS on port 587</b> or <b>SSL on port 465</b>. Choose a provider above to apply its defaults.</div></div>
      </section>

      <!-- Credentials -->
      <section class="sm-card">
        <div class="sm-card__head">
          <span class="sm-card__ic"><?php echo $__ic($P['user'], 19); ?></span>
          <div><h2>Credentials &amp; sender</h2><p>Who emails are sent as, and how we sign in to your mail server.</p></div>
        </div>

        <div class="sm-field">
          <label for="usernameInput">SMTP username</label>
          <input type="text" name="smtp_username" id="usernameInput" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>" placeholder="your-username" autocomplete="off">
        </div>

        <div class="sm-field">
          <label for="smtpPass">SMTP password</label>
          <div class="sm-row">
            <input type="password" name="smtp_password" id="smtpPass" class="sm-input" value="" autocomplete="new-password"
                   placeholder="<?php echo $hasPass ? 'Password saved. Type a new one to replace it' : 'Enter password'; ?>">
            <button type="button" id="togglePass" class="sm-btn sm-btn--ghost" aria-label="Show or hide password"><?php echo $__ic($P['eye'], 16); ?> <span>Show</span></button>
          </div>
          <small>Stored encrypted. Leave this blank to keep the current password.</small>
        </div>

        <div class="sm-grid2">
          <div class="sm-field">
            <label for="fromEmail">From email</label>
            <input type="email" name="smtp_from_email" id="fromEmail" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" placeholder="noreply@yourdomain.com">
          </div>
          <div class="sm-field">
            <label for="fromName">From name</label>
            <input type="text" name="smtp_from_name" id="fromName" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>" placeholder="Your Company">
          </div>
        </div>

        <div class="sm-field">
          <label for="replyTo">Reply-to email</label>
          <input type="email" name="smtp_reply_to" id="replyTo" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_reply_to'] ?? ''); ?>" placeholder="support@yourdomain.com">
          <small>Replies from customers go to this address.</small>
        </div>
      </section>

      <!-- Test -->
      <section class="sm-card sm-card--wide">
        <div class="sm-card__head">
          <span class="sm-card__ic"><?php echo $__ic($P['flask'], 19); ?></span>
          <div><h2>Test configuration</h2><p>Sends a real email using the values currently in the form, even if you haven't saved yet.</p></div>
        </div>
        <div class="sm-test">
          <div class="sm-field">
            <label for="testTo">Test recipient email</label>
            <input type="email" name="smtp_test_to" id="testTo" class="sm-input" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" placeholder="test@example.com">
          </div>
          <button type="submit" name="test_email" value="1" class="sm-btn sm-btn--outline"><?php echo $__ic($P['send'], 16); ?> Send test email</button>
        </div>
      </section>
    </div>

    <!-- actions -->
    <div class="sm-savebar">
      <span><?php echo $__ic($P['lock'], 15); ?> Your password is encrypted before it is stored.</span>
      <div>
        <a href="settings.php" class="sm-btn sm-btn--ghost">Cancel</a>
        <button type="submit" name="save_smtp" value="1" class="sm-btn sm-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save SMTP settings</button>
      </div>
    </div>
  </form>
</div>


<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var providerSelect = document.getElementById('providerSelect');
    var transportSelect = document.getElementById('transportSelect');
    var regionGroup = document.getElementById('regionGroup');
    var regionInput = document.getElementById('regionInput');
    var hostInput = document.getElementById('hostInput');
    var portInput = document.getElementById('portInput');
    var encryptionSelect = document.getElementById('encryptionSelect');
    var usernameInput = document.getElementById('usernameInput');

    function setDefaultValues(provider) {
        if (provider === 'sendgrid') {
            hostInput.value = 'smtp.sendgrid.net';
            portInput.value = '587';
            encryptionSelect.value = 'tls';
            if (!usernameInput.value) {
                usernameInput.value = 'apikey';
            }
        } else if (provider === 'ses') {
            var region = (regionInput.value || 'us-east-1').trim();
            if (!region) {
                region = 'us-east-1';
                regionInput.value = region;
            }
            hostInput.value = 'email-smtp.' + region + '.amazonaws.com';
            portInput.value = '587';
            encryptionSelect.value = 'tls';
        }
    }

    function syncProviderUi() {
        var provider = providerSelect ? providerSelect.value : 'custom';
        if (regionGroup) {
            regionGroup.style.display = provider === 'ses' ? '' : 'none';
        }
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            syncProviderUi();
            setDefaultValues(providerSelect.value);
        });
    }

    if (regionInput) {
        regionInput.addEventListener('input', function () {
            if (providerSelect && providerSelect.value === 'ses') {
                var region = (regionInput.value || 'us-east-1').trim() || 'us-east-1';
                hostInput.value = 'email-smtp.' + region + '.amazonaws.com';
            }
        });
    }

    if (transportSelect) {
        transportSelect.addEventListener('change', function () {
            if (transportSelect.value === 'sendmail' && regionGroup) {
                regionGroup.style.display = 'none';
            } else {
                syncProviderUi();
            }
        });
    }

    // show / hide the password field
    var passBtn = document.getElementById('togglePass'), passInput = document.getElementById('smtpPass');
    if (passBtn && passInput) {
        passBtn.addEventListener('click', function () {
            var show = passInput.type === 'password';
            passInput.type = show ? 'text' : 'password';
            passBtn.querySelector('span').textContent = show ? 'Hide' : 'Show';
        });
    }

    // setup guide: provider tabs + "Fill settings"
    var tabs = document.querySelectorAll('.sm-tabs button'), panes = document.querySelectorAll('.sm-pane');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('is-active', x === t); });
            panes.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-pane') === t.getAttribute('data-prov')); });
        });
    });
    document.querySelectorAll('[data-fill]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = b.getAttribute('data-fill').split('|');
            if (providerSelect) { providerSelect.value = 'custom'; syncProviderUi(); }
            if (transportSelect) { transportSelect.value = 'smtp'; }
            hostInput.value = v[0]; portInput.value = v[1]; encryptionSelect.value = v[2];
            usernameInput.focus();
            b.textContent = 'Filled. Now enter your username and password';
        });
    });
    syncProviderUi();
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
