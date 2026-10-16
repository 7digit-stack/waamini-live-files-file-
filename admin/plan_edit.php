<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Plan Configuration';
$currentPage = 'subscriptions';

// ── Auto-migrate: add any missing columns to subscription_plans ──────────
$_newCols = [
    'feat_crm_analytics'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_voice_analyzer'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_multi_agent'       => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_native_checkout'   => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_pdf_quotes'        => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_loyalty_points'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_quick_replies'     => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_vcard'             => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_crm_advance'       => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_pulse'             => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_google_sheets'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_ocr_scanner'       => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_festival_reminder' => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_school_fee_reminder' => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_vehicle_reminder'  => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_medicine_reminder' => "TINYINT(1) NOT NULL DEFAULT 0",
    'limit_messages_month'   => "INT NOT NULL DEFAULT 1000",
    'feat_gst_invoice'       => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_blacklist'         => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_wa_health'         => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_chatbot_builder'   => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_scheduled_msg'     => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_quick_broadcast'   => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_campaign_reports'  => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_contact_import'    => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_referral'          => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_notifications'     => "TINYINT(1) NOT NULL DEFAULT 1",
    'feat_2fa'               => "TINYINT(1) NOT NULL DEFAULT 1",
    // Ads & Attribution add-on (off by default)
    'feat_ads_attribution'   => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_ads_capi'          => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_ads_insights'      => "TINYINT(1) NOT NULL DEFAULT 0",
    'feat_ads_builder'       => "TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($_newCols as $_col => $_def) {
    try {
        $exists = false;
        $chk = $conn->prepare("SHOW COLUMNS FROM subscription_plans LIKE ?");
        if ($chk) {
            $chk->bind_param("s", $_col);
            $chk->execute();
            $exists = (bool)$chk->get_result()->fetch_assoc();
        }
        if (!$exists) {
            // VULN-001 FIX: Validate column names against hardcoded allowed list.
            if (!array_key_exists($_col, $_newCols)) {
                continue; // Skip if column name not in whitelist
            }
            // Use backticks and verified column name for safety.
            $conn->query("ALTER TABLE subscription_plans ADD COLUMN `$_col` $_def");
        }
    } catch (\Throwable $_e) {
        // Column add failed/exists on this environment — continue with best effort.
    }
}
unset($_newCols, $_col, $_def, $_e);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$plan = null;

if ($id > 0) {
    $stmt_p = $conn->prepare("SELECT * FROM subscription_plans WHERE id=?");
    if ($stmt_p) {
        $stmt_p->bind_param("i", $id);
        $stmt_p->execute();
        $plan = $stmt_p->get_result()->fetch_assoc();
    }
    if (!$plan) {
        header("Location: plans.php");
        exit();
    }
}

$error = '';
$success = '';

