<?php
/**
 * WAAMINI WhatsApp API Helper
 * C:\xampp\htdocs\waamini\includes\whatsapp_api_helper.php
 */

/**
 * Send a plain text WhatsApp message
 *
 * @param string|null $graphApiVersion Optional Graph API version (e.g. v21.0). When null, uses WHATSAPP_API_VERSION env or v18.0.
 */
function send_whatsapp_text(string $phone, string $message, string $token, string $phoneId, ?string $graphApiVersion = null, ?array &$meta = null): ?string {
    $t0 = microtime(true);
    // Local simulation mode for tests (never hits Meta)
    $env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    $simulate = (string)($_ENV['WAAMINI_WA_SIMULATE'] ?? getenv('WAAMINI_WA_SIMULATE') ?: '');
    if ($env === 'local' && in_array(strtolower($simulate), ['1', 'true', 'yes', 'on'], true)) {
        $fakeId = 'sim_' . bin2hex(random_bytes(8));
        if ($meta !== null) {
            $meta = array_merge($meta ?? [], [
                'endpoint' => 'SIMULATED',
                'http_code' => 200,
                'curl_error' => '',
                'response' => json_encode(['messages' => [['id' => $fakeId]]]),
                'request_json' => json_encode(['to' => $phone, 'type' => 'text']),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        }
        return $fakeId;
    }

    $phone = preg_replace('/\D+/', '', str_replace('+', '', $phone));
    
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'text',
        'text'              => ['body' => $message, 'preview_url' => false]
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $apiVer = $graphApiVersion ?? ($_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0');
    $endpoint = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($token),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (is_array($meta)) {
        $meta = array_merge($meta, [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payload,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    } elseif ($meta === null) {
        $meta = [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payload,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    if ($curlError) {
        error_log("[WAAMINI] cURL error sending to $phone: $curlError");
        return null;
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['messages'][0]['id'])) {
        return $data['messages'][0]['id'];
    }

    $errMsg = $data['error']['message'] ?? 'Unknown error';
    error_log("[WAAMINI] WhatsApp API error ($httpCode) sending to $phone: $errMsg");
    return null;
}

/**
 * Send a WhatsApp template message
 */
function send_whatsapp_template(string $phone, string $templateName, string $langCode, array $components, string $token, string $phoneId, ?array &$meta = null): ?string {
    $t0 = microtime(true);
    $env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    $simulate = (string)($_ENV['WAAMINI_WA_SIMULATE'] ?? getenv('WAAMINI_WA_SIMULATE') ?: '');
    if ($env === 'local' && in_array(strtolower($simulate), ['1', 'true', 'yes', 'on'], true)) {
        $fakeId = 'sim_' . bin2hex(random_bytes(8));
        if ($meta !== null) {
            $meta = array_merge($meta ?? [], [
                'endpoint' => 'SIMULATED',
                'http_code' => 200,
                'curl_error' => '',
                'response' => json_encode(['messages' => [['id' => $fakeId]]]),
                'request_json' => json_encode(['to' => $phone, 'type' => 'template']),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        }
        return $fakeId;
    }

    $phone = str_replace('+', '', $phone);

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'template',
        'template'          => [
            'name'       => $templateName,
            'language'   => ['code' => $langCode],
        ]
    ];

    if (!empty($components)) {
        $payload['template']['components'] = $components;
    }

    $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
    $endpoint = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($token),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (is_array($meta)) {
        $meta = array_merge($meta, [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payloadJson ?: '',
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    } elseif ($meta === null) {
        $meta = [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payloadJson ?: '',
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['messages'][0]['id'])) {
        return $data['messages'][0]['id'];
    }

    $errMsg = $data['error']['message'] ?? 'Unknown';
    error_log("[WAAMINI] Template send error ($httpCode): $errMsg");
    return null;
}

/**
 * Send WhatsApp media message (image/document)
 */
function send_whatsapp_media(string $phone, string $mediaUrl, string $type, string $caption, string $token, string $phoneId, ?array &$meta = null): ?string {
    $t0 = microtime(true);
    $env = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    $simulate = (string)($_ENV['WAAMINI_WA_SIMULATE'] ?? getenv('WAAMINI_WA_SIMULATE') ?: '');
    if ($env === 'local' && in_array(strtolower($simulate), ['1', 'true', 'yes', 'on'], true)) {
        $fakeId = 'sim_' . bin2hex(random_bytes(8));
        if ($meta !== null) {
            $meta = array_merge($meta ?? [], [
                'endpoint' => 'SIMULATED',
                'http_code' => 200,
                'curl_error' => '',
                'response' => json_encode(['messages' => [['id' => $fakeId]]]),
                'request_json' => json_encode(['to' => $phone, 'type' => 'media', 'link' => $mediaUrl]),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        }
        return $fakeId;
    }

    $phone = str_replace('+', '', $phone);

    $mediaKey = in_array($type, ['image', 'video', 'audio', 'document', 'sticker']) ? $type : 'image';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => $mediaKey,
        $mediaKey           => ['link' => $mediaUrl, 'caption' => $caption]
    ];

    $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
    $endpoint = "https://graph.facebook.com/{$apiVer}/{$phoneId}/messages";
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($token),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (is_array($meta)) {
        $meta = array_merge($meta, [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payloadJson ?: '',
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    } elseif ($meta === null) {
        $meta = [
            'endpoint' => $endpoint,
            'http_code' => (int)$httpCode,
            'curl_error' => (string)$curlError,
            'response' => is_string($response) ? $response : '',
            'request_json' => $payloadJson ?: '',
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['messages'][0]['id'])) {
        return $data['messages'][0]['id'];
    }

    return null;
}

function get_whatsapp_health($token, $phoneId) {
    $endpoint = "https://graph.facebook.com/v18.0/{$phoneId}?fields=quality_rating,messaging_limit_tier,status,code_verification_status";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim($token)]
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200) ? json_decode($res, true) : null;
}