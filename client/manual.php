<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('set_manual')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
require_once __DIR__ . '/guide_catalog.php';

// ── Language: English, Tanglish, Hindi (remembered in a cookie) ──
$langs = ['en' => 'English', 'tl' => 'Tanglish', 'hi' => 'हिंदी'];
$lang = (string)($_GET['lang'] ?? ($_COOKIE['guide_lang'] ?? 'en'));
if (!isset($langs[$lang])) { $lang = 'en'; }
if (isset($_GET['lang'])) { setcookie('guide_lang', $lang, time() + 31536000, '/', '', !empty($_SERVER['HTTPS']), true); }

$L = [
    'ui' => [
        'guide' => 'User Guide', 'search' => 'Search help topics…', 'home' => 'Welcome',
        'welcome_t' => 'How to use Waamini',
        'welcome_s' => 'Short, step-by-step guides for the :n features in your :plan plan. Pick a topic on the left.',
        'start_t' => 'Get started', 'start_s' => 'Finish these steps to start messaging customers.', 'done_t' => 'You’re all set up', 'done_s' => 'Every first step is done. Explore the guides below.',
        'browse' => 'Browse by topic', 'guides' => 'guides',
        'steps' => 'steps', 'min' => 'min', 'overview' => 'Overview', 'where' => 'Where to find it', 'menu' => 'Menu',
        'how' => 'Step by step', 'tips' => 'Good to know', 'open' => 'Open this page',
        'prev' => 'Previous', 'next' => 'Next guide', 'empty' => 'No topics match. Try another word.',
        'help_t' => 'Still stuck? We’ll help you set it up.', 'help_s' => 'Our team replies on WhatsApp and email, Monday to Saturday.',
        'help_btn' => 'Contact support', 'topics' => 'Topics', 'plan' => 'Your plan',
    ],
    'cats' => [], 'check' => [
        'connect-whatsapp' => 'Connect your WhatsApp number', 'contacts' => 'Add your first contacts',
        'templates' => 'Get a template approved', 'campaigns' => 'Send your first campaign',
    ], 'g' => [],
];
if ($lang !== 'en') {
    $tr = require __DIR__ . '/guide_i18n_' . $lang . '.php';
    foreach (['ui', 'cats', 'check', 'g'] as $k) { $L[$k] = array_merge($L[$k], (array)($tr[$k] ?? [])); }
}
$t = static fn(string $k) => $L['ui'][$k] ?? $k;

$pageTitle = 'User Guide';
$currentPage = 'manual';

$cats = waamini_guide_categories();
foreach ($cats as $ck => $c) { if (isset($L['cats'][$ck])) $cats[$ck][0] = $L['cats'][$ck]; }
$guides = waamini_guides_for_plan();
foreach ($guides as &$g) {   // apply the translation for this guide, English otherwise
    $x = $L['g'][$g['key']] ?? null;
    if ($x) {
        $g['title'] = $x['t'] ?? $g['title'];
        $g['summary'] = $x['s'] ?? $g['summary'];
        if (!empty($x['steps'])) $g['steps'] = $x['steps'];
        if (isset($x['tips'])) $g['tips'] = $x['tips'];
    }
}
unset($g);
$byCat = [];
foreach ($guides as $g) { $byCat[$g['cat']][] = $g; }
$ordered = [];   // reading order = sidebar order
foreach ($cats as $ck => $c) { foreach ($byCat[$ck] ?? [] as $g) { $ordered[] = $g; } }
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
if ($on('set_whatsapp')) $checklist[] = ['connect-whatsapp', $waOk];
if ($on('contacts'))     $checklist[] = ['contacts', $count('SELECT COUNT(*) FROM contacts WHERE company_id = ?') > 0];
if ($on('templates'))    $checklist[] = ['templates', $count("SELECT COUNT(*) FROM message_templates WHERE company_id = ? AND UPPER(wa_status) = 'APPROVED'") > 0];
if ($on('campaigns'))    $checklist[] = ['campaigns', $count('SELECT COUNT(*) FROM campaigns WHERE company_id = ?') > 0];
$done = count(array_filter($checklist, static fn($c) => $c[1]));
$total = count($checklist);
$pct = $total ? (int)round($done / $total * 100) : 100;

