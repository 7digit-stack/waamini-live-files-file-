<?php
/**
 * Animated "CRM platform" hub for the WhatsApp CRM page.
 * Left: heading + selectable feature list. Right: animated hub with the selected feature's live card.
 * Shares assets/css/wm-hub.css + assets/js/wm-hub.js with the other hubs; art styles: assets/css/wm-crm.css (classes start with "wmc-")
 */
$__hubNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$__hub = [
    ['inbox',  'Team Inbox & Live Chat', 'Multiple agents reply from one number. Tickets move from Open to Pending to Resolved.'],
    ['users',  'Agent Assignment',       'Assign chats to teammates and set access levels so everyone sees only what they should.'],
    ['tag',    'Labels & Outcomes',      'Tag conversations and mark outcomes such as won or lost to understand your pipeline.'],
    ['note',   'Notes & Reminders',      'Keep internal notes on each contact and schedule follow-up reminders.'],
    ['spark',  'AI Lead Scores',         'Let AI rank your leads so your team calls the hottest ones first.'],
    ['upload', 'Import & Export',        'Bring contacts in from CSV, group them, block opt-outs and export when you need to.'],
];
$__h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ico = static fn(string $k) => '<svg aria-hidden="true"><use href="#wmc-i-' . $k . '"/></svg>';
?>
<section class="fp-section wmh wmc" id="waamini-crm">
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="wmc-i-inbox" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 13.5l2.6-7.4A2 2 0 0 1 8 4.8h8a2 2 0 0 1 1.9 1.3l2.6 7.4V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/><path d="M3.5 13.5h4.6l1.4 2.5h5l1.4-2.5h4.6"/></g></symbol>
    <symbol id="wmc-i-users" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.4"/><path d="M2.8 19.5c0-3.4 2.8-5.6 6.2-5.6s6.2 2.2 6.2 5.6"/><path d="M15.5 4.8a3.4 3.4 0 0 1 0 6.4M17.6 14.2c2.1.6 3.6 2.5 3.6 5.3"/></g></symbol>
    <symbol id="wmc-i-user" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.5" r="3.6"/><path d="M5 20c0-3.7 3.1-6 7-6s7 2.3 7 6"/></g></symbol>
    <symbol id="wmc-i-tag" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12V5a1.5 1.5 0 0 1 1.5-1.5h7l8.5 8.5-8.5 8.5z"/></g><circle cx="8" cy="8" r="1.4" fill="currentColor"/></symbol>
    <symbol id="wmc-i-note" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h10l4.5 4.5v12.5H5z"/><path d="M14.5 3.5V8.5h5M8.5 12.5h7M8.5 16h4.5"/></g></symbol>
    <symbol id="wmc-i-spark" viewBox="0 0 24 24"><path d="M12 3l2.1 5.6L19.7 11l-5.6 2.1L12 18.7l-2.1-5.6L4.3 11l5.6-2.4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M19 3.5v3M17.5 5h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
    <symbol id="wmc-i-upload" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/><path d="M12 15V4M7.5 8.5L12 4l4.5 4.5"/></g></symbol>
    <symbol id="wmc-i-download" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5"/></g></symbol>
    <symbol id="wmc-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="wmc-i-bell" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2H4.5z"/><path d="M10 21h4"/></g></symbol>
    <symbol id="wmc-i-block" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/></g></symbol>
    <symbol id="wmc-i-phone" viewBox="0 0 24 24"><path d="M21 16.4v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 1.1 3.7 2 2 0 0 1 3.1 1.5h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L7.1 9.3a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
  </svg>

  <div class="fp-wrap">
    <div class="wmh-grid">

      <!-- LEFT: heading + selectable feature list -->
      <div class="wmh-left">
        <span class="fp-badge">Features</span>
        <h2 class="wmh-title">Know every customer, close every deal</h2>
        <p class="wmh-sub">Conversations, contacts and follow-ups live together so nothing slips through. Pick a feature to see it work.</p>
        <div class="wmh-list" role="tablist" aria-label="CRM features">
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
        <div class="wmh-core" role="img" aria-label="WAAMINI CRM platform">
          <svg viewBox="0 0 400 400" aria-hidden="true">
            <defs><path id="wmc-arc" d="M99 200 A101 101 0 0 1 301 200"/></defs>
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
            <text class="band-text" text-anchor="middle"><textPath href="#wmc-arc" startOffset="50%">CRM PLATFORM</textPath></text>
            <circle class="r-disc" cx="200" cy="200" r="86"/>
            <image href="assets/img/waamini-mark.png" x="138" y="138" width="124" height="66" preserveAspectRatio="xMidYMid meet"/>
            <text class="brand" x="200" y="250" text-anchor="middle">WAAMINI</text>
          </svg>
        </div>

        <div class="wmh-wire" aria-hidden="true"><i></i><i></i><i></i></div>

        <div class="wmh-stack" aria-live="polite">

          <!-- 0 Team Inbox & Live Chat -->
          <article class="wmh-card is-active" data-card="0">
            <span class="wmh-badge" aria-hidden="true"><svg><use href="#wmc-i-check"/></svg></span>
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('inbox'); ?></span>Team Inbox &amp; Live Chat</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmc-inbox">
                <div class="wmc-row" style="--n:0"><span class="wmc-av">P</span><span class="wmc-row__txt"><b>Priya</b><em>Is the premium plan monthly?</em></span><span class="wmc-agent">Arun</span><span class="wmc-unread">2</span></div>
                <div class="wmc-row" style="--n:1"><span class="wmc-av wmc-av--b">R</span><span class="wmc-row__txt"><b>Rahul</b><em class="wmc-typing"><i></i><i></i><i></i>typing</em></span><span class="wmc-agent">Meena</span><span class="wmc-unread">1</span></div>
                <div class="wmc-row" style="--n:2"><span class="wmc-av wmc-av--c">S</span><span class="wmc-row__txt"><b>Sunrise Textiles</b><em>Payment received, thank you!</em></span><span class="wmc-agent">You</span></div>
              </div>
              <div class="wmc-status wmh-seq">
                <span class="wmh-pill" style="--n:0"><i class="wmc-dot wmc-dot--open"></i>Open</span>
                <span class="wmh-pill" style="--n:1"><i class="wmc-dot wmc-dot--pend"></i>Pending</span>
                <span class="wmh-pill" style="--n:2"><i class="wmc-dot wmc-dot--done"></i>Resolved</span>
              </div>
            </div>
            <p class="wmh-card__text">Multiple agents reply from one number. Tickets move from Open to Pending to Resolved.</p>
          </article>

          <!-- 1 Agent Assignment -->
          <article class="wmh-card" data-card="1">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('users'); ?></span>Agent Assignment</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmc-assign">
                <span class="wmc-chat"><b>New chat</b><em>Priya &middot; Pricing</em></span>
                <span class="wmh-flow"><i></i><i></i></span>
                <div class="wmc-team">
                  <span class="wmc-mate" style="--n:0"><span class="wmc-face">A</span><em>Arun</em></span>
                  <span class="wmc-mate" style="--n:1"><span class="wmc-face">M</span><em>Meena</em></span>
                  <span class="wmc-mate" style="--n:2"><span class="wmc-face">K</span><em>Karthik</em></span>
                </div>
              </div>
              <div class="wmc-access wmh-seq">
                <span class="wmh-pill" style="--n:0">Admin</span>
                <span class="wmh-pill" style="--n:1">Agent</span>
                <span class="wmh-pill" style="--n:2">View only</span>
              </div>
              <div class="wmc-rules">
                <span class="wmc-rule"><svg class="wmh-ico"><use href="#wmc-i-users"/></svg>Auto-assign<b>Round-robin</b></span>
                <span class="wmc-rule"><svg class="wmh-ico"><use href="#wmc-i-tag"/></svg>Keyword<b>&ldquo;price&rdquo; &rarr; Sales</b></span>
              </div>
            </div>
            <p class="wmh-card__text">Assign chats to teammates and set access levels so everyone sees only what they should.</p>
          </article>

          <!-- 2 Labels & Outcomes -->
          <article class="wmh-card" data-card="2">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('tag'); ?></span>Labels &amp; Outcomes</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmc-contact">
                <span class="wmc-av">P</span>
                <span class="wmc-contact__txt"><b>Priya Sharma</b><em>+91 98XXX XX210</em></span>
                <span class="wmc-labels">
                  <span class="wmc-label wmc-label--hot" style="--n:0">Hot lead</span>
                  <span class="wmc-label" style="--n:1">Follow-up</span>
                  <span class="wmc-label wmc-label--vip" style="--n:2">VIP</span>
                </span>
              </div>
              <div class="wmc-outcome">
                <span class="wmc-out wmc-out--won"><svg class="wmh-ico"><use href="#wmc-i-check"/></svg>Won</span>
                <span class="wmc-out wmc-out--lost">Lost</span>
                <span class="wmc-pipe"><i style="--w:46%"></i><i style="--w:30%"></i><i style="--w:24%"></i></span>
              </div>
              <div class="wmc-legend"><span><i class="wmc-sw wmc-sw--won"></i>Won 46%</span><span><i class="wmc-sw wmc-sw--fup"></i>Follow-up 30%</span><span><i class="wmc-sw wmc-sw--lost"></i>Lost 24%</span></div>
            </div>
            <p class="wmh-card__text">Tag conversations and mark outcomes such as won or lost to understand your pipeline.</p>
          </article>

          <!-- 3 Notes & Reminders -->
          <article class="wmh-card" data-card="3">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('note'); ?></span>Notes &amp; Reminders</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmc-note">
                <span class="wmc-note__head"><svg class="wmh-ico"><use href="#wmc-i-note"/></svg>Internal note<em>Only your team sees this</em></span>
                <span class="wmc-note__line" style="--n:0">Wants the premium plan for 5 outlets.</span>
                <span class="wmc-note__line" style="--n:1">Call after 11 AM, prefers Tamil.</span>
              </div>
              <div class="wmc-remind">
                <span class="wmc-bell"><svg><use href="#wmc-i-bell"/></svg></span>
                <span class="wmc-remind__txt"><b>Follow-up reminder</b><em>Tomorrow, 11:00 AM &middot; Arun</em></span>
                <span class="wmc-set"><svg class="wmh-ico"><use href="#wmc-i-check"/></svg>Set</span>
              </div>
              <div class="wmc-trail wmh-seq">
                <span class="wmh-pill" style="--n:0"><svg class="wmh-ico"><use href="#wmc-i-phone"/></svg>Called</span>
                <span class="wmh-pill" style="--n:1"><svg class="wmh-ico"><use href="#wmc-i-note"/></svg>Quote sent</span>
                <span class="wmh-pill" style="--n:2"><svg class="wmh-ico"><use href="#wmc-i-bell"/></svg>Reminder set</span>
              </div>
            </div>
            <p class="wmh-card__text">Keep internal notes on each contact and schedule follow-up reminders.</p>
          </article>

          <!-- 4 AI Lead Scores -->
          <article class="wmh-card" data-card="4">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('spark'); ?></span>AI Lead Scores</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmc-scores">
                <div class="wmc-lead" style="--n:0;--s:.92"><span class="wmc-av">P</span><b>Priya</b><span class="wmc-bar"><i></i></span><strong>92</strong><span class="wmc-hot"><svg class="wmh-ico"><use href="#wmc-i-phone"/></svg>Call first</span></div>
                <div class="wmc-lead" style="--n:1;--s:.74"><span class="wmc-av wmc-av--b">R</span><b>Rahul</b><span class="wmc-bar"><i></i></span><strong>74</strong></div>
                <div class="wmc-lead" style="--n:2;--s:.41"><span class="wmc-av wmc-av--c">D</span><b>Divya</b><span class="wmc-bar"><i></i></span><strong>41</strong></div>
              </div>
              <span class="wmc-ai"><svg class="wmh-ico"><use href="#wmc-i-spark"/></svg>Ranked by AI from chat intent, replies and activity</span>
            </div>
            <p class="wmh-card__text">Let AI rank your leads so your team calls the hottest ones first.</p>
          </article>

          <!-- 5 Import & Export -->
          <article class="wmh-card" data-card="5">
            <h3 class="wmh-card__title"><span class="wmh-card__ic"><?php echo $__ico('upload'); ?></span>Import &amp; Export</h3>
            <div class="wmh-card__art" aria-hidden="true">
              <div class="wmh-seg">
                <span class="wmh-file">CSV</span>
                <span class="wmh-flow"><i></i><i></i></span>
                <span class="wmc-count"><b>2,480</b><em>contacts added</em></span>
              </div>
              <div class="wmc-groups">
                <span class="wmh-tags wmh-seq">
                  <span class="wmh-pill" style="--n:0">Customers</span>
                  <span class="wmh-pill" style="--n:1">Leads</span>
                  <span class="wmh-pill" style="--n:2">Wholesale</span>
                </span>
                <span class="wmc-blocked"><svg class="wmh-ico"><use href="#wmc-i-block"/></svg>3 opt-outs blocked</span>
                <span class="wmc-export"><svg class="wmh-ico"><use href="#wmc-i-download"/></svg>Export</span>
              </div>
            </div>
            <p class="wmh-card__text">Bring contacts in from CSV, group them, block opt-outs and export when you need to.</p>
          </article>

        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/js/wm-hub.js?v=2" nonce="<?php echo $__hubNonce; ?>"></script>
