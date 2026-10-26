<?php
/* Feature hub for real-estate.php, "Built for Premium Real Estate Developers" (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css).
   The page keeps its own section heading, so the hub is rendered headless ('hub_head' => false). */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$file = static fn(string $name, string $meta, float $d) => '<span class="wmf-file wmf-in" style="--d:' . $d . 's"><i>PDF</i><span>' . $name . '<em>' . $meta . '</em></span></span>';

return [
    'title' => 'Built for Premium Real Estate Developers',
    'sub'   => 'Transform how you sell properties with high-fidelity WhatsApp communication that converts inquiries into closures.',
    'band'  => 'REAL ESTATE SUITE',
    'aria'  => 'Real estate features',
    'items' => [
        ['bolt', 'Instant Lead Capture', 'Never miss a lead from Facebook, Google Ads, or Magicbricks. Send an immediate WhatsApp response with property details and availability.', '
          <div class="wmf-split" style="margin-bottom:10px">
            <div class="wmf-srcs">
              <span class="wmf-src" style="--n:0;--c:#1877F2"><i></i>Facebook Ads</span>
              <span class="wmf-src" style="--n:1;--c:#F4B400"><i></i>Google Ads</span>
              <span class="wmf-src" style="--n:2;--c:#E53935"><i></i>Magicbricks</span>
            </div>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-target"><i>' . $I('wa', '') . '</i>Reply in 5 sec</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1s"><span class="wmf-tagline">Green Meadows &middot; OMR</span>Hi Rahul! 2 &amp; 3 BHK from <b>&#8377;68 L</b>. 12 units left. Want a site visit?<small>10:01 AM ' . $I('ticks') . '</small></span>
          </div>'],

        ['calendar', 'Site Visit Automation', 'Let leads book site visits 24/7. Automatically send reminders, Google Maps locations, and contact info for the property manager.', '
          <div class="wmf-pills wmh-seq" style="--step:1s;--loop:4s;margin-bottom:10px">
            <span class="wmh-pill" style="--n:0">Sat 11 AM</span><span class="wmh-pill" style="--n:1">Sat 4 PM</span><span class="wmh-pill" style="--n:2">Sun 11 AM</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s"><span class="wmf-tagline">Visit booked &middot; Sat 11 AM</span><span class="wmf-file"><i style="background:#1A73E8">' . $I('pin', '') . '</i><span>Green Meadows, OMR<em>Open in Google Maps</em></span></span>Ask for Meena (site manager): +91 98XXX XX310<small>9:12 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop" style="--d:1.5s">' . $I('check') . 'Visit confirmed</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.7s;margin-left:auto">' . $I('bell') . 'Reminder 1 day before</span>
          </div>'],

        ['file', 'Brochure & Floor Plan Sharing', 'Share high-resolution brochures, floor plans, and price sheets instantly after every inquiry — no more manual emails or delays.', '
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.1s">Please share the 3 BHK details.<small>4:20 PM</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">'
              . $file('Green_Meadows_Brochure.pdf', '12 pages &middot; 4.2 MB', .8)
              . $file('Floor_Plan_3BHK.pdf', '2 pages &middot; 1.1 MB', 1.1)
              . $file('Price_Sheet_Phase2.pdf', '1 page &middot; 220 KB', 1.4) . '
              Everything for the 3 BHK, Rahul.<small>4:20 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-chips" style="margin-top:9px"><span class="wmf-chip wmf-pop" style="--d:1.9s">' . $I('bolt') . 'Sent automatically in 10 sec</span></div>'],

        ['users', 'Smart Agent Routing', 'Route high-intent leads to your top agents automatically based on location, budget, or preferred property type for faster closing.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('bolt', '') . '</span><span class="wmf-txt"><b>High-intent lead &middot; Priya</b><em>Asked for a site visit + home loan</em></span><span class="wmf-chip wmf-chip--bad wmf-hide-sm">Hot</span></div>
          <div class="wmf-pills wmh-seq" style="--step:1.2s;--loop:4.8s;margin-bottom:10px">
            <span class="wmh-pill" style="--n:0">' . $I('pin') . 'OMR</span><span class="wmh-pill" style="--n:1">&#8377;70&ndash;90 L</span><span class="wmh-pill" style="--n:2">' . $I('building') . '3 BHK</span>
          </div>
          <div class="wmf-split">
            <div class="wmf-row wmf-in" style="--d:1.2s"><span class="wmf-ic">' . $I('users', '') . '</span><span class="wmf-txt"><b>Karthik &middot; OMR specialist</b><em>Closes 3 BHK deals fastest</em></span></div>
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.8s;--g:2.2s">' . $I('check') . 'Assigned</span>
          </div>'],

        ['send', 'Project Launch Broadcasts', 'Announce new property launches to your entire opt-in list with personalized WhatsApp messages, images, and payment plan documents.', '
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.2s;max-width:92%"><span class="wmf-prod__img" style="--bg:#E6F0FB;--fg:#2E7DD7;height:58px;border-radius:7px;margin-bottom:6px">' . $I('building', '') . '</span><span class="wmf-tagline">New launch &middot; Phase 2</span>Hi Priya, Green Meadows Phase 2 is live! Pre-launch price with a 20:80 payment plan.<small>11:00 AM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-tiles">
            <div class="wmf-tile wmf-in" style="--d:1s"><b>3,200</b><span>Sent</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.15s"><b>88%</b><span>Read</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.3s"><b>140</b><span>Visits booked</span></div>
          </div>'],

        ['home', 'Post-Sale Relationship', 'Maintain relationships after booking with possession updates, registry reminders, and referral programs to generate word-of-mouth leads.', '
          <div class="wmf-steps" style="--loop:6s;--step:2s">
            <div class="wmf-step" style="--n:0"><b>Update</b><span>Tower B: 9th floor slab done</span><span class="wmf-chip wmf-hide-sm">' . $I('building') . 'Photos</span></div>
            <div class="wmf-step" style="--n:1"><b>Registry</b><span>Registration on 12 Aug, 11 AM</span><span class="wmf-chip wmf-hide-sm">' . $I('bell') . 'Reminder</span></div>
            <div class="wmf-step" style="--n:2"><b>Referral</b><span>Refer a friend, earn &#8377;50,000</span><span class="wmf-chip wmf-hide-sm">' . $I('gift') . 'Reward</span></div>
          </div>
          <div class="wmf-note wmf-in" style="--d:1.2s;margin-top:10px">' . $I('users') . 'Happy buyers become your best source of new leads</div>'],
    ],
];
