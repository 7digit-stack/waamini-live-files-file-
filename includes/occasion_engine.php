<?php
/**
 * WAAMINI personal occasions: birthdays, wedding anniversaries, business anniversaries and other
 * yearly dates per customer, with one WhatsApp wish per occasion type.
 *
 * - contact_occasions: one row per customer and occasion (date; the year is optional).
 * - occasion_wishes:   per company and type: message, template, when to send, "send automatically".
 * - occasion_sends:    one row per occasion and year, so nobody is wished twice.
 * Sending runs from festival_dispatch_due() (every minute via the queue worker, 9 am to 9 pm IST) and
 * uses the same rules as festival wishes: approved template for everyone, or free text only inside
 * WhatsApp's 24 h window.
 */

require_once __DIR__ . '/festival_engine.php';

function occasion_types(): array
{
    return [
        'birthday'             => ['label' => 'Birthday',             'word' => 'birthday'],
        'anniversary'          => ['label' => 'Wedding anniversary',  'word' => 'wedding anniversary'],
        'business_anniversary' => ['label' => 'Business anniversary', 'word' => 'business anniversary'],
        'custom'               => ['label' => 'Other day',            'word' => 'special day'],
    ];
}

function occasion_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS contact_occasions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            occasion_type VARCHAR(30) NOT NULL,
            label VARCHAR(100) DEFAULT NULL,
            occasion_date DATE NOT NULL,
            year_known TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_contact_occasion (company_id, contact_id, occasion_type, label),
            INDEX idx_company_md (company_id, occasion_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS occasion_wishes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            occasion_type VARCHAR(30) NOT NULL,
            message TEXT NOT NULL,
            template_name VARCHAR(255) DEFAULT NULL,
            template_lang VARCHAR(20) DEFAULT NULL,
            days_before INT NOT NULL DEFAULT 0,
            auto_send TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_type (company_id, occasion_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS occasion_sends (
            id INT AUTO_INCREMENT PRIMARY KEY,
            occasion_id INT NOT NULL,
            company_id INT NOT NULL,
            send_year SMALLINT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            queue_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_occasion_year (occasion_id, send_year),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        error_log('[occasions] schema: ' . $e->getMessage());
    }
}

function occasion_default_message(string $type): string
{
    switch ($type) {
        case 'birthday':
            return "🎂 *Happy Birthday, {{first_name}}!*\n\nWishing you a wonderful year ahead, full of joy and good health.\n\nWarm wishes,\n*{{business_name}}*";
        case 'anniversary':
            return "💐 *Happy Anniversary, {{first_name}}!*\n\nWishing you both many more years of love and happiness.\n\nWarm wishes,\n*{{business_name}}*";
        case 'business_anniversary':
            return "🎉 *Happy {{years}}business anniversary, {{first_name}}!*\n\nCongratulations on another year of success. Thank you for being with us.\n\n*{{business_name}}*";
        default:
            return "✨ *Happy {{occasion}}, {{first_name}}!*\n\nThinking of you on your special day.\n\nWarm wishes,\n*{{business_name}}*";
    }
}

/** Wish settings for every type (defaults when not saved yet; automatic sending starts off). */
function occasion_wishes(mysqli $conn, int $companyId): array
{
    occasion_ensure_schema($conn);
    $out = [];
    foreach (occasion_types() as $type => $t) {
        $out[$type] = ['occasion_type' => $type, 'message' => occasion_default_message($type), 'template_name' => null,
                       'template_lang' => null, 'days_before' => 0, 'auto_send' => 0, 'saved' => false];
    }
    $st = $conn->prepare("SELECT * FROM occasion_wishes WHERE company_id=?");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (isset($out[$r['occasion_type']])) $out[$r['occasion_type']] = $r + ['saved' => true];
        }
    }
    return $out;
}

