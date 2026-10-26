<?php 
$page_title = "Data Deletion Instructions";
$meta_desc = "Learn how to request deletion of your data from Waamini WhatsApp Business CRM platform. Account deletion, data removal, and privacy rights.";
$asyncStylesheets = [
  'assets/css/landing-neo.css?v=16',
];
$bodyClass = 'lp-neo';
include 'includes/header.php';
?>

<style>
.legal-page{padding:140px 0 80px;background:#fff;min-height:100vh}
.legal-hero{text-align:center;margin-bottom:50px}
.legal-hero .legal-badge{display:inline-flex;align-items:center;gap:6px;background:#E6FCF2;color:#128C7E;padding:5px 14px;border-radius:100px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:16px}
.legal-hero h1{font-size:42px;font-weight:800;color:#0F172A;line-height:1.2;margin:0 0 10px}
.legal-hero .last-updated{font-size:14px;color:#64748B;margin:0}
.legal-card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:20px;padding:50px 56px;box-shadow:0 4px 24px rgba(0,0,0,0.06)}
.legal-section{margin-bottom:36px;padding-bottom:28px;border-bottom:1px solid #F1F5F9}
.legal-section:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.legal-section h2{font-size:20px;font-weight:700;color:#0F172A;margin:0 0 12px;display:flex;align-items:center;gap:10px}
.legal-section h2 .h2-num{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#25D366;color:#fff;border-radius:8px;font-size:13px;font-weight:800;flex-shrink:0}
.legal-section p,.legal-section li{font-size:15px;line-height:1.8;color:#475569;margin:0 0 8px}
.legal-section ul{padding-left:20px;margin:8px 0 0}
.legal-section ul li{margin-bottom:6px}
.legal-section ul li::marker{color:#128C7E}
.legal-steps{counter-reset:step;margin:20px 0 0;padding:0;list-style:none}
.legal-steps li{counter-increment:step;display:flex;gap:14px;padding:16px 18px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin-bottom:12px;align-items:flex-start}
.legal-steps li:before{content:counter(step);display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;background:#25D366;color:#fff;border-radius:8px;font-size:13px;font-weight:800;flex-shrink:0;margin-top:1px}
.legal-steps li .step-content{flex:1}
.legal-steps li .step-content strong{display:block;font-size:15px;color:#0F172A;margin-bottom:4px}
.legal-steps li .step-content span{font-size:14px;color:#475569;line-height:1.6}
.legal-highlight{background:#F8FAFC;border-left:4px solid #25D366;border-radius:0 12px 12px 0;padding:16px 20px;margin:16px 0 0;font-size:14px;color:#334155;line-height:1.7}
.legal-highlight strong{color:#128C7E}
.btn-delete-request{display:inline-flex;align-items:center;gap:8px;padding:14px 32px;background:#25D366;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s;box-shadow:0 6px 18px rgba(37,211,102,0.25)}
.btn-delete-request:hover{background:#128C7E;transform:translateY(-2px);box-shadow:0 10px 28px rgba(37,211,102,0.35)}
.btn-delete-request svg{flex-shrink:0}
.legal-note{margin-top:24px;padding:16px 20px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;font-size:14px;color:#92400E;line-height:1.7}
@media (max-width:768px){
  .legal-page{padding:110px 0 60px}
  .legal-hero h1{font-size:30px}
  .legal-card{padding:28px 20px;margin:0 16px;border-radius:16px}
  .legal-section h2{font-size:18px}
  .legal-section p,.legal-section li{font-size:14px}
}
</style>

<section class="legal-page">
  <div class="container">
    <div class="legal-hero">
      <div class="legal-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
        Data Deletion
      </div>
      <h1>Data Deletion Instructions</h1>
      <p class="last-updated">Last Updated: July 2, 2026</p>
    </div>
    <div class="legal-card">
      <div class="legal-section">
        <h2><span class="h2-num">1</span> Your Right to Delete Data</h2>
        <p>In compliance with Indian IT Act 2000, applicable privacy laws, and WhatsApp Business Solution Partner requirements, you have the right to request the deletion of your personal data and account information from the Waamini platform.</p>
        <div class="legal-highlight">
          <strong>&#10003;</strong> Data deletion is irreversible. Once your data is deleted, it cannot be recovered.
        </div>
      </div>

      <div class="legal-section">
        <h2><span class="h2-num">2</span> How to Request Deletion</h2>
        <p>Follow these steps to request complete deletion of your data from the Waamini platform:</p>
        <ol class="legal-steps">
          <li>
            <div class="step-content">
              <strong>Send an email request</strong>
              <span>Send an email to <strong>support@waamini.in</strong> with the subject line <strong>"Data Deletion Request"</strong> from the email address registered with your Waamini account.</span>
            </div>
          </li>
          <li>
            <div class="step-content">
              <strong>Include required details</strong>
              <span>Include your full name, registered WhatsApp business number, and account email address. This helps us verify your identity and process the request accurately.</span>
            </div>
          </li>
          <li>
            <div class="step-content">
              <strong>Account verification</strong>
              <span>Our team will verify your identity by sending a confirmation email or WhatsApp message to your registered contact. You must respond to confirm the deletion request.</span>
            </div>
          </li>
          <li>
            <div class="step-content">
              <strong>Data deletion processing</strong>
              <span>Once verified, we will process your data deletion within <strong>30 business days</strong>. You will receive a confirmation email once the deletion is complete.</span>
            </div>
          </li>
        </ol>
      </div>

      <div class="legal-section">
        <h2><span class="h2-num">3</span> What Gets Deleted</h2>
        <p>Upon processing your deletion request, the following data will be permanently removed:</p>
        <ul>
          <li>Account profile and login credentials</li>
          <li>Contact lists and imported customer data</li>
          <li>Campaign history, message logs, and analytics data</li>
          <li>AI bot configurations, templates, and automation workflows</li>
          <li>Payment history and billing information</li>
          <li>All connected integrations and API tokens</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2><span class="h2-num">4</span> What Is Retained (Legal Obligations)</h2>
        <p>Certain data may be retained for legal and regulatory compliance purposes even after your account deletion request is processed:</p>
        <ul>
          <li><strong>Financial records</strong> — Invoices and payment transaction records as required by Indian tax laws (retained for 8 years)</li>
          <li><strong>WhatsApp API logs</strong> — Message delivery metadata retained per Meta's data retention policies (typically 30 days)</li>
          <li><strong>Anonymized analytics</strong> — Aggregated, non-personally identifiable usage statistics may be retained for product improvement</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2><span class="h2-num">5</span> Alternative: In-App Account Deletion</h2>
        <p>You may also request account deletion directly from your Waamini dashboard:</p>
        <ul>
          <li>Log in to your Waamini account</li>
          <li>Navigate to <strong>Settings → Account</strong></li>
          <li>Click on <strong>"Delete Account"</strong> at the bottom of the page</li>
          <li>Follow the confirmation steps to initiate deletion</li>
        </ul>
      </div>

      <div class="legal-section" style="border-bottom:none;padding-bottom:0;margin-bottom:0;text-align:center;">
        <a href="mailto:support@waamini.in?subject=Data%20Deletion%20Request" class="btn-delete-request">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
          Request Data Deletion
        </a>
        <p style="font-size:13px;color:#64748B;margin-top:16px;">Clicking above will open your default email client</p>

        <div class="legal-note">
          <strong>&#9888; Important:</strong> Deleting your data is permanent. If you are on a paid plan, please cancel your subscription before requesting deletion. No refunds are provided for data deletion initiated before the billing period end.
        </div>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/lp_scripts.php'; ?>
<?php include 'includes/footer.php'; ?>
