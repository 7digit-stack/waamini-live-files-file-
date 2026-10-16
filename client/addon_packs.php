<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Message Add-on Packs';
$currentPage = 'addon_packs';

// Payment settings
$stmt_pay = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='payment'");
$paySettings = [];
while ($r = $stmt_pay->fetch_assoc()) { $paySettings[$r['setting_key']] = $r['setting_value']; }
$rzKeyId = $paySettings['razorpay_key_id'] ?? '';
$currency = $paySettings['currency'] ?? 'INR';
$symbol   = $paySettings['currency_symbol'] ?? '₹';

// Handle Razorpay order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pack_id'])) {
    csrf_verify();
    $packId = intval($_POST['pack_id']);
    $stmt_pk = $conn->prepare("SELECT * FROM addon_packs WHERE id=? AND is_active=1");
    $stmt_pk->bind_param("i", $packId);
    $stmt_pk->execute();
    $pack = $stmt_pk->get_result()->fetch_assoc();
    if (!$pack) { die("Invalid pack"); }

    // Create Razorpay order
    $rzSecret = $paySettings['razorpay_key_secret'] ?? '';
    $amount   = intval($pack['price'] * 100); // paise
    $payload  = json_encode(['amount' => $amount, 'currency' => $currency, 'receipt' => 'addon_' . $companyId . '_' . time()]);
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$rzKeyId:$rzSecret",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($resp['id'])) {
        $stmt_ins = $conn->prepare("INSERT INTO addon_purchases (company_id, pack_id, messages_count, amount, gateway_order_id, status) VALUES (?,?,?,?,'pending')");
        // Fix: correct number of params
        $stmt_ins = $conn->prepare("INSERT INTO addon_purchases (company_id, pack_id, messages_count, amount, gateway_order_id, status) VALUES (?,?,?,?,?,'pending')");
        $stmt_ins->bind_param("iidss", $companyId, $packId, $pack['messages_count'], $pack['price'], $resp['id']);
        $stmt_ins->execute();
        $purchaseId = $stmt_ins->insert_id;
        echo json_encode(['order_id' => $resp['id'], 'purchase_id' => $purchaseId, 'amount' => $amount, 'key' => $rzKeyId]);
        exit();
    }
    echo json_encode(['error' => 'Payment gateway error. Please try again.']);
    exit();
}

// Handle payment success callback
if (isset($_POST['razorpay_payment_id']) && isset($_POST['purchase_id'])) {
    csrf_verify();
    $purchaseId = intval($_POST['purchase_id']);
    $paymentId  = sanitize($_POST['razorpay_payment_id']);
    $stmt_pu = $conn->prepare("SELECT * FROM addon_purchases WHERE id=? AND company_id=? AND status='pending'");
    $stmt_pu->bind_param("ii", $purchaseId, $companyId);
    $stmt_pu->execute();
    $purchase = $stmt_pu->get_result()->fetch_assoc();
    if ($purchase) {
        // Mark paid + add credits
        $stmt_upd = $conn->prepare("UPDATE addon_purchases SET status='paid', gateway_payment_id=?, paid_at=NOW() WHERE id=?");
        $stmt_upd->bind_param("si", $paymentId, $purchaseId);
        $stmt_upd->execute();
        $stmt_cred = $conn->prepare("UPDATE companies SET addon_message_credits = addon_message_credits + ? WHERE id=?");
        $stmt_cred->bind_param("ii", $purchase['messages_count'], $companyId);
        $stmt_cred->execute();
        header("Location: addon_packs.php?msg=success&credits=" . $purchase['messages_count']);
        exit();
    }
    header("Location: addon_packs.php?msg=failed");
    exit();
}

// Load packs + current credits
$packs = $conn->query("SELECT * FROM addon_packs WHERE is_active=1 ORDER BY sort_order ASC");
$stmt_co = $conn->prepare("SELECT addon_message_credits FROM companies WHERE id=?");
$stmt_co->bind_param("i", $companyId);
$stmt_co->execute();
$credits = intval($stmt_co->get_result()->fetch_assoc()['addon_message_credits'] ?? 0);

include '../includes/client_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'success'): ?>
    <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Payment successful! <?php echo intval($_GET['credits'] ?? 0); ?> message credits added to your account.</div>
    <?php else: ?>
    <div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Payment failed or cancelled. Please try again.</div>
    <?php endif; ?>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Message Add-on Packs</h2>
    <div style="background:rgba(0,184,148,0.1);border:1px solid rgba(0,184,148,0.2);border-radius:8px;padding:8px 16px;font-size:13px;color:var(--accent);font-weight:600;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Add-on Credits: <?php echo number_format($credits); ?> messages remaining
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:32px;">
    <?php while ($pack = $packs->fetch_assoc()): ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:28px 24px;text-align:center;position:relative;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform=''">
        <div style="font-size:36px;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg></div>
        <h3 style="font-size:18px;font-weight:700;margin-bottom:8px;"><?php echo htmlspecialchars($pack['name']); ?></h3>
        <div style="font-size:32px;font-weight:800;color:var(--accent);margin:12px 0;"><?php echo $symbol; ?><?php echo number_format($pack['price'], 0); ?></div>
        <div style="font-size:14px;color:var(--text-muted);margin-bottom:20px;"><?php echo number_format($pack['messages_count']); ?> WhatsApp Messages</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;"><?php echo $symbol; ?><?php echo number_format($pack['price'] / $pack['messages_count'], 3); ?> per message</div>
        <?php if (!empty($rzKeyId)): ?>
        <button onclick="buyPack(<?php echo $pack['id']; ?>, '<?php echo htmlspecialchars($pack['name'], ENT_QUOTES); ?>', <?php echo $pack['price']; ?>)"
            class="btn btn-primary" style="width:100%;">Buy Now</button>
        <?php else: ?>
        <div style="font-size:12px;color:var(--text-muted);">Payment not configured</div>
        <?php endif; ?>
    </div>
    <?php endwhile; ?>
</div>

<div class="form-card" style="max-width:none;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#016df8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg> How Add-on Credits Work</h3>
    <ul style="font-size:13px;color:var(--text-secondary);line-height:2;list-style:none;padding:0;">
        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Credits are used after your monthly plan limit is reached</li>
        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Never expire — use them anytime</li>
        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Apply to campaigns, automations, and manual messages</li>
        <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Buy multiple packs — credits stack</li>
    </ul>
</div>

<form id="paymentForm" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="purchase_id" id="purchaseIdInput">
    <input type="hidden" name="razorpay_payment_id" id="rzPaymentId">
</form>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
function buyPack(packId, packName, price) {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=<?php echo urlencode(csrf_token()); ?>&pack_id=' + packId
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert(data.error); return; }
        var rzp = new Razorpay({
            key: data.key,
            amount: data.amount,
            currency: '<?php echo $currency; ?>',
            order_id: data.order_id,
            name: 'WAAMINI',
            description: packName,
            handler: function(resp) {
                document.getElementById('purchaseIdInput').value = data.purchase_id;
                document.getElementById('rzPaymentId').value = resp.razorpay_payment_id;
                document.getElementById('paymentForm').submit();
            },
        });
        rzp.open();
    });
}
</script>

<?php include '../includes/client_footer.php'; ?>
