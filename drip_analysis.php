<?php
/** DRIP CAMPAIGN DEEP ANALYSIS — schema + campaigns + steps + executions + gaps */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';

$companyId = 2;
echo "═══ DRIP SYSTEM DEEP ANALYSIS ═══\n\n";

echo "1) drip_campaigns schema:\n";
$r = $conn->query("SHOW COLUMNS FROM drip_campaigns");
while ($c = $r->fetch_assoc()) echo "   {$c['Field']} ({$c['Type']})" . ($c['Null']==='NO'?' NOT NULL':'') . "\n";

echo "\n2) drip_steps schema:\n";
$r = $conn->query("SHOW COLUMNS FROM drip_steps");
while ($c = $r->fetch_assoc()) echo "   {$c['Field']} ({$c['Type']})\n";

echo "\n3) drip_executions schema:\n";
$r = $conn->query("SHOW COLUMNS FROM drip_executions");
while ($c = $r->fetch_assoc()) echo "   {$c['Field']} ({$c['Type']})\n";

echo "\n4) Existing campaigns (company $companyId):\n";
$r = $conn->query("SELECT * FROM drip_campaigns WHERE company_id=$companyId");
if ($r->num_rows === 0) echo "   (none)\n";
while ($c = $r->fetch_assoc()) {
    echo "   #{$c['id']} \"{$c['name']}\" trigger='{$c['trigger_label']}' status={$c['status']} randomization={$c['global_randomization']} bh={$c['respect_business_hours']}\n";
    $s = $conn->query("SELECT *, randomization_mode, jitter_range_seconds, max_variants FROM drip_steps WHERE drip_campaign_id={$c['id']} ORDER BY COALESCE(step_order,0)");
    while ($st = $s->fetch_assoc()) {
        $msg = mb_substr((string)($st['message_text'] ?? ''), 0, 60);
        echo "      step#{$st['id']} mode={$st['randomization_mode']} jitter={$st['jitter_range_seconds']}s variants={$st['max_variants']} delay={$st['delay_days']}d {$st['delay_hours']}h " . ($st['delay_minutes'] ?? 0) . "m tpl=" . ($st['template_id'] ?? '-') . " msg=\"$msg\"\n";
    }
}

echo "\n4b) Drip Champion column presence:\n";
$checks = [
    'drip_steps.randomization_mode'         => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_steps' AND COLUMN_NAME='randomization_mode'",
    'drip_steps.jitter_range_seconds'        => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_steps' AND COLUMN_NAME='jitter_range_seconds'",
    'drip_steps.message_variations'          => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_steps' AND COLUMN_NAME='message_variations'",
    'drip_executions.message_variations'     => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_executions' AND COLUMN_NAME='message_variations'",
    'drip_executions.visited_variants'       => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_executions' AND COLUMN_NAME='visited_variants'",
    'drip_executions.delivered_variant'      => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_executions' AND COLUMN_NAME='delivered_variant'",
    'drip_executions.send_attempted_at'      => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_executions' AND COLUMN_NAME='send_attempted_at'",
    'drip_campaigns.global_randomization'    => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_campaigns' AND COLUMN_NAME='global_randomization'",
    'drip_campaigns.respect_business_hours' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_campaigns' AND COLUMN_NAME='respect_business_hours'",
    'drip_campaigns.max_messages_per_day'   => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_campaigns' AND COLUMN_NAME='max_messages_per_day'",
    'drip_campaigns.cooldown_hours'         => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='drip_campaigns' AND COLUMN_NAME='cooldown_hours'",
];
foreach ($checks as $label => $q) {
    $r = $conn->query($q);
    $present = ($r && $r->fetch_row()[0] > 0) ? '✅' : '❌ MISSING';
    echo "   $present $label\n";
}

echo "\n5) Execution stats:\n";
$r = $conn->query("SELECT status, COUNT(*) c FROM drip_executions WHERE company_id=$companyId GROUP BY status");
while ($row = $r->fetch_assoc()) echo "   {$row['status']}: {$row['c']}\n";

echo "\n6) Send-time distribution of sent drips (hour of day):\n";
$r = $conn->query("SELECT HOUR(sent_at) h, COUNT(*) c FROM drip_executions WHERE company_id=$companyId AND sent_at IS NOT NULL GROUP BY h ORDER BY h");
while ($row = $r->fetch_assoc()) echo "   " . str_pad($row['h'] . ':00', 7) . str_repeat('█', $row['c']) . " {$row['c']}\n";

echo "\n7) whatsapp_config present? ";
$r = $conn->query("SELECT company_id, phone_number_id FROM whatsapp_config WHERE company_id=$companyId LIMIT 1");
echo $r->num_rows > 0 ? "YES\n" : "NO (drip_tick would fail)\n";

echo "\n8) GAP CHECK — campaigns for AI outcome labels:\n";
foreach (['Follow-up', 'Converted', 'Declined', 'Not Interested'] as $lbl) {
    $st = $conn->prepare("SELECT id, status FROM drip_campaigns WHERE company_id=? AND trigger_label=? LIMIT 1");
    $st->bind_param('is', $companyId, $lbl);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    echo "   '$lbl' → " . ($row ? "campaign #{$row['id']} ({$row['status']})" : "❌ NO CAMPAIGN (AI label triggers nothing!)") . "\n";
}
?>
