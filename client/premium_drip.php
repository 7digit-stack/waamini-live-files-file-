<?php
include '../includes/client_auth_check.php';

$randModes = [
    'human'   => ['label' => 'Human',    'color' => '#3b82f6', 'bg' => '#dbeafe'],
    'ghost'   => ['label' => 'Ghost',    'color' => '#8b5cf6', 'bg' => '#ede9fe'],
    'chaos'   => ['label' => 'Chaos',    'color' => '#ef4444', 'bg' => '#fee2e2'],
    'minimal' => ['label' => 'Minimal',  'color' => '#10b981', 'bg' => '#d1fae5'],
    'none'    => ['label' => 'Static',   'color' => '#9ca3af', 'bg' => '#f3f4f6'],
];

if (!has_feature('feat_drip_campaign')) {
    // Bug #1 fixed: was raw SVG HTML in $pageTitle → literal text in <title> tab
    $pageTitle   = 'Drip Campaigns';
    $currentPage = 'premium_drip';
    include '../includes/client_header.php';
?>
<style>
/* Upsell gate — only loaded when feature is locked */
.drip-upsell {
    text-align: center;
    padding: 80px 40px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-top: 24px;
    box-shadow: var(--shadow);
}
/* Bug #3 fixed: was font-size:60px div wrapping a 16×16 SVG → 16px dot */
.drip-upsell-icon {
    width: 80px;
    height: 80px;
    background: var(--accent-light, #eaf3ff);
    border: 1px solid var(--info-border, #bdd5f7);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 22px;
    color: var(--accent);
}
.drip-upsell-icon svg { width: 34px; height: 34px; }
.drip-upsell-title { font-size: 26px; font-weight: 800; color: var(--text-primary); margin: 0 0 14px; }
.drip-upsell-desc  { font-size: 15px; color: var(--text-secondary); max-width: 480px; margin: 0 auto 28px; line-height: 1.65; }
</style>

<div class="drip-upsell">
    <div class="drip-upsell-icon"><?php echo cn_svg('drip'); ?></div>
    <h2 class="drip-upsell-title">Neural Drip Engine</h2>
    <p class="drip-upsell-desc">Automated follow-ups are currently locked. Upgrade your subscription to enable the Neural Drip Engine and start nurturing leads while you sleep.</p>
    <a href="subscription.php" class="btn btn-primary">View Upgrade Plans</a>
</div>

<?php
    include '../includes/client_footer.php';
    exit();
}

// Bug #1 fixed: second occurrence of $pageTitle with raw SVG HTML
$pageTitle   = 'Drip Campaigns';
$currentPage = 'premium_drip';
include '../includes/client_header.php';

// ── Backend: labels from contacts.chat_labels ──────────────────────────────
// NOTE: all query/session logic below is intentionally unchanged
$allLabels = [];
$cid = (int)$_SESSION['company_id'];

$labelsStmt = $conn->prepare("SELECT DISTINCT chat_labels FROM contacts WHERE company_id = ? AND chat_labels IS NOT NULL AND TRIM(chat_labels) != ''");
if ($labelsStmt) {
    $labelsStmt->bind_param("i", $cid);
    $labelsStmt->execute();
    $labelsRes = $labelsStmt->get_result();
    if ($labelsRes) {
        while ($row = $labelsRes->fetch_assoc()) {
            $raw = trim((string)($row['chat_labels'] ?? ''));
            if ($raw === '') continue;
            foreach (explode(',', $raw) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $allLabels, true)) {
                    $allLabels[] = $p;
                }
            }
        }
    }
} else {
    error_log('[WAAMINI] premium_drip.php: prepare() failed for contacts.chat_labels');
}

