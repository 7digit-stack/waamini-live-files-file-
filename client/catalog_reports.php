<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/whatsapp_catalog_checkout.php';

$pageTitle = 'Catalog Reports';
$currentPage = 'catalog_reports';

// Ensure tables exist
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS catalog_interactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT DEFAULT NULL,
            customer_phone VARCHAR(30) NOT NULL,
            product_id INT DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            interaction_type ENUM('pay_now','enquire','buy_now','more_products','catalog_view') NOT NULL,
            auto_reply_sent TINYINT(1) DEFAULT 0,
            auto_reply_message TEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cat_int_company (company_id, interaction_type),
            INDEX idx_cat_int_product (product_id),
            INDEX idx_cat_int_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) { /* exists */ }
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS catalog_auto_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            reply_type ENUM('pay_now','enquire','buy_now','catalog_view','order_confirm') NOT NULL,
            message_template TEXT NOT NULL,
            is_enabled TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_type (company_id, reply_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) { /* exists */ }

// Handle auto-reply save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_replies'])) {
    csrf_verify();
    $types = ['pay_now', 'enquire', 'buy_now', 'catalog_view', 'order_confirm'];
    foreach ($types as $type) {
        $msg = trim($_POST['ar_' . $type] ?? '');
        $enabled = isset($_POST['ar_' . $type . '_enabled']) ? 1 : 0;
        if ($msg === '') {
            // Emptied = back to the default message
            $del = $conn->prepare("DELETE FROM catalog_auto_replies WHERE company_id = ? AND reply_type = ?");
            if ($del) {
                $del->bind_param('is', $companyId, $type);
                $del->execute();
            }
        } else {
            $st = $conn->prepare(
                "INSERT INTO catalog_auto_replies (company_id, reply_type, message_template, is_enabled) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE message_template=VALUES(message_template), is_enabled=VALUES(is_enabled), updated_at=NOW()"
            );
            if ($st) {
                $st->bind_param('issi', $companyId, $type, $msg, $enabled);
                $st->execute();
            }
        }
    }
    header("Location: catalog_reports.php?msg=saved");
    exit();
}

// Date range filter
$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate = $_GET['to'] ?? date('Y-m-d');

// Summary stats
$stats = ['total' => 0, 'pay_now' => 0, 'enquire' => 0, 'catalog_view' => 0, 'auto_replies' => 0];
$st = $conn->prepare("SELECT interaction_type, COUNT(*) as cnt, SUM(auto_reply_sent) as ar_sent FROM catalog_interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY interaction_type");
if ($st) {
    $st->bind_param('iss', $companyId, $fromDate, $toDate);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = $row['interaction_type'];
        $stats[$type] = (int)$row['cnt'];
        $stats['total'] += (int)$row['cnt'];
        $stats['auto_replies'] += (int)($row['ar_sent'] ?? 0);
    }
}

// Daily chart data (last 14 days)
$chartDays = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[$d] = ['pay_now' => 0, 'enquire' => 0, 'catalog_view' => 0];
}
$st2 = $conn->prepare("SELECT DATE(created_at) as day, interaction_type, COUNT(*) as cnt FROM catalog_interactions WHERE company_id=? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY day, interaction_type");
if ($st2) {
    $st2->bind_param('i', $companyId);
    $st2->execute();
    $res2 = $st2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $d = $row['day'];
        $t = $row['interaction_type'];
        if (isset($chartDays[$d]) && isset($chartDays[$d][$t])) {
            $chartDays[$d][$t] = (int)$row['cnt'];
        }
    }
}

// Product-wise breakdown
$productStats = [];
$st3 = $conn->prepare("SELECT product_name, product_id, interaction_type, COUNT(*) as cnt FROM catalog_interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ? AND product_id > 0 GROUP BY product_name, product_id, interaction_type ORDER BY cnt DESC LIMIT 20");
if ($st3) {
    $st3->bind_param('iss', $companyId, $fromDate, $toDate);
    $st3->execute();
    $res3 = $st3->get_result();
    while ($row = $res3->fetch_assoc()) {
        $productStats[] = $row;
    }
}

// Recent interactions
$recentInteractions = [];
$st4 = $conn->prepare("SELECT * FROM catalog_interactions WHERE company_id=? ORDER BY created_at DESC LIMIT 50");
if ($st4) {
    $st4->bind_param('i', $companyId);
    $st4->execute();
    $res4 = $st4->get_result();
    while ($row = $res4->fetch_assoc()) {
        $recentInteractions[] = $row;
    }
}

// Auto-reply templates
$autoReplies = [];
$st5 = $conn->prepare("SELECT * FROM catalog_auto_replies WHERE company_id=?");
if ($st5) {
    $st5->bind_param('i', $companyId);
    $st5->execute();
    $res5 = $st5->get_result();
    while ($row = $res5->fetch_assoc()) {
        $autoReplies[$row['reply_type']] = $row;
    }
}

// Default templates
$defaultTemplates = [
    'pay_now' => "{details}",
    'enquire' => "{details}",
    'buy_now' => "🛍️ *Great choice!*\n\n*{product_name}* — ₹{price}\n\nBuy it on our website:\n{buy_url}\n\n{next_step}",
    'catalog_view' => "📦 *Welcome to our catalog!*\n\nBrowse our products below. Tap *Pay Now* to order or *Enquire* for more details.",
    'order_confirm' => "✅ *Order confirmed!*\n\nThank you for ordering *{product_name}*. Here is how to pay:",
];

$typeLabels = [
    'pay_now' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> Pay Now',
    'enquire' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg> Enquire',
    'buy_now' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg> Buy Now',
    'catalog_view' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Catalog View',
    'order_confirm' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Order Confirm',
];

include '../includes/client_header.php';
?>
<?php
// View helpers (display only; all data comes from the queries above)
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ico = static fn(string $p, int $s = 18) => '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$I = [
    'back'  => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'view'  => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'enq'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'pay'   => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    'bot'   => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'cal'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'box'   => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
];
// One colour per action, used on every chart, legend, table and pill on this page
$C = ['catalog_view' => '#1D6FD6', 'enquire' => '#F59E0B', 'pay_now' => '#128C7E', 'buy_now' => '#0B6B5E', 'more_products' => '#7A8A85'];
$views = (int)($stats['catalog_view'] ?? 0);
$enq   = (int)($stats['enquire'] ?? 0);
$pay   = (int)($stats['pay_now'] ?? 0);
$total = (int)$stats['total'];
$ar    = (int)($stats['auto_replies'] ?? 0);
$pctOf = static fn(int $a, int $b) => $b > 0 ? round($a / $b * 100, 1) : 0;
$ringMax = max(1, $views, $enq, $pay);
$rings = [ // outer to inner
    ['Catalog views', $views, $C['catalog_view']],
    ['Enquiries',     $enq,   $C['enquire']],
    ['Pay Now taps',  $pay,   $C['pay_now']],
];
$rangeLbl = date('d M', strtotime($fromDate)) . ' – ' . date('d M Y', strtotime($toDate));
$typeName = ['pay_now' => 'Pay Now', 'enquire' => 'Enquire', 'catalog_view' => 'Catalog view', 'buy_now' => 'Buy Now', 'more_products' => 'More products'];

