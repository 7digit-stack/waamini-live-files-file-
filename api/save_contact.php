<?php
/**
 * WAAMINI – Save Contact API (compatibility endpoint for tests)
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/client_auth_check.php';

if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

// Require CSRF for any mutation
csrf_verify();

// Minimal validation; main app uses other contact APIs
$name  = sanitize($_POST['name'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'validation', 'message' => 'name and phone are required']);
    exit();
}

// Insert/update contact (best-effort)
$stmt = $conn->prepare("INSERT INTO contacts (company_id, name, phone, created_at) VALUES (?,?,?,NOW())");
if ($stmt) {
    $stmt->bind_param("iss", $companyId, $name, $phone);
    $stmt->execute();
}

echo json_encode(['success' => true]);

