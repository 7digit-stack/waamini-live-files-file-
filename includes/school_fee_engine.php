<?php
/**
 * WAAMINI School Fee messages: reminders, overdue notices and receipts to parents on WhatsApp.
 *
 * WhatsApp only allows free text to a parent who messaged the school in the last 24 hours; everyone
 * else needs an approved template. sf_queue() picks the right one and reports what happened, so the
 * page never claims a reminder went out when it could not.
 *
 * Automatic reminders (sf_dispatch_due) run from festival_dispatch_due(), i.e. every minute from the
 * queue worker, 9 am to 9 pm IST: first reminder N days before the due date, again on the due date,
 * then once a week while overdue.
 */

require_once __DIR__ . '/festival_engine.php'; // festival_templates()

function sf_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $have = [];
    try {
        $r = $conn->query("SHOW COLUMNS FROM school_fee_settings");
        while ($r && ($c = $r->fetch_assoc())) $have[$c['Field']] = true;
    } catch (\Throwable $e) { return; }
    $add = [
        'reminder_template' => "ALTER TABLE school_fee_settings ADD COLUMN reminder_template VARCHAR(255) DEFAULT NULL",
        'receipt_template'  => "ALTER TABLE school_fee_settings ADD COLUMN receipt_template VARCHAR(255) DEFAULT NULL",
    ];
    foreach ($add as $col => $sql) {
        if (!isset($have[$col])) { try { $conn->query($sql); } catch (\Throwable $e) { error_log('[school_fees] ' . $e->getMessage()); } }
    }
}

/** Parent phone as digits with country code (10-digit Indian numbers get 91). */
function sf_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10) $d = '91' . $d;
    if (strlen($d) === 11 && $d[0] === '0') $d = '91' . substr($d, 1);
    return $d;
}

/** Contact for a parent: matched on the last 10 digits so "+91 98..." and "98..." are the same person. */
function sf_contact_for(mysqli $conn, int $companyId, string $phone, string $name): int
{
    $phone = sf_phone($phone);
    if (strlen($phone) < 10) return 0;
    $last10 = substr($phone, -10);
    $st = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''),10)=? ORDER BY id LIMIT 1");
    if ($st) {
        $st->bind_param('is', $companyId, $last10);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) return (int)$row['id'];
    }
    $ins = $conn->prepare("INSERT INTO contacts (company_id, name, phone) VALUES (?, ?, ?)");
    if (!$ins) return 0;
    $ins->bind_param('iss', $companyId, $name, $phone);
    $ins->execute();
    return (int)$ins->insert_id;
}

