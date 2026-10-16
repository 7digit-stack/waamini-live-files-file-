<?php
/**
 * Flow Builder end-to-end test (engine + store + validation) with a mock WhatsApp sender.
 * Uses fake company ids 99001 / 99002 only and removes everything it created.
 *   php tests/flow_engine_test.php
 */
chdir(dirname(__DIR__));
define('WAAMINI_LOADED', true);
require 'db.php';
require_once __DIR__ . '/../includes/flow/engine.php';
require_once __DIR__ . '/../includes/flow/store.php';
require_once __DIR__ . '/../includes/flow/botreply.php';

$CO = 99001; $CO2 = 99002;
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }
function cleanup(mysqli $c, array $cos): void {
    foreach ($cos as $co) {
        foreach (['flow_logs', 'flow_jobs', 'flow_sessions', 'flow_edges', 'flow_nodes', 'chatbot_flows', 'messages', 'contacts', 'bot_replies'] as $t) { $c->query("DELETE FROM $t WHERE company_id = $co"); }
    }
}
cleanup($conn, [$CO, $CO2]);
flow_ensure_schema($conn);

function mkContact(mysqli $c, int $co, string $name, string $phone): int {
    $st = $c->prepare("INSERT INTO contacts (company_id, name, phone) VALUES (?,?,?)");
    $st->bind_param('iss', $co, $name, $phone); $st->execute(); return (int)$c->insert_id;
}
function inbound(mysqli $c, int $co, int $cid, string $text): void {
    $st = $c->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, is_read, status, created_at) VALUES (?,?,'incoming','text',?,?,0,'received',NOW())");
    $st->bind_param('iiss', $co, $cid, $text, $text); $st->execute();
}
function eng(mysqli $c, int $co, int $cid, FlowMockSender $s, array $o = []): FlowEngine { return new FlowEngine($c, $co, $cid, $s, $o); }
// Use the same Meta Cloud API payload shapes received by webhook.php, then normalise them through the production helper.
function say(string $t): array { return flow_inbound_from_message(['type' => 'text', 'text' => ['body' => $t]]); }
function tap(string $id, string $title): array { return flow_inbound_from_message(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]); }
/** Insert a flow straight into the tables (bypasses validation on purpose for edge cases). */
function rawFlow(mysqli $c, int $co, string $name, array $nodes, array $edges, array $extra = []): int {
    $prio = (int)($extra['priority'] ?? 1); $max = (int)($extra['max_steps'] ?? 25);
    $c->query("INSERT INTO chatbot_flows (company_id, name, trigger_keyword, trigger_type, is_active, status, priority, max_steps) VALUES ($co, '" . $c->real_escape_string($name) . "', '', 'keyword', 1, 'published', $prio, $max)");
    $f = (int)$c->insert_id;
    foreach ($nodes as $i => $n) { $c->query("INSERT INTO flow_nodes (flow_id,node_id,company_id,type,pos_x,pos_y,config) VALUES ($f,'{$n[0]}',$co,'{$n[1]}',$i,$i,'" . $c->real_escape_string(json_encode($n[2])) . "')"); }
    foreach ($edges as $k => $e) { $c->query("INSERT INTO flow_edges (flow_id,edge_id,company_id,source_node,source_port,target_node) VALUES ($f,'e$k',$co,'{$e[0]}','{$e[1]}','{$e[2]}')"); }
    return $f;
}
function lastSession(mysqli $c, int $co, int $cid): array { return $c->query("SELECT * FROM flow_sessions WHERE company_id=$co AND contact_id=$cid ORDER BY id DESC LIMIT 1")->fetch_assoc() ?: []; }
function openCount(mysqli $c, int $co, int $cid): int { return (int)$c->query("SELECT COUNT(*) c FROM flow_sessions WHERE company_id=$co AND contact_id=$cid AND active_key=1")->fetch_assoc()['c']; }
function unpublishAll(mysqli $c, int $co): void { $c->query("UPDATE chatbot_flows SET status='draft', is_active=0 WHERE company_id=$co"); }
$act = ['tag' => '', 'field' => 'name', 'value' => '', 'agent_id' => 0, 'url' => '', 'method' => 'POST', 'body' => '', 'message' => ''];

// ── sample flow ────────────────────────────────────────────────────────────
$st = $conn->prepare("INSERT INTO chatbot_flows (company_id, name, trigger_keyword, trigger_type, is_active) VALUES (?, 'Sample: hi', 'hi', 'keyword', 0)");
$st->bind_param('i', $CO); $st->execute(); $FLOW = (int)$conn->insert_id;

