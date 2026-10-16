<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_loyalty_points')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Loyalty & Reward Program';
$currentPage = 'loyalty';

$success = '';
$error = '';

// Handle Settings Save
if (isset($_POST['save_loyalty_settings'])) {
    csrf_verify();
    $ptsMsg = floatval($_POST['points_per_msg'] ?? 0);
    $ptsOrdFixed = floatval($_POST['points_per_order_fixed'] ?? 0);
    $ptsOrdPct = floatval($_POST['points_per_order_percent'] ?? 0);
    $threshold = floatval($_POST['reward_threshold'] ?? 0);
    $rewardMsg = sanitize($_POST['reward_message'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO loyalty_settings (company_id, points_per_msg, points_per_order_fixed, points_per_order_percent, reward_threshold, reward_message, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            points_per_msg = VALUES(points_per_msg),
                            points_per_order_fixed = VALUES(points_per_order_fixed),
                            points_per_order_percent = VALUES(points_per_order_percent),
                            reward_threshold = VALUES(reward_threshold),
                            reward_message = VALUES(reward_message),
                            is_active = VALUES(is_active)");
    if ($stmt) {
        $stmt->bind_param("iddddsi", $companyId, $ptsMsg, $ptsOrdFixed, $ptsOrdPct, $threshold, $rewardMsg, $isActive);
        if ($stmt->execute()) {
            $success = "Loyalty settings updated successfully!";
        } else {
            $error = "Error updating settings: " . $conn->error;
        }
    } else {
        $error = "Error updating settings: " . $conn->error;
    }
}

// Fetch current settings
$settings = [];
$st8a = $conn->prepare("SELECT * FROM loyalty_settings WHERE company_id = ?");
if ($st8a) {
    $st8a->bind_param("i", $companyId);
    $st8a->execute();
    $settings = $st8a->get_result()->fetch_assoc() ?: [];
}

// Fetch Top Customers (Leaderboard)
$topCustomers = [];
$st8b = $conn->prepare("SELECT name, phone, loyalty_points FROM contacts WHERE company_id = ? AND loyalty_points > 0 ORDER BY loyalty_points DESC LIMIT 5");
if ($st8b) {
    $st8b->bind_param("i", $companyId);
    $st8b->execute();
    $r8 = $st8b->get_result();
    while ($r8 && ($row = $r8->fetch_assoc())) { $topCustomers[] = $row; }
}

// Summary numbers
$memberCount = 0;
$pointsTotal = 0.0;
$st8c = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(loyalty_points),0) AS p FROM contacts WHERE company_id = ? AND loyalty_points > 0");
if ($st8c) {
    $st8c->bind_param("i", $companyId);
    $st8c->execute();
    $sum = $st8c->get_result()->fetch_assoc() ?: [];
    $memberCount = (int)($sum['c'] ?? 0);
    $pointsTotal = (float)($sum['p'] ?? 0);
}

$engineOn = (int)($settings['is_active'] ?? 0) === 1;
$threshold = (float)($settings['reward_threshold'] ?? 100);
$defaultReward = "Congratulations!\n\nYou have just reached {{POINTS}} loyalty points!\n\nAs a thank you, here is a special reward: *GIFT CODE 50* for 10% off your next order.";
$rewardText = (string)($settings['reward_message'] ?? '');
if (trim($rewardText) === '') $rewardText = $defaultReward;

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

include '../includes/client_header.php';
?>

<style>
/* Loyalty & Rewards — light, black text, soft tiles, no borders or divider lines */
.loy { --g:#128C7E; --gd:#0B6B5E; --ink:#0F2622; --tx:#1f2d29; --mu:#586a63; --soft:#F3FBF7; --field:#F3F7F5; color:var(--ink); }
.loy *, .loy *::before, .loy *::after { box-sizing:border-box; }
.loy-head { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; background:#fff; border-radius:18px; padding:22px 26px; margin-bottom:18px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.loy-head-l { display:flex; align-items:center; gap:16px; }
.loy-ico { width:52px; height:52px; border-radius:14px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; flex:none; }
.loy-ico .lI { width:25px; height:25px; }
.loy-head h1 { margin:0 0 4px; font-size:24px; font-weight:800; letter-spacing:-.4px; color:var(--ink); }
.loy-head p { margin:0; font-size:14px; color:var(--tx); }
.loy-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:999px; font-size:13px; font-weight:800; background:#EEF1F0; color:#4a5a55; }
.loy-pill i { width:8px; height:8px; border-radius:50%; background:#9aa8a3; }
.loy-pill.on { background:#E3F7EE; color:var(--gd); }
.loy-pill.on i { background:#25D366; }

.loy-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:18px; }
.loy-kpi { background:#fff; border-radius:18px; padding:18px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); }
.loy-kpi-i { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; flex:none; background:#E3F7EE; color:var(--g); }
.loy-kpi.blue .loy-kpi-i { background:#E4EFFD; color:#1d6fd6; }
.loy-kpi.amber .loy-kpi-i { background:#FEF3DC; color:#b7791f; }
.loy-kpi.purple .loy-kpi-i { background:#F1E9FE; color:#7c3aed; }
.loy-kpi b { display:block; font-size:26px; font-weight:800; letter-spacing:-.03em; line-height:1.1; color:var(--ink); }
.loy-kpi span { font-size:13px; font-weight:700; color:var(--mu); }

.loy-grid { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); gap:18px; align-items:stretch; }
.loy-card { background:#fff; border-radius:18px; padding:24px; box-shadow:0 12px 30px -22px rgba(15,38,34,.45); display:flex; flex-direction:column; gap:16px; min-width:0; }
.loy-card h2 { margin:0; font-size:18px; font-weight:800; color:var(--ink); letter-spacing:-.2px; display:flex; align-items:center; gap:10px; }
.loy-card h2 .ch { width:34px; height:34px; border-radius:10px; background:#E3F7EE; color:var(--g); display:grid; place-items:center; }
.loy-card h2 .ch .lI { width:18px; height:18px; }
.loy-card > p.sub { margin:-8px 0 0; font-size:13.5px; color:var(--mu); }

.loy-alert { padding:12px 16px; border-radius:14px; font-size:14px; font-weight:600; display:flex; align-items:center; gap:10px; }
.loy-alert.ok { background:#E3F7EE; color:var(--gd); }
.loy-alert.err { background:#FDE7E7; color:#b3261e; }

.loy-sec { background:var(--soft); border-radius:16px; padding:18px; }
.loy-sec-h { display:flex; align-items:center; gap:9px; font-size:14px; font-weight:800; color:var(--ink); margin-bottom:14px; }
.loy-sec-h b { width:24px; height:24px; border-radius:50%; background:var(--g); color:#fff; display:grid; place-items:center; font-size:12px; }
.loy-f2 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
.loy-f label.l { display:block; font-size:13px; font-weight:800; color:var(--tx); margin-bottom:7px; }
.loy-f input[type=number], .loy-f textarea { width:100%; border:0; background:#fff; color:var(--ink); border-radius:12px; padding:12px 14px; font-size:15px; font-family:inherit; }
.loy-f textarea { resize:vertical; line-height:1.6; font-size:14px; }
.loy-f input:focus, .loy-f textarea:focus { outline:none; box-shadow:0 0 0 2px rgba(18,140,126,.4); }
.loy-f .hint { margin:6px 0 0; font-size:12.5px; color:var(--mu); line-height:1.45; }
.loy-unit { position:relative; }
.loy-unit input { padding-right:40px !important; }
.loy-unit em { position:absolute; right:14px; top:50%; transform:translateY(-50%); font-style:normal; font-weight:800; color:var(--mu); }

.loy-switch { display:flex; align-items:center; justify-content:space-between; gap:14px; background:var(--soft); border-radius:16px; padding:16px 18px; cursor:pointer; }
.loy-switch strong { display:block; font-size:15px; color:var(--ink); }
.loy-switch small { font-size:13px; color:var(--mu); }
.loy-switch input { position:absolute; opacity:0; width:0; height:0; }
.loy-tg { width:46px; height:26px; border-radius:999px; background:#cfd8d4; position:relative; flex:none; transition:background .2s; }
.loy-tg::after { content:''; position:absolute; top:3px; left:3px; width:20px; height:20px; border-radius:50%; background:#fff; transition:transform .2s; box-shadow:0 2px 4px rgba(0,0,0,.2); }
.loy-switch input:checked + .loy-tg { background:var(--g); }
.loy-switch input:checked + .loy-tg::after { transform:translateX(20px); }
.loy-switch input:focus-visible + .loy-tg { box-shadow:0 0 0 3px rgba(18,140,126,.35); }

.loy-btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; height:46px; padding:0 26px; border:0; border-radius:12px; background:var(--g); color:#fff; font-size:15px; font-weight:800; cursor:pointer; font-family:inherit; align-self:flex-start; }
.loy-btn:hover { background:var(--gd); }
.loy-btn .lI { width:17px; height:17px; }

/* leaderboard */
.loy-list { display:flex; flex-direction:column; gap:10px; }
.loy-row { display:grid; grid-template-columns:34px 44px minmax(0,1fr) auto; gap:12px; align-items:center; background:var(--soft); border-radius:14px; padding:12px 14px; }
.loy-rank { width:34px; height:34px; border-radius:10px; background:#fff; color:var(--mu); display:grid; place-items:center; font-weight:800; font-size:14px; }
.loy-row:nth-child(1) .loy-rank { background:#FEF3DC; color:#9a6a00; }
.loy-row:nth-child(2) .loy-rank { background:#EEF1F0; color:#4a5a55; }
.loy-row:nth-child(3) .loy-rank { background:#FCE9DD; color:#a2551f; }
.loy-av { width:44px; height:44px; border-radius:50%; background:#E3F7EE; color:var(--gd); display:grid; place-items:center; font-weight:800; font-size:16px; }
.loy-nm { font-weight:800; font-size:15px; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.loy-ph { font-size:13px; color:var(--mu); }
.loy-pts { text-align:right; }
.loy-pts b { display:block; font-size:20px; font-weight:800; color:var(--gd); line-height:1.1; }
.loy-pts span { font-size:11px; font-weight:800; color:var(--mu); letter-spacing:.06em; }
.loy-empty { text-align:center; padding:30px 12px; background:var(--soft); border-radius:16px; }
.loy-empty .e-i { width:60px; height:60px; border-radius:50%; background:#E3F7EE; color:var(--g); display:grid; place-items:center; margin:0 auto 12px; }
.loy-empty .e-i .lI { width:28px; height:28px; }
.loy-empty h3 { margin:0 0 6px; font-size:17px; font-weight:800; color:var(--ink); }
.loy-empty p { margin:0; font-size:14px; color:var(--tx); line-height:1.55; }
.loy-why { background:var(--soft); border-radius:16px; padding:18px; display:flex; gap:14px; align-items:flex-start; margin-top:auto; }
.loy-why .lI { color:var(--g); flex:none; margin-top:2px; }
.loy-why h4 { margin:0 0 6px; font-size:15px; font-weight:800; color:var(--ink); }
.loy-why p { margin:0; font-size:13.5px; color:var(--tx); line-height:1.6; }

@media (max-width:1100px) { .loy-grid { grid-template-columns:minmax(0,1fr); } .loy-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:640px) { .loy-head { padding:18px; } .loy-head h1 { font-size:21px; } .loy-kpis { grid-template-columns:minmax(0,1fr); } .loy-f2 { grid-template-columns:minmax(0,1fr); } .loy-card { padding:18px; } .loy-row { grid-template-columns:30px 40px minmax(0,1fr) auto; gap:10px; } }
</style>

<div class="loy">

<div class="loy-head">
    <div class="loy-head-l">
        <div class="loy-ico"><?php echo $lI('<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>', 25); ?></div>
        <div>
            <h1>Loyalty &amp; Rewards</h1>
            <p>Give customers points for chatting and buying, and send them a reward automatically on WhatsApp.</p>
        </div>
    </div>
    <span class="loy-pill <?php echo $engineOn ? 'on' : ''; ?>"><i></i> <?php echo $engineOn ? 'Loyalty engine is on' : 'Loyalty engine is off'; ?></span>
</div>

<div class="loy-kpis">
    <div class="loy-kpi"><div class="loy-kpi-i"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></div><div><b><?php echo number_format($memberCount); ?></b><span>Customers with points</span></div></div>
    <div class="loy-kpi blue"><div class="loy-kpi-i"><?php echo $lI('<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>'); ?></div><div><b><?php echo number_format($pointsTotal); ?></b><span>Points earned in total</span></div></div>
    <div class="loy-kpi amber"><div class="loy-kpi-i"><?php echo $lI('<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>'); ?></div><div><b><?php echo rtrim(rtrim(number_format($threshold, 1), '0'), '.'); ?></b><span>Points needed for a reward</span></div></div>
    <div class="loy-kpi purple"><div class="loy-kpi-i"><?php echo $lI('<path d="M20 6 9 17l-5-5"/>'); ?></div><div><b><?php echo $engineOn ? 'On' : 'Off'; ?></b><span>Loyalty engine</span></div></div>
</div>

<div class="loy-grid">

    <section class="loy-card">
        <h2><span class="ch"><?php echo $lI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>'); ?></span> Reward rules</h2>
        <p class="sub">Choose how customers earn points and when they get a reward.</p>

        <?php if ($success): ?><div class="loy-alert ok"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="loy-alert err"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>', 18); ?> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST" style="display:flex;flex-direction:column;gap:16px;">
            <?php echo csrf_field(); ?>

            <div class="loy-sec loy-f">
                <div class="loy-sec-h"><b>1</b> How customers earn points</div>
                <div class="loy-f2">
                    <div>
                        <label class="l" for="ppm">Points per message sent</label>
                        <input type="number" step="0.1" id="ppm" name="points_per_msg" value="<?php echo htmlspecialchars((string)($settings['points_per_msg'] ?? '0.1')); ?>" required>
                        <p class="hint">Encourages customers to chat with your bot.</p>
                    </div>
                    <div>
                        <label class="l" for="ppo">Points per paid order (fixed)</label>
                        <input type="number" id="ppo" name="points_per_order_fixed" value="<?php echo htmlspecialchars((string)($settings['points_per_order_fixed'] ?? '10')); ?>" required>
                        <p class="hint">A set number of points for every completed order.</p>
                    </div>
                    <div>
                        <label class="l" for="ppp">Points per order (% of value)</label>
                        <div class="loy-unit"><input type="number" id="ppp" name="points_per_order_percent" value="<?php echo htmlspecialchars((string)($settings['points_per_order_percent'] ?? '1')); ?>" required><em>%</em></div>
                        <p class="hint">Extra points based on how much they spend.</p>
                    </div>
                </div>
            </div>

            <div class="loy-sec loy-f">
                <div class="loy-sec-h"><b>2</b> When to send the reward</div>
                <label class="l" for="thr">Reward threshold (points)</label>
                <input type="number" id="thr" name="reward_threshold" value="<?php echo htmlspecialchars((string)($settings['reward_threshold'] ?? '100')); ?>" required>
                <p class="hint">The reward message is sent when a customer reaches this score.</p>
            </div>

            <div class="loy-sec loy-f">
                <div class="loy-sec-h"><b>3</b> The WhatsApp reward message</div>
                <textarea name="reward_message" rows="5" placeholder="Congratulations! You have earned 100 points. Use code WA100 for 10% off your next order!" required><?php echo htmlspecialchars($rewardText); ?></textarea>
                <p class="hint">Use {{POINTS}} to insert the customer's points.</p>
            </div>

            <label class="loy-switch">
                <span><strong>Activate loyalty engine</strong><small>Start counting points and sending rewards.</small></span>
                <input type="checkbox" name="is_active" <?php echo $engineOn ? 'checked' : ''; ?>>
                <span class="loy-tg"></span>
            </label>

            <button type="submit" name="save_loyalty_settings" class="loy-btn"><?php echo $lI('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?> Save loyalty rules</button>
        </form>
    </section>

    <section class="loy-card">
        <h2><span class="ch"><?php echo $lI('<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>'); ?></span> Leaderboard</h2>
        <p class="sub">Your top 5 most loyal customers.</p>

        <?php if ($topCustomers): ?>
            <div class="loy-list">
                <?php foreach ($topCustomers as $i => $tc):
                    $nm = trim((string)($tc['name'] ?? ''));
                    $initial = strtoupper(function_exists('mb_substr') ? mb_substr($nm !== '' ? $nm : 'U', 0, 1, 'UTF-8') : substr($nm !== '' ? $nm : 'U', 0, 1));
                ?>
                <div class="loy-row">
                    <div class="loy-rank"><?php echo $i + 1; ?></div>
                    <div class="loy-av"><?php echo htmlspecialchars($initial); ?></div>
                    <div style="min-width:0">
                        <div class="loy-nm"><?php echo htmlspecialchars($nm !== '' ? $nm : (string)$tc['phone']); ?></div>
                        <div class="loy-ph"><?php echo htmlspecialchars((string)$tc['phone']); ?></div>
                    </div>
                    <div class="loy-pts"><b><?php echo number_format(round((float)$tc['loyalty_points'])); ?></b><span>POINTS</span></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="loy-empty">
                <div class="e-i"><?php echo $lI('<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>', 28); ?></div>
                <h3>No points recorded yet</h3>
                <p>Turn on the loyalty engine and your most loyal customers will show up here.</p>
            </div>
        <?php endif; ?>

        <div class="loy-why">
            <?php echo $lI('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 22); ?>
            <div>
                <h4>Why use a loyalty program?</h4>
                <p>Loyalty programs can increase customer lifetime value by up to 40%. Rewarding chats and purchases right on WhatsApp gives customers a reason to keep coming back.</p>
            </div>
        </div>
    </section>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
