<?php
/**
 * WAAMINI - WhatsApp Cloud API Class
 * Standalone implementation reading directly from $_ENV
 */

class WhatsAppAPI {
    private string $baseUrl;
    private string $phoneId;
    private string $token;
    
    public function __construct(?int $companyId = null) {
        $version = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
        $this->baseUrl = 'https://graph.facebook.com/' . $version;
        $this->phoneId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?: getenv('WA_BUSINESS_PHONE_NUMBER_ID') ?: '';
        $this->token   = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: getenv('WA_ACCESS_TOKEN') ?: '';
        
        if ($companyId !== null && $companyId > 0) {
            global $conn;
            if (isset($conn)) {
                $st = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
                if ($st) {
                    $st->bind_param("i", $companyId);
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                    if ($row) {
                        $this->phoneId = (string)($row['phone_number_id'] ?: $this->phoneId);
                        
                        // Load decryption helper if needed
                        if (!function_exists('waamini_whatsapp_decrypt_config_token')) {
                            $settingsFile = __DIR__ . '/whatsapp_settings.php';
                            if (file_exists($settingsFile)) {
                                require_once $settingsFile;
                            }
                        }

                        if (function_exists('waamini_whatsapp_decrypt_config_token')) {
                            $this->token = waamini_whatsapp_decrypt_config_token($row) ?: $this->token;
                        } else {
                            $this->token = (string)($row['access_token'] ?: $this->token);
                        }
                        
                        if (!empty($row['api_version'])) {
                            $this->baseUrl = 'https://graph.facebook.com/' . (string)$row['api_version'];
                        }
                    }
                }
            }
        }

        if (empty($this->phoneId) || empty($this->token)) {
            error_log("[WAAMINI CRITICAL] WhatsApp credentials missing for Company $companyId");
        }
    }
    
    public function sendTextMessage(string $to, string $message, bool $previewUrl = false): array {
        if (empty($this->token)) return $this->error('API not configured');
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => $previewUrl, 'body' => substr($message, 0, 4096)]
        ];
        return $this->sendRequest($payload);
    }
    
    public function sendTemplateMessage(string $to, string $templateName, string $lang = 'en', array $components = []): array {
        if (empty($this->token)) return $this->error('API not configured');
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $lang]
            ]
        ];
        
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }
        
        return $this->sendRequest($payload);
    }
    
    public function sendButtonMessage(string $to, string $bodyText, array $buttons): array {
        return $this->sendAdvancedInteractive($to, $bodyText, $buttons);
    }

    public function sendAdvancedInteractive(string $to, string $bodyText, array $buttons, ?string $footer = '', ?string $headerType = 'none', ?string $headerUrl = ''): array {
        if (empty($this->token)) return $this->error('API not configured');
        if (count($buttons) > 3) return $this->error('Max 3 buttons allowed');
        
        $formatted = [];
        foreach ($buttons as $btn) {
            $btnId = $btn['id'] ?? 'btn_' . rand(1000, 9999);
            $btnText = $btn['text'] ?? $btn['title'] ?? 'Select';
            $formatted[] = [
                'type' => 'reply',
                'reply' => ['id' => substr($btnId, 0, 256), 'title' => substr($btnText, 0, 20)]
            ];
        }
        
        $interactive = [
            'type' => 'button',
            'body' => ['text' => substr($bodyText, 0, 1024)],
            'action' => ['buttons' => $formatted]
        ];

        if (!empty($footer)) {
            $interactive['footer'] = ['text' => substr($footer, 0, 60)];
        }

        if ($headerType !== 'none' && !empty($headerUrl)) {
            $interactive['header'] = [
                'type' => $headerType,
                $headerType => ['link' => $headerUrl]
            ];
        }
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive
        ];
        return $this->sendRequest($payload);
    }

    public function sendMediaMessage(string $to, string $type, string $mediaUrl, string $caption = '', string $filename = ''): array {
        if (empty($this->token)) return $this->error('API not configured');
        if (!in_array($type, ['image', 'video', 'document'])) return $this->error('Invalid type');
        
        $media = ['link' => $mediaUrl];
        if (!empty($caption) && in_array($type, ['image', 'video'])) $media['caption'] = substr($caption, 0, 1024);
        if ($type === 'document' && $filename !== '') {   // shown to the customer as the file name
            $media['filename'] = mb_substr($filename, 0, 120);
            if (!empty($caption)) $media['caption'] = substr($caption, 0, 1024);
        }
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => $type,
            $type => $media
        ];
        return $this->sendRequest($payload);
    }
    
    /** Interactive list message (max 10 rows, row title 24 chars). Used by the Flow Builder. */
    public function sendListMessage(string $to, string $bodyText, string $buttonText, array $rows, string $footer = ''): array {
        if (empty($this->token)) return $this->error('API not configured');
        $rows = array_slice($rows, 0, 10);
        if (!$rows) return $this->error('List needs at least one row');
        $formatted = [];
        foreach ($rows as $r) {
            $row = ['id' => substr((string)($r['id'] ?? ''), 0, 200), 'title' => mb_substr((string)($r['title'] ?? 'Select'), 0, 24)];
            if (!empty($r['description'])) $row['description'] = mb_substr((string)$r['description'], 0, 72);
            $formatted[] = $row;
        }
        $interactive = [
            'type' => 'list',
            'body' => ['text' => mb_substr($bodyText, 0, 1024)],
            'action' => ['button' => mb_substr($buttonText !== '' ? $buttonText : 'Choose', 0, 20), 'sections' => [['title' => 'Options', 'rows' => $formatted]]],
        ];
        if ($footer !== '') $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        return $this->sendRequest(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'interactive', 'interactive' => $interactive]);
    }

    public function markAsRead(string $messageId): array {
        $payload = ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId];
        return $this->sendRequest($payload);
    }
    
    private function sendRequest(array $payload, int $attempt = 1): array {
        if (empty($this->phoneId)) return $this->error('Phone ID missing');
        
        $url = $this->baseUrl . '/' . $this->phoneId . '/messages';
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            // Retry once on network error
            if ($attempt < 2) {
                sleep(1);
                return $this->sendRequest($payload, $attempt + 1);
            }
            return $this->error('cURL Error: ' . $err);
        }
        
        $decoded = json_decode($response, true);
        
        // Retry once on 5xx Meta errors
        if ($httpCode >= 500 && $attempt < 2) {
            sleep(1);
            return $this->sendRequest($payload, $attempt + 1);
        }
        
        if ($httpCode >= 400) {
            $errMsg = $decoded['error']['message'] ?? 'Unknown error';
            file_put_contents('c:/xampp/htdocs/waamini/debug.log', "[WA API ERROR] Code $httpCode: $errMsg | Full Response: $response | Payload: " . json_encode($payload) . "\n", FILE_APPEND);
            return $this->error($errMsg, (string)$httpCode);
        }
        
        return [
            'success' => true,
            'message_id' => $decoded['messages'][0]['id'] ?? null
        ];
    }
    
    private function error(string $msg, string $code = '400'): array {
        return ['success' => false, 'error' => ['message' => $msg, 'code' => $code]];
    }
}
