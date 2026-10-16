<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Pincode/Area Campaign';
$currentPage = 'pincode_campaign';

// Add columns if missing
// Migration handled schema change
// Migration handled schema change
// Migration handled schema change

// Handle send
if (isset($_POST['send_campaign'])) {
    csrf_verify();
    $msg = trim((string)($_POST['message'] ?? ''));
    $msg = preg_replace('/\r\n?/', "\n", $msg);
    $pincodes = array_values(array_filter(array_map(static function ($value) {
        $value = preg_replace('/\D+/', '', trim((string)$value));
        return $value !== '' ? $value : null;
    }, explode(',', (string)($_POST['pincodes'] ?? '')))));
    $cities = array_values(array_filter(array_map(static function ($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^\p{L}\p{N}\s\-.,&()]/u', '', $value);
        return $value !== '' ? $value : null;
    }, explode(',', (string)($_POST['cities'] ?? '')))));
    $areas = array_values(array_filter(array_map(static function ($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^\p{L}\p{N}\s\-.,&()]/u', '', $value);
        return $value !== '' ? $value : null;
    }, explode(',', (string)($_POST['areas'] ?? '')))));

    $where = ["company_id=?", "opted_in=1", "status!='blocked'"];
    $params = [$companyId]; $types = "i";
    $filterConds = [];
    if ($pincodes) { $ph = implode(',', array_fill(0, count($pincodes), '?')); $filterConds[] = "pincode IN ($ph)"; foreach ($pincodes as $p) { $params[]=$p; $types.='s'; } }
    if ($cities) { $ph = implode(',', array_fill(0, count($cities), '?')); $filterConds[] = "city IN ($ph)"; foreach ($cities as $c) { $params[]=$c; $types.='s'; } }
    if ($areas) { $ph = implode(',', array_fill(0, count($areas), '?')); $filterConds[] = "area IN ($ph)"; foreach ($areas as $a) { $params[]=$a; $types.='s'; } }
    if ($filterConds) $where[] = '(' . implode(' OR ', $filterConds) . ')';

    $sql = "SELECT id, name FROM contacts WHERE " . implode(' AND ', $where) . " LIMIT 2000";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $contacts = $stmt->get_result();
    $sent = 0;
    while ($ct = $contacts->fetch_assoc()) {
        $firstName = explode(' ',$ct['name'])[0];
        $finalMsg = str_replace('{{first_name}}', $firstName, $msg);
        $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,NOW())");
        $stmt_q->bind_param("iis",$companyId,$ct['id'],$finalMsg);
        $stmt_q->execute(); $sent++;
    }
    header("Location: pincode_campaign.php?msg=sent&count=$sent"); exit();
}

// Get unique pincodes/cities
$stmt_pc = $conn->prepare("SELECT DISTINCT pincode FROM contacts WHERE company_id=? AND pincode IS NOT NULL AND pincode!='' ORDER BY pincode");
$stmt_pc->bind_param("i",$companyId); $stmt_pc->execute();
$pincodeList = $stmt_pc->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt_ci = $conn->prepare("SELECT DISTINCT city FROM contacts WHERE company_id=? AND city IS NOT NULL AND city!='' ORDER BY city");
$stmt_ci->bind_param("i",$companyId); $stmt_ci->execute();
$cityList = $stmt_ci->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Sent to <?php echo (int)($_GET['count'] ?? 0); ?> contacts!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg> Pincode / Area Campaign</h2></div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
    <?php echo csrf_field(); ?>
    <h3 style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--accent);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Target Location</h3>
    <div class="form-group">
        <label>Pincodes (comma separated)</label>
        <input type="text" name="pincodes" class="form-control" placeholder="600001, 600002, 641001">
        <?php if ($pincodeList): ?>
        <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach ($pincodeList as $pc): ?><span onclick="addToField('pincodes','<?php echo htmlspecialchars((string)$pc['pincode'], ENT_QUOTES, 'UTF-8'); ?>')" style="font-size:11px;padding:2px 8px;border-radius:20px;background:var(--bg-input);border:1px solid var(--border);cursor:pointer;"><?php echo htmlspecialchars((string)$pc['pincode'], ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label>Cities (comma separated)</label>
        <input type="text" name="cities" class="form-control" placeholder="Chennai, Coimbatore, Madurai">
        <?php if ($cityList): ?>
        <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach ($cityList as $ci): ?><span onclick="addToField('cities','<?php echo htmlspecialchars((string)$ci['city'], ENT_QUOTES, 'UTF-8'); ?>')" style="font-size:11px;padding:2px 8px;border-radius:20px;background:var(--bg-input);border:1px solid var(--border);cursor:pointer;"><?php echo htmlspecialchars((string)$ci['city'], ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="form-group"><label>Areas (comma separated)</label><input type="text" name="areas" class="form-control" placeholder="Anna Nagar, T Nagar, Velachery"></div>
    <div class="form-group">
        <label>Message</label>
        <textarea name="message" class="form-control" rows="5" id="msgArea" required placeholder="Hi {{first_name}}! Special offer for your area..."></textarea>
        <small style="color:var(--text-muted);">{{first_name}}</small>
    </div>
    <button type="button" onclick="previewCount()" class="btn btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg> Preview Count</button>
    <button type="submit" name="send_campaign" class="btn btn-primary" style="margin-left:8px;" onclick="return confirm('Send to filtered contacts?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Send Campaign</button>
    </form>
</div>

<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Contact Distribution</h3>
        <?php
        $stmt_dist = $conn->prepare("SELECT city, COUNT(*) as c FROM contacts WHERE company_id=? AND city IS NOT NULL AND city!='' GROUP BY city ORDER BY c DESC LIMIT 10");
        $stmt_dist->bind_param("i",$companyId); $stmt_dist->execute();
        $dist = $stmt_dist->get_result();
        while ($d = $dist->fetch_assoc()): ?>
        <div style="display:flex;justify-content:space-between;font-size:12px;padding:5px 0;border-bottom:1px solid var(--border);">
            <span><?php echo htmlspecialchars($d['city']); ?></span>
            <strong><?php echo $d['c']; ?></strong>
        </div>
        <?php endwhile; ?>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> How to add location data</h3>
        <p style="font-size:12px;color:var(--text-secondary);">Import contacts with pincode/city columns, or edit individual contacts to add location.</p>
    </div>
</div>
</div>
<script>
function addToField(fieldName, val) {
    var el = document.querySelector('[name="'+fieldName+'"]');
    var cur = el.value.trim();
    if (cur && !cur.split(',').map(s=>s.trim()).includes(val)) el.value = cur + ', ' + val;
    else if (!cur) el.value = val;
}
</script>
<?php include '../includes/client_footer.php'; ?>