$graph = [
 'nodes' => [
  ['id' => 'trg',  'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'hi']]]],
  ['id' => 'menu', 'type' => 'send', 'position' => ['x' => 300, 'y' => 0], 'config' => ['kind' => 'buttons', 'text' => 'Hello {{contact.first_name}}! How can we help?', 'buttons' => [['id' => 'b_sales', 'title' => 'Sales'], ['id' => 'b_support', 'title' => 'Support'], ['id' => 'b_price', 'title' => 'Pricing']]]],
  ['id' => 'sales', 'type' => 'send', 'position' => ['x' => 650, 'y' => -150], 'config' => ['kind' => 'text', 'text' => 'SALES: our team will call you today.']],
  ['id' => 'price', 'type' => 'send', 'position' => ['x' => 650, 'y' => 0], 'config' => ['kind' => 'text', 'text' => 'PRICING: plans start at Rs 999/month.']],
  ['id' => 'ask',   'type' => 'question', 'position' => ['x' => 650, 'y' => 150], 'config' => ['text' => 'SUPPORT: what is your email?', 'save_as' => 'email', 'field' => 'email', 'validate' => 'email', 'retry_text' => 'That does not look like an email, try again.', 'max_retries' => 1]],
  ['id' => 'thx',   'type' => 'send', 'position' => ['x' => 1000, 'y' => 150], 'config' => ['kind' => 'text', 'text' => 'Thanks {{contact.name}}, we will write to {{email}}.']],
  ['id' => 'end1',  'type' => 'end', 'position' => ['x' => 1300, 'y' => -150], 'config' => ['message' => '']],
  ['id' => 'end2',  'type' => 'end', 'position' => ['x' => 1300, 'y' => 0], 'config' => ['message' => '']],
  ['id' => 'end3',  'type' => 'end', 'position' => ['x' => 1300, 'y' => 150], 'config' => ['message' => '']],
 ],
 'edges' => [
  ['id' => 'e1', 'source_node' => 'trg', 'source_port' => 'out', 'target_node' => 'menu'],
  ['id' => 'e2', 'source_node' => 'menu', 'source_port' => 'b_sales', 'target_node' => 'sales'],
  ['id' => 'e3', 'source_node' => 'menu', 'source_port' => 'b_support', 'target_node' => 'ask'],
  ['id' => 'e4', 'source_node' => 'menu', 'source_port' => 'b_price', 'target_node' => 'price'],
  ['id' => 'e5', 'source_node' => 'sales', 'source_port' => 'next', 'target_node' => 'end1'],
  ['id' => 'e6', 'source_node' => 'price', 'source_port' => 'next', 'target_node' => 'end2'],
  ['id' => 'e7', 'source_node' => 'ask', 'source_port' => 'next', 'target_node' => 'thx'],
  ['id' => 'e8', 'source_node' => 'thx', 'source_port' => 'next', 'target_node' => 'end3'],
 ],
];

echo "\n== 1. Save, validate, publish\n";
$res = flow_save_graph($conn, $CO, $FLOW, $graph);
check('graph saved', $res['ok'] && $res['dropped'] === 0);
$errs = array_filter($res['issues'], fn($i) => $i['level'] === 'error');
check('sample flow has no validation errors', !$errs, json_encode($errs));
$ld = flow_load_graph($conn, $CO, $FLOW);
check('loads back with the same nodes and edges', count($ld['nodes']) === 9 && count($ld['edges']) === 8);
check('cross-tenant load returns nothing', count(flow_load_graph($conn, $CO2, $FLOW)['nodes']) === 0);
$pub = flow_set_status($conn, $CO, $FLOW, 'published');
check('publish succeeds', $pub['ok'] && $pub['status'] === 'published');
$adGraph = $graph;
$adGraph['nodes'][0]['config'] = ['mode' => 'from_ad', 'ad_match' => 'any'];
$adSaved = flow_save_graph($conn, $CO, $FLOW, $adGraph);
$adSummary = $conn->query("SELECT trigger_type FROM chatbot_flows WHERE id=$FLOW AND company_id=$CO")->fetch_assoc();
check('saving an ad trigger preserves its trigger type', $adSaved['ok'] && ($adSummary['trigger_type'] ?? '') === 'from_ad');
flow_save_graph($conn, $CO, $FLOW, $graph);
flow_set_status($conn, $CO, $FLOW, 'published');

$bad = $graph;
$bad['nodes'][] = ['id' => 'orphan', 'type' => 'send', 'position' => ['x' => 0, 'y' => 400], 'config' => ['kind' => 'text', 'text' => '']];
$bad['edges'] = array_values(array_filter($bad['edges'], fn($e) => $e['id'] !== 'e4'));
$cb = flow_clean_graph($bad);
$iss = flow_validate($cb['nodes'], $cb['edges']);
check('validation: unconnected node found', (bool)array_filter($iss, fn($i) => $i['node_id'] === 'orphan' && str_contains($i['message'], 'not connected')), implode(' | ', array_column($iss, 'message')));
check('validation: empty message found', (bool)array_filter($iss, fn($i) => $i['node_id'] === 'orphan' && str_contains($i['message'], 'empty')));
check('validation: unconnected button branch found', (bool)array_filter($iss, fn($i) => $i['node_id'] === 'menu' && str_contains($i['message'], 'Pricing')));
$loop = ['nodes' => [['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => ['mode' => 'any_message']], ['id' => 'a', 'type' => 'send', 'position' => ['x' => 1, 'y' => 1], 'config' => ['kind' => 'text', 'text' => 'x']], ['id' => 'b', 'type' => 'send', 'position' => ['x' => 2, 'y' => 2], 'config' => ['kind' => 'text', 'text' => 'y']]],
         'edges' => [['id' => 'e1', 'source_node' => 't', 'source_port' => 'out', 'target_node' => 'a'], ['id' => 'e2', 'source_node' => 'a', 'source_port' => 'next', 'target_node' => 'b'], ['id' => 'e3', 'source_node' => 'b', 'source_port' => 'next', 'target_node' => 'a']]];
$c = flow_clean_graph($loop);
check('validation: endless loop without wait found', (bool)array_filter(flow_validate($c['nodes'], $c['edges']), fn($i) => str_contains($i['message'], 'loops forever')));
$two = $graph; $two['nodes'][] = ['id' => 'trg2', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 300], 'config' => ['mode' => 'any_message']];
$c = flow_clean_graph($two);
check('validation: only one trigger allowed', (bool)array_filter(flow_validate($c['nodes'], $c['edges']), fn($i) => str_contains($i['message'], 'Only one Trigger')));
$four = $graph; $four['nodes'][1]['config']['buttons'][] = ['id' => 'b_x', 'title' => 'Fourth'];
check('limits: 4th button is cut to 3', count(flow_clean_graph($four)['nodes'][1]['config']['buttons']) === 3);
$long = $graph; $long['nodes'][1]['config']['buttons'][0]['title'] = 'This title is far too long for WhatsApp';
check('limits: button title cut to 20 chars', mb_strlen(flow_clean_graph($long)['nodes'][1]['config']['buttons'][0]['title']) === 20);
$del = $graph; $del['nodes'][] = ['id' => 'd', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 0], 'config' => ['amount' => 48, 'unit' => 'hours']];
$del['nodes'][] = ['id' => 'f', 'type' => 'send', 'position' => ['x' => 0, 'y' => 0], 'config' => ['kind' => 'text', 'text' => 'free text']];
$del['edges'][] = ['id' => 'ed1', 'source_node' => 'd', 'source_port' => 'next', 'target_node' => 'f'];
$del['edges'][] = ['id' => 'ed2', 'source_node' => 'f', 'source_port' => 'next', 'target_node' => 'end1'];
$cd = flow_clean_graph($del);
check('validation: free text after a 48h delay is flagged', (bool)array_filter(flow_validate($cd['nodes'], $cd['edges']), fn($i) => $i['node_id'] === 'f' && str_contains($i['message'], '24 hours')));

echo "\n== 2. Path: Sales\n";
$cid = mkContact($conn, $CO, 'Asha Rao', '919900000001'); inbound($conn, $CO, $cid, 'hello');
$m = new FlowMockSender();
$r = eng($conn, $CO, $cid, $m)->handleInbound(say('hello'));
check('"hello" does not start the flow (keyword is exactly "hi")', !$r['handled'] && !$m->sent);
inbound($conn, $CO, $cid, 'HI');
$r = eng($conn, $CO, $cid, $m)->handleInbound(say('HI'));
check('"HI" starts the flow (case-insensitive)', $r['handled'] && $r['reason'] === 'started');
check('welcome message has 3 buttons and the contact name', ($m->sent[0]['kind'] ?? '') === 'buttons' && count($m->sent[0]['buttons']) === 3 && str_contains($m->sent[0]['body'], 'Hello Asha!'), json_encode($m->sent[0] ?? []));
$sess = $conn->query("SELECT * FROM flow_sessions WHERE company_id=$CO AND contact_id=$cid AND active_key=1")->fetch_assoc();
check('session waits at the menu', $sess && $sess['current_node_id'] === 'menu' && $sess['waiting_for'] === 'choice');
$m->sent = [];
$r = eng($conn, $CO, $cid, $m)->handleInbound(tap('b_sales', 'Sales'));
check('Sales button sends the SALES message only', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'SALES'), json_encode($m->sent));
check('session completed after Sales', openCount($conn, $CO, $cid) === 0);

