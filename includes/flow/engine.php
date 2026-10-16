<?php
/**
 * Flow Builder execution engine.
 *
 * Rules (the canvas graph is the only source of truth):
 *  - A contact with an open session resumes it; otherwise the triggers of PUBLISHED flows are checked in priority order.
 *  - From the current node only the edges saved on the canvas are followed. A node that is not on the path never runs.
 *  - Buttons and list rows are matched by reply ID to the output port with the same id, never by text.
 *  - Waiting nodes (buttons, list, question, delay) store the session and stop. The next inbound message (or the
 *    delay job) continues from there.
 *  - No match: follow the "fallback" port; if none, resend the question once, then end the session.
 *  - Delays are rows in flow_jobs run by cron_flow_worker.php, never sleep().
 *  - Free-form messages are only sent inside WhatsApp's 24 hour window; otherwise only templates.
 *  - A run stops after max_steps nodes per inbound message (loop guard).
 *  - Every step is written to flow_logs. Every query is scoped by company_id.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/sender.php';
require_once __DIR__ . '/botreply.php';

/**
 * Normalise a Meta webhook message for the engine.
 * kind: text | reply (button / list / template quick reply) | media | other
 */
function flow_inbound_from_message(array $message, bool $isFirst = false): array
{
    $type = (string)($message['type'] ?? 'text');
    $in = ['kind' => 'other', 'text' => '', 'reply_id' => null, 'reply_title' => null, 'is_first' => $isFirst];
    if ($type === 'text') {
        $in['kind'] = 'text';
        $in['text'] = trim((string)($message['text']['body'] ?? ''));
    } elseif ($type === 'interactive') {
        $it = (string)($message['interactive']['type'] ?? '');
        $sub = $message['interactive'][$it] ?? [];
        if (in_array($it, ['button_reply', 'list_reply'], true) && is_array($sub)) {
            $in['kind'] = 'reply';
            $in['reply_id'] = (string)($sub['id'] ?? '');
            $in['reply_title'] = (string)($sub['title'] ?? '');
            $in['text'] = trim((string)$in['reply_title']);
        }
    } elseif ($type === 'button') {
        $in['kind'] = 'reply';
        $in['reply_id'] = (string)($message['button']['payload'] ?? '');
        $in['reply_title'] = (string)($message['button']['text'] ?? '');
        $in['text'] = trim((string)$in['reply_title']);
    } elseif (in_array($type, ['image', 'video', 'audio', 'voice', 'document', 'sticker', 'location'], true)) {
        $in['kind'] = 'media';
    }
    // Click-to-WhatsApp ad: the first message of an ad session carries a referral (any message type)
    $r = $message['referral'] ?? null;
    $in['referral'] = is_array($r) && $r ? [
        'source_id'   => (string)($r['source_id'] ?? ''),
        'source_type' => strtolower((string)($r['source_type'] ?? '')),
        'headline'    => (string)($r['headline'] ?? ''),
        'body'        => (string)($r['body'] ?? ''),
    ] : null;
    return $in;
}

final class FlowEngine
{
    private mysqli $conn;
    private int $companyId;
    private int $contactId;
    private FlowSender $sender;
    /** @var callable|null  fn(string $url, string $method, array $payload): array{status:int, body:string} */
    private $http;
    /** @var callable|null  fn(): bool  overrides the 24h window check (tests) */
    private $windowCheck;

    private ?array $contact = null;
    private array $graphCache = [];
    private bool $handoverAi = false;
    private int $sentThisRun = 0;

