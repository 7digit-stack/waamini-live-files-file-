<?php
/* Feature hub for whatsapp-commerce.php (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css) */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$prod = static fn(int $n, string $icon, string $name, string $price, string $stock, string $bg, string $fg) =>
    '<div class="wmf-prod wmf-in" style="--d:' . (0.1 + $n * 0.15) . 's"><div class="wmf-prod__img" style="--bg:' . $bg . ';--fg:' . $fg . '"><svg><use href="#wmf-i-' . $icon . '"/></svg></div>'
    . '<div class="wmf-prod__body"><b>' . $name . '</b><span>' . $price . '</span><em>' . $stock . '</em></div></div>';

return [
    'title' => 'A complete store inside WhatsApp',
    'sub'   => 'From browsing to payment to loyalty, every step happens in one conversation.',
    'band'  => 'COMMERCE PLATFORM',
    'aria'  => 'WhatsApp commerce features',
    'items' => [
        ['tag', 'Catalog & Products', 'Manage products, prices, stock and images, and share them in chat.', '
          <div class="wmf-prods">'
            . $prod(0, 'shirt', 'Cotton Kurta', '&#8377;899', '24 in stock', '#E3F7EE', '#128C7E')
            . $prod(1, 'bag', 'Jute Tote', '&#8377;449', '8 in stock', '#FFF1DC', '#B45309')
            . $prod(2, 'watch', 'Smart Watch', '&#8377;2,499', 'Low stock: 3', '#E6F0FB', '#2E7DD7') . '
          </div>
          <div class="wmf-split" style="margin-top:10px">
            <span class="wmf-note wmf-in" style="--d:.8s">' . $I('tag') . 'Prices, stock and photos in one place</span>
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.1s;--g:1.6s;margin-left:auto">' . $I('send') . 'Share in chat</span>
          </div>'],

        ['card', 'Checkout & Payments', 'Collect UPI and gateway payments with payment links and live checkout.', '
          <div class="wmf-doc">
            <div class="wmf-lines" style="border-top:0;padding-top:0">
              <span class="wmf-line" style="--n:0">Cotton Kurta &times; 1<b>&#8377;899</b></span>
              <span class="wmf-line" style="--n:1">Jute Tote &times; 1<b>&#8377;449</b></span>
              <span class="wmf-line" style="--n:2">Delivery<b>&#8377;0</b></span>
            </div>
            <div class="wmf-total">Total<b>&#8377;1,348</b></div>
            <span class="wmf-stamp" style="--d:2.4s">PAID</span>
          </div>
          <div class="wmf-pills wmh-seq" style="--step:.6s;--loop:6s;margin-top:10px">
            <span class="wmh-pill" style="--n:0">UPI</span><span class="wmh-pill" style="--n:1">Card</span><span class="wmh-pill" style="--n:2">EMI</span><span class="wmh-pill" style="--n:3">Pay link</span>
          </div>'],

        ['cart', 'Cart Recovery', 'Remind shoppers about abandoned carts automatically and win back lost sales.', '
          <div class="wmf-split" style="margin-bottom:10px">
            <div class="wmf-row wmf-in" style="--d:.1s"><span class="wmf-ic wmf-ic--warn">' . $I('cart', '') . '</span><span class="wmf-txt"><b>Cart left behind</b><em>2 items &middot; &#8377;2,350</em></span></div>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:.9s">' . $I('clock') . 'after 1 hr</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1.1s">You left something in your cart, Priya. It is still saved for you.<small>8:15 PM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:1.9s">Oh yes, placing the order now!<small>8:17 PM</small></span>
          </div>
          <div class="wmf-chips" style="margin-top:9px"><span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:2.6s;--g:3s">' . $I('check') . '&#8377;2,350 recovered</span></div>'],

        ['send', 'Order Tracking', 'Send order confirmations and shipping updates without lifting a finger.', '
          <div class="wmf-stepper" style="--c:4">
            <span class="wmf-node" style="--n:0"><i>' . $I('check', '') . '</i><em>Confirmed</em></span>
            <span class="wmf-node" style="--n:1"><i>' . $I('box', '') . '</i><em>Packed</em></span>
            <span class="wmf-node" style="--n:2"><i>' . $I('truck', '') . '</i><em>Shipped</em></span>
            <span class="wmf-node" style="--n:3"><i>' . $I('home', '') . '</i><em>Delivered</em></span>
          </div>
          <div class="wmf-chat" style="margin-top:12px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s"><span class="wmf-tagline">Order #1042</span>Your order is out for delivery and arrives today by 6 PM.<small>9:30 AM ' . $I('ticks') . '</small></span>
          </div>'],

        ['gift', 'Loyalty Points', 'Reward customers with points per message or order and redeem with automatic reward messages.', '
          <div class="wmf-split" style="margin-bottom:10px">
            <span class="wmf-big wmf-pop" style="--d:.3s;color:var(--wmh-accent)">+50 pts</span>
            <span class="wmf-note wmf-in" style="--d:.5s">for order #1042</span>
          </div>
          <div class="wmf-meter wmf-in" style="--d:.6s"><span class="wmf-meter__top">Priya&rsquo;s points<b>300 / 300</b></span><span class="wmf-meter__track"><i style="--v:1;--d:.9s"></i></span></div>
          <div class="wmf-chat" style="margin-top:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:2s"><span class="wmf-tagline">Reward unlocked</span>Congrats Priya! &#8377;100 off is waiting on your next order.<small>9:31 AM ' . $I('ticks') . '</small></span>
          </div>'],

        ['chart', 'Orders & Reports', 'Track orders, revenue and catalog performance from one dashboard.', '
          <div class="wmf-tiles" style="margin-bottom:8px">
            <div class="wmf-tile wmf-in" style="--d:.1s"><b>128</b><span>Orders</span><em>+18%</em></div>
            <div class="wmf-tile wmf-in" style="--d:.2s"><b>&#8377;1.8L</b><span>Revenue</span><em>+24%</em></div>
            <div class="wmf-tile wmf-in" style="--d:.3s"><b>&#8377;1,420</b><span>Avg order</span><em>+6%</em></div>
          </div>
          <div class="wmf-chart">' . implode('', array_map(static fn($n, $h) => '<i style="--n:' . $n . ';--h:' . $h . '%"></i>', range(0, 6), [38, 52, 44, 63, 58, 77, 92])) . '</div>'],
    ],
];