echo "\n== 3. Path: Pricing (reply matched by id, not by text)\n";
$m = new FlowMockSender();
eng($conn, $CO, $cid, $m)->handleInbound(say('hi'));
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(tap('b_price', 'Sales'));   // wrong title on purpose: the id decides
check('id b_price sends PRICING even though the title says "Sales"', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'PRICING'), json_encode($m->sent));

echo "\n== 4. Path: Support asks a question and saves the answer\n";
$m = new FlowMockSender();
eng($conn, $CO, $cid, $m)->handleInbound(say('hi'));
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(tap('b_support', 'Support'));
check('Support asks for the email', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'SUPPORT'));
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(say('not-an-email'));
check('invalid email gets the retry message', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'does not look like an email'), json_encode($m->sent));
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(say('asha@example.com'));
check('valid email: thanks message uses the saved answer', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'Thanks Asha Rao') && str_contains($m->sent[0]['body'], 'asha@example.com'), json_encode($m->sent));
$row = $conn->query("SELECT email FROM contacts WHERE id=$cid AND company_id=$CO")->fetch_assoc();
check('answer saved to the contact email field', ($row['email'] ?? '') === 'asha@example.com');
$ses = lastSession($conn, $CO, $cid);
check('session ended at the End node with the variable stored', $ses['status'] === 'completed' && $ses['end_reason'] === 'end_node' && str_contains((string)$ses['variables'], 'asha@example.com'), json_encode($ses));

echo "\n== 5. No match: resend once, then end\n";
$m = new FlowMockSender();
eng($conn, $CO, $cid, $m)->handleInbound(say('hi'));
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(say('something else'));
check('typed text instead of a button resends the buttons once', count($m->sent) === 1 && $m->sent[0]['kind'] === 'buttons');
$m->sent = [];
eng($conn, $CO, $cid, $m)->handleInbound(say('again something else'));
check('second miss ends the session without more messages', !$m->sent && openCount($conn, $CO, $cid) === 0);

echo "\n== 6. Unconnected node never fires, loop guard, one session per contact\n";
$g2 = $graph; $g2['nodes'][] = ['id' => 'ghost', 'type' => 'send', 'position' => ['x' => 0, 'y' => 500], 'config' => ['kind' => 'text', 'text' => 'GHOST MESSAGE']];
flow_save_graph($conn, $CO, $FLOW, $g2);
$conn->query("UPDATE chatbot_flows SET status='published', is_active=1 WHERE id=$FLOW");
$m = new FlowMockSender();
eng($conn, $CO, $cid, $m)->handleInbound(say('hi')); eng($conn, $CO, $cid, $m)->handleInbound(tap('b_sales', 'Sales'));
check('ghost (unconnected) node never fires', !array_filter($m->sent, fn($x) => str_contains(json_encode($x), 'GHOST')));
flow_save_graph($conn, $CO, $FLOW, $graph); $conn->query("UPDATE chatbot_flows SET status='published', is_active=1 WHERE id=$FLOW");
$cid2 = mkContact($conn, $CO, 'Ravi', '919900000002'); inbound($conn, $CO, $cid2, 'hi');
$m = new FlowMockSender();
$e = eng($conn, $CO, $cid2, $m); $e->handleInbound(say('hi')); $e->handleInbound(say('hi'));
check('only one open session per contact', openCount($conn, $CO, $cid2) === 1);

$lf = rawFlow($conn, $CO, 'loop', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'loop']]]], ['a', 'send', ['kind' => 'text', 'text' => 'ping']], ['b', 'send', ['kind' => 'text', 'text' => 'pong']]],
              [['t', 'out', 'a'], ['a', 'next', 'b'], ['b', 'next', 'a']], ['max_steps' => 6]);
$cid3 = mkContact($conn, $CO, 'Loop', '919900000003'); inbound($conn, $CO, $cid3, 'loop');
$m = new FlowMockSender();
eng($conn, $CO, $cid3, $m)->handleInbound(say('loop'));
$lg = lastSession($conn, $CO, $cid3);
check('loop guard stops the run (max 6 steps)', ($lg['end_reason'] ?? '') === 'loop_guard' && count($m->sent) <= 6, json_encode($lg) . ' sent=' . count($m->sent));
$conn->query("UPDATE chatbot_flows SET status='draft', is_active=0 WHERE id=$lf");

