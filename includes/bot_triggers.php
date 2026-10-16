<?php
/**
 * Bot Replies "Trigger Type" support.
 *
 * Stored in bot_replies.match_type:
 *   welcome        first message from a new customer (no keyword needed)
 *   exact          message IS the keyword
 *   starts_with    message starts with the keyword
 *   ends_with      message ends with the keyword
 *   contains_word  keyword appears as a whole word
 *   contains       keyword appears anywhere (Latin keywords still match whole words only)
 *   stop_promo     customer opts OUT of promotional messages
 *   start_promo    customer opts back IN to promotional messages
 *   stop_ai        pause the AI bot for this customer
 *   book_demo      customer asks for a demo / meeting; with a time it books + creates the Google Meet
 *   start_ai       resume the AI bot for this customer
 */

if (!function_exists('bt_trigger_types')) {

    /** value => [label, help text, needs keywords] */
    function bt_trigger_types(): array
    {
        return [
            'welcome'       => ['Welcome',            'Sent once when a new customer messages you for the first time.', false],
            'exact'         => ['Is',                 'The whole message is exactly the keyword, like "hi".', true],
            'starts_with'   => ['Starts with',        'The message begins with the keyword.', true],
            'ends_with'     => ['Ends with',          'The message finishes with the keyword.', true],
            'contains_word' => ['Contains whole word', 'The keyword appears as a separate word, so "hi" will not match "this".', true],
            'contains'      => ['Contains',           'The keyword appears anywhere in the message.', true],
            'stop_promo'    => ['Stop Promotional',   'Customer says words like STOP. They are removed from promotional campaigns.', true],
            'start_promo'   => ['Start Promotional',  'Customer says words like START. They can receive promotional campaigns again.', true],
            'start_ai'      => ['Start AI bot',       'Customer says a word that turns the AI bot on for their chat.', true],
            'stop_ai'       => ['Stop AI bot',        'Customer says a word that turns the AI bot off for their chat.', true],
            'book_demo'     => ['Book Demo / Meeting', 'Customer asks for a demo or meeting. If they include a time (for example "tomorrow 4 PM") a Google Meet is created and confirmed automatically. If not, your reply below asks for the details.', true],
        ];
    }

    function bt_is_demo_type(string $t): bool
    {
        return $t === 'book_demo';
    }

    function bt_is_action_type(string $t): bool
    {
        return in_array($t, ['stop_promo', 'start_promo', 'start_ai', 'stop_ai'], true);
    }

    /** Does one keyword match the (already lower-cased) text for this trigger type? */
    function bt_text_matches(string $type, string $kw, string $text): bool
    {
        if ($kw === '' || $text === '') return false;
        $lowerLen = static function (string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); };
        $pos = static function (string $h, string $n) { return function_exists('mb_strpos') ? mb_strpos($h, $n, 0, 'UTF-8') : strpos($h, $n); };

        switch ($type) {
            case 'exact':
                return $text === $kw;
            case 'starts_with':
                return strncmp($text, $kw, strlen($kw)) === 0;
            case 'ends_with':
                return strlen($text) >= strlen($kw) && substr($text, -strlen($kw)) === $kw;
            case 'contains_word':
            case 'stop_promo':
            case 'start_promo':
            case 'start_ai':
            case 'stop_ai':
            case 'book_demo':
                // whole word: not glued to letters/digits on either side (works for any language)
                return (bool)preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}_])/u', $text);
            case 'contains':
            default:
                // Latin keywords keep the long-standing whole-word behaviour ("hi" must not match "pathi")
                if (preg_match('/^[a-z0-9_\-]+$/', $kw) && $lowerLen($kw) >= 2) {
                    return (bool)preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text);
                }
                return $pos($text, $kw) !== false;
        }
    }

    /** Keep the schema ready: match_type must accept the new values; blacklist must exist. */
    function bt_ensure_schema($conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $r = $conn->query("SHOW COLUMNS FROM bot_replies LIKE 'match_type'");
            $row = $r ? $r->fetch_assoc() : null;
            if ($row && stripos((string)$row['Type'], 'enum') !== false) {
                $conn->query("ALTER TABLE bot_replies MODIFY COLUMN match_type VARCHAR(30) NOT NULL DEFAULT 'contains'");
            }
        } catch (\Throwable $e) { error_log('[bot_triggers] match_type: ' . $e->getMessage()); }
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS blacklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                phone VARCHAR(30) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_phone (company_id, phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) { error_log('[bot_triggers] blacklist: ' . $e->getMessage()); }
    }

    /** SQL fragment (uses alias c for contacts) that skips customers who opted out of promotions. '' if unusable. */
    function bt_optout_filter($conn): string
    {
        static $frag = null;
        if ($frag !== null) return $frag;
        bt_ensure_schema($conn);
        $norm = static function (string $col): string {
            return "CONVERT(RIGHT(REPLACE(REPLACE($col,'+',''),' ',''),10) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        };
        $hasOpted = false;
        try { $r = $conn->query("SHOW COLUMNS FROM contacts LIKE 'opted_in'"); $hasOpted = (bool)($r && $r->num_rows); } catch (\Throwable $e) {}
        $candidate = " AND NOT EXISTS (SELECT 1 FROM blacklist bl WHERE bl.company_id = c.company_id AND " . $norm('bl.phone') . " = " . $norm('c.phone') . ")"
                   . ($hasOpted ? " AND (c.opted_in IS NULL OR c.opted_in = 1)" : "");
        // Prove it works on this database before any campaign relies on it
        try {
            $ok = $conn->query("SELECT 1 FROM contacts c WHERE 1=0" . $candidate . " LIMIT 1");
            $frag = $ok ? $candidate : '';
            if (!$ok) error_log('[bot_triggers] opt-out filter unavailable: ' . $conn->error);
        } catch (\Throwable $e) { $frag = ''; error_log('[bot_triggers] opt-out filter: ' . $e->getMessage()); }
        return $frag;
    }

    /** Apply the side effect of an action trigger. Returns true when something was changed. */
    function bt_apply_action($conn, int $companyId, int $contactId, string $phoneDigits, string $action): bool
    {
        bt_ensure_schema($conn);
        $phoneDigits = preg_replace('/\D+/', '', $phoneDigits);
        try {
            if ($action === 'stop_promo' || $action === 'start_promo') {
                $hasOpted = false;
                $r = $conn->query("SHOW COLUMNS FROM contacts LIKE 'opted_in'");
                $hasOpted = (bool)($r && $r->num_rows);
                if ($action === 'stop_promo') {
                    if ($phoneDigits !== '') {
                        $reason = 'Customer replied STOP';
                        $st = $conn->prepare("INSERT IGNORE INTO blacklist (company_id, phone, reason) VALUES (?, ?, ?)");
                        if ($st) { $st->bind_param('iss', $companyId, $phoneDigits, $reason); $st->execute(); $st->close(); }
                    }
                    if ($hasOpted && $contactId > 0) {
                        $u = $conn->prepare("UPDATE contacts SET opted_in = 0 WHERE id = ? AND company_id = ?");
                        if ($u) { $u->bind_param('ii', $contactId, $companyId); $u->execute(); $u->close(); }
                    }
                } else {
                    if ($phoneDigits !== '') {
                        $like = '%' . substr($phoneDigits, -10);
                        $d = $conn->prepare("DELETE FROM blacklist WHERE company_id = ? AND phone LIKE ?");
                        if ($d) { $d->bind_param('is', $companyId, $like); $d->execute(); $d->close(); }
                    }
                    if ($hasOpted && $contactId > 0) {
                        $u = $conn->prepare("UPDATE contacts SET opted_in = 1 WHERE id = ? AND company_id = ?");
                        if ($u) { $u->bind_param('ii', $contactId, $companyId); $u->execute(); $u->close(); }
                    }
                }
                return true;
            }
            if (($action === 'stop_ai' || $action === 'start_ai') && $contactId > 0) {
                if ($action === 'stop_ai') {
                    $until = '2099-12-31 00:00:00'; $state = 'customer_stopped';
                    $st = $conn->prepare("INSERT INTO ai_conversation_state (company_id, contact_id, state, paused_until) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE state = VALUES(state), paused_until = VALUES(paused_until)");
                    if ($st) { $st->bind_param('iiss', $companyId, $contactId, $state, $until); $st->execute(); $st->close(); }
                } else {
                    $st = $conn->prepare("INSERT INTO ai_conversation_state (company_id, contact_id, state, paused_until, fail_count) VALUES (?, ?, 'open', NULL, 0)
                        ON DUPLICATE KEY UPDATE state = 'open', paused_until = NULL, fail_count = 0");
                    if ($st) { $st->bind_param('ii', $companyId, $contactId); $st->execute(); $st->close(); }
                }
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[bot_triggers] action ' . $action . ': ' . $e->getMessage());
        }
        return false;
    }

    /** True when the customer has sent exactly one inbound message so far (the one being routed now). */
    function bt_is_first_inbound($conn, int $companyId, int $contactId): bool
    {
        if ($contactId <= 0) return false;
        try {
            $st = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE company_id = ? AND contact_id = ? AND direction = 'incoming'");
            if (!$st) return false;
            $st->bind_param('ii', $companyId, $contactId);
            $st->execute();
            $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
            $st->close();
            return $n <= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
