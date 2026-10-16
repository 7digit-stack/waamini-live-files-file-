<?php
include '../includes/client_auth_check.php';
if (!has_feature('campaigns')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Campaigns';
$currentPage = 'campaigns';

// Handle single delete - POST+CSRF
if (isset($_POST['delete_id']) && empty($_POST['bulk_delete'])) {
    csrf_verify();
    if ($viewOnlyMode) {
        header("Location: campaigns.php?msg=expired_block");
        exit();
    }
    $did = intval($_POST['delete_id']);
    $stmt_del = $conn->prepare("DELETE FROM campaigns WHERE id=? AND company_id=?");
    $stmt_del->bind_param("ii", $did, $companyId);
    $stmt_del->execute();
    header("Location: campaigns.php?msg=deleted");
    exit();
}

// Handle bulk delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if ($viewOnlyMode) {
        header("Location: campaigns.php?msg=expired_block");
        exit();
    }
    csrf_verify();
    $ids = $_POST['selected_ids'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        // Sanitize IDs
        $clean_ids = array_map('intval', $ids);
        $in_clause = implode(',', $clean_ids);
        
        $delStmt = $conn->prepare("DELETE FROM campaigns WHERE id IN ($in_clause) AND company_id=?");
        $delStmt->bind_param("i", $companyId);
        $delStmt->execute();
        
        header("Location: campaigns.php?msg=bulk_deleted");
        exit();
    }
}


// Retry failed jobs for a campaign
if (isset($_POST['retry_campaign_id'])) {
    csrf_verify();
    if ($viewOnlyMode) {
        header("Location: campaigns.php?msg=expired_block");
        exit();
    }
    $cid = intval($_POST['retry_campaign_id']);
    // Ensure campaign belongs to company
    $chk = $conn->prepare("SELECT id FROM campaigns WHERE id=? AND company_id=? LIMIT 1");
    if ($chk) {
        $chk->bind_param("ii", $cid, $companyId);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $updQ = $conn->prepare("UPDATE campaign_queue SET status='pending', error_message=NULL, scheduled_at=NOW() WHERE campaign_id=? AND company_id=? AND status='failed'");
            if ($updQ) {
                $updQ->bind_param("ii", $cid, $companyId);
                $updQ->execute();
            }
            $updC = $conn->prepare("UPDATE campaigns SET status='running' WHERE id=? AND company_id=?");
            if ($updC) {
                $updC->bind_param("ii", $cid, $companyId);
                $updC->execute();
            }
        }
    }
    header("Location: campaigns.php?msg=retried");
    exit();
}

$st1 = $conn->prepare("SELECT c.*, mt.name as template_name FROM campaigns c LEFT JOIN message_templates mt ON c.template_id = mt.id WHERE c.company_id=? ORDER BY c.id DESC");
$st1->bind_param("i", $companyId);
$st1->execute();
$campaignsRes = $st1->get_result();
$campaigns = [];
while ($campaignsRes && ($row = $campaignsRes->fetch_assoc())) {
    $campaigns[] = $row;
}

