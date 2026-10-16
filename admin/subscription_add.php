<?php
include '../includes/auth_check.php';
include '../db.php';

$type = isset($_GET['type']) && $_GET['type'] === 'manual' ? 'manual' : 'auto';
$pageTitle = 'Add ' . ucfirst($type) . ' Subscription';
$currentPage = 'subscriptions';

$error = '';
$plans = $conn->query("SELECT id, name, IFNULL(monthly_price,0) as monthly_price, IFNULL(yearly_price,0) as yearly_price FROM subscription_plans WHERE is_enabled=1 ORDER BY sort_order");
if (!$plans) $plans = $conn->query("SELECT id, name, 0 as monthly_price, 0 as yearly_price FROM subscription_plans ORDER BY id");
$companies = $conn->query("SELECT id, name FROM companies WHERE status='active' ORDER BY name");
if (!$companies) $companies = $conn->query("SELECT id, name FROM companies ORDER BY id");

if (isset($_POST['save'])) {
    csrf_verify();
    $companyId = intval($_POST['company_id']);
    $planId = intval($_POST['plan_id']);
    $billing = in_array($_POST['billing_cycle'], ['monthly', 'yearly', 'lifetime']) ? $_POST['billing_cycle'] : 'monthly';
    $amount = floatval($_POST['amount']);
    $status = in_array($_POST['status'], ['active', 'trial', 'pending']) ? $_POST['status'] : 'active';
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'manual');
    $paymentRef = sanitize($_POST['payment_ref'] ?? '');
    $startsAt = $_POST['starts_at'];
    $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    $notes = sanitize($_POST['notes'] ?? '');
    $whatsappNumber = sanitize_phone($_POST['whatsapp_number'] ?? '');
    $reminderEnabled = isset($_POST['reminder_enabled']) ? 1 : 0;

    if (!$companyId || !$planId || !$startsAt) {
        $error = "Company, Plan, and Start date are required!";
    }
    else {
        $stmt = $conn->prepare("INSERT INTO subscriptions (company_id, plan_id, type, status, billing_cycle, amount, payment_method, payment_ref, starts_at, expires_at, notes, whatsapp_number, reminder_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("iisssdssssssi", $companyId, $planId, $type, $status, $billing, $amount, $paymentMethod, $paymentRef, $startsAt, $expiresAt, $notes, $whatsappNumber, $reminderEnabled);
        $stmt->execute();

        // Add payment record
        $subId = $conn->insert_id;
        if ($amount > 0) {
            $pstmt = $conn->prepare("INSERT INTO subscription_payments (subscription_id, amount, payment_method, payment_ref, status) VALUES (?,?,?,?,'success')");
            $pstmt->bind_param("idss", $subId, $amount, $paymentMethod, $paymentRef);
            $pstmt->execute();
        }

        $redirect = $type === 'manual' ? 'subscriptions_manual.php' : 'subscriptions.php';
        header("Location: $redirect?msg=added");
        exit();
    }
}

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add <?php echo ucfirst($type); ?> Subscription</h2>
    <a href="<?php echo $type === 'manual' ? 'subscriptions_manual.php' : 'subscriptions.php'; ?>" class="btn btn-sm btn-edit">← Back</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div><?php
endif; ?>

<div class="form-card">
    <form method="POST">
        <?php echo csrf_field(); ?>
        
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
                <label>Company *</label>
                <select name="company_id" class="form-control" required>
                    <option value="">Select Company</option>
                    <?php while ($co = $companies->fetch_assoc()): ?>
                        <option value="<?php echo $co['id']; ?>"><?php echo htmlspecialchars($co['name']); ?></option>
                    <?php
endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Plan *</label>
                <select name="plan_id" class="form-control" required id="planSelect">
                    <option value="" data-monthly="0" data-yearly="0">Select Plan</option>
                    <?php while ($pl = $plans->fetch_assoc()): ?>
                        <option value="<?php echo $pl['id']; ?>" data-monthly="<?php echo $pl['monthly_price']; ?>" data-yearly="<?php echo $pl['yearly_price']; ?>"><?php echo htmlspecialchars($pl['name']); ?></option>
                    <?php
endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Billing Cycle</label>
                <select name="billing_cycle" class="form-control" id="billingCycle">
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                    <option value="lifetime">Lifetime</option>
                </select>
            </div>
            <div class="form-group">
                <label>Amount (₹)</label>
                <input type="number" name="amount" class="form-control" id="amountField" value="0" step="0.01" min="0">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Active</option>
                    <option value="trial"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M6.453 15h11.094"/><path d="M8.5 2h7"/></svg> Trial</option>
                    <option value="pending"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending</option>
                </select>
            </div>
            <div class="form-group">
                <label>Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="manual">Manual / Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="stripe">Stripe</option>
                    <option value="razorpay">Razorpay</option>
                </select>
            </div>
            <div class="form-group">
                <label>Payment Reference</label>
                <input type="text" name="payment_ref" class="form-control" placeholder="Transaction ID, UPI ref, etc.">
            </div>
            <div class="form-group">
                <label>Starts At *</label>
                <input type="date" name="starts_at" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Expires At</label>
                <input type="date" name="expires_at" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this subscription..."></textarea>
        </div>
        
        <!-- WhatsApp Reminder Section -->
        <div style="margin-top:8px; padding:18px; background:rgba(37,211,102,0.05); border:1px solid rgba(37,211,102,0.2); border-radius:10px;">
            <h3 style="font-size:15px; font-weight:700; color:#25d366; margin-bottom:14px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> WhatsApp Reminder</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Client WhatsApp Number</label>
                    <input type="text" name="whatsapp_number" class="form-control" placeholder="+91 9XXXXXXXXX" style="border-color:rgba(37,211,102,0.3);">
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:middle;margin-right:3px;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Plan expiry reminder will be sent to this number</div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Auto Reminder</label>
                    <div style="display:flex; align-items:center; gap:10px; margin-top:8px;">
                        <label style="position:relative; width:42px; height:22px; flex-shrink:0; cursor:pointer;">
                            <input type="checkbox" name="reminder_enabled" checked style="opacity:0; width:0; height:0;" id="reminderToggle">
                            <span style="position:absolute; inset:0; background:rgba(255,107,107,0.3); border-radius:22px; cursor:pointer; transition:0.3s;" id="reminderSlider"></span>
                        </label>
                        <span style="font-size:13px; color:var(--text-secondary);">Send reminder 2 days before expiry</span>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="submit" name="save" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Create Subscription</button>
    </form>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function updatePrice() {
    const plan = document.getElementById('planSelect');
    const cycle = document.getElementById('billingCycle').value;
    const opt = plan.options[plan.selectedIndex];
    if (opt && opt.value) {
        const price = cycle === 'yearly' ? opt.dataset.yearly : opt.dataset.monthly;
        document.getElementById('amountField').value = price;

        // Update Expiry Date
        const startInput = document.getElementsByName('starts_at')[0];
        const expiryInput = document.getElementsByName('expires_at')[0];
        if (startInput.value) {
            let start = new Date(startInput.value);
            if (cycle === 'monthly') start.setMonth(start.getMonth() + 1);
            else if (cycle === 'yearly') start.setFullYear(start.getFullYear() + 1);
            else if (cycle === 'lifetime') expiryInput.value = '';
            if (cycle !== 'lifetime') {
                expiryInput.value = start.toISOString().split('T')[0];
            }
        }
    }
}
// Wire plan/cycle selects — addEventListener replaces inline onchange (CSP-safe)
document.getElementById('planSelect').addEventListener('change', updatePrice);
document.getElementById('billingCycle').addEventListener('change', updatePrice);
document.getElementsByName('starts_at')[0].addEventListener('change', updatePrice);

// Toggle slider styling
const rt = document.getElementById('reminderToggle');
const rs = document.getElementById('reminderSlider');
function updateSlider() {
    rs.style.background = rt.checked ? '#25d366' : 'rgba(255,107,107,0.3)';
    rs.innerHTML = '<span style="position:absolute;width:16px;height:16px;background:white;border-radius:50%;left:' + (rt.checked ? '23px' : '3px') + ';top:3px;transition:0.3s;"></span>';
}
rt.addEventListener('change', updateSlider);
updateSlider();
</script>

<?php include '../includes/admin_footer.php'; ?>
