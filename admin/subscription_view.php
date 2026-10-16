<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$pageTitle = 'Subscription Details';
$currentPage = 'subscriptions';

if (!isset($_GET['id'])) {
    header("Location: subscriptions.php");
    exit();
}
$id = intval($_GET['id']);

// Handle cancel (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    csrf_verify();
    $stmt = $conn->prepare("UPDATE subscriptions SET status='cancelled', cancelled_at=CURDATE() WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    header("Location: subscription_view.php?id=$id&msg=cancelled");
    exit();
}

// Handle renew
if (isset($_POST['renew'])) {
    csrf_verify();
    $newExpiry = (string)($_POST['new_expiry'] ?? '');
    $amount = floatval($_POST['renew_amount']);
    $method = sanitize($_POST['renew_method'] ?? 'manual');
    $ref = sanitize($_POST['renew_ref'] ?? '');

    $upd = $conn->prepare("UPDATE subscriptions SET status='active', expires_at=?, amount=? WHERE id=?");
    if ($upd) {
        $upd->bind_param("sdi", $newExpiry, $amount, $id);
        $upd->execute();
    }

    if ($amount > 0) {
        $pstmt = $conn->prepare("INSERT INTO subscription_payments (subscription_id, amount, payment_method, payment_ref, status) VALUES (?,?,?,?,'success')");
        $pstmt->bind_param("idss", $id, $amount, $method, $ref);
        $pstmt->execute();
    }
    header("Location: subscription_view.php?id=$id&msg=renewed");
    exit();
}

$sub = null;
$stmt_s = $conn->prepare("
    SELECT s.*, p.name as plan_name, p.monthly_price, p.yearly_price, c.name as company_name
    FROM subscriptions s
    JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN companies c ON s.company_id = c.id
    WHERE s.id=?
");
if ($stmt_s) {
    $stmt_s->bind_param("i", $id);
    $stmt_s->execute();
    $sub = $stmt_s->get_result()->fetch_assoc();
}

if (!$sub) {
    header("Location: subscriptions.php");
    exit();
}

$payments = null;
$stmt_p = $conn->prepare("SELECT * FROM subscription_payments WHERE subscription_id=? ORDER BY paid_at DESC");
if ($stmt_p) {
    $stmt_p->bind_param("i", $id);
    $stmt_p->execute();
    $payments = $stmt_p->get_result();
}

$daysLeft = $sub['expires_at'] ? round((strtotime($sub['expires_at']) - time()) / 86400) : null;

// Fetch reminder history
$reminders = null;
$stmt_r = $conn->prepare("SELECT * FROM subscription_reminders WHERE subscription_id=? ORDER BY sent_at DESC LIMIT 10");
if ($stmt_r) {
    $stmt_r->bind_param("i", $id);
    $stmt_r->execute();
    $reminders = $stmt_r->get_result();
}

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'x'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'coin'   => '<circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'refresh'=> '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
    'receipt'=> '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M14 8H8"/><path d="M16 12H8"/><path d="M13 16H8"/>',
    'bell'   => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
];
$statusCls = ['active' => 'is-ok', 'trial' => 'is-info', 'expired' => 'is-bad', 'cancelled' => 'is-off', 'pending' => 'is-warn'];
$st = (string)$sub['status'];
$cn = (string)($sub['company_name'] ?? 'N/A');
$startTs = !empty($sub['starts_at']) ? strtotime($sub['starts_at']) : null;
$endTs = !empty($sub['expires_at']) ? strtotime($sub['expires_at']) : null;
$pct = 0;
if ($startTs && $endTs && $endTs > $startTs) { $pct = (int)max(0, min(100, round((time() - $startTs) / ($endTs - $startTs) * 100))); }
$left = $daysLeft === null ? 'No expiry set' : ($daysLeft > 0 ? $daysLeft . ' days remaining' : ($daysLeft == 0 ? 'Expires today' : 'Expired ' . abs($daysLeft) . ' days ago'));
$urgent = ($daysLeft !== null && $daysLeft <= 7);
$payRows = [];
if ($payments) { while ($r = $payments->fetch_assoc()) { $payRows[] = $r; } }
$remRows = [];
if ($reminders) { while ($r = $reminders->fetch_assoc()) { $remRows[] = $r; } }
$paidTotal = 0.0; foreach ($payRows as $r) { if (($r['status'] ?? '') === 'success') $paidTotal += (float)$r['amount']; }
$nextRem = 'N/A'; $nextRemCls = '';
if (!empty($sub['whatsapp_number']) && !empty($sub['reminder_enabled']) && $sub['expires_at']) {
    $rd = round((strtotime($sub['expires_at'] . ' -2 days') - time()) / 86400);
    if ($rd > 0) { $nextRem = date('d M Y', strtotime($sub['expires_at'] . ' -2 days')) . ' (' . $rd . 'd)'; }
    elseif ($rd == 0) { $nextRem = 'Today'; $nextRemCls = 'is-warn'; }
    else { $nextRem = 'Passed'; }
}
$flashMsg = isset($_GET['msg']) ? ucfirst((string)$_GET['msg']) : '';
?>

