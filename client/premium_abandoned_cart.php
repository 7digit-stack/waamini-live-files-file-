<?php
include '../includes/client_auth_check.php';

// Cart Recovery is switched off (removed from the E-Commerce menu). Old links go to Order Analytics.
header('Location: ' . app_url('client/order_dashboard.php'));
exit();

include_once '../includes/schema_guard.php';

$pageTitle = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> E-Commerce Abandoned Cart Recovery';
$currentPage = 'premium_abandoned_cart';

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS ecommerce_carts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            cart_url TEXT,
            cart_total DECIMAL(12,2) DEFAULT 0.00,
            status VARCHAR(32) DEFAULT 'abandoned',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_co (company_id, contact_id),
            INDEX idx_cop (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    error_log('[WAAMINI] premium_abandoned_cart: ecommerce_carts create: ' . $e->getMessage());
}

// API key for webhook (one key per company — use latest row)
$apiKey = null;
$st6a = $conn->prepare('SELECT api_key FROM api_keys WHERE company_id = ? ORDER BY id DESC LIMIT 1');
if ($st6a) {
    $st6a->bind_param('i', $companyId);
    $st6a->execute();
    $rowKey = $st6a->get_result()->fetch_assoc();
    if ($rowKey && !empty($rowKey['api_key'])) {
        $apiKey = (string)$rowKey['api_key'];
    }
}