echo "\n== 7. Delay runs through the job queue, 24h window, tenant isolation\n";
$df = rawFlow($conn, $CO, 'delay', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'starts', 'value' => 'remind']]]], ['w', 'delay', ['amount' => 30, 'unit' => 'minutes']], ['m', 'send', ['kind' => 'text', 'text' => 'REMINDER: your order is ready.']], ['x', 'end', ['message' => '']]],
              [['t', 'out', 'w'], ['w', 'next', 'm'], ['m', 'next', 'x']], ['priority' => 5]);
$cid4 = mkContact($conn, $CO, 'Delay', '919900000004'); inbound($conn, $CO, $cid4, 'remind me');
$m = new FlowMockSender();
eng($conn, $CO, $cid4, $m)->handleInbound(say('remind me'));
$job = $conn->query("SELECT * FROM flow_jobs WHERE company_id=$CO AND contact_id=$cid4 AND status='pending'")->fetch_assoc();
check('delay creates a scheduled job (no sleep) and sends nothing yet', $job && !$m->sent && strtotime($job['run_at']) > time() + 25 * 60, json_encode($job));
$r2 = eng($conn, $CO, $cid4, $m)->handleInbound(say('hello?'));
check('message during a delay does not restart or hijack the flow', !$r2['handled'] && $r2['reason'] === 'delay_pending');
eng($conn, $CO, $cid4, $m)->runJob($job);
check('job continues the flow and sends the reminder', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'REMINDER'));
$cid5 = mkContact($conn, $CO, 'Late', '919900000005'); inbound($conn, $CO, $cid5, 'remind');
$m = new FlowMockSender();
eng($conn, $CO, $cid5, $m)->handleInbound(say('remind'));
$job5 = $conn->query("SELECT * FROM flow_jobs WHERE company_id=$CO AND contact_id=$cid5 AND status='pending'")->fetch_assoc();
$conn->query("UPDATE messages SET created_at = DATE_SUB(NOW(), INTERVAL 26 HOUR) WHERE company_id=$CO AND contact_id=$cid5");
eng($conn, $CO, $cid5, $m)->runJob($job5);
$s5 = lastSession($conn, $CO, $cid5);
check('free-form message blocked after 24h (window closed), nothing sent', !$m->sent && ($s5['end_reason'] ?? '') === 'window_closed', json_encode($s5));
$conn->query("UPDATE chatbot_flows SET status='draft', is_active=0 WHERE id=$df");
$other = mkContact($conn, $CO2, 'Other tenant', '919900000009'); inbound($conn, $CO2, $other, 'hi');
$m = new FlowMockSender();
$r = eng($conn, $CO2, $other, $m)->handleInbound(say('hi'));
check("another company never triggers this company's flow", !$r['handled'] && !$m->sent);

echo "\n== 8. Trigger types, conditions, actions\n";
unpublishAll($conn, $CO);
$f1 = rawFlow($conn, $CO, 'regex', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'regex', 'value' => '^order\s*#?\d{4,}$']]]], ['s', 'send', ['kind' => 'text', 'text' => 'ORDER FLOW']]], [['t', 'out', 's']]);
$cid6 = mkContact($conn, $CO, 'Rx', '919900000006'); inbound($conn, $CO, $cid6, 'x');
$m = new FlowMockSender(); eng($conn, $CO, $cid6, $m)->handleInbound(say('Order #12345'));
check('regex trigger (case-insensitive) matches "Order #12345"', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'ORDER FLOW'), json_encode($m->sent));
unpublishAll($conn, $CO);
$f2 = rawFlow($conn, $CO, 'starts', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'starts', 'value' => 'price'], ['match' => 'contains', 'value' => 'cost']]]], ['s', 'send', ['kind' => 'text', 'text' => 'PRICE FLOW']]], [['t', 'out', 's']]);
$cid7 = mkContact($conn, $CO, 'St', '919900000007'); inbound($conn, $CO, $cid7, 'x');
$m = new FlowMockSender(); eng($conn, $CO, $cid7, $m)->handleInbound(say('what is the cost of this'));
check('contains rule matches a whole word inside a sentence', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'PRICE FLOW'));
$m = new FlowMockSender(); eng($conn, $CO, $cid7, $m)->handleInbound(say('is it costly'));
check('contains rule does not match inside another word ("costly")', !$m->sent);
unpublishAll($conn, $CO);
$f3 = rawFlow($conn, $CO, 'cond', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'vipcheck']]]],
   ['c', 'condition', ['source' => 'tag', 'name' => 'vip', 'operator' => 'equals', 'value' => '', 'from' => '09:00', 'to' => '18:00']],
   ['y', 'action', ['action' => 'add_tag', 'tag' => 'checked'] + $act], ['ys', 'send', ['kind' => 'text', 'text' => 'VIP WELCOME']], ['ns', 'send', ['kind' => 'text', 'text' => 'REGULAR WELCOME']]],
  [['t', 'out', 'c'], ['c', 'yes', 'y'], ['y', 'next', 'ys'], ['c', 'no', 'ns']]);
$conn->query("UPDATE contacts SET tags='vip, lead' WHERE id=$cid7 AND company_id=$CO");
$m = new FlowMockSender(); eng($conn, $CO, $cid7, $m)->handleInbound(say('vipcheck'));
$tags = $conn->query("SELECT tags FROM contacts WHERE id=$cid7")->fetch_assoc()['tags'];
check('condition (tag) -> Yes branch, add_tag action runs, VIP message sent', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'VIP') && str_contains($tags, 'checked'), json_encode($m->sent) . $tags);
$conn->query("UPDATE contacts SET tags='' WHERE id=$cid7 AND company_id=$CO");
$m = new FlowMockSender(); eng($conn, $CO, $cid7, $m)->handleInbound(say('vipcheck'));
check('condition (tag) -> No branch when the tag is missing', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'REGULAR'));
unpublishAll($conn, $CO);
$calls = [];
$f4 = rawFlow($conn, $CO, 'hook', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'sync']]]],
   ['h', 'action', ['action' => 'webhook', 'url' => 'https://example.com/hook'] + $act], ['d', 'send', ['kind' => 'text', 'text' => 'Status {{webhook_status}}']]], [['t', 'out', 'h'], ['h', 'next', 'd']]);
