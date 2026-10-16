<?php
/**
 * Ads & Attribution — Ad Leads.
 * Which Click-to-WhatsApp ad brought which customer, and how many became sales, per ad.
 *   ads_leads.php?range=7d                 → table grouped by ad
 *   ads_leads.php?ad=<source_id>&range=…   → the contacts that came from one ad
 *   …&export=csv                           → CSV of the current view
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/ads/schema.php';
require_once __DIR__ . '/../includes/ads/helpers.php';

$pageTitle = 'Ad Leads';
$currentPage = 'ads_leads';
$companyId = (int)($_SESSION['company_id'] ?? 0);
$enabled = has_feature('ads_attribution');

$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ico = static fn(string $p, int $s = 18) => '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$I = [
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'check' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'star'  => '<path d="M20 6 9 17l-5-5"/>',
    'pct'   => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'video' => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    'down'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'chat'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'back'  => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'search'=> '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'copy'  => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'ad'    => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'info'  => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
];

$range = ads_range($_GET);
$adFilter = isset($_GET['ad']) ? substr(trim((string)$_GET['ad']), 0, 80) : null;
$rows = [];       // one row per (contact, ad) touch in range
$catalog = [];

if ($enabled) {
    ads_ensure_schema($conn);
    $st = $conn->prepare("SELECT r.id AS ref_id, r.contact_id, r.source_id, r.source_type, r.headline, r.media_type, r.local_media_path, r.clicked_at,
            c.name, c.phone, c.lead_stage, c.won_value
        FROM ad_referrals r JOIN contacts c ON c.id = r.contact_id AND c.company_id = r.company_id
        WHERE r.company_id = ? AND r.clicked_at BETWEEN ? AND ?
        ORDER BY r.clicked_at DESC LIMIT 20000");
    if ($st) {
        $st->bind_param('iss', $companyId, $range['from'], $range['to']);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $st = $conn->prepare("SELECT source_id, ad_name, campaign_name, headline, local_thumbnail_path FROM ad_catalog WHERE company_id = ?");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $c) $catalog[$c['source_id']] = $c;
    }
}

// An ad is identified by its Meta ID; posts / unknown referrals without one are grouped by headline.
$adKey = static fn(array $r) => (string)$r['source_id'] !== '' ? (string)$r['source_id'] : 'h:' . mb_substr((string)$r['headline'], 0, 60);

// Totals: distinct contacts. Per ad: a contact who tapped two ads counts for both (every touch is credited).
$tot = ['leads' => [], 'qualified' => [], 'won' => [], 'value' => 0.0];
$ads = [];
foreach ($rows as $r) {
    $cid = (int)$r['contact_id'];
    $stage = (string)$r['lead_stage'];
    $tot['leads'][$cid] = true;
    if ($stage === 'qualified' || $stage === 'won') $tot['qualified'][$cid] = true;
    if ($stage === 'won' && !isset($tot['won'][$cid])) { $tot['won'][$cid] = true; $tot['value'] += (float)$r['won_value']; }

    $k = $adKey($r);
    if (!isset($ads[$k])) {
        $cat = $catalog[(string)$r['source_id']] ?? [];
        $ads[$k] = ['key' => $k, 'source_id' => (string)$r['source_id'], 'type' => (string)$r['source_type'], 'media' => (string)$r['media_type'],
            'headline' => (string)($r['headline'] ?: ($cat['headline'] ?? '')), 'ad_name' => (string)($cat['ad_name'] ?? ''),
            'campaign' => (string)($cat['campaign_name'] ?? ''), 'thumb_ref' => 0, 'contacts' => [], 'qualified' => 0, 'won' => 0, 'value' => 0.0, 'last' => (string)$r['clicked_at']];
    }
    if (!$ads[$k]['thumb_ref'] && $r['local_media_path']) $ads[$k]['thumb_ref'] = (int)$r['ref_id'];
    if (isset($ads[$k]['contacts'][$cid])) continue;   // same contact tapped the same ad twice: count once
    $ads[$k]['contacts'][$cid] = $r;
    if ($stage === 'qualified' || $stage === 'won') $ads[$k]['qualified']++;
    if ($stage === 'won') { $ads[$k]['won']++; $ads[$k]['value'] += (float)$r['won_value']; }
}
foreach ($ads as &$a) { $a['leads'] = count($a['contacts']); $a['conv'] = $a['leads'] ? round($a['won'] / $a['leads'] * 100, 1) : 0; }
unset($a);
uasort($ads, static fn($x, $y) => [$y['leads'], $y['last']] <=> [$x['leads'], $x['last']]);
$nLeads = count($tot['leads']); $nQual = count($tot['qualified']); $nWon = count($tot['won']);
$conv = $nLeads ? round($nWon / $nLeads * 100, 1) : 0;

$drill = ($adFilter !== null && isset($ads[$adFilter])) ? $ads[$adFilter] : null;
$adTitle = static fn(array $a) => $a['ad_name'] !== '' ? $a['ad_name'] : ($a['headline'] !== '' ? $a['headline'] : ($a['type'] === 'post' ? 'Facebook / Instagram post' : 'Ad'));

// ── CSV export of the current view ──
if ($enabled && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $safe = static fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;   // block spreadsheet formula injection
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ad-leads-' . ($drill ? 'ad-' . preg_replace('/\W/', '', $drill['source_id']) . '-' : '') . $range['from_d'] . '-to-' . $range['to_d'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($drill) {
        fputcsv($out, ['Contact', 'Phone', 'Tapped ad at', 'Stage', 'Sale value (INR)']);
        foreach ($drill['contacts'] as $c) fputcsv($out, array_map($safe, [$c['name'], $c['phone'], $c['clicked_at'], ads_stage_label((string)$c['lead_stage'])[0], (string)$c['lead_stage'] === 'won' ? (float)$c['won_value'] : '']));
    } else {
        fputcsv($out, ['Ad ID', 'Type', 'Ad', 'Campaign', 'Leads', 'Qualified', 'Won', 'Won value (INR)', 'Conversion %', 'Last lead']);
        foreach ($ads as $a) fputcsv($out, array_map($safe, [$a['source_id'], $a['type'], $adTitle($a), $a['campaign'], $a['leads'], $a['qualified'], $a['won'], $a['value'], $a['conv'], $a['last']]));
    }
    exit();
}

include '../includes/client_header.php';
$qBase = ['range' => $range['key']] + ($range['key'] === 'custom' ? ['from' => $range['from_d'], 'to' => $range['to_d']] : []);
$url = static fn(array $extra = []) => 'ads_leads.php?' . http_build_query(array_merge($qBase, $extra));
$thumb = static function (array $a) use ($esc, $ico, $I): string {
    if ($a['thumb_ref']) return '<img class="ad-thumb" src="ad_media.php?r=' . (int)$a['thumb_ref'] . '" alt="" loading="lazy">';
    return '<span class="ad-thumb is-ph">' . $ico($a['media'] === 'video' ? $I['video'] : $I['image'], 20) . '</span>';
};
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">

<div class="db ads">
  <div class="db-head">
    <div>
      <h1><?php echo $drill ? $esc($adTitle($drill)) : 'Ad Leads'; ?></h1>
      <p><?php echo $drill ? 'Customers who messaged you after tapping this ad.' : 'Which Click-to-WhatsApp ad brought each customer, and how many of them bought.'; ?></p>
    </div>
    <div class="db-head__actions">
      <?php if ($drill): ?><a class="db-btn" href="<?php echo $esc($url()); ?>"><?php echo $ico($I['back'], 16); ?> All ads</a><?php endif; ?>
      <?php if ($enabled && $rows): ?><a class="db-btn" href="<?php echo $esc($url(($drill ? ['ad' => $drill['key']] : []) + ['export' => 'csv'])); ?>"><?php echo $ico($I['down'], 16); ?> Export CSV</a><?php endif; ?>
    </div>
  </div>

<?php if (!$enabled): ?>
  <section class="db-card ads-empty">
    <span class="ads-empty__ic"><?php echo $ico($I['ad'], 28); ?></span>
    <h2>Ads &amp; Attribution is an add-on</h2>
    <p>See which Facebook and Instagram ad brought every WhatsApp lead, how many became sales, and your cost per sale. Ask your account manager to switch on Ads &amp; Attribution for your plan.</p>
    <a class="db-btn db-btn--primary" href="subscription.php">View plans</a>
  </section>
<?php else: ?>

  <form class="ads-range" method="get" action="ads_leads.php">
    <?php if ($drill): ?><input type="hidden" name="ad" value="<?php echo $esc($drill['key']); ?>"><?php endif; ?>
    <?php foreach (['today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month'] as $k => $lbl): ?>
      <a class="ads-chip<?php echo $range['key'] === $k ? ' is-on' : ''; ?>" href="<?php echo $esc('ads_leads.php?' . http_build_query(['range' => $k] + ($drill ? ['ad' => $drill['key']] : []))); ?>"><?php echo $lbl; ?></a>
    <?php endforeach; ?>
    <span class="ads-custom<?php echo $range['key'] === 'custom' ? ' is-on' : ''; ?>">
      <input type="hidden" name="range" value="custom">
      <input type="date" name="from" value="<?php echo $esc($range['from_d']); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="From">
      <span>to</span>
      <input type="date" name="to" value="<?php echo $esc($range['to_d']); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="To">
      <button type="submit">Apply</button>
    </span>
    <span class="ads-range__lbl"><?php echo $esc($range['label']); ?> · times in IST</span>
  </form>

  <?php $k = $drill ? ['leads' => $drill['leads'], 'qual' => $drill['qualified'], 'won' => $drill['won'], 'value' => $drill['value'], 'conv' => $drill['conv']]
                    : ['leads' => $nLeads, 'qual' => $nQual, 'won' => $nWon, 'value' => $tot['value'], 'conv' => $conv]; ?>
  <div class="db-kpis ads-kpis">
    <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $ico($I['users'], 19); ?></div><div class="db-kpi__l">Ad leads</div><div class="db-kpi__v"><?php echo number_format($k['leads']); ?></div><div class="db-kpi__d">Customers who messaged from an ad</div></div>
    <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $ico($I['check'], 19); ?></div><div class="db-kpi__l">Qualified</div><div class="db-kpi__v"><?php echo number_format($k['qual']); ?></div><div class="db-kpi__d">Follow-up or Converted</div></div>
    <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $ico($I['star'], 19); ?></div><div class="db-kpi__l">Won</div><div class="db-kpi__v"><?php echo number_format($k['won']); ?></div><div class="db-kpi__d"><?php echo $esc(ads_inr($k['value'])); ?> in sales</div></div>
    <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $ico($I['pct'], 19); ?></div><div class="db-kpi__l">Conversion</div><div class="db-kpi__v"><?php echo $k['conv']; ?>%</div><div class="db-kpi__d">Won ÷ ad leads</div></div>
  </div>

  <?php if (!$rows): ?>
    <section class="db-card ads-empty">
      <span class="ads-empty__ic"><?php echo $ico($I['ad'], 28); ?></span>
      <h2>No ad leads <?php echo $range['key'] === '30d' ? 'yet' : 'in this period'; ?></h2>
      <p>Run a <b>Click-to-WhatsApp</b> ad on Facebook or Instagram (the ad button opens a WhatsApp chat with your business number).<br>
         When someone taps the ad and sends a message, WAAMINI records which ad it was.<br>
         The lead, the ad and later the sale appear here automatically.</p>
    </section>
  <?php elseif ($drill): ?>
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Customers from this ad</h2><p><?php echo number_format($drill['leads']); ?> in <?php echo $esc(strtolower($range['label'])); ?></p></div>
        <div class="ads-adid"><?php echo $thumb($drill); ?><?php if ($drill['source_id'] !== ''): ?><span>Ad ID <b><?php echo $esc($drill['source_id']); ?></b></span><button type="button" class="ads-copy" data-copy="<?php echo $esc($drill['source_id']); ?>" title="Copy ad ID"><?php echo $ico($I['copy'], 15); ?></button><?php endif; ?></div>
      </div>
      <div class="db-tablewrap">
        <table class="db-table ads-table">
          <thead><tr><th>Customer</th><th>Tapped the ad</th><th>Stage</th><th>Sale value</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($drill['contacts'] as $c): [$sl, $sc] = ads_stage_label((string)$c['lead_stage']); $nm = trim((string)$c['name']); ?>
            <tr>
              <td><div class="db-who"><span class="db-av"><?php echo $esc(strtoupper(mb_substr($nm !== '' ? $nm : '#', 0, 1))); ?></span><div><b><?php echo $esc($nm !== '' ? $nm : $c['phone']); ?></b><small><?php echo $esc($c['phone']); ?></small></div></div></td>
              <td><?php echo $esc(date('d M Y, h:i A', strtotime((string)$c['clicked_at']))); ?></td>
              <td><span class="db-pill <?php echo $sc; ?>"><?php echo $sl; ?></span></td>
              <td><?php echo (string)$c['lead_stage'] === 'won' && $c['won_value'] !== null ? $esc(ads_inr($c['won_value'])) : '<span class="db-mut">—</span>'; ?></td>
              <td style="text-align:right"><a class="ads-open" href="whatsapp.php?c=<?php echo (int)$c['contact_id']; ?>&amp;crm=open"><?php echo $ico($I['chat'], 15); ?> Open chat</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php else: ?>
    <section class="db-card db-tablecard">
      <div class="db-card__head">
        <div><h2>Leads by ad</h2><p><?php echo count($ads); ?> ad<?php echo count($ads) === 1 ? '' : 's'; ?> · click a row to see its customers <span class="ads-tip" title="A customer who tapped two different ads is counted under both ads, so the rows can add up to more than the total. Customers who typed your number themselves (instead of tapping an ad) are not ad leads."><?php echo $ico($I['info'], 14); ?></span></p></div>
        <label class="ads-search"><?php echo $ico($I['search'], 16); ?><input type="search" id="adsSearch" placeholder="Search ad, headline or ID" aria-label="Search ads"></label>
      </div>
      <div class="db-tablewrap">
        <table class="db-table ads-table" id="adsTable">
          <thead><tr>
            <th data-sort="t">Ad</th><th data-sort="n" class="num">Leads</th><th data-sort="n" class="num">Qualified</th><th data-sort="n" class="num">Won</th>
            <th data-sort="n" class="num">Won value</th><th data-sort="n" class="num">Conversion</th><th data-sort="d">Last lead</th>
          </tr></thead>
          <tbody>
          <?php foreach ($ads as $a): $title = $adTitle($a); ?>
            <tr class="ads-row" data-href="<?php echo $esc($url(['ad' => $a['key']])); ?>" data-q="<?php echo $esc(mb_strtolower($title . ' ' . $a['headline'] . ' ' . $a['source_id'] . ' ' . $a['campaign'])); ?>">
              <td data-v="<?php echo $esc(mb_strtolower($title)); ?>">
                <div class="ads-ad"><?php echo $thumb($a); ?>
                  <div><b><?php echo $esc($title); ?></b>
                    <small><span class="ads-badge"><?php echo $esc($a['type'] === 'post' ? 'Post' : ($a['type'] === 'ad' ? 'Ad' : ucfirst($a['type']))); ?></span><?php echo $a['source_id'] !== '' ? ' ' . $esc($a['source_id']) : ''; ?><?php echo $a['campaign'] !== '' ? ' · ' . $esc($a['campaign']) : ''; ?></small></div></div>
              </td>
              <td class="num" data-v="<?php echo $a['leads']; ?>"><b><?php echo number_format($a['leads']); ?></b></td>
              <td class="num" data-v="<?php echo $a['qualified']; ?>"><?php echo number_format($a['qualified']); ?></td>
              <td class="num" data-v="<?php echo $a['won']; ?>"><?php echo number_format($a['won']); ?></td>
              <td class="num" data-v="<?php echo $a['value']; ?>"><?php echo $esc(ads_inr($a['value'])); ?></td>
              <td class="num" data-v="<?php echo $a['conv']; ?>"><?php echo $a['conv']; ?>%</td>
              <td data-v="<?php echo $esc($a['last']); ?>"><?php echo $esc(date('d M, h:i A', strtotime($a['last']))); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="db-empty" id="adsNone" style="display:none">No ads match your search.</div>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>
</div>

<style>
/* Ads pages: flat colours, no gradients (dash-pro.css defaults overridden here) */
.ads .db-btn--primary{background:#128C7E;box-shadow:none}
.ads .db-btn--primary:hover{background:#0B6B5E;transform:none}
/* KPI strip: four separate cards (dash-pro's .db-kpis is a 2x2 block meant to sit inside a card) */
.ads .ads-kpis{grid-template-columns:repeat(4,minmax(0,1fr));grid-auto-rows:auto;gap:16px;margin-bottom:18px}
.ads .ads-kpis .db-kpi{position:relative;display:grid;grid-template-columns:42px minmax(0,1fr);grid-template-areas:"ic l" "ic v" "d d";column-gap:14px;align-items:start;padding:18px 20px;background:#fff;border:1px solid var(--line)!important;border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:box-shadow .2s}
.ads .ads-kpis .db-kpi:hover{box-shadow:0 16px 34px -22px rgba(18,140,126,.55)}
.ads .ads-kpis .db-kpi__ic{grid-area:ic;margin:0}
.ads .ads-kpis .db-kpi__l{grid-area:l;font-size:13px;color:var(--mu)}
.ads .ads-kpis .db-kpi__v{grid-area:v;margin:2px 0 0;font-size:28px;line-height:1.15}
.ads .ads-kpis .db-kpi__d{grid-area:d;margin-top:12px;padding:8px 10px;border-radius:9px;background:var(--soft);font-size:12.5px;color:var(--tx)}
@media (max-width:1180px){.ads .ads-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:520px){.ads .ads-kpis{grid-template-columns:1fr}}
/* Filter bar: one white panel holding the range chips, dates and label */
.ads-range{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 18px;padding:10px 12px;background:#fff;border:1px solid var(--line,#E3EEE9);border-radius:14px;box-shadow:0 12px 30px -26px rgba(18,140,126,.5)}
.ads-chip{padding:8px 14px;border-radius:999px;background:var(--soft,#F3FBF7);color:var(--tx,#34453f);font:700 13.5px Inter,system-ui,sans-serif;text-decoration:none}
.ads-chip:hover{background:#E6FCF2;color:#0B6B5E}
.ads-chip.is-on{background:#128C7E;color:#fff}
.ads-custom{display:inline-flex;align-items:center;gap:6px;padding:4px 6px 4px 10px;border-radius:999px;background:var(--soft,#F3FBF7);font-size:13px;color:var(--mu,#586a63)}
.ads-custom.is-on{box-shadow:inset 0 0 0 2px #128C7E}
.ads-custom input{border:0;background:#fff;border-radius:8px;height:30px;padding:0 8px;font:500 13px Inter,system-ui,sans-serif}
.ads-custom button{border:0;border-radius:999px;background:#128C7E;color:#fff;height:30px;padding:0 12px;font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.ads-range__lbl{margin-left:auto;font-size:12.5px;color:var(--mu,#586a63)}
.ads-table th.num,.ads-table td.num{text-align:right}
.ads-table th[data-sort]{cursor:pointer;user-select:none}
.ads-table th[data-sort]::after{content:'';display:inline-block;margin-left:5px;opacity:.5}
.ads-table th.is-asc::after{content:'▲';font-size:9px}.ads-table th.is-desc::after{content:'▼';font-size:9px}
.ads-table td{border-bottom:0}
.ads-table tbody tr:nth-child(even) td{background:#F8FCFA}
.ads-row{cursor:pointer}
.ads-row:hover td{background:#E6FCF2 !important}
.ads-ad{display:flex;align-items:center;gap:12px;min-width:240px}
.ads-ad b{display:block;color:var(--ink,#0F2622);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ads-ad small{display:block;margin-top:2px;font-size:12px;color:var(--mu,#586a63)}
.ad-thumb{width:48px;height:48px;border-radius:9px;object-fit:cover;flex:none;background:#EEF2F1}
.ad-thumb.is-ph{display:grid;place-items:center;color:#7d8f89}
.ads-badge{display:inline-block;padding:1px 7px;border-radius:6px;background:#EEF2F1;color:#34453f;font-weight:700;font-size:11px}
.ads-search{display:flex;align-items:center;gap:9px;width:270px;max-width:100%;height:42px;padding:0 14px;border-radius:11px;background:var(--soft,#F3FBF7);color:var(--mu,#586a63)}
.ads-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14px Inter,system-ui,sans-serif}
.ads-tip{display:inline-flex;vertical-align:-2px;color:var(--mu,#586a63);cursor:help}
.ads-adid{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--mu,#586a63)}
.ads-adid b{color:var(--ink,#0F2622)}
.ads-copy{border:0;background:var(--soft,#F3FBF7);border-radius:8px;width:32px;height:32px;display:grid;place-items:center;cursor:pointer;color:#34453f}
.ads-open{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:9px;background:#E6FCF2;color:#0B6B5E;font-weight:700;font-size:13px;text-decoration:none}
.ads-empty{text-align:center;padding:48px 28px}
.ads-empty__ic{width:64px;height:64px;border-radius:50%;background:#E6FCF2;color:#128C7E;display:grid;place-items:center;margin:0 auto 14px}
.ads-empty h2{margin:0 0 8px;font-size:19px}
.ads-empty p{margin:0 auto 18px;max-width:560px;color:var(--tx,#34453f);line-height:1.65;font-size:14px}
@media (max-width:720px){.ads-range__lbl{margin-left:0;width:100%}}
</style>

<script nonce="<?php echo $esc($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  // Save ad thumbnails before Meta's links expire (works without the server cron).
  fetch('../api/ads_media_tick.php', { credentials: 'same-origin' }).catch(function () {});

  document.querySelectorAll('.ads-row').forEach(function (r) { r.addEventListener('click', function () { location.href = r.dataset.href; }); });
  document.querySelectorAll('.ads-copy').forEach(function (b) {
    b.addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy); b.title = 'Copied'; });
  });

  var table = document.getElementById('adsTable');
  if (!table) return;
  var body = table.tBodies[0], search = document.getElementById('adsSearch'), none = document.getElementById('adsNone');
  search.addEventListener('input', function () {
    var q = search.value.toLowerCase().trim(), n = 0;
    Array.prototype.forEach.call(body.rows, function (r) { var ok = !q || r.dataset.q.indexOf(q) !== -1; r.style.display = ok ? '' : 'none'; if (ok) n++; });
    none.style.display = n ? 'none' : 'block';
  });
  Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, col) {
    th.addEventListener('click', function () {
      var asc = !th.classList.contains('is-asc'), kind = th.dataset.sort;
      Array.prototype.forEach.call(th.parentNode.cells, function (c) { c.classList.remove('is-asc', 'is-desc'); });
      th.classList.add(asc ? 'is-asc' : 'is-desc');
      var rows = Array.prototype.slice.call(body.rows);
      rows.sort(function (a, b) {
        var x = a.cells[col].dataset.v, y = b.cells[col].dataset.v;
        if (kind === 'n') { x = parseFloat(x) || 0; y = parseFloat(y) || 0; }
        return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1);
      });
      rows.forEach(function (r) { body.appendChild(r); });
    });
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
