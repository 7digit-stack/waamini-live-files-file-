<?php
/**
 * One-time seed: migrates the 10 hardcoded blog posts from blogs.php
 * into lp_blogs + lp_blog_categories.
 * Run via CLI: php landing-admin/seed-posts.php
 * Or browser: http://localhost:8888/landing-admin/seed-posts.php
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../db.php';

$log = [];

/* ── Helper: convert paragraph-array to HTML ──────────────────────────── */
function paragraphsToHtml(array $paras): string {
    $html = '';
    foreach ($paras as $p) {
        // **Label:** text  →  highlighted block with bold label
        if (preg_match('/^\*\*(.+?)\*\*(.*)$/', $p, $m)) {
            $label = htmlspecialchars(trim($m[1]), ENT_QUOTES);
            $rest  = htmlspecialchars(trim($m[2]), ENT_QUOTES);
            $html .= '<p style="padding:12px 16px;background:rgba(0,109,249,0.06);border-left:3px solid #006df9;border-radius:0 8px 8px 0;margin:0 0 14px;">'
                   . '<strong>' . $label . '</strong>' . ($rest ? ' ' . $rest : '')
                   . '</p>';
        } else {
            $html .= '<p style="margin:0 0 14px;line-height:1.8;">' . htmlspecialchars($p, ENT_QUOTES) . '</p>';
        }
    }
    return $html;
}

/* ── Helper: generate unique slug ─────────────────────────────────────── */
function makeSlug(mysqli $conn, string $title): string {
    $base = strtolower(trim($title));
    $base = preg_replace('/[^\w\s-]/u', '', $base);
    $base = preg_replace('/[\s_-]+/', '-', $base);
    $base = trim($base, '-');
    $slug = $base; $i = 1;
    while (true) {
        $st = $conn->prepare("SELECT id FROM lp_blogs WHERE slug=? LIMIT 1");
        $st->bind_param('s', $slug); $st->execute();
        if ($st->get_result()->num_rows === 0) { $st->close(); break; }
        $slug = $base . '-' . (++$i); $st->close();
    }
    return $slug;
}

/* ── Ensure all needed categories exist ──────────────────────────────── */
$extraCats = [
    'E-Commerce'  => ['e-commerce',  '#74b9ff'],
    'Real Estate' => ['real-estate', '#ff7675'],
    'Support'     => ['support',     '#55efc4'],
    'Strategy'    => ['strategy',    '#fd79a8'],
    'Team'        => ['team',        '#00cec9'],
    'Compliance'  => ['compliance',  '#e17055'],
    'Healthcare'  => ['healthcare',  '#6c5ce7'],
];
foreach ($extraCats as $name => [$slug, $color]) {
    $st = $conn->prepare("INSERT IGNORE INTO lp_blog_categories (name,slug,color) VALUES (?,?,?)");
    $st->bind_param('sss', $name, $slug, $color);
    $st->execute(); $st->close();
    if ($conn->affected_rows > 0) $log[] = ['ok', "Category created: $name"];
}

/* ── Category name → id map ──────────────────────────────────────────── */
$catMap = [];
$res = $conn->query("SELECT id, name FROM lp_blog_categories");
while ($r = $res->fetch_assoc()) $catMap[$r['name']] = (int)$r['id'];

