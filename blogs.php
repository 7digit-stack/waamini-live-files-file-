<?php
$page_title   = "Blog — WhatsApp Business Tips & Growth Strategies";
$meta_desc    = "Explore expert articles on WhatsApp marketing, automation, chatbots, and business growth strategies from the Waamini team.";
$meta_keywords = "WhatsApp marketing, WhatsApp Business API, WhatsApp chatbot, WhatsApp automation, CRM India";

/* ── Fetch published blog posts from DB (CMS) ─────────────────────────── */
$dbBlogs = [];
try {
    if (!defined('WAAMINI_LOADED')) define('WAAMINI_LOADED', true);
    require_once __DIR__ . '/db.php';
    $res = $conn->query("
        SELECT b.id, b.title, b.slug, b.excerpt, b.body, b.cover_image,
               b.author_name, b.read_time, b.views, b.published_at,
               c.name  AS cat_name,  c.color AS cat_color,
               c.slug  AS cat_slug
        FROM lp_blogs b
        LEFT JOIN lp_blog_categories c ON c.id = b.category_id
        WHERE b.status = 'published'
        ORDER BY b.featured DESC, b.published_at DESC, b.id DESC
        LIMIT 30
    ");
    if ($res) while ($r = $res->fetch_assoc()) $dbBlogs[] = $r;
} catch (Throwable $e) { /* table not yet set up — fall back to static data */ }

$asyncStylesheets = [
  'assets/css/landing-neo.css?v=15',
];
$bodyClass = 'lp-neo';
include 'includes/header.php';

$staticBlogs = [
    [
        'id'       => 1,
        'category' => 'WhatsApp API',
        'color'    => '#00b894',
        'tag_bg'   => 'rgba(0,184,148,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
        'title'    => 'WhatsApp Business API vs WhatsApp Business App: Which One Should You Use?',
        'excerpt'  => 'Most businesses start with the WhatsApp Business App — but as you grow, it becomes a bottleneck. Here\'s a complete breakdown of when you should upgrade to the official API.',
        'author'   => 'Arjun Mehta',
        'date'     => 'March 28, 2026',
        'read'     => '6 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
        'content'  => [
            'The WhatsApp Business App works great when you\'re just starting — you get a business profile, quick replies, and basic automation like away messages. But once your team grows beyond 1–2 people or your message volume exceeds 100/day, you hit hard limits.',
            '**WhatsApp Business App Limitations:** Only 1 device, no multi-agent support, no CRM integration, can\'t send bulk campaigns officially, and no API access.',
            '**WhatsApp Business API Advantages:** Multiple agents can access the same number simultaneously, full CRM integration, official bulk messaging via Templates, chatbot automation, read receipts at scale, and Meta Business Verification for credibility.',
            'For SMBs processing 500+ messages/month or running any kind of marketing — the API is the clear winner. The API version also protects your number from bans since you\'re sending through official Meta-approved channels.',
            '**Bottom line:** If you\'re serious about WhatsApp as a business channel, start with a platform like Waamini that gives you the API out of the box.',
        ]
    ],
    [
        'id'       => 2,
        'category' => 'Automation',
        'color'    => '#a29bfe',
        'tag_bg'   => 'rgba(162,155,254,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>',
        'title'    => 'How to Build a WhatsApp Chatbot That Actually Converts Sales',
        'excerpt'  => 'A poorly built chatbot loses customers. A great one closes deals 24/7 without human intervention. Here\'s the exact framework top D2C brands use.',
        'author'   => 'Priya Nair',
        'date'     => 'March 25, 2026',
        'read'     => '8 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>',
        'content'  => [
            'The average WhatsApp chatbot response rate is 80%+ — compared to email\'s 20%. But most chatbots are just FAQ machines that frustrate users. High-converting bots are built differently.',
            '**The 4-Step Conversion Framework:** (1) Qualify intent within 2 messages, (2) Show social proof immediately, (3) Remove friction from checkout, (4) Handle objections proactively.',
            'Your opening message should never be "How can I help you?" — that\'s too open-ended. Instead, use button choices: "Are you here for [Product A] or [Service B]?" This guides users and captures intent data.',
            'Product catalog integration is a game-changer. When a user says "show me products," your bot should instantly display a browsable catalog with prices, not a plain text list.',
            '**Pro tip:** Build an abandoned cart recovery sequence. If someone browses but doesn\'t buy, trigger an automated message 30 minutes later with a limited-time discount code. This alone can recover 15–25% of lost sales.',
        ]
    ],
    [
        'id'       => 3,
        'category' => 'Campaigns',
        'color'    => '#fdcb6e',
        'tag_bg'   => 'rgba(253,203,110,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg>',
        'title'    => '10 WhatsApp Broadcast Best Practices to Avoid Getting Banned',
        'excerpt'  => 'Sending bulk messages on WhatsApp without following Meta\'s guidelines is a one-way ticket to account suspension. Here\'s how to stay compliant and get 60%+ open rates.',
        'author'   => 'Rohit Sharma',
        'date'     => 'March 22, 2026',
        'read'     => '7 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg>',
        'content'  => [
            'Meta\'s spam detection is AI-powered and sophisticated. It tracks user complaints, block rates, and response rates. If your block rate exceeds 2%, your number\'s quality rating drops.',
            '**Rule 1: Only message opted-in contacts.** Never purchase lists or add random numbers. Use opt-in widgets, QR codes, or lead forms.',
            '**Rule 2: Use approved Templates.** Promotional messages must go through Meta\'s template approval process. Templates with personalization tokens get approved faster.',
            '**Rule 3: Segment ruthlessly.** Don\'t send the same message to 10,000 people. Segmented campaigns get 3× higher response rates.',
            '**Rule 4: Time it right.** 10 AM–12 PM and 6 PM–9 PM IST consistently outperform other windows.',
            '**Rule 5: Always include an opt-out path.** Add "Reply STOP to unsubscribe" — this actually improves your quality score with Meta.',
        ]
    ],
    [
        'id'       => 4,
        'category' => 'E-Commerce',
        'color'    => '#25D366',
        'tag_bg'   => 'rgba(116,185,255,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
        'title'    => 'Complete Guide to WhatsApp E-Commerce: From Catalog to Checkout',
        'excerpt'  => 'WhatsApp is now a complete commerce channel. Product catalogs, native checkout, payment links, and automated order updates — all inside WhatsApp.',
        'author'   => 'Kavita Pillai',
        'date'     => 'March 19, 2026',
        'read'     => '10 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
        'content'  => [
            'In 2024, WhatsApp processed over $100M in commerce transactions in India alone. The "conversational commerce" model is growing 3× faster than traditional e-commerce.',
            '**Step 1: Set up your Product Catalog.** WhatsApp Business Catalogs support up to 500 products with images, descriptions, prices, and product codes.',
            '**Step 2: Enable Native Checkout.** With the WhatsApp Business API, customers can browse, add to cart, and pay — all without leaving WhatsApp. Integrate Razorpay or PayU for UPI/card payments.',
            '**Step 3: Automate Order Confirmation.** Immediately after payment, trigger a confirmation message with order number, delivery date, and tracking link. This reduces "where is my order?" queries by 60%.',
            '**Step 4: Post-delivery upsell.** 3 days after delivery, send a review request with a discount for the next purchase. Average LTV increases by 40% with this sequence.',
        ]
    ],
    [
        'id'       => 5,
        'category' => 'Real Estate',
        'color'    => '#ff7675',
        'tag_bg'   => 'rgba(255,118,117,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
        'title'    => 'How Real Estate Agents Close 2× More Deals with WhatsApp Automation',
        'excerpt'  => 'Speed-to-lead is everything in real estate. WhatsApp automation helps agents follow up instantly, share property details, and book site visits — without manual effort.',
        'author'   => 'Sanjay Gupta',
        'date'     => 'March 16, 2026',
        'read'     => '7 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
        'content'  => [
            'A lead contacted within 5 minutes is 21× more likely to convert than one contacted after 30 minutes. WhatsApp automation makes instant response possible.',
            '**IndiaMart & Facebook Lead Integration:** When a lead submits a form, automatically send them a WhatsApp message within seconds with property brochures, pricing, and a booking link.',
            '**Property Showcase Bot:** Build a chatbot that lets prospects say "2BHK in Pune" and instantly receive a carousel of matching properties — available 24/7.',
            '**Drip Campaign for Nurturing:** Set up a 30-day drip sequence with market updates, new listings, and testimonials to stay top-of-mind.',
            '**Site Visit Automation:** When a prospect says "I want to visit," trigger a booking flow that sends available slots, confirms, adds a calendar reminder, and sends directions — no agent involvement needed.',
        ]
    ],
    [
        'id'       => 6,
        'category' => 'Support',
        'color'    => '#55efc4',
        'tag_bg'   => 'rgba(85,239,196,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>',
        'title'    => 'Reduce Support Tickets by 70% with WhatsApp Automation',
        'excerpt'  => 'Every support ticket costs Rs. 500–2000 to resolve manually. WhatsApp chatbots can handle 70% of common queries automatically, saving lakhs every month.',
        'author'   => 'Divya Rao',
        'date'     => 'March 13, 2026',
        'read'     => '6 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>',
        'content'  => [
            'The top 5 support queries in any business are almost always the same: order status, return/refund policy, business hours, pricing, and booking links. All 5 can be automated on WhatsApp.',
            '**Order Status Bot:** Integrate with your order management system so customers can type "track order" and immediately get real-time tracking details.',
            '**Smart Escalation:** The bot should recognize frustration signals and immediately route to a live agent — with full conversation context already loaded.',
            '**Multi-language Support:** A bot that responds in Hindi, Tamil, Telugu, or Bengali automatically converts significantly better in India.',
            '**Resolution Rate Tracking:** Waamini\'s Pulse Monitor tracks which queries get resolved by the bot vs. escalated, first-response time, and CSAT scores.',
        ]
    ],
    [
        'id'       => 7,
        'category' => 'Strategy',
        'color'    => '#fd79a8',
        'tag_bg'   => 'rgba(253,121,168,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>',
        'title'    => 'How to Calculate & Maximize Your WhatsApp Marketing ROI',
        'excerpt'  => 'WhatsApp marketing consistently delivers 10-20× ROI when done correctly. Here\'s the exact formula, industry benchmarks, and strategies to improve your numbers.',
        'author'   => 'Amit Verma',
        'date'     => 'March 10, 2026',
        'read'     => '8 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>',
        'content'  => [
            'WhatsApp has a 98% open rate and 45-60% click-through rate — numbers email and SMS can only dream about.',
            '**The ROI Formula:** (Revenue from Campaign − Campaign Cost) / Campaign Cost × 100. For a ₹10,000/month WhatsApp plan generating ₹1,50,000 in sales, your ROI is 1,400%.',
            '**Industry Benchmarks:** E-commerce average ROI: 800-1200%. Real Estate: 500-900%. Education: 600-1100%. Healthcare: 400-700%.',
            '**5 Ways to Improve ROI:** (1) Personalize every message with {{name}} data. (2) A/B test message copy — small changes can lift conversions 30%. (3) Send at peak engagement times. (4) Build multi-step sequences instead of one-off blasts.',
            '**Attribution Trap:** Many businesses undercount WhatsApp ROI because they don\'t track WhatsApp-assisted conversions. Use Waamini\'s analytics to see the full customer journey.',
        ]
    ],
    [
        'id'       => 8,
        'category' => 'Team',
        'color'    => '#00cec9',
        'tag_bg'   => 'rgba(0,206,201,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'title'    => 'Managing a Multi-Agent WhatsApp Inbox: Complete Team Guide',
        'excerpt'  => 'When multiple team members handle WhatsApp, conversations fall through the cracks. Here\'s the system top sales teams use to manage 1000+ chats without chaos.',
        'author'   => 'Neha Singh',
        'date'     => 'March 7, 2026',
        'read'     => '7 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'content'  => [
            'The WhatsApp Business App allows only 1 device. The moment you add a second sales rep, you have a coordination problem. Multi-agent CRMs solve this by routing conversations intelligently.',
            '**Conversation Assignment Rules:** Set automatic routing based on keywords, language, or round-robin. "Looking to buy" → Sales. "Order issue" → Support. Tamil messages → Tamil-speaking agent.',
            '**Agent Performance Metrics:** Track first response time, resolution rate, conversations handled per day, and CSAT score per agent.',
            '**Internal Notes & Tagging:** Agents can leave internal notes (invisible to the customer) and tag conversations as "follow-up", "hot lead", "VIP" for prioritization.',
            '**Shift Handoffs:** When Agent A ends their shift, Agent B sees the full conversation history and pending tasks with complete context.',
        ]
    ],
    [
        'id'       => 9,
        'category' => 'Compliance',
        'color'    => '#e17055',
        'tag_bg'   => 'rgba(225,112,85,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        'title'    => 'WhatsApp Template Message Approval: The Complete 2026 Guide',
        'excerpt'  => 'Getting WhatsApp templates approved can be confusing. This guide explains Meta\'s guidelines, common rejection reasons, and how to write templates approved in 24 hours.',
        'author'   => 'Kiran Bose',
        'date'     => 'March 4, 2026',
        'read'     => '9 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        'content'  => [
            'WhatsApp Template Messages are pre-approved message formats required for all business-initiated conversations. There are 3 categories: Utility, Authentication, and Marketing.',
            '**Why Templates Get Rejected:** Contains pricing guarantees; includes redirecting URLs; looks like phishing; promotional content in a Utility template; missing opt-in language.',
            '**Approval Timeline:** Marketing templates: 24-48 hours. Utility templates: 2-6 hours. Authentication: Usually instant.',
            '**High-Approval Formula:** [Brand Name]: [Benefit]. [Social proof]. [CTA with button]. Example: "Waamini: Your 7-day trial is ready. Join 5,000+ businesses. [Start Now]"',
            '**Variable Best Practices:** Keep variables to max 5 per template. Always use realistic example values — templates with realistic examples are approved 40% faster.',
        ]
    ],
    [
        'id'       => 10,
        'category' => 'Healthcare',
        'color'    => '#25D366',
        'tag_bg'   => 'rgba(108,92,231,0.12)',
        'icon'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>',
        'title'    => 'WhatsApp Appointment Automation for Clinics & Hospitals',
        'excerpt'  => 'No-shows cost Indian clinics ₹15,000–50,000 per month. WhatsApp automation for booking, reminders, and follow-ups can reduce no-shows by up to 60%.',
        'author'   => 'Dr. Suresh Pillai',
        'date'     => 'March 1, 2026',
        'read'     => '8 min',
        'cover_emoji' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>',
        'content'  => [
            'The average no-show rate for medical appointments in India is 18-25%. Each no-show is not just lost revenue — it\'s a wasted slot that could have helped another patient.',
            '**Automated Booking Flow:** Patient sends "Book appointment" → Bot shows doctors and slots → Patient selects → Confirmation sent → Reminder 24hr before → Reminder 2hr before.',
            '**Smart Reminder Sequences:** A single reminder reduces no-shows by 30%. Two reminders (24hr + 2hr) reduce them by 50-60%. The 2-hour reminder should include one-tap Reschedule and Confirm buttons.',
            '**Post-Appointment Care:** Send discharge instructions, medication reminders, and follow-up suggestions via WhatsApp. This significantly improves treatment compliance.',
            '**HIPAA & Data Privacy:** Waamini\'s healthcare module uses end-to-end encrypted storage and role-based access control so only authorized staff can view patient conversations.',
        ]
    ],
];

