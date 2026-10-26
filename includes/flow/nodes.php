<?php
/**
 * Flow Builder: node catalog shared by the canvas API (validation / sanitising) and the execution engine.
 *
 * Node types: trigger, send, question, condition, delay, action, end.
 * Every node has one input port. Output ports depend on the type and config:
 *   trigger -> out                send(text|image|video|document|template) -> next
 *   send(buttons)  -> one port per button id (b1..b3) + fallback
 *   send(list)     -> one port per row id + fallback
 *   question       -> next (valid answer) + fallback (answer still invalid after retries)
 *   condition      -> yes + no           delay -> next        action -> next (none for hand-over)     end -> none
 */

const FLOW_NODE_TYPES = ['trigger', 'send', 'question', 'condition', 'delay', 'action', 'end'];
const FLOW_LIMIT_BUTTONS = 3;
const FLOW_LIMIT_BUTTON_TITLE = 20;
const FLOW_LIMIT_LIST_ROWS = 10;
const FLOW_LIMIT_ROW_TITLE = 24;
const FLOW_LIMIT_BODY = 1024;
const FLOW_LIMIT_TEXT = 4096;
const FLOW_MAX_NODES = 150;

function flow_contact_fields(): array
{
    return ['name', 'email', 'city', 'area', 'pincode', 'address', 'client_company', 'language', 'var1', 'var2', 'var3', 'var4', 'var5'];
}

/** 60 -> "1 hour", 90 -> "90 min", 1440 -> "24 hours" */
function flow_minutes_label(int $m): string
{
    if ($m >= 60 && $m % 60 === 0) { $h = intdiv($m, 60); return $h . ($h === 1 ? ' hour' : ' hours'); }
    return $m . ' min';
}

function flow_delay_seconds(array $cfg): int
{
    $n = max(0, (int)($cfg['amount'] ?? 0));
    $u = (string)($cfg['unit'] ?? 'minutes');
    return $n * ($u === 'hours' ? 3600 : ($u === 'seconds' ? 1 : 60));
}

/** @return array<int,array{id:string,label:string,kind:string}> kind = main | branch | fallback */
function flow_node_ports(string $type, array $cfg): array
{
    switch ($type) {
        case 'trigger':   return [['id' => 'out', 'label' => 'Start', 'kind' => 'main']];
        case 'question':
            $p = [['id' => 'next', 'label' => 'Answer', 'kind' => 'main'], ['id' => 'fallback', 'label' => 'No valid answer', 'kind' => 'fallback']];
            if ((int)($cfg['timeout_minutes'] ?? 0) > 0) $p[] = ['id' => 'timeout', 'label' => 'No reply after ' . flow_minutes_label((int)$cfg['timeout_minutes']), 'kind' => 'timeout'];
            return $p;
        case 'condition': return [['id' => 'yes', 'label' => 'Yes', 'kind' => 'branch'], ['id' => 'no', 'label' => 'No', 'kind' => 'branch']];
        case 'delay':     return [['id' => 'next', 'label' => 'After wait', 'kind' => 'main']];
        case 'end':       return [];
        case 'action':
            return in_array((string)($cfg['action'] ?? ''), ['handover_ai', 'handover_human'], true) ? [] : [['id' => 'next', 'label' => 'Next', 'kind' => 'main']];
        case 'send':
            $kind = (string)($cfg['kind'] ?? 'text');
            if ($kind === 'buttons') {
                $ports = [];
                foreach ((array)($cfg['buttons'] ?? []) as $b) {
                    if (is_array($b) && !empty($b['id'])) $ports[] = ['id' => (string)$b['id'], 'label' => (string)($b['title'] ?? $b['id']), 'kind' => 'branch'];
                }
                $ports[] = ['id' => 'fallback', 'label' => 'No match', 'kind' => 'fallback'];
                if ((int)($cfg['timeout_minutes'] ?? 0) > 0) $ports[] = ['id' => 'timeout', 'label' => 'No reply after ' . flow_minutes_label((int)$cfg['timeout_minutes']), 'kind' => 'timeout'];
                return $ports;
            }
            if ($kind === 'list') {
                $ports = [];
                foreach ((array)($cfg['rows'] ?? []) as $r) {
                    if (is_array($r) && !empty($r['id'])) $ports[] = ['id' => (string)$r['id'], 'label' => (string)($r['title'] ?? $r['id']), 'kind' => 'branch'];
                }
                $ports[] = ['id' => 'fallback', 'label' => 'No match', 'kind' => 'fallback'];
                if ((int)($cfg['timeout_minutes'] ?? 0) > 0) $ports[] = ['id' => 'timeout', 'label' => 'No reply after ' . flow_minutes_label((int)$cfg['timeout_minutes']), 'kind' => 'timeout'];
                return $ports;
            }
            return [['id' => 'next', 'label' => 'Next', 'kind' => 'main']];
    }
    return [];
}

