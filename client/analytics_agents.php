<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Agent Performance';
$currentPage = 'analytics_agents';

// Add response_time tracking to messages if missing
// Migration handled schema change
// Migration handled schema change

// Get agents (team members)
$stmt_ag = $conn->prepare("SELECT u.id, u.name, u.email,
    (SELECT COUNT(*) FROM messages m WHERE m.responded_by=u.id AND m.direction='outgoing' AND DATE(m.created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)) as messages_sent,
    (SELECT AVG(m.response_time_seconds) FROM messages m WHERE m.responded_by=u.id AND m.response_time_seconds IS NOT NULL AND DATE(m.created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)) as avg_response_sec,
    (SELECT COUNT(DISTINCT m.contact_id) FROM messages m WHERE m.responded_by=u.id AND DATE(m.created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)) as contacts_handled
    FROM users u WHERE u.company_id=? AND u.role IN ('agent','client')
    ORDER BY messages_sent DESC");
$stmt_ag->bind_param("i", $companyId);
$stmt_ag->execute();
$agents = $stmt_ag->get_result();

// Overall stats
$stmt_ov = $conn->prepare("SELECT
    COUNT(DISTINCT contact_id) as total_chats,
    AVG(CASE WHEN direction='outgoing' AND response_time_seconds IS NOT NULL THEN response_time_seconds END) as avg_resp,
    COUNT(CASE WHEN direction='outgoing' THEN 1 END) as total_replies
    FROM messages WHERE company_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$stmt_ov->bind_param("i", $companyId);
$stmt_ov->execute();
$overall = $stmt_ov->get_result()->fetch_assoc();

// Open tickets
$stmt_op = $conn->prepare("SELECT COUNT(*) as c FROM chat_conversations WHERE company_id=? AND ticket_status='open'");
$stmt_op->bind_param("i", $companyId);
$stmt_op->execute();
$openTickets = intval($stmt_op->get_result()->fetch_assoc()['c']);

include '../includes/client_header.php';
?>
<div class="page-header"><h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Agent Performance</h2><div style="font-size:12px;color:var(--text-muted);">Last 30 days</div></div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
<?php
$avgRespMin = $overall['avg_resp'] ? round($overall['avg_resp']/60, 1) . ' min' : '—';
foreach ([
    [$overall['total_chats']??0,'Total Chats','#6c63ff'],
    [$overall['total_replies']??0,'Total Replies','#00b894'],
    [$avgRespMin,'Avg Response Time','#f39c12'],
    [$openTickets,'Open Tickets','#e74c3c'],
] as [$val,$label,$color]):
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;">
    <div style="font-size:22px;font-weight:800;color:<?php echo $color; ?>;"><?php echo $val; ?></div>
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-top:4px;"><?php echo $label; ?></div>
</div>
<?php endforeach; ?>
</div>

<div class="form-card" style="max-width:none;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Agent Leaderboard</h3>
    <div class="data-table-wrapper">
    <table class="data-table">
        <thead><tr><th>#</th><th>Agent</th><th>Messages Sent</th><th>Contacts Handled</th><th>Avg Response</th><th>Rating</th></tr></thead>
        <tbody>
        <?php $rank=1; while ($ag = $agents->fetch_assoc()):
            $avgMin = $ag['avg_response_sec'] ? round($ag['avg_response_sec']/60,1).' min' : '—';
            $medals = ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>','<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>','<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>'];
            $medal = $medals[$rank-1] ?? $rank;
        ?>
        <tr>
            <td style="font-size:18px;"><?php echo $medal; ?></td>
            <td><strong><?php echo htmlspecialchars($ag['name']); ?></strong><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($ag['email']); ?></small></td>
            <td><strong><?php echo number_format($ag['messages_sent']); ?></strong></td>
            <td><?php echo number_format($ag['contacts_handled']); ?></td>
            <td><?php echo $avgMin; ?></td>
            <td>
                <?php
                $score = min(100, ($ag['messages_sent']*2) + ($ag['contacts_handled']*3));
                $col = $score>70?'#00b894':($score>40?'#f39c12':'#95a5a6');
                ?>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:60px;height:6px;background:var(--border);border-radius:3px;"><div style="width:<?php echo min(100,$score); ?>%;height:6px;background:<?php echo $col; ?>;border-radius:3px;"></div></div>
                    <span style="font-size:11px;color:<?php echo $col; ?>;font-weight:700;"><?php echo $score; ?></span>
                </div>
            </td>
        </tr>
        <?php $rank++; endwhile; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/client_footer.php'; ?>
