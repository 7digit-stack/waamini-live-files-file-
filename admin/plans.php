<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Plans';
$currentPage = 'subscriptions';

// Handle state-changing actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $status = 'noop';
    
    if ($id > 0 && $action === 'toggle') {
        try {
            $stmt = $conn->prepare("UPDATE subscription_plans SET is_enabled = NOT is_enabled WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $status = 'toggled';
            }
        } catch (Throwable $e) {
            error_log('Plans toggle failed: ' . $e->getMessage());
            $status = 'toggle_failed';
        }
        header('Location: plans.php?msg=' . urlencode($status));
        exit();
    }
}

$plans = null;
try {
    $result = $conn->query("SELECT * FROM subscription_plans ORDER BY sort_order ASC, id ASC");
    $plans = $result ?? null;
} catch (Throwable $e) {
    error_log('Plans fetch failed: ' . $e->getMessage());
    $plans = null;
}

// Get subscriber counts
$subCounts = [];
try {
    $result = $conn->query("SELECT plan_id, COUNT(*) as c FROM subscriptions WHERE status IN ('active','trial') GROUP BY plan_id");
    if ($result) {
        while ($s = $result->fetch_assoc()) {
            if (isset($s['plan_id']) && isset($s['c'])) {
                $subCounts[$s['plan_id']] = (int)$s['c'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('Plans subscriber count query failed: ' . $e->getMessage());
}

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$planRows = [];
if ($plans) { while ($r = $plans->fetch_assoc()) { $planRows[] = $r; } }
$totalPlans = count($planRows);
$enabledPlans = 0; $pausedPlans = 0; $mrr = 0.0;
foreach ($planRows as $r) {
    if (!empty($r['is_enabled'])) $enabledPlans++; else $pausedPlans++;
    $mrr += (float)($r['monthly_price'] ?? 0) * (int)($subCounts[(int)$r['id']] ?? 0);
}
$totalSubs = array_sum($subCounts);
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$lim = static fn($v) => ((int)$v > 0) ? number_format((int)$v) : '∞';
$inr = static fn($v) => '₹' . number_format((float)$v);
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'pause'  => '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
    'play'   => '<polygon points="6 3 20 12 6 21 6 3"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'coin'   => '<circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'contact'=> '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'bot'    => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
];
$mods = [
    ['feat_whatsapp_chat', 'Chat'], ['ai_chatbot', 'AI Bot'], ['whatsapp_flows', 'Flows'], ['feat_drip_campaign', 'Drips'],
    ['api_webhook_access', 'API'], ['feat_pulse', 'Pulse'], ['feat_native_checkout', 'Checkout'], ['feat_crm_analytics', 'Analytics'], ['feat_multi_agent', 'Multi-agent'],
];
$accents = ['#128C7E', '#1d6fd6', '#F59E0B', '#6d44d8', '#d1356f'];
?>

<div class="db pl">

  <div class="db-head">
    <div>
      <h1>Subscription Plans</h1>
      <p>Pricing tiers, usage limits and the features included in each plan.</p>
    </div>
    <div class="db-head__actions">
      <a href="subscriptions.php" class="db-btn"><?php echo $__ic($P['users'], 16); ?> Subscriptions</a>
      <a href="plan_edit.php" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> Create plan</a>
    </div>
  </div>

  <?php if (isset($_GET['msg'])):
      $msg = (string)$_GET['msg'];
      $isError = str_contains($msg, 'failed');
      $message = 'Plan operation successful.';
      if ($msg === 'deleted') $message = 'Plan has been permanently removed.';
      elseif ($msg === 'toggled') $message = 'Plan visibility updated.';
      elseif ($msg === 'created') $message = 'Plan created successfully.';
      elseif ($msg === 'updated' || $msg === 'saved') $message = 'Plan saved successfully.'; ?>
    <div class="pl-toast <?php echo $isError ? 'is-err' : 'is-ok'; ?>" role="status"><?php echo $__ic($isError ? $P['alert'] : $P['check'], 18); ?> <?php echo $esc($message); ?></div>
  <?php endif; ?>

  <!-- summary -->
  <section class="pl-stats">
    <div class="pl-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['layers'], 20); ?></span><div><span>Total plans</span><b><?php echo $totalPlans; ?></b></div></div>
    <div class="pl-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['check'], 20); ?></span><div><span>Enabled</span><b><?php echo $enabledPlans; ?></b><small><?php echo $pausedPlans; ?> paused</small></div></div>
    <div class="pl-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['users'], 20); ?></span><div><span>Active subscriptions</span><b><?php echo number_format($totalSubs); ?></b></div></div>
    <div class="pl-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['coin'], 20); ?></span><div><span>Est. monthly revenue</span><b><?php echo $inr($mrr); ?></b><small>At list price</small></div></div>
  </section>

  <!-- plan cards -->
  <?php if ($totalPlans > 0): ?>
  <section class="pl-grid">
    <?php foreach ($planRows as $i => $p):
        $planId = (int)$p['id'];
        $on = !empty($p['is_enabled']);
        $accent = $accents[$i % count($accents)];
        $sCount = (int)($subCounts[$planId] ?? 0);
        $hasM = !empty($p['monthly_enabled']); $hasY = !empty($p['yearly_enabled']);
        $price = $hasM ? $inr($p['monthly_price']) : ($hasY ? $inr($p['yearly_price']) : '—');
        $per = $hasM ? '/month' : ($hasY ? '/year' : ''); ?>
    <article class="pl-card<?php echo $on ? '' : ' is-paused'; ?>" style="--ac:<?php echo $accent; ?>">

      <header class="pl-card__top">
        <div class="pl-card__name">
          <span class="pl-card__dot"></span>
          <h3><?php echo $esc($p['name']); ?></h3>
        </div>
        <span class="db-pill <?php echo $on ? 'is-ok' : 'is-off'; ?>"><?php echo $on ? 'Enabled' : 'Paused'; ?></span>
      </header>

      <div class="pl-price">
        <b><?php echo $esc($price); ?></b><span><?php echo $per; ?></span>
        <?php if ($hasM && $hasY): ?><em><?php echo $inr($p['yearly_price']); ?> / year</em><?php endif; ?>
      </div>

      <div class="pl-limits">
        <div><?php echo $__ic($P['contact'], 16); ?><b><?php echo $lim($p['limit_contacts']); ?></b><span>Contacts</span></div>
        <div><?php echo $__ic($P['send'], 16); ?><b><?php echo $lim($p['limit_campaigns']); ?></b><span>Campaigns</span></div>
        <div><?php echo $__ic($P['bot'], 16); ?><b><?php echo $lim($p['limit_bot_replies']); ?></b><span>Bot replies</span></div>
        <div><?php echo $__ic($P['users'], 16); ?><b><?php echo $lim($p['limit_team_members']); ?></b><span>Agents</span></div>
      </div>

      <div class="pl-mods">
        <?php $any = false; foreach ($mods as [$k, $l]): if (!empty($p[$k])): $any = true; ?>
          <span class="pl-mod"><?php echo $esc($l); ?></span>
        <?php endif; endforeach; if (!$any): ?><span class="pl-none">Core features only</span><?php endif; ?>
      </div>

      <footer class="pl-card__foot">
        <div class="pl-subs"><i></i><b><?php echo $sCount; ?></b> active <?php echo $sCount === 1 ? 'user' : 'users'; ?></div>
        <div class="pl-acts">
          <a href="plan_edit.php?id=<?php echo $planId; ?>" class="pl-icon" title="Edit plan" aria-label="Edit plan"><?php echo $__ic($P['edit'], 16); ?></a>
          <form method="POST" action="plans.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $planId; ?>">
            <button type="submit" class="pl-icon" title="<?php echo $on ? 'Pause enrollment' : 'Resume enrollment'; ?>" aria-label="<?php echo $on ? 'Pause plan' : 'Resume plan'; ?>"><?php echo $__ic($on ? $P['pause'] : $P['play'], 16); ?></button>
          </form>
          <a href="subscriptions.php?plan=<?php echo $planId; ?>" class="pl-link">Details <?php echo $__ic($P['arrow'], 14); ?></a>
        </div>
      </footer>
    </article>
    <?php endforeach; ?>
  </section>

  <!-- comparison table -->
  <section class="db-card db-tablecard" style="margin-top:20px">
    <div class="db-card__head"><div><h2>Plan comparison</h2><p>Limits and pricing side by side</p></div></div>
    <div class="db-tablewrap">
      <table class="db-table">
        <thead><tr><th>Plan</th><th>Monthly</th><th>Yearly</th><th>Contacts</th><th>Campaigns</th><th>Agents</th><th>Subscribers</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($planRows as $i => $p): $on = !empty($p['is_enabled']); ?>
          <tr>
            <td><div class="db-who"><span class="db-av" style="background:<?php echo $accents[$i % count($accents)]; ?>1f;color:<?php echo $accents[$i % count($accents)]; ?>"><?php echo $esc(strtoupper(substr((string)$p['name'], 0, 1))); ?></span><div><b><?php echo $esc($p['name']); ?></b><small><?php echo $esc(ucfirst((string)$p['duration'])); ?></small></div></div></td>
            <td><?php echo !empty($p['monthly_enabled']) ? $inr($p['monthly_price']) : '<span class="db-mut">—</span>'; ?></td>
            <td><?php echo !empty($p['yearly_enabled']) ? $inr($p['yearly_price']) : '<span class="db-mut">—</span>'; ?></td>
            <td><?php echo $lim($p['limit_contacts']); ?></td>
            <td><?php echo $lim($p['limit_campaigns']); ?></td>
            <td><?php echo $lim($p['limit_team_members']); ?></td>
            <td><?php echo (int)($subCounts[(int)$p['id']] ?? 0); ?></td>
            <td><span class="db-pill <?php echo $on ? 'is-ok' : 'is-off'; ?>"><?php echo $on ? 'Enabled' : 'Paused'; ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php else: ?>
  <section class="db-card"><div class="db-empty">
    <div class="pl-empty__ic"><?php echo $__ic($P['layers'], 30); ?></div>
    <b style="display:block;font-size:16px;color:var(--ink);margin-top:10px">No plans yet</b>
    <p style="margin:4px 0 16px">Create your first subscription plan to start onboarding clients.</p>
    <a href="plan_edit.php" class="db-btn db-btn--primary" style="display:inline-flex"><?php echo $__ic($P['plus'], 16); ?> Create plan</a>
  </div></section>
  <?php endif; ?>
</div>

<style>
.pl{max-width:none;margin:0}
.pl-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;font-size:14.5px;font-weight:600}
.pl-toast.is-ok{background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E}
.pl-toast.is-err{background:#FEF0EE;border:1px solid #F8C9C2;color:#B42318}

.pl-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.pl-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.pl-stat .db-kpi__ic{margin:0}
.pl-stat>div{min-width:0}
.pl-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;letter-spacing:.03em;color:var(--mu)}
.pl-stat b{display:block;margin-top:1px;font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pl-stat small{display:block;margin-top:1px;font-size:12px;color:var(--tx)}

.pl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:18px}
.pl-card{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:box-shadow .2s,transform .2s,border-color .2s}
.pl-card::before{display:none}
.pl-card:hover{transform:translateY(-3px);box-shadow:0 22px 40px -22px rgba(18,140,126,.55);border-color:#CDE7DC}
.pl-card.is-paused{opacity:.72}
.pl-card__top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}
.pl-card__name{display:flex;align-items:center;gap:10px;min-width:0}
.pl-card__dot{width:12px;height:12px;border-radius:4px;background:var(--ac);flex:none}
.pl-card__name h3{margin:0;font-size:19px;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.pl-price{display:flex;align-items:baseline;flex-wrap:wrap;gap:4px 6px;margin-bottom:18px;min-height:46px}
.pl-price b{font-size:34px;font-weight:900;letter-spacing:-1px;color:var(--ink);line-height:1}
.pl-price span{font-size:14.5px;color:var(--tx)}
.pl-price em{flex-basis:100%;font-style:normal;font-size:13px;font-weight:600;color:var(--mu)}

.pl-limits{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.pl-limits>div{display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto;column-gap:10px;align-items:center;padding:11px 13px;border:1px solid var(--line);border-radius:12px;background:var(--soft)}
.pl-limits .db-ic{grid-row:1/3;color:var(--ac)}
.pl-limits b{font-size:17px;font-weight:800;color:var(--ink);line-height:1.1}
.pl-limits span{font-size:12px;font-weight:600;color:var(--tx)}

.pl-mods{display:flex;flex-wrap:wrap;gap:7px;align-content:flex-start;margin-bottom:18px;flex:1;min-height:58px}
.pl-mod{padding:5px 11px;border-radius:8px;background:#E3F7EE;color:#0B6B5E;font-size:12.5px;font-weight:700}
.pl-none{font-size:13px;color:var(--mu);font-style:italic}

.pl-card__foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.pl-subs{display:flex;align-items:center;gap:8px;font-size:14px;color:var(--tx)}
.pl-subs i{width:8px;height:8px;border-radius:50%;background:#25D366;box-shadow:0 0 0 3px rgba(37,211,102,.2)}
.pl-subs b{color:var(--ink)}
.pl-acts{display:flex;align-items:center;gap:8px}
.pl-acts form{margin:0}
.pl-icon{width:36px;height:36px;border-radius:10px;border:1.5px solid var(--line);background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer;text-decoration:none;transition:border-color .2s,color .2s,background .2s}
.pl-icon:hover{border-color:var(--g);color:var(--g);background:var(--soft)}
.pl-link{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 13px;border-radius:10px;background:#E3F7EE;color:#0B6B5E;font-size:13.5px;font-weight:700;text-decoration:none;transition:background .2s}
.pl-link:hover{background:#CFEFE0;color:#0B6B5E}
.pl-empty__ic{width:64px;height:64px;border-radius:18px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto}

@media (max-width:1100px){.pl-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.pl-stats{grid-template-columns:1fr}.pl-grid{grid-template-columns:1fr}.pl-card{padding:18px}}
</style>

<?php include '../includes/admin_footer.php'; ?>
