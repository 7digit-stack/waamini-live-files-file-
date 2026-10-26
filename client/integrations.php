<?php
require_once '../includes/client_auth_check.php';
if (!has_feature('menu_integrations')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client menu pages

if (
    !has_feature('indiamart')
    && !has_feature('fb_lead_ads')
    && !has_feature('google_sheets')
    && !has_feature('api_webhook')
    && !has_feature('api_webhook_access')
    && !has_feature('shopify')
) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Integrations';
$currentPage = 'integrations';

/* ── Connection status per integration (company-scoped; any failure = "not connected") ── */
$co = [];
try {
    $st = $conn->prepare("SELECT im_crm_key, im_last_sync_at, im_last_sync_status, fb_page_id, fb_page_access_token, google_sheet_webhook, last_gsheet_sync FROM companies WHERE id=? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $co = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
    }
} catch (Throwable $e) { /* columns may be missing on legacy installs */ }

$hasRow = function (string $sql) use ($conn, $companyId): bool {
    try {
        $st = $conn->prepare($sql);
        if (!$st) return false;
        $st->bind_param('i', $companyId);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_assoc();
        $st->close();
        return $ok;
    } catch (Throwable $e) { return false; }
};

$integrations = [
    [
        'key' => 'indiamart', 'name' => 'IndiaMART Sync', 'icon' => 'sales', 'tone' => 'green',
        'desc' => 'Capture IndiaMART buyer leads through the API and send an instant WhatsApp reply.',
        'allowed' => has_feature('indiamart'), 'href' => 'settings_indiamart.php',
        'connected' => !empty($co['im_crm_key']),
        'meta' => !empty($co['im_last_sync_at']) ? 'Last sync ' . date('d M, g:i A', strtotime((string)$co['im_last_sync_at'])) : '',
        'ts' => !empty($co['im_last_sync_at']) ? strtotime((string)$co['im_last_sync_at']) : 0,
        'cta_on' => 'Manage', 'cta_off' => 'Connect CRM key',
    ],
    [
        'key' => 'facebook', 'name' => 'Facebook Lead Ads', 'icon' => 'campaigns', 'tone' => 'blue',
        'desc' => 'Message people the moment they submit a Lead Form on your Facebook Page.',
        'allowed' => has_feature('fb_lead_ads'), 'href' => 'settings_fb_leads.php',
        'connected' => !empty($co['fb_page_id']) && !empty($co['fb_page_access_token']),
        'meta' => '', 'ts' => 0,
        'cta_on' => 'Manage', 'cta_off' => 'Set up webhook',
    ],
    [
        'key' => 'gsheets', 'name' => 'Google Sheets', 'icon' => 'gsheets', 'tone' => 'amber',
        'desc' => 'Push contacts and chat activity to a Google Sheet automatically.',
        'allowed' => has_feature('google_sheets'), 'href' => 'settings_gsheets.php',
        'connected' => !empty($co['google_sheet_webhook']),
        'meta' => !empty($co['last_gsheet_sync']) ? 'Last sync ' . date('d M, g:i A', strtotime((string)$co['last_gsheet_sync'])) : '',
        'ts' => !empty($co['last_gsheet_sync']) ? strtotime((string)$co['last_gsheet_sync']) : 0,
        'cta_on' => 'Manage', 'cta_off' => 'Connect sheet',
    ],
    [
        'key' => 'shopify', 'name' => 'Shopify', 'icon' => 'catalog', 'tone' => 'green',
        'desc' => 'WhatsApp order confirmations, COD checks, shipping updates and abandoned-checkout reminders from your Shopify store.',
        'allowed' => has_feature('shopify'), 'href' => 'shopify_connect.php',
        'connected' => $hasRow("SELECT id FROM shopify_stores WHERE company_id=? AND status='active' LIMIT 1"),
        'meta' => '', 'ts' => 0,
        'cta_on' => 'Manage', 'cta_off' => 'Connect store',
    ],
    [
        'key' => 'zapier', 'name' => 'Zapier Webhooks', 'icon' => 'zapier', 'tone' => 'purple',
        'desc' => 'Connect WAAMINI to thousands of apps with no-code Zaps.',
        'allowed' => has_feature('api_webhook_access'), 'href' => 'premium_zapier.php',
        'connected' => $hasRow("SELECT id FROM zapier_webhooks WHERE company_id=? LIMIT 1"),
        'meta' => '', 'ts' => 0,
        'cta_on' => 'Manage', 'cta_off' => 'Open Zapier',
    ],
    [
        'key' => 'api', 'name' => 'API & Webhook', 'icon' => 'api', 'tone' => 'pink',
        'desc' => 'Import or update leads from any CRM through the authenticated lead-sync API. WhatsApp sends use a separate endpoint.',
        'allowed' => has_feature('api_webhook'), 'href' => 'settings_api.php',
        'connected' => $hasRow("SELECT id FROM api_keys WHERE company_id=? AND is_active=1 LIMIT 1"),
        'meta' => '', 'ts' => 0,
        'cta_on' => 'Manage', 'cta_off' => 'Create API key',
    ],
];

$available = 0; $connected = 0; $lastSync = 0;
foreach ($integrations as $i) {
    if ($i['allowed']) { $available++; if ($i['connected']) $connected++; }
    if ($i['allowed'] && $i['ts'] > $lastSync) $lastSync = $i['ts'];
}
$needsSetup = $available - $connected;

include '../includes/client_header.php';
?>

<style>
/* ── Integrations — dashboard design system, no divider lines ── */
.ig{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.ig *{box-sizing:border-box}
.ig-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.ig-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.ig-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.ig-hero-ico svg{width:22px;height:22px}
.ig-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.ig-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:540px;line-height:1.5}
.ig-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.ig-btn:hover{border-color:var(--g);color:var(--g)}
.ig-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.ig-btn--primary:hover{color:#fff;transform:translateY(-1px)}

.ig-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
.ig-stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.ig-stat:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.ig-stat-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.ig-stat-ico svg{width:20px;height:20px}
.ig-stat.is-blue .ig-stat-ico{background:#E4EFFD;color:#1d6fd6}
.ig-stat.is-amber .ig-stat-ico{background:#FEF3DC;color:#b7791f}
.ig-stat.is-purple .ig-stat-ico{background:#EFE9FD;color:#6d44d8}
.ig-stat-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.ig-stat-v.sm{font-size:17px;letter-spacing:0}
.ig-stat-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

.ig-sec-title{font-size:17.5px;font-weight:800;margin:0 0 4px}
.ig-sec-sub{font-size:13.5px;color:var(--mu);margin:0 0 16px}
.ig-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-bottom:24px}
.ig-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;display:flex;flex-direction:column;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.ig-card:hover{transform:translateY(-2px);box-shadow:0 18px 36px -22px rgba(18,140,126,.6)}
.ig-card.locked{background:#FAFCFB}
.ig-card.locked .ig-ico,.ig-card.locked h3,.ig-card.locked p{opacity:.65}
.ig-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
.ig-ico{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;background:#E3F7EE;color:var(--g)}
.ig-ico svg{width:22px;height:22px}
.ig-ico.t-blue{background:#E4EFFD;color:#1d6fd6}
.ig-ico.t-amber{background:#FEF3DC;color:#b7791f}
.ig-ico.t-purple{background:#EFE9FD;color:#6d44d8}
.ig-ico.t-pink{background:#FDE7F1;color:#d1356f}
.ig-pill{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.ig-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.ig-pill.on{background:#E3F7EE;color:#0B6B5E}
.ig-pill.off{background:#EEF3F1;color:#5d6f69}
.ig-pill.lock{background:#FEF3DC;color:#8a5a00}
.ig-pill.lock::before{display:none}
.ig-pill svg{width:11px;height:11px}
.ig-card h3{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px}
.ig-card p{margin:4px 0 0;font-size:14px;color:var(--mu);line-height:1.55}
.ig-meta{font-size:12.5px;color:var(--tx);background:var(--soft);border-radius:9px;padding:7px 11px;font-weight:600}
.ig-card .ig-btn{margin-top:auto;width:100%}

.ig-tip{display:flex;align-items:center;gap:14px;padding:18px 22px;border-radius:16px;background:var(--soft)}
.ig-tip-ico{width:42px;height:42px;border-radius:10px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.ig-tip-ico svg{width:20px;height:20px}
.ig-tip b{display:block;font-size:14.5px;margin-bottom:2px}
.ig-tip span{font-size:13.5px;color:var(--mu);line-height:1.5}

@media (max-width:1100px){.ig-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ig-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:700px){.ig-grid{grid-template-columns:1fr}}
@media (max-width:560px){.ig-hero{padding:18px}.ig-hero h1{font-size:21px}.ig-stats{grid-template-columns:1fr}.ig-hero .ig-btn{width:100%}}
</style>

<div class="ig">

  <div class="ig-hero">
    <div class="ig-hero-l">
      <div class="ig-hero-ico"><?php echo cn_svg('integrations'); ?></div>
      <div>
        <h1>Powerful Integrations</h1>
        <p>Bring leads and data into WAAMINI, then manage their contacts, conversations and WhatsApp follow-up in one place.</p>
      </div>
    </div>
    <?php if ($needsSetup > 0): ?>
      <a href="#ig-cards" class="ig-btn ig-btn--primary">Finish setup (<?php echo $needsSetup; ?>) <span aria-hidden="true">&rarr;</span></a>
    <?php endif; ?>
  </div>

  <div class="ig-stats">
    <div class="ig-stat"><div class="ig-stat-ico"><?php echo cn_svg('integrations'); ?></div><div><div class="ig-stat-v"><?php echo $connected; ?></div><div class="ig-stat-l">Connected</div></div></div>
    <div class="ig-stat is-blue"><div class="ig-stat-ico"><?php echo cn_svg('flows'); ?></div><div><div class="ig-stat-v"><?php echo $available; ?></div><div class="ig-stat-l">Included in your plan</div></div></div>
    <div class="ig-stat is-amber"><div class="ig-stat-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="ig-stat-v"><?php echo $needsSetup; ?></div><div class="ig-stat-l">Waiting for setup</div></div></div>
    <div class="ig-stat is-purple"><div class="ig-stat-ico"><?php echo cn_svg('pulse'); ?></div><div><div class="ig-stat-v sm"><?php echo $lastSync ? date('d M, g:i A', $lastSync) : 'No sync yet'; ?></div><div class="ig-stat-l">Latest sync</div></div></div>
  </div>

  <h2 class="ig-sec-title" id="ig-cards">Your integrations</h2>
  <p class="ig-sec-sub">Connect a native source or configure the universal CRM API to import new leads.</p>

  <div class="ig-grid">
    <?php foreach ($integrations as $i): ?>
      <div class="ig-card<?php echo $i['allowed'] ? '' : ' locked'; ?>">
        <div class="ig-top">
          <div class="ig-ico t-<?php echo $i['tone']; ?>"><?php echo cn_svg($i['icon']); ?></div>
          <?php if (!$i['allowed']): ?>
            <span class="ig-pill lock"><?php echo cn_lock_icon(); ?> Upgrade</span>
          <?php elseif ($i['connected']): ?>
            <span class="ig-pill on">Connected</span>
          <?php else: ?>
            <span class="ig-pill off">Not connected</span>
          <?php endif; ?>
        </div>
        <div>
          <h3><?php echo htmlspecialchars($i['name']); ?></h3>
          <p><?php echo htmlspecialchars($i['desc']); ?></p>
        </div>
        <?php if ($i['allowed'] && $i['meta'] !== ''): ?><div class="ig-meta"><?php echo htmlspecialchars($i['meta']); ?></div><?php endif; ?>
        <?php if ($i['allowed']): ?>
          <a href="<?php echo $i['href']; ?>" class="ig-btn<?php echo $i['connected'] ? '' : ' ig-btn--primary'; ?>"><?php echo $i['connected'] ? $i['cta_on'] : $i['cta_off']; ?> <span aria-hidden="true">&rarr;</span></a>
        <?php else: ?>
          <a href="subscription.php" class="ig-btn">View plans <span aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ig-tip">
    <div class="ig-tip-ico"><?php echo cn_svg('discover'); ?></div>
    <div>
      <b>Need a different integration?</b>
      <span>For HubSpot, Zoho, Salesforce, Pipedrive, Freshsales, Monday.com or custom CRMs, use the authenticated lead-sync API with Zapier, Make, n8n or a direct HTTPS request. Native OAuth apps are not currently available for these CRMs.</span>
    </div>
  </div>

</div>

<?php include '../includes/client_footer.php'; ?>
