<?php
include '../includes/client_auth_check.php';
if (!has_feature('contacts')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Contacts';
$currentPage = 'contacts';

// Handle single delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contact'])) {
    if ($viewOnlyMode) {
        header("Location: contacts.php?msg=expired_block");
        exit();
    }
    csrf_verify();
    $did = intval($_POST['contact_id'] ?? 0);
    // IDOR protection: assert this contact belongs to current company
    assert_company_owns($conn, 'contacts', $did, $companyId);
    $delStmt = $conn->prepare("DELETE FROM contacts WHERE id=? AND company_id=?");
    $delStmt->bind_param("ii", $did, $companyId);
    $delStmt->execute();
    header("Location: contacts.php?msg=deleted");
    exit();
}

// Handle bulk delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if ($viewOnlyMode) {
        header("Location: contacts.php?msg=expired_block");
        exit();
    }
    csrf_verify();
    $ids = $_POST['selected_ids'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        // Sanitize IDs, then delete with bound placeholders (always scoped to this company)
        $clean_ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
        if ($clean_ids) {
            $ph = implode(',', array_fill(0, count($clean_ids), '?'));
            $delStmt = $conn->prepare("DELETE FROM contacts WHERE company_id=? AND id IN ($ph)");
            $delStmt->bind_param(str_repeat('i', count($clean_ids) + 1), $companyId, ...$clean_ids);
            $delStmt->execute();
        }
        
        header("Location: contacts.php?msg=bulk_deleted");
        exit();
    }
}


// ── Filters, search, pagination (all company-scoped, all bound parameters) ──
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$search  = trim((string)($_GET['search'] ?? ''));
$statusF = strtolower(trim((string)($_GET['status'] ?? '')));
$sourceF = strtolower(trim((string)($_GET['source'] ?? '')));
$hasCrm  = has_feature('crm_advance');

// Older installs lack some optional columns (e.g. contacts.source). Detect them once so the page
// adapts instead of failing on an unknown column.
$ctCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM contacts");
while ($colRes && ($cr = $colRes->fetch_assoc())) { $ctCols[strtolower((string)$cr['Field'])] = true; }
$hasSourceCol = isset($ctCols['source']);
$hasGroupCol  = isset($ctCols['group_id']);
$groupF = $hasGroupCol ? max(0, (int)($_GET['group'] ?? 0)) : 0;
$groupOpts = [];
if ($hasGroupCol) {
    $gs = $conn->prepare("SELECT id, name FROM contact_groups WHERE company_id=? ORDER BY name ASC");
    if ($gs) { $gs->bind_param("i", $companyId); $gs->execute(); $gres = $gs->get_result(); while ($gres && ($gr = $gres->fetch_assoc())) { $groupOpts[(int)$gr['id']] = (string)$gr['name']; } }
    if ($groupF && !isset($groupOpts[$groupF])) { $groupF = 0; }
}
$hasStatusCol = isset($ctCols['status']);
if (!$hasSourceCol) { $sourceF = ''; }

// Ads & Attribution filters: lead source (organic / ad / post) and a specific ad
$adsOn = has_feature('ads_attribution') && isset($ctCols['lead_source']);
$leadF = $adsOn && in_array($_GET['lead'] ?? '', ['organic', 'ad', 'post'], true) ? (string)$_GET['lead'] : '';
$adF = $adsOn ? substr(preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($_GET['ad'] ?? '')), 0, 64) : '';
$adOpts = [];
if ($adsOn) {
    $as = $conn->prepare("SELECT source_id, COALESCE(NULLIF(ad_name,''), NULLIF(headline,''), source_id) AS label FROM ad_catalog WHERE company_id=? ORDER BY last_seen_at DESC LIMIT 300");
    if ($as) { $as->bind_param('i', $companyId); $as->execute(); foreach ($as->get_result()->fetch_all(MYSQLI_ASSOC) as $ar) { $adOpts[(string)$ar['source_id']] = (string)$ar['label']; } }
    if ($adF !== '' && !isset($adOpts[$adF])) { $adF = ''; }
}

