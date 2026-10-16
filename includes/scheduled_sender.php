<?php
/**
 * Scheduled Blasts sender: delivers scheduled_messages rows whose time has come.
 *
 * Runs from workers/automation_queue_worker.php (cron, every minute) and from api/scheduled_tick.php
 * (browser fallback while the panel is open), so messages go out with or without a cron job.
 * Each row is claimed (pending → processing) before sending, so two runners never send it twice.
 */
require_once __DIR__ . '/../services/WhatsAppService.php';

function sm_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM scheduled_messages");
        while ($r && ($c = $r->fetch_assoc())) $cols[$c['Field']] = (string)$c['Type'];
        if (!$cols) return;
        if (strpos($cols['status'] ?? '', 'processing') === false) {
            $conn->query("ALTER TABLE scheduled_messages MODIFY status ENUM('pending','processing','sent','cancelled','failed') NOT NULL DEFAULT 'pending'");
        }
        if (!isset($cols['error_message'])) $conn->query("ALTER TABLE scheduled_messages ADD COLUMN error_message TEXT NULL");
        if (!isset($cols['sent_at']))       $conn->query("ALTER TABLE scheduled_messages ADD COLUMN sent_at DATETIME NULL");
        if (!isset($cols['wa_message_id'])) $conn->query("ALTER TABLE scheduled_messages ADD COLUMN wa_message_id VARCHAR(128) NULL");
    } catch (\Throwable $e) {
        error_log('[scheduled_sender] schema: ' . $e->getMessage());
    }
}

/**
 * Send everything that is due. $companyId = 0 means all companies (cron).
 * Times are compared with PHP's clock, the same clock the page used to save scheduled_at.
 * Returns ['processed' => n, 'sent' => n, 'failed' => n].
 */
function sm_process_due(mysqli $conn, int $companyId = 0, int $limit = 25): array
{
    sm_ensure_schema($conn);
    $now = date('Y-m-d H:i:s');
    $out = ['processed' => 0, 'sent' => 0, 'failed' => 0];

    // A runner that died mid-send leaves rows in "processing"; give up on them after 10 minutes.
    $stale = date('Y-m-d H:i:s', time() - 600);
    $st = $conn->prepare("UPDATE scheduled_messages SET status='failed', error_message='Sending was interrupted. Schedule it again.' WHERE status='processing' AND scheduled_at < ?");
    if ($st) { $st->bind_param('s', $stale); $st->execute(); }

    $sql = "SELECT id, company_id, phone, message, template_id FROM scheduled_messages
            WHERE status='pending' AND scheduled_at <= ?" . ($companyId > 0 ? " AND company_id = ?" : "") . "
            ORDER BY scheduled_at ASC LIMIT ?";
    $st = $conn->prepare($sql);
    if (!$st) { error_log('[scheduled_sender] select: ' . $conn->error); return $out; }
    if ($companyId > 0) $st->bind_param('sii', $now, $companyId, $limit); else $st->bind_param('si', $now, $limit);
    $st->execute();
    $due = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $claim = $conn->prepare("UPDATE scheduled_messages SET status='processing' WHERE id=? AND status='pending'");
    foreach ($due as $row) {
        $id = (int)$row['id'];
        $claim->bind_param('i', $id);
        $claim->execute();
        if ($claim->affected_rows !== 1) continue; // another runner took it, or it was cancelled
        $out['processed']++;

        $r = sm_send_one($conn, $row);
        if ($r['ok']) {
            $up = $conn->prepare("UPDATE scheduled_messages SET status='sent', sent_at=NOW(), wa_message_id=?, error_message=NULL WHERE id=?");
            $up->bind_param('si', $r['wa_id'], $id);
            $up->execute();
            $out['sent']++;
        } else {
            $err = mb_substr($r['error'], 0, 1000);
            $up = $conn->prepare("UPDATE scheduled_messages SET status='failed', error_message=? WHERE id=?");
            $up->bind_param('si', $err, $id);
            $up->execute();
            $out['failed']++;
        }
    }
    return $out;
}