$tagsStmt = $conn->prepare("SELECT tags FROM contacts WHERE company_id = ? AND tags IS NOT NULL AND TRIM(tags) != ''");
if ($tagsStmt) {
    $tagsStmt->bind_param("i", $cid);
    $tagsStmt->execute();
    $tagsRes = $tagsStmt->get_result();
    if ($tagsRes) {
        while ($row = $tagsRes->fetch_assoc()) {
            $raw = trim((string)($row['tags'] ?? ''));
            if ($raw !== '' && $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $p = trim(is_string($item) ? $item : '');
                        if ($p !== '' && !in_array($p, $allLabels, true)) {
                            $allLabels[] = $p;
                        }
                    }
                    continue;
                }
            }
            foreach (explode(',', $raw) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $allLabels, true)) {
                    $allLabels[] = $p;
                }
            }
        }
    }
}

foreach (['Follow-up', 'Converted', 'Declined', 'Not Interested'] as $fixedLbl) {
    if (!in_array($fixedLbl, $allLabels, true)) {
        $allLabels[] = $fixedLbl;
    }
}
sort($allLabels);
$allLabels = array_values(array_unique(array_map('trim', $allLabels)));

$campaignsStmt = $conn->prepare("SELECT * FROM drip_campaigns WHERE company_id = ? ORDER BY id DESC");
$campaigns = false;
if ($campaignsStmt) {
    $campaignsStmt->bind_param("i", $cid);
    $campaignsStmt->execute();
    $campaigns = $campaignsStmt->get_result();
} else {
    error_log('[WAAMINI] premium_drip.php: prepare() failed for drip_campaigns list');
}

$dueCount = 0;
$dueStmt = $conn->prepare("SELECT COUNT(*) as c FROM drip_executions WHERE company_id = ? AND status = 'pending' AND scheduled_for <= NOW()");
if ($dueStmt) {
    $dueStmt->bind_param("i", $cid);
    $dueStmt->execute();
    $dueCount = $dueStmt->get_result()->fetch_assoc()['c'];
}
$dI = static function (string $p): string {
    return '<svg class="dI" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$dripIco = [
    'bolt'   => $dI('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'),
    'plus'   => $dI('<path d="M5 12h14"/><path d="M12 5v14"/>'),
    'edit'   => $dI('<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>'),
    'pause'  => $dI('<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>'),
    'play'   => $dI('<polygon points="6 3 20 12 6 21 6 3"/>'),
    'clock'  => $dI('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
    'refresh'=> $dI('<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>'),
    'mode'   => $dI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>'),
    'flow'   => $dI('<circle cx="5" cy="6" r="3"/><path d="M5 9v12"/><circle cx="19" cy="18" r="3"/><path d="m15 9-3-3 3-3"/><path d="M12 6h5a2 2 0 0 1 2 2v7"/>'),
];
?>

<style>
/* ── Drip Campaigns page ──
   Bug #2 fixed: removed body { background !important; color !important } — was overriding
   the global client layout and breaking sidebar/topbar text colours across the whole page.
   Bug #6 fixed: replaced custom dark design system (--glass-bg, --glass-border, hardcoded
   hex colours) with client.css design tokens throughout.
*/

/* Page hero — Bug #15 fixed: responsive padding */
.drip-hero {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 30px 32px;
    margin-bottom: 24px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 18px;
}

@media (max-width: 680px) {
    .drip-hero { padding: 22px 18px; flex-direction: column; }
    .drip-hero-actions { width: 100%; }
}

.drip-hero-eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--accent);
    margin-bottom: 8px;
}

.drip-hero-title {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 8px;
    letter-spacing: -0.3px;
}

.drip-hero-sub {
    font-size: 13.5px;
    color: var(--text-secondary);
    line-height: 1.6;
    max-width: 540px;
    margin: 0;
}

.drip-hero-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* Campaign grid */
.drip-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}

/* Campaign card */
.drip-campaign-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow);
    /* Bug #14 fixed: was translateY(-5px) — too aggressive, marketing-site feel */
    transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.drip-campaign-card:hover {
    box-shadow: var(--shadow-md);
    border-color: #cbd5e1;
    transform: translateY(-2px);
}

.drip-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

/* Bug #13 fixed: status dot was a raw Unicode • character — now a CSS pseudo-element */
.drip-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.drip-status::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

