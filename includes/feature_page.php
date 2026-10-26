<?php
/**
 * Shared template for the public feature pages.
 * Expects $fp = [
 *   'slug','title','meta_desc','meta_keywords','eyebrow','h1' (use *word* for highlight),'desc',
 *   'bullets'=>[], 'chat'=>[['in|out','text'],...], 'chat_name', 'chips'=>[c1,c2],
 *   'stats'=>[[num,label],...], 'features_title','features_sub','features'=>[[icon,title,text],...],
 *   'steps_title','steps_sub','steps'=>[[title,text],...], 'cta_title','cta_text'
 * ]
 */
$icons = [
    'send'     => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'chart'    => '<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'bot'      => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'spark'    => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
    'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'tag'      => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'cart'     => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    'card'     => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
    'repeat'   => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'gift'     => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
    'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    'filter'   => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
];
$svg = static function (string $name) use ($icons): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? $icons['spark']) . '</svg>';
};
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$registry = require __DIR__ . '/feature_pages_registry.php';

$page_title    = $fp['title'];
$meta_desc     = $fp['meta_desc'];
$meta_keywords = $fp['meta_keywords'];
$asyncStylesheets = ['assets/css/landing-neo.css?v=16x', 'assets/css/feature-pages.css?v=2.2'];
// Interactive feature hub instead of the static feature grid: 'hub' => true (broadcast), 'automation', 'crm', or the name of a file in includes/hub_data/
$__hubs = ['automation' => ['automation_hub.php', 'wm-automation.css?v=1'], 'crm' => ['crm_hub.php', 'wm-crm.css?v=2']];
$__hubFile = '';
if (!empty($fp['hub'])) {
    $asyncStylesheets[] = 'assets/css/wm-hub.css?v=1';
    if (is_string($fp['hub']) && isset($__hubs[$fp['hub']])) { $__hubFile = $__hubs[$fp['hub']][0]; $asyncStylesheets[] = 'assets/css/' . $__hubs[$fp['hub']][1]; }
    elseif (is_string($fp['hub']) && is_file(__DIR__ . '/hub_data/' . basename($fp['hub']) . '.php')) { $__hubFile = 'feature_hub.php'; $asyncStylesheets[] = 'assets/css/wm-feat.css?v=3'; }
    else { $__hubFile = 'broadcast_hub.php'; }
}
$bodyClass = 'lp-neo';
include __DIR__ . '/header.php';

// "*word*" in the headline becomes the highlighted word
$h1 = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $esc($fp['h1']));
$waPath = 'M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z';
$check  = '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<script type="application/ld+json"><?php echo json_encode([
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $fp['title'], 'description' => $fp['meta_desc'],
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Waamini'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>

<main class="fp">

<!-- HERO -->
<section class="fp-hero">
  <div class="fp-wrap">
    <div class="fp-hero-grid">
      <div>
        <span class="fp-eyebrow"><svg viewBox="0 0 24 24" fill="currentColor"><path d="<?php echo $waPath; ?>"/></svg><?php echo $esc($fp['eyebrow']); ?></span>
        <h1 class="fp-h1"><?php echo $h1; ?></h1>
        <p class="fp-desc"><?php echo $esc($fp['desc']); ?></p>
        <div class="fp-bullets">
          <?php foreach ($fp['bullets'] as $b): ?>
            <div class="fp-bullet"><span class="fp-check"><?php echo $check; ?></span><?php echo $esc($b); ?></div>
          <?php endforeach; ?>
        </div>
        <div class="fp-actions">
          <a href="register.php" class="fp-btn fp-btn-primary">Start Free Trial
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
          <a href="pricing.php" class="fp-btn fp-btn-ghost">View Pricing</a>
        </div>
      </div>

      <div class="fp-mock">
        <div class="fp-phone fp-wa" id="fpWa">
          <div class="fp-phone-top">
            <span class="fp-wa-back"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg></span>
            <span class="av wm-av"><img class="wm-av-img" src="assets/img/waamini-mark.png" alt="" width="36" height="19"></span>
            <div class="fp-wa-info"><b><?php echo $esc($fp['chat_name']); ?></b><span>online</span></div>
            <div class="fp-wa-icons" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
              <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </div>
          </div>
          <?php $__ticks = '<svg width="16" height="11" viewBox="0 0 16 11" fill="none" stroke="#53bdeb" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 6 4 9 10 2"/><polyline points="6 8 7.5 9 14 2"/></svg>'; ?>
          <div class="fp-chat" id="fpChat">
            <div class="fp-date">TODAY</div>
            <?php foreach ($fp['chat'] as $__i => [$dir, $text]): $__side = $dir === 'out' ? 'out' : 'in'; ?>
              <div class="fp-msg <?php echo $__side; ?>"><?php echo $esc($text); ?><span class="fp-t">10:<?php echo 41 + intdiv($__i, 2); ?> <?php echo $__side === 'out' ? $__ticks : ''; ?></span></div>
            <?php endforeach; ?>
          </div>
          <div class="fp-wa-input" aria-hidden="true">
            <div class="fp-wa-field"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>Message</div>
            <span class="fp-wa-mic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg></span>
          </div>
        </div>
        <script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
        (function () {
          var chat = document.getElementById('fpChat'); if (!chat) return;
          var msgs = Array.prototype.slice.call(chat.querySelectorAll('.fp-msg'));
          if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
          chat.classList.add('js');
          var MAX = 4, TYPE = 1100, GAP = 800, HOLD = 3200, shown = [], idx = 0;
          function reveal(el) { el.classList.add('show'); shown.push(el); if (shown.length > MAX) shown.shift().classList.remove('show'); }
          function next() {
            if (idx >= msgs.length) {
              setTimeout(function () { shown.forEach(function (m) { m.classList.remove('show'); }); shown = []; idx = 0; setTimeout(next, 700); }, HOLD);
              return;
            }
            var el = msgs[idx++];
            var t = document.createElement('div');
            t.className = 'fp-msg show fp-typing ' + (el.classList.contains('out') ? 'out' : 'in');
            t.innerHTML = '<span></span><span></span><span></span>';
            chat.insertBefore(t, el);
            setTimeout(function () { chat.removeChild(t); reveal(el); setTimeout(next, GAP); }, TYPE);
          }
          next();
        })();
        </script>
        <?php foreach ($fp['chips'] as $k => [$ic, $label]): ?>
          <div class="fp-chip c<?php echo $k + 1; ?>"><?php echo $svg($ic); ?><?php echo $esc($label); ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- STATS -->
