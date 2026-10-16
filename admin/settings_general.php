<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'General Settings';
$currentPage = 'settings_general';

// Handle form submit
if (isset($_POST['save_settings'])) {
    csrf_verify();
    $fields = [
        'app_name', 'timezone', 'date_format', 'maintenance_mode', 'maintenance_message',
        'support_email', 'support_phone', 'company_website',
        'session_timeout_minutes', 'max_login_attempts', 'require_2fa_admin',
        'file_upload_limit_mb', 'api_rate_limit_requests', 'api_rate_limit_seconds',
        'log_retention_days', 'debug_mode',
        'last_backup_timestamp', 'backup_retention_days',
        'gdpr_data_retention_days', 'auto_delete_logs',
        'alert_on_errors', 'alert_on_suspicious_login', 'alert_email',
        'theme_color', 'enable_custom_css'
    ];
    foreach ($fields as $key) {
        if (isset($_POST[$key]) || ($key === 'maintenance_mode' || $key === 'require_2fa_admin' || $key === 'debug_mode' || $key === 'auto_delete_logs' || $key === 'alert_on_errors' || $key === 'alert_on_suspicious_login' || $key === 'enable_custom_css')) {
            $val = trim($_POST[$key] ?? '');
            // Upsert so fresh installs (no rows yet) still save correctly.
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general')
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group='general'");
            if ($stmt) {
                $stmt->bind_param("ss", $key, $val);
                $stmt->execute();
            }
        }
    }
    // Handle logo upload
    if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] === 0) {
        $uploadDir = '../assets/uploads/';
        if (!file_exists($uploadDir))
            mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['app_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp']) && $_FILES['app_logo']['size'] <= 2097152) { // 2 MB max
            $filename = 'logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['app_logo']['tmp_name'], $uploadDir . $filename);
            $logoPath = '/assets/uploads/' . $filename;
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('app_logo', ?, 'general')
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group='general'");
            if ($stmt) {
                $stmt->bind_param("s", $logoPath);
                $stmt->execute();
            }
        }
    }
    header("Location: settings_general.php?msg=saved");
    exit();
}

// Fetch current settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='general' OR (setting_group IS NULL AND setting_key IN ('app_name','timezone','date_format','maintenance_mode','maintenance_message','app_logo'))");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$timezones = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney', 'Pacific/Auckland'];
$dateFormats = ['d M Y' => '01 Mar 2026', 'Y-m-d' => '2026-03-01', 'd/m/Y' => '01/03/2026', 'm/d/Y' => '03/01/2026', 'F j, Y' => 'March 1, 2026'];

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/settings-pro.css?v=1">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="sm-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'gear'   => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    'app'    => '<path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9.5 8h5"/><path d="M9.5 12H16"/><path d="M9.5 16H14"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'chart'  => '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
    'clip'   => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
    'bell'   => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
    'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
];
$v = static fn(string $k, string $d = '') => (string)($settings[$k] ?? $d);
$maint = $v('maintenance_mode', '0') === '1';
$mysqlV = '';
$rv = $conn->query('SELECT VERSION() AS v');
if ($rv) { $mysqlV = (string)($rv->fetch_assoc()['v'] ?? ''); }

// one on/off row; the hidden 0 makes an unchecked box still save as "0"
$toggle = static function (string $name, string $title, string $hint, string $default = '0', bool $warn = false) use ($v, $esc): void {
    echo '<div class="sm-tog' . ($warn ? ' is-warn' : '') . '"><div><b>' . $esc($title) . '</b><small>' . $esc($hint) . '</small></div>'
       . '<label class="sm-switch"><input type="hidden" name="' . $esc($name) . '" value="0">'
       . '<input type="checkbox" name="' . $esc($name) . '" value="1"' . ($v($name, $default) === '1' ? ' checked' : '') . ' aria-label="' . $esc($title) . '"><span></span></label></div>';
};
?>

