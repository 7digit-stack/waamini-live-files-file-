<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';

if (!has_feature('pulse')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'WAAMINI Pulse — Account Health';
$currentPage = 'pulse';

// Ensure system_errors exists — run ONCE via schema guard
schema_ensure('pulse_schema', function() use ($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS system_errors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            error_type VARCHAR(50) NULL,
            message TEXT NULL,
            file VARCHAR(255) NULL,
            line INT NULL,
            stack_trace TEXT NULL,
            status ENUM('new','analyzed','fixed') DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}
});

// Filters
$days = (int)($_GET['days'] ?? 1);
if (!in_array($days, [1, 3, 7, 30], true)) $days = 1;
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['new', 'analyzed', 'fixed', ''], true)) $status = '';

$qsStatus = $status !== '' ? '&status=' . urlencode($status) : '';
$qsDays = 'days=' . (int)$days;

// Status counts for the selected range (ignore current status filter)
$countNew = 0;
$countAnalyzed = 0;
$countFixed = 0;
try {
    $stS = $conn->prepare("
        SELECT status, COUNT(*) c
        FROM system_errors
        WHERE company_id=? AND created_at >= (NOW() - INTERVAL ? DAY)
        GROUP BY status
    ");
    if ($stS) {
        $stS->bind_param("ii", $companyId, $days);
        $stS->execute();
        $res = $stS->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $st = (string)($row['status'] ?? '');
            $c  = (int)($row['c'] ?? 0);
            if ($st === 'new') $countNew = $c;
            if ($st === 'analyzed') $countAnalyzed = $c;
            if ($st === 'fixed') $countFixed = $c;
        }
        $stS->close();
    }
} catch (\Throwable $e) {}

// Actions (company-scoped)
if (isset($_POST['mark_fixed'], $_POST['error_id'])) {
    csrf_verify();
    $eid = (int)$_POST['error_id'];
    if ($eid > 0) {
        $st = $conn->prepare("UPDATE system_errors SET status='fixed' WHERE id=? AND company_id=?");
        if ($st) {
            $st->bind_param("ii", $eid, $companyId);
            $st->execute();
        }
    }
    header("Location: pulse.php?days=" . (int)$days . $qsStatus);
    exit();
}
if (isset($_POST['clear_fixed'])) {
    csrf_verify();
    $st = $conn->prepare("DELETE FROM system_errors WHERE company_id=? AND status='fixed'");
    if ($st) {
        $st->bind_param("i", $companyId);
        $st->execute();
    }
    header("Location: pulse.php?days=" . (int)$days . $qsStatus);
    exit();
}

