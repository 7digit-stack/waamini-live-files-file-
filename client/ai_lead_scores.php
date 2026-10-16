<?php
include '../includes/client_auth_check.php';
$pageTitle = 'AI Lead Scores';
$currentPage = 'ai_lead_scores';

// Ensure columns exist
// Migration handled schema change
// Migration handled schema changeDEFAULT NULL");
// Migration handled schema change

// Filter
$filter = sanitize($_GET['filter'] ?? '');
$filterMap = ['hot' => '>=70', 'warm' => 'BETWEEN 40 AND 69', 'cold' => '<40'];
$filterSQL = isset($filterMap[$filter]) ? "AND ai_lead_score {$filterMap[$filter]}" : '';

$stmt = $conn->prepare("SELECT id, name, phone, email, status, ai_lead_score, ai_sentiment, ai_score_updated, tags FROM contacts WHERE company_id=? AND ai_lead_score IS NOT NULL $filterSQL ORDER BY ai_lead_score DESC LIMIT 100");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$contacts = $stmt->get_result();

// Stats
$stmt_st = $conn->prepare("SELECT
    COUNT(*) as total,
    SUM(ai_lead_score >= 70) as hot,
    SUM(ai_lead_score BETWEEN 40 AND 69) as warm,
    SUM(ai_lead_score < 40) as cold,
    AVG(ai_lead_score) as avg_score
    FROM contacts WHERE company_id=? AND ai_lead_score IS NOT NULL");
$stmt_st->bind_param("i", $companyId);
$stmt_st->execute();
$stats = $stmt_st->get_result()->fetch_assoc();

include '../includes/client_header.php';
?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> AI Lead Scores</h2>
    <div style="font-size:13px;color:var(--text-muted);">Powered by OpenAI — auto-updated on every message</div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
    <?php foreach ([
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>','Hot Leads',$stats['hot']??0,'#e74c3c','hot'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg>','Warm Leads',$stats['warm']??0,'#f39c12','warm'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>','Cold Leads',$stats['cold']??0,'#74b9ff','cold'],
        ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>','Avg Score',round($stats['avg_score']??0),'#6c63ff',''],
    ] as [$icon,$label,$val,$color,$f]): ?>
    <a href="ai_lead_scores.php<?php echo $f ? "?filter=$f" : ''; ?>" style="text-decoration:none;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;">
        <div style="font-size:24px;"><?php echo $icon; ?></div>
        <div style="font-size:22px;font-weight:800;color:<?php echo $color; ?>;"><?php echo $val; ?></div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:600;"><?php echo $label; ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div style="display:flex;gap:8px;margin-bottom:16px;">
    <?php foreach ([''=>'All','hot'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Hot','warm'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg> Warm','cold'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Cold'] as $f=>$label): ?>
    <a href="ai_lead_scores.php<?php echo $f ? "?filter=$f" : ''; ?>" class="btn btn-sm <?php echo $filter===$f ? 'btn-primary' : 'btn-edit'; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<div class="data-table-wrapper">
<table class="data-table">
    <thead><tr><th>Contact</th><th>AI Score</th><th>Sentiment</th><th>Last Updated</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($contacts->num_rows === 0): ?>
    <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">No AI scores yet — scores generate automatically when contacts message you.</td></tr>
    <?php else: while ($c = $contacts->fetch_assoc()):
        $score = intval($c['ai_lead_score']);
        $color = $score >= 70 ? '#e74c3c' : ($score >= 40 ? '#f39c12' : '#74b9ff');
        $sentColors = ['positive'=>'#00b894','neutral'=>'#636e72','negative'=>'#e74c3c'];
        $sc = $sentColors[$c['ai_sentiment']??'neutral']??'#636e72';
    ?>
    <tr>
        <td>
            <strong><?php echo htmlspecialchars($c['name']); ?></strong><br>
            <small style="color:var(--text-muted);"><?php echo htmlspecialchars($c['phone']); ?></small>
        </td>
        <td>
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:44px;height:44px;border-radius:50%;background:<?php echo $color; ?>20;border:3px solid <?php echo $color; ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:<?php echo $color; ?>;"><?php echo $score; ?></div>
                <div style="flex:1;max-width:100px;"><div style="height:6px;background:var(--border);border-radius:3px;"><div style="height:6px;background:<?php echo $color; ?>;border-radius:3px;width:<?php echo $score; ?>%;"></div></div></div>
            </div>
        </td>
        <td><span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:<?php echo $sc; ?>20;color:<?php echo $sc; ?>;"><?php echo ucfirst($c['ai_sentiment']??'—'); ?></span></td>
        <td style="font-size:11px;color:var(--text-muted);"><?php echo $c['ai_score_updated'] ? date('d M, h:i A', strtotime($c['ai_score_updated'])) : '—'; ?></td>
        <td><a href="whatsapp.php?contact_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Chat</a></td>
    </tr>
    <?php endwhile; endif; ?>
    </tbody>
</table>
</div>
<?php include '../includes/client_footer.php'; ?>