/** The date an occasion falls on in a given year (29 Feb becomes 28 Feb in other years). */
function occasion_date_in_year(string $date, int $year): string
{
    $md = substr($date, 5);
    if ($md === '02-29' && !checkdate(2, 29, $year)) $md = '02-28';
    return $year . '-' . $md;
}

/** "5th " for the 5th anniversary / 30th birthday when the year is known, else "". */
function occasion_ordinal(int $n): string
{
    if ($n <= 0) return '';
    $s = 'th';
    if (!in_array($n % 100, [11, 12, 13], true)) {
        $s = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
    }
    return $n . $s . ' ';
}

/**
 * Occasions falling between two dates (inclusive), one entry per occurrence.
 * Each: id, contact_id, name, phone, type, label, title, date, years (0 when the year is unknown).
 */
function occasion_between(mysqli $conn, int $companyId, string $from, string $to): array
{
    occasion_ensure_schema($conn);
    $st = $conn->prepare("SELECT o.*, c.name, c.phone FROM contact_occasions o JOIN contacts c ON c.id=o.contact_id AND c.company_id=o.company_id WHERE o.company_id=?");
    if (!$st) return [];
    $st->bind_param('i', $companyId);
    $st->execute();
    $types = occasion_types();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
            $d = occasion_date_in_year((string)$o['occasion_date'], $y);
            if ($d < $from || $d > $to) continue;
            $years = (int)$o['year_known'] ? $y - (int)substr((string)$o['occasion_date'], 0, 4) : 0;
            $what = $o['occasion_type'] === 'custom' && trim((string)$o['label']) !== '' ? trim((string)$o['label']) : $types[$o['occasion_type']]['label'] ?? 'Occasion';
            $first = trim(explode(' ', trim((string)$o['name']))[0] ?? '');
            $out[] = [
                'id' => (int)$o['id'], 'contact_id' => (int)$o['contact_id'], 'name' => (string)$o['name'], 'phone' => (string)$o['phone'],
                'type' => (string)$o['occasion_type'], 'label' => (string)($o['label'] ?? ''), 'date' => $d, 'years' => max(0, $years),
                'title' => ($first !== '' ? $first : 'Customer') . "'s " . strtolower($what), 'what' => $what,
                'original' => (string)$o['occasion_date'], 'year_known' => (int)$o['year_known'],
            ];
        }
    }
    usort($out, static fn($a, $b) => strcmp($a['date'] . $a['name'], $b['date'] . $b['name']));
    return $out;
}

