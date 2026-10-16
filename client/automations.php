<?php
require_once '../includes/client_auth_check.php';

// Ensure the user actually has access to either Follow ups or Drip Campaigns
if (!has_feature('auto_followup') && !has_feature('drip_campaign')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Automations';
$currentPage = 'automations';

/* ── Live stats (all company-scoped, failures fall back to 0) ── */
$autoStats = ['drip_active' => 0, 'drip_total' => 0, 'queue_pending' => 0, 'sent_30d' => 0];
try {
    $st = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status='active'),0) AS active FROM drip_campaigns WHERE company_id=?");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $autoStats['drip_total']  = (int)($r['total'] ?? 0);
        $autoStats['drip_active'] = (int)($r['active'] ?? 0);
        $st->close();
    }
    $st = $conn->prepare("SELECT COALESCE(SUM(status='pending'),0) AS pending, COALESCE(SUM(status='sent' AND created_at >= (NOW() - INTERVAL 30 DAY)),0) AS sent30 FROM automation_queue WHERE company_id=?");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $autoStats['queue_pending'] = (int)($r['pending'] ?? 0);
        $autoStats['sent_30d']      = (int)($r['sent30'] ?? 0);
        $st->close();
    }
} catch (Throwable $e) { /* tables may be missing on legacy installs */ }

$hasFollowup = has_feature('auto_followup');
$hasDrip     = has_feature('drip_campaign');

include '../includes/client_header.php';
?>

