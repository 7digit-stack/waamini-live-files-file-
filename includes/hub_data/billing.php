<?php
/* Feature hub for gst-invoicing.php (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css) */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$brand = '<div class="wmf-doc__brand"><i>S</i><span><b>Sunrise Textiles</b><em>GSTIN 33ABCDE1234F1Z5</em></span></div>';

return [
    'title' => 'Billing that keeps up with your chats',
    'sub'   => 'Close the sale and collect payment without leaving the conversation.',
    'band'  => 'BILLING PLATFORM',
    'aria'  => 'GST invoicing features',
    'items' => [
        ['file', 'GST Invoices', 'Create, view and print GST invoices with your company details and tax settings.', '
          <div class="wmf-doc">
            <div class="wmf-doc__head">' . $brand . '<div class="wmf-doc__no"><b>Tax invoice</b><span>INV-0231</span></div></div>
            <div class="wmf-lines">
              <span class="wmf-line" style="--n:0">Cotton fabric, 40 m<b>&#8377;12,000</b></span>
              <span class="wmf-line" style="--n:1">CGST 9%<b>&#8377;1,080</b></span>
              <span class="wmf-line" style="--n:2">SGST 9%<b>&#8377;1,080</b></span>
            </div>
            <div class="wmf-total">Total (incl. GST)<b class="wmf-in" style="--d:1.1s">&#8377;14,160</b></div>
          </div>
          <div class="wmf-chips" style="margin-top:9px"><span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.4s">' . $I('file') . 'Print</span><span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.5s">' . $I('download') . 'PDF</span></div>'],

        ['upload', 'PDF Quotes', 'Send professional branded quotes with your logo and terms.', '
          <div class="wmf-doc">
            <div class="wmf-doc__head">' . $brand . '<div class="wmf-doc__no"><b>Quotation</b><span>QT-0087</span></div></div>
            <div class="wmf-lines">
              <span class="wmf-line" style="--n:0">Premium plan, 5 outlets<b>&#8377;45,000</b></span>
              <span class="wmf-line" style="--n:1">Setup &amp; training<b>&#8377;5,000</b></span>
            </div>
            <div class="wmf-total">Quote total<b class="wmf-in" style="--d:.9s">&#8377;50,000</b></div>
          </div>
          <div class="wmf-chips" style="margin-top:9px">
            <span class="wmf-chip wmf-pop" style="--d:1.2s">' . $I('clock') . 'Valid 15 days</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.35s">' . $I('file') . 'Terms included</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:1.5s">' . $I('tag') . 'Your logo</span>
          </div>'],

        ['link', 'Payment Links', 'Share UPI or gateway payment links and mark payments as paid.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:9px"><span class="wmf-ic wmf-ic--solid">' . $I('link', '') . '</span><span class="wmf-txt"><b>pay.waamini.com/INV-0231</b><em>&#8377;14,160 &middot; Sunrise Textiles</em></span><span class="wmf-chip wmf-chip--warn wmf-hide-sm">Pending</span></div>
          <div class="wmf-pills wmh-seq" style="--step:1s;--loop:4s;margin-bottom:10px">
            <span class="wmh-pill" style="--n:0">UPI</span><span class="wmh-pill" style="--n:1">Gateway</span><span class="wmh-pill" style="--n:2">Bank transfer</span>
          </div>
          <div class="wmf-split">
            <span class="wmf-note wmf-in" style="--d:1s">' . $I('check') . 'Payment received 2:14 PM</span>
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.6s;--g:2s;margin-left:auto">' . $I('check') . 'Marked paid</span>
          </div>'],

        ['send', 'Send on WhatsApp', "Deliver invoices and quotes to the customer's chat instantly.", '
          <div class="wmf-split" style="margin-bottom:10px">
            <span class="wmh-file wmf-in" style="--d:.1s">PDF</span>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-chip wmf-chip--solid wmf-pop" style="--d:.8s">' . $I('send') . 'Sent in chat</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1s"><span class="wmf-file"><i>PDF</i><span>Invoice_INV-0231.pdf<em>1 page &middot; 84 KB</em></span></span>Hi Priya, your invoice is attached. Pay here: pay.waamini.com/INV-0231<small>2:05 PM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:2s">Received, paying now. Thanks!<small>2:07 PM</small></span>
          </div>'],

        ['chart', 'Revenue Insights', 'Track invoices, payments and revenue from your dashboard.', '
          <div class="wmf-tiles" style="margin-bottom:8px">
            <div class="wmf-tile wmf-in" style="--d:.1s"><b>&#8377;3.4L</b><span>Invoiced</span><em>this month</em></div>
            <div class="wmf-tile wmf-in" style="--d:.2s"><b>&#8377;2.9L</b><span>Collected</span><em>85%</em></div>
            <div class="wmf-tile wmf-in" style="--d:.3s"><b>6</b><span>Unpaid</span><em style="color:var(--wmf-amber)">&#8377;51K due</em></div>
          </div>
          <div class="wmf-chart">' . implode('', array_map(static fn($n, $h) => '<i style="--n:' . $n . ';--h:' . $h . '%"></i>', range(0, 6), [45, 40, 58, 52, 66, 74, 88])) . '</div>'],

        ['shield', 'Opt-out & Trust', 'Respect customer opt-outs and keep billing messages compliant.', '
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:.2s">STOP<small>11:20 AM</small></span>
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1s">You are unsubscribed. Reply START to join again.<small>11:20 AM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split">
            <div class="wmf-row wmf-row--bad wmf-in" style="--d:1.6s"><span class="wmf-ic wmf-ic--bad">' . $I('block', '') . '</span><span class="wmf-txt"><b>+91 98XXX XX451</b><em>Added to opt-out list</em></span></div>
            <span class="wmf-chip wmf-pop" style="--d:2.2s">' . $I('shield') . 'Compliant</span>
          </div>'],
    ],
];
