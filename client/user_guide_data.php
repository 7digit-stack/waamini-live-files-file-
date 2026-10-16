<?php
// Detailed Multilingual Dictionary for User Guide
// Format: Title, Purpose, and Step-by-Step Instructions
// Security: only include from authenticated pages; block direct web access.
if (!defined('WAAMINI_LOADED') && !isset($_SESSION['user_id'])) {
    http_response_code(403); exit;
}

function get_guide_content($lang = 'en')
{

    $features = [
        'en' => [
            'header_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> Detailed User Guide',
            'header_subtitle' => 'Step-by-step visual instructions on how to use every feature.',
            'search' => 'Search for a feature...',
            'lbl_purpose' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Purpose:',
            'lbl_steps' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> How to use:',
            'sub_panel_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Your subscription & enabled features',
            'sub_panel_intro' => 'Below is what your Waamini plan unlocks for this workspace. Guides for each area follow.',
            'sub_plan_label' => 'Plan',
            'sub_status_label' => 'Status',
            'sub_expires_label' => 'Renews / ends',
            'sub_feature_module' => 'Module',
            'sub_feature_access' => 'Access',
            'sub_on' => 'Included',
            'sub_off' => 'Not included',
            'sub_no_plan' => 'No active subscription plan is linked to this account. Contact your Waamini administrator or upgrade to unlock features.',
            'sub_limits_title' => 'Usage limits (this billing period)',
            'sub_limit_fmt' => ':used / :limit',
            'sub_unlimited' => 'Unlimited',

            'sections' => [
                'Core Modules' => [
                    [
                        'name' => '1. WhatsApp Chat',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                        'purpose' => 'To chat directly with your customers like you do on your phone, but on the computer.',
                        'steps' => [
                            'Go to the <b>WhatsApp Chat</b> page from the left menu.',
                            'Select any customer\'s name on the left side to open their chat.',
                            'Type your message in the text box at the bottom.',
                            'Click the <b>Send Icon</b> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> or press Enter to send.',
                            'Use the <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Paperclip icon to attach images or PDFs.'
                        ]
                    ],
                    [
                        'name' => '2. Contacts',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'To save your customer phonenumbers so you can easily send them messages later.',
                        'steps' => [
                            'Go to the <b>Contacts</b> page.',
                            'To add one person: Click <b>"+ New Contact"</b>, type their Name and Number (with country code, e.g., 919876543210), and click Save.',
                            'To add many people at once: Click <b>"Import Contacts"</b>.',
                            'Download the Sample Excel (CSV) file, paste your numbers there, and upload it back.'
                        ]
                    ],
                    [
                        'name' => '3. Templates',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>',
                        'purpose' => 'WhatsApp requires pre-approved message formats (like "Hi John, your order is ready") before you can send bulk messages.',
                        'steps' => [
                            'Go to the <b>Templates</b> page and click <b>"+ New Template"</b>.',
                            'Give it a simple name (e.g., "diwali_offer").',
                            'Select the Category (Marketing or Utility).',
                            'Type your message. If you want to say "Hi [Name]", write "Hi {{1}}".',
                            'Scroll down to add <b>Buttons</b> (like "Visit Website" or "Call Us").',
                            'Click Save. Wait a few minutes for WhatsApp to approve it.'
                        ]
                    ],
                    [
                        'name' => '4. Campaigns',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
                        'purpose' => 'To send a single promotional message or announcement to hundreds or thousands of people at the exact same time.',
                        'steps' => [
                            'First, go to Contacts and ensure the people you want to message have a specific <b>Label</b> (e.g., "VIP Customers").',
                            'Go to the <b>Campaigns</b> page and click <b>"New Campaign"</b>.',
                            'Select the Label "VIP Customers" in the audience section.',
                            'Select the approved Template you created earlier.',
                            'Click <b>"Send Campaign"</b> to send immediately, OR use the "Schedule Time" option to send it later automatically.'
                        ]
                    ],
                    [
                        'name' => '5. Team Members',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'To allow your staff or employees to log in and chat with customers without giving them your admin password.',
                        'steps' => [
                            'Go to <b>Team Members</b> in the Admin panel.',
                            'Click <b>"Add Member"</b>.',
                            'Enter their Name, Email, and give them a Password.',
                            'Now they can log in to WAAMINI using their email and reply to customer chats!'
                        ]
                    ],
                    [
                        'name' => '6. Bot Replies',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg>',
                        'purpose' => 'To set up basic automatic Keyword replies. E.g., if someone says "Price", the bot sends the price list.',
                        'steps' => [
                            'Go to <b>Bot Replies</b> and click <b>"Add Rule"</b>.',
                            'In the "Keyword" box, type the trigger word (e.g., "Location").',
                            'In the "Reply" box, type what the bot should say (e.g., "Our shop is located in Chennai").',
                            'Click Save. Now anyone texting "Location" will instantly get the address.'
                        ]
                    ]
                ],
                'Premium Features' => [
                    [
                        'name' => '7. Zapier & Webhooks API',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                        'purpose' => 'To connect WAAMINI to other softwares (like Facebook Ads, WordPress, Razorpay) using Zapier or Pabbly.',
                        'steps' => [
                            'Go to <b>Premium Growth -> Zapier Webhooks</b>.',
                            'Click <b>"Generate URL"</b>.',
                            'Copy this URL. Open your Pabbly Connect or Zapier account.',
                            'Paste the URL into Pabbly as your "Action URL".',
                            'Now, whenever a new lead fills a form on Facebook, Pabbly sends it to this URL, and WAAMINI instantly sends them a WhatsApp message on your behalf.'
                        ]
                    ],
                    [
                        'name' => '8. WhatsApp Native Flows',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
                        'purpose' => 'To create beautiful forms (like Appointment Booking or Feedback) that open directly inside the WhatsApp app.',
                        'steps' => [
                            'Design the form in Meta\'s Flow Builder (WhatsApp Manager -> Flows) and copy its JSON.',
                            'Go to <b>Premium Growth -> WA Native Forms</b>, paste the JSON, give it a name (E.g., "Doctor Appointment") and click <b>Save Form</b>. It is uploaded to Meta and any errors are shown.',
                            'Use <b>Send a test</b> to open it on your own phone, then click <b>Publish</b>.',
                            'Create a Template and add an <b>Open form</b> button that uses this form. Send it in a campaign once Meta approves it.',
                            'When the customer taps the button, the form opens inside WhatsApp. Their answers appear in the inbox, the contact\'s notes and under <b>Responses</b> (with CSV export).'
                        ]
                    ],
                    [
                        'name' => '9. E-Commerce Cart Recovery',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
                        'purpose' => 'To automatically message people who added a product to their shopping cart on your website but didn\'t buy it.',
                        'steps' => [
                            'Go to <b>Premium Growth -> E-Commerce</b>.',
                            'Copy the webhook link provided on the page.',
                            'Paste this link in your Shopify or WooCommerce settings.',
                            'Configure the time delay (e.g., "1 Hour").',
                            'If a customer leaves without paying, 1 hour later WAAMINI will WhatsApp them saying "Hey! You forgot your item! Here is a 10% discount link.".'
                        ]
                    ],
                    [
                        'name' => '10. AI Image OCR Scanner',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
                        'purpose' => 'To easily save contacts by just taking a photo of a business card or a handwritten register book.',
                        'steps' => [
                            'Go to <b>Premium Growth -> AI Image Scanner</b>.',
                            'Click <b>"Upload Image"</b> or drag-and-drop a photo.',
                            'Wait 10 seconds. The AI reads the image and finds all the Names and Phone Numbers.',
                            'Review the list it found on screen.',
                            'Click <b>"Save to Contacts"</b> and they are automatically added to your CRM.'
                        ]
                    ],
                    [
                        'name' => '11. Auto Follow-up (Drip Campaigns)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                        'purpose' => 'To automatically send a sequence of messages over several days to new leads.',
                        'steps' => [
                            'Go to <b>Drip Campaigns</b>.',
                            'Create a new sequence.',
                            'Set Message 1 to send directly on "Day 0" (Immediately).',
                            'Set Message 2 to send on "Day 3" (Checking in).',
                            'Set Message 3 to send on "Day 7" (Final Discount offer).',
                            'Anyone added to this Drip list will automatically get these messages without you doing anything!'
                        ]
                    ]
                ],
                'Advanced CRM & Analytics' => [
                    [
                        'name' => '12. Lead Scoring',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
                        'purpose' => 'To identify which customers are highly interested in buying based on how much they chat with you.',
                        'steps' => [
                            'This works automatically in the background.',
                            'When a customer replies to your messages frequently, their "Score" increases.',
                            'Go to your <b>Contacts</b> list and sort by "Score" to see your "Hottest Leads".',
                            'Focus your sales calls on these high-score customers first!'
                        ]
                    ],
                    [
                        'name' => '13. Digital vCard',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                        'purpose' => 'To send a professional digital business card link on WhatsApp instead of a boring text message.',
                        'steps' => [
                            'Go to <b>Digital vCard</b>.',
                            'Fill in your name, company logo, phone number, and social media links.',
                            'Click <b>"Generate vCard"</b>.',
                            'You will get a short link. Send this link to customers on WhatsApp.',
                            'When they click it, a beautiful webpage opens with a "Save Contact" button.'
                        ]
                    ],
                    [
                        'name' => '14. Language Detect',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
                        'purpose' => 'To automatically translate incoming messages if the customer speaks a different language.',
                        'steps' => [
                            'Go to <b>Language Settings</b>.',
                            'Turn on "Auto Translate inbound messages".',
                            'If a customer sends a message in Hindi, you will see it translated into English in your WhatsApp Chat.'
                        ]
                    ],
                    [
                        'name' => '15. WAAMINI Pulse',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>',
                        'purpose' => 'To check if your WhatsApp connection is stable and monitor your daily message limits.',
                        'steps' => [
                            'Go to <b>Pulse Monitor</b>.',
                            'Look at the green graphs. If they are green, your WhatsApp API is healthy.',
                            'It also shows how many free messages you have left for the month.'
                        ]
                    ],
                ],
                'Enterprise Suite' => [
                    [
                        'name' => '16. AI Voice Note Analyzer',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>',
                        'purpose' => 'To automatically transcribe WhatsApp voice notes into text and reply using AI.',
                        'steps' => [
                            'Ensure your OpenAI API Key is set in Settings.',
                            'When a customer sends a voice note, the AI will transcribe it instantly.',
                            'The transcript is saved in the chat history for you to read.',
                            'The AI Bot will automatically reply to the voice note based on the text transcript.'
                        ]
                    ],
                    [
                        'name' => '17. Multi-Agent Live Chat',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'A professional helpdesk to manage chats with a large team of agents.',
                        'steps' => [
                            'Go to <b>Multi-Agent Chat</b>.',
                            'Assign chats to specific staff members using the "Assign" dropdown.',
                            'Set the ticket status to <b>Resolved</b> when a customer query is finished.',
                            'Use the filters to see only "Open" tickets or "Unassigned" messages.'
                        ]
                    ],
                    [
                        'name' => '18. Native WhatsApp Checkout',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
                        'purpose' => 'To collect payments directly from customers within the WhatsApp chat.',
                        'steps' => [
                            'Go to <b>Native Checkout</b> and connect your Razorpay or Stripe account.',
                            'During a live chat, click the <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> icon at the bottom.',
                            'Enter the amount and description, then click "Send Link".',
                            'The customer will receive a secure payment link. Once they pay, you will get an automatic "Payment Received" alert.'
                        ]
                    ],
                    [
                        'name' => '19. PDF Quotation Generator',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>',
                        'purpose' => 'To generate and send professional, branded PDF quotations instantly.',
                        'steps' => [
                            'Go to <b>PDF Quotations</b> and upload your company logo and address.',
                            'In the live chat, click the <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> window icon.',
                            'Enter the item name and price, then click "Generate & Send".',
                            'The customer gets a link to view and download their professional quotation.'
                        ]
                    ],
                    [
                        'name' => '20. Loyalty & Rewards Program',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>',
                        'purpose' => 'To reward customers with points for buying and chatting, increasing your sales.',
                        'steps' => [
                            'Go to <b>Loyalty Program</b> and set how many points to give for messages/orders.',
                            'Define a "Threshold" (e.g., 500 points).',
                            'When a customer hits the threshold, WAAMINI will automatically WhatsApp them a "Congratulatory Reward" message with your gift code.'
                        ]
                    ],
                    [
                        'name' => '21. Billing & Meta Spending',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
                        'purpose' => 'To track your Meta API costs and your E-commerce revenue in one place.',
                        'steps' => [
                            'Go to <b>Billing & Wallet</b>.',
                            'Check the <b>Meta API Spend</b> card to see estimated costs for outgoing messages.',
                            'View the <b>E-Commerce Revenue</b> cards to see how much money you have earned from WhatsApp sales today/this month.'
                        ]
                    ]
                ]
            ]
        ],

        // --- TAMIL TRANSLATION ---
        'ta' => [
            'header_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> பயன்பாட்டு கையேடு (User Guide)',
            'header_subtitle' => 'அனைத்து ஆப்ஷன்களையும் எப்படி பயன்படுத்துவது என்று எளிமையாக படங்களுடன் கற்றுக்கொள்ளுங்கள்.',
            'search' => 'எதை பற்றி தேடுகிறீர்கள்...',
            'lbl_purpose' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> இதன் பயன் என்ன?:',
            'lbl_steps' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> எப்படி பயன்படுத்துவது?:',
            'sub_panel_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> உங்கள் சந்தா மற்றும் செயலில் உள்ள வசதிகள்',
            'sub_panel_intro' => 'இந்த வொர்க்ஸ்பேசுக்கு உங்கள் திட்டத்தில் எது இயக்கப்பட்டுள்ளது என்பது கீழே. தொடர்ந்து விவரமான வழிகாட்டுதல்கள் உள்ளன.',
            'sub_plan_label' => 'திட்டம்',
            'sub_status_label' => 'நிலை',
            'sub_expires_label' => 'முடிவு / புதுப்பிப்பு',
            'sub_feature_module' => 'தொகுதி',
            'sub_feature_access' => 'அணுகல்',
            'sub_on' => 'சேர்க்கப்பட்டது',
            'sub_off' => 'சேர்க்கப்படவில்லை',
            'sub_no_plan' => 'செயலில் சந்தா இல்லை. நிர்வாகியை அணுகவும் அல்லது மேம்படுத்தவும்.',
            'sub_limits_title' => 'பயன்பாட்டு வரம்புகள்',
            'sub_limit_fmt' => ':used / :limit',
            'sub_unlimited' => 'வரம்பில்லா',

            'sections' => [
                'Core Modules (முக்கிய வசதிகள்)' => [
                    [
                        'name' => '1. WhatsApp Chat (வாட்ஸ்அப் சாட்)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                        'purpose' => 'மொபைலில் சாட் செய்வது போலவே, கம்ப்யூட்டரில் இருந்து நேரடியாக வாடிக்கையாளர்களிடம் பேசுவதற்கு.',
                        'steps' => [
                            'இடதுபுற மெனுவில் உள்ள <b>WhatsApp Chat</b> என்பதைக் கிளிக் செய்யவும்.',
                            'யாரிடம் பேச வேண்டுமோ, அவர்களின் பெயரை கிளிக் செய்யவும்.',
                            'கீழே உள்ள கட்டத்தில் உங்கள் மெசேஜை டைப் செய்யவும்.',
                            '<b>Send Icon</b> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>-ஐ கிளிக் செய்து அனுப்பவும்.',
                            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> பேப்பர்கிளிப் ஐகானை கிளிக் செய்து போட்டோக்களையும் அனுப்பலாம்.'
                        ]
                    ],
                    [
                        'name' => '2. Contacts (தொடர்புகள்)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'வாடிக்கையாளர்களின் நம்பர்களை சேமித்து வைத்து, பின்னால் அவர்களுக்கு ஆஃபர் மெசேஜ் அனுப்ப.',
                        'steps' => [
                            '<b>Contacts</b> பக்கத்திற்கு செல்லவும்.',
                            'ஒரு நம்பரை சேர்க்க: <b>"+ New Contact"</b> பட்டனை தட்டி, பெயர் மற்றும் நம்பரை (உ.ம்: 919876543210) டைப் செய்து Save செய்யவும்.',
                            'மொத்தமாக சேர்க்க: <b>"Import Contacts"</b> என்பதை கிளிக் செய்யவும்.',
                            'Sample Excel-ஐ டவுன்லோட் செய்து, அதில் உங்கள் எண்களை பதிவு செய்து மீண்டு அப்லோட் செய்யவும்.'
                        ]
                    ],
                    [
                        'name' => '3. Templates (மெசேஜ் வடிவங்கள்)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>',
                        'purpose' => 'பலருக்கு ஆஃபர் அனுப்புவதற்கு முன்பு, அந்த மெசேஜை WhatsApp-யிடம் காட்டி அப்ரூவல் (Approval) வாங்க வேண்டும்.',
                        'steps' => [
                            '<b>Templates -> + New Template</b> செல்லவும்.',
                            'மெசேஜ் வகையை (Marketing) தேர்ந்தெடுக்கவும்.',
                            'உங்கள் மெசேஜை டைப் செய்யவும். "வணக்கம் {{1}}" என்று போட்டால், {{1}} இருக்கும் இடத்தில் வாடிக்கையாளர் பெயர் வந்துவிடும்.',
                            'கீழே சென்று "Website" அல்லது "Call" பட்டன்களைச் சேர்க்கலாம்.',
                            'Save செய்துவிட்டு, WhatsApp அப்ரூவல் தரும் வரை சில நிமிடங்கள் காத்திருக்கவும்.'
                        ]
                    ],
                    [
                        'name' => '4. Campaigns (மொத்த மெசேஜ்)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
                        'purpose' => 'ஒரே நேரத்தில் 1000 பேருக்கு உங்கள் ஆஃபர் மெசேஜையோ, விளம்பரத்தையோ அனுப்ப.',
                        'steps' => [
                            'முதலில் யாருக்கு அனுப்ப வேண்டுமோ அவர்களை ஒரு Label மூலம் பிரிக்கவும் (உ.ம்: "Offer Customers").',
                            '<b>Campaigns -> New Campaign</b> செல்லவும்.',
                            'அந்த "Offer Customers" Label-ஐ தேர்ந்தெடுக்கவும்.',
                            'WhatsApp அப்ரூவல் தந்த Template-ஐ தேர்ந்தெடுக்கவும்.',
                            '<b>Send</b> பட்டனை அழுத்தினால் உடனே அனைவருக்கும் மெசேஜ் சென்றுவிடும். அல்லது Schedule Time-ல் தேதியை செட் செய்து கொள்ளலாம்.'
                        ]
                    ],
                    [
                        'name' => '5. Bot Replies (ஆட்டோ ரிப்ளை)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg>',
                        'purpose' => 'யாராவது "Price" அல்லது "Location" என்று டைப் செய்தால், சிஸ்டம் தானாகவே அவர்களுக்கு பதில் அனுப்பும்.',
                        'steps' => [
                            '<b>Bot Replies -> Add Rule</b> செல்லவும்.',
                            'Keyword கட்டத்தில் "Location" என்று டைப் செய்யவும்.',
                            'Reply கட்டத்தில் உங்கள் கடையின் முகவரியை டைப் செய்யவும்.',
                            'Save செய்த பிறகு, யாராவது "Location" என்று கேட்டால் முகவரி தானாகவே சென்றுவிடும்.'
                        ]
                    ]
                ],
                'Premium Features (ப்ரீமியம் வசதிகள்)' => [
                    [
                        'name' => '6. Zapier / Pabbly Webhooks',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                        'purpose' => 'Facebook Ads, Justdial, Razorpay போன்ற மற்ற সফটவேര்களில் இருந்து வரும் Data-வுக்கு தானாக WhatsApp மெசேஜ் திருப்பியனுப்ப.',
                        'steps' => [
                            '<b>Zapier Webhooks</b> பக்கத்தில் "Generate URL" கிளிக் செய்யவும்.',
                            'தோன்றும் URL-ஐ காப்பி செய்து Pabbly/Zapier-ல் பேஸ்ட் செய்யவும்.',
                            'இனி Facebook-ல் யாராவது Form fill செய்தால் உடனே அவர்களுக்கு உங்களின் Welcome Message சென்றுவிடும்!'
                        ]
                    ],
                    [
                        'name' => '7. AI Image OCR Scanner (போட்டோ நம்பர் ஸ்கேனர்)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
                        'purpose' => 'ஒரு Visiting Card அல்லது நோட்டுப் புத்தகத்தில் உள்ள எண்களை போட்டோ எடுத்து போட்டால், AI அதை படித்து Contacts-ல் சேமித்துவிடும்.',
                        'steps' => [
                            '<b>Premium Growth -> AI Image Scanner</b> செல்லவும்.',
                            'போன் நம்பர்கள் இருகும் போட்டோவை இழுத்து போடவும்.',
                            '10 வினாடிகள் காத்திருக்கவும். AI அதில் உள்ள எண்களை கண்டுபிடித்து காட்டும்.',
                            '<b>Save to Contacts</b> கிளிக் செய்தால் உங்களின் சிஸ்டமில் சேமிக்கப்பட்டு விடும்!'
                        ]
                    ],
                    [
                        'name' => '8. E-Commerce Cart Recovery',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
                        'purpose' => 'Shopify வெப்சைட்டில் ஒரு பொருளை போட்டுவிட்டு வாங்காமல் செல்பவர்களுக்கு, 1 மணி நேரம் கழித்து Discount கொடுத்து வாங்க வைப்பது.',
                        'steps' => [
                            '<b>E-Commerce</b> பக்கத்தில் உள்ள Webhook URL-ஐ காப்பி செய்யவும்.',
                            'உங்கள் வெப்சைட்டில் (Shopify) இதை சேர்க்கவும்.',
                            'இனி யாராவது பொருளை வாங்காமல் விட்டால், அவர்களுக்கு 1 மணி நேரத்தில் தானாக WhatsApp-ல் ரிமைண்டர் போகும்.'
                        ]
                    ]
                ],
                'Enterprise Suite (என்டர்பிரைஸ் வசதிகள்)' => [
                    [
                        'name' => '9. AI Voice Note Analyzer',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>',
                        'purpose' => 'வாட்ஸ்அப்பில் வாடிக்கையாளர்கள் அனுப்பும் வாய்ஸ் மெசேஜ்களை (Voice Notes) எழுத்துக்களாக மாற்ற.',
                        'steps' => [
                            'Settings-ல் உங்களின் OpenAI API Key-ஐ கொடுக்கவும்.',
                            'வாடிக்கையாளர் வாய்ஸ் மெசேஜ் அனுப்பினால், AI அதை உடனே படித்து காட்டும்.',
                            'சாட் ஹிஸ்டரியில் அந்த மெசேஜ் என்னவென்று நீங்கள் படித்துக் கொள்ளலாம்.',
                            'அந்த வாய்ஸ் மெசேஜுக்கு AI பாட் தானாகவே பதில் அனுப்பிவிடும்.'
                        ]
                    ],
                    [
                        'name' => '10. Multi-Agent Live Chat',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'பெரிய டீம் வைத்திருப்பவர்கள் வாடிக்கையாளர்களை கையாள்வதற்கு.',
                        'steps' => [
                            '<b>Multi-Agent Chat</b> செல்லவும்.',
                            'ஒரு மெசேஜை உங்கள் டீம் மெம்பருக்கு "Assign" செய்யலாம்.',
                            'பேசி முடித்தவுடன் அதன் ஸ்டேட்டஸை (Status) <b>Resolved</b> என்று மாற்றிவிடலாம்.'
                        ]
                    ],
                    [
                        'name' => '11. Native WhatsApp Checkout',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
                        'purpose' => 'வாட்ஸ்அப் சாட்டில் இருந்தே வாடிக்கையாளர்களிடம் பணம் வசூலிக்க.',
                        'steps' => [
                            '<b>Native Checkout</b> பக்கத்தில் உங்களின் Razorpay அல்லது Stripe-ஐ இணைக்கவும்.',
                            'சாட் செய்யும் போது கீழே உள்ள <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> ஐகானை அழுத்தவும்.',
                            'தொகையை டைப் செய்து அனுப்பிவிடலாம். வாடிக்கையாளர் பணம் செலுத்தியவுடன் உங்களுக்கு தகவல் வந்துவிடும்.'
                        ]
                    ],
                    [
                        'name' => '12. PDF Quotation Generator',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>',
                        'purpose' => 'உங்கள் கடையின் லோகோ (Logo) உடன் கூடிய புரொபஷனல் கொட்டேஷன் (Quotation) அனுப்ப.',
                        'steps' => [
                            '<b>PDF Quotations</b> பக்கத்தில் உங்கள் லோகோ மற்றும் முகவரியை பதிவு செய்யவும்.',
                            'சாட்டில் உள்ள <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> ஐகானை கிளிக் செய்து என்ன பொருள் மற்றும் விலை என்று கொடுக்கவும்.',
                            'வாடிக்கையாளருக்கு அழகிய PDF லிங்க் தானாகவே சென்றுவிடும்.'
                        ]
                    ],
                    [
                        'name' => '13. Loyalty & Rewards Program',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>',
                        'purpose' => 'அதிகமாக பொருள் வாங்கும் வாடிக்கையாளர்களுக்கு தானாகவே கிப்ட் கார்டு (Gift Card) அனுப்ப.',
                        'steps' => [
                            '<b>Loyalty Program</b> பக்கத்தில் விதிகளை உருவாக்கவும்.',
                            'வாடிக்கையாளர் ஒரு குறிப்பிட்ட புள்ளிகளை (Points) எட்டியவுடன், அவர்களுக்கு வாட்ஸ்அப்பில் வாழ்த்துச் செய்தியுடன் கிப்ட் கோடு சென்றுவிடும்.'
                        ]
                    ],
                    [
                        'name' => '14. Billing & Meta Spending',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
                        'purpose' => 'மெட்டாவுக்காக (Meta) எவ்வளவு செலவாகிறது மற்றும் எவ்வளவு வருமானம் வருகிறது என்று ஒரே இடத்தில் பார்க்க.',
                        'steps' => [
                            '<b>Billing & Wallet</b> பக்கத்திற்கு செல்லவும்.',
                            '<b>Meta API Spend</b> கட்டத்தில் உங்கள் மெசேஜ்களுக்காக எவ்வளவு செலவாகிறது என்று செக் செய்யவும்.',
                            '<b>E-Commerce Revenue</b> கட்டத்தில் இன்று எவ்வளவு வியாபாரம் நடந்துள்ளது என்று பார்த்துக் கொள்ளலாம்.'
                        ]
                    ]
                ]
            ]
        ],

        // --- HINDI TRANSLATION ---
        'hi' => [
            'header_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> उपयोगकर्ता मार्गदर्शिका (User Guide)',
            'header_subtitle' => 'हर फीचर का उपयोग कैसे करें, इसके लिए आसान स्टेप-दर-स्टेप निर्देश।',
            'search' => 'किसी विकल्प के लिए खोजें...',
            'lbl_purpose' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> इसका फायदा क्या है?:',
            'lbl_steps' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> कैसे उपयोग करें?:',
            'sub_panel_title' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> आपकी सदस्यता और सक्षम फ़ीचर',
            'sub_panel_intro' => 'नीचे देखें इस वर्कस्पेस के लिए आपके प्लान में क्या शामिल है। विस्तृत गाइड आगे मिलेगी।',
            'sub_plan_label' => 'प्लान',
            'sub_status_label' => 'स्थिति',
            'sub_expires_label' => 'समाप्ति / नवीनीकरण',
            'sub_feature_module' => 'मॉड्यूल',
            'sub_feature_access' => 'पहुँच',
            'sub_on' => 'शामिल',
            'sub_off' => 'शामिल नहीं',
            'sub_no_plan' => 'कोई सक्रिय सदस्यता नहीं। एडमिन से संपर्क करें या अपग्रेड करें।',
            'sub_limits_title' => 'उपयोग सीमाएँ',
            'sub_limit_fmt' => ':used / :limit',
            'sub_unlimited' => 'असीमित',

            'sections' => [
                'Core Modules (मुख्य फीचर्स)' => [
                    [
                        'name' => '1. WhatsApp Chat (व्हाट्सएप चैट)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                        'purpose' => 'ग्राहकों से कंप्यूटर के ज़रिए सीधे व्हाट्सएप पर बात करने के लिए।',
                        'steps' => [
                            'बाईं ओर मेनू से <b>WhatsApp Chat</b> पर क्लिक करें।',
                            'किसी ग्राहक के नाम पर क्लिक करके चैट खोलें।',
                            'नीचे बॉक्स में अपना रिप्लाई टाइप करें।',
                            '<b>Send</b> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> आइकन पर क्लिक करके भेजें।',
                            'फोटो भेजने के लिए <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> पेपरक्लिप आइकन का उपयोग करें।'
                        ]
                    ],
                    [
                        'name' => '2. Contacts (संपर्क)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'purpose' => 'ग्राहकों के नंबर सेव करने के लिए ताकि बाद में उन्हें ऑफर भेजा जा सके।',
                        'steps' => [
                            '<b>Contacts</b> पेज पर जाएं।',
                            'एक व्यक्ति को जोड़ने के लिए: <b>"+ New Contact"</b> पर क्लिक करें, नाम और नंबर डालकर सेव करें।',
                            'एक साथ बहुत सारे नंबर जोड़ने के लिए: <b>"Import Contacts"</b> पर क्लिक करें।',
                            'एक्सेल (CSV) फ़ाइल डाउनलोड करें, अपने नंबर भरें और वापस अपलोड कर दें।'
                        ]
                    ],
                    [
                        'name' => '3. Templates (टेम्प्लेट)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>',
                        'purpose' => 'बल्क मैसेज भेजने से पहले व्हाट्सएप से मैसेज का फॉर्मेट पास (Approve) करवाना पड़ता है।',
                        'steps' => [
                            '<b>Templates -> + New Template</b> पर जाएं।',
                            'कैटेगरी चुनें (जैसे Marketing)।',
                            'अपना मैसेज लिखें। "नमस्ते {{1}}" लिखेंगे तो भेजने पर {{1}} की जगह ग्राहक का नाम आ जाएगा।',
                            'नीचे जाकर Button (Call To Action) जोड़ें।',
                            'सेव करें। व्हाट्सएप कुछ मिनटों में इसे Approve कर देगा।'
                        ]
                    ],
                    [
                        'name' => '4. Campaigns (बल्क मैसेज)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
                        'purpose' => 'एक क्लिक में 1000+ ग्राहकों को एक साथ विज्ञापन या ऑफर मैसेज भेजने के लिए।',
                        'steps' => [
                            'पहले ग्राहकों को एक Label दें (जैसे "Offer Users")।',
                            '<b>Campaigns -> New Campaign</b> पर जाएं।',
                            'Label चुनें और Approve हुआ Template चुनें।',
                            'तुरंत भेजने के लिए <b>Send</b> पर क्लिक करें, या बाद में भेजने के लिए Schedule Time सेट करें।'
                        ]
                    ],
                    [
                        'name' => '5. Bot Replies (ऑटो रिप्लाई)',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg>',
                        'purpose' => 'अगर कोई "Price" लिखता है, तो सिस्टम खुद ही रेट लिस्ट भेज देगा।',
                        'steps' => [
                            '<b>Bot Replies -> Add Rule</b> पर जाएं।',
                            'Keyword बॉक्स में "Price" लिखें।',
                            'Reply बॉक्स में अपनी रेट लिस्ट लिखें।',
                            'सेव करें। अब कोई भी "Price" पूछेगा तो उसे अपने आप रिप्लाई मिल जाएगा।'
                        ]
                    ]
                ],
                'Premium Features (प्रीमियम फीचर्स)' => [
                    [
                        'name' => '6. Zapier / Pabbly Webhooks',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                        'purpose' => 'फेसबुक (FB Ads) या वेबसाइट से नया लीड आते ही अपने आप उसे व्हाट्सएप मैसेज भेजने के लिए।',
                        'steps' => [
                            '<b>Zapier Webhooks</b> में "Generate URL" पर क्लिक करें।',
                            'लिंक कॉपी करके अपनी Pabbly / Zapier वेबसाइट में डालें।',
                            'अब फेसबुक में फॉर्म भरते ही ग्राहक को तुरंत व्हाट्सएप पर आपका मैसेज मिल जाएगा।'
                        ]
                    ],
                    [
                        'name' => '7. AI Image OCR Scanner',
                        'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
                        'purpose' => 'विजिटिंग कार्ड या पन्नों की फोटो खींचकर उसमें से नंबर निकालकर अपने आप Contacts में सेव करने के लिए।',
                        'steps' => [
                            '<b>Premium Growth -> AI Image Scanner</b> पर जाएं।',
                            'कार्ड की फोटो अपलोड करें।',
                            '10 सेकंड रुकें। AI सारे नंबर और नाम पढ़कर स्क्रीन पर दिखाएगा।',
                            '<b>Save to Contacts</b> दबाएं और सारे नंबर आपके CRM में सेव हो जाएंगे!'
                        ]
                    ]
                ]
            ]
        ]
    ];

    return $features[$lang] ?? $features['en'];
}

