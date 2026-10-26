<?php
/* Feature hub for smart-reminders.php (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css) */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$confetti = implode('', array_map(static fn($x, $c, $d) => '<i style="--x:' . $x . '%;--c:' . $c . ';--d:' . $d . 's"></i>',
    [8, 22, 37, 51, 66, 80, 92], ['#25D366', '#F5B301', '#2E7DD7', '#E53935', '#25D366', '#F5B301', '#9B5DE5'], [.2, .9, .5, 1.3, .1, .7, 1.1]));

return [
    'title' => 'One platform, many reminder packs',
    'sub'   => 'Pick the packs that fit your business and switch them on.',
    'band'  => 'REMINDER PACKS',
    'aria'  => 'Reminder packs',
    'items' => [
        ['gift', 'Birthday Wishes', "Send personal greetings and offers on every customer's birthday.", '
          <div class="wmf-confetti">' . $confetti . '
            <div class="wmf-split" style="margin-bottom:10px">
              <span class="wmf-cal wmf-pop" style="--d:.1s"><b>Mar</b><span>14</span></span>
              <span class="wmf-txt wmf-in" style="--d:.3s"><b>Priya&rsquo;s birthday</b><em>Sent automatically at 9:00 AM</em></span>
              <span class="wmf-chip wmf-chip--solid wmf-pop" style="--d:.6s">' . $I('gift') . 'Auto</span>
            </div>
            <div class="wmf-chat">
              <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.9s">Happy birthday, Priya! Enjoy <b>15% off</b> anything this week. Code BDAY15<small>9:00 AM ' . $I('ticks') . '</small></span>
              <span class="wmf-bub wmf-bub--in wmf-in" style="--d:1.8s">Aww, thank you so much!<small>9:04 AM</small></span>
            </div>
          </div>'],

        ['calendar', 'Festival Campaigns', 'Use the festival calendar to schedule greetings for every occasion.', '
          <div class="wmf-stack wmf-seq-hi" style="--loop:6s;--step:2s">
            <div class="wmf-row" style="--n:0"><span class="wmf-cal"><b>Jan</b><span>14</span></span><span class="wmf-txt"><b>Pongal</b><em>Greeting + 10% festive offer</em></span><span class="wmf-chip">' . $I('check') . 'Scheduled</span></div>
            <div class="wmf-row" style="--n:1"><span class="wmf-cal"><b>Nov</b><span>01</span></span><span class="wmf-txt"><b>Diwali</b><em>Greeting to all customers</em></span><span class="wmf-chip">' . $I('check') . 'Scheduled</span></div>
            <div class="wmf-row" style="--n:2"><span class="wmf-cal"><b>Dec</b><span>25</span></span><span class="wmf-txt"><b>Christmas</b><em>Greeting + gift voucher</em></span><span class="wmf-chip wmf-chip--plain">Draft</span></div>
          </div>'],

        ['file', 'School Fee Reminders', 'Remind parents about upcoming and overdue fees with polite, timely messages.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:9px"><span class="wmf-ic wmf-ic--solid">' . $I('school', '') . '</span><span class="wmf-txt"><b>Term 2 fee &middot; Aarav, Class 6</b><em>&#8377;18,500 &middot; due 10 Jul</em></span></div>
          <div class="wmf-pills wmh-seq" style="--step:1.5s;--loop:4.5s;margin-bottom:10px">
            <span class="wmh-pill" style="--n:0">7 days before</span><span class="wmh-pill" style="--n:1">On due date</span><span class="wmh-pill" style="--n:2">If overdue</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.8s">Dear parent, Aarav&rsquo;s Term 2 fee of &#8377;18,500 is due on 10 Jul. Pay online here.<small>10:00 AM ' . $I('ticks') . '</small></span>
          </div>'],

        ['clock', 'Medicine Reminders', 'Help patients stick to treatment with scheduled medication nudges.', '
          <div class="wmf-steps" style="--loop:6s;--step:2s">
            <div class="wmf-step" style="--n:0"><b>8 AM</b><span>Metformin 500 mg, after food</span><span class="wmf-chip">' . $I('check') . 'Taken</span></div>
            <div class="wmf-step" style="--n:1"><b>2 PM</b><span>Vitamin D3</span><span class="wmf-chip">' . $I('check') . 'Taken</span></div>
            <div class="wmf-step" style="--n:2"><b>9 PM</b><span>Metformin 500 mg</span><span class="wmf-chip wmf-chip--plain">' . $I('bell') . 'Due</span></div>
          </div>
          <div class="wmf-note wmf-in" style="--d:1s;margin-top:10px">' . $I('pill') . 'Patients reply &ldquo;Taken&rdquo; and the clinic sees who missed a dose</div>'],

        ['repeat', 'Vehicle Service', 'Alert customers when service, insurance or renewals are due.', '
          <div class="wmf-row wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('car', '') . '</span><span class="wmf-txt"><b>TN 63 AB 1234 &middot; Swift</b><em>Owner: Karthik</em></span></div>
          <div class="wmf-meter wmf-meter--warn wmf-in" style="--d:.3s;margin-bottom:10px"><span class="wmf-meter__top">Next service<b>4,800 / 5,000 km</b></span><span class="wmf-meter__track"><i style="--v:.96;--d:.6s"></i></span></div>
          <div class="wmf-pills wmh-seq" style="--step:1.5s;--loop:4.5s">
            <span class="wmh-pill" style="--n:0">' . $I('repeat') . 'Service</span><span class="wmh-pill" style="--n:1">' . $I('shield') . 'Insurance</span><span class="wmh-pill" style="--n:2">' . $I('file') . 'Renewal</span>
          </div>'],

        ['chat', 'Review Requests', 'Follow up after a visit and collect feedback and Google reviews.', '
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.2s"><span class="wmf-tagline">After the visit</span>Thanks for visiting us today, Meena! How was your experience?<small>7:40 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split">
            <div class="wmf-stars">' . implode('', array_map(static fn($n) => '<svg style="--n:' . $n . '"><use href="#wmf-i-star"/></svg>', range(0, 4))) . '</div>
            <span class="wmf-chip wmf-pop" style="--d:1.8s;margin-left:auto">' . $I('check') . 'Google review</span>
          </div>'],
    ],
];
