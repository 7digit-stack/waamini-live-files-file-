<?php
/**
 * Flow Builder: tables, self-healing schema and the one-time conversion of the old node format.
 *
 *   chatbot_flows   (existing)  + status draft|published, priority, idle_minutes, max_steps, graph_version, published_at
 *   flow_nodes                  one row per canvas node  (flow_id, node_id, type, position, config JSON)
 *   flow_edges                  one row per connection   (flow_id, edge_id, source_node, source_port, target_node)
 *   flow_sessions               one active session per contact (variables JSON, current node, last activity)
 *   flow_jobs                   delayed continuations, run by cron_flow_worker.php (never sleep())
 *   flow_logs                   every step of every run, for the "Run log" screen
 *
 * Every table carries company_id and every query in the engine and API filters on it.
 * The old chatbot_nodes / chatbot_node_connections / chatbot_sessions tables are left untouched.
 */

function flow_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $col = static function (string $table, string $name) use ($conn): bool {
        $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($name) . "'");
        return (bool)($r && $r->num_rows > 0);
    };

    // chatbot_flows: new columns (the table already exists in every install)
    $adds = [
        'status'        => "ALTER TABLE chatbot_flows ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft'",
        'priority'      => "ALTER TABLE chatbot_flows ADD COLUMN priority INT NOT NULL DEFAULT 100",
        'idle_minutes'  => "ALTER TABLE chatbot_flows ADD COLUMN idle_minutes INT NOT NULL DEFAULT 120",
        'max_steps'     => "ALTER TABLE chatbot_flows ADD COLUMN max_steps INT NOT NULL DEFAULT 25",
        'graph_version' => "ALTER TABLE chatbot_flows ADD COLUMN graph_version INT NOT NULL DEFAULT 1",
        'published_at'  => "ALTER TABLE chatbot_flows ADD COLUMN published_at DATETIME NULL",
        'canvas_view'   => "ALTER TABLE chatbot_flows ADD COLUMN canvas_view VARCHAR(80) NULL",
    ];
    $migrateFlag = false;
    foreach ($adds as $name => $sql) {
        if (!$col('chatbot_flows', $name)) {
            $conn->query($sql);
            if ($name === 'status') $migrateFlag = true;
        }
    }
    $triggerType = $conn->query("SHOW COLUMNS FROM chatbot_flows LIKE 'trigger_type'");
    $triggerTypeRow = $triggerType ? $triggerType->fetch_assoc() : null;
    if (is_array($triggerTypeRow) && !str_contains((string)($triggerTypeRow['Type'] ?? ''), "'from_ad'")) {
        $conn->query("ALTER TABLE chatbot_flows MODIFY trigger_type ENUM('keyword','manual','webhook','any_message','new_contact','from_ad') NULL DEFAULT 'keyword'");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS flow_nodes (
        flow_id INT NOT NULL,
        node_id VARCHAR(40) NOT NULL,
        company_id INT NOT NULL,
        type VARCHAR(30) NOT NULL,
        pos_x INT NOT NULL DEFAULT 0,
        pos_y INT NOT NULL DEFAULT 0,
        config LONGTEXT NULL,
        PRIMARY KEY (flow_id, node_id),
        KEY idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS flow_edges (
        flow_id INT NOT NULL,
        edge_id VARCHAR(40) NOT NULL,
        company_id INT NOT NULL,
        source_node VARCHAR(40) NOT NULL,
        source_port VARCHAR(60) NOT NULL,
        target_node VARCHAR(40) NOT NULL,
        PRIMARY KEY (flow_id, edge_id),
        KEY idx_source (flow_id, source_node, source_port),
        KEY idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // active_key is 1 while a session is open and NULL afterwards: the unique key therefore allows
    // exactly one open session per contact and any number of finished ones.
    $conn->query("CREATE TABLE IF NOT EXISTS flow_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        flow_id INT NOT NULL,
        current_node_id VARCHAR(40) NULL,
        waiting_for VARCHAR(20) NOT NULL DEFAULT '',
        status ENUM('active','waiting','completed','expired','error') NOT NULL DEFAULT 'active',
        active_key TINYINT NULL DEFAULT 1,
        variables LONGTEXT NULL,
        retries INT NOT NULL DEFAULT 0,
        end_reason VARCHAR(40) NULL,
        started_at DATETIME NOT NULL,
        last_activity_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        UNIQUE KEY uq_open_session (company_id, contact_id, active_key),
        KEY idx_flow (flow_id),
        KEY idx_activity (status, last_activity_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS flow_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        session_id INT NOT NULL,
        flow_id INT NOT NULL,
        contact_id INT NOT NULL,
        node_id VARCHAR(40) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'delay',
        run_at DATETIME NOT NULL,
        status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_due (status, run_at),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS flow_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        flow_id INT NOT NULL,
        session_id INT NOT NULL DEFAULT 0,
        contact_id INT NOT NULL DEFAULT 0,
        node_id VARCHAR(40) NULL,
        node_type VARCHAR(30) NULL,
        event VARCHAR(30) NOT NULL,
        result VARCHAR(10) NOT NULL DEFAULT 'ok',
        detail TEXT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_company_time (company_id, created_at),
        KEY idx_flow (flow_id, created_at),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$col('flow_jobs', 'kind')) $conn->query("ALTER TABLE flow_jobs ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'delay' AFTER node_id");

    if ($migrateFlag) {
        try { flow_migrate_legacy($conn); } catch (\Throwable $e) { error_log('[flow] legacy migration: ' . $e->getMessage()); }
    }
}

/** Short unique id for nodes / edges. */
function flow_new_id(string $prefix = 'n'): string
{
    return $prefix . '_' . substr(bin2hex(random_bytes(5)), 0, 8);
}

/**
 * Convert every flow that has no graph yet from the old format (chatbot_nodes + chatbot_node_connections)
 * to flow_nodes + flow_edges. Nothing is deleted: the old rows stay where they are.
 * A flow becomes "published" only if it was active AND had nodes, so behaviour is unchanged.
 *
 * @return array<string,int> counters (flows, nodes, edges)
 */
function flow_migrate_legacy(mysqli $conn): array
{
    $out = ['flows' => 0, 'nodes' => 0, 'edges' => 0];
    $flows = $conn->query("SELECT * FROM chatbot_flows ORDER BY id");
    while ($flows && ($f = $flows->fetch_assoc())) {
        $flowId = (int)$f['id'];
        $companyId = (int)$f['company_id'];
        $has = $conn->query("SELECT 1 FROM flow_nodes WHERE flow_id = $flowId LIMIT 1");
        if ($has && $has->num_rows > 0) continue;

        $nodes = [];
        $edges = [];

        // trigger node from the old trigger columns
        $trigId = 'trigger';
        $type = (string)($f['trigger_type'] ?? 'keyword');
        $cfg = ['mode' => in_array($type, ['new_contact', 'any_message', 'from_ad'], true) ? $type : 'keyword', 'rules' => []];
        if ($cfg['mode'] === 'keyword') {
            foreach (array_filter(array_map('trim', explode(',', (string)($f['trigger_keyword'] ?? '')))) as $kw) {
                $cfg['rules'][] = ['match' => 'contains', 'value' => $kw];
            }
        }
        $nodes[] = ['id' => $trigId, 'type' => 'trigger', 'x' => 80, 'y' => 160, 'config' => $cfg];

        // old nodes
        $old = [];
        $r = $conn->query("SELECT * FROM chatbot_nodes WHERE flow_id = $flowId ORDER BY (node_type = 'start') DESC, id ASC");
        while ($r && ($n = $r->fetch_assoc())) { $old[(int)$n['id']] = $n; }
        $map = [];
        $i = 0;
        foreach ($old as $oid => $n) { $map[$oid] = 'n' . $oid; }
        foreach ($old as $oid => $n) {
            $i++;
            $nodeType = (string)$n['node_type'];
            $text = (string)($n['message_text'] ?? '');
            $x = (int)$n['position_x']; $y = (int)$n['position_y'];
            if ($x === 0 && $y === 0) { $x = 80 + 320 * $i; $y = 160; }
            $id = $map[$oid];
            if ($nodeType === 'button') {
                $buttons = [];
                $opts = json_decode((string)($n['button_options'] ?? ''), true) ?: [];
                foreach ($opts as $k => $o) {
                    $title = trim((string)($o['text'] ?? $o['label'] ?? $o['title'] ?? ''));
                    if ($title === '') continue;
                    $buttons[] = ['id' => 'b' . ($k + 1), 'title' => mb_substr($title, 0, 20)];
                    if (count($buttons) === 3) break;
                }
                $nodes[] = ['id' => $id, 'type' => 'send', 'x' => $x, 'y' => $y, 'config' => ['kind' => 'buttons', 'text' => $text, 'buttons' => $buttons]];
                $cr = $conn->query("SELECT trigger_value, target_node_id FROM chatbot_node_connections WHERE node_id = $oid");
                while ($cr && ($c = $cr->fetch_assoc())) {
                    foreach ($buttons as $b) {
                        if (mb_strtolower($b['title']) === mb_strtolower(trim((string)$c['trigger_value'])) && isset($map[(int)$c['target_node_id']])) {
                            $edges[] = ['source' => $id, 'port' => $b['id'], 'target' => $map[(int)$c['target_node_id']]];
                        }
                    }
                }
            } elseif ($nodeType === 'question') {
                $save = trim((string)($n['save_response_as'] ?? '')) ?: 'answer';
                $nodes[] = ['id' => $id, 'type' => 'question', 'x' => $x, 'y' => $y, 'config' => ['text' => $text, 'save_as' => preg_replace('/[^a-z0-9_]/i', '_', $save), 'validate' => 'none', 'retry_text' => '', 'max_retries' => 1]];
            } elseif ($nodeType === 'end') {
                $nodes[] = ['id' => $id, 'type' => 'end', 'x' => $x, 'y' => $y, 'config' => ['message' => $text]];
            } elseif ($nodeType === 'condition') {
                $nodes[] = ['id' => $id, 'type' => 'condition', 'x' => $x, 'y' => $y, 'config' => ['source' => 'variable', 'name' => '', 'operator' => 'equals', 'value' => '']];
            } else {
                // message / template / start / commerce / list: kept as a plain message so no text is lost
                $kind = ($nodeType === 'template') ? 'template' : 'text';
                $conf = ['kind' => $kind, 'text' => $text];
                if ($kind === 'template') { $conf['template_id'] = 0; $conf['template_name'] = ''; $conf['language'] = 'en'; }
                $nodes[] = ['id' => $id, 'type' => 'send', 'x' => $x, 'y' => $y, 'config' => $conf];
            }
            if ($nodeType !== 'button' && !empty($n['next_node_id']) && isset($map[(int)$n['next_node_id']])) {
                $port = ($nodeType === 'question') ? 'next' : 'next';
                $edges[] = ['source' => $id, 'port' => $port, 'target' => $map[(int)$n['next_node_id']]];
            }
        }
        if ($old) {
            $first = array_key_first($old);
            $edges[] = ['source' => $trigId, 'port' => 'out', 'target' => $map[$first]];
        }

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("INSERT INTO flow_nodes (flow_id, node_id, company_id, type, pos_x, pos_y, config) VALUES (?,?,?,?,?,?,?)");
            foreach ($nodes as $n) {
                $json = json_encode($n['config'], JSON_UNESCAPED_UNICODE);
                $ins->bind_param('isisiis', $flowId, $n['id'], $companyId, $n['type'], $n['x'], $n['y'], $json);
                $ins->execute();
                $out['nodes']++;
            }
            $ie = $conn->prepare("INSERT INTO flow_edges (flow_id, edge_id, company_id, source_node, source_port, target_node) VALUES (?,?,?,?,?,?)");
            foreach ($edges as $k => $e) {
                $eid = 'e' . ($k + 1);
                $ie->bind_param('isisss', $flowId, $eid, $companyId, $e['source'], $e['port'], $e['target']);
                $ie->execute();
                $out['edges']++;
            }
            $publish = ((int)$f['is_active'] === 1 && $old) ? 'published' : 'draft';
            $up = $conn->prepare("UPDATE chatbot_flows SET status = ?, is_active = ?, published_at = IF(? = 'published', NOW(), NULL) WHERE id = ?");
            $act = $publish === 'published' ? 1 : 0;
            $up->bind_param('sisi', $publish, $act, $publish, $flowId);
            $up->execute();
            $conn->commit();
            $out['flows']++;
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('[flow] migrate flow ' . $flowId . ': ' . $e->getMessage());
        }
    }
    return $out;
}