$cover_imgs = [
    1  => 'assets/images/blog/whatsapp-api.jpg',
    2  => 'assets/images/blog/chatbot-crm.jpg',
    3  => 'assets/images/blog/marketing.jpg',
    4  => 'assets/images/blog/ecommerce.jpg',
    5  => 'assets/images/blog/realestate.jpg',
    6  => 'assets/images/blog/support.jpg',
    7  => 'assets/images/blog/roi-growth.jpg',
    8  => 'assets/images/blog/team-crm.jpg',
    9  => 'assets/images/blog/messaging.jpg',
    10 => 'assets/images/blog/healthcare.jpg',
];

$cover_stats = [
    1  => ['stat' => '5x Speed',   'label' => 'vs Business App'],
    2  => ['stat' => '80%+',       'label' => 'Chatbot Response Rate'],
    3  => ['stat' => '60%+',       'label' => 'Campaign Open Rate'],
    4  => ['stat' => '₹100M+',     'label' => 'Commerce Value/Year'],
    5  => ['stat' => '21x',        'label' => 'Faster Lead Response'],
    6  => ['stat' => '70%',        'label' => 'Tickets Auto-Resolved'],
    7  => ['stat' => '1,400%',     'label' => 'Average Marketing ROI'],
    8  => ['stat' => '1,000+',     'label' => 'Chats Managed/Day'],
    9  => ['stat' => '24hr',       'label' => 'Template Approval Time'],
    10 => ['stat' => '60%',        'label' => 'Fewer No-Shows'],
];

