<?php
/**
 * Landing API — Active Alert Popup
 * GET /api/landing/alert-popup.php
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=30');

$stmt = $conn->prepare("
    SELECT id, title, message, image_url,
           btn_text, btn_url,
           bg_color, text_color, accent_color,
           show_delay, show_frequency, is_dismissible
    FROM lp_alert_popups
    WHERE is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$data = null;
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $data = $row;
        $data['id'] = (int)$row['id'];
        $data['show_delay'] = (int)$row['show_delay'];
        $data['is_dismissible'] = (bool)$row['is_dismissible'];
    }
    $stmt->close();
}
echo json_encode(['alert_popup' => $data], JSON_UNESCAPED_UNICODE);
