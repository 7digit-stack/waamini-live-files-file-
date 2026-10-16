<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Campaigns';
$currentPage = 'campaigns';

// Handle delete/cancel via POST + CSRF
if (isset($_POST['delete_id'])) {
    csrf_verify();
    $did = (int)($_POST['delete_id'] ?? 0);
    if ($did > 0) {
        $stmt = $conn->prepare("DELETE FROM campaigns WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('i', $did);
            $stmt->execute();
        }
    }
    header("Location: campaigns.php?msg=deleted");
    exit();
}

if (isset($_POST['cancel_id'])) {
    csrf_verify();
    $cid = (int)($_POST['cancel_id'] ?? 0);
    if ($cid > 0) {
        $stmt = $conn->prepare("UPDATE campaigns SET status='cancelled' WHERE id=? AND status IN ('draft','scheduled')");
        if ($stmt) {
            $stmt->bind_param('i', $cid);
            $stmt->execute();
        }
    }
    header("Location: campaigns.php?msg=cancelled");
    exit();
}

$totalCampaigns = 0;
$activeCampaigns = 0;
$completedCampaigns = 0;
$totalSent = 0;

try {
    $result = $conn->query("SELECT COUNT(*) as c FROM campaigns");
    if ($result && $row = $result->fetch_assoc()) {
        $totalCampaigns = (int)($row['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI campaigns] Total count error: ' . $e->getMessage());
}

try {
    $result = $conn->query("SELECT COUNT(*) as c FROM campaigns WHERE status IN ('running','scheduled')");
    if ($result && $row = $result->fetch_assoc()) {
        $activeCampaigns = (int)($row['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI campaigns] Active count error: ' . $e->getMessage());
}

try {
    $result = $conn->query("SELECT COUNT(*) as c FROM campaigns WHERE status='completed'");
    if ($result && $row = $result->fetch_assoc()) {
        $completedCampaigns = (int)($row['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI campaigns] Completed count error: ' . $e->getMessage());
}

try {
    $result = $conn->query("SELECT COALESCE(SUM(sent_count),0) as c FROM campaigns");
    if ($result && $row = $result->fetch_assoc()) {
        $totalSent = (int)($row['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI campaigns] Total sent error: ' . $e->getMessage());
}

$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$allowedStatuses = ['draft','scheduled','running','completed','failed','cancelled'];
$campaigns = null;
if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $stmt = $conn->prepare("SELECT c.*, mt.name as template_name FROM campaigns c LEFT JOIN message_templates mt ON c.template_id = mt.id WHERE c.status=? ORDER BY c.id DESC");
    if ($stmt) {
        $stmt->bind_param('s', $statusFilter);
        $stmt->execute();
        $campaigns = $stmt->get_result();
    }
} else {
    $campaigns = $conn->query("SELECT c.*, mt.name as template_name FROM campaigns c LEFT JOIN message_templates mt ON c.template_id = mt.id ORDER BY c.id DESC");
}


$statusCounts = array_fill_keys($allowedStatuses, 0);
try {
    $rs = $conn->query("SELECT status, COUNT(*) AS c FROM campaigns GROUP BY status");
    if ($rs) { while ($x = $rs->fetch_assoc()) { $k = (string)($x['status'] ?? ''); if (isset($statusCounts[$k])) { $statusCounts[$k] = (int)$x['c']; } } }
} catch (Throwable $e) { error_log('[WAAMINI campaigns] Status mix error: ' . $e->getMessage()); }
$totalTargets = 0;
try {
    $rs = $conn->query("SELECT COALESCE(SUM(total_contacts),0) AS c FROM campaigns");
    if ($rs && $x = $rs->fetch_assoc()) { $totalTargets = (int)($x['c'] ?? 0); }
} catch (Throwable $e) { error_log('[WAAMINI campaigns] Targets error: ' . $e->getMessage()); }
$deliveryRate = $totalTargets > 0 ? min(100, round($totalSent / $totalTargets * 100)) : 0;

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'play'   => '<polygon points="6 3 20 12 6 21 6 3"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'doc'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'stop'   => '<path d="M22 2 2 22"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
];
$pillCls = ['draft' => 'is-off', 'scheduled' => 'is-info', 'running' => 'is-ok', 'completed' => 'is-ok', 'failed' => 'is-bad', 'cancelled' => 'is-off'];
$barCol  = ['draft' => '#9AA9A4', 'scheduled' => '#3B82F6', 'running' => '#25D366', 'completed' => '#128C7E', 'failed' => '#E5483A', 'cancelled' => '#C7D1CD'];
$rowsOut = [];
if ($campaigns) { while ($c = $campaigns->fetch_assoc()) { if (is_array($c)) { $rowsOut[] = $c; } } }

$flash = null;
if (isset($_GET['msg'])) {
    $m = (string)$_GET['msg'];
    $flash = $m === 'deleted' ? 'Campaign deleted.' : ($m === 'cancelled' ? 'Campaign cancelled.' : ($m === 'created' ? 'Campaign created.' : 'Updated.'));
}
?>

<div class="db cp">

  <div class="db-head">
    <div>
      <h1>Campaigns</h1>
      <p>Create, schedule and track every WhatsApp campaign in one place.</p>
    </div>
    <div class="db-head__actions">
      <a href="templates.php" class="db-btn"><?php echo $__ic($P['doc'], 16); ?> Templates</a>
      <a href="campaign_create.php" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> New campaign</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="cp-toast" role="status"><?php echo $__ic($P['check'], 18); ?> <?php echo $esc($flash); ?></div>
  <?php endif; ?>

  <section class="cp-stats">
    <div class="cp-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['mega'], 20); ?></span><div><span>Total campaigns</span><b><?php echo number_format($totalCampaigns); ?></b></div></div>
    <div class="cp-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['play'], 20); ?></span><div><span>Active / scheduled</span><b><?php echo number_format($activeCampaigns); ?></b></div></div>
    <div class="cp-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['check'], 20); ?></span><div><span>Completed</span><b><?php echo number_format($completedCampaigns); ?></b></div></div>
    <div class="cp-stat"><span class="db-kpi__ic is-pink"><?php echo $__ic($P['send'], 20); ?></span><div><span>Messages sent</span><b><?php echo number_format($totalSent); ?></b></div></div>
  </section>

  <div class="db-row db-row--bottom">

    <section class="db-card cp-main">
      <div class="db-card__head">
        <div><h2>All campaigns</h2><p><span id="cpShown"><?php echo count($rowsOut); ?></span> <?php echo $statusFilter ? ucfirst($statusFilter) : 'total'; ?></p></div>
        <label class="cp-search"><?php echo $__ic($P['search'], 16); ?><input type="search" id="cpSearch" placeholder="Search campaigns" aria-label="Search campaigns"></label>
      </div>

      <div class="cp-chips">
        <a href="campaigns.php" class="cp-chip<?php echo $statusFilter === '' ? ' is-active' : ''; ?>">All <em><?php echo $totalCampaigns; ?></em></a>
        <?php foreach ($allowedStatuses as $st): ?>
          <a href="campaigns.php?status=<?php echo $st; ?>" class="cp-chip<?php echo $statusFilter === $st ? ' is-active' : ''; ?>"><?php echo ucfirst($st); ?> <em><?php echo $statusCounts[$st]; ?></em></a>
        <?php endforeach; ?>
      </div>

      <div class="cp-list" id="cpList">
        <?php if ($rowsOut): foreach ($rowsOut as $c):
            $id = (int)($c['id'] ?? 0);
            $st = (string)($c['status'] ?? '');
            $tot = (int)($c['total_contacts'] ?? 0);
            $sent = (int)($c['sent_count'] ?? 0);
            $pr = $tot > 0 ? min(100, (int)round($sent / $tot * 100)) : 0;
            $nm = (string)($c['name'] ?? '');
            $target = ucfirst((string)($c['target_type'] ?? ''));
            if (!empty($c['target_value'])) { $target .= ': ' . $c['target_value']; }
        ?>
        <article class="cp-item" data-name="<?php echo $esc(strtolower($nm . ' ' . ($c['template_name'] ?? ''))); ?>">
          <span class="cp-av"><?php echo $esc(strtoupper(mb_substr($nm !== '' ? $nm : '?', 0, 1))); ?></span>
          <div class="cp-info">
            <a href="campaign_view.php?id=<?php echo $id; ?>" class="cp-name"><?php echo $esc($nm); ?></a>
            <div class="cp-sub">
              <span><?php echo $__ic($P['doc'], 13); ?> <?php echo $esc($c['template_name'] ?? 'No template'); ?></span>
              <span><?php echo $__ic($P['users'], 13); ?> <?php echo $esc($target); ?> &middot; <?php echo number_format($tot); ?></span>
              <span><?php echo $__ic($P['cal'], 13); ?> <?php echo !empty($c['scheduled_at']) ? $esc(date('d M Y, H:i', strtotime((string)$c['scheduled_at']))) : 'Not scheduled'; ?></span>
            </div>
          </div>
          <div class="cp-prog">
            <div class="cp-prog__t"><b><?php echo $pr; ?>%</b><small><?php echo number_format($sent); ?>/<?php echo number_format($tot); ?> sent</small></div>
            <div class="db-bar"><i style="width:<?php echo $pr; ?>%"></i></div>
          </div>
          <span class="db-pill <?php echo $pillCls[$st] ?? 'is-off'; ?>"><?php echo $esc(ucfirst($st !== '' ? $st : 'unknown')); ?></span>
          <div class="cp-acts">
            <a href="campaign_view.php?id=<?php echo $id; ?>" class="cp-ib" title="View"><?php echo $__ic($P['eye'], 17); ?></a>
            <?php if (in_array($st, ['draft', 'scheduled'], true)): ?>
              <form method="POST" class="js-confirm-form" data-confirm="Cancel this campaign?">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="cancel_id" value="<?php echo $id; ?>">
                <button type="submit" class="cp-ib is-warn" title="Cancel"><?php echo $__ic($P['stop'], 17); ?></button>
              </form>
            <?php endif; ?>
            <form method="POST" class="js-confirm-form" data-confirm="Delete this campaign?">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="delete_id" value="<?php echo $id; ?>">
              <button type="submit" class="cp-ib is-bad" title="Delete"><?php echo $__ic($P['trash'], 17); ?></button>
            </form>
          </div>
        </article>
        <?php endforeach; endif; ?>
        <div class="db-empty" id="cpEmpty" style="<?php echo $rowsOut ? 'display:none' : ''; ?>">
          <?php echo $rowsOut ? 'No campaigns match your search.' : 'No campaigns yet. Create your first campaign!'; ?>
          <?php if (!$rowsOut): ?><div style="margin-top:14px"><a href="campaign_create.php" class="db-btn db-btn--primary"><?php echo $__ic($P['plus'], 16); ?> Create campaign</a></div><?php endif; ?>
        </div>
      </div>
    </section>

    <aside class="cp-side">
      <section class="db-card cp-card">
        <div class="db-card__head"><div><h2>Delivery</h2><p>Messages sent vs. targeted</p></div></div>
        <div class="cp-rate">
          <?php $circ = 2 * M_PI * 70; $dash = $circ * $deliveryRate / 100; $left = max(0, $totalTargets - $totalSent); ?>
          <div class="cp-donut">
            <svg viewBox="0 0 180 180" width="180" height="180" role="img" aria-label="<?php echo (int)$deliveryRate; ?> percent delivered">
              <defs><linearGradient id="cpGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#25D366"/><stop offset="1" stop-color="#128C7E"/></linearGradient></defs>
              <circle cx="90" cy="90" r="70" fill="none" stroke="#E8F1ED" stroke-width="20"/>
              <circle cx="90" cy="90" r="70" fill="none" stroke="url(#cpGrad)" stroke-width="20" stroke-linecap="round" stroke-dasharray="<?php echo round($dash, 2); ?> <?php echo round($circ, 2); ?>" transform="rotate(-90 90 90)"/>
            </svg>
            <div class="cp-donut__c"><b><?php echo (int)$deliveryRate; ?>%</b><span>delivered</span></div>
          </div>
          <div class="cp-dleg">
            <span><i style="background:#25D366"></i>Sent <b><?php echo number_format($totalSent); ?></b></span>
            <span><i style="background:#E8F1ED"></i>Remaining <b><?php echo number_format($left); ?></b></span>
          </div>
        </div>

      <section class="db-card cp-card">
        <div class="db-card__head"><div><h2>Status mix</h2><p>Campaigns by state</p></div></div>
        <div class="cp-mix">
          <?php foreach ($allowedStatuses as $st): $pct = $totalCampaigns > 0 ? round($statusCounts[$st] / $totalCampaigns * 100) : 0; ?>
            <div class="cp-mix__r">
              <span><i style="background:<?php echo $barCol[$st]; ?>"></i><?php echo ucfirst($st); ?></span>
              <div class="cp-mix__bar"><u style="width:<?php echo (int)$pct; ?>%;background:<?php echo $barCol[$st]; ?>"></u></div>
              <b><?php echo (int)$statusCounts[$st]; ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </aside>
  </div>
</div>

<style>
.cp{max-width:none;margin:0}
.cp-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;color:#0B6B5E;font-size:14.5px;font-weight:600}
.cp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.cp-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.cp-stat .db-kpi__ic{margin:0}
.cp-stat span:not(.db-kpi__ic){display:block;font-size:13px;font-weight:600;color:var(--mu)}
.cp-stat b{display:block;margin-top:1px;font-size:28px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap}
.cp-search{display:flex;align-items:center;gap:9px;width:240px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.cp-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cp-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.cp-chips{display:flex;gap:8px;flex-wrap:wrap;padding:4px 20px 8px}
.cp-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:999px;background:var(--soft);color:var(--tx);font-size:14px;font-weight:700;text-decoration:none;transition:background .15s,color .15s}
.cp-chip em{font-style:normal;font-size:12.5px;padding:1px 8px;border-radius:999px;background:#fff;color:var(--mu)}
.cp-chip:hover{background:#E6FCF2;color:var(--gd)}
.cp-chip.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.cp-chip.is-active em{background:rgba(255,255,255,.22);color:#fff}
.cp-list{display:flex;flex-direction:column;gap:10px;padding:8px 20px 20px}
.cp-item{display:grid;grid-template-columns:48px minmax(0,1.6fr) minmax(130px,.8fr) auto auto;align-items:center;gap:16px;padding:14px 16px;border-radius:14px;background:var(--soft);transition:background .15s,transform .15s,box-shadow .15s}
.cp-item:hover{background:#fff;box-shadow:0 10px 26px -14px rgba(18,140,126,.45);transform:translateY(-1px)}
.cp-av{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;font-weight:800;font-size:19px;display:grid;place-items:center}
.cp-info{min-width:0}
.cp-name{display:block;font-size:16px;font-weight:800;color:var(--ink);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-name:hover{color:var(--g)}
.cp-sub{display:flex;flex-wrap:wrap;gap:4px 16px;margin-top:5px;font-size:13px;color:var(--tx)}
.cp-sub span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.cp-sub svg{color:var(--g)}
.cp-prog__t{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:6px}
.cp-prog__t b{font-size:15px;font-weight:800;color:var(--ink)}
.cp-prog__t small{font-size:12.5px;color:var(--mu)}
.cp-item .db-bar{height:8px;background:#fff}
.cp-acts{display:flex;gap:6px}
.cp-acts form{margin:0}
.cp-ib{width:38px;height:38px;border:0;border-radius:11px;background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}
.cp-ib:hover{background:#E6FCF2;color:var(--gd)}
.cp-ib.is-warn:hover{background:#FEF3DC;color:#92580a}
.cp-ib.is-bad:hover{background:#FDE4E1;color:#c0372b}
.cp-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.cp-rate{padding:0 20px 22px;display:flex;flex-direction:column;align-items:center;gap:16px}
.cp-donut{position:relative;width:180px;height:180px}
.cp-donut__c{position:absolute;inset:0;display:grid;place-content:center;text-align:center}
.cp-donut__c b{font-size:36px;font-weight:800;letter-spacing:-1px;color:var(--ink);line-height:1}
.cp-donut__c span{margin-top:4px;font-size:13px;color:var(--mu)}
.cp-dleg{display:flex;flex-wrap:wrap;justify-content:center;gap:8px 22px;font-size:14px;color:var(--tx)}
.cp-dleg span{display:inline-flex;align-items:center;gap:7px;font-weight:600}
.cp-dleg i{width:10px;height:10px;border-radius:3px}
.cp-dleg b{color:var(--ink);font-weight:800}
.cp-mix{display:flex;flex-direction:column;gap:12px;padding:2px 20px 22px}
.cp-mix__r{display:grid;grid-template-columns:110px minmax(0,1fr) 30px;align-items:center;gap:12px;font-size:14px;color:var(--tx)}
.cp-mix__r span{display:inline-flex;align-items:center;gap:8px;font-weight:600}
.cp-mix__r i{width:10px;height:10px;border-radius:3px;display:inline-block}
.cp-mix__r b{text-align:right;color:var(--ink);font-weight:800}
.cp-mix__bar{height:8px;border-radius:99px;background:var(--soft);overflow:hidden}
.cp-mix__bar u{display:block;height:100%;border-radius:99px;text-decoration:none}
@media (max-width:1200px){.cp-item{grid-template-columns:48px minmax(0,1fr) auto}.cp-prog{grid-column:2 / -1;order:5}.cp-item .db-pill{justify-self:start}}
@media (max-width:1000px){.cp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.db-row--bottom{grid-template-columns:1fr}}
@media (max-width:560px){.cp-item{grid-template-columns:44px minmax(0,1fr)}.cp-acts,.cp-item .db-pill{grid-column:2}.cp-av{width:44px;height:44px}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var s = document.getElementById('cpSearch');
  if (s) s.addEventListener('input', function () {
    var q = s.value.toLowerCase().trim(), n = 0, items = document.querySelectorAll('#cpList .cp-item');
    items.forEach(function (el) {
      var ok = !q || (el.dataset.name || '').indexOf(q) !== -1;
      el.style.display = ok ? '' : 'none';
      if (ok) n++;
    });
    var sh = document.getElementById('cpShown'); if (sh) sh.textContent = n;
    var em = document.getElementById('cpEmpty');
    if (em && items.length) { em.style.display = n ? 'none' : 'block'; }
  });
  document.addEventListener('submit', function (e) {
    var form = e.target && e.target.closest && e.target.closest('.js-confirm-form[data-confirm]');
    if (form && !confirm(form.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
