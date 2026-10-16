<?php
include '../includes/auth_check.php';
include '../db.php';

// Ensure campaign_queue has processed_at column (backwards compat)
try { $conn->query("ALTER TABLE campaign_queue ADD COLUMN processed_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) { /* ignore */ }

if (!isset($_GET['id'])) {
    header("Location: campaigns.php");
    exit();
}
$id = intval($_GET['id']);

$campaign = null;
$cStmt = $conn->prepare("SELECT c.*, mt.name as template_name, mt.body_text, mt.header_text, mt.footer_text, mt.category FROM campaigns c LEFT JOIN message_templates mt ON c.template_id = mt.id WHERE c.id = ?");
if ($cStmt) {
    $cStmt->bind_param('i', $id);
    $cStmt->execute();
    $cRes = $cStmt->get_result();
    $campaign = $cRes ? $cRes->fetch_assoc() : null;
}
if (!$campaign) {
    header("Location: campaigns.php");
    exit();
}

// Handle manual "Process Queue" trigger (admin clicks button to run cron sender)
if (isset($_POST['process_queue']) && in_array($campaign['status'], ['running', 'scheduled'])) {
    csrf_verify();
    $cronFile = realpath(__DIR__ . '/../cron_campaign_sender.php');
    if ($cronFile && file_exists($cronFile)) {
        define('WAAMINI_ADMIN_CRON_TRIGGER', true);
        ob_start();
        try {
            include $cronFile;
        } catch (\Throwable $e) {
            error_log("[WAAMINI] Manual queue trigger error: " . $e->getMessage());
        }
        ob_end_clean();
    }
    header('Location: campaign_view.php?id=' . (int)$id . '&msg=processing');
    exit();
}

// Handle "Retry Failed" — reset failed entries to pending so cron can re-process
if (isset($_POST['retry_failed'])) {
    csrf_verify();
    // Reset all failed queue entries back to pending
    $retryStmt = $conn->prepare("UPDATE campaign_queue SET status='pending', error_message=NULL WHERE campaign_id=? AND status='failed'");
    if ($retryStmt) {
        $retryStmt->bind_param('i', $id);
        $retryStmt->execute();
        $retriedCount = $retryStmt->affected_rows;
    }
    // If campaign was completed, set it back to running so the queue gets processed
    if ($campaign['status'] === 'completed' && ($retriedCount ?? 0) > 0) {
        $rerunStmt = $conn->prepare("UPDATE campaigns SET status='running', completed_at=NULL WHERE id=?");
        if ($rerunStmt) {
            $rerunStmt->bind_param('i', $id);
            $rerunStmt->execute();
        }
    }
    // Now trigger the cron sender to process them immediately
    $cronFile = realpath(__DIR__ . '/../cron_campaign_sender.php');
    if ($cronFile && file_exists($cronFile)) {
        if (!defined('WAAMINI_ADMIN_CRON_TRIGGER')) {
            define('WAAMINI_ADMIN_CRON_TRIGGER', true);
        }
        ob_start();
        try {
            include $cronFile;
        } catch (\Throwable $e) {
            error_log("[WAAMINI] Retry trigger error: " . $e->getMessage());
        }
        ob_end_clean();
    }
    header('Location: campaign_view.php?id=' . (int)$id . '&msg=retried');
    exit();
}

$pageTitle = $campaign['name'];
$currentPage = 'campaigns';

// Read stats from campaign_queue
$stats = ['total' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'delivered' => 0, 'read_count' => 0, 'failed' => 0];
$sStmt = $conn->prepare("SELECT COUNT(*) as total,
    SUM(status='pending') as queued,
    SUM(status='processing') as processing,
    SUM(status='sent' OR status='queued_wa') as sent,
    SUM(wa_delivery_status='delivered') as delivered,
    SUM(wa_delivery_status='read') as read_count,
    SUM(status='failed') as failed
    FROM campaign_queue WHERE campaign_id = ?");
if ($sStmt) {
    $sStmt->bind_param('i', $id);
    $sStmt->execute();
    $sRes = $sStmt->get_result();
    $stats = $sRes ? ($sRes->fetch_assoc() ?: $stats) : $stats;
}

// Auto-complete: if campaign is 'running' and ALL queue entries are done
if ($campaign['status'] === 'running' && ($stats['total'] ?? 0) > 0) {
    $pendingLeft = ($stats['queued'] ?? 0) + ($stats['processing'] ?? 0);
    if ($pendingLeft === 0) {
        $doneStmt = $conn->prepare("UPDATE campaigns SET status='completed', completed_at=NOW() WHERE id=? AND status='running'");
        if ($doneStmt) {
            $doneStmt->bind_param('i', $id);
            $doneStmt->execute();
            if ($doneStmt->affected_rows > 0) {
                $campaign['status'] = 'completed';
            }
        }
    }
}

// Fix stuck 'processing' entries
$stuckStmt = $conn->prepare("UPDATE campaign_queue SET status='pending' WHERE campaign_id=? AND status='processing' AND processed_at IS NOT NULL AND processed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
if ($stuckStmt) {
    $stuckStmt->bind_param('i', $id);
    $stuckStmt->execute();
}
$stuckStmt2 = $conn->prepare("UPDATE campaign_queue SET status='pending' WHERE campaign_id=? AND status='processing' AND processed_at IS NULL AND scheduled_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
if ($stuckStmt2) {
    $stuckStmt2->bind_param('i', $id);
    $stuckStmt2->execute();
}

// Also check campaign_logs for webhook-updated delivery receipts
$logStats = ['total' => 0];
$lsStmt = $conn->prepare("SELECT COUNT(*) as total,
    SUM(status='queued') as queued,
    SUM(status='sent') as sent,
    SUM(status='delivered') as delivered,
    SUM(status='read') as read_count,
    SUM(status='failed') as failed
    FROM campaign_logs WHERE campaign_id = ?");
if ($lsStmt) {
    $lsStmt->bind_param('i', $id);
    $lsStmt->execute();
    $lsRes = $lsStmt->get_result();
    $logStats = $lsRes ? ($lsRes->fetch_assoc() ?: $logStats) : $logStats;
}
if (($logStats['total'] ?? 0) > 0) {
    $stats = $logStats;
    $statsSource = 'logs';
} else {
    $statsSource = 'queue';
}

// Queue entries
$logs = null;
$qStmt = $conn->prepare("
    SELECT cq.id, cq.contact_id, cq.phone, cq.status, cq.scheduled_at as sent_at, cq.processed_at as delivered_at, cq.error_message,
           cq.wa_delivery_status, cq.wa_delivery_ts, cq.retry_count, cq.retry_after, cq.last_error_message,
           COALESCE(ct.name, cq.phone) as contact_name
    FROM campaign_queue cq
    LEFT JOIN contacts ct ON cq.contact_id = ct.id
    WHERE cq.campaign_id = ?
    ORDER BY cq.id DESC LIMIT 50
");
if ($qStmt) {
    $qStmt->bind_param('i', $id);
    $qStmt->execute();
    $logs = $qStmt->get_result();
}

if (!$logs || $logs->num_rows === 0) {
    $lStmt = $conn->prepare("SELECT cl.*, c.name as contact_name FROM campaign_logs cl LEFT JOIN contacts c ON cl.contact_id = c.id WHERE cl.campaign_id = ? ORDER BY cl.id DESC LIMIT 50");
    if ($lStmt) {
        $lStmt->bind_param('i', $id);
        $lStmt->execute();
        $logs = $lStmt->get_result();
    }
    $logsFromQueue = false;
} else {
    $logsFromQueue = true;
}

// Progress calculations
$sentCount = ($stats['sent'] ?? 0) + ($stats['delivered'] ?? 0) + ($stats['read_count'] ?? 0);
$failedCount = ($stats['failed'] ?? 0);
$processedCount = $sentCount + $failedCount;
if ($processedCount === 0 && $campaign['sent_count'] > 0) {
    $sentCount = $campaign['sent_count'];
    $processedCount = $sentCount;
}
$totalContacts = max(1, $campaign['total_contacts']);
$progress = round(($processedCount / $totalContacts) * 100);
$successRate = $processedCount > 0 ? round(($sentCount / $processedCount) * 100) : 0;

// Status config
$statusConfig = [
    'draft'     => ['color' => '#ff9800', 'bg' => 'rgba(255,152,0,0.10)', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>', 'label' => 'Draft'],
    'scheduled' => ['color' => '#2196f3', 'bg' => 'rgba(33,150,243,0.10)', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/></svg>', 'label' => 'Scheduled'],
    'running'   => ['color' => '#00c853', 'bg' => 'rgba(0,200,83,0.10)',   'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><polygon points="6 3 20 12 6 21 6 3"/></svg>', 'label' => 'Running'],
    'completed' => ['color' => '#00c853', 'bg' => 'rgba(0,200,83,0.10)',   'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>', 'label' => 'Completed'],
    'failed'    => ['color' => '#ff4757', 'bg' => 'rgba(255,71,87,0.10)',  'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>', 'label' => 'Failed'],
    'cancelled' => ['color' => '#9e9e9e', 'bg' => 'rgba(158,158,158,0.10)','icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M22 2 2 22"/></svg>', 'label' => 'Cancelled'],
];
$sc = $statusConfig[$campaign['status']] ?? $statusConfig['draft'];
$logClasses = ['pending' => 'cv-status-pending', 'queued' => 'cv-status-pending', 'processing' => 'cv-status-processing', 'sent' => 'cv-status-sent', 'delivered' => 'cv-status-delivered', 'read' => 'cv-status-read', 'failed' => 'cv-status-failed'];

include '../includes/admin_header.php';
?>

<!-- Toast Notifications -->
<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'created'): ?>
        <div class="cv-toast cv-toast-success">
            <span class="cv-toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></span>
            <span>Campaign created successfully!</span>
        </div>
    <?php elseif ($_GET['msg'] === 'processing'): ?>
        <div class="cv-toast cv-toast-info">
            <span class="cv-toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></span>
            <span>Queue processing triggered! Refresh to see updated status.</span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Page Header -->
<div class="cv-header">
    <div class="cv-header-left">
        <a href="campaigns.php" class="cv-back-link">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Campaigns
        </a>
        <div class="cv-title-row">
            <h1 class="cv-title"><?php echo htmlspecialchars($campaign['name']); ?></h1>
            <span class="cv-status-badge" style="color:<?php echo htmlspecialchars($sc['color'], ENT_QUOTES, 'UTF-8'); ?>;background:<?php echo htmlspecialchars($sc['bg'], ENT_QUOTES, 'UTF-8'); ?>;border:1px solid <?php echo htmlspecialchars($sc['color'], ENT_QUOTES, 'UTF-8'); ?>22;">
                <?php echo $sc['icon'] . ' ' . htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <span class="cv-id-badge">#<?php echo $id; ?></span>
        </div>
    </div>
    <div class="cv-header-actions">
        <?php if (in_array($campaign['status'], ['running', 'scheduled']) && ($stats['queued'] ?? 0) > 0): ?>
            <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <button type="submit" name="process_queue" class="cv-btn cv-btn-glow">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 2L12 8L4 14V2Z" fill="currentColor"/></svg>
                    Process Queue
                </button>
            </form>
        <?php endif; ?>
        <?php if (($stats['failed'] ?? 0) > 0): ?>
            <form method="POST" style="display:inline; margin-left:8px;">
                <?php echo csrf_field(); ?>
                <button type="submit" name="retry_failed" class="cv-btn" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;border:none;">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 8a6 6 0 0 1 10.2-4.3L14 2v5h-5l1.8-1.8A4 4 0 1 0 12 8h2a6 6 0 0 1-12 0z" fill="currentColor"/></svg>
                    Retry Failed (<?php echo (int)$stats['failed']; ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Ring + Cards -->
<div class="cv-overview">
    <!-- Left: Progress Ring -->
    <div class="cv-ring-card">
        <div class="cv-ring-wrap">
            <svg class="cv-ring-svg" viewBox="0 0 120 120">
                <circle cx="60" cy="60" r="52" fill="none" stroke="var(--border)" stroke-width="8"/>
                <circle cx="60" cy="60" r="52" fill="none" stroke="url(#cvGrad)" stroke-width="8" stroke-linecap="round"
                    stroke-dasharray="<?php echo round(326.7 * $progress / 100); ?> 326.7"
                    transform="rotate(-90 60 60)" class="cv-ring-progress"/>
                <defs><linearGradient id="cvGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#6c5ce7"/><stop offset="100%" stop-color="#00c853"/></linearGradient></defs>
            </svg>
            <div class="cv-ring-center">
                <span class="cv-ring-pct"><?php echo $progress; ?>%</span>
                <span class="cv-ring-label">Complete</span>
            </div>
        </div>
        <div class="cv-ring-meta">
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#00c853;"></span>
                <span><?php echo number_format($sentCount); ?> Sent</span>
            </div>
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#ff4757;"></span>
                <span><?php echo number_format($failedCount); ?> Failed</span>
            </div>
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#ff9800;"></span>
                <span><?php echo number_format($stats['queued'] ?? 0); ?> Queued</span>
            </div>
        </div>
    </div>

    <!-- Right: Stat Cards Grid -->
    <div class="cv-stat-grid">
        <div class="cv-stat" style="--accent-c:#6c5ce7;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($campaign['total_contacts']); ?></div>
            <div class="cv-stat-label">Total Recipients</div>
        </div>
        <div class="cv-stat" style="--accent-c:#2196f3;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['sent'] ?? 0); ?></div>
            <div class="cv-stat-label">Sent</div>
        </div>
        <div class="cv-stat" style="--accent-c:#00c853;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['delivered'] ?? 0); ?></div>
            <div class="cv-stat-label">Delivered</div>
        </div>
        <div class="cv-stat" style="--accent-c:#a78bfa;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['read_count'] ?? 0); ?></div>
            <div class="cv-stat-label">Read</div>
        </div>
        <div class="cv-stat" style="--accent-c:#ff4757;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($failedCount); ?></div>
            <div class="cv-stat-label">Failed</div>
        </div>
        <div class="cv-stat" style="--accent-c:#29b6f6;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg></div>
            <div class="cv-stat-value"><?php echo $successRate; ?>%</div>
            <div class="cv-stat-label">Success Rate</div>
        </div>
    </div>
</div>

<!-- Details + Message Preview -->
<div class="cv-details-grid">
    <!-- Campaign Details -->
    <div class="cv-card">
        <div class="cv-card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
            <span>Campaign Details</span>
        </div>
        <div class="cv-detail-list">
            <div class="cv-detail-row">
                <span class="cv-detail-key">Template</span>
                <span class="tag-badge" style="font-size:12px;"><?php echo htmlspecialchars($campaign['template_name'] ?? 'None'); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Category</span>
                <span class="cv-detail-val"><?php echo ucfirst($campaign['category'] ?? '—'); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Target</span>
                <span class="cv-detail-val"><?php echo ucfirst($campaign['target_type'] ?? 'all');
if ($campaign['target_value']) echo ' — ' . htmlspecialchars($campaign['target_value']); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Created</span>
                <span class="cv-detail-val"><?php echo date('d M Y, h:i A', strtotime($campaign['created_at'])); ?></span>
            </div>
            <?php if (!empty($campaign['scheduled_at'])): ?>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Scheduled</span>
                <span class="cv-detail-val" style="color:#2196f3;"><?php echo date('d M Y, h:i A', strtotime($campaign['scheduled_at'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($campaign['started_at'])): ?>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Started</span>
                <span class="cv-detail-val"><?php echo date('d M Y, h:i A', strtotime($campaign['started_at'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($campaign['completed_at'])): ?>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Completed</span>
                <span class="cv-detail-val" style="color:#00c853;"><?php echo date('d M Y, h:i A', strtotime($campaign['completed_at'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- WhatsApp Message Preview -->
    <div class="cv-card">
        <div class="cv-card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#25d366" stroke-width="2"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
            <span>Message Preview</span>
        </div>
        <div class="cv-wa-phone">
            <div class="cv-wa-topbar">
                <div class="cv-wa-avatar">W</div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:#fff;">WAAMINI</div>
                    <div style="font-size:10px;color:rgba(255,255,255,0.6);">Business Account</div>
                </div>
            </div>
            <div class="cv-wa-body">
                <?php if (!empty($campaign['header_text'])): ?>
                    <div class="cv-wa-header"><?php echo htmlspecialchars($campaign['header_text']); ?></div>
                <?php endif; ?>
                <div class="cv-wa-bubble">
                    <div class="cv-wa-text"><?php echo nl2br(htmlspecialchars($campaign['body_text'] ?? 'No template body')); ?></div>
                    <?php if (!empty($campaign['footer_text'])): ?>
                        <div class="cv-wa-footer"><?php echo htmlspecialchars($campaign['footer_text']); ?></div>
                    <?php endif; ?>
                    <div class="cv-wa-time"><?php echo date('h:i A'); ?> <span style="color:#53bdeb;"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:1px;"><path d="M20 6 9 17l-5-5"/></svg></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delivery Log -->
<div class="cv-card cv-card-full">
    <div class="cv-card-header" style="margin-bottom:0;padding-bottom:16px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
        <span><?php echo ($logsFromQueue ?? false) ? 'Send Queue' : 'Delivery Log'; ?></span>
        <span class="cv-log-count"><?php echo $logs ? $logs->num_rows : 0; ?> entries</span>
    </div>
    <div class="cv-log-table-wrap">
        <table class="cv-log-table">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th>Sent</th>
                    <th>Delivered</th>
                    <th>Error / Retry</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <?php while ($l = $logs->fetch_assoc()):
                        $lsc = $logClasses[$l['status']] ?? 'cv-status-pending';
                        
                        // Determine delivery status display (wa_delivery_status from webhook)
                        $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                        $statusText = 'Pending';
                        if ($l['wa_delivery_status'] === 'read') {
                            $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>';
                            $statusText = 'Read';
                        } elseif ($l['wa_delivery_status'] === 'delivered') {
                            $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
                            $statusText = 'Delivered';
                        } elseif ($l['wa_delivery_status'] === 'sent') {
                            $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>';
                            $statusText = 'Sent';
                        } elseif ($l['wa_delivery_status'] === 'failed') {
                            $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
                            $statusText = 'Failed';
                        } elseif ($l['status'] === 'failed' && ($l['retry_count'] ?? 0) < 3) {
                            $statusIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                            $statusText = 'Pending Retry';
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="cv-contact-cell">
                                <div class="cv-contact-avatar"><?php echo strtoupper(substr($l['contact_name'] ?? '?', 0, 1)); ?></div>
                                <span style="color:var(--text-primary);font-weight:500;"><?php echo htmlspecialchars($l['contact_name']); ?></span>
                            </div>
                        </td>
                        <td><code class="cv-phone"><?php echo htmlspecialchars($l['phone']); ?></code></td>
                        <td><span class="cv-log-badge <?php echo $lsc; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:16px;"><?php echo $statusIcon; ?></span>
                                <span style="font-size:12px;color:var(--text-secondary);"><?php echo $statusText; ?></span>
                            </div>
                        </td>
                        <td class="cv-date"><?php echo $l['sent_at'] ? date('d M, H:i', strtotime($l['sent_at'])) : '—'; ?></td>
                        <td class="cv-date"><?php echo $l['wa_delivery_ts'] ? date('d M, H:i', strtotime($l['wa_delivery_ts'])) : '—'; ?></td>
                        <td>
                            <?php if ($l['status'] === 'failed' && ($l['retry_count'] ?? 0) < 3): ?>
                                <div style="font-size:11px;color:var(--text-secondary);">
                                    <div>Retry: <?php echo ($l['retry_count'] ?? 0); ?>/3</div>
                                    <?php if ($l['retry_after']): ?>
                                        <div>Next: <?php echo date('H:i', strtotime($l['retry_after'])); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($l['last_error_message']) && $l['last_error_message'] !== '—'): ?>
                                <span class="cv-error-msg" title="<?php echo htmlspecialchars($l['last_error_message']); ?>"><?php echo htmlspecialchars(mb_strimwidth($l['last_error_message'], 0, 50, '...')); ?></span>
                            <?php elseif (!empty($l['error_message']) && $l['error_message'] !== '—'): ?>
                                <span class="cv-error-msg" title="<?php echo htmlspecialchars($l['error_message']); ?>"><?php echo htmlspecialchars(mb_strimwidth($l['error_message'], 0, 50, '...')); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="cv-empty-log">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                        <span>No delivery logs yet</span>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* ============ CAMPAIGN VIEW PREMIUM ============ */

/* Toast */
.cv-toast { display:flex;align-items:center;gap:10px;padding:14px 20px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;animation:cvSlideIn .4s ease; }
.cv-toast-success { background:rgba(0,200,83,0.08);border:1px solid rgba(0,200,83,0.2);color:#00c853; }
.cv-toast-info { background:rgba(108,92,231,0.08);border:1px solid rgba(108,92,231,0.2);color:#a78bfa; }
.cv-toast-icon { font-size:18px; }

/* Header */
.cv-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:16px; }
.cv-header-left { display:flex;flex-direction:column;gap:8px; }
.cv-back-link { display:inline-flex;align-items:center;gap:4px;font-size:13px;color:var(--text-muted);text-decoration:none;transition:color .2s; }
.cv-back-link:hover { color:var(--accent); }
.cv-title-row { display:flex;align-items:center;gap:12px;flex-wrap:wrap; }
.cv-title { font-size:26px;font-weight:800;letter-spacing:-0.5px;color:var(--text-primary);margin:0; }
.cv-status-badge { display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:5px 14px;border-radius:20px; }
.cv-id-badge { font-size:12px;color:var(--text-muted);background:var(--bg-input);padding:4px 10px;border-radius:6px;font-weight:600; }
.cv-header-actions { display:flex;gap:10px;align-items:center;padding-top:28px; }
.cv-btn { display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .2s; }
.cv-btn-glow { background:linear-gradient(135deg,#6c5ce7,#a855f7);color:#fff;box-shadow:0 4px 20px rgba(108,92,231,0.4); }
.cv-btn-glow:hover { transform:translateY(-2px);box-shadow:0 6px 30px rgba(108,92,231,0.5); }

/* Overview: Ring + Stats */
.cv-overview { display:grid;grid-template-columns:280px 1fr;gap:24px;margin-bottom:28px; }
.cv-ring-card { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;display:flex;flex-direction:column;align-items:center;gap:20px; }
.cv-ring-wrap { position:relative;width:140px;height:140px; }
.cv-ring-svg { width:100%;height:100%; }
.cv-ring-progress { transition:stroke-dasharray 1s ease; }
.cv-ring-center { position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center; }
.cv-ring-pct { font-size:28px;font-weight:800;color:var(--text-primary);letter-spacing:-1px; }
.cv-ring-label { font-size:11px;color:var(--text-muted);font-weight:500;text-transform:uppercase;letter-spacing:1px; }
.cv-ring-meta { display:flex;flex-direction:column;gap:8px;width:100%; }
.cv-ring-meta-row { display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary); }
.cv-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }

/* Stat cards */
.cv-stat-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:16px; }
.cv-stat { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;position:relative;overflow:hidden;transition:all .3s; }
.cv-stat::after { content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent-c);opacity:0;transition:opacity .3s; }
.cv-stat:hover { border-color:color-mix(in srgb, var(--accent-c) 30%, transparent);transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2); }
.cv-stat:hover::after { opacity:1; }
.cv-stat-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:14px;background:color-mix(in srgb, var(--accent-c) 12%, transparent);color:var(--accent-c); }
.cv-stat-value { font-size:26px;font-weight:800;color:var(--text-primary);letter-spacing:-0.5px;margin-bottom:2px; }
.cv-stat-label { font-size:12px;color:var(--text-muted);font-weight:500; }

/* Details Grid */
.cv-details-grid { display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px; }
.cv-card { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;overflow:hidden; }
.cv-card-full { grid-column:1/-1; }
.cv-card-header { display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;color:var(--text-primary);margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border); }
.cv-card-header span:last-child:not(:nth-child(2)) { margin-left:auto; }

/* Detail list */
.cv-detail-list { display:flex;flex-direction:column; }
.cv-detail-row { display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(42,45,62,0.5); }
.cv-detail-row:last-child { border-bottom:none; }
.cv-detail-key { font-size:13px;color:var(--text-muted); }
.cv-detail-val { font-size:13px;color:var(--text-primary);font-weight:500; }

/* WhatsApp Preview */
.cv-wa-phone { border-radius:16px;overflow:hidden;border:1px solid var(--border); }
.cv-wa-topbar { display:flex;align-items:center;gap:10px;padding:12px 16px;background:#075e54; }
.cv-wa-avatar { width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6c5ce7,#a855f7);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff; }
.cv-wa-body { background:#0b141a;padding:16px;min-height:160px; }
.cv-wa-header { font-size:13px;font-weight:600;color:#e4e6f0;margin-bottom:8px;padding:8px 12px;background:rgba(255,255,255,0.05);border-radius:8px; }
.cv-wa-bubble { background:#1f2c34;border-radius:0 10px 10px 10px;padding:12px 14px;max-width:95%;position:relative; }
.cv-wa-bubble::before { content:'';position:absolute;top:0;left:-6px;width:0;height:0;border-top:6px solid #1f2c34;border-left:6px solid transparent; }
.cv-wa-text { font-size:13px;color:#e4e6eb;line-height:1.6;white-space:pre-wrap; }
.cv-wa-footer { font-size:11px;color:rgba(255,255,255,0.4);margin-top:6px;font-style:italic; }
.cv-wa-time { text-align:right;font-size:10px;color:rgba(255,255,255,0.35);margin-top:4px; }

/* Log Table */
.cv-log-count { margin-left:auto;font-size:12px;font-weight:500;color:var(--text-muted);background:var(--bg-input);padding:3px 10px;border-radius:6px; }
.cv-log-table-wrap { overflow-x:auto;margin:0 -24px;padding:0 24px; }
.cv-log-table { width:100%;border-collapse:collapse; }
.cv-log-table th { text-align:left;padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);border-bottom:1px solid var(--border);background:rgba(108,92,231,0.03); }
.cv-log-table td { padding:14px 16px;font-size:13px;border-bottom:1px solid rgba(42,45,62,0.4);color:var(--text-secondary); }
.cv-log-table tr:last-child td { border-bottom:none; }
.cv-log-table tr:hover td { background:rgba(108,92,231,0.03); }

.cv-contact-cell { display:flex;align-items:center;gap:10px; }
.cv-contact-avatar { width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#a855f7);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0; }
.cv-phone { background:var(--bg-input);padding:3px 8px;border-radius:4px;font-size:12px;color:var(--text-secondary); }
.cv-date { font-size:12px;color:var(--text-muted); }
.cv-error-msg { font-size:12px;color:#ff4757;background:rgba(255,71,87,0.08);padding:4px 8px;border-radius:4px;display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

/* Log status badges */
.cv-log-badge { display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600; }
.cv-status-pending { background:rgba(255,152,0,0.10);color:#ff9800; }
.cv-status-processing { background:rgba(33,150,243,0.10);color:#2196f3; }
.cv-status-sent { background:rgba(33,150,243,0.10);color:#2196f3; }
.cv-status-delivered { background:rgba(0,200,83,0.10);color:#00c853; }
.cv-status-read { background:rgba(167,139,250,0.10);color:#a78bfa; }
.cv-status-failed { background:rgba(255,71,87,0.10);color:#ff4757; }

.cv-empty-log { text-align:center;padding:48px 20px !important;color:var(--text-muted);display:flex;flex-direction:column;align-items:center;gap:8px; }

/* Animation */
@keyframes cvSlideIn { from { opacity:0;transform:translateY(-10px); } to { opacity:1;transform:translateY(0); } }

/* Responsive */
@media(max-width:900px) {
    .cv-overview { grid-template-columns:1fr; }
    .cv-stat-grid { grid-template-columns:repeat(2,1fr); }
    .cv-details-grid { grid-template-columns:1fr; }
    .cv-header { flex-direction:column; }
    .cv-header-actions { padding-top:0; }
}
@media(max-width:600px) {
    .cv-stat-grid { grid-template-columns:1fr; }
    .cv-title { font-size:20px; }
}
</style>

<?php include '../includes/admin_footer.php'; ?>
