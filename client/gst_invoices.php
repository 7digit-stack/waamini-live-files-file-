<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_gst_invoice')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$success = '';
$error = '';

// Messages coming back from the WhatsApp send endpoint
if (($_GET['error'] ?? '') !== '') {
    $map = ['invalid' => 'That invoice could not be found.', 'notfound' => 'That invoice could not be found.', 'nowaconfig' => 'Connect WhatsApp in WhatsApp Setup before sending invoices.'];
    $error = $map[(string)$_GET['error']] ?? 'Something went wrong.';
}

// Handle Create Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invoice'])) {
    csrf_verify();
    $invNum = sanitize($_POST['invoice_number']);
    $custName = sanitize($_POST['customer_name']);
    $custGstin = sanitize($_POST['customer_gstin']);
    $subject = sanitize($_POST['subject'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $baseAmount = floatval($_POST['base_amount']);
    $gstRate = floatval($_POST['gst_rate']);

    // Quick-create makes a single line item
    $items = [
        ['name' => $subject ?: 'Services', 'qty' => 1, 'rate' => $baseAmount, 'amount' => $baseAmount, 'gst_rate' => $gstRate]
    ];
    $itemsJson = json_encode($items);

    $gstAmount = ($baseAmount * $gstRate) / 100;
    $totalAmount = $baseAmount + $gstAmount;

    // Find the customer by name or create a minimal contact
    $contactId = 0;
    $cCheck = $conn->prepare("SELECT id FROM contacts WHERE name=? AND company_id=? LIMIT 1");
    $cCheck->bind_param("si", $custName, $companyId);
    $cCheck->execute();
    $cRes = $cCheck->get_result()->fetch_assoc();
    if ($cRes) {
        $contactId = $cRes['id'];
    } else {
        $cIns = $conn->prepare("INSERT INTO contacts (company_id, name) VALUES (?, ?)");
        $cIns->bind_param("is", $companyId, $custName);
        if ($cIns->execute()) $contactId = $conn->insert_id;
    }

    $stmt = $conn->prepare("INSERT INTO gst_invoices (company_id, contact_id, invoice_number, subject, notes, items_json, subtotal, total_amount, cgst_amount, sgst_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
    $cgst = $gstAmount / 2;
    $sgst = $gstAmount / 2;
    $stmt->bind_param("iissssdddd", $companyId, $contactId, $invNum, $subject, $notes, $itemsJson, $baseAmount, $totalAmount, $cgst, $sgst);

    if ($stmt->execute()) {
        $success = "Invoice #$invNum created successfully.";
    } else {
        $error = "Failed to create invoice: " . $conn->error;
    }
}

// Stats (all scoped to this company)
$one = static function (mysqli $db, string $sql, int $cid): array {
    $st = $db->prepare($sql);
    if (!$st) { return []; }
    $st->bind_param('i', $cid);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: [];
};
$tot = $one($conn, "SELECT COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS n, COALESCE(SUM(cgst_amount + sgst_amount),0) AS gst,
    COALESCE(SUM(CASE WHEN status <> 'paid' THEN total_amount ELSE 0 END),0) AS due,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END),0) AS paid,
    COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')),0) AS this_month FROM gst_invoices WHERE company_id = ?", $companyId);
$revTotal = (float)($tot['rev'] ?? 0); $invCount = (int)($tot['n'] ?? 0); $gstTotal = (float)($tot['gst'] ?? 0);
$dueTotal = (float)($tot['due'] ?? 0); $paidTotal = (float)($tot['paid'] ?? 0); $thisMonthN = (int)($tot['this_month'] ?? 0);

$statusCounts = ['draft' => 0, 'sent' => 0, 'paid' => 0];
$sq = $conn->prepare("SELECT status, COUNT(*) AS c FROM gst_invoices WHERE company_id = ? GROUP BY status");
if ($sq) { $sq->bind_param('i', $companyId); $sq->execute(); $sr = $sq->get_result(); while ($sr && ($x = $sr->fetch_assoc())) { if (isset($statusCounts[$x['status']])) { $statusCounts[$x['status']] = (int)$x['c']; } } }

// Revenue by month, last 6 months
$mLabels = []; $mData = []; $mMap = [];
$mq = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COALESCE(SUM(total_amount),0) AS t FROM gst_invoices WHERE company_id = ? AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY m");
if ($mq) { $mq->bind_param('i', $companyId); $mq->execute(); $mr = $mq->get_result(); while ($mr && ($x = $mr->fetch_assoc())) { $mMap[$x['m']] = (float)$x['t']; } }
for ($i = 5; $i >= 0; $i--) { $t = strtotime("first day of -$i months"); $mLabels[] = date('M y', $t); $mData[] = round($mMap[date('Y-m', $t)] ?? 0, 2); }

// Invoice list with the customer name (stored on the contact)
$invoices = [];
$lq = $conn->prepare("SELECT gi.id, gi.invoice_number, gi.subject, gi.subtotal, gi.total_amount, gi.cgst_amount, gi.sgst_amount, gi.status, gi.created_at, c.name AS customer
    FROM gst_invoices gi LEFT JOIN contacts c ON c.id = gi.contact_id AND c.company_id = gi.company_id WHERE gi.company_id = ? ORDER BY gi.id DESC LIMIT 100");
if ($lq) { $lq->bind_param('i', $companyId); $lq->execute(); $lr = $lq->get_result(); while ($lr && ($x = $lr->fetch_assoc())) { $invoices[] = $x; } }

$pageTitle = 'GST Invoices';
$currentPage = 'gst_invoices';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'invoice' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'rupee'   => '<path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/>',
    'percent' => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'clock'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'gear'    => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'plus'    => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'eye'     => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'send'    => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'search'  => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'check'   => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'   => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
];
$money = static fn($n) => '₹' . number_format((float)$n, 2);
$moneyShort = static function ($n): string {
    $n = (float)$n;
    if ($n >= 10000000) { return '₹' . round($n / 10000000, 2) . ' Cr'; }
    if ($n >= 100000) { return '₹' . round($n / 100000, 2) . ' L'; }
    return '₹' . number_format($n, 0);
};
$pill = ['draft' => 'is-off', 'sent' => 'is-info', 'paid' => 'is-ok'];
$csrf = csrf_token();
?>

<div class="db gi">

  <div class="db-head">
    <div>
      <h1>GST Invoices</h1>
      <p>Create GST invoices, send them on WhatsApp and track what is paid and what is due.</p>
    </div>
    <div class="db-head__actions">
      <a href="settings_gst.php" class="db-btn"><?php echo $__ic($I['gear'], 16); ?> GST settings</a>
      <a href="gst_invoice_create.php" class="db-btn db-btn--primary"><?php echo $__ic($I['plus'], 16); ?> Full invoice</a>
    </div>
  </div>

  <?php if ($success): ?><div class="gi-toast is-ok" role="status"><?php echo $__ic($I['check'], 18); ?> <span><?php echo $esc($success); ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="gi-toast is-err" role="alert"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($error); ?></span></div><?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Revenue</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Invoiced: <?php echo $esc($moneyShort(array_sum($mData))); ?></span><span class="db-mut">Last 6 months</span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="giChart" aria-label="Invoiced revenue by month" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['rupee'], 19); ?></div><div class="db-kpi__l">Total invoiced</div><div class="db-kpi__v"><?php echo $esc($moneyShort($revTotal)); ?></div><div class="db-kpi__d"><?php echo $esc($money($paidTotal)); ?> paid</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['invoice'], 19); ?></div><div class="db-kpi__l">Invoices</div><div class="db-kpi__v"><?php echo number_format($invCount); ?></div><div class="db-kpi__d"><?php echo $thisMonthN; ?> this month</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['clock'], 19); ?></div><div class="db-kpi__l">Amount due</div><div class="db-kpi__v"><?php echo $esc($moneyShort($dueTotal)); ?></div><div class="db-kpi__d"><?php echo $dueTotal > 0 ? '<span class="db-chip is-down">Not yet paid</span>' : '<span class="db-chip is-up">All settled</span>'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($I['percent'], 19); ?></div><div class="db-kpi__l">GST collected</div><div class="db-kpi__v"><?php echo $esc($moneyShort($gstTotal)); ?></div><div class="db-kpi__d">CGST + SGST</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Invoice status</h2><a class="db-link" href="gst_invoice_create.php">New</a></div>
      <div class="db-donut__chart">
        <canvas id="giDonut" width="200" height="200" aria-label="Invoices by status" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($invCount); ?></b><span>invoices</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#25D366"></i>Paid: <?php echo $statusCounts['paid']; ?></span>
        <span><i style="background:#3B82F6"></i>Sent: <?php echo $statusCounts['sent']; ?></span>
        <span><i style="background:#9AA9A4"></i>Draft: <?php echo $statusCounts['draft']; ?></span>
      </div>
    </section>
  </div>

  <div class="db-row db-row--bottom">
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Recent invoices</h2><p><span id="giShown"><?php echo count($invoices); ?></span> shown &middot; latest 100</p></div>
        <label class="gi-search"><?php echo $__ic($I['search'], 16); ?><input type="search" id="giSearch" placeholder="Search invoice or customer" aria-label="Search invoices"></label>
      </div>
      <div class="gi-chips" id="giChips">
        <button type="button" class="is-active" data-s="all">All <em><?php echo $invCount; ?></em></button>
        <button type="button" data-s="paid">Paid <em><?php echo $statusCounts['paid']; ?></em></button>
        <button type="button" data-s="sent">Sent <em><?php echo $statusCounts['sent']; ?></em></button>
        <button type="button" data-s="draft">Draft <em><?php echo $statusCounts['draft']; ?></em></button>
      </div>
      <div class="db-tablewrap">
        <table class="db-table gi-table" id="giTable">
          <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Amount</th><th>GST</th><th>Total</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invoices as $r):
              $name = (string)($r['customer'] ?? ''); $gstAmt = (float)$r['cgst_amount'] + (float)$r['sgst_amount']; ?>
            <tr data-s="<?php echo $esc($r['status']); ?>" data-q="<?php echo $esc(strtolower($r['invoice_number'] . ' ' . $name . ' ' . $r['subject'])); ?>">
              <td><span class="gi-no"><?php echo $esc($r['invoice_number']); ?></span></td>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1))); ?></span><div><b><?php echo $esc($name !== '' ? $name : 'Unknown customer'); ?></b><small><?php echo $esc($r['subject'] ?: 'Services'); ?></small></div></div></td>
              <td class="db-mut" style="white-space:nowrap"><?php echo $esc(date('d M Y', strtotime((string)$r['created_at']))); ?></td>
              <td><?php echo $esc($money($r['subtotal'])); ?></td>
              <td class="db-mut"><?php echo $esc($money($gstAmt)); ?></td>
              <td><b><?php echo $esc($money($r['total_amount'])); ?></b></td>
              <td><span class="db-pill <?php echo $pill[$r['status']] ?? 'is-off'; ?>"><?php echo $esc(ucfirst((string)$r['status'])); ?></span></td>
              <td style="white-space:nowrap">
                <a class="gi-ib" href="gst_invoice_view.php?id=<?php echo (int)$r['id']; ?>" title="View invoice"><?php echo $__ic($I['eye'], 16); ?></a>
                <a class="gi-ib is-wa" href="../api/send_gst_invoice_wa.php?invoice_id=<?php echo (int)$r['id']; ?>&amp;csrf=<?php echo $esc($csrf); ?>" title="Send on WhatsApp" onclick="return confirm('Send this invoice to the customer on WhatsApp?')"><?php echo $__ic($I['send'], 16); ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="db-empty" id="giEmpty" style="<?php echo $invoices ? 'display:none' : ''; ?>"><?php echo $invoices ? 'No invoices match your filters.' : 'No invoices yet. Create your first one with the form on the right.'; ?></div>
      </div>
    </section>

    <aside class="db-card gi-form">
      <div class="gi-ch"><h2>Quick invoice</h2><p>One line item. Use Full invoice for several.</p></div>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="create_invoice" value="1">
        <div class="gi-f"><label for="g_no">Invoice number</label><input id="g_no" type="text" name="invoice_number" placeholder="INV-<?php echo date('Y'); ?>-001" required></div>
        <div class="gi-f"><label for="g_cust">Customer name</label><input id="g_cust" type="text" name="customer_name" placeholder="e.g. Amazon India" required></div>
        <div class="gi-f"><label for="g_gstin">Customer GSTIN <span>optional</span></label><input id="g_gstin" type="text" name="customer_gstin" placeholder="22AAAAA0000A1Z5" maxlength="15"></div>
        <div class="gi-f"><label for="g_sub">Service or product</label><input id="g_sub" type="text" name="subject" placeholder="e.g. WhatsApp API integration" required></div>
        <div class="gi-two">
          <div class="gi-f"><label for="g_amt">Amount (₹)</label><input id="g_amt" type="number" name="base_amount" placeholder="0.00" step="0.01" min="0" required></div>
          <div class="gi-f"><label for="g_rate">GST rate</label>
            <select id="g_rate" name="gst_rate"><option value="0">0% (none)</option><option value="5">5%</option><option value="12">12%</option><option value="18" selected>18%</option><option value="28">28%</option></select></div>
        </div>
        <div class="gi-sum" id="giSum"><span>GST <b id="giGst">₹0.00</b></span><span>Total <b id="giTot">₹0.00</b></span></div>
        <div class="gi-f"><label for="g_notes">Notes <span>optional</span></label><textarea id="g_notes" name="notes" rows="2" placeholder="Thank you for your business!"></textarea></div>
        <button type="submit" class="db-btn db-btn--primary gi-wide"><?php echo $__ic($I['invoice'], 16); ?> Create invoice</button>
      </form>
    </aside>
  </div>
