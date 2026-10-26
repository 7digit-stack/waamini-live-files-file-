<?php
/**
 * Data-driven animated feature hub (same look as broadcast/automation/crm hubs).
 * Used by feature pages whose $fp['hub'] names a file in includes/hub_data/ (for example 'marketing').
 * That file returns: title, sub, band (text on the ring), aria, items => [[icon, title, text, art-html], ...].
 * Styles: assets/css/wm-hub.css + assets/css/wm-feat.css (card-art kit, classes "wmf-"). Script: assets/js/wm-hub.js
 */
$__hd = require __DIR__ . '/hub_data/' . basename((string)$fp['hub']) . '.php';
$__hubNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$__h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ico = static fn(string $k) => '<svg aria-hidden="true"><use href="#wmf-i-' . $k . '"/></svg>';
$__arc = 'wmf-arc-' . preg_replace('/[^a-z0-9]/', '', strtolower((string)$fp['hub']));
// 'hub_head' => false: the page keeps its own section heading (and its typography) above; the hub shows only list + cards
$__headless = isset($fp['hub_head']) && $fp['hub_head'] === false;
?>
<section class="<?php echo $__headless ? 'wmh--headless' : 'fp-section'; ?> wmh wmf" id="waamini-<?php echo $__h($fp['hub']); ?>-hub">
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="wmf-i-repeat" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2.5l3 3-3 3"/><path d="M4 11.5v-1a5 5 0 0 1 5-5h11"/><path d="M7 21.5l-3-3 3-3"/><path d="M20 12.5v1a5 5 0 0 1-5 5H4"/></g></symbol>
    <symbol id="wmf-i-clock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></g></symbol>
    <symbol id="wmf-i-send" viewBox="0 0 24 24"><path d="M20.5 3.5L3.5 10.5l6.5 3 3 6.5zM10 13.5l4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-chat" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 4.5h15a2 2 0 0 1 2 2v8.5a2 2 0 0 1-2 2H11l-4.5 3.5V17H4.5a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2z"/><path d="M7.5 9h9M7.5 12.5h5.5"/></g></symbol>
    <symbol id="wmf-i-chart" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 3.5v17h17"/><path d="M8 16v-4M12.5 16V8M17 16v-6.5"/></g></symbol>
    <symbol id="wmf-i-link" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></g></symbol>
    <symbol id="wmf-i-tag" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12V5a1.5 1.5 0 0 1 1.5-1.5h7l8.5 8.5-8.5 8.5z"/></g><circle cx="8" cy="8" r="1.4" fill="currentColor"/></symbol>
    <symbol id="wmf-i-card" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></g></symbol>
    <symbol id="wmf-i-cart" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 3.5h2.6l2.3 11.2a1.6 1.6 0 0 0 1.6 1.3h8.7a1.6 1.6 0 0 0 1.6-1.2L21 8H6"/></g><circle cx="9.5" cy="20" r="1.5" fill="currentColor"/><circle cx="17.5" cy="20" r="1.5" fill="currentColor"/></symbol>
    <symbol id="wmf-i-gift" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4.5" rx="1"/><path d="M4.5 12.5V20a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1v-7.5M12 8v13"/><path d="M12 8c-1.8-3.6-6-3.8-6-1.2C6 8 8 8 12 8zM12 8c1.8-3.6 6-3.8 6-1.2C18 8 16 8 12 8z"/></g></symbol>
    <symbol id="wmf-i-file" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h10l4.5 4.5v12.5H5z"/><path d="M14.5 3.5V8.5h5M8.5 12.5h7M8.5 16h4.5"/></g></symbol>
    <symbol id="wmf-i-upload" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/><path d="M12 15V4M7.5 8.5L12 4l4.5 4.5"/></g></symbol>
    <symbol id="wmf-i-shield" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8l7.5 3v6c0 4.6-3.2 8.4-7.5 9.6-4.3-1.2-7.5-5-7.5-9.6v-6z"/><path d="M8.8 12.2l2.2 2.2 4.2-4.4"/></g></symbol>
    <symbol id="wmf-i-calendar" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></g></symbol>
    <symbol id="wmf-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-ticks" viewBox="0 0 24 24"><path d="M2 12.5l4 4L15.5 7M10.5 15.5l1 1L21 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-phone" viewBox="0 0 24 24"><path d="M21 16.4v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 1.1 3.7 2 2 0 0 1 3.1 1.5h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 9.3a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-missed" viewBox="0 0 24 24"><path d="M21 16.4v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 1.1 3.7 2 2 0 0 1 3.1 1.5h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 9.3a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M16 2.5l5 5M21 2.5l-5 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></symbol>
    <symbol id="wmf-i-star" viewBox="0 0 24 24"><path d="M12 2.8l2.8 5.8 6.4.9-4.6 4.4 1.1 6.3L12 17.2l-5.7 3 1.1-6.3-4.6-4.4 6.4-.9z" fill="currentColor"/></symbol>
    <symbol id="wmf-i-bell" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2H4.5z"/><path d="M10 21h4"/></g></symbol>
    <symbol id="wmf-i-pill" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.8" y="8.2" width="18.4" height="7.6" rx="3.8" transform="rotate(-45 12 12)"/><path d="M8.7 8.7l6.6 6.6"/></g></symbol>
    <symbol id="wmf-i-car" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 16.5v-4l2.2-5.2A2 2 0 0 1 7.5 6h9a2 2 0 0 1 1.8 1.3l2.2 5.2v4a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1z"/><path d="M3.5 12.5h17"/></g><circle cx="7.5" cy="17.5" r="1.8" fill="currentColor"/><circle cx="16.5" cy="17.5" r="1.8" fill="currentColor"/></symbol>
    <symbol id="wmf-i-download" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5"/></g></symbol>
    <symbol id="wmf-i-block" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/></g></symbol>
    <symbol id="wmf-i-box" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 7.5L12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5L12 12l8.5-4.5M12 12v9"/></g></symbol>
    <symbol id="wmf-i-truck" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 6h11v10.5h-11zM13.5 10h4l3.5 3.5v3h-7.5"/></g><circle cx="6.5" cy="18" r="1.8" fill="currentColor"/><circle cx="17" cy="18" r="1.8" fill="currentColor"/></symbol>
    <symbol id="wmf-i-home" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 11L12 4l8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/></g></symbol>
    <symbol id="wmf-i-shirt" viewBox="0 0 24 24"><path d="M8 3.5L3 6.5l2 4 2-1v11h10v-11l2 1 2-4-5-3c-.5 1.5-2 2.5-4 2.5s-3.5-1-4-2.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-bag" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1.2 12.5H6.2z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></g></symbol>
    <symbol id="wmf-i-watch" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5.5"/><path d="M9 7l.8-4h4.4l.8 4M9 17l.8 4h4.4l.8-4M12 10v2.2l1.4 1"/></g></symbol>
    <symbol id="wmf-i-school" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 9.5L12 5l9.5 4.5L12 14z"/><path d="M6.5 11.5v4.5c3.3 2.3 7.7 2.3 11 0v-4.5M21.5 9.5v5"/></g></symbol>
    <symbol id="wmf-i-bolt" viewBox="0 0 24 24"><path d="M13.5 2.5L4.5 13.5h6.5l-1 8 9-11h-6.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="wmf-i-pin" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21.5s7-6.2 7-11.5a7 7 0 0 0-14 0c0 5.3 7 11.5 7 11.5z"/><circle cx="12" cy="10" r="2.5"/></g></symbol>
    <symbol id="wmf-i-building" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 21V4.5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1V21M14.5 9.5h4a1 1 0 0 1 1 1V21M2.5 21h19"/><path d="M8 7.5h3M8 11h3M8 14.5h3M17 13.5v.01M17 17v.01"/></g></symbol>
    <symbol id="wmf-i-users" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.4"/><path d="M2.8 19.5c0-3.4 2.8-5.6 6.2-5.6s6.2 2.2 6.2 5.6"/><path d="M15.5 4.8a3.4 3.4 0 0 1 0 6.4M17.6 14.2c2.1.6 3.6 2.5 3.6 5.3"/></g></symbol>
    <symbol id="wmf-i-wa" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z" fill="currentColor"/><path d="M8.5 8.5c.2 3.4 2.6 5.8 6 6l1.2-1.4-1.9-1-.8.7c-.9-.4-1.6-1.1-2-2l.7-.8-1-1.9z" fill="#25D366"/></symbol>
  </svg>

  <div class="fp-wrap">
    <div class="wmh-grid">

      <!-- LEFT: heading + selectable feature list -->
      <div class="wmh-left">
        <?php if (!$__headless): ?>
        <span class="fp-badge">Features</span>
        <h2 class="wmh-title"><?php echo $__h($__hd['title']); ?></h2>
        <p class="wmh-sub"><?php echo $__h($__hd['sub']); ?> Pick a feature to see it work.</p>
        <?php endif; ?>
        <div class="wmh-list" role="tablist" aria-label="<?php echo $__h($__hd['aria']); ?>">
          <?php foreach ($__hd['items'] as $__i => $__it): ?>
          <button type="button" class="wmh-item<?php echo $__i === 0 ? ' is-active' : ''; ?>" data-i="<?php echo $__i; ?>" role="tab" aria-selected="<?php echo $__i === 0 ? 'true' : 'false'; ?>">
            <span class="wmh-item__ic"><?php echo $__ico($__it[0]); ?></span>
            <span class="wmh-item__txt"><strong><?php echo $__h($__it[1]); ?></strong><em><?php echo $__h($__it[2]); ?></em></span>
          </button>
          <?php endforeach; ?>
        </div>
        <a href="register.php" class="fp-btn fp-btn-primary wmh-cta">Start Free Trial
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      </div>

      <!-- RIGHT: hub + live card -->
      <div class="wmh-right" id="wmhViz">
        <div class="wmh-core" role="img" aria-label="WAAMINI <?php echo $__h(strtolower($__hd['band'])); ?>">
          <svg viewBox="0 0 400 400" aria-hidden="true">
            <defs><path id="<?php echo $__arc; ?>" d="M99 200 A101 101 0 0 1 301 200"/></defs>
            <circle class="r-outer" cx="200" cy="200" r="196"/>
            <g class="wmh-spin-rev">
              <circle cx="200" cy="200" r="178" fill="none"/>
              <rect class="r-node" x="193" y="15" width="14" height="14" rx="4"/>
              <rect class="r-node" x="347" y="282" width="14" height="14" rx="4"/>
              <rect class="r-node" x="39" y="282" width="14" height="14" rx="4"/>
            </g>
            <circle class="r-mid" cx="200" cy="200" r="160"/>
            <circle class="r-orbit wmh-spin" cx="200" cy="200" r="144"/>
            <circle class="r-pulse" cx="200" cy="200" r="128"/>
            <circle class="r-pulse p2" cx="200" cy="200" r="128"/>
            <circle class="r-band" cx="200" cy="200" r="128"/>
            <text class="band-text" text-anchor="middle"><textPath href="#<?php echo $__arc; ?>" startOffset="50%"><?php echo $__h($__hd['band']); ?></textPath></text>
            <circle class="r-disc" cx="200" cy="200" r="86"/>
            <image href="assets/img/waamini-mark.png" x="138" y="138" width="124" height="66" preserveAspectRatio="xMidYMid meet"/>
            <text class="brand" x="200" y="250" text-anchor="middle">WAAMINI</text>
          </svg>
        </div>

        <div class="wmh-wire" aria-hidden="true"><i></i><i></i><i></i></div>

        <div class="wmh-stack" aria-live="polite">
          <?php foreach ($__hd['items'] as $__i => $__it): ?>
          <article class="wmh-card<?php echo $__i === 0 ? ' is-active' : ''; ?>" data-card="<?php echo $__i; ?>">
            <?php if ($__i === 0): ?><span class="wmh-badge" aria-hidden="true"><svg><use href="#wmf-i-check"/></svg></span><?php endif; ?>
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico($__it[0]); ?></span><?php echo $__h($__it[1]); ?></h3>
            <div class="wmh-card__art" aria-hidden="true"><?php echo $__it[3]; ?></div>
            <p class="wmh-card__text"><?php echo $__h($__it[2]); ?></p>
          </article>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/js/wm-hub.js?v=2" nonce="<?php echo $__hubNonce; ?>"></script>
