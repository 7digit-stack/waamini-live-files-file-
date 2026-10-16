<?php
include '../includes/client_auth_check.php';

// Replaced by the Festival Reminders calendar (client/festival_campaigns.php), which uses real dates.
header('Location: ' . app_url('client/festival_campaigns.php'));
exit();
$pageTitle = 'Festival Campaign Calendar';
$currentPage = 'festival_calendar';

// Migration handled schema change

// Pre-loaded Indian festivals for current year
$year = date('Y');
$festivals = [
    // National
    ['Pongal', "$year-01-14", 'tamil'],
    ['Republic Day', "$year-01-26", 'all'],
    ['Maha Shivaratri', "$year-02-26", 'all'],
    ['Holi', "$year-03-14", 'north'],
    ['Ugadi', "$year-03-30", 'karnataka'],
    ['Tamil New Year', "$year-04-14", 'tamil'],
    ['Vishu', "$year-04-14", 'kerala'],
    ['Eid ul-Fitr', "$year-03-31", 'all'],
    ['Ramadan Start', "$year-03-01", 'all'],
    ['Good Friday', "$year-04-18", 'all'],
    ['Easter', "$year-04-20", 'all'],
    ['Mothers Day', "$year-05-11", 'all'],
    ['Eid ul-Adha', "$year-06-07", 'all'],
    ['Independence Day', "$year-08-15", 'all'],
    ['Raksha Bandhan', "$year-08-09", 'north'],
    ['Janmashtami', "$year-08-16", 'all'],
    ['Ganesh Chaturthi', "$year-08-27", 'all'],
    ['Onam', "$year-09-05", 'kerala'],
    ['Navratri Start', "$year-09-22", 'all'],
    ['Dussehra', "$year-10-02", 'all'],
    ['Gandhi Jayanti', "$year-10-02", 'all'],
    ['Diwali', "$year-10-20", 'all'],
    ['Bhai Dooj', "$year-10-23", 'all'],
    ['Muharram', "$year-07-07", 'all'],
    ['Milad-un-Nabi', "$year-09-05", 'all'],
    ['Christmas', "$year-12-25", 'all'],
    ['New Year', "$year-12-31", 'all'],
    // Tamil Nadu Specific
    ['Karthigai Deepam', "$year-12-04", 'tamil'],
    ['Aadi Perukku', "$year-08-03", 'tamil'],
    ['Chithirai Festival', "$year-04-14", 'tamil'],
    ['Kaveri Pushkaram', "$year-10-17", 'tamil'],
];

