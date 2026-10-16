<?php
/**
 * WAAMINI festival engine.
 *
 * - Calendar: live "Holidays in India" feed from Google Calendar (national holidays, Hindu, Muslim,
 *   Christian, Sikh, Jain, Buddhist and regional festivals, 2021-2031), cached for 7 days, with a bundled
 *   copy (includes/data/india_holidays.ics) when the feed cannot be reached. Business days that are not
 *   holidays (Mother's Day, Teachers' Day ...) are added by rule, and Tamil Nadu festivals (Pongal,
 *   Thaipusam, Karthigai Deepam ...) are computed by includes/tamil_calendar.php.
 * - Campaigns (table festival_campaigns): one row per festival and year for a company. Rows that were not
 *   sent yet get their date corrected from the calendar.
 * - Sending: festival_dispatch_due() runs from the every-minute queue worker (and from the old cron URLs).
 *   It only sends campaigns the business switched to "Send automatically", queues one WhatsApp message per
 *   contact through automation_queue (approved template, or free text inside the 24 h window), never queues
 *   the same contact twice (festival_sends), and creates next year's campaign afterwards.
 */

require_once __DIR__ . '/tamil_calendar.php';

if (!defined('WAAMINI_FESTIVAL_FEED')) {
    define('WAAMINI_FESTIVAL_FEED', 'https://calendar.google.com/calendar/ical/en.indian%23holiday%40group.v.calendar.google.com/public/basic.ics');
}

/* ---------------------------------------------------------------- schema */

function festival_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS festival_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            festival_key VARCHAR(100) NOT NULL,
            festival_name VARCHAR(150) NOT NULL,
            festival_date DATE NOT NULL,
            message TEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            sent TINYINT(1) DEFAULT 0,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            festival_region VARCHAR(30) NOT NULL DEFAULT 'all',
            days_before INT NOT NULL DEFAULT 1,
            INDEX idx_company (company_id),
            INDEX idx_date (festival_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* exists */ }

    $have = [];
    try {
        $r = $conn->query("SHOW COLUMNS FROM festival_campaigns");
        while ($r && ($c = $r->fetch_assoc())) $have[$c['Field']] = true;
    } catch (\Throwable $e) {}
    $add = [
        // Switched on by the business on the calendar page; old rows stay off until reviewed
        'auto_send'     => "ALTER TABLE festival_campaigns ADD COLUMN auto_send TINYINT(1) NOT NULL DEFAULT 0",
        'template_name' => "ALTER TABLE festival_campaigns ADD COLUMN template_name VARCHAR(255) DEFAULT NULL",
        'template_lang' => "ALTER TABLE festival_campaigns ADD COLUMN template_lang VARCHAR(20) DEFAULT NULL",
        'queued_count'  => "ALTER TABLE festival_campaigns ADD COLUMN queued_count INT NOT NULL DEFAULT 0",
        'skipped_count' => "ALTER TABLE festival_campaigns ADD COLUMN skipped_count INT NOT NULL DEFAULT 0",
        'last_note'     => "ALTER TABLE festival_campaigns ADD COLUMN last_note VARCHAR(255) DEFAULT NULL",
        'festival_type' => "ALTER TABLE festival_campaigns ADD COLUMN festival_type VARCHAR(20) DEFAULT NULL",
    ];
    foreach ($add as $col => $sql) {
        if (!isset($have[$col])) {
            try { $conn->query($sql); } catch (\Throwable $e) { error_log('[festival] ' . $e->getMessage()); }
        }
    }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS festival_sends (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            queue_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_campaign_contact (campaign_id, contact_id),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* exists */ }
}

/* -------------------------------------------------------------- calendar */

