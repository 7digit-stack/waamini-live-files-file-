<?php
include '../includes/client_auth_check.php';
$pageTitle = 'WhatsApp Polls & Surveys';
$currentPage = 'wa_polls';

// Schema migrations are managed via migrations/ and should not run inline.

// Handle create
if (isset($_POST['create_poll'])) {
    csrf_verify();
    $title = sanitize($_POST['title'] ?? '');
    $type = in_array($_POST['poll_type'] ?? '', ['nps', 'rating', 'yesno', 'choice'], true) ? $_POST['poll_type'] : 'nps';
    $question = trim($_POST['question'] ?? '');
    $opts = null;
    if ($type==='choice' && !empty($_POST['options'])) {
        $opts = json_encode(array_filter(array_map('trim', explode("\n",$_POST['options']))));
    }
    $stmt = $conn->prepare("INSERT INTO wa_polls (company_id,title,poll_type,question,options) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issss",$companyId,$title,$type,$question,$opts); $stmt->execute();
    header("Location: wa_polls.php?msg=created"); exit();
}

// Handle send poll
if (isset($_POST['send_poll'])) {
    csrf_verify();
    $pid = intval($_POST['poll_id']);
    $stmt_poll = $conn->prepare("SELECT * FROM wa_polls WHERE id=? AND company_id=?");
    $stmt_poll->bind_param("ii",$pid,$companyId); $stmt_poll->execute();
    $poll = $stmt_poll->get_result()->fetch_assoc();
    if ($poll) {
        $pollOptions = json_decode($poll['options'] ?? '[]', true);
        if (!is_array($pollOptions)) {
            $pollOptions = [];
        }
        $choiceLines = [];
        foreach ($pollOptions as $idx => $opt) {
            $choiceLines[] = ($idx + 1) . ". " . $opt;
        }
        $typeMessages = [
            'nps' => "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><line x1=\"18\" y1=\"20\" x2=\"18\" y2=\"10\"/><line x1=\"12\" y1=\"20\" x2=\"12\" y2=\"4\"/><line x1=\"6\" y1=\"20\" x2=\"6\" y2=\"14\"/></svg> *{$poll['title']}*\n\n{$poll['question']}\n\nPlease rate from *0-10*:\n<svg width=\"10\" height=\"10\" viewBox=\"0 0 10 10\" style=\"display:inline;vertical-align:middle\"><circle cx=\"5\" cy=\"5\" r=\"5\" fill=\"#ef4444\"/></svg> 0-6 (Not likely)\n<svg width=\"10\" height=\"10\" viewBox=\"0 0 10 10\" style=\"display:inline;vertical-align:middle\"><circle cx=\"5\" cy=\"5\" r=\"5\" fill=\"#f59e0b\"/></svg> 7-8 (Neutral)\n<svg width=\"10\" height=\"10\" viewBox=\"0 0 10 10\" style=\"display:inline;vertical-align:middle\"><circle cx=\"5\" cy=\"5\" r=\"5\" fill=\"#10b981\"/></svg> 9-10 (Very likely)\n\nJust reply with a number!",
            'rating' => "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"/></svg> *{$poll['title']}*\n\n{$poll['question']}\n\nRate us: *1-5* <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"/></svg>\nReply with a number!",
            'yesno' => "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3\"/><line x1=\"12\" y1=\"17\" x2=\"12.01\" y2=\"17\"/></svg> *{$poll['title']}*\n\n{$poll['question']}\n\nReply: *YES* or *NO*",
            'choice' => "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline;vertical-align:middle;flex-shrink:0\"><path d=\"M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2\"/><rect x=\"8\" y=\"2\" width=\"8\" height=\"4\" rx=\"1\"/></svg> *{$poll['title']}*\n\n{$poll['question']}\n\n" . implode("\n", $choiceLines) . "\n\nReply with the number!",
        ];
        $msg = $typeMessages[$poll['poll_type']] ?? $poll['question'];
        $stmt_ct = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND opted_in=1 AND status!='blocked' LIMIT 500");
        $stmt_ct->bind_param("i",$companyId); $stmt_ct->execute();
        $contacts = $stmt_ct->get_result(); $queued=0;
        while ($ct=$contacts->fetch_assoc()) {
            $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,NOW())");
            $stmt_q->bind_param("iis",$companyId,$ct['id'],$msg); $stmt_q->execute(); $queued++;
        }
        header("Location: wa_polls.php?msg=sent&count=$queued"); exit();
    }
}

