<?php
/**
 * Contacts export. ?format=xlsx (default, real Excel file) or ?format=csv (opens cleanly in Excel too).
 * Phone numbers stay text (+919003876516, never 9.19E+11), Tamil/emoji names keep their characters,
 * dates are readable. Optional ?group=ID exports one group.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
if (!has_feature('feat_contact_import')) {
    die("Unauthorized.");
}

$companyId = (int)$_SESSION['company_id'];
$format = strtolower((string)($_GET['format'] ?? 'xlsx')) === 'csv' ? 'csv' : 'xlsx';
$groupId = max(0, (int)($_GET['group'] ?? 0));

$cols = [];
$cr = $conn->query("SHOW COLUMNS FROM contacts");
while ($cr && ($x = $cr->fetch_assoc())) { $cols[strtolower($x['Field'])] = true; }
$pick = static fn(string $c, string $as = '') => isset($cols[$c]) ? "c.$c" . ($as ? " AS $as" : '') : "NULL" . ($as ? " AS $as" : " AS $c");

$sql = "SELECT c.id, c.name, c.phone, " . $pick('email') . ", " . $pick('client_company') . ", " . $pick('city') . ", g.name AS group_name, "
     . $pick('chat_labels') . ", " . $pick('tags') . ", " . $pick('status') . ", " . $pick('last_message_at') . ", c.created_at
        FROM contacts c LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
        WHERE c.company_id = ?" . ($groupId > 0 ? " AND c.group_id = ?" : '') . " ORDER BY c.id DESC";
$st = $conn->prepare($sql);
if ($groupId > 0) { $st->bind_param('ii', $companyId, $groupId); } else { $st->bind_param('i', $companyId); }
$st->execute();
$res = $st->get_result();

require_once __DIR__ . '/../includes/contact_fields.php';
$cfFields = cf_enabled() ? cf_list($conn, $companyId) : [];
$cfRows = [];

$fmtDate = static function ($d): string {
    $t = $d ? strtotime((string)$d) : false;
    return $t ? date('d-m-Y h:i A', $t) : '';
};
$headers = array_merge(['Name', 'Phone', 'Email', 'Company', 'City', 'Group', 'Labels', 'Tags', 'Status', 'Last message', 'Created'], array_column($cfFields, 'name'));
$rows = [];
while ($r = $res->fetch_assoc()) {
    $name = trim((string)$r['name']);
    if (preg_match('/^\+?\d{8,15}$/', $name)) { $name = ''; }   // WhatsApp contacts with no name: leave blank, phone is next to it
    $cfRows[] = (int)$r['id'];
    $rows[] = [
        $name,
        (string)$r['phone'],
        (string)$r['email'],
        (string)$r['client_company'],
        (string)$r['city'],
        (string)$r['group_name'],
        trim((string)$r['chat_labels'], " ,"),
        trim((string)$r['tags'], " ,"),
        ucfirst((string)$r['status']),
        $fmtDate($r['last_message_at']),
        $fmtDate($r['created_at']),
    ];
}

if ($cfFields) {   // custom field values, in the same column order as the header
    $vals = cf_values($conn, $companyId, $cfRows);
    foreach ($rows as $i => $row) {
        foreach ($cfFields as $f) { $rows[$i][] = cf_display((string)$f['field_type'], (string)($vals[$cfRows[$i]][(int)$f['id']] ?? '')); }
    }
}

$base = 'waamini_contacts_' . date('Y-m-d');
if ($format === 'csv') {
    csv_excel_download($base . '.csv', $headers, $rows, [1]);
} else {
    xlsx_download($base . '.xlsx', 'Contacts', $headers, $rows, array_merge([24, 18, 28, 24, 14, 18, 26, 22, 10, 20, 20], array_fill(0, count($cfFields), 20)));
}
exit();
