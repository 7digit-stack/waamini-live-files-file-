<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Polls & NPS Surveys';
$currentPage = 'polls';

// Schema migrations are managed via migrations/ and should not run inline.

// Handle create poll
if (isset($_POST['create_poll'])) {
    csrf_verify();
    $title = sanitize($_POST['title'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['poll', 'nps', 'rating', 'feedback'], true) ? $_POST['type'] : 'poll';
    $question = trim($_POST['question'] ?? '');
    $options = array_filter(array_map('trim', explode("\n", $_POST['options']??'')));
    $optJson = $options ? json_encode(array_values($options)) : null;
    $stmt = $conn->prepare("INSERT INTO wa_polls (company_id,title,type,question,options) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issss",$companyId,$title,$type,$question,$optJson);
    $stmt->execute();
    header("Location: polls.php?msg=created"); exit();
}

// Handle send poll
if (isset($_POST['send_poll'])) {
    csrf_verify();
    $pid = intval($_POST['poll_id']);
    $stmt_p = $conn->prepare("SELECT * FROM wa_polls WHERE id=? AND company_id=?");
    $stmt_p->bind_param("ii",$pid,$companyId); $stmt_p->execute();
    $poll = $stmt_p->get_result()->fetch_assoc();
    if ($poll) {
        $options = json_decode($poll['options']??'[]',true)?:[];
        $optText = '';
        if ($poll['type']==='nps') $optText = "\n\n" . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Reply with a number 0-10' . "\n(0 = Not at all, 10 = Absolutely!)";
        elseif ($poll['type']==='rating') $optText = "\n\n" . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Reply with 1-5 stars';
        elseif ($options) { foreach ($options as $i=>$o) $optText .= "\n" . ($i+1) . ". $o"; $optText .= "\n\nReply with the number of your choice."; }
        $msg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> *Quick ' . ucfirst($poll['type']) . "*\n\n{$poll['question']}{$optText}";
        $stmt_ct = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND opted_in=1 AND status!='blocked' LIMIT 1000");
        $stmt_ct->bind_param("i",$companyId); $stmt_ct->execute();
        $contacts = $stmt_ct->get_result();
        $sent = 0;
        while ($ct = $contacts->fetch_assoc()) {
            $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,NOW())");
            $stmt_q->bind_param("iis",$companyId,$ct['id'],$msg); $stmt_q->execute(); $sent++;
        }
        $stmtUpd = $conn->prepare("UPDATE wa_polls SET sent_count = sent_count + ? WHERE id = ? AND company_id = ?");
        $stmtUpd->bind_param("iii", $sent, $pid, $companyId);
        $stmtUpd->execute();
        header("Location: polls.php?msg=sent&count=$sent"); exit();
    }
}

$stmt_polls = $conn->prepare("SELECT wp.*, (SELECT COUNT(*) FROM poll_responses pr WHERE pr.poll_id=wp.id) as response_count, (SELECT AVG(pr.score) FROM poll_responses pr WHERE pr.poll_id=wp.id AND pr.score IS NOT NULL) as avg_score FROM wa_polls wp WHERE wp.company_id=? ORDER BY wp.id DESC");
$stmt_polls->bind_param("i",$companyId); $stmt_polls->execute();
$polls = $stmt_polls->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Poll <?php echo ($_GET['msg'] === 'sent') ? ('sent to ' . intval($_GET['count']) . ' contacts!') : 'created!'; ?></div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Polls & NPS Surveys</h2>
    <button onclick="document.getElementById('createPollModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create Poll</button>
</div>

<?php if ($polls->num_rows===0): ?>
<div style="text-align:center;padding:60px;background:var(--bg-card);border-radius:16px;border:1px solid var(--border);">
    <div style="font-size:48px;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg></div>
    <h3>No Polls Yet</h3>
    <p style="color:var(--text-muted);">Create your first WhatsApp poll or NPS survey!</p>
</div>
<?php else: while ($poll = $polls->fetch_assoc()):
    $typeColors = ['poll'=>'#6c63ff','nps'=>'#e17055','rating'=>'#f39c12','feedback'=>'#00b894'];
    $tc = $typeColors[$poll['type']]??'#6c63ff';
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:12px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div>
            <span style="font-size:10px;padding:2px 10px;border-radius:20px;background:<?php echo $tc; ?>20;color:<?php echo $tc; ?>;font-weight:700;text-transform:uppercase;margin-right:8px;"><?php echo $poll['type']; ?></span>
            <strong style="font-size:15px;"><?php echo htmlspecialchars($poll['title']); ?></strong>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:12px;color:var(--text-muted);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> <?php echo $poll['sent_count']; ?> sent · <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> <?php echo $poll['response_count']; ?> responses</span>
            <?php if ($poll['avg_score']): ?><span style="font-size:12px;color:#f39c12;font-weight:700;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Avg: <?php echo round($poll['avg_score'],1); ?></span><?php endif; ?>
        </div>
    </div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;"><?php echo htmlspecialchars($poll['question']); ?></p>
    <?php $opts=json_decode($poll['options']??'[]',true)?:[]; if ($opts): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">
        <?php foreach ($opts as $i=>$o): ?><span style="font-size:12px;padding:3px 10px;border-radius:20px;background:var(--bg-input);border:1px solid var(--border);"><?php echo ($i+1).'. '.htmlspecialchars($o); ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:8px;">
        <form method="POST"><input type="hidden" name="poll_id" value="<?php echo $poll['id']; ?>"><?php echo csrf_field(); ?><button name="send_poll" class="btn btn-sm btn-primary" onclick="return confirm('Send to all opted-in contacts?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Send Now</button></form>
        <a href="poll_results.php?id=<?php echo $poll['id']; ?>" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> View Results</a>
    </div>
</div>
<?php endwhile; endif; ?>

<!-- Create Modal -->
<div id="createPollModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:24px;width:500px;max-width:90vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Create Poll / Survey</h3>
    <form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required placeholder="e.g. Customer Satisfaction Survey"></div>
    <div class="form-group">
        <label>Type</label>
        <select name="type" class="form-control" id="pollType" onchange="toggleOptions()">
            <option value="poll"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Multiple Choice Poll</option>
            <option value="nps"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg> NPS Survey (0-10)</option>
            <option value="rating"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Star Rating (1-5)</option>
            <option value="feedback"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Open Feedback</option>
        </select>
    </div>
    <div class="form-group"><label>Question</label><textarea name="question" class="form-control" rows="3" required placeholder="How satisfied are you with our service?"></textarea></div>
    <div class="form-group" id="optionsBox">
        <label>Options (one per line)</label>
        <textarea name="options" class="form-control" rows="5" placeholder="Very Satisfied&#10;Satisfied&#10;Neutral&#10;Dissatisfied"></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('createPollModal').style.display='none'" class="btn btn-edit">Cancel</button>
        <button type="submit" name="create_poll" class="btn btn-primary">Create Poll</button>
    </div>
    </form>
</div>
</div>
<script>
function toggleOptions() {
    var t = document.getElementById('pollType').value;
    document.getElementById('optionsBox').style.display = t==='poll'?'':'none';
}
toggleOptions();
</script>
<?php include '../includes/client_footer.php'; ?>

