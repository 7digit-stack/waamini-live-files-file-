<?php
include '../includes/client_auth_check.php';

$pageTitle = 'Billing & Payments Dashboard';
$currentPage = 'billing';

$companyId = (int)$companyId;

// 1. Calculate E-commerce Revenue (Native Checkout)
$revenue = ['daily' => 0.0, 'monthly' => 0.0, 'total' => 0.0];
$revStmt = $conn->prepare("SELECT
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as daily,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN amount ELSE 0 END) as monthly,
    SUM(amount) as total
    FROM ecommerce_orders
    WHERE company_id = ? AND status = 'paid'");
if ($revStmt) {
    $revStmt->bind_param('i', $companyId);
    if ($revStmt->execute()) {
        $row = $revStmt->get_result()->fetch_assoc();
        if (is_array($row)) {
            $revenue['daily'] = (float)($row['daily'] ?? 0);
            $revenue['monthly'] = (float)($row['monthly'] ?? 0);
            $revenue['total'] = (float)($row['total'] ?? 0);
        }
    }
}

// 2. Fetch Recent Orders (LEFT JOIN so rows without a matching contact still appear)
$recentOrders = [];
$orderStmt = $conn->prepare(
    'SELECT o.*, COALESCE(NULLIF(TRIM(c.name), \'\'), \'Customer\') AS name, COALESCE(c.phone, \'\') AS phone
     FROM ecommerce_orders o
     LEFT JOIN contacts c ON o.contact_id = c.id AND c.company_id = o.company_id
     WHERE o.company_id = ?
     ORDER BY o.created_at DESC LIMIT 5'
);
if ($orderStmt) {
    $orderStmt->bind_param('i', $companyId);
    if ($orderStmt->execute()) {
        $rs = $orderStmt->get_result();
        while ($rs && ($o = $rs->fetch_assoc())) { $recentOrders[] = $o; }
    }
}

// 3. Meta API spending estimate (illustrative — not Meta’s real invoice)
$mStats = ['total_out' => 0];
$metaStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_out FROM messages
     WHERE company_id = ? AND direction = 'outgoing' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
if ($metaStmt) {
    $metaStmt->bind_param('i', $companyId);
    if ($metaStmt->execute()) {
        $mr = $metaStmt->get_result()->fetch_assoc();
        if (is_array($mr)) {
            $mStats['total_out'] = (int)($mr['total_out'] ?? 0);
        }
    }
}

