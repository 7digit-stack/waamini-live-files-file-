<?php
/**
 * Landing API — Active Offer Counter
 * GET /api/landing/offer-counter.php
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

$stmt = $conn->prepare("
    SELECT id, title, subtitle, countdown_target, countdown_label,
           bg_color, text_color, accent_color,
           btn_text, btn_url, position, is_sticky, is_dismissible
    FROM lp_offer_counters
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
        $data['is_sticky'] = (bool)$row['is_sticky'];
        $data['is_dismissible'] = (bool)$row['is_dismissible'];
        if ($row['countdown_target']) {
            $data['countdown_remaining'] = max(0, strtotime($row['countdown_target']) - time());
        } else {
            $data['countdown_remaining'] = null;
        }
        unset($data['countdown_target']);
    }
    $stmt->close();
}
echo json_encode(['offer_counter' => $data], JSON_UNESCAPED_UNICODE);
