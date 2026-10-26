<?php
/**
 * Animated "messaging platform" hub for the WhatsApp Broadcasts page.
 * Left: heading + selectable feature list. Right: animated hub with the selected feature's live card.
 * Styles: assets/css/wm-hub.css   Script: assets/js/wm-hub.js   (every class starts with "wmh-")
 */
$__hubNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$__hub = [
    ['template', 'Approved Templates', 'Create templates with image, video or document headers, variables and buttons, then send them once Meta approves.'],
    ['users',    'Groups & Segments',  'Send to all contacts or to a group. Import contacts by CSV and keep audiences tidy with tags.'],
    ['braces',   'Personalisation',    'Insert first name, full name, mobile, email, tags or custom text into every message automatically.'],
    ['calendar', 'Scheduling',         'Pick a date and time, or use Quick Broadcast for an instant send. Queue messages and let Waamini deliver them.'],
    ['shield',   'Number Protection',  'Min and max delay, batch size and batch rest keep your sending natural and protect your quality rating.'],
    ['bars',     'Campaign Reports',   'See sent, delivered, read and replied counts for every campaign, with a full message log.'],
];
$__h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ico = static fn(string $k) => '<svg aria-hidden="true"><use href="#wmh-i-' . $k . '"/></svg>';
?>
<section class="fp-section wmh" id="waamini-messaging-platform">
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="wmh-i-template" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="17" height="17" rx="3.5"/><path d="M8 12.2l2.8 2.8L16.2 9"/></g></symbol>
    <symbol id="wmh-i-users" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17.2" cy="9.5" r="2.4"/><path d="M17.5 14.2c2.2.3 3.6 2 3.6 4.3"/></g></symbol>
    <symbol id="wmh-i-braces" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 4C6.5 4 6 5 6 6.5v2.8c0 1.2-.7 2.2-2 2.7 1.3.5 2 1.5 2 2.7v2.8C6 19 6.5 20 8.5 20"/><path d="M15.5 4c2 0 2.5 1 2.5 2.5v2.8c0 1.2.7 2.2 2 2.7-1.3.5-2 1.5-2 2.7v2.8c0 1.5-.5 2.5-2.5 2.5"/><circle cx="12" cy="12" r=".6" fill="currentColor"/></g></symbol>
    <symbol id="wmh-i-calendar" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></g></symbol>
    <symbol id="wmh-i-shield" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7.5 2.8v5.4c0 4.6-3.1 8-7.5 9.8-4.4-1.8-7.5-5.2-7.5-9.8V5.8z"/><path d="M8.7 12.2l2.3 2.3 4.3-4.6"/></g></symbol>
    <symbol id="wmh-i-bars" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 20v-6M12 20V9M19 20V4"/></g></symbol>
    <symbol id="wmh-i-user" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.5" r="3.6"/><path d="M5 20c0-3.7 3.1-6 7-6s7 2.3 7 6"/></g></symbol>
    <symbol id="wmh-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmh-i-checks" viewBox="0 0 24 24"><path d="M2.5 12.5l4 4 8-9M10.5 15l1.5 1.5 8.5-9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmh-i-clock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></g></symbol>
    <symbol id="wmh-i-send" viewBox="0 0 24 24"><path d="M20.5 3.5L3.5 10.5l6.5 3 3 6.5zM10 13.5l4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmh-i-stack" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l8.5 4.5-8.5 4.5L3.5 8z"/><path d="M3.5 12.5L12 17l8.5-4.5M3.5 16.5L12 21l8.5-4.5"/></g></symbol>
    <symbol id="wmh-i-rocket" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 15.5l-5-5C10 6 13.5 3.5 20.5 3.5c0 7-2.5 10.5-7 12z"/><path d="M8.5 10.5L5 11l-1.5 2.5 3 .5M13.5 15.5L13 19l-2.5 1.5-.5-3M5 19l2.5-2.5"/><circle cx="15.5" cy="8.5" r="1.3"/></g></symbol>
    <symbol id="wmh-i-image" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18.5l5.5-7 4 5 2.5-3 6 5"/><circle cx="16.5" cy="7" r="1.8"/></g></symbol>
    <symbol id="wmh-i-arrow" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  </svg>

  <div class="fp-wrap">
    <div class="wmh-grid">

      <!-- LEFT: heading + selectable feature list -->
      <div class="wmh-left">
        <span class="fp-badge">Features</span>
        <h2 class="wmh-title">Everything you need to run WhatsApp broadcasts</h2>
        <p class="wmh-sub">Templates, audiences, scheduling and reports, all in one messaging platform. Pick a feature to see it work.</p>
        <div class="wmh-list" role="tablist" aria-label="Broadcast features">
          <?php foreach ($__hub as $__i => [$__ic, $__t, $__p]): ?>
          <button type="button" class="wmh-item<?php echo $__i === 0 ? ' is-active' : ''; ?>" data-i="<?php echo $__i; ?>" role="tab" aria-selected="<?php echo $__i === 0 ? 'true' : 'false'; ?>">
            <span class="wmh-item__ic"><?php echo $__ico($__ic); ?></span>
            <span class="wmh-item__txt"><strong><?php echo $__h($__t); ?></strong><em><?php echo $__h($__p); ?></em></span>
          </button>
          <?php endforeach; ?>
        </div>
        <a href="register.php" class="fp-btn fp-btn-primary wmh-cta">Start Free Trial
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      </div>

      <!-- RIGHT: hub + live card -->
      <div class="wmh-right" id="wmhViz">
        <div class="wmh-core" role="img" aria-label="WAAMINI messaging platform">
          <svg viewBox="0 0 400 400" aria-hidden="true">
            <defs><path id="wmh-arc" d="M99 200 A101 101 0 0 1 301 200"/></defs>
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
            <text class="band-text" text-anchor="middle"><textPath href="#wmh-arc" startOffset="50%">MESSAGING PLATFORM</textPath></text>
            <circle class="r-disc" cx="200" cy="200" r="86"/>
            <image href="assets/img/waamini-mark.png" x="138" y="138" width="124" height="66" preserveAspectRatio="xMidYMid meet"/>
            <text class="brand" x="200" y="250" text-anchor="middle">WAAMINI</text>
          </svg>
        </div>

        <div class="wmh-wire" aria-hidden="true"><i></i><i></i><i></i></div>

        <div class="wmh-stack" aria-live="polite">

          <!-- 0 Approved Templates -->
          <article class="wmh-card is-active" data-card="0">
            <span class="wmh-badge" aria-hidden="true"><svg><use href="#wmh-i-check"/></svg></span>
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('template'); ?></span>Approved Templates</h3>
            <div class="wmh-card__art wmh-tpl" aria-hidden="true">
              <div class="wmh-phone">
                <div class="wmh-phone__img"><svg><use href="#wmh-i-image"/></svg></div>
                <div class="wmh-phone__line" style="--w:88%"></div>
                <div class="wmh-phone__line" style="--w:60%;--d:.25s"></div>
                <span class="wmh-phone__btn">Learn More</span>
                <span class="wmh-phone__btn">Call Now</span>
              </div>
              <div class="wmh-steps wmh-seq">
                <span class="wmh-pill" style="--n:0"><svg class="wmh-ico"><use href="#wmh-i-send"/></svg>Submitted</span>
                <span class="wmh-pill" style="--n:1"><svg class="wmh-ico"><use href="#wmh-i-clock"/></svg>In review</span>
                <span class="wmh-pill" style="--n:2"><svg class="wmh-ico"><use href="#wmh-i-check"/></svg>Approved</span>
              </div>
            </div>
            <p class="wmh-card__text">Create templates with image, video or document headers, variables and buttons, then send them once Meta approves.</p>
          </article>

          <!-- 1 Groups & Segments -->
          <article class="wmh-card" data-card="1">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('users'); ?></span>Groups &amp; Segments</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmh-seg">
                <span class="wmh-file">CSV</span>
                <span class="wmh-flow"><i></i><i></i></span>
                <span class="wmh-avatars">
                  <span class="wmh-av" style="--n:0"><svg><use href="#wmh-i-user"/></svg></span>
                  <span class="wmh-av" style="--n:1"><svg><use href="#wmh-i-user"/></svg></span>
                  <span class="wmh-av" style="--n:2"><svg><use href="#wmh-i-user"/></svg></span>
                  <span class="wmh-av" style="--n:3"><svg><use href="#wmh-i-user"/></svg></span>
                </span>
              </div>
              <div class="wmh-tags wmh-seq">
                <span class="wmh-pill" style="--n:0">All contacts</span>
                <span class="wmh-pill" style="--n:1">VIP customers</span>
                <span class="wmh-pill" style="--n:2">New leads</span>
                <span class="wmh-pill" style="--n:3">Repeat buyers</span>
              </div>
            </div>
            <p class="wmh-card__text">Send to all contacts or to a group. Import contacts by CSV and keep audiences tidy with tags.</p>
          </article>

          <!-- 2 Personalisation -->
          <article class="wmh-card" data-card="2">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('braces'); ?></span>Personalisation</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmh-msg">Hi <span class="wmh-tok" data-token="{First Name}">{First Name}</span>, your order is ready. Reply here if you need any help.</div>
              <div class="wmh-vars wmh-seq">
                <span class="wmh-pill" style="--n:0">{First Name}</span>
                <span class="wmh-pill" style="--n:1">{Full Name}</span>
                <span class="wmh-pill" style="--n:2">{Mobile}</span>
                <span class="wmh-pill" style="--n:3">{Email}</span>
                <span class="wmh-pill" style="--n:4">{Tags}</span>
              </div>
            </div>
            <p class="wmh-card__text">Insert first name, full name, mobile, email, tags or custom text into every message automatically.</p>
          </article>

          <!-- 3 Scheduling -->
          <article class="wmh-card" data-card="3">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('calendar'); ?></span>Scheduling</h3>
            <div class="wmh-card__art wmh-sched" aria-hidden="true">
              <svg class="wmh-cal" viewBox="0 0 74 74">
                <rect class="c-fill" x="4" y="10" width="50" height="46" rx="8"/>
                <rect x="4" y="10" width="50" height="46" rx="8" fill="none" stroke="currentColor" stroke-width="2.4"/>
                <path d="M4 24h50M17 4v11M41 4v11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                <rect class="c-acc" x="12" y="31" width="8" height="7" rx="2"/><rect class="c-acc" x="25" y="31" width="8" height="7" rx="2" opacity=".45"/><rect class="c-acc" x="38" y="31" width="8" height="7" rx="2" opacity=".45"/>
                <rect class="c-acc" x="12" y="43" width="8" height="7" rx="2" opacity=".45"/><rect class="c-acc" x="25" y="43" width="8" height="7" rx="2" opacity=".45"/>
                <circle class="c-face" cx="54" cy="54" r="16"/>
                <path class="c-hand h2" d="M54 54v-7"/><path class="c-hand" d="M54 54v-10.5"/>
              </svg>
              <div class="wmh-rows wmh-seq">
                <span class="wmh-pill" style="--n:0"><svg class="wmh-ico"><use href="#wmh-i-clock"/></svg>Date and time</span>
                <span class="wmh-pill" style="--n:1"><svg class="wmh-ico"><use href="#wmh-i-stack"/></svg>Queued</span>
                <span class="wmh-pill" style="--n:2"><svg class="wmh-ico"><use href="#wmh-i-rocket"/></svg>Quick Broadcast</span>
              </div>
            </div>
            <p class="wmh-card__text">Pick a date and time, or use Quick Broadcast for an instant send. Queue messages and let Waamini deliver them.</p>
          </article>

          <!-- 4 Number Protection -->
          <article class="wmh-card" data-card="4">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('shield'); ?></span>Number Protection</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmh-num">
                <svg class="wmh-shield" viewBox="0 0 24 24"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                <span class="wmh-pill">+91 98XXX XXX10</span>
              </div>
              <div class="wmh-slider">
                <div class="wmh-slider__top"><span>Min delay</span><span>Max delay</span></div>
                <div class="wmh-slider__track"><span class="wmh-slider__fill"></span><span class="wmh-slider__knob"></span></div>
              </div>
              <div class="wmh-guard wmh-seq">
                <span class="wmh-pill" style="--n:0"><svg class="wmh-ico"><use href="#wmh-i-stack"/></svg>Batch size</span>
                <svg class="wmh-ico"><use href="#wmh-i-arrow"/></svg>
                <span class="wmh-pill" style="--n:1"><svg class="wmh-ico"><use href="#wmh-i-clock"/></svg>Batch rest</span>
                <svg class="wmh-ico"><use href="#wmh-i-arrow"/></svg>
                <span class="wmh-pill" style="--n:2"><svg class="wmh-ico"><use href="#wmh-i-check"/></svg>Quality</span>
              </div>
            </div>
            <p class="wmh-card__text">Min and max delay, batch size and batch rest keep your sending natural and protect your quality rating.</p>
          </article>

          <!-- 5 Campaign Reports -->
          <article class="wmh-card" data-card="5">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('bars'); ?></span>Campaign Reports</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <!-- sample figures for illustration only -->
              <div class="wmh-stats">
                <div class="wmh-stat"><b data-count="12.8">12.8K</b><span>Sent</span></div>
                <div class="wmh-stat"><b data-count="12.4">12.4K</b><span>Delivered</span></div>
                <div class="wmh-stat"><b data-count="9.6">9.6K</b><span>Read</span></div>
                <div class="wmh-stat"><b data-count="1.4">1.4K</b><span>Replied</span></div>
              </div>
              <div class="wmh-log wmh-seq">
                <div class="wmh-log__row" style="--n:0"><i></i><i></i><svg class="wmh-ico"><use href="#wmh-i-checks"/></svg></div>
                <div class="wmh-log__row" style="--n:1"><i></i><i></i><svg class="wmh-ico"><use href="#wmh-i-checks"/></svg></div>
                <div class="wmh-log__row" style="--n:2"><i></i><i></i><svg class="wmh-ico"><use href="#wmh-i-checks"/></svg></div>
              </div>
            </div>
            <p class="wmh-card__text">See sent, delivered, read and replied counts for every campaign, with a full message log.</p>
          </article>

        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/js/wm-hub.js?v=1" nonce="<?php echo $__hubNonce; ?>"></script>