/** Stable key for a festival name; also maps the names used by older WAAMINI rows. */
function festival_key(string $name): string
{
    $n = strtolower(trim(preg_replace('/\s*\(.*\)\s*/', ' ', $name)));
    $alias = [
        "new year's day" => 'new_year', 'diwali/deepavali' => 'diwali', 'deepavali' => 'diwali', 'ramzan id' => 'eid',
        'eid ul-fitr' => 'eid', 'eid al-fitr' => 'eid', 'bakrid' => 'eid_adha', 'eid al-adha' => 'eid_adha', 'eid ul-adha' => 'eid_adha',
        'mahatma gandhi jayanti' => 'gandhi_jayanti', 'first day of sharad navratri' => 'navratri', 'navratri' => 'navratri',
        'bhai duj' => 'bhai_dooj', 'bhai dooj' => 'bhai_dooj', 'muharram/ashura' => 'muharram', 'milad un-nabi' => 'milad_un_nabi',
        'janmashtami' => 'janmashtami', 'chhat puja' => 'chhath_puja', 'makar sankranti' => 'makar_sankranti',
        'first day of durga puja festivities' => 'durga_puja',
        "valentine's day" => 'valentines', "mother's day" => 'mothers_day', "father's day" => 'fathers_day',
        "children's day" => 'childrens_day', "women's day" => 'womens_day', "new year's eve" => 'new_years_eve',
        'teachers day' => 'teachers_day', "teachers' day" => 'teachers_day', 'doctors day' => 'doctors_day',
        'thai pongal' => 'pongal', 'puthandu' => 'tamil_new_year',
    ];
    if (isset($alias[$n])) return $alias[$n];
    $k = preg_replace('/[^a-z0-9]+/', '_', $n);
    return trim((string)$k, '_');
}

/** Region a festival is mainly celebrated in (used for filtering only). */
function festival_region(string $key): string
{
    static $map = [
        'pongal' => 'tamil', 'tamil_new_year' => 'tamil', 'aadi_perukku' => 'tamil', 'karthigai_deepam' => 'tamil',
        'bhogi' => 'tamil', 'thiruvalluvar_day' => 'tamil', 'uzhavar_thirunal' => 'tamil', 'thaipusam' => 'tamil',
        'thai_amavasai' => 'tamil', 'masi_magam' => 'tamil', 'panguni_uthiram' => 'tamil', 'chithra_pournami' => 'tamil',
        'vaikasi_visakam' => 'tamil', 'aadi_amavasai' => 'tamil', 'tamil_nadu_day' => 'tamil', 'mahalaya_amavasai' => 'tamil',
        'vaikunta_ekadasi' => 'tamil', 'ayudha_puja' => 'tamil',
        'onam' => 'kerala', 'vishu' => 'kerala', 'ugadi' => 'south', 'gudi_padwa' => 'west', 'durga_puja' => 'east',
        'maha_saptami' => 'east', 'maha_ashtami' => 'east', 'vaisakhadi' => 'east', 'bahag_bihu' => 'east',
        'chhath_puja' => 'north', 'karaka_chaturthi' => 'north', 'lohri' => 'north', 'holika_dahana' => 'north',
        'vaisakhi' => 'north', 'cheti_chand' => 'west',
    ];
    return $map[$key] ?? 'all';
}

/** Business / greeting days that are not in the holiday feed. */
function festival_rule_days(int $year): array
{
    $nthSunday = static function (int $y, int $m, int $n): string {
        $d = strtotime(sprintf('%04d-%02d-01', $y, $m));
        $first = (int)date('w', $d); // 0 = Sunday
        $day = 1 + ((7 - $first) % 7) + 7 * ($n - 1);
        return sprintf('%04d-%02d-%02d', $y, $m, $day);
    };
    return [
        ['Valentine\'s Day', sprintf('%04d-02-14', $year), 'special'],
        ['Women\'s Day', sprintf('%04d-03-08', $year), 'special'],
        ['Mother\'s Day', $nthSunday($year, 5, 2), 'special'],
        ['Father\'s Day', $nthSunday($year, 6, 3), 'special'],
        ['Doctors Day', sprintf('%04d-07-01', $year), 'special'],
        ['Friendship Day', $nthSunday($year, 8, 1), 'special'],
        ['Teachers Day', sprintf('%04d-09-05', $year), 'special'],
        ['Children\'s Day', sprintf('%04d-11-14', $year), 'special'],
        ['New Year\'s Eve', sprintf('%04d-12-31', $year), 'special'],
    ];
}

/** Raw ICS text: fresh feed (cached 7 days), else last cached copy, else the bundled copy. */
function festival_feed_text(bool $forceRefresh = false): string
{
    $cacheDir = __DIR__ . '/../storage/cache';
    $cache = $cacheDir . '/festivals_india.ics';
    $fresh = is_file($cache) && (time() - (int)@filemtime($cache)) < 7 * 86400;
    if ($fresh && !$forceRefresh) {
        return (string)@file_get_contents($cache);
    }
    $txt = '';
    if (function_exists('curl_init')) {
        $ch = curl_init(WAAMINI_FESTIVAL_FEED);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && is_string($body) && strpos($body, 'BEGIN:VCALENDAR') !== false) {
            $txt = $body;
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            @file_put_contents($cache, $txt);
        }
    }
    if ($txt === '' && is_file($cache)) $txt = (string)@file_get_contents($cache);
    if ($txt === '') $txt = (string)@file_get_contents(__DIR__ . '/data/india_holidays.ics');
    return $txt;
}

