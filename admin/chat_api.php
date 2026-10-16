<?php
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/whatsapp_settings.php';
require_once __DIR__ . '/../services/EncryptionService.php';

header('Content-Type: application/json');

// IMPORTANT: Read php://input ONCE because it can only be read once!
$rawInput = file_get_contents('php://input');
$data = ($rawInput !== false && $rawInput !== '') ? json_decode($rawInput, true) : [];

// CSRF for JSON POSTs (admin panel)
function _require_csrf_from_json(array $data, string $rawInput): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['_token'] ?? null);
    if (!is_string($token) || $token === '' || !hash_equals(csrf_get_token(), $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed. Please refresh and try again.']);
        exit;
    }
}

// SEND message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$data || !isset($data['action'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }

    if ($data['action'] === 'send') {
        _require_csrf_from_json($data, $rawInput);
        session_write_close(); // Release session lock AFTER CSRF verification
        $contactId = intval($data['contact_id']);
        $message = trim($data['message'] ?? '');

        if (!$contactId || !$message) {
            echo json_encode(['success' => false, 'error' => 'Missing fields']);
            exit();
        }

        // Fetch contact phone & company_id
        $cStmt = $conn->prepare("SELECT phone, company_id FROM contacts WHERE id = ? LIMIT 1");
        $cStmt->bind_param("i", $contactId);
        $cStmt->execute();
        $contact = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if (!$contact || empty($contact['phone'])) {
            echo json_encode(['success' => false, 'error' => 'Contact not found or has no phone number']);
            exit();
        }

        $contactPhone = $contact['phone'];
        $companyId = (int)$contact['company_id'];

        // Insert message as 'pending' first (will update to sent/failed after API call)
        $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read) VALUES (?, ?, 'outgoing', 'text', ?, 'pending', 1)");
        $stmt->bind_param("iis", $companyId, $contactId, $message);

        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit();
        }

        $msgId = $conn->insert_id;

        // Update conversation
        $convStmt = $conn->prepare("INSERT INTO chat_conversations (contact_id, last_message, last_message_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message=VALUES(last_message), last_message_at=NOW()");
        $convStmt->bind_param("is", $contactId, $message);
        $convStmt->execute();

        // Fetch WhatsApp config for this company
        $cfgStmt = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id = ? AND status = 'active' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $cfgStmt->bind_param("i", $companyId);
        $cfgStmt->execute();
        $cfg = $cfgStmt->get_result()->fetch_assoc();
        $cfgStmt->close();

        // Fallback: try system-level (master) WhatsApp config
        if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
            $sysSettings = waamini_whatsapp_load_system_settings($conn);
            $sysToken = (string)($sysSettings['system_wa_token'] ?? '');
            $sysPhoneId = (string)($sysSettings['system_wa_phone_id'] ?? '');
            $sysApiVer = (string)($sysSettings['wa_default_api_version'] ?? '');
            if (!empty($sysToken) && !empty($sysPhoneId)) {
                $cfg = [
                    'access_token' => $sysToken,
                    'phone_number_id' => $sysPhoneId,
                    'api_version' => $sysApiVer ?: 'v18.0'
                ];
            }
        }

        if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
            // No WA config — mark as failed but still return the DB message
            $failStmt = $conn->prepare("UPDATE messages SET status = 'failed' WHERE id = ?");
            $failStmt->bind_param("i", $msgId);
            $failStmt->execute();
            echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => false, 'wa_error' => 'WhatsApp API not configured for this company']);
            exit();
        }

        // Decrypt access token
        $waToken = waamini_whatsapp_decrypt_config_token($cfg);

        $phoneId = (string)$cfg['phone_number_id'];
        $apiVer = (string)($cfg['api_version'] ?? $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0');

        // Send via WhatsApp Cloud API
        $cleanPhone = preg_replace('/\D+/', '', str_replace('+', '', $contactPhone));
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $cleanPhone,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message]
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $url = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim($waToken),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $failStmt = $conn->prepare("UPDATE messages SET status = 'failed' WHERE id = ?");
            $failStmt->bind_param("i", $msgId);
            $failStmt->execute();
            echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => false, 'wa_error' => 'cURL error: ' . $curlError]);
            exit();
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['messages'][0]['id'])) {
            $waMsgId = (string)$responseData['messages'][0]['id'];
            $sentStmt = $conn->prepare("UPDATE messages SET status = 'sent', whatsapp_message_id = ? WHERE id = ?");
            $sentStmt->bind_param("si", $waMsgId, $msgId);
            $sentStmt->execute();
            echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => true, 'wa_message_id' => $waMsgId]);
        } else {
            $apiError = $responseData['error']['message'] ?? 'Unknown API error (HTTP ' . $httpCode . ')';
            $failStmt = $conn->prepare("UPDATE messages SET status = 'failed' WHERE id = ?");
            $failStmt->bind_param("i", $msgId);
            $failStmt->execute();
            echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => false, 'wa_error' => $apiError]);
        }
        exit();
    }

    // Simulate incoming message (for demo)
    if ($data['action'] === 'simulate_incoming') {
        _require_csrf_from_json($data, $rawInput);
        session_write_close(); // Release session lock AFTER CSRF verification
        $contactId = intval($data['contact_id']);
        $message = trim($data['message'] ?? '');

        $cid2Stmt = $conn->prepare("SELECT company_id FROM contacts WHERE id=? LIMIT 1");
        $cid2Stmt->bind_param("i", $contactId);
        $cid2Stmt->execute();
        $cid2Row = $cid2Stmt->get_result()->fetch_assoc();
        $simCompanyId = (int)($cid2Row['company_id'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, status, is_read) VALUES (?, ?, 'incoming', 'text', ?, 'received', 0)");
        $stmt->bind_param("iis", $simCompanyId, $contactId, $message);
        $stmt->execute();

        $convStmt = $conn->prepare("UPDATE chat_conversations SET last_message=?, last_message_at=NOW(), unread_count=unread_count+1 WHERE contact_id=?");
        $convStmt->bind_param("si", $message, $contactId);
        $convStmt->execute();

        echo json_encode(['success' => true]);
        exit();
    }
}

// POLL for new messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'poll') {
    $contactId = intval($_GET['contact_id']);
    $lastId = intval($_GET['last_id']);

    $stmt = $conn->prepare("SELECT id, direction, COALESCE(NULLIF(content,''), message) AS message_text, status, created_at FROM messages WHERE contact_id=? AND id > ? ORDER BY created_at ASC, id ASC");
    if (!$stmt) {
        echo json_encode(['messages' => []]);
        exit();
    }
    $stmt->bind_param('ii', $contactId, $lastId);
    $stmt->execute();
    $result = $stmt->get_result();
    $msgs = [];
    while ($m = $result->fetch_assoc()) {
        $msgs[] = $m;
    }

    echo json_encode(['messages' => $msgs]);
    exit();
}

echo json_encode(['error' => 'Unknown action']);
?>
