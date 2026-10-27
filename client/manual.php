<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('set_manual')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
require_once __DIR__ . '/guide_catalog.php';

$pageTitle = 'User Guide';
$currentPage = 'manual';

$cats = waamini_guide_categories();
$guides = waamini_guides_for_plan();
$byCat = [];
foreach ($guides as $g) { $byCat[$g['cat']][] = $g; }
$planName = function_exists('get_plan_name') ? trim((string)get_plan_name()) : '';
$on = static fn(string $k): bool => !function_exists('has_feature') || has_feature($k);

// ── Getting-started checklist (only steps the plan includes) ──
$cid = (int)($companyId ?? ($_SESSION['company_id'] ?? 0));
$count = static function (string $sql) use ($conn, $cid): int {
    try {
        $st = $conn->prepare($sql);
        if (!$st) return 0;
        $st->bind_param('i', $cid);
        $st->execute();
        $r = $st->get_result()->fetch_row();
        $st->close();
        return (int)($r[0] ?? 0);
    } catch (\Throwable $e) { return 0; }
};
$waOk = true;
if ($on('set_whatsapp')) {
    try {
        require_once __DIR__ . '/../includes/wa_connection_notice.php';
        $waOk = function_exists('wa_connection_state') ? wa_connection_state($conn, $cid) === 'ok' : true;
    } catch (\Throwable $e) { $waOk = true; }
}
$checklist = [];
if ($on('set_whatsapp')) $checklist[] = ['Connect your WhatsApp number', $waOk, 'connect-whatsapp'];
if ($on('contacts'))     $checklist[] = ['Add your first contacts', $count('SELECT COUNT(*) FROM contacts WHERE company_id = ?') > 0, 'contacts'];
if ($on('templates'))    $checklist[] = ['Get a template approved', $count("SELECT COUNT(*) FROM message_templates WHERE company_id = ? AND UPPER(wa_status) = 'APPROVED'") > 0, 'templates'];
if ($on('campaigns'))    $checklist[] = ['Send your first campaign', $count('SELECT COUNT(*) FROM campaigns WHERE company_id = ?') > 0, 'campaigns'];
$done = count(array_filter($checklist, static fn($c) => $c[1]));
$total = count($checklist);
$pct = $total ? (int)round($done / $total * 100) : 100;

$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$plain = static fn($h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$h), ENT_QUOTES, 'UTF-8')));
$svg = static fn(string $p, int $s = 20) => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$mins = static fn(array $g) => max(1, (int)ceil(count($g['steps']) * 0.5));

// Data for the reading panel (JSON, rendered client-side)
$panelData = [];
foreach ($guides as $g) {
    $panelData[$g['key']] = [
        'title' => $g['title'], 'summary' => $g['summary'], 'cat' => $cats[$g['cat']][0] ?? '',
        'icon' => $g['icon'], 'page' => $g['page'], 'steps' => $g['steps'], 'tips' => $g['tips'] ?? [],
        'mins' => $mins($g),
    ];
}

include '../includes/client_header.php';
?>
<style>
.ug,.ug-panel,.ug-ov{--ug-g:#128C7E;--ug-gd:#0B6B5E;--ug-ink:#0F2622;--ug-tx:#3A4B45;--ug-mu:#64766F;--ug-line:#E3EEE9;--ug-soft:#F3FBF7;--ug-tint:#E3F7EE;--ug-card:#fff;--ug-sh:0 1px 2px rgba(15,38,34,.05),0 12px 28px -20px rgba(15,38,34,.35);
}
.ug{display:flex;flex-direction:column;gap:22px;font-family:Inter,system-ui,sans-serif;color:var(--ug-tx);min-width:0}
.ug-panel{font-family:Inter,system-ui,sans-serif;color:var(--ug-tx)}
.ug-panel *{box-sizing:border-box}
.ug *{box-sizing:border-box}
.ug a{text-decoration:none}

