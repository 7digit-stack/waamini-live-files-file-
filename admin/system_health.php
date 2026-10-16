<?php
include '../includes/auth_check.php';
include '../db.php';
require_once '../includes/server_health.php';

$pageTitle = 'System Health';
$currentPage = 'system_health';

// Server health
$health = getServerHealth($conn);

// DB stats
$totalCompanies  = $conn->query("SELECT COUNT(*) as c FROM companies")->fetch_assoc()['c'];
$activeCompanies = $conn->query("SELECT COUNT(*) as c FROM companies WHERE status='active'")->fetch_assoc()['c'];
$totalContacts   = $conn->query("SELECT COUNT(*) as c FROM contacts")->fetch_assoc()['c'];
$totalMessages   = $conn->query("SELECT COUNT(*) as c FROM messages")->fetch_assoc()['c'];
$totalCampaigns  = $conn->query("SELECT COUNT(*) as c FROM campaigns")->fetch_assoc()['c'];
$queuePending    = $conn->query("SELECT COUNT(*) as c FROM campaign_queue WHERE status='pending'")->fetch_assoc()['c'];
$queueFailed     = $conn->query("SELECT COUNT(*) as c FROM campaign_queue WHERE status='failed'")->fetch_assoc()['c'];

// DB size
$dbSizeRes = $conn->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
$dbSizeMB  = $dbSizeRes ? $dbSizeRes->fetch_assoc()['size_mb'] : 'N/A';

// Recent errors
$stmt_err = $conn->prepare("SELECT e.*, c.name as company_name FROM system_errors e LEFT JOIN companies c ON e.company_id = c.id ORDER BY e.created_at DESC LIMIT 10");
$stmt_err->execute();
$recentErrors = $stmt_err->get_result();

// Cron last run times
$cronKeys = ['cron_campaign_last_run','cron_automation_last_run','cron_drip_last_run','cron_reminder_last_run','cron_eod_last_run','cron_pulse_last_run'];
$cronTimes = [];
foreach ($cronKeys as $key) {
    $stmt_cr = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
    $stmt_cr->bind_param("s", $key);
    $stmt_cr->execute();
    $row = $stmt_cr->get_result()->fetch_assoc();
    $cronTimes[$key] = $row['setting_value'] ?? null;
}

// Active subscriptions breakdown
$subStats = $conn->query("SELECT status, COUNT(*) as c FROM subscriptions GROUP BY status");

include '../includes/admin_header.php';
?>

<div class="page-header">
    <div class="header-content">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg> System Health</h2>
        <p style="color:var(--text-muted);font-size:14px;margin-top:4px;">Live server status, database stats, and cron monitoring.</p>
    </div>
    <button id="refreshPageBtn" class="btn btn-edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Refresh</button>
</div>

<!-- Server Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
    <?php
    $cards = [
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>', 'PHP Version', $health['php_version'], '#6c63ff'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>', 'MySQL', $health['mysql_status'], '#00b894'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>', 'PHP Memory', $health['php_memory'], '#fdcb6e'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="2"/></svg>', 'Disk Used', $health['disk_used'] . ' / ' . $health['disk_total'], $health['disk_pct'] > 80 ? '#e74c3c' : '#00b894'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>', 'Server RAM', $health['server_ram'], '#74b9ff'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>', 'CPU Load', $health['server_cpu'], '#a29bfe'],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>', 'DB Size', $dbSizeMB . ' MB', '#fd79a8'],
    ];
    foreach ($cards as [$icon, $label, $val, $color]):
    ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;">
        <div style="font-size:22px;margin-bottom:6px;"><?php echo $icon; ?></div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:4px;"><?php echo $label; ?></div>
        <div style="font-size:15px;font-weight:700;color:var(--text-primary);"><?php echo $val; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- DB Counts -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
    <?php
    $dbcards = [
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9.5 8h5"/><path d="M9.5 12H16"/><path d="M9.5 16H14"/></svg>', 'Companies', "$activeCompanies / $totalCompanies active"],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>', 'Contacts', number_format($totalContacts)],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>', 'Messages Sent', number_format($totalMessages)],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>', 'Campaigns', number_format($totalCampaigns)],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', 'Queue Pending', number_format($queuePending), $queuePending > 1000 ? '#e74c3c' : null],
        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>', 'Queue Failed', number_format($queueFailed), $queueFailed > 0 ? '#e74c3c' : null],
    ];
    foreach ($dbcards as $row):
        $icon  = $row[0] ?? '';
        $label = $row[1] ?? '';
        $val   = $row[2] ?? '';
        $warn  = $row[3] ?? null;
    ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;">
        <div style="font-size:20px;margin-bottom:4px;"><?php echo $icon; ?></div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:4px;"><?php echo $label; ?></div>
        <div style="font-size:18px;font-weight:700;color:<?php echo $warn ?? 'var(--text-primary)'; ?>;"><?php echo $val; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Cron Status -->
