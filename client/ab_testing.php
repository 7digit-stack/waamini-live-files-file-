<?php
include '../includes/client_auth_check.php';
$pageTitle = 'A/B Campaign Testing';
$currentPage = 'ab_testing';

// Schema migrations are managed via `migrations/` and should not run inline.

// Handle create
if (isset($_POST['create_test'])) {
    csrf_verify();
    $name  = sanitize($_POST['name']);
    $msgA  = trim($_POST['message_a']);
    $msgB  = trim($_POST['message_b']);
    $split = max(10, min(90, intval($_POST['split_pct']??50)));
    if ($name && $msgA && $msgB) {
        $stmt = $conn->prepare("INSERT INTO ab_tests (company_id,name,message_a,message_b,split_pct) VALUES (?,?,?,?,?)");
        $stmt->bind_param("isssi", $companyId,$name,$msgA,$msgB,$split);
        $stmt->execute();
        header("Location: ab_testing.php?msg=created"); exit();
    }
}

// Handle launch test
if (isset($_POST['launch_test'])) {
    csrf_verify();
    $tid = intval($_POST['test_id']);
    $segment = intval($_POST['contact_segment'] ?? 0);
    $stmt_t = $conn->prepare("SELECT * FROM ab_tests WHERE id=? AND company_id=? AND status='draft'");
    $stmt_t->bind_param("ii", $tid, $companyId);
    $stmt_t->execute();
    $test = $stmt_t->get_result()->fetch_assoc();
    if ($test) {
        // Get contacts
        $stmt_ct = $conn->prepare("SELECT id, phone FROM contacts WHERE company_id=? AND status!='blocked' LIMIT ?");
        $limit = $segment > 0 ? $segment : 1000;
        $stmt_ct->bind_param("ii", $companyId, $limit);
        $stmt_ct->execute();
        $contacts = $stmt_ct->get_result()->fetch_all(MYSQLI_ASSOC);
        $total = count($contacts);
        $splitA = round($total * $test['split_pct'] / 100);
        $sentA = $sentB = 0;

        foreach ($contacts as $i => $ct) {
            $msg = ($i < $splitA) ? $test['message_a'] : $test['message_b'];
            $variant = ($i < $splitA) ? 'a' : 'b';
            $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id,contact_id,type,message,scheduled_at) VALUES (?,?,'instant',?,NOW())");
            $stmt_q->bind_param("iis", $companyId, $ct['id'], $msg);
            $stmt_q->execute();
            if ($variant==='a') $sentA++; else $sentB++;
        }

        $stmt_upd = $conn->prepare("UPDATE ab_tests SET status='running',sent_a=?,sent_b=? WHERE id=?");
        $stmt_upd->bind_param("iii",$sentA,$sentB,$tid);
        $stmt_upd->execute();
        header("Location: ab_testing.php?msg=launched"); exit();
    }
}

// Handle pick winner
if (isset($_POST['pick_winner'])) {
    csrf_verify();
    $tid = intval($_POST['test_id']);
    $winner = in_array($_POST['winner'],['a','b','tie']) ? $_POST['winner'] : 'tie';
    $stmt_w = $conn->prepare("UPDATE ab_tests SET status='completed', winner=? WHERE id=? AND company_id=?");
    $stmt_w->bind_param("sii", $winner, $tid, $companyId);
    $stmt_w->execute();
    header("Location: ab_testing.php"); exit();
}

$stmt_all = $conn->prepare("SELECT * FROM ab_tests WHERE company_id=? ORDER BY id DESC");
$stmt_all->bind_param("i", $companyId);
$stmt_all->execute();
$tests = $stmt_all->get_result();

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Test <?php echo htmlspecialchars($_GET['msg']); ?>!</div><?php endif; ?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> A/B Campaign Testing</h2>
    <button onclick="document.getElementById('createModal').style.display='flex'" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> New A/B Test</button>
</div>

<?php if ($tests->num_rows === 0): ?>
<div style="text-align:center;padding:60px;background:var(--bg-card);border-radius:16px;border:1px solid var(--border);">
    <div style="font-size:48px;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg></div>
    <h3>No A/B Tests Yet</h3>
    <p style="color:var(--text-muted);font-size:14px;">Test 2 message variants and automatically find the winner.</p>
    <button onclick="document.getElementById('createModal').style.display='flex'" class="btn btn-primary">Create First Test</button>