if ($apiKey === null || $apiKey === '') {
    $apiKey = bin2hex(random_bytes(32));
    $stmt = $conn->prepare('INSERT INTO api_keys (company_id, api_key, is_active) VALUES (?, ?, 1)');
    if ($stmt) {
        $stmt->bind_param('is', $companyId, $apiKey);
        $stmt->execute();
    } else {
        error_log('[WAAMINI] premium_abandoned_cart.php: api_keys insert prepare failed');
    }
    $st6a->execute();
    $rowKey = $st6a->get_result()->fetch_assoc();
    if ($rowKey && !empty($rowKey['api_key'])) {
        $apiKey = (string)$rowKey['api_key'];
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $webhookUrl = $protocol . '://' . $host . '/api/ecommerce_catch.php?company_id=' . (int)$companyId . '&api_key=' . rawurlencode($apiKey);

// Stats (recovered is set when Waamini marks an ecommerce order paid — see api/payment_success.php)
$recoveredCount = 0;
$recoveredValue = 0.0;
$abandonedCount = 0;
$abandonedValue = 0.0;

$st6b = $conn->prepare("SELECT COUNT(*) AS c FROM ecommerce_carts WHERE company_id = ? AND status = 'recovered'");
if ($st6b) {
    $st6b->bind_param('i', $companyId);
    $st6b->execute();
    $recoveredCount = (int)($st6b->get_result()->fetch_assoc()['c'] ?? 0);
}

$st6c = $conn->prepare("SELECT SUM(cart_total) AS t FROM ecommerce_carts WHERE company_id = ? AND status = 'recovered'");
if ($st6c) {
    $st6c->bind_param('i', $companyId);
    $st6c->execute();
    $rv = $st6c->get_result()->fetch_assoc()['t'] ?? null;
    $recoveredValue = $rv !== null ? (float)$rv : 0.0;
}

$st6d = $conn->prepare("SELECT COUNT(*) AS c FROM ecommerce_carts WHERE company_id = ? AND status = 'abandoned'");
if ($st6d) {
    $st6d->bind_param('i', $companyId);
    $st6d->execute();
    $abandonedCount = (int)($st6d->get_result()->fetch_assoc()['c'] ?? 0);
}

$st6e = $conn->prepare("SELECT SUM(cart_total) AS t FROM ecommerce_carts WHERE company_id = ? AND status = 'abandoned'");
if ($st6e) {
    $st6e->bind_param('i', $companyId);
    $st6e->execute();
    $av = $st6e->get_result()->fetch_assoc()['t'] ?? null;
    $abandonedValue = $av !== null ? (float)$av : 0.0;
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> E-Commerce Abandoned Cart Recovery</h2>
    <div style="font-size:13px;color:var(--text-secondary);margin-top:8px;">Automatically follow up with shoppers who leave without buying. Works with Shopify, WooCommerce, and custom stores.</div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0,1fr)); margin-bottom: 18px;">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($abandonedCount); ?></div>
            <div class="stat-label">Abandoned carts (tracked)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-number">₹<?php echo number_format($abandonedValue, 2); ?></div>
            <div class="stat-label">Value in abandoned carts</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($recoveredCount); ?></div>
            <div class="stat-label">Carts recovered (paid via Waamini)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-number">₹<?php echo number_format($recoveredValue, 2); ?></div>
            <div class="stat-label">Revenue from recovered carts</div>
        </div>
    </div>
</div>

<p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">Recovered totals increase when a customer completes payment through your Waamini commerce flow after we had tracked their abandoned cart.</p>

<div class="dashboard-grid" style="grid-template-columns: 2fr 1fr;">
    
    <!-- URL Settings -->
    <div>
        <div class="form-card" style="max-width:none;margin-top:0;">
            <h3 style="font-size:15px;font-weight:700;color:var(--text-primary);margin-bottom:16px;">Step 1: Your Store Webhook URL</h3>
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:24px;">Paste this URL into the Webhook settings of your Shopify or WooCommerce store.</p>
            
            <div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:8px;padding:16px;display:flex;align-items:center;gap:12px;margin-bottom:24px;">
                <input type="text" id="webhookUrlInput" value="<?php echo htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly style="flex:1;background:transparent;border:none;color:var(--text-primary);font-family:monospace;font-size:13px;outline:none;">
                <button type="button" onclick="copyWebhook(this)" class="btn btn-sm btn-primary">Copy <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg></button>
            </div>
        </div>

        <div class="form-card" style="max-width:none;margin-top:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                 <h3 style="font-size:15px;font-weight:700;color:var(--text-primary);margin:0;">Step 2: Recovery Message Template</h3>
                 <span class="badge badge-active" style="font-size:11px;">Active</span>
            </div>
            
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">This message will be automatically sent via WhatsApp approximately 1 hour after a cart is abandoned (see <code>api/ecommerce_catch.php</code>).</p>
            
            <div style="background:var(--bg-dark); border:1px solid var(--border); border-radius:8px; padding:16px; color:var(--text-primary); font-size:14px; position:relative; min-height:100px;">
                <div style="position:absolute; top: -10px; right: 20px; background:#25D366; color:#fff; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                Hey <strong>{{name}}</strong>! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg><br><br>
                We noticed you left some awesome items in your cart worth <strong>{{total}}</strong>.<br><br>
                Complete your order now and get <strong>10% OFF</strong> using code: <strong>RECOVER10</strong><br><br>
                Return to cart: <strong>{{checkout_url}}</strong>
            </div>

            <button type="button" class="btn btn-sm" style="margin-top:16px; background:var(--bg-input); border:1px solid var(--border); color:var(--text-primary);" onclick="alert('Template editing requires WhatsApp Business Approval. This is fixed for the Beta version.')">Edit Template <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg></button>
        </div>
    </div>

    <!-- Right Column: Tutorials -->
    <div>
        <div class="form-card" style="margin:0;max-width:none;">
            <h3 style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> Setup Guide</h3>
            
            <div style="margin-bottom:16px;">
                <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">Shopify Configuration</div>
                <div style="font-size:12px;color:var(--text-secondary);">Go to Settings &gt; Notifications &gt; Webhooks. Create a new webhook for &quot;Cart Creation&quot; and paste the URL.</div>
            </div>
            
            <div style="margin-bottom:16px;">
                <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">WooCommerce Configuration</div>
                <div style="font-size:12px;color:var(--text-secondary);">Settings &gt; Advanced &gt; Webhooks. Add webhook, set Topic to &quot;Action&quot; and hook to <code>woocommerce_cart_updated</code>.</div>
            </div>
            
             <div style="margin-bottom:16px;">
                <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">When does it send?</div>
                <div style="font-size:12px;color:var(--text-secondary);">WAAMINI queues the recovery message for about 60 minutes after the webhook fires (see scheduled time in <code>ecommerce_catch.php</code>).</div>
            </div>
        </div>
    </div>
</div>

<script>
function copyWebhook(btn) {
    var copyText = document.getElementById('webhookUrlInput');
    if (!copyText) return;

    copyText.focus();
    copyText.select();
    copyText.setSelectionRange(0, 99999);

    var ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) {}

    if (!ok && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(copyText.value).then(function() { ok = true; }).catch(function() {});
    }

    var el = btn && btn.closest ? btn.closest('button') : btn;
    if (el) {
        var oldText = el.innerText;
        el.innerText = ok ? 'Copied!' : 'Press Ctrl+C';
        el.style.background = ok ? '#00b894' : '';
        setTimeout(function() {
            el.innerText = oldText;
            el.style.background = '';
        }, 2000);
    }
}
</script>

<?php include '../includes/client_footer.php'; ?>
