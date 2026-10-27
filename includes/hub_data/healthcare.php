<?php
/* Feature hub for healthcare.php, "Built for Clinics, Hospitals & Diagnostics" (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css).
   The page keeps its own section heading, so the hub is rendered headless ('hub_head' => false). */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$file = static fn(string $name, string $meta, float $d) => '<span class="wmf-file wmf-in" style="--d:' . $d . 's"><i>PDF</i><span>' . $name . '<em>' . $meta . '</em></span></span>';

return [
    'title' => 'Built for Clinics, Hospitals & Diagnostics',
    'sub'   => 'Improve patient outcomes by staying connected throughout their entire care journey — automatically.',
    'band'  => 'HEALTHCARE SUITE',
    'aria'  => 'Healthcare features',
    'items' => [
        ['calendar', 'Appointment Booking', 'Let patients book, reschedule, or cancel appointments 24/7 without calling the front desk. Syncs with your practice management software.', '
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.1s">Need to see Dr. Priya tomorrow<small>9:12 PM</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">Sure! Available slots with Dr. Priya (General Physician), Thu:<small>9:12 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-pop" style="--d:1.1s">' . $I('clock') . '10:30 AM</span>
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.3s;--g:1.9s">' . $I('check') . '11:15 AM booked</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop wmf-hide-sm" style="--d:1.5s;margin-left:auto">No calls needed</span>
          </div>'],

        ['file', 'Digital Lab Reports', 'Deliver lab results and prescriptions instantly via WhatsApp. Secure, fast, and highly convenient for patients and their families.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('shield', '') . '</span><span class="wmf-txt"><b>Report ready &middot; Ravi K.</b><em>CBC + Lipid profile &middot; verified by lab</em></span><span class="wmf-chip wmf-chip--solid wmf-hide-sm">Ready</span></div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">'
              . $file('CBC_Lipid_Report.pdf', '3 pages &middot; 420 KB', .8) . '
              Hi Ravi, your reports are ready. Reply DOCTOR to book a review.<small>4:05 PM ' . $I('ticks') . '</small></span>
          </div>'],

        ['pill', 'Medicine Reminders', 'Automatically send medication schedules and follow-up reminders to ensure treatment adherence and complete patient recovery.', '
          <div class="wmf-steps" style="--loop:6s;--step:2s;margin-bottom:10px">
            <div class="wmf-step" style="--n:0"><b>8:00</b><span>Metformin 500 mg &middot; after food</span><span class="wmf-chip wmf-hide-sm">Morning</span></div>
            <div class="wmf-step" style="--n:1"><b>2:00</b><span>Vitamin D3 &middot; 1 tablet</span><span class="wmf-chip wmf-hide-sm">Afternoon</span></div>
            <div class="wmf-step" style="--n:2"><b>9:00</b><span>Metformin 500 mg &middot; after food</span><span class="wmf-chip wmf-hide-sm">Night</span></div>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.8s"><span class="wmf-tagline">Daily reminder</span>Time for your Metformin 500 mg. Reply TAKEN once done.<small>8:00 AM ' . $I('ticks') . '</small></span>
          </div>'],

        ['shield', 'Vaccination Alerts', 'Automate immunization schedules for pediatric or travel clinics. Send timely alerts to parents when the next dose is due.', '
          <div class="wmf-stack wmf-seq-hi" style="--loop:6s;--step:2s;margin-bottom:10px">
            <div class="wmf-row" style="--n:0"><span class="wmf-cal"><b>Week</b><span>6</span></span><span class="wmf-txt"><b>DTaP, IPV, Hep B</b><em>Given &middot; 12 May</em></span><span class="wmf-chip wmf-chip--plain wmf-hide-sm">Done</span></div>
            <div class="wmf-row" style="--n:1"><span class="wmf-cal"><b>Week</b><span>10</span></span><span class="wmf-txt"><b>DTaP, IPV, Rotavirus</b><em>Due 23 Jun &middot; alert sent</em></span><span class="wmf-chip wmf-chip--warn wmf-hide-sm">Due</span></div>
            <div class="wmf-row" style="--n:2"><span class="wmf-cal"><b>Week</b><span>14</span></span><span class="wmf-txt"><b>DTaP, IPV, PCV</b><em>Reminder scheduled</em></span><span class="wmf-chip wmf-hide-sm">Scheduled</span></div>
          </div>'],

        ['link', 'Teleconsultation Links', 'Automatically send video consultation links before each appointment. Enable remote care without patients downloading extra apps.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('calendar', '') . '</span><span class="wmf-txt"><b>Video consult &middot; 6:00 PM</b><em>Dr. Arun &middot; Dermatology</em></span><span class="wmf-chip wmf-hide-sm">Google Meet</span></div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s"><span class="wmf-tagline">15 min before</span>Your consultation with Dr. Arun starts at 6:00 PM. Join: meet.google.com/xyz-abcd-efg<small>5:45 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.4s;--g:1.8s">' . $I('check') . 'Patient joined</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.6s;margin-left:auto">No app to install</span>
          </div>'],

        ['star', 'Patient Feedback', 'Collect CSAT ratings and reviews automatically after every visit. Build your online reputation while catching issues before they escalate.', '
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.1s">Thanks for visiting City Care Clinic today. How was your experience? Rate 1&ndash;5<small>7:30 PM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.8s">5 &mdash; very quick and friendly<small>7:34 PM</small></span>
          </div>
          <div class="wmf-tiles">
            <div class="wmf-tile wmf-in" style="--d:1.1s"><b>4.8&#9733;</b><span>Avg rating</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.25s"><b>62%</b><span>Replied</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.4s"><b>+120</b><span>Google reviews</span></div>
          </div>'],
    ],
];
