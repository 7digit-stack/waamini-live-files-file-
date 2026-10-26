<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('set_manual')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu
include 'user_guide_data.php';

$validLangs = ['en', 'ta', 'hi'];
$lang = isset($_GET['lang']) && in_array($_GET['lang'], $validLangs, true) ? $_GET['lang'] : 'en';

$content = get_guide_content($lang);

$pageTitle = strip_tags($content['header_title']);
$currentPage = 'manual';

// Counts for the summary tiles
$secCount = 0; $topicCount = 0; $stepCount = 0;
foreach ($content['sections'] as $sName => $features) {
    $secCount++;
    foreach ($features as $f) { $topicCount++; $stepCount += count($f['steps']); }
}
$langNames = ['en' => 'English', 'ta' => 'தமிழ்', 'hi' => 'हिंदी'];

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$plain = static fn($h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$h), ENT_QUOTES, 'UTF-8')));

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=3'); ?>">
<style>
.mu-grid{display:grid;grid-template-columns:270px minmax(0,1fr);gap:18px;align-items:start}
.mu-toc{position:sticky;top:16px;background:#fff;border-radius:18px;padding:18px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45)}
.mu-toc h2{margin:0 0 12px;font-size:15px;font-weight:800;color:var(--ink);letter-spacing:-.1px}
.mu-toc nav{display:flex;flex-direction:column;gap:4px;max-height:calc(100vh - 220px);overflow-y:auto}
.mu-toc a{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:11px;color:var(--tx);font-size:13.5px;font-weight:700;text-decoration:none;transition:background .15s,color .15s}
.mu-toc a span.n{font-size:12px;font-weight:800;padding:1px 8px;border-radius:999px;background:var(--soft);color:var(--mu);flex:none}
.mu-toc a:hover{background:var(--soft);color:var(--gd)}
.mu-toc a.is-on{background:#E3F7EE;color:var(--gd)}
.mu-toc a.is-on span.n{background:#fff}
.mu-main{display:flex;flex-direction:column;gap:18px;min-width:0}
.mu-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;border-radius:18px;padding:16px 18px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45)}
.mu-search{flex:1;min-width:240px;display:flex;align-items:center;gap:10px;height:46px;padding:0 16px;border-radius:13px;background:var(--field,#F3F7F5);color:var(--mu)}
.mu-search:focus-within{box-shadow:0 0 0 3px rgba(18,140,126,.18)}
.mu-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:600 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.mu-search input::placeholder{color:#7c8b86}
.mu-sec{scroll-margin-top:16px}
.mu-sec-h{display:flex;align-items:center;gap:12px;margin:0 0 12px}
.mu-sec-h h2{margin:0;font-size:19px;font-weight:800;color:var(--ink);letter-spacing:-.2px;display:flex;align-items:center;gap:10px}
.mu-sec-h h2 svg{width:20px;height:20px;color:var(--g);flex:none}
.mu-sec-h .su-tag{margin-left:auto}
.mu-topics{display:flex;flex-direction:column;gap:12px}
.mu-topic{background:#fff;border-radius:18px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45);overflow:hidden}
.mu-topic summary{display:flex;align-items:center;gap:14px;padding:16px 20px;cursor:pointer;list-style:none}
.mu-topic summary::-webkit-details-marker{display:none}
.mu-topic summary:hover{background:#FAFEFC}
.mu-ti{width:44px;height:44px;border-radius:12px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex:none}
.mu-ti svg{width:21px;height:21px}
.mu-tt{flex:1;min-width:0}
.mu-tt b{display:block;font-size:16px;font-weight:800;color:var(--ink);letter-spacing:-.1px}
.mu-tt small{display:block;margin-top:3px;font-size:13px;color:var(--mu);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mu-cv{width:32px;height:32px;border-radius:10px;background:var(--soft);color:var(--mu);display:grid;place-items:center;flex:none;transition:transform .2s,background .15s}
.mu-topic[open] .mu-cv{transform:rotate(180deg);background:#E3F7EE;color:var(--gd)}
.mu-body{padding:0 20px 20px 78px}
.mu-purpose{padding:13px 16px;border-radius:13px;background:var(--soft);font-size:14px;line-height:1.6;color:var(--tx);margin-bottom:14px}
.mu-purpose strong{color:var(--gd);margin-right:4px}
.mu-steps-l{margin:0 0 10px;font-size:12.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mu)}
.mu-steps{list-style:none;margin:0;padding:0;counter-reset:s;display:flex;flex-direction:column;gap:9px}
.mu-steps li{counter-increment:s;display:flex;gap:12px;align-items:flex-start;font-size:14px;line-height:1.6;color:var(--tx)}
.mu-steps li::before{content:counter(s);width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;font-size:12.5px;font-weight:800;display:grid;place-items:center;flex:none;margin-top:1px}
.mu-steps b{color:var(--ink)}
.mu-empty{display:none;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:50px 20px;background:#fff;border-radius:18px}
.mu-empty .e-i{width:70px;height:70px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin-bottom:6px}
.mu-empty h3{margin:0;font-size:18px;color:var(--ink)}
.mu-empty p{margin:0;color:var(--mu);font-size:14px}
.mu-cta{display:flex;align-items:center;gap:18px;flex-wrap:wrap;background:var(--soft);border-radius:18px;padding:22px 26px}
.mu-cta .su-ico{background:#fff}
.mu-cta div.t{flex:1;min-width:220px}
.mu-cta b{display:block;font-size:18px;color:var(--ink)}
.mu-cta p{margin:4px 0 0;color:var(--tx);font-size:14px;line-height:1.5}
mark.mu-hit{background:#FEF3DC;color:inherit;border-radius:4px;padding:0 2px}
@media (max-width:1000px){.mu-grid{grid-template-columns:1fr}.mu-toc{position:static}.mu-toc nav{flex-direction:row;flex-wrap:wrap;max-height:none}.mu-body{padding-left:20px}}
</style>

<div class="su">

  <div class="su-head">
    <div class="su-head-l">
      <div class="su-ico"><?php echo $lI('<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>', 25); ?></div>
      <div>
        <h1><?php echo $esc($plain($content['header_title'])); ?></h1>
        <p><?php echo $esc($plain($content['header_subtitle'])); ?></p>
      </div>
    </div>
    <div class="su-chips" role="group" aria-label="Language">
      <?php foreach ($langNames as $code => $name): ?>
        <a href="?lang=<?php echo $code; ?>" class="su-chip<?php echo $lang === $code ? ' on' : ''; ?>"><?php echo $esc($name); ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="su-kpis">
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>'); ?></div><div><b><?php echo $topicCount; ?></b><span>Feature guides</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'); ?></div><div><b><?php echo $secCount; ?></b><span>Topics covered</span></div></div>
    <div class="su-kpi amber"><div class="su-kpi-i"><?php echo $lI('<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>'); ?></div><div><b><?php echo $stepCount; ?></b><span>Step-by-step actions</span></div></div>
    <div class="su-kpi purple"><div class="su-kpi-i"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>'); ?></div><div><b>3</b><span>Languages</span></div></div>
  </div>

  <div class="mu-grid">
    <aside class="mu-toc">
      <h2>Contents</h2>
      <nav id="muToc">
        <?php $i = 0; foreach ($content['sections'] as $sName => $features): $i++; ?>
          <a href="#sec<?php echo $i; ?>" data-sec="sec<?php echo $i; ?>"><span><?php echo $esc($plain($sName)); ?></span><span class="n"><?php echo count($features); ?></span></a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <div class="mu-main">
      <div class="mu-tools">
        <label class="mu-search"><?php echo $lI('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 18); ?><input type="search" id="searchInput" placeholder="<?php echo $esc($plain($content['search'])); ?>" aria-label="Search the guide" autocomplete="off"></label>
        <button type="button" class="su-btn soft sm" id="muAll">Expand all</button>
      </div>

      <div class="mu-empty" id="searchEmpty">
        <span class="e-i"><?php echo $lI('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', 28); ?></span>
        <h3>No matches found</h3>
        <p>Try different keywords or clear the search.</p>
      </div>

      <div id="manualContent" style="display:flex;flex-direction:column;gap:26px">
        <?php $i = 0; foreach ($content['sections'] as $sName => $features): $i++; ?>
        <section class="mu-sec manual-section" id="sec<?php echo $i; ?>">
          <div class="mu-sec-h"><h2><?php echo $sName; ?></h2><span class="su-tag green"><?php echo count($features); ?> guide<?php echo count($features) === 1 ? '' : 's'; ?></span></div>
          <div class="mu-topics">
            <?php foreach ($features as $feature): ?>
            <details class="mu-topic manual-card" data-q="<?php echo $esc(mb_strtolower($plain($feature['name']) . ' ' . $plain($feature['purpose']) . ' ' . $plain(implode(' ', $feature['steps'])))); ?>">
              <summary>
                <span class="mu-ti"><?php echo $feature['icon']; ?></span>
                <span class="mu-tt"><b class="mc-title"><?php echo $feature['name']; ?></b><small><?php echo $esc(mb_strimwidth($plain($feature['purpose']), 0, 120, '…')); ?></small></span>
                <span class="su-tag"><?php echo count($feature['steps']); ?> steps</span>
                <span class="mu-cv"><?php echo $lI('<path d="m6 9 6 6 6-6"/>', 16); ?></span>
              </summary>
              <div class="mu-body">
                <div class="mu-purpose"><strong><?php echo $content['lbl_purpose']; ?></strong> <?php echo $feature['purpose']; ?></div>
                <p class="mu-steps-l"><?php echo $esc($plain($content['lbl_steps'])); ?></p>
                <ol class="mu-steps">
                  <?php foreach ($feature['steps'] as $step): ?><li><span><?php echo $step; ?></span></li><?php endforeach; ?>
                </ol>
              </div>
            </details>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>

      <div class="mu-cta">
        <div class="su-ico"><?php echo $lI('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 25); ?></div>
        <div class="t"><b>Still need help?</b><p>Our team replies on WhatsApp and email. Raise a ticket and we will walk you through it.</p></div>
        <a href="support.php" class="su-btn">Contact support</a>
      </div>
    </div>
  </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var topics = document.querySelectorAll('.manual-card'), secs = document.querySelectorAll('.manual-section'), toc = document.querySelectorAll('#muToc a');

  function filter() {
    var q = $('searchInput').value.toLowerCase().trim(), total = 0;
    secs.forEach(function (s) {
      var any = false;
      s.querySelectorAll('.manual-card').forEach(function (c) {
        var ok = !q || (c.getAttribute('data-q') || '').indexOf(q) !== -1;
        c.style.display = ok ? '' : 'none';
        if (ok) { any = true; total++; if (q) { c.open = true; } }
      });
      s.style.display = any ? '' : 'none';
      var link = document.querySelector('#muToc a[data-sec="' + s.id + '"]'); if (link) { link.style.display = any ? '' : 'none'; }
    });
    $('searchEmpty').style.display = total === 0 ? 'flex' : 'none';
  }
  $('searchInput').addEventListener('input', filter);

  var allOpen = false;
  $('muAll').addEventListener('click', function () {
    allOpen = !allOpen;
    topics.forEach(function (c) { if (c.style.display !== 'none') { c.open = allOpen; } });
    this.textContent = allOpen ? 'Collapse all' : 'Expand all';
  });

  // highlight the section being read
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { toc.forEach(function (a) { a.classList.toggle('is-on', a.getAttribute('data-sec') === e.target.id); }); } });
    }, {rootMargin: '-20% 0px -70% 0px'});
    secs.forEach(function (s) { io.observe(s); });
  }
  toc.forEach(function (a) { a.addEventListener('click', function (e) {
    var t = document.getElementById(a.getAttribute('data-sec')); if (t) { e.preventDefault(); t.scrollIntoView({behavior: 'smooth', block: 'start'}); }
  }); });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
