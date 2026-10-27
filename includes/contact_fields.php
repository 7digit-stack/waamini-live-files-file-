<?php
/**
 * Contact custom fields: extra details a client defines for their contacts (e.g. "Birthday", "Policy number", "City").
 *   contact_custom_fields  — the fields a company defined (name, type, order)
 *   contact_field_values   — one value per contact per field
 * Plan switch: has_feature('contact_fields') (Admin > Plans > Contact Custom Fields), limit: get_limit('custom_fields') (0 = unlimited).
 * Used by: client/contact_fields.php, contacts.php, contact_add.php, contact_edit.php, contact_import.php, api/contact_export.php.
 */

if (!function_exists('cf_types')) {

/** type key => [label, HTML input type, example] */
function cf_types(): array
{
    return [
        'text'     => ['Text', 'text', 'Gold member'],
        'number'   => ['Number', 'number', '2500'],
        'email'    => ['Email', 'email', 'office@example.com'],
        'url'      => ['URL', 'url', 'https://example.com'],
        'date'     => ['Date', 'date', '2026-08-15'],
        'time'     => ['Time', 'time', '18:30'],
        'datetime' => ['Date and time', 'datetime-local', '2026-08-15 18:30'],
    ];
}

/** Names that already mean something in the contact import, so a custom field may not use them. */
function cf_reserved_names(): array
{
    return ['name', 'phone', 'phone_number', 'mobile', 'mobile_number', 'email', 'email_address', 'group', 'group_name', 'list',
            'custom', 'custom_field', 'notes', 'note', 'tag', 'tags', 'status', 'company', 'city', 'created', 'id'];
}

function cf_enabled(): bool
{
    if (!function_exists('has_feature')) return true;
    return has_feature('contacts') && has_feature('contact_fields');
}

function cf_ensure_tables(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS contact_custom_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(60) NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            field_type VARCHAR(16) NOT NULL DEFAULT 'text',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_key (company_id, field_key),
            KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS contact_field_values (
            contact_id INT NOT NULL,
            field_id INT NOT NULL,
            company_id INT NOT NULL,
            `value` VARCHAR(1000) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (contact_id, field_id),
            KEY idx_field (company_id, field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        error_log('[contact_fields] ensure tables: ' . $e->getMessage());
    }
    $done = true;
}

/** A company's fields in display order: [ ['id','name','field_key','field_type','sort_order'], ... ] */
function cf_list(mysqli $conn, int $companyId): array
{
    cf_ensure_tables($conn);
    $out = [];
    try {
        $st = $conn->prepare('SELECT id, name, field_key, field_type, sort_order FROM contact_custom_fields WHERE company_id=? ORDER BY sort_order ASC, id ASC');
        if ($st) {
            $st->bind_param('i', $companyId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) { $x['id'] = (int)$x['id']; $out[] = $x; }
            $st->close();
        }
    } catch (\Throwable $e) { error_log('[contact_fields] list: ' . $e->getMessage()); }
    return $out;
}

/** Values for several contacts: [contact_id => [field_id => value]] */
function cf_values(mysqli $conn, int $companyId, array $contactIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
    if (!$ids) return [];
    cf_ensure_tables($conn);
    $out = [];
    try {
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);   // integers only
            $st = $conn->prepare("SELECT contact_id, field_id, `value` FROM contact_field_values WHERE company_id=? AND contact_id IN ($in)");
            if (!$st) continue;
            $st->bind_param('i', $companyId);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) { $out[(int)$x['contact_id']][(int)$x['field_id']] = (string)$x['value']; }
            $st->close();
        }
    } catch (\Throwable $e) { error_log('[contact_fields] values: ' . $e->getMessage()); }
    return $out;
}

/** Field key from a name: "Policy No." -> "policy_no" */
function cf_key(string $name): string
{
    $k = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
    return $k !== '' ? substr($k, 0, 60) : 'field';
}

/**
 * Check and tidy one value for its field type. Returns [ok(bool), value(string), error(string)].
 * Empty input is valid and means "no value". Accepts common Excel formats (15/08/2026, 6:30 PM, 1,250).
 */
function cf_normalize(string $type, $raw): array
{
    $v = trim((string)$raw);
    if ($v === '') return [true, '', ''];
    $v = ltrim($v, '=+@|%');   // spreadsheet formula injection
    if ($v === '') return [true, '', ''];
    switch ($type) {
        case 'number':
            $n = str_replace([',', ' ', '₹'], '', $v);
            if (!is_numeric($n)) return [false, '', 'must be a number'];
            return [true, (string)(0 + $n), ''];
        case 'email':
            return filter_var($v, FILTER_VALIDATE_EMAIL) ? [true, strtolower($v), ''] : [false, '', 'must be an email address'];
        case 'url':
            if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
            return filter_var($v, FILTER_VALIDATE_URL) ? [true, $v, ''] : [false, '', 'must be a web address'];
        case 'date':
            $t = cf_parse_time($v, false);
            return $t !== null ? [true, date('Y-m-d', $t), ''] : [false, '', 'must be a date (e.g. 2026-08-15 or 15/08/2026)'];
        case 'time':
            $t = strtotime('2000-01-01 ' . $v);
            return $t !== false ? [true, date('H:i', $t), ''] : [false, '', 'must be a time (e.g. 18:30 or 6:30 PM)'];
        case 'datetime':
            $t = cf_parse_time($v, true);
            return $t !== null ? [true, date('Y-m-d\TH:i', $t), ''] : [false, '', 'must be a date and time (e.g. 2026-08-15 18:30)'];
        default:
            return [true, mb_substr($v, 0, 1000), ''];
    }
}