$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$plain = static fn($h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$h), ENT_QUOTES, 'UTF-8')));
$svg = static fn(string $p, int $s = 20) => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$mins = static fn(array $g) => max(1, (int)ceil(count($g['steps']) * 0.5));
$chev = '<path d="m9 18 6-6-6-6"/>';
$welcomeSub = strtr($t('welcome_s'), [':n' => (string)count($guides), ':plan' => $planName !== '' ? $planName : '']);

include '../includes/client_header.php';
?>
<style>
.ug{--g:#128C7E;--gd:#0B6B5E;--ink:#0F2622;--tx:#3A4B45;--mu:#6B7C76;--line:#E3EEE9;--soft:#F4FAF7;--tint:#E3F7EE;
  display:grid;grid-template-columns:290px minmax(0,1fr);background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 1px 2px rgba(15,38,34,.04),0 14px 34px -26px rgba(15,38,34,.4);
  font-family:Inter,system-ui,sans-serif;color:var(--tx);min-height:calc(100vh - 140px);overflow:clip}
.ug *{box-sizing:border-box}
.ug a{text-decoration:none}

/* ── guide sidebar ── */
.ug-side{border-right:1px solid var(--line);background:#FBFDFC;display:flex;flex-direction:column;min-width:0;position:sticky;top:0;align-self:start;max-height:calc(100vh - 90px)}
.ug-side-h{padding:22px 20px 14px;flex:none}
.ug-brand{display:flex;align-items:center;gap:9px;margin:0 0 16px;font-size:19px;font-weight:800;color:var(--ink);letter-spacing:-.01em}
.ug-brand svg{color:var(--g)}
.ug-search{display:flex;align-items:center;gap:9px;height:44px;padding:0 14px;border-radius:12px;background:#fff;border:1px solid var(--line);transition:border-color .15s,box-shadow .15s}
.ug-search:focus-within{border-color:var(--g);box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.ug-search svg{color:var(--mu);flex:none}
.ug-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14px Inter,system-ui,sans-serif;color:var(--ink)}
.ug-nav{flex:1;overflow-y:auto;padding:4px 12px 24px;scrollbar-width:thin;overscroll-behavior:contain}
.ug-grp{margin-top:14px}
.ug-grp h4{margin:0 0 4px;padding:6px 10px;font-size:13px;font-weight:700;color:#8A9A94;letter-spacing:.01em}
.ug-link{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:10px;font-size:14px;font-weight:600;color:var(--ink);line-height:1.35;transition:background .15s,color .15s}
.ug-link span{flex:1;min-width:0}
.ug-link svg{flex:none;opacity:0;transition:opacity .15s}
.ug-link:hover{background:var(--tint);color:var(--gd)}
.ug-link:focus-visible{outline:2px solid var(--g);outline-offset:-2px}
.ug-link.on{background:var(--g);color:#fff;box-shadow:0 8px 16px -10px rgba(18,140,126,.9)}
.ug-link.on svg{opacity:1}
.ug-link--home{margin-top:6px}
.ug-nomatch{display:none;padding:14px 12px;font-size:13.5px;color:var(--mu)}

/* ── reading area ── */
.ug-main{min-width:0;display:flex;flex-direction:column}
.ug-top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:12px;padding:14px clamp(18px,3vw,40px);background:rgba(255,255,255,.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.ug-topics{display:none;align-items:center;gap:8px;height:38px;padding:0 14px;border-radius:10px;border:1px solid var(--line);background:#fff;font:700 13.5px Inter,system-ui,sans-serif;color:var(--ink);cursor:pointer}
.ug-crumb{font-size:13px;font-weight:600;color:var(--mu);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ug-crumb b{color:var(--ink);font-weight:700}
.ug-lang{margin-left:auto;display:inline-flex;padding:4px;border-radius:12px;background:var(--soft);border:1px solid var(--line);flex:none}
.ug-lang a{height:32px;padding:0 13px;display:inline-flex;align-items:center;border-radius:9px;font-size:13px;font-weight:700;color:var(--tx);white-space:nowrap}
.ug-lang a:hover{color:var(--ink)}
.ug-lang a.on{background:#fff;color:var(--gd);box-shadow:0 1px 2px rgba(15,38,34,.08),0 4px 10px -6px rgba(15,38,34,.3)}
.ug-body{padding:clamp(22px,3.4vw,44px) clamp(18px,3.4vw,52px) 56px;max-width:900px;width:100%}
.ug-art[hidden]{display:none}
.ug-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.ug-badge{display:inline-flex;align-items:center;height:26px;padding:0 11px;border-radius:7px;background:var(--tint);color:var(--gd);font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.ug-meta small{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9AA9A3}
.ug-meta small::before{content:'';width:5px;height:5px;border-radius:50%;background:#C9D6D1}
.ug-art h1{margin:0 0 10px;font-size:clamp(26px,3.2vw,38px);line-height:1.15;font-weight:800;letter-spacing:-.025em;color:var(--ink)}
.ug-sub{margin:0 0 30px;font-size:clamp(16px,1.6vw,18.5px);line-height:1.55;color:#5E716A}
.ug-art h2{margin:34px 0 14px;font-size:21px;font-weight:800;letter-spacing:-.015em;color:var(--ink)}
.ug-art h2:first-of-type{margin-top:0}
.ug-where{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 18px;border-radius:14px;background:var(--soft);border:1px solid var(--line)}
.ug-where .i{width:42px;height:42px;border-radius:12px;background:#fff;border:1px solid var(--line);color:var(--g);display:grid;place-items:center;flex:none}
.ug-where .t{flex:1;min-width:180px}
.ug-where .t small{display:block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mu)}
.ug-where .t b{display:block;margin-top:2px;font-size:15px;color:var(--ink)}
.ug-steps{list-style:none;margin:0;padding:0;counter-reset:s}
.ug-steps li{counter-increment:s;position:relative;display:flex;gap:16px;padding:0 0 20px;font-size:15.5px;line-height:1.65;color:var(--tx)}
.ug-steps li::before{content:counter(s);width:30px;height:30px;border-radius:50%;background:var(--g);color:#fff;font-size:13.5px;font-weight:800;display:grid;place-items:center;flex:none;position:relative;z-index:1;box-shadow:0 0 0 4px #fff}
.ug-steps li:not(:last-child)::after{content:'';position:absolute;left:14px;top:32px;bottom:0;width:2px;background:var(--line)}
.ug-steps li span{padding-top:2px}
.ug-steps b{color:var(--ink)}
.ug-tips{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
.ug-tips li{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:12px;background:#FFF9EA;border:1px solid #F3E3B5;font-size:14.5px;line-height:1.6;color:#5A4815}
.ug-tips li svg{flex:none;color:#C08A0B;margin-top:3px}
.ug-tips b{color:#3D3108}
.ug-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;padding:0 18px;border-radius:12px;font:700 14px Inter,system-ui,sans-serif;cursor:pointer;border:0;transition:transform .15s,box-shadow .15s;white-space:nowrap}
.ug-btn:hover{transform:translateY(-1px)}
.ug-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;box-shadow:0 10px 20px -10px rgba(18,140,126,.8)}
.ug-btn--primary:hover{color:#fff}
.ug-btn--light{background:#fff;color:var(--gd)}
.ug-pn{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:40px;padding-top:26px;border-top:1px solid var(--line)}
.ug-pn a{display:flex;flex-direction:column;gap:4px;padding:16px 18px;border-radius:14px;border:1px solid var(--line);background:#fff;transition:border-color .15s,box-shadow .15s;min-width:0}
.ug-pn a:hover{border-color:#BFE3D4;box-shadow:0 12px 24px -18px rgba(18,140,126,.6)}
.ug-pn small{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mu)}
.ug-pn b{font-size:15px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ug-pn .nx{text-align:right;grid-column:2}

/* welcome page */
.ug-start{display:flex;gap:22px;align-items:center;flex-wrap:wrap;padding:22px;border-radius:16px;background:var(--soft);border:1px solid var(--line);margin-bottom:8px}
.ug-ring{--p:0;width:72px;height:72px;border-radius:50%;flex:none;display:grid;place-items:center;background:conic-gradient(var(--g) calc(var(--p)*1%),#D6E9E1 0)}
.ug-ring span{width:56px;height:56px;border-radius:50%;background:var(--soft);display:grid;place-items:center;font-size:15px;font-weight:800;color:var(--ink)}
.ug-start-t{flex:1;min-width:220px}
.ug-start-t b{display:block;font-size:17px;color:var(--ink)}
.ug-start-t small{display:block;margin-top:2px;font-size:14px;color:var(--mu)}
.ug-check{list-style:none;margin:14px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(230px,100%),1fr));gap:8px}
.ug-check a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:11px;background:#fff;border:1px solid var(--line);font-size:14px;font-weight:600;color:var(--ink)}
.ug-check a:hover{border-color:#BFE3D4}
.ug-check .dot{width:22px;height:22px;border-radius:50%;border:2px solid #B9D3C9;flex:none;display:grid;place-items:center;color:#fff}
.ug-check .is-done .dot{background:var(--g);border-color:var(--g)}
.ug-check .is-done span.t{color:var(--mu);text-decoration:line-through;text-decoration-color:#B9D3C9}
.ug-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(230px,100%),1fr));gap:12px}
.ug-cat{display:flex;gap:14px;align-items:flex-start;padding:16px;border-radius:14px;border:1px solid var(--line);background:#fff;transition:border-color .15s,transform .15s,box-shadow .15s}
.ug-cat:hover{border-color:#BFE3D4;transform:translateY(-2px);box-shadow:0 14px 26px -20px rgba(18,140,126,.6)}
.ug-cat .i{width:42px;height:42px;border-radius:12px;background:var(--tint);color:var(--g);display:grid;place-items:center;flex:none}
.ug-cat b{display:block;font-size:15px;color:var(--ink)}
.ug-cat small{display:block;margin-top:3px;font-size:13px;color:var(--mu)}
.ug-help{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:34px;padding:20px 22px;border-radius:16px;background:linear-gradient(135deg,#0F3B35,#128C7E);color:#E6F5EF}
.ug-help .t{flex:1;min-width:220px}
.ug-help b{display:block;font-size:16.5px;color:#fff}
.ug-help p{margin:3px 0 0;font-size:14px;color:#CFE9E0}

/* phones / small tablets: sidebar becomes a slide-in panel */
.ug-scrim{display:none}
@media (max-width:980px){
  .ug{grid-template-columns:1fr;border-radius:16px}
  .ug-topics{display:inline-flex}
  .ug-side{position:fixed;top:0;left:0;bottom:0;z-index:10001;width:min(320px,86vw);max-height:none;transform:translateX(-105%);transition:transform .25s cubic-bezier(.2,.8,.2,1);box-shadow:24px 0 60px -20px rgba(15,38,34,.35)}
  .ug.side-open .ug-side{transform:none}
  .ug-scrim{display:block;position:fixed;inset:0;z-index:10000;background:rgba(15,38,34,.4);opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s}
  .ug.side-open .ug-scrim{opacity:1;visibility:visible}
  .ug-crumb{display:none}
}
@media (max-width:520px){
  .ug-top{padding:10px 12px}
  .ug-lang a{padding:0 9px;font-size:12.5px}
  .ug-pn{grid-template-columns:1fr}.ug-pn .nx{grid-column:1}
  .ug-where .ug-btn,.ug-help .ug-btn{width:100%}
}
@media (prefers-reduced-motion:reduce){.ug-side,.ug-scrim,.ug-cat{transition:none}}
</style>

<div class="ug" id="ug">
  <aside class="ug-side" id="ugSide" aria-label="<?php echo $esc($t('topics')); ?>">
    <div class="ug-side-h">
      <div class="ug-brand"><?php echo $svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>', 22); ?> <?php echo $esc($t('guide')); ?></div>
      <label class="ug-search"><?php echo $svg('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 17); ?><input type="search" id="ugQ" placeholder="<?php echo $esc($t('search')); ?>" aria-label="<?php echo $esc($t('search')); ?>" autocomplete="off"></label>
    </div>
    <nav class="ug-nav" id="ugNav">
      <a class="ug-link ug-link--home" href="#welcome" data-page="welcome"><span><?php echo $esc($t('home')); ?></span><?php echo $svg($chev, 16); ?></a>
      <?php foreach ($cats as $ck => [$cName]): if (empty($byCat[$ck])) continue; ?>
      <div class="ug-grp">
        <h4><?php echo $esc($cName); ?></h4>
        <?php foreach ($byCat[$ck] as $g): ?>
        <a class="ug-link" href="#<?php echo $esc($g['key']); ?>" data-page="<?php echo $esc($g['key']); ?>" data-q="<?php echo $esc(mb_strtolower($plain($g['title'] . ' ' . $g['summary'] . ' ' . implode(' ', $g['steps']) . ' ' . $cName))); ?>"><span><?php echo $esc($g['title']); ?></span><?php echo $svg($chev, 16); ?></a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <p class="ug-nomatch" id="ugNoMatch"><?php echo $esc($t('empty')); ?></p>
    </nav>
  </aside>
  <div class="ug-scrim" id="ugScrim"></div>

  <div class="ug-main">
    <div class="ug-top">
      <button type="button" class="ug-topics" id="ugTopicsBtn" aria-controls="ugSide" aria-expanded="false"><?php echo $svg('<path d="M3 12h18"/><path d="M3 6h18"/><path d="M3 18h18"/>', 17); ?> <?php echo $esc($t('topics')); ?></button>
      <span class="ug-crumb" id="ugCrumb"><?php echo $esc($t('guide')); ?></span>
      <nav class="ug-lang" aria-label="Language">
        <?php foreach ($langs as $code => $name): ?>
          <a href="?lang=<?php echo $code; ?>" class="<?php echo $lang === $code ? 'on' : ''; ?>" data-lang="<?php echo $code; ?>" lang="<?php echo $code === 'tl' ? 'ta-Latn' : $code; ?>"<?php echo $lang === $code ? ' aria-current="true"' : ''; ?>><?php echo $esc($name); ?></a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="ug-body" id="ugBody">
      <!-- welcome -->
      <article class="ug-art" data-page="welcome" data-crumb="<?php echo $esc($t('home')); ?>">
        <div class="ug-meta"><span class="ug-badge"><?php echo $esc($t('guide')); ?></span><?php if ($planName !== ''): ?><small><?php echo $esc($t('plan')); ?>: <?php echo $esc($planName); ?></small><?php endif; ?></div>
        <h1><?php echo $esc($t('welcome_t')); ?></h1>
        <p class="ug-sub"><?php echo $esc($welcomeSub); ?></p>

        <?php if ($total > 0): ?>
        <div class="ug-start">
          <div class="ug-ring" style="--p:<?php echo $pct; ?>"><span><?php echo $done; ?>/<?php echo $total; ?></span></div>
          <div class="ug-start-t"><b><?php echo $esc($done === $total ? $t('done_t') : $t('start_t')); ?></b><small><?php echo $esc($done === $total ? $t('done_s') : $t('start_s')); ?></small></div>
          <ul class="ug-check" style="flex-basis:100%">
            <?php foreach ($checklist as [$key, $ok]): ?>
            <li><a href="#<?php echo $esc($key); ?>" class="<?php echo $ok ? 'is-done' : ''; ?>"><span class="dot"><?php echo $ok ? $svg('<path d="M20 6 9 17l-5-5"/>', 13) : ''; ?></span><span class="t"><?php echo $esc($L['check'][$key] ?? $key); ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <h2><?php echo $esc($t('browse')); ?></h2>
        <div class="ug-cats">
          <?php foreach ($cats as $ck => [$cName, $cIcon]): if (empty($byCat[$ck])) continue; ?>
          <a class="ug-cat" href="#<?php echo $esc($byCat[$ck][0]['key']); ?>"><span class="i"><?php echo $svg($cIcon, 20); ?></span><span><b><?php echo $esc($cName); ?></b><small><?php echo count($byCat[$ck]); ?> <?php echo $esc($t('guides')); ?></small></span></a>
          <?php endforeach; ?>
        </div>

        <div class="ug-help">
          <div class="t"><b><?php echo $esc($t('help_t')); ?></b><p><?php echo $esc($t('help_s')); ?></p></div>
          <?php if ($on('set_support')): ?><a href="support.php" class="ug-btn ug-btn--light"><?php echo $esc($t('help_btn')); ?></a><?php endif; ?>
        </div>
      </article>

      <?php foreach ($ordered as $i => $g):
          $prev = $ordered[$i - 1] ?? null; $next = $ordered[$i + 1] ?? null; $cName = $cats[$g['cat']][0] ?? ''; ?>
      <article class="ug-art" data-page="<?php echo $esc($g['key']); ?>" data-crumb="<?php echo $esc($cName . ' / ' . $g['title']); ?>" hidden>
        <div class="ug-meta"><span class="ug-badge"><?php echo $esc($cName); ?></span><small><?php echo count($g['steps']); ?> <?php echo $esc($t('steps')); ?> · <?php echo $mins($g); ?> <?php echo $esc($t('min')); ?></small></div>
        <h1><?php echo $esc($g['title']); ?></h1>
        <p class="ug-sub"><?php echo $esc($g['summary']); ?></p>

        <h2><?php echo $esc($t('overview')); ?></h2>
        <div class="ug-where">
          <span class="i"><?php echo $svg($g['icon'], 20); ?></span>
          <div class="t"><small><?php echo $esc($t('where')); ?></small><b><?php echo $esc($t('menu')); ?> → <?php echo $esc($g['title']); ?></b></div>
          <a class="ug-btn ug-btn--primary" href="<?php echo $esc($g['page']); ?>"><?php echo $esc($t('open')); ?> <?php echo $svg('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>', 16); ?></a>
        </div>

        <h2><?php echo $esc($t('how')); ?></h2>
        <ol class="ug-steps"><?php foreach ($g['steps'] as $s): ?><li><span><?php echo $s; ?></span></li><?php endforeach; ?></ol>

        <?php if (!empty($g['tips'])): ?>
        <h2><?php echo $esc($t('tips')); ?></h2>
        <ul class="ug-tips"><?php foreach ($g['tips'] as $tip): ?><li><?php echo $svg('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 17); ?><span><?php echo $tip; ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <div class="ug-pn">
          <?php if ($prev): ?><a href="#<?php echo $esc($prev['key']); ?>"><small>← <?php echo $esc($t('prev')); ?></small><b><?php echo $esc($prev['title']); ?></b></a><?php endif; ?>
          <?php if ($next): ?><a class="nx" href="#<?php echo $esc($next['key']); ?>"><small><?php echo $esc($t('next')); ?> →</small><b><?php echo $esc($next['title']); ?></b></a><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var ug = $('ug'), arts = [].slice.call(document.querySelectorAll('.ug-art')), links = [].slice.call(document.querySelectorAll('.ug-link'));
  var narrow = window.matchMedia('(max-width:980px)');

  function show(key, scroll) {
    var art = arts.filter(function (a) { return a.getAttribute('data-page') === key; })[0] || arts[0];
    key = art.getAttribute('data-page');
    arts.forEach(function (a) { a.hidden = a !== art; });
    links.forEach(function (l) { var on = l.getAttribute('data-page') === key; l.classList.toggle('on', on); if (on) { l.setAttribute('aria-current', 'page'); } else { l.removeAttribute('aria-current'); } });
    $('ugCrumb').textContent = art.getAttribute('data-crumb') || '';
    var cur = links.filter(function (l) { return l.classList.contains('on'); })[0];
    if (cur && !narrow.matches) { cur.scrollIntoView({block: 'nearest'}); }
    if (scroll) { var top = ug.getBoundingClientRect().top + window.pageYOffset - 12; if (window.pageYOffset > top) window.scrollTo({top: top, behavior: 'smooth'}); }
    document.querySelectorAll('.ug-lang a').forEach(function (a) { a.href = '?lang=' + a.getAttribute('data-lang') + (key !== 'welcome' ? '#' + key : ''); });
    closeSide();
  }
  function route(scroll) { show(decodeURIComponent(location.hash.slice(1)) || 'welcome', scroll); }
  window.addEventListener('hashchange', function () { route(true); });

  // search the sidebar
  $('ugQ').addEventListener('input', function () {
    var q = this.value.toLowerCase().trim(), any = false;
    document.querySelectorAll('.ug-grp').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('.ug-link').forEach(function (l) {
        var ok = !q || q.split(/\s+/).every(function (w) { return (l.getAttribute('data-q') || '').indexOf(w) !== -1; });
        l.style.display = ok ? '' : 'none'; if (ok) vis++;
      });
      g.style.display = vis ? '' : 'none'; if (vis) any = true;
    });
    document.querySelector('.ug-link--home').style.display = q ? 'none' : '';
    $('ugNoMatch').style.display = any ? 'none' : 'block';
  });
  $('ugQ').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { var f = links.filter(function (l) { return l.style.display !== 'none' && !l.classList.contains('ug-link--home'); })[0]; if (f) { location.hash = f.getAttribute('data-page'); } }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/input|textarea|select/i.test((document.activeElement || {}).tagName || '')) { e.preventDefault(); if (narrow.matches) openSide(); $('ugQ').focus(); }
    if (e.key === 'Escape' && ug.classList.contains('side-open')) closeSide();
  });

  // sidebar as a slide-in panel on small screens
  function openSide() { ug.classList.add('side-open'); $('ugTopicsBtn').setAttribute('aria-expanded', 'true'); }
  function closeSide() { ug.classList.remove('side-open'); $('ugTopicsBtn').setAttribute('aria-expanded', 'false'); }
  $('ugTopicsBtn').addEventListener('click', openSide);
  $('ugScrim').addEventListener('click', closeSide);

  route(false);
})();
</script>

<?php include '../includes/client_footer.php'; ?>
