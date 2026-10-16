<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Vendor Subscription Details';
$currentPage = 'companies'; // keep sidebar active on companies

if (!isset($_GET['company_id'])) {
    die("Invalid vendor ID");
}
$companyId = intval($_GET['company_id']);

// Fetch Company Details
$stVs1 = $conn->prepare("SELECT * FROM companies WHERE id=?");
$stVs1->bind_param("i", $companyId);
$stVs1->execute();
$company = $stVs1->get_result()->fetch_assoc();
if (!$company)
    die("Vendor not found");

// Fetch active subscription and plan
$stVs2 = $conn->prepare("
    SELECT s.*, p.*, p.name as plan_name, s.id as sub_id
    FROM subscriptions s
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    WHERE s.company_id = ? AND s.status = 'active'
    ORDER BY s.id DESC LIMIT 1
");
$stVs2->bind_param("i", $companyId);
$stVs2->execute();
$activeSub = $stVs2->get_result()->fetch_assoc();

// Fetch history
$stVs3 = $conn->prepare("
    SELECT s.*, p.name as plan_name
    FROM subscriptions s
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    WHERE s.company_id = ?
    ORDER BY s.id DESC
");
$stVs3->bind_param("i", $companyId);
$stVs3->execute();
$history = $stVs3->get_result();

include '../includes/admin_header.php';
?>

<div style="padding: 10px 0;">
    <div class="page-header" style="margin-bottom: 20px;">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9.5 8h5"/><path d="M9.5 12H16"/><path d="M9.5 16H14"/></svg> <?php echo htmlspecialchars($company['name']); ?> Details</h2>
        <a href="companies.php" class="btn btn-edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg> Manage Vendors</a>
    </div>

    <!-- Basic Information -->
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px;">
        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 15px;">Basic Information</div>
        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">Vendor Name</div>
        <div style="font-size: 16px; color: var(--text-primary); font-weight: 600;"><?php echo htmlspecialchars($company['name']); ?></div>
    </div>

    <!-- Subscription Plan -->
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px;">
        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 15px;">Current Subscribed Plan</div>
        
        <?php if ($activeSub): ?>
            <div style="color: var(--accent); font-weight: 700; font-size: 18px; margin-bottom: 20px;"><?php echo htmlspecialchars($activeSub['plan_name']); ?></div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; color: var(--text-secondary); font-size: 14px;">
                <!-- Limits -->
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_contacts']; ?> Contacts</div>
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_campaigns']; ?> Campaigns Per Month</div>
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_bot_replies']; ?> Bot Replies</div>
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_bot_flows']; ?> Bot Flows</div>
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_custom_fields']; ?> Contact Custom Fields</div>
                <div style="display: flex; align-items: center; gap: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg> <?php echo $activeSub['limit_team_members']; ?> Team Members/Agents</div>
                

                <!-- Booleans -->
                <?php
    $allFeats = [
        'feat_whatsapp_chat' => 'WhatsApp Chat',
        'feat_contacts' => 'Contacts',
        'feat_campaigns' => 'Campaigns',
        'feat_templates' => 'Templates',
        'feat_message_log' => 'Message Log',
        'feat_qr_code' => 'QR Code',
        'feat_team_members' => 'Team Members',
        'feat_bot_replies' => 'Bot Replies',
        'ai_chatbot' => 'AI Chat Bot',
        'api_webhook_access' => 'API and Webhook Access',
        'feat_auto_followup' => 'Auto Follow-up',
        'feat_lead_scoring' => 'Lead Scoring',
        'feat_drip_campaign' => 'Drip Campaigns',
        'feat_language_detect' => 'Language Detection',
        'feat_indiamart' => 'IndiaMART Integration',
        'feat_fb_lead_ads' => 'FB Lead Ads Integration',
        'feat_google_sheets' => 'Google Sheets Sync',
        'feat_pulse' => 'Pulse AI Monitoring',
        'feat_crm_advance' => 'Advanced CRM',
        'feat_vcard' => 'Digital vCard',
        'feat_quick_replies' => 'Quick Replies',
        'feat_crm_analytics' => 'CRM Analytics',
        'feat_voice_analyzer' => 'Voice Analyzer',
        'feat_multi_agent' => 'Multi-Agent Chat',
        'feat_native_checkout' => 'Native Checkout',
        'feat_pdf_quotes' => 'PDF Quotations',
        'feat_loyalty_points' => 'Loyalty Program',
        'whatsapp_flows' => 'WhatsApp Flows',
        'ecommerce_cart_recovery' => 'Cart Recovery',
        'feat_festival_reminder' => 'Festival Reminders',
        'feat_school_fee_reminder' => 'School Fee Reminders',
        'feat_vehicle_reminder' => 'Vehicle Reminders',
        'feat_medicine_reminder' => 'Medicine Reminders',
    ];
    foreach ($allFeats as $key => $label):
        if (!isset($activeSub[$key]))
            continue;
?>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <?php if ($activeSub[$key]): ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg><?php else: ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--danger)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?> 
                    <?php echo $label; ?>
                </div>
                <?php
    endforeach; ?>
            </div>
        <?php
else: ?>
            <div style="color: var(--danger); font-size: 14px; font-weight: 500; margin-bottom: 10px;">No active subscription found.</div>
        <?php
endif; ?>

        <div style="margin-top: 24px;">
            <a href="subscription_add.php?company_id=<?php echo $company['id']; ?>" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> Create New Subscription</a>
        </div>
    </div>

    <!-- Manual/Prepaid Subscription Log -->
    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 16px;">Manual/Prepaid Subscription Log</h3>
    
    <div class="data-table-wrapper">
        <div class="table-header">
            <h3>Payment History</h3>
            <div style="font-size: 13px; color: var(--text-muted);">
                Search: <input type="text" class="form-control" style="display:inline-block; width:200px; padding: 6px 12px; margin-left: 10px;">
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Plan Name</th>
                    <th>Billing Cycle</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Payment Status</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($h = $history->fetch_assoc()): ?>
                    <tr>
                        <td style="color: var(--accent); font-weight:600;"><?php echo htmlspecialchars($h['plan_name'] ?? 'Custom'); ?></td>
                        <td><?php echo ucfirst($h['billing_cycle'] ?? 'Monthly'); ?></td>
                        <td><?php echo date('d M Y', strtotime($h['starts_at'])); ?></td>
                        <td><?php echo date('d M Y', strtotime($h['expires_at'])); ?></td>
                        <td>
                            <?php if ($h['status'] == 'active'): ?>
                                <span class="badge badge-active">Paid</span>
                            <?php
        else: ?>
                                <span class="badge badge-suspended">Failed</span>
                            <?php
        endif; ?>
                        </td>
                        <td>
                            <?php if ($h['status'] == 'active'): ?>
                                <span class="badge badge-active">Active</span>
                            <?php
        else: ?>
                                <span class="badge badge-suspended"><?php echo ucfirst($h['status']); ?></span>
                            <?php
        endif; ?>
                        </td>
                    </tr>
                    <?php
    endwhile; ?>
                <?php
else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></div>
                                <p>No subscription history.</p>
                            </div>
                        </td>
                    </tr>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php include '../includes/admin_footer.php'; ?>
