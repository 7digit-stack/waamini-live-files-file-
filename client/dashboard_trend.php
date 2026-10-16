<?php
/**
 * Dashboard "Messages trend" data: messages sent vs received per month for one year, for the logged-in account only.
 *   GET ?year=2026  ->  {year, years[], labels[12], sent[12], received[12], totals}
 * Months that have not happened yet (current year) are null so the line stops at today.
 */
include '../includes/client_auth_check.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

$companyId = (int)$companyId;
$thisYear = (int)date('Y');

// years that have messages (for the dropdown); always include the current year
$years = [$thisYear];
$st = $conn->prepare("SELECT MIN(YEAR(created_at)) y FROM messages WHERE company_id = ?");
$st->bind_param('i', $companyId);
$st->execute();
$minY = (int)($st->get_result()->fetch_assoc()['y'] ?? $thisYear);
$st->close();
for ($y = $thisYear - 1; $y >= max($minY, $thisYear - 5); $y--) $years[] = $y;
sort($years);

$year = (int)($_GET['year'] ?? $thisYear);
if (!in_array($year, $years, true)) $year = $thisYear;

$from = sprintf('%04d-01-01 00:00:00', $year);
$to   = sprintf('%04d-01-01 00:00:00', $year + 1);
$sent = array_fill(1, 12, 0);
$recv = array_fill(1, 12, 0);
$st = $conn->prepare("SELECT MONTH(created_at) m, direction, COUNT(*) c FROM messages
                      WHERE company_id = ? AND created_at >= ? AND created_at < ? AND direction IN ('incoming','outgoing')
                      GROUP BY MONTH(created_at), direction");
$st->bind_param('iss', $companyId, $from, $to);
$st->execute();
$r = $st->get_result();
while ($x = $r->fetch_assoc()) {
    $m = (int)$x['m'];
    if ($m < 1 || $m > 12) continue;
    if ($x['direction'] === 'outgoing') $sent[$m] = (int)$x['c']; else $recv[$m] = (int)$x['c'];
}
$st->close();

$lastMonth = ($year === $thisYear) ? (int)date('n') : 12;
$outSent = []; $outRecv = [];
for ($m = 1; $m <= 12; $m++) {
    $outSent[] = $m <= $lastMonth ? $sent[$m] : null;
    $outRecv[] = $m <= $lastMonth ? $recv[$m] : null;
}
echo json_encode([
    'year' => $year, 'years' => $years,
    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'sent' => $outSent, 'received' => $outRecv,
    'totals' => ['sent' => array_sum($sent), 'received' => array_sum($recv)],
]);
