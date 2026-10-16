<?php
/**
 * Flow Builder: load / save / publish one flow graph. Used by client/flow_api.php and the tests.
 * All queries are scoped by company_id; the graph is replaced inside one transaction.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/nodes.php';

function flow_get(mysqli $conn, int $companyId, int $flowId): ?array
{
    flow_ensure_schema($conn);
    $st = $conn->prepare("SELECT * FROM chatbot_flows WHERE id = ? AND company_id = ? LIMIT 1");
    $st->bind_param('ii', $flowId, $companyId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

/** @return array{nodes:array,edges:array} in the shape the canvas uses */
function flow_load_graph(mysqli $conn, int $companyId, int $flowId): array
{
    $nodes = []; $edges = [];
    $st = $conn->prepare("SELECT node_id, type, pos_x, pos_y, config FROM flow_nodes WHERE flow_id = ? AND company_id = ? ORDER BY pos_x, pos_y");
    $st->bind_param('ii', $flowId, $companyId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $nodes[] = ['id' => $x['node_id'], 'type' => $x['type'], 'position' => ['x' => (int)$x['pos_x'], 'y' => (int)$x['pos_y']], 'config' => json_decode((string)$x['config'], true) ?: new stdClass()];
    }
    $st->close();
    $st = $conn->prepare("SELECT edge_id, source_node, source_port, target_node FROM flow_edges WHERE flow_id = ? AND company_id = ?");
    $st->bind_param('ii', $flowId, $companyId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $edges[] = ['id' => $x['edge_id'], 'source_node' => $x['source_node'], 'source_port' => $x['source_port'], 'target_node' => $x['target_node']];
    }
    $st->close();
    return ['nodes' => $nodes, 'edges' => $edges];
}

/**
 * Replace the stored graph. Returns the cleaned graph counters and the validation issues.
 * A published flow stays published: the new graph is live as soon as it is saved, so it must still pass validation;
 * if it does not, the flow is moved back to draft.
 *
 * @return array{ok:bool,error?:string,dropped:int,issues:array,status:string,version:int}
 */
function flow_save_graph(mysqli $conn, int $companyId, int $flowId, array $graph, ?string $canvasView = null): array
{
    $flow = flow_get($conn, $companyId, $flowId);
    if (!$flow) return ['ok' => false, 'error' => 'Flow not found', 'dropped' => 0, 'issues' => [], 'status' => 'draft', 'version' => 0];

    $clean = flow_clean_graph($graph);
    $conn->begin_transaction();
    try {
        $d = $conn->prepare("DELETE FROM flow_edges WHERE flow_id = ? AND company_id = ?");
        $d->bind_param('ii', $flowId, $companyId); $d->execute(); $d->close();
        $d = $conn->prepare("DELETE FROM flow_nodes WHERE flow_id = ? AND company_id = ?");
        $d->bind_param('ii', $flowId, $companyId); $d->execute(); $d->close();

        $ins = $conn->prepare("INSERT INTO flow_nodes (flow_id, node_id, company_id, type, pos_x, pos_y, config) VALUES (?,?,?,?,?,?,?)");
        foreach ($clean['nodes'] as $n) {
            $json = json_encode($n['config'], JSON_UNESCAPED_UNICODE);
            $ins->bind_param('isisiis', $flowId, $n['id'], $companyId, $n['type'], $n['x'], $n['y'], $json);
            $ins->execute();
        }
        $ins->close();
        $ie = $conn->prepare("INSERT INTO flow_edges (flow_id, edge_id, company_id, source_node, source_port, target_node) VALUES (?,?,?,?,?,?)");
        foreach ($clean['edges'] as $e) {
            $ie->bind_param('isisss', $flowId, $e['id'], $companyId, $e['source_node'], $e['source_port'], $e['target_node']);
            $ie->execute();
        }
        $ie->close();

        // trigger summary kept on chatbot_flows so the flow list and the old columns stay meaningful
        $trigType = 'keyword'; $trigKw = '';
        foreach ($clean['nodes'] as $n) {
            if ($n['type'] !== 'trigger') continue;
            $m = $n['config']['mode'] ?? 'keyword';
            $trigType = in_array($m, ['new_contact', 'any_message', 'from_ad'], true) ? $m : 'keyword';
            if ($m === 'keyword') $trigKw = mb_substr(implode(', ', array_column($n['config']['rules'] ?? [], 'value')), 0, 100);
            break;
        }
        $issues = flow_validate($clean['nodes'], $clean['edges']);
        $hasError = false;
        foreach ($issues as $i) { if ($i['level'] === 'error') { $hasError = true; break; } }
        $status = (string)$flow['status'];
        if ($status === 'published' && $hasError) $status = 'draft';
        $active = $status === 'published' ? 1 : 0;

        $u = $conn->prepare("UPDATE chatbot_flows SET graph_version = graph_version + 1, trigger_type = ?, trigger_keyword = ?, status = ?, is_active = ?, canvas_view = COALESCE(?, canvas_view), updated_at = NOW() WHERE id = ? AND company_id = ?");
        $u->bind_param('sssisii', $trigType, $trigKw, $status, $active, $canvasView, $flowId, $companyId);
        $u->execute();
        $u->close();
        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('[flow] save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save the flow', 'dropped' => 0, 'issues' => [], 'status' => (string)$flow['status'], 'version' => (int)$flow['graph_version']];
    }
    $fresh = flow_get($conn, $companyId, $flowId);
    return ['ok' => true, 'dropped' => $clean['dropped'], 'issues' => $issues, 'status' => $status, 'version' => (int)$fresh['graph_version']];
}

/** @return array{ok:bool,issues:array,status:string} */
function flow_set_status(mysqli $conn, int $companyId, int $flowId, string $to): array
{
    $flow = flow_get($conn, $companyId, $flowId);
    if (!$flow) return ['ok' => false, 'issues' => [['node_id' => null, 'level' => 'error', 'message' => 'Flow not found']], 'status' => 'draft'];
    $g = flow_load_graph($conn, $companyId, $flowId);
    $nodes = array_map(static fn($n) => ['id' => $n['id'], 'type' => $n['type'], 'config' => (array)$n['config']], $g['nodes']);
    $issues = flow_validate($nodes, $g['edges']);
    if ($to === 'published') {
        foreach ($issues as $i) { if ($i['level'] === 'error') return ['ok' => false, 'issues' => $issues, 'status' => (string)$flow['status']]; }
    }
    $active = $to === 'published' ? 1 : 0;
    $u = $conn->prepare("UPDATE chatbot_flows SET status = ?, is_active = ?, published_at = IF(? = 'published', NOW(), published_at), updated_at = NOW() WHERE id = ? AND company_id = ?");
    $u->bind_param('sisii', $to, $active, $to, $flowId, $companyId);
    $u->execute();
    $u->close();
    return ['ok' => true, 'issues' => $issues, 'status' => $to];
}