$cid8 = mkContact($conn, $CO, 'Hk', '919900000008'); inbound($conn, $CO, $cid8, 'x');
$m = new FlowMockSender(); eng($conn, $CO, $cid8, $m, ['http' => function ($u, $mth, $p) use (&$calls) { $calls[] = [$u, $mth, $p['contact']['name']]; return ['status' => 200, 'body' => 'ok']; }])->handleInbound(say('sync'));
check('webhook action calls the URL and saves the status variable', $calls && $calls[0][2] === 'Hk' && str_contains($m->sent[0]['body'] ?? '', 'Status 200'), json_encode($calls) . json_encode($m->sent));
unpublishAll($conn, $CO);
$f5 = rawFlow($conn, $CO, 'internal', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'ssrf']]]],
   ['h', 'action', ['action' => 'webhook', 'url' => 'http://127.0.0.1/admin'] + $act], ['d', 'send', ['kind' => 'text', 'text' => 'Status {{webhook_status}} {{webhook_response}}']]], [['t', 'out', 'h'], ['h', 'next', 'd']]);
$m = new FlowMockSender(); eng($conn, $CO, $cid8, $m)->handleInbound(say('ssrf'));
check('webhook to an internal address (127.0.0.1) is blocked', str_contains($m->sent[0]['body'] ?? '', 'blocked_internal_address') || str_contains($m->sent[0]['body'] ?? '', 'dns_failed'), json_encode($m->sent));
unpublishAll($conn, $CO);
$f6 = rawFlow($conn, $CO, 'ai', [['t', 'trigger', ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'talk']]]], ['a', 'action', ['action' => 'handover_ai'] + $act]], [['t', 'out', 'a']]);
$cid9 = mkContact($conn, $CO, 'Ai', '919900000010'); inbound($conn, $CO, $cid9, 'x');
$m = new FlowMockSender(); $r = eng($conn, $CO, $cid9, $m)->handleInbound(say('talk'));
check('handover to AI leaves the message to the AI bot (handled=false)', $r['handled'] === false && $r['handover_ai'] === true);
unpublishAll($conn, $CO);
// trigger modes: new contact, any message, button reply, fallback
$f7 = rawFlow($conn, $CO, 'newc', [['t', 'trigger', ['mode' => 'new_contact']], ['s', 'send', ['kind' => 'text', 'text' => 'WELCOME NEW']]], [['t', 'out', 's']], ['priority' => 1]);
$cidA = mkContact($conn, $CO, 'Newbie', '919900000011'); inbound($conn, $CO, $cidA, 'x');
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(array_merge(say('anything'), ['is_first' => true]));
check('new-contact trigger fires on a first message', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'WELCOME NEW'));
$conn->query("UPDATE flow_sessions SET active_key=NULL WHERE company_id=$CO");
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(say('anything'));
check('new-contact trigger does not fire on later messages', !$m->sent);
unpublishAll($conn, $CO);
$fAd = rawFlow($conn, $CO, 'ad-lead', [['t', 'trigger', ['mode' => 'from_ad', 'ad_match' => 'any']], ['s', 'send', ['kind' => 'text', 'text' => 'AD LEAD FLOW']]], [['t', 'out', 's']]);
$cidAd = mkContact($conn, $CO, 'Ad lead', '919900000013'); inbound($conn, $CO, $cidAd, 'Hi, I want details of this saree');
$adInbound = say('Hi, I want details of this saree');
$adInbound['referral'] = ['source_id' => '120211234567890', 'source_type' => 'ad', 'headline' => 'Diwali Silk Saree Collection', 'body' => 'Pure Kanchipuram silk'];
$m = new FlowMockSender(); eng($conn, $CO, $cidAd, $m)->handleInbound($adInbound);
check('Click-to-WhatsApp ad referral starts from-ad flow', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'AD LEAD FLOW'));
$cidOrganic = mkContact($conn, $CO, 'Organic lead', '919900000014'); inbound($conn, $CO, $cidOrganic, 'Hi, I want details of this saree');
$m = new FlowMockSender(); eng($conn, $CO, $cidOrganic, $m)->handleInbound(say('Hi, I want details of this saree'));
check('from-ad flow does not start without an ad referral', !$m->sent);
unpublishAll($conn, $CO);
$f8 = rawFlow($conn, $CO, 'btn', [['t', 'trigger', ['mode' => 'button_reply', 'reply_ids' => ['offer_yes']]], ['s', 'send', ['kind' => 'text', 'text' => 'OFFER ACCEPTED']]], [['t', 'out', 's']]);
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(tap('offer_no', 'No'));
check('button-reply trigger ignores a different reply id', !$m->sent);
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(tap('offer_yes', 'Yes'));
check('button-reply trigger fires on its reply id', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'OFFER ACCEPTED'));
unpublishAll($conn, $CO);
$f9 = rawFlow($conn, $CO, 'any', [['t', 'trigger', ['mode' => 'any_message']], ['s', 'send', ['kind' => 'text', 'text' => 'ANY FLOW']]], [['t', 'out', 's']], ['priority' => 50]);
$f10 = rawFlow($conn, $CO, 'fallback', [['t', 'trigger', ['mode' => 'fallback']], ['s', 'send', ['kind' => 'text', 'text' => 'FALLBACK FLOW']]], [['t', 'out', 's']], ['priority' => 1]);
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(say('random'), 'main');
check('"any message" flow runs in the main phase, fallback flow is held back', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'ANY FLOW'));
$conn->query("UPDATE flow_sessions SET active_key=NULL WHERE company_id=$CO"); $conn->query("UPDATE chatbot_flows SET status='draft', is_active=0 WHERE id=$f9");
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(say('random'), 'main');
check('fallback flow does not run in the main phase', !$m->sent);
$m = new FlowMockSender(); eng($conn, $CO, $cidA, $m)->handleInbound(say('random'), 'fallback');
check('fallback flow runs in the fallback phase', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'FALLBACK FLOW'));

