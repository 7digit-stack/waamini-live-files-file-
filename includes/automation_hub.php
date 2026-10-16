<?php
/**
 * Animated "automation platform" hub for the AI Chatbot page.
 * Left: heading + selectable feature list. Right: animated hub with the selected feature's live card.
 * Shares assets/css/wm-hub.css + assets/js/wm-hub.js with the broadcast hub; art styles: assets/css/wm-automation.css
 */
$__hubNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$__hub = [
    ['chat',    'Keyword Bot Replies', 'Match exact, contains or starts-with keywords and reply with text, templates, media and up to three quick-reply buttons.'],
    ['flow',    'Flow Builder',        'Build conversational flows visually with triggers and keywords. Or use the simple builder for quick setups.'],
    ['ai',      'AI Assistant',        'Connect your own OpenAI or Gemini key, set the persona, tone and language, and let AI answer from your instructions.'],
    ['booking', 'Demo & Booking Bot',  'Capture bookings through chat and link them to Google Calendar and Meet.'],
    ['headset', 'Human Handoff',       'Alert your team on a chosen number and move the conversation to a live agent when the bot reaches its limit.'],
];
$__h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ico = static fn(string $k) => '<svg aria-hidden="true"><use href="#wma-i-' . $k . '"/></svg>';
?>
<section class="fp-section wmh wma" id="waamini-automation">
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="wma-i-chat" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 4.5h15a2 2 0 0 1 2 2v8.5a2 2 0 0 1-2 2H11l-4.5 3.5V17H4.5a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2z"/><path d="M7.5 9h9M7.5 12.5h5.5"/></g></symbol>
    <symbol id="wma-i-shuffle" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h3.2c5.2 0 5.2 10 10.4 10H20"/><path d="M3 17h3.2c1.5 0 2.6-.8 3.5-2M13.2 9c.9-1.2 2-2 3.4-2H20"/><path d="M17.5 4.5L20 7l-2.5 2.5M17.5 14.5L20 17l-2.5 2.5"/></g></symbol>
    <symbol id="wma-i-flow" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3.5" width="8" height="6.5" rx="1.8"/><rect x="13" y="14" width="8" height="6.5" rx="1.8"/><path d="M11 6.8h3a3 3 0 0 1 3 3V14"/></g></symbol>
    <symbol id="wma-i-ai" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="2.6"/><path d="M9.5 3v3M14.5 3v3M9.5 18v3M14.5 18v3M3 9.5h3M3 14.5h3M18 9.5h3M18 14.5h3"/></g><path d="M12 8.8l1 2.2 2.2 1-2.2 1-1 2.2-1-2.2-2.2-1 2.2-1z" fill="currentColor"/></symbol>
    <symbol id="wma-i-booking" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4M8.8 15l2.2 2.2 4.2-4.4"/></g></symbol>
    <symbol id="wma-i-headset" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 13.5V12a7.5 7.5 0 0 1 15 0v1.5"/><rect x="3" y="13" width="4.5" height="6.5" rx="1.8"/><rect x="16.5" y="13" width="4.5" height="6.5" rx="1.8"/><path d="M18.8 19.5c0 1.4-1.6 2.3-4.8 2.3"/></g></symbol>
    <symbol id="wma-i-bot" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="8" width="15" height="11" rx="3.2"/><path d="M12 8V5M2.5 12.5v3M21.5 12.5v3M9.5 16h5"/></g><circle cx="12" cy="4" r="1.4" fill="currentColor"/><circle cx="9.2" cy="12.5" r="1.3" fill="currentColor"/><circle cx="14.8" cy="12.5" r="1.3" fill="currentColor"/></symbol>
    <symbol id="wma-i-user" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.5" r="3.6"/><path d="M5 20c0-3.7 3.1-6 7-6s7 2.3 7 6"/></g></symbol>
    <symbol id="wma-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wma-i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5" fill="currentColor"/><path d="M12 7v6" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16.6" r="1.3" fill="#fff"/></symbol>
    <symbol id="wma-i-calendar" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></g></symbol>
    <symbol id="wma-i-video" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6.5" width="13" height="11" rx="2.6"/><path d="M15.5 11l6-3.2v8.4l-6-3.2z"/></g></symbol>
    <symbol id="wma-i-key" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15.5" r="4.2"/><path d="M11 12.5l8.5-8.5M16 7.5l2.5 2.5M13.5 10l2 2"/></g></symbol>
    <symbol id="wma-i-send" viewBox="0 0 24 24"><path d="M20.5 3.5L3.5 10.5l6.5 3 3 6.5zM10 13.5l4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wma-i-tag" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12V5a1.5 1.5 0 0 1 1.5-1.5h7l8.5 8.5-8.5 8.5z"/></g><circle cx="8" cy="8" r="1.4" fill="currentColor"/></symbol>
    <symbol id="wma-i-template" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3.5" width="16" height="17" rx="3"/><path d="M8 8.5h8M8 12h8M8 15.5h4.5"/></g></symbol>
  </svg>

  <div class="fp-wrap">
    <div class="wmh-grid">

      <!-- LEFT: heading + selectable feature list -->
      <div class="wmh-left">
        <span class="fp-badge">Features</span>
        <h2 class="wmh-title">Automate every WhatsApp conversation</h2>
        <p class="wmh-sub">Keyword bots, visual flows, AI replies and live-agent handoff, working together on one platform. Pick a feature to see it work.</p>
        <div class="wmh-list" role="tablist" aria-label="Chatbot features">
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
        <div class="wmh-core" role="img" aria-label="WAAMINI automation platform">
          <svg viewBox="0 0 400 400" aria-hidden="true">
            <defs><path id="wma-arc" d="M99 200 A101 101 0 0 1 301 200"/></defs>
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
            <text class="band-text" text-anchor="middle"><textPath href="#wma-arc" startOffset="50%">AUTOMATION PLATFORM</textPath></text>
            <circle class="r-disc" cx="200" cy="200" r="86"/>
            <path class="bub-back" d="M178 136h60a16 16 0 0 1 16 16v32a16 16 0 0 1-16 16h-34l-18 16v-16h-8a16 16 0 0 1-16-16v-32a16 16 0 0 1 16-16z"/>
            <path class="bub-front" d="M170 128h60a16 16 0 0 1 16 16v32a16 16 0 0 1-16 16h-34l-18 16v-16h-8a16 16 0 0 1-16-16v-32a16 16 0 0 1 16-16z"/>
            <circle class="bub-dot" cx="182" cy="160" r="5"/>
            <circle class="bub-dot d2" cx="200" cy="160" r="5"/>
            <circle class="bub-dot d3" cx="218" cy="160" r="5"/>
            <text class="brand" x="200" y="250" text-anchor="middle">WAAMINI</text>
          </svg>
        </div>

        <div class="wmh-wire" aria-hidden="true"><i></i><i></i><i></i></div>

        <div class="wmh-stack" aria-live="polite">

          <!-- 0 Keyword Bot Replies -->
          <article class="wmh-card is-active" data-card="0">
            <span class="wmh-badge" aria-hidden="true"><svg><use href="#wma-i-check"/></svg></span>
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('chat'); ?></span>Keyword Bot Replies</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wma-match wmh-seq">
                <span class="wmh-pill" style="--n:0">Exact</span>
                <span class="wmh-pill" style="--n:1">Contains</span>
                <span class="wmh-pill" style="--n:2">Starts with</span>
              </div>
              <div class="wma-chat">
                <span class="wma-bub wma-bub--in">Hi, <b>price</b> list please</span>
                <span class="wma-bub wma-bub--out">Here is our latest price list.</span>
                <div class="wma-qr"><span style="--n:0">Catalogue</span><span style="--n:1">Offers</span><span style="--n:2">Talk to us</span></div>
              </div>
            </div>
            <p class="wmh-card__text">Match exact, contains or starts-with keywords and reply with text, templates, media and up to three quick-reply buttons.</p>
          </article>

          <!-- 1 Flow Builder -->
          <article class="wmh-card" data-card="1">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('flow'); ?></span>Flow Builder</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <svg class="wma-diagram wma-flowmap" viewBox="0 0 300 138">
                <defs><pattern id="wma-dots" width="12" height="12" patternUnits="userSpaceOnUse"><circle class="d-dot" cx="2" cy="2" r="1"/></pattern></defs>
                <rect class="d-win" x=".75" y=".75" width="298.5" height="136.5" rx="10"/>
                <rect x="6" y="24" width="288" height="108" fill="url(#wma-dots)"/>
                <path class="d-wire" d="M1 20h298"/>
                <circle class="d-chrome" cx="14" cy="10.5" r="3"/><circle class="d-chrome" cx="24" cy="10.5" r="3"/><circle class="d-chrome" cx="34" cy="10.5" r="3"/>
                <path class="d-wire" d="M138 52h28M160 47l6 5-6 5M226 68v22M221 84l5 6 5-6M166 106h-28M144 101l-6 5 6 5"/>
                <path class="d-run" style="--n:0" pathLength="100" d="M138 52h28"/>
                <path class="d-run" style="--n:1" pathLength="100" d="M226 68v22"/>
                <path class="d-run" style="--n:2" pathLength="100" d="M166 106h-28"/>
                <rect class="d-node" style="--n:0" x="10" y="36" width="128" height="32" rx="8"/>
                <svg class="d-ic" x="19" y="45" width="14" height="14"><use href="#wma-i-chat"/></svg><text x="39" y="56">Trigger: keyword</text>
                <rect class="d-node" style="--n:1" x="166" y="36" width="120" height="32" rx="8"/>
                <svg class="d-ic" x="176" y="45" width="14" height="14"><use href="#wma-i-send"/></svg><text x="197" y="56">Send template</text>
                <rect class="d-node" style="--n:2" x="166" y="90" width="120" height="32" rx="8"/>
                <svg class="d-ic" x="176" y="99" width="14" height="14"><use href="#wma-i-tag"/></svg><text x="197" y="110">Check tag</text>
                <rect class="d-node" style="--n:3" x="10" y="90" width="128" height="32" rx="8"/>
                <svg class="d-ic" x="19" y="99" width="14" height="14"><use href="#wma-i-check"/></svg><text x="39" y="110">Send reply</text>
              </svg>
            </div>
            <p class="wmh-card__text">Build conversational flows visually with triggers and keywords. Or use the simple builder for quick setups.</p>
          </article>

          <!-- 2 AI Assistant -->
          <article class="wmh-card" data-card="2">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('ai'); ?></span>AI Assistant</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wma-ai">
                <svg class="wma-chip" viewBox="0 0 66 66">
                  <path class="c-pin" style="--n:0" d="M24 4v9M42 4v9"/><path class="c-pin" style="--n:1" d="M53 24h9M53 42h9"/>
                  <path class="c-pin" style="--n:2" d="M24 53v9M42 53v9"/><path class="c-pin" style="--n:3" d="M4 24h9M4 42h9"/>
                  <rect class="c-body" x="12" y="12" width="42" height="42" rx="10"/>
                  <path class="c-star" d="M33 20l3.6 9.4L46 33l-9.4 3.6L33 46l-3.6-9.4L20 33l9.4-3.6z"/>
                </svg>
                <div class="wma-ai__side">
                  <span class="wmh-pill wma-key"><svg class="wmh-ico"><use href="#wma-i-key"/></svg>Your API key<em>Connected</em></span>
                  <div class="wma-prov wmh-seq">
                    <span class="wmh-pill" style="--n:0">OpenAI</span>
                    <span class="wmh-pill" style="--n:1">Gemini</span>
                  </div>
                </div>
              </div>
              <div class="wma-persona wmh-seq">
                <span class="wmh-pill" style="--n:0">Persona</span>
                <span class="wmh-pill" style="--n:1">Tone</span>
                <span class="wmh-pill" style="--n:2">Language</span>
              </div>
            </div>
            <p class="wmh-card__text">Connect your own OpenAI or Gemini key, set the persona, tone and language, and let AI answer from your instructions.</p>
          </article>

          <!-- 3 Demo & Booking Bot -->
          <article class="wmh-card" data-card="3">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('booking'); ?></span>Demo &amp; Booking Bot</h3>
            <div class="wmh-card__art wma-book" aria-hidden="true">
              <span class="wma-bub wma-bub--out">Pick a time for your demo</span>
              <div class="wma-slots wmh-seq">
                <span class="wmh-pill" style="--n:0">10:00 AM</span>
                <span class="wmh-pill" style="--n:1">11:30 AM</span>
                <span class="wmh-pill" style="--n:2">4:00 PM</span>
              </div>
              <div class="wma-sync">
                <span class="wma-booked"><svg class="wmh-ico"><use href="#wma-i-check"/></svg>Booked</span>
                <span class="wmh-flow"><i></i><i></i></span>
                <span class="wma-apps">
                  <span class="wmh-pill"><svg class="wmh-ico"><use href="#wma-i-calendar"/></svg>Google Calendar</span>
                  <span class="wmh-pill"><svg class="wmh-ico"><use href="#wma-i-video"/></svg>Google Meet</span>
                </span>
              </div>
            </div>
            <p class="wmh-card__text">Capture bookings through chat and link them to Google Calendar and Meet.</p>
          </article>

          <!-- 4 Human Handoff -->
          <article class="wmh-card" data-card="4">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('headset'); ?></span>Human Handoff</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wma-hand">
                <div class="wma-agent"><span class="wma-face"><svg><use href="#wma-i-bot"/></svg></span><em>Bot</em></div>
                <div class="wma-hand__mid">
                  <span class="wma-alert"><svg class="wmh-ico"><use href="#wma-i-alert"/></svg>Limit reached</span>
                  <span class="wmh-flow"><i></i><i></i></span>
                </div>
                <div class="wma-agent wma-agent--live"><span class="wma-face"><svg><use href="#wma-i-headset"/></svg></span><em>Live agent</em></div>
              </div>
              <div class="wma-alertnum"><span>Alert number</span><span class="wmh-pill">+91 98XXX XXX10</span></div>
            </div>
            <p class="wmh-card__text">Alert your team on a chosen number and move the conversation to a live agent when the bot reaches its limit.</p>
          </article>

        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/js/wm-hub.js?v=2" nonce="<?php echo $__hubNonce; ?>"></script>
