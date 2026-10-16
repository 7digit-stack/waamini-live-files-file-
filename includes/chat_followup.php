<?php
/**
 * Account-level "no reply" auto-reply, based on the chat itself.
 *
 * Each client account (company) can switch it on in Flow Builder > Auto follow-up. When the customer's LAST message in a
 * chat has had no answer for N minutes (default 60) from anyone (agent, bot or flow), one polite message is sent.
 * It is sent at most once per unanswered customer message, never while a flow conversation is open, and never after
 * the 24 hour WhatsApp window has closed (free-form messages are not allowed then).
 *
 *   chat_followup_settings  one row per company: enabled, after_minutes, message
 *   chat_followup_log       one row per customer message that was followed up (unique per company + message id)
 */

function chat_followup_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS chat_followup_settings (
        company_id INT NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        after_minutes INT NOT NULL DEFAULT 60,
        message TEXT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS chat_followup_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        inbound_message_id BIGINT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'sending',
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_inbound (company_id, inbound_message_id),
        KEY idx_company_time (company_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

const CHAT_FOLLOWUP_DEFAULT_TEXT = 'Thanks for your message! Our team will get back to you shortly.';

function chat_followup_get(mysqli $conn, int $companyId): array
{
    chat_followup_ensure_schema($conn);
    $st = $conn->prepare("SELECT enabled, after_minutes, message FROM chat_followup_settings WHERE company_id = ? LIMIT 1");
    $st->bind_param('i', $companyId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return [
        'enabled' => (int)($r['enabled'] ?? 0) === 1,
        'after_minutes' => (int)($r['after_minutes'] ?? 60) ?: 60,
        'message' => trim((string)($r['message'] ?? '')) !== '' ? (string)$r['message'] : CHAT_FOLLOWUP_DEFAULT_TEXT,
    ];
}

function chat_followup_save(mysqli $conn, int $companyId, bool $enabled, int $minutes, string $message): void
{
    chat_followup_ensure_schema($conn);
    $minutes = max(5, min(1380, $minutes));          // 5 minutes .. 23 hours (stay inside the 24 hour window)
    $message = trim(mb_substr($message, 0, 1000));
    if ($message === '') $message = CHAT_FOLLOWUP_DEFAULT_TEXT;
    $en = $enabled ? 1 : 0;
    $st = $conn->prepare("INSERT INTO chat_followup_settings (company_id, enabled, after_minutes, message, updated_at) VALUES (?,?,?,?, NOW())
                          ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), after_minutes = VALUES(after_minutes), message = VALUES(message), updated_at = NOW()");
    $st->bind_param('iiis', $companyId, $en, $minutes, $message);
    $st->execute();
    $st->close();
}

final class ChatFollowup
{
    /**
     * @param callable|null $senderFactory fn(int $companyId, int $contactId, string $phone): FlowSender  (tests pass a mock)
     * @return int number of follow-ups sent
     */
    public static function process(mysqli $conn, int $limit = 50, ?callable $senderFactory = null): int
    {
        chat_followup_ensure_schema($conn);
        require_once __DIR__ . '/flow/sender.php';
        $sent = 0;

        $cs = $conn->query("SELECT company_id, after_minutes, message FROM chat_followup_settings WHERE enabled = 1");
        $companies = [];
        while ($cs && ($c = $cs->fetch_assoc())) $companies[] = $c;

        foreach ($companies as $co) {
            if ($sent >= $limit) break;
            $cid = (int)$co['company_id'];
            $mins = max(5, min(1380, (int)$co['after_minutes']));
            // customer's latest message is the unanswered one, old enough, still inside the 24h window,
            // not already followed up, and no flow conversation is open with this customer
            $sql = "SELECT m.id AS inbound_id, m.contact_id, c.name, c.phone
                    FROM messages m
                    JOIN contacts c ON c.id = m.contact_id AND c.company_id = m.company_id
                    WHERE m.company_id = ? AND m.direction = 'incoming'
                      AND m.id = (SELECT MAX(x.id) FROM messages x WHERE x.company_id = m.company_id AND x.contact_id = m.contact_id)
                      AND m.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                      AND m.created_at >= DATE_SUB(NOW(), INTERVAL 23 HOUR)
                      AND NOT EXISTS (SELECT 1 FROM chat_followup_log l WHERE l.company_id = m.company_id AND l.inbound_message_id = m.id)
                      AND NOT EXISTS (SELECT 1 FROM flow_sessions s WHERE s.company_id = m.company_id AND s.contact_id = m.contact_id AND s.active_key = 1)
                    ORDER BY m.id ASC LIMIT 25";
            $st = $conn->prepare($sql);
            $st->bind_param('ii', $cid, $mins);
            $st->execute();
            $rows = [];
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $rows[] = $x;
            $st->close();

            foreach ($rows as $x) {
                if ($sent >= $limit) break;
                // claim first: if another worker already did, the unique key stops a double send
                $claim = $conn->prepare("INSERT IGNORE INTO chat_followup_log (company_id, contact_id, inbound_message_id, status, created_at) VALUES (?,?,?, 'sending', NOW())");
                $claim->bind_param('iii', $cid, $x['contact_id'], $x['inbound_id']);
                $claim->execute();
                $won = $conn->affected_rows === 1;
                $claim->close();
                if (!$won) continue;

                $first = (string)(preg_split('/\s+/', trim((string)$x['name']))[0] ?? '');
                $text = str_replace(['{{contact.first_name}}', '{{contact.name}}'], [$first, (string)$x['name']], (string)$co['message']);
                $err = null;
                try {
                    $sender = $senderFactory ? $senderFactory($cid, (int)$x['contact_id'], (string)$x['phone'])
                                             : new FlowWaSender($conn, $cid, (int)$x['contact_id'], (string)$x['phone']);
                    $res = $sender->text($text);
                    if (!$res['ok']) $err = (string)$res['error'];
                } catch (\Throwable $e) { $err = $e->getMessage(); }

                $status = $err === null ? 'sent' : 'failed';
                $e2 = $err === null ? null : mb_substr($err, 0, 250);
                $u = $conn->prepare("UPDATE chat_followup_log SET status = ?, error = ? WHERE company_id = ? AND inbound_message_id = ?");
                $u->bind_param('ssii', $status, $e2, $cid, $x['inbound_id']);
                $u->execute();
                $u->close();
                if ($err === null) $sent++;
                else error_log('[chat followup] company ' . $cid . ' contact ' . $x['contact_id'] . ': ' . $err);
            }
        }
        return $sent;
    }
}