// Delivery stats for every campaign in ONE grouped query (was one query per row)
$statsByCampaign = [];
try {
    $stStats = $conn->prepare("SELECT campaign_id,
            SUM(wa_delivery_status='delivered') AS delivered,
            SUM(wa_delivery_status='read') AS read_count,
            SUM(status='failed' AND retry_count < 3) AS failed_retry,
            SUM(status='failed' AND retry_count >= 3) AS failed_permanent
        FROM campaign_queue WHERE company_id=? GROUP BY campaign_id");
    if ($stStats) {
        $stStats->bind_param("i", $companyId);
        $stStats->execute();
        $rs = $stStats->get_result();
        while ($rs && ($sr = $rs->fetch_assoc())) {
            $statsByCampaign[(int)$sr['campaign_id']] = array_map('intval', $sr);
        }
    }
} catch (\Throwable $e) { /* stats stay empty */ }

// Page-level KPIs + status groups for the filter chips
$statusGroup = [
    'draft' => 'draft', 'queued' => 'active', 'running' => 'active', 'scheduled' => 'active', 'sending' => 'active',
    'completed' => 'completed', 'failed' => 'failed', 'cancelled' => 'failed',
];
$kpi = ['campaigns' => count($campaigns), 'sent' => 0, 'delivered' => 0, 'read' => 0];
$groupCounts = ['all' => count($campaigns), 'active' => 0, 'completed' => 0, 'draft' => 0, 'failed' => 0];
foreach ($campaigns as $c) {
    $ds = $statsByCampaign[(int)$c['id']] ?? ['delivered' => 0, 'read_count' => 0];
    $kpi['sent']      += (int)($c['sent_count'] ?? 0);
    $kpi['delivered'] += $ds['delivered'] + $ds['read_count'];
    $kpi['read']      += $ds['read_count'];
    $g = $statusGroup[$c['status']] ?? 'draft';
    $groupCounts[$g]++;
}
$deliveryRate = $kpi['sent'] > 0 ? min(100, round($kpi['delivered'] / $kpi['sent'] * 100)) : 0;
$readRate     = $kpi['sent'] > 0 ? min(100, round($kpi['read'] / $kpi['sent'] * 100)) : 0;

include '../includes/client_header.php';
?>

<style>
/* ── Campaigns — dashboard design system, no divider lines ── */
.cp{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.cp *{box-sizing:border-box}
.cp-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.cp-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.cp-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.cp-hero-ico svg{width:22px;height:22px}
.cp-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.cp-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:520px;line-height:1.5}
.cp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s;position:relative;z-index:1}
.cp-btn:hover{border-color:var(--g);color:var(--g)}
.cp-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.cp-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.cp-btn--muted{opacity:.55;cursor:not-allowed}
.cp-btn--danger{background:#fff;color:#c62828;border-color:#f5c6c6}
.cp-btn--danger:hover{background:#fdecec;border-color:#c62828;color:#c62828}

.cp-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;background:#FEF3DC;color:#7a4b00}
.cp-notice a{color:inherit;font-weight:700;text-decoration:underline}
.cp-notice.ok{background:#E3F7EE;color:#0B6B5E}
.cp-notice.err{background:#FDE7E7;color:#9b1c1c}

.cp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.cp-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.cp-kpi:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.cp-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.cp-kpi-ico svg{width:20px;height:20px}
.cp-kpi.is-blue .cp-kpi-ico{background:#E4EFFD;color:#1d6fd6}
.cp-kpi.is-amber .cp-kpi-ico{background:#FEF3DC;color:#b7791f}
.cp-kpi.is-purple .cp-kpi-ico{background:#EFE9FD;color:#6d44d8}
.cp-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.cp-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

.cp-panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);padding:18px 18px 12px}
.cp-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.cp-chips{display:flex;gap:8px;flex-wrap:wrap}
.cp-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;background:var(--soft);color:var(--tx);font:600 13.5px Inter,system-ui,sans-serif;border:0;cursor:pointer;transition:background .15s,color .15s}
.cp-chip:hover{background:#E7F7F1}
.cp-chip.on{background:var(--g);color:#fff}
.cp-chip em{font-style:normal;font-size:12px;opacity:.8}
.cp-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.cp-search{display:flex;align-items:center;gap:10px;background:var(--soft);border-radius:11px;padding:0 14px;height:40px;min-width:240px}
.cp-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0}
.cp-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.cp-search:focus-within{box-shadow:0 0 0 2px var(--g);background:#fff}

/* list (grid rows, spaced instead of lined) */
.cp-list{display:flex;flex-direction:column;gap:8px}
.cp-row{display:grid;grid-template-columns:36px minmax(190px,2.2fr) 120px minmax(150px,1.6fr) 74px 74px 74px 128px;gap:12px;align-items:center;padding:12px 14px;border-radius:13px;background:var(--soft);transition:background .15s,transform .15s}
.cp-row:not(.cp-head):hover{background:#E7F7F1}
.cp-head{background:transparent;padding-top:2px;padding-bottom:2px;font-size:12px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.cp-num{text-align:center}
.cp-row input[type=checkbox]{accent-color:var(--g);width:17px;height:17px;cursor:pointer}
.cp-name{font-weight:700;font-size:15px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-meta{font-size:12.5px;color:var(--mu);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-pill{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.cp-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.cp-pill.s-draft{background:#EEF3F1;color:#5d6f69}
.cp-pill.s-active{background:#E4EFFD;color:#1d6fd6}
.cp-pill.s-completed{background:#E3F7EE;color:#0B6B5E}
.cp-pill.s-failed{background:#FDE7E7;color:#c62828}
.cp-prog-top{display:flex;justify-content:space-between;font-size:12.5px;color:var(--tx);margin-bottom:5px;font-weight:600}
.cp-bar{height:7px;border-radius:99px;background:#DCEBE5;overflow:hidden}
.cp-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--g),var(--g2))}
.cp-stat{font-weight:700;font-size:15px;text-align:center}
.cp-stat small{display:none}
.cp-retry{display:inline-block;background:#FEF3DC;color:#a15c00;border-radius:8px;padding:2px 9px;font-size:12.5px;font-weight:700}
.cp-acts{display:flex;gap:6px;justify-content:flex-end}
.cp-ib{width:36px;height:36px;border-radius:10px;border:0;background:#fff;color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}
.cp-ib:hover{background:var(--g);color:#fff}
.cp-ib.danger:hover{background:#c62828}
.cp-ib.warn:hover{background:#b7791f}
.cp-ib svg{width:17px;height:17px}

.cp-empty{text-align:center;padding:48px 20px}
.cp-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 16px}
.cp-empty-ico svg{width:30px;height:30px}
.cp-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.cp-empty p{margin:0 0 18px;color:var(--mu);font-size:14.5px}
.cp-none{display:none;text-align:center;padding:30px;color:var(--mu);font-size:14.5px}

@media (max-width:1100px){.cp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:900px){
  .cp-head{display:none}
  .cp-row{grid-template-columns:30px 1fr auto;grid-template-areas:"chk name pill" "prog prog prog" "d r y" "act act act";row-gap:10px}
  .cp-row>.c-chk{grid-area:chk}.cp-row>.c-name{grid-area:name}.cp-row>.c-status{grid-area:pill}.cp-row>.c-prog{grid-area:prog}
  .cp-row>.c-act{grid-area:act}
  .cp-row>.cp-stat{text-align:left}.cp-stat small{display:block;font-size:11.5px;font-weight:600;color:var(--mu)}
  .cp-row>.c-prog{grid-column:1/-1}
}
@media (max-width:560px){
  .cp-hero{padding:18px}.cp-hero h1{font-size:21px}
  .cp-kpis{grid-template-columns:1fr}
  .cp-search{min-width:0;width:100%}.cp-tools{width:100%}
}
</style>

<div class="cp">

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'expired_block'): ?>
        <div class="cp-notice err">Action restricted. Your plan has expired. <a href="subscription.php">Renew now</a></div>
    <?php elseif ($_GET['msg'] === 'bulk_deleted'): ?>
        <div class="cp-notice ok">Selected campaigns deleted successfully.</div>
    <?php else: ?>
        <div class="cp-notice ok">Campaign <?php echo htmlspecialchars($_GET['msg']); ?>.</div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($viewOnlyMode): ?>
<div class="cp-notice"><strong>View-only mode:</strong> your plan has expired. You can review campaign history, but creating campaigns or sending is disabled. <a href="subscription.php">Renew your plan →</a></div>
<?php endif; ?>

<!-- Hero -->
<div class="cp-hero">
    <div class="cp-hero-l">
        <div class="cp-hero-ico"><?php echo cn_svg('campaigns'); ?></div>
        <div>
            <h1>Campaigns</h1>
            <p>Broadcast approved templates to your contacts and track delivery, reads and retries in one place.</p>
        </div>
    </div>
    <?php if ($viewOnlyMode): ?>
        <span class="cp-btn cp-btn--primary cp-btn--muted">Create campaign</span>
    <?php elseif (can_use('campaigns')): ?>
        <a href="campaign_create.php" class="cp-btn cp-btn--primary">+ Create campaign</a>
    <?php else: ?>
        <span class="cp-btn cp-btn--muted">Limit reached</span>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="cp-kpis">
    <div class="cp-kpi"><div class="cp-kpi-ico"><?php echo cn_svg('campaigns'); ?></div><div><div class="cp-kpi-v"><?php echo number_format($kpi['campaigns']); ?></div><div class="cp-kpi-l">Total campaigns</div></div></div>
    <div class="cp-kpi is-blue"><div class="cp-kpi-ico"><?php echo cn_svg('blast'); ?></div><div><div class="cp-kpi-v"><?php echo number_format($kpi['sent']); ?></div><div class="cp-kpi-l">Messages sent</div></div></div>
    <div class="cp-kpi is-amber"><div class="cp-kpi-ico"><?php echo cn_svg('chat'); ?></div><div><div class="cp-kpi-v"><?php echo $deliveryRate; ?>%</div><div class="cp-kpi-l">Delivery rate</div></div></div>
    <div class="cp-kpi is-purple"><div class="cp-kpi-ico"><?php echo cn_svg('analytics'); ?></div><div><div class="cp-kpi-v"><?php echo $readRate; ?>%</div><div class="cp-kpi-l">Read rate</div></div></div>
</div>

<!-- List -->
<form id="bulkDeleteForm" method="POST">
<?php echo csrf_field(); ?>
<input type="hidden" name="bulk_delete" value="1">
<div class="cp-panel">
<?php if (count($campaigns) > 0): ?>
    <div class="cp-toolbar">
        <div class="cp-chips" id="cpChips">
            <button type="button" class="cp-chip on" data-f="all">All <em><?php echo $groupCounts['all']; ?></em></button>
            <button type="button" class="cp-chip" data-f="active">Active <em><?php echo $groupCounts['active']; ?></em></button>
            <button type="button" class="cp-chip" data-f="completed">Completed <em><?php echo $groupCounts['completed']; ?></em></button>
            <button type="button" class="cp-chip" data-f="draft">Draft <em><?php echo $groupCounts['draft']; ?></em></button>
            <button type="button" class="cp-chip" data-f="failed">Failed <em><?php echo $groupCounts['failed']; ?></em></button>
        </div>
        <div class="cp-tools">
            <button type="button" id="bulk-actions" class="cp-btn cp-btn--danger" style="display:none;height:40px;" onclick="submitBulkDelete()">Delete selected (<span id="selCount">0</span>)</button>
            <label class="cp-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" id="cpSearch" placeholder="Search campaigns or templates" autocomplete="off">
            </label>
        </div>
    </div>

    <div class="cp-list" id="cpList">
        <div class="cp-row cp-head">
            <div class="c-chk"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" aria-label="Select all"></div>
            <div>Campaign</div><div>Status</div><div>Progress</div>
            <div class="cp-num">Sent</div><div class="cp-num">Delivered</div><div class="cp-num">Read</div><div></div>
        </div>
        <?php foreach ($campaigns as $c):
            $ds = $statsByCampaign[(int)$c['id']] ?? ['delivered' => 0, 'read_count' => 0, 'failed_retry' => 0, 'failed_permanent' => 0];
            $failedJobs = $ds['failed_retry'] + $ds['failed_permanent'];
            $total    = (int)($c['total_contacts'] ?? 0);
            $sent     = (int)($c['sent_count'] ?? 0);
            $progress = $total > 0 ? min(100, round($sent / $total * 100)) : 0;
            $group    = $statusGroup[$c['status']] ?? 'draft';
            $created  = !empty($c['created_at']) ? date('d M Y', strtotime((string)$c['created_at'])) : '';
        ?>
        <div class="cp-row cp-item" data-group="<?php echo $group; ?>" data-text="<?php echo htmlspecialchars(strtolower($c['name'] . ' ' . ($c['template_name'] ?? '')), ENT_QUOTES); ?>">
            <div class="c-chk"><input type="checkbox" name="selected_ids[]" value="<?php echo (int)$c['id']; ?>" class="row-checkbox" onchange="updateBulkActions()" aria-label="Select campaign"></div>
            <div class="c-name" style="min-width:0;">
                <div class="cp-name"><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="cp-meta">#<?php echo (int)$c['id']; ?> · <?php echo htmlspecialchars($c['template_name'] ?? 'No template'); ?><?php echo $created ? ' · ' . $created : ''; ?></div>
            </div>
            <div class="c-status"><span class="cp-pill s-<?php echo $group; ?>"><?php echo htmlspecialchars(ucfirst((string)$c['status'])); ?></span></div>
            <div class="c-prog">
                <div class="cp-prog-top"><span><?php echo number_format($sent); ?> / <?php echo number_format($total); ?></span><span><?php echo $progress; ?>%</span></div>
                <div class="cp-bar"><i style="width:<?php echo $progress; ?>%"></i></div>
            </div>
            <div class="cp-stat"><small>Sent</small><?php echo number_format($sent); ?></div>
            <div class="cp-stat"><small>Delivered</small><?php echo number_format($ds['delivered'] + $ds['read_count']); ?></div>
            <div class="cp-stat"><small>Read</small><?php echo number_format($ds['read_count']); ?>
                <?php if ($ds['failed_retry'] > 0): ?><span class="cp-retry" title="Retrying"><?php echo $ds['failed_retry']; ?> retrying</span><?php endif; ?>
            </div>
            <div class="c-act cp-acts">
                <a href="campaign_view.php?id=<?php echo (int)$c['id']; ?>" class="cp-ib" title="View details" aria-label="View details"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></a>
                <?php if ($failedJobs > 0 && !$viewOnlyMode): ?>
                    <button type="submit" form="retryForm_<?php echo (int)$c['id']; ?>" class="cp-ib warn" title="Retry failed sends" aria-label="Retry failed sends"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg></button>
                <?php endif; ?>
                <button type="submit" form="singleDeleteForm_<?php echo (int)$c['id']; ?>" class="cp-ib danger" title="Delete" aria-label="Delete campaign" onclick="return confirm('Delete campaign?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="cp-none" id="cpNone">No campaigns match your search.</div>
    </div>
<?php else: ?>
    <div class="cp-empty">
        <div class="cp-empty-ico"><?php echo cn_svg('campaigns'); ?></div>
        <h3>No campaigns yet</h3>
        <p>Create your first broadcast to reach your contacts on WhatsApp.</p>
        <?php if (!$viewOnlyMode && can_use('campaigns')): ?><a href="campaign_create.php" class="cp-btn cp-btn--primary">+ Create campaign</a><?php endif; ?>
    </div>
<?php endif; ?>
</div>
</form>

</div><!-- .cp -->

<?php
foreach ($campaigns as $c) {
    echo '<form id="singleDeleteForm_' . (int)$c['id'] . '" method="POST" style="display:none;">';
    echo csrf_field();
    echo '<input type="hidden" name="delete_id" value="' . (int)$c['id'] . '">';
    echo '</form>';

    echo '<form id="retryForm_' . (int)$c['id'] . '" method="POST" style="display:none;">';
    echo csrf_field();
    echo '<input type="hidden" name="retry_campaign_id" value="' . (int)$c['id'] . '">';
    echo '</form>';
}
?>

<script>
function toggleSelectAll(selectAllCheckbox) {
    document.querySelectorAll('.cp-item:not([hidden]) .row-checkbox').forEach(cb => cb.checked = selectAllCheckbox.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const checked = document.querySelectorAll('.row-checkbox:checked').length;
    const bulk = document.getElementById('bulk-actions');
    const cnt = document.getElementById('selCount');
    if (bulk) bulk.style.display = checked > 0 ? 'inline-flex' : 'none';
    if (cnt) cnt.textContent = checked;
    const all = document.getElementById('selectAllCheckbox');
    const total = document.querySelectorAll('.row-checkbox').length;
    if (all && total > 0) all.checked = (checked === total);
}

function submitBulkDelete() {
    if (confirm('Are you sure you want to delete all selected campaigns? This cannot be undone.')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}

// Filter chips + search
(function () {
    var chips = document.querySelectorAll('#cpChips .cp-chip');
    var items = document.querySelectorAll('.cp-item');
    var search = document.getElementById('cpSearch');
    var none = document.getElementById('cpNone');
    var filter = 'all';
    function apply() {
        var q = (search && search.value || '').toLowerCase().trim(), shown = 0;
        items.forEach(function (el) {
            var ok = (filter === 'all' || el.dataset.group === filter) && (!q || el.dataset.text.indexOf(q) !== -1);
            el.hidden = !ok;
            el.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        if (none) none.style.display = shown ? 'none' : 'block';
    }
    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            chips.forEach(function (x) { x.classList.remove('on'); });
            c.classList.add('on');
            filter = c.dataset.f;
            apply();
        });
    });
    if (search) search.addEventListener('input', apply);
})();
</script>

<?php include '../includes/client_footer.php'; ?>
