<?php
include '../includes/client_auth_check.php';
if (!has_feature('feat_chatbot_builder')) {
    header("Location: dashboard.php");
    exit();
}

$flowId = intval($_GET['flow_id'] ?? 0);
if (!$flowId) { header("Location: chatbot_flows.php"); exit(); }

$stmt_fl = $conn->prepare("SELECT * FROM chatbot_flows WHERE id=? AND company_id=?");
$stmt_fl->bind_param("ii", $flowId, $companyId);
$stmt_fl->execute();
$flow = $stmt_fl->get_result()->fetch_assoc();
if (!$flow) { header("Location: chatbot_flows.php"); exit(); }

// Fetch approved templates for selection
$st_tpl = $conn->prepare("SELECT id, name FROM message_templates WHERE company_id=? AND (wa_status='APPROVED' OR wa_status='PENDING') ORDER BY name ASC");
$st_tpl->bind_param("i", $companyId);
$st_tpl->execute();
$tplRes = $st_tpl->get_result();
$availableTemplates = [];
while ($row = $tplRes->fetch_assoc()) {
    $availableTemplates[] = $row;
}

$pageTitle = 'Visual Architect: ' . htmlspecialchars($flow['name']);
$currentPage = 'chatbot_flows';
include '../includes/client_header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">
<script src="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.js"></script>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap">

<style>
:root {
    --fb-bg: #080c14;
    --fb-card: rgba(22, 24, 34, 0.85);
    --fb-accent: #00b894;
    --fb-border: rgba(255, 255, 255, 0.08);
}

body { font-family: 'Outfit', sans-serif; background: var(--fb-bg) !important; margin: 0; overflow: hidden; }

.builder-container { display: flex; height: calc(100vh - 70px); width: 100%; position: relative; }

#drawflow {
    flex: 1; height: 100%; background: var(--fb-bg);
    background-image: radial-gradient(rgba(255,255,255,0.05) 1px, transparent 1px);
    background-size: 30px 30px;
}

.sidebar {
    width: 320px; background: var(--fb-card); border-right: 1px solid var(--fb-border);
    backdrop-filter: blur(25px); padding: 30px; display: flex; flex-direction: column; gap: 20px; z-index: 10;
}

.node-item {
    padding: 15px; background: rgba(255,255,255,0.03); border: 1px solid var(--fb-border);
    border-radius: 18px; cursor: grab; transition: 0.3s; display: flex; align-items: center; gap: 15px;
}
.node-item:hover { border-color: var(--fb-accent); background: rgba(0,184,148,0.05); transform: translateX(5px); }

.drawflow .drawflow-node {
    background: var(--fb-card); border: 1px solid var(--fb-border); border-radius: 20px;
    color: #fff; width: 240px !important; padding: 0 !important; backdrop-filter: blur(15px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}
.drawflow .drawflow-node.selected { border-color: var(--fb-accent); box-shadow: 0 0 20px rgba(0,184,148,0.2); }

.node-header {
    padding: 12px 18px; border-bottom: 1px solid var(--fb-border); font-weight: 800; font-size: 11px;
    text-transform: uppercase; border-radius: 20px 20px 0 0; letter-spacing: 1px;
}
.node-body { padding: 20px; font-size: 13px; }

.df-input {
    width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--fb-border);
    color: #fff; padding: 8px 12px; border-radius: 10px; font-size: 12px; margin-top: 5px;
}

.control-panel {
    position: absolute; bottom: 30px; right: 30px; display: flex; gap: 15px; z-index: 100;
}

.btn-premium {
    background: var(--fb-accent); color: #080c14; border: none; padding: 15px 35px;
    border-radius: 18px; font-weight: 800; cursor: pointer; transition: 0.3s;
    box-shadow: 0 10px 30px rgba(0,184,148,0.3); display: flex; align-items: center; gap: 10px;
}
.btn-premium:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(0,184,148,0.4); }

.drawflow-delete {
    background: #ff7675 !important; border: 2px solid #080c14 !important;
}
</style>

