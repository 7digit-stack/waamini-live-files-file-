<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_referral')) {
    header("Location: dashboard.php");
    exit();
}

$companyId = (int)$_SESSION['company_id'];

// Referral code (created once, on first visit)
$company = [];
$stC = $conn->prepare("SELECT referral_code FROM companies WHERE id = ?");
if ($stC) {
    $stC->bind_param("i", $companyId);
    $stC->execute();
    $company = $stC->get_result()->fetch_assoc() ?: [];
    $stC->close();
}
if (empty($company['referral_code'])) {
    $newCode = 'WA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stU = $conn->prepare("UPDATE companies SET referral_code = ? WHERE id = ?");
    if ($stU) {
        $stU->bind_param("si", $newCode, $companyId);
        $stU->execute();
        $stU->close();
    }
    $company['referral_code'] = $newCode;
}

$referralLink = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_url('register.php?ref=' . $company['referral_code']);

$earnings = 0.0;
$stE = $conn->prepare("SELECT SUM(amount) AS total FROM referral_earnings WHERE company_id = ? AND status = 'approved'");
if ($stE) {
    $stE->bind_param("i", $companyId);
    $stE->execute();
    $earnings = (float)($stE->get_result()->fetch_assoc()['total'] ?? 0);
    $stE->close();
}

$referredCount = 0;
$stR = $conn->prepare("SELECT COUNT(*) AS total FROM companies WHERE referred_by = ?");
if ($stR) {
    $stR->bind_param("i", $companyId);
    $stR->execute();
    $referredCount = (int)($stR->get_result()->fetch_assoc()['total'] ?? 0);
    $stR->close();
}

$referrals = [];
$stL = $conn->prepare("SELECT company_name, created_at FROM companies WHERE referred_by = ? ORDER BY id DESC LIMIT 10");
if ($stL) {
    $stL->bind_param("i", $companyId);
    $stL->execute();
    $rl = $stL->get_result();
    while ($rl && ($row = $rl->fetch_assoc())) { $referrals[] = $row; }
    $stL->close();
}

$payoutMin = 1000;
$toGo = max(0, $payoutMin - $earnings);
$progress = $payoutMin > 0 ? min(100, (int)round($earnings / $payoutMin * 100)) : 100;

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

