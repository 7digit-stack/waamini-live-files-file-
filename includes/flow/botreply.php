<?php
/**
 * Flow Builder <-> Bot Replies sync.
 *
 * A Bot Reply with buttons (client/bot_replies.php) stores its buttons in bot_replies.interactive_data as
 * {"buttons":[{"id":"btn_1_a1b2c3","text":"BOOK AN APPOINTMENT"}, ...]}. The button id is what WhatsApp sends back when the
 * customer taps it, so it is the stable key. This file lets the Flow Builder use those buttons:
 *
 *  - catalog       every active Bot Reply with buttons (id, keyword, text, footer, buttons)
 *  - linked node   a Send > Buttons node with config.bot_reply_id mirrors that Bot Reply: text, footer and the button labels
 *                  follow the Bot Reply; the canvas ports keep the same button ids, so connections survive a label rename
 *  - push          called after a Bot Reply is saved: every linked node in every flow of the company is refreshed
 *  - trigger       a Trigger in "button reply" mode can pick buttons from the catalog (matches by button id)
 */

function flow_botreply_catalog(mysqli $conn, int $companyId): array
{
    $out = [];
    $st = $conn->prepare("SELECT id, keyword, reply_content, footer_text, interactive_data FROM bot_replies WHERE company_id = ? AND reply_type = 'interactive' AND is_active = 1 ORDER BY id DESC LIMIT 200");
    if (!$st) return $out;
    $st->bind_param('i', $companyId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $d = json_decode((string)$x['interactive_data'], true);
        $btns = [];
        foreach ((array)($d['buttons'] ?? []) as $b) {
            $id = (string)($b['id'] ?? ''); $title = trim((string)($b['text'] ?? $b['title'] ?? ''));
            if ($id !== '' && $title !== '' && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id)) $btns[] = ['id' => $id, 'title' => mb_substr($title, 0, FLOW_LIMIT_BUTTON_TITLE)];
        }
        if (!$btns) continue;
        $out[] = ['id' => (int)$x['id'], 'name' => mb_substr((string)$x['keyword'], 0, 60), 'text' => (string)$x['reply_content'], 'footer' => mb_substr((string)$x['footer_text'], 0, 60), 'buttons' => array_slice($btns, 0, FLOW_LIMIT_BUTTONS)];
    }
    $st->close();
    return $out;
}

function flow_botreply_get(mysqli $conn, int $companyId, int $botReplyId): ?array
{
    foreach (flow_botreply_catalog($conn, $companyId) as $b) { if ($b['id'] === $botReplyId) return $b; }
    return null;
}

/**
 * Make a send/buttons config mirror its Bot Reply. Returns [config, changed(bool)].
 * Buttons are matched by id: a renamed label keeps its canvas connection, a new button gets a new (unconnected) port,
 * a deleted button disappears (its connection is dropped on the next save).
 */
function flow_botreply_apply(array $cfg, array $bot): array
{
    $new = $cfg;
    $new['kind'] = 'buttons';
    $new['bot_reply_id'] = $bot['id'];
    $new['text'] = mb_substr($bot['text'], 0, FLOW_LIMIT_BODY);
    $new['footer'] = $bot['footer'];
    $new['buttons'] = array_map(static fn($b) => ['id' => $b['id'], 'title' => $b['title']], $bot['buttons']);
    return [$new, $new !== $cfg];
}

/** Refresh every linked node of one company (optionally only for one Bot Reply). Returns number of nodes changed. */
function flow_botreply_push(mysqli $conn, int $companyId, int $botReplyId = 0): int
{
    $catalog = [];
    foreach (flow_botreply_catalog($conn, $companyId) as $b) $catalog[$b['id']] = $b;
    $st = $conn->prepare("SELECT flow_id, node_id, config FROM flow_nodes WHERE company_id = ? AND type = 'send' AND config LIKE '%\"bot_reply_id\"%'");
    if (!$st) return 0;
    $st->bind_param('i', $companyId);
    $st->execute();
    $rows = [];
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) $rows[] = $x;
    $st->close();
    $n = 0; $flows = [];
    foreach ($rows as $x) {
        $cfg = json_decode((string)$x['config'], true) ?: [];
        $bid = (int)($cfg['bot_reply_id'] ?? 0);
        if ($bid <= 0 || ($botReplyId > 0 && $bid !== $botReplyId) || !isset($catalog[$bid])) continue;
        [$new, $changed] = flow_botreply_apply($cfg, $catalog[$bid]);
        if (!$changed) continue;
        $valid = array_column($new['buttons'], 'id');
        $json = json_encode($new, JSON_UNESCAPED_UNICODE);
        $u = $conn->prepare("UPDATE flow_nodes SET config = ? WHERE flow_id = ? AND node_id = ? AND company_id = ?");
        $u->bind_param('sisi', $json, $x['flow_id'], $x['node_id'], $companyId);
        $u->execute();
        $u->close();
        // connections of removed buttons go away
        $e = $conn->prepare("SELECT edge_id, source_port FROM flow_edges WHERE flow_id = ? AND source_node = ? AND company_id = ? AND source_port <> 'fallback'");
        $e->bind_param('isi', $x['flow_id'], $x['node_id'], $companyId);
        $e->execute();
        $er = $e->get_result();
        while ($ed = $er->fetch_assoc()) {
            if (!in_array($ed['source_port'], $valid, true)) {
                $d = $conn->prepare("DELETE FROM flow_edges WHERE flow_id = ? AND edge_id = ? AND company_id = ?");
                $d->bind_param('isi', $x['flow_id'], $ed['edge_id'], $companyId);
                $d->execute();
                $d->close();
            }
        }
        $e->close();
        $flows[(int)$x['flow_id']] = true;
        $n++;
    }
    foreach (array_keys($flows) as $fid) {
        $u = $conn->prepare("UPDATE chatbot_flows SET graph_version = graph_version + 1 WHERE id = ? AND company_id = ?");
        $u->bind_param('ii', $fid, $companyId);
        $u->execute();
        $u->close();
    }
    return $n;
}