/** Queue today's personal wishes (types switched to "Send automatically"). Returns messages queued. */
function occasion_dispatch_due(mysqli $conn, int $batch = 200): int
{
    occasion_ensure_schema($conn);
    $ist = new DateTimeZone('Asia/Kolkata');
    $hour = (int)(new DateTime('now', $ist))->format('G');
    if ($hour < 9 || $hour >= 21) return 0;
    $today = (new DateTime('now', $ist))->format('Y-m-d');

    $wishes = $conn->query("SELECT * FROM occasion_wishes WHERE auto_send=1");
    if (!$wishes) return 0;
    $queued = 0;
    while ($w = $wishes->fetch_assoc()) {
        $companyId = (int)$w['company_id'];
        $target = date('Y-m-d', strtotime($today . ' +' . max(0, (int)$w['days_before']) . ' days'));
        $year = (int)substr($target, 0, 4);
        $due = array_filter(occasion_between($conn, $companyId, $target, $target), static fn($o) => $o['type'] === $w['occasion_type']);
        if (!$due) continue;

        $chk = $conn->prepare("SELECT 1 FROM occasion_sends WHERE occasion_id=? AND send_year=? LIMIT 1");
        $mark = $conn->prepare("INSERT IGNORE INTO occasion_sends (occasion_id, company_id, send_year, status, queue_id) VALUES (?, ?, ?, ?, ?)");
        $insQ = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at, payload_json) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW(), ?)");
        $biz = ''; $bizPhone = '';
        $st = $conn->prepare("SELECT name FROM companies WHERE id=?");
        if ($st) { $st->bind_param('i', $companyId); $st->execute(); $biz = (string)($st->get_result()->fetch_assoc()['name'] ?? ''); }
        $st = $conn->prepare("SELECT display_phone FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
        if ($st) { $st->bind_param('i', $companyId); $st->execute(); $bizPhone = (string)($st->get_result()->fetch_assoc()['display_phone'] ?? ''); }
        $tpl = null;
        if (trim((string)$w['template_name']) !== '') {
            $tpls = festival_templates($conn, $companyId);
            $tpl = $tpls[trim((string)$w['template_name'])] ?? null;
        }
        $optOut = '';
        if (is_file(__DIR__ . '/bot_triggers.php')) {
            require_once __DIR__ . '/bot_triggers.php';
            if (function_exists('bt_optout_filter')) $optOut = bt_optout_filter($conn);
        }

        $n = 0;
        foreach ($due as $o) {
            if ($n++ >= $batch) break;
            $oid = $o['id'];
            if ($chk) { $chk->bind_param('ii', $oid, $year); $chk->execute(); if ($chk->get_result()->num_rows) continue; }
            // Customer must still be reachable (opted in, not blocked, not on the STOP list)
            $ok = $conn->prepare("SELECT (SELECT MAX(m.created_at) FROM messages m WHERE m.company_id=c.company_id AND m.contact_id=c.id AND m.direction='incoming') AS last_in
                                  FROM contacts c WHERE c.id=? AND c.company_id=? AND c.opted_in=1 AND c.status<>'blocked' AND c.phone<>''" . $optOut);
            $cid = $o['contact_id'];
            $row = null;
            if ($ok) { $ok->bind_param('ii', $cid, $companyId); $ok->execute(); $row = $ok->get_result()->fetch_assoc(); }
            $status = 'skipped';
            $qid = null;
            if ($row) {
                $first = trim(explode(' ', trim($o['name']))[0] ?? '');
                $ord = occasion_ordinal($o['years']);
                $text = str_replace(
                    ['{{first_name}}', '{{name}}', '{{occasion}}', '{{years}}', '{{business_name}}', '{{phone}}'],
                    [$first, $o['name'], strtolower($o['what']), $ord, $biz, $bizPhone],
                    (string)$w['message']
                );
                $inWindow = $row['last_in'] && (time() - strtotime((string)$row['last_in'])) < 22 * 3600;
                $type = '';
                if ($tpl) {
                    $type = 'template';
                    $vals = [$first !== '' ? $first : 'there', strtolower($o['what']), $biz, trim($ord) !== '' ? trim($ord) : '-'];
                    $params = [];
                    for ($i = 0; $i < (int)$tpl['vars']; $i++) $params[] = ['type' => 'text', 'text' => (string)($vals[$i] ?? '-')];
                    $payload = json_encode(['template_name' => $tpl['name'], 'language_code' => (string)($tpl['language'] ?: 'en'),
                        'components' => $params ? [['type' => 'body', 'parameters' => $params]] : [], 'occasion_id' => $oid], JSON_UNESCAPED_UNICODE);
                    $body = ucfirst(strtolower($o['what'])) . ' wish (template ' . $tpl['name'] . ')';
                } elseif ($inWindow) {
                    $type = 'text';
                    $payload = json_encode(['occasion_id' => $oid]);
                    $body = $text;
                }
                if ($type !== '' && $insQ) {
                    $ph = $o['phone'];
                    $insQ->bind_param('iissss', $companyId, $cid, $type, $ph, $body, $payload);
                    if ($insQ->execute()) { $qid = (int)$conn->insert_id; $status = 'queued'; $queued++; }
                }
            }
            if ($mark) { $mark->bind_param('iiisi', $oid, $companyId, $year, $status, $qid); $mark->execute(); }
        }
    }
    return $queued;
}