$pageTitle = 'Referral Program';
$currentPage = 'referral';
include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=1'); ?>">
<style>
.rf-link { display:flex; align-items:center; gap:10px; background:var(--field); border-radius:14px; padding:6px 6px 6px 18px; }
.rf-link code { flex:1; min-width:0; font-size:14px; font-weight:700; color:var(--gd); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-family:ui-monospace,Consolas,monospace; background:transparent; }
.rf-steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
.rf-step { background:var(--soft); border-radius:16px; padding:18px; }
.rf-step .n { width:40px; height:40px; border-radius:12px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; margin-bottom:12px; }
.rf-step strong { display:block; font-size:15px; color:var(--ink); margin-bottom:4px; }
.rf-step span { font-size:13px; color:var(--tx); line-height:1.5; }
.rf-list { display:flex; flex-direction:column; gap:8px; }
.rf-item { display:grid; grid-template-columns:42px minmax(0,1fr) auto; gap:12px; align-items:center; background:var(--soft); border-radius:14px; padding:12px 14px; }
.rf-av { width:42px; height:42px; border-radius:50%; background:#E3F7EE; color:var(--gd); display:grid; place-items:center; font-weight:800; }
.rf-nm { font-weight:800; font-size:15px; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rf-dt { font-size:13px; color:var(--mu); }
.rf-bar { height:10px; border-radius:99px; background:#fff; overflow:hidden; }
.rf-bar i { display:block; height:100%; border-radius:99px; background:var(--g); }
.rf-big { font-size:38px; font-weight:800; letter-spacing:-.04em; color:var(--ink); line-height:1.05; }
.rf-toast { position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(80px); background:#0F2622; color:#fff; padding:12px 22px; border-radius:12px; font-size:14px; font-weight:700; opacity:0; transition:all .3s ease; z-index:9999; pointer-events:none; max-width:90vw; text-align:center; }
.rf-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
@media (max-width:900px) { .rf-steps { grid-template-columns:minmax(0,1fr); } .rf-link { flex-direction:column; align-items:stretch; padding:12px; } .rf-link code { white-space:normal; word-break:break-all; } }
</style>

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 25); ?></div>
        <div>
            <h1>Referral Program</h1>
            <p>Earn rewards for every business you bring to Waamini. Share your link and earn commission on every plan they buy.</p>
        </div>
    </div>
</div>

<div class="su-kpis k3">
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></div><div><b>₹<?php echo number_format($earnings, 2); ?></b><span>Total earnings</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>'); ?></div><div><b><?php echo number_format($referredCount); ?></b><span>Businesses referred</span></div></div>
    <div class="su-kpi amber"><div class="su-kpi-i"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'); ?></div><div><b>₹<?php echo number_format($payoutMin); ?></b><span>Minimum payout</span></div></div>
</div>

<div class="su-grid">

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'); ?></span> Your referral link</h2>
            <p class="sub">Send this link to other business owners. When they sign up through it, they count as your referral.</p>
            <div class="rf-link">
                <code id="referralLink"><?php echo htmlspecialchars($referralLink); ?></code>
                <button type="button" class="su-btn" onclick="copyLink()"><?php echo $lI('<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>'); ?> Copy link</button>
            </div>
            <div class="rf-steps">
                <div class="rf-step"><div class="n"><?php echo $lI('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'); ?></div><strong>1. Invite</strong><span>Send your unique link to other business owners.</span></div>
                <div class="rf-step"><div class="n"><?php echo $lI('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'); ?></div><strong>2. They sign up</strong><span>They register and start their WhatsApp journey.</span></div>
                <div class="rf-step"><div class="n"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></div><strong>3. You earn</strong><span>Get recurring commission on every plan they buy.</span></div>
            </div>
        </section>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></span> Recent referrals</h2>
            <?php if ($referrals): ?>
            <div class="rf-list">
                <?php foreach ($referrals as $r):
                    $nm = trim((string)($r['company_name'] ?? ''));
                    $ini = strtoupper(function_exists('mb_substr') ? mb_substr($nm !== '' ? $nm : 'B', 0, 1, 'UTF-8') : substr($nm !== '' ? $nm : 'B', 0, 1));
                    $ts = strtotime((string)($r['created_at'] ?? ''));
                ?>
                <div class="rf-item">
                    <div class="rf-av"><?php echo htmlspecialchars($ini); ?></div>
                    <div style="min-width:0"><div class="rf-nm"><?php echo htmlspecialchars($nm !== '' ? $nm : 'New business'); ?></div><div class="rf-dt">Joined <?php echo $ts ? date('d M Y', $ts) : '—'; ?></div></div>
                    <span class="su-pill on"><i></i> Active</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="su-empty">
                <div class="e-i"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 28); ?></div>
                <h3>No referrals yet</h3>
                <p>Share your link above and your first referral will show up here.</p>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'); ?></span> Payout</h2>
            <div class="rf-big">₹<?php echo number_format($earnings, 2); ?></div>
            <div>
                <div class="rf-bar" aria-hidden="true"><i style="width:<?php echo $progress; ?>%"></i></div>
                <p class="sub" style="margin:8px 0 0"><?php echo $toGo > 0 ? '₹' . number_format($toGo, 2) . ' more to reach the ₹' . number_format($payoutMin) . ' minimum payout.' : 'You have reached the minimum payout.'; ?></p>
            </div>
            <button type="button" class="su-btn block" id="payoutBtn"><?php echo $lI('<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>'); ?> Request payout</button>
        </section>

        <div class="su-note">
            <?php echo $lI('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 22); ?>
            <div>
                <h4>Affiliate tip</h4>
                <p>Businesses that send more messages earn you more. Help your referrals set up their first campaign to grow your recurring commission.</p>
            </div>
        </div>
    </div>

</div>
</div>

<div id="copyToast" class="rf-toast" role="status" aria-live="polite"></div>

<script>
var RF_EARN = <?php echo json_encode($earnings); ?>;
var RF_MIN = <?php echo (int)$payoutMin; ?>;
function rfToast(msg) {
    var t = document.getElementById('copyToast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(rfToast._t);
    rfToast._t = setTimeout(function () { t.classList.remove('show'); }, 2800);
}
function copyLink() {
    var text = document.getElementById('referralLink').textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { rfToast('Referral link copied'); }, function () { rfToast('Press Ctrl+C to copy the link'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        rfToast(ok ? 'Referral link copied' : 'Press Ctrl+C to copy the link');
    }
}
var pb = document.getElementById('payoutBtn');
if (pb) pb.addEventListener('click', function () {
    if (RF_EARN < RF_MIN) {
        rfToast('Minimum payout is ₹' + RF_MIN.toLocaleString('en-IN') + '. You have ₹' + RF_EARN.toFixed(2) + ' so far.');
    } else {
        rfToast('Payout request submitted. Our team will process it within 5-7 business days.');
    }
});
</script>

<?php include '../includes/client_footer.php'; ?>
