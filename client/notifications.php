<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_notifications')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)$_SESSION['company_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notifications'])) {
    csrf_verify();
    $l_em = isset($_POST['alert_leads_email']) ? 1 : 0;
    $l_wa = isset($_POST['alert_leads_wa']) ? 1 : 0;
    $c_em = isset($_POST['alert_campaign_email']) ? 1 : 0;
    $c_wa = isset($_POST['alert_campaign_wa']) ? 1 : 0;
    $w_em = isset($_POST['alert_wallet_email']) ? 1 : 0;
    $w_wa = isset($_POST['alert_wallet_wa']) ? 1 : 0;

    $st = $conn->prepare("INSERT INTO notification_settings (company_id, alert_leads_email, alert_leads_wa, alert_campaign_email, alert_campaign_wa, alert_wallet_email, alert_wallet_wa)
                  VALUES (?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE alert_leads_email=VALUES(alert_leads_email), alert_leads_wa=VALUES(alert_leads_wa), alert_campaign_email=VALUES(alert_campaign_email), alert_campaign_wa=VALUES(alert_campaign_wa), alert_wallet_email=VALUES(alert_wallet_email), alert_wallet_wa=VALUES(alert_wallet_wa)");
    if ($st) {
        $st->bind_param("iiiiiii", $companyId, $l_em, $l_wa, $c_em, $c_wa, $w_em, $w_wa);
        if ($st->execute()) {
            $success = "Preferences saved successfully!";
        } else {
            $error = "Could not save your preferences. Please try again.";
        }
        $st->close();
    } else {
        $error = "Could not save your preferences. Please try again.";
    }
}

$settings = null;
$stS = $conn->prepare("SELECT * FROM notification_settings WHERE company_id = ?");
if ($stS) {
    $stS->bind_param("i", $companyId);
    $stS->execute();
    $settings = $stS->get_result()->fetch_assoc();
    $stS->close();
}
if (!$settings) {
    $settings = [
        'alert_leads_email' => 1, 'alert_leads_wa' => 1,
        'alert_campaign_email' => 1, 'alert_campaign_wa' => 1,
        'alert_wallet_email' => 1, 'alert_wallet_wa' => 1
    ];
}
$on = static function ($k) use ($settings) { return !empty($settings[$k]); };
$activeCount = 0;
foreach (['alert_leads_email', 'alert_leads_wa', 'alert_campaign_email', 'alert_wallet_wa'] as $k) { if ($on($k)) $activeCount++; }

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

$pageTitle = 'Notification Preferences';
$currentPage = 'notifications';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=1'); ?>">

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>', 25); ?></div>
        <div>
            <h1>Notification Preferences</h1>
            <p>Choose which alerts you get, and where: by email or on your registered WhatsApp number.</p>
        </div>
    </div>
    <span class="su-pill <?php echo $activeCount > 0 ? 'on' : ''; ?>"><i></i> <?php echo (int)$activeCount; ?> alert<?php echo $activeCount === 1 ? '' : 's'; ?> on</span>
</div>

<?php if ($success): ?><div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="su-alert err" style="margin-bottom:18px"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>', 18); ?> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="POST">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="save_notifications" value="1">

    <div class="su-grid even" style="margin-bottom:18px">

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></span> New leads &amp; interactions</h2>
            <p class="sub">Know the moment a customer reaches out.</p>
            <div class="su-rows">
                <label class="su-row"><span class="su-row-t"><strong>Email alerts</strong><span>Receive lead details in your inbox</span></span>
                    <span class="su-sw"><input type="checkbox" name="alert_leads_email" <?php echo $on('alert_leads_email') ? 'checked' : ''; ?>><i></i></span></label>
                <label class="su-row"><span class="su-row-t"><strong>WhatsApp alerts</strong><span>Get instant pings on your registered number</span></span>
                    <span class="su-sw"><input type="checkbox" name="alert_leads_wa" <?php echo $on('alert_leads_wa') ? 'checked' : ''; ?>><i></i></span></label>
            </div>
        </section>

        <div class="su-col">
            <section class="su-card">
                <h2><span class="ch"><?php echo $lI('<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>'); ?></span> Campaign intelligence</h2>
                <p class="sub">Stay on top of how your campaigns perform.</p>
                <div class="su-rows">
                    <label class="su-row"><span class="su-row-t"><strong>Campaign summaries</strong><span>Get a report when a campaign finishes</span></span>
                        <span class="su-sw"><input type="checkbox" name="alert_campaign_email" <?php echo $on('alert_campaign_email') ? 'checked' : ''; ?>><i></i></span></label>
                </div>
            </section>

            <section class="su-card">
                <h2><span class="ch"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'); ?></span> Wallet &amp; billing</h2>
                <p class="sub">Never run out of credits by surprise.</p>
                <div class="su-rows">
                    <label class="su-row"><span class="su-row-t"><strong>Low balance warning</strong><span>Alert me when credits fall below ₹100</span></span>
                        <span class="su-sw"><input type="checkbox" name="alert_wallet_wa" <?php echo $on('alert_wallet_wa') ? 'checked' : ''; ?>><i></i></span></label>
                </div>
            </section>
        </div>

    </div>

    <button type="submit" class="su-btn"><?php echo $lI('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?> Save preferences</button>
</form>

</div>

<?php include '../includes/client_footer.php'; ?>