<div class="db sv">

  <a class="sv-back" href="<?php echo $sub['type'] === 'manual' ? 'subscriptions_manual.php' : 'subscriptions.php'; ?>"><?php echo $__ic($P['back'], 14); ?> Back to subscriptions</a>

  <?php if ($flashMsg !== ''): ?>
    <div class="sv-toast" role="status"><?php echo $__ic($P['check'], 18); ?> Subscription <?php echo $esc(strtolower($flashMsg)); ?> successfully.</div>
  <?php endif; ?>

  <div class="db-head">
    <div class="sv-title">
      <span class="db-av sv-av"><?php echo $esc(strtoupper(mb_substr($cn, 0, 1))); ?></span>
      <div>
        <h1>Subscription #<?php echo (int)$id; ?></h1>
        <p><?php echo $esc($cn); ?> · <?php echo $esc($sub['plan_name']); ?> <span class="db-pill <?php echo $statusCls[$st] ?? 'is-off'; ?>" style="margin-left:6px"><?php echo $esc(ucfirst($st)); ?></span></p>
      </div>
    </div>
    <div class="db-head__actions">
      <?php if ($sub['status'] === 'active'): ?>
        <form method="POST" action="subscription_view.php?id=<?php echo (int)$id; ?>" style="margin:0">
          <?php echo csrf_field(); ?>
          <button type="submit" name="cancel" value="1" class="db-btn sv-cancel js-confirm" data-confirm="Cancel this subscription?"><?php echo $__ic($P['x'], 16); ?> Cancel subscription</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== row 1: overview + timeline ===== -->
  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-kpis" style="grid-column:1/-1">
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($P['building'], 19); ?></div><div class="db-kpi__l">Company</div><div class="db-kpi__v" style="font-size:22px"><?php echo $esc($cn); ?></div><div class="db-kpi__d">Account owner</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['layers'], 19); ?></div><div class="db-kpi__l">Plan</div><div class="db-kpi__v" style="font-size:22px"><?php echo $esc($sub['plan_name']); ?></div><div class="db-kpi__d"><?php echo $esc(ucfirst((string)$sub['billing_cycle'])); ?> billing</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($P['coin'], 19); ?></div><div class="db-kpi__l">Amount</div><div class="db-kpi__v">₹<?php echo number_format((float)$sub['amount']); ?></div><div class="db-kpi__d">Paid so far <span class="db-chip is-up">₹<?php echo number_format($paidTotal); ?></span></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($P['clock'], 19); ?></div><div class="db-kpi__l">Status</div><div class="db-kpi__v" style="font-size:22px"><?php echo $esc(ucfirst($st)); ?></div><div class="db-kpi__d <?php echo $urgent ? 'sv-urgent' : ''; ?>"><?php echo $esc($left); ?></div></div>
      </div>
      <div class="sv-details">
        <div><span>Subscription ID</span><b>#<?php echo (int)$id; ?></b></div>
        <div><span>Created</span><b><?php echo !empty($sub['created_at']) ? date('d M Y', strtotime($sub['created_at'])) : '—'; ?></b></div>
        <div><span>Payment reference</span><b><?php echo !empty($sub['payment_ref']) ? $esc($sub['payment_ref']) : '—'; ?></b></div>
        <div><span>Billing cycle</span><b><?php echo $esc(ucfirst((string)$sub['billing_cycle'])); ?></b></div>
        <?php if (!empty($sub['notes'])): ?><div class="sv-details__wide"><span>Notes</span><b><?php echo $esc($sub['notes']); ?></b></div><?php endif; ?>
      </div>
    </section>

    <section class="db-card">
      <div class="db-card__head"><div><h2>Timeline</h2><p>Billing period progress</p></div></div>
      <div class="sv-tl">
        <div class="sv-tl__row"><span>Type</span><b><?php echo $esc(ucfirst((string)$sub['type'])); ?></b></div>
        <div class="sv-tl__row"><span>Started</span><b><?php echo $startTs ? date('d M Y', $startTs) : '—'; ?></b></div>
        <div class="sv-tl__row"><span>Expires</span><b class="<?php echo $urgent ? 'sv-urgent' : ''; ?>"><?php echo $endTs ? date('d M Y', $endTs) : '—'; ?></b></div>
        <div class="sv-tl__row"><span>Payment method</span><b><?php echo $esc(ucfirst((string)$sub['payment_method'])); ?></b></div>
        <div class="sv-prog">
          <div class="sv-prog__top"><span>Period used</span><b><?php echo $pct; ?>%</b></div>
          <div class="db-bar"><i style="width:<?php echo $pct; ?>%;<?php echo $urgent ? 'background:#e5484d' : ''; ?>"></i></div>
        </div>
      </div>
    </section>
  </div>

  <!-- ===== row 2: payments | whatsapp reminder ===== -->
  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head"><div><h2>Payment history</h2><p><?php echo count($payRows); ?> payment<?php echo count($payRows) === 1 ? '' : 's'; ?> recorded</p></div></div>
      <div class="db-tablewrap">
        <table class="db-table" style="min-width:520px">
          <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr></thead>
          <tbody>
          <?php if ($payRows): foreach ($payRows as $pay):
              $pc = $pay['status'] === 'success' ? 'is-ok' : ($pay['status'] === 'failed' ? 'is-bad' : 'is-warn'); ?>
            <tr>
              <td class="db-mut" style="white-space:nowrap"><?php echo date('d M Y, h:i A', strtotime($pay['paid_at'])); ?></td>
              <td><b>₹<?php echo number_format((float)$pay['amount']); ?></b></td>
              <td><?php echo $esc(ucfirst((string)$pay['payment_method'])); ?></td>
              <td class="db-mut"><?php echo $esc($pay['payment_ref'] ?? '—'); ?></td>
              <td><span class="db-pill <?php echo $pc; ?>"><?php echo $esc(ucfirst((string)$pay['status'])); ?></span></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="db-empty">No payments yet.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="db-card">
      <div class="db-card__head"><div><h2>WhatsApp reminder</h2><p>Automatic expiry alerts</p></div>
        <span class="db-pill <?php echo !empty($sub['reminder_enabled']) ? 'is-ok' : 'is-bad'; ?>"><?php echo !empty($sub['reminder_enabled']) ? 'Enabled' : 'Disabled'; ?></span></div>
      <div class="db-meter">
        <div class="db-meter__row"><span>WhatsApp number</span><b><?php echo !empty($sub['whatsapp_number']) ? $esc($sub['whatsapp_number']) : '<span class="db-mut">Not set</span>'; ?></b></div>
        <div class="db-meter__row"><span>Last reminder</span><b><?php echo !empty($sub['reminder_sent_at']) ? date('d M Y, h:i A', strtotime($sub['reminder_sent_at'])) : 'Never'; ?></b></div>
        <div class="db-meter__row" style="border-bottom:0"><span>Next reminder</span><b><?php echo $esc($nextRem); ?></b></div>
      </div>
      <?php if ($remRows): ?>
        <div class="sv-hist">
          <h4>Reminder history</h4>
          <?php foreach ($remRows as $rem): ?>
            <div class="db-li">
              <span class="db-li__ic <?php echo $rem['status'] === 'sent' ? '' : 'is-amber'; ?>"><?php echo $__ic($P['bell'], 17); ?></span>
              <div class="db-li__t"><b><?php echo $esc($rem['phone']); ?></b><small><?php echo $esc($rem['message']); ?></small></div>
              <div class="db-li__r"><span class="db-pill <?php echo $rem['status'] === 'sent' ? 'is-ok' : 'is-bad'; ?>"><?php echo $esc(ucfirst((string)$rem['status'])); ?></span><div style="margin-top:4px;font-weight:600;color:var(--mu)"><?php echo date('d M', strtotime($rem['sent_at'])); ?></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ===== renew ===== -->
  <?php if ($sub['status'] !== 'cancelled'): ?>
  <section class="db-card sv-renew">
    <div class="db-card__head"><div><h2>Renew subscription</h2><p>Extend the expiry date and record the payment</p></div><span class="sv-renew__ic"><?php echo $__ic($P['refresh'], 20); ?></span></div>
    <form method="POST" class="sv-form">
      <?php echo csrf_field(); ?>
      <div class="sv-f"><label for="newExp">New expiry *</label><input type="date" name="new_expiry" id="newExp" class="sv-in" value="<?php echo date('Y-m-d', strtotime($sub['expires_at'] ?? 'now') + 30 * 86400); ?>" required></div>
      <div class="sv-f"><label for="rnAmt">Amount (₹)</label><input type="number" name="renew_amount" id="rnAmt" class="sv-in" value="<?php echo $esc($sub['amount']); ?>" step="0.01"></div>
      <div class="sv-f"><label for="rnMeth">Method</label>
        <select name="renew_method" id="rnMeth" class="sv-in"><option value="manual">Manual</option><option value="upi">UPI</option><option value="bank_transfer">Bank transfer</option><option value="stripe">Stripe</option></select></div>
      <div class="sv-f"><label for="rnRef">Reference</label><input type="text" name="renew_ref" id="rnRef" class="sv-in" placeholder="Transaction ID"></div>
      <button type="submit" name="renew" value="1" class="db-btn db-btn--primary sv-go"><?php echo $__ic($P['refresh'], 16); ?> Renew</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<style>