/** Does the flow stop at this node and wait for the customer's next message? */
function flow_node_waits_for_user(string $type, array $cfg): bool
{
    return $type === 'question' || ($type === 'send' && in_array((string)($cfg['kind'] ?? ''), ['buttons', 'list'], true));
}

function flow_clip(string $s, int $max): string
{
    $s = trim(str_replace("\0", '', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}

function flow_safe_id(string $s): string
{
    return preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $s) ? $s : '';
}

function flow_sanitize_config(string $type, array $c): array
{
    $str = static fn($k, $max = 255) => flow_clip((string)($c[$k] ?? ''), $max);
    switch ($type) {
        case 'trigger':
            $mode = in_array($c['mode'] ?? '', ['keyword', 'new_contact', 'any_message', 'button_reply', 'fallback', 'from_ad'], true) ? $c['mode'] : 'keyword';
            $rules = [];
            foreach ((array)($c['rules'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $v = flow_clip((string)($r['value'] ?? ''), 200);
                if ($v === '') continue;
                $rules[] = ['match' => in_array($r['match'] ?? '', ['exact', 'contains', 'starts', 'regex'], true) ? $r['match'] : 'exact', 'value' => $v];
                if (count($rules) >= 30) break;
            }
            $ids = [];
            foreach ((array)($c['reply_ids'] ?? []) as $v) { $v = flow_clip((string)$v, 200); if ($v !== '') $ids[] = $v; }
            $out = ['mode' => $mode, 'rules' => $rules, 'reply_ids' => array_slice($ids, 0, 30), 'cooldown_hours' => max(0, min(720, (int)($c['cooldown_hours'] ?? 12)))];
            if ($mode === 'from_ad') {
                $out['ad_match'] = in_array($c['ad_match'] ?? '', ['any', 'ad_id', 'headline'], true) ? $c['ad_match'] : 'any';
                $out['ad_value'] = flow_clip((string)($c['ad_value'] ?? ''), 500);
            }
            return $out;

        case 'send':
            $kind = in_array($c['kind'] ?? '', ['text', 'image', 'video', 'document', 'buttons', 'list', 'template'], true) ? $c['kind'] : 'text';
            $out = ['kind' => $kind, 'text' => $str('text', $kind === 'text' ? FLOW_LIMIT_TEXT : FLOW_LIMIT_BODY)];
            if (in_array($kind, ['image', 'video', 'document'], true)) {
                $out['media_url'] = $str('media_url', 500);
                if ($kind === 'document') $out['filename'] = $str('filename', 120);
            }
            if ($kind === 'buttons' || $kind === 'list') { $out['footer'] = $str('footer', 60); $out['timeout_minutes'] = max(0, min(1440, (int)($c['timeout_minutes'] ?? 0))); }
            if ($kind === 'buttons') {
                $out['bot_reply_id'] = max(0, (int)($c['bot_reply_id'] ?? 0));   // linked to a Bot Reply: labels follow it
                $btns = []; $seen = [];
                foreach ((array)($c['buttons'] ?? []) as $b) {
                    if (!is_array($b)) continue;
                    $id = flow_safe_id((string)($b['id'] ?? ''));
                    if ($id === '' || isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $btns[] = ['id' => $id, 'title' => flow_clip((string)($b['title'] ?? ''), FLOW_LIMIT_BUTTON_TITLE)];
                    if (count($btns) >= FLOW_LIMIT_BUTTONS) break;
                }
                $out['buttons'] = $btns;
            }
            if ($kind === 'list') {
                $out['button'] = $str('button', FLOW_LIMIT_BUTTON_TITLE) ?: 'Choose';
                $rows = []; $seen = [];
                foreach ((array)($c['rows'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    $id = flow_safe_id((string)($r['id'] ?? ''));
                    if ($id === '' || isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $rows[] = ['id' => $id, 'title' => flow_clip((string)($r['title'] ?? ''), FLOW_LIMIT_ROW_TITLE), 'description' => flow_clip((string)($r['description'] ?? ''), 72)];
                    if (count($rows) >= FLOW_LIMIT_LIST_ROWS) break;
                }
                $out['rows'] = $rows;
            }
            if ($kind === 'template') {
                $out['template_id'] = max(0, (int)($c['template_id'] ?? 0));
                $out['template_name'] = $str('template_name', 120);
                $out['language'] = $str('language', 12) ?: 'en';
                $vars = [];
                foreach ((array)($c['variables'] ?? []) as $v) { $vars[] = flow_clip((string)$v, 200); if (count($vars) >= 10) break; }
                $out['variables'] = $vars;
            }
            return $out;

        case 'question':
            $save = preg_replace('/[^A-Za-z0-9_]/', '_', $str('save_as', 40));
            return [
                'text' => $str('text', FLOW_LIMIT_BODY), 'save_as' => $save !== '' ? $save : 'answer',
                'field' => in_array($c['field'] ?? '', flow_contact_fields(), true) ? $c['field'] : '',
                'validate' => in_array($c['validate'] ?? '', ['none', 'number', 'email', 'phone'], true) ? $c['validate'] : 'none',
                'retry_text' => $str('retry_text', FLOW_LIMIT_BODY), 'max_retries' => max(0, min(3, (int)($c['max_retries'] ?? 1))),
                'timeout_minutes' => max(0, min(1440, (int)($c['timeout_minutes'] ?? 0))),
            ];

        case 'condition':
            return [
                'source' => in_array($c['source'] ?? '', ['variable', 'field', 'tag', 'time'], true) ? $c['source'] : 'variable',
                'name' => $str('name', 60),
                'operator' => in_array($c['operator'] ?? '', ['equals', 'not_equals', 'contains', 'gt', 'lt', 'empty', 'not_empty'], true) ? $c['operator'] : 'equals',
                'value' => $str('value', 200),
                'from' => preg_match('/^\d{2}:\d{2}$/', (string)($c['from'] ?? '')) ? $c['from'] : '09:00',
                'to' => preg_match('/^\d{2}:\d{2}$/', (string)($c['to'] ?? '')) ? $c['to'] : '18:00',
            ];

        case 'delay':
            return ['amount' => max(1, min(720, (int)($c['amount'] ?? 1))), 'unit' => in_array($c['unit'] ?? '', ['seconds', 'minutes', 'hours'], true) ? $c['unit'] : 'minutes'];

        case 'action':
            $a = in_array($c['action'] ?? '', ['add_tag', 'remove_tag', 'update_field', 'assign_agent', 'webhook', 'handover_ai', 'handover_human'], true) ? $c['action'] : 'add_tag';
            return [
                'action' => $a, 'tag' => $str('tag', 60),
                'field' => in_array($c['field'] ?? '', flow_contact_fields(), true) ? $c['field'] : 'name', 'value' => $str('value', 500),
                'agent_id' => max(0, (int)($c['agent_id'] ?? 0)),
                'url' => $str('url', 500), 'method' => ($c['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST', 'body' => $str('body', 2000),
                'message' => $str('message', FLOW_LIMIT_BODY),
            ];

        case 'end':
            return ['message' => $str('message', FLOW_LIMIT_BODY)];
    }
    return [];
}

/**
 * Clean the graph posted by the canvas. Unknown types, bad ids, edges to missing nodes or to ports that no longer
 * exist are dropped; only one edge per output port is kept.
 *
 * @return array{nodes:array,edges:array,dropped:int}
 */
function flow_clean_graph(array $graph): array
{
    $nodes = []; $dropped = 0;
    foreach ((array)($graph['nodes'] ?? []) as $n) {
        if (!is_array($n)) { $dropped++; continue; }
        $id = flow_safe_id((string)($n['id'] ?? ''));
        $type = (string)($n['type'] ?? '');
        if ($id === '' || !in_array($type, FLOW_NODE_TYPES, true) || isset($nodes[$id])) { $dropped++; continue; }
        $pos = (array)($n['position'] ?? []);
        $nodes[$id] = [
            'id' => $id, 'type' => $type,
            'x' => (int)max(-20000, min(20000, (float)($pos['x'] ?? $n['x'] ?? 0))), 'y' => (int)max(-20000, min(20000, (float)($pos['y'] ?? $n['y'] ?? 0))),
            'config' => flow_sanitize_config($type, (array)($n['config'] ?? [])),
        ];
        if (count($nodes) > FLOW_MAX_NODES) break;
    }
    $edges = []; $used = [];
    foreach ((array)($graph['edges'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $eid = flow_safe_id((string)($e['id'] ?? ''));
        $s = (string)($e['source_node'] ?? ''); $p = (string)($e['source_port'] ?? ''); $t = (string)($e['target_node'] ?? '');
        if ($eid === '' || !isset($nodes[$s]) || !isset($nodes[$t]) || $s === $t || $nodes[$t]['type'] === 'trigger') { $dropped++; continue; }
        $ok = false;
        foreach (flow_node_ports($nodes[$s]['type'], $nodes[$s]['config']) as $port) { if ($port['id'] === $p) { $ok = true; break; } }
        if (!$ok || isset($used[$s . '|' . $p])) { $dropped++; continue; }
        $used[$s . '|' . $p] = true;
        $edges[] = ['id' => $eid, 'source_node' => $s, 'source_port' => $p, 'target_node' => $t];
    }
    return ['nodes' => array_values($nodes), 'edges' => $edges, 'dropped' => $dropped];
}

/**
 * Fetches a media link the way WhatsApp will and says what is wrong with it, or null when it is fine.
 * WhatsApp accepts the send first and only fails later when it downloads the file, so a web page link
 * (e.g. an iStock/Pixabay/Google Images page) shows up as a silent red "!" in the chat.
 * Returns ['level' => 'error'|'warning', 'message' => ...]. Unreachable from this server = warning only.
 */
function flow_media_url_problem(string $kind, string $url): ?array
{
    static $memo = [];
    $url = trim($url);
    $key = $kind . '|' . $url;
    if (array_key_exists($key, $memo)) return $memo[$key];

    $cacheKey = 'flow_media_check:' . sha1($key);
    if (function_exists('wa_cache_get')) {
        $hit = wa_cache_get($cacheKey, 3600);
        if (is_array($hit)) return $memo[$key] = ($hit['p'] ?? null);
    }

    $allowed = [
        'image' => ['image/jpeg', 'image/png'],
        'video' => ['video/mp4', 'video/3gpp'],
    ];
    $problem = null;
    $status = 0; $type = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => false, CURLOPT_RANGE => '0-1023', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'WhatsApp/2.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        curl_close($ch);
    }

    if ($status === 0) {
        $problem = ['level' => 'warning', 'message' => "Could not open the $kind link to check it. Make sure it is a public link to the file itself."];
    } elseif ($status >= 400) {
        $problem = ['level' => 'error', 'message' => "The $kind link does not open (HTTP $status). Use a public link to the file itself."];
    } elseif ($type === 'text/html' || $type === 'application/xhtml+xml') {
        $problem = ['level' => 'error', 'message' => "The $kind link is a web page, not a file, so WhatsApp cannot send it. Open the picture, right-click it and choose \"Copy image address\", or upload the file and use that link."];
    } elseif (isset($allowed[$kind]) && $type !== '' && !in_array($type, $allowed[$kind], true)) {
        $problem = ['level' => 'error', 'message' => 'WhatsApp only sends ' . ($kind === 'image' ? 'JPG or PNG images' : 'MP4 or 3GP videos') . " but this link is \"$type\". Convert the file and use the new link."];
    }

    // Only remember definite answers; a network hiccup is re-checked next time.
    if ($status !== 0 && function_exists('wa_cache_set')) wa_cache_set($cacheKey, ['p' => $problem], 3600);
    return $memo[$key] = $problem;
}

/**
 * Publish-time checks. Returns a list of problems; level "error" blocks publishing, "warning" does not.
 *
 * @return array<int,array{node_id:?string,level:string,message:string}>
 */
function flow_validate(array $nodes, array $edges): array
{
    $issues = [];
    $add = static function (?string $node, string $msg, string $level = 'error') use (&$issues) { $issues[] = ['node_id' => $node, 'level' => $level, 'message' => $msg]; };

    $byId = []; $triggers = [];
    foreach ($nodes as $n) { $byId[$n['id']] = $n; if ($n['type'] === 'trigger') $triggers[] = $n['id']; }
    if (!$nodes) { $add(null, 'The flow is empty. Add a trigger and at least one message.'); return $issues; }
    if (count($triggers) === 0) $add(null, 'The flow needs exactly one Trigger node.');
    if (count($triggers) > 1) foreach ($triggers as $t) $add($t, 'Only one Trigger node is allowed per flow.');

    $out = []; $in = [];
    foreach ($edges as $e) { $out[$e['source_node']][$e['source_port']] = $e['target_node']; $in[$e['target_node']] = true; }

    foreach ($nodes as $n) {
        $id = $n['id']; $c = $n['config']; $type = $n['type'];
        $label = ucfirst($type === 'send' ? 'message' : $type);

        if ($type !== 'trigger' && empty($in[$id])) $add($id, "$label node is not connected to anything. Connect it or delete it.");

        foreach (flow_node_ports($type, $c) as $p) {
            if ($p['kind'] === 'fallback') continue;
            if (empty($out[$id][$p['id']])) {
                if ($p['kind'] === 'timeout') { $add($id, 'The "' . $p['label'] . '" output is on but not connected. Connect it to the follow-up message, or set the step to never time out.'); continue; }
                $what = $type === 'trigger' ? 'The trigger' : ($p['kind'] === 'branch' ? '"' . $p['label'] . '" branch' : "$label node");
                $add($id, "$what has no next step. Connect it, or connect it to an End node.");
            }
        }

        if ($type === 'trigger') {
            $m = $c['mode'] ?? 'keyword';
            if ($m === 'keyword' && empty($c['rules'])) $add($id, 'Add at least one keyword rule.');
            foreach ((array)($c['rules'] ?? []) as $r) {
                if (($r['match'] ?? '') === 'regex' && @preg_match('~' . str_replace('~', '\~', (string)$r['value']) . '~iu', '') === false) $add($id, 'Invalid regular expression: ' . $r['value']);
            }
        }
        if ($type === 'send') {
            $kind = $c['kind'] ?? 'text';
            if (in_array($kind, ['text', 'buttons', 'list'], true) && trim((string)($c['text'] ?? '')) === '') $add($id, 'The message is empty.');
            if (in_array($kind, ['image', 'video', 'document'], true)) {
                $mu = (string)($c['media_url'] ?? '');
                if (!preg_match('~^https?://~i', $mu)) $add($id, "Add a public https link for the $kind.");
                elseif (strpos($mu, '{{') === false && ($mp = flow_media_url_problem($kind, $mu))) $add($id, $mp['message'], $mp['level']);
            }
            if ($kind === 'buttons') {
                $b = (array)($c['buttons'] ?? []);
                if (count($b) < 1) $add($id, 'Add at least one reply button.');
                if (count($b) > FLOW_LIMIT_BUTTONS) $add($id, 'WhatsApp allows at most ' . FLOW_LIMIT_BUTTONS . ' reply buttons.');
                foreach ($b as $x) { $t = trim((string)($x['title'] ?? '')); if ($t === '') $add($id, 'A button has no title.'); elseif (mb_strlen($t) > FLOW_LIMIT_BUTTON_TITLE) $add($id, 'Button titles can be at most ' . FLOW_LIMIT_BUTTON_TITLE . ' characters.'); }
            }
            if ($kind === 'list') {
                $r = (array)($c['rows'] ?? []);
                if (count($r) < 1) $add($id, 'Add at least one list row.');
                if (count($r) > FLOW_LIMIT_LIST_ROWS) $add($id, 'WhatsApp allows at most ' . FLOW_LIMIT_LIST_ROWS . ' list rows.');
                foreach ($r as $x) { if (trim((string)($x['title'] ?? '')) === '') $add($id, 'A list row has no title.'); }
            }
            if ($kind === 'template' && empty($c['template_id'])) $add($id, 'Choose an approved template.');
        }
        if ($type === 'question') {
            if (trim((string)($c['text'] ?? '')) === '') $add($id, 'The question is empty.');
            if (($c['validate'] ?? 'none') !== 'none' && trim((string)($c['retry_text'] ?? '')) === '') $add($id, 'Add a retry message for the answer check.', 'warning');
        }
        if ($type === 'condition') {
            $src = $c['source'] ?? 'variable';
            if (in_array($src, ['variable', 'field', 'tag'], true) && trim((string)($c['name'] ?? '')) === '') $add($id, 'Choose what to check (variable, field or tag name).');
        }
        if ($type === 'action') {
            $a = $c['action'] ?? '';
            if (in_array($a, ['add_tag', 'remove_tag'], true) && trim((string)($c['tag'] ?? '')) === '') $add($id, 'Enter the tag name.');
            if ($a === 'assign_agent' && empty($c['agent_id'])) $add($id, 'Choose the agent.');
            if ($a === 'webhook' && !preg_match('~^https?://~i', (string)($c['url'] ?? ''))) $add($id, 'Enter the webhook URL (http or https).');
        }
    }

    // reachability from the trigger
    if (count($triggers) === 1) {
        $seen = [$triggers[0] => true]; $stack = [$triggers[0]];
        $adj = [];
        foreach ($edges as $e) { $adj[$e['source_node']][] = $e['target_node']; }
        while ($stack) { $cur = array_pop($stack); foreach ($adj[$cur] ?? [] as $nx) { if (!isset($seen[$nx])) { $seen[$nx] = true; $stack[] = $nx; } } }
        foreach ($nodes as $n) { if (empty($seen[$n['id']]) && !empty($in[$n['id']])) $add($n['id'], 'This step cannot be reached from the Trigger.'); }
    }

    // endless loop: a cycle that never waits for the customer or a delay
    $adj = [];
    foreach ($edges as $e) { $adj[$e['source_node']][] = $e['target_node']; }
    $state = [];
    $waits = static fn(array $n): bool => $n['type'] === 'delay' || flow_node_waits_for_user($n['type'], $n['config']);
    $reported = [];
    $dfs = function (string $id, array $path) use (&$dfs, &$state, $adj, $byId, $waits, &$reported, $add) {
        $state[$id] = 1;
        $path[] = $id;
        foreach ($adj[$id] ?? [] as $nx) {
            if (($state[$nx] ?? 0) === 1) {
                $cycle = array_slice($path, array_search($nx, $path, true));
                $hasWait = false;
                foreach ($cycle as $cid) { if (isset($byId[$cid]) && $waits($byId[$cid])) { $hasWait = true; break; } }
                if (!$hasWait && empty($reported[$nx])) { $reported[$nx] = true; $add($nx, 'This part of the flow loops forever. Add a Delay or a question inside the loop.'); }
            } elseif (($state[$nx] ?? 0) === 0 && isset($byId[$nx])) {
                $dfs($nx, $path);
            }
        }
        $state[$id] = 2;
    };
    foreach ($nodes as $n) { if (($state[$n['id']] ?? 0) === 0) $dfs($n['id'], []); }

    // after a 24h+ delay only approved templates may be sent
    foreach ($nodes as $n) {
        if ($n['type'] !== 'delay' || flow_delay_seconds($n['config']) < 86400) continue;
        $stack = [$out[$n['id']]['next'] ?? null]; $seen = [];
        while ($stack) {
            $cur = array_pop($stack);
            if ($cur === null || isset($seen[$cur]) || !isset($byId[$cur])) continue;
            $seen[$cur] = true;
            $t = $byId[$cur]['type']; $cfg = $byId[$cur]['config'];
            if (($t === 'send' && ($cfg['kind'] ?? '') !== 'template') || $t === 'question' || ($t === 'end' && trim((string)($cfg['message'] ?? '')) !== '')) {
                $add($cur, 'Sent more than 24 hours after the last customer message: WhatsApp only allows an approved template here.');
                continue;
            }
            foreach ($adj[$cur] ?? [] as $nx) { $stack[] = $nx; }
        }
    }

    return $issues;
}
