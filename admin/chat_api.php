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

// SEND a file (photo, video, audio or document) from the paperclip button. multipart/form-data.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_media') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? '');
    if (!is_string($token) || $token === '' || !hash_equals(csrf_get_token(), $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session expired. Refresh the page and try again.']);
        exit;
    }
    session_write_close();
    $contactId = (int)($_POST['contact_id'] ?? 0);
    $caption   = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 1024);
    $f = $_FILES['file'] ?? null;
    if (!$contactId || !$f || (int)$f['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Choose a file to send (max ' . ini_get('upload_max_filesize') . ').']);
        exit;
    }
    // WhatsApp's own limits: images 5 MB, audio/video 16 MB, documents 100 MB
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $kinds = ['image' => ['jpg', 'jpeg', 'png', 'webp'], 'video' => ['mp4', '3gp'], 'audio' => ['mp3', 'ogg', 'm4a', 'aac', 'amr'],
              'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'zip']];
    $limits = ['image' => 5, 'video' => 16, 'audio' => 16, 'document' => 100];
    $type = null;
    foreach ($kinds as $k => $list) { if (in_array($ext, $list, true)) { $type = $k; break; } }
    if (!$type) { echo json_encode(['success' => false, 'error' => 'WhatsApp cannot send .' . $ext . ' files. Use JPG/PNG, MP4, MP3, PDF or Office files.']); exit; }
    if ((int)$f['size'] > $limits[$type] * 1048576) { echo json_encode(['success' => false, 'error' => 'Too large: WhatsApp allows ' . $limits[$type] . ' MB for ' . $type . ' files.']); exit; }

    $c = $conn->prepare("SELECT phone, company_id FROM contacts WHERE id = ? LIMIT 1");
    $c->bind_param('i', $contactId); $c->execute();
    $contact = $c->get_result()->fetch_assoc(); $c->close();
    if (!$contact || empty($contact['phone'])) { echo json_encode(['success' => false, 'error' => 'Contact not found or has no phone number']); exit; }
    $companyId = (int)$contact['company_id'];

    $dir = __DIR__ . '/../uploads/chat_media/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fileName = 'admin_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $fileName)) { echo json_encode(['success' => false, 'error' => 'Could not save the file on the server.']); exit; }
    // WhatsApp downloads the file from this link, so it must be the public https address of the site
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host  = (string)($_SERVER['HTTP_HOST'] ?? '');
    $base  = rtrim((string)(\Waamini\Config\Env::get('PUBLIC_URL', '') ?: ''), '/');
    if ($base === '' && $https && $host !== '') $base = 'https://' . $host;
    $publicUrl = ($base !== '' ? $base : '') . '/uploads/chat_media/' . $fileName;

    $label = $caption !== '' ? $caption : ['image' => '📷 Photo', 'video' => '🎥 Video', 'audio' => '🎵 Audio', 'document' => '📄 ' . $f['name']][$type];
    $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, content, media_url, status, is_read) VALUES (?, ?, 'outgoing', ?, ?, ?, ?, 'pending', 1)");
    $ins->bind_param('iissss', $companyId, $contactId, $type, $caption, $caption, $publicUrl);
    $ins->execute(); $msgId = (int)$conn->insert_id; $ins->close();
    $cv = $conn->prepare("INSERT INTO chat_conversations (contact_id, last_message, last_message_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message=VALUES(last_message), last_message_at=NOW()");
    $cv->bind_param('is', $contactId, $label); $cv->execute();

    $fail = static function (string $err) use ($conn, $msgId, $publicUrl, $type): void {
        $u = $conn->prepare("UPDATE messages SET status='failed', error_message=? WHERE id=?");
        if ($u) { $u->bind_param('si', $err, $msgId); $u->execute(); }
        echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => false, 'wa_error' => $err, 'media_url' => $publicUrl, 'type' => $type]);
        exit;
    };
    if (!preg_match('#^https://#i', $publicUrl) || preg_match('#//(localhost|127\.0\.0\.1)#i', $publicUrl)) {
        $fail('Saved, but WhatsApp needs a public https link. Set PUBLIC_URL in .env (works automatically on waamini.com).');
    }

    $cf = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id = ? AND status = 'active' ORDER BY updated_at DESC, id DESC LIMIT 1");
    $cf->bind_param('i', $companyId); $cf->execute();
    $cfg = $cf->get_result()->fetch_assoc(); $cf->close();
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
        $sys = waamini_whatsapp_load_system_settings($conn);
        if (!empty($sys['system_wa_token']) && !empty($sys['system_wa_phone_id'])) {
            $cfg = ['access_token' => $sys['system_wa_token'], 'phone_number_id' => $sys['system_wa_phone_id'], 'api_version' => ($sys['wa_default_api_version'] ?? '') ?: 'v18.0'];
        }
    }
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) $fail('WhatsApp API not configured for this company');

    $media = ['link' => $publicUrl];
    if ($caption !== '' && $type !== 'audio') $media['caption'] = $caption;
    if ($type === 'document') $media['filename'] = mb_substr((string)$f['name'], 0, 120);
    $payload = json_encode(['messaging_product' => 'whatsapp', 'to' => preg_replace('/\D+/', '', (string)$contact['phone']), 'type' => $type, $type => $media],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $apiVer = (string)($cfg['api_version'] ?? '') ?: 'v18.0';
    $ch = curl_init('https://graph.facebook.com/' . $apiVer . '/' . $cfg['phone_number_id'] . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim(waamini_whatsapp_decrypt_config_token($cfg))]]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($cerr) $fail('Network error: ' . $cerr);
    $rd = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && isset($rd['messages'][0]['id'])) {
        $wid = (string)$rd['messages'][0]['id'];
        $u = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=?");
        $u->bind_param('si', $wid, $msgId); $u->execute();
        echo json_encode(['success' => true, 'message_id' => $msgId, 'wa_sent' => true, 'media_url' => $publicUrl, 'type' => $type]);
        exit;
    }
    $fail((string)($rd['error']['message'] ?? ('WhatsApp error (HTTP ' . $code . ')')));
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

    $stmt = $conn->prepare("SELECT id, direction, type, media_url, COALESCE(NULLIF(content,''), message) AS message_text, status, created_at FROM messages WHERE contact_id=? AND id > ? ORDER BY created_at ASC, id ASC");
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
