<?php
/**
 * Admin Notification Polling Endpoint
 * Returns new client registrations since a given audit_log ID.
 * Protected: super_admin session required.
 */
require_once __DIR__ . '/../config/bootstrap.php';
include_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

// ── Auth: must be logged-in super_admin ───────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// ── Input: last seen audit_log ID (stored in browser localStorage) ────────────
$sinceId = max(0, (int)($_GET['since_id'] ?? 0));

// ── Query new registrations from audit_log ────────────────────────────────────
$items = [];
$maxId = $sinceId;

$stmt = $conn->prepare(
    "SELECT al.id, al.action, al.description, al.created_at,
            u.name, u.email, u.google_id, u.avatar_url
     FROM audit_log al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE al.action IN ('email_register', 'google_register')
       AND al.id > ?
     ORDER BY al.id ASC
     LIMIT 10"
);

if ($stmt) {
    $stmt->bind_param('i', $sinceId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id'        => (int)$row['id'],
            'name'      => (string)($row['name']   ?? 'Unknown'),
            'email'     => (string)($row['email']  ?? ''),
            'method'    => !empty($row['google_id']) ? 'google' : 'email',
            'avatar'    => (string)($row['avatar_url'] ?? ''),
            'time'      => (string)$row['created_at'],
        ];
        $maxId = max($maxId, (int)$row['id']);
    }
    $stmt->close();
}

echo json_encode([
    'items'   => $items,
    'last_id' => $maxId,
]);
