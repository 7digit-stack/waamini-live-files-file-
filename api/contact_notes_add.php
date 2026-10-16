<?php
/**
 * WAAMINI – Contact Notes (Add)
 */
require_once __DIR__ . '/../includes/client_auth_check.php';
if (ob_get_level()) { @ob_clean(); }
header('Content-Type: application/json');

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit();
}

if (!empty($viewOnlyMode) || !empty($isRestricted)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'read_only']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

csrf_verify();

$cid = intval($_POST['contact_id'] ?? 0);
$note = trim(str_replace("\0", '', (string)($_POST['note'] ?? '')));
if (function_exists('mb_strlen') && mb_strlen($note, 'UTF-8') > 10000) {
    $note = mb_substr($note, 0, 10000, 'UTF-8');
}
elseif (strlen($note) > 10000) {
    $note = substr($note, 0, 10000);
}
if ($cid <= 0 || $note === '') {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Verify ownership
$chk = $conn->prepare("SELECT id FROM contacts WHERE id=? AND company_id=? LIMIT 1");
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

$uid = intval($_SESSION['user_id'] ?? 0);
$st = $conn->prepare("INSERT INTO contact_notes (company_id, contact_id, created_by, note, created_at) VALUES (?,?,?,?,NOW())");
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit();
}
$st->bind_param('iiis', $companyId, $cid, $uid, $note);
if (!$st->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit();
}

echo json_encode(['ok' => true, 'id' => (int)$conn->insert_id]);

