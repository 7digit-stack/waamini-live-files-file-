<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Abandoned Cart Recovery';
$currentPage = 'settings_cart_recovery';

// Schema migrations are managed via `migrations/` and should not run inline.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $delay   = max(5, intval($_POST['delay_minutes'] ?? 30));
    $msg1    = trim($_POST['message1']);
    $msg2    = trim($_POST['message2']);
    $msg3    = trim($_POST['message3']);
    $code    = sanitize($_POST['discount_code'] ?? '');
    $pct     = min(100, max(0, intval($_POST['discount_pct'] ?? 10)));
    $stmt    = $conn->prepare("INSERT INTO cart_recovery_settings (company_id,is_active,delay_minutes,message1,message2,message3,discount_code,discount_pct) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),delay_minutes=VALUES(delay_minutes),message1=VALUES(message1),message2=VALUES(message2),message3=VALUES(message3),discount_code=VALUES(discount_code),discount_pct=VALUES(discount_pct)");
    $stmt->bind_param("iiissssi", $companyId,$active,$delay,$msg1,$msg2,$msg3,$code,$pct);
    $stmt->execute();
    header("Location: settings_cart_recovery.php?msg=saved"); exit();
}

$stmt_s = $conn->prepare("SELECT * FROM cart_recovery_settings WHERE company_id=?");
$stmt_s->bind_param("i", $companyId);
$stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

$def1 = "Hey {{first_name}}!  You left something in your cart at *{{business_name}}*.\n\n Your cart total: ₹{{cart_total}}\n\nComplete your order before it expires!  {{checkout_url}}";
$def2 = "{{first_name}}, your cart is waiting! \n\nWe've saved your items at *{{business_name}}*. Don't miss out!\n\n Offer valid for 24 hours only.\n\n {{checkout_url}}";
$def3 = "Last chance, {{first_name}}! \n\nHere's an exclusive *{{discount_pct}}% off* just for you!\n\n Use code: *{{discount_code}}*\n\n {{checkout_url}}\n\nOffer expires in 2 hours!";

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Settings saved!</div><?php endif; ?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> Abandoned Cart Recovery</h2></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
            <input type="checkbox" name="is_active" id="active_cb" <?php echo ($s['is_active']??1)?'checked':''; ?>>
            <label for="active_cb" style="margin:0;font-size:13px;font-weight:600;">Enable Cart Recovery</label>
        </div>
        <div class="form-group">
            <label>First Message Delay (minutes after abandonment)</label>
            <input type="number" name="delay_minutes" class="form-control" value="<?php echo $s['delay_minutes']??30; ?>" min="5" max="1440">
        </div>
        <div class="form-group">
            <label>Message 1 (sent after delay)</label>
            <textarea name="message1" class="form-control" rows="4"><?php echo htmlspecialchars($s['message1']??$def1); ?></textarea>
        </div>
        <div class="form-group">
            <label>Message 2 (sent after 2 hours)</label>
            <textarea name="message2" class="form-control" rows="4"><?php echo htmlspecialchars($s['message2']??$def2); ?></textarea>
        </div>
        <div class="form-group">
            <label>Message 3 — with discount (sent after 6 hours)</label>
            <textarea name="message3" class="form-control" rows="4"><?php echo htmlspecialchars($s['message3']??$def3); ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>Discount Code</label>
                <input type="text" name="discount_code" class="form-control" value="<?php echo htmlspecialchars($s['discount_code']??'SAVE10'); ?>">
            </div>
            <div class="form-group">
                <label>Discount %</label>
                <input type="number" name="discount_pct" class="form-control" value="<?php echo $s['discount_pct']??10; ?>" min="0" max="100">
            </div>
        </div>
        <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save</button>
    </form>
</div>
<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Integration</h3>
        <p style="font-size:13px;color:var(--text-secondary);">Add this to your website/app when a cart is created:</p>
        <pre style="background:var(--bg-input);border-radius:8px;padding:12px;font-size:11px;overflow-x:auto;">POST /api/cart_event.php
{
  "api_key": "YOUR_API_KEY",
  "phone": "919876543210",
  "cart_total": 1250.00,
  "checkout_url": "https://yoursite.com/checkout",
  "items": [{"name":"Product","qty":1,"price":1250}]
}</pre>
        <small style="color:var(--text-muted);">Get API key from <a href="settings.php">Settings → API</a></small>
    </div>
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Recovery Stats</h3>
        <?php
        $stmt_st = $conn->prepare("SELECT recovery_status, COUNT(*) as c FROM abandoned_carts WHERE company_id=? GROUP BY recovery_status");
        $stmt_st->bind_param("i", $companyId);
        $stmt_st->execute();
        $stMap = []; while($r=$stmt_st->get_result()->fetch_assoc()) $stMap[$r['recovery_status']]=$r['c'];
        ?>
        <div style="font-size:12px;color:var(--text-secondary);line-height:2.2;">
            <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> Total Abandoned: <strong><?php echo array_sum($stMap); ?></strong></div>
            <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Recovered: <strong style="color:var(--accent);"><?php echo $stMap['recovered']??0; ?></strong></div>
            <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Messages Sent: <strong><?php echo ($stMap['sent1']??0)+($stMap['sent2']??0)+($stMap['sent3']??0); ?></strong></div>
        </div>
    </div>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>

