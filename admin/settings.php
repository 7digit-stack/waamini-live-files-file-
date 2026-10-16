<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Configurations';
$currentPage = 'settings';

// Gather system health stats
$stats = [];

// General settings count
$res = $conn->query("SELECT COUNT(*) as c FROM system_settings WHERE setting_group='general'");
$stats['general'] = $res ? (int)$res->fetch_assoc()['c'] : 0;

// WhatsApp accounts
$res = $conn->query("SELECT COUNT(*) as c FROM whatsapp_config");
$stats['whatsapp_total'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) as c FROM whatsapp_config WHERE status='active'");
$stats['whatsapp_active'] = $res ? (int)$res->fetch_assoc()['c'] : 0;

// SMTP configured
$res = $conn->query("SELECT COUNT(*) as c FROM system_settings WHERE setting_group='email' AND setting_value IS NOT NULL AND setting_value != ''");
$stats['smtp_configured'] = $res ? (int)$res->fetch_assoc()['c'] : 0;


// Email templates
try {
    $res = $conn->query("SELECT COUNT(*) as c FROM email_templates");
    $stats['email_templates'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
} catch (Throwable $e) {
    $stats['email_templates'] = 0;
}

// System uptime
$stats['php_version'] = phpversion();
$res = $conn->query("SELECT VERSION() as v");
$stats['mysql_version'] = $res ? ($res->fetch_assoc()['v'] ?? 'Unknown') : 'Unknown';
$stats['server_time'] = date('d M Y, h:i A T');

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'gear'   => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    'phone'  => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'card'   => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
    'file'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'circle' => '<circle cx="12" cy="12" r="10"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
];
$smtpOk = $stats['smtp_configured'] > 0;
$waOk = $stats['whatsapp_active'] > 0;
$genOk = $stats['general'] > 0;
$tplOk = $stats['email_templates'] > 0;
$checklist = [
    ['General settings saved', $genOk, 'settings_general.php'],
    ['WhatsApp account connected', $waOk, 'settings_whatsapp.php'],
    ['Email / SMTP configured', $smtpOk, 'settings_smtp.php'],
    ['Email templates ready', $tplOk, 'email_templates.php'],
];
$done = 0; foreach ($checklist as $c) { if ($c[1]) $done++; }
$pct = (int)round($done / count($checklist) * 100);
$cards = [
    ['settings_general.php', 'General settings', 'Application name, timezone, security, maintenance mode, GDPR compliance and system limits.', 'gear', 'is-blue',
        $genOk ? ['is-ok', $stats['general'] . ' settings'] : ['is-warn', 'Not set']],
    ['settings_whatsapp.php', 'WhatsApp onboarding', 'Meta API version, webhook tokens, master system account, campaign rate limits and connected accounts.', 'phone', 'is-green',
        $waOk ? ['is-ok', $stats['whatsapp_active'] . ' connected'] : ['is-warn', 'None connected']],
    ['settings_smtp.php', 'Email / SMTP settings', 'SMTP provider, host, credentials, encryption, sender details and test email.', 'mail', 'is-purple',
        $smtpOk ? ['is-ok', 'Configured'] : ['is-warn', 'Not configured']],
    ['email_templates.php', 'Email templates', 'Notification templates for subscriptions, invoices and system alerts.', 'file', 'is-pink',
        $tplOk ? ['is-ok', $stats['email_templates'] . ' templates'] : ['is-warn', 'No templates']],
];
?>

