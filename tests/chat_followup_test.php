<?php
/**
 * Account auto follow-up ("no reply after 1 hour") test. Fake companies 99011 / 99012 only; everything is removed at the end.
 *   php tests/chat_followup_test.php
 */
chdir(dirname(__DIR__));
define('WAAMINI_LOADED', true);
require 'db.php';
require_once __DIR__ . '/../includes/chat_followup.php';
require_once __DIR__ . '/../includes/flow/engine.php';

$A = 99011; $B = 99012;
$pass = 0; $fail = 0;
function check(string $n, bool $ok, string $x = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n $x\n"; } }
function wipe(mysqli $c, array $cos): void { foreach ($cos as $co) foreach (['chat_followup_log', 'chat_followup_settings', 'flow_sessions', 'messages', 'contacts'] as $t) $c->query("DELETE FROM $t WHERE company_id = $co"); }
wipe($conn, [$A, $B]);
chat_followup_ensure_schema($conn); flow_ensure_schema($conn);

function contact(mysqli $c, int $co, string $name, string $phone): int { $st = $c->prepare("INSERT INTO contacts (company_id, name, phone) VALUES (?,?,?)"); $st->bind_param('iss', $co, $name, $phone); $st->execute(); return (int)$c->insert_id; }
function msg(mysqli $c, int $co, int $cid, string $dir, string $text, int $minutesAgo): int {
    $st = $c->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, is_read, status, created_at) VALUES (?,?,?, 'text', ?, ?, 0, 'sent', DATE_SUB(NOW(), INTERVAL ? MINUTE))");
    $st->bind_param('iisssi', $co, $cid, $dir, $text, $text, $minutesAgo); $st->execute(); return (int)$c->insert_id;
}
$sentTo = [];
$factory = function (int $co, int $cid, string $phone) use (&$sentTo) {
    return new class($sentTo, $co, $cid) implements FlowSender {
        public function __construct(private array &$log, private int $co, private int $cid) {}
        public function text(string $body): array { $this->log[] = ['co' => $this->co, 'cid' => $this->cid, 'body' => $body]; return ['ok' => true, 'id' => 'm', 'error' => null]; }
        public function media(string $t, string $u, string $c = '', string $f = ''): array { return ['ok' => true, 'id' => null, 'error' => null]; }
        public function buttons(string $b, array $x, string $f = ''): array { return ['ok' => true, 'id' => null, 'error' => null]; }
        public function lists(string $b, string $t, array $r, string $f = ''): array { return ['ok' => true, 'id' => null, 'error' => null]; }
        public function template(string $n, string $l, array $v = []): array { return ['ok' => true, 'id' => null, 'error' => null]; }
    };
};

echo "\n== settings\n";
$d = chat_followup_get($conn, $A);
check('defaults: off, 60 minutes, polite default text', !$d['enabled'] && $d['after_minutes'] === 60 && $d['message'] !== '');
chat_followup_save($conn, $A, true, 60, 'Hi {{contact.first_name}}, we got your message and will reply soon.');
$d = chat_followup_get($conn, $A);
check('save: enabled, 60 minutes, custom text', $d['enabled'] && $d['after_minutes'] === 60 && str_contains($d['message'], 'will reply soon'));
chat_followup_save($conn, $B, true, 5000, '');
check('minutes are capped at 23 hours and an empty text falls back to the default', chat_followup_get($conn, $B)['after_minutes'] === 1380 && chat_followup_get($conn, $B)['message'] === CHAT_FOLLOWUP_DEFAULT_TEXT);
chat_followup_save($conn, $B, false, 60, '');

echo "\n== who gets the 1-hour reply\n";
$silent   = contact($conn, $A, 'Asha Rao', '919900010001'); msg($conn, $A, $silent, 'incoming', 'Need help with my order', 75);
$recent   = contact($conn, $A, 'Recent', '919900010002');   msg($conn, $A, $recent, 'incoming', 'Hello', 20);
$answered = contact($conn, $A, 'Answered', '919900010003'); msg($conn, $A, $answered, 'incoming', 'Price?', 90); msg($conn, $A, $answered, 'outgoing', 'Rs 999', 80);
$late     = contact($conn, $A, 'Late', '919900010004');     msg($conn, $A, $late, 'incoming', 'Anyone there?', 26 * 60);
$inflow   = contact($conn, $A, 'InFlow', '919900010005');   msg($conn, $A, $inflow, 'incoming', 'hi', 120);
$conn->query("INSERT INTO flow_sessions (company_id, contact_id, flow_id, current_node_id, waiting_for, status, active_key, variables, started_at, last_activity_at) VALUES ($A,$inflow,1,'m','choice','waiting',1,'{}',NOW(),NOW())");
$twice    = contact($conn, $A, 'Twice', '919900010006');    msg($conn, $A, $twice, 'incoming', 'first', 200); msg($conn, $A, $twice, 'incoming', 'second', 70);
$other    = contact($conn, $B, 'OtherCo', '919900010007');  msg($conn, $B, $other, 'incoming', 'hello', 300);   // company B has it switched OFF

