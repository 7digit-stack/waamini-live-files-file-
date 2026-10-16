<?php
/**
 * AI Drip Campaign Seeder — creates the missing 'Converted' onboarding campaign.
 * Idempotent: skips any trigger label that already has an active campaign.
 * Steps use message_text (no WhatsApp template approval needed) with smart
 * send-time windows applied automatically by drip_engine.
 */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';

$companyId = 2;

$campaigns = [
    [
        'name' => 'AI Onboarding — Converted Customers',
        'trigger_label' => 'Converted',
        'steps' => [
            // [delay_days, delay_hours, delay_minutes, message]
            [0, 1, 0, "Vanakkam {{name}} sir/madam 🙏 Welcome to the Waamini family!\n\nUngal account setup na engaloda team help pannum. Setup-ku ready-ah irundha reply pannunga.\n\nAny questions, we're one message away!\n— Team 7digit | +91 91508 02906"],
            [3, 0, 0, "Hi {{name}}! Waamini setup eppadi pogudhu? 🚀\n\nBulk campaigns, auto-reply bot, catalog — edhavadhu doubt irundha kelunga. Free training call venum na sollunga.\n\n— Team 7digit"],
            [7, 0, 0, "{{name}} sir/madam, one week complete! 🎉\n\nWaamini results eppadi irukku? Ungal feedback romba mukkiyam — 1 line reply podhunga.\n\nRefer a business friend & get benefits! Details venum na 'refer' nu reply pannunga.\n— Team 7digit"],
        ],
    ],
];

foreach ($campaigns as $c) {
    // Skip if an active campaign already exists for this trigger
    $st = $conn->prepare("SELECT id FROM drip_campaigns WHERE company_id=? AND trigger_label=? AND status='active' LIMIT 1");
    $st->bind_param('is', $companyId, $c['trigger_label']);
    $st->execute();
    $exists = $st->get_result()->fetch_assoc();
    $st->close();
    if ($exists) {
        echo "'{$c['trigger_label']}' already has active campaign #{$exists['id']} — skipped\n";
        continue;
    }

    $st = $conn->prepare("INSERT INTO drip_campaigns (company_id, name, trigger_label, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
    $st->bind_param('iss', $companyId, $c['name'], $c['trigger_label']);
    $st->execute();
    $cid = (int)$conn->insert_id;
    $st->close();

    $order = 1;
    foreach ($c['steps'] as $s) {
        [$d, $h, $m, $msg] = $s;
        $st = $conn->prepare("INSERT INTO drip_steps (drip_campaign_id, step_order, delay_days, delay_hours, delay_minutes, template_id, message_text) VALUES (?, ?, ?, ?, ?, NULL, ?)");
        $st->bind_param('iiiiis', $cid, $order, $d, $h, $m, $msg);
        $st->execute();
        $st->close();
        $order++;
    }
    echo "✅ Created campaign #{$cid} \"{$c['name']}\" (trigger: {$c['trigger_label']}, " . count($c['steps']) . " steps)\n";
}
?>