/**
 * All festivals between two dates, sorted by date.
 * Each: ['key','name','date' => 'Y-m-d','type' => national|holiday|observance|special,'region','tentative' => bool]
 */
function festival_events(string $from, string $to): array
{
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $txt = str_replace(["\r\n ", "\n "], '', festival_feed_text()); // unfold long lines
        if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $txt, $m)) {
            foreach ($m[1] as $ev) {
                if (!preg_match('/DTSTART(?:;VALUE=DATE)?:(\d{4})(\d{2})(\d{2})/', $ev, $d)) continue;
                if (!preg_match('/\nSUMMARY:([^\r\n]+)/', $ev, $s)) continue;
                $desc = preg_match('/\nDESCRIPTION:([^\r\n]+)/', $ev, $x) ? $x[1] : '';
                $name = trim(str_replace(['\\,', '\\;'], [',', ';'], $s[1]));
                $key = festival_key($name);
                $type = stripos($desc, 'Public holiday') !== false ? 'holiday' : 'observance';
                if (in_array($key, ['republic_day', 'independence_day', 'gandhi_jayanti'], true)) $type = 'national';
                $parsed[] = [
                    'key' => $key, 'name' => $name, 'date' => "$d[1]-$d[2]-$d[3]", 'type' => $type,
                    'region' => festival_region($key), 'tentative' => stripos($desc, 'tentative') !== false,
                ];
            }
        }
    }
    // Tamil Nadu festivals for the years in range (Tamil Nadu dates win over the feed, e.g. Pongal)
    $tamil = [];
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        foreach (tamil_festivals($y) as [$name, $date, $type]) {
            $tamil[] = ['key' => festival_key($name), 'name' => $name, 'date' => $date, 'type' => $type, 'region' => 'tamil', 'tentative' => false];
        }
        // Ayudha Puja / Saraswati Puja: the day before Vijayadashami (Dussehra) in the feed
        foreach ($parsed as $e) {
            if ($e['key'] === 'dussehra' && (int)substr($e['date'], 0, 4) === $y) {
                $tamil[] = ['key' => 'ayudha_puja', 'name' => 'Ayudha Puja', 'date' => date('Y-m-d', strtotime($e['date'] . ' -1 day')), 'type' => 'holiday', 'region' => 'tamil', 'tentative' => $e['tentative']];
            }
        }
    }
    $tamilKeys = [];
    foreach ($tamil as $e) $tamilKeys[$e['key'] . '|' . substr($e['date'], 0, 4)] = true;

    $out = [];
    $seen = [];
    foreach ($tamil as $e) {
        if ($e['date'] < $from || $e['date'] > $to || isset($seen[$e['key'] . '|' . $e['date']])) continue;
        $seen[$e['key'] . '|' . $e['date']] = true;
        $out[] = $e;
    }
    foreach ($parsed as $e) {
        if ($e['date'] < $from || $e['date'] > $to) continue;
        if (isset($tamilKeys[$e['key'] . '|' . substr($e['date'], 0, 4)])) continue; // Tamil Nadu date used instead
        $sig = $e['key'] . '|' . $e['date'];
        if (isset($seen[$sig])) continue;
        $seen[$sig] = true;
        $out[] = $e;
    }
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        foreach (festival_rule_days($y) as [$name, $date, $type]) {
            $key = festival_key($name);
            if ($date < $from || $date > $to || isset($seen[$key . '|' . $date])) continue;
            $seen[$key . '|' . $date] = true;
            $out[] = ['key' => $key, 'name' => $name, 'date' => $date, 'type' => $type, 'region' => festival_region($key), 'tentative' => false];
        }
    }
    usort($out, static fn($a, $b) => strcmp($a['date'] . $a['name'], $b['date'] . $b['name']));
    return $out;
}

/** Date of a festival key in a given year from the calendar, or null. */
function festival_date_in_year(string $key, int $year): ?string
{
    foreach (festival_events("$year-01-01", "$year-12-31") as $e) {
        if ($e['key'] === $key) return $e['date'];
    }
    return null;
}

