<?php
/* Feature hub for marketing-automation.php (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css) */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';

return [
    'title' => 'Automation that never forgets a lead',
    'sub'   => 'Build sequences and triggers once, then let them work around the clock.',
    'band'  => 'MARKETING ENGINE',
    'aria'  => 'Marketing automation features',
    'items' => [
        ['repeat', 'Drip Campaigns', 'Create multi-step sequences with delays. The engine varies message wording to feel natural.', '
          <div class="wmf-steps">
            <div class="wmf-step" style="--n:0"><b>Day 0</b><span>Welcome + brochure</span><span class="wmf-chip">Sent</span></div>
            <div class="wmf-step" style="--n:1"><b>Day 2</b><span>Book a free demo</span><span class="wmf-chip">Sent</span></div>
            <div class="wmf-step" style="--n:2"><b>Day 5</b><span>Trial ends soon</span><span class="wmf-chip wmf-chip--plain">' . $I('clock') . 'Queued</span></div>
          </div>
          <div class="wmf-note wmf-in" style="--d:1.2s;margin-top:10px">' . $I('bolt') . 'Wording varies on every send, so it never reads like a broadcast</div>'],

        ['clock', 'Lead Follow-ups', 'First and second follow-ups plus a delayed thank-you go out automatically after a chat.', '
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.2s">Thanks, I will check and get back.<small>10:02</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.9s"><span class="wmf-tagline">Follow-up 1 &middot; after 1 hr</span>Any questions on the plan? Happy to help.<small>11:02 ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1.7s"><span class="wmf-tagline">Follow-up 2 &middot; next day</span>Shall I hold the launch offer for you?<small>10:00 ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:2.5s"><span class="wmf-tagline">Thank you &middot; after 3 days</span>Thanks for choosing us, Priya!<small>10:00 ' . $I('ticks') . '</small></span>
          </div>'],

        ['send', 'Missed Call Reply', 'Send a WhatsApp message the moment you miss a call so the lead never goes cold.', '
          <div class="wmf-split" style="margin-bottom:10px">
            <div class="wmf-row wmf-row--bad wmf-in" style="--d:.1s"><span class="wmf-ic wmf-ic--bad wmf-ring" style="--g:.5s">' . $I('missed', '') . '</span><span class="wmf-txt"><b>Missed call</b><em>+91 98XXX XX451</em></span></div>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-chip wmf-chip--solid wmf-pop" style="--d:1s">' . $I('bolt') . '2 sec</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1.2s">Sorry we missed your call! How can we help you today?<small>4:31 PM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:2.2s">I wanted the price for 2 units.<small>4:32 PM</small></span>
          </div>'],

        ['chat', 'Review Requests', 'Ask for a Google review after a delay and follow up if there is no response.', '
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.2s"><span class="wmf-tagline">2 hours after the visit</span>Hope you loved it, Priya! Could you rate us on Google?<small>6:10 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split">
            <div class="wmf-stars">' . implode('', array_map(static fn($n) => '<svg style="--n:' . $n . '"><use href="#wmf-i-star"/></svg>', range(0, 4))) . '</div>
            <span class="wmf-chip wmf-pop" style="--d:1.8s;margin-left:auto">' . $I('check') . 'Review posted</span>
          </div>
          <div class="wmf-note wmf-in" style="--d:2.3s;margin-top:9px">' . $I('clock') . 'No reply in 2 days? A gentle follow-up goes out.</div>'],

        ['chart', 'A/B Testing', 'Split an audience between two variants and see which message performs better.', '
          <div class="wmf-ab">
            <div class="wmf-var wmf-in" style="--d:.1s"><span class="wmf-var__head"><i>A</i>Variant A</span><p>Flat 20% off this week.</p>
              <div class="wmf-meter"><span class="wmf-meter__top">Reply rate<b>38%</b></span><span class="wmf-meter__track"><i style="--v:.38;--d:.5s"></i></span></div></div>
            <div class="wmf-var wmf-var--win wmf-in" style="--d:.2s"><span class="wmf-chip wmf-chip--solid wmf-win wmf-pop" style="--d:1.9s">' . $I('star') . 'Winner</span><span class="wmf-var__head"><i>B</i>Variant B</span><p>Your 20% off ends tonight!</p>
              <div class="wmf-meter"><span class="wmf-meter__top">Reply rate<b>61%</b></span><span class="wmf-meter__track"><i style="--v:.61;--d:.7s"></i></span></div></div>
          </div>
          <div class="wmf-note wmf-in" style="--d:2.2s;margin-top:10px">' . $I('send') . '50 / 50 split &middot; winner goes to the rest of the list</div>'],

        ['link', 'Zapier & Webhooks', 'Connect Facebook Lead Ads, IndiaMART, Google Sheets and thousands of apps.', '
          <div class="wmf-split">
            <div class="wmf-srcs">
              <span class="wmf-src" style="--n:0;--c:#1877F2"><i></i>Facebook Lead Ads</span>
              <span class="wmf-src" style="--n:1;--c:#E53935"><i></i>IndiaMART</span>
              <span class="wmf-src" style="--n:2;--c:#0F9D58"><i></i>Google Sheets</span>
            </div>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-target"><i>' . $I('wa', '') . '</i>Instant reply</span>
          </div>
          <div class="wmf-chips" style="margin-top:12px">
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.2s">' . $I('link') . 'Webhooks</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.35s">' . $I('bolt') . 'Zapier: 5,000+ apps</span>
          </div>'],
    ],
];