/**
 * Keys must match has_feature() / feature_check.php $map.
 */
function waamini_plan_feature_matrix(): array
{
    return [
        ['key' => 'whatsapp_chat', 'label' => 'WhatsApp chat inbox'],
        ['key' => 'contacts', 'label' => 'Contacts (CRM)'],
        ['key' => 'templates', 'label' => 'WhatsApp message templates'],
        ['key' => 'campaigns', 'label' => 'Broadcast campaigns'],
        ['key' => 'team_members', 'label' => 'Team members (seats)'],
        ['key' => 'bot_replies', 'label' => 'Keyword & auto-reply rules'],
        ['key' => 'auto_followup', 'label' => 'Auto follow-up'],
        ['key' => 'lead_scoring', 'label' => 'Lead scoring'],
        ['key' => 'drip_campaign', 'label' => 'Drip / sequence messaging'],
        ['key' => 'qr_code', 'label' => 'QR code & chat link tools'],
        ['key' => 'indiamart', 'label' => 'IndiaMART leads'],
        ['key' => 'fb_lead_ads', 'label' => 'Facebook Lead Ads'],
        ['key' => 'language_detect', 'label' => 'Language detection / reply language'],
        ['key' => 'api_webhook', 'label' => 'REST API & outbound webhooks'],
        ['key' => 'google_sheets', 'label' => 'Google Sheets integration'],
        ['key' => 'pulse', 'label' => 'Pulse workflows / automations'],
        ['key' => 'crm_advance', 'label' => 'Advanced CRM (labels, pipelines)'],
        ['key' => 'vcard', 'label' => 'vCard / digital business card'],
        ['key' => 'quick_replies', 'label' => 'Quick replies in chat composer'],
        ['key' => 'native_checkout', 'label' => 'Native WhatsApp checkout & payments (catalog → pay)'],
    ];
}

/** Resources supported by get_limit() / get_usage() in feature_check.php */
function waamini_plan_usage_resources(): array
{
    return [
        ['resource' => 'contacts', 'label' => 'Contacts stored'],
        ['resource' => 'campaigns', 'label' => 'Campaigns created (calendar month)'],
        ['resource' => 'bot_replies', 'label' => 'Bot reply rules saved'],
        ['resource' => 'team_members', 'label' => 'Team accounts'],
        ['resource' => 'messages_month', 'label' => 'Outgoing WhatsApp messages (calendar month)'],
    ];
}
?>
