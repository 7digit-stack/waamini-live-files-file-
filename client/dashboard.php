<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/cache.php';

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';

// Company stats (cached 60s)
$ns    = "company:{$companyId}";
$stats = wa_cache_remember(wa_cache_scoped_key($ns, "dash:stats"), 60, function () use ($conn, $companyId) {
    $out = ['contacts' => 0, 'campaigns' => 0, 'templates' => 0, 'messages' => 0];
    $_statsStmt = $conn->prepare("
        SELECT
          (SELECT COUNT(*) FROM contacts WHERE company_id=?) AS contacts_c,
          (SELECT COUNT(*) FROM campaigns WHERE company_id=?) AS campaigns_c,
          (SELECT COUNT(*) FROM message_templates WHERE company_id=?) AS templates_c,
          (SELECT COUNT(*) FROM messages WHERE company_id=?) AS messages_c
    ");
    if ($_statsStmt) {
        $_statsStmt->bind_param('iiii', $companyId, $companyId, $companyId, $companyId);
        if ($_statsStmt->execute()) {
            $rw = $_statsStmt->get_result()->fetch_assoc();
            $out['contacts']  = (int)($rw['contacts_c'] ?? 0);
            $out['campaigns'] = (int)($rw['campaigns_c'] ?? 0);
            $out['templates'] = (int)($rw['templates_c'] ?? 0);
            $out['messages']  = (int)($rw['messages_c'] ?? 0);
        }
        $_statsStmt->close();
    }
    return $out;
});
$contactCount  = (int)($stats['contacts'] ?? 0);
$campaignCount = (int)($stats['campaigns'] ?? 0);
$templateCount = (int)($stats['templates'] ?? 0);
$msgCount      = (int)($stats['messages'] ?? 0);

// This month messages (cached 60s)
$thisMonth = date('Y-m');
$monthMsgs = (int)wa_cache_remember(wa_cache_scoped_key($ns, "dash:monthMsgs:ym={$thisMonth}"), 60, function () use ($conn, $companyId, $thisMonth) {
    $month = 0;
    $_msgStmt = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='outgoing' AND DATE_FORMAT(created_at,'%Y-%m')=?");
    if ($_msgStmt) {
        $_msgStmt->bind_param('is', $companyId, $thisMonth);
        if ($_msgStmt->execute()) {
            $rw    = $_msgStmt->get_result()->fetch_assoc();
            $month = (int)($rw['c'] ?? 0);
        }
        $_msgStmt->close();
    }
    return $month;
});
// Activity overview donut: outgoing messages of the last 30 days by latest delivery status (cached 60s)
$actStatus = wa_cache_remember(wa_cache_scoped_key($ns, "dash:act30"), 60, function () use ($conn, $companyId) {
    $out = ['read' => 0, 'delivered' => 0, 'sent' => 0, 'failed' => 0];
    $st = $conn->prepare("SELECT LOWER(COALESCE(status,'')) AS s, COUNT(*) AS c FROM messages
        WHERE company_id=? AND direction='outgoing' AND created_at >= (NOW() - INTERVAL 30 DAY) GROUP BY s");
    if ($st) {
        $st->bind_param('i', $companyId);
        if ($st->execute()) {
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $k = in_array($r['s'], ['read', 'delivered', 'failed'], true) ? $r['s'] : 'sent'; // sent/accepted/pending = not yet delivered
                $out[$k] += (int)$r['c'];
            }
        }
        $st->close();
    }
    return $out;
});
$actTotal = array_sum($actStatus);
$msgLimit      = get_limit('messages_month');
$msgPercent    = ($msgLimit > 0) ? min(round(($monthMsgs / $msgLimit) * 100), 100) : 0;

// Contact limit
$contactLimit   = get_limit('contacts');
$contactPercent = ($contactLimit > 0) ? min(round(($contactCount / $contactLimit) * 100), 100) : 0;

// Latest subscription (cached 60s)
$sub = wa_cache_remember(wa_cache_scoped_key($ns, "dash:sub"), 60, function () use ($conn, $companyId) {
    $row = null;
    $_subStmt = $conn->prepare('
        SELECT s.*, sp.name AS plan_name
        FROM subscriptions s
        LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
        WHERE s.company_id = ?
        ORDER BY s.expires_at DESC, s.id DESC
        LIMIT 1
    ');
    if ($_subStmt) {
        $_subStmt->bind_param('i', $companyId);
        if ($_subStmt->execute()) {
            $row = $_subStmt->get_result()->fetch_assoc() ?: null;
        }
        $_subStmt->close();
    }
    return $row;
});
$isExpired = is_subscription_expired();

// Monthly message chart data (last 6 months, cached 60s)
$chart = wa_cache_remember(wa_cache_scoped_key($ns, "dash:chart6"), 60, function () use ($conn, $companyId) {
    $chartLabels = [];
    $months      = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym        = date('Y-m', strtotime("-$i months"));
        $months[]  = $ym;
        $chartLabels[] = date('M y', strtotime("-$i months"));
    }
    $start = $months[0] . '-01';
    $map   = [];
    $r     = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as c FROM messages WHERE company_id=? AND created_at >= ? GROUP BY mo");
    if ($r) {
        $r->bind_param('is', $companyId, $start);
        if ($r->execute()) {
            $res = $r->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $map[(string)$row['mo']] = (int)$row['c'];
            }
        }
        $r->close();
    }
    $chartData = [];
    foreach ($months as $mo) {
        $chartData[] = (int)($map[$mo] ?? 0);
    }
    return ['labels' => $chartLabels, 'data' => $chartData];
});
$chartLabels = (array)($chart['labels'] ?? []);
$chartData   = (array)($chart['data'] ?? []);

// Subscription days remaining (computed once for template use)
$daysLeft     = 0;
$daysLeftPct  = 0;
$daysUrgency  = 'good';
if ($sub && !empty($sub['expires_at'])) {
    $daysLeft    = max(0, (int)((strtotime((string)$sub['expires_at']) - time()) / 86400));
    $daysLeftPct = min(100, (int)($daysLeft / 90 * 100));
    $daysUrgency = $daysLeft <= 7 ? 'critical' : ($daysLeft <= 30 ? 'warning' : 'good');
}

include '../includes/client_header.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   Premium Dashboard — uses client.css design tokens exclusively
   ═══════════════════════════════════════════════════════════════ */

/* ── Stat cards ─────────────────────────────────────────────── */
.pdash-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.pdash-stat {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px 20px 18px;
    position: relative;
    overflow: hidden;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
    cursor: default;
}

.pdash-stat:hover {
    box-shadow: 0 8px 28px rgba(0,0,0,0.09);
    transform: translateY(-2px);
}



.pdash-stat-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 14px;
}

.pdash-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.pdash-stat-icon svg { width: 20px; height: 20px; }

