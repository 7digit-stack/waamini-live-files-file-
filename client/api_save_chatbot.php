<?php
include '../includes/client_auth_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $flowId = intval($data['flow_id'] ?? 0);
    $drawflowData = $data['drawflow_data'] ?? null;

    if (!$flowId || !$drawflowData) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit();
    }

    // 1. Save the raw Drawflow JSON for layout persistence
    $stmt = $conn->prepare("UPDATE chatbot_flows SET flow_data = ? WHERE id = ? AND company_id = ?");
    $jsonStr = json_encode($drawflowData);
    $stmt->bind_param("sii", $jsonStr, $flowId, $companyId);
    $stmt->execute();

    // Sync nodes for the execution engine
    $conn->query("DELETE FROM chatbot_nodes WHERE flow_id = $flowId");
    $conn->query("DELETE FROM chatbot_node_connections WHERE node_id IN (SELECT id FROM chatbot_nodes WHERE flow_id = $flowId)");

    $nodes = $drawflowData['drawflow']['Home']['data'];
    $nodeMap = []; // Maps Drawflow ID to Database ID

    // First pass: Create nodes
    foreach ($nodes as $dfId => $n) {
        $type = $n['name'];
        $posX = (int)$n['pos_x'];
        $posY = (int)$n['pos_y'];
        $label = sanitize($n['data']['label'] ?? $type);
        $msg = sanitize($n['data']['message'] ?? '');
        
        $btnOptions = null;
        $tplId = null;

        if ($type === 'button') {
            $btns = [];
            for ($i=1; $i<=3; $i++) {
                if (!empty($n['data']['btn'.$i])) $btns[] = ['text' => $n['data']['btn'.$i]];
            }
            $btnOptions = json_encode($btns);
        } elseif ($type === 'template') {
            $tplId = !empty($n['data']['template_id']) ? intval($n['data']['template_id']) : null;
        }

        $stmt_n = $conn->prepare("INSERT INTO chatbot_nodes (flow_id, node_type, label, message_text, position_x, position_y, button_options, template_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_n->bind_param("isssiisi", $flowId, $type, $label, $msg, $posX, $posY, $btnOptions, $tplId);
        $stmt_n->execute();
        $nodeMap[$dfId] = $stmt_n->insert_id;
    }

    // Second pass: Create connections
    foreach ($nodes as $dfId => $n) {
        $dbId = $nodeMap[$dfId];
        $type = $n['name'];
        $outputs = $n['outputs'];
        
        foreach ($outputs as $outKey => $outData) {
            if (isset($outData['connections'][0])) {
                $targetDfId = $outData['connections'][0]['node'];
                $targetId = $nodeMap[$targetDfId] ?? null;
                
                if ($targetId) {
                    if ($outKey === 'output_1' && $type !== 'button') {
                        // Linear next node
                        $conn->query("UPDATE chatbot_nodes SET next_node_id = $targetId WHERE id = $dbId");
                    } else if ($type === 'button') {
                        // Branching connection
                        $btnIdx = str_replace('output_', '', $outKey);
                        $triggerVal = $n['data']['btn'.$btnIdx] ?? null;
                        if ($triggerVal) {
                            $stmt_conn = $conn->prepare("INSERT INTO chatbot_node_connections (node_id, trigger_value, target_node_id) VALUES (?, ?, ?)");
                            $stmt_conn->bind_param("isi", $dbId, $triggerVal, $targetId);
                            $stmt_conn->execute();
                        }
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true]);
    exit();
}