    public function __construct(mysqli $conn, int $companyId, int $contactId, FlowSender $sender, array $opts = [])
    {
        $this->conn = $conn;
        $this->companyId = $companyId;
        $this->contactId = $contactId;
        $this->sender = $sender;
        $this->http = $opts['http'] ?? null;
        $this->windowCheck = $opts['window'] ?? null;
        flow_ensure_schema($conn);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Entry point
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * @param array  $in    from flow_inbound_from_message()
     * @param string $phase 'resume' = only continue an open session; 'main' = resume, else start a flow by its trigger;
     *                      'fallback' = only "default fallback" flows (called last, after bot replies and main flows)
     * @return array{handled:bool,flow_id:int,session_id:int,reason:string,handover_ai:bool}
     */
    public function handleInbound(array $in, string $phase = 'main'): array
    {
        $none = ['handled' => false, 'flow_id' => 0, 'session_id' => 0, 'reason' => 'no_match', 'handover_ai' => false];
        if ($this->companyId <= 0 || $this->contactId <= 0) return $none;
        $this->handoverAi = false;
        $this->sentThisRun = 0;

        try {
            if ($phase === 'main' || $phase === 'resume') {
                $s = $this->openSession();
                if ($s) {
                    $flow = $this->flowRow((int)$s['flow_id']);
                    $idle = $flow ? (int)$flow['idle_minutes'] : 120;
                    if (strtotime((string)$s['last_activity_at']) < time() - max(1, $idle) * 60 && !$this->hasPendingTimeout((int)$s['id'])) {
                        $this->closeSession($s, 'expired', 'idle_timeout');
                        $this->log((int)$s['flow_id'], (int)$s['id'], null, null, 'expire', 'ok', 'Idle for more than ' . $idle . ' minutes');
                        $s = null;
                    }
                }
                if ($s) {
                    $flow = $this->flowRow((int)$s['flow_id']);
                    if (!$flow || $flow['status'] !== 'published') {
                        $this->closeSession($s, 'completed', 'unpublished');
                        $this->log((int)$s['flow_id'], (int)$s['id'], null, null, 'end', 'ok', 'Flow is no longer published');
                    } elseif ($s['waiting_for'] === 'delay') {
                        return ['handled' => false, 'flow_id' => (int)$s['flow_id'], 'session_id' => (int)$s['id'], 'reason' => 'delay_pending', 'handover_ai' => false];
                    } else {
                        $this->resume($s, $in);
                        return $this->result((int)$s['flow_id'], (int)$s['id'], 'resumed');
                    }
                }
            }

            if ($phase === 'resume') return $none;   // only continue an open session, never start a flow here

            foreach ($this->publishedFlows() as $flow) {
                $graph = $this->graph((int)$flow['id']);
                $trigId = $graph['trigger'] ?? null;
                if (!$trigId) continue;
                $cfg = $graph['nodes'][$trigId]['config'];
                $mode = (string)($cfg['mode'] ?? 'keyword');
                if (($phase === 'fallback') !== ($mode === 'fallback')) continue;
                if (!$this->triggerMatches($cfg, $in)) continue;
                if ($mode === 'any_message' && $this->ranRecently((int)$flow['id'], (int)($cfg['cooldown_hours'] ?? 12))) continue;   // do not restart on every message

                $s = $this->startSession((int)$flow['id'], $trigId, $in);
                if (!$s) continue;
                $this->log((int)$flow['id'], (int)$s['id'], $trigId, 'trigger', 'trigger', 'ok', 'Matched ' . $mode . ($in['text'] !== '' ? ': "' . mb_substr($in['text'], 0, 80) . '"' : ''));
                $this->run($s, $trigId, $in);
                return $this->result((int)$flow['id'], (int)$s['id'], 'started');
            }
        } catch (\Throwable $e) {
            error_log('[FlowEngine] ' . $e->getMessage());
            $this->log(0, 0, null, null, 'engine', 'error', $e->getMessage());
        }
        return $none;
    }

    /** A flow that only handed over to the AI bot without sending anything leaves the message to the AI. */
    private function result(int $flowId, int $sessionId, string $reason): array
    {
        $handled = !($this->handoverAi && $this->sentThisRun === 0);
        return ['handled' => $handled, 'flow_id' => $flowId, 'session_id' => $sessionId, 'reason' => $reason, 'handover_ai' => $this->handoverAi];
    }

    /** Continue a delayed session (called by the cron worker for a due job). */
    public function runJob(array $job): bool
    {
        $sid = (int)$job['session_id'];
        $st = $this->conn->prepare("SELECT * FROM flow_sessions WHERE id = ? AND company_id = ? AND contact_id = ? AND active_key = 1 LIMIT 1");
        $st->bind_param('iii', $sid, $this->companyId, $this->contactId);
        $st->execute();
        $s = $st->get_result()->fetch_assoc();
        $st->close();
        $isTimeout = (($job['kind'] ?? 'delay') === 'timeout');
        if (!$s || $s['current_node_id'] !== $job['node_id']) return false;
        if ($isTimeout ? !in_array($s['waiting_for'], ['choice', 'answer'], true) : $s['waiting_for'] !== 'delay') return false;   // the customer already replied: nothing to do

        $flow = $this->flowRow((int)$s['flow_id']);
        if (!$flow || $flow['status'] !== 'published') { $this->closeSession($s, 'completed', 'unpublished'); return true; }
        $graph = $this->graph((int)$s['flow_id']);
        $next = $graph['edges'][$job['node_id'] . '|' . ($isTimeout ? 'timeout' : 'next')] ?? null;
        if ($isTimeout) $this->log((int)$s['flow_id'], $sid, $job['node_id'], $graph['nodes'][$job['node_id']]['type'] ?? null, 'timeout', 'ok', 'No reply, following the "No reply" output');
        else $this->log((int)$s['flow_id'], $sid, $job['node_id'], 'delay', 'resume', 'ok', 'Wait finished');
        $s['waiting_for'] = '';
        if (!$next) { $this->closeSession($s, 'completed', $isTimeout ? 'timeout' : 'no_next'); return true; }
        $this->run($s, $next, ['kind' => 'other', 'text' => '', 'reply_id' => null, 'reply_title' => null, 'is_first' => false]);
        return true;
    }

    /** Close sessions that were idle for longer than their flow allows (cron). */
    public static function expireIdleSessions(mysqli $conn): int
    {
        flow_ensure_schema($conn);
        $n = 0;
        $r = $conn->query("SELECT s.id, s.company_id, s.flow_id, f.idle_minutes FROM flow_sessions s JOIN chatbot_flows f ON f.id = s.flow_id AND f.company_id = s.company_id
                           WHERE s.active_key = 1 AND s.waiting_for <> 'delay' AND NOT EXISTS (SELECT 1 FROM flow_jobs j WHERE j.session_id = s.id AND j.status = 'pending') AND s.last_activity_at < DATE_SUB(NOW(), INTERVAL f.idle_minutes MINUTE) LIMIT 500");
        while ($r && ($x = $r->fetch_assoc())) {
            $u = $conn->prepare("UPDATE flow_sessions SET status='expired', active_key=NULL, end_reason='idle_timeout', ended_at=NOW() WHERE id=? AND company_id=?");
            $u->bind_param('ii', $x['id'], $x['company_id']);
            $u->execute();
            $u->close();
            $l = $conn->prepare("INSERT INTO flow_logs (company_id, flow_id, session_id, event, result, detail, created_at) VALUES (?,?,?, 'expire', 'ok', 'Idle timeout', NOW())");
            $l->bind_param('iii', $x['company_id'], $x['flow_id'], $x['id']);
            $l->execute();
            $l->close();
            $n++;
        }
        return $n;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Triggers
    // ═════════════════════════════════════════════════════════════════════════

    private function triggerMatches(array $cfg, array $in): bool
    {
        switch ((string)($cfg['mode'] ?? 'keyword')) {
            case 'any_message':
            case 'fallback':
                return true;
            case 'new_contact':
                return !empty($in['is_first']);
            case 'from_ad':
                // "Lead came from an ad": any ad, specific ad IDs (comma separated), or headline / ad text contains
                $ref = $in['referral'] ?? null;
                if (!is_array($ref)) return false;
                $val = trim((string)($cfg['ad_value'] ?? ''));
                switch ((string)($cfg['ad_match'] ?? 'any')) {
                    case 'ad_id':
                        if ($val === '') return true;
                        return in_array($ref['source_id'], array_map('trim', explode(',', $val)), true);
                    case 'headline':
                        if ($val === '') return true;
                        $v = mb_strtolower($val);
                        return mb_strpos(mb_strtolower($ref['headline']), $v) !== false || mb_strpos(mb_strtolower($ref['body']), $v) !== false;
                    default:
                        return true;
                }
            case 'button_reply':
                if ($in['kind'] !== 'reply') return false;
                $ids = array_map('strval', (array)($cfg['reply_ids'] ?? []));
                if (!$ids) return true;
                foreach ($ids as $want) {
                    if ($want === (string)$in['reply_id'] || mb_strtolower($want) === mb_strtolower((string)$in['reply_title'])) return true;
                }
                return false;
            case 'keyword':
                if ($in['text'] === '' || !in_array($in['kind'], ['text', 'reply'], true)) return false;
                $text = $this->norm($in['text']);
                foreach ((array)($cfg['rules'] ?? []) as $r) {
                    $v = $this->norm((string)($r['value'] ?? ''));
                    if ($v === '') continue;
                    switch ((string)($r['match'] ?? 'exact')) {
                        case 'exact': if ($text === $v) return true; break;
                        case 'starts': if (mb_strpos($text, $v) === 0) return true; break;
                        case 'contains':
                            if (preg_match('/^[a-z0-9_\- ]+$/', $v) && strlen($v) >= 2) { if (preg_match('/(?<![a-z0-9_])' . preg_quote($v, '/') . '(?![a-z0-9_])/u', $text)) return true; }
                            elseif (mb_strpos($text, $v) !== false) return true;
                            break;
                        case 'regex':
                            $re = '~' . str_replace('~', '\~', (string)$r['value']) . '~iu';
                            if (@preg_match($re, $in['text']) === 1) return true;
                            break;
                    }
                }
                return false;
        }
        return false;
    }

    /** Has this flow already started for the contact within the last N hours? (0 = no cool-down) */
    private function ranRecently(int $flowId, int $hours): bool
    {
        if ($hours <= 0) return false;
        $st = $this->conn->prepare("SELECT 1 FROM flow_sessions WHERE company_id = ? AND contact_id = ? AND flow_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) LIMIT 1");
        $st->bind_param('iiii', $this->companyId, $this->contactId, $flowId, $hours);
        $st->execute();
        $r = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $r;
    }

    private function norm(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($s, 'UTF-8')));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Running
    // ═════════════════════════════════════════════════════════════════════════

    private function run(array $s, string $nodeId, array $in): void
    {
        $flow = $this->flowRow((int)$s['flow_id']);
        $max = max(3, (int)($flow['max_steps'] ?? 25));
        $graph = $this->graph((int)$s['flow_id']);
        $steps = 0;

        while (true) {
            if (++$steps > $max) {
                $this->log((int)$s['flow_id'], (int)$s['id'], $nodeId, null, 'guard', 'error', "Stopped after $max steps in one run (possible loop)");
                $this->closeSession($s, 'error', 'loop_guard');
                return;
            }
            $node = $graph['nodes'][$nodeId] ?? null;
            if (!$node) {
                $this->log((int)$s['flow_id'], (int)$s['id'], $nodeId, null, 'engine', 'error', 'Node no longer exists in the flow');
                $this->closeSession($s, 'error', 'node_missing');
                return;
            }
            $s['current_node_id'] = $nodeId;
            $r = $this->exec($s, $node, $in);

            if (!empty($r['end'])) return;
            if (!empty($r['wait'])) {
                $this->saveSession($s, (string)$r['wait'], $nodeId);
                return;
            }
            $port = (string)($r['port'] ?? '');
            $target = $graph['edges'][$nodeId . '|' . $port] ?? null;
            $this->log((int)$s['flow_id'], (int)$s['id'], $nodeId, $node['type'], 'branch', 'ok', $target ? "$port -> $target" : "$port -> (no connection, flow ends)");
            if (!$target) { $this->closeSession($s, 'completed', 'no_next'); return; }
            $nodeId = $target;
        }
    }

    /** @return array{port?:string,wait?:string,end?:bool} */
    private function exec(array &$s, array $node, array $in): array
    {
        $type = $node['type'];
        $c = $node['config'];
        $fid = (int)$s['flow_id'];
        $sid = (int)$s['id'];
        $nid = $node['id'];

        switch ($type) {
            case 'trigger':
                return ['port' => 'out'];

            case 'send': {
                $kind = (string)($c['kind'] ?? 'text');
                if ($kind === 'buttons' && !empty($c['bot_reply_id'])) $c = $this->liveBotReply($c);
                $body = $this->render((string)($c['text'] ?? ''), $s);
                if ($kind !== 'template' && !$this->windowOpen()) return $this->blockedByWindow($s, $node);
                switch ($kind) {
                    case 'template': $res = $this->sendTemplateNode($s, $c); break;
                    case 'image': case 'video': case 'document':
                        $res = $this->sender->media($kind, (string)$c['media_url'], $body, (string)($c['filename'] ?? '')); break;
                    case 'buttons':
                        $btns = array_map(fn($b) => ['id' => $b['id'], 'title' => mb_substr($this->render((string)$b['title'], $s), 0, FLOW_LIMIT_BUTTON_TITLE)], (array)$c['buttons']);
                        $res = $this->sender->buttons($body, $btns, (string)($c['footer'] ?? '')); break;
                    case 'list':
                        $rows = array_map(fn($r) => ['id' => $r['id'], 'title' => mb_substr($this->render((string)$r['title'], $s), 0, FLOW_LIMIT_ROW_TITLE), 'description' => (string)($r['description'] ?? '')], (array)$c['rows']);
                        $res = $this->sender->lists($body, (string)($c['button'] ?? 'Choose'), $rows, (string)($c['footer'] ?? '')); break;
                    default:
                        if (trim($body) === '') { $this->log($fid, $sid, $nid, $type, 'send', 'skip', 'Empty message, nothing sent'); return ['port' => 'next']; }
                        $res = $this->sender->text($body);
                }
                if (!$res['ok']) {
                    $this->log($fid, $sid, $nid, $type, 'send', 'error', 'WhatsApp send failed: ' . $res['error']);
                    $this->closeSession($s, 'error', 'send_failed');
                    return ['end' => true];
                }
                $this->sentThisRun++;
                $this->log($fid, $sid, $nid, $type, 'send', 'ok', $kind . ': ' . mb_substr($body !== '' ? $body : (string)($c['template_name'] ?? ''), 0, 120));
                if ($kind === 'buttons' || $kind === 'list') { $s['retries'] = 0; return ['wait' => 'choice']; }
                return ['port' => 'next'];
            }

            case 'question': {
                if (!$this->windowOpen()) return $this->blockedByWindow($s, $node);
                $res = $this->sender->text($this->render((string)$c['text'], $s));
                if (!$res['ok']) { $this->log($fid, $sid, $nid, $type, 'send', 'error', 'WhatsApp send failed: ' . $res['error']); $this->closeSession($s, 'error', 'send_failed'); return ['end' => true]; }
                $this->sentThisRun++;
                $s['retries'] = 0;
                $this->log($fid, $sid, $nid, $type, 'ask', 'ok', mb_substr((string)$c['text'], 0, 120) . '  (saves to {{' . ($c['save_as'] ?? 'answer') . '}})');
                return ['wait' => 'answer'];
            }

            case 'condition': {
                $yes = $this->evalCondition($c, $s);
                $this->log($fid, $sid, $nid, $type, 'condition', 'ok', ($c['source'] ?? '') . ' ' . ($c['name'] ?? '') . ' ' . ($c['operator'] ?? '') . ' "' . ($c['value'] ?? '') . '" => ' . ($yes ? 'yes' : 'no'));
                return ['port' => $yes ? 'yes' : 'no'];
            }

            case 'delay': {
                $secs = flow_delay_seconds($c);
                $j = $this->conn->prepare("INSERT INTO flow_jobs (company_id, session_id, flow_id, contact_id, node_id, run_at, status, created_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending', NOW())");
                $j->bind_param('iiiisi', $this->companyId, $sid, $fid, $this->contactId, $nid, $secs);
                $j->execute();
                $j->close();
                $this->log($fid, $sid, $nid, $type, 'delay', 'ok', "Waiting {$c['amount']} {$c['unit']}, then continues");
                return ['wait' => 'delay'];
            }

            case 'action':
                return $this->execAction($s, $node);

            case 'end': {
                $msg = $this->render((string)($c['message'] ?? ''), $s);
                if (trim($msg) !== '') {
                    if ($this->windowOpen()) { $res = $this->sender->text($msg); if ($res['ok']) $this->sentThisRun++; }
                    else $this->log($fid, $sid, $nid, $type, 'send', 'skip', 'Closing message not sent: outside the 24 hour window');
                }
                $this->log($fid, $sid, $nid, $type, 'end', 'ok', 'Flow finished');
                $this->closeSession($s, 'completed', 'end_node');
                return ['end' => true];
            }
        }
        $this->closeSession($s, 'error', 'unknown_node');
        return ['end' => true];
    }

    /** A Send > Buttons node linked to a Bot Reply always sends the Bot Reply's current text and button labels. */
    private function liveBotReply(array $c): array
    {
        $bot = flow_botreply_get($this->conn, $this->companyId, (int)$c['bot_reply_id']);
        if (!$bot) return $c;   // Bot Reply deleted or switched off: keep the last synced copy
        $stored = array_column((array)($c['buttons'] ?? []), 'id');
        $fresh = $c;
        $fresh['text'] = mb_substr($bot['text'], 0, FLOW_LIMIT_BODY);
        $fresh['footer'] = $bot['footer'];
        $fresh['buttons'] = array_values(array_filter($bot['buttons'], static fn($b) => in_array($b['id'], $stored, true)));
        return $fresh['buttons'] ? $fresh : $c;
    }

    private function blockedByWindow(array &$s, array $node): array
    {
        $this->log((int)$s['flow_id'], (int)$s['id'], $node['id'], $node['type'], 'send', 'error', 'Blocked: more than 24 hours since the customer last wrote. Use an approved template.');
        $this->closeSession($s, 'error', 'window_closed');
        return ['end' => true];
    }

    private function sendTemplateNode(array $s, array $c): array
    {
        $tid = (int)($c['template_id'] ?? 0);
        $st = $this->conn->prepare("SELECT name, language, status, wa_status FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1");
        $st->bind_param('ii', $tid, $this->companyId);
        $st->execute();
        $t = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$t) return ['ok' => false, 'id' => null, 'error' => 'template_not_found'];
        if (strtoupper((string)$t['wa_status']) !== 'APPROVED' && strtoupper((string)$t['status']) !== 'APPROVED') return ['ok' => false, 'id' => null, 'error' => 'template_not_approved'];
        $vars = array_map(fn($v) => $this->render((string)$v, $s), (array)($c['variables'] ?? []));
        return $this->sender->template((string)$t['name'], (string)($t['language'] ?: 'en'), $vars);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Resuming a waiting node
    // ═════════════════════════════════════════════════════════════════════════

    private function hasPendingTimeout(int $sessionId): bool
    {
        $st = $this->conn->prepare("SELECT 1 FROM flow_jobs WHERE session_id = ? AND company_id = ? AND kind = 'timeout' AND status = 'pending' LIMIT 1");
        $st->bind_param('ii', $sessionId, $this->companyId);
        $st->execute();
        $r = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $r;
    }

    private function resume(array $s, array $in): void
    {
        $fid = (int)$s['flow_id'];
        $sid = (int)$s['id'];
        $this->cancelTimeouts($sid);   // the customer answered: the "no reply" follow-up is no longer needed
        $graph = $this->graph($fid);
        $nid = (string)$s['current_node_id'];
        $node = $graph['nodes'][$nid] ?? null;
        $vars = $this->vars($s);
        $vars['last_reply'] = $in['text'];
        if ($in['reply_id'] !== null) $vars['last_reply_id'] = $in['reply_id'];
        $s['variables'] = json_encode($vars, JSON_UNESCAPED_UNICODE);

        if (!$node) { $this->log($fid, $sid, $nid, null, 'engine', 'error', 'Waiting node no longer exists'); $this->closeSession($s, 'error', 'node_missing'); return; }
        $type = $node['type'];
        $c = $node['config'];
        $this->log($fid, $sid, $nid, $type, 'resume', 'ok', 'Customer replied: ' . ($in['kind'] === 'reply' ? '[' . $in['reply_id'] . '] ' : '') . mb_substr($in['text'], 0, 100));

        // ── buttons / list: reply id -> port id
        if ($type === 'send') {
            $ports = array_column(flow_node_ports('send', $c), null, 'id');
            $rid = (string)($in['reply_id'] ?? '');
            if ($in['kind'] === 'reply' && $rid !== '' && isset($ports[$rid]) && $ports[$rid]['kind'] === 'branch') {
                $this->log($fid, $sid, $nid, $type, 'match', 'ok', 'Reply id "' . $rid . '" -> ' . $ports[$rid]['label']);
                $this->continueFrom($s, $nid, $rid, $in);
                return;
            }
            $this->noMatch($s, $node, $in);
            return;
        }

        // ── question: validate, save, continue
        if ($type === 'question') {
            $answer = trim($in['text']);
            if ($in['kind'] === 'media' || $answer === '' || !$this->validAnswer($answer, (string)($c['validate'] ?? 'none'))) {
                $retries = (int)$s['retries'];
                if ($retries < (int)($c['max_retries'] ?? 1)) {
                    $msg = trim((string)($c['retry_text'] ?? '')) !== '' ? $c['retry_text'] : $c['text'];
                    if ($this->windowOpen()) { $r = $this->sender->text($this->render((string)$msg, $s)); if ($r['ok']) $this->sentThisRun++; }
                    $s['retries'] = $retries + 1;
                    $this->saveSession($s, 'answer', $nid);
                    $this->log($fid, $sid, $nid, $type, 'validate', 'error', 'Answer not accepted (' . ($c['validate'] ?? 'none') . '), retry ' . ($retries + 1));
                    return;
                }
                $this->log($fid, $sid, $nid, $type, 'validate', 'error', 'Answer still invalid after retries');
                if (!empty($graph['edges'][$nid . '|fallback'])) { $this->continueFrom($s, $nid, 'fallback', $in); return; }
                $this->closeSession($s, 'completed', 'invalid_answer');
                return;
            }
            $key = (string)($c['save_as'] ?? 'answer');
            $vars[$key] = $answer;
            $s['variables'] = json_encode($vars, JSON_UNESCAPED_UNICODE);
            $saved = '{{' . $key . '}}';
            if (!empty($c['field']) && in_array($c['field'], flow_contact_fields(), true)) {
                $this->setContactField((string)$c['field'], $answer);
                $saved .= ' and contact.' . $c['field'];
            }
            $this->log($fid, $sid, $nid, $type, 'save', 'ok', 'Saved "' . mb_substr($answer, 0, 80) . '" to ' . $saved);
            $this->continueFrom($s, $nid, 'next', $in);
            return;
        }

        $this->log($fid, $sid, $nid, $type, 'engine', 'error', 'Session was waiting at a node that cannot wait');
        $this->closeSession($s, 'error', 'bad_wait');
    }

    private function noMatch(array $s, array $node, array $in): void
    {
        $fid = (int)$s['flow_id'];
        $sid = (int)$s['id'];
        $nid = $node['id'];
        $graph = $this->graph($fid);
        if (!empty($graph['edges'][$nid . '|fallback'])) {
            $this->log($fid, $sid, $nid, $node['type'], 'match', 'ok', 'No button matched, following the fallback port');
            $this->continueFrom($s, $nid, 'fallback', $in);
            return;
        }
        if ((int)$s['retries'] < 1) {
            $this->log($fid, $sid, $nid, $node['type'], 'match', 'error', 'No button matched and no fallback: asking again once');
            $s['retries'] = 1;
            $c = $node['config'];
            if ($this->windowOpen()) {
                $body = $this->render((string)$c['text'], $s);
                $res = ($c['kind'] ?? '') === 'list'
                    ? $this->sender->lists($body, (string)($c['button'] ?? 'Choose'), (array)$c['rows'], (string)($c['footer'] ?? ''))
                    : $this->sender->buttons($body, (array)$c['buttons'], (string)($c['footer'] ?? ''));
                if ($res['ok']) $this->sentThisRun++;
            }
            $this->saveSession($s, 'choice', $nid);
            return;
        }
        $this->log($fid, $sid, $nid, $node['type'], 'match', 'error', 'Still no match after asking again: session ended');
        $this->closeSession($s, 'completed', 'no_match');
    }

    private function continueFrom(array $s, string $nodeId, string $port, array $in): void
    {
        $graph = $this->graph((int)$s['flow_id']);
        $target = $graph['edges'][$nodeId . '|' . $port] ?? null;
        $this->log((int)$s['flow_id'], (int)$s['id'], $nodeId, $graph['nodes'][$nodeId]['type'] ?? null, 'branch', 'ok', $target ? "$port -> $target" : "$port -> (no connection, flow ends)");
        if (!$target) { $this->closeSession($s, 'completed', 'no_next'); return; }
        $s['waiting_for'] = '';
        $this->run($s, $target, $in);
    }

    private function validAnswer(string $a, string $rule): bool
    {
        switch ($rule) {
            case 'number': return (bool)preg_match('/^-?\d+([.,]\d+)?$/', $a);
            case 'email':  return (bool)filter_var($a, FILTER_VALIDATE_EMAIL);
            case 'phone':  $d = preg_replace('/[\s\-().+]/', '', $a); return (bool)preg_match('/^\d{8,15}$/', (string)$d);
        }
        return true;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Conditions and actions
    // ═════════════════════════════════════════════════════════════════════════

    private function evalCondition(array $c, array $s): bool
    {
        $src = (string)($c['source'] ?? 'variable');
        $name = (string)($c['name'] ?? '');
        if ($src === 'time') {
            $now = (int)date('Hi');
            $f = (int)str_replace(':', '', (string)($c['from'] ?? '09:00'));
            $t = (int)str_replace(':', '', (string)($c['to'] ?? '18:00'));
            return $f <= $t ? ($now >= $f && $now < $t) : ($now >= $f || $now < $t);
        }
        if ($src === 'tag') {
            $has = in_array(mb_strtolower($name), array_map('mb_strtolower', $this->contactTags()), true);
            return ($c['operator'] ?? '') === 'not_equals' ? !$has : $has;
        }
        $left = $src === 'field' ? (string)($this->contact()[$name] ?? '') : (string)($this->vars($s)[$name] ?? '');
        $right = $this->render((string)($c['value'] ?? ''), $s);
        switch ((string)($c['operator'] ?? 'equals')) {
            case 'equals':     return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
            case 'not_equals': return mb_strtolower(trim($left)) !== mb_strtolower(trim($right));
            case 'contains':   return $right !== '' && mb_stripos($left, $right) !== false;
            case 'gt':         return is_numeric($left) && is_numeric($right) && (float)$left > (float)$right;
            case 'lt':         return is_numeric($left) && is_numeric($right) && (float)$left < (float)$right;
            case 'empty':      return trim($left) === '';
            case 'not_empty':  return trim($left) !== '';
        }
        return false;
    }

    private function execAction(array &$s, array $node): array
    {
        $c = $node['config'];
        $fid = (int)$s['flow_id']; $sid = (int)$s['id']; $nid = $node['id'];
        $a = (string)($c['action'] ?? '');
        $log = fn(string $res, string $d) => $this->log($fid, $sid, $nid, 'action', $a, $res, $d);

        switch ($a) {
            case 'add_tag': case 'remove_tag': {
                $tag = trim($this->render((string)$c['tag'], $s));
                $tags = $this->contactTags();
                $lower = array_map('mb_strtolower', $tags);
                if ($a === 'add_tag' && $tag !== '' && !in_array(mb_strtolower($tag), $lower, true)) $tags[] = $tag;
                if ($a === 'remove_tag') $tags = array_values(array_filter($tags, fn($t) => mb_strtolower($t) !== mb_strtolower($tag)));
                $this->setContactRaw('tags', implode(', ', $tags));
                $log('ok', ($a === 'add_tag' ? 'Added' : 'Removed') . ' tag "' . $tag . '"');
                return ['port' => 'next'];
            }
            case 'update_field': {
                $val = $this->render((string)$c['value'], $s);
                $this->setContactField((string)$c['field'], $val);
                $log('ok', 'contact.' . $c['field'] . ' = "' . mb_substr($val, 0, 80) . '"');
                return ['port' => 'next'];
            }
            case 'assign_agent': {
                $ok = $this->assignAgent((int)$c['agent_id']);
                $log($ok ? 'ok' : 'error', $ok ? 'Assigned to agent #' . (int)$c['agent_id'] : 'Agent not found in this company');
                return ['port' => 'next'];
            }
            case 'webhook': {
                $url = $this->render((string)$c['url'], $s);
                $payload = ['company_id' => $this->companyId, 'flow_id' => $fid, 'contact' => ['id' => $this->contactId, 'name' => (string)($this->contact()['name'] ?? ''), 'phone' => (string)($this->contact()['phone'] ?? '')], 'variables' => $this->vars($s)];
                if (trim((string)$c['body']) !== '') $payload['body'] = $this->render((string)$c['body'], $s);
                $res = $this->callWebhook($url, (string)$c['method'], $payload);
                $vars = $this->vars($s);
                $vars['webhook_status'] = (string)$res['status'];
                $vars['webhook_response'] = mb_substr((string)$res['body'], 0, 1000);
                $s['variables'] = json_encode($vars, JSON_UNESCAPED_UNICODE);
                $log($res['status'] >= 200 && $res['status'] < 300 ? 'ok' : 'error', 'HTTP ' . $res['status'] . ' ' . $c['method'] . ' ' . mb_substr($url, 0, 100));
                return ['port' => 'next'];
            }
            case 'handover_ai': {
                $this->setContactRaw('bot_enabled', '1');
                $this->setContactRaw('reply_bot_enabled', '1');
                $this->handoverAi = true;
                $log('ok', 'Conversation handed over to the AI bot');
                $this->closeSession($s, 'completed', 'handover_ai');
                return ['end' => true];
            }
            case 'handover_human': {
                $this->setContactRaw('bot_enabled', '0');
                $this->setContactRaw('reply_bot_enabled', '0');
                if ((int)$c['agent_id'] > 0) $this->assignAgent((int)$c['agent_id']);
                $msg = $this->render((string)($c['message'] ?? ''), $s);
                if (trim($msg) !== '' && $this->windowOpen()) { $r = $this->sender->text($msg); if ($r['ok']) $this->sentThisRun++; }
                $log('ok', 'Conversation handed over to a human agent, bots paused for this contact');
                $this->closeSession($s, 'completed', 'handover_human');
                return ['end' => true];
            }
        }
        $log('error', 'Unknown action');
        return ['port' => 'next'];
    }

    private function callWebhook(string $url, string $method, array $payload): array
    {
        if (!preg_match('~^https?://~i', $url)) return ['status' => 0, 'body' => 'invalid_url'];
        if ($this->http) return ($this->http)($url, $method, $payload);

        $host = (string)parse_url($url, PHP_URL_HOST);
        $ips = $host !== '' ? (@gethostbynamel($host) ?: []) : [];
        if (!$ips) return ['status' => 0, 'body' => 'dns_failed'];
        foreach ($ips as $ip) {   // never call internal addresses (SSRF)
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['status' => 0, 'body' => 'blocked_internal_address'];
        }
        $ch = curl_init($url . ($method === 'GET' ? ((strpos($url, '?') === false ? '?' : '&') . http_build_query(['contact_id' => $this->contactId, 'phone' => $payload['contact']['phone'] ?? ''])) : ''));
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
                 CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: WAAMINI-Flow/1.0']];
        if ($method !== 'GET') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE); }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Variables, contact, window
    // ═════════════════════════════════════════════════════════════════════════

    private function render(string $tpl, array $s): string
    {
        if (strpos($tpl, '{{') === false) return $tpl;
        $vars = $this->vars($s);
        $contact = $this->contact();
        return (string)preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', function ($m) use ($vars, $contact) {
            $k = $m[1];
            if (strpos($k, 'contact.') === 0) {
                $f = substr($k, 8);
                if ($f === 'first_name') return (string)preg_split('/\s+/', trim((string)($contact['name'] ?? '')))[0];
                return in_array($f, array_merge(flow_contact_fields(), ['phone', 'tags']), true) ? (string)($contact[$f] ?? '') : '';
            }
            if (strpos($k, 'vars.') === 0) $k = substr($k, 5);
            elseif (strpos($k, 'var.') === 0) $k = substr($k, 4);
            return (string)($vars[$k] ?? '');
        }, $tpl);
    }