.pdash-stat.s-contacts  .pdash-stat-icon { background: rgba(16,185,129,0.1);  color: #10b981; }
.pdash-stat.s-campaigns .pdash-stat-icon { background: rgba(45,124,227,0.1);  color: var(--accent); }
.pdash-stat.s-templates .pdash-stat-icon { background: rgba(139,92,246,0.1);  color: #8b5cf6; }
.pdash-stat.s-messages  .pdash-stat-icon { background: rgba(245,158,11,0.1);  color: #f59e0b; }

.pdash-stat-num {
    font-size: 32px;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -1.5px;
    line-height: 1;
    margin-bottom: 5px;
}

.pdash-stat-label {
    font-size: 12.5px;
    color: var(--text-muted);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

/* Large watermark icon inside card */
.pdash-stat-wm {
    position: absolute;
    right: -6px;
    bottom: -6px;
    opacity: 0.035;
    pointer-events: none;
    color: var(--text-primary);
}
.pdash-stat-wm svg { width: 72px; height: 72px; }

/* ── Main two-column grid ────────────────────────────────────── */
.pdash-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 20px;
    margin-bottom: 22px;
}

@media (max-width: 1100px) { .pdash-grid { grid-template-columns: 1fr 290px; } }
@media (max-width: 900px)  { .pdash-grid { grid-template-columns: 1fr; } }

/* ── Shared card shell ───────────────────────────────────────── */
.pdash-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
}

.pdash-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 22px 0;
    margin-bottom: 16px;
}

.pdash-card-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.pdash-card-badge {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 3px 10px;
}

/* ── ApexChart wrapper ───────────────────────────────────────── */
.pdash-chart-wrap {
    padding: 0 6px 10px;
    min-height: 240px;
}

/* ── Usage bars ──────────────────────────────────────────────── */
.pdash-usage { padding: 18px 22px; }

.pdash-usage-row { margin-bottom: 18px; }
.pdash-usage-row:last-child { margin-bottom: 0; }

.pdash-usage-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 7px;
}

.pdash-usage-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;
}

.pdash-usage-label svg { opacity: 0.7; }

.pdash-usage-val {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
}

.pdash-bar-track {
    height: 7px;
    background: var(--bg-input);
    border-radius: 100px;
    overflow: hidden;
}

