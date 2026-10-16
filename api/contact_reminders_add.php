<?php
/**
 * WAAMINI – Contact Reminders (Add)
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
$dueAt = trim((string)($_POST['due_at'] ?? ''));
$note = trim(str_replace("\0", '', (string)($_POST['note'] ?? '')));
if (function_exists('mb_strlen') && mb_strlen($note, 'UTF-8') > 255) {
    $note = mb_substr($note, 0, 255, 'UTF-8');
}
elseif (strlen($note) > 255) {
    $note = substr($note, 0, 255);
}
if ($cid <= 0 || $dueAt === '') {
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
    $conn->query("CREATE TABLE IF NOT EXISTS contact_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        contact_id INT NOT NULL,
        created_by INT DEFAULT NULL,
        due_at DATETIME NOT NULL,
        note VARCHAR(255) DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        done_at DATETIME DEFAULT NULL,
        INDEX idx_company_contact (company_id, contact_id),
        INDEX idx_due (due_at),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// Basic validation: due_at must parse
$ts = strtotime($dueAt);
if ($ts === false) {
    echo json_encode(['error' => 'Invalid due_at']);
    exit();
}
$dueAtSql = date('Y-m-d H:i:s', $ts);

$uid = intval($_SESSION['user_id'] ?? 0);
$st = $conn->prepare("INSERT INTO contact_reminders (company_id, contact_id, created_by, due_at, note, status, created_at)
    VALUES (?,?,?,?,?,'pending',NOW())");
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit();
}
$st->bind_param('iiiss', $companyId, $cid, $uid, $dueAtSql, $note);
if (!$st->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit();
}

echo json_encode(['ok' => true, 'id' => (int)$conn->insert_id]);

