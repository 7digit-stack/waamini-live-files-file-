<?php
include '../includes/client_auth_check.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0 || !has_feature('feat_drip_campaign')) {
    header("Location: premium_drip.php");
    exit();
}

$campaign = null;
$stmt = $conn->prepare("SELECT * FROM drip_campaigns WHERE id = ? AND company_id = ?");
if ($stmt) {
    $stmt->bind_param("ii", $id, $_SESSION['company_id']);
    $stmt->execute();
    $campaign = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$campaign) {
    header("Location: premium_drip.php");
    exit();
}

$pageTitle = 'Drip Champion: ' . htmlspecialchars($campaign['name']);
$currentPage = 'premium_drip';
include '../includes/client_header.php';

$templates = false;
$tempStmt = $conn->prepare(
    "SELECT id, name, category FROM message_templates
     WHERE company_id = ?
       AND (wa_status IN ('APPROVED','approved') OR LOWER(TRIM(COALESCE(status,''))) = 'approved')
     ORDER BY name ASC"
);
if ($tempStmt) {
    $tempStmt->bind_param("i", $_SESSION['company_id']);
    $tempStmt->execute();
    $templates = $tempStmt->get_result();
}
$templateOptions = "";
if ($templates) {
    while ($t = $templates->fetch_assoc()) {
        $cat = (string)($t['category'] ?? '');
        $templateOptions .= '<option value="' . (int)$t['id'] . '">' . htmlspecialchars($t['name']) . ' (' . htmlspecialchars($cat) . ')</option>';
    }
}

$steps = false;
$stepsStmt = $conn->prepare(
    "SELECT s.*, t.name as template_name
     FROM drip_steps s
     LEFT JOIN message_templates t ON s.template_id = t.id
     WHERE s.drip_campaign_id = ?
     ORDER BY COALESCE(s.step_order,0) ASC, s.delay_days ASC, s.delay_hours ASC, COALESCE(s.delay_minutes,0) ASC"
);
if ($stepsStmt) {
    $stepsStmt->bind_param("i", $id);
    $stepsStmt->execute();
    $steps = $stepsStmt->get_result();
}

