<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Revenue Analytics';
$currentPage = 'analytics_revenue';

// Add campaign_id to ecommerce_orders if missing
// Migration handled schema change
// Migration handled schema change

// Revenue by campaign (last 30 days)
$campaignRevenue = null;
$stmt_rc = $conn->prepare("
    SELECT c.name as campaign_name, c.id,
           COUNT(o.id) as orders,
           SUM(o.amount) as revenue,
           c.total_recipients
    FROM campaigns c
    LEFT JOIN ecommerce_orders o ON o.source_campaign_id=c.id AND o.status='paid'
    WHERE c.company_id=?
    GROUP BY c.id ORDER BY revenue DESC LIMIT 10
");
if ($stmt_rc) {
    $stmt_rc->bind_param("i", $companyId);
    $stmt_rc->execute();
    $campaignRevenue = $stmt_rc->get_result();
} else {
    error_log('[WAAMINI] analytics_revenue.php: prepare() failed for campaign revenue');
}

// Total revenue stats
$totals = ['today' => 0, 'this_month' => 0, 'total' => 0, 'total_orders' => 0, 'avg_order' => 0];
$stmt_tot = $conn->prepare("SELECT
    SUM(CASE WHEN DATE(created_at)=CURDATE() THEN amount ELSE 0 END) as today,
    SUM(CASE WHEN MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) THEN amount ELSE 0 END) as this_month,
    SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as total,
    COUNT(CASE WHEN status='paid' THEN 1 END) as total_orders,
    AVG(CASE WHEN status='paid' THEN amount END) as avg_order
    FROM ecommerce_orders WHERE company_id=?");
if ($stmt_tot) {
    $stmt_tot->bind_param("i", $companyId);
    $stmt_tot->execute();
    $totals = $stmt_tot->get_result()->fetch_assoc() ?: $totals;
} else {
    error_log('[WAAMINI] analytics_revenue.php: prepare() failed for totals');
}

// Revenue last 30 days chart
$revLabels = $revData = [];
for ($i=29; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $revLabels[] = date('d M', strtotime("-$i days"));
    $stmt_rv = $conn->prepare("SELECT SUM(amount) as r FROM ecommerce_orders WHERE company_id=? AND status='paid' AND DATE(created_at)=?");
    if ($stmt_rv) {
        $stmt_rv->bind_param("is", $companyId, $d);
        $stmt_rv->execute();
        $revData[] = floatval($stmt_rv->get_result()->fetch_assoc()['r'] ?? 0);
    } else {
        $revData[] = 0;
    }
}

include '../includes/client_header.php';
?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Revenue Attribution</h2></div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
<?php foreach ([
    ['₹'.number_format($totals['today']??0),'Today','#00b894'],
    ['₹'.number_format($totals['this_month']??0),'This Month','#6c63ff'],
    ['₹'.number_format($totals['total']??0),'All Time','#e17055'],
    [number_format($totals['total_orders']??0),'Total Orders','#74b9ff'],
    ['₹'.number_format($totals['avg_order']??0),'Avg Order','#fd79a8'],
] as [$val,$label,$color]): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;">
    <div style="font-size:22px;font-weight:800;color:<?php echo $color; ?>;"><?php echo $val; ?></div>
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-top:4px;"><?php echo $label; ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Revenue Chart -->
<div class="form-card" style="max-width:none;margin-bottom:24px;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg> Revenue — Last 30 Days</h3>
    <canvas id="revChart" height="80"></canvas>
</div>

<!-- Campaign Attribution -->
<div class="form-card" style="max-width:none;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Revenue by Campaign</h3>
    <div class="data-table-wrapper">
    <table class="data-table">
        <thead><tr><th>Campaign</th><th>Recipients</th><th>Orders</th><th>Revenue</th><th>Conversion</th></tr></thead>
        <tbody>
        <?php if (!$campaignRevenue || $campaignRevenue->num_rows === 0): ?>
            <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted);">No revenue data yet.</td></tr>
        <?php else: while ($cr = $campaignRevenue->fetch_assoc()):
            $conv = $cr['total_recipients'] > 0 ? round($cr['orders']/$cr['total_recipients']*100,1) : 0;
        ?>
        <tr>
            <td style="font-weight:600;"><?php echo htmlspecialchars($cr['campaign_name']); ?></td>
            <td><?php echo number_format($cr['total_recipients']); ?></td>
            <td><strong><?php echo $cr['orders']; ?></strong></td>
            <td style="color:var(--accent);font-weight:700;">₹<?php echo number_format($cr['revenue']??0); ?></td>
            <td><span style="padding:3px 8px;border-radius:20px;font-size:11px;background:rgba(0,184,148,0.1);color:var(--accent);"><?php echo $conv; ?>%</span></td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('revChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($revLabels); ?>,
        datasets: [{
            label: 'Revenue (₹)',
            data: <?php echo json_encode($revData); ?>,
            borderColor: '#00b894',
            backgroundColor: 'rgba(0,184,148,0.1)',
            fill: true, tension: 0.4, pointRadius: 2
        }]
    },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
</script>
<?php include '../includes/client_footer.php'; ?>
