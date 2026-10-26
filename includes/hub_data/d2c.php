<?php
/* Feature hub for ecommerce.php, "Built for Hyper-Growth D2C Brands" (rendered by includes/feature_hub.php, art kit: assets/css/wm-feat.css) */
$I = static fn(string $k, string $c = 'wmh-ico') => '<svg class="' . $c . '"><use href="#wmf-i-' . $k . '"/></svg>';
$prod = static fn(int $n, string $icon, string $name, string $price, string $stock, string $bg, string $fg) =>
    '<div class="wmf-prod wmf-in" style="--d:' . (0.1 + $n * 0.15) . 's"><div class="wmf-prod__img" style="--bg:' . $bg . ';--fg:' . $fg . '"><svg><use href="#wmf-i-' . $icon . '"/></svg></div>'
    . '<div class="wmf-prod__body"><b>' . $name . '</b><span>' . $price . '</span><em>' . $stock . '</em></div></div>';

return [
    'title' => 'Built for Hyper-Growth D2C Brands',
    'sub'   => "Stop losing customers to crowded email inboxes. Reach them where they're most active: WhatsApp.",
    'band'  => 'D2C GROWTH ENGINE',
    'aria'  => 'E-commerce features',
    'items' => [
        ['cart', 'Abandoned Cart Recovery', 'Automatically send personalized WhatsApp nudges with discount codes to users who leave items in their cart. Recover up to 40% of lost sales.', '
          <div class="wmf-split" style="margin-bottom:10px">
            <div class="wmf-row wmf-in" style="--d:.1s"><span class="wmf-ic wmf-ic--warn">' . $I('cart', '') . '</span><span class="wmf-txt"><b>Cart left behind</b><em>2 items &middot; &#8377;2,350</em></span></div>
            <span class="wmh-flow"><i></i><i></i></span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:.9s">' . $I('clock') . '30 min</span>
          </div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:1.1s">Still thinking it over, Priya? Your cart is saved. Use <b>COMEBACK10</b> for 10% off.<small>8:15 PM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:1.9s">Perfect, ordering now!<small>8:17 PM</small></span>
          </div>
          <div class="wmf-chips" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:2.6s;--g:3s">' . $I('check') . '&#8377;2,350 recovered</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:2.8s">Up to 40% of lost sales</span>
          </div>'],

        ['box', 'Order & Shipping Alerts', 'Send automated order confirmations, shipping updates, and delivery alerts. Reduce "Where is my order?" tickets by 70% without extra staff.', '
          <div class="wmf-stepper" style="--c:4">
            <span class="wmf-node" style="--n:0"><i>' . $I('check', '') . '</i><em>Confirmed</em></span>
            <span class="wmf-node" style="--n:1"><i>' . $I('box', '') . '</i><em>Packed</em></span>
            <span class="wmf-node" style="--n:2"><i>' . $I('truck', '') . '</i><em>Shipped</em></span>
            <span class="wmf-node" style="--n:3"><i>' . $I('home', '') . '</i><em>Delivered</em></span>
          </div>
          <div class="wmf-chat" style="margin-top:12px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s"><span class="wmf-tagline">Order #1042 &middot; shipped</span>Your order is on its way! Track it live: trk.store/1042<small>9:30 AM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-chips" style="margin-top:9px"><span class="wmf-chip wmf-pop" style="--d:1.4s">' . $I('chat') . '&ldquo;Where is my order?&rdquo; tickets down 70%</span></div>'],

        ['card', 'COD to Prepaid Conversion', 'Incentivize COD customers to switch to prepaid with instant WhatsApp coupons. Significantly reduce RTO and last-mile delivery costs.', '
          <div class="wmf-row wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--warn">' . $I('card', '') . '</span><span class="wmf-txt"><b>Order #1043 &middot; Cash on delivery</b><em>&#8377;1,499 &middot; Rahul, Coimbatore</em></span><span class="wmf-chip wmf-chip--warn wmf-hide-sm">COD</span></div>
          <div class="wmf-chat">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">Pay online now and get <b>&#8377;50 off</b> plus faster dispatch.<small>11:02 AM ' . $I('ticks') . '</small></span>
            <span class="wmf-bub wmf-bub--in wmf-in" style="--d:1.4s">Done, paid by UPI.<small>11:04 AM</small></span>
          </div>
          <div class="wmf-split" style="margin-top:9px">
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:2s;--g:2.4s">' . $I('check') . 'Switched to prepaid</span>
            <span class="wmf-chip wmf-chip--plain wmf-pop" style="--d:2.2s;margin-left:auto">' . $I('truck') . 'Lower RTO risk</span>
          </div>'],

        ['star', 'Automated Review Collection', 'Send a WhatsApp message 3 days after delivery requesting a photo review. Build 5x more social proof with zero manual effort.', '
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.2s"><span class="wmf-tagline">3 days after delivery</span>Loving your new Cotton Kurta, Priya? Share a photo review and get 50 points!<small>6:10 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-split">
            <div class="wmf-stars">' . implode('', array_map(static fn($n) => '<svg style="--n:' . $n . '"><use href="#wmf-i-star"/></svg>', range(0, 4))) . '</div>
            <span class="wmf-chip wmf-pop" style="--d:1.8s;margin-left:auto">' . $I('check') . 'Photo review added</span>
          </div>
          <div class="wmf-note wmf-in" style="--d:2.3s;margin-top:9px">' . $I('star') . '5x more reviews, collected without lifting a finger</div>'],

        ['tag', 'WhatsApp Product Catalog', 'Showcase your entire product range inside WhatsApp. Let customers browse, select, and place orders without leaving the app.', '
          <div class="wmf-prods">'
            . $prod(0, 'shirt', 'Cotton Kurta', '&#8377;899', '24 in stock', '#E3F7EE', '#128C7E')
            . $prod(1, 'bag', 'Jute Tote', '&#8377;449', '8 in stock', '#FFF1DC', '#B45309')
            . $prod(2, 'watch', 'Smart Watch', '&#8377;2,499', 'Low stock: 3', '#E6F0FB', '#2E7DD7') . '
          </div>
          <div class="wmf-split" style="margin-top:10px">
            <span class="wmf-note wmf-in" style="--d:.8s">' . $I('cart') . 'Browse, pick and order inside the chat</span>
            <span class="wmf-chip wmf-chip--solid wmf-pop wmf-glow" style="--d:1.1s;--g:1.6s;margin-left:auto">' . $I('cart') . 'Add to cart</span>
          </div>'],

        ['repeat', 'Re-engagement Campaigns', 'Win back dormant customers with personalized offers sent at the perfect time. Broadcast new arrivals to opted-in customers in one click.', '
          <div class="wmf-row wmf-row--hi wmf-in" style="--d:.1s;margin-bottom:10px"><span class="wmf-ic wmf-ic--solid">' . $I('repeat', '') . '</span><span class="wmf-txt"><b>No order in 60+ days</b><em>1,240 opted-in customers</em></span><span class="wmf-chip wmf-hide-sm">Segment</span></div>
          <div class="wmf-chat" style="margin-bottom:10px">
            <span class="wmf-bub wmf-bub--out wmf-in" style="--d:.6s">We miss you, Priya! New arrivals just landed. Here is 15% off, this week only.<small>7:00 PM ' . $I('ticks') . '</small></span>
          </div>
          <div class="wmf-tiles">
            <div class="wmf-tile wmf-in" style="--d:1.2s"><b>1,240</b><span>Sent</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.35s"><b>91%</b><span>Read</span></div>
            <div class="wmf-tile wmf-in" style="--d:1.5s"><b>186</b><span>Orders</span><em>won back</em></div>
          </div>'],
    ],
];