if (isset($_POST['save'])) {
    csrf_verify();

    $name = sanitize($_POST['name']);
    $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
    $duration = in_array($_POST['duration'], ['monthly', 'yearly', 'lifetime']) ? $_POST['duration'] : 'monthly';

    $limitContacts = intval($_POST['limit_contacts']);
    $limitCampaigns = intval($_POST['limit_campaigns']);
    $limitBotReplies = intval($_POST['limit_bot_replies']);
    $limitBotFlows = intval($_POST['limit_bot_flows']);
    $limitCustomFields = intval($_POST['limit_custom_fields']);
    $limitTeamMembers = intval($_POST['limit_team_members']);

    $aiChatbot = intval($_POST['ai_chatbot'] ?? 0);
    $apiWebhook = intval($_POST['api_webhook_access'] ?? 0);
    $whatsappFlows = intval($_POST['whatsapp_flows'] ?? 0);
    $ecommerceCart = intval($_POST['ecommerce_cart_recovery'] ?? 0);
    $featOcr = intval($_POST['feat_ocr_scanner'] ?? 0);

    // Feature modules
    $featWhatsapp = intval($_POST['feat_whatsapp_chat'] ?? 0);
    $featContacts = intval($_POST['feat_contacts'] ?? 0);
    $featCampaigns = intval($_POST['feat_campaigns'] ?? 0);
    $featTemplates = intval($_POST['feat_templates'] ?? 0);
    $featBotReplies = intval($_POST['feat_bot_replies'] ?? 0);
    $featTeamMembers = intval($_POST['feat_team_members'] ?? 0);
    $featAutoFollowup = intval($_POST['feat_auto_followup'] ?? 0);
    $featLeadScoring = intval($_POST['feat_lead_scoring'] ?? 0);
    $featMessageLog = intval($_POST['feat_message_log'] ?? 0);
    $featQrCode = intval($_POST['feat_qr_code'] ?? 0);
    $featIndiamart = intval($_POST['feat_indiamart'] ?? 0);
    $featFbLeadAds = intval($_POST['feat_fb_lead_ads'] ?? 0);
    $featDripCampaign = intval($_POST['feat_drip_campaign'] ?? 0);
    $featLanguageDetect = intval($_POST['feat_language_detect'] ?? 0);
    $featGoogleSheets = intval($_POST['feat_google_sheets'] ?? 0);
    $featPulse = intval($_POST['feat_pulse'] ?? 0);
    $featCrmAdv = intval($_POST['feat_crm_advance'] ?? 0);
    $featVcard = intval($_POST['feat_vcard'] ?? 0);
    $featQuickReplies = intval($_POST['feat_quick_replies'] ?? 0);
    $featCrmAnalytics = intval($_POST['feat_crm_analytics'] ?? 0);
    $featVoiceAnalyzer = intval($_POST['feat_voice_analyzer'] ?? 0);
    $featMultiAgent = intval($_POST['feat_multi_agent'] ?? 0);
    $featNativeCheckout = intval($_POST['feat_native_checkout'] ?? 0);
    $featPdfQuotes = intval($_POST['feat_pdf_quotes'] ?? 0);
    $featLoyaltyPoints = intval($_POST['feat_loyalty_points'] ?? 0);
    $featFestivalReminder = intval($_POST['feat_festival_reminder'] ?? 0);
    $featSchoolFeeReminder = intval($_POST['feat_school_fee_reminder'] ?? 0);
    $featVehicleReminder = intval($_POST['feat_vehicle_reminder'] ?? 0);
    $featMedicineReminder = intval($_POST['feat_medicine_reminder'] ?? 0);
    $limitMsgMonth = intval($_POST['limit_messages_month'] ?? 1000);

    $featGstInvoice = intval($_POST['feat_gst_invoice'] ?? 0);
    $featBlacklist = intval($_POST['feat_blacklist'] ?? 0);
    $featWaHealth = intval($_POST['feat_wa_health'] ?? 0);
    $featChatbotBuilder = intval($_POST['feat_chatbot_builder'] ?? 0);
    $featScheduledMsg = intval($_POST['feat_scheduled_msg'] ?? 0);
    $featQuickBroadcast = intval($_POST['feat_quick_broadcast'] ?? 0);
    $featCampaignReports = intval($_POST['feat_campaign_reports'] ?? 0);
    $featContactImport = intval($_POST['feat_contact_import'] ?? 0);
    $featReferral = intval($_POST['feat_referral'] ?? 0);
    $featNotifications = intval($_POST['feat_notifications'] ?? 0);
    $feat2fa = intval($_POST['feat_2fa'] ?? 0);
    $adsFlags = [];
    foreach (['feat_ads_attribution', 'feat_ads_capi', 'feat_ads_insights', 'feat_ads_builder'] as $_af) { $adsFlags[$_af] = intval($_POST[$_af] ?? 0) ? 1 : 0; }
    $saveAdsFlags = static function (int $planId) use ($conn, $adsFlags): void {
        $st = $conn->prepare("UPDATE subscription_plans SET feat_ads_attribution=?, feat_ads_capi=?, feat_ads_insights=?, feat_ads_builder=? WHERE id=?");
        if ($st) { $st->bind_param('iiiii', $adsFlags['feat_ads_attribution'], $adsFlags['feat_ads_capi'], $adsFlags['feat_ads_insights'], $adsFlags['feat_ads_builder'], $planId); $st->execute(); }
    };

    $monthlyEnabled = isset($_POST['monthly_enabled']) ? 1 : 0;
    $monthlyPrice = floatval($_POST['monthly_price'] ?? 0);
    $monthlyStripe = sanitize($_POST['monthly_stripe_id'] ?? '');
    $yearlyEnabled = isset($_POST['yearly_enabled']) ? 1 : 0;
    $yearlyPrice = floatval($_POST['yearly_price'] ?? 0);
    $yearlyStripe = sanitize($_POST['yearly_stripe_id'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if (empty($name)) {
        $error = "Plan name is required!";
    }
    else {
        try {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE subscription_plans SET name=?, is_enabled=?, duration=?, limit_contacts=?, limit_campaigns=?, limit_bot_replies=?, limit_bot_flows=?, limit_custom_fields=?, limit_team_members=?, ai_chatbot=?, api_webhook_access=?, whatsapp_flows=?, ecommerce_cart_recovery=?, feat_ocr_scanner=?, monthly_enabled=?, monthly_price=?, monthly_stripe_id=?, yearly_enabled=?, yearly_price=?, yearly_stripe_id=?, sort_order=?, feat_whatsapp_chat=?, feat_contacts=?, feat_campaigns=?, feat_templates=?, feat_bot_replies=?, feat_team_members=?, feat_auto_followup=?, feat_lead_scoring=?, feat_message_log=?, feat_qr_code=?, feat_indiamart=?, feat_fb_lead_ads=?, feat_drip_campaign=?, feat_language_detect=?, limit_messages_month=?, feat_google_sheets=?, feat_pulse=?, feat_crm_advance=?, feat_vcard=?, feat_quick_replies=?, feat_crm_analytics=?, feat_voice_analyzer=?, feat_multi_agent=?, feat_native_checkout=?, feat_pdf_quotes=?, feat_loyalty_points=? WHERE id=?");
            $stmt->bind_param("sisiiiiiiiiiiiidsidsiiiiiiiiiiiiiiiiiiiiiiiiiiii", $name, $isEnabled, $duration, $limitContacts, $limitCampaigns, $limitBotReplies, $limitBotFlows, $limitCustomFields, $limitTeamMembers, $aiChatbot, $apiWebhook, $whatsappFlows, $ecommerceCart, $featOcr, $monthlyEnabled, $monthlyPrice, $monthlyStripe, $yearlyEnabled, $yearlyPrice, $yearlyStripe, $sortOrder, $featWhatsapp, $featContacts, $featCampaigns, $featTemplates, $featBotReplies, $featTeamMembers, $featAutoFollowup, $featLeadScoring, $featMessageLog, $featQrCode, $featIndiamart, $featFbLeadAds, $featDripCampaign, $featLanguageDetect, $limitMsgMonth, $featGoogleSheets, $featPulse, $featCrmAdv, $featVcard, $featQuickReplies, $featCrmAnalytics, $featVoiceAnalyzer, $featMultiAgent, $featNativeCheckout, $featPdfQuotes, $featLoyaltyPoints, $id);
            $stmt->execute();
            $stmtExtra = $conn->prepare("UPDATE subscription_plans SET feat_festival_reminder=?, feat_school_fee_reminder=?, feat_vehicle_reminder=?, feat_medicine_reminder=? WHERE id=?");
            if ($stmtExtra) {
                $stmtExtra->bind_param("iiiii", $featFestivalReminder, $featSchoolFeeReminder, $featVehicleReminder, $featMedicineReminder, $id);
                $stmtExtra->execute();
            }
            $stmtExtra2 = $conn->prepare("UPDATE subscription_plans SET feat_gst_invoice=?, feat_blacklist=?, feat_wa_health=?, feat_chatbot_builder=?, feat_scheduled_msg=?, feat_quick_broadcast=?, feat_campaign_reports=?, feat_contact_import=?, feat_referral=?, feat_notifications=?, feat_2fa=? WHERE id=?");
            if ($stmtExtra2) {
                $stmtExtra2->bind_param("iiiiiiiiiiii", $featGstInvoice, $featBlacklist, $featWaHealth, $featChatbotBuilder, $featScheduledMsg, $featQuickBroadcast, $featCampaignReports, $featContactImport, $featReferral, $featNotifications, $feat2fa, $id);
                $stmtExtra2->execute();
            }
            $saveAdsFlags($id);
            $success = "Plan updated!";
            $stmt_p2 = $conn->prepare("SELECT * FROM subscription_plans WHERE id=?");
            if ($stmt_p2) {
                $stmt_p2->bind_param("i", $id);
                $stmt_p2->execute();
                $plan = $stmt_p2->get_result()->fetch_assoc();
            }
        }
        else {
            $stmt = $conn->prepare("INSERT INTO subscription_plans (name, is_enabled, duration, limit_contacts, limit_campaigns, limit_bot_replies, limit_bot_flows, limit_custom_fields, limit_team_members, ai_chatbot, api_webhook_access, whatsapp_flows, ecommerce_cart_recovery, feat_ocr_scanner, monthly_enabled, monthly_price, monthly_stripe_id, yearly_enabled, yearly_price, yearly_stripe_id, sort_order, feat_whatsapp_chat, feat_contacts, feat_campaigns, feat_templates, feat_bot_replies, feat_team_members, feat_auto_followup, feat_lead_scoring, feat_message_log, feat_qr_code, feat_indiamart, feat_fb_lead_ads, feat_drip_campaign, feat_language_detect, limit_messages_month, feat_google_sheets, feat_pulse, feat_crm_advance, feat_vcard, feat_quick_replies, feat_crm_analytics, feat_voice_analyzer, feat_multi_agent, feat_native_checkout, feat_pdf_quotes, feat_loyalty_points) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sisiiiiiiiiiiiidsidsiiiiiiiiiiiiiiiiiiiiiiiiiii", $name, $isEnabled, $duration, $limitContacts, $limitCampaigns, $limitBotReplies, $limitBotFlows, $limitCustomFields, $limitTeamMembers, $aiChatbot, $apiWebhook, $whatsappFlows, $ecommerceCart, $featOcr, $monthlyEnabled, $monthlyPrice, $monthlyStripe, $yearlyEnabled, $yearlyPrice, $yearlyStripe, $sortOrder, $featWhatsapp, $featContacts, $featCampaigns, $featTemplates, $featBotReplies, $featTeamMembers, $featAutoFollowup, $featLeadScoring, $featMessageLog, $featQrCode, $featIndiamart, $featFbLeadAds, $featDripCampaign, $featLanguageDetect, $limitMsgMonth, $featGoogleSheets, $featPulse, $featCrmAdv, $featVcard, $featQuickReplies, $featCrmAnalytics, $featVoiceAnalyzer, $featMultiAgent, $featNativeCheckout, $featPdfQuotes, $featLoyaltyPoints);
            $stmt->execute();
            $newPlanId = (int)$conn->insert_id;
            if ($newPlanId > 0) {
                $stmtExtra = $conn->prepare("UPDATE subscription_plans SET feat_festival_reminder=?, feat_school_fee_reminder=?, feat_vehicle_reminder=?, feat_medicine_reminder=? WHERE id=?");
                if ($stmtExtra) {
                    $stmtExtra->bind_param("iiiii", $featFestivalReminder, $featSchoolFeeReminder, $featVehicleReminder, $featMedicineReminder, $newPlanId);
                    $stmtExtra->execute();
                }
                $stmtExtra2 = $conn->prepare("UPDATE subscription_plans SET feat_gst_invoice=?, feat_blacklist=?, feat_wa_health=?, feat_chatbot_builder=?, feat_scheduled_msg=?, feat_quick_broadcast=?, feat_campaign_reports=?, feat_contact_import=?, feat_referral=?, feat_notifications=?, feat_2fa=? WHERE id=?");
                if ($stmtExtra2) {
                    $stmtExtra2->bind_param("iiiiiiiiiiii", $featGstInvoice, $featBlacklist, $featWaHealth, $featChatbotBuilder, $featScheduledMsg, $featQuickBroadcast, $featCampaignReports, $featContactImport, $featReferral, $featNotifications, $feat2fa, $newPlanId);
                    $stmtExtra2->execute();
                }
                $saveAdsFlags($newPlanId);
            }
            header("Location: plans.php?msg=created");
            exit();
        }
        } catch (\Throwable $saveEx) {
            $error = "Save failed: " . htmlspecialchars($saveEx->getMessage()) . " — Please contact support or check the database schema.";
            error_log("[WAAMINI plan_edit] Save error: " . $saveEx->getMessage());
        }
    }
}

// Defaults for new plan
if (!$plan) {
    $plan = [
        'name' => '', 'is_enabled' => 1, 'duration' => 'monthly',
        'limit_contacts' => 1000, 'limit_campaigns' => 10, 'limit_bot_replies' => 50,
        'limit_bot_flows' => 1, 'limit_custom_fields' => 5, 'limit_team_members' => 1,
        'ai_chatbot' => 0, 'api_webhook_access' => 0, 'whatsapp_flows' => 0, 'ecommerce_cart_recovery' => 0, 'feat_ocr_scanner' => 0,
        'monthly_enabled' => 1, 'monthly_price' => 0, 'monthly_stripe_id' => '',
        'yearly_enabled' => 0, 'yearly_price' => 0, 'yearly_stripe_id' => '',
        'sort_order' => 0,
        'feat_whatsapp_chat' => 1, 'feat_contacts' => 1, 'feat_campaigns' => 1,
        'feat_templates' => 1, 'feat_bot_replies' => 0, 'feat_team_members' => 0,
        'feat_auto_followup' => 0, 'feat_lead_scoring' => 0, 'feat_message_log' => 1,
        'feat_qr_code' => 1, 'feat_indiamart' => 0, 'feat_fb_lead_ads' => 0,
        'feat_drip_campaign' => 0, 'feat_language_detect' => 0,
        'limit_messages_month' => 1000,
        'feat_google_sheets' => 0,
        'feat_pulse' => 0,
        'feat_crm_advance' => 0,
        'feat_vcard' => 1,
        'feat_quick_replies' => 1,
        'feat_crm_analytics' => 0,
        'feat_voice_analyzer' => 0,
        'feat_multi_agent' => 0,
        'feat_native_checkout' => 0,
        'feat_pdf_quotes' => 0,
        'feat_loyalty_points' => 0,
        'feat_festival_reminder' => 0,
        'feat_school_fee_reminder' => 0,
        'feat_vehicle_reminder' => 0,
        'feat_medicine_reminder' => 0,
        'feat_gst_invoice' => 1,
        'feat_blacklist' => 1,
        'feat_wa_health' => 1,
        'feat_chatbot_builder' => 1,
        'feat_scheduled_msg' => 1,
        'feat_quick_broadcast' => 1,
        'feat_campaign_reports' => 1,
        'feat_contact_import' => 1,
        'feat_referral' => 1,
        'feat_notifications' => 1,
        'feat_2fa' => 1,
        'feat_ads_attribution' => 0,
        'feat_ads_capi' => 0,
        'feat_ads_insights' => 0,
        'feat_ads_builder' => 0,
    ];
}

include '../includes/admin_header.php';
?>

<style>
.plan-section { margin-bottom: 24px; }
.plan-section-title { font-size: 13px; font-weight: 700; color: #a78bfa; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; }
.plan-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.plan-card { background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
.plan-card label { font-size: 12px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 6px; }
.plan-card input { width: 100%; padding: 8px 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary); font-size: 14px; }
.plan-card .hint { font-size: 10px; color: var(--text-muted); margin-top: 4px; }
.toggle-row { display: flex; align-items: center; gap: 10px; padding: 14px; background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; }
.toggle-row label { font-size: 13px; font-weight: 600; color: var(--text-primary); cursor: pointer; }
.toggle-switch { position: relative; width: 42px; height: 22px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: rgba(255,107,107,0.3); border-radius: 22px; cursor: pointer; transition: 0.3s; }
.toggle-slider::before { content: ''; position: absolute; width: 16px; height: 16px; background: white; border-radius: 50%; left: 3px; top: 3px; transition: 0.3s; }
.toggle-switch input:checked + .toggle-slider { background: #00b894; }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
.charge-section { padding: 18px; background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; }
.charge-section h4 { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; }
.charge-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }
.feature-toggle-card {
    background: var(--bg-input); border: 2px solid var(--border); border-radius: 12px;
    padding: 14px 12px; cursor: pointer; text-align: center;
    transition: all 0.2s ease; position: relative;
}
.feature-toggle-card:hover { border-color: rgba(108,92,231,0.3); transform: translateY(-2px); }
.feature-toggle-card.active { border-color: #00b894; background: rgba(0,184,148,0.05); }
.feature-icon { font-size: 24px; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; }
.feature-name { font-size: 12px; font-weight: 600; color: var(--text-primary); }
.feature-check { position: absolute; top: 6px; right: 8px; font-size: 12px; }
</style>

<div class="page-header">
    <h2><?php if ($id > 0): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg> Edit Plan<?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create New Plan<?php endif; ?></h2>
    <a href="plans.php" class="btn btn-sm btn-edit">← Back to Plans</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>
<?php if ($success): ?><div class="alert alert-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php
endif; ?>

<form method="POST">
    <?php echo csrf_field(); ?>
    
    <!-- Plan Enable & Name -->
    <div class="form-card plan-section">
        <div style="display:flex; align-items:center; gap:16px; margin-bottom:16px;">
            <div class="toggle-switch">
                <input type="checkbox" name="is_enabled" id="isEnabled" <?php echo $plan['is_enabled'] ? 'checked' : ''; ?>>
                <label class="toggle-slider" for="isEnabled"></label>
            </div>
            <label for="isEnabled" style="font-size:14px; font-weight:600; color:var(--text-primary); cursor:pointer;">Enable this Plan</label>
        </div>
        
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Plan Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Growth Plan" value="<?php echo htmlspecialchars($plan['name']); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Duration</label>
                <select name="duration" class="form-control">
                    <option value="monthly" <?php echo $plan['duration'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="yearly" <?php echo $plan['duration'] === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                    <option value="lifetime" <?php echo $plan['duration'] === 'lifetime' ? 'selected' : ''; ?>>Lifetime</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Sort Order</label>
                <input type="number" name="sort_order" class="form-control" value="<?php echo intval($plan['sort_order']); ?>" min="0">
            </div>
        </div>
    </div>
    
    <!-- Feature Limits -->
    <div class="plan-section">
        <div class="plan-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Feature Limits</div>
        <div class="plan-grid">
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Contacts</label>
                <input type="number" name="limit_contacts" value="<?php echo intval($plan['limit_contacts']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg> Campaigns</label>
                <input type="number" name="limit_campaigns" value="<?php echo intval($plan['limit_campaigns']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg> Bot Replies</label>
                <input type="number" name="limit_bot_replies" value="<?php echo intval($plan['limit_bot_replies']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Bot Flows</label>
                <input type="number" name="limit_bot_flows" value="<?php echo intval($plan['limit_bot_flows']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Contact Custom Fields</label>
                <input type="number" name="limit_custom_fields" value="<?php echo intval($plan['limit_custom_fields']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
            <div class="plan-card">
                <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Team Members/Agents</label>
                <input type="number" name="limit_team_members" value="<?php echo intval($plan['limit_team_members']); ?>" min="0">
                <div class="hint">Use 0 for unlimited</div>
            </div>
        </div>
    </div>
    
    <!-- Feature Toggles -->
    <div class="plan-section">
        <div class="plan-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/></svg> Feature Modules</div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Toggle which modules are available in this plan. Disabled modules will show as <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:middle;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> locked in the client panel.</p>
        
        <!-- Core Features -->
        <div style="margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg> Core Modules</div>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;">
                <?php
$coreFeatures = [
    ['feat_whatsapp_chat', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>', 'WhatsApp Chat', '#25d366'],
    ['feat_contacts', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>', 'Contacts', '#0984e3'],
    ['feat_campaigns', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>', 'Campaigns', '#6c5ce7'],
    ['feat_templates', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>', 'Templates', '#fdcb6e'],
    ['feat_message_log', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>', 'Message Log', '#a78bfa'],
    ['feat_qr_code', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>', 'QR Code', '#00b894'],
    ['feat_team_members', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', 'Team Members', '#e17055'],
    ['feat_bot_replies', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>', 'Bot Replies', '#00cec9'],
];
foreach ($coreFeatures as $f):
    $checked = ($plan[$f[0]] ?? 0) ? 'checked' : '';
?>
                <div class="feature-toggle-card <?php echo $checked ? 'active' : ''; ?>" data-feature="<?php echo htmlspecialchars($f[0]); ?>">
                    <input type="hidden" name="<?php echo $f[0]; ?>" value="0">
                    <input type="checkbox" name="<?php echo $f[0]; ?>" id="<?php echo $f[0]; ?>" <?php echo $checked; ?> style="display:none;" value="1">
                    <div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo $f[1]; ?></div>
                    <div class="feature-name"><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="feature-check"><?php if ($checked): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?></div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>

        <!-- Advanced Features -->
        <div style="margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg> Advanced Features</div>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;">
                <?php
$advFeatures = [
    ['ai_chatbot', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>', 'AI Chatbot', '#e84393'],
    ['api_webhook_access', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>', 'Webhooks & API', '#0984e3'],
    ['whatsapp_flows', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>', 'Native Flows', '#29b6f6'],
    ['ecommerce_cart_recovery', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>', 'Cart Recovery', '#f50057'],
    ['feat_ocr_scanner', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>', 'Image OCR Lead Scanner', '#8e44ad'],
    ['feat_auto_followup', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/></svg>', 'Auto Follow-up', '#f39c12'],
    ['feat_lead_scoring', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>', 'Lead Scoring', '#e74c3c'],
    ['feat_drip_campaign', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'Drip Campaigns', '#3498db'],
    ['feat_crm_analytics', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>', 'Advanced CRM Analytics', '#8e44ad'],
    ['feat_language_detect', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>', 'Language Detect', '#2ecc71'],
    ['feat_pulse', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>', 'WAAMINI Pulse', '#6c5ce7'],
    ['feat_crm_advance', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/></svg>', 'Advanced CRM', '#00b894'],
    ['feat_vcard', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>', 'Digital vCard', '#6c5ce7'],
    ['feat_quick_replies', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>', 'Quick Replies', '#fdcb6e'],
    ['feat_voice_analyzer', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>', 'AI Voice Analyzer', '#ff7675'],
    ['feat_multi_agent', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', 'Multi-Agent Chat', '#00cec9'],
    ['feat_native_checkout', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>', 'Native Checkout', '#6c5ce7'],
    ['feat_pdf_quotes', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>', 'PDF Quotations', '#74b9ff'],
    ['feat_loyalty_points', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>', 'Loyalty Points', '#fab1a0'],
    ['feat_festival_reminder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>', 'Festival Reminders', '#e67e22'],
    ['feat_school_fee_reminder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>', 'School Fee Reminders', '#16a085'],
    ['feat_vehicle_reminder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>', 'Vehicle Reminders', '#2980b9'],
    ['feat_medicine_reminder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 14h6"/><path d="M12 17v-6"/></svg>', 'Medicine Reminders', '#27ae60'],
];
foreach ($advFeatures as $f):
    $checked = ($plan[$f[0]] ?? 0) ? 'checked' : '';
?>
                <div class="feature-toggle-card <?php echo $checked ? 'active' : ''; ?>" data-feature="<?php echo htmlspecialchars($f[0]); ?>">
                    <input type="hidden" name="<?php echo $f[0]; ?>" value="0">
                    <input type="checkbox" name="<?php echo $f[0]; ?>" id="<?php echo $f[0]; ?>" <?php echo $checked; ?> style="display:none;" value="1">
                    <div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo $f[1]; ?></div>
                    <div class="feature-name"><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="feature-check"><?php if ($checked): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?></div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>

        <!-- Integration Features -->
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/></svg> Integrations</div>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;">
                <?php
$intFeatures = [
    ['feat_indiamart', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>', 'IndiaMART', '#2980b9'],
    ['feat_fb_lead_ads', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>', 'FB Lead Ads', '#1877f2'],
    ['feat_google_sheets', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>', 'Google Sheets', '#0f9d58'],
];
foreach ($intFeatures as $f):
    $checked = ($plan[$f[0]] ?? 0) ? 'checked' : '';
?>
                <div class="feature-toggle-card <?php echo $checked ? 'active' : ''; ?>" data-feature="<?php echo htmlspecialchars($f[0]); ?>">
                    <input type="hidden" name="<?php echo $f[0]; ?>" value="0">
                    <input type="checkbox" name="<?php echo $f[0]; ?>" id="<?php echo $f[0]; ?>" <?php echo $checked; ?> style="display:none;" value="1">
                    <div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo $f[1]; ?></div>
                    <div class="feature-name"><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="feature-check"><?php if ($checked): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?></div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>

        <!-- Client Panel Tools -->
        <div style="margin-top:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg> Client Panel Tools</div>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;">
                <?php
$toolFeatures = [
    ['feat_gst_invoice', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>', 'GST Invoices', '#0984e3'],
    ['feat_blacklist', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M22 2 2 22"/></svg>', 'Blacklist', '#e74c3c'],
    ['feat_wa_health', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>', 'WA Health Monitor', '#00b894'],
    ['feat_chatbot_builder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>', 'Chatbot Builder', '#6c5ce7'],
    ['feat_scheduled_msg', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', 'Scheduled Messages', '#fdcb6e'],
    ['feat_campaign_reports', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>', 'Campaign Reports', '#8e44ad'],
    ['feat_contact_import', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>', 'Bulk Contact Import', '#e17055'],
    ['feat_referral', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>', 'Referral Program', '#fab1a0'],
    ['feat_notifications', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>', 'Notifications', '#74b9ff'],
    ['feat_2fa', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>', 'Two-Factor Auth', '#16a085'],
];
foreach ($toolFeatures as $f):
    $checked = ($plan[$f[0]] ?? 0) ? 'checked' : '';
?>
                <div class="feature-toggle-card <?php echo $checked ? 'active' : ''; ?>" data-feature="<?php echo htmlspecialchars($f[0]); ?>">
                    <input type="hidden" name="<?php echo $f[0]; ?>" value="0">
                    <input type="checkbox" name="<?php echo $f[0]; ?>" id="<?php echo $f[0]; ?>" <?php echo $checked; ?> style="display:none;" value="1">
                    <div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo $f[1]; ?></div>
                    <div class="feature-name"><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="feature-check"><?php if ($checked): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?></div>
                </div>
                <?php
endforeach; ?>
            </div>
        </div>

        <!-- Ads & Attribution add-on -->
        <div style="margin-top:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> Ads &amp; Attribution add-on</div>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;">
                <?php
$adsFeatures = [
    ['feat_ads_attribution', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>', 'Ad Lead Attribution', '#128C7E', 'Live'],
    ['feat_ads_capi', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>', 'Conversions API', '#0984e3', 'Live'] ,
    ['feat_ads_insights', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>', 'Ad Spend Insights', '#8e44ad', 'Next release'],
    ['feat_ads_builder', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>', 'Ad Builder', '#e17055', 'Needs Meta approval'],
];
foreach ($adsFeatures as $f):
    $checked = ($plan[$f[0]] ?? 0) ? 'checked' : '';
?>
                <div class="feature-toggle-card <?php echo $checked ? 'active' : ''; ?>" data-feature="<?php echo htmlspecialchars($f[0]); ?>" title="<?php echo htmlspecialchars($f[4]); ?>">
                    <input type="hidden" name="<?php echo $f[0]; ?>" value="0">
                    <input type="checkbox" name="<?php echo $f[0]; ?>" id="<?php echo $f[0]; ?>" <?php echo $checked; ?> style="display:none;" value="1">
                    <div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo $f[1]; ?></div>
                    <div class="feature-name"><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?><small style="display:block;font-size:10.5px;font-weight:600;color:var(--text-muted);margin-top:2px;"><?php echo htmlspecialchars($f[4]); ?></small></div>
                    <div class="feature-check"><?php if ($checked): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><?php endif; ?></div>
                </div>
                <?php
endforeach; ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">Ad Lead Attribution shows which Click-to-WhatsApp ad brought each lead. Turn it on for single companies under Companies → Edit → Add-ons.</div>
        </div>

        <!-- Message Limit -->
        <div style="margin-top:16px;">
            <div class="plan-grid" style="grid-template-columns:1fr;">
                <div class="plan-card">
                    <label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Messages per Month</label>
                    <input type="number" name="limit_messages_month" value="<?php echo intval($plan['limit_messages_month'] ?? 1000); ?>" min="0">
                    <div class="hint">Use 0 for unlimited. This limits outgoing WhatsApp messages per month.</div>
                </div>
            </div>
        </div>
    </div>
    

    <!-- Charges -->
    <div class="plan-section">
        <div class="plan-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Charges</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <!-- Monthly -->
            <div class="charge-section">
                <h4>Monthly</h4>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <div class="toggle-switch">
                        <input type="checkbox" name="monthly_enabled" id="monthlyEnabled" <?php echo $plan['monthly_enabled'] ? 'checked' : ''; ?>>
                        <label class="toggle-slider" for="monthlyEnabled"></label>
                    </div>
                    <label for="monthlyEnabled" style="font-size:13px; cursor:pointer; color:var(--text-secondary);">Enable this Charge</label>
                </div>
                <div class="charge-grid">
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px;">Stripe Plan Price ID</label>
                        <input type="text" name="monthly_stripe_id" class="form-control" placeholder="price_xxx" value="<?php echo htmlspecialchars($plan['monthly_stripe_id'] ?? ''); ?>" style="font-size:12px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px;">Charge Amount</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted);">₹</span>
                            <input type="number" name="monthly_price" class="form-control" value="<?php echo floatval($plan['monthly_price']); ?>" step="0.01" style="padding-left:28px; font-size:14px; font-weight:600;">
                            <span style="position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:11px; color:var(--text-muted);">INR</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Yearly -->
            <div class="charge-section">
                <h4>Yearly</h4>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <div class="toggle-switch">
                        <input type="checkbox" name="yearly_enabled" id="yearlyEnabled" <?php echo $plan['yearly_enabled'] ? 'checked' : ''; ?>>
                        <label class="toggle-slider" for="yearlyEnabled"></label>
                    </div>
                    <label for="yearlyEnabled" style="font-size:13px; cursor:pointer; color:var(--text-secondary);">Enable this Charge</label>
                </div>
                <div class="charge-grid">
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px;">Stripe Plan Price ID</label>
                        <input type="text" name="yearly_stripe_id" class="form-control" placeholder="price_xxx" value="<?php echo htmlspecialchars($plan['yearly_stripe_id'] ?? ''); ?>" style="font-size:12px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px;">Charge Amount</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted);">₹</span>
                            <input type="number" name="yearly_price" class="form-control" value="<?php echo floatval($plan['yearly_price']); ?>" step="0.01" style="padding-left:28px; font-size:14px; font-weight:600;">
                            <span style="position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:11px; color:var(--text-muted);">INR</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <button type="submit" name="save" class="btn btn-primary" style="padding:12px 32px; font-size:14px;">
        <?php if ($id > 0): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Update Plan<?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create Plan<?php endif; ?>
    </button>
</form>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// CSP-safe: use event delegation instead of inline onclick
document.addEventListener('click', function(e) {
    var card = e.target.closest('.feature-toggle-card[data-feature]');
    if (!card) return;
    var cb = card.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.checked = !cb.checked;
    card.classList.toggle('active', cb.checked);
    var check = card.querySelector('.feature-check');
    if (check) check.innerHTML = cb.checked ? '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"width:14px;height:14px;\"><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>' : '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"width:14px;height:14px;\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"15\" y1=\"9\" x2=\"9\" y2=\"15\"/><line x1=\"9\" y1=\"9\" x2=\"15\" y2=\"15\"/></svg>';
});
</script>

<?php include '../includes/admin_footer.php'; ?>
