<?php
/**
 * CRM LEVEL ANALYSIS REPORT
 * Shows the funnel level of every contact + AI analysis state + drip/automation sync status.
 *
 * Levels:
 *   L1 NEW        — contact exists, no conversation yet
 *   L2 ENGAGED    — chatting, no outcome decided yet
 *   L3 FOLLOW-UP  — warm lead (drip/lead automation active)
 *   L4 CONVERTED  — customer 🏆
 *   L4 DECLINED / NOT INTERESTED — closed lost
 */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/includes/ai_crm_sync.php';

$companyId = 2;
crm_ensure_analysis_table($conn);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║           CRM LEVEL ANALYSIS — AI AUTO-SYNC REPORT            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "Generated: " . date('Y-m-d H:i:s') . " | Company #$companyId\n\n";

// ── Per-contact levels ──
$res = $conn->query(
    "SELECT c.id, c.name, c.phone, c.chat_labels,
            (SELECT COUNT(*) FROM messages m WHERE m.contact_id=c.id AND m.company_id=c.company_id) AS msg_count,
            a.status AS ai_status, a.confidence, a.auto_applied, a.applied_outcome, a.reason
     FROM contacts c
     LEFT JOIN ai_lead_analysis a ON a.contact_id=c.id AND a.company_id=c.company_id
     WHERE c.company_id=$companyId
     ORDER BY c.id"
);

$funnel = ['L1 NEW' => 0, 'L2 ENGAGED' => 0, 'L3 FOLLOW-UP' => 0, 'L4 CONVERTED' => 0, 'L4 CLOSED-LOST' => 0];
$rows = [];

while ($c = $res->fetch_assoc()) {
    $outcome = crm_labels_get_outcome((string)($c['chat_labels'] ?? ''));
    $msgs = (int)$c['msg_count'];

    if ($outcome === 'Converted') $level = 'L4 CONVERTED';
    elseif ($outcome === 'Declined' || $outcome === 'Not Interested') $level = 'L4 CLOSED-LOST';
    elseif ($outcome === 'Follow-up') $level = 'L3 FOLLOW-UP';
    elseif ($msgs > 0) $level = 'L2 ENGAGED';
    else $level = 'L1 NEW';

    $funnel[$level]++;
    $rows[] = ['level' => $level, 'c' => $c, 'outcome' => $outcome];
}

echo "📊 CRM FUNNEL\n" . str_repeat('─', 64) . "\n";
foreach ($funnel as $lvl => $cnt) {
    $bar = str_repeat('█', min(40, $cnt * 2));
    echo str_pad($lvl, 16) . str_pad($cnt, 5) . " $bar\n";
}
$total = array_sum($funnel);
echo str_pad('TOTAL', 16) . "$total\n\n";

echo "👥 CONTACT LEVELS (with AI state)\n" . str_repeat('─', 64) . "\n";
foreach ($rows as $r) {
    $c = $r['c'];
    $ai = $c['ai_status']
        ? "AI:{$c['ai_status']}({$c['confidence']}%)" . ($c['auto_applied'] ? " AUTO-APPLIED→{$c['applied_outcome']}" : '')
        : 'AI:not-analyzed';
    echo str_pad($r['level'], 16)
        . str_pad('#' . $c['id'] . ' ' . mb_substr((string)($c['name'] ?: $c['phone']), 0, 18), 26)
        . str_pad($c['msg_count'] . ' msgs', 9)
        . str_pad($r['outcome'] ?: '—', 15)
        . $ai . "\n";
}

// ── Automation/drip queue sync state ──
echo "\n⚙️ AUTOMATION QUEUE (lead follow-ups scheduled by outcomes)\n" . str_repeat('─', 64) . "\n";
$q = $conn->query(
    "SELECT type, status, COUNT(*) c FROM automation_queue
     WHERE company_id=$companyId AND type IN ('followup_1','followup_2','instant','delayed')
     GROUP BY type, status ORDER BY type, status"
);
$any = false;
while ($row = $q->fetch_assoc()) {
    echo str_pad($row['type'], 14) . str_pad($row['status'], 10) . $row['c'] . "\n";
    $any = true;
}
if (!$any) echo "(empty — no outcome automations scheduled yet)\n";

echo "\n💧 DRIP EXECUTIONS\n" . str_repeat('─', 64) . "\n";
$hasDrip = $conn->query("SHOW TABLES LIKE 'drip_executions'")->num_rows > 0;
if ($hasDrip) {
    $q = $conn->query("SELECT status, COUNT(*) c FROM drip_executions WHERE company_id=$companyId GROUP BY status");
    $any = false;
    while ($row = $q->fetch_assoc()) { echo str_pad($row['status'], 14) . $row['c'] . "\n"; $any = true; }
    if (!$any) echo "(no drip executions yet — need an active drip campaign with a matching trigger label)\n";
} else {
    echo "(drip_executions table not present)\n";
}

echo "\n" . str_repeat('═', 64) . "\n";
echo "✅ SYNC CHAIN: message → AI classify → chat_labels → automation_queue → drip\n";
echo str_repeat('═', 64) . "\n";
?>
