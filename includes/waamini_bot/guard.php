<?php
/**
 * WAAMINI Bot rules: when the bot may answer, queueing, pause/resume, handoff, sending and run logging.
 * A chat for a client without a live bot is never touched here: eligible() returns false and the
 * existing router path continues unchanged.
 */
class WB_Guard
{
    private const PAUSE_FOREVER = '2099-12-31 00:00:00';
    private const DEBOUNCE_SECONDS = 4;

    /** Replaces the WhatsApp sender. Used by automated tests so no real message is ever sent. */
    public static ?\Closure $senderOverride = null;

    /** Platform on + admin enabled + client switched on (+ setup finished). Cheap, no chat context. */
    public static function isLive(mysqli $c, int $companyId): bool
    {
        if ($companyId <= 0) { return false; }
        try {
            $s = WB_Store::settings($c);
            if (!WB_Store::platformReady($s)) { return false; }
            $bot = WB_Store::bot($c, $companyId);
            return $bot && $bot['is_admin_enabled'] && $bot['is_live'] && !empty($bot['tested_at']) && !empty($bot['profile_confirmed_at']);
        } catch (Throwable $e) {
            error_log('[WAAMINI Bot] isLive: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{0:bool,1:string} */
    public static function eligible(mysqli $c, int $companyId, int $contactId): array
    {
        if (!self::isLive($c, $companyId)) { return [false, 'bot_not_live']; }
        $bot = WB_Store::bot($c, $companyId);

        // Assigned to a human?
        $st = $c->prepare("SELECT c.assigned_to, (SELECT assigned_agent_id FROM chat_conversations cv WHERE cv.company_id = c.company_id AND cv.contact_id = c.id LIMIT 1) AS agent
            FROM contacts c WHERE c.id = ? AND c.company_id = ?");
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) { return [false, 'contact_missing']; }
        if ((int)($row['assigned_to'] ?? 0) > 0 || (int)($row['agent'] ?? 0) > 0) { return [false, 'assigned_to_human']; }

        // Paused for this chat?
        $ps = $c->prepare("SELECT paused_until FROM ai_conversation_state WHERE company_id = ? AND contact_id = ?");
        $ps->bind_param('ii', $companyId, $contactId);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        if ($pr && !empty($pr['paused_until']) && strtotime((string)$pr['paused_until']) > time()) { return [false, 'paused']; }

        // Replies left this month?
        $limits = WB_Store::limitsFor($c, $companyId);
        $usage = WB_Store::usage($c, $companyId);
        if ($usage['replies'] >= $limits['replies']) { return [false, 'limit_reached']; }

        // Reply mode: only outside business hours
        if (($bot['reply_mode'] ?? 'always') === 'after_hours') {
            $from = (string)($bot['business_profile']['hours_start'] ?? '');
            $to = (string)($bot['business_profile']['hours_end'] ?? '');
            if (preg_match('/^\d{2}:\d{2}$/', $from) && preg_match('/^\d{2}:\d{2}$/', $to)) {
                $now = date('H:i');
                $open = $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
                if ($open) { return [false, 'business_hours']; }
            }
        }
        return [true, 'ok'];
    }

    /** Called by the router. Queues a reply job when eligible. Returns true when the bot took the message. */
    public static function enqueueIfEligible(mysqli $c, int $companyId, int $contactId, string $wamid): bool
    {
        if ($contactId <= 0 || $wamid === '') { return false; }
        [$ok] = self::eligible($c, $companyId, $contactId);
        if (!$ok) { return false; }

        $runAt = date('Y-m-d H:i:s', time() + self::DEBOUNCE_SECONDS);
        $ins = $c->prepare("INSERT IGNORE INTO ai_reply_jobs (company_id, contact_id, wamid, run_at) VALUES (?, ?, ?, ?)");
        $ins->bind_param('iiss', $companyId, $contactId, $wamid, $runAt);
        $ins->execute();
        if ($c->affected_rows < 1) { return true; } // Meta delivered the same message id twice: already queued

        // Quick follow-up messages share one answer: push earlier pending jobs of this chat to the same time
        $up = $c->prepare("UPDATE ai_reply_jobs SET run_at = ? WHERE company_id = ? AND contact_id = ? AND status = 'pending'");
        $up->bind_param('sii', $runAt, $companyId, $contactId);
        $up->execute();
        self::kickWorker();
        return true;
    }

    /* ── pause / resume / handoff ────────────────────────────────────────── */

    public static function pause(mysqli $c, int $companyId, int $contactId, string $state = 'escalated'): void
    {
        $until = self::PAUSE_FOREVER;
        $st = $c->prepare("INSERT INTO ai_conversation_state (company_id, contact_id, state, paused_until) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE state = VALUES(state), paused_until = VALUES(paused_until)");
        $st->bind_param('iiss', $companyId, $contactId, $state, $until);
        $st->execute();
    }

    /** A human replied in the inbox: the bot steps back for this chat until someone presses "Resume bot". */
    public static function staffReplied(mysqli $c, int $companyId, int $contactId): void
    {
        if ($companyId <= 0 || $contactId <= 0) { return; }
        try {
            $has = $c->prepare("SELECT 1 FROM ai_bots WHERE company_id = ? LIMIT 1");
            $has->bind_param('i', $companyId);
            $has->execute();
            if (!$has->get_result()->fetch_row()) { return; }
            self::pause($c, $companyId, $contactId, 'escalated');
            // drop replies that were queued before the human stepped in
            $d = $c->prepare("UPDATE ai_reply_jobs SET status = 'done', last_error = 'staff_replied' WHERE company_id = ? AND contact_id = ? AND status = 'pending'");
            $d->bind_param('ii', $companyId, $contactId);
            $d->execute();
        } catch (Throwable $e) {
            error_log('[WAAMINI Bot] staffReplied: ' . $e->getMessage());
        }
    }

    public static function resume(mysqli $c, int $companyId, int $contactId): void
    {
        $st = $c->prepare("INSERT INTO ai_conversation_state (company_id, contact_id, state, paused_until, fail_count) VALUES (?, ?, 'open', NULL, 0)
            ON DUPLICATE KEY UPDATE state = 'open', paused_until = NULL, fail_count = 0");
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
        // The bot never answers a chat assigned to a person, so resuming also releases the assignment
        $u1 = $c->prepare("UPDATE contacts SET assigned_to = NULL WHERE id = ? AND company_id = ?");
        $u1->bind_param('ii', $contactId, $companyId);
        $u1->execute();
        $u2 = $c->prepare("UPDATE chat_conversations SET assigned_agent_id = NULL WHERE contact_id = ? AND company_id = ?");
        $u2->bind_param('ii', $contactId, $companyId);
        $u2->execute();
    }

    public static function isPaused(mysqli $c, int $companyId, int $contactId): bool
    {
        $ps = $c->prepare("SELECT paused_until FROM ai_conversation_state WHERE company_id = ? AND contact_id = ?");
        $ps->bind_param('ii', $companyId, $contactId);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        return $pr && !empty($pr['paused_until']) && strtotime((string)$pr['paused_until']) > time();
    }

    /** Assign to the configured person (if any), pause the bot for this chat. Reuses the existing assignment columns. */
    private static function handoff(mysqli $c, int $companyId, array $bot, int $contactId, string $reason): void
    {
        $target = (int)($bot['handoff_target'] ?? 0);
        if ($target > 0) {
            $chk = $c->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
            $chk->bind_param('ii', $target, $companyId);
            $chk->execute();
            if ($chk->get_result()->fetch_row()) {
                $u1 = $c->prepare("UPDATE contacts SET assigned_to = ? WHERE id = ? AND company_id = ?");
                $u1->bind_param('iii', $target, $contactId, $companyId);
                $u1->execute();
                $u2 = $c->prepare("UPDATE chat_conversations SET assigned_agent_id = ?, ticket_status = 'open' WHERE contact_id = ? AND company_id = ?");
                $u2->bind_param('iii', $target, $contactId, $companyId);
                $u2->execute();
            }
        }
        self::pause($c, $companyId, $contactId, 'escalated');
    }

    /* ── sending ─────────────────────────────────────────────────────────── */

    private static function lastIncomingAgeSeconds(mysqli $c, int $companyId, int $contactId): int
    {
        $st = $c->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS s FROM messages WHERE company_id = ? AND contact_id = ? AND direction = 'incoming' ORDER BY id DESC LIMIT 1");
        $st->bind_param('ii', $companyId, $contactId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return $row ? (int)$row['s'] : PHP_INT_MAX;
    }

    /** Free-form text, only inside the 24-hour customer service window. Logged in the inbox as sender "bot". */
    private static function send(mysqli $c, int $companyId, int $contactId, string $phone, string $text): array
    {
        if (self::lastIncomingAgeSeconds($c, $companyId, $contactId) >= 86400) { return [false, 'window_closed', 0]; }
        $ins = $c->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, sender, is_read, status, created_at)
            VALUES (?, ?, 'outgoing', 'text', ?, ?, 'bot', 1, 'sending', NOW())");
        $ins->bind_param('iiss', $companyId, $contactId, $text, $text);
        $ins->execute();
        $mid = (int)$c->insert_id;

        $wamid = null; $err = null;
        try {
            if (!class_exists('WhatsAppService')) { require_once __DIR__ . '/../../services/WhatsAppService.php'; }
            $wamid = self::$senderOverride !== null
                ? (self::$senderOverride)($c, $companyId, $phone, $text)
                : WhatsAppService::sendText($c, $companyId, $phone, $text);
        } catch (Throwable $e) { $err = mb_substr($e->getMessage(), 0, 200); }

        if ($wamid) {
            $u = $c->prepare("UPDATE messages SET status = 'sent', whatsapp_message_id = ? WHERE id = ?");
            $u->bind_param('si', $wamid, $mid);
            $u->execute();
            $c->query("UPDATE chat_conversations SET last_message = '" . $c->real_escape_string(mb_substr($text, 0, 100)) . "', last_message_at = NOW() WHERE contact_id = " . (int)$contactId . " AND company_id = " . (int)$companyId);
            return [true, '', $mid];
        }
        $e2 = $err ?? (self::lastMetaError($c, $companyId) ?: 'send_failed');
        $u = $c->prepare("UPDATE messages SET status = 'failed', error_message = ? WHERE id = ?");
        $u->bind_param('si', $e2, $mid);
        $u->execute();
        return [false, $e2, $mid];
    }

    /** The reason Meta gave for the send that just failed (from the API log), in plain words. */
    private static function lastMetaError(mysqli $c, int $companyId): string
    {
        try {
            $st = $c->prepare("SELECT http_code, response_json, curl_error FROM whatsapp_api_logs WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1");
            $st->bind_param('i', $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if (!$row) { return ''; }
            if ((string)$row['curl_error'] !== '') { return 'Network error reaching WhatsApp: ' . mb_substr((string)$row['curl_error'], 0, 120); }
            $http = (int)$row['http_code'];
            if ($http >= 200 && $http < 300) { return ''; }
            $j = json_decode((string)$row['response_json'], true);
            $code = (int)($j['error']['code'] ?? 0);
            $msg = (string)($j['error']['message'] ?? ('HTTP ' . $http));
            $out = 'WhatsApp rejected the message (' . $http . ($code ? ', code ' . $code : '') . '): ' . $msg . '.';
            if ($code === 190 || $http === 401) { $out .= ' The WhatsApp access token is invalid or expired. Reconnect it in WhatsApp Setup.'; }
            elseif ($code === 131047 || $code === 131026) { $out .= ' The 24-hour reply window may be closed or the number cannot receive messages.'; }
            return mb_substr($out, 0, 290);
        } catch (Throwable $e) { return ''; }
    }
    /* ── logging ─────────────────────────────────────────────────────────── */

    public static function logRun(mysqli $c, int $companyId, int $contactId, ?int $messageId, ?string $wamid, bool $isTest, array $r): void
    {
        $chunks = json_encode(array_values($r['chunk_ids'] ?? []));
        $tools = json_encode(array_values($r['tool_calls'] ?? []), JSON_UNESCAPED_UNICODE);
        $pt = (int)($r['prompt_tokens'] ?? 0); $ct = (int)($r['completion_tokens'] ?? 0); $lat = (int)($r['latency_ms'] ?? 0);
        $out = (string)($r['outcome'] ?? ''); $err = isset($r['error']) ? WB_OpenAI::scrub((string)$r['error']) : null;
        $it = $isTest ? 1 : 0;
        $st = $c->prepare("INSERT INTO ai_runs (company_id, contact_id, message_id, wamid, is_test, used_chunk_ids, tool_calls, prompt_tokens, completion_tokens, latency_ms, outcome, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('iiisissiiiss', $companyId, $contactId, $messageId, $wamid, $it, $chunks, $tools, $pt, $ct, $lat, $out, $err);
        $st->execute();
    }

    /* ── worker ──────────────────────────────────────────────────────────── */

    /** Process due reply jobs. One job per chat at a time. Returns how many jobs were handled. */
    public static function processDue(mysqli $c, int $limit = 10): int
    {
        // recover jobs whose worker died
        $c->query("UPDATE ai_reply_jobs SET status = 'pending', attempts = attempts + 1, run_at = NOW() WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND attempts < 2");
        $c->query("UPDATE ai_reply_jobs SET status = 'failed', last_error = 'stuck' WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

        $handled = 0;
        $r = $c->query("SELECT id, company_id, contact_id, wamid FROM ai_reply_jobs WHERE status = 'pending' AND run_at <= NOW() ORDER BY id ASC LIMIT " . (int)$limit);
        $jobs = [];
        while ($r && ($row = $r->fetch_assoc())) { $jobs[] = $row; }
        foreach ($jobs as $job) {
            $jid = (int)$job['id']; $cid = (int)$job['company_id']; $ctid = (int)$job['contact_id'];
            // claim, and make sure no other job of this chat is being processed
            $busy = $c->prepare("SELECT 1 FROM ai_reply_jobs WHERE company_id = ? AND contact_id = ? AND status = 'processing' LIMIT 1");
            $busy->bind_param('ii', $cid, $ctid);
            $busy->execute();
            if ($busy->get_result()->fetch_row()) { continue; }
            $claim = $c->prepare("UPDATE ai_reply_jobs SET status = 'processing' WHERE id = ? AND status = 'pending'");
            $claim->bind_param('i', $jid);
            $claim->execute();
            if ($c->affected_rows !== 1) { continue; }
            // one answer for all quick messages of this chat
            $sup = $c->prepare("UPDATE ai_reply_jobs SET status = 'done', last_error = 'merged' WHERE company_id = ? AND contact_id = ? AND status = 'pending' AND id <> ?");
            $sup->bind_param('iii', $cid, $ctid, $jid);
            $sup->execute();

            try {
                $note = self::handleJob($c, $cid, $ctid, (string)$job['wamid']);
                $d = $c->prepare("UPDATE ai_reply_jobs SET status = 'done', last_error = ? WHERE id = ?");
                $d->bind_param('si', $note, $jid);
                $d->execute();
            } catch (Throwable $e) {
                error_log('[WAAMINI Bot] job ' . $jid . ': ' . $e->getMessage());
                $err = WB_OpenAI::scrub($e->getMessage());
                $f = $c->prepare("UPDATE ai_reply_jobs SET status = 'failed', last_error = ? WHERE id = ?");
                $f->bind_param('si', $err, $jid);
                $f->execute();
            }
            $handled++;
        }
        return $handled;
    }

    private static function handleJob(mysqli $c, int $companyId, int $contactId, string $wamid): string
    {
        // Re-check at run time: a person may have taken over, the limit may be reached, the admin may have switched the bot off
        [$ok, $why] = self::eligible($c, $companyId, $contactId);
        if (!$ok) { return 'skipped:' . $why; }
        $bot = WB_Store::bot($c, $companyId);

        $ct = $c->prepare("SELECT phone FROM contacts WHERE id = ? AND company_id = ?");
        $ct->bind_param('ii', $contactId, $companyId);
        $ct->execute();
        $phone = (string)($ct->get_result()->fetch_assoc()['phone'] ?? '');
        if ($phone === '') { return 'skipped:no_phone'; }

        // last 20 messages of this chat, oldest first
        $h = $c->prepare("SELECT id, direction, COALESCE(NULLIF(message, ''), content) AS body FROM messages
            WHERE company_id = ? AND contact_id = ? AND type IN ('text','interactive','audio','voice') ORDER BY id DESC LIMIT 20");
        $h->bind_param('ii', $companyId, $contactId);
        $h->execute();
        $hr = $h->get_result();
        $hist = []; $lastIn = null;
        while ($m = $hr->fetch_assoc()) { $hist[] = $m; }
        $hist = array_reverse($hist);
        $msgs = [];
        foreach ($hist as $m) {
            $body = trim((string)$m['body']);
            if ($body === '') { continue; }
            $msgs[] = ['role' => $m['direction'] === 'incoming' ? 'user' : 'assistant', 'content' => $body];
            if ($m['direction'] === 'incoming') { $lastIn = (int)$m['id']; }
        }

        $stt = $c->prepare("SELECT fail_count FROM ai_conversation_state WHERE company_id = ? AND contact_id = ?");
        $stt->bind_param('ii', $companyId, $contactId);
        $stt->execute();
        $fail = (int)($stt->get_result()->fetch_assoc()['fail_count'] ?? 0);

        // Retry once on a hard error before handing over (WB_OpenAI::chat already retries transient failures once)
        // Language of the reply = language of the customer's latest message (voice notes carry the label from transcription)
        $langOpts = [];
        try {
            $lq = $c->prepare("SELECT type, transcript_lang FROM messages WHERE company_id = ? AND contact_id = ? AND direction = 'incoming' ORDER BY id DESC LIMIT 1");
            $lq->bind_param('ii', $companyId, $contactId);
            $lq->execute();
            $lr = $lq->get_result()->fetch_assoc();
            if ($lr && in_array((string)$lr['type'], ['audio', 'voice'], true)) {
                $langOpts['voice'] = true;
                $tl = strtolower((string)($lr['transcript_lang'] ?? ''));
                if (in_array($tl, ['english', 'tamil', 'tanglish', 'hindi', 'malayalam'], true)) { $langOpts['reply_lang'] = $tl; }
            }
        } catch (Throwable $e) { /* transcript_lang column not created yet */ }

        $res = WB_Engine::run($c, $companyId, $bot, $msgs, ['is_test' => false, 'contact_id' => $contactId, 'fail_count' => $fail] + $langOpts);

        $text = $res['text'];
        $welcome = '';
        if (!$res['handoff'] && trim((string)$bot['welcome_line']) !== '') {
            $n = $c->prepare("SELECT COUNT(*) AS n FROM messages WHERE company_id = ? AND contact_id = ? AND direction = 'outgoing'");
            $n->bind_param('ii', $companyId, $contactId);
            $n->execute();
            if ((int)($n->get_result()->fetch_assoc()['n'] ?? 0) === 0) { $welcome = trim((string)$bot['welcome_line']); }
        }
        if ($welcome !== '') { $text = $welcome . "\n\n" . $text; }

        [$sent, $sendErr] = self::send($c, $companyId, $contactId, $phone, $text);
        if (!$sent) { $res['outcome'] = $sendErr === 'window_closed' ? 'window_closed' : 'send_failed'; $res['error'] = $sendErr; }

        // state: handoff pauses, match resets the failure counter, no match counts it
        if ($sent && $res['handoff']) {
            self::handoff($c, $companyId, $bot, $contactId, (string)$res['reason']);
        } elseif ($sent) {
            $newFail = $res['matched'] ? 0 : $fail + 1;
            $su = $c->prepare("INSERT INTO ai_conversation_state (company_id, contact_id, state, fail_count) VALUES (?, ?, 'open', ?)
                ON DUPLICATE KEY UPDATE fail_count = VALUES(fail_count), state = 'open'");
            $su->bind_param('iii', $companyId, $contactId, $newFail);
            $su->execute();
        }

        if ($sent && !$res['handoff']) {
            WB_Store::addUsage($c, $companyId, 1, (int)$res['prompt_tokens'] + (int)$res['completion_tokens']);
        } elseif ((int)$res['prompt_tokens'] + (int)$res['completion_tokens'] > 0) {
            WB_Store::addUsage($c, $companyId, 0, (int)$res['prompt_tokens'] + (int)$res['completion_tokens']);
        }
        self::logRun($c, $companyId, $contactId, $lastIn, $wamid, false, $res);
        return (string)$res['outcome'];
    }

    /* ── follow-ups ─────────────────────────────────────────────────────── */

    /**
     * Send ONE follow-up to customers who stopped replying, X hours (1-23) after their last message,
     * like the WhatsApp Business app's AI agent. Only when the business spoke last, only inside the
     * 24-hour window, never twice for the same silence, and only where the bot may reply.
     */
    public static function processFollowups(mysqli $c, int $limit = 10, int $onlyCompany = 0): int
    {
        WB_Store::ensureSchema($c);
        $sent = 0;
        $bq = $c->query("SELECT company_id, followup_hours FROM ai_bots WHERE followup_enabled = 1 AND is_live = 1 AND is_admin_enabled = 1"
            . ($onlyCompany > 0 ? ' AND company_id = ' . (int)$onlyCompany : ''));
        while ($bq && ($b = $bq->fetch_assoc()) && $sent < $limit) {
            $cid = (int)$b['company_id'];
            $hours = max(1, min(23, (int)$b['followup_hours']));
            if (!self::isLive($c, $cid)) { continue; }
            // chats whose last customer message is older than X hours but still inside the window (23h50m)
            $st = $c->prepare("SELECT contact_id, MAX(id) AS last_in, MAX(created_at) AS last_at FROM messages
                WHERE company_id = ? AND direction = 'incoming' AND created_at >= DATE_SUB(NOW(), INTERVAL 1430 MINUTE)
                GROUP BY contact_id HAVING last_at <= DATE_SUB(NOW(), INTERVAL ? HOUR) LIMIT 50");
            $st->bind_param('ii', $cid, $hours);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) {
                if ($sent >= $limit) { break; }
                $ctid = (int)$row['contact_id']; $lastIn = (int)$row['last_in'];
                // the business must have spoken last (customer went quiet after our reply)
                $lo = $c->prepare("SELECT direction FROM messages WHERE company_id = ? AND contact_id = ? ORDER BY id DESC LIMIT 1");
                $lo->bind_param('ii', $cid, $ctid);
                $lo->execute();
                if ((string)($lo->get_result()->fetch_assoc()['direction'] ?? '') !== 'outgoing') { continue; }
                // claim this silence once (unique key); a later customer message starts a new one
                $claim = $c->prepare("INSERT IGNORE INTO ai_followups (company_id, contact_id, last_incoming_id, status) VALUES (?, ?, ?, 'processing')");
                $claim->bind_param('iii', $cid, $ctid, $lastIn);
                $claim->execute();
                if ($c->affected_rows !== 1) { continue; }
                $fid = (int)$c->insert_id;
                [$status, $text, $mid, $err] = self::followupOne($c, $cid, $ctid);
                $u = $c->prepare("UPDATE ai_followups SET status = ?, text = ?, message_id = ?, error = ? WHERE id = ?");
                $u->bind_param('ssisi', $status, $text, $mid, $err, $fid);
                $u->execute();
                if ($status === 'sent') { $sent++; }
            }
        }
        return $sent;
    }

    /** @return array{0:string,1:?string,2:?int,3:?string} status, text, message id, error */
    private static function followupOne(mysqli $c, int $companyId, int $contactId): array
    {
        [$ok, $why] = self::eligible($c, $companyId, $contactId);
        if (!$ok) { return ['skipped', null, null, $why]; }
        $bot = WB_Store::bot($c, $companyId);
        $ct = $c->prepare("SELECT phone FROM contacts WHERE id = ? AND company_id = ?");
        $ct->bind_param('ii', $contactId, $companyId);
        $ct->execute();
        $phone = (string)($ct->get_result()->fetch_assoc()['phone'] ?? '');
        if ($phone === '') { return ['skipped', null, null, 'no_phone']; }

        $h = $c->prepare("SELECT direction, COALESCE(NULLIF(message, ''), content) AS body FROM messages
            WHERE company_id = ? AND contact_id = ? AND type IN ('text','interactive','audio','voice') ORDER BY id DESC LIMIT 16");
        $h->bind_param('ii', $companyId, $contactId);
        $h->execute();
        $hist = array_reverse($h->get_result()->fetch_all(MYSQLI_ASSOC));
        $msgs = [];
        foreach ($hist as $m) {
            $body = trim((string)$m['body']);
            if ($body !== '') { $msgs[] = ['role' => $m['direction'] === 'incoming' ? 'user' : 'assistant', 'content' => $body]; }
        }
        if (!$msgs) { return ['skipped', null, null, 'no_history']; }

        $g = WB_Engine::followupText($c, $bot, $msgs);
        if ($g['text'] === '') { return ['failed', null, null, mb_substr($g['error'] ?: 'empty_text', 0, 290)]; }
        [$sentOk, $sendErr, $mid] = self::send($c, $companyId, $contactId, $phone, $g['text']);
        WB_Store::addUsage($c, $companyId, $sentOk ? 1 : 0, (int)$g['tokens']);
        self::logRun($c, $companyId, $contactId, null, null, false, ['outcome' => $sentOk ? 'followup' : 'followup_failed', 'error' => $sentOk ? null : $sendErr,
            'prompt_tokens' => (int)$g['tokens'], 'completion_tokens' => 0]);
        return $sentOk ? ['sent', $g['text'], (int)$mid, null] : ['failed', $g['text'], (int)$mid, mb_substr((string)$sendErr, 0, 290)];
    }

    /* ── background worker launcher (same approach as webhook::triggerAiWorker) ── */

    public static function kickWorker(): void
    {
        try {
            $worker = realpath(__DIR__ . '/../../process_waamini_bot.php');
            if (!$worker) { return; }
            $php = 'php';
            foreach (['C:\\xampp\\php\\php.exe', 'D:\\xampp\\php\\php.exe', PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe', PHP_BINDIR . DIRECTORY_SEPARATOR . 'php'] as $cand) {
                if (@is_file($cand)) { $php = $cand; break; }
            }
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $h = @popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > NUL 2>&1', 'r');
                if (is_resource($h)) { @pclose($h); }
            } else {
                @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
            }
        } catch (Throwable $e) {
            error_log('[WAAMINI Bot] kickWorker: ' . $e->getMessage());
        }
    }
}