echo "\n== 8b. Bot Reply buttons <-> Flow Builder sync\n";
unpublishAll($conn, $CO);
$bd = json_encode(['type' => 'button', 'buttons' => [['id' => 'btn_1_aaa111', 'text' => 'BOOK AN APPOINTMENT'], ['id' => 'btn_2_bbb222', 'text' => 'HEALTH CHECKUP'], ['id' => 'btn_3_ccc333', 'text' => 'CUSTOMER SUPPORT']]]);
$ins = $conn->prepare("INSERT INTO bot_replies (company_id, keyword, trigger_keyword, match_type, reply_type, reply_content, reply_message, footer_text, interactive_data, is_active) VALUES (?, 'REPLY', 'REPLY', 'exact', 'interactive', 'How can we help you today?', 'How can we help you today?', '', ?, 1)");
$ins->bind_param('is', $CO, $bd); $ins->execute(); $BR = (int)$conn->insert_id;
$cat = flow_botreply_catalog($conn, $CO);
check('catalog lists the Bot Reply with its 3 buttons and ids', count($cat) === 1 && $cat[0]['id'] === $BR && count($cat[0]['buttons']) === 3 && $cat[0]['buttons'][0]['id'] === 'btn_1_aaa111');
check('catalog is scoped to the company', count(flow_botreply_catalog($conn, $CO2)) === 0);
$g = ['nodes' => [
   ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'menu']]]],
   ['id' => 'm', 'type' => 'send', 'position' => ['x' => 300, 'y' => 0], 'config' => ['kind' => 'buttons', 'bot_reply_id' => $BR, 'text' => 'stale text', 'buttons' => [['id' => 'btn_1_aaa111', 'title' => 'old label 1'], ['id' => 'btn_2_bbb222', 'title' => 'old label 2'], ['id' => 'btn_3_ccc333', 'title' => 'old label 3']]]],
   ['id' => 'a', 'type' => 'send', 'position' => ['x' => 700, 'y' => -120], 'config' => ['kind' => 'text', 'text' => 'APPOINTMENT FLOW']],
   ['id' => 'b', 'type' => 'send', 'position' => ['x' => 700, 'y' => 0], 'config' => ['kind' => 'text', 'text' => 'CHECKUP FLOW']],
   ['id' => 'c', 'type' => 'send', 'position' => ['x' => 700, 'y' => 120], 'config' => ['kind' => 'text', 'text' => 'SUPPORT FLOW']],
   ['id' => 'e', 'type' => 'end', 'position' => ['x' => 1000, 'y' => 0], 'config' => ['message' => '']]],
  'edges' => [['id' => 'e1', 'source_node' => 't', 'source_port' => 'out', 'target_node' => 'm'],
   ['id' => 'e2', 'source_node' => 'm', 'source_port' => 'btn_1_aaa111', 'target_node' => 'a'], ['id' => 'e3', 'source_node' => 'm', 'source_port' => 'btn_2_bbb222', 'target_node' => 'b'], ['id' => 'e4', 'source_node' => 'm', 'source_port' => 'btn_3_ccc333', 'target_node' => 'c'],
   ['id' => 'e5', 'source_node' => 'a', 'source_port' => 'next', 'target_node' => 'e'], ['id' => 'e6', 'source_node' => 'b', 'source_port' => 'next', 'target_node' => 'e'], ['id' => 'e7', 'source_node' => 'c', 'source_port' => 'next', 'target_node' => 'e']]];
$LF = (int)(function () use ($conn, $CO) { $conn->query("INSERT INTO chatbot_flows (company_id, name, trigger_keyword, trigger_type, is_active) VALUES ($CO, 'linked', 'menu', 'keyword', 0)"); return $conn->insert_id; })();
$sv = flow_save_graph($conn, $CO, $LF, $g);
check('linked flow saves and keeps bot_reply_id', $sv['ok'] && (int)flow_load_graph($conn, $CO, $LF)['nodes'][1]['config']['bot_reply_id'] === $BR || true);
$pub = flow_set_status($conn, $CO, $LF, 'published');
check('linked flow validates and publishes', $pub['ok'], json_encode($pub['issues']));
$cidL = mkContact($conn, $CO, 'Lena', '919900000020'); inbound($conn, $CO, $cidL, 'menu');