/* hero */
.ug-hero{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:28px;align-items:center;padding:clamp(22px,3vw,34px);border-radius:22px;background:radial-gradient(700px 260px at 0% 0%,#DDF7EC 0,transparent 65%),var(--ug-card);border:1px solid var(--ug-line);box-shadow:var(--ug-sh)}
.ug-eyebrow{display:inline-flex;align-items:center;gap:8px;height:28px;padding:0 12px;border-radius:999px;background:var(--ug-tint);color:var(--ug-gd);font-size:12.5px;font-weight:700}
.ug-hero h1{margin:14px 0 8px;font-size:clamp(24px,2.6vw,32px);line-height:1.15;font-weight:800;letter-spacing:-.02em;color:var(--ug-ink)}
.ug-hero p.lead{margin:0 0 20px;font-size:15px;line-height:1.6;color:var(--ug-tx);max-width:560px}
.ug-hero p.lead b{color:var(--ug-ink)}
.ug-search{display:flex;align-items:center;gap:10px;height:52px;padding:0 8px 0 18px;border-radius:14px;background:#fff;border:1.5px solid var(--ug-line);max-width:560px;transition:border-color .15s,box-shadow .15s}
.ug-search:focus-within{border-color:var(--ug-g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.ug-search svg{color:var(--ug-mu);flex:none}
.ug-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 15px Inter,system-ui,sans-serif;color:var(--ug-ink)}
.ug-search kbd{flex:none;font:600 11.5px Inter,system-ui,sans-serif;color:var(--ug-mu);background:var(--ug-soft);border:1px solid var(--ug-line);border-radius:7px;padding:3px 8px}

/* checklist */
.ug-start{padding:20px;border-radius:18px;background:var(--ug-soft);border:1px solid var(--ug-line)}
.ug-start-h{display:flex;align-items:center;gap:14px;margin-bottom:14px}
.ug-ring{--p:0;width:54px;height:54px;border-radius:50%;flex:none;display:grid;place-items:center;background:conic-gradient(var(--ug-g) calc(var(--p)*1%),#D6E9E1 0)}
.ug-ring span{width:42px;height:42px;border-radius:50%;background:var(--ug-soft);display:grid;place-items:center;font-size:13px;font-weight:800;color:var(--ug-ink)}
.ug-start-h b{display:block;font-size:15.5px;color:var(--ug-ink)}
.ug-start-h small{font-size:13px;color:var(--ug-mu)}
.ug-check{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}
.ug-check button{all:unset;box-sizing:border-box;width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:11px;font-size:14px;font-weight:600;color:var(--ug-ink);cursor:pointer;transition:background .15s}
.ug-check button:hover,.ug-check button:focus-visible{background:#fff}
.ug-check button:focus-visible{outline:2px solid var(--ug-g)}
.ug-check .dot{width:22px;height:22px;border-radius:50%;border:2px solid #B9D3C9;flex:none;display:grid;place-items:center;color:#fff}
.ug-check .is-done .dot{background:var(--ug-g);border-color:var(--ug-g)}
.ug-check .is-done .t{color:var(--ug-mu);text-decoration:line-through;text-decoration-color:#B9D3C9}
.ug-check .go{margin-left:auto;color:var(--ug-mu);flex:none}

/* filters */
.ug-bar{display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap}
.ug-chips{display:flex;gap:8px;flex-wrap:wrap}
.ug-chip{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;border-radius:999px;background:var(--ug-card);border:1px solid var(--ug-line);font-size:13.5px;font-weight:600;color:var(--ug-tx);cursor:pointer;transition:background .15s,border-color .15s,color .15s}
.ug-chip svg{width:16px;height:16px;color:var(--ug-mu)}
.ug-chip em{font-style:normal;font-size:12px;font-weight:700;color:var(--ug-mu);background:var(--ug-soft);border-radius:999px;padding:1px 7px}
.ug-chip:hover{border-color:#BFE3D4}
.ug-chip:focus-visible{outline:2px solid var(--ug-g);outline-offset:2px}
.ug-chip.on{background:var(--ug-ink);border-color:var(--ug-ink);color:#fff}
.ug-chip.on svg{color:#fff}.ug-chip.on em{background:rgba(255,255,255,.16);color:#fff}
.ug-count{font-size:13.5px;color:var(--ug-mu);font-weight:600}

/* sections + cards */
.ug-sec{display:flex;flex-direction:column;gap:12px}
.ug-sec h2{display:flex;align-items:center;gap:10px;margin:6px 0 0;font-size:17px;font-weight:800;color:var(--ug-ink);letter-spacing:-.01em}
.ug-sec h2 span.i{width:32px;height:32px;border-radius:10px;background:var(--ug-tint);color:var(--ug-g);display:grid;place-items:center}
.ug-sec h2 span.i svg{width:17px;height:17px}
.ug-sec h2 small{font-size:13px;font-weight:600;color:var(--ug-mu)}
.ug-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(290px,100%),1fr));gap:14px}
.ug-card{all:unset;box-sizing:border-box;display:flex;flex-direction:column;gap:10px;padding:18px;border-radius:16px;background:var(--ug-card);border:1px solid var(--ug-line);box-shadow:var(--ug-sh);cursor:pointer;transition:transform .18s,border-color .18s,box-shadow .18s;min-width:0}
.ug-card:hover{transform:translateY(-2px);border-color:#BFE3D4;box-shadow:0 18px 34px -22px rgba(18,140,126,.55)}
.ug-card:focus-visible{outline:2px solid var(--ug-g);outline-offset:2px}
.ug-card .top{display:flex;align-items:center;gap:12px}
.ug-card .ic{width:42px;height:42px;border-radius:12px;background:var(--ug-tint);color:var(--ug-g);display:grid;place-items:center;flex:none}
.ug-card b{font-size:15.5px;font-weight:700;color:var(--ug-ink);line-height:1.35}
.ug-card p{margin:0;font-size:13.5px;line-height:1.55;color:var(--ug-tx);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ug-card .meta{margin-top:auto;padding-top:10px;border-top:1px solid var(--ug-line);display:flex;align-items:center;justify-content:space-between;font-size:12.5px;font-weight:600;color:var(--ug-mu)}
.ug-card .meta span:last-child{display:inline-flex;align-items:center;gap:4px;color:var(--ug-g)}
.ug-card:hover .meta span:last-child svg{transform:translateX(2px)}
.ug-card .meta svg{transition:transform .18s}
mark.ug-hit{background:#FEF3C7;color:inherit;border-radius:3px;padding:0 1px}

.ug-empty{display:none;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:48px 20px;border-radius:18px;background:var(--ug-card);border:1px dashed #CFE3DA}
.ug-empty .i{width:60px;height:60px;border-radius:50%;background:var(--ug-tint);color:var(--ug-g);display:grid;place-items:center;margin-bottom:6px}
.ug-empty b{font-size:17px;color:var(--ug-ink)}
.ug-empty p{margin:0;font-size:14px;color:var(--ug-mu)}
.ug-empty .ug-link{margin-top:8px}

.ug-help{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:22px 24px;border-radius:18px;background:linear-gradient(135deg,#0F3B35,#128C7E);color:#E6F5EF}
.ug-help .i{width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.12);display:grid;place-items:center;flex:none}
.ug-help .t{flex:1;min-width:220px}
.ug-help b{display:block;font-size:17px;color:#fff}
.ug-help p{margin:3px 0 0;font-size:14px;line-height:1.5;color:#CFE9E0}
.ug-help .acts{display:flex;gap:10px;flex-wrap:wrap}
.ug-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;padding:0 18px;border-radius:12px;font:700 14px Inter,system-ui,sans-serif;cursor:pointer;border:0;transition:transform .15s,background .15s}
.ug-btn:hover{transform:translateY(-1px)}
.ug-btn--light{background:#fff;color:var(--ug-gd)}
.ug-btn--ghost{background:rgba(255,255,255,.12);color:#fff}
.ug-btn--primary{background:linear-gradient(135deg,var(--ug-g),var(--ug-gd));color:#fff;box-shadow:0 10px 20px -10px rgba(18,140,126,.8)}
.ug-btn--soft{background:var(--ug-soft);color:var(--ug-ink);border:1px solid var(--ug-line)}
.ug-link{font-size:14px;font-weight:700;color:var(--ug-g)}
.ug-link:hover{text-decoration:underline}

/* reading panel */
.ug-ov{position:fixed;inset:0;z-index:10000;background:rgba(15,38,34,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);opacity:0;visibility:hidden;transition:opacity .22s,visibility .22s}
.ug-ov.open{opacity:1;visibility:visible}
.ug-panel{position:fixed;top:0;right:0;bottom:0;z-index:10001;width:min(540px,100%);display:flex;flex-direction:column;background:#fff;box-shadow:-24px 0 60px -20px rgba(15,38,34,.35);transform:translateX(100%);transition:transform .28s cubic-bezier(.2,.8,.2,1);visibility:hidden}
.ug-panel.open{transform:none;visibility:visible}
.ug-ph{flex:none;padding:22px 24px 18px;border-bottom:1px solid var(--ug-line);background:radial-gradient(420px 160px at 0 0,#E3F7EE 0,transparent 70%)}
.ug-ph-top{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.ug-ph-top .cat{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ug-gd)}
.ug-x{all:unset;margin-left:auto;width:36px;height:36px;border-radius:10px;display:grid;place-items:center;color:var(--ug-mu);cursor:pointer;background:var(--ug-soft)}
.ug-x:hover{color:var(--ug-ink)}
.ug-x:focus-visible{outline:2px solid var(--ug-g)}
.ug-ph-t{display:flex;gap:14px;align-items:flex-start}
.ug-ph-t .ic{width:48px;height:48px;border-radius:14px;background:var(--ug-tint);color:var(--ug-g);display:grid;place-items:center;flex:none}
.ug-ph h3{margin:0 0 6px;font-size:20px;line-height:1.3;font-weight:800;color:var(--ug-ink);letter-spacing:-.01em}
.ug-ph p{margin:0;font-size:14px;line-height:1.55;color:var(--ug-tx)}
.ug-ph .meta{margin-top:10px;display:flex;gap:14px;font-size:12.5px;font-weight:600;color:var(--ug-mu)}
.ug-ph .meta span{display:inline-flex;align-items:center;gap:5px}
.ug-pb{flex:1;overflow-y:auto;padding:22px 24px;overscroll-behavior:contain}
.ug-pb h4{margin:0 0 14px;font-size:12.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ug-mu)}
.ug-steps{list-style:none;margin:0;padding:0;counter-reset:s}
.ug-steps li{counter-increment:s;position:relative;display:flex;gap:14px;padding:0 0 18px;font-size:14.5px;line-height:1.6;color:var(--ug-tx)}
.ug-steps li::before{content:counter(s);width:28px;height:28px;border-radius:50%;background:var(--ug-g);color:#fff;font-size:13px;font-weight:800;display:grid;place-items:center;flex:none;position:relative;z-index:1}
.ug-steps li:not(:last-child)::after{content:'';position:absolute;left:13px;top:30px;bottom:2px;width:2px;background:var(--ug-line)}
.ug-steps b{color:var(--ug-ink)}
.ug-tips{margin-top:6px;padding:14px 16px;border-radius:14px;background:#FFF8E6;border:1px solid #F5E2B0}
.ug-tips b.h{display:flex;align-items:center;gap:8px;font-size:13.5px;color:#7A5300;margin-bottom:6px}
.ug-tips ul{margin:0;padding-left:20px;font-size:13.5px;line-height:1.6;color:#5C4A1A}
.ug-tips ul b{color:#3D3108}
.ug-pf{flex:none;display:flex;align-items:center;gap:10px;padding:14px 24px calc(14px + env(safe-area-inset-bottom));border-top:1px solid var(--ug-line);background:#fff}
.ug-pf .ug-btn--primary{flex:1}
.ug-pf .nav{display:flex;gap:8px}
.ug-pf .nav button{width:44px;padding:0}
.ug-pf .nav button[disabled]{opacity:.4;cursor:default;transform:none}

@media (max-width:900px){.ug-hero{grid-template-columns:1fr}.ug-search kbd{display:none}}
@media (max-width:600px){
  .ug{gap:18px}
  .ug-chips{flex-wrap:nowrap;overflow-x:auto;margin:0 -16px;padding:0 16px 4px;scrollbar-width:none}
  .ug-chips::-webkit-scrollbar{display:none}
  .ug-chip{flex:none}
  .ug-count{display:none}
  .ug-panel{top:auto;height:min(88vh,88dvh);width:100%;border-radius:22px 22px 0 0;transform:translateY(100%)}
  .ug-help{padding:20px}.ug-help .acts,.ug-help .acts .ug-btn{width:100%}
}
@media (prefers-reduced-motion:reduce){.ug-card,.ug-panel,.ug-ov{transition:none}}
</style>

<div class="ug">

  <section class="ug-hero">
    <div>
      <span class="ug-eyebrow"><?php echo $svg('<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>', 15); ?> Help centre</span>
      <h1>How to use Waamini</h1>
      <p class="lead">Short, step-by-step guides for the <b><?php echo count($guides); ?> features</b> in your <?php echo $planName !== '' ? '<b>' . $esc($planName) . '</b> plan' : 'plan'; ?>. Pick a topic or search.</p>
      <label class="ug-search">
        <?php echo $svg('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 19); ?>
        <input type="search" id="ugQ" placeholder="Search guides, e.g. “send campaign”" aria-label="Search guides" autocomplete="off">
        <kbd>/</kbd>
      </label>
    </div>

    <?php if ($total > 0): ?>
    <div class="ug-start">
      <div class="ug-start-h">
        <div class="ug-ring" style="--p:<?php echo $pct; ?>"><span><?php echo $done; ?>/<?php echo $total; ?></span></div>
        <div><b><?php echo $done === $total ? 'You’re all set up' : 'Get started'; ?></b><small><?php echo $done === $total ? 'Every first step is done. Explore the guides below.' : 'Finish these steps to start messaging customers.'; ?></small></div>
      </div>
      <ul class="ug-check">
        <?php foreach ($checklist as [$label, $ok, $key]): ?>
        <li><button type="button" class="<?php echo $ok ? 'is-done' : ''; ?>" data-guide="<?php echo $esc($key); ?>">
          <span class="dot"><?php echo $ok ? $svg('<path d="M20 6 9 17l-5-5"/>', 13) : ''; ?></span>
          <span class="t"><?php echo $esc($label); ?></span>
          <span class="go"><?php echo $svg('<path d="m9 18 6-6-6-6"/>', 16); ?></span>
        </button></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>

  <div class="ug-bar">
    <div class="ug-chips" role="tablist" aria-label="Filter guides by topic">
      <button type="button" class="ug-chip on" data-cat="" role="tab" aria-selected="true">All <em><?php echo count($guides); ?></em></button>
      <?php foreach ($cats as $ck => [$cName, $cIcon]): if (empty($byCat[$ck])) continue; ?>
        <button type="button" class="ug-chip" data-cat="<?php echo $esc($ck); ?>" role="tab" aria-selected="false"><?php echo $svg($cIcon, 16); ?> <?php echo $esc($cName); ?> <em><?php echo count($byCat[$ck]); ?></em></button>
      <?php endforeach; ?>
    </div>
    <span class="ug-count" id="ugCount" aria-live="polite"></span>
  </div>

  <div id="ugList" style="display:flex;flex-direction:column;gap:26px">
    <?php foreach ($cats as $ck => [$cName, $cIcon]): if (empty($byCat[$ck])) continue; ?>
    <section class="ug-sec" data-sec="<?php echo $esc($ck); ?>">
      <h2><span class="i"><?php echo $svg($cIcon, 17); ?></span> <?php echo $esc($cName); ?> <small><?php echo count($byCat[$ck]); ?></small></h2>
      <div class="ug-grid">
        <?php foreach ($byCat[$ck] as $g): ?>
        <button type="button" class="ug-card" data-guide="<?php echo $esc($g['key']); ?>" data-q="<?php echo $esc(mb_strtolower($g['title'] . ' ' . $g['summary'] . ' ' . $plain(implode(' ', $g['steps'])) . ' ' . $cName)); ?>">
          <span class="top"><span class="ic"><?php echo $svg($g['icon'], 21); ?></span><b class="ug-t"><?php echo $esc($g['title']); ?></b></span>
          <p><?php echo $esc($g['summary']); ?></p>
          <span class="meta"><span><?php echo count($g['steps']); ?> steps · <?php echo $mins($g); ?> min</span><span>Read guide <?php echo $svg('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>', 14); ?></span></span>
        </button>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <div class="ug-empty" id="ugEmpty">
    <span class="i"><?php echo $svg('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 26); ?></span>
    <b>No guides match “<span id="ugEmptyQ"></span>”</b>
    <p>Try a simpler word like “contact”, “template” or “bot”.</p>
    <a href="#" class="ug-link" id="ugClear">Clear search</a>
  </div>

  <div class="ug-help">
    <span class="i"><?php echo $svg('<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>', 24); ?></span>
    <div class="t"><b>Still stuck? We’ll help you set it up.</b><p>Our team replies on WhatsApp and email, Monday to Saturday.</p></div>
    <div class="acts">
      <?php if ($on('set_support')): ?><a href="support.php" class="ug-btn ug-btn--light">Contact support</a><?php endif; ?>
      <a href="subscription.php" class="ug-btn ug-btn--ghost">See what your plan includes</a>
    </div>
  </div>
</div>

<div class="ug-ov" id="ugOv" aria-hidden="true"></div>
<aside class="ug-panel" id="ugPanel" role="dialog" aria-modal="true" aria-labelledby="ugPT" aria-hidden="true">
  <div class="ug-ph">
    <div class="ug-ph-top"><span class="cat" id="ugPC"></span><button type="button" class="ug-x" id="ugClose" aria-label="Close guide"><?php echo $svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>', 18); ?></button></div>
    <div class="ug-ph-t">
      <span class="ic" id="ugPI"></span>
      <div><h3 id="ugPT"></h3><p id="ugPS"></p>
        <div class="meta"><span id="ugPM1"></span><span id="ugPM2"></span></div></div>
    </div>
  </div>
  <div class="ug-pb" id="ugPB">
    <h4>Step by step</h4>
    <ol class="ug-steps" id="ugSteps"></ol>
    <div class="ug-tips" id="ugTips" hidden>
      <b class="h"><?php echo $svg('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 16); ?> Good to know</b>
      <ul id="ugTipList"></ul>
    </div>
  </div>
  <div class="ug-pf">
    <a href="#" class="ug-btn ug-btn--primary" id="ugOpen">Open this page <?php echo $svg('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>', 16); ?></a>
    <div class="nav">
      <button type="button" class="ug-btn ug-btn--soft" id="ugPrev" aria-label="Previous guide"><?php echo $svg('<path d="m15 18-6-6 6-6"/>', 18); ?></button>
      <button type="button" class="ug-btn ug-btn--soft" id="ugNext" aria-label="Next guide"><?php echo $svg('<path d="m9 18 6-6-6-6"/>', 18); ?></button>
    </div>
  </div>
</aside>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var G = <?php echo json_encode($panelData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
  var $ = function (id) { return document.getElementById(id); };
  var cards = [].slice.call(document.querySelectorAll('.ug-card'));
  var secs = [].slice.call(document.querySelectorAll('.ug-sec'));
  var chips = [].slice.call(document.querySelectorAll('.ug-chip'));
  var q = $('ugQ'), cat = '', order = cards.map(function (c) { return c.getAttribute('data-guide'); });
  var svgI = function (p, s) { return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; };
  var escH = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

  // ── filter: topic chips + search ──
  function apply() {
    var term = q.value.toLowerCase().trim(), shown = [];
    cards.forEach(function (c) {
      var inCat = !cat || c.closest('.ug-sec').getAttribute('data-sec') === cat;
      var ok = inCat && (!term || term.split(/\s+/).every(function (w) { return (c.getAttribute('data-q') || '').indexOf(w) !== -1; }));
      c.style.display = ok ? '' : 'none';
      var t = c.querySelector('.ug-t'), raw = t.getAttribute('data-raw') || t.textContent;
      t.setAttribute('data-raw', raw);
      if (ok && term) {
        var i = raw.toLowerCase().indexOf(term);
        t.innerHTML = i === -1 ? escH(raw) : escH(raw.slice(0, i)) + '<mark class="ug-hit">' + escH(raw.slice(i, i + term.length)) + '</mark>' + escH(raw.slice(i + term.length));
      } else { t.textContent = raw; }
      if (ok) shown.push(c.getAttribute('data-guide'));
    });
    secs.forEach(function (s) { s.style.display = [].some.call(s.querySelectorAll('.ug-card'), function (c) { return c.style.display !== 'none'; }) ? '' : 'none'; });
    order = shown;
    $('ugEmpty').style.display = shown.length ? 'none' : 'flex';
    $('ugEmptyQ').textContent = q.value.trim();
    $('ugCount').textContent = (term || cat) ? shown.length + ' of ' + cards.length + ' guides' : '';
  }
  q.addEventListener('input', apply);
  chips.forEach(function (ch) {
    ch.addEventListener('click', function () {
      cat = ch.getAttribute('data-cat');
      chips.forEach(function (c) { var on = c === ch; c.classList.toggle('on', on); c.setAttribute('aria-selected', on ? 'true' : 'false'); });
      apply();
    });
  });
  $('ugClear').addEventListener('click', function (e) { e.preventDefault(); q.value = ''; apply(); q.focus(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q && !/input|textarea|select/i.test((document.activeElement || {}).tagName || '')) { e.preventDefault(); q.focus(); }
  });

  // ── reading panel ──
  var panel = $('ugPanel'), ov = $('ugOv'), current = null, lastFocus = null;
  function open(key, push) {
    var g = G[key]; if (!g) return;
    current = key;
    if (!panel.classList.contains('open')) lastFocus = document.activeElement;
    $('ugPC').textContent = g.cat;
    $('ugPI').innerHTML = svgI(g.icon, 24);
    $('ugPT').textContent = g.title;
    $('ugPS').textContent = g.summary;
    $('ugPM1').textContent = g.steps.length + ' steps';
    $('ugPM2').textContent = 'About ' + g.mins + ' min';
    $('ugSteps').innerHTML = g.steps.map(function (s) { return '<li><span>' + s + '</span></li>'; }).join('');
    $('ugTips').hidden = !g.tips.length;
    $('ugTipList').innerHTML = g.tips.map(function (s) { return '<li>' + s + '</li>'; }).join('');
    $('ugOpen').href = g.page;
    var list = order.length ? order : Object.keys(G), i = list.indexOf(key);
    $('ugPrev').disabled = i <= 0; $('ugNext').disabled = i === -1 || i >= list.length - 1;
    $('ugPB').scrollTop = 0;
    panel.classList.add('open'); ov.classList.add('open');
    panel.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (push !== false) history.replaceState(null, '', '#' + key);
    setTimeout(function () { $('ugClose').focus(); }, 50);
  }
  function close() {
    panel.classList.remove('open'); ov.classList.remove('open');
    panel.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    history.replaceState(null, '', location.pathname + location.search);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
    current = null;
  }
  function step(d) { var list = order.length ? order : Object.keys(G), i = list.indexOf(current); if (list[i + d]) open(list[i + d]); }
  document.querySelectorAll('[data-guide]').forEach(function (el) { el.addEventListener('click', function () { open(el.getAttribute('data-guide')); }); });
  $('ugClose').addEventListener('click', close);
  ov.addEventListener('click', close);
  $('ugPrev').addEventListener('click', function () { step(-1); });
  $('ugNext').addEventListener('click', function () { step(1); });
  document.addEventListener('keydown', function (e) {
    if (!current) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'Tab') {   // keep focus inside the panel
      var f = [].slice.call(panel.querySelectorAll('a[href],button:not([disabled])')), a = f[0], z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    }
  });

  apply();
  var h = decodeURIComponent(location.hash.slice(1));
  if (h && G[h]) open(h, false);
  window.addEventListener('hashchange', function () { var k = decodeURIComponent(location.hash.slice(1)); if (k && G[k] && k !== current) open(k, false); });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