/* ── Decide whether to use DB posts or static fallback ─────────────────── */
$useDb = !empty($dbBlogs);
if ($useDb) {
    // Transform DB rows to match template keys
    $blogs = [];
    foreach ($dbBlogs as $row) {
        $color   = $row['cat_color'] ?: '#25D366';
        $r = hexdec(substr(ltrim($color,'#'),0,2));
        $g = hexdec(substr(ltrim($color,'#'),2,2));
        $b = hexdec(substr(ltrim($color,'#'),4,2));
        $blogs[] = [
            'id'       => (int)$row['id'],
            'slug'     => $row['slug'],
            'category' => $row['cat_name'] ?: 'General',
            'color'    => $color,
            'tag_bg'   => "rgba($r,$g,$b,0.12)",
            'icon'     => '',
            'title'    => $row['title'],
            'excerpt'  => $row['excerpt'],
            'author'   => $row['author_name'] ?: 'Waamini Team',
            'date'     => $row['published_at'] ? date('F j, Y', strtotime($row['published_at'])) : '',
            'read'     => $row['read_time'] ?: '5 min',
            'cover_image' => $row['cover_image'],
            'body'     => $row['body'],  // HTML from rich editor
            'content'  => [],            // empty; JS will use 'body' instead
        ];
    }
} else {
    $blogs = $staticBlogs;
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ====================================
   WAAMINI BLOG — Premium Blue Light
   ==================================== */
:root {
    --blog-card:   #ffffff;
    --blog-border: #e2e8f0;
    --blog-accent: #25D366;
    --blog-cyan:   #25D366;
    --blog-muted:  #64748B;
    --blog-text:   #0F172A;
}

.blog-page-wrap {
    min-height: 100vh;
    background: #f8fafc;
    animation: blogIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes blogIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Hero ── */
.blog-hero {
    text-align: center;
    padding: 70px 20px 56px;
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #E6FCF2 0%, #ffffff 50%, #E6FCF2 100%);
}
.blog-hero::before {
    content: '';
    position: absolute; top: 0; left: 50%; transform: translateX(-50%);
    width: 700px; height: 360px;
    background: radial-gradient(ellipse at center, rgba(37,211,102,0.07) 0%, transparent 70%);
    pointer-events: none;
}
.hero-pill {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--blog-accent);
    background: rgba(37,211,102,0.08);
    border: 1px solid rgba(37,211,102,0.2);
    padding: 6px 18px; border-radius: 20px; margin-bottom: 22px;
}
.blog-hero h1 {
    font-size: clamp(28px, 5vw, 48px);
    font-weight: 900; letter-spacing: -0.04em;
    color: #0F172A; margin-bottom: 16px;
    line-height: 1.15;
}
.blog-hero h1 span {
    background: linear-gradient(135deg, var(--blog-accent), var(--blog-cyan));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.blog-hero-sub {
    font-size: 16px; color: var(--blog-muted); font-weight: 500;
    max-width: 480px; margin: 0 auto 36px; line-height: 1.7;
}
/* Search */
.blog-search-row { max-width: 460px; margin: 0 auto; position: relative; }
.blog-search-row i {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    color: var(--blog-accent); font-size: 14px; pointer-events: none;
}
.blog-search-input {
    width: 100%; padding: 14px 18px 14px 48px;
    background: #ffffff;
    border: 1px solid var(--blog-border); border-radius: 18px;
    color: #0F172A; font-size: 14px; font-weight: 500;
    font-family: 'Inter', sans-serif; outline: none; transition: 0.3s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.blog-search-input:focus {
    border-color: var(--blog-accent);
    box-shadow: 0 0 0 4px rgba(37,211,102,0.1);
}
.blog-search-input::placeholder { color: var(--blog-muted); }

/* ── Category Bar ── */
.blog-cat-bar {
    display: flex; justify-content: center;
    gap: 8px; flex-wrap: wrap;
    padding: 28px 20px 40px;
}
.blog-cat-btn {
    padding: 7px 16px; border-radius: 20px;
    font-size: 12px; font-weight: 700;
    background: #ffffff;
    border: 1px solid var(--blog-border);
    color: var(--blog-muted); cursor: pointer;
    transition: 0.3s; font-family: 'Inter', sans-serif;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.blog-cat-btn:hover { border-color: var(--blog-accent); color: var(--blog-accent); }
.blog-cat-btn.active { border-color: var(--blog-accent); color: #fff; background: var(--blog-accent); }

/* ── Grid ── */
.blog-grid-outer {
    max-width: 1200px; margin: 0 auto;
    padding: 0 24px 80px;
}
.blog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 22px;
}
.blog-card-featured {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: 280px 1fr;
}

/* ── Cards ── */
.blog-card {
    background: var(--blog-card);
    border: 1px solid var(--blog-border);
    border-radius: 24px; overflow: hidden;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    cursor: pointer; position: relative;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.blog-card::after {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--bcard-color, var(--blog-accent)), transparent);
    opacity: 0; transition: 0.4s;
}
.blog-card:hover {
    transform: translateY(-5px);
    border-color: #cbd5e1;
    box-shadow: 0 20px 56px rgba(0,0,0,0.08);
}
.blog-card:hover::after { opacity: 1; }

/* Cover */
.bcard-cover {
    min-height: 185px;
    background: #0f172a;
    position: relative; overflow: hidden;
}
.blog-card-featured .bcard-cover { min-height: 260px; border-radius: 0; }
.bcard-cover-img {
    width: 100%; height: 100%; min-height: 185px;
    object-fit: cover; object-position: center top;
    display: block;
    transition: transform 0.5s ease;
}
.blog-card-featured .bcard-cover-img { min-height: 260px; }
.blog-card:hover .bcard-cover-img { transform: scale(1.06); }
.bcard-cover-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.72) 0%, rgba(0,0,0,0.18) 55%, transparent 100%);
    pointer-events: none;
}
.bcard-cover-glow { display: none; }
.bcard-cover-stat {
    position: absolute; bottom: 14px; left: 16px; z-index: 2;
}
.bcard-cover-stat strong {
    display: block; font-size: 24px; font-weight: 900;
    color: #fff; line-height: 1; letter-spacing: -0.5px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.4);
}
.bcard-cover-stat span {
    display: block; font-size: 11px; font-weight: 600;
    color: rgba(255,255,255,0.82); margin-top: 2px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.4);
}