/** Name without emoji or symbols (older rows stored "🪔 Diwali"). */
function festival_plain_name(string $name): string
{
    $clean = preg_replace('/[^\p{L}\p{N}\s\'\-\/&.,()]/u', '', $name);
    return trim(preg_replace('/\s+/u', ' ', (string)$clean));
}

/**
 * Correct the dates of a company's campaigns that were not sent yet, using the calendar:
 * the real date in the same year, or next year's date when this year's has already passed
 * (so an unsent wish is never left in the past). Only festivals the calendar knows are moved,
 * and never onto a date that already has a wish for the same festival. Returns rows changed.
 */
function festival_fix_dates(mysqli $conn, int $companyId): int
{
    festival_ensure_schema($conn);
    $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $st = $conn->prepare("SELECT id, festival_key, festival_name, festival_date FROM festival_campaigns WHERE company_id=? AND sent_at IS NULL");
    if (!$st) return 0;
    $st->bind_param('i', $companyId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $upd = $conn->prepare("UPDATE festival_campaigns SET festival_date=? WHERE id=? AND company_id=? AND sent_at IS NULL");
    $dup = $conn->prepare("SELECT 1 FROM festival_campaigns WHERE company_id=? AND festival_key=? AND festival_date=? AND id<>? LIMIT 1");
    $changed = 0;
    foreach ($rows as $r) {
        $key = (string)$r['festival_key'] !== '' ? (string)$r['festival_key'] : festival_key(festival_plain_name((string)$r['festival_name']));
        $year = (int)substr((string)$r['festival_date'], 0, 4);
        $real = festival_date_in_year($key, $year);
        if ($real !== null && $real < $today) {
            // Already passed and never sent: use the next time this festival comes round
            $thisYear = (int)substr($today, 0, 4);
            $real = festival_date_in_year($key, $thisYear);
            if ($real === null || $real < $today) $real = festival_date_in_year($key, $thisYear + 1);
        }
        if ($real !== null && $real !== $r['festival_date'] && $dup) {
            $id = (int)$r['id'];
            $dup->bind_param('issi', $companyId, $key, $real, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows) continue;
        }
        if ($real !== null && $real !== $r['festival_date'] && $upd) {
            $id = (int)$r['id'];
            $upd->bind_param('sii', $real, $id, $companyId);
            $upd->execute();
            $changed += $upd->affected_rows > 0 ? 1 : 0;
        }
    }
    return $changed;
}

/* --------------------------------------------------------------- sending */

/** Default greeting (customers see this; {{...}} are filled per contact). */
function festival_default_message(string $name): string
{
    return "🎉 *Happy {$name}!*\n\nWishing you and your family a joyful celebration.\n\nWith warm regards,\n*{{business_name}}*";
}

/** Approved templates a festival can use: body only, or a plain text header. */
function festival_templates(mysqli $conn, int $companyId): array
{
    $out = [];
    $st = $conn->prepare("SELECT name, language, header_type, header_text, COALESCE(NULLIF(body_text,''), body) AS body
                          FROM message_templates WHERE company_id=? AND UPPER(wa_status)='APPROVED' ORDER BY name");
    if (!$st) return $out;
    $st->bind_param('i', $companyId);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $ht = strtolower((string)$t['header_type']);
        if (!in_array($ht, ['', 'none', 'text'], true)) continue;           // image/video headers need a file
        if ($ht === 'text' && strpos((string)$t['header_text'], '{{') !== false) continue;
        preg_match_all('/\{\{(\d+)\}\}/', (string)$t['body'], $m);
        $t['vars'] = $m[1] ? max(array_map('intval', $m[1])) : 0;
        $out[$t['name']] = $t;
    }
    return $out;
}

/** Template body parameters: {{1}} customer first name, {{2}} festival, {{3}} business name, {{4}} business phone. */
function festival_template_components(int $varCount, string $firstName, string $festival, string $biz, string $phone): array
{
    if ($varCount <= 0) return [];
    $vals = [$firstName !== '' ? $firstName : 'there', $festival, $biz, $phone];
    $params = [];
    for ($i = 0; $i < $varCount; $i++) {
        $params[] = ['type' => 'text', 'text' => (string)($vals[$i] ?? '-')];
    }
    return [['type' => 'body', 'parameters' => $params]];
}

/**
 * Queue one campaign's messages. Safe to call repeatedly: each contact is queued once.
 * Returns ['queued' => n, 'skipped' => n, 'done' => bool, 'note' => string].
 */
function festival_queue_campaign(mysqli $conn, array $fc, int $batch = 250): array
{
    festival_ensure_schema($conn);
    $companyId = (int)$fc['company_id'];
    $cid = (int)$fc['id'];
    $festival = festival_plain_name((string)$fc['festival_name']);

    $biz = '';
    $phone = '';
    $st = $conn->prepare("SELECT name FROM companies WHERE id=?");
    if ($st) { $st->bind_param('i', $companyId); $st->execute(); $biz = (string)($st->get_result()->fetch_assoc()['name'] ?? ''); }
    $st = $conn->prepare("SELECT display_phone FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
    if ($st) { $st->bind_param('i', $companyId); $st->execute(); $phone = (string)($st->get_result()->fetch_assoc()['display_phone'] ?? ''); }

    $tplName = trim((string)($fc['template_name'] ?? ''));
    $tpl = null;
    if ($tplName !== '') {
        $tpls = festival_templates($conn, $companyId);
        $tpl = $tpls[$tplName] ?? null;
        if (!$tpl) return ['queued' => 0, 'skipped' => 0, 'done' => false, 'note' => 'The chosen template is no longer approved.'];
    }

    // Opted-in contacts not yet handled for this campaign (blacklist / STOP excluded like other campaigns)
    $optOut = '';
    if (is_file(__DIR__ . '/bot_triggers.php')) {
        require_once __DIR__ . '/bot_triggers.php';
        if (function_exists('bt_optout_filter')) $optOut = bt_optout_filter($conn);
    }
    $sql = "SELECT c.id, c.name, c.phone,
                   (SELECT MAX(m.created_at) FROM messages m WHERE m.company_id=c.company_id AND m.contact_id=c.id AND m.direction='incoming') AS last_in
            FROM contacts c
            WHERE c.company_id=? AND c.opted_in=1 AND c.status<>'blocked' AND c.phone<>''" . $optOut . "
              AND NOT EXISTS (SELECT 1 FROM festival_sends fs WHERE fs.campaign_id=? AND fs.contact_id=c.id)
            ORDER BY c.id LIMIT ?";
    $st = $conn->prepare($sql);
    if (!$st) return ['queued' => 0, 'skipped' => 0, 'done' => false, 'note' => 'Could not read contacts.'];
    $st->bind_param('iii', $companyId, $cid, $batch);
    $st->execute();
    $contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $mark = $conn->prepare("INSERT IGNORE INTO festival_sends (campaign_id, company_id, contact_id, status, queue_id) VALUES (?, ?, ?, ?, ?)");
    $insQ = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at, payload_json) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW(), ?)");
    $queued = 0;
    $skipped = 0;
    foreach ($contacts as $c) {
        $contactId = (int)$c['id'];
        $first = trim(explode(' ', trim((string)$c['name']))[0] ?? '');
        $text = str_replace(
            ['{{business_name}}', '{{phone}}', '{{name}}', '{{first_name}}', '{{festival}}'],
            [$biz, $phone, (string)$c['name'], $first, $festival],
            (string)$fc['message']
        );
        $inWindow = $c['last_in'] && (time() - strtotime((string)$c['last_in'])) < 22 * 3600;
        $status = 'skipped';
        $qid = null;
        if ($tpl) {
            $type = 'template';
            $payload = json_encode([
                'template_name' => $tpl['name'], 'language_code' => (string)($tpl['language'] ?: 'en'),
                'components' => festival_template_components((int)$tpl['vars'], $first, $festival, $biz, $phone),
                'festival_campaign_id' => $cid,
            ], JSON_UNESCAPED_UNICODE);
            $body = 'Festival: ' . $festival . ' (template ' . $tpl['name'] . ')';
        } elseif ($inWindow) {
            // Free text is only allowed by WhatsApp inside the 24 h customer-service window
            $type = 'text';
            $payload = json_encode(['festival_campaign_id' => $cid]);
            $body = $text;
        } else {
            $type = '';
        }
        if ($type !== '' && $insQ) {
            $ph = (string)$c['phone'];
            $insQ->bind_param('iissss', $companyId, $contactId, $type, $ph, $body, $payload);
            if ($insQ->execute()) { $qid = (int)$conn->insert_id; $status = 'queued'; }
        }
        if ($mark) {
            $mark->bind_param('iiisi', $cid, $companyId, $contactId, $status, $qid);
            $mark->execute();
        }
        $status === 'queued' ? $queued++ : $skipped++;
    }

    $done = count($contacts) < $batch;
    $note = $tpl ? '' : ($skipped > 0 ? 'Without an approved template, only customers who messaged in the last day can receive it.' : '');
    $upd = $conn->prepare("UPDATE festival_campaigns SET queued_count=queued_count+?, skipped_count=skipped_count+?, last_note=?" . ($done ? ", sent=1, sent_at=NOW()" : "") . " WHERE id=?");
    if ($upd) { $upd->bind_param('iisi', $queued, $skipped, $note, $cid); $upd->execute(); }
    if ($done) festival_rollover($conn, $fc);
    return ['queued' => $queued, 'skipped' => $skipped, 'done' => $done, 'note' => $note];
}

