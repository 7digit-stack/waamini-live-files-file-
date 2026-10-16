<?php
/**
 * Super-admin: Ads & Attribution overview across all companies.
 * Who has the add-on (plan or per-company override), how many ad leads / sales each company gets,
 * and whether ad thumbnails are downloading. Phases 2–4 add token health, CAPI failures and sync lag here.
 */
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/ads/schema.php';
require_once __DIR__ . '/../includes/ads/helpers.php';

$pageTitle = 'Ads & Attribution';
$currentPage = 'ads_overview';
ads_ensure_schema($conn);

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;
// Per-company add-ons live in companies.features_override; older installs may not have the column yet.
try {
    $fc = $conn->query("SHOW COLUMNS FROM companies LIKE 'features_override'");
    if ($fc && $fc->num_rows === 0) $conn->query("ALTER TABLE companies ADD COLUMN features_override TEXT NULL");
} catch (\Throwable $e) { error_log('[ads_overview] features_override: ' . $e->getMessage()); }
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

// Companies + whether the add-on is on (plan flag, then per-company override wins)
$companies = [];
$r = $conn->query("SELECT c.*,
        (SELECT sp.feat_ads_attribution FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
          WHERE s.company_id = c.id AND s.status IN ('active','trial') ORDER BY s.id DESC LIMIT 1) AS plan_flag,
        (SELECT sp.name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
          WHERE s.company_id = c.id AND s.status IN ('active','trial') ORDER BY s.id DESC LIMIT 1) AS plan_name
    FROM companies c ORDER BY c.name ASC");
if (!$r) error_log('[ads_overview] companies: ' . $conn->error);
while ($r && ($x = $r->fetch_assoc())) {
    $x['status'] = (string)($x['status'] ?? 'active');
    $ov = json_decode((string)($x['features_override'] ?? ''), true);
    $hasOv = is_array($ov) && array_key_exists('feat_ads_attribution', $ov);
    $x['on'] = $hasOv ? (bool)(int)$ov['feat_ads_attribution'] : (bool)(int)$x['plan_flag'];
    $x['via'] = $hasOv ? ((int)$ov['feat_ads_attribution'] ? 'Override: on' : 'Override: off') : ((int)$x['plan_flag'] ? 'Plan' : 'Off');
    $x += ['refs' => 0, 'leads' => 0, 'last' => null, 'mfail' => 0, 'mpend' => 0, 'won' => 0, 'value' => 0.0, 'ads' => 0];
    $companies[(int)$x['id']] = $x;
}

$st = $conn->prepare("SELECT company_id, COUNT(*) refs, COUNT(DISTINCT contact_id) leads, MAX(clicked_at) last,
        SUM(media_status = 'failed') mfail, SUM(media_status IN ('pending','processing')) mpend
    FROM ad_referrals WHERE clicked_at >= ? GROUP BY company_id");
$st->bind_param('s', $since);
$st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) {
    if (isset($companies[(int)$x['company_id']])) $companies[(int)$x['company_id']] = array_merge($companies[(int)$x['company_id']], $x);
}
$st = $conn->prepare("SELECT company_id, COUNT(*) won, COALESCE(SUM(won_value),0) value FROM contacts
    WHERE lead_source IN ('ad','post') AND lead_stage = 'won' AND (lead_stage_at IS NULL OR lead_stage_at >= ?) GROUP BY company_id");
$st->bind_param('s', $since);
$st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) {
    if (isset($companies[(int)$x['company_id']])) { $companies[(int)$x['company_id']]['won'] = (int)$x['won']; $companies[(int)$x['company_id']]['value'] = (float)$x['value']; }
}
$r = $conn->query("SELECT company_id, COUNT(*) n FROM ad_catalog GROUP BY company_id");
while ($r && ($x = $r->fetch_assoc())) if (isset($companies[(int)$x['company_id']])) $companies[(int)$x['company_id']]['ads'] = (int)$x['n'];

// Conversions API health (Phase 2)
require_once __DIR__ . '/../services/CapiService.php';
foreach (CapiService::stats($conn, 0, $since) as $cid => $cs) {
    if (isset($companies[$cid])) $companies[$cid]['capi'] = $cs;
}
$capiOn = [];
$r = $conn->query("SELECT company_id, enabled, dataset_id FROM capi_settings");
while ($r && ($x = $r->fetch_assoc())) $capiOn[(int)$x['company_id']] = (int)$x['enabled'] === 1 && $x['dataset_id'] !== '';

// Show companies that have the add-on or any ad data; the rest are summarised in the KPI.
$rows = array_filter($companies, static fn($c) => $c['on'] || $c['refs'] > 0 || $c['ads'] > 0 || !empty($c['capi']));
$capiSent = array_sum(array_map(static fn($c) => (int)($c['capi']['sent'] ?? 0), $companies));
$capiFail = array_sum(array_map(static fn($c) => (int)($c['capi']['failed'] ?? 0), $companies));
uasort($rows, static fn($a, $b) => [$b['refs'], $b['on']] <=> [$a['refs'], $a['on']]);
$sum = static fn(string $k) => array_sum(array_column($companies, $k));
$nOn = count(array_filter($companies, static fn($c) => $c['on']));

include '../includes/admin_header.php';
?>
<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="vertical-align:middle;"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> Ads &amp; Attribution</h2>
    <div style="display:flex;gap:8px;">
        <?php foreach ([7, 30, 90] as $d): ?><a href="?days=<?php echo $d; ?>" class="btn btn-sm <?php echo $d === $days ? 'btn-primary' : 'btn-edit'; ?>"><?php echo $d; ?> days</a><?php endforeach; ?>
    </div>
</div>

<div class="ao-kpis">
    <div class="ao-kpi"><span>Companies with the add-on</span><b><?php echo $nOn; ?></b><small>of <?php echo count($companies); ?> companies</small></div>
    <div class="ao-kpi"><span>Ad leads</span><b><?php echo number_format($sum('leads')); ?></b><small><?php echo number_format($sum('refs')); ?> ad taps, last <?php echo $days; ?> days</small></div>
    <div class="ao-kpi"><span>Won from ads</span><b><?php echo number_format($sum('won')); ?></b><small><?php echo $esc(ads_inr($sum('value'))); ?> in sales</small></div>
    <div class="ao-kpi"><span>Conversion events to Meta</span><b><?php echo number_format($capiSent); ?> sent</b><small><?php echo number_format($capiFail); ?> failed<?php echo ($capiSent + $capiFail) > 0 ? ' (' . round($capiFail / ($capiSent + $capiFail) * 100, 1) . '% failure rate)' : ''; ?></small></div>
    <div class="ao-kpi"><span>Thumbnails</span><b><?php echo number_format($sum('mpend')); ?> pending</b><small><?php echo number_format($sum('mfail')); ?> failed (links expired)</small></div>
</div>

<div class="form-card" style="max-width:none;">
    <h3 style="margin:0 0 4px;font-size:16px;">Companies</h3>
    <p style="margin:0 0 14px;font-size:12.5px;color:var(--text-muted);">Turn the add-on on in Plans (for a whole plan) or per company under Companies → Edit → Add-ons. Ad taps are recorded for every company, so a company that upgrades sees its earlier ad leads straight away.</p>
    <?php if (!$rows): ?>
        <p style="padding:24px;text-align:center;color:var(--text-muted);">No company has the add-on or any ad leads yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="data-table ao-table">
        <thead><tr><th>Company</th><th>Access</th><th class="n">Ad leads</th><th class="n">Ad taps</th><th class="n">Won</th><th class="n">Sales</th><th class="n">Ads seen</th><th>Last ad lead</th><th>Conversions API</th><th>Thumbnails</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr>
                <td><b><?php echo $esc($c['name']); ?></b><small><?php echo $esc($c['plan_name'] ?: 'No active plan'); ?><?php echo $c['status'] !== 'active' ? ' · ' . $esc(ucfirst((string)$c['status'])) : ''; ?></small></td>
                <td><span class="ao-pill <?php echo $c['on'] ? 'on' : 'off'; ?>"><?php echo $esc($c['via']); ?></span></td>
                <td class="n"><?php echo number_format((int)$c['leads']); ?></td>
                <td class="n"><?php echo number_format((int)$c['refs']); ?></td>
                <td class="n"><?php echo number_format((int)$c['won']); ?></td>
                <td class="n"><?php echo $esc(ads_inr($c['value'])); ?></td>
                <td class="n"><?php echo number_format((int)$c['ads']); ?></td>
                <td><?php echo $c['last'] ? $esc(date('d M Y, h:i A', strtotime((string)$c['last']))) : '<span style="color:var(--text-muted)">—</span>'; ?></td>
                <td><?php $cs = $c['capi'] ?? null;
                    if (!($capiOn[(int)$c['id']] ?? false) && !$cs): ?><span style="color:var(--text-muted)">Not set up</span>
                    <?php else: ?><span class="ao-pill <?php echo ($cs['failed'] ?? 0) > 0 ? 'bad' : 'on'; ?>"><?php echo (int)($cs['sent'] ?? 0); ?> sent<?php echo ($cs['failed'] ?? 0) > 0 ? ' · ' . (int)$cs['failed'] . ' failed' : ''; ?></span><?php if (!($capiOn[(int)$c['id']] ?? false)): ?><small>Switched off</small><?php endif; ?><?php endif; ?></td>
                <td><?php if ((int)$c['mfail'] > 0): ?><span class="ao-pill bad"><?php echo (int)$c['mfail']; ?> failed</span><?php elseif ((int)$c['mpend'] > 0): ?><span class="ao-pill wait"><?php echo (int)$c['mpend']; ?> pending</span><?php else: ?><span class="ao-pill on">OK</span><?php endif; ?></td>
                <td><a class="btn btn-sm btn-edit" href="company_edit.php?id=<?php echo (int)$c['id']; ?>">Add-ons</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p style="margin:14px 0 0;font-size:12px;color:var(--text-muted);">Many pending thumbnails mean the every-minute worker (workers/automation_queue_worker.php) is not running. They also download when the company opens Ad Leads or WhatsApp Chat.</p>
</div>

<style>
.ao-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:18px}
@media (max-width:900px){.ao-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.ao-kpi{background:var(--bg-card);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow)}
.ao-kpi span{display:block;font-size:12px;color:var(--text-muted);font-weight:600}
.ao-kpi b{display:block;font-size:24px;font-weight:800;color:var(--text-primary);margin:4px 0 2px}
.ao-kpi small{font-size:12px;color:var(--text-muted)}
.ao-table td small{display:block;font-size:11.5px;color:var(--text-muted);margin-top:2px}
.ao-table th.n,.ao-table td.n{text-align:right}
.ao-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap}
.ao-pill.on{background:rgba(18,140,126,.12);color:#0B6B5E}
.ao-pill.off{background:rgba(100,116,139,.12);color:#64748b}
.ao-pill.bad{background:rgba(239,68,68,.12);color:#b91c1c}
.ao-pill.wait{background:rgba(245,158,11,.14);color:#a15c00}
</style>
<?php include '../includes/admin_footer.php'; ?>
