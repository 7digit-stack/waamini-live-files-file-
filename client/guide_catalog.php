<?php
/**
 * Client user guide catalogue (used by client/manual.php).
 * Each guide is shown only when the client's plan includes it — the same feature keys the sidebar menu uses.
 *   need => all of these features   any => at least one of these features   (neither => always shown)
 * Text is trusted markup (only <b> is used).
 */
if (!defined('WAAMINI_LOADED') && !isset($_SESSION['user_id'])) {
    http_response_code(403); exit;
}

function waamini_guide_categories(): array
{
    return [
        'start'      => ['Getting started', '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>'],
        'messaging'  => ['Chats & campaigns', '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>'],
        'contacts'   => ['Contacts', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'automation' => ['Bots & automation', '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>'],
        'sales'      => ['Sales & e-commerce', '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>'],
        'growth'     => ['Ads & insights', '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>'],
        'reminders'  => ['Reminders', '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'],
        'connect'    => ['Integrations', '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>'],
        'account'    => ['Account & team', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
    ];
}

function waamini_guide_catalog(): array
{
    $I = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'link'   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'chat'   => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'mega'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'tpl'    => '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/>',
        'clock'  => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'bars'   => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'gift'   => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'ban'    => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'qr'     => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'reply'  => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/>',
        'cal'    => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'bot'    => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
        'flow'   => '<rect width="8" height="8" x="3" y="3" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect width="8" height="8" x="13" y="13" rx="2"/>',
        'form'   => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/>',
        'drip'   => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
        'scan'   => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'mic'    => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
        'cart'   => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'box'    => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'file'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'receipt'=> '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'star'   => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
        'shop'   => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'pulse'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'heart'  => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'share'  => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98"/><path d="m15.41 6.51-6.82 3.98"/>',
        'party'  => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/>',
        'school' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'car'    => '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
        'pill'   => '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>',
        'plug'   => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'code'   => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        'sheet'  => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'bell'   => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'card'   => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
        'help'   => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'gear'   => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
    ];

    return [
        // ── Getting started ──
        ['key' => 'connect-whatsapp', 'cat' => 'start', 'icon' => $I['link'], 'page' => 'settings_whatsapp.php', 'need' => ['set_whatsapp'],
         'title' => 'Connect your WhatsApp number',
         'summary' => 'Link your WhatsApp Business number to Waamini so you can send and receive messages.',
         'steps' => [
            'Open <b>Settings → WhatsApp</b> from the menu.',
            'In Meta Business, create a Business App and add the WhatsApp product.',
            'Copy the <b>Phone Number ID</b>, <b>Facebook App ID</b> and <b>App Secret</b> into the form.',
            'Generate a <b>Permanent Access Token</b> in Meta and paste it in.',
            'Click <b>Save</b>, then follow <b>Set up Webhook</b> so replies reach your Chats.',
         ],
         'tips' => ['Use a permanent token, not a 24-hour test token, or the connection stops tomorrow.', 'Stuck? Our team can connect it for you — raise a ticket from Support.']],

        ['key' => 'dashboard', 'cat' => 'start', 'icon' => $I['home'], 'page' => 'dashboard.php',
         'title' => 'Read your dashboard',
         'summary' => 'See messages sent this month, your plan, recent activity and quick actions in one place.',
         'steps' => [
            'Open <b>Dashboard</b> — it is the first page after you log in.',
            'Check <b>Messages this month</b> and the <b>Messages trend</b> chart.',
            'Use <b>Quick actions</b> to start a campaign, add a contact or open Chats.',
            'Your <b>Current plan</b> card shows what is included and when it renews.',
         ]],

        ['key' => 'business-profile', 'cat' => 'start', 'icon' => $I['gear'], 'page' => 'settings_general.php', 'need' => ['set_general'],
         'title' => 'Set up your business details',
         'summary' => 'Add your business name, logo and contact details used across Waamini.',
         'steps' => [
            'Open <b>Settings → General</b>.',
            'Fill in your business name, address and contact number.',
            'Upload your logo so it appears on documents and pages.',
            'Click <b>Save</b>.',
         ]],

        // ── Chats & campaigns ──
        ['key' => 'chats', 'cat' => 'messaging', 'icon' => $I['chat'], 'page' => 'whatsapp.php', 'need' => ['whatsapp_chat'],
         'title' => 'Reply to customers in Chats',
         'summary' => 'Read and answer every WhatsApp conversation from your computer or phone.',
         'steps' => [
            'Open <b>WhatsApp Chat</b> from the menu.',
            'Pick a customer on the left to open the conversation.',
            'Type your reply and press <b>Enter</b> or the send button.',
            'Use the paperclip to send images, PDFs or documents.',
            'Open <b>Contact info</b> on the right to see notes, tags and lead details.',
         ],
         'tips' => ['You can reply freely for 24 hours after a customer’s last message. After that, send an approved template.']],

        ['key' => 'templates', 'cat' => 'messaging', 'icon' => $I['tpl'], 'page' => 'templates.php', 'need' => ['templates'],
         'title' => 'Create a message template',
         'summary' => 'Templates are messages approved by Meta — needed for campaigns and for chats older than 24 hours.',
         'steps' => [
            'Open <b>Templates</b> and click <b>+ Create template</b>.',
            'Choose a category: <b>Marketing</b>, <b>Utility</b> or <b>Authentication</b>.',
            'Give it a name in lowercase with underscores, e.g. <b>diwali_offer</b>.',
            'Write the body. Use <b>{{1}}</b>, <b>{{2}}</b> for details like the customer’s name.',
            'Add a header, footer or buttons if you want, then click <b>Submit for Review</b>.',
            'Approval usually takes a few minutes. Approved templates show <b>Ready to send</b>.',
         ],
         'tips' => ['Not sure what to write? Open <b>Ready-made examples</b> on the Templates page and copy one.', 'Approved templates can’t be edited. Use <b>Edit</b> to make a new version instead.']],

        ['key' => 'campaigns', 'cat' => 'messaging', 'icon' => $I['mega'], 'page' => 'campaigns.php', 'need' => ['campaigns'],
         'title' => 'Send a campaign',
         'summary' => 'Send one approved template to many contacts at once — offers, updates or reminders.',
         'steps' => [
            'Open <b>Campaigns</b> and click <b>+ Create campaign</b>.',
            '<b>Choose a template</b> — only approved templates can be sent.',
            '<b>Fill in the variables</b>, like the customer’s name or offer amount.',
            'Under <b>Audience and schedule</b>, pick a contact group or all contacts.',
            'Send now, or pick a date and time to send later.',
            'Watch delivered, read and failed counts on the Campaigns page.',
         ],
         'tips' => ['Shortcut: on the Templates page, click <b>Create campaign</b> next to any approved template.', 'Send a test to your own number first.']],

        ['key' => 'scheduled', 'cat' => 'messaging', 'icon' => $I['clock'], 'page' => 'scheduled_messages.php', 'need' => ['feat_scheduled_msg'],
         'title' => 'Schedule messages',
         'summary' => 'Plan messages ahead so they go out automatically at the right time.',
         'steps' => [
            'Open <b>Scheduled Messages</b>.',
            'Click <b>Schedule a message</b> and pick the template and contacts.',
            'Choose the date and time, then save.',
            'Track them under <b>Upcoming deliveries</b> and <b>Deliveries</b>.',
         ]],

        ['key' => 'campaign-reports', 'cat' => 'messaging', 'icon' => $I['bars'], 'page' => 'campaign_reports.php', 'need' => ['feat_campaign_reports'],
         'title' => 'Check campaign results',
         'summary' => 'See which campaigns were delivered, read and answered.',
         'steps' => [
            'Open <b>Campaign Reports</b>.',
            'See your <b>Top campaign</b> and <b>Performance over time</b>.',
            'Open a campaign to see delivered, read, replied and failed numbers.',
            'Use the <b>Insights</b> to pick the best time and template for next time.',
         ]],

        ['key' => 'festival', 'cat' => 'messaging', 'icon' => $I['party'], 'page' => 'festival_campaigns.php', 'need' => ['festival_reminder'],
         'title' => 'Festival & occasion wishes',
         'summary' => 'Send festival greetings and birthday or anniversary wishes automatically.',
         'steps' => [
            'Open <b>Festival Campaigns</b>.',
            'See festivals in the <b>Next 60 days</b> and click <b>Create festival wish</b>.',
            'Pick a template and the contacts to wish.',
            'Under <b>Personal occasions</b>, add birthdays or anniversaries with <b>Save date</b>.',
         ]],

        // ── Contacts ──
        ['key' => 'contacts', 'cat' => 'contacts', 'icon' => $I['users'], 'page' => 'contacts.php', 'need' => ['contacts'],
         'title' => 'Add and manage contacts',
         'summary' => 'Keep your customer list in one place so you can message them anytime.',
         'steps' => [
            'Open <b>Contacts</b> and click <b>+ Add contact</b>.',
            'Enter the name and number with country code, e.g. <b>919876543210</b>.',
            'Search or filter to find people, and select rows to delete or group them.',
            'Click <b>Export Excel</b> to download your list.',
         ],
         'tips' => ['Always include the country code (91 for India), without + or spaces.']],

        ['key' => 'groups', 'cat' => 'contacts', 'icon' => $I['folder'], 'page' => 'contact_groups.php', 'need' => ['contacts', 'contact_groups'],
         'title' => 'Organise contacts into groups',
         'summary' => 'Group customers (e.g. VIP, Chennai, New leads) to send campaigns to the right people.',
         'steps' => [
            'Open <b>Contacts → Groups</b> and click <b>+ New group</b>.',
            'Name the group and click <b>Create group</b>.',
            'Add contacts to it from the Contacts page, or <b>Import</b> a list straight into the group.',
            'Pick the group as the audience when you create a campaign.',
         ]],

        ['key' => 'bulk-import', 'cat' => 'contacts', 'icon' => $I['upload'], 'page' => 'contact_bulk.php', 'need' => ['feat_contact_import'],
         'title' => 'Import contacts from Excel',
         'summary' => 'Add hundreds of contacts at once from a CSV file.',
         'steps' => [
            'Open <b>Bulk Contacts</b>.',
            'Check the <b>CSV format</b> and download the sample file.',
            'Fill in names and numbers in Excel and save it as CSV.',
            'Click <b>Upload a CSV file</b>. New and duplicate counts are shown when it finishes.',
         ],
         'tips' => ['Keep numbers as text in Excel so the leading digits aren’t lost.']],

        ['key' => 'opt-out', 'cat' => 'contacts', 'icon' => $I['ban'], 'page' => 'blacklist.php', 'need' => ['feat_blacklist'],
         'title' => 'Manage opt-outs',
         'summary' => 'Stop messaging people who asked not to be contacted, and protect your number’s quality.',
         'steps' => [
            'Open <b>Opt-out Manager</b>.',
            'Click <b>Add to opt-out list</b> and enter the number.',
            'Opted-out numbers are skipped in every campaign automatically.',
         ],
         'tips' => ['Respecting opt-outs keeps your number’s quality rating high with Meta.']],

        ['key' => 'qr-code', 'cat' => 'contacts', 'icon' => $I['qr'], 'page' => 'qr_code.php', 'need' => ['qr_code'],
         'title' => 'Get a WhatsApp QR code',
         'summary' => 'Print a QR code so customers can message you in one scan.',
         'steps' => [
            'Open <b>WhatsApp QR Code</b>.',
            'Pick a purpose like <b>Place an order</b> or <b>Book appointment</b> — it pre-fills the first message.',
            'Click <b>Customise</b> to change the text, then <b>Preview</b>.',
            'Download it and put it on your counter, bills, menu or packaging.',
         ]],

        // ── Bots & automation ──
        ['key' => 'bot-replies', 'cat' => 'automation', 'icon' => $I['reply'], 'page' => 'bot_replies.php', 'need' => ['bot_replies'],
         'title' => 'Set up auto-replies',
         'summary' => 'Reply instantly when customers send words like “price”, “hi” or “address”.',
         'steps' => [
            'Open <b>Bot Replies</b> and click <b>+ New rule</b>.',
            'Type the keywords customers send, e.g. <b>price, rate, cost</b>.',
            'Write the reply. Use <b>+ Customer name</b> to personalise it.',
            'Add up to 3 buttons if you want, then save.',
         ],
         'tips' => ['Make a “hi / hello” rule with a short menu — it answers most first messages.']],

        ['key' => 'demo-bookings', 'cat' => 'automation', 'icon' => $I['cal'], 'page' => 'demo_bookings.php', 'need' => ['bot_replies', 'demo_bookings'],
         'title' => 'Handle demo bookings',
         'summary' => 'See appointments booked through WhatsApp and confirm them in one click.',
         'steps' => [
            'Open <b>Demo Bookings</b>.',
            'New requests appear with the customer’s name, date and time.',
            'Click <b>Confirm + Notify</b> to confirm and send them a WhatsApp message.',
            'Mark it <b>Completed</b> after the meeting, or open the chat to talk to them.',
         ]],

        ['key' => 'waamini-bot', 'cat' => 'automation', 'icon' => $I['bot'], 'page' => 'waamini_bot.php', 'need' => ['ai_chatbot'],
         'title' => 'Train the WAAMINI AI bot',
         'summary' => 'An AI assistant that answers customer questions using your business information.',
         'steps' => [
            'Open <b>WAAMINI Bot</b> and click <b>Finish setup</b>.',
            'Under <b>Instructions</b>, tell the bot how to talk and what to avoid.',
            'Open <b>Knowledge</b> and add your products, prices, timings and FAQs.',
            'Use <b>Bot Settings</b> to set follow-up messages and when to hand over to a person.',
            'Test it with the chat box on the Overview page.',
         ],
         'tips' => ['The more specific your Knowledge, the better the answers.', 'In Chats, use <b>Resume bot</b> to let the bot take over again after you reply.']],

        ['key' => 'flow-builder', 'cat' => 'automation', 'icon' => $I['flow'], 'page' => 'chatbot_flows.php', 'need' => ['feat_chatbot_builder'],
         'title' => 'Build a chatbot flow',
         'summary' => 'Design step-by-step conversations with buttons and branches — no coding.',
         'steps' => [
            'Open <b>Flow Builder</b> and click <b>New flow</b>.',
            'Set the <b>Triggers</b> — the words that start the flow.',
            'Add message, question and button steps, and connect them.',
            'Click <b>Save</b> and test it by sending the trigger word from your phone.',
         ]],

        ['key' => 'wa-flows', 'cat' => 'automation', 'icon' => $I['form'], 'page' => 'premium_flows.php', 'need' => ['whatsapp_flows'],
         'title' => 'Create WhatsApp forms (Flows)',
         'summary' => 'Collect leads, bookings and feedback with forms that open inside WhatsApp.',
         'steps' => [
            'Open <b>WhatsApp Flows</b>.',
            'Pick a starting point: <b>Lead enquiry</b>, <b>Appointment</b>, <b>Feedback</b> or <b>Blank</b>.',
            'Click <b>Open in visual builder</b> to edit the fields.',
            'Publish it, then attach it to a template button.',
         ],
         'tips' => ['Made it in Meta already? Use <b>Sync templates</b> to bring it in.']],

        ['key' => 'drip', 'cat' => 'automation', 'icon' => $I['drip'], 'page' => 'premium_drip.php', 'need' => ['feat_drip_campaign'],
         'title' => 'Auto follow-ups (drip)',
         'summary' => 'Send a series of messages over days to new leads, automatically.',
         'steps' => [
            'Open <b>Auto Follow-up</b>.',
            'Create a sequence with a <b>Campaign Name</b> and a <b>Trigger Condition</b>.',
            'Add each message and how many days after the previous one it should go.',
            'Save. New contacts who match the trigger get the series automatically.',
         ]],

        ['key' => 'ocr', 'cat' => 'automation', 'icon' => $I['scan'], 'page' => 'premium_ocr.php', 'need' => ['feat_ocr_scanner'],
         'title' => 'Scan numbers from photos',
         'summary' => 'Take a photo of a visiting card or register and save the numbers as contacts.',
         'steps' => [
            'Open <b>OCR Scanner</b>.',
            'Click or drag an image in (JPG, PNG or WEBP, up to 5 MB).',
            'Check the numbers found in the results.',
            'Click <b>Save to CRM</b> to add them to Contacts.',
         ]],

        ['key' => 'voice', 'cat' => 'automation', 'icon' => $I['mic'], 'page' => 'premium_voice.php', 'need' => ['feat_voice_analyzer'],
         'title' => 'Review voice notes',
         'summary' => 'See all customer voice notes in one list, with the text where available.',
         'steps' => [
            'Open <b>Voice Analyzer</b>.',
            'Filter by <b>24h</b>, <b>7d</b>, <b>30d</b> or all time.',
            'Read the text or caption, and click <b>Open chat</b> to reply.',
         ]],

        // ── Sales & e-commerce ──
        ['key' => 'catalog', 'cat' => 'sales', 'icon' => $I['box'], 'page' => 'catalog.php', 'need' => ['feat_native_checkout', 'ec_catalog'],
         'title' => 'Add products to your catalogue',
         'summary' => 'List your products so customers can browse and order on WhatsApp.',
         'steps' => [
            'Open <b>Catalog</b>.',
            'Add a product with name, price, photo and description.',
            'Click <b>Save Product</b>.',
            'Customers can ask about it with <b>Send Enquiry</b>, and orders come into Orders.',
         ]],

        ['key' => 'orders', 'cat' => 'sales', 'icon' => $I['cart'], 'page' => 'orders.php', 'need' => ['feat_native_checkout', 'ec_orders'],
         'title' => 'Manage orders',
         'summary' => 'Track every WhatsApp order from new to delivered.',
         'steps' => [
            'Open <b>Orders</b>.',
            'Open an order to see the items and the customer.',
            'Use <b>Update Status</b> as it moves — the customer gets a WhatsApp update.',
            'Click <b>Mark as Paid</b> when you receive payment.',
         ]],

        ['key' => 'checkout', 'cat' => 'sales', 'icon' => $I['card'], 'page' => 'ecommerce_settings.php', 'need' => ['feat_native_checkout', 'ec_checkout'],
         'title' => 'Set up payments & checkout',
         'summary' => 'Connect your payment gateway so customers can pay inside WhatsApp.',
         'steps' => [
            'Open <b>E-commerce Settings</b> → <b>Payments &amp; checkout</b>.',
            'Enter your payment gateway keys.',
            'Copy the webhook URL into your gateway with <b>Copy URL</b>.',
            'Click <b>Test webhook</b>, then <b>Save settings</b>.',
         ]],

        ['key' => 'sales-dashboard', 'cat' => 'sales', 'icon' => $I['bars'], 'page' => 'order_dashboard.php', 'need' => ['feat_native_checkout', 'ec_analytics'],
         'title' => 'Track sales',
         'summary' => 'See revenue, delivered orders and how much the AI bot sold.',
         'steps' => [
            'Open <b>Order Dashboard</b>.',
            'Check revenue for the <b>Last 7 days</b> and your <b>Best day</b>.',
            'See <b>Bot revenue</b> — sales closed by the AI bot.',
         ]],

        ['key' => 'catalog-reports', 'cat' => 'sales', 'icon' => $I['bars'], 'page' => 'catalog_reports.php', 'need' => ['feat_native_checkout', 'ec_reports'],
         'title' => 'Catalogue reports',
         'summary' => 'See which products customers look at and ask about most.',
         'steps' => [
            'Open <b>Catalog Reports</b>.',
            'Choose dates and click <b>Apply</b>.',
            'Check <b>Interactions per day</b> and the top products.',
         ]],

        ['key' => 'shopify', 'cat' => 'sales', 'icon' => $I['shop'], 'page' => 'shopify_connect.php', 'need' => ['shopify'],
         'title' => 'Connect your Shopify store',
         'summary' => 'Send order updates and abandoned-cart reminders to Shopify customers on WhatsApp.',
         'steps' => [
            'Open <b>Integrations → Shopify</b>.',
            'Enter your store and app details, then click <b>Save Shopify app</b>.',
            'Choose the messages to send (order placed, shipped, cart reminder) and click <b>Save messages</b>.',
            'Click <b>Re-check events</b> to confirm Shopify is sending updates.',
         ]],

        ['key' => 'quotes', 'cat' => 'sales', 'icon' => $I['file'], 'page' => 'pdf_settings.php', 'need' => ['feat_pdf_quotes'],
         'title' => 'Send PDF quotations',
         'summary' => 'Create branded quotations and share them as PDF on WhatsApp.',
         'steps' => [
            'Open <b>PDF Quotations</b>.',
            'Fill in <b>Company Branding</b>: name, logo, address and GSTIN.',
            'Add <b>Bank Account Details</b> and <b>Notes &amp; Terms</b>.',
            'Add your products, check the preview, and save.',
         ]],

        ['key' => 'gst', 'cat' => 'sales', 'icon' => $I['receipt'], 'page' => 'gst_invoices.php', 'need' => ['feat_gst_invoice'],
         'title' => 'Create GST invoices',
         'summary' => 'Make GST invoices and send them to customers on WhatsApp.',
         'steps' => [
            'Open <b>GST Invoices</b>.',
            'Click <b>Quick invoice</b> and enter the customer and items.',
            'Save and send it — track it under <b>Recent invoices</b>.',
         ]],

        ['key' => 'loyalty', 'cat' => 'sales', 'icon' => $I['star'], 'page' => 'premium_loyalty.php', 'need' => ['feat_loyalty_points'],
         'title' => 'Run a loyalty program',
         'summary' => 'Give customers points for orders and reward repeat buyers.',
         'steps' => [
            'Open <b>Loyalty &amp; Rewards</b> and click <b>Activate loyalty engine</b>.',
            'Set <b>Points per paid order</b> and <b>Points per message sent</b>.',
            'Set the <b>Reward threshold</b> — points needed for a reward.',
            'See <b>Customers with points</b> as they collect them.',
         ]],

        // ── Ads & insights ──
        ['key' => 'ads-leads', 'cat' => 'growth', 'icon' => $I['target'], 'page' => 'ads_leads.php', 'need' => ['ads_attribution'],
         'title' => 'See leads from your ads',
         'summary' => 'Know which Facebook and Instagram ad brought each WhatsApp lead.',
         'steps' => [
            'Open <b>Ads Leads</b>.',
            'Pick a date range and click <b>Apply</b>.',
            'See <b>Leads by ad</b>, then open an ad to see <b>Customers from this ad</b>.',
         ]],

        ['key' => 'capi', 'cat' => 'growth', 'icon' => $I['share'], 'page' => 'ads_capi.php', 'need' => ['ads_capi'],
         'title' => 'Send sales back to Meta Ads',
         'summary' => 'Tell Meta which chats became sales so your ads find more buyers.',
         'steps' => [
            'Open <b>Conversions API</b> and click <b>Finish setup</b>.',
            'Choose the <b>Conversion Events</b> to send (lead, purchase).',
            'Check the <b>Event log</b>, and use <b>Retry</b> on any that failed.',
         ]],

        ['key' => 'analytics', 'cat' => 'growth', 'icon' => $I['bars'], 'page' => 'premium_analytics.php', 'need' => ['feat_crm_analytics'],
         'title' => 'CRM analytics',
         'summary' => 'Lead growth, delivery performance and bot conversations in charts.',
         'steps' => [
            'Open <b>CRM Analytics</b>.',
            'Switch between <b>14d</b> and <b>30d</b>.',
            'Check <b>Lead growth</b>, <b>Delivery performance</b> and the <b>Health check</b>.',
         ]],

        ['key' => 'pulse', 'cat' => 'growth', 'icon' => $I['pulse'], 'page' => 'pulse.php', 'need' => ['pulse'],
         'title' => 'Keep your account healthy (Pulse)',
         'summary' => 'Pulse spots problems like failed messages and tells you how to fix them.',
         'steps' => [
            'Open <b>Pulse</b>.',
            'See the <b>Most common issues</b> from the last 14 days.',
            'Fix each one, then click <b>Mark as fixed</b>.',
         ]],

        ['key' => 'wa-health', 'cat' => 'growth', 'icon' => $I['heart'], 'page' => 'wa_health.php', 'need' => ['feat_wa_health'],
         'title' => 'Check your number’s health',
         'summary' => 'See your verified name, messaging tier and recent API activity.',
         'steps' => [
            'Open <b>WhatsApp Health</b>.',
            'Check the <b>Messaging tier</b> — how many people you can message per day.',
            'Review <b>API activity</b> for the <b>Last 24 hours</b>.',
         ]],

        // ── Reminders ──
        ['key' => 'school-fees', 'cat' => 'reminders', 'icon' => $I['school'], 'page' => 'school_fees.php', 'need' => ['school_fee_reminder'],
         'title' => 'School fee reminders',
         'summary' => 'Remind parents about fee due dates on WhatsApp automatically.',
         'steps' => [
            'Open <b>School Fees</b> and click <b>Add student</b>.',
            'Click <b>Add fee</b> with the amount and due date.',
            'Parents get a reminder before the due date — no manual work.',
         ]],

        ['key' => 'vehicle', 'cat' => 'reminders', 'icon' => $I['car'], 'page' => 'vehicle_reminders.php', 'need' => ['vehicle_reminder'],
         'title' => 'Vehicle service reminders',
         'summary' => 'Remind customers when their vehicle service is due.',
         'steps' => [
            'Open <b>Vehicle Reminders</b>.',
            'Add the customer, vehicle number and next service date.',
            'Click <b>Save vehicle</b> — the reminder goes out on time.',
         ]],

        ['key' => 'medicine', 'cat' => 'reminders', 'icon' => $I['pill'], 'page' => 'medicine_reminders.php', 'need' => ['medicine_reminder'],
         'title' => 'Medicine reminders',
         'summary' => 'Send patients reminders to take or refill their medicine.',
         'steps' => [
            'Open <b>Medicine Reminders</b> and click <b>Add reminder</b>.',
            'Enter the patient, medicine and times.',
            'Save — reminders are sent automatically.',
         ]],

        // ── Integrations ──
        ['key' => 'integrations', 'cat' => 'connect', 'icon' => $I['plug'], 'page' => 'integrations.php', 'need' => ['menu_integrations'], 'any' => ['indiamart', 'fb_lead_ads', 'google_sheets', 'api_webhook', 'api_webhook_access', 'shopify'],
         'title' => 'Connect your other apps',
         'summary' => 'Bring leads in from IndiaMART, Facebook Lead Ads and more.',
         'steps' => [
            'Open <b>Integrations</b>.',
            'Pick the app under <b>Your integrations</b>.',
            'Follow its steps and click <b>Finish setup</b>.',
         ]],

        ['key' => 'webhooks', 'cat' => 'connect', 'icon' => $I['code'], 'page' => 'premium_zapier.php', 'need' => ['api_webhook_access'],
         'title' => 'Zapier, Pabbly & webhooks',
         'summary' => 'Send leads from any app to Waamini, or Waamini events to any app.',
         'steps' => [
            'Open <b>Zapier &amp; Webhooks</b>.',
            'Copy <b>Your webhook</b> URL and credentials.',
            'Paste them into Zapier, Pabbly or your website form.',
            'See <b>Code examples</b> (PHP, Node.js, Python) if a developer is helping.',
         ]],

        ['key' => 'api', 'cat' => 'connect', 'icon' => $I['code'], 'page' => 'settings_api.php', 'need' => ['api_webhook'],
         'title' => 'API & webhook hub',
         'summary' => 'Your API key and endpoints for connecting your own software.',
         'steps' => [
            'Open <b>Settings → API</b>.',
            'Copy your <b>API Key</b> and keep it private.',
            'Set an <b>Outbound Webhook</b> to get events in your system.',
            'Follow the <b>Quick-start</b> to send your first message by API.',
         ]],

        ['key' => 'gsheets', 'cat' => 'connect', 'icon' => $I['sheet'], 'page' => 'settings_gsheets.php', 'need' => ['google_sheets'],
         'title' => 'Sync with Google Sheets',
         'summary' => 'Save leads and sales to a Google Sheet automatically.',
         'steps' => [
            'Open <b>Settings → Google Sheets</b>.',
            '<b>Step 1:</b> copy the script into your sheet (Extensions → Apps Script).',
            'Deploy it as a <b>Web app</b> and copy the URL.',
            '<b>Step 2:</b> paste the <b>Web App URL</b> here and save.',
         ]],

        ['key' => 'gcal', 'cat' => 'connect', 'icon' => $I['cal'], 'page' => 'google_calendar.php', 'need' => ['google_calendar'],
         'title' => 'Google Calendar & Meet',
         'summary' => 'Add bookings to your calendar with a Google Meet link automatically.',
         'steps' => [
            'Open <b>Google Calendar &amp; Meet</b>.',
            'Log in with Google when asked.',
            'New confirmed bookings appear in your calendar with a Meet link.',
         ]],

        ['key' => 'sales-tracker', 'cat' => 'connect', 'icon' => $I['receipt'], 'page' => 'settings_sales_tracker.php', 'need' => ['sales_tracker'],
         'title' => 'Log sales by WhatsApp',
         'summary' => 'Record a sale by sending a message like “Saree, 5, 1500”.',
         'steps' => [
            'Open <b>Sales Tracker</b> and turn on <b>Enable Sales Tracker</b>.',
            'Add the numbers allowed to log sales.',
            'Send <b>item, quantity, price</b> to your business number.',
            'See <b>Revenue today</b> and this month, or <b>Undo last sale</b>.',
         ]],

        // ── Account & team ──
        ['key' => 'team', 'cat' => 'account', 'icon' => $I['users'], 'page' => 'team_members.php', 'need' => ['team_members'],
         'title' => 'Add team members',
         'summary' => 'Give staff their own login to reply to chats.',
         'steps' => [
            'Open <b>Team Members</b>.',
            'Enter their name, email, mobile number and a password.',
            'Choose an access level: <b>Standard Member</b>, <b>Support Agent</b> or <b>Dashboard only</b>.',
            'Save. Set a member to <b>Disabled</b> when they leave.',
         ]],

        ['key' => 'security', 'cat' => 'account', 'icon' => $I['shield'], 'page' => 'security.php', 'need' => ['feat_2fa'],
         'title' => 'Turn on two-factor login',
         'summary' => 'Protect your account with a code from your phone at every login.',
         'steps' => [
            'Open <b>Security Center</b>.',
            'Turn on <b>Two-factor authentication</b>.',
            'Scan the QR code with <b>Google Authenticator</b>.',
            'Next time you log in, enter the 6-digit code from the app.',
         ],
         'tips' => ['You can also change your password here.']],

        ['key' => 'notifications', 'cat' => 'account', 'icon' => $I['bell'], 'page' => 'notifications.php', 'need' => ['feat_notifications'],
         'title' => 'Choose your alerts',
         'summary' => 'Get emails or WhatsApp alerts for new leads, campaign results and low balance.',
         'steps' => [
            'Open <b>Notification Preferences</b>.',
            'Turn on <b>Email alerts</b> or <b>WhatsApp alerts</b>.',
            'Pick what you want: <b>Campaign summaries</b>, <b>Low balance warning</b>, new leads.',
         ]],

        ['key' => 'plan', 'cat' => 'account', 'icon' => $I['star'], 'page' => 'subscription.php',
         'title' => 'Your plan & usage',
         'summary' => 'See what your plan includes, how much you’ve used and when it renews.',
         'steps' => [
            'Open <b>Subscription</b>.',
            'Check <b>What your plan includes</b> and <b>Usage and limits</b>.',
            'Click <b>Renew or upgrade</b> when you need more.',
         ]],

        ['key' => 'billing', 'cat' => 'account', 'icon' => $I['card'], 'page' => 'billing.php', 'need' => ['billing'],
         'title' => 'Billing & Meta charges',
         'summary' => 'See payments and what Meta charges per conversation.',
         'steps' => [
            'Open <b>Billing &amp; Payments</b>.',
            'See your payments and open <b>Meta Billing Hub</b> for conversation charges.',
         ],
         'tips' => ['Meta bills WhatsApp conversation charges directly to the card in your Meta Business account.']],

        ['key' => 'referrals', 'cat' => 'account', 'icon' => $I['gift'], 'page' => 'referrals.php', 'need' => ['feat_referral'],
         'title' => 'Refer and earn',
         'summary' => 'Share Waamini with other businesses and earn for each one that joins.',
         'steps' => [
            'Open <b>Referral Program</b>.',
            'Copy your referral link and share it.',
            'Track <b>Businesses referred</b> and <b>Total earnings</b>.',
         ]],

        ['key' => 'support', 'cat' => 'account', 'icon' => $I['help'], 'page' => 'support.php', 'need' => ['set_support'],
         'title' => 'Get help from our team',
         'summary' => 'Raise a ticket and we’ll reply on WhatsApp or email.',
         'steps' => [
            'Open <b>Support</b>.',
            'Click <b>Create a support ticket</b>.',
            'Describe the problem and add a screenshot if you can.',
         ]],
    ];
}

/** Guides this client's plan includes (same checks as the sidebar menu). */
function waamini_guides_for_plan(): array
{
    $on = static function (string $k): bool { return !function_exists('has_feature') || has_feature($k); };
    return array_values(array_filter(waamini_guide_catalog(), static function (array $g) use ($on): bool {
        foreach ($g['need'] ?? [] as $k) { if (!$on($k)) return false; }
        if (!empty($g['any'])) {
            foreach ($g['any'] as $k) { if ($on($k)) return true; }
            return false;
        }
        return true;
    }));
}
