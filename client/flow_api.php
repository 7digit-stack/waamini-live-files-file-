<?php
/**
 * Flow Builder API (JSON). Every action is scoped to the logged-in company.
 *   GET  ?action=load&flow_id=     flow + graph + lists for the property panel
 *   GET  ?action=logs&flow_id=     run log (optional session_id, contact search, limit)
 *   POST action=save               {flow_id, graph:{nodes,edges}, settings?:{name,priority,idle_minutes,max_steps}, view?}
 *   POST action=validate           {flow_id, graph}  -> issues, nothing is stored
 *   POST action=publish | unpublish {flow_id}
 * POST needs the CSRF token in the X-CSRF-Token header.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/flow/store.php';
require_once __DIR__ . '/../includes/flow/botreply.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fa_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!has_feature('feat_chatbot_builder')) fa_out(['ok' => false, 'error' => 'Flow Builder is not part of your plan'], 403);

$companyId = (int)$companyId;
flow_ensure_schema($conn);

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$body = [];
if ($isPost) {
    csrf_verify();
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) fa_out(['ok' => false, 'error' => 'Invalid request'], 400);
}
$action = (string)($isPost ? ($body['action'] ?? '') : ($_GET['action'] ?? ''));
$flowId = (int)($isPost ? ($body['flow_id'] ?? 0) : ($_GET['flow_id'] ?? 0));

$flow = $flowId > 0 ? flow_get($conn, $companyId, $flowId) : null;
if (!$flow) fa_out(['ok' => false, 'error' => 'Flow not found'], 404);

function fa_flow_meta(array $f): array
{
    return ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'status' => (string)$f['status'], 'priority' => (int)$f['priority'],
            'idle_minutes' => (int)$f['idle_minutes'], 'max_steps' => (int)$f['max_steps'], 'version' => (int)$f['graph_version'],
            'view' => (string)($f['canvas_view'] ?? ''), 'published_at' => $f['published_at'],
            'trigger_type' => (string)$f['trigger_type'], 'trigger_keyword' => (string)($f['trigger_keyword'] ?? '')];
}

try {
    switch ($action) {
        case 'load': {
            $templates = [];
            $st = $conn->prepare("SELECT id, name, language, body_text, wa_status FROM message_templates WHERE company_id = ? ORDER BY name ASC LIMIT 300");
            $st->bind_param('i', $companyId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) {
                $templates[] = ['id' => (int)$x['id'], 'name' => $x['name'], 'language' => $x['language'] ?: 'en', 'approved' => strtoupper((string)$x['wa_status']) === 'APPROVED',
                                'body' => mb_substr((string)$x['body_text'], 0, 200)];
            }
            $st->close();
            $agents = [];
            $st = $conn->prepare("SELECT id, name FROM users WHERE company_id = ? ORDER BY name ASC LIMIT 200");
            $st->bind_param('i', $companyId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $agents[] = ['id' => (int)$x['id'], 'name' => (string)$x['name']];
            $st->close();
            $tags = [];
            $st = $conn->prepare("SELECT tags FROM contacts WHERE company_id = ? AND tags IS NOT NULL AND tags <> '' LIMIT 1000");
            $st->bind_param('i', $companyId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) {
                foreach (explode(',', (string)$x['tags']) as $t) {
                    $t = trim($t);
                    if ($t !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}/', $t)) $tags[mb_strtolower($t)] = $t;
                }
            }
            $st->close();
            $g = flow_load_graph($conn, $companyId, $flowId);
            $catalog = flow_botreply_catalog($conn, $companyId);
            fa_out(['ok' => true, 'flow' => fa_flow_meta($flow), 'graph' => $g, 'meta' => [
                'bot_replies' => $catalog,
                'templates' => $templates, 'agents' => $agents, 'tags' => array_slice(array_values($tags), 0, 100), 'contact_fields' => flow_contact_fields(),
                'limits' => ['buttons' => FLOW_LIMIT_BUTTONS, 'button_title' => FLOW_LIMIT_BUTTON_TITLE, 'list_rows' => FLOW_LIMIT_LIST_ROWS, 'row_title' => FLOW_LIMIT_ROW_TITLE, 'body' => FLOW_LIMIT_BODY, 'text' => FLOW_LIMIT_TEXT],
            ]]);
        }

        case 'save': {
            $graph = is_array($body['graph'] ?? null) ? $body['graph'] : ['nodes' => [], 'edges' => []];
            if (isset($body['base_version']) && (int)$body['base_version'] > 0 && (int)$body['base_version'] !== (int)$flow['graph_version']) {
                fa_out(['ok' => false, 'error' => 'This flow was changed in another tab. Reload the page to continue.', 'conflict' => true, 'version' => (int)$flow['graph_version']], 409);
            }
            $view = isset($body['view']) ? mb_substr(preg_replace('/[^0-9.,\-]/', '', (string)$body['view']), 0, 80) : null;
            $res = flow_save_graph($conn, $companyId, $flowId, $graph, $view);
            if (!$res['ok']) fa_out($res, 500);
            if (is_array($body['settings'] ?? null)) {
                $s = $body['settings'];
                $name = trim((string)($s['name'] ?? ''));
                $prio = max(1, min(999, (int)($s['priority'] ?? $flow['priority'])));
                $idle = max(5, min(10080, (int)($s['idle_minutes'] ?? $flow['idle_minutes'])));
                $max = max(5, min(100, (int)($s['max_steps'] ?? $flow['max_steps'])));
                if ($name === '') $name = (string)$flow['name'];
                $name = mb_substr($name, 0, 150);
                $u = $conn->prepare("UPDATE chatbot_flows SET name = ?, priority = ?, idle_minutes = ?, max_steps = ? WHERE id = ? AND company_id = ?");
                $u->bind_param('siiiii', $name, $prio, $idle, $max, $flowId, $companyId);
                $u->execute();
                $u->close();
            }
            $res['saved_at'] = date('H:i:s');
            fa_out($res);
        }

        case 'botreplies':
            fa_out(['ok' => true, 'bot_replies' => flow_botreply_catalog($conn, $companyId)]);

        case 'validate': {
            $g = flow_clean_graph(is_array($body['graph'] ?? null) ? $body['graph'] : []);
            fa_out(['ok' => true, 'issues' => flow_validate($g['nodes'], $g['edges'])]);
        }

        case 'publish':
        case 'unpublish': {
            // publish checks the stored graph, so the canvas saves first
            $res = flow_set_status($conn, $companyId, $flowId, $action === 'publish' ? 'published' : 'draft');
            fa_out($res + ['flow' => fa_flow_meta(flow_get($conn, $companyId, $flowId))], $res['ok'] ? 200 : 422);
        }

        case 'logs': {
            $limit = max(10, min(500, (int)($_GET['limit'] ?? 150)));
            $sid = (int)($_GET['session_id'] ?? 0);
            $sql = "SELECT l.id, l.session_id, l.contact_id, c.name AS contact_name, c.phone AS contact_phone, l.node_id, l.node_type, l.event, l.result, l.detail, l.created_at
                    FROM flow_logs l LEFT JOIN contacts c ON c.id = l.contact_id AND c.company_id = l.company_id
                    WHERE l.company_id = ? AND l.flow_id = ?" . ($sid > 0 ? " AND l.session_id = ?" : "") . " ORDER BY l.id DESC LIMIT $limit";
            $st = $conn->prepare($sql);
            if ($sid > 0) $st->bind_param('iii', $companyId, $flowId, $sid); else $st->bind_param('ii', $companyId, $flowId);
            $st->execute();
            $rows = [];
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $rows[] = $x;
            $st->close();
            $sessions = [];
            $st = $conn->prepare("SELECT s.id, s.contact_id, c.name AS contact_name, c.phone AS contact_phone, s.status, s.end_reason, s.started_at, s.last_activity_at FROM flow_sessions s LEFT JOIN contacts c ON c.id = s.contact_id AND c.company_id = s.company_id WHERE s.company_id = ? AND s.flow_id = ? ORDER BY s.id DESC LIMIT 30");
            $st->bind_param('ii', $companyId, $flowId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $sessions[] = $x;
            $st->close();
            fa_out(['ok' => true, 'logs' => $rows, 'sessions' => $sessions]);
        }
    }
} catch (\Throwable $e) {
    error_log('[flow_api] ' . $e->getMessage());
    fa_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}

fa_out(['ok' => false, 'error' => 'Unknown action'], 400);