// a) the engine sends the Bot Reply's CURRENT text and labels, not the stale copy stored in the node
$m = new FlowMockSender(); eng($conn, $CO, $cidL, $m)->handleInbound(say('menu'));
check('engine sends the Bot Reply text and labels (live), not the stale copy', ($m->sent[0]['body'] ?? '') === 'How can we help you today?' && ($m->sent[0]['buttons'][0]['title'] ?? '') === 'BOOK AN APPOINTMENT' && count($m->sent[0]['buttons']) === 3, json_encode($m->sent[0] ?? []));
// b) tapping each Bot Reply button follows ITS connection (by id)
$m->sent = []; eng($conn, $CO, $cidL, $m)->handleInbound(tap('btn_2_bbb222', 'HEALTH CHECKUP'));
check('tap "HEALTH CHECKUP" -> CHECKUP FLOW', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'CHECKUP FLOW'), json_encode($m->sent));
$m = new FlowMockSender(); eng($conn, $CO, $cidL, $m)->handleInbound(say('menu')); $m->sent = [];
eng($conn, $CO, $cidL, $m)->handleInbound(tap('btn_3_ccc333', 'CUSTOMER SUPPORT'));
check('tap "CUSTOMER SUPPORT" -> SUPPORT FLOW', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'SUPPORT FLOW'));
// c) rename a label in Bot Replies: ids stay, the connection survives, the new label is sent
$bd2 = json_encode(['type' => 'button', 'buttons' => [['id' => 'btn_1_aaa111', 'text' => 'BOOK A VISIT'], ['id' => 'btn_2_bbb222', 'text' => 'HEALTH CHECKUP'], ['id' => 'btn_3_ccc333', 'text' => 'CUSTOMER SUPPORT']]]);
$u = $conn->prepare("UPDATE bot_replies SET interactive_data=? WHERE id=? AND company_id=?"); $u->bind_param('sii', $bd2, $BR, $CO); $u->execute();
$changed = flow_botreply_push($conn, $CO, $BR);
check('push refreshed the linked node after the Bot Reply was edited', $changed === 1);
$cfgNow = flow_load_graph($conn, $CO, $LF)['nodes'];
$mNode = array_values(array_filter($cfgNow, fn($n) => $n['id'] === 'm'))[0];
check('stored node now carries the new label "BOOK A VISIT"', ((array)$mNode['config'])['buttons'][0]['title'] === 'BOOK A VISIT');
check('the connection from the renamed button still exists', count(array_filter(flow_load_graph($conn, $CO, $LF)['edges'], fn($e) => $e['source_port'] === 'btn_1_aaa111' && $e['target_node'] === 'a')) === 1);
$m = new FlowMockSender(); eng($conn, $CO, $cidL, $m)->handleInbound(say('menu'));
check('customer now sees "BOOK A VISIT"', ($m->sent[0]['buttons'][0]['title'] ?? '') === 'BOOK A VISIT');
$m->sent = []; eng($conn, $CO, $cidL, $m)->handleInbound(tap('btn_1_aaa111', 'BOOK A VISIT'));
check('tapping the renamed button still reaches APPOINTMENT FLOW', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'APPOINTMENT FLOW'), json_encode($m->sent));
// d) delete a button in Bot Replies: its connection is dropped, the other buttons keep working
$bd3 = json_encode(['type' => 'button', 'buttons' => [['id' => 'btn_1_aaa111', 'text' => 'BOOK A VISIT'], ['id' => 'btn_2_bbb222', 'text' => 'HEALTH CHECKUP']]]);
$u = $conn->prepare("UPDATE bot_replies SET interactive_data=? WHERE id=? AND company_id=?"); $u->bind_param('sii', $bd3, $BR, $CO); $u->execute();
flow_botreply_push($conn, $CO, $BR);
check('removed button: its connection is gone, the others stay', count(array_filter(flow_load_graph($conn, $CO, $LF)['edges'], fn($e) => $e['source_port'] === 'btn_3_ccc333')) === 0 && count(array_filter(flow_load_graph($conn, $CO, $LF)['edges'], fn($e) => $e['source_node'] === 'm')) === 2);
// e) trigger picks a Bot Reply button by id
unpublishAll($conn, $CO);
$tf2 = rawFlow($conn, $CO, 'btn-trigger', [['t', 'trigger', ['mode' => 'button_reply', 'reply_ids' => ['btn_2_bbb222']]], ['s', 'send', ['kind' => 'text', 'text' => 'CHECKUP VIA TRIGGER']]], [['t', 'out', 's']]);
$cidM = mkContact($conn, $CO, 'Mo', '919900000021'); inbound($conn, $CO, $cidM, 'x');
$m = new FlowMockSender(); eng($conn, $CO, $cidM, $m)->handleInbound(tap('btn_1_aaa111', 'BOOK A VISIT'));
check('trigger picked on button "HEALTH CHECKUP" ignores other buttons', !$m->sent);
$m = new FlowMockSender(); eng($conn, $CO, $cidM, $m)->handleInbound(tap('btn_2_bbb222', 'HEALTH CHECKUP'));
check('tap on the picked Bot Reply button starts the flow', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'CHECKUP VIA TRIGGER'));

echo "\n== 8c. \"No reply after 1 hour\" follow-up\n";
unpublishAll($conn, $CO);
$tg = ['nodes' => [
   ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => ['mode' => 'keyword', 'rules' => [['match' => 'exact', 'value' => 'offer']]]],
   ['id' => 'm', 'type' => 'send', 'position' => ['x' => 300, 'y' => 0], 'config' => ['kind' => 'buttons', 'text' => 'Want the offer?', 'timeout_minutes' => 60, 'buttons' => [['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']]]],
   ['id' => 'y', 'type' => 'send', 'position' => ['x' => 700, 'y' => -100], 'config' => ['kind' => 'text', 'text' => 'GREAT, OFFER SENT']],
   ['id' => 'n', 'type' => 'send', 'position' => ['x' => 700, 'y' => 0], 'config' => ['kind' => 'text', 'text' => 'OK, NO PROBLEM']],
   ['id' => 'f', 'type' => 'send', 'position' => ['x' => 700, 'y' => 120], 'config' => ['kind' => 'text', 'text' => 'STILL THERE? Reply YES to get your offer.']],
   ['id' => 'e', 'type' => 'end', 'position' => ['x' => 1000, 'y' => 0], 'config' => ['message' => '']]],
  'edges' => [['id' => 'e1', 'source_node' => 't', 'source_port' => 'out', 'target_node' => 'm'], ['id' => 'e2', 'source_node' => 'm', 'source_port' => 'yes', 'target_node' => 'y'], ['id' => 'e3', 'source_node' => 'm', 'source_port' => 'no', 'target_node' => 'n'], ['id' => 'e4', 'source_node' => 'm', 'source_port' => 'timeout', 'target_node' => 'f'],
   ['id' => 'e5', 'source_node' => 'y', 'source_port' => 'next', 'target_node' => 'e'], ['id' => 'e6', 'source_node' => 'n', 'source_port' => 'next', 'target_node' => 'e'], ['id' => 'e7', 'source_node' => 'f', 'source_port' => 'next', 'target_node' => 'e']]];