/** Send one scheduled row. Returns ['ok' => bool, 'wa_id' => string, 'error' => string]. */
function sm_send_one(mysqli $conn, array $row): array
{
    $fail = static fn(string $e) => ['ok' => false, 'wa_id' => '', 'error' => $e];
    $companyId = (int)$row['company_id'];
    $phone = preg_replace('/\D+/', '', (string)$row['phone']);
    if (strlen($phone) < 10) return $fail('The phone number is not valid.');

    $cfg = WhatsAppService::getConfig($conn, $companyId);
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
        return $fail('WhatsApp is not connected. Add the access token and phone number ID in WhatsApp settings.');
    }

    // The contact (if saved) gives {{1}} a name and lets the message show up in WhatsApp Chat.
    $contact = null;
    $last10 = substr($phone, -10);
    $st = $conn->prepare("SELECT id, name FROM contacts WHERE company_id=? AND CONVERT(RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),')',''),10) USING utf8mb4) COLLATE utf8mb4_general_ci = ? LIMIT 1");
    if ($st) {
        $st->bind_param('is', $companyId, $last10);
        $st->execute();
        $contact = $st->get_result()->fetch_assoc() ?: null;
    }
    $name = trim((string)($contact['name'] ?? ''));

    $meta = [];
    $templateId = (int)$row['template_id'];
    if ($templateId > 0) {
        $st = $conn->prepare("SELECT * FROM message_templates WHERE id=? AND company_id=? LIMIT 1");
        $st->bind_param('ii', $templateId, $companyId);
        $st->execute();
        $tpl = $st->get_result()->fetch_assoc();
        if (!$tpl) return $fail('The chosen template no longer exists.');
        if (strtoupper((string)($tpl['wa_status'] ?? '')) !== 'APPROVED') return $fail('The template "' . $tpl['name'] . '" is not approved by Meta.');

        $components = [];
        $hType = strtolower((string)($tpl['header_type'] ?? ''));
        if (in_array($hType, ['image', 'video', 'document'], true)) {
            require_once __DIR__ . '/public_url.php';
            $link = wa_public_media_url((string)($tpl['header_link'] ?? '')); // completes "uploads/…" paths
            if (!preg_match('#^https://#i', $link)) return $fail('This template has a ' . $hType . ' header without a public https link. Use Campaigns to send it with the ' . $hType . '.');
            $components[] = ['type' => 'header', 'parameters' => [['type' => $hType, $hType => ['link' => $link]]]];
        }
        $body = (string)(($tpl['body'] ?? '') !== '' ? $tpl['body'] : ($tpl['body_text'] ?? ''));
        $vars = 0;
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) $vars = min(10, max(array_map('intval', $m[1])));
        if ($vars > 0) {
            // {{1}} = the contact's name, or the typed message if one was given; the rest get a dash.
            $typed = trim((string)$row['message']);
            $params = [];
            for ($i = 1; $i <= $vars; $i++) {
                $v = $i === 1 ? ($typed !== '' ? $typed : ($name !== '' ? $name : 'there')) : '-';
                $params[] = ['type' => 'text', 'text' => $v];
            }
            $components[] = ['type' => 'body', 'parameters' => $params];
        }
        $waId = send_whatsapp_template($phone, (string)$tpl['name'], (string)(($tpl['language'] ?? '') ?: 'en'), $components, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], $meta);
        $logText = $body !== '' ? preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', static fn($x) => $x[1] === '1' ? ($name !== '' ? $name : 'there') : '-', $body) : '[Template: ' . $tpl['name'] . ']';
    } else {
        $text = trim((string)$row['message']);
        if ($text === '') return $fail('The message is empty.');
        $waId = send_whatsapp_text($phone, $text, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], $cfg['api_version'] ?? null, $meta);
        $logText = $text;
    }

    if (!$waId) return $fail(sm_meta_error($meta));

    if ($contact) {
        try {
            $cid = (int)$contact['id'];
            $wa = (string)$waId;
            $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, is_read, status, whatsapp_message_id) VALUES (?,?,'outgoing','text',?,?,1,'sent',?)");
            if ($ins) { $ins->bind_param('iisss', $companyId, $cid, $logText, $logText, $wa); $ins->execute(); }
            $preview = mb_substr((string)$logText, 0, 800);
            $cv = $conn->prepare("INSERT INTO chat_conversations (company_id, contact_id, last_message, last_message_at, unread_count) VALUES (?,?,?,NOW(),0)
                                  ON DUPLICATE KEY UPDATE last_message=VALUES(last_message), last_message_at=VALUES(last_message_at)");
            if ($cv) { $cv->bind_param('iis', $companyId, $cid, $preview); $cv->execute(); }
        } catch (\Throwable $e) {
            error_log('[scheduled_sender] inbox log: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'wa_id' => (string)$waId, 'error' => ''];
}

/** A readable reason from the Graph API response. */
function sm_meta_error(array $meta): string
{
    $j = json_decode((string)($meta['response'] ?? ''), true);
    $e = is_array($j) ? ($j['error'] ?? []) : [];
    $code = (int)($e['code'] ?? 0);
    $msg = (string)($e['error_data']['details'] ?? $e['error_user_msg'] ?? $e['message'] ?? '');
    if ($code === 131047 || stripos($msg, '24 hours') !== false || stripos($msg, 're-engagement') !== false) {
        return 'More than 24 hours have passed since this customer last messaged you, so WhatsApp only allows an approved template. Schedule it again with a template.';
    }
    if ($msg === '' && !empty($meta['curl_error'])) $msg = 'Could not reach WhatsApp: ' . $meta['curl_error'];
    if ($msg === '') $msg = 'WhatsApp did not accept the message' . (!empty($meta['http_code']) ? ' (HTTP ' . (int)$meta['http_code'] . ')' : '') . '.';
    return $code ? $msg . ' (code ' . $code . ')' : $msg;
}
