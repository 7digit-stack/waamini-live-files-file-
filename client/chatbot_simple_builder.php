<?php
include '../includes/client_auth_check.php';

$flowId = intval($_GET['flow_id'] ?? 0);
if (!$flowId) { header("Location: chatbot_flows.php"); exit(); }

$stmt_fl = $conn->prepare("SELECT * FROM chatbot_flows WHERE id=? AND company_id=?");
$stmt_fl->bind_param("ii", $flowId, $companyId);
$stmt_fl->execute();
$flow = $stmt_fl->get_result()->fetch_assoc();
if (!$flow) { header("Location: chatbot_flows.php"); exit(); }

// Fetch approved templates
$st_tpl = $conn->prepare("SELECT id, name, body, body_text, language FROM message_templates WHERE company_id=? AND (wa_status='APPROVED' OR wa_status='approved') ORDER BY name ASC");
$st_tpl->bind_param("i", $companyId);
$st_tpl->execute();
$tplRes = $st_tpl->get_result();
$templates = [];
while ($row = $tplRes->fetch_assoc()) $templates[] = $row;

@$conn->query("CREATE TABLE IF NOT EXISTS chatbot_node_connections (
    id INT AUTO_INCREMENT PRIMARY KEY, node_id INT NOT NULL,
    trigger_value VARCHAR(255) DEFAULT NULL, target_node_id INT NOT NULL,
    INDEX idx_node (node_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    csrf_verify();
    $action = $_POST['action'];

    if ($action === 'add_node') {
        $type = sanitize($_POST['node_type'] ?? 'message');
        $label = sanitize($_POST['label'] ?? 'New Reply');
        $msg = sanitize($_POST['message_text'] ?? '');
        $btnOptions = null; $tplId = null;
        $parentId = intval($_POST['parent_node_id'] ?? 0);
        $btnTrigger = sanitize($_POST['btn_trigger'] ?? '');

        if ($type === 'button') {
            $btns = [];
            for ($i=1; $i<=3; $i++) if (!empty($_POST['btn'.$i])) $btns[] = ['text' => sanitize($_POST['btn'.$i])];
            $btnOptions = json_encode($btns);
        }
        if ($type === 'template') $tplId = intval($_POST['template_id'] ?? 0) ?: null;

        $stmt = $conn->prepare("INSERT INTO chatbot_nodes (flow_id, node_type, label, message_text, button_options, template_id, position_x, position_y) VALUES (?,?,?,?,?,?,0,0)");
        $stmt->bind_param("issssi", $flowId, $type, $label, $msg, $btnOptions, $tplId);
        $stmt->execute();
        $newId = $stmt->insert_id;

        if ($parentId > 0) {
            if ($btnTrigger) {
                $conn->query("INSERT INTO chatbot_node_connections (node_id, trigger_value, target_node_id) VALUES ($parentId, '".$conn->real_escape_string($btnTrigger)."', $newId)");
            } else {
                $conn->query("UPDATE chatbot_nodes SET next_node_id=$newId WHERE id=$parentId AND flow_id=$flowId");
            }
        }
        echo json_encode(['success'=>true,'node_id'=>$newId]); exit();
    }
    if ($action === 'update_node') {
        $nid = intval($_POST['node_id'] ?? 0);
        $label = sanitize($_POST['label'] ?? '');
        $msg = sanitize($_POST['message_text'] ?? '');
        $type = sanitize($_POST['node_type'] ?? 'message');
        $btnOptions = null; $tplId = null;
        if ($type === 'button') {
            $btns = [];
            for ($i=1; $i<=3; $i++) if (!empty($_POST['btn'.$i])) $btns[] = ['text'=>sanitize($_POST['btn'.$i])];
            $btnOptions = json_encode($btns);
        }
        if ($type === 'template') $tplId = intval($_POST['template_id'] ?? 0) ?: null;
        $stmt = $conn->prepare("UPDATE chatbot_nodes SET node_type=?,label=?,message_text=?,button_options=?,template_id=? WHERE id=? AND flow_id=?");
        $stmt->bind_param("ssssiis", $type, $label, $msg, $btnOptions, $tplId, $nid, $flowId);
        $stmt->execute();
        echo json_encode(['success'=>true]); exit();
    }
    if ($action === 'delete_node') {
        $nid = intval($_POST['node_id'] ?? 0);
        $conn->query("UPDATE chatbot_nodes SET next_node_id=NULL WHERE next_node_id=$nid AND flow_id=$flowId");
        $conn->query("DELETE FROM chatbot_node_connections WHERE target_node_id=$nid");
        $conn->query("DELETE FROM chatbot_node_connections WHERE node_id=$nid");
        $conn->query("DELETE FROM chatbot_nodes WHERE id=$nid AND flow_id=$flowId");
        echo json_encode(['success'=>true]); exit();
    }
    if ($action === 'toggle_status') {
        $ns = ($flow['is_active'] ?? 1) ? 0 : 1;
        $conn->query("UPDATE chatbot_flows SET is_active=$ns WHERE id=$flowId AND company_id=$companyId");
        echo json_encode(['success'=>true,'is_active'=>$ns]); exit();
    }
    exit();
}

// Load nodes
$stmt_n = $conn->prepare("SELECT * FROM chatbot_nodes WHERE flow_id=? ORDER BY id ASC");
$stmt_n->bind_param("i", $flowId);
$stmt_n->execute();
$nodesRes = $stmt_n->get_result();
$nodes = [];
while ($n = $nodesRes->fetch_assoc()) {
    $n['connections'] = [];
    $cRes = $conn->query("SELECT * FROM chatbot_node_connections WHERE node_id=".intval($n['id']));
    if ($cRes) while ($c = $cRes->fetch_assoc()) $n['connections'][] = $c;
    $nodes[] = $n;
}

$pageTitle = htmlspecialchars($flow['name']);
$currentPage = 'chatbot_flows';
include '../includes/client_header.php';
?>

<style>
.fb-page { max-width:1100px; margin:0 auto; padding-bottom:60px; }

/* ── Top Bar ── */
.fb-top {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
    padding:18px 24px; background:var(--bg-card); border:1px solid var(--border);
    border-radius:16px; margin-bottom:24px;
}
.fb-top-left { display:flex; align-items:center; gap:16px; }
.fb-back { color:var(--text-muted); font-size:18px; text-decoration:none; transition:.3s; }
.fb-back:hover { color:var(--accent); }
.fb-name { font-size:22px; font-weight:800; color:var(--text-primary); }
.fb-trigger {
    display:inline-block; font-size:12px; font-weight:700; padding:3px 12px;
    border-radius:6px; background:rgba(0,184,148,.12); color:var(--accent); margin-top:2px;
}
.fb-top-right { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }

/* Toggle switch */
.fb-sw { display:flex; align-items:center; gap:8px; cursor:pointer; user-select:none; }
.fb-sw-track {
    width:44px; height:24px; border-radius:12px; background:var(--border);
    position:relative; transition:.3s;
}
.fb-sw-track.on { background:var(--accent); }
.fb-sw-dot {
    position:absolute; top:3px; left:3px; width:18px; height:18px;
    border-radius:50%; background:#fff; transition:.3s; box-shadow:0 1px 4px rgba(0,0,0,.3);
}
.fb-sw-track.on .fb-sw-dot { left:23px; }
.fb-sw-lbl { font-size:13px; font-weight:700; color:var(--text-secondary); }

/* Dropdown */
.fb-dd { position:relative; }
.fb-dd-btn {
    padding:10px 20px; border-radius:12px; border:none;
    background:var(--accent); color:#fff; font-size:13px; font-weight:700;
    cursor:pointer; display:flex; align-items:center; gap:8px;
    font-family:inherit; transition:.2s;
}
.fb-dd-btn:hover { background:var(--accent-hover); }
.fb-dd-menu {
    display:none; position:absolute; top:calc(100% + 6px); right:0; z-index:200;
    background:var(--bg-card); border:1px solid var(--border); border-radius:14px;
    box-shadow:0 12px 36px rgba(0,0,0,.5); overflow:hidden; min-width:250px;
}
.fb-dd-menu.open { display:block; animation:ddIn .2s ease; }
@keyframes ddIn { from{opacity:0;transform:translateY(-6px);} to{opacity:1;transform:translateY(0);} }
.fb-dd-item {
    padding:14px 18px; font-size:13px; font-weight:600; color:var(--text-primary);
    cursor:pointer; display:flex; align-items:center; gap:10px; transition:.2s;
    border-bottom:1px solid var(--border);
}
.fb-dd-item:last-child { border:none; }
.fb-dd-item:hover { background:rgba(0,184,148,.08); color:var(--accent); }

.fb-save {
    padding:10px 22px; border-radius:12px; border:1px solid var(--accent);
    background:transparent; color:var(--accent); font-size:13px; font-weight:700;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.fb-save:hover { background:var(--accent); color:#fff; }

/* ── SVG Canvas ── */
.fb-canvas { position:relative; overflow:visible; }
.fb-svg-layer {
    position:absolute; top:0; left:0; width:100%; height:100%;
    pointer-events:none; z-index:1; overflow:visible;
}

/* ── Start Node ── */
.fb-start {
    display:inline-flex; align-items:center; gap:10px;
    padding:14px 28px; background:var(--bg-card); border:2px solid var(--accent);
    border-radius:14px; margin-bottom:0; position:relative; z-index:2;
}
.fb-start-text { font-size:18px; font-weight:800; color:var(--text-primary); }
.fb-start-arrow { font-size:20px; color:var(--accent); }
.fb-start-kw { font-size:14px; font-weight:600; color:var(--accent); }

/* ── Node Card ── */
.fb-node {
    background:var(--bg-card); border:1px solid var(--border); border-radius:14px;
    min-width:260px; max-width:320px; position:relative; z-index:2;
    box-shadow:0 4px 16px rgba(0,0,0,.2); transition:.3s;
}
.fb-node:hover { border-color:rgba(0,184,148,.3); box-shadow:0 8px 28px rgba(0,0,0,.35); }
.fb-node-head {
    padding:14px 16px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.fb-node-title { font-size:15px; font-weight:800; color:var(--text-primary); }
.fb-node-acts { display:flex; gap:5px; }
.fb-node-abtn {
    padding:5px 12px; border-radius:8px; font-size:11px; font-weight:700;
    cursor:pointer; border:none; display:flex; align-items:center; gap:4px;
    font-family:inherit; transition:.2s;
}
.fb-node-abtn.e { background:rgba(52,152,219,.15); color:#3498db; }
.fb-node-abtn.e:hover { background:#3498db; color:#fff; }
.fb-node-abtn.d { background:rgba(231,76,60,.12); color:#e74c3c; }
.fb-node-abtn.d:hover { background:#e74c3c; color:#fff; }
.fb-node-abtn.c { background:rgba(0,184,148,.12); color:var(--accent); }
.fb-node-abtn.c:hover { background:var(--accent); color:#fff; }

.fb-node-body { padding:12px 16px; }
.fb-node-msg {
    font-size:12px; color:var(--text-secondary); line-height:1.5;
    background:var(--bg-input); border-radius:8px; padding:8px 12px;
    white-space:pre-wrap; word-break:break-word; max-height:80px; overflow:hidden;
}

/* Button list inside node */
.fb-node-btns { padding:0 16px 14px; }
.fb-node-bopt {
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 14px; background:var(--bg-input); border:1px solid var(--border);
    border-radius:8px; margin-bottom:4px; font-size:12px; font-weight:600;
    color:var(--text-primary);
}
.fb-node-bopt .arr { color:var(--accent); font-weight:800; }

/* Arrow connector */
.fb-node-next {
    padding:4px 16px 12px; color:var(--accent); font-weight:800; font-size:16px;
}

/* ── Tree Layout ── */
.fb-tree { padding-left:0; margin-top:16px; }
.fb-branch { padding-left:56px; position:relative; margin-top:4px; }
.fb-branch::before {
    content:''; position:absolute; left:24px; top:0; bottom:20px;
    width:3px; background:linear-gradient(180deg,var(--accent),rgba(0,184,148,.15));
    border-radius:2px;
}
.fb-child { position:relative; margin-bottom:14px; }
.fb-child::before {
    content:''; position:absolute; left:-32px; top:22px;
    width:32px; height:3px; background:var(--accent); border-radius:2px;
}
.fb-child::after {
    content:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polygon points="6 3 20 12 6 21 6 3"/></svg>'; position:absolute; left:-4px; top:15px;
    color:var(--accent); font-size:11px;
}
.fb-child-label {
    font-size:11px; font-weight:700; color:var(--text-muted);
    margin-bottom:4px; padding-left:2px;
}

/* ── Empty State ── */
.fb-empty {
    text-align:center; padding:60px; background:var(--bg-card);
    border:2px dashed var(--border); border-radius:18px; margin-top:20px;
}
.fb-empty-ico { font-size:52px; margin-bottom:14px; opacity:.3; }
.fb-empty h3 { font-size:18px; font-weight:700; color:var(--text-secondary); margin-bottom:6px; }
.fb-empty p { font-size:13px; color:var(--text-muted); }

/* ── Modal ── */
.fb-overlay {
    display:none; position:fixed; inset:0; z-index:5000;
    background:rgba(0,0,0,.65); align-items:center; justify-content:center;
    backdrop-filter:blur(6px);
}
.fb-overlay.show { display:flex; }
.fb-modal {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:22px; padding:32px; width:500px; max-width:92vw;
    box-shadow:0 20px 60px rgba(0,0,0,.5);
    animation:mIn .25s ease;
}
@keyframes mIn { from{opacity:0;transform:scale(.96) translateY(8px);} to{opacity:1;transform:scale(1) translateY(0);} }
.fb-modal-t { font-size:20px; font-weight:800; color:var(--text-primary); margin-bottom:4px; }
.fb-modal-s { font-size:13px; color:var(--text-muted); margin-bottom:24px; }
.fb-fld { margin-bottom:16px; }
.fb-fld-l { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-bottom:6px; display:block; }
.fb-fld input,.fb-fld textarea,.fb-fld select {
    width:100%; padding:11px 14px; background:var(--bg-input); border:1px solid var(--border);
    color:var(--text-primary); border-radius:10px; font-size:13px; font-family:inherit;
    outline:none; transition:.2s; box-sizing:border-box;
}
.fb-fld input:focus,.fb-fld textarea:focus,.fb-fld select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,184,148,.12); }
.fb-fld textarea { min-height:80px; resize:vertical; }
.fb-fld input::placeholder,.fb-fld textarea::placeholder { color:var(--text-muted); }
.fb-modal-btns { display:flex; gap:10px; margin-top:24px; }
.fb-modal-can {
    flex:1; padding:12px; border-radius:12px; border:1px solid var(--border);
    background:transparent; color:var(--text-muted); font-size:14px; font-weight:600;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.fb-modal-can:hover { border-color:var(--accent); color:var(--accent); }
.fb-modal-ok {
    flex:2; padding:12px; border-radius:12px; border:none;
    background:var(--accent); color:#fff; font-size:14px; font-weight:700;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.fb-modal-ok:hover { background:var(--accent-hover); }

/* Toast */
.fb-toast {
    position:fixed; bottom:30px; left:50%; transform:translateX(-50%);
    padding:12px 28px; background:var(--accent); color:#fff; border-radius:12px;
    font-size:14px; font-weight:700; box-shadow:0 8px 28px rgba(0,184,148,.4);
    z-index:9999; animation:toastIn .4s ease;
}
@keyframes toastIn { from{opacity:0;transform:translateX(-50%) translateY(20px);} to{opacity:1;transform:translateX(-50%) translateY(0);} }

@media(max-width:640px) {
    .fb-top { flex-direction:column; align-items:flex-start; }
    .fb-top-right { width:100%; justify-content:flex-end; }
    .fb-branch { padding-left:32px; }
    .fb-node { min-width:auto; max-width:none; }
}
</style>

<div class="fb-page">
    <!-- Top Bar -->
    <div class="fb-top">
        <div class="fb-top-left">
            <a href="chatbot_flows.php" class="fb-back"><i class="fas fa-arrow-left"></i></a>
            <div>
                <div class="fb-name"><?php echo htmlspecialchars($flow['name']); ?></div>
                <div class="fb-trigger"><?php echo htmlspecialchars($flow['trigger_keyword'] ?: ucfirst(str_replace('_',' ',$flow['trigger_type']))); ?></div>
            </div>
        </div>
        <div class="fb-top-right">
            <div class="fb-sw" onclick="toggleStatus()">
                <div class="fb-sw-track <?php echo ($flow['is_active'] ?? 1) ? 'on' : ''; ?>" id="swTrack">
                    <div class="fb-sw-dot"></div>
                </div>
                <span class="fb-sw-lbl" id="swLabel">Status</span>
            </div>
            <div class="fb-dd">
                <button class="fb-dd-btn" onclick="this.nextElementSibling.classList.toggle('open')">
                    Add New Bot Reply <i class="fas fa-caret-down" style="margin-left:4px;"></i>
                </button>
                <div class="fb-dd-menu" id="ddMenu">
                    <div class="fb-dd-item" onclick="openAdd('message')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> &nbsp;Simple Bot Reply</div>
                    <div class="fb-dd-item" onclick="openAdd('media')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg> &nbsp;Media Bot Reply</div>
                    <div class="fb-dd-item" onclick="openAdd('button')"><svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#1e293b"/></svg> &nbsp;Advance Interactive Bot Reply</div>
                    <div class="fb-dd-item" onclick="openAdd('template')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> &nbsp;Template Bot Reply</div>
                </div>
            </div>
            <button class="fb-save" onclick="toast('All changes saved automatically!')"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>

    <!-- Flow Canvas -->
    <div class="fb-canvas">
        <div class="fb-start">
            <span class="fb-start-text">Start</span>
            <span class="fb-start-arrow">→</span>
            <span class="fb-start-kw"><?php echo htmlspecialchars($flow['trigger_keyword'] ?: ucfirst(str_replace('_',' ',$flow['trigger_type']))); ?></span>
        </div>
        <div class="fb-tree" id="tree"></div>
    </div>
</div>

<!-- Modal -->
<div class="fb-overlay" id="modal">
    <div class="fb-modal">
        <div class="fb-modal-t" id="mTitle">Add Bot Reply</div>
        <div class="fb-modal-s" id="mSub">Configure the reply</div>
        <form onsubmit="return false;">
            <input type="hidden" id="mNodeId" value="">
            <input type="hidden" id="mType" value="message">
            <input type="hidden" id="mParent" value="">
            <input type="hidden" id="mBtnTrig" value="">
            <div class="fb-fld">
                <label class="fb-fld-l">Reply Name</label>
                <input id="mLabel" placeholder="e.g. Welcome, Menu, Payment..." required>
            </div>
            <div class="fb-fld" id="fMsg">
                <label class="fb-fld-l">Message Text</label>
                <textarea id="mMsg" placeholder="Type the bot reply..."></textarea>
            </div>
            <div class="fb-fld" id="fMedia" style="display:none">
                <label class="fb-fld-l">Media URL (image/video/document)</label>
                <input id="mMediaUrl" placeholder="https://...">
            </div>
            <div class="fb-fld" id="fMediaType" style="display:none">
                <label class="fb-fld-l">Media Type</label>
                <select id="mMediaType"><option value="image">Image</option><option value="video">Video</option><option value="document">Document</option></select>
            </div>
            <div class="fb-fld" id="fBtns" style="display:none">
                <label class="fb-fld-l">Buttons (max 3)</label>
                <input id="mB1" placeholder="Button 1" style="margin-bottom:6px">
                <input id="mB2" placeholder="Button 2" style="margin-bottom:6px">
                <input id="mB3" placeholder="Button 3">
            </div>
            <div class="fb-fld" id="fTpl" style="display:none">
                <label class="fb-fld-l">WhatsApp Template</label>
                <select id="mTpl"><option value="">-- Select --</option>
                <?php foreach($templates as $t): ?><option value="<?php echo $t['id'];?>"><?php echo htmlspecialchars($t['name']);?></option><?php endforeach; ?></select>
            </div>
            <div class="fb-modal-btns">
                <button type="button" class="fb-modal-can" onclick="closeM()">Cancel</button>
                <button type="button" class="fb-modal-ok" onclick="saveNode()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Reply</button>
            </div>
        </form>
    </div>
</div>

<script>
const FID=<?php echo $flowId;?>, CS='<?php echo $_SESSION['csrf_token']??'';?>';
let nodes=<?php echo json_encode(array_values($nodes));?>;

function render(){
    const t=document.getElementById('tree');
    if(!nodes.length){t.innerHTML='<div class="fb-empty" style="margin-top:20px"><div class="fb-empty-ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/></svg></div><h3>No replies yet</h3><p>Click "Add New Bot Reply" above to start building your flow</p></div>';return;}
    const childMap={},btnMap={};
    nodes.forEach(n=>{
        if(n.next_node_id) childMap[n.next_node_id]={pid:n.id,trig:null};
        if(n.connections&&n.connections.length){
            btnMap[n.id]=n.connections;
            n.connections.forEach(c=>{childMap[c.target_node_id]={pid:n.id,trig:c.trigger_value};});
        }
    });
    const roots=nodes.filter(n=>!childMap[n.id]);
    function rn(node){
        const ty=node.node_type||'message';
        const btns=node.button_options?JSON.parse(node.button_options):[];
        const msg=(node.message_text||'').substring(0,120);
        let h=`<div class="fb-node"><div class="fb-node-head"><span class="fb-node-title">${esc(node.label||'Untitled')}</span><div class="fb-node-acts"><button class="fb-node-abtn e" onclick="openEdit(${node.id})"><i class="fas fa-edit"></i> Edit</button><button class="fb-node-abtn d" onclick="delNode(${node.id})"><i class="fas fa-trash"></i> Delete</button><button class="fb-node-abtn c" onclick="openAddChild(${node.id})"><i class="fas fa-plus"></i></button></div></div>`;
        if(msg) h+=`<div class="fb-node-body"><div class="fb-node-msg">${esc(msg)}</div></div>`;
        if(ty==='button'&&btns.length){
            h+='<div class="fb-node-btns">';
            btns.forEach(b=>{h+=`<div class="fb-node-bopt">${esc(b.text)} <span class="arr">→</span></div>`;});
            h+='</div>';
        }
        if(node.next_node_id) h+='<div class="fb-node-next">→</div>';
        h+='</div>';
        // Children
        const lc=nodes.find(x=>x.id==node.next_node_id);
        const bc=btnMap[node.id]||[];
        if(lc||bc.length){
            h+='<div class="fb-branch">';
            if(lc) h+=`<div class="fb-child">${rn(lc)}</div>`;
            bc.forEach(c=>{
                const cn=nodes.find(x=>x.id==c.target_node_id);
                if(cn) h+=`<div class="fb-child"><div class="fb-child-label">${esc(c.trigger_value)}</div>${rn(cn)}</div>`;
            });
            h+='</div>';
        }
        return h;
    }
    let html='';
    roots.forEach(n=>{html+=`<div style="margin-top:16px">${rn(n)}</div>`;});
    t.innerHTML=html;
}

// Dropdown close
document.addEventListener('click',e=>{if(!e.target.closest('.fb-dd'))document.getElementById('ddMenu').classList.remove('open');});

function showFields(ty){
    document.getElementById('fMsg').style.display='block';
    document.getElementById('fBtns').style.display=ty==='button'?'block':'none';
    document.getElementById('fTpl').style.display=ty==='template'?'block':'none';
    document.getElementById('fMedia').style.display=ty==='media'?'block':'none';
    document.getElementById('fMediaType').style.display=ty==='media'?'block':'none';
}
function openAdd(ty){
    document.getElementById('ddMenu').classList.remove('open');
    document.getElementById('mTitle').textContent='Add Bot Reply';
    document.getElementById('mNodeId').value='';
    document.getElementById('mType').value=ty;
    document.getElementById('mParent').value='';
    document.getElementById('mBtnTrig').value='';
    document.getElementById('mLabel').value='';
    document.getElementById('mMsg').value='';
    document.getElementById('mB1').value='';
    document.getElementById('mB2').value='';
    document.getElementById('mB3').value='';
    document.getElementById('mTpl').value='';
    if(document.getElementById('mMediaUrl'))document.getElementById('mMediaUrl').value='';
    showFields(ty);
    document.getElementById('modal').classList.add('show');
}
function openAddChild(parentId){
    openAdd('message');
    document.getElementById('mParent').value=parentId;
}
function openEdit(id){
    const n=nodes.find(x=>x.id==id);if(!n)return;
    document.getElementById('mTitle').textContent='Edit: '+esc(n.label||'Reply');
    document.getElementById('mNodeId').value=id;
    document.getElementById('mType').value=n.node_type;
    document.getElementById('mParent').value='';
    document.getElementById('mBtnTrig').value='';
    document.getElementById('mLabel').value=n.label||'';
    document.getElementById('mMsg').value=n.message_text||'';
    const btns=n.button_options?JSON.parse(n.button_options):[];
    document.getElementById('mB1').value=btns[0]?btns[0].text:'';
    document.getElementById('mB2').value=btns[1]?btns[1].text:'';
    document.getElementById('mB3').value=btns[2]?btns[2].text:'';
    document.getElementById('mTpl').value=n.template_id||'';
    showFields(n.node_type);
    document.getElementById('modal').classList.add('show');
}
function closeM(){document.getElementById('modal').classList.remove('show');}

async function saveNode(){
    const nid=document.getElementById('mNodeId').value;
    const isEdit=nid!=='';
    const fd=new FormData();
    fd.append('csrf_token',CS);
    fd.append('action',isEdit?'update_node':'add_node');
    fd.append('node_type',document.getElementById('mType').value);
    fd.append('label',document.getElementById('mLabel').value);
    fd.append('message_text',document.getElementById('mMsg').value);
    fd.append('btn1',document.getElementById('mB1').value);
    fd.append('btn2',document.getElementById('mB2').value);
    fd.append('btn3',document.getElementById('mB3').value);
    fd.append('template_id',document.getElementById('mTpl').value);
    if(isEdit) fd.append('node_id',nid);
    const pid=document.getElementById('mParent').value;
    if(!isEdit&&pid) fd.append('parent_node_id',pid);
    if(!isEdit&&!pid&&nodes.length){
        // auto-connect to last node that has no children
        const last=nodes[nodes.length-1];
        fd.append('parent_node_id',last.id);
    }
    try{
        const r=await fetch('chatbot_simple_builder.php?flow_id='+FID,{method:'POST',body:fd});
        const j=await r.json();
        if(j.success){closeM();location.reload();}else alert('Error');
    }catch(e){alert('Connection error');}
}
async function delNode(id){
    if(!confirm('Delete this reply?'))return;
    const fd=new FormData();fd.append('csrf_token',CS);fd.append('action','delete_node');fd.append('node_id',id);
    try{await fetch('chatbot_simple_builder.php?flow_id='+FID,{method:'POST',body:fd});location.reload();}catch(e){alert('Error');}
}
async function toggleStatus(){
    const fd=new FormData();fd.append('csrf_token',CS);fd.append('action','toggle_status');
    try{
        const r=await fetch('chatbot_simple_builder.php?flow_id='+FID,{method:'POST',body:fd});
        const j=await r.json();
        const tr=document.getElementById('swTrack');
        if(j.is_active){tr.classList.add('on');}else{tr.classList.remove('on');}
    }catch(e){}
}
function toast(msg){
    const d=document.createElement('div');d.className='fb-toast';d.textContent=msg;
    document.body.appendChild(d);setTimeout(()=>d.remove(),2500);
}
function esc(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)closeM();});
render();
</script>
<?php include '../includes/client_footer.php'; ?>