// Product table rows (same grouping as before)
$grouped = [];
foreach ($productStats as $ps) {
    $pn = $ps['product_name'];
    if (!isset($grouped[$pn])) $grouped[$pn] = ['pay_now' => 0, 'enquire' => 0, 'catalog_view' => 0, 'total' => 0];
    $grouped[$pn][$ps['interaction_type']] = (int)$ps['cnt'];
    $grouped[$pn]['total'] += (int)$ps['cnt'];
}
uasort($grouped, function ($a, $b) { return $b['total'] <=> $a['total']; });
$topTotal = $grouped ? max(array_column($grouped, 'total')) : 1;
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<div class="db cr">
  <div class="db-head">
    <div>
      <h1>Catalog Reports</h1>
      <p>How customers use your WhatsApp catalog: what they view, ask about and buy.</p>
    </div>
    <div class="db-head__actions">
      <a class="db-btn" href="catalog.php"><?php echo $ico($I['back'], 16); ?> Back to catalog</a>
    </div>
  </div>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
    <div class="cr-toast"><?php echo $ico('<path d="M20 6 9 17l-5-5"/>', 17); ?> Auto-reply templates saved.</div>
  <?php endif; ?>

  <!-- Date filter -->
  <form method="GET" class="cr-filter">
    <span class="cr-filter__ic"><?php echo $ico($I['cal'], 17); ?></span>
    <label>From <input type="date" name="from" value="<?php echo $esc($fromDate); ?>"></label>
    <label>To <input type="date" name="to" value="<?php echo $esc($toDate); ?>"></label>
    <button type="submit" class="cr-apply">Apply</button>
    <a href="catalog_reports.php" class="cr-reset">Reset</a>
    <span class="cr-filter__lbl"><?php echo $esc($rangeLbl); ?></span>
  </form>

  <!-- Overview: rings + funnel -->
  <div class="cr-top">
    <section class="db-card cr-mix">
      <div class="cr-card-h">
        <h2><?php echo $ico($I['box'], 18); ?> Interaction mix</h2>
        <span class="cr-range">Selected dates</span>
      </div>
      <div class="cr-mix__body">
        <div class="cr-rings" data-cr-rings>
          <svg viewBox="0 0 220 220" aria-hidden="true">
            <?php foreach ($rings as $i => [$lbl, $val, $col]):
              $r = 96 - $i * 22; $len = 2 * M_PI * $r * 0.75; // 270 degree track
              $frac = $val > 0 ? max(0.04, $val / $ringMax) : 0; ?>
              <circle class="cr-track" cx="110" cy="110" r="<?php echo $r; ?>" stroke-dasharray="<?php echo round($len, 2) . ' ' . round(2 * M_PI * $r, 2); ?>" transform="rotate(135 110 110)"/>
              <circle class="cr-arc" cx="110" cy="110" r="<?php echo $r; ?>" stroke="<?php echo $col; ?>" style="--len:<?php echo round($len, 2); ?>;--to:<?php echo round($len * (1 - $frac), 2); ?>;--d:<?php echo $i * 0.15; ?>s" stroke-dasharray="<?php echo round($len, 2) . ' ' . round(2 * M_PI * $r, 2); ?>" stroke-dashoffset="<?php echo round($len * (1 - $frac), 2); ?>" transform="rotate(135 110 110)"/>
            <?php endforeach; ?>
          </svg>
        </div>
        <div class="cr-mix__side">
          <small>Total interactions</small>
          <b class="cr-big"><?php echo number_format($total); ?></b>
          <span class="cr-note"><?php echo $ico('<path d="M20 6 9 17l-5-5"/>', 14); ?> <?php echo $pctOf($ar, $total); ?>% got an auto-reply</span>
          <ul class="cr-legend">
            <?php foreach ($rings as [$lbl, $val, $col]): ?>
              <li><i style="background:<?php echo $col; ?>"></i><span><?php echo $lbl; ?></span><b><?php echo number_format($val); ?></b></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </section>

    <section class="db-card cr-funnel">
      <div class="cr-card-h">
        <h2><?php echo $ico('<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>', 18); ?> Catalog funnel</h2>
        <span class="cr-range">Selected dates</span>
      </div>
      <div class="cr-duo">
        <div><small>Auto-replies sent</small><b><?php echo number_format($ar); ?></b></div>
        <div><small>View to Pay Now</small><b><?php echo $pctOf($pay, $views); ?>%</b></div>
      </div>
      <?php
      $steps = [
          ['Catalog views', $views, $C['catalog_view'], 'Opened the catalog', null],
          ['Enquiries', $enq, $C['enquire'], 'Asked about a product', $pctOf($enq, $views)],
          ['Pay Now taps', $pay, $C['pay_now'], 'Started a payment', $pctOf($pay, $views)],
      ];
      $fMax = max(1, $views, $enq, $pay); ?>
      <div class="cr-steps">
        <?php foreach ($steps as $i => [$lbl, $val, $col, $hint, $conv]): ?>
          <div class="cr-step">
            <div class="cr-step__top">
              <span class="cr-step__n" style="background:<?php echo $col; ?>"><?php echo $i + 1; ?></span>
              <div class="cr-step__t"><b><?php echo $lbl; ?></b><small><?php echo $hint; ?></small></div>
              <div class="cr-step__v"><b><?php echo number_format($val); ?></b><?php if ($conv !== null): ?><small><?php echo $conv; ?>% of views</small><?php endif; ?></div>
            </div>
            <div class="cr-bar"><i style="width:<?php echo $val > 0 ? max(2, round($val / $fMax * 100)) : 0; ?>%;background:<?php echo $col; ?>"></i></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <!-- Daily chart -->
  <section class="db-card cr-daily">
    <div class="cr-card-h">
      <div><h2>Interactions per day</h2><p>Last 14 days, by action</p></div>
      <div class="cr-dots">
        <span><i style="background:<?php echo $C['catalog_view']; ?>"></i>Catalog views</span>
        <span><i style="background:<?php echo $C['enquire']; ?>"></i>Enquiries</span>
        <span><i style="background:<?php echo $C['pay_now']; ?>"></i>Pay Now</span>
      </div>
    </div>
    <div class="cr-chart">
      <canvas id="crDaily" aria-label="Catalog interactions per day for the last 14 days" role="img"></canvas>
      <?php if (!array_sum(array_map('array_sum', $chartDays))): ?><div class="cr-chart-empty">No catalog activity in the last 14 days</div><?php endif; ?>
    </div>
  </section>

  <!-- Detail tabs -->
  <div class="cr-tabs" role="tablist">
    <button type="button" class="cr-tab is-on" data-tab="products" role="tab" aria-selected="true"><?php echo $ico($I['box'], 16); ?> By product</button>
    <button type="button" class="cr-tab" data-tab="recent" role="tab" aria-selected="false"><?php echo $ico($I['clock'], 16); ?> Recent</button>
    <button type="button" class="cr-tab" data-tab="autoreply" role="tab" aria-selected="false"><?php echo $ico($I['bot'], 16); ?> Auto-reply</button>
  </div>

  <!-- By product -->
  <section class="db-card db-tablecard cr-panel" id="panel_products">
    <div class="db-card__head"><div><h2>Products</h2><p>Top products by interactions in the selected dates</p></div></div>
    <div class="db-tablewrap">
      <?php if ($grouped): ?>
      <table class="db-table cr-table">
        <thead><tr><th>Product</th><th class="num">Views</th><th class="num">Enquiries</th><th class="num">Pay Now</th><th>Share of interest</th></tr></thead>
        <tbody>
          <?php foreach ($grouped as $name => $counts): ?>
          <tr>
            <td><div class="db-who"><span class="db-av"><?php echo $esc(mb_strtoupper(mb_substr((string)$name, 0, 1))); ?></span><div><b><?php echo $esc($name); ?></b><small><?php echo (int)$counts['total']; ?> interactions</small></div></div></td>
            <td class="num"><?php echo (int)$counts['catalog_view']; ?></td>
            <td class="num"><?php echo (int)$counts['enquire']; ?></td>
            <td class="num"><b><?php echo (int)$counts['pay_now']; ?></b></td>
            <td><div class="cr-share"><div class="cr-bar"><i style="width:<?php echo round($counts['total'] / max(1, $topTotal) * 100); ?>%;background:<?php echo $C['pay_now']; ?>"></i></div><span><?php echo $pctOf((int)$counts['total'], $total); ?>%</span></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="cr-empty"><span><?php echo $ico($I['box'], 24); ?></span><b>No product activity yet</b><p>When customers message <b>products</b>, <b>catalog</b> or <b>shop</b> and tap a product, it shows here.</p></div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Recent -->
  <section class="db-card db-tablecard cr-panel" id="panel_recent" hidden>
    <div class="db-card__head"><div><h2>Recent interactions</h2><p>Latest 50 catalog taps from customers</p></div></div>
    <div class="db-tablewrap">
      <?php if ($recentInteractions): ?>
      <table class="db-table cr-table">
        <thead><tr><th>Time</th><th>Customer</th><th>Product</th><th>Action</th><th>Auto-reply</th></tr></thead>
        <tbody>
          <?php foreach ($recentInteractions as $ri): $t = $ri['interaction_type']; $col = $C[$t] ?? '#7A8A85'; ?>
          <tr>
            <td class="cr-time"><?php echo $esc(date('d M, h:i A', strtotime($ri['created_at']))); ?></td>
            <td><?php echo $esc($ri['customer_phone']); ?></td>
            <td><b><?php echo $esc($ri['product_name']); ?></b></td>
            <td><span class="cr-pill" style="--c:<?php echo $col; ?>"><i></i><?php echo $esc($typeName[$t] ?? ucwords(str_replace('_', ' ', $t))); ?></span></td>
            <td><?php if ($ri['auto_reply_sent']): ?><span class="db-pill is-ok">Sent</span><?php else: ?><span class="db-mut">—</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="cr-empty"><span><?php echo $ico($I['clock'], 24); ?></span><b>No interactions recorded yet</b><p>Every catalog tap from WhatsApp is listed here with the reply that was sent.</p></div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Auto-reply templates as a flow (same form fields as before) -->
  <?php
  // When each template is sent, which placeholders it understands, and what "off" does
  $arMeta = [
      'catalog_view'  => ['title' => 'Catalog opened',         'when' => 'Customer sends products, catalog, shop or menu. Sent before the first product card.',
                          'vars' => ['product_count'], 'off' => 'Off: no welcome message, product cards only.'],
      'pay_now'       => ['title' => 'Pay Now tapped',         'when' => 'Customer taps Pay Now on a product without a website link.',
                          'vars' => ['product_name', 'price', 'details', 'next_step'], 'off' => 'Off: the standard product message is sent.'],
      'buy_now'       => ['title' => 'Pay Now, website link',  'when' => 'Customer taps Pay Now on a product that has a buy link.',
                          'vars' => ['product_name', 'price', 'buy_url', 'next_step'], 'off' => 'Off: the standard product message is sent.'],
      'enquire'       => ['title' => 'Enquire tapped',         'when' => 'Customer taps Enquire. Their next message is saved as an enquiry.',
                          'vars' => ['product_name', 'price', 'buy_url', 'description', 'details', 'next_step'], 'off' => 'Off: the standard product details are sent.'],
      'order_confirm' => ['title' => 'Order placed',           'when' => 'Customer confirms the delivery address. Shown above the order summary and payment link.',
                          'vars' => ['product_name', 'qty', 'total', 'order_id', 'customer_name'], 'off' => 'Off: a standard "Order confirmed" heading is used.'],
  ];
  // Automatic steps WAAMINI runs itself (not editable here)
  $efAuto = [
      'cards'   => ['title' => 'Product cards',        'desc' => 'Photo, name and price of each product, with buttons. More Products shows the next card.'],
      'steps'   => ['title' => 'Quantity and address', 'desc' => 'Asks how many, shows the order summary, then collects name, phone, address, city and pincode.'],
      'saved'   => ['title' => 'Enquiry saved',        'desc' => 'The customer\'s question goes to Product Catalog → Enquiries for your team, and they get a thank-you.'],
      'payment' => ['title' => 'Payment sent',         'desc' => 'Order summary with the UPI pay link and QR code (or Razorpay / Stripe link). The order shows in Manage Orders.'],
  ];
  $efIco = [
      'trigger' => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
      'catalog_view' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
      'cards' => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
      'pay_now' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
      'buy_now' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
      'enquire' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
      'steps' => '<path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"/><path d="m9 11 3 3L22 4"/>',
      'saved' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
      'order_confirm' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
      'payment' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
  ];
  $efColor = ['trigger' => '#128C7E', 'catalog_view' => '#1D6FD6', 'cards' => '#7A8A85', 'pay_now' => '#128C7E', 'buy_now' => '#0B6B5E',
              'enquire' => '#F59E0B', 'steps' => '#7A8A85', 'saved' => '#7A8A85', 'order_confirm' => '#7C3AED', 'payment' => '#7A8A85'];
  // Canvas positions (world coordinates)
  $efPos = ['trigger' => [30, 190], 'catalog_view' => [340, 190], 'cards' => [650, 160], 'pay_now' => [960, 20], 'buy_now' => [960, 250],
            'enquire' => [960, 480], 'steps' => [1270, 120], 'saved' => [1270, 500], 'order_confirm' => [1580, 120], 'payment' => [1890, 150]];
  // Connectors: [from node, from port, to node]
  $efEdges = [['trigger', 'main', 'catalog_view'], ['catalog_view', 'main', 'cards'], ['cards', 'pay', 'pay_now'], ['cards', 'buy', 'buy_now'],
              ['cards', 'enq', 'enquire'], ['pay_now', 'main', 'steps'], ['buy_now', 'main', 'steps'], ['enquire', 'main', 'saved'],
              ['steps', 'main', 'order_confirm'], ['order_confirm', 'main', 'payment']];
  $efSvg = static fn(string $p) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
  $efState = [];
  foreach (array_keys($arMeta) as $type) {
      $default = $defaultTemplates[$type];
      $stored = isset($autoReplies[$type]) ? (string)$autoReplies[$type]['message_template'] : null;
      $norm = static fn($t) => trim(str_replace("\r\n", "\n", (string)$t));
      if ($stored !== null && (waamini_catalog_legacy_default($type, $stored) || $norm($stored) === $norm($default))) {
          $stored = null; // not customised
      }
      $efState[$type] = [
          'on' => (isset($autoReplies[$type]) && $autoReplies[$type]['is_enabled']) || !isset($autoReplies[$type]),
          'custom' => $stored !== null,
          'text' => $stored ?? $default,
          'default' => $default,
      ];
  }
  ?>
  <section class="db-card cr-panel ef" id="panel_autoreply" hidden>
    <form method="POST" class="ef-form">
      <?php echo csrf_field(); ?>
      <div class="ef-top">
        <div class="ef-top__t"><b>Catalog auto-reply flow</b><small>What WhatsApp customers receive at each step. Click a message to edit it.</small></div>
        <div class="ef-legend"><span><i class="is-msg"></i>Message you can edit</span><span><i class="is-auto"></i>Automatic step</span></div>
        <div class="ef-zoom" aria-label="Zoom">
          <button type="button" data-ef-zoom="-1" aria-label="Zoom out">&minus;</button>
          <button type="button" data-ef-zoom="0" aria-label="Fit to screen">Fit</button>
          <button type="button" data-ef-zoom="1" aria-label="Zoom in">+</button>
        </div>
      </div>

      <div class="ef-work">
        <div class="ef-view" data-ef-view>
          <div class="ef-world" data-ef-world style="width:2180px;height:660px">
            <svg class="ef-edges" data-ef-edges aria-hidden="true"></svg>

            <!-- Trigger -->
            <div class="ef-node is-trigger" data-node="trigger" style="left:<?php echo $efPos['trigger'][0]; ?>px;top:<?php echo $efPos['trigger'][1]; ?>px;--c:<?php echo $efColor['trigger']; ?>">
              <div class="ef-nh"><span class="ef-ic"><?php echo $efSvg($efIco['trigger']); ?></span><div class="ef-nt"><b>Keyword received</b><small>Trigger</small></div></div>
              <div class="ef-nb"><div class="ef-kw"><span>products</span><span>catalog</span><span>shop</span><span>menu</span></div><p class="ef-note">Also /products and /catalog. Works in any letter case.</p></div>
              <span class="ef-port out main" data-port="main"></span>
            </div>

            <?php foreach (['catalog_view', 'pay_now', 'buy_now', 'enquire', 'order_confirm'] as $type):
              $m = $arMeta[$type]; $st = $efState[$type]; ?>
              <div class="ef-node is-msg<?php echo $st['on'] ? '' : ' is-off'; ?>" data-node="<?php echo $type; ?>" data-edit="<?php echo $type; ?>" tabindex="0" role="button" aria-label="Edit <?php echo $esc($m['title']); ?>"
                   style="left:<?php echo $efPos[$type][0]; ?>px;top:<?php echo $efPos[$type][1]; ?>px;--c:<?php echo $efColor[$type]; ?>">
                <span class="ef-port in"></span>
                <div class="ef-nh"><span class="ef-ic"><?php echo $efSvg($efIco[$type]); ?></span><div class="ef-nt"><b><?php echo $esc($m['title']); ?></b><small>Send message</small></div></div>
                <div class="ef-nb">
                  <div class="ef-prev" data-node-prev="<?php echo $type; ?>"></div>
                  <div class="ef-meta">
                    <span class="ef-tag" data-node-state="<?php echo $type; ?>"><?php echo $st['on'] ? 'On' : 'Off'; ?></span>
                    <?php if ($st['custom']): ?><span class="ef-tag is-edit">Edited</span><?php endif; ?>
                  </div>
                </div>
                <span class="ef-port out main" data-port="main"></span>
              </div>
            <?php endforeach; ?>

            <!-- Product cards (automatic) with three buttons -->
            <div class="ef-node is-auto" data-node="cards" data-info="cards" tabindex="0" role="button" style="left:<?php echo $efPos['cards'][0]; ?>px;top:<?php echo $efPos['cards'][1]; ?>px;--c:<?php echo $efColor['cards']; ?>">
              <span class="ef-port in"></span>
              <div class="ef-nh"><span class="ef-ic"><?php echo $efSvg($efIco['cards']); ?></span><div class="ef-nt"><b>Product cards</b><small>Automatic</small></div></div>
              <div class="ef-outs">
                <div class="ef-out is-pay"><span>Pay Now</span><em>no buy link</em><span class="ef-port out" data-port="pay"></span></div>
                <div class="ef-out is-buy"><span>Pay Now</span><em>has buy link</em><span class="ef-port out" data-port="buy"></span></div>
                <div class="ef-out is-enq"><span>Enquire</span><span class="ef-port out" data-port="enq"></span></div>
                <div class="ef-out is-more"><span>More Products</span><em>next card</em></div>
              </div>
            </div>

            <?php foreach (['steps', 'saved', 'payment'] as $k): ?>
              <div class="ef-node is-auto" data-node="<?php echo $k; ?>" data-info="<?php echo $k; ?>" tabindex="0" role="button" style="left:<?php echo $efPos[$k][0]; ?>px;top:<?php echo $efPos[$k][1]; ?>px;--c:<?php echo $efColor[$k]; ?>">
                <span class="ef-port in"></span>
                <div class="ef-nh"><span class="ef-ic"><?php echo $efSvg($efIco[$k]); ?></span><div class="ef-nt"><b><?php echo $esc($efAuto[$k]['title']); ?></b><small>Automatic</small></div></div>
                <div class="ef-nb"><p class="ef-note"><?php echo $esc($efAuto[$k]['desc']); ?></p></div>
                <?php if ($k === 'steps'): ?><span class="ef-port out main" data-port="main"></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Inspector: one editor per message node (all fields stay in the form) -->
        <aside class="ef-insp" data-ef-insp aria-label="Edit message">
          <?php foreach ($arMeta as $type => $m): $st = $efState[$type]; ?>
            <div class="ef-ed" data-ar data-type="<?php echo $type; ?>" data-editor="<?php echo $type; ?>" hidden>
              <div class="ef-ih">
                <span class="ef-ic" style="--c:<?php echo $efColor[$type]; ?>"><?php echo $efSvg($efIco[$type]); ?></span>
                <div class="ef-nt"><b><?php echo $esc($m['title']); ?></b><small style="color:<?php echo $efColor[$type]; ?>">Send message</small></div>
                <button type="button" class="ef-x" data-ef-close aria-label="Close">&times;</button>
              </div>
              <div class="ef-ib">
                <p class="ef-when"><?php echo $esc($m['when']); ?></p>
                <label class="cr-switch ef-sw"><input type="checkbox" name="ar_<?php echo $type; ?>_enabled" value="1" <?php echo $st['on'] ? 'checked' : ''; ?>><span>Send this message</span></label>
                <p class="ef-off"><?php echo $esc($m['off']); ?></p>
                <label class="ef-lbl" for="ar_<?php echo $type; ?>">Message</label>
                <textarea id="ar_<?php echo $type; ?>" name="ar_<?php echo $type; ?>" rows="7" data-default="<?php echo $esc($st['default']); ?>"><?php echo htmlspecialchars($st['text']); ?></textarea>
                <div class="ef-vars"><?php foreach ($m['vars'] as $v): ?><button type="button" class="cr-var" data-var="{<?php echo $v; ?>}">{<?php echo $v; ?>}</button><?php endforeach; ?></div>
                <button type="button" class="cr-link" data-reset>Reset to default</button>
                <div class="ef-phone"><small>Preview on WhatsApp</small><div class="cr-ar__msg" data-preview></div></div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php foreach ($efAuto as $k => $a): ?>
            <div class="ef-ed" data-editor="<?php echo $k; ?>" hidden>
              <div class="ef-ih">
                <span class="ef-ic" style="--c:#7A8A85"><?php echo $efSvg($efIco[$k]); ?></span>
                <div class="ef-nt"><b><?php echo $esc($a['title']); ?></b><small style="color:#7A8A85">Automatic step</small></div>
                <button type="button" class="ef-x" data-ef-close aria-label="Close">&times;</button>
              </div>
              <div class="ef-ib"><p class="ef-when"><?php echo $esc($a['desc']); ?></p><p class="ef-off">WAAMINI runs this step itself, so there is nothing to edit here.</p></div>
            </div>
          <?php endforeach; ?>
        </aside>
      </div>

      <div class="cr-ar__foot">
        <span><b>{details}</b> is the standard product message and <b>{next_step}</b> the "reply with a quantity / your question" line; WAAMINI adds the next step if a message leaves it out. Empty a message and save to restore its default.</span>
        <button type="submit" name="save_auto_replies" class="db-btn db-btn--primary">Save auto-replies</button>
      </div>
    </form>
  </section>