/** After a festival is sent, create next year's campaign with the same settings (if the calendar knows the date). */
function festival_rollover(mysqli $conn, array $fc): void
{
    $key = (string)$fc['festival_key'] !== '' ? (string)$fc['festival_key'] : festival_key(festival_plain_name((string)$fc['festival_name']));
    $next = festival_date_in_year($key, (int)substr((string)$fc['festival_date'], 0, 4) + 1);
    if ($next === null) return;
    $companyId = (int)$fc['company_id'];
    $chk = $conn->prepare("SELECT 1 FROM festival_campaigns WHERE company_id=? AND festival_key=? AND YEAR(festival_date)=YEAR(?) LIMIT 1");
    if (!$chk) return;
    $chk->bind_param('iss', $companyId, $key, $next);
    $chk->execute();
    if ($chk->get_result()->num_rows) return;
    $ins = $conn->prepare("INSERT INTO festival_campaigns (company_id, festival_key, festival_name, festival_date, message, is_active, festival_region, days_before, auto_send, template_name, template_lang, festival_type)
                           VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)");
    if (!$ins) return;
    $name = festival_plain_name((string)$fc['festival_name']);
    $msg = (string)$fc['message'];
    $region = (string)($fc['festival_region'] ?? 'all');
    $days = (int)($fc['days_before'] ?? 0);
    $auto = (int)($fc['auto_send'] ?? 0);
    $tn = $fc['template_name'] ?? null;
    $tl = $fc['template_lang'] ?? null;
    $ty = $fc['festival_type'] ?? null;
    $ins->bind_param('isssssiisss', $companyId, $key, $name, $next, $msg, $region, $days, $auto, $tn, $tl, $ty);
    $ins->execute();
}

