<?php
/**
 * WAAMINI – Contact Reminders (Done)
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

$rid = intval($_POST['reminder_id'] ?? 0);
if ($rid <= 0) {
    echo json_encode(['error' => 'Invalid reminder_id']);
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

$st = $conn->prepare("UPDATE contact_reminders SET status='done', done_at=NOW() WHERE id=? AND company_id=? AND status='pending'");
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit();
}
$st->bind_param('ii', $rid, $companyId);
$st->execute();
if ($conn->affected_rows < 1) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit();
}

echo json_encode(['ok' => true]);

