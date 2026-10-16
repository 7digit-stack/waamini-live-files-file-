<?php
require_once '../config/bootstrap.php';
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/feature_check.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$companyId = intval($_SESSION['company_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Ensure the user has the feature enabled
if (!has_feature('feat_ocr_scanner')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Plan does not support OCR.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['leads']) || !is_array($input['leads'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload format.']);
    exit();
}

$maxBatch = 200;
$leads = $input['leads'];
if (count($leads) > $maxBatch) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Too many leads in one request (max ' . $maxBatch . ').']);
    exit();
}

$contactLimit = get_limit('contacts');
$usage = get_usage('contacts');
$remainingSlots = ($contactLimit === 0) ? PHP_INT_MAX : max(0, $contactLimit - $usage);
if ($contactLimit > 0 && $remainingSlots === 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Contact limit reached. Remove contacts or upgrade your plan.']);
    exit();
}

$savedCount = 0;
$stoppedByLimit = false;
$stmt_check = $conn->prepare("SELECT id FROM contacts WHERE phone = ? AND company_id = ?");
$stmt_insert = $conn->prepare("INSERT INTO contacts (company_id, phone, name, status, chat_labels) VALUES (?, ?, ?, 'unassigned', 'Image Scan')");

foreach ($leads as $lead) {
    if (!is_array($lead)) {
        continue;
    }
    if ($contactLimit > 0 && $savedCount >= $remainingSlots) {
        $stoppedByLimit = true;
        break;
    }

    $phone = sanitize((string)($lead['phone'] ?? ''));
    $name = sanitize((string)($lead['name'] ?? 'Unknown'));

    if ($phone === '') {
        continue;
    }

    $stmt_check->bind_param("si", $phone, $companyId);
    $stmt_check->execute();
    $chkRes = $stmt_check->get_result();
    $exists = $chkRes && $chkRes->num_rows > 0;
    if ($chkRes instanceof mysqli_result) {
        $chkRes->free();
    }
    if ($exists) {
        continue;
    }

    $stmt_insert->bind_param("iss", $companyId, $phone, $name);
    if ($stmt_insert->execute()) {
        $savedCount++;
        include_once '../includes/drip_engine.php';
        trigger_drip_campaigns($conn, $companyId, $stmt_insert->insert_id, 'Image Scan');
    }
}

echo json_encode([
    'status' => 'success',
    'saved' => $savedCount,
    'stopped_by_limit' => $stoppedByLimit,
]);