</div>

<style>
.gi{max-width:none}
.gi-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.gi-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.gi-toast.is-err{background:#FDE4E1;color:#c0372b}.gi-toast svg{margin-top:2px;flex:none}
.gi .db-canvas{height:270px}
.gi .db-donut__chart canvas{max-width:200px;max-height:200px}
.gi-search{display:flex;align-items:center;gap:9px;width:260px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.gi-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.gi-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.gi-chips{display:flex;gap:8px;flex-wrap:wrap;padding:4px 20px 8px}
.gi-chips button{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 14px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.gi-chips button em{font-style:normal;font-size:12.5px;padding:1px 8px;border-radius:999px;background:#fff;color:var(--mu)}
.gi-chips button:hover{background:#E6FCF2;color:var(--gd)}
.gi-chips button.is-active{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff}
.gi-chips button.is-active em{background:rgba(255,255,255,.22);color:#fff}
.gi-table td{border-bottom:0}
.gi-table tbody tr:nth-child(even) td{background:#F8FCFA}
.gi-table tbody tr:nth-child(even) td:first-child{border-radius:10px 0 0 10px}.gi-table tbody tr:nth-child(even) td:last-child{border-radius:0 10px 10px 0}
.gi-no{display:inline-block;padding:4px 10px;border-radius:8px;background:var(--soft);color:var(--gd);font:700 13px ui-monospace,Consolas,monospace}
.gi-ib{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;background:var(--soft);color:var(--tx);text-decoration:none;margin-left:4px;transition:background .15s,color .15s}
.gi-ib:hover{background:#E6FCF2;color:var(--gd)}
.gi-ib.is-wa{color:#128C7E}
.gi-form{padding:22px}
.gi-ch{margin-bottom:16px}
.gi-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.gi-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.gi-f{margin-bottom:14px}
.gi-f label{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.gi-f label span{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:4px}
.gi-f input,.gi-f select,.gi-f textarea{width:100%;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.gi-f input,.gi-f select{height:44px}
.gi-f textarea{padding:11px 14px;line-height:1.55;resize:vertical}
.gi-f input:focus,.gi-f select:focus,.gi-f textarea:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.gi-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.gi-sum{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:12px;background:var(--soft);margin-bottom:14px;font-size:13.5px;color:var(--tx)}
.gi-sum b{color:var(--ink);font-weight:800;margin-left:4px}
.gi-wide{width:100%;justify-content:center}
@media (max-width:1100px){.gi .db-row{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var fmt = function (n) { return '₹' + n.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };

  // live GST preview in the quick form
  function calc() { var a = parseFloat($('g_amt').value) || 0, r = parseFloat($('g_rate').value) || 0, g = a * r / 100; $('giGst').textContent = fmt(g); $('giTot').textContent = fmt(a + g); }
  $('g_amt').addEventListener('input', calc); $('g_rate').addEventListener('change', calc); calc();

  // search + status filter
  var status = 'all';
  function apply() {
    var q = $('giSearch').value.toLowerCase().trim(), n = 0, rows = document.querySelectorAll('#giTable tbody tr');
    rows.forEach(function (r) {
      var ok = (status === 'all' || r.getAttribute('data-s') === status) && (!q || (r.getAttribute('data-q') || '').indexOf(q) !== -1);
      r.style.display = ok ? '' : 'none'; if (ok) { n++; }
    });
    $('giShown').textContent = n;
    var e = $('giEmpty'); if (rows.length) { e.style.display = n ? 'none' : 'block'; }
  }
  $('giSearch').addEventListener('input', apply);
  $('giChips').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-s]'); if (!b) { return; }
    status = b.getAttribute('data-s'); this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); apply();
  });

  if (typeof Chart === 'undefined') { return; }
  var cv = $('giChart');
  if (cv) {
    var ctx = cv.getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270); g.addColorStop(0, '#25D366'); g.addColorStop(1, '#128C7E');
    new Chart(ctx, {type: 'bar',
      data: {labels: <?php echo json_encode($mLabels); ?>, datasets: [{label: 'Invoiced', data: <?php echo json_encode($mData); ?>, backgroundColor: g, borderRadius: 8, maxBarThickness: 46}]},
      options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false, callbacks: {label: function (c) { return fmt(c.parsed.y); }}}},
        scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 12}}},
                 y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, maxTicksLimit: 5, callback: function (v) { return v >= 100000 ? (v / 100000) + 'L' : v; }}}}}});
  }
  var vals = [<?php echo (int)$statusCounts['paid']; ?>, <?php echo (int)$statusCounts['sent']; ?>, <?php echo (int)$statusCounts['draft']; ?>], total = vals[0] + vals[1] + vals[2];
  new Chart($('giDonut'), {type: 'doughnut',
    data: {labels: ['Paid', 'Sent', 'Draft'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#25D366', '#3B82F6', '#9AA9A4'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>
