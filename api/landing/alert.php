<?php
/**
 * Landing API — Active Alert Bar
 * GET /api/landing/alert.php
 * Returns the currently active alert (one with highest sort_order).
 * If countdown_target is set, includes remaining seconds.
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT id, type, message, link_text, link_url,
           btn_text, btn_url,
           countdown_label, countdown_target,
           bg_color, text_color, is_dismissible
    FROM lp_alerts
    WHERE is_active = 1
      AND (start_date IS NULL OR start_date <= ?)
      AND (end_date IS NULL OR end_date >= ?)
    ORDER BY sort_order ASC, id DESC
    LIMIT 1
");
$alert = null;
if ($stmt) {
    $stmt->bind_param('ss', $now, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $alert = $row;
        $alert['id'] = (int)$row['id'];
        $alert['is_dismissible'] = (bool)$row['is_dismissible'];
        if ($row['countdown_target']) {
            $alert['countdown_remaining'] = max(0, strtotime($row['countdown_target']) - time());
        } else {
            $alert['countdown_remaining'] = null;
        }
        unset($alert['countdown_target']);
    }
    $stmt->close();
}

echo json_encode(['alert' => $alert], JSON_UNESCAPED_UNICODE);
