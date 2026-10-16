<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';
include_once '../includes/schema_guard.php';

$pageTitle = 'WAAMINI Pulse — AI Monitor';
$currentPage = 'pulse';
$pulseSelfHealNote = 'Pulse can repair common app-level issues like missing monitoring tables, missing columns, and stuck queues. It cannot fix every possible code bug automatically.';

// Pulse automation defaults (stored in system_settings group=monitoring)
$pulseAutoHealEnabled = 1;
$pulseAutoHealIntervalMinutes = 60;

// Ensure Pulse core tables always exist so this page can self-recover from accidental table drops.
$conn->query("CREATE TABLE IF NOT EXISTS system_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL,
    error_type VARCHAR(50) NULL,
    message TEXT NULL,
    file VARCHAR(255) NULL,
    line INT NULL,
    stack_trace TEXT NULL,
    status ENUM('new','analyzed','fixed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS pulse_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    last_run_at DATETIME NULL,
    last_status VARCHAR(30) DEFAULT NULL,
    last_summary VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uniq_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure generic queue table exists (used by worker/daemon).
$conn->query("CREATE TABLE IF NOT EXISTS queue_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    payload_json JSON DEFAULT NULL,
    status ENUM('pending','processing','retrying','failed','done') NOT NULL DEFAULT 'pending',
    run_at DATETIME DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    last_error TEXT DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    lock_token VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    INDEX idx_status_run (status, run_at),
    INDEX idx_type_status (type, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load Pulse automation settings (+ watchdog status written by cron_pulse_watchdog.php)
$watchdogLastRunAt = null;
$watchdogLastSummary = '';
$settingsRes = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='monitoring' AND setting_key IN ('pulse_auto_heal_enabled','pulse_auto_heal_interval_minutes','watchdog_last_run_at','watchdog_last_summary')");
if ($settingsRes) {
    while ($s = $settingsRes->fetch_assoc()) {
        if (($s['setting_key'] ?? '') === 'pulse_auto_heal_enabled') {
            $pulseAutoHealEnabled = ((string)($s['setting_value'] ?? '1') === '1') ? 1 : 0;
        }
        if (($s['setting_key'] ?? '') === 'pulse_auto_heal_interval_minutes') {
            $pulseAutoHealIntervalMinutes = max(5, min(1440, (int)($s['setting_value'] ?? 60)));
        }
        if (($s['setting_key'] ?? '') === 'watchdog_last_run_at') {
            $watchdogLastRunAt = (string)($s['setting_value'] ?? '') ?: null;
        }
        if (($s['setting_key'] ?? '') === 'watchdog_last_summary') {
            $watchdogLastSummary = (string)($s['setting_value'] ?? '');
        }
    }
}
$watchdogActive = ($watchdogLastRunAt && (time() - strtotime($watchdogLastRunAt)) < 300);

// Helper to run self-heal safely from this page
$waRunPulseHeal = static function (bool $force = false) use ($conn): void {
    if (!defined('WAAMINI_QUEUE_WORKER')) {
        define('WAAMINI_QUEUE_WORKER', true);
    }
    if (!defined('WAAMINI_TRUSTED_INTERNAL_RUN')) {
        define('WAAMINI_TRUSTED_INTERNAL_RUN', true);
    }

    $forceBackup = $_GET['force'] ?? null;
    $keyBackup = $_GET['key'] ?? null;
    if ($force) {
        $_GET['force'] = '1';
    }
    if (defined('CRON_SECRET_KEY') && (string)CRON_SECRET_KEY !== '') {
        $_GET['key'] = (string)CRON_SECRET_KEY;
    }

    ob_start();
    include '../cron_pulse_analyzer.php';
    ob_end_clean();

    if ($forceBackup === null) {
        unset($_GET['force']);
    } else {
        $_GET['force'] = $forceBackup;
    }
    if ($keyBackup === null) {
        unset($_GET['key']);
    } else {
        $_GET['key'] = $keyBackup;
    }
};

// Run one queue worker cycle from web UI (non-dev recovery path).
$waRunQueueWorkerCycle = static function (?string &$workerOutput = null): bool {
    $workerOutput = '';
    $scriptPath = dirname(__DIR__) . '/queue/worker.php';
    if (!is_file($scriptPath)) {
        $workerOutput = 'queue/worker.php not found.';
        return false;
    }

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);

    if (function_exists('proc_open')) {
        $spec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $spec, $pipes, dirname($scriptPath));
        if (is_resource($proc)) {
            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = (int)proc_close($proc);
            $workerOutput = trim($out . "\n" . $err);
            return $code === 0;
        }
    }

    if (function_exists('shell_exec')) {
        $out = @shell_exec($cmd . ' 2>&1');
        $workerOutput = trim((string)$out);
        return $workerOutput !== '';
    }

    $workerOutput = 'proc_open/shell_exec are unavailable on this server.';
    return false;
};

// Save auto-heal scheduler settings
if (isset($_POST['save_pulse_automation'])) {
    csrf_verify();
    $pulseAutoHealEnabled = isset($_POST['pulse_auto_heal_enabled']) ? 1 : 0;
    $pulseAutoHealIntervalMinutes = max(5, min(1440, (int)($_POST['pulse_auto_heal_interval_minutes'] ?? 60)));

    $stSet = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'monitoring') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group='monitoring'");
    if ($stSet) {
        $k1 = 'pulse_auto_heal_enabled';
        $v1 = (string)$pulseAutoHealEnabled;
        $stSet->bind_param('ss', $k1, $v1);
        $stSet->execute();

        $k2 = 'pulse_auto_heal_interval_minutes';
        $v2 = (string)$pulseAutoHealIntervalMinutes;
        $stSet->bind_param('ss', $k2, $v2);
        $stSet->execute();
    }

    $analysisResult = 'Pulse automation settings updated.';
}

// Actions: Mark as fixed or clear all
if (isset($_POST['mark_fixed'])) {
    csrf_verify();
    $errId = intval($_POST['error_id']);
    $stmt = $conn->prepare("UPDATE system_errors SET status='fixed' WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $errId);
        $stmt->execute();
    }
}

if (isset($_POST['analyze_fix'])) {
    csrf_verify();
    $waRunPulseHeal(true);
    $analysisResult = "Self-heal run completed. Common app-level issues were checked and repaired where possible.";
}

if (isset($_POST['emergency_repair'])) {
    csrf_verify();
    $waRunPulseHeal(true);
    $analysisResult = "Emergency repair run completed. Safe schema and queue repairs were executed.";
}

if (isset($_POST['repair_queue_worker'])) {
    csrf_verify();

    // Remove emergency stop flag if it exists so daemon/worker can proceed.
    $stopFile = dirname(__DIR__) . '/storage/STOP_QUEUE_DAEMON';
    if (is_file($stopFile)) {
        @unlink($stopFile);
    }

    // Recover stale queue locks first.
    $staleRecovered = 0;
    $stRecover = $conn->prepare("UPDATE queue_jobs
        SET status = CASE WHEN attempts+1 >= max_attempts THEN 'failed' ELSE 'retrying' END,
            run_at = CASE WHEN attempts+1 >= max_attempts THEN NULL ELSE DATE_ADD(NOW(), INTERVAL 30 SECOND) END,
            last_error = CASE WHEN attempts+1 >= max_attempts THEN COALESCE(last_error, 'pulse_recovery_max_attempts') ELSE COALESCE(last_error, 'pulse_recovered_stale_lock') END,
            locked_at = NULL,
            lock_token = NULL,
            updated_at = NOW()
        WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    if ($stRecover) {
        $stRecover->execute();
        $staleRecovered = (int)$conn->affected_rows;
    }

    $workerOutput = '';
    $workerOk = $waRunQueueWorkerCycle($workerOutput);
    if ($workerOk) {
        $analysisResult = 'Queue worker recovery completed successfully.' . ($staleRecovered > 0 ? (' Recovered stale jobs: ' . $staleRecovered . '.') : '');
    } else {
        $analysisResult = 'Queue recovery attempted but worker execution needs manual review: ' . ($workerOutput !== '' ? $workerOutput : 'unknown worker error');
    }
}

if (isset($_POST['clear_all'])) {
    csrf_verify();
    $stmt = $conn->prepare("DELETE FROM system_errors WHERE status='fixed'");
    if ($stmt) {
        $stmt->execute();
    }
}

if (isset($_POST['simulate_error']) && ((defined('APP_ENV') && APP_ENV === 'local') || (defined('DEBUG_MODE') && DEBUG_MODE))) {
    csrf_verify();
    // Trigger a mock error for testing
    trigger_error("PULSE_SIMULATION: Testing AI error capture logic.", E_USER_WARNING);
    header("Location: pulse.php");
    exit();
}

// Fetch stats
$totalErrors = 0;
$qTotal = $conn->query("SELECT COUNT(id) as c FROM system_errors WHERE status='new'");
if ($qTotal) {
    $totalErrors = (int)($qTotal->fetch_assoc()['c'] ?? 0);
}

// Last run status for dashboard and scheduler
$lastPulseRunAt = null;
$lastPulseStatus = 'unknown';
$lastPulseSummary = '';
$lastRunRes = $conn->query("SELECT last_run_at, last_status, last_summary FROM pulse_runs ORDER BY last_run_at DESC LIMIT 1");
if ($lastRunRes && ($runRow = $lastRunRes->fetch_assoc())) {
    $lastPulseRunAt = $runRow['last_run_at'] ?? null;
    $lastPulseStatus = (string)($runRow['last_status'] ?? 'unknown');
    $lastPulseSummary = (string)($runRow['last_summary'] ?? '');
}

// Queue health summary for operations card
$queueHealth = [
    'pending' => 0,
    'retrying' => 0,
    'processing' => 0,
    'failed' => 0,
    'done' => 0,
    'oldest_created_at' => null,
    'stopped' => is_file(dirname(__DIR__) . '/storage/STOP_QUEUE_DAEMON'),
];
$qHealthRes = $conn->query("SELECT
    COALESCE(SUM(status='pending'),0) pending,
    COALESCE(SUM(status='retrying'),0) retrying,
    COALESCE(SUM(status='processing'),0) processing,
    COALESCE(SUM(status='failed'),0) failed,
    COALESCE(SUM(status='done'),0) done,
    MIN(created_at) oldest_created_at
  FROM queue_jobs");
if ($qHealthRes && ($qh = $qHealthRes->fetch_assoc())) {
    $queueHealth = array_merge($queueHealth, $qh);
    $queueHealth['pending'] = (int)$queueHealth['pending'];
    $queueHealth['retrying'] = (int)$queueHealth['retrying'];
    $queueHealth['processing'] = (int)$queueHealth['processing'];
    $queueHealth['failed'] = (int)$queueHealth['failed'];
    $queueHealth['done'] = (int)$queueHealth['done'];
}

// Auto-heal scheduler: run if stale and enabled
if (!isset($_POST['analyze_fix']) && !isset($_POST['emergency_repair']) && !isset($_POST['save_pulse_automation']) && $pulseAutoHealEnabled === 1) {
    $isStale = true;
    if ($lastPulseRunAt) {
        $secondsAgo = time() - strtotime((string)$lastPulseRunAt);
        $isStale = ($secondsAgo >= ($pulseAutoHealIntervalMinutes * 60));
    }
    if ($isStale) {
        $waRunPulseHeal(false);
        $analysisResult = "Auto-heal executed automatically (stale run detected).";

        $lastRunRes = $conn->query("SELECT last_run_at, last_status, last_summary FROM pulse_runs ORDER BY last_run_at DESC LIMIT 1");
        if ($lastRunRes && ($runRow = $lastRunRes->fetch_assoc())) {
            $lastPulseRunAt = $runRow['last_run_at'] ?? null;
            $lastPulseStatus = (string)($runRow['last_status'] ?? 'unknown');
            $lastPulseSummary = (string)($runRow['last_summary'] ?? '');
        }
    }
}

$topFilesRows = [];
$topFiles = $conn->query("SELECT file, COUNT(id) as count FROM system_errors WHERE status='new' GROUP BY file ORDER BY count DESC LIMIT 5");
if ($topFiles) {
    while ($row = $topFiles->fetch_assoc()) {
        $topFilesRows[] = $row;
    }
}

// Fetch recent errors
$errorRows = [];
$errors = $conn->query("SELECT e.*, c.name as company_name FROM system_errors e LEFT JOIN companies c ON e.company_id = c.id ORDER BY e.created_at DESC LIMIT 50");
if ($errors) {
    while ($row = $errors->fetch_assoc()) {
        $errorRows[] = $row;
    }
}

// Fetch Performance Stats for top vendors
include_once '../includes/monitor_handler.php';
$perfStats = [];
$companiesRes = $conn->query("SELECT id, name FROM companies LIMIT 5");
if ($companiesRes) {
    while ($c = $companiesRes->fetch_assoc()) {
        $perfStats[] = [
            'name' => $c['name'],
            'usage' => get_company_msg_usage($c['id']),
            'storage' => get_folder_size(dirname(__DIR__) . "/storage/vendor_" . $c['id'])
        ];
    }
}
// Sort by usage
usort($perfStats, function ($a, $b) {
    return $b['usage'] - $a['usage'];
});

// Human-friendly health score for non-technical operators
$healthScore = 100;
$healthScore -= min(60, $totalErrors * 2);
if (!empty($lastPulseRunAt)) {
    $lastRunAgeMinutes = (int)max(0, floor((time() - strtotime((string)$lastPulseRunAt)) / 60));
    if ($lastRunAgeMinutes > ($pulseAutoHealIntervalMinutes * 2)) {
        $healthScore -= 20;
    }
} else {
    $healthScore -= 20;
}
$healthScore = max(0, min(100, $healthScore));

$healthLabel = 'GREEN';
$healthColor = 'var(--success)';
if ($healthScore < 60) {
    $healthLabel = 'RED';
    $healthColor = 'var(--danger)';
} elseif ($healthScore < 85) {
    $healthLabel = 'AMBER';
    $healthColor = '#f39c12';
}

// MCP server health check (waamini-mcp). Fail-safe in the shared library:
// short timeouts and no warnings, so the page renders even when MCP is down.
$mcpHealth = waamini_pulse_check_mcp();

include '../includes/admin_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'pulse'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
    'cpu'    => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
    'refresh'=> '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'users'  => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    'arrow'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'flask'  => '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M6.453 15h11.094"/><path d="M8.5 2h7"/>',
];

// Health presentation
if ($healthLabel === 'GREEN') { $hState = 'Healthy'; $hSub = 'All systems are running normally.'; $hRing = '#7CFFB2'; }
elseif ($healthLabel === 'AMBER') { $hState = 'Needs attention'; $hSub = 'Some issues were detected. Run a self-heal.'; $hRing = '#FFD36B'; }
else { $hState = 'Critical'; $hSub = 'Multiple problems detected. Act now.'; $hRing = '#FF8A80'; }
$ringC = 2 * M_PI * 54;
$ringD = $ringC * $healthScore / 100;
$ranAgo = '';
if (!empty($lastPulseRunAt)) {
    $m = (int)max(0, floor((time() - strtotime((string)$lastPulseRunAt)) / 60));
    $ranAgo = $m < 1 ? 'just now' : ($m < 60 ? $m . ' min ago' : ($m < 1440 ? floor($m / 60) . ' h ago' : floor($m / 1440) . ' d ago'));
}
$typeCls = static function (string $t): string {
    $t = strtolower($t);
    if (strpos($t, 'fatal') !== false || strpos($t, 'error') !== false || strpos($t, 'exception') !== false) { return 'is-bad'; }
    if (strpos($t, 'warn') !== false) { return 'is-warn'; }
    if (strpos($t, 'notice') !== false || strpos($t, 'deprec') !== false) { return 'is-info'; }
    return 'is-off';
};
$qTotalJobs = max(1, $queueHealth['pending'] + $queueHealth['retrying'] + $queueHealth['processing'] + $queueHealth['failed'] + $queueHealth['done']);
$perfMax = 1; foreach ($perfStats as $ps0) { $perfMax = max($perfMax, (int)$ps0['usage']); }
$isLocalDev = (defined('APP_ENV') && APP_ENV === 'local') || (defined('DEBUG_MODE') && DEBUG_MODE);
// Error activity, last 14 days (for the overview chart)
$errDays = []; $errNew = []; $errFixed = [];
$__em = []; $__ef = [];
$__r = $conn->query("SELECT DATE(created_at) d, SUM(status<>'fixed') n, SUM(status='fixed') f FROM system_errors WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)");
if ($__r) { while ($x = $__r->fetch_assoc()) { $__em[$x['d']] = (int)$x['n']; $__ef[$x['d']] = (int)$x['f']; } }
for ($i = 13; $i >= 0; $i--) { $k = date('Y-m-d', strtotime("-$i days")); $errDays[] = date('d M', strtotime($k)); $errNew[] = $__em[$k] ?? 0; $errFixed[] = $__ef[$k] ?? 0; }
?>

<div class="db pu">

  <div class="db-head">
    <div>
      <h1>WAAMINI Pulse</h1>
      <p>Real-time AI monitoring and self-healing diagnostics.</p>
    </div>
    <form method="POST" class="db-head__actions">
      <?php echo csrf_field(); ?>
      <?php if ($isLocalDev): ?><button type="submit" name="simulate_error" class="db-btn"><?php echo $__ic($P['flask'], 16); ?> Simulate error</button><?php endif; ?>
      <button type="submit" name="clear_all" class="db-btn"><?php echo $__ic($P['trash'], 16); ?> Clear fixed</button>
      <button type="submit" name="emergency_repair" class="db-btn pu-btn--warn"><?php echo $__ic($P['wrench'], 16); ?> Emergency repair</button>
      <button type="submit" name="analyze_fix" class="db-btn db-btn--primary"><?php echo $__ic($P['bolt'], 16); ?> Run self-heal</button>
    </form>
  </div>

  <?php if (isset($analysisResult)): ?>
    <div class="pu-toast" role="status"><?php echo $__ic($P['check'], 18); ?> <span><?php echo $esc($analysisResult); ?></span></div>
  <?php endif; ?>

  <!-- overview: chart + KPIs + health donut (same layout as the Dashboard) -->
  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Error activity</h2>
            <div class="db-legend"><span><i style="background:#E5483A"></i>Captured: <?php echo (int)array_sum($errNew); ?></span><span><i style="background:#128C7E"></i>Fixed: <?php echo (int)array_sum($errFixed); ?></span></div>
          </div>
          <div class="db-seg" id="puSeg"><button type="button" class="is-active" data-mode="both">Both</button><button type="button" data-mode="new">Captured</button><button type="button" data-mode="fixed">Fixed</button></div>
        </div>
        <div class="db-canvas"><canvas id="puChart" aria-label="Errors over the last 14 days" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $totalErrors > 0 ? 'is-pink' : 'is-green'; ?>"><?php echo $__ic($P['alert'], 19); ?></div><div class="db-kpi__l">Unresolved errors</div><div class="db-kpi__v"><?php echo number_format($totalErrors); ?></div><div class="db-kpi__d"><?php echo $totalErrors > 0 ? '<span class="db-chip is-down">Needs review</span>' : '<span class="db-chip is-up">All clear</span>'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $watchdogActive ? 'is-green' : 'is-amber'; ?>"><?php echo $__ic($P['eye'], 19); ?></div><div class="db-kpi__l">Watchdog</div><div class="db-kpi__v"><?php echo $watchdogActive ? 'Active' : 'Inactive'; ?></div><div class="db-kpi__d" title="<?php echo $esc($watchdogLastSummary); ?>"><?php echo $watchdogLastRunAt ? 'Last run ' . $esc(date('h:i A', strtotime($watchdogLastRunAt))) : 'Never ran'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic <?php echo $mcpHealth['online'] ? 'is-blue' : 'is-pink'; ?>"><?php echo $__ic($P['cpu'], 19); ?></div><div class="db-kpi__l">MCP server</div><div class="db-kpi__v"><?php echo $mcpHealth['online'] ? 'Online' : 'Offline'; ?></div><div class="db-kpi__d"><?php echo $mcpHealth['online'] ? ($mcpHealth['latency_ms'] !== null ? (int)$mcpHealth['latency_ms'] . ' ms latency' : 'Connected') : 'Not reachable'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($P['refresh'], 19); ?></div><div class="db-kpi__l">Queue failed jobs</div><div class="db-kpi__v"><?php echo number_format($queueHealth['failed']); ?></div><div class="db-kpi__d"><?php echo number_format($queueHealth['pending']); ?> pending &middot; <?php echo number_format($queueHealth['retrying']); ?> retrying</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>System health</h2><span class="db-pill <?php echo $healthLabel === 'GREEN' ? 'is-ok' : ($healthLabel === 'AMBER' ? 'is-warn' : 'is-bad'); ?>"><?php echo $esc($hState); ?></span></div>
      <div class="db-donut__chart">
        <canvas id="puDonut" width="200" height="200" aria-label="Health score" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo (int)$healthScore; ?></b><span>/ 100</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:<?php echo $healthLabel === 'GREEN' ? '#128C7E' : ($healthLabel === 'AMBER' ? '#F59E0B' : '#E5483A'); ?>"></i>Score <?php echo (int)$healthScore; ?></span>
        <span><i style="background:#E8F1ED"></i>Gap <?php echo 100 - (int)$healthScore; ?></span>
      </div>
      <div class="pu-meta">
        <div><span><?php echo $__ic($P['clock'], 15); ?> Last heal</span><b><?php echo !empty($lastPulseRunAt) ? $esc($ranAgo) : 'Never'; ?></b></div>
        <div><span><?php echo $__ic($P['check'], 15); ?> Result</span><b><?php echo $esc(ucfirst($lastPulseStatus)); ?></b></div>
      </div>
    </section>
  </div>

  <!-- feed + side -->
  <div class="db-row db-row--top">
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Live error feed</h2><p>Latest 50 captured events</p></div>
        <span class="db-pill <?php echo $totalErrors > 0 ? 'is-bad' : 'is-ok'; ?>"><?php echo number_format($totalErrors); ?> unresolved</span>
      </div>
      <?php if ($errorRows): ?>
      <div class="pu-feed">
        <?php foreach ($errorRows as $e): $fixed = ($e['status'] === 'fixed'); ?>
          <article class="pu-ev<?php echo $fixed ? ' is-fixed' : ''; ?>">
            <div class="pu-ev__time"><b><?php echo $esc(date('H:i:s', strtotime($e['created_at']))); ?></b><span><?php echo $esc(date('d M', strtotime($e['created_at']))); ?></span></div>
            <div class="pu-ev__body">
              <div class="pu-ev__top">
                <span class="db-pill <?php echo $typeCls((string)$e['error_type']); ?>"><?php echo $esc($e['error_type'] !== null && $e['error_type'] !== '' ? $e['error_type'] : 'event'); ?></span>
                <?php if (!empty($e['company_name'])): ?><span class="pu-co"><?php echo $__ic($P['users'], 12); ?> <?php echo $esc($e['company_name']); ?></span><?php endif; ?>
              </div>
              <p class="pu-ev__msg"><?php echo $esc($e['message']); ?></p>
              <code class="pu-ev__loc"><?php echo $esc(basename((string)$e['file'])); ?>:<?php echo (int)$e['line']; ?></code>
            </div>
            <div class="pu-ev__act">
              <?php if ($e['status'] === 'new'): ?>
                <form method="POST">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="error_id" value="<?php echo (int)$e['id']; ?>">
                  <button type="submit" name="mark_fixed" class="pu-fix"><?php echo $__ic($P['check'], 15); ?> Mark fixed</button>
                </form>
              <?php else: ?>
                <span class="db-pill is-ok"><?php echo $__ic($P['check'], 13); ?> Fixed</span>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="pu-clear"><span class="pu-clear__ic"><?php echo $__ic($P['shield'], 34); ?></span><b>No errors captured</b><p>Pulse is watching. Anything that goes wrong will show up here instantly.</p></div>
      <?php endif; ?>
    </section>

    <div class="pu-side">
      <!-- AI insights -->
      <section class="db-card pu-card pu-ai">
        <div class="pu-ai__hd"><span class="pu-ai__ic"><?php echo $__ic($P['cpu'], 22); ?></span><div><h2>AI Pulse insights</h2><p>Recurring problem hot-spots</p></div></div>
        <?php if (!empty($topFilesRows)): ?>
          <div class="pu-files">
            <?php foreach ($topFilesRows as $tf): ?>
              <div class="pu-file">
                <div><b><?php echo $esc(basename((string)$tf['file'])); ?></b><span><?php echo (int)$tf['count']; ?> events</span></div>
                <div class="pu-track"><u style="width:<?php echo min(100, (int)$tf['count'] * 10); ?>%"></u></div>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="POST">
            <?php echo csrf_field(); ?>
            <button type="submit" name="analyze_fix" class="db-btn db-btn--primary pu-wide"><?php echo $__ic($P['bolt'], 16); ?> Analyze &amp; fix issues</button>
          </form>
        <?php else: ?>
          <div class="pu-ai__ok"><?php echo $__ic($P['shield'], 22); ?> <span>Everything looks good. No issues detected by AI.</span></div>
        <?php endif; ?>
        <p class="pu-note"><?php echo $esc($pulseSelfHealNote); ?></p>
      </section>

      <!-- Automation -->
      <section class="db-card pu-card">
        <div class="pu-ch"><h2>Pulse automation</h2><p>Scheduled self-heal runs</p></div>
        <form method="POST" class="pu-form">
          <?php echo csrf_field(); ?>
          <label class="pu-switch">
            <input type="checkbox" name="pulse_auto_heal_enabled" value="1" <?php echo $pulseAutoHealEnabled === 1 ? 'checked' : ''; ?>>
            <i></i><span>Enable auto-heal scheduler</span>
          </label>
          <label class="pu-lab" for="puInt">Run every (minutes)</label>
          <input id="puInt" type="number" name="pulse_auto_heal_interval_minutes" min="5" max="1440" value="<?php echo (int)$pulseAutoHealIntervalMinutes; ?>" class="pu-input">
          <button type="submit" name="save_pulse_automation" class="db-btn db-btn--primary pu-wide"><?php echo $__ic($P['save'], 16); ?> Save automation</button>
        </form>
      </section>
    </div>
  </div>

  <!-- operations trio -->
  <div class="pu-trio">
    <section class="db-card pu-card">
      <div class="pu-ch"><h2>Worker recovery</h2><p>Background job queue</p></div>
      <div class="pu-q">
        <?php foreach ([['Pending', 'pending', '#3B82F6'], ['Retrying', 'retrying', '#F59E0B'], ['Processing', 'processing', '#8B5CF6'], ['Failed', 'failed', '#E5483A'], ['Done', 'done', '#25D366']] as [$ql, $qk, $qc]): ?>
          <div class="pu-q__r"><span><i style="background:<?php echo $qc; ?>"></i><?php echo $ql; ?></span><div class="pu-track"><u style="width:<?php echo round($queueHealth[$qk] / $qTotalJobs * 100); ?>%;background:<?php echo $qc; ?>"></u></div><b><?php echo number_format($queueHealth[$qk]); ?></b></div>
        <?php endforeach; ?>
      </div>
      <div class="pu-flag <?php echo $queueHealth['stopped'] ? 'is-bad' : 'is-ok'; ?>">Daemon stop flag: <b><?php echo $queueHealth['stopped'] ? 'PRESENT' : 'CLEAR'; ?></b></div>
      <form method="POST">
        <?php echo csrf_field(); ?>
        <button type="submit" name="repair_queue_worker" class="db-btn pu-wide"><?php echo $__ic($P['refresh'], 16); ?> Run queue recovery</button>
      </form>
      <p class="pu-note">Runs one worker cycle, clears stale queue locks and removes stop flags.</p>
    </section>

    <section class="db-card pu-card">
      <div class="pu-ch"><h2>Vendor usage</h2><p>Top vendors by messages</p></div>
      <?php if (empty($perfStats)): ?>
        <div class="db-empty">No vendor data available.</div>
      <?php else: ?>
        <div class="pu-vend">
          <?php foreach ($perfStats as $ps): $high = $ps['storage'] > 100 * 1024 * 1024; ?>
            <div class="pu-v">
              <span class="db-av"><?php echo $esc(strtoupper(mb_substr((string)$ps['name'], 0, 1))); ?></span>
              <div class="pu-v__b">
                <div class="pu-v__t"><b><?php echo $esc($ps['name']); ?></b><span><?php echo number_format($ps['usage']); ?> msgs</span></div>
                <div class="pu-track"><u style="width:<?php echo round($ps['usage'] / $perfMax * 100); ?>%;background:linear-gradient(90deg,#25D366,#128C7E)"></u></div>
                <small>Storage <?php echo $esc(format_bytes($ps['storage'])); ?><?php if ($high): ?> <em class="pu-high"><?php echo $__ic($P['alert'], 11); ?> High</em><?php endif; ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <a href="companies.php" class="db-link pu-more">Manage all vendors <?php echo $__ic($P['arrow'], 14); ?></a>
    </section>

    <section class="db-card pu-card">
      <div class="pu-ch"><h2>Server health</h2><p>Runtime environment</p></div>
      <div class="pu-srv">
        <div><span><?php echo $__ic($P['server'], 16); ?> PHP version</span><b><?php echo $esc(PHP_VERSION); ?></b></div>
        <div><span><?php echo $__ic($P['server'], 16); ?> MySQL</span><b class="is-ok"><i></i> Connected</b></div>
        <div><span><?php echo $__ic($P['cpu'], 16); ?> MCP server<?php echo $mcpHealth['service'] !== '' ? ' (' . $esc($mcpHealth['service']) . ')' : ''; ?></span><b class="<?php echo $mcpHealth['online'] ? 'is-ok' : 'is-bad'; ?>"><i></i> <?php echo $mcpHealth['online'] ? 'Connected' . ($mcpHealth['latency_ms'] !== null ? ' &middot; ' . (int)$mcpHealth['latency_ms'] . 'ms' : '') : 'Offline'; ?></b></div>
        <div><span><?php echo $__ic($P['pulse'], 16); ?> Memory usage</span><b><?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB</b></div>
        <div><span><?php echo $__ic($P['eye'], 16); ?> Watchdog</span><b class="<?php echo $watchdogActive ? 'is-ok' : 'is-bad'; ?>"><i></i> <?php echo $watchdogActive ? 'Active' : 'Inactive'; ?></b></div>
      </div>
    </section>
  </div>
</div>

<style>
.pu{max-width:none;margin:0}
.pu .db-head__actions{margin:0}
.pu-btn--warn{background:#FEF3DC;border-color:transparent;color:#92580a}
.pu-btn--warn:hover{background:#FCE8BE;color:#7a4608;border-color:transparent}
.pu-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;background:#E6FCF2;color:#0B6B5E;font-size:14.5px;font-weight:600;line-height:1.45;word-break:break-word}
.pu-toast svg{margin-top:2px}

.pu .db-donut__chart canvas{max-width:200px;max-height:200px}
.pu-meta{display:flex;flex-direction:column;gap:8px;margin-top:16px}
.pu-meta>div{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-radius:12px;background:var(--soft);font-size:13.5px}
.pu-meta span{display:inline-flex;align-items:center;gap:8px;color:var(--tx);font-weight:600}
.pu-meta span svg{color:var(--g)}
.pu-meta b{color:var(--ink);font-weight:800}

.pu-feed{display:flex;flex-direction:column;gap:10px;padding:6px 20px 20px;max-height:640px;overflow-y:auto}
.pu-ev{display:grid;grid-template-columns:62px minmax(0,1fr) auto;gap:16px;align-items:center;padding:14px 16px;border-radius:14px;background:var(--soft);transition:background .15s,box-shadow .15s}
.pu-ev:hover{background:#fff;box-shadow:0 10px 26px -16px rgba(18,140,126,.45)}
.pu-ev.is-fixed{opacity:.6}
.pu-ev__time b{display:block;font-size:14px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.pu-ev__time span{font-size:12.5px;color:var(--mu)}
.pu-ev__top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px}
.pu-co{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:7px;background:#fff;font-size:12px;font-weight:700;color:var(--gd)}
.pu-ev__msg{margin:0 0 6px;font-size:14px;line-height:1.5;color:var(--tx);word-break:break-word;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.pu-ev__loc{font:600 12.5px ui-monospace,Consolas,monospace;color:var(--mu)}
.pu-fix{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:0;border-radius:10px;background:#fff;color:var(--gd);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.pu-fix:hover{background:linear-gradient(135deg,#25D366,#128C7E);color:#fff}
.pu-clear{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:44px 24px 52px}
.pu-clear__ic{width:76px;height:76px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin-bottom:6px}
.pu-clear b{font-size:18px;color:var(--ink)}
.pu-clear p{margin:0;max-width:340px;font-size:14.5px;color:var(--mu);line-height:1.55}

.pu-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.pu-card{padding:20px}
.pu-ch{margin-bottom:14px}
.pu-ch h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.pu-ch p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.pu-note{margin:14px 0 0;padding:11px 14px;border-radius:12px;background:var(--soft);font-size:12.5px;line-height:1.55;color:var(--mu)}
.pu-wide{width:100%;justify-content:center}
.pu-track{height:8px;border-radius:99px;background:#fff;overflow:hidden;min-width:0}
.pu-card .pu-track{background:var(--soft)}
.pu-track u{display:block;height:100%;border-radius:99px;text-decoration:none;min-width:2px}

.pu-ai{background:#fff}
.pu-ai__hd{display:flex;align-items:center;gap:13px;margin-bottom:16px}
.pu-ai__hd h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.pu-ai__hd p{margin:2px 0 0;font-size:13.5px;color:var(--mu)}
.pu-ai__ic{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;flex:none;box-shadow:0 10px 20px -10px rgba(18,140,126,.8)}
.pu-files{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.pu-file{padding:12px 14px;border-radius:12px;background:#fff}
.pu-file>div:first-child{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:14px}
.pu-file b{color:var(--ink);font-weight:700;word-break:break-all}
.pu-file span{color:var(--mu);font-size:12.5px;white-space:nowrap}
.pu-file .pu-track{background:var(--soft)}
.pu-file .pu-track u{background:linear-gradient(90deg,#F59E0B,#E5483A)}
.pu-ai__ok{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:12px;background:#fff;color:#12803f;font-size:14.5px;font-weight:600}

.pu-form{display:flex;flex-direction:column;gap:12px}
.pu-lab{font-size:13.5px;font-weight:700;color:var(--tx)}
.pu-input{height:44px;max-width:170px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:600 15px Inter,system-ui,sans-serif;color:var(--ink);outline:0}
.pu-input:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.pu-switch{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--soft);cursor:pointer;font-size:14.5px;font-weight:600;color:var(--ink)}
.pu-switch input{position:absolute;opacity:0;pointer-events:none}
.pu-switch i{position:relative;width:42px;height:24px;border-radius:99px;background:#C7D1CD;flex:none;transition:background .2s}
.pu-switch i::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.pu-switch input:checked+i{background:linear-gradient(135deg,#25D366,#128C7E)}
.pu-switch input:checked+i::after{transform:translateX(18px)}
.pu-switch input:focus-visible+i{box-shadow:0 0 0 4px rgba(18,140,126,.2)}

.pu-trio{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:18px}
.pu-q{display:flex;flex-direction:column;gap:12px;margin-bottom:14px}
.pu-q__r{display:grid;grid-template-columns:104px minmax(0,1fr) 40px;align-items:center;gap:12px;font-size:14px;color:var(--tx)}
.pu-q__r span{display:inline-flex;align-items:center;gap:8px;font-weight:600}
.pu-q__r i{width:10px;height:10px;border-radius:3px;display:inline-block}
.pu-q__r b{text-align:right;color:var(--ink);font-weight:800}
.pu-flag{padding:11px 14px;border-radius:12px;font-size:13.5px;margin-bottom:12px}
.pu-flag.is-ok{background:#DDF7E7;color:#12803f}.pu-flag.is-bad{background:#FDE4E1;color:#c0372b}
.pu-vend{display:flex;flex-direction:column;gap:12px}
.pu-v{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;background:var(--soft)}
.pu-v .db-av{background:#fff;color:var(--gd);flex:none}
.pu-v__b{flex:1;min-width:0}
.pu-v__t{display:flex;justify-content:space-between;gap:8px;margin-bottom:7px;font-size:14px}
.pu-v__t b{color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pu-v__t span{color:var(--gd);font-weight:800;font-size:13px;white-space:nowrap}
.pu-v small{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:12.5px;color:var(--mu)}
.pu-high{display:inline-flex;align-items:center;gap:3px;font-style:normal;font-weight:800;color:#c0372b}
.pu-more{display:inline-flex;align-items:center;gap:6px;margin-top:14px}
.pu-srv{display:flex;flex-direction:column;gap:10px}
.pu-srv>div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border-radius:12px;background:var(--soft);font-size:14px}
.pu-srv span{display:inline-flex;align-items:center;gap:9px;color:var(--tx);font-weight:600;min-width:0}
.pu-srv span svg{color:var(--g);flex:none}
.pu-srv b{display:inline-flex;align-items:center;gap:7px;color:var(--ink);font-weight:800;white-space:nowrap}
.pu-srv b i{width:9px;height:9px;border-radius:50%;background:currentColor}
.pu-srv b.is-ok{color:#12803f}.pu-srv b.is-bad{color:#c0372b}

@media (max-width:1180px){.pu .db-row{grid-template-columns:1fr}.pu-trio{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.pu-trio{grid-template-columns:1fr}.pu-ev{grid-template-columns:1fr}.pu-ev__act{justify-self:start}.pu .db-head__actions{width:100%}}
</style>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  if (typeof Chart === 'undefined') { return; }
  var labels = <?php echo json_encode($errDays); ?>;
  var dNew = <?php echo json_encode($errNew); ?>, dFix = <?php echo json_encode($errFixed); ?>;
  var cv = document.getElementById('puChart');
  if (cv) {
    var chart = new Chart(cv, {
      type: 'bar',
      data: {labels: labels, datasets: [
        {label: 'Captured', data: dNew, backgroundColor: '#E5483A', borderRadius: 5, barPercentage: .8, categoryPercentage: .7},
        {label: 'Fixed', data: dFix, backgroundColor: '#128C7E', borderRadius: 5, barPercentage: .8, categoryPercentage: .7}
      ]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}},
        scales: {
          x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0, autoSkip: true, maxTicksLimit: 7}},
          y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}
        }
      }
    });
    var seg = document.getElementById('puSeg');
    if (seg) { seg.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-mode]'); if (!b) { return; }
      seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
      var m = b.getAttribute('data-mode');
      chart.setDatasetVisibility(0, m !== 'fixed'); chart.setDatasetVisibility(1, m !== 'new');
      chart.update();
    }); }
  }
  var dn = document.getElementById('puDonut');
  if (dn) {
    new Chart(dn, {type: 'doughnut',
      data: {labels: ['Score', 'Gap'], datasets: [{data: [<?php echo (int)$healthScore; ?>, <?php echo 100 - (int)$healthScore; ?>], backgroundColor: ['<?php echo $healthLabel === 'GREEN' ? '#128C7E' : ($healthLabel === 'AMBER' ? '#F59E0B' : '#E5483A'); ?>', '#E8F1ED'], borderWidth: 0}]},
      options: {responsive: true, maintainAspectRatio: true, cutout: '74%', plugins: {legend: {display: false}, tooltip: {enabled: false}}}});
  }
})();
</script>

<?php include '../includes/admin_footer.php'; ?>
