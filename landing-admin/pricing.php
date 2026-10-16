<?php
/** Landing Admin — Pricing Plans */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../includes/cms_pricing.php';
la_require_login();

$laTitle = 'Pricing Plans';
$laPage  = 'pricing';
cms_price_schema($conn);
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        $n = cms_price_seed($conn, !empty($_POST['replace']));
        la_flash_set('success', $n ? "Pricing page plans copied into the CMS ($n plans)." : 'The CMS already has plans — nothing changed.');
        header('Location: pricing.php'); exit;
    }
    if (in_array($action, ['create','update'], true)) {
        $id           = (int)($_POST['plan_id']      ?? 0);
        $name         = trim($_POST['name']           ?? '');
        $tagline      = trim($_POST['tagline']        ?? '');
        $priceMonthly = (float)($_POST['price_monthly'] ?? 0);
        $priceYearly  = (float)($_POST['price_yearly']  ?? 0);
        $currency     = trim($_POST['currency']       ?? 'INR');
        $cta_text     = trim($_POST['cta_text']       ?? 'Get Started');
        $cta_url      = trim($_POST['cta_url']        ?? '');
        $sort         = (int)($_POST['sort_order']    ?? 0);
        $popular      = isset($_POST['is_popular'])   ? 1 : 0;
        $active       = isset($_POST['is_active'])    ? 1 : 0;

        // Feature lines → JSON
        $featureLines = array_values(array_filter(array_map('trim', explode("\n", $_POST['features_text'] ?? ''))));
        $featuresJson = json_encode($featureLines, JSON_UNESCAPED_UNICODE);
        $extraJson = json_encode([
            'list_price'     => max(0, (int)($_POST['list_price'] ?? 0)),
            'ribbon_monthly' => trim($_POST['ribbon_monthly'] ?? ''),
            'ribbon_yearly'  => trim($_POST['ribbon_yearly'] ?? ''),
            'cta_icon'       => isset(cms_price_icons()[$_POST['cta_icon'] ?? '']) ? $_POST['cta_icon'] : '',
        ], JSON_UNESCAPED_UNICODE);

        if (!$name) { la_flash_set('error', 'Plan name is required.'); header('Location: pricing.php'); exit; }

        $pmStr = number_format($priceMonthly, 2, '.', '');
        $pyStr = number_format($priceYearly,  2, '.', '');

        if ($action === 'create') {
            // 11 params: 8 strings + 3 ints
            $st = $conn->prepare("INSERT INTO lp_pricing (name,tagline,price_monthly,price_yearly,currency,features_json,cta_text,cta_url,sort_order,is_popular,is_active,extra_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('ssssssssiiis', $name, $tagline, $pmStr, $pyStr, $currency, $featuresJson, $cta_text, $cta_url, $sort, $popular, $active, $extraJson);
            $st->execute(); $st->close();
            la_flash_set('success', "Plan \"$name\" created.");
        } else {
            if (!$id) { la_flash_set('error', 'Invalid ID.'); header('Location: pricing.php'); exit; }
            // 12 params: 8 strings + 4 ints (sort, popular, active, id)
            $st = $conn->prepare("UPDATE lp_pricing SET name=?,tagline=?,price_monthly=?,price_yearly=?,currency=?,features_json=?,cta_text=?,cta_url=?,sort_order=?,is_popular=?,is_active=?,extra_json=? WHERE id=?");
            $st->bind_param('ssssssssiiisi', $name, $tagline, $pmStr, $pyStr, $currency, $featuresJson, $cta_text, $cta_url, $sort, $popular, $active, $extraJson, $id);
            $st->execute(); $st->close();
            la_flash_set('success', 'Plan updated.');
        }
        header('Location: pricing.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['plan_id'] ?? 0);
        if ($id) { $st = $conn->prepare("DELETE FROM lp_pricing WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        la_flash_set('success', 'Plan deleted.'); header('Location: pricing.php'); exit;

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['plan_id'] ?? 0);
        if ($id) $conn->query("UPDATE lp_pricing SET is_active = NOT is_active WHERE id=$id");
        header('Location: pricing.php'); exit;
    }
}

/* ── Load plans ───────────────────────────────────────────────────────── */
$plans = [];
$res = $conn->query("SELECT * FROM lp_pricing ORDER BY sort_order ASC, id ASC");
if ($res) while ($r = $res->fetch_assoc()) $plans[] = $r;

$editId = (int)($_GET['edit'] ?? 0);
$editPlan = null;
foreach ($plans as $p) if ((int)$p['id'] === $editId) { $editPlan = $p; break; }

$currencies = ['INR' => '₹ INR', 'USD' => '$ USD', 'EUR' => '€ EUR', 'GBP' => '£ GBP'];

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Pricing Plans</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Pricing</span></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/pricing.php" target="_blank" rel="noopener" class="la-btn la-btn-outline">View page</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Replace ALL plans with the current Pricing page plans (Starter, Growth, Enterprise)?')">
            <?php echo la_csrf_field(); ?><input type="hidden" name="action" value="sync"><input type="hidden" name="replace" value="1">
            <button type="submit" class="la-btn la-btn-outline">Sync from pricing page</button>
        </form>
        <a href="?edit=0" class="la-btn la-btn-primary">+ Add Plan</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr <?php echo isset($_GET['edit']) ? '400px' : '0'; ?>;gap:20px;align-items:start;">

<!-- Plan Cards -->
<div>
<?php if (!$plans): ?>
    <div class="la-card"><div class="la-empty">
        <div class="la-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></div>
        <h3>No pricing plans yet</h3><p>The Pricing page is showing its built-in plans. Copy them here to edit them from the CMS.</p>
        <form method="POST" style="margin-top:10px"><?php echo la_csrf_field(); ?><input type="hidden" name="action" value="sync"><button type="submit" class="la-btn la-btn-primary">Copy plans from the pricing page</button></form>
    </div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
<?php foreach ($plans as $plan):
    $feats = json_decode($plan['features_json'] ?? '[]', true) ?: [];
    $sym = ['INR'=>'₹','USD'=>'$','EUR'=>'€','GBP'=>'£'][$plan['currency'] ?? 'INR'] ?? '₹';
?>
<div class="la-card" style="<?php echo !$plan['is_active'] ? 'opacity:.5;' : ''; ?><?php echo $plan['is_popular'] ? 'border-color:var(--accent);' : ''; ?>">
    <?php if ($plan['is_popular']): ?>
    <div style="background:var(--accent);color:#fff;font-size:11px;font-weight:700;text-align:center;padding:5px;border-radius:10px 10px 0 0;letter-spacing:.05em;">MOST POPULAR</div>
    <?php endif; ?>
    <div style="padding:18px;">
        <div style="margin-bottom:12px;">
            <div style="font-size:16px;font-weight:700;"><?php echo e($plan['name']); ?></div>
            <?php if ($plan['tagline']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?php echo e($plan['tagline']); ?></div><?php endif; ?>
        </div>
        <div style="margin-bottom:12px;">
            <div>
                <span style="font-size:22px;font-weight:700;color:var(--accent);"><?php echo $sym; ?><?php echo number_format((float)$plan['price_monthly'], 0); ?></span>
                <span style="font-size:12px;color:var(--text-muted);">/mo</span>
            </div>
            <?php if ((float)$plan['price_yearly'] > 0): ?>
            <div style="font-size:11px;color:var(--text-muted);"><?php echo $sym; ?><?php echo number_format((float)$plan['price_yearly'], 0); ?>/yr</div>
            <?php endif; ?>
        </div>
        <ul style="list-style:none;padding:0;margin:0 0 14px;display:flex;flex-direction:column;gap:4px;">
        <?php foreach (array_slice($feats, 0, 5) as $feat): ?>
            <li style="font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:6px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent);flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg><?php echo e(cms_price_feature($feat)[1]); ?></li>
        <?php endforeach; ?>
        <?php if (count($feats) > 5): ?><li style="font-size:11px;color:var(--text-muted);">+<?php echo count($feats)-5; ?> more…</li><?php endif; ?>
        </ul>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="?edit=<?php echo $plan['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
            <form method="POST" style="display:inline">
                <?php echo la_csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                <button type="submit" class="la-btn la-btn-outline la-btn-sm"><?php echo $plan['is_active'] ? 'Disable' : 'Enable'; ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this plan?');" style="display:inline">
                <?php echo la_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                <button type="submit" class="la-btn la-btn-danger la-btn-sm la-btn-icon"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- Edit / Create Form -->
<?php if (isset($_GET['edit'])): ?>
<div class="la-card">
    <div class="la-card-hd">
        <span><?php echo $editPlan ? 'Edit Plan' : 'New Plan'; ?></span>
        <a href="pricing.php" class="la-btn la-btn-outline la-btn-sm">Cancel</a>
    </div>
    <form method="POST" style="padding:20px;display:flex;flex-direction:column;gap:14px;">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="action"  value="<?php echo $editPlan ? 'update' : 'create'; ?>">
        <input type="hidden" name="plan_id" value="<?php echo (int)($editPlan['id'] ?? 0); ?>">

        <div class="la-form-group">
            <label class="la-label">Plan Name *</label>
            <input type="text" name="name" class="la-input" required
                value="<?php echo e($editPlan['name'] ?? ''); ?>" placeholder="e.g. Professional">
        </div>

        <div class="la-form-group">
            <label class="la-label">Tagline</label>
            <input type="text" name="tagline" class="la-input"
                value="<?php echo e($editPlan['tagline'] ?? ''); ?>" placeholder="e.g. For growing businesses">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 100px;gap:10px;">
            <div class="la-form-group">
                <label class="la-label">Monthly Price (shown)</label>
                <input type="number" name="price_monthly" class="la-input" min="0" step="0.01"
                    value="<?php echo (float)($editPlan['price_monthly'] ?? 0); ?>">
            </div>
            <div class="la-form-group">
                <label class="la-label">Yearly Price (12 months)</label>
                <input type="number" name="price_yearly" class="la-input" min="0" step="0.01"
                    value="<?php echo (float)($editPlan['price_yearly'] ?? 0); ?>">
            </div>
            <div class="la-form-group">
                <label class="la-label">Currency</label>
                <select name="currency" class="la-select">
                    <?php foreach ($currencies as $code => $label): ?>
                    <option value="<?php echo $code; ?>" <?php echo ($editPlan['currency'] ?? 'INR') === $code ? 'selected' : ''; ?>><?php echo $code; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php $ex = json_decode($editPlan['extra_json'] ?? '', true) ?: []; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <div class="la-form-group"><label class="la-label">Struck-out price</label>
                <input type="number" name="list_price" class="la-input" min="0" value="<?php echo (int)($ex['list_price'] ?? 0); ?>" placeholder="e.g. 1499"></div>
            <div class="la-form-group"><label class="la-label">Monthly badge</label>
                <input type="text" name="ribbon_monthly" class="la-input" value="<?php echo e($ex['ribbon_monthly'] ?? ''); ?>" placeholder="15% OFF"></div>
            <div class="la-form-group"><label class="la-label">Yearly badge</label>
                <input type="text" name="ribbon_yearly" class="la-input" value="<?php echo e($ex['ribbon_yearly'] ?? ''); ?>" placeholder="20% OFF"></div>
        </div>
        <div class="la-form-group">
            <label class="la-label">Button icon</label>
            <select name="cta_icon" class="la-select">
                <option value="">None</option>
                <?php foreach (['speed'=>'Target','fire'=>'Flame','bolt'=>'Bolt','star'=>'Star','chat'=>'Chat'] as $ik => $il): ?>
                <option value="<?php echo $ik; ?>" <?php echo ($ex['cta_icon'] ?? '') === $ik ? 'selected' : ''; ?>><?php echo $il; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="la-form-group">
            <label class="la-label">Features <span style="color:var(--text-muted);font-weight:400;">(one per line; optional icon first, e.g. <b>bot|AI Chatbot</b> — icons: chat, broadcast, contacts, bot, bolt, link, user, star, speed, plus, check)</span></label>
            <textarea name="features_text" class="la-textarea" rows="8"
                placeholder="Unlimited contacts&#10;Bulk messaging&#10;WhatsApp API access&#10;Analytics dashboard"><?php
                $feats = json_decode($editPlan['features_json'] ?? '[]', true) ?: [];
                echo e(implode("\n", $feats));
            ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="la-form-group">
                <label class="la-label">CTA Button Text</label>
                <input type="text" name="cta_text" class="la-input"
                    value="<?php echo e($editPlan['cta_text'] ?? 'Get Started'); ?>" placeholder="Get Started">
            </div>
            <div class="la-form-group">
                <label class="la-label">CTA Button URL</label>
                <input type="text" name="cta_url" class="la-input"
                    value="<?php echo e($editPlan['cta_url'] ?? ''); ?>" placeholder="/register.php">
            </div>
        </div>

        <div class="la-form-group">
            <label class="la-label">Sort Order</label>
            <input type="number" name="sort_order" class="la-input" min="0"
                value="<?php echo (int)($editPlan['sort_order'] ?? 0); ?>">
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <div class="la-toggle">
                    <input type="checkbox" name="is_popular" <?php echo ($editPlan['is_popular'] ?? 0) ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </div>
                <span style="font-size:13px;">Most Popular</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <div class="la-toggle">
                    <input type="checkbox" name="is_active" <?php echo ($editPlan['is_active'] ?? 1) ? 'checked' : ''; ?>>
                    <span class="la-toggle-slider"></span>
                </div>
                <span style="font-size:13px;">Active</span>
            </label>
        </div>

        <button type="submit" class="la-btn la-btn-primary" style="width:100%;justify-content:center;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
            <?php echo $editPlan ? 'Update Plan' : 'Create Plan'; ?>
        </button>
    </form>
</div>
<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
