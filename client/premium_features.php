<?php
include '../includes/client_auth_check.php';
if (function_exists('waamini_plan_switches') && !has_feature('discover')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client menu pages

$pageTitle = 'Premium Features';
$currentPage = 'premium_features';

include '../includes/client_header.php';
?>

<div style="text-align:center;padding:40px 20px;max-width:600px;margin:0 auto;">
    <div style="font-size:60px;margin-bottom:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></div>
    <h2 style="font-size:24px;color:var(--text-primary);margin-bottom:16px;">Supercharge Your Growth</h2>
    <p style="color:var(--text-secondary);line-height:1.6;margin-bottom:32px;">
        You've discovered our Pro tools! Features like the <strong>Abandoned Cart Recovery and Zapier Webhooks</strong> are currently being rolled out to select Enterprise customers to guarantee maximum reliability.
    </p>

    <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:24px;text-align:left;margin-bottom:32px;">
         <h4 style="color:var(--accent);margin-bottom:12px;font-size:14px;text-transform:uppercase;letter-spacing:1px;">Coming Soon to Your Panel:</h4>
         <ul style="color:var(--text-primary);font-size:14px;line-height:2;padding-left:20px;">
             <li><strong>AI ChatGPT Sales Bot:</strong> Let AI close your deals.</li>
             <li><strong>Shopify integration:</strong> Recover lost sales automatically.</li>
             <li><strong>Zapier Webhooks:</strong> Connect to 5,000+ apps.</li>
             <li><strong>Native WhatsApp Forms:</strong> Collect leads without leaving chat.</li>
         </ul>
    </div>

    <button onclick="alert('Our sales team will contact you shortly about Beta Access!')" class="btn btn-primary" style="font-size:16px;padding:14px 32px;background:#00b894;border:none;">
        Request Beta Access <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
    </button>
</div>

<?php include '../includes/client_footer.php'; ?>