$stmt_all = $conn->prepare("SELECT p.*, (SELECT COUNT(*) FROM wa_poll_responses r WHERE r.poll_id=p.id) as responses FROM wa_polls p WHERE p.company_id=? ORDER BY p.id DESC");
$stmt_all->bind_param("i",$companyId); $stmt_all->execute(); $polls = $stmt_all->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Poll <?php echo htmlspecialchars($_GET['msg']); ?><?php echo isset($_GET['count']) ? ' to ' . intval($_GET['count']) . ' contacts' : ''; ?>!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"/><path d="m9 11 3 3L22 4"/></svg> WhatsApp Polls & NPS Surveys</h2>
    <button onclick="document.getElementById('createPollModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create Poll</button>
</div>

<?php if ($polls->num_rows===0): ?>
<div style="text-align:center;padding:60px;background:var(--bg-card);border-radius:16px;border:1px solid var(--border);">
    <div style="font-size:48px;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"/><path d="m9 11 3 3L22 4"/></svg></div>
    <h3>No Polls Yet</h3>
    <p style="color:var(--text-muted);">Create NPS surveys, rating polls, and yes/no questions — collect responses via WhatsApp!</p>
    <button onclick="document.getElementById('createPollModal').style.display='flex'" class="btn btn-primary">Create First Poll</button>
</div>
<?php else: while ($poll=$polls->fetch_assoc()):
    $typeIcons=['nps'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>','rating'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>','yesno'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>','choice'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>'];
    $typeColors=['nps'=>'#6c63ff','rating'=>'#f39c12','yesno'=>'#00b894','choice'=>'#e17055'];
    $ic=$typeIcons[$poll['poll_type']]??'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>'; $tc=$typeColors[$poll['poll_type']]??'#6c63ff';
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:16px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <span style="font-size:20px;"><?php echo $ic; ?></span>
                <h3 style="font-size:15px;font-weight:700;margin:0;"><?php echo htmlspecialchars($poll['title']); ?></h3>
                <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:<?php echo $tc; ?>20;color:<?php echo $tc; ?>;"><?php echo strtoupper($poll['poll_type']); ?></span>
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin:0;"><?php echo htmlspecialchars(mb_strimwidth($poll['question'],0,100,'...')); ?></p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <div style="text-align:center;padding:8px 16px;background:var(--bg-input);border-radius:10px;">
                <div style="font-size:20px;font-weight:800;color:#6c63ff;"><?php echo $poll['responses']; ?></div>
                <div style="font-size:10px;color:var(--text-muted);">Responses</div>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="poll_id" value="<?php echo $poll['id']; ?>">
                <button type="submit" name="send_poll" class="btn btn-primary btn-sm" onclick="return confirm('Send to all opted-in contacts?')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Send</button>
            </form>
        </div>
    </div>
</div>
<?php endwhile; endif; ?>

<!-- Create Modal -->
<div id="createPollModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:500px;max-width:90vw;max-height:90vh;overflow-y:auto;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create Poll / Survey</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required placeholder="e.g. Customer Satisfaction Survey"></div>
        <div class="form-group">
            <label>Poll Type</label>
            <select name="poll_type" class="form-control" onchange="toggleOptions(this.value)">
                <option value="nps"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> NPS Score (0-10)</option>
                <option value="rating"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Star Rating (1-5)</option>
                <option value="yesno"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg> Yes / No</option>
                <option value="choice"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Multiple Choice</option>
            </select>
        </div>
        <div class="form-group"><label>Question</label><textarea name="question" class="form-control" rows="3" required placeholder="How likely are you to recommend us to a friend?"></textarea></div>
        <div class="form-group" id="optionsGroup" style="display:none;">
            <label>Options <span style="color:var(--text-muted);font-size:11px;">(one per line)</span></label>
            <textarea name="options" class="form-control" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('createPollModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="create_poll" class="btn btn-primary">Create Poll</button>
        </div>
    </form>
</div>
</div>
<script>
function toggleOptions(v){ document.getElementById('optionsGroup').style.display=v==='choice'?'block':'none'; }
</script>
<?php include '../includes/client_footer.php'; ?>