<style>
/* ── Marketing Automations — dashboard design system, no divider lines ── */
.au{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.au *{box-sizing:border-box}

.au-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.au-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.au-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.au-hero-ico svg,.au-stat-ico svg,.au-card-ico svg{width:22px;height:22px}
.au-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px;color:var(--ink)}
.au-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:560px;line-height:1.5}
.au-hero-actions{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.au-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.au-btn:hover{border-color:var(--g);color:var(--g)}
.au-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.au-btn--primary:hover{color:#fff;transform:translateY(-1px)}

.au-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.au-stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.au-stat:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.au-stat-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.au-stat.is-amber .au-stat-ico{background:#FEF3DC;color:#b7791f}
.au-stat.is-blue .au-stat-ico{background:#E4EFFD;color:#1d6fd6}
.au-stat.is-purple .au-stat-ico{background:#EFE9FD;color:#6d44d8}
.au-stat-val{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px;color:var(--ink)}
.au-stat-lbl{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

.au-sec-title{font-size:17.5px;font-weight:800;color:var(--ink);margin:0 0 4px}
.au-sec-sub{font-size:13.5px;color:var(--mu);margin:0 0 16px}

.au-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:24px}
.au-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.au-card:hover{transform:translateY(-2px);box-shadow:0 18px 36px -22px rgba(18,140,126,.6)}
.au-card-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
.au-card-ico{width:42px;height:42px;border-radius:12px;background:#E3F7EE;color:var(--g);display:grid;place-items:center}
.au-card:nth-child(1) .au-card-ico{background:#E4EFFD;color:#1d6fd6}
.au-pill{font-size:12px;font-weight:700;padding:4px 11px;border-radius:999px;background:#E7F7F1;color:var(--gd);white-space:nowrap}
.au-pill.off{background:#EEF3F1;color:#7a8c86}
.au-card h3{margin:0 0 6px;font-size:18px;font-weight:800;color:var(--ink);letter-spacing:-.2px}
.au-card p{margin:0;font-size:14px;color:var(--mu);line-height:1.6}
.au-points{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.au-points li{display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--tx);padding:9px 12px;border-radius:10px;background:var(--soft)}
.au-points li::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--g);flex-shrink:0}
.au-card .au-btn{margin-top:auto;width:100%}

.au-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.au-step{background:var(--soft);border-radius:14px;padding:18px 20px}
.au-step-n{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.au-step b{display:block;font-size:14.5px;color:var(--ink);margin-bottom:4px}
.au-step span{font-size:13px;color:var(--mu);line-height:1.5}

@media (max-width:1100px){.au-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:860px){.au-grid,.au-steps{grid-template-columns:1fr}}
@media (max-width:560px){
  .au-hero{padding:18px}
  .au-hero h1{font-size:21px}
  .au-hero-actions,.au-hero-actions .au-btn{width:100%}
  .au-stats{grid-template-columns:1fr}
}
</style>

<div class="au">

  <!-- Hero -->
  <div class="au-hero">
    <div class="au-hero-l">
      <div class="au-hero-ico"><?php echo cn_svg('automations'); ?></div>
      <div>
        <h1>Marketing Automations</h1>
        <p>Nurture every lead on autopilot. Set the rules once and WhatsApp follows up at the right time, every time.</p>
      </div>
    </div>
    <div class="au-hero-actions">
      <?php if ($hasDrip): ?><a href="premium_drip.php" class="au-btn au-btn--primary">+ New drip campaign</a><?php endif; ?>
      <?php if ($hasFollowup): ?><a href="lead_automation.php" class="au-btn">Follow-up settings</a><?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="au-stats">
    <div class="au-stat">
      <div class="au-stat-ico"><?php echo cn_svg('drip'); ?></div>
      <div><div class="au-stat-val"><?php echo number_format($autoStats['drip_active']); ?></div><div class="au-stat-lbl">Active Drips</div></div>
    </div>
    <div class="au-stat is-blue">
      <div class="au-stat-ico"><?php echo cn_svg('campaigns'); ?></div>
      <div><div class="au-stat-val"><?php echo number_format($autoStats['drip_total']); ?></div><div class="au-stat-lbl">Total Campaigns</div></div>
    </div>
    <div class="au-stat is-amber">
      <div class="au-stat-ico"><?php echo cn_svg('scheduled'); ?></div>
      <div><div class="au-stat-val"><?php echo number_format($autoStats['queue_pending']); ?></div><div class="au-stat-lbl">Queued Messages</div></div>
    </div>
    <div class="au-stat is-purple">
      <div class="au-stat-ico"><?php echo cn_svg('blast'); ?></div>
      <div><div class="au-stat-val"><?php echo number_format($autoStats['sent_30d']); ?></div><div class="au-stat-lbl">Sent (30 days)</div></div>
    </div>
  </div>

  <!-- Modules -->
  <h2 class="au-sec-title">Automation tools</h2>
  <p class="au-sec-sub">Pick how you want to engage your leads.</p>

  <div class="au-grid">

    <?php if ($hasFollowup): ?>
    <div class="au-card">
      <div class="au-card-top">
        <div class="au-card-ico"><?php echo cn_svg('chat'); ?></div>
        <span class="au-pill">Included in your plan</span>
      </div>
      <div>
        <h3>Auto Follow-up</h3>
        <p>Automatically send a sequence of messages when a lead's status changes, such as Converted or Follow-up.</p>
      </div>
      <ul class="au-points">
        <li>Timed reminders after 3 and 7 days</li>
        <li>Thank-you and conversion messages</li>
      </ul>
      <a href="lead_automation.php" class="au-btn au-btn--primary">Configure follow-ups <span aria-hidden="true">&rarr;</span></a>
    </div>
    <?php endif; ?>

    <?php if ($hasDrip): ?>
    <div class="au-card">
      <div class="au-card-top">
        <div class="au-card-ico"><?php echo cn_svg('drip'); ?></div>
        <span class="au-pill<?php echo $autoStats['drip_active'] > 0 ? '' : ' off'; ?>"><?php echo $autoStats['drip_active'] > 0 ? $autoStats['drip_active'] . ' running' : 'None running'; ?></span>
      </div>
      <div>
        <h3>Drip Campaigns</h3>
        <p>Build time-delayed sequences that nurture leads over WhatsApp using your approved templates.</p>
      </div>
      <ul class="au-points">
        <li>Multi-step sequences with custom delays</li>
        <li>Pause or resume any campaign anytime</li>
      </ul>
      <a href="premium_drip.php" class="au-btn au-btn--primary">Create drip campaign <span aria-hidden="true">&rarr;</span></a>
    </div>
    <?php endif; ?>

  </div>

  <!-- How it works -->
  <h2 class="au-sec-title">How it works</h2>
  <p class="au-sec-sub">Three steps from setup to steady follow-ups.</p>
  <div class="au-steps">
    <div class="au-step"><div class="au-step-n">1</div><b>Set your trigger</b><span>Choose when a sequence starts, like a new lead or a status change.</span></div>
    <div class="au-step"><div class="au-step-n">2</div><b>Write your messages</b><span>Add steps and delays using your approved WhatsApp templates.</span></div>
    <div class="au-step"><div class="au-step-n">3</div><b>Let it run</b><span>Messages send on schedule while you watch the stats above.</span></div>
  </div>

</div>

<?php include '../includes/client_footer.php'; ?>
