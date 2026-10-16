<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Missed Call Auto-Reply';
$currentPage = 'missed_call';

// Migration handled schema change

// Migration handled schema change

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
    csrf_verify();
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $msg     = trim($_POST['message']);
    $delay   = max(0, intval($_POST['delay_seconds']??5));
    $track   = isset($_POST['track_calls']) ? 1 : 0;
    $stmt    = $conn->prepare("INSERT INTO missed_call_settings (company_id,is_active,message,delay_seconds,track_calls) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),message=VALUES(message),delay_seconds=VALUES(delay_seconds),track_calls=VALUES(track_calls)");
    $stmt->bind_param("isiii",$companyId,$msg,$active,$delay,$track);
    $stmt->execute();
    header("Location: missed_call.php?msg=saved"); exit();
}

$stmt_s = $conn->prepare("SELECT * FROM missed_call_settings WHERE company_id=?");
$stmt_s->bind_param("i",$companyId); $stmt_s->execute();
$settings = $stmt_s->get_result()->fetch_assoc();

$stmt_log = $conn->prepare("SELECT ml.*, c.name as contact_name FROM missed_calls_log ml LEFT JOIN contacts c ON ml.contact_id=c.id WHERE ml.company_id=? ORDER BY ml.id DESC LIMIT 20");
$stmt_log->bind_param("i",$companyId); $stmt_log->execute();
$logs = $stmt_log->get_result();

// Stats
$stmt_st = $conn->prepare("SELECT COUNT(*) as total, SUM(wa_sent) as sent FROM missed_calls_log WHERE company_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$stmt_st->bind_param("i",$companyId); $stmt_st->execute();
$stats = $stmt_st->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Missed Call → WhatsApp Auto-Reply</h2></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Settings</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                <input type="checkbox" name="is_active" id="mc_active" <?php echo ($settings['is_active']??1)?'checked':''; ?>>
                <label for="mc_active" style="margin:0;font-weight:600;font-size:13px;">Enable Missed Call Auto-Reply</label>
            </div>
            <div class="form-group">
                <label>Auto-Reply Message</label>
                <textarea name="message" class="form-control" rows="5"><?php echo htmlspecialchars($settings['message']??"Hi {{first_name}}! <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><circle cx='12' cy='12' r='10'/><path d='M8 14s1.5 2 4 2 4-2 4-2'/><line x1='9' x2='9.01' y1='9' y2='9'/><line x1='15' x2='15.01' y1='9' y2='9'/></svg>\n\nYou tried calling us at *{{business_name}}*.\n\nWe missed your call! How can we help you?\n\nReply here and we'll get back to you right away. <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='display:inline;vertical-align:middle;flex-shrink:0'><circle cx='12' cy='12' r='10'/><path d='M8 14s1.5 2 4 2 4-2 4-2'/><line x1='9' x2='9.01' y1='9' y2='9'/><line x1='15' x2='15.01' y1='9' y2='9'/></svg>"); ?></textarea>
                <small style="color:var(--text-muted);">Use {{first_name}}, {{business_name}}</small>
            </div>
            <div class="form-group">
                <label>Delay after missed call (seconds)</label>
                <input type="number" name="delay_seconds" class="form-control" value="<?php echo $settings['delay_seconds']??5; ?>" min="0" max="300">
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                <input type="checkbox" name="track_calls" id="mc_track" <?php echo ($settings['track_calls']??1)?'checked':''; ?>>
                <label for="mc_track" style="margin:0;font-size:13px;">Log all missed calls</label>
            </div>
            <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
        </form>
    </div>

    <!-- Integration Guide -->
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Integration Guide</h3>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;">When someone misses a call to your business number, send their phone number to this API:</p>
        <pre style="background:var(--bg-input);border-radius:8px;padding:12px;font-size:11px;overflow-x:auto;">POST /api/missed_call_trigger.php
{
  "api_key": "YOUR_API_KEY",
  "phone": "919876543210"
}</pre>
        <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Works with: Exotel, Knowlarity, MyOperator, Tata Tele, Ozonetel</p>
    </div>
</div>

<div class="form-card" style="max-width:none;">
    <div style="display:flex;gap:12px;margin-bottom:16px;">
        <div style="flex:1;text-align:center;background:var(--bg-input);border-radius:10px;padding:12px;">
            <div style="font-size:20px;font-weight:800;color:#6c63ff;"><?php echo $stats['total']??0; ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Missed Calls (30d)</div>
        </div>
        <div style="flex:1;text-align:center;background:var(--bg-input);border-radius:10px;padding:12px;">
            <div style="font-size:20px;font-weight:800;color:#00b894;"><?php echo $stats['sent']??0; ?></div>
            <div style="font-size:11px;color:var(--text-muted);">WA Sent</div>
        </div>
    </div>
    <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Recent Missed Calls</h3>
    <?php while ($log = $logs->fetch_assoc()): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px;">
        <div>
            <strong><?php echo $log['contact_name'] ?? $log['phone']; ?></strong><br>
            <span style="color:var(--text-muted);"><?php echo date('d M h:i A', strtotime($log['created_at'])); ?></span>
        </div>
        <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:<?php echo $log['wa_sent']?'rgba(0,184,148,0.1)':'rgba(231,76,60,0.1)'; ?>;color:<?php echo $log['wa_sent']?'#00b894':'#e74c3c'; ?>;">
            <?php echo $log['wa_sent'] ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> WA Sent' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending'; ?>
        </span>
    </div>
    <?php endwhile; ?>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>
