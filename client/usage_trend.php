<?php
/**
 * Subscription page "Usage and limits" chart data (JSON), for the logged-in account only.
 *   GET ?metric=contacts|campaigns|messages_month|bot_replies|team_members&year=2026
 * Counts match includes/feature_check.php::get_usage():
 *   monthly     campaigns, messages_month  = how many were created / sent in each month (this is what the plan limit counts)
 *   cumulative  contacts, bot_replies, team_members = how many existed at the end of each month (limit counts the total)
 * Months that have not happened yet are null. History is built from the records that still exist.
 */
include '../includes/client_auth_check.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

$companyId = (int)$companyId;
$defs = [
    'contacts'       => ['label' => 'Contacts',          'mode' => 'cumulative', 'table' => 'contacts',    'col' => 'created_at', 'where' => ''],
    'campaigns'      => ['label' => 'Campaigns',         'mode' => 'monthly',    'table' => 'campaigns',   'col' => 'created_at', 'where' => ''],
    'messages_month' => ['label' => 'Messages sent',     'mode' => 'monthly',    'table' => 'messages',    'col' => 'created_at', 'where' => " AND direction = 'outgoing'"],
    'bot_replies'    => ['label' => 'Bot replies',       'mode' => 'cumulative', 'table' => 'bot_replies', 'col' => 'created_at', 'where' => ''],
    'team_members'   => ['label' => 'Team members',      'mode' => 'cumulative', 'table' => 'users',       'col' => 'created_at', 'where' => ''],
];
$metric = (string)($_GET['metric'] ?? 'contacts');
if (!isset($defs[$metric])) $metric = 'contacts';
$d = $defs[$metric];
$thisYear = (int)date('Y');

// table / column names come from the fixed list above, never from the request
$st = $conn->prepare("SELECT MIN(YEAR({$d['col']})) y FROM {$d['table']} WHERE company_id = ?{$d['where']}");
$st->bind_param('i', $companyId);
$st->execute();
$minY = (int)($st->get_result()->fetch_assoc()['y'] ?? $thisYear);
$st->close();
if ($minY < 2000 || $minY > $thisYear) $minY = $thisYear;
$years = [];
for ($y = max($minY, $thisYear - 5); $y <= $thisYear; $y++) $years[] = $y;

$year = (int)($_GET['year'] ?? $thisYear);
if (!in_array($year, $years, true)) $year = $thisYear;
$from = sprintf('%04d-01-01 00:00:00', $year);
$to   = sprintf('%04d-01-01 00:00:00', $year + 1);

$per = array_fill(1, 12, 0);
$st = $conn->prepare("SELECT MONTH({$d['col']}) m, COUNT(*) c FROM {$d['table']} WHERE company_id = ?{$d['where']} AND {$d['col']} >= ? AND {$d['col']} < ? GROUP BY MONTH({$d['col']})");
$st->bind_param('iss', $companyId, $from, $to);
$st->execute();
$r = $st->get_result();
while ($x = $r->fetch_assoc()) { $m = (int)$x['m']; if ($m >= 1 && $m <= 12) $per[$m] = (int)$x['c']; }
$st->close();

$base = 0;
if ($d['mode'] === 'cumulative') {
    $st = $conn->prepare("SELECT COUNT(*) c FROM {$d['table']} WHERE company_id = ?{$d['where']} AND {$d['col']} < ?");
    $st->bind_param('is', $companyId, $from);
    $st->execute();
    $base = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
}

$last = ($year === $thisYear) ? (int)date('n') : 12;
$used = []; $run = $base;
for ($m = 1; $m <= 12; $m++) {
    if ($d['mode'] === 'cumulative') $run += $per[$m];
    $used[] = $m <= $last ? ($d['mode'] === 'cumulative' ? $run : $per[$m]) : null;
}
$limit = 0;
try { $limit = (int)get_limit($metric); } catch (\Throwable $e) {}

echo json_encode([
    'metric' => $metric, 'label' => $d['label'], 'mode' => $d['mode'], 'year' => $year, 'years' => $years,
    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'used' => $used, 'limit' => $limit,
]);