</div>
<?php else: while ($t = $tests->fetch_assoc()):
    $totalSent = $t['sent_a'] + $t['sent_b'];
    $rateA = $t['sent_a'] > 0 ? round($t['opens_a']/$t['sent_a']*100,1) : 0;
    $rateB = $t['sent_b'] > 0 ? round($t['opens_b']/$t['sent_b']*100,1) : 0;
    $statusColors = ['draft'=>'#95a5a6','running'=>'#f39c12','completed'=>'#00b894'];
    $sc = $statusColors[$t['status']];
?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <h3 style="font-size:16px;font-weight:700;margin:0;"><?php echo htmlspecialchars($t['name']); ?></h3>
            <?php if ($t['winner']): ?><span style="font-size:12px;font-weight:700;color:#f39c12;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Winner: Variant <?php echo strtoupper($t['winner']); ?></span><?php endif; ?>
        </div>
        <span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:<?php echo $sc; ?>20;color:<?php echo $sc; ?>;"><?php echo ucfirst($t['status']); ?></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <?php foreach (['a','b'] as $v): $msg=$t["message_$v"]; $sent=$t["sent_$v"]; ?>
        <div style="background:var(--bg-input);border-radius:10px;padding:14px;">
            <div style="font-size:11px;font-weight:800;color:<?php echo $v==='a'?'#6c63ff':'#e17055'; ?>;margin-bottom:8px;">VARIANT <?php echo strtoupper($v); ?> <?php echo $t['winner']===$v ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>' : ''; ?></div>
            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;white-space:pre-wrap;"><?php echo htmlspecialchars(mb_strimwidth($msg,0,150,'...')); ?></div>
            <div style="font-size:12px;"><strong><?php echo $sent; ?></strong> sent</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($t['status']==='running'): ?>
    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="test_id" value="<?php echo $t['id']; ?>">
        <select name="winner" class="form-control" style="width:auto;">
            <option value="a">Variant A Wins</option>
            <option value="b">Variant B Wins</option>
            <option value="tie">It's a Tie</option>
        </select>
        <button type="submit" name="pick_winner" class="btn btn-primary btn-sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Pick Winner & Close</button>
    </form>
    <?php elseif ($t['status']==='draft'): ?>
    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="test_id" value="<?php echo $t['id']; ?>">
        <input type="number" name="contact_segment" class="form-control" style="width:120px;" placeholder="# contacts (0=all)" min="0">
        <button type="submit" name="launch_test" class="btn btn-primary btn-sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Launch Test</button>
    </form>
    <?php endif; ?>
</div>
<?php endwhile; endif; ?>

<!-- Create Modal -->
<div id="createModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:var(--bg-card);border-radius:16px;padding:28px;width:540px;max-width:90vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Create A/B Test</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="form-group"><label>Test Name</label><input type="text" name="name" class="form-control" placeholder="e.g. Diwali Sale - Urgency vs Friendly" required></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label style="color:#6c63ff;font-weight:700;">A Variant A</label><textarea name="message_a" class="form-control" rows="5" placeholder="First message variant..." required></textarea></div>
            <div class="form-group"><label style="color:#e17055;font-weight:700;">B Variant B</label><textarea name="message_b" class="form-control" rows="5" placeholder="Second message variant..." required></textarea></div>
        </div>
        <div class="form-group"><label>Split: % to Variant A</label><input type="range" name="split_pct" min="10" max="90" value="50" class="form-control" oninput="document.getElementById('splitDisplay').textContent=this.value+'% A / '+(100-this.value)+'% B'"><div id="splitDisplay" style="font-size:12px;color:var(--text-muted);margin-top:4px;">50% A / 50% B</div></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('createModal').style.display='none'" class="btn btn-edit">Cancel</button>
            <button type="submit" name="create_test" class="btn btn-primary">Create Test</button>
        </div>
    </form>
</div>
</div>
<?php include '../includes/client_footer.php'; ?>