/** Parse a date (or date-time); day-first for slashes and dashes (Indian format), plus Excel serial numbers. */
function cf_parse_time(string $v, bool $withTime): ?int
{
    $v = trim(str_replace('T', ' ', $v));
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {   // Excel serial date (e.g. 46249)
        return (int)round(((float)$v - 25569) * 86400);
    }
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})(.*)$#', $v, $m)) {   // 15/08/2026 [time]
        $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        if (!checkdate((int)$m[2], (int)$m[1], (int)$y)) return null;
        $t = strtotime(sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) . ' ' . trim($m[4]));
        return $t === false ? null : $t;
    }
    $t = strtotime($v);
    return $t === false ? null : $t;
}

/** Text to show for a stored value. */
function cf_display(string $type, string $value): string
{
    if ($value === '') return '';
    switch ($type) {
        case 'date':     $t = strtotime($value); return $t ? date('d M Y', $t) : $value;
        case 'time':     $t = strtotime('2000-01-01 ' . $value); return $t ? date('g:i A', $t) : $value;
        case 'datetime': $t = strtotime(str_replace('T', ' ', $value)); return $t ? date('d M Y, g:i A', $t) : $value;
        default:         return $value;
    }
}

/** Value for an <input> of the field's type. */
function cf_input_value(string $type, string $value): string
{
    return $type === 'datetime' ? str_replace(' ', 'T', substr($value, 0, 16)) : $value;
}

/**
 * Save values for one contact. $values = [field_id => raw value]; only fields in $fields are touched.
 * Empty value removes it unless $keepExistingOnEmpty (used by imports so a blank cell does not wipe data).
 * Returns list of "Field name: problem" for values that were rejected.
 */
function cf_save(mysqli $conn, int $companyId, int $contactId, array $fields, array $values, bool $keepExistingOnEmpty = false): array
{
    if ($contactId <= 0 || !$fields) return [];
    cf_ensure_tables($conn);
    $errors = [];
    $up = $conn->prepare('INSERT INTO contact_field_values (contact_id, field_id, company_id, `value`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $del = $conn->prepare('DELETE FROM contact_field_values WHERE contact_id=? AND field_id=? AND company_id=?');
    foreach ($fields as $f) {
        $fid = (int)$f['id'];
        if (!array_key_exists($fid, $values)) continue;
        [$ok, $val, $err] = cf_normalize((string)$f['field_type'], $values[$fid]);
        if (!$ok) { $errors[] = $f['name'] . ': ' . $err; continue; }
        if ($val === '') {
            if (!$keepExistingOnEmpty && $del) { $del->bind_param('iii', $contactId, $fid, $companyId); $del->execute(); }
            continue;
        }
        if ($up) { $up->bind_param('iiis', $contactId, $fid, $companyId, $val); $up->execute(); }
    }
    if ($up) $up->close();
    if ($del) $del->close();
    return $errors;
}

/** Read posted form values: inputs are named cf[<field_id>]. */
function cf_from_post(array $fields): array
{
    $posted = (array)($_POST['cf'] ?? []);
    $out = [];
    foreach ($fields as $f) {
        $fid = (int)$f['id'];
        if (array_key_exists((string)$fid, $posted) || array_key_exists($fid, $posted)) { $out[$fid] = (string)($posted[$fid] ?? ''); }
    }
    return $out;
}

/** CSV header row -> [column index => field] for columns named like a custom field (by name or key, any case). */
function cf_header_map(array $fields, array $header): array
{
    $byName = [];
    foreach ($fields as $f) {
        $byName[mb_strtolower(trim((string)$f['name']))] = $f;
        $byName[strtolower((string)$f['field_key'])] = $f;
    }
    $map = [];
    foreach ($header as $i => $h) {
        $h = mb_strtolower(trim(preg_replace("/^\x{FEFF}/u", '', (string)$h)));
        if ($h !== '' && isset($byName[$h])) $map[(int)$i] = $byName[$h];
    }
    return $map;
}

/** Form inputs for the add / edit contact pages. */
function cf_render_inputs(array $fields, array $values, callable $esc): string
{
    $types = cf_types();
    $html = '';
    foreach ($fields as $f) {
        $fid = (int)$f['id'];
        $t = $types[$f['field_type']] ?? $types['text'];
        $val = cf_input_value((string)$f['field_type'], (string)($values[$fid] ?? ''));
        $extra = $f['field_type'] === 'number' ? ' step="any"' : '';
        $html .= '<div class="ca-field cf-field"><label class="ca-lab" for="cf_' . $fid . '">' . $esc($f['name']) . ' <span class="ca-opt">' . $esc($t[0]) . '</span></label>'
               . '<input id="cf_' . $fid . '" type="' . $t[1] . '" name="cf[' . $fid . ']" value="' . $esc($val) . '" placeholder="' . $esc($t[2]) . '"' . $extra . ' maxlength="1000"></div>';
    }
    return $html;
}

}
