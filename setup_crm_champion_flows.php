<?php
/**
 * CRM → DRIP CHAMPION AUTOMATIC FLOW SETUP
 * ------------------------------------------
 * Maps every AI CRM outcome to a purpose-built Drip Champion flow:
 *
 *   CRM STAGE        →  CHAMPION FLOW                 STRATEGY
 *   Follow-up (warm) →  AI Nurture — Warm Leads       value → proof → demo → last call
 *   Converted        →  AI Onboarding (existing #11)  welcome → setup → feedback/referral
 *   Declined         →  AI Win-Back — Declined        graceful thanks → win-back offer
 *   Not Interested   →  AI Re-Engage — Silent         one gentle re-engage after 30d
 *
 * The AI pipeline then runs 100% automatically:
 *   customer msg → AI reply → AI classifies CRM stage → label applied
 *   → matching champion flow enrolls with per-client jitter + variants
 *   → CLI worker delivers on humanized schedule
 *
 * Also: pauses old test campaigns on those triggers (reversible in UI)
 * and removes the 2 crash-artifact steps from campaign #11.
 */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/includes/drip_engine.php';

$companyId = 2;
drip_champion_ensure_schema($conn);

echo "═══ CRM → DRIP CHAMPION FLOW SETUP ═══\n\n";

// ── 1. Remove crash-artifact steps from AI Onboarding (#11): 0-delay template steps ──
$r = $conn->query(
    "SELECT s.id FROM drip_steps s
     JOIN drip_campaigns c ON c.id = s.drip_campaign_id
     WHERE c.company_id=$companyId AND c.trigger_label='Converted' AND c.name LIKE 'AI Onboarding%'
       AND s.template_id IS NOT NULL AND s.delay_days=0 AND s.delay_hours=0
       AND COALESCE(s.delay_minutes,0)=0 AND COALESCE(s.delay_seconds,0)=0"
);
$junkSteps = [];
while ($row = $r->fetch_assoc()) $junkSteps[] = (int)$row['id'];
if ($junkSteps) {
    $in = implode(',', $junkSteps);
    $conn->query("DELETE FROM drip_executions WHERE drip_step_id IN ($in) AND status='pending'");
    $conn->query("DELETE FROM drip_steps WHERE id IN ($in)");
    echo "1. Removed crash-artifact steps from AI Onboarding: #" . implode(', #', $junkSteps) . " ✅\n";
} else {
    echo "1. AI Onboarding campaign clean — no artifact steps ✅\n";
}

// ── 2. Champion flow definitions per CRM stage ──
$flows = [
    [
        'name' => 'AI Nurture — Warm Leads (Champion)',
        'trigger' => 'Follow-up',
        'steps' => [
            // [d, h, m, s, mode, jitter, variants, message]
            [0, 0, 2, 30, 'minimal', 90, 3,
                "{{name}} sir/madam, interest kaatiya thanks! 🙏 Waamini epdi ungal business-ku help pannum nu short-ah sollatuma? Bulk campaigns, auto-reply bot, WhatsApp la order & payment — ellam oru dashboard la. Ungal business type enna?"],
            [1, 0, 0, 0, 'human', 300, 3,
                "Hi {{name}}! 500+ businesses already use Waamini to grow on WhatsApp — cart recovery alone brings back ~20% of lost orders 📈 Oru 20-min free demo la live-ah kaatturom. Indha week epo convenient?"],
            [3, 0, 0, 0, 'human', 300, 3,
                "{{name}} sir/madam, quick check-in 🙏 Demo slot innum open ah irukku — neenga ready-na 'demo' nu reply pannunga, naanga time fix pannuvom. Questions irundhalum kelunga!"],
            [7, 0, 0, 0, 'human', 600, 3,
                "Hi {{name}}, last check from our side! Waamini pathi doubts irundha eppo venumnalum reply pannalam — we're here 👍 Ungal business-ku vazhthukkal!\n— Team 7digit | +91 91508 02906"],
        ],
    ],
    [
        'name' => 'AI Win-Back — Declined (Champion)',
        'trigger' => 'Declined',
        'steps' => [
            [1, 0, 0, 0, 'human', 300, 3,
                "{{name}} sir/madam, ungal time-ku romba nandri 🙏 Ippo venam nu sonnadhu completely fine. Future la WhatsApp automation venum na, naanga eppovum inga irukkom. All the best!"],
            [14, 0, 0, 0, 'ghost', 900, 3,
                "Hi {{name}}! 2 weeks munnadi pesinom — appo timing correct-ah illa. Ippo Waamini la pudhu features vandhirukku + special offer nadakkudhu 🚀 Oru quick look venuma? 'yes' nu reply pannunga."],
        ],
    ],
    [
        'name' => 'AI Re-Engage — Not Interested (Champion)',
        'trigger' => 'Not Interested',
        'steps' => [
            [30, 0, 0, 0, 'ghost', 1800, 2,
                "Vanakkam {{name}}! Oru month munnadi Waamini pathi pesinom. Business needs maarirundha — WhatsApp la customers-a automate panna eppovum ready 🙏 Interest illa na indha message-a ignore pannalam, disturb panna maattom."],
        ],
    ],
];