<section class="fp-stats">
  <div class="fp-wrap fp-stats-in">
    <?php foreach ($fp['stats'] as [$num, $label]): ?>
      <div class="fp-stat"><b><?php echo $esc($num); ?></b><span><?php echo $esc($label); ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<!-- FEATURES -->
<?php if ($__hubFile !== ''): include __DIR__ . '/' . $__hubFile; else: ?>
<section class="fp-section">
  <div class="fp-wrap">
    <div class="fp-head">
      <span class="fp-badge">Features</span>
      <h2 class="fp-title"><?php echo $esc($fp['features_title']); ?></h2>
      <p class="fp-sub"><?php echo $esc($fp['features_sub']); ?></p>
    </div>
    <div class="fp-grid">
      <?php foreach ($fp['features'] as [$ic, $t, $p]): ?>
        <div class="fp-card">
          <div class="fp-ico"><?php echo $svg($ic); ?></div>
          <h3><?php echo $esc($t); ?></h3>
          <p><?php echo $esc($p); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- HOW IT WORKS -->
<section class="fp-section alt">
  <div class="fp-wrap">
    <div class="fp-head">
      <span class="fp-badge">How it works</span>
      <h2 class="fp-title"><?php echo $esc($fp['steps_title']); ?></h2>
      <p class="fp-sub"><?php echo $esc($fp['steps_sub']); ?></p>
    </div>
    <div class="fp-steps">
      <?php foreach ($fp['steps'] as $i => [$t, $p]): ?>
        <div class="fp-step">
          <span class="fp-step-n">STEP 0<?php echo $i + 1; ?></span>
          <h3><?php echo $esc($t); ?></h3>
          <p><?php echo $esc($p); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- EXPLORE MORE -->
<section class="fp-section">
  <div class="fp-wrap">
    <div class="fp-head" style="margin-bottom:34px">
      <span class="fp-badge">Explore more</span>
      <h2 class="fp-title">Everything else Waamini can do</h2>
    </div>
    <div class="fp-more">
      <?php foreach ($registry as $slug => $label): if ($slug === $fp['slug']) continue; ?>
        <a href="<?php echo $esc($slug); ?>"><?php echo $esc($label); ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      <?php endforeach; ?>
      <a href="features.php">All features
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="fp-cta">
  <div class="fp-wrap">
    <div class="fp-cta-card">
      <h2><?php echo $esc($fp['cta_title']); ?></h2>
      <p><?php echo $esc($fp['cta_text']); ?></p>
      <a href="register.php" class="fp-btn fp-btn-primary">Start Free Today</a>
      <div class="fp-cta-meta"><span>No credit card required</span><span>Free trial</span><span>Cancel anytime</span></div>
    </div>
  </div>
</section>

</main>

<?php include __DIR__ . '/lp_scripts.php'; ?>
<?php include __DIR__ . '/footer.php'; ?>