/**
 * Send festival campaigns that are due. Called every minute by the queue worker; does real work
 * only between 9 am and 9 pm (India time) and only for campaigns switched to "Send automatically".
 */
function festival_dispatch_due(mysqli $conn, int $maxCampaigns = 3): array
{
    festival_ensure_schema($conn);
    $hour = (int)(new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('G');
    if ($hour < 9 || $hour >= 21) return ['campaigns' => 0, 'queued' => 0];
    $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    // Personal occasions (birthdays, anniversaries ...) use the same schedule
    $personal = 0;
    try {
        require_once __DIR__ . '/occasion_engine.php';
        $personal = occasion_dispatch_due($conn);
    } catch (\Throwable $e) {
        error_log('[occasions] ' . $e->getMessage());
    }
    // School fee reminders (School Fee Manager, "Auto send reminders") use the same schedule
    try {
        require_once __DIR__ . '/school_fee_engine.php';
        $personal += sf_dispatch_due($conn);
    } catch (\Throwable $e) {
        error_log('[school_fees] ' . $e->getMessage());
    }
    $st = $conn->prepare("SELECT * FROM festival_campaigns
                          WHERE is_active=1 AND auto_send=1 AND sent_at IS NULL
                            AND DATE_SUB(festival_date, INTERVAL days_before DAY) <= ? AND festival_date >= ?
                          ORDER BY festival_date, id LIMIT ?");
    if (!$st) return ['campaigns' => 0, 'queued' => 0];
    $st->bind_param('ssi', $today, $today, $maxCampaigns);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $queued = 0;
    foreach ($rows as $fc) {
        try {
            $r = festival_queue_campaign($conn, $fc);
            $queued += $r['queued'];
        } catch (\Throwable $e) {
            error_log('[festival] campaign ' . (int)$fc['id'] . ': ' . $e->getMessage());
        }
    }
    return ['campaigns' => count($rows), 'queued' => $queued + $personal];
}