.drip-status.active   { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.drip-status.paused   { background: var(--bg-input);   color: var(--text-muted); border: 1px solid var(--border); }

.drip-steps-badge {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.drip-card-name {
    font-size: 17px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 12px;
    line-height: 1.3;
}

.drip-trigger-box {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 18px;
    font-size: 12.5px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.drip-trigger-label  { font-weight: 700; color: var(--accent); font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
.drip-trigger-value  { color: var(--text-secondary); font-style: italic; }

/* Bug #11 fixed: card action buttons were 100% inline styles */
.drip-card-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}

.drip-card-actions .btn { flex: 1; justify-content: center; }

/* Bug #7 fixed: .form-input used !important on all 5 properties — replaced with .drip-input */
.drip-input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text-primary);
    padding: 11px 14px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    margin-bottom: 18px;
    display: block;
}

.drip-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,124,227,0.12);
}

.drip-input::placeholder { color: var(--text-muted); }

.drip-form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 7px;
}

.drip-hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: -10px;
    margin-bottom: 0;
    line-height: 1.5;
    display: flex;
    align-items: flex-start;
    gap: 5px;
}

.drip-hint svg { flex-shrink: 0; margin-top: 1px; }

/* Empty state — Bug #4 fixed: proper sized icon container */
.drip-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 80px 40px;
    background: var(--bg-card);
    border: 2px dashed var(--border);
    border-radius: 16px;
}

.drip-empty-icon {
    width: 72px;
    height: 72px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    color: var(--text-muted);
    opacity: 0.7;
}

.drip-empty-icon svg { width: 30px; height: 30px; }
.drip-empty h3 { font-size: 20px; font-weight: 800; color: var(--text-primary); margin: 0 0 10px; }
.drip-empty p  { color: var(--text-secondary); max-width: 400px; margin: 0 auto 24px; line-height: 1.65; font-size: 14px; }

/* Modal — Bug #9 fixed: consistent open/close via JS functions, Escape key added */
.drip-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 16px;
}

.drip-modal-overlay.open { display: flex; }

