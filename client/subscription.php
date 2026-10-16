<?php
include '../includes/client_auth_check.php';

$pageTitle = 'My Subscription';
$currentPage = 'subscription';

/* ── Latest subscription (any status, so an expired plan is still shown properly) ── */
$sub = null;
try {
    $subStmt = $conn->prepare("
        SELECT s.*, sp.name AS plan_name
        FROM subscriptions s
        LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
        WHERE s.company_id = ?
        ORDER BY (s.status IN ('active','trial')) DESC, s.expires_at DESC, s.id DESC
        LIMIT 1
    ");
    if ($subStmt) {
        $subStmt->bind_param("i", $companyId);
        $subStmt->execute();
        $subRes = $subStmt->get_result();
        if ($subRes) $sub = $subRes->fetch_assoc() ?: null;
    }
} catch (\Throwable $e) {
    // Keep the page usable even if subscription tables/columns vary across installs.
    $sub = null;
}

/* ── Payment history (table may not exist on older installs) ── */
$payments = [];
try {
    $payStmt = $conn->prepare("
        SELECT sp.*, p.name AS plan_name
        FROM subscription_payments sp
        JOIN subscriptions sub ON sp.subscription_id = sub.id
        LEFT JOIN subscription_plans p ON sub.plan_id = p.id
        WHERE sub.company_id = ?
        ORDER BY sp.paid_at DESC
        LIMIT 12
    ");
    if ($payStmt) {
        $payStmt->bind_param("i", $companyId);
        $payStmt->execute();
        $pr = $payStmt->get_result();
        while ($pr && ($row = $pr->fetch_assoc())) { $payments[] = $row; }
    }
} catch (\Throwable $e) { /* no history to show */ }

/* ── Derived state ── */
$ts = static function ($v): int { $t = $v ? strtotime((string)$v) : false; return $t ? (int)$t : 0; };
$fmtDate = static function ($v) use ($ts): string { $t = $ts($v); return $t ? date('d M Y', $t) : '—'; };
$money = static function ($v): string { $n = (float)$v; return '₹' . number_format($n, fmod($n, 1) === 0.0 ? 0 : 2); };

$state = 'none'; $startTs = 0; $endTs = 0; $daysLeft = 0; $totalDays = 0; $pctElapsed = 0;
if ($sub) {
    $startTs = $ts($sub['starts_at'] ?? null);
    $endTs   = $ts($sub['expires_at'] ?? null);
    $status  = strtolower((string)($sub['status'] ?? ''));
    $live    = in_array($status, ['active', 'trial'], true);
    $expired = $endTs > 0 && $endTs < strtotime('today');
    if ($status === 'cancelled') $state = 'cancelled';
    elseif (!$live || $expired) $state = 'expired';
    else $state = ($status === 'trial') ? 'trial' : 'active';

    $daysLeft = ($endTs > 0 && $state !== 'expired' && $state !== 'cancelled') ? max(0, (int)floor(($endTs - time()) / 86400)) : 0;
    if ($startTs > 0 && $endTs > $startTs) {
        $totalDays  = max(1, (int)round(($endTs - $startTs) / 86400));
        $pctElapsed = max(0, min(100, (int)round((time() - $startTs) / ($endTs - $startTs) * 100)));
    }
}
$urgent = in_array($state, ['active', 'trial'], true) && $daysLeft <= 7;
$soon   = in_array($state, ['active', 'trial'], true) && $daysLeft <= 30;
$tone   = ($state === 'expired' || $state === 'cancelled' || $urgent) ? 'red' : ($soon ? 'amber' : 'green');
$stateLabel = ['active' => 'Active', 'trial' => 'Free trial', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'none' => 'No plan'][$state];
$ringPct = ($totalDays > 0 && in_array($state, ['active', 'trial'], true)) ? max(2, min(100, (int)round($daysLeft / $totalDays * 100))) : 0;
$needsAction = in_array($state, ['expired', 'cancelled', 'none'], true) || $soon;

/* ── Usage & limits ── */
$usageItems = [
    ['Contacts',         'contacts',       'contacts',       'contacts.php'],
    ['Campaigns / month', 'campaigns',     'campaigns',      'campaigns.php'],
    ['Messages / month', 'messages_month', 'blast',          ''],
    ['Bot replies',      'bot_replies',    'bot',            'bot_replies.php'],
    ['Team members',     'team_members',   'team',           'team_members.php'],
];
$usage = [];
foreach ($usageItems as [$label, $key, $icon, $link]) {
    $used = 0; $limit = 0;
    try { $used = (int)get_usage($key); $limit = (int)get_limit($key); } catch (\Throwable $e) {}
    $pct = $limit > 0 ? (int)min(100, round($used / $limit * 100)) : 0;
    $usage[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'used' => $used, 'limit' => $limit, 'pct' => $pct, 'link' => $link];
}

/* ── Features included in the plan ── */
$featureGroups = [
    'Core modules' => ['whatsapp_chat' => 'WhatsApp Chat', 'contacts' => 'Contacts', 'campaigns' => 'Campaigns', 'templates' => 'Templates', 'bot_replies' => 'Bot Replies', 'team_members' => 'Team Members', 'qr_code' => 'QR Code'],
    'Growth and automation' => ['auto_followup' => 'Auto Follow-up', 'lead_scoring' => 'Lead Scoring', 'drip_campaign' => 'Drip Campaigns', 'language_detect' => 'Language Detect', 'crm_advance' => 'Advanced CRM', 'vcard' => 'Digital vCard', 'quick_replies' => 'Quick Replies', 'pulse' => 'Pulse Monitor', 'google_sheets' => 'Google Sheets', 'indiamart' => 'IndiaMART', 'fb_lead_ads' => 'FB Lead Ads'],
    'Premium and AI' => ['whatsapp_flows' => 'WhatsApp Flows', 'feat_ocr_scanner' => 'OCR Scanner', 'feat_crm_analytics' => 'CRM Analytics', 'feat_voice_analyzer' => 'Voice Analyzer', 'feat_multi_agent' => 'Multi-Agent Chat', 'api_webhook' => 'API and Webhooks'],
    'Commerce' => ['native_checkout' => 'Native Checkout', 'feat_pdf_quotes' => 'PDF Quotations', 'feat_loyalty_points' => 'Loyalty Program'],
    'Reminder packs' => ['festival_reminder' => 'Festival Reminders', 'school_fee_reminder' => 'School Fee Reminders', 'vehicle_reminder' => 'Vehicle Reminders', 'medicine_reminder' => 'Medicine Reminders'],
];
$featOn = 0; $featAll = 0; $featStatus = [];
foreach ($featureGroups as $gTitle => $items) {
    foreach ($items as $k => $lbl) {
        $on = false;
        try { $on = function_exists('has_feature') && has_feature($k); } catch (\Throwable $e) {}
        $featStatus[$k] = $on; $featAll++; if ($on) $featOn++;
    }
}

$paidTotal = 0.0;
foreach ($payments as $p) { if (strtolower((string)($p['status'] ?? '')) === 'success') $paidTotal += (float)($p['amount'] ?? 0); }

include '../includes/client_header.php';
?>

<style>
/* ── My Subscription — dashboard design system, no divider lines ── */
.sb{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.sb *{box-sizing:border-box}
.sb-top{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;margin-bottom:20px;align-items:stretch}
.sb-plan-card{padding:22px 24px;display:flex;flex-direction:column;gap:18px}
.sb-ph{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap}
.sb-ph-l{min-width:0}
.sb-eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--g);margin-bottom:6px}
.sb-ph-name{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.sb-plan{margin:0;font-size:28px;font-weight:800;letter-spacing:-.5px;overflow-wrap:anywhere}
.sb-pill{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;padding:5px 13px;border-radius:999px;background:#E3F7EE;color:var(--gd)}
.sb-pill::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor}
.sb-pill.amber{background:#FEF3DC;color:#8a5a00}.sb-pill.red{background:#FDE7E7;color:#c62828}
.sb-dates{display:flex;gap:10px;flex-wrap:wrap}
.sb-dates div{padding:9px 14px;border-radius:12px;background:var(--soft)}
.sb-dates div small{display:block;font-size:11.5px;font-weight:600;color:var(--mu)}
.sb-dates div b{font-size:14.5px}
.sb-track{max-width:none}
.sb-track-bar{height:8px;border-radius:99px;background:#E3EEE9;overflow:hidden}
.sb-track-bar i{display:block;height:100%;border-radius:99px;background:var(--tc,var(--g))}
.sb-track small{display:block;margin-top:6px;font-size:12.5px;color:var(--mu);font-weight:600}

.sb-banner{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:16px 20px;border-radius:16px;margin-bottom:20px;background:#FEF3DC;color:#7a4b00}
.sb-banner.red{background:#FDE7E7;color:#8f1d1d}
.sb-banner-t{flex:1;min-width:240px}
.sb-banner-t b{display:block;font-size:15.5px;margin-bottom:2px}
.sb-banner-t span{font-size:13.5px;line-height:1.5}
.sb-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.sb-btn:hover{border-color:var(--g);color:var(--g)}
.sb-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.sb-btn--primary:hover{color:#fff;transform:translateY(-1px)}

.sb-kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.sb-kpi{padding:16px 18px;border-radius:14px;background:var(--soft);min-width:0}
.sb-kpi-ico{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;margin-bottom:12px;background:#fff}
.sb-kpi-ico svg{width:19px;height:19px}
.sb-kpi-ico.green{color:var(--g)}.sb-kpi-ico.blue{color:#1d6fd6}.sb-kpi-ico.amber{color:#b7791f}.sb-kpi-ico.purple{color:#6d44d8}
.sb-kpi-l{font-size:13.5px;font-weight:600;color:var(--tx)}
.sb-kpi-v{margin:3px 0 8px;font-size:28px;font-weight:800;letter-spacing:-.5px;line-height:1.15;font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-kpi-d{font-size:12.5px;color:var(--mu);display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.sb-chip{display:inline-block;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700;background:#fff;color:#5f6f6a}
.sb-chip.up{background:#DDF7E7;color:#12803f}.sb-chip.warn{background:#FEF0C7;color:#B45309}
.sb-period{padding:20px 22px;display:flex;flex-direction:column}
.sb-period-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.sb-period-top h2{margin:0;font-size:17px;font-weight:800}
.sb-link{font-size:14px;font-weight:700;color:var(--g);text-decoration:none}.sb-link:hover{color:var(--gd)}
.sb-period-chart{position:relative;flex:1;display:grid;place-items:center;min-height:210px;margin:10px 0}
.sb-period-chart canvas{max-width:210px;max-height:210px}
.sb-period-c{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;text-align:center}
.sb-period-c b{display:block;font-size:34px;font-weight:800;letter-spacing:-.5px;line-height:1.1}
.sb-period-c span{font-size:13px;color:var(--mu)}
.sb-period-leg{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--tx)}
.sb-period-leg span{display:inline-flex;align-items:center;gap:7px}
.sb-period-leg i{width:10px;height:10px;border-radius:3px}
.sb-period-foot{margin-top:14px;text-align:center}

.sb-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;margin-bottom:20px;align-items:start}
.sb-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:16px;min-width:0}
.sb-card h2{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.sb-card h2 small{font-size:12.5px;font-weight:700;color:var(--gd);background:#E3F7EE;padding:3px 10px;border-radius:999px}
.sb-use{display:flex;flex-direction:column;gap:14px}
.sb-use-r{display:flex;flex-direction:column;gap:7px;text-decoration:none;color:inherit;background:var(--soft);border-radius:13px;padding:13px 15px;transition:background .15s}
.sb-use-r:hover{background:#E7F7F1;color:inherit}
.sb-use-top{display:flex;align-items:center;gap:10px;justify-content:space-between}
.sb-use-name{display:flex;align-items:center;gap:10px;font-weight:700;font-size:14.5px}
.sb-use-name svg{width:17px;height:17px;color:var(--g)}
.sb-use-val{font-size:13.5px;font-weight:700;color:var(--tx);font-variant-numeric:tabular-nums}
.sb-use-val em{font-style:normal;color:var(--mu);font-weight:600}
.sb-bar{height:8px;border-radius:99px;background:#DCEBE5;overflow:hidden}
.sb-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--g),var(--g2))}
.sb-bar.warn i{background:linear-gradient(90deg,#F59E0B,#FBBF24)}.sb-bar.full i{background:linear-gradient(90deg,#EF4444,#F87171)}
.sb-bar.unl i{width:100% !important;background:repeating-linear-gradient(135deg,#CDEBDF 0 8px,#E3F7EE 8px 16px)}
.sb-use-note{font-size:12px;font-weight:700}
.sb-use-note.warn{color:#8a5a00}.sb-use-note.full{color:#c62828}

.sb-fg{display:flex;flex-direction:column;gap:8px}
.sb-fg-t{display:flex;justify-content:space-between;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--mu)}
.sb-chips{display:flex;flex-wrap:wrap;gap:7px}
.sb-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:9px;font-size:12.5px;font-weight:700;background:#E3F7EE;color:var(--gd)}
.sb-chip svg{width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}
.sb-chip.off{background:#F1F4F3;color:#8d9c97;font-weight:600}
.sb-card.sb-feats-card{grid-column:1/-1}
.sb-fh{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.sb-fh p{margin:4px 0 0;font-size:13px;color:var(--mu)}
.sb-fx{flex:none;height:34px;padding:0 14px;border:0;border-radius:10px;background:var(--soft);color:var(--gd);font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.sb-fx:hover{background:#E3F7EE}
.sb-acc{display:flex;flex-direction:column;gap:8px}
.sb-ag{border-radius:14px;background:var(--soft);transition:background .2s}
.sb-ag[open]{background:#fff;box-shadow:0 0 0 1px var(--line),0 14px 30px -24px rgba(18,140,126,.55)}
.sb-ag summary{display:flex;align-items:center;gap:14px;padding:12px 14px;cursor:pointer;list-style:none;border-radius:14px}
.sb-ag summary::-webkit-details-marker{display:none}
.sb-ag summary:focus-visible{outline:2px solid var(--g);outline-offset:2px}
.sb-ag-ic{flex:none;width:38px;height:38px;border-radius:11px;display:grid;place-items:center}
.sb-ag-ic svg,.sb-ag-cv,.sb-al-ic svg{fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sb-ag-ic svg{width:19px;height:19px}
.sb-ag-ic.is-core{background:#E3F7EE;color:#0B6B5E}.sb-ag-ic.is-growth{background:#E6F0FF;color:#2563EB}
.sb-ag-ic.is-premium{background:#F1EAFE;color:#7C3AED}.sb-ag-ic.is-commerce{background:#FFF1DE;color:#C2620A}
.sb-ag-ic.is-reminder{background:#FDE8EF;color:#DB2777}.sb-ag-ic.is-other{background:#EEF2F1;color:#475569}
.sb-ag-t{flex:1;min-width:0;display:flex;flex-direction:column}
.sb-ag-t b{font-size:14.5px;font-weight:700;color:var(--ink)}
.sb-ag-t small{font-size:12.5px;color:var(--mu);margin-top:1px}
.sb-ag-bar{flex:none;width:120px;height:6px;border-radius:99px;background:#DDEDE6;overflow:hidden}
.sb-ag-bar i{display:block;height:100%;border-radius:inherit;background:var(--g)}
.sb-ag-n{flex:none;min-width:46px;text-align:center;padding:3px 9px;border-radius:999px;background:#fff;font-size:12px;font-weight:800;color:var(--tx)}
.sb-ag[open] .sb-ag-n{background:var(--soft)}
.sb-ag-n.full{color:var(--gd)}
.sb-ag-cv{flex:none;width:18px;height:18px;color:var(--mu);transition:transform .25s}
.sb-ag[open] .sb-ag-cv{transform:rotate(180deg)}
.sb-al{list-style:none;margin:0;padding:2px 14px 14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px}
.sb-al li{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:11px;background:var(--soft)}
.sb-al-ic{flex:none;width:24px;height:24px;border-radius:8px;display:grid;place-items:center;background:#D5F2E5;color:var(--gd)}
.sb-al-ic svg{width:13px;height:13px;stroke-width:2.6}
.sb-al-n{flex:1;min-width:0;font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-al-s{flex:none;font-size:11.5px;font-weight:700;color:var(--gd)}
.sb-al li.off .sb-al-ic{background:#ECEFEE;color:#8d9c97}
.sb-al li.off .sb-al-n{color:#8d9c97}
.sb-al li.off .sb-al-s{color:#C2620A}
@media (max-width:640px){.sb-ag-bar{display:none}.sb-al{grid-template-columns:1fr}}

.sb-pay{display:flex;flex-direction:column;gap:8px}
.sb-pay-h,.sb-pay-r{display:grid;grid-template-columns:110px minmax(120px,1.4fr) 110px 110px 110px minmax(100px,1fr);gap:12px;align-items:center;padding:11px 14px}
.sb-pay-h{padding-top:2px;padding-bottom:2px;font-size:12px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.sb-pay-r{background:var(--soft);border-radius:13px;font-size:14px}
.sb-pay-r b{font-weight:700}
.sb-amt{font-weight:800;color:var(--gd)}
.sb-ref{font-size:12.5px;color:var(--mu);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-st{display:inline-flex;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:#E3F7EE;color:var(--gd);width:max-content}
.sb-st.pending{background:#FEF3DC;color:#8a5a00}.sb-st.failed{background:#FDE7E7;color:#c62828}.sb-st.refunded{background:#EFE9FD;color:#6d44d8}
.sb-empty{text-align:center;padding:36px 16px}
.sb-empty-ico{width:68px;height:68px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 14px}
.sb-empty-ico svg{width:28px;height:28px}
.sb-empty h3{margin:0 0 6px;font-size:18px;font-weight:800}
.sb-empty p{margin:0 auto 16px;color:var(--mu);font-size:14px;max-width:420px;line-height:1.55}

@media (max-width:1100px){.sb-grid{grid-template-columns:minmax(0,1fr)}.sb-top{grid-template-columns:1fr}}
@media (max-width:820px){.sb-pay-h{display:none}.sb-pay-r{grid-template-columns:1fr auto;row-gap:6px}.sb-pay-r>*:nth-child(n+3){grid-column:1/-1}}
@media (max-width:640px){.sb-plan-card{padding:18px}.sb-plan{font-size:24px}.sb-kpis{grid-template-columns:1fr}}
</style>

<div class="sb">

<?php if ($state === 'none'): ?>
    <div class="sb-card">
        <div class="sb-empty">
            <div class="sb-empty-ico"><?php echo cn_svg('subscription'); ?></div>
            <h3>No subscription yet</h3>
            <p>Your workspace is not on a plan. Contact us and we will activate the right plan for your business.</p>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <a href="support.php" class="sb-btn sb-btn--primary">Contact support</a>
                <a href="<?php echo htmlspecialchars(app_url('pricing.php')); ?>" class="sb-btn">View pricing</a>
            </div>
        </div>
    </div>
<?php else: ?>

<?php
$isLive   = in_array($state, ['active', 'trial'], true);
$toneCol  = $tone === 'red' ? '#DC2626' : ($tone === 'amber' ? '#F59E0B' : '#128C7E');
$daysUsed = $totalDays > 0 ? max(0, $totalDays - ($isLive ? $daysLeft : 0)) : 0;
$payCount = count($payments);
$featLocked = max(0, $featAll - $featOn);
$cycle = !empty($sub['billing_cycle']) ? ucfirst((string)$sub['billing_cycle']) : '';
?>
<div class="sb-top">
    <section class="sb-card sb-plan-card">
        <div class="sb-ph">
            <div class="sb-ph-l">
                <div class="sb-eyebrow">Current plan</div>
                <div class="sb-ph-name"><h1 class="sb-plan"><?php echo htmlspecialchars((string)($sub['plan_name'] ?? 'Your plan')); ?></h1>
                    <span class="sb-pill <?php echo $tone === 'green' ? '' : $tone; ?>"><?php echo $stateLabel; ?></span></div>
            </div>
            <div class="sb-dates">
                <div><small>Started</small><b><?php echo $fmtDate($sub['starts_at'] ?? null); ?></b></div>
                <div><small><?php echo $isLive ? 'Renews or expires' : 'Ended'; ?></small><b><?php echo $fmtDate($sub['expires_at'] ?? null); ?></b></div>
                <?php if ($cycle !== ''): ?><div><small>Billing</small><b><?php echo htmlspecialchars($cycle); ?></b></div><?php endif; ?>
            </div>
        </div>
        <?php if ($totalDays > 0): ?>
        <div class="sb-track" style="--tc:<?php echo $toneCol; ?>">
            <div class="sb-track-bar"><i style="width:<?php echo $pctElapsed; ?>%"></i></div>
            <small><?php echo $pctElapsed; ?>% of your plan period used</small>
        </div>
        <?php endif; ?>
        <div class="sb-kpis">
            <div class="sb-kpi"><div class="sb-kpi-ico green"><?php echo cn_svg('scheduled'); ?></div><div class="sb-kpi-l">Days remaining</div>
                <div class="sb-kpi-v"><?php echo $isLive ? $daysLeft : 0; ?></div>
                <div class="sb-kpi-d"><?php echo $totalDays > 0 ? 'Of <span class="sb-chip">' . $totalDays . ' days</span> in this period' : 'No end date set'; ?></div></div>
            <div class="sb-kpi"><div class="sb-kpi-ico blue"><?php echo cn_svg('billing'); ?></div><div class="sb-kpi-l">Plan price</div>
                <div class="sb-kpi-v"><?php echo isset($sub['amount']) && (float)$sub['amount'] > 0 ? $money($sub['amount']) : '—'; ?></div>
                <div class="sb-kpi-d"><?php echo $cycle !== '' ? 'Billed <span class="sb-chip">' . htmlspecialchars(strtolower($cycle)) . '</span>' : 'Per billing cycle'; ?></div></div>
            <div class="sb-kpi"><div class="sb-kpi-ico amber"><?php echo cn_svg('shield'); ?></div><div class="sb-kpi-l">Features included</div>
                <div class="sb-kpi-v"><?php echo $featOn; ?> / <?php echo $featAll; ?></div>
                <div class="sb-kpi-d"><?php echo $featLocked === 0 ? '<span class="sb-chip up">All unlocked</span>' : '<span class="sb-chip warn">' . $featLocked . ' locked</span> upgrade to unlock'; ?></div></div>
            <div class="sb-kpi"><div class="sb-kpi-ico purple"><?php echo cn_svg('invoice'); ?></div><div class="sb-kpi-l">Total paid</div>
                <div class="sb-kpi-v"><?php echo $paidTotal > 0 ? $money($paidTotal) : '—'; ?></div>
                <div class="sb-kpi-d"><?php echo $payCount > 0 ? 'Across <span class="sb-chip">' . $payCount . ' payment' . ($payCount === 1 ? '' : 's') . '</span>' : 'No payments yet'; ?></div></div>
        </div>
    </section>

    <section class="sb-card sb-period">
        <div class="sb-period-top"><h2>Plan period</h2><a class="sb-link" href="#sbPayments">Payments</a></div>
        <div class="sb-period-chart">
            <canvas id="sbPeriod" width="200" height="200" data-left="<?php echo $isLive ? $daysLeft : 0; ?>" data-used="<?php echo $daysUsed; ?>" data-color="<?php echo $toneCol; ?>" role="img" aria-label="<?php echo $isLive ? $daysLeft : 0; ?> days left in this plan period"></canvas>
            <div class="sb-period-c"><div><b><?php echo $isLive ? $daysLeft : 0; ?></b><span>days left</span></div></div>
        </div>
        <div class="sb-period-leg">
            <span><i style="background:<?php echo $toneCol; ?>"></i>Left: <?php echo $isLive ? $daysLeft : 0; ?> days</span>
            <?php if ($totalDays > 0): ?><span><i style="background:#E3EEE9"></i>Used: <?php echo $daysUsed; ?> days</span><?php endif; ?>
        </div>
        <div class="sb-period-foot"><span class="sb-pill <?php echo $tone === 'green' ? '' : $tone; ?>"><?php echo $isLive ? ($soon ? 'Renews soon: ' : 'Renews on ') . $fmtDate($sub['expires_at'] ?? null) : 'Plan ' . strtolower($stateLabel); ?></span></div>
    </section>
</div>

<?php if ($needsAction): ?>
<div class="sb-banner <?php echo in_array($state, ['expired', 'cancelled'], true) || $urgent ? 'red' : ''; ?>">
    <div class="sb-banner-t">
        <?php if ($state === 'expired'): ?>
            <b>Your plan has expired</b><span>You can still view your data, but sending messages and creating campaigns is paused until you renew.</span>
        <?php elseif ($state === 'cancelled'): ?>
            <b>Your plan was cancelled</b><span>Contact us to reactivate your workspace.</span>
        <?php elseif ($urgent): ?>
            <b>Your plan ends in <?php echo $daysLeft; ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?></b><span>Renew now so sending and campaigns are never interrupted.</span>
        <?php else: ?>
            <b>Your plan renews soon</b><span><?php echo $daysLeft; ?> days remain. Reach out any time to renew or upgrade.</span>
        <?php endif; ?>
    </div>
    <a href="support.php" class="sb-btn sb-btn--primary">Renew or upgrade</a>
    <a href="<?php echo htmlspecialchars(app_url('pricing.php')); ?>" class="sb-btn">See plans</a>
</div>
<?php endif; ?>

<div class="sb-grid">
    <section class="sb-card sb-span sb-usage">
        <div class="sb-ut-head">
            <div><h2>Usage and limits</h2><p class="sb-ut-sub">Pick a resource to see how it grew against your plan limit</p></div>
            <label class="sb-ut-sel"><select id="sbUtYear" aria-label="Year"><option><?php echo (int)date('Y'); ?></option></select></label>
        </div>
        <?php $utColors = ['contacts' => '#128C7E', 'campaigns' => '#2563EB', 'messages_month' => '#0EA5E9', 'bot_replies' => '#7C3AED', 'team_members' => '#D97706']; ?>
        <div class="sb-ut-top">
            <div class="sb-polar" id="sbPolar" role="img" aria-label="Share of each plan limit used">
                <svg viewBox="0 0 1100 640" xmlns="http://www.w3.org/2000/svg">
                    <g id="sbPolarSegs"></g>
                    <rect x="-400" y="565" width="1900" height="75" fill="#fff"/>
                    <g class="sb-polar-knob" id="sbPolarKnob" tabindex="0" role="button" aria-label="Replay chart">
                        <circle cx="550" cy="565" r="112" fill="#F3FBF7"/>
                        <circle cx="550" cy="565" r="92" fill="#fff" stroke="#DDEDE6" stroke-width="2"/>
                        <text id="sbPolarPct" x="550" y="548" text-anchor="middle" class="sb-polar-pct">0%</text>
                        <text id="sbPolarName" x="550" y="584" text-anchor="middle" class="sb-polar-name"></text>
                    </g>
                    <g id="sbPolarLabels"></g>
                </svg>
            </div>
            <?php
            $utFull = 0; $utWarn = 0;
            foreach ($usage as $u) { if ($u['limit'] > 0 && $u['pct'] >= 100) $utFull++; elseif ($u['limit'] > 0 && $u['pct'] >= 85) $utWarn++; }
            ?>
            <div class="sb-ul">
                <div class="sb-ul-head">
                    <div><b>Plan resources</b><small><?php echo count($usage); ?> tracked this billing cycle</small></div>
                    <?php if ($utFull): ?><span class="sb-ul-flag is-full"><?php echo $utFull; ?> at limit</span>
                    <?php elseif ($utWarn): ?><span class="sb-ul-flag is-warn"><?php echo $utWarn; ?> near limit</span>
                    <?php else: ?><span class="sb-ul-flag">All within limits</span><?php endif; ?>
                </div>
                <div class="sb-ut-tiles" id="sbUtTiles" role="listbox" aria-label="Plan resources">
                <?php foreach ($usage as $i => $u):
                    $unl = $u['limit'] <= 0;
                    $cls = $unl ? 'unl' : ($u['pct'] >= 100 ? 'full' : ($u['pct'] >= 85 ? 'warn' : ''));
                    $col = $cls === 'full' ? '#DC2626' : ($utColors[$u['key']] ?? '#128C7E');
                    $exactPct = !$unl ? min(100, round($u['used'] / max(1, $u['limit']) * 100, 1)) : 0;
                    $pill = $unl ? 'No limit' : ($cls === 'full' ? 'Full' : $u['pct'] . '%');
                ?>
                    <button type="button" role="option" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>" class="sb-ut-tile <?php echo $cls; ?><?php echo $i === 0 ? ' is-on' : ''; ?>" style="--c:<?php echo $col; ?>" data-metric="<?php echo htmlspecialchars($u['key']); ?>" data-limit="<?php echo (int)$u['limit']; ?>" data-link="<?php echo htmlspecialchars($u['link']); ?>" data-name="<?php echo htmlspecialchars($u['label']); ?>" data-color="<?php echo $col; ?>" data-pct="<?php echo $exactPct; ?>" data-unl="<?php echo $unl ? 1 : 0; ?>">
                        <span class="sb-ut-ic"><?php echo cn_svg($u['icon']); ?></span>
                        <span class="sb-ut-txt">
                            <span class="sb-ut-name"><?php echo htmlspecialchars($u['label']); ?></span>
                            <span class="sb-ut-val"><b><?php echo number_format($u['used']); ?></b> of <?php echo $unl ? 'unlimited' : number_format($u['limit']); ?> used</span>
                        </span>
                        <span class="sb-ut-bar" aria-hidden="true"><i style="width:<?php echo $unl ? 0 : max(2, $u['pct']); ?>%"></i></span>
                        <span class="sb-ut-pill"><?php echo $pill; ?></span>
                    </button>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="sb-ut-legend">
            <span><i id="sbUtDot" style="background:#128C7E"></i><b id="sbUtLabel">Contacts</b> <small id="sbUtMode">total at the end of each month</small></span>
            <span id="sbUtLimitKey"><i class="dash"></i>Plan limit <b id="sbUtLimit"></b></span>
            <a id="sbUtManage" href="#" class="sb-ut-manage">Manage</a>
        </div>
        <div class="sb-ut-canvas"><canvas id="sbUtChart" role="img" aria-label="Usage compared with the plan limit"></canvas></div>
        <p class="sb-ut-foot" id="sbUtFoot"></p>
    </section>

    <section class="sb-card sb-feats-card">
        <div class="sb-fh">
            <div><h2>What your plan includes</h2><p><?php echo $featOn; ?> of <?php echo $featAll; ?> features are active on your plan</p></div>
            <button type="button" class="sb-fx" id="sbFeatToggle" aria-expanded="false">Expand all</button>
        </div>
        <div class="sb-acc" id="sbFeatAcc">
        <?php
        // Lucide icons per group
        $grpIco = [
            'core'     => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
            'growth'   => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
            'premium'  => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/>',
            'commerce' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
            'reminder' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'other'    => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        ];
        $gi = 0;
        foreach ($featureGroups as $gTitle => $items):
            $gOn = 0; foreach ($items as $k => $l) { if (!empty($featStatus[$k])) $gOn++; }
            $gAll = count($items);
            $gKey = 'other';
            foreach (['core', 'growth', 'premium', 'commerce', 'reminder'] as $kw) { if (stripos($gTitle, $kw) !== false) { $gKey = $kw; break; } }
            $gPct = $gAll ? (int)round($gOn / $gAll * 100) : 0; ?>
            <details class="sb-ag"<?php echo $gi++ === 0 ? ' open' : ''; ?>>
                <summary>
                    <span class="sb-ag-ic is-<?php echo $gKey; ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $grpIco[$gKey]; ?></svg></span>
                    <span class="sb-ag-t"><b><?php echo htmlspecialchars($gTitle); ?></b><small><?php echo $gOn === $gAll ? 'All ' . $gAll . ' included' : $gOn . ' of ' . $gAll . ' included'; ?></small></span>
                    <span class="sb-ag-bar" aria-hidden="true"><i style="width:<?php echo $gPct; ?>%"></i></span>
                    <span class="sb-ag-n<?php echo $gOn === $gAll ? ' full' : ''; ?>"><?php echo $gOn; ?>/<?php echo $gAll; ?></span>
                    <svg class="sb-ag-cv" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <ul class="sb-al">
                <?php foreach ($items as $k => $l): $on = !empty($featStatus[$k]); ?>
                    <li class="<?php echo $on ? 'on' : 'off'; ?>">
                        <span class="sb-al-ic"><?php if ($on): ?><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg><?php else: ?><?php echo cn_lock_icon(); ?><?php endif; ?></span>
                        <span class="sb-al-n"><?php echo htmlspecialchars($l); ?></span>
                        <span class="sb-al-s"><?php echo $on ? 'Included' : 'Upgrade'; ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>
        </div>
    </section>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var btn = document.getElementById('sbFeatToggle'), acc = document.getElementById('sbFeatAcc');
  if (!btn || !acc) return;
  function sync() {
    var all = acc.querySelectorAll('details'), open = acc.querySelectorAll('details[open]').length === all.length;
    btn.textContent = open ? 'Collapse all' : 'Expand all'; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function () {
    var open = btn.getAttribute('aria-expanded') !== 'true';
    acc.querySelectorAll('details').forEach(function (d) { d.open = open; }); sync();
  });
  acc.querySelectorAll('details').forEach(function (d) { d.addEventListener('toggle', sync); });
  sync();
})();
</script>
</div>

<?php endif; ?>

<section class="sb-card" id="sbPayments">
    <h2>Payment history</h2>
    <?php if ($payments): ?>
    <div class="sb-pay">
        <div class="sb-pay-h"><div>Date</div><div>Plan</div><div>Amount</div><div>Method</div><div>Status</div><div>Reference</div></div>
        <?php foreach ($payments as $p):
            $pst = strtolower((string)($p['status'] ?? 'success')); ?>
        <div class="sb-pay-r">
            <div><?php echo $fmtDate($p['paid_at'] ?? null); ?></div>
            <div><b><?php echo htmlspecialchars((string)($p['plan_name'] ?? 'Plan')); ?></b></div>
            <div class="sb-amt"><?php echo $money($p['amount'] ?? 0); ?></div>
            <div><?php echo htmlspecialchars(ucfirst((string)($p['payment_method'] ?? '') ?: '—')); ?></div>
            <div><span class="sb-st <?php echo htmlspecialchars($pst); ?>"><?php echo htmlspecialchars(ucfirst($pst)); ?></span></div>
            <div class="sb-ref" title="<?php echo htmlspecialchars((string)($p['payment_ref'] ?? '')); ?>"><?php echo htmlspecialchars((string)($p['payment_ref'] ?? '') !== '' ? (string)$p['payment_ref'] : '—'); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="sb-empty">
        <div class="sb-empty-ico"><?php echo cn_svg('invoice'); ?></div>
        <h3>No payments yet</h3>
        <p>Payments for your plan will appear here once they are recorded.</p>
    </div>
    <?php endif; ?>
</section>

</div>

<style>
.sb-span{grid-column:1 / -1}
.sb-ut-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.sb-ut-head h2{margin:0}
.sb-ut-sub{margin:4px 0 0;font-size:13.5px;color:var(--mu)}
.sb-ut-sel select{appearance:none;-webkit-appearance:none;height:40px;min-width:112px;padding:0 38px 0 16px;border:0;border-radius:12px;background:#F1F6F4 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 12px center/16px;font:600 14px Inter,system-ui,sans-serif;color:var(--ink);cursor:pointer;outline:0;transition:background-color .15s,box-shadow .15s}
.sb-ut-sel select:hover{background-color:#E6FCF2}.sb-ut-sel select:focus{box-shadow:0 0 0 4px rgba(18,140,126,.14)}
.sb-ut-top{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:22px;align-items:center}
.sb-polar{position:relative;overflow:hidden;padding:10px 0 0;background:transparent}
.sb-polar svg{position:relative;display:block;width:100%;height:auto;overflow:visible}
.sb-polar-seg{cursor:pointer;outline:none;transition:transform .45s cubic-bezier(.2,.8,.2,1),opacity .3s}
.sb-polar.has-sel .sb-polar-seg{opacity:.55}
.sb-polar.has-sel .sb-polar-seg.is-on,.sb-polar-seg:hover,.sb-polar-seg:focus-visible{opacity:1}
.sb-polar-val{font:800 44px Inter,system-ui,sans-serif;fill:#fff;pointer-events:none}
.sb-polar-lab{font:600 30px Inter,system-ui,sans-serif;fill:#34453f}
.sb-polar-lab.is-on{fill:#0F2622;font-weight:800}
.sb-polar-knob{cursor:pointer;outline:none}
.sb-polar-knob:focus-visible circle:nth-child(2){stroke:var(--g)}
.sb-polar-pct{font:800 46px Inter,system-ui,sans-serif;fill:#0F2622}
.sb-polar-name{font:600 21px Inter,system-ui,sans-serif;fill:#586a63}
.sb-ul{display:flex;flex-direction:column;gap:12px}
.sb-ul-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 4px}
.sb-ul-head b{display:block;font-size:14.5px;font-weight:800;color:var(--ink)}
.sb-ul-head small{display:block;margin-top:1px;font-size:12.5px;color:var(--mu)}
.sb-ul-flag{flex:none;padding:4px 10px;border-radius:999px;background:#E3F7EE;color:var(--gd);font-size:12px;font-weight:700}
.sb-ul-flag.is-full{background:#FEE4E2;color:#B42318}.sb-ul-flag.is-warn{background:#FEF0C7;color:#B45309}
.sb-ut-tiles{display:flex;flex-direction:column;gap:4px}
.sb-ut-tile{display:grid;grid-template-columns:36px minmax(0,1fr) 110px 64px;align-items:center;gap:14px;padding:10px 12px;border:0;border-radius:12px;background:transparent;text-align:left;cursor:pointer;font-family:inherit;color:var(--ink);transition:background .15s,box-shadow .15s}
.sb-ut-tile:hover{background:#F6FAF8}
.sb-ut-tile:focus-visible{outline:2px solid var(--c);outline-offset:1px}
.sb-ut-tile.is-on{background:#fff;box-shadow:0 0 0 1px #E3EEE9,0 14px 28px -22px rgba(15,38,34,.55)}
.sb-ut-ic{width:36px;height:36px;border-radius:10px;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);display:grid;place-items:center;transition:background .15s,color .15s}
.sb-ut-tile.is-on .sb-ut-ic{background:var(--c);color:#fff}
.sb-ut-ic svg{width:18px;height:18px}
.sb-ut-txt{min-width:0;display:flex;flex-direction:column;gap:1px}
.sb-ut-name{font-size:13.5px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-ut-val{font-size:12.5px;color:var(--mu);white-space:nowrap}
.sb-ut-val b{font-weight:700;color:var(--tx)}
.sb-ut-bar{height:6px;border-radius:99px;background:#EAF1EE;overflow:hidden}
.sb-ut-bar i{display:block;height:100%;border-radius:inherit;background:var(--c)}
.sb-ut-pill{justify-self:end;min-width:52px;padding:4px 9px;border-radius:999px;text-align:center;font-size:12px;font-weight:800;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c)}
.sb-ut-tile.unl .sb-ut-pill{background:#EEF2F1;color:var(--mu)}
.sb-ut-tile.warn .sb-ut-pill{background:#FEF0C7;color:#B45309}
.sb-ut-tile.full .sb-ut-pill{background:#FEE4E2;color:#B42318}
.sb-ut-tile.full .sb-ut-val{color:#B42318}
@media (max-width:1000px){.sb-ut-top{grid-template-columns:1fr}}
.sb-ut-legend{display:flex;align-items:center;flex-wrap:wrap;gap:6px 22px;font-size:13.5px;font-weight:600;color:var(--mu)}
.sb-ut-legend span{display:inline-flex;align-items:center;gap:8px}
.sb-ut-legend i{width:10px;height:10px;border-radius:50%}
.sb-ut-legend i.dash{width:18px;height:0;border-radius:0;border-top:2.5px dashed #38BDF8}
.sb-ut-legend b{color:var(--ink);font-weight:800}
.sb-ut-legend small{font-size:12.5px;font-weight:500;color:var(--mu)}
.sb-ut-manage{margin-left:auto;font-weight:700;color:var(--g);text-decoration:none;font-size:13.5px}
.sb-ut-manage:hover{text-decoration:underline}
.sb-ut-canvas{position:relative;height:300px}
.sb-ut-canvas canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
.sb-ut-foot{margin:0;font-size:12.5px;color:var(--mu)}
@media (max-width:560px){.sb-ut-tile{grid-template-columns:36px minmax(0,1fr) 60px}.sb-ut-bar{display:none}}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var cv = document.getElementById('sbPeriod'); if (!cv || typeof Chart === 'undefined') return;
  var left = parseInt(cv.dataset.left, 10) || 0, used = parseInt(cv.dataset.used, 10) || 0, none = left + used === 0;
  new Chart(cv, {
    type: 'doughnut',
    data: { labels: ['Days left', 'Days used'], datasets: [{ data: none ? [1] : [left, used], backgroundColor: none ? ['#E3EEE9'] : [cv.dataset.color, '#E3EEE9'], borderWidth: 0, spacing: none ? 0 : 2 }] },
    options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: !none, displayColors: false, backgroundColor: '#0F2622', callbacks: { label: function (c) { return c.label + ': ' + c.parsed + ' days'; } } } } }
  });
})();
</script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var cv = document.getElementById('sbUtChart'); if (!cv || typeof Chart === 'undefined') return;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif'; Chart.defaults.color = '#7a8c86';
  var tiles = document.getElementById('sbUtTiles'), sel = document.getElementById('sbUtYear');
  var fmt = function (v) { return v >= 1000000 ? +(v / 1000000).toFixed(1) + 'M' : (v >= 1000 ? +(v / 1000).toFixed(1) + 'K' : String(v)); };
  var metric = 'contacts', limitShown = false;
  var chart = new Chart(cv, {
    type: 'line',
    data: { labels: [], datasets: [
      { label: 'Used', data: [], borderColor: '#128C7E', backgroundColor: '#128C7E', cubicInterpolationMode: 'monotone', borderWidth: 2.4, pointRadius: 4.5, pointHoverRadius: 6.5, pointBorderWidth: 0, spanGaps: false },
      { label: 'Plan limit', data: [], borderColor: '#38BDF8', backgroundColor: '#38BDF8', borderDash: [7, 6], borderWidth: 2, pointRadius: 0, pointHoverRadius: 0, hidden: true }
    ] },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'nearest', intersect: true },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0F2622', padding: { x: 12, y: 8 }, cornerRadius: 8, displayColors: false, titleFont: { size: 11, weight: '600' }, bodyFont: { size: 14, weight: '800' },
        filter: function (i) { return i.datasetIndex === 0; },
        callbacks: { title: function (i) { return i[0].label.toUpperCase(); }, label: function (c) { return c.parsed.y.toLocaleString('en-IN'); } } } },
      scales: { x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                y: { beginAtZero: true, border: { display: false }, grid: { color: '#EEF3F1' }, ticks: { precision: 0, maxTicksLimit: 5, font: { size: 12 }, callback: function (v) { return fmt(v); } } } }
    }
  });
  var tile = function () { return tiles.querySelector('.sb-ut-tile.is-on'); };
  var load = function (year) {
    var t = tile(), limit = parseInt(t.dataset.limit, 10) || 0;
    fetch('usage_trend.php?metric=' + encodeURIComponent(metric) + (year ? '&year=' + encodeURIComponent(year) : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.labels) return;
        if (sel.options.length !== d.years.length || sel.value !== String(d.year)) sel.innerHTML = d.years.slice().reverse().map(function (y) { return '<option value="' + y + '"' + (y === d.year ? ' selected' : '') + '>' + y + '</option>'; }).join('');
        var vals = d.used.filter(function (v) { return v !== null; }), mx = vals.length ? Math.max.apply(null, vals) : 0;
        // draw the limit line only when it is within sight of the usage; otherwise keep the chart readable and say so
        limitShown = limit > 0 && mx >= limit * 0.35;
        chart.data.labels = d.labels; chart.data.datasets[0].data = d.used;
        var col = t.dataset.color || '#128C7E';
        chart.data.datasets[0].borderColor = col; chart.data.datasets[0].backgroundColor = col;
        var dot = document.getElementById('sbUtDot'); if (dot) dot.style.background = col;
        chart.data.datasets[1].data = d.labels.map(function () { return limit; });
        chart.data.datasets[1].hidden = !limitShown;
        chart.options.scales.y.suggestedMax = limitShown ? Math.ceil(limit * 1.12) : undefined;
        chart.update();
        document.getElementById('sbUtLabel').textContent = d.label;
        document.getElementById('sbUtMode').textContent = d.mode === 'cumulative' ? 'total at the end of each month' : 'used in each month';
        var lk = document.getElementById('sbUtLimitKey'); lk.style.display = limit > 0 && limitShown ? '' : 'none';
        document.getElementById('sbUtLimit').textContent = limit > 0 ? limit.toLocaleString('en-IN') : '';
        var foot = document.getElementById('sbUtFoot');
        foot.textContent = limit <= 0 ? 'Your plan has no limit on ' + d.label.toLowerCase() + '.' : (limitShown ? '' : 'Your plan limit is ' + limit.toLocaleString('en-IN') + ', well above this usage, so it is not drawn.');
        var m = document.getElementById('sbUtManage'), link = t.dataset.link;
        m.style.display = link ? '' : 'none'; m.href = link || '#'; m.textContent = 'Manage ' + t.dataset.name.toLowerCase().replace(' / month', '') + ' \u2192';
      }).catch(function () {});
  };
  tiles.addEventListener('click', function (e) {
    var b = e.target.closest('.sb-ut-tile'); if (!b) return;
    tiles.querySelectorAll('.sb-ut-tile').forEach(function (x) { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
    metric = b.dataset.metric; load(sel.value);
  });
  sel.addEventListener('change', function () { load(sel.value); });
  load('');
})();
</script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Polar usage chart: one slice per resource, slice length = share of the plan limit used. Synced with the list and line chart.
(function () {
  var root = document.getElementById('sbPolar'), tiles = document.getElementById('sbUtTiles'); if (!root || !tiles) return;
  var NS = 'http://www.w3.org/2000/svg', CX = 550, CY = 565, R_MIN = 250, R_MAX = 440;
  var segLayer = document.getElementById('sbPolarSegs'), labLayer = document.getElementById('sbPolarLabels'), knob = document.getElementById('sbPolarKnob');
  var pctEl = document.getElementById('sbPolarPct'), nameEl = document.getElementById('sbPolarName');
  var el = function (tag, attrs, parent) { var n = document.createElementNS(NS, tag); for (var k in attrs) n.setAttribute(k, attrs[k]); if (parent) parent.appendChild(n); return n; };
  var rad = function (d) { return d * Math.PI / 180; };
  var pt = function (a, r) { return [CX + r * Math.cos(rad(a)), CY - r * Math.sin(rad(a))]; };
  var sector = function (a0, a1, r) { if (r <= .5) return ''; var p0 = pt(a0, r), p1 = pt(a1, r); return 'M' + CX + ' ' + CY + 'L' + p0[0] + ' ' + p0[1] + 'A' + r + ' ' + r + ' 0 0 1 ' + p1[0] + ' ' + p1[1] + 'Z'; };
  var clamp = function (v) { return Math.max(0, Math.min(1, v)); };
  var easeOutBack = function (t) { var c1 = 1.45, c3 = c1 + 1; return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2); };
  var easeOutCubic = function (t) { return 1 - Math.pow(1 - t, 3); };
  var short = function (n) { return n.replace(' / month', '').replace('Team members', 'Team').replace('Bot replies', 'Bot replies'); };

  var btns = Array.prototype.slice.call(tiles.querySelectorAll('.sb-ut-tile')), N = btns.length, step = 180 / N;
  var segs = btns.map(function (b, i) {
    var unl = b.dataset.unl === '1', pct = Math.round(parseFloat(b.dataset.pct) || 0);
    var a0 = 180 - i * step, a1 = a0 - step, mid = a0 - step / 2;
    var R = unl ? R_MIN : R_MIN + (Math.min(100, pct) / 100) * (R_MAX - R_MIN);
    var g = el('g', { 'class': 'sb-polar-seg', tabindex: '0', role: 'button', 'aria-label': b.dataset.name + ': ' + (unl ? 'no limit' : pct + '% used') }, segLayer);
    var body = el('path', { fill: b.dataset.color }, g);
    var shade = el('path', { fill: '#000', 'fill-opacity': '.08' }, g);
    var vp = pt(mid, R * .7);
    var txt = el('text', { 'class': 'sb-polar-val', x: vp[0], y: vp[1] + 14, 'text-anchor': 'middle' }, g);
    var lp = pt(mid, R + 34), c = Math.cos(rad(mid));
    var lab = el('text', { 'class': 'sb-polar-lab', x: lp[0], y: lp[1] + 8, 'text-anchor': c > .25 ? 'start' : (c < -.25 ? 'end' : 'middle') }, labLayer);
    lab.textContent = short(b.dataset.name);
    var hx = Math.cos(rad(mid)) * 14, hy = -Math.sin(rad(mid)) * 14;
    var pick = function () { b.click(); };
    g.addEventListener('click', pick);
    g.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
    return { b: b, g: g, body: body, shade: shade, txt: txt, lab: lab, a0: a0, a1: a1, R: R, pct: pct, unl: unl, hx: hx, hy: hy };
  });

  function draw(s, t) {
    var r = s.R * (t <= 0 ? 0 : easeOutBack(t));
    s.body.setAttribute('d', sector(s.a0, s.a1, r));
    s.shade.setAttribute('d', sector(s.a0, s.a1, Math.min(r, 150)));
    var tv = clamp((t - .25) / .75);
    s.txt.textContent = s.unl ? '\u221E' : Math.round(s.pct * easeOutCubic(tv)) + '%';
    s.txt.setAttribute('opacity', clamp((t - .3) / .35));
    var e = easeOutCubic(clamp((t - .45) / .55));
    s.lab.setAttribute('opacity', e);
  }
  function sync() {
    var on = tiles.querySelector('.sb-ut-tile.is-on'); root.classList.toggle('has-sel', !!on);
    segs.forEach(function (s) {
      var sel = s.b === on;
      s.g.classList.toggle('is-on', sel); s.lab.classList.toggle('is-on', sel);
      s.g.style.transform = sel ? 'translate(' + s.hx + 'px,' + s.hy + 'px)' : '';
      if (sel) { pctEl.textContent = s.unl ? 'No limit' : s.pct + '%'; pctEl.style.fontSize = s.unl ? '34px' : ''; nameEl.textContent = short(s.b.dataset.name); pctEl.setAttribute('fill', s.b.dataset.color); }
    });
  }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches, raf = null;
  function play() {
    cancelAnimationFrame(raf);
    if (reduce) { segs.forEach(function (s) { draw(s, 1); }); return; }
    var start = performance.now(), DUR = 1100, STAGGER = 140;
    (function frame(now) {
      var done = true;
      segs.forEach(function (s, i) { var t = clamp((now - start - 150 - i * STAGGER) / DUR); draw(s, t); if (t < 1) done = false; });
      if (!done) raf = requestAnimationFrame(frame);
    })(start);
  }
  // fit the frame to the tallest slice so there is no empty space above the chart
  var maxR = Math.max.apply(null, segs.map(function (s) { return s.R; })) * 1.06, padX = 125, padTop = 70;
  root.querySelector('svg').setAttribute('viewBox', (CX - maxR - padX) + ' ' + (CY - maxR - padTop) + ' ' + (2 * (maxR + padX)) + ' ' + (maxR + padTop + 75));
  segs.forEach(function (s) { draw(s, 0); }); sync();
  tiles.addEventListener('click', function () { setTimeout(sync, 0); });
  knob.addEventListener('click', play);
  knob.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); play(); } });
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { if (es.some(function (e) { return e.isIntersecting; })) { play(); io.disconnect(); } }, { threshold: .3 });
    io.observe(root);
  } else play();
})();
</script>
<?php include '../includes/client_footer.php'; ?>