// Light self-heal trigger: fire via HTTP API so CLI guard doesn't block this page
// (cron_pulse_analyzer.php exits 'Forbidden' when included directly in web context)
try {
    if (defined('CRON_SECRET_KEY') && CRON_SECRET_KEY !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        // Prefer dynamic base path (works under subdirectories and different folder names)
        $cronUrl = $scheme . '://' . $host . app_url('cron_pulse_analyzer.php') . '?key=' . urlencode(CRON_SECRET_KEY);
        $ch = curl_init($cronUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
} catch (\Throwable $e) {}

$where = "company_id=? AND created_at >= (NOW() - INTERVAL ? DAY)";
$types = "ii";
$params = [$companyId, $days];
if ($status !== '') {
    $where .= " AND status=?";
    $types .= "s";
    $params[] = $status;
}

// Count unresolved in range
$unresolved = 0;
try {
    $stC = $conn->prepare("SELECT COUNT(*) c FROM system_errors WHERE company_id=? AND created_at >= (NOW() - INTERVAL ? DAY) AND status <> 'fixed'");
    if ($stC) {
        $stC->bind_param("ii", $companyId, $days);
        $stC->execute();
        $unresolved = (int)($stC->get_result()->fetch_assoc()['c'] ?? 0);
    }
} catch (\Throwable $e) {}

// Fetch recent errors for this company ONLY (in range)
$events = [];
try {
    $stPulse = $conn->prepare("SELECT id, error_type, message, status, created_at FROM system_errors WHERE $where ORDER BY created_at DESC LIMIT 50");
    if ($stPulse) {
        $stPulse->bind_param($types, ...$params);
        $stPulse->execute();
        $er = $stPulse->get_result();
        while ($er && ($row = $er->fetch_assoc())) { $events[] = $row; }
    }
} catch (\Throwable $e) {}

// Last 14 days, per day (independent of the range filter) for the trend chart
$trend = [];
for ($i = 13; $i >= 0; $i--) { $trend[date('Y-m-d', strtotime("-$i day"))] = 0; }
try {
    $stT = $conn->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM system_errors WHERE company_id=? AND created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)");
    if ($stT) {
        $stT->bind_param("i", $companyId);
        $stT->execute();
        $tr = $stT->get_result();
        while ($tr && ($row = $tr->fetch_assoc())) {
            $k = (string)($row['d'] ?? '');
            if (isset($trend[$k])) $trend[$k] = (int)$row['c'];
        }
        $stT->close();
    }
} catch (\Throwable $e) {}
$trendMax = max(1, max($trend));

// Most common issue types in the selected range
$topTypes = [];
try {
    $stY = $conn->prepare("SELECT COALESCE(NULLIF(error_type,''),'event') t, COUNT(*) c FROM system_errors WHERE company_id=? AND created_at >= (NOW() - INTERVAL ? DAY) GROUP BY t ORDER BY c DESC LIMIT 5");
    if ($stY) {
        $stY->bind_param("ii", $companyId, $days);
        $stY->execute();
        $yr = $stY->get_result();
        while ($yr && ($row = $yr->fetch_assoc())) { $topTypes[] = $row; }
        $stY->close();
    }
} catch (\Throwable $e) {}

$totalRange = $countNew + $countAnalyzed + $countFixed;
$score = $totalRange > 0 ? (int)round(($countFixed / $totalRange) * 100) : 100;
$ringC = 2 * M_PI * 34;
$healthy = $unresolved === 0;
$rangeLabel = [1 => 'the last 24 hours', 3 => 'the last 3 days', 7 => 'the last 7 days', 30 => 'the last 30 days'][$days];
$statusMeta = [
    'new'      => ['New', 'We just detected this and are looking at it.'],
    'analyzed' => ['Analyzed', 'Understood. Pulse is working on a fix or it needs a quick check from you.'],
    'fixed'    => ['Fixed', 'Resolved. It is kept for your records until you clear it.'],
];
$ago = static function (int $ts): string {
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    return floor($d / 86400) . ' day' . (floor($d / 86400) > 1 ? 's' : '') . ' ago';
};
$pretty = static function (string $t): string { return ucwords(str_replace(['_', '-'], ' ', $t)) ?: 'Event'; };

include '../includes/client_header.php';
?>

<style>
/* ── Account Health — plain white page header, no divider lines ── */
.ph{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.ph *{box-sizing:border-box}
.ph-head{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:22px 26px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.ph-head-l{display:flex;align-items:center;gap:16px}
.ph-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.ph-ico.bad{background:#FDE7E7;color:#c62828}
.ph-ico svg{width:22px;height:22px}
.ph-head h1{margin:0 0 4px;font-size:25px;font-weight:800;letter-spacing:-.4px}
.ph-head p{margin:0;font-size:14.5px;color:var(--tx);max-width:560px;line-height:1.5}
.ph-state{display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:800;padding:9px 16px;border-radius:999px;background:#E3F7EE;color:var(--gd)}
.ph-state i{width:8px;height:8px;border-radius:50%;background:var(--g2);box-shadow:0 0 0 0 rgba(37,211,102,.6);animation:phping 2s infinite}
.ph-state.bad{background:#FDE7E7;color:#c62828}.ph-state.bad i{background:#ef4444;animation:none}
@keyframes phping{0%{box-shadow:0 0 0 0 rgba(37,211,102,.55)}100%{box-shadow:0 0 0 9px rgba(37,211,102,0)}}

.ph-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.ph-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.ph-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.ph-kpi-ico svg{width:20px;height:20px}
.ph-kpi.red .ph-kpi-ico{background:#FDE7E7;color:#c62828}.ph-kpi.amber .ph-kpi-ico{background:#FEF3DC;color:#b7791f}.ph-kpi.blue .ph-kpi-ico{background:#E4EFFD;color:#1d6fd6}
.ph-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.ph-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

.ph-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}
.ph-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:16px;min-width:0}
.ph-card h2{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px}
.ph-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.ph-seg{display:inline-flex;background:var(--soft);border-radius:12px;padding:4px;gap:4px}
.ph-seg a{padding:7px 14px;border-radius:9px;font:700 13px Inter,system-ui,sans-serif;color:var(--tx);text-decoration:none}
.ph-seg a:hover{background:#E7F7F1}.ph-seg a.on{background:#fff;color:var(--gd);box-shadow:0 4px 12px -6px rgba(18,140,126,.5)}
.ph-chips{display:flex;gap:8px;flex-wrap:wrap}
.ph-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;background:var(--soft);color:var(--tx);font:600 13px Inter,system-ui,sans-serif;text-decoration:none}
.ph-chip:hover{background:#E7F7F1}.ph-chip.on{background:var(--g);color:#fff}
.ph-chip em{font-style:normal;font-size:12px;opacity:.85}
.ph-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:38px;padding:0 15px;border-radius:10px;border:1.5px solid var(--line);background:#fff;color:var(--ink);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:border-color .2s,color .2s}
.ph-btn:hover{border-color:var(--g);color:var(--g)}
.ph-btn.sm{height:34px;padding:0 12px;font-size:12.5px;border-radius:9px}
.ph-btn.ok{background:var(--g);border-color:var(--g);color:#fff}.ph-btn.ok:hover{background:var(--gd);border-color:var(--gd);color:#fff}
.ph-list{display:flex;flex-direction:column;gap:8px;max-height:640px;overflow:auto;padding-right:2px}
.ph-ev{display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:14px;align-items:start;padding:14px 16px;border-radius:14px;background:var(--soft)}
.ph-ev.fixed{opacity:.72}
.ph-ev-ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#fff;color:#c62828}
.ph-ev.analyzed .ph-ev-ico{color:#b7791f}.ph-ev.fixed .ph-ev-ico{color:var(--g)}
.ph-ev-ico svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ph-ev b{display:block;font-size:14.5px}
.ph-ev time{display:block;font-size:12.5px;color:var(--mu);margin-top:2px}
.ph-msg{margin:8px 0 0;font-size:13px;color:var(--tx);line-height:1.55;overflow-wrap:anywhere}
.ph-msg details summary{cursor:pointer;color:var(--gd);font-weight:700;list-style:none;margin-top:4px}
.ph-msg details summary::-webkit-details-marker{display:none}
.ph-ev-side{display:flex;flex-direction:column;align-items:flex-end;gap:10px}
.ph-pill{font-size:12px;font-weight:700;padding:3px 11px;border-radius:999px;white-space:nowrap}
.ph-pill.new{background:#FDE7E7;color:#c62828}.ph-pill.analyzed{background:#FEF3DC;color:#8a5a00}.ph-pill.fixed{background:#E3F7EE;color:var(--gd)}
.ph-form{margin:0}
.ph-empty{text-align:center;padding:42px 16px}
.ph-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 14px}
.ph-empty-ico svg{width:30px;height:30px}
.ph-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.ph-empty p{margin:0 auto;color:var(--mu);font-size:14.5px;max-width:420px;line-height:1.55}
.ph-note{font-size:12.5px;color:var(--mu)}

.ph-side{display:flex;flex-direction:column;gap:20px}
.ph-rows{display:grid;gap:8px}
.ph-rows div{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--soft);border-radius:12px;padding:11px 14px;font-size:13.5px}
.ph-rows b{font-size:12px;font-weight:800;color:var(--gd);background:#E3F7EE;padding:3px 10px;border-radius:999px}
.ph-card p.t{margin:0;font-size:13.5px;color:var(--tx);line-height:1.6}
.ph-legend{display:grid;gap:10px}
.ph-legend div{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--tx);line-height:1.5}
.ph-legend .ph-pill{flex-shrink:0;margin-top:1px}

.ph-head-r{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.ph-ring{position:relative;width:84px;height:84px;flex-shrink:0}
.ph-ring svg{width:84px;height:84px;transform:rotate(-90deg)}
.ph-ring circle{fill:none;stroke-width:8;stroke-linecap:round}
.ph-ring .bg{stroke:#E3F7EE}.ph-ring .fg{stroke:var(--g2)}
.ph-ring.bad .bg{stroke:#FDE7E7}.ph-ring.bad .fg{stroke:#f59e0b}
.ph-ring div{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ph-ring b{font-size:18px;font-weight:800;line-height:1}.ph-ring span{font-size:10.5px;color:var(--mu);font-weight:700;margin-top:3px}
.ph-main{display:flex;flex-direction:column;gap:20px;min-width:0}
.ph-bars{display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:8px;height:150px;align-items:end;padding:6px 4px 0;background:var(--soft);border-radius:14px}
.ph-bar{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;padding-bottom:8px}
.ph-bar i{display:block;width:100%;max-width:26px;border-radius:7px;background:#D6EDE4;min-height:4px}
.ph-bar i.has{background:linear-gradient(180deg,var(--g2),var(--g))}
.ph-bar-v{font-size:11px;font-weight:800;color:var(--gd);min-height:13px}
.ph-bar em{font-style:normal;font-size:11px;color:var(--mu);font-weight:600}
.ph-top{display:grid;gap:12px}
.ph-top div{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 10px;font-size:13.5px}
.ph-top b{font-size:12px;color:var(--gd);background:#E3F7EE;padding:2px 10px;border-radius:999px}
.ph-top u{grid-column:1/-1;display:block;height:7px;border-radius:99px;background:var(--soft);text-decoration:none;overflow:hidden}
.ph-top u i{display:block;height:100%;border-radius:99px;background:var(--g)}
@media (max-width:1100px){.ph-grid{grid-template-columns:minmax(0,1fr)}.ph-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.ph-head{padding:18px}.ph-head h1{font-size:21px}.ph-kpis{grid-template-columns:1fr}.ph-ev{grid-template-columns:36px minmax(0,1fr)}.ph-ev-side{grid-column:1/-1;flex-direction:row;align-items:center;justify-content:space-between}}
@media (prefers-reduced-motion:reduce){.ph-state i{animation:none}}
</style>

<div class="ph">

<div class="ph-head">
    <div class="ph-head-l">
        <div class="ph-ico<?php echo $healthy ? '' : ' bad'; ?>"><?php echo cn_svg($healthy ? 'health' : 'support'); ?></div>
        <div>
            <h1>Account Health</h1>
            <p><?php echo $healthy
                ? 'Everything looks good. No open issues in ' . $rangeLabel . '.'
                : number_format($unresolved) . ' issue' . ($unresolved === 1 ? '' : 's') . ' in ' . $rangeLabel . ' still need attention. Pulse is already working on them.'; ?></p>
        </div>
    </div>
    <div class="ph-head-r">
        <span class="ph-state<?php echo $healthy ? '' : ' bad'; ?>"><i></i> <?php echo $healthy ? 'All systems healthy' : 'Needs attention'; ?></span>
        <div class="ph-ring<?php echo $healthy ? '' : ' bad'; ?>" title="Share of events resolved in <?php echo htmlspecialchars($rangeLabel); ?>">
            <svg viewBox="0 0 80 80" aria-hidden="true"><circle cx="40" cy="40" r="34" class="bg"/><circle cx="40" cy="40" r="34" class="fg" stroke-dasharray="<?php echo round($ringC * $score / 100, 1) . ' ' . round($ringC, 1); ?>"/></svg>
            <div><b><?php echo $score; ?>%</b><span>Resolved</span></div>
        </div>
    </div>
</div>

<div class="ph-kpis">
    <div class="ph-kpi<?php echo $healthy ? '' : ' red'; ?>"><div class="ph-kpi-ico"><?php echo cn_svg($healthy ? 'health' : 'blacklist'); ?></div><div><div class="ph-kpi-v"><?php echo number_format($unresolved); ?></div><div class="ph-kpi-l">Open issues</div></div></div>
    <div class="ph-kpi red"><div class="ph-kpi-ico"><?php echo cn_svg('bell'); ?></div><div><div class="ph-kpi-v"><?php echo number_format($countNew); ?></div><div class="ph-kpi-l">New</div></div></div>
    <div class="ph-kpi amber"><div class="ph-kpi-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="ph-kpi-v"><?php echo number_format($countAnalyzed); ?></div><div class="ph-kpi-l">Analyzed</div></div></div>
    <div class="ph-kpi blue"><div class="ph-kpi-ico"><?php echo cn_svg('shield'); ?></div><div><div class="ph-kpi-v"><?php echo number_format($countFixed); ?></div><div class="ph-kpi-l">Fixed automatically or by you</div></div></div>
</div>

<div class="ph-grid">

    <div class="ph-main">
    <section class="ph-card">
        <div class="ph-tools"><h2>Last 14 days</h2><span class="ph-note"><?php echo array_sum($trend); ?> event<?php echo array_sum($trend) === 1 ? '' : 's'; ?> in total</span></div>
        <div class="ph-bars" role="img" aria-label="Events per day for the last 14 days">
            <?php foreach ($trend as $dk => $cv): $h = $cv > 0 ? max(8, (int)round($cv / $trendMax * 100)) : 4; ?>
                <div class="ph-bar" title="<?php echo date('d M', strtotime($dk)) . ': ' . $cv; ?>">
                    <span class="ph-bar-v"><?php echo $cv ?: ''; ?></span>
                    <i class="<?php echo $cv ? 'has' : ''; ?>" style="height:<?php echo $h; ?>%"></i>
                    <em><?php echo date('j', strtotime($dk)); ?></em>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="ph-card">
        <div class="ph-tools">
            <h2>Activity feed</h2>
            <div class="ph-seg" role="tablist" aria-label="Time range">
                <?php foreach ([1 => '24h', 3 => '3 days', 7 => '7 days', 30 => '30 days'] as $d => $lbl): ?>
                    <a class="<?php echo $days === $d ? 'on' : ''; ?>" href="pulse.php?days=<?php echo $d . $qsStatus; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ph-tools">
            <div class="ph-chips">
                <a class="ph-chip<?php echo $status === '' ? ' on' : ''; ?>" href="pulse.php?<?php echo htmlspecialchars($qsDays, ENT_QUOTES, 'UTF-8'); ?>">All <em><?php echo $totalRange; ?></em></a>
                <a class="ph-chip<?php echo $status === 'new' ? ' on' : ''; ?>" href="pulse.php?<?php echo htmlspecialchars($qsDays . '&status=new', ENT_QUOTES, 'UTF-8'); ?>">New <em><?php echo $countNew; ?></em></a>
                <a class="ph-chip<?php echo $status === 'analyzed' ? ' on' : ''; ?>" href="pulse.php?<?php echo htmlspecialchars($qsDays . '&status=analyzed', ENT_QUOTES, 'UTF-8'); ?>">Analyzed <em><?php echo $countAnalyzed; ?></em></a>
                <a class="ph-chip<?php echo $status === 'fixed' ? ' on' : ''; ?>" href="pulse.php?<?php echo htmlspecialchars($qsDays . '&status=fixed', ENT_QUOTES, 'UTF-8'); ?>">Fixed <em><?php echo $countFixed; ?></em></a>
            </div>
            <?php if ($countFixed > 0): ?>
            <form method="POST" class="ph-form" onsubmit="return confirm('Clear all fixed items from your feed? This cannot be undone.')">
                <?php echo csrf_field(); ?>
                <button type="submit" name="clear_fixed" class="ph-btn sm">Clear fixed items</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$events): ?>
            <div class="ph-empty">
                <div class="ph-empty-ico"><?php echo cn_svg('health'); ?></div>
                <h3><?php echo $status === '' ? 'Your account is healthy' : 'Nothing here'; ?></h3>
                <p><?php echo $status === ''
                    ? 'No problems were detected in ' . $rangeLabel . '. Pulse keeps watching and will list anything unusual here.'
                    : 'No ' . strtolower($statusMeta[$status][0]) . ' items in ' . $rangeLabel . '. Try another filter or time range.'; ?></p>
            </div>
        <?php else: ?>
            <div class="ph-list">
            <?php foreach ($events as $e):
                $st = (string)($e['status'] ?? 'new'); if (!isset($statusMeta[$st])) $st = 'new';
                $ts = isset($e['created_at']) ? strtotime((string)$e['created_at']) : false;
                $msg = trim((string)($e['message'] ?? ''));
                $short = function_exists('mb_substr') ? mb_substr($msg, 0, 220, 'UTF-8') : substr($msg, 0, 220);
                $long = strlen($msg) > strlen($short);
            ?>
                <article class="ph-ev <?php echo $st; ?>">
                    <div class="ph-ev-ico"><svg viewBox="0 0 24 24"><?php echo $st === 'fixed' ? '<path d="M20 6 9 17l-5-5"/>' : ($st === 'analyzed' ? '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' : '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'); ?></svg></div>
                    <div>
                        <b><?php echo htmlspecialchars($pretty((string)($e['error_type'] ?? ''))); ?></b>
                        <time datetime="<?php echo $ts ? date('c', $ts) : ''; ?>"><?php echo $ts ? $ago($ts) . ' · ' . date('d M, g:i A', $ts) : '—'; ?></time>
                        <?php if ($msg !== ''): ?>
                        <div class="ph-msg">
                            <?php if ($long): ?>
                                <?php echo htmlspecialchars($short); ?>…
                                <details><summary>Show full message</summary><?php echo nl2br(htmlspecialchars($msg)); ?></details>
                            <?php else: echo nl2br(htmlspecialchars($msg)); endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="ph-ev-side">
                        <span class="ph-pill <?php echo $st; ?>"><?php echo $statusMeta[$st][0]; ?></span>
                        <?php if ($st !== 'fixed'): ?>
                        <form method="POST" class="ph-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="error_id" value="<?php echo (int)$e['id']; ?>">
                            <button type="submit" name="mark_fixed" class="ph-btn sm ok">Mark as fixed</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="ph-note">Showing the latest <?php echo count($events); ?> event<?php echo count($events) === 1 ? '' : 's'; ?> from <?php echo $rangeLabel; ?>.</div>
        <?php endif; ?>
    </section>
    </div>

    <aside class="ph-side">
        <?php if ($topTypes): $topMax = max(1, (int)$topTypes[0]['c']); ?>
        <section class="ph-card">
            <h2>Most common issues</h2>
            <div class="ph-top">
                <?php foreach ($topTypes as $tt): ?>
                    <div>
                        <span><?php echo htmlspecialchars($pretty((string)$tt['t'])); ?></span>
                        <b><?php echo (int)$tt['c']; ?></b>
                        <u><i style="width:<?php echo max(6, (int)round($tt['c'] / $topMax * 100)); ?>%"></i></u>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <section class="ph-card">
            <h2>Pulse self-healing</h2>
            <p class="t">Pulse watches your account around the clock. Most integration and database problems are fixed on their own within seconds, and anything it cannot fix shows up in your feed.</p>
            <div class="ph-rows">
                <div><span>Monitoring</span><b>Online</b></div>
                <div><span>Auto-repair</span><b>Active</b></div>
            </div>
        </section>
        <section class="ph-card">
            <h2>What the labels mean</h2>
            <div class="ph-legend">
                <?php foreach ($statusMeta as $k => $m): ?>
                    <div><span class="ph-pill <?php echo $k; ?>"><?php echo $m[0]; ?></span><span><?php echo htmlspecialchars($m[1]); ?></span></div>
                <?php endforeach; ?>
            </div>
        </section>
    </aside>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>