<div class="sm">

  <a class="sm-back" href="settings.php"><?php echo $__ic($P['back'], 14); ?> Back to Configurations</a>

  <header class="sm-head">
    <div class="sm-head__ic"><?php echo $__ic($P['gear'], 26); ?></div>
    <div class="sm-head__txt">
      <h1>General Settings</h1>
      <p>Application identity, security, limits, alerts and maintenance for the whole platform.</p>
    </div>
    <span class="sm-chip <?php echo $maint ? 'is-warn' : 'is-ok'; ?>"><i></i><?php echo $maint ? 'Maintenance ON' : 'System online'; ?></span>
  </header>

  <?php if (isset($_GET['msg'])): ?>
    <div class="sm-alert is-ok" role="status"><?php echo $__ic($P['check'], 18); ?><div>Settings saved successfully.</div></div>
  <?php endif; ?>

  <section class="sm-stats">
    <div class="sm-stat"><span class="sm-stat__ic is-blue"><?php echo $__ic($P['app'], 20); ?></span><div><span>App name</span><b title="<?php echo $esc($v('app_name', 'WAAMINI')); ?>"><?php echo $esc($v('app_name', 'WAAMINI')); ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-green"><?php echo $__ic($P['clock'], 20); ?></span><div><span>Timezone</span><b><?php echo $esc($v('timezone', 'Asia/Kolkata')); ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-amber"><?php echo $__ic($P['wrench'], 20); ?></span><div><span>Maintenance</span><b><?php echo $maint ? 'ON' : 'OFF'; ?></b></div></div>
    <div class="sm-stat"><span class="sm-stat__ic is-teal"><?php echo $__ic($P['shield'], 20); ?></span><div><span>Admin 2FA</span><b><?php echo $v('require_2fa_admin', '0') === '1' ? 'Required' : 'Optional'; ?></b></div></div>
  </section>

  <form method="POST" enctype="multipart/form-data" autocomplete="off">
    <?php echo csrf_field(); ?>

    <div class="sm-grid">

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['app'], 19); ?></span>
          <div><h2>Application</h2><p>How the platform is named and displayed.</p></div></div>
        <div class="sm-field"><label for="app_name">Application name</label>
          <input type="text" id="app_name" name="app_name" class="sm-input" value="<?php echo $esc($v('app_name', 'WAAMINI CRM')); ?>"></div>
        <div class="sm-field"><label for="app_logo">App logo</label>
          <?php if ($v('app_logo') !== ''): ?><div class="sm-logo"><img src="<?php echo $esc($v('app_logo')); ?>" alt="Current logo"><span style="font-size:12.5px;color:var(--mu)">Current logo</span></div><?php endif; ?>
          <input type="file" id="app_logo" name="app_logo" class="sm-input" accept="image/*">
          <small>PNG, JPG, SVG or WebP, up to 2 MB.</small></div>
        <div class="sm-grid2">
          <div class="sm-field"><label for="timezone">Timezone</label>
            <select id="timezone" name="timezone" class="sm-input">
              <?php foreach ($timezones as $tz): ?><option value="<?php echo $esc($tz); ?>" <?php echo $v('timezone') === $tz ? 'selected' : ''; ?>><?php echo $esc($tz); ?></option><?php endforeach; ?>
            </select></div>
          <div class="sm-field"><label for="date_format">Date format</label>
            <select id="date_format" name="date_format" class="sm-input">
              <?php foreach ($dateFormats as $fmt => $example): ?><option value="<?php echo $esc($fmt); ?>" <?php echo $v('date_format') === $fmt ? 'selected' : ''; ?>><?php echo $esc($example); ?></option><?php endforeach; ?>
            </select></div>
        </div>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['phone'], 19); ?></span>
          <div><h2>Support &amp; company</h2><p>Contact details shown to your clients.</p></div></div>
        <div class="sm-field"><label for="support_email">Support email</label>
          <input type="email" id="support_email" name="support_email" class="sm-input" value="<?php echo $esc($v('support_email')); ?>" placeholder="support@yourdomain.com"></div>
        <div class="sm-field"><label for="support_phone">Support phone</label>
          <input type="tel" id="support_phone" name="support_phone" class="sm-input" value="<?php echo $esc($v('support_phone')); ?>" placeholder="+91 98765 43210"></div>
        <div class="sm-field"><label for="company_website">Company website</label>
          <input type="url" id="company_website" name="company_website" class="sm-input" value="<?php echo $esc($v('company_website')); ?>" placeholder="https://yourdomain.com"></div>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['lock'], 19); ?></span>
          <div><h2>Security</h2><p>Sessions, sign-in protection and two-factor.</p></div></div>
        <div class="sm-grid2">
          <div class="sm-field"><label for="session_timeout_minutes">Session timeout <span>(minutes)</span></label>
            <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" class="sm-input" min="5" max="480" value="<?php echo $esc($v('session_timeout_minutes', '30')); ?>"></div>
          <div class="sm-field"><label for="max_login_attempts">Max login attempts</label>
            <input type="number" id="max_login_attempts" name="max_login_attempts" class="sm-input" min="3" max="20" value="<?php echo $esc($v('max_login_attempts', '5')); ?>"></div>
        </div>
        <?php $toggle('require_2fa_admin', 'Require 2FA for admins', 'Enforce two-factor authentication for every admin user.'); ?>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['bolt'], 19); ?></span>
          <div><h2>System limits</h2><p>Protect the platform from abuse.</p></div></div>
        <div class="sm-field"><label for="file_upload_limit_mb">File upload limit <span>(MB)</span></label>
          <input type="number" id="file_upload_limit_mb" name="file_upload_limit_mb" class="sm-input" min="1" max="1024" value="<?php echo $esc($v('file_upload_limit_mb', '10')); ?>"></div>
        <div class="sm-field"><label>API rate limit</label>
          <div class="sm-pair">
            <input type="number" name="api_rate_limit_requests" class="sm-input" min="1" max="10000" value="<?php echo $esc($v('api_rate_limit_requests', '100')); ?>" placeholder="Requests" aria-label="Requests">
            <input type="number" name="api_rate_limit_seconds" class="sm-input" min="1" max="3600" value="<?php echo $esc($v('api_rate_limit_seconds', '60')); ?>" placeholder="Per seconds" aria-label="Per seconds">
          </div><small>Maximum API calls (left) per number of seconds (right).</small></div>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['chart'], 19); ?></span>
          <div><h2>Logging &amp; audit</h2><p>What is kept, and for how long.</p></div></div>
        <div class="sm-field"><label for="log_retention_days">Log retention <span>(days)</span></label>
          <input type="number" id="log_retention_days" name="log_retention_days" class="sm-input" min="1" max="3650" value="<?php echo $esc($v('log_retention_days', '90')); ?>"></div>
        <?php $toggle('auto_delete_logs', 'Auto-delete old logs', 'Remove logs older than the retention period.', '1'); ?>
        <?php $toggle('debug_mode', 'Debug mode', 'Production warning: enable only to troubleshoot. It logs sensitive information.', '0', true); ?>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['clip'], 19); ?></span>
          <div><h2>GDPR &amp; compliance</h2><p>Keep only the data you need.</p></div></div>
        <div class="sm-field"><label for="gdpr_data_retention_days">Data retention <span>(days)</span></label>
          <input type="number" id="gdpr_data_retention_days" name="gdpr_data_retention_days" class="sm-input" min="30" max="3650" value="<?php echo $esc($v('gdpr_data_retention_days', '1095')); ?>">
          <small>Inactive contact records are removed after this period (30 to 3650 days).</small></div>
        <div class="sm-note"><?php echo $__ic($P['check'], 17); ?><div><b>GDPR ready.</b> Purging inactive contact data supports the data-minimisation principle.</div></div>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['bell'], 19); ?></span>
          <div><h2>Notifications &amp; alerts</h2><p>Who is told when something goes wrong.</p></div></div>
        <div class="sm-field"><label for="alert_email">Alert email address</label>
          <input type="email" id="alert_email" name="alert_email" class="sm-input" value="<?php echo $esc($v('alert_email', $v('support_email'))); ?>" placeholder="alerts@yourdomain.com"></div>
        <?php $toggle('alert_on_errors', 'Alert on errors', 'Email when a critical error occurs.', '1'); ?>
        <?php $toggle('alert_on_suspicious_login', 'Alert on suspicious login', 'Email on unusual sign-in patterns or repeated failures.', '1'); ?>
      </section>

      <section class="sm-card">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['wrench'], 19); ?></span>
          <div><h2>Maintenance mode</h2><p>Show clients a maintenance page while you work.</p></div></div>
        <?php $toggle('maintenance_mode', $maint ? 'Maintenance is ON' : 'System is online', 'When on, clients see a maintenance page. The admin panel stays available.', '0', $maint); ?>
        <div class="sm-field" style="margin-top:16px"><label for="maintenance_message">Maintenance message</label>
          <textarea id="maintenance_message" name="maintenance_message" class="sm-input" rows="4" placeholder="We are performing scheduled maintenance and will be back shortly."><?php echo $esc($v('maintenance_message')); ?></textarea></div>
      </section>

      <section class="sm-card sm-card--wide">
        <div class="sm-card__head"><span class="sm-card__ic"><?php echo $__ic($P['info'], 19); ?></span>
          <div><h2>System information</h2><p>Read-only details about this server.</p></div></div>
        <div class="sm-info">
          <div><span>Platform version</span><b>v2.0.0 (April 2026)</b></div>
          <div><span>PHP version</span><b><?php echo $esc(phpversion()); ?></b></div>
          <div><span>Database version</span><b><?php echo $esc($mysqlV !== '' ? $mysqlV : 'Unknown'); ?></b></div>
          <div><span>Server time</span><b><?php echo $esc(date('d M Y, h:i A T')); ?></b></div>
        </div>
      </section>
    </div>

    <div class="sm-savebar">
      <span><?php echo $__ic($P['lock'], 15); ?> Changes apply to the whole platform after you save.</span>
      <div>
        <a href="settings.php" class="sm-btn sm-btn--ghost">Cancel</a>
        <button type="submit" name="save_settings" value="1" class="sm-btn sm-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save settings</button>
      </div>
    </div>
  </form>
</div>

<?php include '../includes/admin_footer.php'; ?>