$where  = "c.company_id=?";
$types  = "i";
$params = [$companyId];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($statusF !== '' && $hasCrm && $hasStatusCol) {
    $where .= " AND LOWER(c.status)=?";
    $types .= 's';
    $params[] = $statusF;
}
if ($groupF > 0) {
    $where .= " AND c.group_id=?";
    $types .= 'i';
    $params[] = $groupF;
}
if ($leadF !== '') {
    $where .= " AND c.lead_source=?";
    $types .= 's';
    $params[] = $leadF;
}
if ($adF !== '') {
    $where .= " AND EXISTS (SELECT 1 FROM ad_referrals ar2 WHERE ar2.company_id = c.company_id AND ar2.contact_id = c.id AND ar2.source_id = ?)";
    $types .= 's';
    $params[] = $adF;
}
$sourceCounts = [];
$sourceLabels = [
    'indiamart' => 'IndiaMART',
    'facebook' => 'Facebook',
    'whatsapp' => 'WhatsApp',
    'crm_api' => 'CRM API',
    'manual' => 'Manual',
];
if ($hasSourceCol) {
    $sourcesStmt = $conn->prepare("SELECT LOWER(COALESCE(source,'')) AS s, MIN(source) AS label, COUNT(*) AS c FROM contacts WHERE company_id=? GROUP BY s");
    if ($sourcesStmt) {
        $sourcesStmt->bind_param('i', $companyId);
        $sourcesStmt->execute();
        $sourcesResult = $sourcesStmt->get_result();
        while ($sourcesResult && ($sourceRow = $sourcesResult->fetch_assoc())) {
            $sourceKey = (string)$sourceRow['s'];
            $sourceCounts[$sourceKey] = (int)$sourceRow['c'];
            if ($sourceKey !== '' && !isset($sourceLabels[$sourceKey])) {
                $sourceLabels[$sourceKey] = trim((string)$sourceRow['label']) ?: ucwords(str_replace(['_', '-'], ' ', $sourceKey));
            }
        }
        $sourcesStmt->close();
    }
}
if (!$hasSourceCol) {
    $sourceF = '';
} elseif ($sourceF === 'manual') {
    $where .= " AND (c.source IS NULL OR c.source='' OR LOWER(c.source)='manual')";
} elseif (isset($sourceCounts[$sourceF]) && $sourceF !== '') {
    $where .= " AND LOWER(c.source)=?";
    $types .= 's';
    $params[] = $sourceF;
} else {
    $sourceF = '';
}