<div class="builder-container">
    <div class="sidebar">
        <div style="margin-bottom: 30px; padding: 25px; background: linear-gradient(135deg, rgba(0, 184, 148, 0.15), rgba(0, 184, 148, 0.05)); border: 1px solid rgba(0, 184, 148, 0.3); border-radius: 28px; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div style="position: absolute; top: -15px; right: -15px; opacity: 0.1;"><svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></div>
            <div style="font-size: 11px; font-weight: 800; color: var(--fb-accent); text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px;">Active Entry Trigger</div>
            <div style="font-size: 22px; font-weight: 800; color: #fff; margin-bottom: 6px; letter-spacing: -0.5px;"><?php echo htmlspecialchars($flow['name']); ?></div>
            <div style="font-size: 13px; color: #94a3b8; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <span style="padding: 4px 10px; background: var(--fb-accent); color: #000; border-radius: 8px; font-size: 10px; font-weight: 900;"><?php echo strtoupper($flow['trigger_type']); ?></span>
                <span style="color: #fff; text-decoration: underline; text-underline-offset: 4px;"><?php echo htmlspecialchars($flow['trigger_keyword'] ?: 'Any Message'); ?></span>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
            <div style="width:12px; height:12px; background:var(--fb-accent); border-radius:50%; box-shadow:0 0 10px var(--fb-accent);"></div>
            <h3 style="margin:0; font-size:20px; font-weight:800; letter-spacing:-0.5px;">Neural Architect</h3>
        </div>
        <p style="font-size:13px; color:#94a3b8; line-height:1.6;">Drag components onto the canvas to construct your autonomous customer journey.</p>
        
        <div style="display:flex; flex-direction:column; gap:12px; margin-top:10px;">
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="start">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Entry Point</div>
                    <div style="font-size:11px; color:#94a3b8;">Where the flow begins</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="message">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Message</div>
                    <div style="font-size:11px; color:#94a3b8;">Outbound text bubble</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="question">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Inquiry</div>
                    <div style="font-size:11px; color:#94a3b8;">Wait for user response</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="condition">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Logic Gate</div>
                    <div style="font-size:11px; color:#94a3b8;">Split flow by conditions</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="button">
                <span style="font-size:24px;"><svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#1e293b"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Button Group</div>
                    <div style="font-size:11px; color:#94a3b8;">Interactive quick replies</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="template">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Template</div>
                    <div style="font-size:11px; color:#94a3b8;">Pre-approved WA Template</div>
                </div>
            </div>
            <div class="node-item" draggable="true" ondragstart="drag(event)" data-node="end">
                <span style="font-size:24px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg></span>
                <div>
                    <div style="font-weight:700; font-size:14px;">Terminal</div>
                    <div style="font-size:11px; color:#94a3b8;">End session</div>
                </div>
            </div>
        </div>

        <div style="margin-top:auto; padding:20px; background:rgba(255,255,255,0.02); border-radius:20px; border:1px solid var(--fb-border);">
            <div style="font-size:11px; font-weight:800; color:var(--fb-accent); margin-bottom:8px;">PRO TIP</div>
            <p style="font-size:12px; color:#94a3b8; margin:0; line-height:1.5;">Connect nodes to define the sequence. Use 'Inquiry' nodes to gather lead data.</p>
        </div>
    </div>

    <div id="drawflow"></div>
    
    <div class="control-panel">
        <button class="btn-premium" style="background:rgba(255,255,255,0.05); color:#fff; box-shadow:none;" onclick="location.reload()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Reset View</button>
        <button class="btn-premium" onclick="saveNeuralFlow()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Deploy Neural Flow</button>
    </div>
</div>

