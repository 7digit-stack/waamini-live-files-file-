<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Revenue Intelligence';
$currentPage = 'revenue';

// MRR / ARR Calculations with safe fallback
$revenue = ['mrr' => 0, 'yearly_mrr' => 0, 'active_subs' => 0];
$rRes = $conn->query("SELECT 
    SUM(CASE WHEN billing_cycle='monthly' THEN amount ELSE 0 END) as mrr,
    SUM(CASE WHEN billing_cycle='yearly' THEN amount / 12 ELSE 0 END) as yearly_mrr,
    COUNT(*) as active_subs
    FROM subscriptions WHERE status='active'");
if ($rRes) $revenue = $rRes->fetch_assoc();

$mrr = (float)($revenue['mrr'] ?? 0) + (float)($revenue['yearly_mrr'] ?? 0);
$arr = $mrr * 12;

// New Signups This Month
$monthStart = date('Y-m-01');
$newSignups = 0;
$nsRes = $conn->prepare("SELECT COUNT(*) as c FROM companies WHERE created_at >= ?");
if ($nsRes) { $nsRes->bind_param('s', $monthStart); $nsRes->execute(); $nsRow = $nsRes->get_result()->fetch_assoc(); $newSignups = (int)($nsRow['c'] ?? 0); $nsRes->close(); }

// Churn Calculation (Last 30 days)
$thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
$churnCount = 0;
$totalSubsAtStart = 0;
$chStmt = $conn->prepare("SELECT COUNT(*) as c FROM subscriptions WHERE status IN ('expired','cancelled') AND created_at >= ?");
if ($chStmt) { $chStmt->bind_param('s', $thirtyDaysAgo); $chStmt->execute(); $chRow = $chStmt->get_result()->fetch_assoc(); $churnCount = (int)($chRow['c'] ?? 0); $chStmt->close(); }
$tsStmt = $conn->prepare("SELECT COUNT(*) as c FROM subscriptions WHERE status='active' OR (status IN ('expired','cancelled') AND created_at >= ?)");
if ($tsStmt) { $tsStmt->bind_param('s', $thirtyDaysAgo); $tsStmt->execute(); $tsRow = $tsStmt->get_result()->fetch_assoc(); $totalSubsAtStart = (int)($tsRow['c'] ?? 0); $tsStmt->close(); }
$churnRate = ($totalSubsAtStart > 0) ? ($churnCount / $totalSubsAtStart) * 100 : 0;

// Plan Distribution
$plansDist = null;
$pdStmt = $conn->prepare("SELECT sp.name, COUNT(*) as count 
    FROM subscriptions s 
    JOIN subscription_plans sp ON s.plan_id = sp.id 
    WHERE s.status='active' 
    GROUP BY s.plan_id");
if ($pdStmt) { $pdStmt->execute(); $plansDist = $pdStmt->get_result(); }

// Recent Transactions with plan name
$transactions = null;
$txStmt = $conn->prepare("SELECT s.*, c.name as company_name, COALESCE(sp.name, 'N/A') as plan_name 
    FROM subscriptions s 
    JOIN companies c ON s.company_id = c.id 
    LEFT JOIN subscription_plans sp ON s.plan_id = sp.id 
    ORDER BY s.id DESC LIMIT 10");
if ($txStmt) { $txStmt->execute(); $transactions = $txStmt->get_result(); }

include '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h2>Revenue Intelligence</h2>
        <p style="font-size:13px;color:var(--text-muted);margin-top:4px;">Real-time financial tracking, plan performance, and growth trajectory</p>
    </div>
</div>

<!-- KPI Stat Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-label">Monthly Recurring (MRR)</div>
        <div class="stat-value">₹<?php echo number_format($mrr, 2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <svg viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
        </div>
        <div class="stat-label">Annual Projection (ARR)</div>
        <div class="stat-value">₹<?php echo number_format($arr, 2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        </div>
        <div class="stat-label">Active Subscriptions</div>
        <div class="stat-value"><?php echo (int)$revenue['active_subs']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
        </div>
        <div class="stat-label">New Signups (MTD)</div>
        <div class="stat-value">+<?php echo (int)$newSignups; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        </div>
        <div class="stat-label">Churn Rate (30d)</div>
        <div class="stat-value"><?php echo number_format($churnRate, 1); ?>%</div>
    </div>
</div>

<!-- Main Content Grid -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

    <!-- Recent Revenue Events -->
    <div class="data-table-wrapper">
        <div class="table-header">
            <h3>Recent Revenue Events</h3>
        </div>
        <div style="overflow-x:auto;">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Cycle</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions && $transactions->num_rows > 0): ?>
                        <?php while ($row = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['company_name'] ?? '—'); ?></strong></td>
                            <td><span style="font-weight:600;color:var(--accent);"><?php echo htmlspecialchars($row['plan_name']); ?></span></td>
                            <td><strong>₹<?php echo number_format((float)$row['amount'], 2); ?></strong></td>
                            <td style="text-transform:uppercase;font-size:11px;font-weight:600;color:var(--text-muted);"><?php echo htmlspecialchars($row['billing_cycle'] ?? '—'); ?></td>
                            <td>
                                <?php if (($row['status'] ?? '') === 'active'): ?>
                                <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                <span class="badge badge-suspended"><?php echo strtoupper(htmlspecialchars($row['status'] ?? 'UNKNOWN')); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">No subscription transactions recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Plan Distribution -->
    <div class="chart-wrapper">
        <div class="chart-header" style="margin-bottom:24px;">
            <h3>Plan Distribution</h3>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px;">
            <?php if ($plansDist && $plansDist->num_rows > 0): ?>
                <?php while ($p = $plansDist->fetch_assoc()):
                    $pct = ((int)$revenue['active_subs'] > 0) ? ((int)$p['count'] / (int)$revenue['active_subs']) * 100 : 0;
                ?>
                <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                        <span style="font-weight:600;color:var(--text-primary);"><?php echo htmlspecialchars($p['name'] ?? '—'); ?></span>
                        <span style="color:var(--text-muted);font-weight:600;font-size:13px;"><?php echo (int)$p['count']; ?> users</span>
                    </div>
                    <div style="width:100%;height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
                        <div style="width:<?php echo $pct; ?>%;height:100%;background:var(--accent);border-radius:4px;transition:width 0.4s ease;"></div>
                    </div>
                    <div style="text-align:right;font-size:11px;color:var(--text-dim);margin-top:4px;"><?php echo number_format($pct, 1); ?>%</div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);">No active subscriptions to display.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include '../includes/admin_footer.php'; ?>