// Total matching rows
$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM contacts c WHERE $where");
$cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$matching = (int)($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
$pages = max(1, (int)ceil($matching / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

// Page of rows
$adsSel = $adsOn ? ', ar.headline AS ad_headline, ar.source_id AS ad_source_id' : '';
$adsJoin = $adsOn ? ' LEFT JOIN ad_referrals ar ON ar.id = c.last_ad_referral_id AND ar.company_id = c.company_id' : '';
$listStmt = $conn->prepare("
    SELECT c.*, u.name AS assigned_name{$adsSel}
    FROM contacts c
    LEFT JOIN users u ON c.assigned_to = u.id{$adsJoin}
    WHERE $where
    ORDER BY c.id DESC
    LIMIT ? OFFSET ?
");
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$perPage, $offset]);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$contactRows = [];
$listRes = $listStmt->get_result();
while ($listRes && ($r = $listRes->fetch_assoc())) { $contactRows[] = $r; }

// Overall stats for the KPI cards (not affected by filters)
$totalContacts = 0; $statusCounts = []; $sourceCounts = [];
$st = $conn->prepare("SELECT COUNT(*) AS c FROM contacts WHERE company_id=?");
$st->bind_param("i", $companyId);
$st->execute();
$totalContacts = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
if ($hasStatusCol) {
    $st = $conn->prepare("SELECT LOWER(COALESCE(status,'')) AS s, COUNT(*) AS c FROM contacts WHERE company_id=? GROUP BY s");
    $st->bind_param("i", $companyId);
    $st->execute();
    $sr = $st->get_result();
    while ($sr && ($r = $sr->fetch_assoc())) { $statusCounts[$r['s']] = (int)$r['c']; }
}
$st = $conn->prepare("SELECT COUNT(*) AS c FROM contacts WHERE company_id=? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$st->bind_param("i", $companyId);
$st->execute();
$newThisMonth = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);

$contactLimit = get_limit('contacts');
$limitPct = $contactLimit > 0 ? min(100, (int)round($totalContacts / $contactLimit * 100)) : 0;
$followUp  = ($statusCounts['follow up'] ?? 0) + ($statusCounts['follow-up'] ?? 0);
$converted = $statusCounts['converted'] ?? 0;
$fromWa    = $sourceCounts['whatsapp'] ?? 0;
$fromLeads = 0;
foreach ($sourceCounts as $sourceName => $sourceCount) {
    if (!in_array($sourceName, ['', 'manual', 'whatsapp'], true)) {
        $fromLeads += $sourceCount;
    }
}

$statusLabels = ['active' => 'Active', 'inactive' => 'Inactive', 'new' => 'New', 'follow up' => 'Follow up', 'converted' => 'Converted', 'decline' => 'Declined', 'not interested' => 'Not interested', 'unassigned' => 'Unassigned'];
foreach (array_keys($statusCounts) as $sk) { if ($sk !== '' && !isset($statusLabels[$sk])) $statusLabels[$sk] = ucwords($sk); }
$statusTone = ['follow up' => 'amber', 'follow-up' => 'amber', 'converted' => 'green', 'decline' => 'red', 'not interested' => 'red'];
$avatarColors = ['#25D366','#34B7F1','#6C63FF','#FF6B6B','#F0932B','#00B894','#E84393','#6AB04C','#EB4D4B','#e55039'];

$qs = static function (array $over = []) use ($search, $statusF, $sourceF, $groupF, $leadF, $adF): string {
    $q = array_filter(array_merge(['search' => $search, 'status' => $statusF, 'source' => $sourceF, 'group' => $groupF ?: '', 'lead' => $leadF, 'ad' => $adF], $over), static fn($v) => $v !== '' && $v !== null && $v !== 1);
    return $q ? '?' . http_build_query($q) : '';
};
$filtersOn = ($search !== '' || $statusF !== '' || $sourceF !== '' || $groupF > 0 || $leadF !== '' || $adF !== '');

// Custom field values under each name. Optional: if anything here fails, the list still loads without them.
$cfFields = [];
$cfVals = [];
try {
    if (is_file(__DIR__ . '/../includes/contact_fields.php')) {
        require_once __DIR__ . '/../includes/contact_fields.php';
        if (function_exists('cf_enabled') && cf_enabled()) {
            $cfFields = cf_list($conn, (int)$companyId);
            $cfVals = $cfFields ? cf_values($conn, (int)$companyId, array_column($contactRows ?? [], 'id')) : [];
        }
    }
} catch (\Throwable $e) {
    error_log('[contacts.php] custom fields skipped: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $cfFields = [];
    $cfVals = [];
}

include '../includes/client_header.php';
?>

<style>
/* ── Contacts — dashboard design system, no divider lines ── */
.ct{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.ct *{box-sizing:border-box}
.ct-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.ct-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.ct-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.ct-hero-ico svg{width:22px;height:22px}
.ct-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.ct-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:520px;line-height:1.5}
.ct-acts{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.ct-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.ct-btn:hover{border-color:var(--g);color:var(--g)}
.ct-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.ct-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.ct-btn--muted{opacity:.55;cursor:not-allowed}
.ct-btn--danger{background:#fff;color:#c62828;border-color:#f5c6c6}
.ct-btn--danger:hover{background:#fdecec;border-color:#c62828;color:#c62828}
.ct-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;font-weight:600;background:#E3F7EE;color:#0B6B5E}
.ct-notice.err{background:#FDE7E7;color:#9b1c1c}.ct-notice.warn{background:#FEF3DC;color:#7a4b00}
.ct-notice a{color:inherit;text-decoration:underline;font-weight:700}

.ct-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.ct-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.ct-kpi:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.ct-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.ct-kpi-ico svg{width:20px;height:20px}
.ct-kpi.blue .ct-kpi-ico{background:#E4EFFD;color:#1d6fd6}.ct-kpi.amber .ct-kpi-ico{background:#FEF3DC;color:#b7791f}.ct-kpi.purple .ct-kpi-ico{background:#EFE9FD;color:#6d44d8}
.ct-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.ct-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}
.ct-limit{height:6px;border-radius:99px;background:#DCEBE5;overflow:hidden;margin-top:8px;width:120px}
.ct-limit i{display:block;height:100%;background:linear-gradient(90deg,var(--g),var(--g2));border-radius:99px}
.ct-limit.hot i{background:linear-gradient(90deg,#F59E0B,#EF4444)}

.ct-panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);padding:18px 18px 12px}
.ct-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.ct-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1}
.ct-search{display:flex;align-items:center;gap:10px;background:var(--soft);border-radius:11px;padding:0 14px;height:42px;min-width:240px;flex:1;max-width:380px}
.ct-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.ct-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.ct-search:focus-within{box-shadow:0 0 0 2px var(--g);background:#fff}
.ct-sel{height:42px;border:0;border-radius:11px;background:var(--soft);padding:0 34px 0 14px;font:600 13.5px Inter,system-ui,sans-serif;color:var(--tx);outline:none;cursor:pointer}
.ct-sel:focus{box-shadow:0 0 0 2px var(--g);background:#fff}
.ct-meta{font-size:13px;color:var(--mu);font-weight:600;margin:0 2px 12px}

.ct-list{display:flex;flex-direction:column;gap:8px;container-type:inline-size;container-name:ctlist}
.ct-row{display:grid;grid-template-columns:36px minmax(200px,2fr) 124px 108px minmax(110px,1fr) 74px 98px 124px;gap:12px;align-items:center;padding:11px 14px;border-radius:13px;background:var(--soft);transition:background .15s}
.ct-row:not(.ct-head):hover{background:#E7F7F1}
.ct-head{background:transparent;padding-top:2px;padding-bottom:2px;font-size:12px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.ct-row input[type=checkbox]{accent-color:var(--g);width:17px;height:17px;cursor:pointer}
.ct-who{display:flex;align-items:center;gap:12px;min-width:0}
.ct-av{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.ct-name{font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ct-sub{font-size:12.5px;color:var(--mu);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ct-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap;background:#EEF3F1;color:#5d6f69}
.ct-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.ct-pill.green{background:#E3F7EE;color:#0B6B5E}.ct-pill.amber{background:#FEF3DC;color:#8a5a00}.ct-pill.red{background:#FDE7E7;color:#c62828}
.ct-pill.src::before{display:none}
.ct-pill.src.ad{background:#E6FCF2;color:#0B6B5E;text-decoration:none}
.ct-pill.src.ad:hover{background:#CDF5E3}
.ct-pill.indiamart{background:#FFEBDD;color:#c2561b}.ct-pill.facebook{background:#E4EFFD;color:#1d6fd6}.ct-pill.whatsapp{background:#E3F7EE;color:#0B6B5E}
.ct-lock{font-size:12px;color:var(--mu);display:inline-flex;align-items:center;gap:5px}
.ct-lock svg{width:12px;height:12px}
.ct-assign{font-size:13px;font-weight:600;color:#1d6fd6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ct-score{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:26px;padding:0 9px;border-radius:999px;font-size:12.5px;font-weight:800}
.ct-score.hot{background:#FDE7E7;color:#c62828}.ct-score.warm{background:#FEF3DC;color:#8a5a00}
.ct-date{font-size:13px;color:var(--mu)}
.ct-ib{width:36px;height:36px;border-radius:10px;border:0;background:#fff;color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}
.ct-ib:hover{background:var(--g);color:#fff}.ct-ib.danger:hover{background:#c62828}
.ct-ib svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ct-rowacts{display:flex;gap:6px;justify-content:flex-end}
.ct-empty{text-align:center;padding:48px 20px}
.ct-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 16px}
.ct-empty-ico svg{width:30px;height:30px}
.ct-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.ct-empty p{margin:0 0 18px;color:var(--mu);font-size:14.5px}
.ct-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 4px 6px}
.ct-pager span{font-size:13px;color:var(--mu);font-weight:600}
.ct-pages{display:flex;gap:6px;flex-wrap:wrap}
.ct-pages a,.ct-pages b{min-width:36px;height:36px;padding:0 10px;border-radius:10px;display:inline-grid;place-items:center;font:700 13.5px Inter,system-ui,sans-serif;text-decoration:none;color:var(--tx);background:var(--soft)}
.ct-pages a:hover{background:#E7F7F1;color:var(--gd)}
.ct-pages b{background:var(--g);color:#fff}
.ct-pages .gap{background:transparent;color:var(--mu)}

/* Row columns follow the width the list really has (sidebar open or closed), not the screen */
@container ctlist (max-width:990px){.ct-row{grid-template-columns:36px minmax(160px,2fr) 118px 100px 74px 124px}.ct-row>.c-assign,.ct-row>.c-date,.ct-head>.c-assign,.ct-head>.c-date{display:none}}
@container ctlist (max-width:760px){.ct-row{grid-template-columns:36px minmax(140px,1fr) 112px 124px}.ct-row>.c-src,.ct-row>.c-score,.ct-head>.c-src,.ct-head>.c-score{display:none}}
@media (max-width:1180px){.ct-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){
  .ct-head{display:none}
  .ct-row{grid-template-columns:30px 1fr auto;grid-template-areas:"chk who acts" "st st st";row-gap:8px}
  .ct-row>.c-chk{grid-area:chk}.ct-row>.c-who{grid-area:who}.ct-row>.c-act{grid-area:acts}.ct-row>.c-st{grid-area:st}
  .ct-row>.c-src,.ct-row>.c-score{display:none}
}
@media (max-width:560px){.ct-hero{padding:18px}.ct-hero h1{font-size:21px}.ct-kpis{grid-template-columns:1fr}.ct-search{min-width:0;max-width:none}.ct-acts,.ct-acts .ct-btn{width:100%}}
.ct-cf{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.ct-cf span{display:inline-flex;gap:4px;max-width:100%;padding:1px 8px;border-radius:6px;background:#F3F6F9;border:1px solid #E3E8EE;font-size:11.5px;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ct-cf em{font-style:normal;color:#6B7A8C}
.ct-cf a{font-size:11.5px;font-weight:600;color:#128C7E}
</style>

<div class="ct">

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'expired_block'): ?>
        <div class="ct-notice err">Action restricted. Your plan has expired. <a href="subscription.php">Renew now</a></div>
    <?php elseif ($_GET['msg'] === 'bulk_deleted'): ?>
        <div class="ct-notice">Selected contacts deleted successfully.</div>
    <?php else: ?>
        <div class="ct-notice">Contact <?php echo htmlspecialchars($_GET['msg']); ?>.</div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($viewOnlyMode): ?>
    <div class="ct-notice warn"><strong>View-only mode:</strong> your plan has expired. You can view your data, but adding or changing contacts is disabled. <a href="subscription.php">Renew your plan</a></div>
<?php endif; ?>

<div class="ct-hero">
    <div class="ct-hero-l">
        <div class="ct-hero-ico"><?php echo cn_svg('contacts'); ?></div>
        <div>
            <h1>Contacts</h1>
            <p>Everyone you talk to on WhatsApp, with their status, source and lead score in one place.</p>
        </div>
    </div>
    <div class="ct-acts">
        <?php if ($viewOnlyMode): ?>
            <span class="ct-btn ct-btn--muted">Add contact</span>
        <?php elseif (can_use('contacts')): ?>
            <a href="contact_groups.php" class="ct-btn">Groups</a>
            <a href="contact_import.php" class="ct-btn">Import CSV</a>
            <?php if (has_feature('feat_contact_import')): ?><a href="../api/contact_export.php?format=xlsx<?php echo $groupF ? '&group=' . (int)$groupF : ''; ?>" class="ct-btn" title="Excel file: phone numbers and Tamil names kept exactly">Export Excel</a><a href="../api/contact_export.php?format=csv<?php echo $groupF ? '&group=' . (int)$groupF : ''; ?>" class="ct-btn">CSV</a><?php endif; ?>
            <a href="contact_add.php" class="ct-btn ct-btn--primary">+ Add contact</a>
        <?php else: ?>
            <span class="ct-btn ct-btn--muted">Limit reached</span>
        <?php endif; ?>
    </div>
</div>

<div class="ct-kpis">
    <div class="ct-kpi">
        <div class="ct-kpi-ico"><?php echo cn_svg('contacts'); ?></div>
        <div>
            <div class="ct-kpi-v"><?php echo number_format($totalContacts); ?><?php if ($contactLimit > 0): ?><span style="font-size:15px;color:var(--mu);font-weight:600"> / <?php echo number_format($contactLimit); ?></span><?php endif; ?></div>
            <div class="ct-kpi-l">Total contacts</div>
            <?php if ($contactLimit > 0): ?><div class="ct-limit<?php echo $limitPct >= 85 ? ' hot' : ''; ?>"><i style="width:<?php echo $limitPct; ?>%"></i></div><?php endif; ?>
        </div>
    </div>
    <div class="ct-kpi blue"><div class="ct-kpi-ico"><?php echo cn_svg('growth'); ?></div><div><div class="ct-kpi-v"><?php echo number_format($newThisMonth); ?></div><div class="ct-kpi-l">New this month</div></div></div>
    <?php if ($hasCrm): ?>
        <div class="ct-kpi amber"><div class="ct-kpi-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="ct-kpi-v"><?php echo number_format($followUp); ?></div><div class="ct-kpi-l">In follow-up</div></div></div>
        <div class="ct-kpi purple"><div class="ct-kpi-ico"><?php echo cn_svg('shield'); ?></div><div><div class="ct-kpi-v"><?php echo number_format($converted); ?></div><div class="ct-kpi-l">Converted</div></div></div>
    <?php else: ?>
        <div class="ct-kpi amber"><div class="ct-kpi-ico"><?php echo cn_svg('chat'); ?></div><div><div class="ct-kpi-v"><?php echo number_format($fromWa); ?></div><div class="ct-kpi-l">From WhatsApp</div></div></div>
        <div class="ct-kpi purple"><div class="ct-kpi-ico"><?php echo cn_svg('integrations'); ?></div><div><div class="ct-kpi-v"><?php echo number_format($fromLeads); ?></div><div class="ct-kpi-l">From lead sources</div></div></div>
    <?php endif; ?>
</div>

<form id="bulkDeleteForm" method="POST">
<?php echo csrf_field(); ?>
<input type="hidden" name="bulk_delete" value="1">
<div class="ct-panel">

    <div class="ct-toolbar">
        <div class="ct-filter" id="ctFilter">
            <label class="ct-search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" name="search" form="ctGet" placeholder="Search name, phone or email" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
            </label>
            <?php if ($hasCrm && $hasStatusCol): ?>
            <select class="ct-sel" name="status" form="ctGet" aria-label="Filter by status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $k => $lbl): if (!isset($statusCounts[$k])) continue; ?>
                    <option value="<?php echo htmlspecialchars($k); ?>"<?php echo $statusF === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?> (<?php echo $statusCounts[$k]; ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($groupOpts): ?>
            <select class="ct-sel" name="group" form="ctGet" aria-label="Filter by group" onchange="this.form.submit()">
                <option value="">All groups</option>
                <?php foreach ($groupOpts as $gid => $gname): ?>
                    <option value="<?php echo $gid; ?>"<?php echo $groupF === $gid ? ' selected' : ''; ?>><?php echo htmlspecialchars($gname); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($hasSourceCol): ?>
            <select class="ct-sel" name="source" form="ctGet" aria-label="Filter by source" onchange="this.form.submit()">
                <option value="">All sources</option>
                <?php foreach ($sourceLabels as $k => $lbl): if ($k !== '' && empty($sourceCounts[$k])) continue; ?>
                    <option value="<?php echo htmlspecialchars($k); ?>"<?php echo $sourceF === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?><?php echo isset($sourceCounts[$k]) ? ' (' . number_format($sourceCounts[$k]) . ')' : ''; ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($adsOn): ?>
            <select class="ct-sel" name="lead" form="ctGet" aria-label="Filter by lead source" onchange="this.form.submit()">
                <option value="">Any lead source</option>
                <?php foreach (['organic' => 'Organic', 'ad' => 'From an ad', 'post' => 'From a post'] as $k => $lbl): ?>
                    <option value="<?php echo $k; ?>"<?php echo $leadF === $k ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($adOpts): ?>
            <select class="ct-sel" name="ad" form="ctGet" aria-label="Filter by ad" onchange="this.form.submit()">
                <option value="">Any ad</option>
                <?php foreach ($adOpts as $sid => $lbl): ?>
                    <option value="<?php echo htmlspecialchars($sid); ?>"<?php echo $adF === (string)$sid ? ' selected' : ''; ?>><?php echo htmlspecialchars(mb_strimwidth($lbl, 0, 48, '…')); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php endif; ?>
            <button type="submit" form="ctGet" class="ct-btn" style="height:42px">Search</button>
            <?php if ($filtersOn): ?><a href="contacts.php" class="ct-btn" style="height:42px">Clear</a><?php endif; ?>
        </div>
        <button type="button" id="bulk-actions" class="ct-btn ct-btn--danger" style="display:none;height:42px" onclick="submitBulkDelete()">Delete selected (<span id="selCount">0</span>)</button>
    </div>

    <?php if ($matching > 0): ?>
    <div class="ct-meta">Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format($offset + count($contactRows)); ?> of <?php echo number_format($matching); ?> contact<?php echo $matching === 1 ? '' : 's'; ?><?php echo $filtersOn ? ' (filtered)' : ''; ?></div>
    <div class="ct-list">
        <div class="ct-row ct-head">
            <div class="c-chk"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" aria-label="Select all on this page"></div>
            <div>Contact</div><div class="c-st">Status</div><div class="c-src">Source</div><div class="c-assign">Assigned to</div><div class="c-score" style="text-align:center">Score</div><div class="c-date">Added</div><div></div>
        </div>
        <?php foreach ($contactRows as $c):
            $name  = trim((string)($c['name'] ?? ''));
            $phone = (string)($c['phone'] ?? '');
            $disp  = $name !== '' ? $name : $phone;
            $init  = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $disp) ?: 'U', 0, 2));
            $avBg  = $avatarColors[abs(crc32($disp)) % count($avatarColors)];
            $stat  = strtolower((string)($c['status'] ?? 'new')) ?: 'new';
            $tone  = $statusTone[$stat] ?? '';
            $src   = strtolower((string)($c['source'] ?? ''));
            $score = (int)($c['lead_score'] ?? 0);
            $cid   = (int)$c['id'];
        ?>
        <div class="ct-row">
            <div class="c-chk"><input type="checkbox" name="selected_ids[]" value="<?php echo $cid; ?>" class="row-checkbox" onchange="updateBulkActions()" aria-label="Select <?php echo htmlspecialchars($disp, ENT_QUOTES); ?>"></div>
            <div class="c-who ct-who">
                <div class="ct-av" style="background:<?php echo $avBg; ?>"><?php echo htmlspecialchars($init); ?></div>
                <div style="min-width:0"><div class="ct-name"><?php echo htmlspecialchars($disp); ?></div><div class="ct-sub"><?php echo htmlspecialchars($name !== '' ? $phone : ((string)($c['email'] ?? ''))); ?></div><?php
                    $cfShown = [];
                    foreach ($cfFields as $f) { $val = (string)($cfVals[$cid][(int)$f['id']] ?? ''); if ($val !== '') { $cfShown[] = [$f['name'], cf_display((string)$f['field_type'], $val)]; } }
                    if ($cfShown): ?><div class="ct-cf"><?php foreach (array_slice($cfShown, 0, 3) as [$fn, $fv]): ?><span title="<?php echo htmlspecialchars($fn . ': ' . $fv, ENT_QUOTES); ?>"><em><?php echo htmlspecialchars($fn); ?></em> <?php echo htmlspecialchars(mb_strimwidth($fv, 0, 28, '…')); ?></span><?php endforeach; ?><?php if (count($cfShown) > 3): ?><a href="contact_edit.php?id=<?php echo $cid; ?>">+<?php echo count($cfShown) - 3; ?> more</a><?php endif; ?></div><?php endif; ?></div>
            </div>
            <div class="c-st">
                <?php if (!$hasCrm): ?><span class="ct-lock"><?php echo cn_lock_icon(); ?> Premium</span>
                <?php else: ?><span class="ct-pill <?php echo $tone; ?>"><?php echo htmlspecialchars($statusLabels[$stat] ?? ucwords($stat)); ?></span><?php endif; ?>
            </div>
            <div class="c-src">
                <?php if ($adsOn && in_array((string)($c['lead_source'] ?? ''), ['ad', 'post'], true)): $adLbl = trim((string)($c['ad_headline'] ?? '')) ?: (($c['lead_source'] === 'post' ? 'Post' : 'Ad') . (!empty($c['ad_source_id']) ? ' ' . $c['ad_source_id'] : '')); ?>
                    <a class="ct-pill src ad" href="ads_leads.php?<?php echo htmlspecialchars(http_build_query(['ad' => !empty($c['ad_source_id']) ? $c['ad_source_id'] : 'h:' . mb_substr((string)($c['ad_headline'] ?? ''), 0, 60), 'range' => 'custom', 'from' => date('Y-m-d', strtotime('-365 days')), 'to' => date('Y-m-d')])); ?>" title="<?php echo htmlspecialchars('Came from: ' . $adLbl); ?>"><?php echo $c['lead_source'] === 'post' ? 'Post' : 'Ad'; ?></a>
                <?php elseif ($src !== ''): ?>
                    <span class="ct-pill src <?php echo in_array($src, ['indiamart', 'facebook', 'whatsapp'], true) ? $src : 'crm'; ?>"><?php echo htmlspecialchars($sourceLabels[$src] ?? ucwords(str_replace(['_', '-'], ' ', $src))); ?></span>
                <?php else: ?><span class="ct-sub">Manual</span><?php endif; ?>
            </div>
            <div class="c-assign">
                <?php if (!$hasCrm): ?><span class="ct-lock"><?php echo cn_lock_icon(); ?> Locked</span>
                <?php elseif (!empty($c['assigned_name'])): ?><span class="ct-assign"><?php echo htmlspecialchars($c['assigned_name']); ?></span>
                <?php else: ?><span class="ct-sub">Unassigned</span><?php endif; ?>
            </div>
            <div class="c-score" style="text-align:center">
                <?php if ($score > 50): ?><span class="ct-score hot"><?php echo $score; ?></span>
                <?php elseif ($score > 0): ?><span class="ct-score warm"><?php echo $score; ?></span>
                <?php else: ?><span class="ct-sub">&mdash;</span><?php endif; ?>
            </div>
            <div class="c-date ct-date"><?php echo !empty($c['created_at']) ? date('d M Y', strtotime((string)$c['created_at'])) : ''; ?></div>
            <div class="c-act ct-rowacts">
                <a href="whatsapp.php?c=<?php echo $cid; ?>" class="ct-ib" title="Open chat" aria-label="Open chat"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>
                <a href="contact_edit.php?id=<?php echo $cid; ?>" class="ct-ib" title="Edit" aria-label="Edit contact"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg></a>
                <button type="submit" form="del_<?php echo $cid; ?>" class="ct-ib danger" title="Delete" aria-label="Delete contact" onclick="return confirm('Delete this contact?')"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1):
        $win = array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))))); sort($win); ?>
    <div class="ct-pager">
        <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <div class="ct-pages">
            <?php if ($page > 1): ?><a href="contacts.php<?php echo $qs(['page' => $page - 1]); ?>" aria-label="Previous page">&larr;</a><?php endif; ?>
            <?php $prev = 0; foreach ($win as $p): if ($p - $prev > 1): ?><b class="gap">…</b><?php endif; $prev = $p;
                if ($p === $page): ?><b><?php echo $p; ?></b><?php else: ?><a href="contacts.php<?php echo $qs(['page' => $p]); ?>"><?php echo $p; ?></a><?php endif; endforeach; ?>
            <?php if ($page < $pages): ?><a href="contacts.php<?php echo $qs(['page' => $page + 1]); ?>" aria-label="Next page">&rarr;</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="ct-empty">
        <div class="ct-empty-ico"><?php echo cn_svg('contacts'); ?></div>
        <?php if ($filtersOn): ?>
            <h3>No contacts match</h3><p>Try a different search or clear the filters.</p>
            <a href="contacts.php" class="ct-btn ct-btn--primary">Clear filters</a>
        <?php else: ?>
            <h3>No contacts yet</h3><p>Add your first contact or import a CSV to start messaging.</p>
            <?php if (!$viewOnlyMode && can_use('contacts')): ?><a href="contact_add.php" class="ct-btn ct-btn--primary">+ Add contact</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</form>
<form id="ctGet" method="GET" action="contacts.php"></form>

</div>

<?php foreach ($contactRows as $c): ?>
<form id="del_<?php echo (int)$c['id']; ?>" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="delete_contact" value="1">
    <input type="hidden" name="contact_id" value="<?php echo (int)$c['id']; ?>">
</form>
<?php endforeach; ?>

<script>
function toggleSelectAll(sa) {
    document.querySelectorAll('.row-checkbox').forEach(function (cb) { cb.checked = sa.checked; });
    updateBulkActions();
}
function updateBulkActions() {
    var checked = document.querySelectorAll('.row-checkbox:checked').length;
    var bulk = document.getElementById('bulk-actions'), cnt = document.getElementById('selCount');
    if (bulk) bulk.style.display = checked > 0 ? 'inline-flex' : 'none';
    if (cnt) cnt.textContent = checked;
    var all = document.getElementById('selectAllCheckbox'), total = document.querySelectorAll('.row-checkbox').length;
    if (all && total > 0) all.checked = (checked === total);
}
function submitBulkDelete() {
    if (confirm('Are you sure you want to delete all selected contacts? This cannot be undone.')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}
</script>

<?php include '../includes/client_footer.php'; ?>