<script>
    const id = document.getElementById("drawflow");
    const editor = new Drawflow(id);
    editor.start();

    const availableTemplates = <?php echo json_encode($availableTemplates); ?>;

    // Node HTML Generators
    function getNodeHTML(type, data = {}) {
        const colors = {
            start: '#00b894',
            message: 'rgba(0, 184, 148, 0.2)',
            question: 'rgba(162, 155, 254, 0.2)',
            condition: 'rgba(253, 203, 110, 0.2)',
            button: 'rgba(0, 206, 201, 0.2)',
            template: 'rgba(255, 159, 67, 0.2)',
            end: 'rgba(255, 118, 117, 0.2)'
        };
        const label = data.label || '';
        const msg = data.message || '';
        const headerTitle = type === 'start' ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> FLOW TRIGGER' : type.toUpperCase();
        const triggerKeyword = <?php echo json_encode($flow['trigger_keyword'] ?: 'Any Message'); ?>;
        
        let extraHTML = '';
        if (type === 'button') {
            extraHTML = `
                <div style="font-size:10px; color:#94a3b8; margin:12px 0 4px 0; font-weight:700;">BUTTONS (MAX 3)</div>
                <input type="text" df-btn1 class="df-input" style="margin-bottom:5px;" value="${data.btn1 || ''}" placeholder="Button 1 Label">
                <input type="text" df-btn2 class="df-input" style="margin-bottom:5px;" value="${data.btn2 || ''}" placeholder="Button 2 Label">
                <input type="text" df-btn3 class="df-input" value="${data.btn3 || ''}" placeholder="Button 3 Label">
            `;
        } else if (type === 'template') {
            let options = '<option value="">-- Select Template --</option>';
            availableTemplates.forEach(tpl => {
                const selected = data.template_id == tpl.id ? 'selected' : '';
                options += `<option value="${tpl.id}" ${selected}>${tpl.name}</option>`;
            });
            extraHTML = `
                <div style="font-size:10px; color:#94a3b8; margin:12px 0 4px 0; font-weight:700;">SELECT TEMPLATE</div>
                <select df-template_id class="df-input" style="height:40px;">
                    ${options}
                </select>
            `;
        }

        return `
            <div>
                <div class="node-header" style="background: ${colors[type] || 'rgba(255,255,255,0.1)'}; color: ${type==='start'?'#000':'#fff'}">${headerTitle}</div>
                <div class="node-body">
                    ${type === 'start' ? `
                        <div style="font-size:10px; color:#94a3b8; margin-bottom:4px; font-weight:700;">LISTENING FOR</div>
                        <div style="background:rgba(0,184,148,0.1); padding:10px; border-radius:10px; border:1px solid rgba(0,184,148,0.2); margin-bottom:15px; font-weight:800; font-size:13px; color:#00b894;">
                            ${triggerKeyword.toUpperCase()}
                        </div>
                        <input type="hidden" df-label value="Trigger: ${triggerKeyword}">
                    ` : `
                        <div style="font-size:10px; color:#94a3b8; margin-bottom:4px; font-weight:700;">NODE LABEL</div>
                        <input type="text" df-label class="df-input" value="${label}" placeholder="e.g. Menu">
                    `}
                    
                    <div style="font-size:10px; color:#94a3b8; margin:12px 0 4px 0; font-weight:700;">GREETING MESSAGE</div>
                    <textarea df-message class="df-input" style="height:60px; resize:none;" placeholder="What should the bot say first?">${msg}</textarea>
                    ${extraHTML}
                </div>
            </div>
        `;
    }

    // Load existing flow data
    const flowData = <?php echo $flow['flow_data'] ?: 'null'; ?>;
    if (flowData) {
        editor.import(flowData);
        // Ensure inputs are synced with data after import
        Object.keys(flowData.drawflow.Home.data).forEach(id => {
            const node = flowData.drawflow.Home.data[id];
            const nodeEl = document.getElementById('node-'+id);
            if(nodeEl && node.data) {
                if(nodeEl.querySelector('[df-label]')) nodeEl.querySelector('[df-label]').value = node.data.label || '';
                if(nodeEl.querySelector('[df-message]')) nodeEl.querySelector('[df-message]').value = node.data.message || '';
                if(nodeEl.querySelector('[df-btn1]')) nodeEl.querySelector('[df-btn1]').value = node.data.btn1 || '';
                if(nodeEl.querySelector('[df-btn2]')) nodeEl.querySelector('[df-btn2]').value = node.data.btn2 || '';
                if(nodeEl.querySelector('[df-btn3]')) nodeEl.querySelector('[df-btn3]').value = node.data.btn3 || '';
                if(nodeEl.querySelector('[df-template_id]')) nodeEl.querySelector('[df-template_id]').value = node.data.template_id || '';
            }
        });
    } else {
        // Auto-create Start node for fresh flows
        const html = getNodeHTML('start');
        const triggerLabel = <?php echo json_encode($flow['trigger_keyword'] ?: 'Any Message'); ?>;
        editor.addNode('start', 0, 1, 150, 100, 'start', {label: 'Trigger: ' + triggerLabel, message: 'Hi! How can I help you today?'}, html);
    }

    // Drag & Drop Handling
    function drag(ev) {
        ev.dataTransfer.setData("node", ev.target.getAttribute('data-node'));
    }

    id.addEventListener('drop', function(ev) {
        ev.preventDefault();
        const type = ev.dataTransfer.getData("node");
        const x = ev.clientX;
        const y = ev.clientY;
        const html = getNodeHTML(type);
        const inputs = type === 'start' ? 0 : 1;
        const outputs = type === 'button' ? 3 : 1;
        editor.addNode(type, inputs, outputs, x - 300, y - 100, type, {label: '', message: ''}, html);
    });

    id.addEventListener('dragover', (ev) => ev.preventDefault());

    async function saveNeuralFlow() {
        const data = editor.export();
        
        Object.keys(data.drawflow.Home.data).forEach(id => {
            const nodeEl = document.getElementById('node-'+id);
            if(nodeEl) {
                const label = nodeEl.querySelector('[df-label]').value;
                const msg = nodeEl.querySelector('[df-message]').value;
                const nodeData = { label, message: msg };
                
                if (nodeEl.querySelector('[df-btn1]')) {
                    nodeData.btn1 = nodeEl.querySelector('[df-btn1]').value;
                    nodeData.btn2 = nodeEl.querySelector('[df-btn2]').value;
                    nodeData.btn3 = nodeEl.querySelector('[df-btn3]').value;
                }
                if (nodeEl.querySelector('[df-template_id]')) {
                    nodeData.template_id = nodeEl.querySelector('[df-template_id]').value;
                }
                data.drawflow.Home.data[id].data = nodeData;
            }
        });

        try {
            const response = await fetch('api_save_chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    flow_id: <?php echo $flowId; ?>,
                    drawflow_data: data,
                    csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                })
            });
            const result = await response.json();
            if (result.success) {
                alert('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Neural Flow Deployed Successfully!');
            } else {
                alert('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Deployment Error: ' + result.error);
            }
        } catch (e) {
            alert('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Connection failed.');
        }
    }
</script>

<?php include '../includes/client_footer.php'; ?>