<div class="form-card" style="max-width:none;margin-bottom:24px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Cron Job Last Run</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <?php
        $cronLabels = [
            'cron_campaign_last_run'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg> Campaign Sender',
            'cron_automation_last_run' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg> Automation Sender',
            'cron_drip_last_run'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg> Drip Cron',
            'cron_reminder_last_run'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg> Subscription Reminder',
            'cron_eod_last_run'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg> EOD Sheets Sync',
            'cron_pulse_last_run'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.859a10 10 0 0 1 14 0"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/></svg> Pulse Analyzer',
        ];
        foreach ($cronLabels as $key => $label):
            $lastRun = $cronTimes[$key] ?? null;
            $ago = $lastRun ? human_time_diff($lastRun) : null;
            $isStale = $lastRun ? (time() - strtotime($lastRun)) > 3600 : true;
        ?>
        <div style="background:var(--bg-input);border-radius:8px;padding:12px;border-left:3px solid <?php echo $isStale ? '#e74c3c' : '#00b894'; ?>;">
            <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:4px;"><?php echo $label; ?></div>
            <?php if ($lastRun): ?>
            <div style="font-size:12px;color:<?php echo $isStale ? '#e74c3c' : '#00b894'; ?>;"><?php echo $ago; ?> ago</div>
            <div style="font-size:10px;color:var(--text-muted);"><?php echo $lastRun; ?></div>
            <?php else: ?>
            <div style="font-size:12px;color:#e74c3c;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:3px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Never run</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Recent Errors -->
<div class="form-card" style="max-width:none;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Recent Errors (last 10)</h3>
    <?php if ($recentErrors && $recentErrors->num_rows > 0): ?>
    <div class="data-table-wrapper">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Company</th><th>Type</th><th>Message</th></tr></thead>
            <tbody>
            <?php while ($e = $recentErrors->fetch_assoc()): ?>
                <tr>
                    <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?php echo $e['created_at']; ?></td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($e['company_name'] ?? '—'); ?></td>
                    <td><span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(231,76,60,0.1);color:#e74c3c;"><?php echo htmlspecialchars($e['error_type'] ?? 'error'); ?></span></td>
                    <td style="font-size:12px;color:var(--text-secondary);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($e['message'] ?? ''); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:32px;color:var(--text-muted);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> No errors logged. System looks healthy!</div>
    <?php endif; ?>
</div>

<?php
function human_time_diff($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return $diff . 's';
    if ($diff < 3600) return round($diff/60) . 'm';
    if ($diff < 86400) return round($diff/3600) . 'h';
    return round($diff/86400) . 'd';
}
?>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Refresh button (CSP-safe, replaces inline onclick="location.reload()")
var refreshBtn = document.getElementById('refreshPageBtn');
if (refreshBtn) refreshBtn.addEventListener('click', function() { location.reload(); });
</script>

<?php include '../includes/admin_footer.php'; ?>
