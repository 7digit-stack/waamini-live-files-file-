<?php
/**
 * WAAMINI - AJAX Chat Fetcher
 */
ob_start(); // catch any stray output (warnings, redirects)
require_once '../includes/client_auth_check.php';
ob_clean(); // discard anything the auth check may have output

// Release session lock — this endpoint is read-only (fetches messages).
// Concurrent chat polling requests now run in parallel, not one-by-one.
session_write_close();

header('Content-Type: application/json');

// If auth check redirected (e.g. session expired), companyId won't be set
if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit();
}

if (!isset($_GET['contact_id'])) {
    echo json_encode(['error' => 'Missing contact_id']);
    exit();
}

$cid = intval($_GET['contact_id']);
$lastId = intval($_GET['last_id'] ?? 0);
$tail = intval($_GET['tail'] ?? 0);
if ($tail < 0) $tail = 0;
if ($tail > 120) $tail = 120; // safety cap
if ($cid <= 0) {
    echo json_encode(['error' => 'Invalid contact_id']);
    exit();
}

// Verify contact belongs to company (prepared)
$stmt_chk = $conn->prepare("SELECT id FROM contacts WHERE id = ? AND company_id = ? LIMIT 1");
if (!$stmt_chk) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
    exit();
}
$stmt_chk->bind_param("ii", $cid, $companyId);
$stmt_chk->execute();
if ($stmt_chk->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$messages = [];
// Fetch messages (either delta or tail for status updates)
if ($tail > 0) {
    // Pull the last N messages, then return oldest->newest
    $stmt = $conn->prepare("
        SELECT * FROM (
            SELECT * FROM messages
            WHERE company_id = ? AND contact_id = ?
            ORDER BY id DESC
            LIMIT ?
        ) t ORDER BY id ASC
    ");
    if ($stmt) {
        $stmt->bind_param("iii", $companyId, $cid, $tail);
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM messages WHERE company_id = ? AND contact_id = ? AND id > ? ORDER BY id ASC LIMIT 50");
    if ($stmt) {
        $stmt->bind_param("iii", $companyId, $cid, $lastId);
    }
}
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
    exit();
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['created_at'])) {
        $row['time_formatted'] = date('h:i A', strtotime($row['created_at']));
    } else {
        $row['time_formatted'] = '';
    }
    // Normalise: content column is canonical (webhook/bot), message column is legacy (dashboard sends).
    // Always expose as 'message' so JS and PHP templates have a single field to read.
    if (empty($row['message']) && !empty($row['content'])) {
        $row['message'] = $row['content'];
    }
    $messages[] = $row;
}

// Mark incoming as read when agent opens chat (skip view-only / restricted — matches client/whatsapp.php policy)
if (empty($viewOnlyMode) && empty($isRestricted)) {
    $stmt_r = $conn->prepare("UPDATE messages SET is_read = 1 WHERE company_id = ? AND contact_id = ? AND direction = 'incoming'");
    if ($stmt_r) {
        $stmt_r->bind_param("ii", $companyId, $cid);
        $stmt_r->execute();
    }

    // Keep chat_conversations in sync for fast inbox badges
    $stCv = $conn->prepare("UPDATE chat_conversations SET unread_count = 0 WHERE company_id = ? AND contact_id = ?");
    if ($stCv) {
        $stCv->bind_param("ii", $companyId, $cid);
        $stCv->execute();
    }
}

echo json_encode($messages);