<?php $efEdgesJson = json_encode($efEdges); ?>
</div>

<style>
/* Catalog Reports: dash-pro layout, flat colours, no divider lines */
.cr .db-btn--primary{background:#128C7E;box-shadow:none}.cr .db-btn--primary:hover{background:#0B6B5E;transform:none}
.cr-toast{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;background:#E6FCF2;color:#0B6B5E;font-weight:600;font-size:14px;margin-bottom:16px}
.cr-filter{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:0 0 18px;padding:10px 12px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 12px 30px -26px rgba(18,140,126,.5)}
.cr-filter__ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#E6FCF2;color:#0B6B5E}
.cr-filter label{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--tx)}
.cr-filter input{height:36px;padding:0 10px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;font:500 13px Inter,system-ui,sans-serif;color:var(--ink)}
.cr-filter input:focus{outline:0;border-color:#128C7E}
.cr-apply{height:36px;padding:0 16px;border:0;border-radius:10px;background:#128C7E;color:#fff;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
.cr-apply:hover{background:#0B6B5E}
.cr-reset{font-size:13px;font-weight:700;color:#0B6B5E;text-decoration:none;padding:0 4px}
.cr-filter__lbl{margin-left:auto;font-size:12.5px;color:var(--mu)}

.cr-top{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:18px;margin-bottom:18px}
.cr-card-h{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.cr-card-h h2{display:flex;align-items:center;gap:9px;margin:0;font-size:16.5px;font-weight:800;color:var(--ink)}
.cr-card-h h2 svg{color:#128C7E}
.cr-card-h p{margin:3px 0 0;font-size:13px;color:var(--mu)}
.cr-range{padding:6px 12px;border-radius:9px;border:1px solid #E3EBE8;font-size:12.5px;font-weight:600;color:#4A5B55;white-space:nowrap}

.cr-mix,.cr-funnel,.cr-daily{padding:20px 22px}
.cr-mix__body{display:grid;grid-template-columns:minmax(180px,250px) minmax(0,1fr);gap:26px;align-items:center;margin-top:12px}
.cr-rings svg{display:block;width:100%;height:auto}
.cr-track{fill:none;stroke:#EEF3F1;stroke-width:15;stroke-linecap:round}
.cr-arc{fill:none;stroke-width:15;stroke-linecap:round}
/* loading animation: arcs grow from empty, one after another */
.cr-rings.is-armed .cr-arc{stroke-dashoffset:var(--len);transition:none}
.cr-rings.is-armed.is-in .cr-arc{stroke-dashoffset:var(--to);transition:stroke-dashoffset 1.3s cubic-bezier(.22,.61,.36,1) var(--d)}
.cr-mix__side small{display:block;font-size:13px;font-weight:600;color:var(--mu)}
.cr-big{display:block;margin:4px 0 8px;font-size:38px;font-weight:800;letter-spacing:-.03em;line-height:1.05;color:var(--ink);font-variant-numeric:tabular-nums}
.cr-note{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#1D6FD6}
.cr-legend{list-style:none;margin:20px 0 0;padding:0;display:grid;gap:8px}
.cr-legend li{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;background:#F6FAF8;font-size:13.5px;color:var(--tx)}
.cr-legend i{width:9px;height:9px;border-radius:50%}
.cr-legend b{font-size:14.5px;color:var(--ink);font-variant-numeric:tabular-nums}

.cr-duo{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}
.cr-duo div{padding:12px 14px;border-radius:12px;background:#F6FAF8}
.cr-duo small{display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.cr-duo b{display:block;margin-top:3px;font-size:26px;font-weight:800;letter-spacing:-.02em;color:var(--ink);font-variant-numeric:tabular-nums}
.cr-steps{display:grid;gap:14px;margin-top:18px}
.cr-step__top{display:flex;align-items:center;gap:12px}
.cr-step__n{width:26px;height:26px;flex:none;border-radius:8px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:12.5px}
.cr-step__t{flex:1;min-width:0}
.cr-step__t b{display:block;font-size:14px;color:var(--ink)}
.cr-step__t small{display:block;font-size:12px;color:var(--mu)}
.cr-step__v{text-align:right}
.cr-step__v b{display:block;font-size:18px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.cr-step__v small{display:block;font-size:11.5px;color:var(--mu)}
.cr-bar{height:8px;margin-top:9px;border-radius:99px;background:#EEF3F1;overflow:hidden}
.cr-bar i{display:block;height:100%;border-radius:99px}

.cr-daily{margin-bottom:18px}
.cr-dots{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;color:#6B7A75}
.cr-dots span{display:inline-flex;align-items:center;gap:7px}
.cr-dots i{width:8px;height:8px;border-radius:50%}
.cr-chart{position:relative;height:280px;margin-top:16px}
.cr-chart-empty{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%);padding:7px 14px;border-radius:9px;background:#F6FAF8;font-size:12.5px;font-weight:600;color:#6B7A75;white-space:nowrap;pointer-events:none}

.cr-tabs{display:inline-flex;gap:4px;padding:4px;margin-bottom:14px;border-radius:12px;background:#fff;border:1px solid var(--line);max-width:100%;overflow-x:auto}
.cr-tab{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 16px;border:0;border-radius:9px;background:none;font:700 13.5px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer;white-space:nowrap}
.cr-tab:hover{background:#F3FBF7;color:#0B6B5E}
.cr-tab.is-on{background:#128C7E;color:#fff}
.cr-table th.num,.cr-table td.num{text-align:right}
.cr-table td{border-bottom:0}
.cr-table tbody tr:nth-child(even) td{background:#F8FCFA}
.cr-time{white-space:nowrap;color:var(--tx)}
.cr-share{display:flex;align-items:center;gap:10px;min-width:160px}
.cr-share .cr-bar{flex:1;margin:0}
.cr-share span{font-size:12.5px;font-weight:700;color:var(--tx);min-width:44px;text-align:right}
.cr-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);font-size:12px;font-weight:700;white-space:nowrap}
.cr-pill i{width:6px;height:6px;border-radius:50%;background:var(--c)}
.cr-empty{padding:40px 16px;text-align:center}
.cr-empty span{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;margin:0 auto 12px;background:#E6FCF2;color:#128C7E}
.cr-empty > b{display:block;font-size:16px;color:var(--ink)}
.cr-empty p{margin:6px auto 0;max-width:420px;font-size:13.5px;color:var(--tx);line-height:1.55}

/* Auto-reply flow: same look as the Flow Builder canvas (dotted grid, white nodes, curved connectors, inspector) */
.ef{padding:0;overflow:hidden}
.ef-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 20px}
.ef-top__t{flex:1;min-width:220px}
.ef-top__t b{display:block;font-size:16.5px;font-weight:800;color:var(--ink)}
.ef-top__t small{display:block;margin-top:2px;font-size:13px;color:var(--mu)}
.ef-legend{display:flex;gap:14px;font-size:12.5px;color:var(--tx)}
.ef-legend span{display:inline-flex;align-items:center;gap:7px}
.ef-legend i{width:12px;height:12px;border-radius:4px;background:#fff;box-shadow:0 0 0 2px #128C7E}
.ef-legend i.is-auto{box-shadow:0 0 0 2px #B9C6C1;background:#F4F7F6}
.ef-zoom{display:inline-flex;gap:2px;padding:3px;border-radius:11px;background:#F3F7F5}
.ef-zoom button{min-width:34px;height:32px;padding:0 8px;border:0;border-radius:8px;background:transparent;font:800 13px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer}
.ef-zoom button:hover{background:#fff;color:#0B6B5E}

.ef-work{position:relative}
.ef-view{position:relative;height:520px;overflow:auto;background-color:#F6F9F8;background-image:radial-gradient(circle,#CBD8D3 1.4px,transparent 1.5px);background-size:24px 24px;cursor:grab}
.ef-view.is-pan{cursor:grabbing;user-select:none}
.ef-world{position:relative;transform-origin:0 0}
.ef-edges{position:absolute;left:0;top:0;width:100%;height:100%;overflow:visible;pointer-events:none}
.ef-edge{fill:none;stroke:#9FB2AB;stroke-width:2.4}
.ef-edge.is-hot{stroke:#128C7E;stroke-width:3}
.ef-flowdot{fill:#128C7E}

.ef-node{position:absolute;width:262px;background:#fff;border-radius:18px;box-shadow:0 10px 28px -16px rgba(15,38,34,.5),0 0 0 2px transparent;transition:box-shadow .15s,opacity .2s}
.ef-node.is-msg,.ef-node.is-auto{cursor:pointer}
.ef-node.is-msg:hover,.ef-node.is-auto:hover{box-shadow:0 14px 32px -16px rgba(15,38,34,.6),0 0 0 2px #CFE8DF}
.ef-node.is-sel{box-shadow:0 14px 34px -14px rgba(18,140,126,.55),0 0 0 2.5px #128C7E}
.ef-node:focus-visible{outline:0;box-shadow:0 0 0 3px #128C7E}
.ef-node.is-trigger{box-shadow:0 10px 28px -16px rgba(15,38,34,.5),0 0 0 2px #128C7E}
.ef-node.is-auto{background:#FBFCFC}
.ef-node.is-off{opacity:.62}
.ef-nh{display:flex;align-items:center;gap:11px;padding:13px 14px 8px}
.ef-ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:none;background:color-mix(in srgb,var(--c) 14%,#fff);color:var(--c)}
.ef-ic svg{width:18px;height:18px}
.ef-nt{min-width:0;flex:1}
.ef-nt b{display:block;font-size:14.5px;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ef-nt small{display:block;margin-top:1px;font-size:11px;font-weight:700;color:var(--c);text-transform:uppercase;letter-spacing:.06em}
.ef-nb{padding:2px 14px 14px;font-size:13px;line-height:1.5;color:var(--tx)}
.ef-prev{background:#F3F7F5;border-radius:12px;padding:9px 11px;max-height:76px;overflow:hidden;white-space:pre-wrap;word-break:break-word;font-size:12.5px;color:var(--tx)}
.ef-meta{display:flex;gap:6px;margin-top:8px}
.ef-tag{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:99px;background:#E3F7EE;color:#0B6B5E;font-size:11.5px;font-weight:700}
.ef-node.is-off .ef-tag:first-child{background:#EEF3F1;color:#5F6F6A}
.ef-tag.is-edit{background:#FFF4DE;color:#8a5300}
.ef-kw{display:flex;flex-wrap:wrap;gap:6px}
.ef-kw span{padding:4px 10px;border-radius:99px;background:#E6FCF2;color:#0B6B5E;font:700 12px ui-monospace,Menlo,Consolas,monospace}
.ef-note{margin:8px 0 0;font-size:12px;color:var(--mu);line-height:1.45}
.ef-nb .ef-note:first-child{margin-top:0}
.ef-outs{display:flex;flex-direction:column;gap:6px;padding:0 0 12px}
.ef-out{position:relative;display:flex;align-items:center;gap:8px;margin:0 14px;padding:8px 22px 8px 12px;border-radius:11px;background:#F1F7F5;font-size:13px;font-weight:700;color:var(--ink)}
.ef-out em{margin-left:auto;font-style:normal;font-size:11px;font-weight:600;color:var(--mu)}
.ef-out.is-enq{background:#FEF6E7;color:#8a5300}
.ef-out.is-more{background:#F4F6F5;color:#5F6F6A;font-weight:600}
.ef-port{position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 3px #9FB2AB;z-index:2}
.ef-port.in{left:-7px;top:26px}
.ef-port.out.main{right:-7px;top:26px}
.ef-out .ef-port.out{right:-21px;top:50%;margin-top:-7px}
.ef-node.is-sel .ef-port{box-shadow:0 0 0 3px #128C7E}

.ef-insp{position:absolute;right:14px;top:14px;bottom:14px;width:370px;max-width:calc(100% - 28px);z-index:5;background:#fff;border-radius:20px;box-shadow:0 22px 50px -24px rgba(15,38,34,.6);display:flex;flex-direction:column;overflow:hidden;transform:translateX(calc(100% + 24px));transition:transform .28s cubic-bezier(.22,.61,.36,1)}
.ef-insp.is-open{transform:none}
.ef-ed{display:flex;flex-direction:column;min-height:0;flex:1}
.ef-ed[hidden]{display:none}
.ef-ih{display:flex;align-items:center;gap:12px;padding:16px 16px 10px}
.ef-ih .ef-nt b{font-size:15.5px}
.ef-x{width:34px;height:34px;border:0;border-radius:10px;background:#F3F7F5;color:var(--tx);font-size:20px;line-height:1;cursor:pointer}
.ef-x:hover{background:#E1F6EC}
.ef-ib{flex:1;min-height:0;overflow-y:auto;padding:0 16px 16px;display:flex;flex-direction:column;gap:10px}
.ef-when{margin:0;padding:10px 12px;border-radius:11px;background:#F3F7F5;font-size:12.5px;color:var(--tx);line-height:1.5}
.ef-off{margin:-4px 0 0;font-size:11.5px;color:var(--mu)}
.ef-lbl{font-size:12.5px;font-weight:700;color:var(--tx)}
.ef textarea{display:block;width:100%;min-height:150px;padding:10px 12px;border:1.5px solid #E3EEE9;border-radius:10px;background:#fff;font:500 13px/1.5 Inter,system-ui,sans-serif;color:var(--ink);resize:vertical}
.ef textarea:focus{outline:0;border-color:#128C7E;box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.ef-vars{display:flex;flex-wrap:wrap;gap:6px}
.ef .cr-var{background:#F3F7F5}
.ef .cr-link{align-self:flex-start}
.ef-phone{margin-top:4px;padding:10px;border-radius:12px;background:#EFEAE2}
.ef-phone small{display:block;margin-bottom:6px;font-size:11px;font-weight:700;color:#7A8A85}

.cr-ar__msg{max-width:100%;padding:8px 10px;border-radius:0 9px 9px 9px;background:#fff;font-size:12.5px;line-height:1.5;color:#111B21;white-space:pre-wrap;word-break:break-word;box-shadow:0 1px .5px rgba(11,20,26,.13)}
.cr-ar__msg .is-var{padding:0 3px;border-radius:4px;background:#E6FCF2;color:#0B6B5E}
.cr-ar__msg.is-off{opacity:.45}
.cr-var{padding:4px 8px;border:0;border-radius:7px;background:#fff;color:#0B6B5E;font:600 11.5px ui-monospace,Menlo,Consolas,monospace;cursor:pointer}
.cr-var:hover{background:#E6FCF2}
.cr-link{border:0;background:none;padding:0;color:#0B6B5E;font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.cr-switch{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:var(--tx);cursor:pointer}
.cr-switch input{appearance:none;-webkit-appearance:none;width:34px;height:20px;margin:0;border-radius:99px;background:#CBD8D3;position:relative;cursor:pointer;transition:background .2s}
.cr-switch input::after{content:'';position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s}
.cr-switch input:checked{background:#128C7E}
.cr-switch input:checked::after{transform:translateX(14px)}
.cr-switch input:focus-visible{outline:2px solid #128C7E;outline-offset:2px}
.ef-sw{font-size:13px}
.cr-ar__foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;background:#EEFBF4}
.cr-ar__foot span{flex:1;min-width:240px;font-size:12.5px;color:var(--tx);line-height:1.5}
.cr-ar__foot span b{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#0B6B5E}
@media (max-width:760px){.ef-view{height:460px}.ef-insp{left:10px;right:10px;width:auto;max-width:none}.ef-legend{display:none}}

@media (max-width:1100px){.cr-top{grid-template-columns:1fr}}
@media (max-width:640px){.cr-mix__body{grid-template-columns:1fr;justify-items:center}.cr-mix__side{width:100%}.cr-rings{max-width:220px}.cr-filter__lbl{margin-left:0;width:100%}}
</style>

<script>
(function () {
  /* Auto-reply flow: preview text, inspector, connectors, zoom and pan */
  var NEXT = {
    pay_now: 'How many would you like? Reply with a *number (1–99)*.\nReply *cancel* to go back.',
    buy_now: 'Or order here on WhatsApp: reply a *number (1–99)* for quantity.\nReply *cancel* to go back.',
    enquire: '💬 Type your question below and our team will reply.\nReply *cancel* to go back.'
  };
  var DETAILS = {
    pay_now: '🛒 *Kanchipuram Silk Saree*\n💰 Price: *₹4,850.00*\n\n' + NEXT.pay_now,
    enquire: '📋 *Product Details*\n\n📦 *Kanchipuram Silk Saree*\n💰 Price: *₹4,850.00*\n\n📝 Pure silk, maroon with gold zari border.\n\n' + NEXT.enquire
  };
  var SAMPLE = {
    product_name: 'Kanchipuram Silk Saree', price: '4,850.00', buy_url: 'https://yourshop.in/saree',
    product_count: '8', qty: '2', total: '9,700.00', order_id: '1042', customer_name: 'Priya',
    description: 'Pure silk, maroon with gold zari border.'
  };
  function escHtml(t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function render(text, kind) {
    SAMPLE.next_step = NEXT[kind] || NEXT.pay_now;
    SAMPLE.details = DETAILS[kind] || DETAILS.pay_now;
    var out = escHtml(text).replace(/\{([a-z_]+)\}/g, function (m, k) {
      return SAMPLE[k] !== undefined ? '<span class="is-var">' + escHtml(SAMPLE[k]) + '</span>' : m;
    });
    return out.replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>');
  }

  var root = document.querySelector('.ef');
  if (root) (function () {
    var view = root.querySelector('[data-ef-view]'), world = root.querySelector('[data-ef-world]');
    var edgesSvg = root.querySelector('[data-ef-edges]'), insp = root.querySelector('[data-ef-insp]');
    var EDGES = <?php echo $efEdgesJson; ?>;
    var W = parseFloat(world.style.width), H = parseFloat(world.style.height), scale = 1, fitted = false;

    /* message editors: preview in the node and in the inspector */
    root.querySelectorAll('[data-ar]').forEach(function (ed) {
      var kind = ed.getAttribute('data-type'), ta = ed.querySelector('textarea'), sw = ed.querySelector('.cr-switch input');
      var pv = ed.querySelector('[data-preview]'), node = root.querySelector('.ef-node[data-node="' + kind + '"]');
      var nodePrev = root.querySelector('[data-node-prev="' + kind + '"]'), nodeState = root.querySelector('[data-node-state="' + kind + '"]');
      var update = function () {
        var txt = ta.value.trim() === '' ? ta.getAttribute('data-default') : ta.value;
        // Same rule as the server: the next-step line is added when a message leaves it out
        if (NEXT[kind] && txt.indexOf('{next_step}') < 0 && txt.indexOf('{details}') < 0) txt = txt.replace(/\s+$/, '') + '\n\n{next_step}';
        var html = render(txt, kind);
        pv.innerHTML = html; pv.classList.toggle('is-off', !sw.checked);
        if (nodePrev) nodePrev.innerHTML = html;
        if (node) node.classList.toggle('is-off', !sw.checked);
        if (nodeState) nodeState.textContent = sw.checked ? 'On' : 'Off';
      };
      ta.addEventListener('input', update);
      sw.addEventListener('change', update);
      ed.querySelectorAll('[data-var]').forEach(function (b) {
        b.addEventListener('click', function () {
          var v = b.getAttribute('data-var'), s = ta.selectionStart || ta.value.length, e = ta.selectionEnd || s;
          ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
          ta.focus(); ta.selectionStart = ta.selectionEnd = s + v.length; update();
        });
      });
      ed.querySelector('[data-reset]').addEventListener('click', function () { ta.value = ta.getAttribute('data-default'); update(); ta.focus(); });
      update();
    });

    /* inspector */
    var current = null;
    function openNode(key) {
      root.querySelectorAll('[data-editor]').forEach(function (e) { e.hidden = e.getAttribute('data-editor') !== key; });
      root.querySelectorAll('.ef-node').forEach(function (n) { n.classList.toggle('is-sel', n.getAttribute('data-node') === key); });
      // Narrow screens: give the inspector room while it is open
      if (view.clientWidth < 900) view.style.height = Math.max(view.clientHeight, 600) + 'px';
      insp.classList.add('is-open'); current = key; drawEdges();
    }
    function closeInsp() {
      insp.classList.remove('is-open'); current = null;
      if (view.clientWidth < 900) view.style.height = Math.max(340, Math.round(H * scale + 24)) + 'px';
      root.querySelectorAll('.ef-node').forEach(function (n) { n.classList.remove('is-sel'); }); drawEdges();
    }
    root.querySelectorAll('.ef-node[data-edit], .ef-node[data-info]').forEach(function (n) {
      var key = n.getAttribute('data-edit') || n.getAttribute('data-info');
      n.addEventListener('click', function () { if (!moved) openNode(key); });
      n.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openNode(key); } });
    });
    root.querySelectorAll('[data-ef-close]').forEach(function (b) { b.addEventListener('click', closeInsp); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current) closeInsp(); });

    /* connectors: bezier from an output port to the next node's input port (world coordinates) */
    function portXY(el) {
      var r = el.getBoundingClientRect(), w = world.getBoundingClientRect();
      return [(r.left + r.width / 2 - w.left) / scale, (r.top + r.height / 2 - w.top) / scale];
    }
    function drawEdges() {
      if (!world.offsetWidth) return;
      var NS = 'http://www.w3.org/2000/svg', html = [];
      edgesSvg.innerHTML = '';
      EDGES.forEach(function (e, i) {
        var from = root.querySelector('.ef-node[data-node="' + e[0] + '"] [data-port="' + e[1] + '"]');
        var to = root.querySelector('.ef-node[data-node="' + e[2] + '"] .ef-port.in');
        if (!from || !to) return;
        var a = portXY(from), b = portXY(to), dx = Math.max(50, (b[0] - a[0]) / 2);
        var d = 'M' + a[0] + ' ' + a[1] + ' C' + (a[0] + dx) + ' ' + a[1] + ' ' + (b[0] - dx) + ' ' + b[1] + ' ' + b[0] + ' ' + b[1];
        var hot = current && (e[0] === current || e[2] === current);
        var p = document.createElementNS(NS, 'path');
        p.setAttribute('d', d); p.setAttribute('class', 'ef-edge' + (hot ? ' is-hot' : ''));
        edgesSvg.appendChild(p);
        // a small dot travelling along each connector shows the direction of the flow
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          var dot = document.createElementNS(NS, 'circle');
          dot.setAttribute('r', '3.5'); dot.setAttribute('class', 'ef-flowdot');
          var mv = document.createElementNS(NS, 'animateMotion');
          mv.setAttribute('path', d); mv.setAttribute('dur', '2.6s'); mv.setAttribute('begin', (i * 0.25) + 's'); mv.setAttribute('repeatCount', 'indefinite');
          dot.appendChild(mv); edgesSvg.appendChild(dot);
        }
      });
    }

    /* zoom: fit to the visible width by default */
    function applyScale(s) {
      scale = Math.max(0.4, Math.min(1.2, s));
      world.style.transform = 'scale(' + scale + ')';
      world.parentNode.style.setProperty('--ef-scale', scale);
      // keep scroll size equal to the scaled world
      world.style.marginRight = (W * scale - W) + 'px';
      world.style.marginBottom = (H * scale - H) + 'px';
      drawEdges();
    }
    function fit() {
      if (!view.clientWidth) return;
      // Fit the width, then size the canvas to the flow so there is no empty band below it
      applyScale(Math.min((view.clientWidth - 24) / W, 1));
      view.style.height = Math.max(340, Math.round(H * scale + 24)) + "px";
      view.scrollLeft = 0; view.scrollTop = 0; fitted = true;
    }
    root.querySelectorAll('[data-ef-zoom]').forEach(function (b) {
      b.addEventListener('click', function () {
        var z = +b.getAttribute('data-ef-zoom');
        if (z === 0) fit(); else applyScale(scale + z * 0.1);
      });
    });
    if ('ResizeObserver' in window) {
      new ResizeObserver(function () { if (view.clientWidth) { if (!fitted) fit(); else drawEdges(); } }).observe(view);
    } else {
      window.addEventListener('resize', function () { fit(); });
    }

    /* drag the background to pan, like the Flow Builder */
    var moved = false, pan = null;
    view.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 || e.target.closest('.ef-node, .ef-insp')) return;
      pan = { x: e.clientX, y: e.clientY, l: view.scrollLeft, t: view.scrollTop }; moved = false;
      view.classList.add('is-pan');
    });
    window.addEventListener('pointermove', function (e) {
      if (!pan) return;
      if (Math.abs(e.clientX - pan.x) + Math.abs(e.clientY - pan.y) > 4) moved = true;
      view.scrollLeft = pan.l - (e.clientX - pan.x); view.scrollTop = pan.t - (e.clientY - pan.y);
    });
    window.addEventListener('pointerup', function () { if (pan) { pan = null; view.classList.remove('is-pan'); setTimeout(function () { moved = false; }, 0); } });

    window.efLayout = function () { fitted = false; fit(); };
  })();

  /* Tabs */
  var tabs = document.querySelectorAll('.cr-tab');
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      tabs.forEach(function (o) { o.classList.toggle('is-on', o === t); o.setAttribute('aria-selected', o === t ? 'true' : 'false'); });
      document.querySelectorAll('.cr-panel').forEach(function (p) { p.hidden = p.id !== 'panel_' + t.getAttribute('data-tab'); });
    });
  });

  /* Rings: grow when they scroll into view */
  var rings = document.querySelector('[data-cr-rings]');
  if (rings && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    rings.classList.add('is-armed');
    new IntersectionObserver(function (es, ob) {
      if (es[0].isIntersecting) { requestAnimationFrame(function () { rings.classList.add('is-in'); }); ob.disconnect(); }
    }, { threshold: 0.3 }).observe(rings);
  }

  /* Daily chart */
  var el = document.getElementById('crDaily');
  if (!el || typeof Chart === 'undefined') return;
  var data = <?php echo json_encode(array_values($chartDays)); ?>;
  var labels = <?php echo json_encode(array_map(function ($d) { return date('d M', strtotime($d)); }, array_keys($chartDays))); ?>;
  var pick = function (k) { return data.map(function (d) { return d[k]; }); };
  var ds = function (label, key, color) {
    return { label: label, data: pick(key), backgroundColor: color, borderRadius: 4, borderSkipped: false, maxBarThickness: 26, categoryPercentage: 0.66 };
  };
  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: { labels: labels, datasets: [
      ds('Catalog views', 'catalog_view', '<?php echo $C['catalog_view']; ?>'),
      ds('Enquiries', 'enquire', '<?php echo $C['enquire']; ?>'),
      ds('Pay Now', 'pay_now', '<?php echo $C['pay_now']; ?>')
    ] },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { intersect: false, mode: 'index' },
      animation: { duration: 900, easing: 'easeOutQuart', delay: function (c) { return c.type === 'data' && c.mode === 'default' ? c.dataIndex * 35 + c.datasetIndex * 90 : 0; } },
      plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: '#FFFFFF', borderColor: '#E3EBE8', borderWidth: 1, titleColor: '#0F2622', bodyColor: '#4A5B55', titleFont: { weight: '700' }, padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true }
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { color: '#8A9A95', font: { size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
        y: { stacked: true, beginAtZero: true, suggestedMax: 4, grid: { color: '#EEF2F0' }, border: { display: false }, ticks: { color: '#8A9A95', font: { size: 11 }, precision: 0, maxTicksLimit: 5, padding: 8 } }
      }
    }
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
