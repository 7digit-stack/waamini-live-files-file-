<?php
/**
 * WAAMINI – Contact Notes (List)
 */
ob_start();
require_once __DIR__ . '/../includes/client_auth_check.php';
ob_clean();
session_write_close(); // read-only endpoint — release session lock for concurrency

header('Content-Type: application/json');

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit();
}

$cid = intval($_GET['contact_id'] ?? 0);
$limit = intval($_GET['limit'] ?? 20);
if ($limit < 5) $limit = 5;
if ($limit > 50) $limit = 50;

if ($cid <= 0) {
    echo json_encode(['error' => 'Invalid contact_id']);
    exit();
}

// Verify ownership
$chk = $conn->prepare("SELECT id FROM contacts WHERE id=? AND company_id=? LIMIT 1");
if (!$chk) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit();
}
$chk->bind_param('ii', $cid, $companyId);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Ensure table exists (safe/idempotent)
try {
    $conn->query("CREATE TABLE IF NOT EXISTS contact_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        created_by INT DEFAULT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company_contact (company_id, contact_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$out = [];
$st = $conn->prepare("SELECT n.id, n.note, n.created_at, u.name as created_by_name
    FROM contact_notes n
    LEFT JOIN users u ON u.id=n.created_by AND u.company_id=n.company_id
    WHERE n.company_id=? AND n.contact_id=?
    ORDER BY n.id DESC
    LIMIT ?");
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit();
}
$st->bind_param('iii', $companyId, $cid, $limit);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id' => (int)$r['id'],
        'note' => (string)$r['note'],
        'created_at' => (string)$r['created_at'],
        'created_by' => (string)($r['created_by_name'] ?? ''),
    ];
}

echo json_encode(['ok' => true, 'items' => $out]);

