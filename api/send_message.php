<?php
/**
 * WAAMINI – AJAX Send Message API
 * Hardened against RCE, SQLi, and MITM
 */

// Load security and session
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/cache.php';

// Clear any accidental output from includes (e.g. BOMs)
if (ob_get_level()) ob_clean();

header('Content-Type: application/json');

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

// CSRF check
csrf_verify();

 $contactId = intval($_POST['contact_id'] ?? 0);
 $msgText   = sanitize($_POST['message_text'] ?? '');

 $hasFile = isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0;

// Strip UI placeholder for empty captions
if ($hasFile && isset($_FILES['media_file']['name'])) {
    $fileNameClean = $_FILES['media_file']['name'];
    if (str_starts_with($msgText, '? ' . $fileNameClean) || str_starts_with($msgText, '📎 ' . $fileNameClean)) {
        $msgText = '';
    }
}

if ($contactId <= 0 || (empty($msgText) && !$hasFile)) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Verify contact belongs to company (Prepared Statement)
 $chk = $conn->prepare("SELECT phone FROM contacts WHERE id=? AND company_id=? LIMIT 1");
 $chk->bind_param("ii", $contactId, $companyId);
 $chk->execute();
 $contactRow = $chk->get_result()->fetch_assoc();
if (!$contactRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// SECURITY FIX: Robust phone formatting (Strip all non-digits, remove leading 0 for India if necessary)
 $contactPhone = preg_replace('/[^0-9]/', '', $contactRow['phone']);
if (strlen($contactPhone) > 10 && strpos($contactPhone, '0') === 0) {
    $contactPhone = substr($contactPhone, 1); // Remove leading 0 if country code is present
}

 $msgType = 'text';
 $mediaUrl = null;

// ── SECURE FILE UPLOAD ──
if ($hasFile) {
    $mime = $_FILES['media_file']['type'];
    if (str_starts_with($mime, 'image/')) $msgType = 'image';
    elseif (str_starts_with($mime, 'video/')) $msgType = 'video';
    else $msgType = 'document';
    
    // CRITICAL FIX: Strict whitelist of allowed extensions
    $allowedExts = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => ['mp4', '3gp', 'mov'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt']
    ];
    
    $ext = strtolower(pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowedExts[$msgType] ?? [])) {
        echo json_encode(['error' => 'Invalid file type for this media']);
        exit();
    }
    
    // Save locally for dashboard rendering
    $uploadDir = __DIR__ . '/../uploads/chat_media/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    // Generate safe filename
    $filename = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    // CRITICAL FIX: Use move_uploaded_file instead of copy()
    if (!move_uploaded_file($_FILES['media_file']['tmp_name'], $destination)) {
        echo json_encode(['error' => 'Failed to save file']);
        exit();
    }
    
    $mediaUrl = 'uploads/chat_media/' . $filename;
}

// Save to DB (Prepared Statement)
 // Status starts as queued; updated to sent/failed once API result is known.
 $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, content, media_url, is_read, status) VALUES (?,?,'outgoing',?,?,?,?,1,'queued')");
 $mediaBind = ($mediaUrl !== null && $mediaUrl !== '') ? (string)$mediaUrl : '';
 $stmt->bind_param("iissss", $companyId, $contactId, $msgType, $msgText, $msgText, $mediaBind);
 $stmt->execute();
 $newMsgId = $conn->insert_id;
 // Invalidate company dashboard cache (message counts/recent)
 wa_cache_bump("company:{$companyId}");

// CRITICAL FIX: Parameterized update query to prevent SQLi
 $updContact = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=? AND company_id=?");
 $updContact->bind_param("ii", $contactId, $companyId);
 $updContact->execute();
 wa_cache_bump("company:{$companyId}");

// Maintain chat_conversations summary row for fast inbox list
try {
    $preview = trim((string)$msgText);
    if ($preview === '' && $hasFile) {
        $preview = '[' . $msgType . ']';
    }
    if (function_exists('mb_strlen') && mb_strlen($preview, 'UTF-8') > 800) {
        $preview = mb_substr($preview, 0, 800, 'UTF-8') . '…';
    } elseif (strlen($preview) > 800) {
        $preview = substr($preview, 0, 800) . '…';
    }
    $stCv = $conn->prepare("
        INSERT INTO chat_conversations (company_id, contact_id, last_message, last_message_at, unread_count)
        VALUES (?, ?, ?, NOW(), 0)
        ON DUPLICATE KEY UPDATE
            last_message = VALUES(last_message),
            last_message_at = VALUES(last_message_at)
    ");
    if ($stCv) {
        $stCv->bind_param('iis', $companyId, $contactId, $preview);
        $stCv->execute();
    }
} catch (\Throwable $e) {}

 $apiStatus  = 'queued';
 $waMsgId    = null;
 $apiError   = null;

 // WhatsApp expects country code digits (no '+'). If contact is 10-digit India number, prefix 91.
 if (strlen($contactPhone) === 10 && preg_match('/^[6-9]\\d{9}$/', $contactPhone)) {
     $contactPhone = '91' . $contactPhone;
 }

 // Enqueue for async processing (never block the UI)
 require_once __DIR__ . '/../services/QueueService.php';
 $jobType = ($hasFile ? 'media' : 'text');

 // For media-link sends, WhatsApp requires a publicly accessible URL.
 $publicMediaUrl = null;
 if ($hasFile && $mediaUrl) {
     $appUrl = (string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
     if ($appUrl === '') {
         $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
         $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
     }
     $appUrl = rtrim($appUrl, '/');
     $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
     // api/ is one level below root; normalize to root
     $rootPath = preg_replace('~/api$~', '', $basePath);
     $publicMediaUrl = $appUrl . $rootPath . '/' . ltrim((string)$mediaUrl, '/');
 }

 $enqueued = QueueService::enqueue($conn, [
     'company_id' => $companyId,
     'contact_id' => $contactId,
     'type'       => $jobType,
     'phone'      => $contactPhone,
     'message'    => $msgText,
     'media_url'  => $publicMediaUrl,
     'payload'    => [
         'message_id' => (int)$newMsgId,
         'media_type' => $msgType,
         'source' => 'api_send_message'
     ],
 ]);
 if (!$enqueued) {
     error_log("[api/send_message] Queue enqueue failed for company={$companyId}, message={$newMsgId}");
     $failed = $conn->prepare("UPDATE messages SET status='failed', error_message='queue_enqueue_failed', updated_at=NOW() WHERE id=? AND company_id=?");
     if ($failed) {
         $failed->bind_param('ii', $newMsgId, $companyId);
         $failed->execute();
         $failed->close();
     }
     http_response_code(503);
     echo json_encode(['success' => false, 'error' => 'Message could not be queued. Please try again.']);
     exit();
 }

 // LOCAL FAILOVER: If running on XAMPP (local), trigger the worker immediately
 // so the user doesn't see "queued" indefinitely.
 if (\Waamini\Config\Env::get('APP_ENV') === 'local') {
     try {
         require_once __DIR__ . '/../services/QueueWorker.php';
         $worker = new QueueWorker($conn);
         $worker->process(5); // Process the message we just added
     } catch (\Throwable $e) {
         error_log('[api/send_message] Local worker trigger failed: ' . $e->getMessage());
     }
 }


echo json_encode([
    'success'    => true,
    'message_id' => $newMsgId,
    'api_status' => $apiStatus,
    'wa_msg_id'  => $waMsgId,
    'api_error'  => $apiError,
    'time'       => date('h:i A'),
    'message'    => $msgText
]);