$totalOut = (int)($mStats['total_out'] ?? 0);
// Simplified illustrative rate (not conversation-category aware)
$estSpend = $totalOut * 0.85;

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$ext = $lI('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>', 15);
$statusTag = ['paid' => 'green', 'pending' => 'amber', 'failed' => 'red', 'refunded' => 'purple', 'shipped' => 'blue', 'delivered' => 'green', 'processing' => 'purple'];

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
<style>
.bl-links { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
.bl-link { display:flex; align-items:center; gap:12px; background:#fff; border-radius:16px; padding:14px 16px; text-decoration:none; color:var(--ink); box-shadow:0 12px 30px -22px rgba(15,38,34,.45); transition:transform .15s, box-shadow .15s; }
.bl-link:hover { transform:translateY(-2px); box-shadow:0 18px 38px -22px rgba(15,38,34,.5); color:var(--ink); }
.bl-link .i { width:40px; height:40px; border-radius:11px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; flex:none; }
.bl-link strong { display:block; font-size:14.5px; }
.bl-link span { font-size:12.5px; color:var(--mu); display:flex; align-items:center; gap:5px; }
.bl-link.main { background:var(--g); color:#fff; }
.bl-link.main .i { background:rgba(255,255,255,.2); color:#fff; }
.bl-link.main span { color:rgba(255,255,255,.85); }
.bl-link.main:hover { background:var(--gd); color:#fff; }
.bl-big { font-size:36px; font-weight:800; letter-spacing:-.04em; line-height:1.05; color:var(--ink); }
.bl-split { display:flex; flex-direction:column; gap:8px; }
.bl-split div { display:flex; align-items:center; justify-content:space-between; gap:10px; background:var(--soft); border-radius:12px; padding:11px 14px; font-size:14px; color:var(--tx); }
.bl-split b { color:var(--ink); }
.bl-split i { width:9px; height:9px; border-radius:50%; display:inline-block; margin-right:9px; }
.bl-rate { display:flex; align-items:center; justify-content:space-between; gap:10px; background:var(--soft); border-radius:14px; padding:13px 16px; font-size:14px; color:var(--tx); }
.bl-rate b { font-size:15px; color:var(--ink); }
.bl-warn { display:flex; gap:12px; align-items:flex-start; background:#FEF3DC; border-radius:14px; padding:14px 16px; color:#6f4a00; font-size:13.5px; line-height:1.55; }
.bl-warn .lI { margin-top:1px; flex:none; }
@media (max-width:1100px) { .bl-links { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:560px) { .bl-links { grid-template-columns:minmax(0,1fr); } .bl-big { font-size:30px; } }
</style>

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>', 25); ?></div>
        <div>
            <h1>Billing &amp; Payments</h1>
            <p>See what your messages cost on Meta and how much you earn through WhatsApp checkout.</p>
        </div>
    </div>
    <a href="https://business.facebook.com/latest/billing_hub" target="_blank" rel="noopener" class="su-btn soft">Meta Billing Hub <?php echo $ext; ?></a>
</div>

<div class="bl-links">
    <a class="bl-link main" href="settings_whatsapp.php" title="Connect token and phone number ID in WhatsApp settings">
        <div class="i"><?php echo $lI('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'); ?></div>
        <div><strong>WhatsApp connection</strong><span>Open settings</span></div>
    </a>
    <a class="bl-link" href="https://business.facebook.com/wa/manage/phone-numbers" target="_blank" rel="noopener">
        <div class="i"><?php echo $lI('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'); ?></div>
        <div><strong>Phone numbers</strong><span>Manage on Meta <?php echo $ext; ?></span></div>
    </a>
    <a class="bl-link" href="https://business.facebook.com/wa/manage/home" target="_blank" rel="noopener">
        <div class="i"><?php echo $lI('<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'); ?></div>
        <div><strong>WhatsApp Manager</strong><span>Open on Meta <?php echo $ext; ?></span></div>
    </a>
    <a class="bl-link" href="https://business.facebook.com/latest/billing_hub/payment_methods" target="_blank" rel="noopener">
        <div class="i"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'); ?></div>
        <div><strong>Payment methods</strong><span>Manage on Meta <?php echo $ext; ?></span></div>
    </a>
</div>

<div class="su-kpis k3">
    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>'); ?></span> Meta API spend (30 days)</h2>
        <div class="bl-big">₹<?php echo number_format($estSpend, 2); ?></div>
        <p class="sub" style="margin:0">Estimated from <?php echo number_format($totalOut); ?> outgoing messages in the last 30 days.</p>
        <div class="bl-split"><div><span><i style="background:#128C7E"></i>Marketing (estimated)</span><b>₹<?php echo number_format($estSpend * 0.8, 2); ?></b></div></div>
    </section>
    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></span> Revenue this month</h2>
        <div class="bl-big">₹<?php echo number_format($revenue['monthly'], 2); ?></div>
        <p class="sub" style="margin:0">From native WhatsApp checkout.</p>
        <div class="bl-split"><div><span><i style="background:#25D366"></i>Today's sales</span><b>₹<?php echo number_format($revenue['daily'], 2); ?></b></div></div>
    </section>
    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'); ?></span> Lifetime revenue</h2>
        <div class="bl-big">₹<?php echo number_format($revenue['total'], 2); ?></div>
        <p class="sub" style="margin:0">All payments processed through WAAMINI.</p>
        <div class="bl-split"><div><span><i style="background:#7c3aed"></i>Paid orders only</span><b>All time</b></div></div>
    </section>
</div>

<div class="su-grid">

    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'); ?></span> Recent sales
            <a href="orders.php" class="su-btn xs soft" style="margin-left:auto">View all</a></h2>
        <div class="su-tbl-wrap">
        <table class="su-tbl">
            <thead><tr><th>Customer</th><th>Order</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($recentOrders): foreach ($recentOrders as $ord):
                $st = strtolower((string)($ord['status'] ?? ''));
                $ts = strtotime((string)($ord['created_at'] ?? ''));
            ?>
            <tr>
                <td><span class="nm"><?php echo htmlspecialchars((string)($ord['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><br><small><?php echo htmlspecialchars((string)($ord['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td>#<?php echo (int)($ord['id'] ?? 0); ?></td>
                <td><strong>₹<?php echo number_format((float)($ord['amount'] ?? 0), 2); ?></strong></td>
                <td style="white-space:nowrap"><?php echo $ts ? date('d M, H:i', $ts) : '—'; ?></td>
                <td><span class="su-tag <?php echo $statusTag[$st] ?? ''; ?>"><?php echo htmlspecialchars(ucfirst($st), ENT_QUOTES, 'UTF-8'); ?></span></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="su-tbl-empty">No sales recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'); ?></span> Understanding your Meta bill</h2>
        <p class="sub">Meta charges for each 24-hour conversation window. If you use your own card for clients, check this page to bill them correctly.</p>
        <div class="su-rows">
            <div class="bl-rate"><span>Marketing</span><b>about ₹0.85 / conversation</b></div>
            <div class="bl-rate"><span>Utility / OTP</span><b>about ₹0.35 / conversation</b></div>
            <div class="bl-rate"><span>Service</span><b>First 1,000 / month free</b></div>
        </div>
        <div class="bl-warn"><?php echo $lI('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>', 18); ?><span>These are <strong>estimates</strong>. Your real cost can differ with Meta's official currency conversion and GST.</span></div>
    </section>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
