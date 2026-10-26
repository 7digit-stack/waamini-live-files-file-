<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('sales_tracker')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
$pageTitle = 'Sales Tracker';
$currentPage = 'settings_sales_tracker';

// Handle save
if (isset($_POST['save'])) {
    csrf_verify();
    $active = isset($_POST['is_active']) ? 1 : 0;
    $sheetUrl = trim(sanitize($_POST['google_sheet_url'] ?? ''));

    $stmt = $conn->prepare("INSERT INTO sales_tracker_settings (company_id, is_active, google_sheet_url)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), google_sheet_url=VALUES(google_sheet_url)");
    $stmt->bind_param("iis", $companyId, $active, $sheetUrl);
    $stmt->execute();
    header("Location: settings_sales_tracker.php?msg=saved");
    exit();
}

// Also update google_sheet_webhook in companies table (backward compat)
if (isset($_POST['save'])) {
    $sheetUrl = trim(sanitize($_POST['google_sheet_url'] ?? ''));
    $stmt2 = $conn->prepare("UPDATE companies SET google_sheet_webhook=? WHERE id=?");
    $stmt2->bind_param("si", $sheetUrl, $companyId);
    $stmt2->execute();
}

// Auto-create tables if missing (safe for first-time load)
$conn->query("CREATE TABLE IF NOT EXISTS `sales_tracker_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `company_id` INT(11) NOT NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `google_sheet_url` VARCHAR(500) DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), UNIQUE KEY `uq_company` (`company_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS `sales_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT, `company_id` INT(11) NOT NULL, `user_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL, `item_name` VARCHAR(255) NOT NULL, `quantity` INT(11) NOT NULL DEFAULT 1,
    `price` DECIMAL(10,2) DEFAULT NULL, `details` TEXT DEFAULT NULL, `phone` VARCHAR(50) DEFAULT NULL,
    `synced_to_google` TINYINT(1) DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `idx_company_date` (`company_id`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure price column exists (for tables created before upgrade)
$conn->query("ALTER TABLE sales_log ADD COLUMN price DECIMAL(10,2) DEFAULT NULL AFTER quantity");
$conn->query("ALTER TABLE sales_log ADD COLUMN user_id INT(11) DEFAULT NULL AFTER company_id");
$conn->query("ALTER TABLE sales_log ADD COLUMN synced_to_google TINYINT(1) DEFAULT 0 AFTER phone");

// Load current settings
$s = [];
$stmt_s = $conn->prepare("SELECT * FROM sales_tracker_settings WHERE company_id=?");
if ($stmt_s) { $stmt_s->bind_param("i", $companyId); $stmt_s->execute(); $s = $stmt_s->get_result()->fetch_assoc() ?: []; }

// Stats — check if price column exists to avoid errors
$hasPriceCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM sales_log LIKE 'price'");
if ($colCheck && $colCheck->num_rows > 0) $hasPriceCol = true;

$today = ['sales_count' => 0, 'total_qty' => 0, 'total_rev' => 0];
$revExpr = $hasPriceCol ? "COALESCE(SUM(IFNULL(price,0)*quantity),0)" : "0";
$stmt_today = $conn->prepare("SELECT COUNT(*) as sales_count, COALESCE(SUM(quantity),0) as total_qty, $revExpr as total_rev FROM sales_log WHERE company_id=? AND DATE(created_at)=CURDATE()");
if ($stmt_today) { $stmt_today->bind_param("i", $companyId); $stmt_today->execute(); $today = $stmt_today->get_result()->fetch_assoc() ?: $today; }

$month = ['sales_count' => 0, 'total_qty' => 0, 'total_rev' => 0];
$stmt_month = $conn->prepare("SELECT COUNT(*) as sales_count, COALESCE(SUM(quantity),0) as total_qty, $revExpr as total_rev FROM sales_log WHERE company_id=? AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
if ($stmt_month) { $stmt_month->bind_param("i", $companyId); $stmt_month->execute(); $month = $stmt_month->get_result()->fetch_assoc() ?: $month; }

// Recent sales (last 20)
$selectCols = $hasPriceCol ? "item_name, quantity, price, phone, created_at" : "item_name, quantity, NULL as price, phone, created_at";
$stmt_recent = $conn->prepare("SELECT $selectCols FROM sales_log WHERE company_id=? ORDER BY id DESC LIMIT 20");
$recentSales = null;
if ($stmt_recent) { $stmt_recent->bind_param("i", $companyId); $stmt_recent->execute(); $recentSales = $stmt_recent->get_result(); }

// Team members for auth info
$stmt_team = $conn->prepare("SELECT name, mobile FROM users WHERE company_id=? AND status='active' AND mobile IS NOT NULL AND mobile != '' LIMIT 10");
$teamMembers = null;
if ($stmt_team) { $stmt_team->bind_param("i", $companyId); $stmt_team->execute(); $teamMembers = $stmt_team->get_result(); }

$lI = static function (string $p, int $sz = 20): string {
    return '<svg class="lI" width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$trackerOn = (int)($s['is_active'] ?? 1) === 1;
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>', 25); ?></div>
        <div>
            <h1>WhatsApp Sales Tracker</h1>
            <p>Log sales by sending a quick WhatsApp message, and see them sync to your Google Sheet.</p>
        </div>
    </div>
    <span class="su-pill <?php echo $trackerOn ? 'on' : ''; ?>"><i></i> <?php echo $trackerOn ? 'Tracker is on' : 'Tracker is off'; ?></span>
</div>

<?php if (isset($_GET['msg'])): ?><div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> Settings saved.</div><?php endif; ?>

<div class="stk-kpis">
    <div class="stk-kpi"><div class="i"><?php echo $lI('<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'); ?></div><div><b><?php echo (int)$today['total_qty']; ?></b><span>Units sold today</span></div></div>
    <div class="stk-kpi amber"><div class="i"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></div><div><b>₹<?php echo number_format((float)$today['total_rev'], 0); ?></b><span>Revenue today</span></div></div>
    <div class="stk-kpi blue"><div class="i"><?php echo $lI('<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>'); ?></div><div><b><?php echo (int)$month['total_qty']; ?></b><span>Units this month</span></div></div>
    <div class="stk-kpi purple"><div class="i"><?php echo $lI('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'); ?></div><div><b>₹<?php echo number_format((float)$month['total_rev'], 0); ?></b><span>Revenue this month</span></div></div>
</div>

<div class="stk-grid">

<div class="stk-col">
    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>'); ?></span> Settings</h2>
        <form method="POST" style="display:flex;flex-direction:column;gap:16px;">
            <?php echo csrf_field(); ?>
            <label class="su-row" for="st_active" style="cursor:pointer">
                <span class="su-row-t"><strong>Enable Sales Tracker</strong><span>Turn on to log sales sent by your team on WhatsApp.</span></span>
                <span class="su-sw"><input type="checkbox" name="is_active" id="st_active" <?php echo $trackerOn ? 'checked' : ''; ?>><i></i></span>
            </label>
            <div class="su-f">
                <label class="l" for="st_url">Google Apps Script Web App URL</label>
                <input type="text" id="st_url" name="google_sheet_url" value="<?php echo htmlspecialchars($s['google_sheet_url'] ?? ''); ?>" placeholder="https://script.google.com/macros/s/XXXX/exec">
                <span class="hint">Deploy your Apps Script as a Web App and paste the URL here.</span>
            </div>
            <button type="submit" name="save" class="su-btn" style="align-self:flex-start"><?php echo $lI('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?> Save settings</button>
        </form>
    </section>

    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'); ?></span> How to use (WhatsApp commands)</h2>
        <div class="su-sec">
            <div class="su-sec-h" style="margin-bottom:8px">Log a sale</div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <div class="stk-ex">Saree 5</div><div class="stk-ex">Saree 5 1500</div><div class="stk-ex">Saree, 5, 1500</div>
            </div>
        </div>
        <div class="su-fg">
            <div class="su-sec"><div class="su-sec-h" style="margin-bottom:8px">View report</div><div class="stk-ex">Report</div></div>
            <div class="su-sec"><div class="su-sec-h" style="margin-bottom:8px">Undo last sale</div><div class="stk-ex">Undo</div></div>
        </div>
    </section>

    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></span> Authorized team members</h2>
        <p class="sub">Only these people can log sales through WhatsApp.</p>
        <div class="su-rows">
            <?php $tmCount = 0; while ($teamMembers && $tm = $teamMembers->fetch_assoc()): $tmCount++; ?>
            <div class="su-row"><span class="su-row-t"><strong><?php echo htmlspecialchars((string)$tm['name']); ?></strong><span><?php echo htmlspecialchars((string)$tm['mobile']); ?></span></span><span class="su-tag green">Allowed</span></div>
            <?php endwhile; ?>
            <?php if ($tmCount === 0): ?><div class="su-empty"><p>No team members yet.</p></div><?php endif; ?>
        </div>
        <span class="hint" style="font-size:12.5px;color:#586a63">Add team members in Settings → Team to authorize more people.</span>
    </section>
</div>

<div class="stk-col">
    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>'); ?></span> Recent sales</h2>
        <?php if (!$recentSales || $recentSales->num_rows === 0): ?>
            <div class="su-empty">
                <div class="e-i"><?php echo $lI('<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>', 28); ?></div>
                <h3>No sales recorded yet</h3>
                <p>Send a message like <strong>"Saree 5 1500"</strong> on WhatsApp to start.</p>
            </div>
        <?php else: ?>
            <div class="su-tbl-wrap">
            <table class="su-tbl">
                <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($sale = $recentSales->fetch_assoc()): ?>
                    <tr>
                        <td><span class="nm"><?php echo htmlspecialchars(ucfirst((string)$sale['item_name'])); ?></span></td>
                        <td><?php echo (int)$sale['quantity']; ?></td>
                        <td><?php echo $sale['price'] ? '₹' . number_format((float)$sale['price'], 0) : '—'; ?></td>
                        <td style="white-space:nowrap"><small><?php echo date('d M h:i A', strtotime((string)$sale['created_at'])); ?></small></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="su-card">
        <h2><span class="ch"><?php echo $lI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'); ?></span> Security</h2>
        <ul class="su-list">
            <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> Only team members in your Users table can log sales</li>
            <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> Customer messages are never treated as sales commands</li>
            <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> Sales sync to Google Sheets in under a second</li>
            <li><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 15); ?> The AI bot is silenced while a sale is being logged</li>
        </ul>
    </section>
</div>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