.sv{max-width:none;margin:0}
.sv .db-row{align-items:start}
.sv-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:14px;color:var(--tx);text-decoration:none}
.sv-back:hover{color:var(--g)}
.sv-toast{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E;font-size:14.5px;font-weight:600}
.sv-title{display:flex;align-items:center;gap:16px}
.sv-av{width:56px;height:56px;border-radius:16px;font-size:22px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff}
.sv-title h1{margin:0 0 4px!important}
.sv-title p{display:flex;align-items:center;flex-wrap:wrap;gap:4px}
.sv-cancel{color:#c0372b;border-color:#F8C9C2}
.sv-cancel:hover{background:#FEF0EE;border-color:#e5484d;color:#c0372b}
.sv-urgent{color:#c0372b!important;font-weight:700}

.sv .db-report{grid-template-columns:1fr}
.sv .db-kpis{grid-template-columns:repeat(4,minmax(0,1fr));grid-auto-rows:auto}
.sv .db-kpi{border-bottom:0!important;border-right:1px solid var(--line)!important}
.sv .db-kpi:last-child{border-right:0!important}
.sv .db-kpi{padding:20px 22px 18px}
.sv .db-kpi__ic{margin-bottom:14px}
.sv .db-kpi__v{white-space:normal;line-height:1.2;overflow:visible;word-break:break-word;margin:5px 0 8px}
.sv-details{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;border-top:1px solid var(--line);background:var(--soft)}
.sv-details>div{padding:15px 22px;border-right:1px solid var(--line);min-width:0}
.sv-details>div:last-child{border-right:0}
.sv-details span{display:block;font-size:12.5px;font-weight:600;color:var(--mu);margin-bottom:4px}
.sv-details b{display:block;font-size:14.5px;font-weight:700;color:var(--ink);word-break:break-word}
.sv-details .sv-details__wide{grid-column:1/-1;border-right:0;border-top:1px solid var(--line)}

.sv-tl{padding:6px 20px 20px}
.sv-tl__row{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #EEF3F1;font-size:14.5px}
.sv-tl__row span{color:var(--tx)}.sv-tl__row b{color:var(--ink)}
.sv-prog{margin-top:14px}
.sv-prog__top{display:flex;justify-content:space-between;font-size:13.5px;color:var(--tx)}
.sv-prog__top b{color:var(--ink)}
.sv-hist{padding:4px 20px 14px}
.sv-hist h4{margin:6px 0 4px;font-size:13px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--mu)}
.sv-hist .db-li__t small{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.sv-renew{margin-top:2px;margin-bottom:6px}
.sv-renew__ic{width:40px;height:40px;border-radius:11px;background:#E3F7EE;color:var(--g);display:grid;place-items:center}
.sv-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr)) auto;gap:14px;align-items:end;padding:18px 20px 22px}
.sv-f label{display:block;margin-bottom:7px;font-size:13.5px;font-weight:700;color:var(--ink)}
.sv-in{width:100%;height:44px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font:500 14.5px Inter,system-ui,sans-serif}
.sv-in:focus{outline:0;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
select.sv-in{appearance:none;-webkit-appearance:none;padding-right:36px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 12px center}
.sv-go{height:44px;padding:0 24px}

@media (max-width:980px){.sv-details{grid-template-columns:1fr 1fr}.sv-details>div{border-bottom:1px solid var(--line)}.sv .db-kpis{grid-template-columns:1fr 1fr}.sv .db-kpi{border-bottom:1px solid var(--line)!important}.sv-form{grid-template-columns:1fr 1fr}.sv-go{grid-column:1/-1;justify-content:center}}
@media (max-width:520px){.sv-details{grid-template-columns:1fr}.sv .db-kpis{grid-template-columns:1fr}.sv .db-kpi{border-right:0!important}.sv-form{grid-template-columns:1fr}.sv-title{align-items:flex-start}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('click', function (e) {
    var el = e.target.closest('.js-confirm[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
});
</script>

<?php include '../includes/admin_footer.php'; ?>
