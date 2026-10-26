<?php
/* Feature hub for education.php, "Built for Schools, Colleges & Coaching Centers" (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css).
   The page keeps its own section heading, so the hub is rendered headless ('hub_head' => false). */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$file = static fn(string $name, string $meta, float $d) => '<span class="wmf-file wmf-in" style="--d:' . $d . 's"><i>PDF</i><span>' . $name . '<em>' . $meta . '</em></span></span>';

return [
    'title' => 'Built for Schools, Colleges & Coaching Centers',
    'sub'   => 'Bridge the gap between your institution and parents with instant, automated WhatsApp messaging.',
    'band'  => 'EDUCATION SUITE',
    'aria'  => 'Education features',
    'items' => [
        ['school', 'Admission Automation', 'Qualify and capture admission leads 24/7. Automatically share prospectus, fee structure, and book campus tours via WhatsApp.', '
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.1s">Admission open for Class 6?<small>9:40 PM</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">'
              . $file('Prospectus_2025-26.pdf', '16 pages &middot; 3.8 MB', .8)
              . $file('Fee_Structure_Class6.pdf', '1 page &middot; 120 KB', 1.1) . '
              Yes! Admissions for 2025&ndash;26 are open. Pick a campus tour slot below.<small>9:40 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.6s;--g:2s">' . $I('calendar') . 'Campus tour: Sat 10 AM</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.8s;margin-left:auto">' . $I('clock') . 'Answered 24/7</span>
          </div>'],

        ['card', 'Fee Reminders & Collection', 'Send automated fee reminders with Razorpay payment links. Parents pay directly in WhatsApp — improve collection by 40%.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('school', '') . '</span><span class="wmf-txt"><b>Term 2 fee &middot; Aarav, Class 6</b><em>&#8377;18,500 &middot; due 10 Jul</em></span><span class="wmf-chip wmf-chip--warn wmf-hide-sm">Due</span></div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">Dear parent, Aarav&rsquo;s Term 2 fee of &#8377;18,500 is due on 10 Jul. Pay securely: rzp.io/l/aarav-t2<small>10:00 AM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:1.4s">Paid, thank you.<small>10:06 AM</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:2s;--g:2.4s">' . $I('check') . 'Paid via Razorpay</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:2.2s;margin-left:auto">' . $I('chart') . 'Collection up 40%</span>
          </div>'],

        ['check', 'Attendance & Results', "Push daily attendance alerts and term results to parents instantly. Keep them updated on their child's progress effortlessly.", '
          <div class="wmf-stack" style="margin-bottom:10px">
            <div class="wmf-row wmf-in" style="--d:.1s"><span class="wmf-ic wmf-ic--solid">' . $I('check', '') . '</span><span class="wmf-txt"><b>Aarav marked present</b><em>Today, 9:02 AM &middot; Class 6 B</em></span><span class="wmf-chip">Present</span></div>
            <div class="wmf-row wmf-in" style="--d:.3s"><span class="wmf-ic wmf-ic--bad">' . $I('block', '') . '</span><span class="wmf-txt"><b>Diya absent</b><em>Parent alerted at 9:15 AM</em></span><span class="wmf-chip wmf-chip--bad">Absent</span></div>
          </div>
          <div class="wmf-meter wmf-in" style="--d:.7s"><span class="wmf-meter__top">Term 1 result &middot; Aarav<b>92% &middot; A+</b></span><span class="wmf-meter__track"><i style="--v:.92;--d:1s"></i></span></div>
          <div class="wmf-note wmf-in" style="--d:1.8s;margin-top:9px">' . $I('send') . 'Results reach every parent the moment they are published</div>'],

        ['bell', 'Emergency Announcements', 'Send high-priority broadcasts for school closures, holiday updates, or event reminders to all parents in seconds with 98% open rates.', '
          <div class="wmf-row wmf-row--bad wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--bad wmf-ring" style="--g:.5s">' . $I('bell', '') . '</span><span class="wmf-txt"><b>High priority: school closed tomorrow</b><em>Heavy rain alert &middot; all branches</em></span></div>
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">Dear parents, the school will remain closed tomorrow (Thu) due to heavy rain. Stay safe.<small>6:05 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-tiles">
            <div class="wmf-tile wmf-in" style="--d:1.1s"><b>2,400</b><span>Parents</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.25s"><b>98%</b><span>Opened</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.4s"><b>30 sec</b><span>To send</span></div>
          </div>'],

        ['clock', 'Online Class Reminders', 'Send Zoom or Google Meet links 15 minutes before every online class. Reduce absenteeism and keep students on track automatically.', '
          <div class="wmf-steps" style="--loop:6s;--step:2s;margin-bottom:10px">
            <div class="wmf-step" style="--n:0"><b>4:00</b><span>Physics &middot; Class 11</span><span class="wmf-chip wmf-hide-sm">Google Meet</span></div>
            <div class="wmf-step" style="--n:1"><b>5:00</b><span>Maths &middot; Class 12</span><span class="wmf-chip wmf-hide-sm">Zoom</span></div>
            <div class="wmf-step" style="--n:2"><b>6:30</b><span>JEE doubt session</span><span class="wmf-chip wmf-hide-sm">Google Meet</span></div>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.8s"><span class="wmf-tagline">15 min before class</span>Physics starts at 4:00 PM. Join: meet.google.com/abc-defg-hij<small>3:45 PM ' . $I('ticks') . '</small></span>
          </div>'],

        ['users', 'Alumni Engagement', 'Keep graduates connected with batch reunions, job fair announcements, and achievement newsletters sent via WhatsApp broadcast.', '
          <div class="wmf-stack wmf-seq-hi" style="--loop:6s;--step:2s;margin-bottom:10px">
            <div class="wmf-row" style="--n:0"><span class="wmf-cal"><b>Dec</b><span>14</span></span><span class="wmf-txt"><b>Batch 2015 reunion</b><em>Invite sent to 320 alumni</em></span><span class="wmf-chip wmf-hide-sm">' . $I('users') . 'RSVP 184</span></div>
            <div class="wmf-row" style="--n:1"><span class="wmf-cal"><b>Jan</b><span>22</span></span><span class="wmf-txt"><b>Campus job fair</b><em>40 companies hiring</em></span><span class="wmf-chip wmf-hide-sm">' . $I('send') . 'Broadcast</span></div>
            <div class="wmf-row" style="--n:2"><span class="wmf-cal"><b>Every</b><span>1st</span></span><span class="wmf-txt"><b>Achievement newsletter</b><em>Monthly to all graduates</em></span><span class="wmf-chip wmf-chip--plain wmf-hide-sm">' . $I('repeat') . 'Monthly</span></div>
          </div>'],
    ],
];
