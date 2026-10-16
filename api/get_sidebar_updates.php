<?php
/**
 * WAAMINI - AJAX Sidebar Updater
 */
ob_start();
require_once '../includes/client_auth_check.php';
require_once '../includes/wa_chat_helpers.php';
ob_clean();

// Release session lock — this endpoint only reads session data, never writes.
// Releasing early lets concurrent AJAX calls run in parallel instead of queuing.
session_write_close();

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

header('Content-Type: application/json');

// Get sidebar conversations fast (use chat_conversations summary)
$contactsRes = null;
$stmt = $conn->prepare("
    SELECT c.id, c.phone, c.name,
           cv.last_message_at,
           COALESCE(cv.unread_count, 0) as unread_count,
           COALESCE(cv.last_message, '') as last_snippet
    FROM chat_conversations cv FORCE INDEX (idx_chat_conv_company)
    STRAIGHT_JOIN contacts c ON c.id = cv.contact_id AND c.company_id = cv.company_id
    WHERE cv.company_id = ?
    ORDER BY cv.last_message_at DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $contactsRes = $stmt->get_result();
}

function sidebar_relative_time($datetime) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '';
    $diff = time() - strtotime($datetime);
    if ($diff <= 0)      return 'just now';
    if ($diff < 60)      return $diff . 's ago';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    if ($diff < 2592000) { $w = floor($diff/604800); $d = floor(($diff%604800)/86400); return $w.'w'.($d?(' '.$d.'d'):'').' ago'; }
    return floor($diff/2592000) . ' months ago';
}

$updates = [];
if ($contactsRes) {
while ($row = $contactsRes->fetch_assoc()) {
    $row['relative_time']  = sidebar_relative_time($row['last_message_at']);
    $row['chat_time']      = wa_chat_time($row['last_message_at']);
    $row['time_formatted'] = $row['last_message_at'] ? date('H:i', strtotime($row['last_message_at'])) : '';
    $row['last_snippet']   = mb_strimwidth($row['last_snippet'] ?? '', 0, 60, '...');
    $updates[] = $row;
}
}

echo json_encode($updates);
