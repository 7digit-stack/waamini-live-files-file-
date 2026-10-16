<?php
/**
 * WAAMINI – AI Smart Compose API
 * Uses bot_settings (Gemini/OpenAI) to rewrite/translate messages for agents.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/client_auth_check.php';

if (ob_get_level()) { @ob_clean(); }
header('Content-Type: application/json');

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit();
}

if (!has_feature('whatsapp_chat')) {
    http_response_code(403);
    echo json_encode(['error' => 'feature_disabled']);
    exit();
}

if (!empty($viewOnlyMode) || !empty($isRestricted)) {
    http_response_code(403);
    echo json_encode(['error' => 'read_only']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

csrf_verify();

$mode = trim((string)($_POST['mode'] ?? ''));
$text = trim((string)($_POST['text'] ?? ''));
$context = trim((string)($_POST['context'] ?? ''));

if (function_exists('mb_strlen')) {
    if (mb_strlen($text, 'UTF-8') > 8000) {
        $text = mb_substr($text, 0, 8000, 'UTF-8');
    }
    if (mb_strlen($context, 'UTF-8') > 2000) {
        $context = mb_substr($context, 0, 2000, 'UTF-8');
    }
}
else {
    if (strlen($text) > 8000) {
        $text = substr($text, 0, 8000);
    }
    if (strlen($context) > 2000) {
        $context = substr($context, 0, 2000);
    }
}

if ($text === '') {
    echo json_encode(['error' => 'Text is empty']);
    exit();
}

$allowedModes = [
    'shorten',
    'persuasive',
    'translate_en',
    'translate_ta',
    'tanglish',
];
if (!in_array($mode, $allowedModes, true)) {
    echo json_encode(['error' => 'Invalid mode']);
    exit();
}

// Load bot_settings
$aiProvider = null;
$aiApiKey = '';
$aiModel = '';
$systemPrompt = 'You are an assistant for a WhatsApp sales/support agent. Return ONLY the final rewritten message. No lists, no explanations.';
$maxTokens = 250;

try {
    $bsStmt = $conn->prepare("SELECT * FROM bot_settings WHERE company_id=? AND ai_enabled=1 LIMIT 1");
    if ($bsStmt) {
        $bsStmt->bind_param('i', $companyId);
        $bsStmt->execute();
        $bs = $bsStmt->get_result()->fetch_assoc();
        if ($bs) {
            $aiProvider = (string)($bs['ai_provider'] ?? 'gemini');
            $aiApiKey   = (string)($bs['ai_api_key'] ?? '');
            $aiModel    = (string)($bs['ai_model'] ?? '');
            if (!empty($bs['system_prompt'])) {
                // Reuse the same system prompt but enforce single-message output
                $systemPrompt = trim((string)$bs['system_prompt']) . "\n\nCRITICAL: Return ONLY the final message text. No bullet points. No explanations.";
            }
            $maxTokens  = (int)($bs['max_tokens'] ?? 250);
            if ($maxTokens <= 0) $maxTokens = 250;
            if ($maxTokens > 500) $maxTokens = 500;
        }
    }
} catch (\Throwable $e) {}

if ($aiApiKey !== '' && file_exists(__DIR__ . '/../services/EncryptionService.php')) {
    require_once __DIR__ . '/../services/EncryptionService.php';
}
if ($aiApiKey !== '' && function_exists('decrypt_secret')) {
    $aiApiKey = decrypt_secret((string)$aiApiKey) ?? $aiApiKey;
}

if (!$aiProvider || trim($aiApiKey) === '') {
    echo json_encode(['error' => 'AI not configured. Please enable AI in Bot Settings.']);
    exit();
}

// Build instruction per mode
$task = '';
if ($mode === 'shorten') {
    $task = "Rewrite the message to be shorter and clearer, keep meaning, keep WhatsApp tone.";
} elseif ($mode === 'persuasive') {
    $task = "Rewrite the message to be more persuasive and conversion-focused, still natural and not pushy.";
} elseif ($mode === 'translate_en') {
    $task = "Translate to English, keep it natural for WhatsApp.";
} elseif ($mode === 'translate_ta') {
    $task = "Translate to Tamil (தமிழ்), keep it natural for WhatsApp.";
} elseif ($mode === 'tanglish') {
    $task = "Rewrite in natural Tanglish (Tamil words in English letters), friendly WhatsApp tone.";
}

$userPrompt = $task . "\n\nMessage:\n" . $text;
if ($context !== '') {
    $userPrompt .= "\n\nContext:\n" . $context;
}

$reply = null;

try {
    if ($aiProvider === 'gemini') {
        $geminiModel = $aiModel !== '' ? $aiModel : 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$geminiModel}:generateContent?key={$aiApiKey}";
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.7],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200 && $resp) {
            $j = json_decode($resp, true);
            $reply = (is_array($j) && is_array($j['candidates'] ?? null) && is_array($j['candidates'][0]['content'] ?? null) && is_array($j['candidates'][0]['content']['parts'] ?? null)) ? ($j['candidates'][0]['content']['parts'][0]['text'] ?? null) : null;
        } else {
            error_log('[WAAMINI AI COMPOSE] Gemini HTTP ' . $http . ': ' . substr((string)$resp, 0, 200));
        }
    } else {
        $oaiModel = $aiModel !== '' ? $aiModel : 'gpt-4o-mini';
        $payload = [
            'model' => $oaiModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => $maxTokens,
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $aiApiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200 && $resp) {
            $j = json_decode($resp, true);
            $reply = (is_array($j) && is_array($j['choices'] ?? null) && is_array($j['choices'][0]['message'] ?? null)) ? ($j['choices'][0]['message']['content'] ?? null) : null;
        } else {
            error_log('[WAAMINI AI COMPOSE] OpenAI HTTP ' . $http . ': ' . substr((string)$resp, 0, 200));
        }
    }
} catch (\Throwable $e) {
    error_log('[WAAMINI AI COMPOSE] error: ' . $e->getMessage());
}

$reply = trim((string)$reply);
if ($reply === '') {
    echo json_encode(['error' => 'AI failed to generate. Check API key/model.']);
    exit();
}

echo json_encode(['ok' => true, 'text' => $reply]);