// Handle save campaign
if (isset($_POST['save_campaign'])) {
    csrf_verify();
    $fname  = sanitize($_POST['festival_name']);
    $fdate  = $_POST['festival_date'];
    $fregion= sanitize($_POST['festival_region']??'all');
    $msg    = trim($_POST['message']);
    $days   = max(0, intval($_POST['days_before']??1));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $stmt   = $conn->prepare("INSERT INTO festival_campaigns (company_id,festival_name,festival_date,festival_region,message,days_before,is_active) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("issssii", $companyId,$fname,$fdate,$fregion,$msg,$days,$active);
    $stmt->execute();
    header("Location: festival_calendar.php?msg=saved"); exit();
}

// Handle delete
if (isset($_POST['delete_campaign'])) {
    csrf_verify();
    $did = intval($_POST['campaign_id']);
    $stmt_d = $conn->prepare("DELETE FROM festival_campaigns WHERE id=? AND company_id=?");
    if ($stmt_d) {
        $stmt_d->bind_param("ii", $did, $companyId);
        $stmt_d->execute();
    } else {
        error_log('[WAAMINI] festival_calendar.php: prepare() failed for delete');
    }
    header("Location: festival_calendar.php"); exit();
}

// Handle toggle
if (isset($_POST['toggle_campaign'])) {
    csrf_verify();
    $tid = intval($_POST['campaign_id']);
    $stmt_t = $conn->prepare("UPDATE festival_campaigns SET is_active = 1-is_active WHERE id=? AND company_id=?");
    if ($stmt_t) {
        $stmt_t->bind_param("ii", $tid, $companyId);
        $stmt_t->execute();
    } else {
        error_log('[WAAMINI] festival_calendar.php: prepare() failed for toggle');
    }
    header("Location: festival_calendar.php"); exit();
}

// Handle bulk install
if (isset($_POST['install_festivals'])) {
    csrf_verify();
    $region = sanitize($_POST['install_region']??'all');
    $defaultMsg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> *{festival}* wishes from *{{business_name}}*!' . "\n\nWishing you and your family a wonderful celebration! " . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>' . "\n\nFor special offers, reply *OFFER*";
    $installed = 0;
    foreach ($festivals as [$name, $date, $reg]) {
        if ($region === 'all' || $reg === 'all' || $reg === $region) {
            $msg = str_replace('{festival}', $name, $defaultMsg);
            $stmt_i = $conn->prepare("INSERT IGNORE INTO festival_campaigns (company_id,festival_name,festival_date,festival_region,message,days_before,is_active) VALUES (?,?,?,?,?,1,1)");
            if ($stmt_i) {
                $stmt_i->bind_param("issss", $companyId, $name, $date, $reg, $msg);
                $stmt_i->execute();
                $installed++;
            } else {
                error_log('[WAAMINI] festival_calendar.php: prepare() failed for install');
            }
        }
    }
    header("Location: festival_calendar.php?msg=installed&count=$installed"); exit();
}

// Load saved campaigns
$stmt_fc = $conn->prepare("SELECT * FROM festival_campaigns WHERE company_id=? ORDER BY festival_date ASC");
$campaigns = null;
if ($stmt_fc) {
    $stmt_fc->bind_param("i", $companyId);
    $stmt_fc->execute();
    $campaigns = $stmt_fc->get_result();
} else {
    error_log('[WAAMINI] festival_calendar.php: prepare() failed for campaigns list');
}

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo (isset($_GET['msg']) && $_GET['msg']==='installed') ? intval($_GET['count']??0)." festivals installed!" : "Saved!"; ?></div>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Festival Campaign Calendar</h2>
    <div style="display:flex;gap:8px;">
        <button onclick="document.getElementById('installModal').style.display='flex'" class="btn btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Auto-Install Festivals</button>
        <button onclick="document.getElementById('addModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Custom</button>
    </div>
</div>

<!-- Stats bar -->
<?php
$stmt_st = $conn->prepare("SELECT COUNT(*) as total, SUM(is_active) as active, SUM(sent_at IS NOT NULL) as sent FROM festival_campaigns WHERE company_id=?");
$st = ['total' => 0, 'active' => 0, 'sent' => 0];
if ($stmt_st) {
    $stmt_st->bind_param("i", $companyId);
    $stmt_st->execute();
    $st = $stmt_st->get_result()->fetch_assoc() ?: $st;
} else {
    error_log('[WAAMINI] festival_calendar.php: prepare() failed for stats');
}
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
    <?php foreach ([['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>',$st['total']??0,'Total Festivals','#6c63ff'],['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',$st['active']??0,'Active','#00b894'],['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',$st['sent']??0,'Sent This Year','#f39c12']] as [$icon,$val,$label,$color]): ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;">
        <div style="font-size:24px;"><?php echo $icon; ?></div>
        <div style="font-size:24px;font-weight:800;color:<?php echo $color; ?>;"><?php echo $val; ?></div>
        <div style="font-size:11px;color:var(--text-muted);"><?php echo $label; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Upcoming festivals preview -->
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:24px;">
    <div style="font-size:13px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Upcoming Festivals (Next 60 days)</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php foreach ($festivals as [$fname,$fdate,$freg]):
        $daysLeft = (strtotime($fdate) - time()) / 86400;
        if ($daysLeft >= 0 && $daysLeft <= 60):
    ?>
    <div style="padding:6px 12px;border-radius:20px;background:rgba(108,99,255,0.1);border:1px solid rgba(108,99,255,0.2);font-size:12px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> <?php echo $fname; ?> — <strong><?php echo date('d M', strtotime($fdate)); ?></strong>
        <span style="color:var(--accent);margin-left:4px;"><?php echo ceil($daysLeft); ?>d</span>
    </div>
    <?php endif; endforeach; ?>
    </div>
</div>

<!-- Campaigns table -->
<div class="form-card" style="max-width:none;">
    <div class="data-table-wrapper">
    <table class="data-table">
        <thead><tr><th>Festival</th><th>Date</th><th>Region</th><th>Send Before</th><th>Status</th><th>Sent</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$campaigns || $campaigns->num_rows === 0): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
            No festivals yet — Click "Auto-Install Festivals" to get started! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
        </td></tr>
        <?php else: while ($fc = $campaigns->fetch_assoc()):
            $daysLeft = (strtotime($fc['festival_date']) - time()) / 86400;
            $isPast = $daysLeft < 0;
        ?>
        <tr style="<?php echo $isPast ? 'opacity:0.5;' : ''; ?>">
            <td><strong><?php echo htmlspecialchars($fc['festival_name']); ?></strong></td>
            <td><?php echo date('d M Y', strtotime($fc['festival_date'])); ?>
                <?php if (!$isPast && $daysLeft <= 7): ?><span style="margin-left:6px;padding:2px 8px;border-radius:10px;background:rgba(231,76,60,0.1);color:#e74c3c;font-size:10px;font-weight:700;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <?php echo ceil($daysLeft); ?> days</span><?php endif; ?>
            </td>
            <td><span style="padding:2px 8px;border-radius:10px;background:rgba(108,99,255,0.1);color:#6c63ff;font-size:11px;"><?php echo ucfirst($fc['festival_region']); ?></span></td>
            <td><?php echo $fc['days_before']; ?> day<?php echo $fc['days_before']>1?'s':''; ?> before</td>
            <td>
                <form method="POST" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="campaign_id" value="<?php echo $fc['id']; ?>">
                    <button type="submit" name="toggle_campaign" style="padding:3px 12px;border-radius:20px;border:none;cursor:pointer;font-size:11px;font-weight:700;background:<?php echo $fc['is_active']?'rgba(0,184,148,0.15)':'rgba(149,165,166,0.15)'; ?>;color:<?php echo $fc['is_active']?'#00b894':'#95a5a6'; ?>;">
                        <?php echo $fc['is_active'] ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Active' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg> Paused'; ?>
                    </button>
                </form>
            </td>
            <td><?php echo $fc['sent_at'] ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> '.date('d M', strtotime($fc['sent_at'])) : '—'; ?></td>
            <td>
                <form method="POST" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="campaign_id" value="<?php echo $fc['id']; ?>">
                    <button type="submit" name="delete_campaign" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                </form>
            </td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Install Modal -->