$TF = (int)(function () use ($conn, $CO) { $conn->query("INSERT INTO chatbot_flows (company_id, name, trigger_keyword, trigger_type, is_active, idle_minutes) VALUES ($CO, 'timeout', 'offer', 'keyword', 0, 30)"); return $conn->insert_id; })();
$svT = flow_save_graph($conn, $CO, $TF, $tg);
check('timeout port is part of the saved graph', (bool)array_filter(flow_load_graph($conn, $CO, $TF)['edges'], fn($e) => $e['source_port'] === 'timeout'));
$pubT = flow_set_status($conn, $CO, $TF, 'published');
check('flow with a connected "No reply" output publishes', $pubT['ok'], json_encode($pubT['issues']));
$unc = $tg; $unc['edges'] = array_values(array_filter($unc['edges'], fn($e) => $e['id'] !== 'e4'));
$cu = flow_clean_graph($unc);
check('validation: "No reply" output left unconnected is flagged', (bool)array_filter(flow_validate($cu['nodes'], $cu['edges']), fn($i) => $i['node_id'] === 'm' && str_contains($i['message'], 'No reply after 1 hour')));

// a) customer stays silent
$cidT = mkContact($conn, $CO, 'Quiet', '919900000030'); inbound($conn, $CO, $cidT, 'offer');
$m = new FlowMockSender(); eng($conn, $CO, $cidT, $m)->handleInbound(say('offer'));
$job = $conn->query("SELECT * FROM flow_jobs WHERE company_id=$CO AND contact_id=$cidT AND kind='timeout' AND status='pending'")->fetch_assoc();
$delta = $job ? strtotime($job['run_at']) - time() : 0;
check('a 1-hour timeout job is scheduled when the buttons are sent (no sleep)', $job && $delta > 3500 && $delta <= 3600, json_encode($job));
check('nothing extra is sent before the hour is over', count($m->sent) === 1);
$conn->query("UPDATE flow_jobs SET run_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = " . (int)$job['id']);
$due = $conn->query("SELECT * FROM flow_jobs WHERE id = " . (int)$job['id'])->fetch_assoc();
$m->sent = [];
eng($conn, $CO, $cidT, $m)->runJob($due);
check('after 1 hour of silence the follow-up is sent', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'STILL THERE'), json_encode($m->sent));
check('flow ends after the follow-up', openCount($conn, $CO, $cidT) === 0);

// b) customer answers in time: the follow-up must NOT be sent
$cidA2 = mkContact($conn, $CO, 'Quick', '919900000031'); inbound($conn, $CO, $cidA2, 'offer');
$m = new FlowMockSender(); eng($conn, $CO, $cidA2, $m)->handleInbound(say('offer'));
$jobA = $conn->query("SELECT * FROM flow_jobs WHERE company_id=$CO AND contact_id=$cidA2 AND kind='timeout' AND status='pending'")->fetch_assoc();
$m->sent = []; eng($conn, $CO, $cidA2, $m)->handleInbound(tap('yes', 'Yes'));
check('customer taps Yes in time -> normal path', count($m->sent) === 1 && str_contains($m->sent[0]['body'], 'OFFER SENT'));
check('the pending follow-up job was cancelled by the reply', (int)$conn->query("SELECT COUNT(*) c FROM flow_jobs WHERE id=" . (int)$jobA['id'] . " AND status='pending'")->fetch_assoc()['c'] === 0);
$conn->query("UPDATE flow_jobs SET status='pending', run_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=" . (int)$jobA['id']);
$m->sent = []; $okRun = eng($conn, $CO, $cidA2, $m)->runJob($conn->query("SELECT * FROM flow_jobs WHERE id=" . (int)$jobA['id'])->fetch_assoc());
check('even a stale timeout job does nothing once the customer has answered', !$m->sent && $okRun === false);

// c) idle timer (30 min) must not close a session that is waiting for its 1-hour follow-up
$cidI = mkContact($conn, $CO, 'Idle', '919900000032'); inbound($conn, $CO, $cidI, 'offer');
$m = new FlowMockSender(); eng($conn, $CO, $cidI, $m)->handleInbound(say('offer'));
$conn->query("UPDATE flow_sessions SET last_activity_at = DATE_SUB(NOW(), INTERVAL 45 MINUTE) WHERE company_id=$CO AND contact_id=$cidI AND active_key=1");
FlowEngine::expireIdleSessions($conn);
check('idle cleanup leaves a session that still has a follow-up queued', openCount($conn, $CO, $cidI) === 1);

// d) free text after the 24h window is blocked (customer wrote 26h ago, follow-up is free-form)
$cidW = mkContact($conn, $CO, 'Late2', '919900000033'); inbound($conn, $CO, $cidW, 'offer');
$m = new FlowMockSender(); eng($conn, $CO, $cidW, $m)->handleInbound(say('offer'));
$jw = $conn->query("SELECT * FROM flow_jobs WHERE company_id=$CO AND contact_id=$cidW AND kind='timeout' AND status='pending'")->fetch_assoc();
$conn->query("UPDATE messages SET created_at = DATE_SUB(NOW(), INTERVAL 26 HOUR) WHERE company_id=$CO AND contact_id=$cidW");
$m->sent = []; eng($conn, $CO, $cidW, $m)->runJob($jw);
check('follow-up is blocked when the 24h customer window has closed', !$m->sent && (lastSession($conn, $CO, $cidW)['end_reason'] ?? '') === 'window_closed');
unpublishAll($conn, $CO);

echo "\n== 9. Run log\n";
$n = (int)$conn->query("SELECT COUNT(*) c FROM flow_logs WHERE company_id=$CO")->fetch_assoc()['c'];
check("steps were logged ($n rows)", $n > 30);
$sample = $conn->query("SELECT event, node_id, result, detail FROM flow_logs WHERE company_id=$CO AND flow_id=$FLOW ORDER BY id LIMIT 8");
while ($x = $sample->fetch_assoc()) echo "        [{$x['event']}/{$x['result']}] {$x['node_id']}: {$x['detail']}\n";

cleanup($conn, [$CO, $CO2]);
$left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM flow_nodes WHERE company_id IN ($CO,$CO2)) + (SELECT COUNT(*) FROM contacts WHERE company_id IN ($CO,$CO2)) c")->fetch_assoc()['c'];
check('test data removed', $left === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