/** The free-text message for a fee (custom text from settings, or the standard one). */
function sf_text(array $settings, array $fee, string $kind, array $extra = []): string
{
    $school = trim((string)($settings['school_name'] ?? '')) !== '' ? (string)$settings['school_name'] : 'School';
    $due = date('d M Y', strtotime((string)$fee['due_date']));
    $daysLeft = (int)floor((strtotime((string)$fee['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    $dueText = $daysLeft > 0 ? 'Due in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' (' . $due . ')' : ($daysLeft === 0 ? 'Due today (' . $due . ')' : 'Overdue since ' . $due);
    $amount = '₹' . number_format((float)$fee['amount'], 2);

    if ($kind === 'receipt') {
        $custom = trim((string)($settings['receipt_message'] ?? ''));
        $paid = '₹' . number_format((float)($extra['paid'] ?? $fee['amount']), 2);
        if ($custom !== '') {
            return str_replace(['{{student_name}}', '{{class}}', '{{fee_type}}', '{{amount}}', '{{receipt}}', '{{school_name}}'],
                [$fee['student_name'], $fee['class_name'], $fee['fee_type'], $paid, (string)($extra['receipt'] ?? ''), $school], $custom);
        }
        return "🧾 *Fee receipt*\n*{$school}*\nReceipt: *" . ($extra['receipt'] ?? '') . "*\nDate: " . date('d M Y') . "\n\nStudent: *{$fee['student_name']}*\nClass: {$fee['class_name']}\nFee: {$fee['fee_type']}\nAmount paid: *{$paid}*\n\nThank you!";
    }

    $custom = trim((string)($settings[$daysLeft >= 0 ? 'reminder_message' : 'overdue_message'] ?? ''));
    if ($custom !== '' && $custom !== '0') {
        return str_replace(['{{student_name}}', '{{class}}', '{{fee_type}}', '{{amount}}', '{{due_date}}', '{{school_name}}'],
            [$fee['student_name'], $fee['class_name'], $fee['fee_type'], $amount, $dueText, $school], $custom);
    }
    return "🏫 *{$school}*\n\nDear parent of *{$fee['student_name']}* ({$fee['class_name']}),\n\nFee reminder\nType: *{$fee['fee_type']}*\nAmount: *{$amount}*\nStatus: {$dueText}\n\nPlease pay at the earliest. For questions, contact the school office.";
}

/**
 * Queue one message to the parent.
 * Returns ['queued' => bool, 'mode' => 'text'|'template'|'', 'reason' => string].
 */
function sf_queue(mysqli $conn, int $companyId, array $fee, array $settings, string $kind = 'reminder', array $extra = []): array
{
    sf_ensure_schema($conn);
    $parentName = trim((string)($fee['parent_name'] ?? '')) !== '' ? (string)$fee['parent_name'] : 'Parent of ' . $fee['student_name'];
    $cid = sf_contact_for($conn, $companyId, (string)$fee['parent_phone'], $parentName);
    if ($cid <= 0) return ['queued' => false, 'mode' => '', 'reason' => 'The parent phone number is not valid.'];

    // Opted out / blocked parents are not messaged
    $st = $conn->prepare("SELECT c.phone, c.opted_in, c.status,
                                 (SELECT MAX(m.created_at) FROM messages m WHERE m.company_id=c.company_id AND m.contact_id=c.id AND m.direction='incoming') AS last_in
                          FROM contacts c WHERE c.id=? AND c.company_id=?");
    $st->bind_param('ii', $cid, $companyId);
    $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) return ['queued' => false, 'mode' => '', 'reason' => 'Contact not found.'];
    if ((string)$c['status'] === 'blocked' || (int)($c['opted_in'] ?? 1) === 0) {
        return ['queued' => false, 'mode' => '', 'reason' => 'This parent has opted out of WhatsApp messages.'];
    }

    $school = trim((string)($settings['school_name'] ?? '')) !== '' ? (string)$settings['school_name'] : 'School';
    $inWindow = $c['last_in'] && (time() - strtotime((string)$c['last_in'])) < 22 * 3600;
    $tplName = trim((string)($settings[$kind === 'receipt' ? 'receipt_template' : 'reminder_template'] ?? ''));
    $tpl = $tplName !== '' ? (festival_templates($conn, $companyId)[$tplName] ?? null) : null;

    $phone = (string)$c['phone'];
    if ($inWindow || !$tpl) {
        if (!$inWindow) {
            return ['queued' => false, 'mode' => '', 'reason' => 'WhatsApp only allows free text to parents who messaged you in the last 24 hours. Choose an approved template in School Fee settings to reach every parent.'];
        }
        $type = 'text';
        $body = sf_text($settings, $fee, $kind, $extra);
        $payload = json_encode(['school_fee_id' => (int)$fee['id'], 'kind' => $kind]);
    } else {
        $type = 'template';
        $due = date('d M Y', strtotime((string)$fee['due_date']));
        $vals = $kind === 'receipt'
            ? [$fee['student_name'], '₹' . number_format((float)($extra['paid'] ?? $fee['amount']), 2), (string)($extra['receipt'] ?? ''), $fee['fee_type'], $school]
            : [$fee['student_name'], '₹' . number_format((float)$fee['amount'], 2), $due, $fee['fee_type'], $school];
        $params = [];
        for ($i = 0; $i < (int)$tpl['vars']; $i++) $params[] = ['type' => 'text', 'text' => (string)($vals[$i] ?? '-')];
        $payload = json_encode(['template_name' => $tpl['name'], 'language_code' => (string)($tpl['language'] ?: 'en'),
            'components' => $params ? [['type' => 'body', 'parameters' => $params]] : [], 'school_fee_id' => (int)$fee['id'], 'kind' => $kind], JSON_UNESCAPED_UNICODE);
        $body = 'School fee ' . $kind . ' for ' . $fee['student_name'] . ' (template ' . $tpl['name'] . ')';
    }
    $ins = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, status, scheduled_at, created_at, payload_json) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW(), ?)");
    if (!$ins) return ['queued' => false, 'mode' => '', 'reason' => 'Could not queue the message.'];
    $ins->bind_param('iissss', $companyId, $cid, $type, $phone, $body, $payload);
    if (!$ins->execute()) return ['queued' => false, 'mode' => '', 'reason' => 'Could not queue the message.'];
    return ['queued' => true, 'mode' => $type, 'reason' => ''];
}

/** Automatic reminders for every school with "Auto send reminders" on. Returns messages queued. */
function sf_dispatch_due(mysqli $conn, int $batch = 200): int
{
    $ist = new DateTimeZone('Asia/Kolkata');
    $hour = (int)(new DateTime('now', $ist))->format('G');
    if ($hour < 9 || $hour >= 21) return 0;
    try { $r = $conn->query("SHOW TABLES LIKE 'school_fee_settings'"); if (!$r || !$r->num_rows) return 0; } catch (\Throwable $e) { return 0; }
    sf_ensure_schema($conn);
    $today = (new DateTime('now', $ist))->format('Y-m-d');
    $schools = $conn->query("SELECT * FROM school_fee_settings WHERE auto_remind=1");
    if (!$schools) return 0;
    $queued = 0;
    while ($s = $schools->fetch_assoc()) {
        $companyId = (int)$s['company_id'];
        $days = max(1, (int)($s['reminder_days_before'] ?? 3));
        // First reminder inside the window, again on the due date, then weekly while overdue
        $st = $conn->prepare("SELECT sf.*, ss.student_name, ss.class_name, ss.parent_phone, ss.parent_name
                              FROM school_fees sf JOIN school_students ss ON ss.id=sf.student_id AND ss.company_id=sf.company_id
                              WHERE sf.company_id=? AND sf.status IN ('pending','overdue') AND ss.is_active=1
                                AND sf.due_date <= DATE_ADD(?, INTERVAL ? DAY)
                                AND (sf.last_reminder_at IS NULL
                                     OR (sf.due_date = ? AND DATE(sf.last_reminder_at) < ?)
                                     OR (sf.due_date < ? AND sf.last_reminder_at <= DATE_SUB(?, INTERVAL 7 DAY)))
                              ORDER BY sf.due_date LIMIT ?");
        if (!$st) continue;
        $st->bind_param('isissssi', $companyId, $today, $days, $today, $today, $today, $today, $batch);
        $st->execute();
        $upd = $conn->prepare("UPDATE school_fees SET reminder_sent=reminder_sent+1, last_reminder_at=NOW() WHERE id=? AND company_id=?");
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $fee) {
            $r = sf_queue($conn, $companyId, $fee, $s, 'reminder');
            if ($r['queued'] && $upd) {
                $fid = (int)$fee['id'];
                $upd->bind_param('ii', $fid, $companyId);
                $upd->execute();
                $queued++;
            }
        }
    }
    return $queued;
}