<div id="installModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:440px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Auto-Install Festival Campaigns</h3>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:20px;">Select your region — all festivals auto-install with default messages. You can edit later!</p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Region</label>
            <select name="install_region" class="form-control">
                <option value="all"> All India (National festivals)</option>
                <option value="tamil"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg> Tamil Nadu</option>
                <option value="kerala">Kerala</option>
                <option value="karnataka">Karnataka</option>
                <option value="north">North India</option>
            </select>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="document.getElementById('installModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="install_festivals" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Install Now</button>
        </div>
    </form>
</div>
</div>

<!-- Add Custom Modal -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:500px;max-width:90vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Festival Campaign</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group"><label>Festival Name</label><input type="text" name="festival_name" class="form-control" required placeholder="e.g. Pongal"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label>Date</label><input type="date" name="festival_date" class="form-control" required></div>
            <div class="form-group"><label>Send Before (days)</label><input type="number" name="days_before" class="form-control" value="1" min="0" max="7"></div>
        </div>
        <div class="form-group">
            <label>Region</label>
            <select name="festival_region" class="form-control">
                <option value="all">All India</option>
                <option value="tamil">Tamil Nadu</option>
                <option value="kerala">Kerala</option>
                <option value="karnataka">Karnataka</option>
                <option value="north">North India</option>
            </select>
        </div>
        <div class="form-group">
            <label>Message</label>
            <textarea name="message" class="form-control" rows="4" required placeholder="Use {{business_name}}, {{first_name}}"></textarea>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="is_active" id="fc_active" checked>
            <label for="fc_active" style="margin:0;font-size:13px;">Active</label>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="save_campaign" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