echo "\n2. Creating champion flows per CRM stage:\n";
$created = [];
foreach ($flows as $f) {
    // Pause old campaigns on this trigger (reversible), except our own AI flows
    $st = $conn->prepare("SELECT id, name FROM drip_campaigns WHERE company_id=? AND trigger_label=? AND status='active' AND name NOT LIKE 'AI %'");
    $st->bind_param('is', $companyId, $f['trigger']);
    $st->execute();
    $olds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($olds as $o) {
        $conn->query("UPDATE drip_campaigns SET status='paused' WHERE id=" . (int)$o['id']);
        echo "   ⏸️  Paused old test campaign #{$o['id']} \"{$o['name']}\" (trigger {$f['trigger']}) — re-activate in UI if needed\n";
    }

    // Skip if our AI flow already exists
    $st = $conn->prepare("SELECT id FROM drip_campaigns WHERE company_id=? AND trigger_label=? AND name=? LIMIT 1");
    $st->bind_param('iss', $companyId, $f['trigger'], $f['name']);
    $st->execute();
    $exists = $st->get_result()->fetch_assoc();
    $st->close();
    if ($exists) {
        echo "   ✅ \"{$f['name']}\" already exists (#{$exists['id']})\n";
        $created[$f['trigger']] = (int)$exists['id'];
        continue;
    }

    $st = $conn->prepare("INSERT INTO drip_campaigns (company_id, name, trigger_label, status, global_randomization, respect_business_hours, max_messages_per_day, cooldown_hours, created_at) VALUES (?, ?, ?, 'active', 1, 1, 5, 4, NOW())");
    $st->bind_param('iss', $companyId, $f['name'], $f['trigger']);
    $st->execute();
    $cid = (int)$conn->insert_id;
    $st->close();

    $ord = 1;
    foreach ($f['steps'] as $s) {
        [$d, $h, $m, $sec, $mode, $jit, $mv, $msg] = $s;
        $st = $conn->prepare("INSERT INTO drip_steps (drip_campaign_id, step_order, delay_days, delay_hours, delay_minutes, delay_seconds, template_id, message_text, randomization_mode, jitter_range_seconds, max_variants) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)");
        $st->bind_param('iiiiiissii', $cid, $ord, $d, $h, $m, $sec, $msg, $mode, $jit, $mv);
        $st->execute();
        $st->close();
        $ord++;
    }
    $created[$f['trigger']] = $cid;
    echo "   ✅ Created \"{$f['name']}\" (#$cid, " . count($f['steps']) . " steps, humanized)\n";
}

// Converted → existing AI Onboarding
$r = $conn->query("SELECT id FROM drip_campaigns WHERE company_id=$companyId AND trigger_label='Converted' AND name LIKE 'AI Onboarding%' AND status='active' LIMIT 1");
$row = $r->fetch_assoc();
if ($row) $created['Converted'] = (int)$row['id'];

// ── 3. Final CRM → flow map ──
echo "\n3. ACTIVE CRM → DRIP CHAMPION MAP:\n";
echo str_repeat('─', 74) . "\n";
printf("   %-18s %-42s %s\n", "CRM STAGE (AI)", "CHAMPION FLOW", "STEPS");
echo str_repeat('─', 74) . "\n";
foreach (['Follow-up', 'Converted', 'Declined', 'Not Interested'] as $trigger) {
    $st = $conn->prepare("SELECT id, name FROM drip_campaigns WHERE company_id=? AND trigger_label=? AND status='active' ORDER BY id ASC LIMIT 1");
    $st->bind_param('is', $companyId, $trigger);
    $st->execute();
    $c = $st->get_result()->fetch_assoc();
    $st->close();
    if ($c) {
        $n = $conn->query("SELECT COUNT(*) c FROM drip_steps WHERE drip_campaign_id={$c['id']}")->fetch_assoc()['c'];
        printf("   %-18s #%-3d %-37s %d\n", $trigger, $c['id'], mb_substr($c['name'], 0, 37), $n);
    } else {
        printf("   %-18s %s\n", $trigger, "❌ none active");
    }
}
echo str_repeat('─', 74) . "\n";
echo "\n✅ CRM → Drip Champion mapping is LIVE and fully automatic\n";
?>
