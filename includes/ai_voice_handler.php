<?php
/**
 * WAAMINI AI Voice Handler
 * Handles WhatsApp Media Download and OpenAI Whisper Transcription
 */

/**
 * OpenAI API key for Whisper (must be a real OpenAI key — not a Gemini key).
 */
function waamini_resolve_openai_key_for_whisper(mysqli $conn, int $companyId): ?string
{
    if (file_exists(__DIR__ . '/../services/EncryptionService.php')) {
        require_once __DIR__ . '/../services/EncryptionService.php';
    }

    $key = '';
    $st = $conn->prepare('SELECT ai_provider, ai_api_key FROM bot_settings WHERE company_id=? LIMIT 1');
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $bs = $st->get_result()->fetch_assoc();
        if ($bs && !empty($bs['ai_api_key']) && strtolower((string)($bs['ai_provider'] ?? '')) === 'openai') {
            $key = trim((string)$bs['ai_api_key']);
        }
    }

    if ($key === '') {
        $st2 = $conn->prepare('SELECT openai_api_key, is_active FROM ai_bot_settings WHERE company_id=? LIMIT 1');
        if ($st2) {
            $st2->bind_param('i', $companyId);
            $st2->execute();
            $row = $st2->get_result()->fetch_assoc();
            if ($row && !empty($row['openai_api_key'])) {
                $activeOk = true;
                if (array_key_exists('is_active', $row)) {
                    $activeOk = ((int)($row['is_active'] ?? 0) === 1);
                }
                if ($activeOk) {
                    $key = trim((string)$row['openai_api_key']);
                }
            }
        }
    }

    if ($key !== '' && function_exists('decrypt_secret')) {
        $key = decrypt_secret($key) ?? $key;
    }

    return $key !== '' ? $key : null;
}

/**
 * @return array{text: string, language: string|null}|null  Transcript + Whisper ISO language; null on failure
 */
function transcribe_whatsapp_audio($mediaId, $companyId, $openAiKey)
{
    global $conn;

    // 1. Get WhatsApp Config for this company
    $stmt = $conn->prepare("SELECT access_token FROM whatsapp_config WHERE company_id = ?");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    if (!$config)
        return false;
    $waToken = (string)($config['access_token'] ?? '');
    if ($waToken !== '' && function_exists('decrypt_secret')) {
        if (file_exists(__DIR__ . '/../services/EncryptionService.php')) {
            require_once __DIR__ . '/../services/EncryptionService.php';
        }
        $waToken = decrypt_secret($waToken) ?? $waToken;
    }
    if ($waToken === '') {
        return false;
    }

    // 2. Get Media URL from Meta
    $ch = curl_init("https://graph.facebook.com/v19.0/$mediaId");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $waToken"]
    ]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (empty($data['url']))
        return false;
    $mediaUrl = $data['url'];

    // 3. Download the binary audio data
    $ch = curl_init($mediaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $waToken"]
    ]);
    $audioData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$audioData)
        return false;

    // 4. Save to temporary file (binary from Meta — usually OGG/opus voice note)
    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "wa_voice_$mediaId.bin";
    file_put_contents($tempFile, $audioData);

    // 5. OpenAI Whisper — do NOT send `language`; model auto-detects spoken language
    //    (Tamil, Malayalam, English, Hindi, etc.). MIME uses generic octet-stream if needed for odd encodings.
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    $cFile = new CURLFile($tempFile, 'application/octet-stream', 'audio.' . $mediaId . '.ogg');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $openAiKey",
            "Content-Type: multipart/form-data"
        ],
        CURLOPT_POSTFIELDS => [
            'file' => $cFile,
            'model' => 'whisper-1',
            // verbose_json returns detected language (helpful for logs); transcript still in `text`
            'response_format' => 'verbose_json',
        ]
    ]);

    $response = curl_exec($ch);
    $resJson = json_decode($response, true);
    curl_close($ch);

    // Cleanup
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }

    if (!empty($resJson['text'])) {
        require_once __DIR__ . '/ai_language_reply.php';
        $langRaw = isset($resJson['language']) ? (string)$resJson['language'] : null;
        $lang = waamini_normalize_whisper_language($langRaw);
        if ($lang !== null && $lang !== '') {
            error_log('[WAAMINI Whisper] detected_language=' . $lang . ' company_id=' . (int)$companyId);
        }

        return [
            'text' => (string)$resJson['text'],
            'language' => $lang,
        ];
    }

    return null;
}
?>