/* ── Posts data ──────────────────────────────────────────────────────── */
$posts = [
    [
        'category'    => 'WhatsApp API',
        'title'       => 'WhatsApp Business API vs WhatsApp Business App: Which One Should You Use?',
        'excerpt'     => "Most businesses start with the WhatsApp Business App — but as you grow, it becomes a bottleneck. Here's a complete breakdown of when you should upgrade to the official API.",
        'author'      => 'Arjun Mehta',
        'date'        => '2026-03-28',
        'read_time'   => '6 min',
        'featured'    => 1,
        'cover_image' => '/assets/images/blog/whatsapp-api.jpg',
        'content'     => [
            "The WhatsApp Business App works great when you're just starting — you get a business profile, quick replies, and basic automation like away messages. But once your team grows beyond 1–2 people or your message volume exceeds 100/day, you hit hard limits.",
            "**WhatsApp Business App Limitations:** Only 1 device, no multi-agent support, no CRM integration, can't send bulk campaigns officially, and no API access.",
            "**WhatsApp Business API Advantages:** Multiple agents can access the same number simultaneously, full CRM integration, official bulk messaging via Templates, chatbot automation, read receipts at scale, and Meta Business Verification for credibility.",
            "For SMBs processing 500+ messages/month or running any kind of marketing — the API is the clear winner. The API version also protects your number from bans since you're sending through official Meta-approved channels.",
            "**Bottom line:** If you're serious about WhatsApp as a business channel, start with a platform like Waamini that gives you the API out of the box.",
        ],
    ],
    [
        'category'    => 'Automation',
        'title'       => 'How to Build a WhatsApp Chatbot That Actually Converts Sales',
        'excerpt'     => "A poorly built chatbot loses customers. A great one closes deals 24/7 without human intervention. Here's the exact framework top D2C brands use.",
        'author'      => 'Priya Nair',
        'date'        => '2026-03-25',
        'read_time'   => '8 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/chatbot-crm.jpg',
        'content'     => [
            "The average WhatsApp chatbot response rate is 80%+ — compared to email's 20%. But most chatbots are just FAQ machines that frustrate users. High-converting bots are built differently.",
            "**The 4-Step Conversion Framework:** (1) Qualify intent within 2 messages, (2) Show social proof immediately, (3) Remove friction from checkout, (4) Handle objections proactively.",
            'Your opening message should never be "How can I help you?" — that\'s too open-ended. Instead, use button choices: "Are you here for [Product A] or [Service B]?" This guides users and captures intent data.',
            "Product catalog integration is a game-changer. When a user says \"show me products,\" your bot should instantly display a browsable catalog with prices, not a plain text list.",
            "**Pro tip:** Build an abandoned cart recovery sequence. If someone browses but doesn't buy, trigger an automated message 30 minutes later with a limited-time discount code. This alone can recover 15–25% of lost sales.",
        ],
    ],
    [
        'category'    => 'Campaigns',
        'title'       => '10 WhatsApp Broadcast Best Practices to Avoid Getting Banned',
        'excerpt'     => "Sending bulk messages on WhatsApp without following Meta's guidelines is a one-way ticket to account suspension. Here's how to stay compliant and get 60%+ open rates.",
        'author'      => 'Rohit Sharma',
        'date'        => '2026-03-22',
        'read_time'   => '7 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/marketing.jpg',
        'content'     => [
            "Meta's spam detection is AI-powered and sophisticated. It tracks user complaints, block rates, and response rates. If your block rate exceeds 2%, your number's quality rating drops.",
            "**Rule 1: Only message opted-in contacts.** Never purchase lists or add random numbers. Use opt-in widgets, QR codes, or lead forms.",
            "**Rule 2: Use approved Templates.** Promotional messages must go through Meta's template approval process. Templates with personalization tokens get approved faster.",
            "**Rule 3: Segment ruthlessly.** Don't send the same message to 10,000 people. Segmented campaigns get 3× higher response rates.",
            "**Rule 4: Time it right.** 10 AM–12 PM and 6 PM–9 PM IST consistently outperform other windows.",
            "**Rule 5: Always include an opt-out path.** Add \"Reply STOP to unsubscribe\" — this actually improves your quality score with Meta.",
        ],
    ],
    [
        'category'    => 'E-Commerce',
        'title'       => 'Complete Guide to WhatsApp E-Commerce: From Catalog to Checkout',
        'excerpt'     => 'WhatsApp is now a complete commerce channel. Product catalogs, native checkout, payment links, and automated order updates — all inside WhatsApp.',
        'author'      => 'Kavita Pillai',
        'date'        => '2026-03-19',
        'read_time'   => '10 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/ecommerce.jpg',
        'content'     => [
            'In 2024, WhatsApp processed over $100M in commerce transactions in India alone. The "conversational commerce" model is growing 3× faster than traditional e-commerce.',
            "**Step 1: Set up your Product Catalog.** WhatsApp Business Catalogs support up to 500 products with images, descriptions, prices, and product codes.",
            "**Step 2: Enable Native Checkout.** With the WhatsApp Business API, customers can browse, add to cart, and pay — all without leaving WhatsApp. Integrate Razorpay or PayU for UPI/card payments.",
            "**Step 3: Automate Order Confirmation.** Immediately after payment, trigger a confirmation message with order number, delivery date, and tracking link. This reduces \"where is my order?\" queries by 60%.",
            "**Step 4: Post-delivery upsell.** 3 days after delivery, send a review request with a discount for the next purchase. Average LTV increases by 40% with this sequence.",
        ],
    ],
    [
        'category'    => 'Real Estate',
        'title'       => 'How Real Estate Agents Close 2× More Deals with WhatsApp Automation',
        'excerpt'     => 'Speed-to-lead is everything in real estate. WhatsApp automation helps agents follow up instantly, share property details, and book site visits — without manual effort.',
        'author'      => 'Sanjay Gupta',
        'date'        => '2026-03-16',
        'read_time'   => '7 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/realestate.jpg',
        'content'     => [
            "A lead contacted within 5 minutes is 21× more likely to convert than one contacted after 30 minutes. WhatsApp automation makes instant response possible.",
            "**IndiaMart & Facebook Lead Integration:** When a lead submits a form, automatically send them a WhatsApp message within seconds with property brochures, pricing, and a booking link.",
            "**Property Showcase Bot:** Build a chatbot that lets prospects say \"2BHK in Pune\" and instantly receive a carousel of matching properties — available 24/7.",
            "**Drip Campaign for Nurturing:** Set up a 30-day drip sequence with market updates, new listings, and testimonials to stay top-of-mind.",
            "**Site Visit Automation:** When a prospect says \"I want to visit,\" trigger a booking flow that sends available slots, confirms, adds a calendar reminder, and sends directions — no agent involvement needed.",
        ],
    ],
    [
        'category'    => 'Support',
        'title'       => 'Reduce Support Tickets by 70% with WhatsApp Automation',
        'excerpt'     => 'Every support ticket costs Rs. 500–2000 to resolve manually. WhatsApp chatbots can handle 70% of common queries automatically, saving lakhs every month.',
        'author'      => 'Divya Rao',
        'date'        => '2026-03-13',
        'read_time'   => '6 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/support.jpg',
        'content'     => [
            "The top 5 support queries in any business are almost always the same: order status, return/refund policy, business hours, pricing, and booking links. All 5 can be automated on WhatsApp.",
            "**Order Status Bot:** Integrate with your order management system so customers can type \"track order\" and immediately get real-time tracking details.",
            "**Smart Escalation:** The bot should recognize frustration signals and immediately route to a live agent — with full conversation context already loaded.",
            "**Multi-language Support:** A bot that responds in Hindi, Tamil, Telugu, or Bengali automatically converts significantly better in India.",
            "**Resolution Rate Tracking:** Waamini's Pulse Monitor tracks which queries get resolved by the bot vs. escalated, first-response time, and CSAT scores.",
        ],
    ],
    [
        'category'    => 'Strategy',
        'title'       => 'How to Calculate & Maximize Your WhatsApp Marketing ROI',
        'excerpt'     => "WhatsApp marketing consistently delivers 10-20× ROI when done correctly. Here's the exact formula, industry benchmarks, and strategies to improve your numbers.",
        'author'      => 'Amit Verma',
        'date'        => '2026-03-10',
        'read_time'   => '8 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/roi-growth.jpg',
        'content'     => [
            "WhatsApp has a 98% open rate and 45-60% click-through rate — numbers email and SMS can only dream about.",
            "**The ROI Formula:** (Revenue from Campaign − Campaign Cost) / Campaign Cost × 100. For a ₹10,000/month WhatsApp plan generating ₹1,50,000 in sales, your ROI is 1,400%.",
            "**Industry Benchmarks:** E-commerce average ROI: 800-1200%. Real Estate: 500-900%. Education: 600-1100%. Healthcare: 400-700%.",
            "**5 Ways to Improve ROI:** (1) Personalize every message with {{name}} data. (2) A/B test message copy — small changes can lift conversions 30%. (3) Send at peak engagement times. (4) Build multi-step sequences instead of one-off blasts.",
            "**Attribution Trap:** Many businesses undercount WhatsApp ROI because they don't track WhatsApp-assisted conversions. Use Waamini's analytics to see the full customer journey.",
        ],
    ],
    [
        'category'    => 'Team',
        'title'       => 'Managing a Multi-Agent WhatsApp Inbox: Complete Team Guide',
        'excerpt'     => "When multiple team members handle WhatsApp, conversations fall through the cracks. Here's the system top sales teams use to manage 1000+ chats without chaos.",
        'author'      => 'Neha Singh',
        'date'        => '2026-03-07',
        'read_time'   => '7 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/team-crm.jpg',
        'content'     => [
            "The WhatsApp Business App allows only 1 device. The moment you add a second sales rep, you have a coordination problem. Multi-agent CRMs solve this by routing conversations intelligently.",
            "**Conversation Assignment Rules:** Set automatic routing based on keywords, language, or round-robin. \"Looking to buy\" → Sales. \"Order issue\" → Support. Tamil messages → Tamil-speaking agent.",
            "**Agent Performance Metrics:** Track first response time, resolution rate, conversations handled per day, and CSAT score per agent.",
            "**Internal Notes & Tagging:** Agents can leave internal notes (invisible to the customer) and tag conversations as \"follow-up\", \"hot lead\", \"VIP\" for prioritization.",
            "**Shift Handoffs:** When Agent A ends their shift, Agent B sees the full conversation history and pending tasks with complete context.",
        ],
    ],
    [
        'category'    => 'Compliance',
        'title'       => 'WhatsApp Template Message Approval: The Complete 2026 Guide',
        'excerpt'     => "Getting WhatsApp templates approved can be confusing. This guide explains Meta's guidelines, common rejection reasons, and how to write templates approved in 24 hours.",
        'author'      => 'Kiran Bose',
        'date'        => '2026-03-04',
        'read_time'   => '9 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/messaging.jpg',
        'content'     => [
            "WhatsApp Template Messages are pre-approved message formats required for all business-initiated conversations. There are 3 categories: Utility, Authentication, and Marketing.",
            "**Why Templates Get Rejected:** Contains pricing guarantees; includes redirecting URLs; looks like phishing; promotional content in a Utility template; missing opt-in language.",
            "**Approval Timeline:** Marketing templates: 24-48 hours. Utility templates: 2-6 hours. Authentication: Usually instant.",
            "**High-Approval Formula:** [Brand Name]: [Benefit]. [Social proof]. [CTA with button]. Example: \"Waamini: Your 7-day trial is ready. Join 5,000+ businesses. [Start Now]\"",
            "**Variable Best Practices:** Keep variables to max 5 per template. Always use realistic example values — templates with realistic examples are approved 40% faster.",
        ],
    ],
    [
        'category'    => 'Healthcare',
        'title'       => 'WhatsApp Appointment Automation for Clinics & Hospitals',
        'excerpt'     => 'No-shows cost Indian clinics ₹15,000–50,000 per month. WhatsApp automation for booking, reminders, and follow-ups can reduce no-shows by up to 60%.',
        'author'      => 'Dr. Suresh Pillai',
        'date'        => '2026-03-01',
        'read_time'   => '8 min',
        'featured'    => 0,
        'cover_image' => '/assets/images/blog/healthcare.jpg',
        'content'     => [
            "The average no-show rate for medical appointments in India is 18-25%. Each no-show is not just lost revenue — it's a wasted slot that could have helped another patient.",
            "**Automated Booking Flow:** Patient sends \"Book appointment\" → Bot shows doctors and slots → Patient selects → Confirmation sent → Reminder 24hr before → Reminder 2hr before.",
            "**Smart Reminder Sequences:** A single reminder reduces no-shows by 30%. Two reminders (24hr + 2hr) reduce them by 50-60%. The 2-hour reminder should include one-tap Reschedule and Confirm buttons.",
            "**Post-Appointment Care:** Send discharge instructions, medication reminders, and follow-up suggestions via WhatsApp. This significantly improves treatment compliance.",
            "**HIPAA & Data Privacy:** Waamini's healthcare module uses end-to-end encrypted storage and role-based access control so only authorized staff can view patient conversations.",
        ],
    ],
];

