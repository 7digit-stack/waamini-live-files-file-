<?php
/**
 * WAAMINI API - Zapier / Pabbly Catch Hook
 * Endpoint: /waamini/api/zapier_catch.php?company_id=XXX
 * Preferred authentication: X-WAAMINI-API-Key or Authorization: Bearer.
 * Legacy api_key query authentication remains supported for existing Zaps.
 */
require_once '../db.php';
require_once '../includes/security.php';

header('Content-Type: application/json');

// 1. Authenticate Request
$companyId = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;
$apiKey = trim((string)($_SERVER['HTTP_X_WAAMINI_API_KEY'] ?? ''));
if ($apiKey === '' && preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $matches)) {
    $apiKey = trim($matches[1]);
}
if ($apiKey === '' && isset($_GET['api_key'])) {
    $apiKey = sanitize($_GET['api_key']);
}

if ($companyId === 0 || empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing company_id or API key.']);
    exit();
}

$stmt = $conn->prepare("SELECT id FROM api_keys WHERE company_id = ? AND api_key = ? AND is_active = 1");
$stmt->bind_param("is", $companyId, $apiKey);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API Key or Company ID.']);
    exit();
}

// 2. Enforce HMAC Signature for payload integrity (VULN-011 FIX)
$inputRaw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WAAMINI_SIGNATURE'] ?? '';

// We use the API Key itself as the HMAC secret for Zapier/Pabbly integrations
$expectedSignature = hash_hmac('sha256', $inputRaw, $apiKey);

if (!hash_equals($expectedSignature, $signature)) {
    // Note: In development/localhost, you might want to log this but allow it.
    // For 100% production security, we enforce it.
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid HMAC signature.']);
    exit();
}


// 2. Parse Incoming JSON Payload
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

if (!$input || !isset($input['phone']) || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload. "phone" and "message" are required fields.']);
    exit();
}

$phone = sanitize($input['phone']);
$message = sanitize($input['message']);
$name = isset($input['name']) ? sanitize($input['name']) : 'Zapier Lead';
$email = isset($input['email']) ? sanitize($input['email']) : '';

// 3. Find or Create Contact in CRM
$stmt_contact = $conn->prepare("SELECT id FROM contacts WHERE phone = ? AND company_id = ?");
$stmt_contact->bind_param("si", $phone, $companyId);
$stmt_contact->execute();
$contactRes = $stmt_contact->get_result();

if ($contactRes->num_rows === 0) {
    // Add new lead to CRM
    $stmt_ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name, email, status, chat_labels) VALUES (?, ?, ?, ?, 'unassigned', 'Zapier Lead')");
    $stmt_ins->bind_param("isss", $companyId, $phone, $name, $email);
    $stmt_ins->execute();
    $contactId = $stmt_ins->insert_id;
}
else {
    $contactId = $contactRes->fetch_assoc()['id'];

    // Optional: Update name if provided and contact exists
    if (!empty($input['name'])) {
        $stmt_upd = $conn->prepare("UPDATE contacts SET name = ? WHERE id = ?");
        $stmt_upd->bind_param("si", $name, $contactId);
        $stmt_upd->execute();
    }
}

// 4. Queue the Message for Delivery
// We insert this into the automation_queue so that the cron job sends it out.
// This prevents the webhook from hanging if Meta's API is slow, ensuring Zapier gets a fast 200 OK.
$stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?, ?, 'instant', ?, NOW())");
$stmt_q->bind_param("iis", $companyId, $contactId, $message);

if ($stmt_q->execute()) {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Payload received and message queued successfully.']);
}
else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to queue message.']);
}