/* Body */
.bcard-body { padding: 26px; }
.blog-card-featured .bcard-body { padding: 36px; display: flex; flex-direction: column; justify-content: center; }

.bcard-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.bcard-cat {
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
    padding: 4px 11px; border-radius: 20px;
}
.bcard-read { font-size: 11px; color: var(--blog-muted); font-weight: 600; display: flex; align-items: center; gap: 5px; }

.bcard-title {
    font-size: 17px; font-weight: 800; color: #0F172A;
    line-height: 1.35; letter-spacing: -0.01em;
    margin-bottom: 10px; transition: 0.3s;
}
.blog-card-featured .bcard-title { font-size: 24px; margin-bottom: 14px; }
.blog-card:hover .bcard-title { color: var(--blog-accent); }

.bcard-excerpt {
    font-size: 13px; color: var(--blog-muted);
    line-height: 1.7; margin-bottom: 20px;
}
.blog-card-featured .bcard-excerpt { font-size: 14.5px; }

.bcard-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding-top: 14px; border-top: 1px solid var(--blog-border);
}
.bcard-author { display: flex; align-items: center; gap: 9px; }
.bcard-av {
    width: 30px; height: 30px; border-radius: 50%;
    background: linear-gradient(135deg, var(--blog-accent), var(--blog-cyan));
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.bcard-av-name { font-size: 11px; font-weight: 700; color: #0F172A; }
.bcard-av-date { font-size: 10px; color: var(--blog-muted); margin-top: 1px; }
.bcard-arrow {
    width: 32px; height: 32px; border-radius: 50%;
    background: #f1f5f9;
    border: 1px solid var(--blog-border);
    display: flex; align-items: center; justify-content: center;
    color: var(--blog-muted); font-size: 12px; transition: 0.3s;
}
.blog-card:hover .bcard-arrow { background: var(--blog-accent); border-color: var(--blog-accent); color: #fff; }

/* No results */
.blog-no-results {
    grid-column: 1/-1; text-align: center; padding: 60px 20px;
    color: var(--blog-muted); display: none;
}

/* ── Read Modal ── */
.blog-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(8px);
    align-items: flex-start; justify-content: center;
    overflow-y: auto; padding: 48px 16px;
}
.blog-modal {
    background: #ffffff;
    border: 1px solid var(--blog-border);
    border-radius: 32px; overflow: hidden;
    max-width: 740px; width: 100%;
    margin: auto;
    box-shadow: 0 32px 80px rgba(0,0,0,0.15);
    animation: mIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
}
@keyframes mIn {
    from { opacity: 0; transform: scale(0.96) translateY(20px); }
    to   { opacity: 1; transform: scale(1)   translateY(0); }
}
.modal-x {
    position: absolute; top: 18px; right: 18px; z-index: 10;
    width: 38px; height: 38px; border-radius: 50%;
    background: rgba(0,0,0,0.1); border: 1px solid var(--blog-border);
    color: #64748B; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: 0.3s;
}
.modal-x:hover { background: rgba(239,68,68,0.1); border-color: #ef4444; color: #ef4444; }
.modal-cover-bg {
    min-height: 190px; display: flex; align-items: center; justify-content: center;
    font-size: 88px; background: #f1f5f9;
}
.modal-inner { padding: 36px; }
.modal-cat {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.1em; padding: 4px 14px; border-radius: 20px;
    display: inline-block; margin-bottom: 18px;
}
.modal-title {
    font-size: clamp(20px, 4vw, 30px);
    font-weight: 900; color: #0F172A; line-height: 1.2;
    letter-spacing: -0.03em; margin-bottom: 14px;
}
.modal-meta-row {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    padding-bottom: 20px; border-bottom: 1px solid var(--blog-border); margin-bottom: 28px;
}
.modal-av {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, var(--blog-accent), var(--blog-cyan));
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 900; color: #fff;
}
.modal-content p {
    font-size: 14.5px; line-height: 1.85;
    color: #475569; margin-bottom: 20px;
}
.modal-content p strong {
    color: #0F172A; font-weight: 700;
}
.modal-content .highlight-p {
    padding: 14px 18px;
    background: rgba(37,211,102,0.06);
    border-left: 3px solid var(--blog-accent);
    border-radius: 0 12px 12px 0;
    color: #475569 !important;
}
.modal-cta-box {
    margin-top: 36px; padding: 26px; text-align: center;
    background: #E6FCF2;
    border: 1px solid rgba(37,211,102,0.15);
    border-radius: 20px;
}
.modal-cta-box h3 { font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 8px; }
.modal-cta-box p { font-size: 13px; color: var(--blog-muted); margin-bottom: 18px; }
.modal-cta-link {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 26px; border-radius: 13px;
    background: linear-gradient(135deg, var(--blog-accent), var(--blog-cyan));
    color: #fff; font-size: 13px; font-weight: 800;
    text-decoration: none; transition: 0.3s;
}
.modal-cta-link:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(37,211,102,0.35); color: #fff; }

/* ── Responsive ── */
@media (max-width: 860px) {
    .blog-card-featured { grid-column: auto; grid-template-columns: 1fr; }
    .blog-card-featured .bcard-cover { min-height: 150px; }
}
@media (max-width: 640px) {
    .blog-grid { grid-template-columns: 1fr; }
    .blog-grid-outer { padding: 0 14px 60px; }
    .blog-hero { padding: 48px 16px 40px; }
}
</style>

<div class="blog-page-wrap">

    <!-- Hero -->
    <div class="blog-hero">
        <div class="hero-pill"><i class="fas fa-pen-nib"></i> Waamini Blog</div>
        <div class="title-wrap"><h1>WhatsApp Growth Strategies<br><span>for Modern Businesses</span></h1></div>
        <p class="blog-hero-sub">Expert articles on WhatsApp marketing, automation, chatbots, and scaling with conversational commerce.</p>
        <div class="blog-search-row">
            <i class="fas fa-search"></i>
            <input type="text" class="blog-search-input" id="blogSearch" placeholder="Search articles..." oninput="filterBlog()">
        </div>
    </div>

    <!-- Category Filter -->
    <div class="blog-cat-bar" id="catBar">
        <button class="blog-cat-btn active" onclick="filterCat('all', this)">All Posts</button>
        <?php
        $cats = array_unique(array_column($blogs, 'category'));
        foreach ($cats as $c) echo '<button class="blog-cat-btn" onclick="filterCat(\'' . htmlspecialchars($c) . '\', this)">' . $c . '</button>';
        ?>
    </div>

    <!-- Grid -->
    <div class="blog-grid-outer">
        <div class="blog-grid" id="blogGrid">
            <?php foreach ($blogs as $i => $blog):
                $featured = ($i === 0) ? 'blog-card-featured' : '';
                $init = strtoupper($blog['author'][0]);
            ?>
            <article class="blog-card <?php echo $featured; ?>"
                     onclick="openBlogPost(<?php echo $i; ?>)"
                     data-cat="<?php echo htmlspecialchars($blog['category']); ?>"
                     data-title="<?php echo htmlspecialchars(strtolower($blog['title'] . ' ' . $blog['category'])); ?>"
                     style="--bcard-color: <?php echo $blog['color']; ?>; --bcard-glow: <?php echo $blog['tag_bg']; ?>;">

                <?php
                    $cs = $cover_stats[$blog['id']] ?? ['stat' => '', 'label' => ''];
                    $ci = !empty($blog['cover_image'])
                        ? $blog['cover_image']
                        : ($cover_imgs[$blog['id']] ?? 'assets/images/whatsapp_3d_hub.png');
                ?>
                <div class="bcard-cover">
                    <img src="<?php echo htmlspecialchars($ci); ?>" alt="<?php echo htmlspecialchars($blog['title']); ?>" class="bcard-cover-img">
                    <div class="bcard-cover-overlay"></div>
                    <div class="bcard-cover-stat">
                        <strong><?php echo $cs['stat']; ?></strong>
                        <span><?php echo $cs['label']; ?></span>
                    </div>
                </div>

                <div class="bcard-body">
                    <div class="bcard-meta">
                        <span class="bcard-cat" style="background:<?php echo $blog['tag_bg']; ?>;color:<?php echo $blog['color']; ?>;">
                            <?php echo $blog['icon']; ?> <?php echo $blog['category']; ?>
                        </span>
                        <span class="bcard-read"><i class="far fa-clock"></i> <?php echo $blog['read']; ?> read</span>
                    </div>
                    <h2 class="bcard-title"><?php echo $blog['title']; ?></h2>
                    <p class="bcard-excerpt"><?php echo $blog['excerpt']; ?></p>
                    <div class="bcard-footer">

                        <div class="bcard-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <div class="blog-no-results" id="noResults">
                <i class="fas fa-search-minus" style="font-size:36px;margin-bottom:12px;opacity:.2;display:block;"></i>
                No articles found. Try different keywords.
            </div>
        </div>
    </div>
</div>

<!-- Read Modal -->
<div class="blog-modal-overlay" id="blogModal" onclick="closeBlogModal(event)">
    <div class="blog-modal">
        <button class="modal-x" onclick="closeBlogModalBtn()"><i class="fas fa-times"></i></button>
        <div class="modal-cover-bg" id="mCover"></div>
        <div class="modal-inner">
            <span class="modal-cat" id="mCat"></span>
            <h2 class="modal-title" id="mTitle"></h2>
            <div class="modal-meta-row">

                <span style="font-size:11px;color:var(--blog-muted);padding:4px 12px;background:#f1f5f9;border:1px solid var(--blog-border);border-radius:20px;" id="mRead"></span>
            </div>
            <div class="modal-content" id="mContent"></div>
            <div class="modal-cta-box">
                <h3>Ready to Automate Your WhatsApp?</h3>
                <p>Join 5,000+ businesses using Waamini to grow with WhatsApp Business API.</p>
                <a href="register.php" class="modal-cta-link"><i class="fas fa-rocket"></i> Start Free Trial</a>
            </div>
        </div>
    </div>
</div>

<script>
const blogData = <?php echo json_encode($blogs); ?>;

function openBlogPost(i) {
    const b = blogData[i];
    const coverImgs = [null,
        'assets/images/blog/whatsapp-api.jpg',
        'assets/images/blog/chatbot-crm.jpg',
        'assets/images/blog/marketing.jpg',
        'assets/images/blog/ecommerce.jpg',
        'assets/images/blog/realestate.jpg',
        'assets/images/blog/support.jpg',
        'assets/images/blog/roi-growth.jpg',
        'assets/images/blog/team-crm.jpg',
        'assets/images/blog/messaging.jpg',
        'assets/images/blog/healthcare.jpg'
    ];
    const coverStats = [null,
        {stat:'5x Speed',label:'vs Business App'},
        {stat:'80%+',label:'Chatbot Response Rate'},
        {stat:'60%+',label:'Campaign Open Rate'},
        {stat:'₹100M+',label:'Commerce Value/Year'},
        {stat:'21x',label:'Faster Lead Response'},
        {stat:'70%',label:'Tickets Auto-Resolved'},
        {stat:'1,400%',label:'Average Marketing ROI'},
        {stat:'1,000+',label:'Chats Managed/Day'},
        {stat:'24hr',label:'Template Approval'},
        {stat:'60%',label:'Fewer No-Shows'}
    ];
    const csi = coverStats[b.id] || {stat:'',label:''};
    const imgSrc = b.cover_image || coverImgs[b.id] || 'assets/images/whatsapp_3d_hub.png';
    const mCov = document.getElementById('mCover');
    mCov.style.cssText = 'position:relative;overflow:hidden;min-height:220px;background:#0f172a;';
    mCov.innerHTML =
        '<img src="'+imgSrc+'" alt="'+b.title+'" style="width:100%;height:220px;object-fit:cover;object-position:center top;display:block;">' +
        '<div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.75) 0%,rgba(0,0,0,0.15) 55%,transparent 100%);pointer-events:none;"></div>' +
        (csi.stat ? '<div style="position:absolute;bottom:16px;left:20px;z-index:2;">' +
        '<strong style="display:block;font-size:28px;font-weight:900;color:#fff;letter-spacing:-0.5px;line-height:1;text-shadow:0 2px 8px rgba(0,0,0,0.5);">' + csi.stat + '</strong>' +
        '<span style="display:block;font-size:12px;color:rgba(255,255,255,0.82);font-weight:600;margin-top:3px;text-shadow:0 1px 4px rgba(0,0,0,0.5);">' + csi.label + '</span>' +
        '</div>' : '');
    document.getElementById('mCat').textContent = (b.icon ? b.icon + ' ' : '') + b.category;
    document.getElementById('mCat').style.cssText = 'background:' + b.tag_bg + ';color:' + b.color + ';';
    document.getElementById('mTitle').textContent = b.title;
    document.getElementById('mRead').textContent = b.read + ' read';

    let html = '';
    if (b.body) {
        // DB post — already HTML from rich editor
        html = b.body;
    } else {
        // Static post — markdown-style array
        b.content.forEach(p => {
            const fmt = p.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            const cls = p.startsWith('**') ? 'highlight-p' : '';
            html += '<p class="' + cls + '">' + fmt + '</p>';
        });
    }
    document.getElementById('mContent').innerHTML = html;
    const ov = document.getElementById('blogModal');
    ov.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeBlogModal(e) { if (e.target.id === 'blogModal') closeBlogModalBtn(); }
function closeBlogModalBtn() {
    document.getElementById('blogModal').style.display = 'none';
    document.body.style.overflow = '';
}
function filterBlog() {
    const q = document.getElementById('blogSearch').value.toLowerCase();
    let vis = 0;
    document.querySelectorAll('.blog-card').forEach(c => {
        const m = c.dataset.title && c.dataset.title.includes(q);
        c.style.display = m ? '' : 'none';
        if (m) vis++;
    });
    document.getElementById('noResults').style.display = vis === 0 ? 'block' : 'none';
}
function filterCat(cat, btn) {
    document.querySelectorAll('.blog-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    let vis = 0;
    document.querySelectorAll('.blog-card').forEach(c => {
        const m = cat === 'all' || c.dataset.cat === cat;
        c.style.display = m ? '' : 'none';
        if (m) vis++;
    });
    document.getElementById('noResults').style.display = vis === 0 ? 'block' : 'none';
    document.getElementById('blogSearch').value = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeBlogModalBtn(); });
</script>

<!-- 7digit-system port: same Lenis + GSAP ScrollTrigger reveal as the homepage -->
<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="assets/js/lp-theme.js?v=4" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>

<?php include 'includes/footer.php'; ?>