.drip-modal {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    width: 480px;
    max-width: 100%;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.drip-modal-head {
    padding: 20px 24px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.drip-modal-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.drip-modal-close {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    font-family: inherit;
    line-height: 1;
    transition: var(--transition);
}

.drip-modal-close:hover { color: var(--danger); border-color: var(--danger); background: var(--danger-bg); }

.drip-modal-body { padding: 22px 24px 4px; }
.drip-modal-foot {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* Inline notification — Bug #8 fixed: replaces alert() popups in processDrips() */
.drip-notif {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 11px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 18px;
}

.drip-notif.show    { display: flex; }
.drip-notif.success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.drip-notif.error   { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-border, #fecaca); }

/* ── Line-free refresh: soft tinted tiles, no borders or divider lines ── */
.drip-hero, .drip-campaign-card, .drip-modal, .drip-empty { border: 0; box-shadow: 0 12px 30px -22px rgba(15,38,34,.45); }
.drip-campaign-card { border-radius: 18px; padding: 22px; gap: 4px; }
.drip-campaign-card:hover { box-shadow: 0 18px 38px -22px rgba(15,38,34,.5); }
.drip-hero-eyebrow { color: #128C7E; }
.drip-hero-eyebrow .dI { width: 15px; height: 15px; }
.drip-status { border: 0 !important; padding: 5px 12px; }
.drip-status.active { background: #E3F7EE; color: #0B6B5E; }
.drip-status.paused { background: #EEF1F0; color: #6b7a76; }
.drip-steps-badge { background: #F3F7F5; padding: 4px 10px; border-radius: 999px; color: #586a63; }
.drip-trigger-box { border: 0; border-left: 0; background: #F3FBF7; border-radius: 12px; padding: 12px 14px; flex-wrap: wrap; gap: 8px; }
.drip-trigger-ico { width: 28px; height: 28px; border-radius: 8px; background: #E3F7EE; color: #128C7E; display: grid; place-items: center; flex: none; }
.drip-trigger-ico .dI { width: 15px; height: 15px; }
.drip-trigger-label { color: #128C7E; }
.drip-trigger-box > span[style] { display: inline-flex; align-items: center; gap: 5px; margin-left: auto !important; border: 0 !important; }
.drip-trigger-box > span[style] .dI { width: 12px; height: 12px; }
.drip-card-actions { border-top: 0; padding-top: 6px; }
.drip-card-actions .btn, .drip-card-actions form .btn { border: 0; border-radius: 10px; height: 38px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
.drip-card-actions .btn-edit { background: #E6FCF2; color: #0B6B5E; }
.drip-card-actions .btn-edit:hover { background: #CDF5E3; color: #0B6B5E; }
.drip-card-actions .btn-danger { background: #FEEDED; color: #c62828; }
.drip-card-actions .btn-danger:hover { background: #FBD9D9; }
.drip-card-actions .btn-success { background: #128C7E; color: #fff; }
.drip-card-actions .btn-success:hover { background: #0B6B5E; }
.drip-hero .btn-primary, .drip-empty .btn-primary, .drip-modal-foot .btn-primary { background: #128C7E; border-color: #128C7E; color: #fff; display: inline-flex; align-items: center; gap: 7px; }
.drip-hero .btn-primary:hover, .drip-empty .btn-primary:hover, .drip-modal-foot .btn-primary:hover { background: #0B6B5E; border-color: #0B6B5E; }
.drip-hero .btn-warning { display: inline-flex; align-items: center; gap: 7px; }
.drip-empty { background: #F3FBF7; }
.drip-empty-icon { border: 0; background: #E3F7EE; color: #128C7E; opacity: 1; }
.drip-modal-head { border-bottom: 0; }
.drip-modal-foot { border-top: 0; }
.drip-modal-head h3 { gap: 10px; }
.drip-mico { width: 32px; height: 32px; border-radius: 9px; background: #E3F7EE; color: #128C7E; display: grid; place-items: center; }
.drip-mico .dI { width: 17px; height: 17px; }
.drip-modal-close { border: 0; }
.drip-input { border: 0; background: #F3F7F5; }
.drip-input:focus { box-shadow: 0 0 0 2px rgba(18,140,126,.35); }
.drip-modal-foot .btn-secondary { border: 0; background: #EEF3F1; color: #34453f; }
.drip-notif.success, .drip-notif.error { border: 0; }
.dI { flex-shrink: 0; }

/* Processing button spinner */
@keyframes dripSpin { to { transform: rotate(360deg); } }
.drip-processing-icon { animation: dripSpin 0.8s linear infinite; display: inline-block; }
</style>

<!-- ── Page header ── -->
<div class="drip-hero">
    <div>
        <div class="drip-hero-eyebrow">
            <?php echo $dripIco['flow']; ?> Automation
        </div>
        <h1 class="drip-hero-title">Neural Drip Engine</h1>
        <p class="drip-hero-sub">Automate your sales funnel with intelligent message sequences. Set a trigger label and let the bot nurture your leads over hours, days, or weeks.</p>
    </div>
    <div class="drip-hero-actions">
        <?php if ($dueCount > 0): ?>
        <!-- Bug #12 fixed: was style="background:#f39c12;" inline — now uses .btn-warning -->
        <!-- Bug #5 fixed: added <span> for label; processDrips() updates textContent only -->
        <button id="processDripBtn" class="btn btn-warning" onclick="processDrips()">
            <?php echo $dripIco['refresh']; ?>
            <span id="processDripLabel">Process Drips (<?php echo (int)$dueCount; ?>)</span>
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openDripModal()">
            <?php echo $dripIco['plus']; ?> New Sequence
        </button>
    </div>
</div>

<!-- Bug #8 fixed: inline notification replaces alert() popups -->
<div id="dripNotif" class="drip-notif" role="alert"></div>

<!-- ── Campaign grid ── -->
<div class="drip-grid">
    <?php if (!$campaigns): ?>
        <div style="grid-column:1/-1;" class="alert alert-danger">
            <?php echo cn_svg('support'); ?>
            System Error: Neural Engine failed to initialize. Check server logs.
        </div>

    <?php elseif ($campaigns->num_rows === 0): ?>
        <!-- Bug #4 fixed: was font-size:60px div wrapping a 16×16 SVG → 16px dot -->
        <div class="drip-empty">
            <div class="drip-empty-icon">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h3>No Sequences Yet</h3>
            <p>Your lead nurturing engine is idle. Create your first sequence to start automating your follow-ups.</p>
            <button class="btn btn-primary" onclick="openDripModal()">
                <?php echo $dripIco['plus']; ?> Launch First Campaign
            </button>
        </div>

    <?php else: ?>
    <?php while ($d = $campaigns->fetch_assoc()): ?>
        <?php
            // Step count query — backend logic unchanged
            $stepsStmt = $conn->prepare("SELECT COUNT(*) as c FROM drip_steps WHERE drip_campaign_id = ?");
            $stepsStmt->bind_param("i", $d['id']);
            $stepsStmt->execute();
            $stepCount = $stepsStmt->get_result()->fetch_assoc()['c'];
            $isActive  = ($d['status'] === 'active');
        ?>
        <div class="drip-campaign-card">
            <div class="drip-card-top">
                <!-- Bug #13 fixed: was raw Unicode • bullet — now CSS ::before circle -->
                <span class="drip-status <?php echo $isActive ? 'active' : 'paused'; ?>">
                    <?php echo $isActive ? 'Active' : 'Paused'; ?>
                </span>
                <span class="drip-steps-badge"><?php echo (int)$stepCount; ?> Step<?php echo $stepCount !== 1 ? 's' : ''; ?></span>
            </div>

            <h3 class="drip-card-name"><?php echo htmlspecialchars($d['name']); ?></h3>

            <div class="drip-trigger-box">
                <span class="drip-trigger-ico"><?php echo $dripIco['bolt']; ?></span>
                <span class="drip-trigger-label">Trigger:</span>
                <span class="drip-trigger-value">"<?php echo htmlspecialchars($d['trigger_label'] ?? 'None'); ?>"</span>
                <?php
                    $campMode = strtolower((string)($d['global_randomization'] ?? 'none'));
                    $mInfo = $randModes[$campMode] ?? $randModes['none'];
                    if ($campMode !== 'none'):
                ?>
                <span style="margin-left:8px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:<?php echo $mInfo['bg']; ?>;color:<?php echo $mInfo['color']; ?>;border:1px solid <?php echo $mInfo['color']; ?>40;text-transform:uppercase;letter-spacing:.4px;">
                    <?php echo $dripIco['mode']; ?> <?php echo $mInfo['label']; ?> Mode
                </span>
                <?php endif; ?>
            </div>

            <!-- Bug #11 fixed: action buttons were 100% inline style="..." -->
            <div class="drip-card-actions">
                <a href="premium_drip_builder.php?id=<?php echo (int)$d['id']; ?>" class="btn btn-sm btn-edit">
                    <?php echo $dripIco['edit']; ?> Edit
                </a>

                <!-- Bug #17 fixed: added confirm() guard before submitting toggle form -->
                <form action="../api/drip_toggle.php" method="POST" style="flex:1;display:flex;"
                      onsubmit="return confirm('<?php echo $isActive ? 'Pause this sequence? Active contacts will stop receiving messages.' : 'Activate this sequence?'; ?>')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                    <button type="submit" class="btn btn-sm <?php echo $isActive ? 'btn-danger' : 'btn-success'; ?>" style="flex:1;justify-content:center;">
                        <?php if ($isActive): ?>
                            <?php echo $dripIco['pause']; ?> Pause
                        <?php else: ?>
                            <?php echo $dripIco['play']; ?> Start
                        <?php endif; ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endwhile; ?>
    <?php endif; ?>
</div>

<!-- ── New Sequence Modal ──
     Bug #9 fixed: open/close via JS functions, Escape key handler, backdrop click -->
<div id="newDripModal" class="drip-modal-overlay" onclick="if(event.target===this)closeDripModal()">
    <div class="drip-modal" role="dialog" aria-modal="true" aria-labelledby="dripModalTitle">
        <div class="drip-modal-head">
            <h3 id="dripModalTitle"><span class="drip-mico"><?php echo $dripIco['flow']; ?></span> New Drip Sequence</h3>
            <button type="button" class="drip-modal-close" onclick="closeDripModal()" aria-label="Close">&times;</button>
        </div>

        <!-- Backend form action unchanged: ../api/drip_create.php -->
        <form action="../api/drip_create.php" method="POST">
            <?php echo csrf_field(); ?>

            <div class="drip-modal-body">
                <label class="drip-form-label">Campaign Name</label>
                <input type="text" name="name" class="drip-input" placeholder="e.g. Luxury Villa Follow-up" required>

                <label class="drip-form-label">Trigger Condition <span style="font-weight:400;text-transform:none;">(contact label)</span></label>
                <select name="trigger_label" class="drip-input" required>
                    <option value="">-- Select a contact label --</option>
                    <?php foreach ($allLabels as $lbl): ?>
                        <option value="<?php echo htmlspecialchars($lbl); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Bug #16 fixed: was raw lightbulb inline SVG → cn_svg('discover') -->
                <p class="drip-hint">
                    <?php echo cn_svg('discover'); ?>
                    When you assign this label to any contact in the chat panel, they automatically enter this sequence.
                </p>
            </div>

            <div class="drip-modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeDripModal()">Cancel</button>
                <!-- Bug #10 fixed: was ➡️ emoji → SVG arrow -->
                <button type="submit" class="btn btn-primary">
                    Launch Builder
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal open/close ───────────────────────────────────────────────────────
function openDripModal() {
    document.getElementById('newDripModal').classList.add('open');
}

function closeDripModal() {
    document.getElementById('newDripModal').classList.remove('open');
}

// Bug #9 fixed: Escape key now closes the modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDripModal();
});

// ── Inline notification helper ─────────────────────────────────────────────
function showDripNotif(type, msg) {
    var n = document.getElementById('dripNotif');
    n.className = 'drip-notif show ' + type;
    n.textContent = msg;
    n.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Auto-tick on page load (unchanged backend call) ────────────────────────
(function() {
    var dueCount = <?php echo (int)($dueCount ?? 0); ?>;
    if (dueCount <= 0) return;

    console.log('[WAAMINI] Auto-processing triggered for drips (' + dueCount + ' due)');

    function tick() {
        fetch('../api/drip_tick.php?limit=10')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.processed > 0) {
                    console.log('[WAAMINI] Auto-processed ' + data.processed + ' drips.');
                    setTimeout(function() { location.reload(); }, 2000);
                }
            })
            .catch(function(e) { console.error('[WAAMINI] Auto-tick failed:', e); });
    }

    setTimeout(tick, 1500);
})();

// ── Manual process drips button ────────────────────────────────────────────
function processDrips() {
    var btn   = document.getElementById('processDripBtn');
    var label = document.getElementById('processDripLabel');
    if (!btn || !label) return;

    // Bug #5 fixed: was btn.innerHTML = '...' which destroyed SVG child and
    // relied on browser HTML serialization to restore it. Now only the <span>
    // textContent is updated — the SVG icon is never touched.
    var orig = label.textContent;
    label.textContent = 'Processing…';
    btn.disabled = true;

    // Backend fetch URL unchanged: ../api/drip_tick.php
    fetch('../api/drip_tick.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                // Bug #8 fixed: was alert() — now inline notification
                showDripNotif('success',
                    'Processed ' + data.processed + ' drip' + (data.processed !== 1 ? 's' : '') +
                    ' — Sent: ' + data.sent + ', Failed: ' + data.failed + '.'
                );
                setTimeout(function() { location.reload(); }, 2200);
            } else {
                showDripNotif('error', 'Error processing drips: ' + (data.error || 'Unknown error'));
                btn.disabled = false;
                label.textContent = orig;
            }
        })
        .catch(function(err) {
            console.error(err);
            showDripNotif('error', 'Failed to connect to the processing engine. Check your connection.');
            btn.disabled = false;
            label.textContent = orig;
        });
}
</script>

<?php include '../includes/client_footer.php'; ?>
