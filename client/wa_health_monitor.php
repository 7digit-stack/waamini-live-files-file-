<?php
include '../includes/client_auth_check.php';
$pageTitle = 'WA Account Health';
$currentPage = 'wa_health_monitor';

// Get WA config
$stmt_wc = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id=? LIMIT 1");
$stmt_wc->bind_param("i", $companyId);
$stmt_wc->execute();
$waConfig = $stmt_wc->get_result()->fetch_assoc();

// Schema migrations are managed via migrations/ and should not run inline.

// Fetch live health from Meta API
$healthData = null;
$issues = [];
$riskLevel = 'low';

if (!empty($waConfig['access_token']) && !empty($waConfig['phone_number_id'])) {
    $ch = curl_init("https://graph.facebook.com/" . ($waConfig['api_version'] ?? 'v18.0') . "/{$waConfig['phone_number_id']}?fields=quality_rating,messaging_limit_tier,account_mode&access_token={$waConfig['access_token']}");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $healthData = json_decode($resp, true);
    }
}

// Calculate local stats (last 7 days)
$stmt_stats = $conn->prepare("SELECT
    COUNT(*) as total,
    SUM(status='delivered' OR status='read') as delivered,
    SUM(status='read') as `reads`,
    SUM(status='failed') as failed
    FROM messages WHERE company_id=? AND direction='outgoing' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt_stats->bind_param("i", $companyId);
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();

$total    = intval($stats['total'] ?? 0);
$delivRate = $total > 0 ? round($stats['delivered']/$total*100,1) : 0;
$readRate  = $total > 0 ? round($stats['reads']/$total*100,1) : 0;
$failRate  = $total > 0 ? round($stats['failed']/$total*100,1) : 0;

// Risk assessment
if ($failRate > 10) { $issues[] = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> High failure rate (' . $failRate . '%) - Check message content'; $riskLevel = 'high'; }
if ($failRate > 20) { $riskLevel = 'critical'; }
if ($delivRate < 50 && $total > 10) { $issues[] = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg> Low delivery rate - Possible spam flag'; $riskLevel = max_risk($riskLevel,'medium'); }

$qualityRating = $healthData['quality_rating'] ?? 'UNKNOWN';
if ($qualityRating === 'RED') { $issues[] = '<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#ef4444"/></svg> Quality rating RED - Ban risk HIGH!'; $riskLevel = 'critical'; }
if ($qualityRating === 'YELLOW') { $issues[] = '<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#f59e0b"/></svg> Quality rating YELLOW - Improve message quality'; $riskLevel = max_risk($riskLevel,'medium'); }

function max_risk($a, $b) {
    $map = ['low'=>0,'medium'=>1,'high'=>2,'critical'=>3];
    return $map[$a] >= $map[$b] ? $a : $b;
}

// Save health log
$issuesJson = json_encode($issues);
$stmt_log = $conn->prepare("INSERT INTO wa_health_logs (company_id,quality_rating,delivery_rate,read_rate,failed_rate,risk_level,issues_json) VALUES (?,?,?,?,?,?,?)");
$stmt_log->bind_param("isdddss", $companyId,$qualityRating,$delivRate,$readRate,$failRate,$riskLevel,$issuesJson);
$stmt_log->execute();

// Historical health logs
$stmt_hist = $conn->prepare("SELECT * FROM wa_health_logs WHERE company_id=? ORDER BY id DESC LIMIT 14");
$stmt_hist->bind_param("i", $companyId);
$stmt_hist->execute();
$history = $stmt_hist->get_result()->fetch_all(MYSQLI_ASSOC);

$riskColors = ['low'=>'#00b894','medium'=>'#f39c12','high'=>'#e17055','critical'=>'#e74c3c'];
$riskColor = $riskColors[$riskLevel];

include '../includes/client_header.php';
?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> WhatsApp Account Health</h2>
    <div style="font-size:12px;color:var(--text-muted);">Real-time monitoring — auto-checked every visit</div>
</div>

<!-- Risk Badge -->
<div style="background:<?php echo $riskColor; ?>15;border:2px solid <?php echo $riskColor; ?>;border-radius:16px;padding:20px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
    <div style="font-size:48px;"><?php echo ['low'=>'<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#10b981"/></svg>','medium'=>'<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#f59e0b"/></svg>','high'=>'<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#f97316"/></svg>','critical'=>'<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#ef4444"/></svg>'][$riskLevel]; ?></div>
    <div>
        <div style="font-size:20px;font-weight:800;color:<?php echo $riskColor; ?>;"><?php echo strtoupper($riskLevel); ?> RISK</div>
        <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
            <?php echo ['low'=>'Your account is healthy! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>','medium'=>'Some issues detected — review recommended','high'=>'Action needed — fix issues to avoid ban','critical'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> CRITICAL — Immediate action required!'][$riskLevel]; ?>
        </div>
    </div>
    <?php if($qualityRating !== 'UNKNOWN'): ?>
    <div style="margin-left:auto;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;">META QUALITY</div>
        <div style="font-size:18px;font-weight:800;color:<?php echo ['GREEN'=>'#00b894','YELLOW'=>'#f39c12','RED'=>'#e74c3c'][$qualityRating]??'#636e72'; ?>"><?php echo $qualityRating; ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
    <?php foreach([
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>','Messages Sent',$total,'#6c63ff'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>','Delivery Rate',$delivRate.'%','#00b894'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>','Read Rate',$readRate.'%','#74b9ff'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>','Failed Rate',$failRate.'%','#e74c3c'],
    ] as [$icon,$label,$val,$color]): ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;">
        <div style="font-size:20px;"><?php echo $icon; ?></div>
        <div style="font-size:22px;font-weight:800;color:<?php echo $color; ?>;margin-top:4px;"><?php echo $val; ?></div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;"><?php echo $label; ?> (7 days)</div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Issues -->
<?php if(!empty($issues)): ?>
<div class="form-card" style="max-width:none;margin-bottom:24px;border-left:4px solid <?php echo $riskColor; ?>;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:<?php echo $riskColor; ?>;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Issues Detected</h3>
    <?php foreach($issues as $issue): ?>
    <div style="padding:10px;background:var(--bg-input);border-radius:8px;margin-bottom:8px;font-size:13px;"><?php echo htmlspecialchars($issue); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Tips -->
<div class="form-card" style="max-width:none;margin-bottom:24px;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> Health Tips</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
        <?php foreach([
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>','Never send spam','Always get opt-in before sending campaigns'],
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>','Quality messages','Personalize with {{name}} — avoid bulk generic texts'],
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>','Right timing','Send between 9AM-7PM only'],
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>','Monitor blocks','If many contacts block you — reduce campaign frequency'],
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>','Warm up account','New number — start with 100/day, increase slowly'],
            ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>','Template approval','Use Meta-approved templates for campaigns'],
        ] as [$icon,$title,$tip]): ?>
        <div style="background:var(--bg-input);border-radius:8px;padding:12px;">
            <div style="font-weight:700;margin-bottom:4px;"><?php echo "$icon $title"; ?></div>
            <div style="color:var(--text-muted);"><?php echo $tip; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- History Chart -->
<?php if(count($history) > 1): ?>
<div class="form-card" style="max-width:none;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg> Health History (Last 14 checks)</h3>
    <canvas id="healthChart" height="80"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var hist = <?php echo json_encode(array_reverse($history), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
new Chart(document.getElementById('healthChart'),{
    type:'line',
    data:{
        labels: hist.map(h=>h.checked_at.substring(5,16)),
        datasets:[
            {label:'Delivery %',data:hist.map(h=>h.delivery_rate),borderColor:'#00b894',tension:0.4,fill:false},
            {label:'Read %',data:hist.map(h=>h.read_rate),borderColor:'#74b9ff',tension:0.4,fill:false},
            {label:'Failed %',data:hist.map(h=>h.failed_rate),borderColor:'#e74c3c',tension:0.4,fill:false},
        ]
    },
    options:{responsive:true,scales:{y:{beginAtZero:true,max:100}}}
});
</script>
<?php endif; ?>
<?php include '../includes/client_footer.php'; ?>

