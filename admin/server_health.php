<?php
include '../includes/auth_check.php';
include '../db.php';
require_once '../includes/server_health.php';

$pageTitle = 'Server Health';
$currentPage = 'server_health';

$health = getServerHealth($conn);

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg> Server Health</h2>
    <a href="system_health.php" class="btn btn-sm btn-edit">↩ System Health</a>
</div>

<div class="form-card" style="max-width:none;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
        <div class="stat-card">
            <div class="stat-label">PHP Version</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)($health['php_version'] ?? '')); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">MySQL</div>
            <div class="stat-value"><?php echo $health['mysql_status'] ?? ''; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">PHP Memory</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)($health['php_memory'] ?? '')); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Disk</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)($health['disk_used'] ?? '')); ?> / <?php echo htmlspecialchars((string)($health['disk_total'] ?? '')); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Server RAM</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)($health['server_ram'] ?? '')); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">CPU</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)($health['server_cpu'] ?? '')); ?></div>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>