.pdash-bar-fill {
    height: 100%;
    border-radius: 100px;
    background: linear-gradient(90deg, var(--accent), #60a5fa);
    transition: width 1.2s cubic-bezier(0.4,0,0.2,1);
}

.pdash-bar-fill.warn     { background: linear-gradient(90deg, #f59e0b, #ef4444); }
.pdash-bar-fill.critical { background: var(--danger); }

/* ── Subscription card ───────────────────────────────────────── */
.pdash-sub {
    padding: 20px 22px;
    position: relative;
    overflow: hidden;
    border-radius: 16px;
}


.pdash-sub-eyebrow {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.9px;
    color: var(--text-muted);
    margin-bottom: 3px;
}

.pdash-sub-plan {
    font-size: 20px;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 10px;
    letter-spacing: -0.3px;
}

.pdash-sub-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 4px 11px;
    border-radius: 20px;
    margin-bottom: 16px;
}
.pdash-sub-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.pdash-sub-pill.active  { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.pdash-sub-pill.expired { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-border, #fecaca); }

.pdash-sub-row {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    color: var(--text-secondary);
    margin-bottom: 7px;
}

.pdash-sub-days-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 14px 0 6px;
}

.pdash-sub-days-text { font-size: 12px; color: var(--text-muted); }

.pdash-sub-days-count {
    font-size: 13px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 6px;
}

.pdash-sub-days-count.good     { color: #10b981; background: rgba(16,185,129,0.1); }
.pdash-sub-days-count.warning  { color: #f59e0b; background: rgba(245,158,11,0.1); }
.pdash-sub-days-count.critical { color: var(--danger); background: var(--danger-bg); }

.pdash-sub-bar-track {
    height: 5px;
    background: var(--bg-input);
    border-radius: 100px;
    overflow: hidden;
    margin-bottom: 18px;
}

.pdash-sub-bar-fill {
    height: 100%;
    border-radius: 100px;
    transition: width 1.2s cubic-bezier(0.4,0,0.2,1);
}

.pdash-sub-bar-fill.good     { background: linear-gradient(90deg, var(--accent), #8b5cf6); }
.pdash-sub-bar-fill.warning  { background: linear-gradient(90deg, #f59e0b, #f97316); }
.pdash-sub-bar-fill.critical { background: var(--danger); }

/* Sub empty state */
.pdash-sub-empty {
    text-align: center;
    padding: 28px 16px;
}

.pdash-sub-empty-icon {
    width: 52px; height: 52px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    color: var(--text-muted);
}
.pdash-sub-empty-icon svg { width: 24px; height: 24px; }
.pdash-sub-empty p { font-size: 13px; color: var(--text-muted); margin: 0 0 16px; }

/* ── Quick actions ───────────────────────────────────────────── */
.pdash-qa { padding: 18px 22px; }

.pdash-qa-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 14px;
}

.pdash-qa-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: 9px;
}

.pdash-qa-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    padding: 13px 6px;
    border-radius: 12px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 600;
    background: var(--bg-input);
    border: 1.5px solid transparent;
    transition: all 0.18s ease;
    text-align: center;
    line-height: 1.2;
}

.pdash-qa-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
    color: var(--text-primary);
}

.pdash-qa-ico {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.pdash-qa-ico svg { width: 18px; height: 18px; }

/* QA colour system */
.qa-g  .pdash-qa-ico { background:rgba(16,185,129,0.1);  color:#10b981; } .qa-g:hover  { border-color:#10b981; color:#10b981; }
.qa-b  .pdash-qa-ico { background:rgba(45,124,227,0.1);  color:var(--accent); } .qa-b:hover  { border-color:var(--accent); color:var(--accent); }
.qa-t  .pdash-qa-ico { background:rgba(20,184,166,0.1);  color:#14b8a6; } .qa-t:hover  { border-color:#14b8a6; color:#14b8a6; }
.qa-s  .pdash-qa-ico { background:rgba(100,116,139,0.1); color:var(--text-muted); } .qa-s:hover { border-color:var(--border); }
.qa-a  .pdash-qa-ico { background:rgba(245,158,11,0.1);  color:#f59e0b; } .qa-a:hover  { border-color:#f59e0b; color:#f59e0b; }
.qa-r  .pdash-qa-ico { background:rgba(239,68,68,0.1);   color:var(--danger); } .qa-r:hover  { border-color:var(--danger); color:var(--danger); }
.qa-p  .pdash-qa-ico { background:rgba(139,92,246,0.1);  color:#8b5cf6; } .qa-p:hover  { border-color:#8b5cf6; color:#8b5cf6; }

/* ── Premium growth section (matches .db-card system) ────────── */
.pdash-upsell {
    background: #fff;
    border: 1px solid #E3EEE9;
    border-radius: 16px;
    box-shadow: 0 12px 30px -24px rgba(18,140,126,.5);
    margin-bottom: 22px;
    overflow: hidden;
}
.pdash-upsell-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    padding: 22px 22px 8px;
}
.pdash-upsell-title { font-size: 17.5px; font-weight: 800; color: #0F2622; margin: 0; letter-spacing: -.2px; }
.pdash-upsell-desc  { font-size: 13.5px; color: #586a63; margin: 3px 0 0; line-height: 1.5; }
.pdash-upsell-count {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; color: #0B6B5E;
    background: #E7F7F1; border: 1px solid #CDEBDF;
    padding: 3px 10px; border-radius: 999px; margin-bottom: 8px;
}
.pdash-upsell-btn {
    display: inline-flex; align-items: center; gap: 8px;
    height: 42px; padding: 0 18px; border-radius: 11px;
    font-size: 14px; font-weight: 700; text-decoration: none; white-space: nowrap;
    background: linear-gradient(135deg, #128C7E, #0B6B5E); color: #fff;
    box-shadow: 0 8px 18px -8px rgba(18,140,126,.7);
    transition: transform .2s;
}
.pdash-upsell-btn:hover { color: #fff; transform: translateY(-1px); }

.pdash-upsell-grid {
    display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px; padding: 14px 22px 22px;
}
.pdash-feat {
    display: flex; flex-direction: column; gap: 12px;
    background: #F3FBF7; padding: 20px 22px; border-radius: 14px;
    text-decoration: none; color: inherit;
    transition: background .18s ease, transform .18s ease, box-shadow .18s ease;
}
.pdash-feat:hover { background: #E7F7F1; color: inherit; transform: translateY(-2px); box-shadow: 0 12px 24px -16px rgba(18,140,126,.6); }
.pdash-feat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.pdash-feat-ic {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    display: grid; place-items: center;
    background: #E3F7EE; color: #128C7E;
}
.pdash-feat-ic svg { width: 20px; height: 20px; }
.pdash-feat:nth-child(1) .pdash-feat-ic { background:#FEF3DC; color:#b7791f; }
.pdash-feat:nth-child(2) .pdash-feat-ic { background:#FDE7F1; color:#d1356f; }
.pdash-feat:nth-child(3) .pdash-feat-ic { background:#E4EFFD; color:#1d6fd6; }
.pdash-feat:nth-child(4) .pdash-feat-ic { background:#EFE9FD; color:#6d44d8; }
.pdash-feat:nth-child(5) .pdash-feat-ic { background:#FDE7F1; color:#d1356f; }
.pdash-upsell-grid .pdash-feat:last-child:nth-child(3n+2) { grid-column: span 2; }
@media (max-width: 1000px) { .pdash-upsell-grid .pdash-feat:last-child:nth-child(odd) { grid-column: span 2; } }
@media (max-width: 640px) { .pdash-upsell-grid .pdash-feat:last-child { grid-column: auto !important; } }
.pdash-feat-tag {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
}
.pdash-feat-tag svg { width: 10px; height: 10px; }
.pdash-feat-tag.on  { background: #E7F7F1; color: #0B6B5E; }
.pdash-feat-tag.on::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #25D366; }
.pdash-feat-tag.off { background: #FEF3C7; color: #92400E; }
.pdash-feat-name { font-size: 15px; font-weight: 700; color: #0F2622; margin: 0 0 4px; }
.pdash-feat-sub  { font-size: 13px; color: #586a63; line-height: 1.5; margin: 0; }
.pdash-feat-cta  { margin-top: auto; font-size: 13px; font-weight: 700; color: #128C7E; display: inline-flex; align-items: center; gap: 6px; }
.pdash-feat-cta span { transition: transform .18s; }
.pdash-feat:hover .pdash-feat-cta span { transform: translateX(3px); }
.pdash-upsell-foot {
    padding: 0 22px 20px; font-size: 12.5px; color: #586a63;
}
.pdash-upsell-foot b { color: #0B6B5E; font-weight: 700; }
@media (max-width: 1000px) { .pdash-upsell-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px)  { .pdash-upsell-grid { grid-template-columns: 1fr; } .pdash-upsell-btn { width: 100%; justify-content: center; } }

/* ── Expired banner ──────────────────────────────────────────── */
.pdash-expired {
    background: var(--danger-bg);
    border: 1px solid var(--danger-border, #fecaca);
    border-left: 4px solid var(--danger);
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.pdash-expired-icon {
    width: 40px; height: 40px;
    background: rgba(239,68,68,0.12);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: var(--danger); flex-shrink: 0;
}
.pdash-expired-icon svg { width: 20px; height: 20px; }
.pdash-expired-title { color: var(--danger); font-size: 14px; font-weight: 700; margin: 0 0 2px; }
.pdash-expired-desc  { color: var(--text-secondary); font-size: 12.5px; margin: 0; }
.pdash-expired-body  { flex: 1; min-width: 200px; }

/* ── Recent messages table ───────────────────────────────────── */
.pdash-table-wrap {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow);
    margin-bottom: 8px;
}

.pdash-table-head {
    padding: 16px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pdash-table-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.pdash-msg-cell {
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pdash-tick-double { color: var(--info, #2d7ce3); font-weight: 800; font-size: 12px; }

/* ── Restricted view ─────────────────────────────────────────── */
.pdash-restricted {
    text-align: center;
    padding: 64px 20px;
    max-width: 880px;
    margin: 0 auto;
}

.pdash-restricted-icon {
    width: 80px; height: 80px;
    background: var(--accent-light, #eaf3ff);
    border: 1px solid var(--info-border, #bdd5f7);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 22px;
    color: var(--accent);
}
.pdash-restricted-icon svg { width: 36px; height: 36px; }

.pdash-restricted h2 { font-size: 28px; font-weight: 800; color: var(--text-primary); margin-bottom: 12px; }
.pdash-restricted p  { font-size: 15px; color: var(--text-secondary); line-height: 1.65; max-width: 540px; margin: 0 auto 44px; }

.pdash-contact-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }

.pdash-contact-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 36px 28px;
    text-align: center;
    box-shadow: var(--shadow);
    transition: box-shadow 0.2s, transform 0.2s;
}
.pdash-contact-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

.pdash-cc-icon {
    width: 60px; height: 60px;
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 18px;
}
.pdash-cc-icon svg { width: 26px; height: 26px; }
.pdash-cc-icon.phone  { background: rgba(139,92,246,0.1); color: #8b5cf6; }
.pdash-cc-icon.email  { background: rgba(16,185,129,0.1); color: #10b981; }
.pdash-cc-icon.visit  { background: rgba(239,68,68,0.1);  color: var(--danger); }

.pdash-cc-title { font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; }

@media (max-width: 640px) {
    .pdash-qa-grid { grid-template-columns: repeat(4,1fr); }
    .pdash-stats { grid-template-columns: repeat(2,1fr); }
    .pdash-stat-num { font-size: 26px; }
}

/* Apex tooltip override */
.apx-tooltip {
    background: var(--bg-card) !important;
    border: 1px solid var(--border) !important;
    border-radius: 10px !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1) !important;
    padding: 10px 14px !important;
    font-family: inherit !important;
}
</style>

<?php if ($isRestricted): ?>
<!-- ══════════════════════════════════════════════════
     RESTRICTED VIEW — new / unapproved users
     ══════════════════════════════════════════════════ -->
<div class="pdash-restricted">
    <div class="pdash-restricted-icon">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
    </div>
    <h2>Explore Our Premium Features</h2>
    <p>Welcome to Waamini! To explore our features and activate your workspace, please contact our sales team — we'll help you find the perfect plan for your business growth.</p>

    <div class="pdash-contact-cards">
        <div class="pdash-contact-card">
            <div class="pdash-cc-icon phone">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div class="pdash-cc-title">Call Us</div>
            <div style="color:var(--accent);font-weight:600;font-size:14px;margin-bottom:4px;">+91 9150802906</div>
            <div style="color:var(--accent);font-weight:600;font-size:14px;margin-bottom:10px;">+91 9003876516</div>
            <div style="font-size:12px;color:var(--text-muted);">Mon–Sat &nbsp;9 AM – 7 PM IST</div>
        </div>
        <div class="pdash-contact-card">
            <div class="pdash-cc-icon email">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </div>
            <div class="pdash-cc-title">Email Us</div>
            <a href="mailto:info@7digit.in" style="color:var(--accent);font-weight:600;font-size:14px;text-decoration:none;display:block;margin-bottom:10px;">info@7digit.in</a>
            <div style="font-size:12px;color:var(--text-muted);">We reply within 24 hours</div>
        </div>
        <div class="pdash-contact-card">
            <div class="pdash-cc-icon visit">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div class="pdash-cc-title">Visit Us</div>
            <div style="font-size:13px;color:var(--text-secondary);line-height:1.65;">2nd Floor, Dolphin Super Market,<br>Kalanivasal, Karaikudi – 630001</div>
        </div>
    </div>
    <div style="margin-top:48px;">
        <a href="/logout.php" class="btn btn-primary" style="padding:12px 32px;font-weight:700;">Sign Out</a>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════
     FULL DASHBOARD
     ══════════════════════════════════════════════════ -->
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php if ($isExpired): ?>
<div class="pdash-expired">
    <div class="pdash-expired-icon"><?php echo cn_svg('support'); ?></div>
    <div class="pdash-expired-body">
        <div class="pdash-expired-title">Subscription Expired</div>
        <p class="pdash-expired-desc">
            <?php if (!empty($sub['expires_at'])): ?>
                Your plan expired on <strong><?php echo htmlspecialchars(date('d M Y', strtotime((string)$sub['expires_at'])), ENT_QUOTES, 'UTF-8'); ?></strong>.
            <?php else: ?>
                Your subscription is not active.
            <?php endif; ?>
            Premium features are locked until you renew.
        </p>
    </div>
    <a href="subscription.php" class="btn btn-danger" style="flex-shrink:0;">
        <?php echo cn_svg('billing'); ?> Renew Now
    </a>
</div>
<?php endif; ?>
<?php
$__e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__rem = ($msgLimit > 0) ? max(0, $msgLimit - $monthMsgs) : 0;
$__chartTotal = (int)array_sum(array_map('intval', $chartData));
?>
<div class="db">

  <div class="db-head">
    <div>
      <h1>Dashboard</h1>
      <p>Your WhatsApp activity, plan usage and recent conversations at a glance.</p>
    </div>
    <div class="db-head__actions">
      <?php if (has_feature('contacts')): ?><a href="contacts.php" class="db-btn"><?php echo cn_svg('contacts'); ?> Contacts</a><?php endif; ?>
      <?php if (has_feature('campaigns')): ?><a href="campaign_create.php" class="db-btn db-btn--primary"><?php echo cn_svg('campaigns'); ?> New campaign</a><?php endif; ?>
    </div>
  </div>

  <!-- ===== row 1: message activity + KPIs | plan usage donut ===== -->
  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <?php
      /* Stats used in ring card text */
      $__maxMsg    = count($chartData) > 0 ? max(1, max(array_map('intval', array_values($chartData)))) : 1;
      $__peakIdx   = array_search($__maxMsg, array_map('intval', array_values($chartData)));
      $__peakLabel = isset($chartLabels[$__peakIdx]) ? $chartLabels[$__peakIdx] : '—';
      $__avgMsg    = count($chartData) > 0 ? number_format((int)round($__chartTotal / count($chartData))) : '0';
      $__ring1_pct = ($msgLimit > 0) ? min(100, (int)round(($monthMsgs / $msgLimit) * 100)) : ($monthMsgs > 0 ? 60 : 5);
      ?>

      <!-- ── Activity overview: messages of the last 30 days by delivery status ── -->
      <style>
      .wd-wrap{display:flex;flex-direction:column;gap:18px;padding:22px 22px 18px;min-width:0;height:100%}
      .wd-body{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1}
      .wd-head h2{margin:0 0 4px;font-size:22px;font-weight:800;line-height:1.15;letter-spacing:-.02em;color:#0F2622}
      .wd-head h2 span{color:#128C7E}
      .wd-head p{margin:0;font-size:13.5px;line-height:1.5;color:#64748B}
      .wd-chart{position:relative;width:100%;max-width:250px;aspect-ratio:1;margin:0 auto}
      .wd-chart svg{width:100%;height:100%;overflow:visible;display:block}
      .wd-seg{cursor:pointer;transition:transform .35s cubic-bezier(.2,.8,.2,1),opacity .3s}
      .wd-dim .wd-seg{opacity:.3}
      .wd-dim .wd-seg.wd-on{opacity:1}
      .wd-badge{transform-box:fill-box;transform-origin:center;opacity:0;transform:scale(.4);transition:opacity .35s,transform .45s cubic-bezier(.3,1.6,.5,1)}
      .wd-center{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;text-align:center}
      .wd-val{font-size:32px;font-weight:800;color:#0F2622;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
      .wd-lbl{font-size:12px;color:#64748B;margin-top:2px}
      .wd-loader{opacity:0;transition:opacity .35s}
      .wd-loading .wd-loader{opacity:1}
      .wd-spin{transform-box:fill-box;transform-origin:center;animation:wd-spin 1.4s cubic-bezier(.55,.15,.45,.85) infinite}
      .wd-arc{fill:none;stroke-width:22;stroke-linecap:round;animation:wd-dash 1.4s ease-in-out infinite}
      .wd-arc:nth-child(2){animation-delay:.1s}.wd-arc:nth-child(3){animation-delay:.2s}.wd-arc:nth-child(4){animation-delay:.3s}
      .wd-ltxt{display:none;font-size:13px;font-weight:600;color:#64748B}
      .wd-ltxt i{display:inline-block;width:4px;height:4px;border-radius:50%;background:#64748B;margin-left:3px;animation:wd-pulse 1s infinite}
      .wd-ltxt i:nth-child(2){animation-delay:.15s}.wd-ltxt i:nth-child(3){animation-delay:.3s}
      .wd-loading .wd-ltxt{display:block}
      .wd-loading .wd-val,.wd-loading .wd-lbl{display:none}
      .wd-empty{font-size:12.5px;color:#64748B;text-align:center;margin-top:8px}
      .wd-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
      @keyframes wd-spin{to{transform:rotate(360deg)}}
      @keyframes wd-dash{0%,100%{stroke-dasharray:14 826}50%{stroke-dasharray:150 690}}
      @keyframes wd-pulse{0%,100%{opacity:1}50%{opacity:.45}}
      @media (max-width:520px){.wd-chart{max-width:240px}}
      /* no grid lines between the KPI tiles / chart panel */
      .db-report .db-kpi,.db-report .db-chart{border:0!important}
      .db-report .db-kpis{gap:10px;padding:12px}
      .db-report .db-kpi{border-radius:12px;background:#F7FAF9}
      @media (prefers-reduced-motion:reduce){.wd-spin,.wd-arc,.wd-ltxt i{animation:none}}
      </style>
      <?php
      $wdSeries = [
          ['k' => 'read',      'l' => 'Read',      'h' => 'Opened by the customer', 'c' => '#3478F6'],
          ['k' => 'delivered', 'l' => 'Delivered', 'h' => 'On the phone, not read yet', 'c' => '#0E8A7A'],
          ['k' => 'sent',      'l' => 'Sent',      'h' => 'Waiting for the phone', 'c' => '#4CC35E'],
          ['k' => 'failed',    'l' => 'Failed',    'h' => 'Not delivered', 'c' => '#E5484D'],
      ];
      ?>
      <div class="wd-wrap" id="wdActivity">
        <div class="wd-head">
          <h2>Activity <span>overview</span></h2>
          <p>Messages you sent in the last 30 days and what happened to them.</p>
        </div>
        <div class="wd-body">
        <div style="width:100%">
          <div id="wdDonut" class="wd-chart" role="img" aria-label="<?php echo htmlspecialchars('Messages sent in the last 30 days: ' . implode(', ', array_map(fn($x) => $x['l'] . ' ' . (int)$actStatus[$x['k']], $wdSeries))); ?>"></div>
          <?php if ($actTotal === 0): ?><p class="wd-empty">No messages sent in the last 30 days yet.</p><?php endif; ?>
        </div>
        </div>
        <table class="wd-sr"><caption>Messages sent in the last 30 days by status</caption>
          <tr><th>Status</th><th>Messages</th></tr>
          <?php foreach ($wdSeries as $x): ?><tr><td><?php echo $x['l']; ?></td><td><?php echo (int)$actStatus[$x['k']]; ?></td></tr><?php endforeach; ?>
        </table>
      </div>
      <script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
      function WaaminiDonut(el, opt){
        opt = Object.assign({colors:[],labels:[],centerLabel:'Total',autoHighlight:true}, opt||{});
        const NS='http://www.w3.org/2000/svg', RO=175, RI=105, RM=(RO+RI)/2, GAP=2.2;
        const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        const n = opt.colors.length;
        let geo=[], segs=[], badges=[], timer, active=-1, loadId=0, values=[];
        const rad=d=>(d-90)*Math.PI/180, pt=(r,d)=>[r*Math.cos(rad(d)), r*Math.sin(rad(d))];
        const ease=t=>1-Math.pow(1-t,4), fmt=v=>Number(v).toLocaleString('en-IN');
        const mk=(tag,attrs,parent)=>{const e=document.createElementNS(NS,tag);for(const k in attrs)e.setAttribute(k,attrs[k]);parent&&parent.appendChild(e);return e;};
        function arc(a0,a1){
          if(a1-a0<0.01) return '';
          if(a1-a0>=359.9) a1=a0+359.9;
          const big=a1-a0>180?1:0,[x1,y1]=pt(RO,a0),[x2,y2]=pt(RO,a1),[x3,y3]=pt(RI,a1),[x4,y4]=pt(RI,a0);
          return `M${x1} ${y1}A${RO} ${RO} 0 ${big} 1 ${x2} ${y2}L${x3} ${y3}A${RI} ${RI} 0 ${big} 0 ${x4} ${y4}Z`;
        }
        el.innerHTML='';
        const svg=mk('svg',{viewBox:'-200 -200 400 400','aria-hidden':'true'},el);
        mk('circle',{r:RM,fill:'none',stroke:'#EEF4F2','stroke-width':RO-RI},svg);
        const spin=mk('g',{class:'wd-spin'},mk('g',{class:'wd-loader'},svg));
        opt.colors.forEach((c,i)=>mk('circle',{class:'wd-arc',r:RM,stroke:c,transform:`rotate(${i*(360/n)-90})`},spin));
        const ring=mk('g',{},svg), bgrp=mk('g',{},svg);
        opt.colors.forEach((c,i)=>{
          const p=mk('path',{class:'wd-seg',fill:c},ring);
          p.addEventListener('mouseenter',()=>{stopAuto();setActive(i);});
          p.addEventListener('mouseleave',()=>{setActive(-1);startAuto();});
          segs.push(p);
          const w=mk('g',{},bgrp), b=mk('g',{class:'wd-badge'},w);
          mk('circle',{r:25,fill:'#fff',stroke:c,'stroke-width':3.5},b);
          const t=mk('text',{'text-anchor':'middle',dy:'5','font-size':14,'font-weight':800,fill:'#0F2622'},b);
          t.textContent=String(i+1).padStart(2,'0');
          badges.push(b);
        });
        const center=document.createElement('div'); center.className='wd-center';
        center.innerHTML='<div><div class="wd-ltxt">Loading<i></i><i></i><i></i></div><div class="wd-val">0</div><div class="wd-lbl"></div></div>';
        el.appendChild(center);
        const cv=center.querySelector('.wd-val'), cl=center.querySelector('.wd-lbl');
        function countUp(to,dur=1100){
          if(reduce){cv.textContent=fmt(to);return;}
          const s=performance.now();
          (function f(now){const t=Math.max(0,Math.min(1,(now-s)/dur));cv.textContent=fmt(Math.round(to*ease(t)));if(t<1)requestAnimationFrame(f);})(s);
        }
        function setActive(i){
          if(i>=0 && !geo[i]) return;
          active=i; svg.classList.toggle('wd-dim',i>=0);
          segs.forEach((s,k)=>{
            s.classList.toggle('wd-on',k===i);
            if(k===i){const [x,y]=pt(10,geo[k].mid);s.style.transform=`translate(${x}px,${y}px)`;} else s.style.transform='';
          });
          badges.forEach((b,k)=>{ if(b.style.opacity==='1') b.style.transform=k===i?'scale(1.18)':'scale(1)'; });
          const total=values.reduce((a,b)=>a+b,0);
          if(i>=0){cv.textContent=fmt(geo[i].v);cl.textContent=opt.labels[i]+' · '+geo[i].pct+'%';}
          else{cv.textContent=fmt(total);cl.textContent=opt.centerLabel;}
          if(opt.onSelect) opt.onSelect(i);
        }
        function startAuto(){stopAuto(); if(reduce||!opt.autoHighlight) return; const live=geo.map((g,k)=>g.v>0?k:-1).filter(k=>k>=0); if(live.length<2) return; let p=-1; timer=setInterval(()=>{p=(p+1)%live.length;setActive(live[p]);},2400);}
        function stopAuto(){clearInterval(timer);}
        function draw(data){
          values=data.map(Number); const sum=values.reduce((a,b)=>a+b,0), total=sum||1;
          let acc=0; geo=values.map(v=>{const a0=acc,a1=acc+v/total*360;acc=a1;const gap=v>0&&v<sum?GAP/2:0;return{a0:a0+gap,a1:a1-gap,mid:(a0+a1)/2,v,pct:Math.round(v/total*100)};});
          cl.textContent=opt.centerLabel; countUp(sum);
          const showBadges=()=>{
            badges.forEach((b,i)=>{
              if(!geo[i].v || geo[i].pct<4) return;
              b.querySelector('text').textContent=geo[i].pct+'%';
              const [x,y]=pt(RM,geo[i].mid); b.parentNode.setAttribute('transform',`translate(${x} ${y})`);
              setTimeout(()=>{b.style.opacity='1';b.style.transform='scale(1)';}, reduce?0:i*110);
            });
            startAuto();
          };
          if(reduce){segs.forEach((s,i)=>s.setAttribute('d',arc(geo[i].a0,geo[i].a1)));showBadges();return;}
          const DUR=1500,STAG=180,END=DUR+STAG*(n-1),start=performance.now();
          (function frame(now){
            const e=Math.max(0,now-start), rt=ease(Math.min(1,e/END));
            ring.setAttribute('transform',`rotate(${-200*(1-rt)}) scale(${0.82+0.18*rt})`);
            segs.forEach((s,i)=>{const t=ease(Math.max(0,Math.min(1,(e-i*STAG)/DUR)));s.setAttribute('d',arc(geo[i].a0,geo[i].a0+(geo[i].a1-geo[i].a0)*t));});
            if(e<END) requestAnimationFrame(frame); else {ring.removeAttribute('transform');showBadges();}
          })(start);
        }
        function load(source){
          const id=++loadId; stopAuto(); active=-1; svg.classList.remove('wd-dim');
          el.classList.add('wd-loading');
          segs.forEach(s=>{s.setAttribute('d','');s.style.transform='';});
          badges.forEach(b=>{b.style.opacity='0';b.style.transform='scale(.4)';});
          const minWait=new Promise(r=>setTimeout(r,reduce?0:500));
          Promise.all([Promise.resolve(source),minWait]).then(([data])=>{
            if(id!==loadId) return;
            el.classList.remove('wd-loading');
            setTimeout(()=>{ if(id===loadId) draw(data); },200);
          }).catch(err=>{ el.classList.remove('wd-loading'); cl.textContent='Could not load'; console.error(err); });
        }
        return { load, setActive, stop:stopAuto, startAuto };
      }
      (function(){
        var el=document.getElementById('wdDonut'); if(!el) return;
        var donut=WaaminiDonut(el,{
          colors:<?php echo json_encode(array_column($wdSeries, 'c')); ?>,
          labels:<?php echo json_encode(array_column($wdSeries, 'l')); ?>,
          centerLabel:'Messages sent'
        });
        var data=<?php echo json_encode(array_map(fn($x) => (int)$actStatus[$x['k']], $wdSeries)); ?>;
        // draw when the card scrolls into view (dashboard is long on phones)
        if('IntersectionObserver' in window){
          var io=new IntersectionObserver(function(es){ if(es[0].isIntersecting){ io.disconnect(); donut.load(data); } },{threshold:.25});
          io.observe(el);
        } else donut.load(data);
      })();
      </script>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo cn_svg('contacts'); ?></div><div class="db-kpi__l">Total contacts</div><div class="db-kpi__v"><?php echo number_format($contactCount); ?></div><div class="db-kpi__d"><?php echo $contactLimit > 0 ? 'Limit <span class="db-chip is-flat">' . number_format($contactLimit) . '</span>' : 'No limit on your plan'; ?></div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo cn_svg('campaigns'); ?></div><div class="db-kpi__l">Campaigns</div><div class="db-kpi__v"><?php echo number_format($campaignCount); ?></div><div class="db-kpi__d">All time</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo cn_svg('templates'); ?></div><div class="db-kpi__l">Templates</div><div class="db-kpi__v"><?php echo number_format($templateCount); ?></div><div class="db-kpi__d">Ready to send</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo cn_svg('chat'); ?></div><div class="db-kpi__l">Total messages</div><div class="db-kpi__v"><?php echo number_format($msgCount); ?></div><div class="db-kpi__d">Sent this month <span class="db-chip is-up"><?php echo number_format($monthMsgs); ?></span></div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Messages this month</h2><a class="db-link" href="subscription.php">Plan</a></div>
      <div class="db-donut__chart">
        <canvas id="dbUsage" width="200" height="200"></canvas>
        <div class="db-donut__center"><div><b><?php echo $msgLimit > 0 ? (int)$msgPercent . '%' : number_format($monthMsgs); ?></b><span><?php echo $msgLimit > 0 ? 'of plan used' : 'messages sent'; ?></span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#128C7E"></i>Sent: <?php echo number_format($monthMsgs); ?></span>
        <?php if ($msgLimit > 0): ?><span><i style="background:#E3EEE9"></i>Remaining: <?php echo number_format($__rem); ?></span><?php endif; ?>
      </div>
      <?php if ($msgPercent > 85 || $contactPercent > 85): ?>
        <div style="margin-top:12px;text-align:center"><span class="db-pill is-warn">Nearing your plan limit</span></div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ===== row 2: recent messages | plan + quick actions ===== -->
  <div class="db-row db-row--bottom">

    <section class="db-card db-trend">
      <div class="db-card__head">
        <div><h2>Messages trend</h2><p>Messages you sent and received, month by month</p></div>
        <label class="db-trend__sel"><select id="dbTrendYear" aria-label="Year"><option><?php echo (int)date('Y'); ?></option></select></label>
      </div>
      <div class="db-trend__legend">
        <span><i style="background:#128C7E"></i>Sent <b id="dbTrendSent">0</b></span>
        <span><i style="background:#38BDF8"></i>Received <b id="dbTrendRecv">0</b></span>
      </div>
      <div class="db-trend__canvas"><canvas id="dbTrend" aria-label="Messages sent and received per month" role="img"></canvas><div class="db-trend__empty" id="dbTrendEmpty">No messages in this year yet</div></div>
    </section>

    <div style="display:flex;flex-direction:column;gap:18px;min-width:0">

      <!-- subscription -->
      <section class="db-card">
        <div class="db-card__head"><div><h2>Current plan</h2><p><?php echo $sub ? $__e($sub['plan_name'] ?? 'Plan') : 'No active subscription'; ?></p></div>
          <?php if ($sub): ?><span class="db-pill <?php echo $isExpired ? 'is-bad' : 'is-ok'; ?>"><?php echo $isExpired ? 'Expired' : $__e(ucfirst((string)($sub['status'] ?? 'Active'))); ?></span><?php endif; ?>
        </div>
        <div class="db-meter">
          <?php if ($sub && !empty($sub['expires_at'])): ?>
            <div class="db-meter__row"><span>Expires</span><b><?php echo $__e(date('d M Y', strtotime((string)$sub['expires_at']))); ?></b></div>
            <div class="db-meter__row" style="border-bottom:0;padding-bottom:0"><span>Days remaining</span><b><?php echo (int)$daysLeft; ?> days</b></div>
            <div class="db-bar"><i style="width:<?php echo (int)$daysLeftPct; ?>%;<?php echo $daysUrgency === 'critical' ? 'background:#e5484d' : ($daysUrgency === 'warning' ? 'background:#F59E0B' : ''); ?>"></i></div>
          <?php elseif ($sub): ?>
            <div class="db-meter__row" style="border-bottom:0"><span>Expiry date</span><b>Not set</b></div>
          <?php else: ?>
            <div class="db-meter__row" style="border-bottom:0"><span>Pick a plan to unlock features</span></div>
          <?php endif; ?>
          <a href="/client/subscription.php" class="db-btn db-btn--primary" style="width:100%;justify-content:center;margin-top:14px"><?php echo $sub ? 'Manage subscription' : 'View plans'; ?></a>
        </div>
      </section>

      <!-- quick actions -->
      <section class="db-card">
        <div class="db-card__head"><div><h2>Quick actions</h2></div></div>
        <div class="db-list" style="padding-top:6px">
          <?php if (has_feature('whatsapp_chat')): ?><a class="db-li" href="whatsapp.php" style="text-decoration:none;color:inherit"><span class="db-li__ic"><?php echo cn_svg('chat'); ?></span><div class="db-li__t"><b>Open chat</b><small>Reply to customers</small></div><div class="db-li__r">&rsaquo;</div></a><?php endif; ?>
          <?php if (has_feature('templates')): ?><a class="db-li" href="templates.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-g"><?php echo cn_svg('templates'); ?></span><div class="db-li__t"><b>Templates</b><small>Create and manage</small></div><div class="db-li__r">&rsaquo;</div></a><?php endif; ?>
          <a class="db-li" href="settings_whatsapp.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-amber"><?php echo cn_svg('wa_setup'); ?></span><div class="db-li__t"><b>WhatsApp setup</b><small>Connect your number</small></div><div class="db-li__r">&rsaquo;</div></a>
          <?php if (has_feature('feat_native_checkout')): ?><a class="db-li" href="order_dashboard.php" style="text-decoration:none;color:inherit"><span class="db-li__ic"><?php echo cn_svg('cart'); ?></span><div class="db-li__t"><b>Orders</b><small>Track your sales</small></div><div class="db-li__r">&rsaquo;</div></a><?php endif; ?>
          <a class="db-li" href="profile.php" style="text-decoration:none;color:inherit"><span class="db-li__ic is-g"><?php echo cn_svg('profile'); ?></span><div class="db-li__t"><b>Profile</b><small>Account details</small></div><div class="db-li__r">&rsaquo;</div></a>
        </div>
      </section>
    </div>
  </div>

  <!-- premium growth -->
  <?php
  $__premium = [
    ['cart',      'E-Commerce',      'WhatsApp catalog, checkout and order tracking.',  'feat_native_checkout', 'order_dashboard.php'],
    ['zapier',    'Zapier Webhooks', 'Connect 5,000+ apps without writing code.',       'api_webhook_access',   'premium_zapier.php'],
    ['drip',      'Drip Campaigns',  'Automated follow-up sequences that convert.',     'feat_drip_campaign',   'premium_drip.php'],
    ['analytics', 'CRM Analytics',   'Revenue, funnel and agent performance insights.', 'feat_crm_analytics',   'premium_analytics.php'],
    ['voice',     'AI Voice Notes',  'Transcribe and analyse customer voice messages.', 'feat_voice_analyzer',  'premium_voice.php'],
  ];
  // Only the modules this plan includes are shown; the block is left out when the plan has none.
  $__premium = array_values(array_filter($__premium, static fn($__p) => has_feature($__p[3])));
  if ($__premium):
  ?>
  <section class="pdash-upsell">
    <div class="pdash-upsell-head">
      <div>
        <div class="pdash-upsell-count"><?php echo cn_svg('ai'); ?> <?php echo count($__premium); ?> premium <?php echo count($__premium) === 1 ? 'module' : 'modules'; ?> active</div>
        <h2 class="pdash-upsell-title">Your premium modules</h2>
        <p class="pdash-upsell-desc">Included in your plan. Open a module to set it up or see how it is doing.</p>
      </div>
      <a href="premium_features.php" class="pdash-upsell-btn">Explore all features <span aria-hidden="true">&rarr;</span></a>
    </div>

    <div class="pdash-upsell-grid">
      <?php foreach ($__premium as [$ic, $name, $sub, $flag, $page]): ?>
        <a class="pdash-feat" href="<?php echo $page; ?>">
          <div class="pdash-feat-top">
            <span class="pdash-feat-ic"><?php echo cn_svg($ic); ?></span>
            <span class="pdash-feat-tag on">Active</span>
          </div>
          <div>
            <h3 class="pdash-feat-name"><?php echo htmlspecialchars($name); ?></h3>
            <p class="pdash-feat-sub"><?php echo htmlspecialchars($sub); ?></p>
          </div>
          <span class="pdash-feat-cta">Open module <span aria-hidden="true">&rarr;</span></span>
        </a>
      <?php endforeach; ?>
    </div>

  </section>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = '#7a8c86';
  var labels = <?php echo json_encode(array_values($chartLabels)); ?>;
  var data   = <?php echo json_encode(array_map('intval', array_values($chartData))); ?>;

  /* ring chart JS handles #wmaRingRoot automatically via .wm-flow selector — no extra code needed */
  var sent = <?php echo (int)$monthMsgs; ?>, rem = <?php echo (int)$__rem; ?>, limited = <?php echo $msgLimit > 0 ? 'true' : 'false'; ?>;
  var none = sent === 0 && rem === 0;
  new Chart(document.getElementById('dbUsage'), {
    type: 'doughnut',
    data: { labels: limited ? ['Sent', 'Remaining'] : ['Sent'], datasets: [{ data: none ? [1] : (limited ? [sent, rem] : [sent]), backgroundColor: none ? ['#E3EEE9'] : (limited ? ['#128C7E', '#E3EEE9'] : ['#128C7E']), borderWidth: 0, spacing: limited ? 2 : 0 }] },
    options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: !none } } }
  });

  /* Messages trend: sent vs received per month, real data from client/dashboard_trend.php */
  var cv = document.getElementById('dbTrend'), sel = document.getElementById('dbTrendYear');
  if (cv && sel) {
    var fmt = function (v) { return v >= 1000000 ? +(v / 1000000).toFixed(1) + 'M' : (v >= 1000 ? +(v / 1000).toFixed(1) + 'K' : String(v)); };
    var mk = function (label, color) { return { label: label, data: [], borderColor: color, backgroundColor: color, cubicInterpolationMode: 'monotone', borderWidth: 2.4, pointRadius: 4.5, pointHoverRadius: 6.5, pointBackgroundColor: color, pointBorderWidth: 0, spanGaps: false }; };
    var trend = new Chart(cv, {
      type: 'line', data: { labels: [], datasets: [mk('Sent', '#128C7E'), mk('Received', '#38BDF8')] },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'nearest', intersect: true },
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0F2622', padding: { x: 12, y: 8 }, cornerRadius: 8, displayColors: false, titleFont: { size: 11, weight: '600' }, bodyFont: { size: 14, weight: '800' },
          callbacks: { title: function (i) { return i[0].label.toUpperCase() + ' · ' + i[0].dataset.label.toUpperCase(); }, label: function (c) { return c.parsed.y.toLocaleString('en-IN') + ' messages'; } } } },
        scales: { x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                  y: { beginAtZero: true, border: { display: false }, grid: { color: '#EEF3F1' }, ticks: { precision: 0, maxTicksLimit: 5, font: { size: 12 }, callback: function (v) { return fmt(v); } } } }
      }
    });
    var empty = document.getElementById('dbTrendEmpty');
    var load = function (year) {
      fetch('dashboard_trend.php' + (year ? '?year=' + encodeURIComponent(year) : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.labels) return;
          if (sel.options.length !== d.years.length || sel.value !== String(d.year)) {
            sel.innerHTML = d.years.slice().reverse().map(function (y) { return '<option value="' + y + '"' + (y === d.year ? ' selected' : '') + '>' + y + '</option>'; }).join('');
          }
          trend.data.labels = d.labels; trend.data.datasets[0].data = d.sent; trend.data.datasets[1].data = d.received; trend.update();
          document.getElementById('dbTrendSent').textContent = d.totals.sent.toLocaleString('en-IN');
          document.getElementById('dbTrendRecv').textContent = d.totals.received.toLocaleString('en-IN');
          empty.style.display = (d.totals.sent + d.totals.received) === 0 ? 'flex' : 'none';
        }).catch(function () {});
    };
    sel.addEventListener('change', function () { load(sel.value); });
    load('');
  }
})();
</script>
<?php endif; ?>

<style>
.db-trend{display:flex;flex-direction:column;min-width:0}
.db-trend__sel{position:relative;flex:none}
.db-trend__sel select{appearance:none;-webkit-appearance:none;height:40px;min-width:112px;padding:0 38px 0 16px;border:0;border-radius:12px;background:#F1F6F4 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23586a63' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 12px center/16px;font:600 14px Inter,system-ui,sans-serif;color:#0F2622;cursor:pointer;outline:0;transition:background-color .15s,box-shadow .15s}
.db-trend__sel select:hover{background-color:#E6FCF2}
.db-trend__sel select:focus{box-shadow:0 0 0 4px rgba(18,140,126,.14)}
.db-trend__legend{display:flex;gap:22px;padding:14px 20px 0;font-size:13.5px;color:#586a63;font-weight:600}
.db-trend__legend span{display:inline-flex;align-items:center;gap:8px}
.db-trend__legend i{width:10px;height:10px;border-radius:50%}
.db-trend__legend b{color:#0F2622;font-weight:800;margin-left:2px}
.db-trend__canvas{position:relative;flex:1;min-height:300px;padding:10px 14px 16px}
.db-trend__canvas canvas{position:absolute;inset:10px 14px 16px 14px;width:calc(100% - 28px)!important;height:calc(100% - 26px)!important}
.db-trend__empty{position:absolute;inset:0;display:none;align-items:center;justify-content:center;font-size:14px;color:#8a9a95;pointer-events:none}
</style>

<!-- ============================================================
     WAAMINI RING CHART JS  — exact same script as landing page.
     Runs on ANY .wm-flow element on the page, including #wmaRingRoot.
     No changes from the original; paste here to avoid a separate file.
     ============================================================ -->
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var CX = 600, CY = 410;
  var CORE_R = 90;
  var BANDS = [[98, 160], [160, 223], [223, 286]];
  var CARD_RIGHT = 280;
  var CARD_Y = [82, 222, 362];
  var CENTRE_NAME = 'WAAMINI';
  var CENTRE_SUB  = 'WHATSAPP CRM';

  var ICONS = {
    capture:  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    automate: '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    convert:  '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'
  };

  function el(name, attrs, html) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (html) n.innerHTML = html;
    return n;
  }
  function pt(r, deg) {
    var a = deg * Math.PI / 180;
    return [CX + r * Math.sin(a), CY - r * Math.cos(a)];
  }
  function f(n) { return Math.round(n * 100) / 100; }

  document.querySelectorAll('.wm-flow').forEach(function (root) {
    var svg   = root.querySelector('.wm-flow__svg');
    var steps = Array.prototype.slice.call(root.querySelectorAll('.wm-step')).slice(0, 3);
    if (!svg || !steps.length) return;

    svg.innerHTML =
      '<defs><filter id="wmShadow" x="-20%" y="-20%" width="140%" height="140%">' +
      '<feDropShadow dx="0" dy="3" stdDeviation="7" flood-color="#0F172A" flood-opacity="0.24"/>' +
      '</filter></defs>';

    var gRings = el('g', {}), gLinks = el('g', {}), gTop = el('g', {});
    var gDots  = el('g', { 'class': 'wm-dots' });
    var calm   = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    steps.forEach(function (li, i) {
      var cs      = getComputedStyle(li);
      var color   = cs.getPropertyValue('--c').trim() || '#1E1B4B';
      var onColor = cs.getPropertyValue('--t').trim() || '#FFFFFF';
      var sweep   = Math.max(20, Math.min(270, parseFloat(li.getAttribute('data-sweep')) || 270));
      var rIn = BANDS[i][0], rOut = BANDS[i][1], r = (rIn + rOut) / 2;
      var start = pt(r, 0), end = pt(r, sweep);
      li.setAttribute('data-i', i);
      li.style.setProperty('--i', i);

      var ring = el('path', {
        'class': 'wm-ring', 'data-i': i, style: '--i:' + i, pathLength: 1,
        d: 'M' + f(start[0]) + ' ' + f(start[1]) +
           ' A' + r + ' ' + r + ' 0 ' + (sweep > 180 ? 1 : 0) + ' 1 ' + f(end[0]) + ' ' + f(end[1]),
        stroke: color, 'stroke-width': rOut - rIn, filter: 'url(#wmShadow)'
      });
      gRings.insertBefore(ring, gRings.firstChild);

      var xv = CX - r, y = CARD_Y[i], c = 18;
      var d = 'M' + CARD_RIGHT + ' ' + y + ' H' + f(xv - c) +
              ' Q' + f(xv) + ' ' + y + ' ' + f(xv) + ' ' + (y + c) + ' V' + CY;
      if (sweep < 270) d += ' A' + r + ' ' + r + ' 0 0 0 ' + f(end[0]) + ' ' + f(end[1]);
      gLinks.appendChild(el('path', { 'class': 'wm-link', 'data-i': i, style: '--i:' + i, pathLength: 1, d: d, stroke: color }));

      if (!calm) {
        var dot = el('circle', { r: 4, fill: color, 'data-i': i });
        dot.appendChild(el('animateMotion', { path: d, dur: '2.8s', begin: (i * 0.45) + 's', repeatCount: 'indefinite' }));
        dot.appendChild(el('animate', { attributeName: 'opacity', values: '0;1;1;0', keyTimes: '0;0.08;0.88;1', dur: '2.8s', begin: (i * 0.45) + 's', repeatCount: 'indefinite' }));
        gDots.appendChild(dot);
      }

      var ip = pt(r, sweep - (24 / r) * 180 / Math.PI), size = 24;
      var iconWrap = el('g', {
        'data-i': i, 'pointer-events': 'none',
        transform: 'translate(' + f(ip[0] - size / 2) + ' ' + f(ip[1] - size / 2) + ') scale(' + size / 24 + ')'
      });
      iconWrap.appendChild(el('g', { 'class': 'wm-icon', style: '--i:' + i, stroke: onColor },
        ICONS[li.getAttribute('data-icon')] || ICONS.convert));
      gTop.appendChild(iconWrap);

      var num = el('text', { 'class': 'wm-num', 'data-i': i, style: '--i:' + i, x: CX - 14, y: f(CY - r + 6), 'text-anchor': 'end' });
      num.textContent = ('0' + (i + 1)).slice(-2);
      gTop.appendChild(num);
    });

    var core = el('g', { 'class': 'wm-core' });
    core.appendChild(el('circle', { 'class': 'wm-core__disc', cx: CX, cy: CY, r: CORE_R, fill: '#FFFFFF', filter: 'url(#wmShadow)' }));
    var name = el('text', { 'class': 'wm-core__name', x: CX, y: CY + 3, 'text-anchor': 'middle' });
    name.textContent = CENTRE_NAME;
    var sub = el('text', { 'class': 'wm-core__sub', x: CX, y: CY + 24, 'text-anchor': 'middle' });
    sub.textContent = CENTRE_SUB;
    core.appendChild(name); core.appendChild(sub); gTop.appendChild(core);

    svg.appendChild(gLinks); svg.appendChild(gDots); svg.appendChild(gRings); svg.appendChild(gTop);

    var mq = window.matchMedia('(max-width: 820px)');
    function fit() { svg.setAttribute('viewBox', mq.matches ? '296 106 608 608' : '0 0 900 720'); }
    fit();
    if (mq.addEventListener) mq.addEventListener('change', fit); else mq.addListener(fit);

    var armed = !calm && 'IntersectionObserver' in window, doneTimer;
    if (armed) {
      root.classList.add('is-ready');
      new IntersectionObserver(function (entries) {
        var e = entries[entries.length - 1];
        if (e.intersectionRatio >= 0.3 && !root.classList.contains('is-in')) {
          root.classList.add('is-in');
          doneTimer = setTimeout(function () { root.classList.add('is-done'); }, 3200);
        } else if (!e.isIntersecting) {
          clearTimeout(doneTimer);
          setActive(-1);
          root.classList.remove('is-in', 'is-done');
        }
      }, { threshold: [0, 0.3] }).observe(root);
    }

    var parts = Array.prototype.slice.call(root.querySelectorAll('[data-i]'));
    var pinned = -1, lastPointer = 'mouse';
    function setActive(i) {
      root.classList.toggle('has-active', i >= 0);
      parts.forEach(function (n) { n.classList.toggle('is-active', +n.getAttribute('data-i') === i); });
    }
    function live() { return !armed || root.classList.contains('is-done'); }
    root.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType; }, true);
    root.querySelectorAll('.wm-step, .wm-ring').forEach(function (n) {
      var i = +n.getAttribute('data-i');
      n.addEventListener('pointerenter', function (e) { if (e.pointerType !== 'touch' && live()) setActive(i); });
      n.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'touch') setActive(pinned); });
      n.addEventListener('click', function (e) {
        if (lastPointer !== 'touch' || !live()) return;
        e.stopPropagation();
        pinned = pinned === i ? -1 : i;
        setActive(pinned);
      });
    });
    document.addEventListener('click', function () { if (pinned >= 0) { pinned = -1; setActive(-1); } });
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
