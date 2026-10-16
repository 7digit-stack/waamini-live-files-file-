<?php
/**
 * Built-in content of the public home page. Single source of truth for:
 *   - the fallback shown when the CMS has no (active) content for a section,
 *   - the starting values the CMS editor and "Sync from landing page" fill in.
 * Generated from the live landing page; edit content in the Landing CMS, not here.
 */

function cms_default_hero(): array
{
    return [
        'announce'         => '',
        'desc'             => "Waamini helps businesses turn WhatsApp into a complete growth engine — automate conversations, manage leads, run bulk campaigns, and support customers 24/7 from one powerful dashboard.\n\nBuilt for speed. Designed for scale. Trusted by growing Indian businesses.",
        'cta_primary_text' => 'Start Free Trial',
        'cta_primary_url'  => 'register.php',
        'cta_sec_text'     => 'Watch Demo',
        'cta_sec_url'      => 'features.php',
        'typed'            => ['WhatsApp Automation', 'WhatsApp API', 'WhatsApp CRM', 'WhatsApp Bot'],
        'bullets'          => ['Bulk WhatsApp Campaigns', 'Smart Lead Automation', 'Multi-Agent Team Inbox', 'WhatsApp Chatbot Builder', '1,500+ App Integrations', 'Official WhatsApp Business API'],
        'meta'             => ['No Credit Card Required', 'Free 14-Day Trial', 'Cancel Anytime'],
    ];
}

/** Trust strip. value is a number that counts up; prefix/suffix wrap it. */
function cms_default_stats(): array
{
    return [
        ['prefix' => '₹', 'value' => 0,   'suffix' => '',  'label' => 'Message markup'],
        ['prefix' => '',  'value' => 30,  'suffix' => '+', 'label' => 'Industry bot packs'],
        ['prefix' => '',  'value' => 98,  'suffix' => '%', 'label' => 'WhatsApp open rate'],
        ['prefix' => '',  'value' => 200, 'suffix' => '+', 'label' => 'Festivals auto-scheduled'],
    ];
}

/** Feature hub: [side L|R, icon key, title, tag, description, input, output, [chat customer text, chat business text], chip text] */
function cms_default_hub(): array
{
    return [
            ['L','send',  'Bulk Campaigns',       'MARKETING', 'Send 10,000+ personalised WhatsApp messages in minutes with approved templates and zero markup on Meta rates.', 'contact groups, templates, CSV lists', 'delivery, read and reply reports', ['Any offers this week?', 'Yes! 40% off till Sunday. Tap to view.'], 'CAMPAIGN SENT TO 8,420 CONTACTS'],
            ['L','bot',   'AI Chatbot',           'AUTOMATION','OpenAI or Gemini powered, multilingual (Tamil, Hindi, English). Qualifies leads and answers 24/7.', 'customer questions, keywords', 'instant replies, lead tags', ['Do you have a demo slot?', 'Yes! 11:00 AM or 4:30 PM. Which works?'], 'AI REPLIED IN 2 SECONDS'],
            ['L','cart',  'WhatsApp Checkout',    'COMMERCE',  'Customers browse, order and pay inside the chat. UPI, cards and EMI supported, with cart recovery built in.', 'catalog, cart, UPI payments', 'order updates, payment confirmations', ['I want 2 of the blue set.', 'Added! Pay securely via UPI: ₹1,499'], 'ORDER #1042 CONFIRMED'],
            ['L','repeat','Drip Automation',     'AUTOMATION','Multi-day nurture sequences triggered by labels, time delays and customer actions.', 'new leads, labels, replies', 'timed follow-up messages', ['Thanks for the brochure!', 'Day 2: want a quick demo of how it works?'], 'DRIP STEP 2 OF 5 SENT'],
            ['L','file',  'GST Invoices',         'FINANCE',   'Auto-generate GST invoices with CGST, SGST and IGST and send them on WhatsApp instantly.', 'orders, company and tax details', 'PDF invoices, payment links', ['Please send the invoice.', 'GST invoice INV-5584 attached. ₹12,400'], 'INVOICE SENT ON WHATSAPP'],
            ['R','users', 'Multi-Agent Live Chat','TEAM',      'A full team inbox with labels, notes, assignments and real-time typing indicators.', 'incoming chats, agent assignment', 'tickets, resolved conversations', ['I need help with my order.', 'Hi, this is Arun from support. On it!'], 'CHAT ASSIGNED TO ARUN'],
            ['R','target','Lead CRM & Scoring',   'CRM',       'Every chat becomes a tracked lead with notes, reminders and AI lead scoring.', 'chats, forms, ad leads', 'scored leads, follow-up reminders', ['Call me tomorrow at 11.', 'Done. Reminder set for 11:00 AM.'], 'LEAD SCORED: HOT'],
            ['R','bell',  'Smart Reminders',      'RETENTION', 'Birthday, festival, fee, medicine and service reminders that run themselves.', 'dates, contacts, templates', 'timed reminder messages', ['Hi!', 'Reminder: Term 2 fees are due on the 10th.'], 'REMINDER SENT'],
            ['R','link',  'Integrations',         'CONNECT',   'Connect Zapier, Google Sheets, IndiaMART, Facebook Lead Ads and a developer API.', 'leads from ads and portals', 'rows, webhooks, CRM updates', ['Interested in your product.', 'Thanks! Our team will call you shortly.'], 'LEAD SAVED TO GOOGLE SHEETS'],
        ];
}