$n = ChatFollowup::process($conn, 50, $factory);
$ids = array_column($sentTo, 'cid');
check('silent customer (75 min, no reply) gets the follow-up', in_array($silent, $ids, true));
check('message uses the contact first name', (bool)array_filter($sentTo, fn($s) => $s['cid'] === $silent && str_contains($s['body'], 'Hi Asha,')), json_encode($sentTo));
check('customer who wrote 20 minutes ago is not followed up yet', !in_array($recent, $ids, true));
check('customer already answered by the team is skipped', !in_array($answered, $ids, true));
check('customer outside the 24h window is skipped', !in_array($late, $ids, true));
check('customer inside an open flow conversation is skipped', !in_array($inflow, $ids, true));
check('only ONE follow-up when the customer wrote twice', count(array_filter($ids, fn($i) => $i === $twice)) === 1);
check('another company (feature off) is untouched', !in_array($other, $ids, true));
check('exactly the 2 expected customers were messaged', $n === 2 && count($sentTo) === 2, "n=$n sent=" . count($sentTo));

echo "\n== never twice\n";
$before = count($sentTo);
ChatFollowup::process($conn, 50, $factory);
check('running the worker again sends nothing more', count($sentTo) === $before);
$conn->query("UPDATE chat_followup_log SET status = status WHERE company_id = $A");
msg($conn, $A, $silent, 'incoming', 'Hello??', 70);   // customer writes again and is ignored again
ChatFollowup::process($conn, 50, $factory);
check('a NEW unanswered message gets its own follow-up', count($sentTo) === $before + 1);
$rows = (int)$conn->query("SELECT COUNT(*) c FROM chat_followup_log WHERE company_id = $A AND status = 'sent'")->fetch_assoc()['c'];
check('every follow-up is logged', $rows === 3, (string)$rows);

echo "\n== a reply stops it, switching off stops it\n";
$q = contact($conn, $A, 'Quick', '919900010008'); msg($conn, $A, $q, 'incoming', 'hi', 61); msg($conn, $A, $q, 'outgoing', 'Hello! How can I help?', 5);
$b4 = count($sentTo); ChatFollowup::process($conn, 50, $factory);
check('agent replied before the hour was over: nothing sent', count($sentTo) === $b4);
chat_followup_save($conn, $A, false, 60, '');
$z = contact($conn, $A, 'Zed', '919900010009'); msg($conn, $A, $z, 'incoming', 'hi', 120);
$b5 = count($sentTo); ChatFollowup::process($conn, 50, $factory);
check('switched off: nothing is sent', count($sentTo) === $b5);

echo "\n== real sender path (no WhatsApp credentials here, so the send fails but must be logged and not retried)\n";
chat_followup_save($conn, $A, true, 60, 'real path');
$r = contact($conn, $A, 'Real', '919900010010'); msg($conn, $A, $r, 'incoming', 'hi', 90);
$bad = ChatFollowup::process($conn, 50);
$lg = $conn->query("SELECT status, error FROM chat_followup_log WHERE company_id = $A AND contact_id = $r")->fetch_assoc();
check('failed send is logged as failed with the reason', $lg && $lg['status'] === 'failed' && $lg['error'] !== '', json_encode($lg));
$msgRow = $conn->query("SELECT status FROM messages WHERE company_id = $A AND contact_id = $r AND direction = 'outgoing'")->fetch_assoc();
check('the attempt appears in the chat (marked failed)', $msgRow && $msgRow['status'] === 'failed', json_encode($msgRow));
ChatFollowup::process($conn, 50);
check('a failed send is not retried every minute', (int)$conn->query("SELECT COUNT(*) c FROM chat_followup_log WHERE company_id = $A AND contact_id = $r")->fetch_assoc()['c'] === 1);

wipe($conn, [$A, $B]);
check('test data removed', (int)$conn->query("SELECT COUNT(*) c FROM contacts WHERE company_id IN ($A,$B)")->fetch_assoc()['c'] === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
