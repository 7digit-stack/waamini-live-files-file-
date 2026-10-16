<?php
/**
 * WhatsApp API analytics (success rate, latency) — JSON
 * Protect with CRON_SECRET_KEY (header X-Health-Key or ?key=)
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$key = (string)($_SERVER['HTTP_X_HEALTH_KEY'] ?? ($_GET['key'] ?? ''));
$secret = (string)($_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?: '');
if ($secret === '' || !hash_equals($secret, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// last 24h
$hours = 24;
$out = ['ok' => true, 'ts' => date('c'), 'window_hours' => $hours, 'by_company' => []];

// duration_ms is optional (migration 012)
$hasDuration = false;
try {
    $c = $conn->query("SHOW COLUMNS FROM whatsapp_api_logs LIKE 'duration_ms'");
    $hasDuration = $c && (int)$c->num_rows > 0;
} catch (\Throwable $e) {}

$sql = $hasDuration
    ? "SELECT company_id,
          COUNT(*) total,
          SUM(http_code BETWEEN 200 AND 299) ok_count,
          AVG(duration_ms) avg_ms,
          MAX(duration_ms) max_ms
       FROM whatsapp_api_logs
       WHERE direction='outbound' AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
       GROUP BY company_id
       ORDER BY total DESC
       LIMIT 200"
    : "SELECT company_id,
          COUNT(*) total,
          SUM(http_code BETWEEN 200 AND 299) ok_count
       FROM whatsapp_api_logs
       WHERE direction='outbound' AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
       GROUP BY company_id
       ORDER BY total DESC
       LIMIT 200";

$st = $conn->prepare($sql);
if ($st) {
    $st->bind_param('i', $hours);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $total = (int)($r['total'] ?? 0);
        $ok = (int)($r['ok_count'] ?? 0);
        $row = [
            'company_id' => (int)($r['company_id'] ?? 0),
            'total' => $total,
            'success' => $ok,
            'success_rate' => $total > 0 ? round(($ok / $total) * 100, 2) : 0,
        ];
        if ($hasDuration) {
            $row['avg_ms'] = (int)round((float)($r['avg_ms'] ?? 0));
            $row['max_ms'] = (int)($r['max_ms'] ?? 0);
        }
        $out['by_company'][] = $row;
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

