<?php
include '../includes/client_auth_check.php';
header('Content-Type: application/json');

$contactId = intval($_POST['contact_id'] ?? 0);
if (!$contactId) { echo json_encode(['error'=>'Invalid contact']); exit(); }

// AI usage guard (daily cap)
if (file_exists(__DIR__ . '/../includes/ai_usage.php')) {
    require_once __DIR__ . '/../includes/ai_usage.php';
    if (function_exists('ai_usage_consume')) {
        if (!ai_usage_consume($conn, (int)$companyId, 1)) {
            http_response_code(429);
            echo json_encode(['error' => 'AI daily limit reached', 'suggestions' => []]);
            exit();
        }
    }
}

// Encryption helpers
if (file_exists(__DIR__ . '/../services/EncryptionService.php')) {
    require_once __DIR__ . '/../services/EncryptionService.php';
}

// Prefer bot_settings (multi-provider) then fall back to ai_bot_settings
$aiProvider = 'openai';
$aiKey = '';
$aiModel = 'gpt-4o-mini';
try {
    $st = $conn->prepare("SELECT ai_provider, ai_api_key, ai_model FROM bot_settings WHERE company_id=? AND ai_enabled=1 LIMIT 1");
    if ($st) {
        $st->bind_param("i", $companyId);
        $st->execute();
        $bs = $st->get_result()->fetch_assoc();
        if ($bs && !empty($bs['ai_api_key'])) {
            $aiProvider = (string)($bs['ai_provider'] ?? 'openai');
            $aiKey = (string)($bs['ai_api_key'] ?? '');
            $aiModel = (string)($bs['ai_model'] ?? ($aiProvider === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o-mini'));
        }
    }
} catch (\Throwable $e) {}

if ($aiKey === '') {
    $stmt_ai = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id=? AND is_active=1 LIMIT 1");
    $stmt_ai->bind_param("i", $companyId);
    $stmt_ai->execute();
    $ai = $stmt_ai->get_result()->fetch_assoc();
    $aiProvider = 'openai';
    $aiKey = (string)($ai['openai_api_key'] ?? '');
    $aiModel = 'gpt-4o-mini';
}

if ($aiKey !== '' && function_exists('decrypt_secret')) {
    $aiKey = decrypt_secret($aiKey) ?? $aiKey;
}

if ($aiKey === '') { echo json_encode(['error'=>'AI not configured']); exit(); }

// Get contact info
$stmt_c = $conn->prepare("SELECT name, tags, status FROM contacts WHERE id=? AND company_id=?");
$stmt_c->bind_param("ii", $contactId, $companyId);
$stmt_c->execute();
$contact = $stmt_c->get_result()->fetch_assoc();

// Get last 6 messages
$stmt_m = $conn->prepare("SELECT direction, message FROM messages WHERE contact_id=? AND company_id=? ORDER BY id DESC LIMIT 6");
$stmt_m->bind_param("ii", $contactId, $companyId);
$stmt_m->execute();
$msgs = array_reverse($stmt_m->get_result()->fetch_all(MYSQLI_ASSOC));
if (empty($msgs)) { echo json_encode(['suggestions'=>[]]); exit(); }

$history = implode("\n", array_map(fn($m) => ($m['direction']==='incoming'?'Customer':'Agent').": ".$m['message'], $msgs));
$lastMsg = end($msgs);
if ($lastMsg['direction'] !== 'incoming') { echo json_encode(['suggestions'=>[]]); exit(); }

$prompt = "You are a helpful sales/support agent assistant. Based on this WhatsApp conversation, suggest 3 short, natural reply options the agent can send. Be concise, friendly, and professional.\n\nContact: {$contact['name']} | Status: {$contact['status']}\n\nConversation:\n$history\n\nReply ONLY with JSON array of 3 strings: [\"reply1\", \"reply2\", \"reply3\"]";

$suggestions = [];
if ($aiProvider === 'gemini') {
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($aiModel) . ":generateContent?key=" . rawurlencode($aiKey);
    $gemPayload = json_encode([
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => 220, 'temperature' => 0.7],
    ]);
    $ch = curl_init($geminiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $gemPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $text = ($http >= 200 && $http < 300 && is_array($data) && is_array($data['candidates'] ?? null) && is_array($data['candidates'][0]['content'] ?? null) && is_array($data['candidates'][0]['content']['parts'] ?? null)) ? ($data['candidates'][0]['content']['parts'][0]['text'] ?? '[]') : '[]';
} else {
    $payload = json_encode(['model'=>$aiModel,'messages'=>[['role'=>'user','content'=>$prompt]],'max_tokens'=>220,'temperature'=>0.7]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$aiKey,
            'Content-Type: application/json'
        ]
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $text = ($http >= 200 && $http < 300 && is_array($data) && is_array($data['choices'] ?? null) && is_array($data['choices'][0]['message'] ?? null)) ? ($data['choices'][0]['message']['content'] ?? '[]') : '[]';
}

$text = trim(preg_replace('/```json|```/','',$text));
if ($text !== '') {
    $suggestions = json_decode($text, true);
}
if (!is_array($suggestions)) $suggestions = [];

echo json_encode(['suggestions' => array_slice($suggestions, 0, 3)]);