/* ── Insert posts ─────────────────────────────────────────────────────── */
$inserted = 0; $skipped = 0;
foreach ($posts as $post) {
    // Check if already exists by title
    $chk = $conn->prepare("SELECT id FROM lp_blogs WHERE title=? LIMIT 1");
    $chk->bind_param('s', $post['title']); $chk->execute();
    if ($chk->get_result()->num_rows > 0) { $chk->close(); $skipped++; continue; }
    $chk->close();

    $catId     = $catMap[$post['category']] ?? null;
    $slug      = makeSlug($conn, $post['title']);
    $body      = paragraphsToHtml($post['content']);
    $pubDate   = $post['date'] . ' 09:00:00';
    $metaTitle = $post['title'] . ' | Waamini Blog';
    $metaDesc  = $post['excerpt'];
    $featured  = (int)$post['featured'];
    $cover     = $post['cover_image'];
    $author    = $post['author'];
    $readTime  = $post['read_time'];
    $status    = 'published';

    // 13 params: i + 9s + i + 2s = 'issssssssisss'
    // category_id(i), title(s), slug(s), excerpt(s), body(s), cover(s),
    // author(s), readTime(s), status(s), featured(i), metaTitle(s), metaDesc(s), pubDate(s)
    $st = $conn->prepare("
        INSERT INTO lp_blogs
            (category_id, title, slug, excerpt, body, cover_image, author_name,
             read_time, status, featured, meta_title, meta_desc, published_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $st->bind_param('issssssssisss',
        $catId, $post['title'], $slug, $post['excerpt'], $body, $cover,
        $author, $readTime, $status, $featured, $metaTitle, $metaDesc, $pubDate
    );
    if ($st->execute()) {
        $log[] = ['ok', 'Inserted: ' . $post['title']];
        $inserted++;
    } else {
        $log[] = ['err', 'Failed: ' . $post['title'] . ' — ' . $conn->error];
    }
    $st->close();
}

$log[] = ['info', "Done — $inserted inserted, $skipped skipped (already existed)."];

/* ── Output ───────────────────────────────────────────────────────────── */
$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    foreach ($log as [$t, $m]) echo ($t === 'ok' ? '✅' : ($t === 'err' ? '❌' : 'ℹ️')) . " $m\n";
} else {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Seed Posts</title>
    <style>body{font-family:system-ui;background:#090d18;color:#f0f4ff;padding:40px;max-width:700px;margin:0 auto;}
    h1{color:#00d2ff;}.ok{color:#00e676;}.err{color:#ff4757;}.info{color:#74b9ff;}
    li{padding:5px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;}
    .box{background:#101724;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:20px;margin-top:20px;}
    a{color:#00d2ff;}</style></head><body>';
    echo '<h1>📦 Blog Post Migration</h1><ul>';
    foreach ($log as [$t, $m]) {
        echo '<li class="'.$t.'">'.($t==='ok'?'✅':($t==='err'?'❌':'ℹ️')).' '.htmlspecialchars($m).'</li>';
    }
    echo '</ul><div class="box">';
    echo "<strong>$inserted post(s) inserted, $skipped skipped.</strong><br><br>";
    echo '<a href="/landing-admin/blogs.php">→ View in CMS</a> &nbsp; ';
    echo '<a href="/blogs.php">→ View Public Blog</a>';
    echo '</div></body></html>';
}