// ── Icons (inline SVG, same stroke style as the rest of the panel) ──
$bI = static function (string $p, int $size = 16): string {
    return '<svg class="bI" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$ic = [
    'back'     => $bI('<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>'),
    'flow'     => $bI('<circle cx="5" cy="6" r="3"/><path d="M5 9v12"/><circle cx="19" cy="18" r="3"/><path d="m15 9-3-3 3-3"/><path d="M12 6h5a2 2 0 0 1 2 2v7"/>'),
    'bolt'     => $bI('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'),
    'tag'      => $bI('<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>'),
    'clock'    => $bI('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
    'check'    => $bI('<path d="M20 6 9 17l-5-5"/>'),
    'trash'    => $bI('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>', 14),
    'plus'     => $bI('<path d="M5 12h14"/><path d="M12 5v14"/>'),
    'settings' => $bI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>'),
    'shield'   => $bI('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'),
    'file'     => $bI('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>', 14),
    'chat'     => $bI('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 14),
    'bulb'     => $bI('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>'),
];

$randModes = [
    'none'    => ['label' => 'Static',  'desc' => 'Exact delay, no jitter',        'color' => '#6b7a76', 'icon' => $bI('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>')],
    'minimal' => ['label' => 'Minimal', 'desc' => '±5% delay, tight skew',         'color' => '#128C7E', 'icon' => $bI('<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>')],
    'human'   => ['label' => 'Human',   'desc' => '±20% jitter, randomized opens', 'color' => '#1d6fd6', 'icon' => $bI('<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>')],
    'ghost'   => ['label' => 'Ghost',   'desc' => 'Wide scatter, silent gaps',     'color' => '#7c3aed', 'icon' => $bI('<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>')],
    'chaos'   => ['label' => 'Chaos',   'desc' => 'Non-linear burst + silence',    'color' => '#dc2626', 'icon' => $bI('<path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/>')],
];

function formatDelayChampion($d, $h, $m, $mode = 'human', $jitterSec = 300, $s = 0)
{
    $d = (int)$d; $h = (int)$h; $m = (int)$m; $s = (int)$s; $j = (int)$jitterSec;
    if ($d == 0 && $h == 0 && $m == 0 && $s == 0) return "Send immediately";
    $txt = [];
    if ($d > 0) $txt[] = "$d Day" . ($d !== 1 ? 's' : '');
    if ($h > 0) $txt[] = "$h Hour" . ($h !== 1 ? 's' : '');
    if ($m > 0) $txt[] = "$m Min" . ($m !== 1 ? 's' : '');
    if ($s > 0) $txt[] = "$s Sec" . ($s !== 1 ? 's' : '');
    $delayLabel = "After " . implode(', ', $txt);
    if ($mode === 'none') $delayLabel .= " <small class='dc-mode-label'>(static)</small>";
    else $delayLabel .= " <small class='dc-mode-label'>±" . ($j > 0 ? ($j >= 60 ? round($j/60) . 'm' : $j . 's') : 'auto') . "</small>";
    return $delayLabel;
}

// Campaign randomization: older rows store a mode name, newer ones a 0/1 flag
$grRaw = (string)($campaign['global_randomization'] ?? 'none');
$grMode = isset($randModes[$grRaw]) ? $grRaw : ($grRaw === '1' ? 'human' : 'none');
$smartOn = $grMode !== 'none';
$respectBHflag = (int)($campaign['respect_business_hours'] ?? 1) === 1;

// Compute preview send times for the first 3 contacts
$timePreview = [];
$stepTotal = 0;
if ($steps && $steps->num_rows > 0) {
    require_once __DIR__ . '/../includes/drip_interval_engine.php';
    $steps->data_seek(0);
    $allSteps = [];
    while ($s = $steps->fetch_assoc()) $allSteps[] = $s;
    $stepTotal = count($allSteps);
    // global_randomization is a TINYINT flag: 1 = per-step modes apply, 0 = exact schedule
    $randomizationOn = (int)($campaign['global_randomization'] ?? 1) === 1;
    $respectBH = $respectBHflag;

    for ($previewContact = 1001; $previewContact <= 1003; $previewContact++) {
        $times = [];
        foreach ($allSteps as $idx => $s) {
            if (!$randomizationOn) $s['randomization_mode'] = 'none';
            $baseSecs = drip_delay_to_seconds(
                max(0, (int)$s['delay_days']),
                max(0, (int)$s['delay_hours']),
                max(0, (int)($s['delay_minutes'] ?? 0)),
                max(0, (int)($s['delay_seconds'] ?? 0))
            );
            $ts = drip_compute_send_timestamp($baseSecs, $s, $previewContact, $idx, $respectBH);
            $times[] = date('M j, g:i A', $ts);
        }
        $timePreview[] = $times;
    }
}
?>
<style>
/* ── Sequence builder — soft tiles, no borders or divider lines, sidebar green ── */
.dcb { --g:#128C7E; --g2:#25D366; --gd:#0B6B5E; --ink:#0F2622; --tx:#34453f; --mu:#586a63; --soft:#F3FBF7; --mint:#E6FCF2; font-family: inherit; }
.dcb .bI { flex-shrink: 0; }
.dcb *, .dcb *::before, .dcb *::after { box-sizing: border-box; }

.dc-back { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:700; color:var(--mu); text-decoration:none; margin-bottom:12px; padding:7px 12px; border-radius:10px; background:#fff; box-shadow:0 6px 16px -12px rgba(15,38,34,.5); }
.dc-back:hover { color:var(--gd); background:var(--mint); }

.dc-head { background:#fff; border-radius:18px; padding:22px 26px; margin-bottom:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); display:flex; justify-content:space-between; align-items:center; gap:18px; flex-wrap:wrap; }
.dc-head-l { display:flex; align-items:center; gap:16px; min-width:0; }
.dc-head-ico { width:52px; height:52px; border-radius:14px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; flex:none; }
.dc-head-ico .bI { width:24px; height:24px; }
.dc-eyebrow { font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; color:var(--g); margin-bottom:3px; }
.dc-head h1 { margin:0 0 8px; font-size:23px; font-weight:800; letter-spacing:-.3px; color:var(--ink); overflow-wrap:anywhere; }
.dc-chips { display:flex; flex-wrap:wrap; gap:8px; }
.dc-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:999px; background:var(--soft); color:var(--tx); font-size:12.5px; font-weight:700; }
.dc-chip .bI { width:14px; height:14px; color:var(--g); }
.dc-chip.on { background:#E3F7EE; color:var(--gd); }
.dc-chip.off { background:#EEF1F0; color:#6b7a76; }
.dc-stats { display:flex; gap:12px; }
.dc-stat { background:var(--soft); border-radius:14px; padding:12px 20px; text-align:center; min-width:96px; }
.dc-stat b { display:block; font-size:24px; font-weight:800; color:var(--ink); line-height:1.1; }
.dc-stat span { font-size:12px; font-weight:700; color:var(--mu); }

.dc-note { display:flex; align-items:center; gap:12px; padding:13px 18px; background:var(--mint); border-radius:14px; margin-bottom:18px; color:var(--tx); font-size:13.5px; line-height:1.5; }
.dc-note .bI { width:20px; height:20px; color:var(--g); }
.dc-note strong { color:var(--gd); }

.builder-layout { display:grid; grid-template-columns:minmax(0,1fr) 400px; gap:20px; align-items:start; }
@media (max-width:1100px) { .builder-layout { grid-template-columns:minmax(0,1fr); } .dc-stats { width:100%; } .dc-stat { flex:1; } }

.dc-card { background:#fff; border-radius:18px; padding:24px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.dc-card > h3 { margin:0 0 18px; font-size:16px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:10px; }
.dc-card > h3 .ico { width:32px; height:32px; border-radius:9px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; }
.dc-card > h3 .ico .bI { width:17px; height:17px; }
.dc-card > h3 small { margin-left:auto; font-size:12px; font-weight:700; color:var(--mu); background:var(--soft); padding:4px 10px; border-radius:999px; }
.dc-side { display:flex; flex-direction:column; gap:20px; }

/* flow */
.flow-list { display:flex; flex-direction:column; gap:12px; }
.flow-item { display:grid; grid-template-columns:40px minmax(0,1fr); gap:14px; align-items:start; }
.flow-marker { width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-weight:800; font-size:14px; background:#E3F7EE; color:var(--g); }
.flow-marker.trigger { background:var(--g); color:#fff; }
.flow-marker.step { background:#E3F7EE; color:var(--gd); }
.flow-marker.ghost { background:#F1E9FE; color:#7c3aed; }
.flow-marker.chaos { background:#FDE7E7; color:#dc2626; }
.flow-marker .bI { width:18px; height:18px; }

.trigger-tile { background:var(--mint); border-radius:14px; padding:14px 18px; }
.trigger-tile .k { font-size:11px; font-weight:800; color:var(--g); text-transform:uppercase; letter-spacing:.6px; }
.trigger-tile p { margin:4px 0 0; font-size:14px; color:var(--tx); }
.trigger-tile .m { font-size:12.5px; color:var(--mu); display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:6px; }
.trigger-tile .m span { display:inline-flex; align-items:center; gap:5px; }
.trigger-tile .m .bI { width:13px; height:13px; }

.step-card { background:var(--soft); border-radius:14px; padding:16px 18px; }
.step-card:hover { background:#EDFAF3; }
.step-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.step-tags { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:8px; }
.delay-tag { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; background:#fff; color:var(--gd); border-radius:999px; font-size:12.5px; font-weight:800; }
.delay-tag .bI { width:13px; height:13px; }
.dc-mode-label { font-weight:700; opacity:.7; }
.dc-mode-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:5px 12px; border-radius:999px; background:#fff; }
.dc-mode-chip .bI { width:13px; height:13px; }
.step-card h4 { margin:0; font-size:15px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:7px; }
.step-card h4 .bI { color:var(--g); }
.step-meta { margin-top:6px; font-size:12px; color:var(--mu); }
.step-meta code { background:#fff; padding:1px 7px; border-radius:6px; font-size:11.5px; color:var(--tx); }
.step-msg-preview { margin-top:12px; background:#fff; border-radius:12px; padding:12px 14px; font-size:13.5px; color:var(--tx); line-height:1.6; word-break:break-word; }
.step-del-btn { background:#FEEDED; color:#c62828; border:0; padding:7px 12px; border-radius:10px; font-size:12.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; }
.step-del-btn:hover { background:#dc2626; color:#fff; }

.builder-empty { text-align:center; padding:42px 24px; background:var(--soft); border-radius:16px; margin-top:4px; }
.builder-empty .ico { width:64px; height:64px; border-radius:50%; background:#E3F7EE; color:var(--g); display:grid; place-items:center; margin:0 auto 12px; }
.builder-empty .ico .bI { width:28px; height:28px; }
.builder-empty h4 { margin:0 0 6px; font-size:17px; font-weight:800; color:var(--ink); }
.builder-empty p { margin:0 auto; font-size:13.5px; color:var(--mu); max-width:320px; line-height:1.55; }

/* time preview */
.tp { margin-top:20px; background:var(--soft); border-radius:16px; padding:18px; }
.tp h4 { margin:0 0 12px; font-size:14px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:8px; }
.tp h4 .bI { color:var(--g); }
.tp-wrap { overflow-x:auto; }
.time-preview-table { width:100%; border-collapse:separate; border-spacing:0 6px; font-size:12.5px; }
.time-preview-table th { text-align:left; padding:4px 12px; color:var(--mu); text-transform:uppercase; letter-spacing:.4px; font-size:11px; font-weight:800; }
.time-preview-table td { padding:10px 12px; background:#fff; color:var(--tx); white-space:nowrap; }
.time-preview-table td:first-child { border-radius:10px 0 0 10px; font-weight:800; color:var(--ink); }
.time-preview-table td:last-child { border-radius:0 10px 10px 0; }
.tp p { margin:8px 0 0; font-size:12px; color:var(--mu); }

/* forms */
.dcb label.lab { display:block; font-size:12px; font-weight:800; color:var(--mu); text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
.form-input { width:100%; background:#F3F7F5; border:0; color:var(--ink); border-radius:11px; padding:11px 13px; font-size:14px; font-family:inherit; }
.form-input:focus { outline:none; box-shadow:0 0 0 2px rgba(18,140,126,.4); background:#fff; }
textarea.form-input { resize:vertical; line-height:1.55; }
.dc-help { font-size:12.5px; color:var(--mu); margin:7px 0 0; line-height:1.5; }
.dc-sec { background:var(--soft); border-radius:14px; padding:16px; margin-bottom:14px; }
.dc-sec .form-input { background:#fff; }
.dc-sec-h { display:flex; align-items:center; gap:9px; font-size:14px; font-weight:800; color:var(--ink); margin-bottom:12px; }
.dc-sec-h b { width:24px; height:24px; border-radius:50%; background:var(--g); color:#fff; display:grid; place-items:center; font-size:12px; }
.delay-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.delay-grid .u { display:block; text-align:center; font-size:10.5px; color:var(--mu); margin-top:6px; font-weight:800; letter-spacing:.5px; }
.delay-grid .form-input { text-align:center; padding:11px 6px; }

.mode-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px; }
.mode-option { position:relative; background:#fff; border-radius:12px; padding:11px 12px; cursor:pointer; user-select:none; display:block; text-transform:none; letter-spacing:0; margin:0 !important; }
.mode-option:hover { background:#F0FAF5; }
.mode-option.selected { background:#E3F7EE; box-shadow:inset 0 0 0 2px var(--g); }
.mode-option input[type=radio] { position:absolute; opacity:0; width:0; height:0; }
.mode-option .mode-ico { width:30px; height:30px; border-radius:9px; display:grid; place-items:center; margin-bottom:7px; background:var(--soft); }
.mode-option .mode-ico .bI { width:16px; height:16px; }
.mode-option .mode-name { font-size:13px; font-weight:800; color:var(--ink); }
.mode-option .mode-desc { font-size:11.5px; color:var(--mu); margin-top:2px; line-height:1.35; font-weight:500; }

.set-row { display:flex; justify-content:space-between; align-items:center; gap:14px; background:var(--soft); border-radius:12px; padding:12px 14px; margin-bottom:8px; }
.set-row .t { font-size:13.5px; font-weight:700; color:var(--ink); line-height:1.35; }
.set-row .t small { display:block; font-weight:500; color:var(--mu); font-size:12px; }
.set-row .form-input { width:130px; background:#fff; padding:9px 11px; }
.set-row input[type=number].form-input { width:84px; text-align:center; }
.dcb .tg { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:700; color:var(--tx); cursor:pointer; }
.dcb .tg input { width:18px; height:18px; accent-color:var(--g); }

.dcb .btn-go { width:100%; display:inline-flex; align-items:center; justify-content:center; gap:8px; height:44px; border:0; border-radius:12px; background:var(--g); color:#fff; font-size:14px; font-weight:800; cursor:pointer; font-family:inherit; }
.dcb .btn-go:hover { background:var(--gd); }
.dcb .btn-soft { background:var(--mint); color:var(--gd); }
.dcb .btn-soft:hover { background:#CDF5E3; color:var(--gd); }

.pro-tip { margin-top:16px; padding:14px 16px; background:var(--mint); border-radius:14px; display:flex; gap:12px; align-items:flex-start; }
.pro-tip .bI { width:20px; height:20px; color:var(--g); margin-top:2px; }
.pro-tip h5 { margin:0 0 4px; color:var(--gd); font-size:13px; font-weight:800; }
.pro-tip p { margin:0; font-size:12.5px; color:var(--tx); line-height:1.55; }

@media (max-width:640px) { .dc-head { padding:18px; } .dc-head h1 { font-size:20px; } .dc-card { padding:18px; } .delay-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .set-row { flex-wrap:wrap; } .flow-item { grid-template-columns:34px minmax(0,1fr); gap:10px; } .flow-marker { width:34px; height:34px; } }
</style>

<div class="dcb">

<a href="premium_drip.php" class="dc-back"><?php echo $ic['back']; ?> Back to campaigns</a>

<div class="dc-head">
    <div class="dc-head-l">
        <div class="dc-head-ico"><?php echo $ic['flow']; ?></div>
        <div style="min-width:0">
            <div class="dc-eyebrow">Sequence builder</div>
            <h1><?php echo htmlspecialchars($campaign['name']); ?></h1>
            <div class="dc-chips">
                <span class="dc-chip"><?php echo $ic['tag']; ?> Trigger: <?php echo htmlspecialchars((string)($campaign['trigger_label'] ?? '')); ?></span>
                <span class="dc-chip <?php echo $smartOn ? 'on' : 'off'; ?>"><?php echo $smartOn ? 'Smart mode on · ' . htmlspecialchars($randModes[$grMode]['label']) : 'Standard mode'; ?></span>
            </div>
        </div>
    </div>
    <div class="dc-stats">
        <div class="dc-stat"><b><?php echo (int)$stepTotal; ?></b><span>Step<?php echo $stepTotal === 1 ? '' : 's'; ?></span></div>
        <div class="dc-stat"><b><?php echo (int)($campaign['max_messages_per_day'] ?? 10); ?></b><span>Max / day</span></div>
    </div>
</div>

<div class="dc-note">
    <?php echo $ic['shield']; ?>
    <span>Messages go out with <strong>human-like, randomized intervals</strong> so your sequence feels natural and keeps your delivery rate high.</span>
</div>

<div class="builder-layout">

    <!-- Flow workspace -->
    <section class="dc-card">
        <h3><span class="ico"><?php echo $ic['bolt']; ?></span> Automation flow <?php if ($stepTotal > 0): ?><small><?php echo (int)$stepTotal; ?> step<?php echo $stepTotal === 1 ? '' : 's'; ?></small><?php endif; ?></h3>

        <div class="flow-list">
            <div class="flow-item">
                <div class="flow-marker trigger"><?php echo $ic['check']; ?></div>
                <div class="trigger-tile">
                    <div class="k">Trigger activated</div>
                    <p>Contact gets the label <strong>"<?php echo htmlspecialchars((string)($campaign['trigger_label'] ?? '')); ?>"</strong></p>
                    <div class="m">
                        <span><?php echo $ic['settings']; ?> Randomization: <strong><?php echo htmlspecialchars($randModes[$grMode]['label']); ?></strong></span>
                        <?php if ($respectBHflag): ?><span><?php echo $ic['clock']; ?> Business hours 10 AM – 8 PM</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$steps || $steps->num_rows == 0): ?>
                <div class="builder-empty">
                    <div class="ico"><?php echo $ic['clock']; ?></div>
                    <h4>No steps yet</h4>
                    <p>Use the panel on the right to add your first automated message.</p>
                </div>
            <?php else: ?>
                <?php
                $stepIndex = 0;
                $steps->data_seek(0);
                while ($s = $steps->fetch_assoc()):
                    $mode = strtolower((string)($s['randomization_mode'] ?? 'human'));
                    $modeInfo = $randModes[$mode] ?? $randModes['human'];
                    $flexGap = ($mode === 'chaos') ? '5m→3s→25s' : ($mode === 'ghost' ? 'wide scatter' : ($mode === 'minimal' ? '±5%' : '±20%'));
                    $markerClass = match($mode) {
                        'chaos' => 'chaos',
                        'ghost' => 'ghost',
                        default => 'step',
                    };
                    $stepIndex++;
                ?>
                <div class="flow-item">
                    <div class="flow-marker <?php echo $markerClass; ?>"><?php echo $stepIndex; ?></div>
                    <div class="step-card">
                        <div class="step-card-top">
                            <div style="min-width:0;">
                                <div class="step-tags">
                                    <span class="delay-tag"><?php echo $ic['clock']; ?> <?php echo formatDelayChampion($s['delay_days'], $s['delay_hours'], $s['delay_minutes'] ?? 0, $mode, $s['jitter_range_seconds'] ?? 300, $s['delay_seconds'] ?? 0); ?></span>
                                    <span class="dc-mode-chip" style="color:<?php echo $modeInfo['color']; ?>;">
                                        <?php echo $modeInfo['icon']; ?> <?php echo htmlspecialchars($modeInfo['label']); ?>
                                        <span style="color:var(--mu);font-weight:600;">· <?php $tt = ($mode === 'chaos') ? 'non-linear patterns' : (($mode === 'ghost') ? 'up to 2x scatter' : $modeInfo['desc']); echo htmlspecialchars($tt); ?></span>
                                    </span>
                                </div>
                                <h4><?php echo $s['template_name'] ? $ic['file'] . ' Template: ' . htmlspecialchars($s['template_name']) : $ic['chat'] . ' Custom text message'; ?></h4>
                            </div>
                            <form action="../api/drip_step_delete.php" method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Remove this step from the sequence?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <input type="hidden" name="campaign_id" value="<?php echo $id; ?>">
                                <button type="submit" class="step-del-btn"><?php echo $ic['trash']; ?> Remove</button>
                            </form>
                        </div>
                        <div class="step-meta">
                            Pattern: <code><?php echo htmlspecialchars($flexGap); ?></code>
                            · Max variants: <strong><?php echo (int)($s['max_variants'] ?? 3); ?></strong>
                            · Jitter: ±<?php echo (int)($s['jitter_range_seconds'] ?? 300); ?>s
                        </div>
                        <?php if (!empty($s['message_text'])): ?>
                        <div class="step-msg-preview"><?php echo nl2br(htmlspecialchars($s['message_text'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($timePreview)): ?>
        <div class="tp">
            <h4><?php echo $ic['clock']; ?> Live send-time preview <span style="font-weight:600;color:var(--mu)">(3 sample contacts)</span></h4>
            <div class="tp-wrap">
                <table class="time-preview-table">
                    <thead>
                        <tr><th>Step</th><th>Contact A</th><th>Contact B</th><th>Contact C</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timePreview as $contactIdx => $times): ?>
                        <tr>
                            <td>Step <?php echo $contactIdx + 1; ?></td>
                            <?php foreach ($times as $t): ?>
                            <td><?php echo htmlspecialchars($t); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p>Times differ for each contact because of randomization, so no two sequences look alike.</p>
        </div>
        <?php endif; ?>
    </section>

    <!-- Configuration sidebar -->
    <div class="dc-side">

        <!-- Campaign settings -->
        <section class="dc-card">
            <h3><span class="ico"><?php echo $ic['settings']; ?></span> Campaign settings</h3>
            <form action="../api/drip_campaign_update.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>">

                <div class="set-row">
                    <div class="t">Global randomization<small>Applies to every step</small></div>
                    <select name="global_randomization" class="form-input">
                        <?php foreach ($randModes as $key => $info): ?>
                        <option value="<?php echo $key; ?>" <?php echo $grMode === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($info['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="set-row">
                    <div class="t">Business hours<small>Send only 10 AM – 8 PM</small></div>
                    <label class="tg">
                        <input type="hidden" name="respect_business_hours" value="0">
                        <input type="checkbox" name="respect_business_hours" value="1" <?php echo $respectBHflag ? 'checked' : ''; ?>>
                        Enforce
                    </label>
                </div>
                <div class="set-row">
                    <div class="t">Max messages / day<small>Per contact</small></div>
                    <input type="number" name="max_messages_per_day" class="form-input" value="<?php echo (int)($campaign['max_messages_per_day'] ?? 10); ?>" min="1" max="50">
                </div>
                <div class="set-row">
                    <div class="t">Cooldown<small>Hours between messages</small></div>
                    <input type="number" name="cooldown_hours" class="form-input" value="<?php echo (int)($campaign['cooldown_hours'] ?? 4); ?>" min="1" max="72">
                </div>
                <button type="submit" class="btn-go btn-soft" style="margin-top:12px;">Save settings</button>
            </form>
        </section>

        <!-- Add step -->
        <section class="dc-card">
            <h3><span class="ico"><?php echo $ic['plus']; ?></span> Add a step</h3>
            <form action="../api/drip_step_add.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="drip_campaign_id" value="<?php echo $id; ?>">

                <div class="dc-sec">
                    <div class="dc-sec-h"><b>1</b> How long to wait</div>
                    <div class="delay-grid">
                        <div><input type="number" name="delay_days" class="form-input" value="0" min="0"><span class="u">DAYS</span></div>
                        <div><input type="number" name="delay_hours" class="form-input" value="0" min="0" max="23"><span class="u">HOURS</span></div>
                        <div><input type="number" name="delay_minutes" class="form-input" value="0" min="0" max="59"><span class="u">MINS</span></div>
                        <div><input type="number" name="delay_seconds" class="form-input" value="0" min="0" max="59"><span class="u">SECS</span></div>
                    </div>
                    <p class="dc-help">Counted from the trigger. Seconds are supported, for example 25s or 1m 45s.</p>
                </div>

                <div class="dc-sec">
                    <div class="dc-sec-h"><b>2</b> Timing pattern</div>
                    <div class="mode-grid">
                        <?php foreach ($randModes as $key => $info): ?>
                        <label class="mode-option<?php echo $key === 'human' ? ' selected' : ''; ?>" onclick="selectMode(this,'<?php echo $key; ?>')">
                            <input type="radio" name="randomization_mode" value="<?php echo $key; ?>" <?php echo $key === 'human' ? 'checked' : ''; ?>>
                            <div class="mode-ico" style="color:<?php echo $info['color']; ?>"><?php echo $info['icon']; ?></div>
                            <div class="mode-name"><?php echo htmlspecialchars($info['label']); ?></div>
                            <div class="mode-desc"><?php echo htmlspecialchars($info['desc']); ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <label class="lab" for="jitterInput">Jitter range (seconds, 0–3600)</label>
                    <input type="number" name="jitter_range_seconds" class="form-input" value="300" min="0" max="3600" id="jitterInput">
                    <p class="dc-help">Extra seconds added on top of the pattern.</p>

                    <label class="lab" for="maxVar" style="margin-top:14px;">Message variants per contact</label>
                    <select name="max_variants" id="maxVar" class="form-input">
                        <?php for ($i=1;$i<=5;$i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i===3?'selected':''; ?>><?php echo $i; ?> unique variant<?php echo $i>1?'s':''; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="dc-sec">
                    <div class="dc-sec-h"><b>3</b> What to send</div>
                    <label class="lab" for="tplSel">WhatsApp template <span style="font-weight:600;text-transform:none;">(optional)</span></label>
                    <select name="template_id" id="tplSel" class="form-input">
                        <option value="">No template, send custom text</option>
                        <?php echo $templateOptions; ?>
                    </select>
                    <p class="dc-help">Choose a template or write your own message below. You need at least one. Custom text gets a slightly different wording for every contact.</p>

                    <label class="lab" for="msgTxt" style="margin-top:14px;">Message text</label>
                    <textarea name="message_text" id="msgTxt" class="form-input" rows="3" placeholder="Hi {{name}}, just checking in about your enquiry..."></textarea>
                    <p class="dc-help">You can use {{name}}, {{1}}, {{company}} and {{product}}.</p>
                </div>

                <button type="submit" class="btn-go"><?php echo $ic['plus']; ?> Add step to flow</button>
            </form>

            <div class="pro-tip">
                <?php echo $ic['bulb']; ?>
                <div>
                    <h5>Which pattern should I pick?</h5>
                    <p><strong>Ghost</strong> and <strong>Chaos</strong> feel like a real person texting, great for cold outreach. Use <strong>Human</strong> for warm leads and <strong>Minimal</strong> for time-sensitive updates.</p>
                </div>
            </div>
        </section>
    </div>
</div>

</div>

<script>
function selectMode(el, mode) {
    document.querySelectorAll('.mode-option').forEach(function (o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    var r = el.querySelector('input[type=radio]');
    if (r) r.checked = true;
}
</script>

<?php include '../includes/client_footer.php'; ?>