<div class="db cf">

  <div class="db-head">
    <div>
      <h1>Configurations</h1>
      <p>Everything that controls how Waamini runs, in one place.</p>
    </div>
    <div class="db-head__actions"><span class="db-pill <?php echo $pct === 100 ? 'is-ok' : 'is-warn'; ?>"><?php echo $pct; ?>% set up</span></div>
  </div>

  <?php if (isset($_GET['msg'])): ?>
    <div class="cf-toast" role="status"><?php echo $__ic($P['check'], 18); ?> Settings saved successfully.</div>
  <?php endif; ?>

  <!-- summary -->
  <section class="cf-stats">
    <div class="cf-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['gear'], 20); ?></span><div><span>General settings</span><b><?php echo (int)$stats['general']; ?></b></div></div>
    <div class="cf-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['phone'], 20); ?></span><div><span>WhatsApp connected</span><b><?php echo (int)$stats['whatsapp_active']; ?> <em>/ <?php echo (int)$stats['whatsapp_total']; ?></em></b></div></div>
    <div class="cf-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['mail'], 20); ?></span><div><span>Email / SMTP</span><b class="cf-sm <?php echo $smtpOk ? 'is-ok' : 'is-bad'; ?>"><?php echo $smtpOk ? 'Configured' : 'Not set'; ?></b></div></div>
  </section>

  <div class="db-row db-row--bottom cf-layout">

    <!-- ===== setting cards ===== -->
    <section class="cf-grid">
      <?php foreach ($cards as [$href, $title, $desc, $ico, $tone, $state]): ?>
      <a href="<?php echo $href; ?>" class="cf-card">
        <div class="cf-card__top">
          <span class="db-kpi__ic <?php echo $tone; ?>" style="margin:0"><?php echo $__ic($P[$ico], 22); ?></span>
          <span class="db-pill <?php echo $state[0]; ?>"><?php echo $esc($state[1]); ?></span>
        </div>
        <h3><?php echo $esc($title); ?></h3>
        <p><?php echo $esc($desc); ?></p>
        <span class="cf-card__go">Open <?php echo $__ic($P['arrow'], 15); ?></span>
      </a>
      <?php endforeach; ?>
    </section>

    <!-- ===== side ===== -->
    <aside class="cf-side">
      <section class="db-card">
        <div class="db-card__head"><div><h2>Setup progress</h2><p><?php echo $done; ?> of <?php echo count($checklist); ?> steps complete</p></div></div>
        <div class="cf-prog">
          <div class="cf-ring" style="--p:<?php echo $pct; ?>"><div><b><?php echo $pct; ?>%</b><span>complete</span></div></div>
          <ul class="cf-check">
            <?php foreach ($checklist as [$label, $ok, $href]): ?>
              <li class="<?php echo $ok ? 'is-done' : ''; ?>"><a href="<?php echo $href; ?>"><span class="cf-tick"><?php echo $__ic($ok ? $P['check'] : $P['circle'], 17); ?></span><?php echo $esc($label); ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>

      <section class="db-card">
        <div class="db-card__head"><div><h2>System information</h2></div><span class="db-pill is-ok">Online</span></div>
        <div class="db-meter">
          <div class="db-meter__row"><span>PHP version</span><b><?php echo $esc($stats['php_version']); ?></b></div>
          <div class="db-meter__row"><span>MySQL version</span><b class="cf-trunc"><?php echo $esc($stats['mysql_version']); ?></b></div>
          <div class="db-meter__row"><span>Platform</span><b>v2.0.0</b></div>
          <div class="db-meter__row" style="border-bottom:0"><span>Server time</span><b><?php echo $esc($stats['server_time']); ?></b></div>
        </div>
      </section>
    </aside>
  </div>
</div>

<style>
.cf{max-width:none;margin:0}
.cf-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E;font-size:14.5px;font-weight:600}
.cf-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.cf-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.cf-stat .db-kpi__ic{margin:0}
.cf-stat>div{min-width:0}
.cf-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.cf-stat b{display:block;margin-top:1px;font-size:27px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cf-stat b em{font-style:normal;font-size:17px;color:var(--mu);font-weight:700}
.cf-stat b.cf-sm{font-size:20px;padding-top:3px}.cf-stat b.is-ok{color:#12803f}.cf-stat b.is-bad{color:#c0372b}

.cf-layout{align-items:start}
.cf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.cf-card{display:flex;flex-direction:column;padding:22px;background:#fff;border:1px solid var(--line);border-radius:18px;text-decoration:none;color:inherit;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s,border-color .2s}
.cf-card:hover{transform:translateY(-3px);border-color:#9ED9BF;box-shadow:0 22px 40px -22px rgba(18,140,126,.55)}
.cf-card__top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}
.cf-card h3{margin:0 0 7px;font-size:18px;font-weight:800;color:var(--ink)}
.cf-card p{margin:0 0 18px;font-size:14px;line-height:1.6;color:var(--tx);flex:1}
.cf-card__go{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;color:var(--g);transition:gap .2s}
.cf-card:hover .cf-card__go{gap:11px}

.cf-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.cf-prog{padding:8px 20px 20px;display:grid;justify-items:center;gap:16px}
.cf-ring{--p:0;width:150px;height:150px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--g) calc(var(--p)*1%),#E8F1ED 0)}
.cf-ring>div{width:112px;height:112px;border-radius:50%;background:#fff;display:grid;place-content:center;text-align:center}
.cf-ring b{display:block;font-size:30px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}
.cf-ring span{font-size:12.5px;color:var(--mu)}
.cf-check{list-style:none;margin:0;padding:0;width:100%}
.cf-check li{border-bottom:1px solid #EEF3F1}.cf-check li:last-child{border-bottom:0}
.cf-check a{display:flex;align-items:center;gap:11px;padding:11px 2px;font-size:14px;font-weight:600;color:var(--tx);text-decoration:none}
.cf-check a:hover{color:var(--g)}
.cf-tick{display:grid;place-items:center;color:#b7c6c0}
.cf-check li.is-done .cf-tick{color:var(--g2)}.cf-check li.is-done a{color:var(--ink)}
.cf-trunc{max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

@media (max-width:1100px){.cf-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.cf-layout{grid-template-columns:1fr}.cf-side{flex-direction:row;flex-wrap:wrap}.cf-side>*{flex:1 1 320px}}
@media (max-width:640px){.cf-stats{grid-template-columns:1fr}.cf-grid{grid-template-columns:1fr}}
</style>

<?php include '../includes/admin_footer.php'; ?>