/** Testimonials: [initials, name, 'Job, City', avatar class, stars, text] */
function cms_default_testimonials(): array
{
    return [
                ['PK','Priya K.','Restaurant Owner, Chennai','av-a',5,'WAAMINI transformed how I manage my restaurant bookings. The AI bot handles reservations 24/7 and I\'ve saved ₹15,000/month on staff costs. The Pongal campaign sent automatically was brilliant!'],
                ['RM','Rajan M.','Real Estate Agent, Coimbatore','av-b',5,'I was losing 40% of leads because I couldn\'t reply fast enough. Now my bot responds in seconds, qualifies buyers, and books site visits. Closed 12 more deals in 2 months.'],
                ['SV','Dr. S. Venkatesh','Clinic Owner, Madurai','av-c',5,'Medicine reminders, appointment booking, and bill collection — all on WhatsApp. My patients love it and my front desk workload is down 60%. Best decision I made.'],
                ['AL','Aishwarya L.','E-commerce Store, Tirupur','av-d',4,'Cart recovery alone paid for the subscription 10x over. 22% of abandoned carts come back. The GST invoice feature is a lifesaver for my textile business.'],
                ['VP','Vijay P.','School Admin, Salem','av-e',5,'Fee collection via WhatsApp? Yes please! We send fee reminders, exam schedules, and results to 800+ parents. Zero paper, zero follow-up calls. Essential for any school.'],
    ];
}

/** FAQ: [question, answer] */
function cms_default_faqs(): array
{
    return [
                ['How does the free trial work?','Sign up with your WhatsApp Business number and get full access to all features for 14 days. No credit card required. After trial, choose Free (₹0/mo) or upgrade to Enterprise (₹2,999/mo).'],
                ['Do I need a WhatsApp Business API account?','We handle the entire API onboarding process for you. If you already have a WhatsApp Business API account, we can connect it in 5 minutes. If not, we help you get approved.'],
                ['Is the AI chatbot really multilingual?','Absolutely! The AI bot automatically detects and replies in Tamil, Hindi, English, and Tanglish (mixing Tamil + English). It mirrors your customer\'s language in real-time.'],
                ['How does cart recovery work?','When a customer adds items to cart but doesn\'t checkout, WAAMINI automatically sends a WhatsApp reminder after 1 hour, then a follow-up with a discount offer after 24 hours. Average recovery rate: 22%.'],
                ['Can I integrate with my existing tools?','Yes! We integrate with Razorpay, Google Sheets, IndiaMart, Facebook Lead Ads, Zapier, and more. Our open API lets you connect any custom tool. Enterprise plan includes webhooks.'],
                ['What payment methods do you accept?','We accept Razorpay (cards, UPI, net banking), direct UPI transfers, and bank transfers. Enterprise plans can pay monthly or yearly (save 17%).'],
                ['How many team members can I add?','Free plan: 3 team members. Enterprise plan: Unlimited team members with no per-seat charges. Each agent gets their own login, assignments, and performance dashboard.'],
                ['How is WAAMINI different from WATI or AiSensy?','WAAMINI is built specifically for Indian businesses. We offer zero markup on Meta rates, self-hosted deployment for data privacy, GST invoicing, 200+ Indian festival campaigns, and industry-specific bots (schools, clinics, real estate, etc.) — features none of our competitors offer.'],
            ];
}

function cms_default_cta(): array
{
    return [
        'heading'          => 'Ready to Grow on WhatsApp?',
        'highlight'        => 'WhatsApp',
        'text'             => 'Join 500+ Indian businesses already using WAAMINI to automate sales, engage customers, and close more deals — all from one powerful dashboard.',
        'primary_text'     => 'Start Free Trial — No Card Needed',
        'primary_url'      => 'register.php',
        'secondary_text'   => 'Talk to Sales',
        'secondary_url'    => 'support.php',
        'meta'             => ['Setup in 5 minutes', 'Free 14-day trial', 'Cancel anytime'],
    ];
}

/** Pricing plans on the home page (same shape as lp_pricing; "**x**" = bold, leading "✓ " = tick item). */
function cms_default_pricing(): array
{
    return [
        ['name' => 'Free Trial', 'tagline' => 'Perfect to get started. No credit card required.', 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'INR', 'is_popular' => 0,
         'cta_text' => 'Start Free Trial', 'cta_url' => 'register.php',
         'features' => ['**500 Contacts** — Grow your list', '**10 Campaigns** / month', '**1,000 Messages** / month', '**3 Team Members** — No per-seat fees', '✓ Chatbot Builder', '✓ GST Invoices']],
        ['name' => 'Enterprise', 'tagline' => 'or ₹29,990/year (save 17%)', 'price_monthly' => 2999, 'price_yearly' => 29990, 'currency' => 'INR', 'is_popular' => 1,
         'cta_text' => 'Get Enterprise', 'cta_url' => 'register.php',
         'features' => ['**1,000+ Contacts** — Unlimited growth', '**Unlimited Campaigns**', '**10,000+ Messages** / month', '**Multi-Agent Team** — Unlimited seats', '✓ AI Sales Chatbot', '✓ Cart Recovery (20% avg)', '✓ Native WA Checkout', '✓ Drip Campaigns', '✓ WhatsApp Flows', '✓ All Industry Bots', '✓ API + Webhooks', '✓ Advanced Analytics']],
    ];
}
