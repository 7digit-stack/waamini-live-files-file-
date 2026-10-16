<?php
/**
 * Analytics "Delivery performance" chart data (JSON), for the logged-in account only.
 *   GET ?year=2026 -> per month: sent, delivered, read, failed  (same definitions as the page's delivery funnel)
 *     sent      = messages with status sent + delivered + read (everything that left WAAMINI successfully)
 *     delivered = delivered + read          read = read          failed = failed
 * Months that have not happened yet are null so the lines stop at today.
 */
include '../includes/client_auth_check.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

$companyId = (int)$companyId;
$thisYear = (int)date('Y');
$st = $conn->prepare("SELECT MIN(YEAR(created_at)) y FROM messages WHERE company_id = ? AND status IN ('sent','delivered','read','failed')");
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
$m = ['sent' => array_fill(1, 12, 0), 'delivered' => array_fill(1, 12, 0), 'read' => array_fill(1, 12, 0), 'failed' => array_fill(1, 12, 0)];
$st = $conn->prepare("SELECT MONTH(created_at) mo, status, COUNT(*) c FROM messages
                      WHERE company_id = ? AND created_at >= ? AND created_at < ? AND status IN ('sent','delivered','read','failed')
                      GROUP BY MONTH(created_at), status");
$st->bind_param('iss', $companyId, $from, $to);
$st->execute();
$raw = array_fill(1, 12, ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0]);
$r = $st->get_result();
while ($x = $r->fetch_assoc()) { $mo = (int)$x['mo']; if ($mo >= 1 && $mo <= 12) $raw[$mo][$x['status']] = (int)$x['c']; }
$st->close();

$last = ($year === $thisYear) ? (int)date('n') : 12;
$out = ['sent' => [], 'delivered' => [], 'read' => [], 'failed' => []];
for ($i = 1; $i <= 12; $i++) {
    $v = $raw[$i];
    $vals = ['sent' => $v['sent'] + $v['delivered'] + $v['read'], 'delivered' => $v['delivered'] + $v['read'], 'read' => $v['read'], 'failed' => $v['failed']];
    foreach ($vals as $k => $n) $out[$k][] = $i <= $last ? $n : null;
}
echo json_encode(['year' => $year, 'years' => $years, 'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']] + $out);