    private function vars(array $s): array
    {
        $v = json_decode((string)($s['variables'] ?? ''), true);
        return is_array($v) ? $v : [];
    }

    private function contact(): array
    {
        if ($this->contact === null) {
            $st = $this->conn->prepare("SELECT * FROM contacts WHERE id = ? AND company_id = ? LIMIT 1");
            $st->bind_param('ii', $this->contactId, $this->companyId);
            $st->execute();
            $this->contact = $st->get_result()->fetch_assoc() ?: [];
            $st->close();
        }
        return $this->contact;
    }

    private function contactTags(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)($this->contact()['tags'] ?? '')))));
    }

    private function setContactField(string $field, string $value): void
    {
        if (!in_array($field, flow_contact_fields(), true)) return;
        $this->setContactRaw($field, mb_substr(trim($value), 0, 255));
    }

    /** Column name must come from a fixed list; value is bound. */
    private function setContactRaw(string $col, string $value): void
    {
        if (!in_array($col, array_merge(flow_contact_fields(), ['tags', 'bot_enabled', 'reply_bot_enabled', 'assigned_to']), true)) return;
        $st = $this->conn->prepare("UPDATE contacts SET `$col` = ? WHERE id = ? AND company_id = ?");
        if (!$st) return;
        $st->bind_param('sii', $value, $this->contactId, $this->companyId);
        $st->execute();
        $st->close();
        if ($this->contact !== null) $this->contact[$col] = $value;
    }

    private function assignAgent(int $agentId): bool
    {
        $st = $this->conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ? LIMIT 1");
        $st->bind_param('ii', $agentId, $this->companyId);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_assoc();
        $st->close();
        if ($ok) $this->setContactRaw('assigned_to', (string)$agentId);
        return $ok;
    }

    private function windowOpen(): bool
    {
        if ($this->windowCheck) return (bool)($this->windowCheck)();
        $st = $this->conn->prepare("SELECT MAX(created_at) t FROM messages WHERE company_id = ? AND contact_id = ? AND direction = 'incoming'");
        $st->bind_param('ii', $this->companyId, $this->contactId);
        $st->execute();
        $t = $st->get_result()->fetch_assoc()['t'] ?? null;
        $st->close();
        return $t !== null && strtotime((string)$t) >= time() - 86400;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Storage
    // ═════════════════════════════════════════════════════════════════════════

    private function flowRow(int $flowId): ?array
    {
        $st = $this->conn->prepare("SELECT * FROM chatbot_flows WHERE id = ? AND company_id = ? LIMIT 1");
        $st->bind_param('ii', $flowId, $this->companyId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return $r ?: null;
    }

    private function publishedFlows(): array
    {
        $st = $this->conn->prepare("SELECT * FROM chatbot_flows WHERE company_id = ? AND status = 'published' ORDER BY priority ASC, id ASC");
        $st->bind_param('i', $this->companyId);
        $st->execute();
        $rows = [];
        $r = $st->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $st->close();
        return $rows;
    }

    /** @return array{nodes:array<string,array>,edges:array<string,string>,trigger:?string} edges are keyed "source|port" */
    private function graph(int $flowId): array
    {
        if (isset($this->graphCache[$flowId])) return $this->graphCache[$flowId];
        $g = ['nodes' => [], 'edges' => [], 'trigger' => null];
        $st = $this->conn->prepare("SELECT node_id, type, config FROM flow_nodes WHERE flow_id = ? AND company_id = ?");
        $st->bind_param('ii', $flowId, $this->companyId);
        $st->execute();
        $r = $st->get_result();
        while ($x = $r->fetch_assoc()) {
            $g['nodes'][$x['node_id']] = ['id' => $x['node_id'], 'type' => $x['type'], 'config' => json_decode((string)$x['config'], true) ?: []];
            if ($x['type'] === 'trigger') $g['trigger'] = $x['node_id'];
        }
        $st->close();
        $st = $this->conn->prepare("SELECT source_node, source_port, target_node FROM flow_edges WHERE flow_id = ? AND company_id = ?");
        $st->bind_param('ii', $flowId, $this->companyId);
        $st->execute();
        $r = $st->get_result();
        while ($x = $r->fetch_assoc()) $g['edges'][$x['source_node'] . '|' . $x['source_port']] = $x['target_node'];
        $st->close();
        return $this->graphCache[$flowId] = $g;
    }

    private function openSession(): ?array
    {
        $st = $this->conn->prepare("SELECT * FROM flow_sessions WHERE company_id = ? AND contact_id = ? AND active_key = 1 LIMIT 1");
        $st->bind_param('ii', $this->companyId, $this->contactId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return $r ?: null;
    }

    private function startSession(int $flowId, string $triggerId, array $in): ?array
    {
        $vars = json_encode(['trigger_text' => $in['text'], 'last_reply' => $in['text']], JSON_UNESCAPED_UNICODE);
        $st = $this->conn->prepare("INSERT INTO flow_sessions (company_id, contact_id, flow_id, current_node_id, waiting_for, status, active_key, variables, started_at, last_activity_at) VALUES (?,?,?,?, '', 'active', 1, ?, NOW(), NOW())");
        $st->bind_param('iiiss', $this->companyId, $this->contactId, $flowId, $triggerId, $vars);
        try { $ok = $st->execute(); } catch (\Throwable $e) { $ok = false; }   // another run already holds the open session
        if (!$ok) { $st->close(); return null; }
        $id = (int)$this->conn->insert_id;
        $st->close();
        $s = $this->openSession();
        return ($s && (int)$s['id'] === $id) ? $s : null;
    }

    private function saveSession(array $s, string $waitingFor, string $nodeId): void
    {
        $status = 'waiting';
        $st = $this->conn->prepare("UPDATE flow_sessions SET current_node_id = ?, waiting_for = ?, status = ?, variables = ?, retries = ?, last_activity_at = NOW() WHERE id = ? AND company_id = ?");
        $vars = (string)($s['variables'] ?? '{}');
        $retries = (int)($s['retries'] ?? 0);
        $sid = (int)$s['id'];
        $st->bind_param('ssssiii', $nodeId, $waitingFor, $status, $vars, $retries, $sid, $this->companyId);
        $st->execute();
        $st->close();
        $this->log((int)$s['flow_id'], $sid, $nodeId, null, 'wait', 'ok', 'Waiting for ' . ($waitingFor === 'choice' ? 'a button / list reply' : ($waitingFor === 'answer' ? 'the answer' : 'the delay to finish')));
        $this->cancelTimeouts($sid);
        if ($waitingFor === 'choice' || $waitingFor === 'answer') {
            $mins = (int)(($this->graph((int)$s['flow_id'])['nodes'][$nodeId]['config']['timeout_minutes'] ?? 0));
            if ($mins > 0) {
                $secsT = $mins * 60;
                $j = $this->conn->prepare("INSERT INTO flow_jobs (company_id, session_id, flow_id, contact_id, node_id, kind, run_at, status, created_at) VALUES (?,?,?,?,?, 'timeout', DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending', NOW())");
                $fid = (int)$s['flow_id'];
                $j->bind_param('iiiisi', $this->companyId, $sid, $fid, $this->contactId, $nodeId, $secsT);
                $j->execute();
                $j->close();
                $this->log($fid, $sid, $nodeId, null, 'timeout_set', 'ok', 'If there is no reply, the "No reply" step runs after ' . flow_minutes_label($mins));
            }
        }
    }

    private function cancelTimeouts(int $sessionId): void
    {
        $u = $this->conn->prepare("UPDATE flow_jobs SET status = 'done' WHERE session_id = ? AND company_id = ? AND kind = 'timeout' AND status = 'pending'");
        $u->bind_param('ii', $sessionId, $this->companyId);
        $u->execute();
        $u->close();
    }

    private function closeSession(array $s, string $status, string $reason): void
    {
        $st = $this->conn->prepare("UPDATE flow_sessions SET status = ?, active_key = NULL, end_reason = ?, variables = ?, ended_at = NOW(), last_activity_at = NOW() WHERE id = ? AND company_id = ?");
        $vars = (string)($s['variables'] ?? '{}');
        $sid = (int)$s['id'];
        $st->bind_param('sssii', $status, $reason, $vars, $sid, $this->companyId);
        $st->execute();
        $st->close();
        $j = $this->conn->prepare("UPDATE flow_jobs SET status = 'done' WHERE session_id = ? AND company_id = ? AND status = 'pending'");
        $j->bind_param('ii', $sid, $this->companyId);
        $j->execute();
        $j->close();
    }

    private function log(int $flowId, int $sessionId, ?string $nodeId, ?string $nodeType, string $event, string $result, string $detail): void
    {
        $st = $this->conn->prepare("INSERT INTO flow_logs (company_id, flow_id, session_id, contact_id, node_id, node_type, event, result, detail, created_at) VALUES (?,?,?,?,?,?,?,?,?, NOW())");
        if (!$st) return;
        $detail = mb_substr($detail, 0, 1000);
        $st->bind_param('iiiisssss', $this->companyId, $flowId, $sessionId, $this->contactId, $nodeId, $nodeType, $event, $result, $detail);
        $st->execute();
        $st->close();
    }
}
