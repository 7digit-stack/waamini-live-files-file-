<?php
/**
 * WAAMINI - WhatsApp Webhook Handler
 * Reconstructed & Hardened for actual project structure
 */

// Block direct browser access (Allow Meta GET/POST and CLI)
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['hub_mode'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Load core dependencies
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php'; // provides $conn (mysqli)

// Encryption helpers — needed to decrypt stored tokens (WhatsApp access_token, AI keys)
if (file_exists(__DIR__ . '/services/EncryptionService.php')) {
    require_once __DIR__ . '/services/EncryptionService.php';
}

if (file_exists(__DIR__ . '/includes/whatsappapi.php')) {
    require_once __DIR__ . '/includes/whatsappapi.php';
}
if (file_exists(__DIR__ . '/includes/whatsapp_settings.php')) {
    require_once __DIR__ . '/includes/whatsapp_settings.php';
}
if (file_exists(__DIR__ . '/includes/chatbot_engine.php')) {
    require_once __DIR__ . '/includes/chatbot_engine.php';
}
// WAAMINI Bot (one AI bot per client). Helper used to keep the older AI queue silent for clients whose bot is live.
require_once __DIR__ . '/includes/waamini_bot/bootstrap.php';
if (!function_exists('waamini_bot_is_live')) {
    function waamini_bot_is_live(mysqli $c, int $companyId): bool { try { return WB_Guard::isLive($c, $companyId); } catch (\Throwable $e) { return false; } }
}
// Smart Router: 3-tier priority system (Bot Replies → Flow Builder → AI Sales Bot)
if (file_exists(__DIR__ . '/includes/smart_router.php')) {
    require_once __DIR__ . '/includes/smart_router.php';
}

class WebhookHandler {
    private $waApi = null;
    private $chatbot = null;
    
    public function __construct() {
        // Dynamically initialize existing classes without crashing if they differ
        if (class_exists('ChatbotEngine')) {
            $this->chatbot = new ChatbotEngine();
        }
    }
    
    /**
     * GET: Meta Webhook Verification
     */
    public function verify(): void {
        $mode = $_GET['hub_mode'] ?? '';
        $token = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge'] ?? '';
        
        if (empty($mode) || empty($token) || empty($challenge)) {
            http_response_code(400);
            exit('Missing verification parameters');
        }
        
        // Prefer per-company verify token from DB when company_id is present in callback URL
        $verifyToken = '';
        $qid = (int)($_GET['company_id'] ?? 0);
        if ($qid > 0) {
            global $conn;
            if ($conn instanceof mysqli) {
                $st = $conn->prepare("SELECT verify_token FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
                if ($st) {
                    $st->bind_param('i', $qid);
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                    $verifyToken = (string)($row['verify_token'] ?? '');
                }
            }
        }
        // The callback URL shown in Settings has no company id (/api/webhook/whatsapp), so when none is
        // given, accept the token if it belongs to any saved WhatsApp configuration.
        if ($verifyToken === '' && $qid <= 0) {
            global $conn;
            if ($conn instanceof mysqli) {
                $st2 = $conn->prepare("SELECT verify_token FROM whatsapp_config WHERE verify_token = ? AND verify_token <> '' LIMIT 1");
                if ($st2) {
                    $st2->bind_param('s', $token);
                    $st2->execute();
                    $row2 = $st2->get_result()->fetch_assoc();
                    $verifyToken = (string)($row2['verify_token'] ?? '');
                }
            }
        }
        // If still empty after DB lookup, fall back to environment variable.
        if ($verifyToken === '') {
            $verifyToken = getenv('WHATSAPP_VERIFY_TOKEN') ?: getenv('WA_WEBHOOK_VERIFY_TOKEN') ?: '';
        }
        
        // VULN-008 FIX: Removed hardcoded project token for security. 
        // Verification will fail if no token is set in DB or ENV, which is intended for production.
        // The platform default token from Admin > WhatsApp Onboarding (system_settings) is accepted too,
        // so the value shown on that page works when verifying any company's callback URL.
        $defaultToken = '';
        global $conn;
        if ($conn instanceof mysqli) {
            $st3 = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'wa_webhook_verify_token' LIMIT 1");
            if ($st3) {
                $st3->execute();
                $row3 = $st3->get_result()->fetch_assoc();
                $defaultToken = trim((string)($row3['setting_value'] ?? ''));
            }
        }
        if ($verifyToken === '' && $defaultToken === '') {
             http_response_code(403);
             exit('Verification failed: No verify token configured');
        }
        
        $matches = ($verifyToken !== '' && hash_equals($verifyToken, $token))
            || ($defaultToken !== '' && hash_equals($defaultToken, $token));
        if ($mode === 'subscribe' && $matches) {
            http_response_code(200);
            exit($challenge);
        }
        
        http_response_code(403);
        exit('Verification failed');
    }
    
    /**
     * POST: Incoming Webhook Event
     */
    public function handleEvent(): void {
        $payload = file_get_contents('php://input');
        
        if (empty($payload)) {
            http_response_code(400);
            exit('Empty payload');
        }
        
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Helpful local diagnostics
            if (strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')) === 'local') {
                error_log('[WAAMINI WEBHOOK] Invalid JSON: ' . json_last_error_msg() . ' len=' . strlen($payload));
                try {
                    @file_put_contents(__DIR__ . '/storage/logs/webhook.log', json_encode([
                        'ts' => date('c'),
                        'error' => 'invalid_json',
                        'json_error' => json_last_error_msg(),
                        'len' => strlen($payload),
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {}
            }
            http_response_code(400);
            exit('Invalid JSON');
        }
        
        // Security: Validate HMAC signature. (VULN-007 FIX)
        $appSecret = getenv('META_APP_SECRET') ?: getenv('FB_APP_SECRET') ?: getenv('WHATSAPP_APP_SECRET') ?: '';
        $isProduction = (strtolower((string)(getenv('APP_ENV') ?: '')) === 'production');
        $strictVerify = filter_var(getenv('WEBHOOK_STRICT_VERIFY') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        
        // Enforce signature check ONLY in production or if explicitly enabled in local .env.
        // Clients connect their own Meta apps, and Meta signs each webhook with that app's secret, so the
        // signature may match the platform secret OR the App Secret the client saved in WhatsApp Setup.
        if ($isProduction || $strictVerify) {
            $secrets = $this->candidateSecrets($data, $appSecret);
            if (!$secrets) {
                error_log('[WAAMINI WEBHOOK] CRITICAL: Signature verification is enabled but no Meta app secret is configured.');
                http_response_code(403);
                exit('Security configuration missing');
            }
            $signed = false;
            foreach ($secrets as $secret) {
                if ($this->validateSignature($payload, $secret)) { $signed = true; break; }
            }
            if (!$signed) {
                error_log('[WAAMINI WEBHOOK] Signature verification failed for payload of length ' . strlen($payload) . ' (checked ' . count($secrets) . ' secret(s); a client using its own Meta app must save its App Secret in WhatsApp Setup)');
                http_response_code(403);
                exit('Invalid signature');
            }
        }
        
        try {
            error_log('[WAAMINI WEBHOOK] Processing event for company_id: ' . ($companyId ?? 'unknown'));
            $this->processEvent($data);
            
            // Always return 200 to prevent Meta retries
            http_response_code(200);
            exit('OK');
            
        } catch (\Throwable $e) {
            error_log('[WAAMINI WEBHOOK ERROR] ' . $e->getMessage());
            // A non-2xx response lets Meta retry instead of permanently dropping an unpersisted message.
            http_response_code(500);
            exit('Webhook processing failed');
        }
    }
    
    /**
     * Secrets that may have signed this webhook: the platform app secret, plus the App Secret saved by the
     * company in the callback URL (?company_id=) and by the company that owns the phone number in the payload.
     * @return string[]
     */
    private function candidateSecrets(array $data, string $platformSecret): array {
        global $conn;
        $secrets = $platformSecret !== '' ? [$platformSecret] : [];
        if (!($conn instanceof mysqli)) return $secrets;
        try {
            $col = $conn->query("SHOW COLUMNS FROM whatsapp_config LIKE 'app_secret'");
            if (!$col || $col->num_rows === 0) return $secrets;
            $rows = [];
            $cid = (int)($_GET['company_id'] ?? 0);
            if ($cid > 0) {
                $st = $conn->prepare("SELECT app_secret FROM whatsapp_config WHERE company_id=? AND app_secret IS NOT NULL AND app_secret<>'' LIMIT 1");
                if ($st) { $st->bind_param('i', $cid); $st->execute(); if ($r = $st->get_result()->fetch_row()) $rows[] = $r[0]; $st->close(); }
            }
            $phoneIds = [];
            foreach (($data['entry'] ?? []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    $pid = (string)($change['value']['metadata']['phone_number_id'] ?? '');
                    if ($pid !== '' && ctype_digit($pid)) $phoneIds[$pid] = true;
                }
            }
            foreach (array_slice(array_keys($phoneIds), 0, 5) as $pid) {
                $st = $conn->prepare("SELECT app_secret FROM whatsapp_config WHERE phone_number_id=? AND app_secret IS NOT NULL AND app_secret<>'' LIMIT 3");
                if ($st) { $st->bind_param('s', $pid); $st->execute(); $res = $st->get_result(); while ($r = $res->fetch_row()) $rows[] = $r[0]; $st->close(); }
            }
            foreach (array_unique($rows) as $cipher) {
                $plain = function_exists('decrypt_secret') ? decrypt_secret((string)$cipher) : null;
                if (is_string($plain) && $plain !== '') $secrets[] = $plain;
            }
        } catch (\Throwable $e) {
            error_log('[WAAMINI WEBHOOK] candidateSecrets: ' . $e->getMessage());
        }
        return array_values(array_unique($secrets));
    }

    /**
     * Validate Meta HMAC Signature
     */
    private function validateSignature(string $payload, string $appSecret): bool {
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

        // If no secret is configured, signature verification is disabled by design.
        if ($appSecret === '') {
            return true;
        }
        if ($signature === '') {
            return false;
        }
        
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Route Webhook Events
     */
    private function processEvent(array $data): void {
        if (!isset($data['entry']) || !is_array($data['entry'])) return;
        
        foreach ($data['entry'] as $entry) {
            if (!isset($entry['changes'])) continue;
            
            foreach ($entry['changes'] as $change) {
                $value = $change['value'] ?? [];
                
                // Handle Incoming Messages
                if (isset($value['messages'])) {
                    foreach ($value['messages'] as $message) {
                        $this->processMessage($message, $value);
                    }
                }
                
                // Handle Message Status (Sent, Delivered, Read, Failed)
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        $this->processStatus($status);
                    }
                }
            }
        }
    }
    
    /**
     * Process Incoming Message
     */
    private function processMessage(array $message, array $value): void {
        global $conn;
        $messageId = $message['id'] ?? '';
        $from = $message['from'] ?? '';
        $type = $message['type'] ?? 'text';
        $isNfmReply = ($type === 'interactive' && (($message['interactive']['type'] ?? '') === 'nfm_reply'));

        if (empty($messageId) || empty($from)) return;
        
        // Idempotency: Prevent duplicate processing within same request
        static $processedIds = [];
        if (isset($processedIds[$messageId])) return;
        $processedIds[$messageId] = true;
        
        // Determine company scope.
        // Prefer explicit company_id on the webhook URL (common in this codebase),
        // then fall back to metadata.phone_number_id mapping.
        $companyId = $this->resolveCompanyId($value, (string)$from);
        if ($companyId <= 0) {
            // No company has this WhatsApp number saved. That is a setup problem Meta's retries cannot fix,
            // so log it and let the request finish with 200 (a 5xx would make Meta retry and later disable the webhook).
            $pnid = (string)($value['metadata']['phone_number_id'] ?? '');
            $displayId = (string)($value['metadata']['display_phone_number'] ?? '');
            error_log("[WAAMINI WEBHOOK] SKIPPED: no company has phone_number_id=$pnid (display=$displayId). Save this number in a company's WhatsApp settings.");
            return;
        }
        error_log("[WAAMINI WEBHOOK] Tenant resolved: company_id=$companyId");
        if (class_exists('WhatsAppAPI')) {
            $this->waApi = new WhatsAppAPI($companyId);
        }

        // 1. Ensure contact exists
        $contactId = $this->ensureContactExists($companyId, $from, $value);
        if ($contactId <= 0) {
            throw new \RuntimeException('Could not resolve or create the inbound WhatsApp contact.');
        }



        // Cross-request idempotency: Meta may retry webhooks; avoid inserting/sending twice.
        // If this message_id already exists in DB, exit early.
        try {
            $chk = $conn->prepare("SELECT id FROM messages WHERE company_id=? AND whatsapp_message_id=? LIMIT 1");
            if ($chk) {
                $wm = (string)$messageId;
                $chk->bind_param('is', $companyId, $wm);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                if ($row && isset($row['id'])) {
                    return;
                }
            }
        } catch (\Throwable $e) {}

        // Lightweight monitoring log (confirms webhook is firing + tenant mapping)
        try {
            $path = __DIR__ . '/storage/logs/webhook.log';
            $line = json_encode([
                'ts' => date('c'),
                'event' => 'incoming_message',
                'company_id' => $companyId,
                'from' => (string)$from,
                'contact_id' => $contactId,
                'type' => (string)$type,
                'message_id' => (string)$messageId,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($line)) {
                @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable $e) {}
        
        // 2. Extract message content based on type
        $content = '';
        $mediaId = null;
        
        switch ($type) {
            case 'text':
                $content = $message['text']['body'] ?? '';
                break;
            case 'image':
                $content = $message['image']['caption'] ?? '';
                $mediaId = $message['image']['id'] ?? null;
                break;
            case 'video':
                $content = $message['video']['caption'] ?? '';
                $mediaId = $message['video']['id'] ?? null;
                break;
            case 'document':
                $content = $message['document']['caption'] ?? $message['document']['filename'] ?? '';
                $mediaId = $message['document']['id'] ?? null;
                break;
            case 'audio':
                $mediaId = $message['audio']['id'] ?? null;
                break;
            case 'sticker':
                // Stickers have no text body — store type label, never trigger bots
                $content = '';
                $mediaId = $message['sticker']['id'] ?? null;
                break;
            case 'reaction':
                // Reactions (emoji) — store emoji, never trigger bots
                $content = $message['reaction']['emoji'] ?? '';
                break;
            case 'location':
                // Location pin — store coords as label, never trigger bots
                $lat = $message['location']['latitude'] ?? '';
                $lng = $message['location']['longitude'] ?? '';
                $content = $lat !== '' ? "[location:{$lat},{$lng}]" : '[location]';
                break;
            case 'contacts':
                // vCard contact share — never trigger bots
                $content = '[contact]';
                break;
            case 'order':
                // WhatsApp Pay / catalog order — handled by checkout, not bots
                $content = json_encode($message['order'] ?? []);
                break;
            case 'interactive':
                $intType = $message['interactive']['type'] ?? '';
                if ($intType === 'nfm_reply') {
                    // WhatsApp Flow (Native Flow Message) — user submitted the form
                    $content = $this->formatWhatsAppFlowResponse($message);
                    $this->appendContactFlowNotes($companyId, $contactId, $content);
                    try {
                        require_once __DIR__ . '/includes/wa_flows.php';
                        waf_record_response($conn, $companyId, $contactId, (string)$from, (string)$messageId,
                            (string)($message['interactive']['nfm_reply']['response_json'] ?? ''));
                    } catch (\Throwable $e) {
                        error_log('[WAAMINI WEBHOOK] flow response save failed: ' . $e->getMessage());
                    }
                } else {
                    $sub = $message['interactive'][$intType] ?? [];
                    $content = json_encode([
                        'type' => $intType,
                        'id' => is_array($sub) ? ($sub['id'] ?? '') : '',
                        'title' => is_array($sub) ? ($sub['title'] ?? '') : '',
                    ], JSON_UNESCAPED_UNICODE);
                }
                break;
            default:
                // Unknown future types — store empty, never trigger bots
                $content = '';
                break;
        }
        
        $timestamp = date('Y-m-d H:i:s', (int)($message['timestamp'] ?? time()));

        // 3. Store inbound message (schema-compatible: some DBs use message, some use content, many have both)
        static $msgCols = null;
        if (!is_array($msgCols)) {
            $msgCols = [];
            try {
                $r = $conn->query("SHOW COLUMNS FROM messages");
                while ($r && ($c = $r->fetch_assoc())) {
                    $msgCols[strtolower((string)$c['Field'])] = true;
                }
            } catch (\Throwable $e) {}
        }

        $mediaUrl = ($mediaId !== null && $mediaId !== '') ? (string)$mediaId : '';
        $waMid = (string)$messageId;
        $msgText = (string)$content;
        $msgType = (string)$type;

        $hasContent = isset($msgCols['content']);
        $hasMessage = isset($msgCols['message']);

        if ($hasContent && $hasMessage) {
            $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, media_url, is_read, status, whatsapp_message_id, created_at)
                                   VALUES (?, ?, 'incoming', ?, ?, ?, ?, 0, 'received', ?, ?)");
            if (!$ins) throw new \RuntimeException('Could not prepare inbound message insert: ' . $conn->error);
            $ins->bind_param('iissssss', $companyId, $contactId, $msgType, $msgText, $msgText, $mediaUrl, $waMid, $timestamp);
            if (!$ins->execute()) throw new \RuntimeException('Inbound message insert failed: ' . $ins->error);
        } elseif ($hasContent) {
            $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, media_url, is_read, status, whatsapp_message_id, created_at)
                                   VALUES (?, ?, 'incoming', ?, ?, ?, 0, 'received', ?, ?)");
            if (!$ins) throw new \RuntimeException('Could not prepare inbound message insert: ' . $conn->error);
            $ins->bind_param('iisssss', $companyId, $contactId, $msgType, $msgText, $mediaUrl, $waMid, $timestamp);
            if (!$ins->execute()) throw new \RuntimeException('Inbound message insert failed: ' . $ins->error);
        } else {
            // Oldest schema: message column only
            $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, media_url, is_read, status, whatsapp_message_id, created_at)
                                   VALUES (?, ?, 'incoming', ?, ?, ?, 0, 'received', ?, ?)");
            if (!$ins) throw new \RuntimeException('Could not prepare inbound message insert: ' . $conn->error);
            $ins->bind_param('iisssss', $companyId, $contactId, $msgType, $msgText, $mediaUrl, $waMid, $timestamp);
            if (!$ins->execute()) throw new \RuntimeException('Inbound message insert failed: ' . $ins->error);
        }

        $newMsgId = (int)$conn->insert_id;   // read before any other query
        if ($newMsgId <= 0) {
            throw new \RuntimeException('Inbound WhatsApp message was not persisted.');
        }

        // Keep contact ordering correct for inbox UI
        $touch = $conn->prepare("UPDATE contacts SET last_message_at=? WHERE id=? AND company_id=?");
        if ($touch) {
            $touch->bind_param('sii', $timestamp, $contactId, $companyId);
            $touch->execute();
        }

        // AI lead outcome: queue this chat for analysis (debounced; runs in the background worker)
        try {
            require_once __DIR__ . '/includes/ai_crm_sync.php';
            crm_ai_enqueue($conn, (int)$companyId, (int)$contactId);
            if (!class_exists('WB_Guard') && is_file(__DIR__ . '/includes/waamini_bot/bootstrap.php')) { require_once __DIR__ . '/includes/waamini_bot/bootstrap.php'; }
            // Voice note: transcribe in the background, then the bot answers the transcript
            if (in_array((string)$msgType, ['audio', 'voice'], true) && $newMsgId > 0) {
                require_once __DIR__ . '/includes/voice_ai.php';
                voice_ai_enqueue($conn, (int)$companyId, (int)$contactId, $newMsgId);
            }
            if (class_exists('WB_Guard')) { WB_Guard::kickWorker(); }
        } catch (Throwable $e) {
            error_log('[WAAMINI WEBHOOK] crm enqueue: ' . $e->getMessage());
        }

        // Fast inbox: maintain chat_conversations summary row (last_message + unread_count)
        try {
            $preview = trim((string)$content);
            if ($preview !== '' && $preview[0] === '{' && ($__pj = json_decode($preview, true)) && in_array($__pj['type'] ?? '', ['button_reply', 'list_reply'], true) && isset($__pj['title'])) {
                $preview = (string)$__pj['title'];   // customer tapped a button / list row
            }
            if ($preview === '') {
                $preview = '[' . (string)$type . ']';
            }
            if (function_exists('mb_strlen') && mb_strlen($preview, 'UTF-8') > 800) {
                $preview = mb_substr($preview, 0, 800, 'UTF-8') . '…';
            } elseif (strlen($preview) > 800) {
                $preview = substr($preview, 0, 800) . '…';
            }
            $stCv = $conn->prepare("
                INSERT INTO chat_conversations (company_id, contact_id, last_message, last_message_at, unread_count)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    last_message = VALUES(last_message),
                    last_message_at = VALUES(last_message_at),
                    unread_count = unread_count + 1
            ");
            if ($stCv) {
                $stCv->bind_param('iiss', $companyId, $contactId, $preview, $timestamp);
                $stCv->execute();
            }
        } catch (\Throwable $e) {
            // best-effort only
        }

        // Ads & Attribution: Click-to-WhatsApp referral (first message of an ad session). A few indexed
        // queries, no network; the thumbnail is downloaded later by the worker. Never blocks the message.
        if (!empty($message['referral']) && is_array($message['referral'])) {
            try {
                require_once __DIR__ . '/services/AttributionService.php';
                AttributionService::capture($conn, (int)$companyId, (int)$contactId, $message);
            } catch (\Throwable $e) {
                error_log('[WAAMINI WEBHOOK] ad referral: ' . $e->getMessage());
            }
        }

        // 🆕 Queue message for AI processing (async, non-blocking)
        // Text messages AND voice notes (voice → Whisper transcription in the worker)
        $isVoiceForAi = ($type === 'audio' && $contactId > 0 && $mediaId !== null && $mediaId !== '');
        if ($isVoiceForAi && $msgText === '') {
            $msgText = '[VOICE:' . $mediaId . ']'; // worker detects & transcribes
        }
        // Don't enqueue AI when the text clearly targets the deterministic
        // catalog/keyword flow (prevents catalog + AI double replies).
        $aiBlockedByCheckout = false;
        if ($type === 'text' && file_exists(__DIR__ . '/includes/whatsapp_catalog_checkout.php')) {
            require_once __DIR__ . '/includes/whatsapp_catalog_checkout.php';
            if (function_exists('waamini_checkout_should_block_autonomous_ai')) {
                $aiBlockedByCheckout = waamini_checkout_should_block_autonomous_ai((string)$msgText);
            }
        }

        if (($type === 'text' || $isVoiceForAi) && $contactId > 0 && $msgText !== '' && !$aiBlockedByCheckout) {
            try {
                // Check if AI is enabled for this company
                $aiCfg = $conn->prepare("SELECT ai_enabled FROM ai_settings WHERE company_id = ? LIMIT 1");
                if ($aiCfg) {
                    $aiCfg->bind_param('i', $companyId);
                    $aiCfg->execute();
                    $aiRes = $aiCfg->get_result()->fetch_assoc();
                    $aiCfg->close();

                    // If AI enabled, queue this message for processing
                    if ($aiRes && $aiRes['ai_enabled'] && !(function_exists('waamini_bot_is_live') && waamini_bot_is_live($conn, (int)$companyId))) {
                        $qStmt = $conn->prepare(
                            "INSERT IGNORE INTO ai_message_queue
                             (company_id, customer_phone, message_text, wamid, status, created_at)
                             VALUES (?, ?, ?, ?, 'pending', NOW())"
                        );
                        if ($qStmt) {
                            $wamid = (string)$messageId;
                            $qStmt->bind_param('isss', $companyId, $from, $msgText, $wamid);
                            $qStmt->execute();
                            $queuedNew = ($conn->affected_rows > 0);
                            $qStmt->close();
                            error_log("[WEBHOOK] AI queue: company=$companyId, phone=$from, msg_len=" . strlen($msgText));

                            // 🚀 INSTANT REPLY: fire the AI worker in the background right now
                            // (non-blocking - webhook returns 200 to Meta immediately)
                            if ($queuedNew) {
                                $this->triggerAiWorker();
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("[WEBHOOK] AI queue insert failed: " . $e->getMessage());
                // Best-effort: don't block webhook if queue insert fails
            }
        }

        // Track whether a keyword bot already replied (prevents AI queue for same inbound)
        $keywordBotHandled = false;

        // Keyword Bot Replies are handled by ChatbotEngine (single source of truth).
        // This avoids mismatched rules and double sends caused by having 2 different matchers.

        // Plain text for catalog/checkout (interactive replies are JSON in $content — extract id for checkout)
        $checkoutInboundText = (string)$content;
        if ($type === 'interactive' && !$isNfmReply) {
            $intType = $message['interactive']['type'] ?? '';
            if ($intType !== '' && $intType !== 'nfm_reply') {
                $sub = $message['interactive'][$intType] ?? [];
                if (is_array($sub)) {
                    // Prefer the button ID for checkout (e.g. "pay_5", "enq_5") — regex handlers expect it
                    $bid = trim((string)($sub['id'] ?? ''));
                    if ($bid !== '') {
                        $checkoutInboundText = $bid;
                    } else {
                        $ti = trim((string)($sub['title'] ?? ''));
                        if ($ti !== '') {
                            $checkoutInboundText = $ti;
                        }
                    }
                }
            }
        }

        // 3b. WhatsApp catalog → qty → payment (deterministic; runs before keyword bot / AI)
        $checkoutHandled = false;
        $checkoutEligible = !$isNfmReply && ($type === 'text' || ($type === 'interactive' && $checkoutInboundText !== ''));

        // 3c. Sales Tracker (Instant Google Sheet Sync + Reporting)
        if ($type === 'text' && file_exists(__DIR__ . '/includes/sales_tracker.php')) {
            require_once __DIR__ . '/includes/sales_tracker.php';
            $tracker = new SalesTracker($conn);
            $trackerReply = $tracker->process($companyId, $contactId, $msgText, (string)$from);
            if ($trackerReply) {
                if ($this->waApi && method_exists($this->waApi, 'sendTextMessage')) {
                    try {
                        $this->waApi->sendTextMessage((string)$from, $trackerReply);
                        
                        // Log the bot reply
                        $insReply = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, created_at) VALUES (?, ?, 'outgoing', 'text', ?, ?, 'sent', NOW())");
                        if ($insReply) {
                            $insReply->bind_param("iiss", $companyId, $contactId, $trackerReply, $trackerReply);
                            $insReply->execute();
                        }
                    } catch (\Throwable $e) {
                        error_log('[SALES TRACKER REPLY ERROR] ' . $e->getMessage());
                    }
                }
                $checkoutHandled = true; // Stop other bots from replying to this specific command
            }
        }

        if ($checkoutEligible && !$checkoutHandled && file_exists(__DIR__ . '/includes/whatsapp_catalog_checkout.php')) {
            require_once __DIR__ . '/includes/whatsapp_catalog_checkout.php';
            $fromDigits = preg_replace('/\D+/', '', (string)$from);
            try {
                $checkoutHandled = waamini_catalog_checkout_handle($conn, $companyId, $contactId, $fromDigits, $checkoutInboundText);
            } catch (\Throwable $e) {
                error_log('[WAAMINI checkout] ' . $e->getMessage());
            }
        }
        
        // ══════════════════════════════════════════════════════════════════
        // 4. SMART ROUTER: 3-Tier Priority Message Routing
        //    Priority 1: Bot Replies (exact keyword, case-insensitive)
        //    Priority 2: Flow Builder (keyword triggers, case-insensitive)
        //    Priority 3: AI Sales Bot (GPT/Gemini fallback)
        //
        //    All replies work with uppercase & lowercase.
        //    Professional tone, responds in client's language.
        // ══════════════════════════════════════════════════════════════════
        $queuedId = 0;
        if (!$checkoutHandled && !$isNfmReply && class_exists('SmartRouter')) {
            try {
                $smartRouter = new SmartRouter($conn);
                $routeResult = $smartRouter->route($message, $value, $companyId, $contactId);

                if ($routeResult['handled']) {
                    $keywordBotHandled = in_array($routeResult['routed_to'], ['bot_reply', 'flow_builder']);
                    if ($routeResult['routed_to'] === 'ai_sales_bot') {
                        $queuedId = 1; // Signal that AI was queued
                    }
                }

                error_log('[SMART ROUTER] Message from ' . ($from ?? '?') . ' routed to: ' . ($routeResult['routed_to'] ?? 'none'));
            } catch (\Throwable $e) {
                error_log('[SMART ROUTER ERROR] ' . $e->getMessage());
            }
        }
        // Legacy fallback: use old ChatbotEngine if SmartRouter is not available
        elseif (!$checkoutHandled && !$isNfmReply && $this->chatbot && method_exists($this->chatbot, 'processIncomingScoped')) {
            try {
                $keywordBotHandled = (bool) $this->chatbot->processIncomingScoped($message, $value, $companyId, $contactId);
            } catch (Exception $e) {
                error_log('[CHATBOT ERROR] ' . $e->getMessage());
            }

            // Legacy AI enqueue (only if SmartRouter didn't handle it)
            if (!$keywordBotHandled) {
                try {
                    $shouldAiReply = false;
                    $stA = $conn->prepare("SELECT * FROM ai_bot_settings WHERE company_id=? LIMIT 1");
                    if ($stA) {
                        $stA->bind_param('i', $companyId);
                        $stA->execute();
                        $a = $stA->get_result()->fetch_assoc();
                        $isActive = is_array($a) && array_key_exists('is_active', $a) ? ((int)($a['is_active'] ?? 0) === 1) : true;
                        if ($isActive && !empty($a['openai_api_key'])) $shouldAiReply = true;
                    }
                    if (!$shouldAiReply) {
                        $stB = $conn->prepare("SELECT ai_enabled FROM bot_settings WHERE company_id=? LIMIT 1");
                        if ($stB) { $stB->bind_param('i', $companyId); $stB->execute(); $b = $stB->get_result()->fetch_assoc(); if ((int)($b['ai_enabled'] ?? 0) === 1) $shouldAiReply = true; }
                    }
                    $prompt = trim((string)$content);
                    if ($shouldAiReply && $prompt !== '') {
                        $q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at) VALUES (?, ?, 'ai_reply', ?, 'pending', NOW(), NOW())");
                        if ($q) { $q->bind_param('iis', $companyId, $contactId, $prompt); if ($q->execute()) $queuedId = (int)$conn->insert_id; }
                    }
                } catch (\Throwable $e) {
                    error_log('[WAAMINI AI] legacy enqueue failed: ' . $e->getMessage());
                }
            }
        }
        
        error_log("[WAAMINI WEBHOOK] Reached drip trigger point for contact=$contactId");
        // 6. Trigger matching drips on EVERY incoming message
        // Drips schedule future follow-ups, so they should run even if an instant reply was sent.
        if (file_exists(__DIR__ . '/includes/drip_engine.php')) {
            error_log("[WAAMINI WEBHOOK] drip_engine.php found, calling trigger_drip_campaigns");
            require_once __DIR__ . '/includes/drip_engine.php';
            trigger_drip_campaigns($conn, (int)$companyId, (int)$contactId);
        } else {
            error_log("[WAAMINI WEBHOOK] drip_engine.php NOT found at " . __DIR__ . '/includes/drip_engine.php');
        }

        // 5. Mark as read (Safely call your real class if it exists)
        if ($this->waApi && method_exists($this->waApi, 'markAsRead')) {
            try {
                $this->waApi->markAsRead($messageId);
            } catch (Exception $e) {
                error_log('[WA API ERROR] ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Fire the AI queue worker as a detached background process (non-blocking).
     * Windows: start /B via popen. Linux: nohup &. Never throws.
     */
    private function triggerAiWorker(): void {
        try {
            $worker = __DIR__ . '/process_ai_queue.php';
            if (!file_exists($worker)) return;

            // Resolve PHP CLI binary (under Apache, PHP_BINARY is not the CLI)
            $php = 'php';
            $candidates = [
                'C:\\xampp\\php\\php.exe',
                PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
                PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
            ];
            foreach ($candidates as $c) {
                if (@is_file($c)) { $php = $c; break; }
            }

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > NUL 2>&1';
                $h = @popen($cmd, 'r');
                if (is_resource($h)) @pclose($h);
            } else {
                @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
            }
            error_log('[WEBHOOK] AI worker triggered in background');
        } catch (\Throwable $e) {
            error_log('[WEBHOOK] AI worker trigger failed (cron will pick up): ' . $e->getMessage());
        }
    }

    /**
     * Human-readable text from WhatsApp Flow (nfm_reply) for inbox + CRM notes.
     */
    private function formatWhatsAppFlowResponse(array $message): string {
        $nfm = $message['interactive']['nfm_reply'] ?? [];
        $raw = (string)($nfm['response_json'] ?? '');
        if ($raw === '') {
            return "📋 WhatsApp Flow submission (empty payload)";
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return "📋 WhatsApp Flow submission: " . $raw;
        }
        $lines = ["📋 WhatsApp Flow submission"];
        foreach ($parsed as $k => $v) {
            $key = is_string($k) ? $k : (string)$k;
            if (is_array($v)) {
                $lines[] = $key . ": " . json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $val = (string)$v;
                if (function_exists('mb_strlen') && mb_strlen($val) > 800) {
                    $val = mb_substr($val, 0, 800) . '…';
                } elseif (strlen($val) > 800) {
                    $val = substr($val, 0, 800) . '…';
                }
                $lines[] = $key . ": " . $val;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Append Flow submission to contact notes (CRM timeline).
     */
    private function appendContactFlowNotes(int $companyId, int $contactId, string $text): void {
        global $conn;
        $text = trim($text);
        if ($text === '' || $contactId <= 0 || $companyId <= 0) {
            return;
        }
        try {
            $conn->query("ALTER TABLE contacts ADD COLUMN notes TEXT DEFAULT NULL");
        } catch (\Throwable $e) {
            /* column exists */
        }
        $block = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r\n", "\r"], "\n", $text);
        if (function_exists('mb_strlen') && mb_strlen($block) > 12000) {
            $block = mb_substr($block, 0, 12000) . "\n… (truncated)";
        }
        $st = $conn->prepare("SELECT COALESCE(notes,'') AS n FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        if (!$st) {
            return;
        }
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $existing = (string)($row['n'] ?? '');
        $newNotes = $existing === '' ? $block : $existing . "\n\n" . $block;
        if (function_exists('mb_strlen') && mb_strlen($newNotes) > 65000) {
            $newNotes = mb_substr($newNotes, -65000);
        }
        $up = $conn->prepare("UPDATE contacts SET notes=? WHERE id=? AND company_id=?");
        if ($up) {
            $up->bind_param('sii', $newNotes, $contactId, $companyId);
            $up->execute();
        }
    }

    /**
     * Which company receives this message. Only companies that have this WhatsApp phone number ID saved
     * (whatsapp_config) are ever considered, so a message can never land in an unrelated tenant.
     *  1. ?company_id= on the callback URL, when that company owns the number.
     *  2. The only company that owns the number.
     *  3. Several companies share the number (test setups, re-saved settings): the one that already chats
     *     with this customer most recently, else the one whose WhatsApp settings were saved last. Logged as
     *     a warning, never rejected, so customer messages are not lost.
     */
    private function resolveCompanyId(array $value, string $from = ''): int {
        global $conn;
        $phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
        if ($phoneNumberId === '') return 0;

        $owners = [];   // company_id => last time its WhatsApp settings were saved, newest first
        $st = $conn->prepare("SELECT company_id, MAX(updated_at) AS u FROM whatsapp_config
                              WHERE phone_number_id = ? GROUP BY company_id ORDER BY u DESC, company_id ASC");
        if (!$st) return 0;
        $st->bind_param('s', $phoneNumberId);
        if (!$st->execute()) { $st->close(); return 0; }
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $owners[(int)$r['company_id']] = (string)$r['u'];
        $st->close();
        if (!$owners) return 0;

        $qid = (int)($_GET['company_id'] ?? 0);
        if ($qid > 0) {
            if (isset($owners[$qid])) return $qid;
            error_log("[WAAMINI WEBHOOK] company_id=$qid in the callback URL does not own phone_number_id=$phoneNumberId; using the company that does.");
        }
        if (count($owners) === 1) return (int)array_key_first($owners);

        // Shared number: prefer the company already talking to this customer.
        $digits = preg_replace('/\D+/', '', $from);
        if (strlen($digits) >= 10) {
            $ids = array_keys($owners);
            $in = implode(',', array_map('intval', $ids));
            $last10 = substr($digits, -10);
            $sc = $conn->prepare("SELECT company_id FROM contacts
                                  WHERE company_id IN ($in) AND RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''), 10) = ?
                                  ORDER BY (last_message_at IS NULL) ASC, last_message_at DESC, id DESC LIMIT 1");
            if ($sc) {
                $sc->bind_param('s', $last10);
                if ($sc->execute() && ($row = $sc->get_result()->fetch_assoc())) {
                    $sc->close();
                    error_log("[WAAMINI WEBHOOK] WARNING: phone_number_id=$phoneNumberId is saved in companies " . implode(',', $ids) . "; routed to company {$row['company_id']} (existing chat with this customer).");
                    return (int)$row['company_id'];
                }
                $sc->close();
            }
        }
        $pick = (int)array_key_first($owners);
        error_log("[WAAMINI WEBHOOK] WARNING: phone_number_id=$phoneNumberId is saved in companies " . implode(',', array_keys($owners)) . "; routed to company $pick (WhatsApp settings saved most recently). Keep one number in one company to avoid this.");
        return $pick;
    }

    private function ensureContactExists(int $companyId, string $phone, array $value): int {
        global $conn;
        static $colCache = null;
        if (!is_array($colCache)) {
            $colCache = ['contacts' => [], 'messages' => []];
            try {
                $r1 = $conn->query("SHOW COLUMNS FROM contacts");
                while ($r1 && ($c = $r1->fetch_assoc())) {
                    $colCache['contacts'][strtolower((string)$c['Field'])] = true;
                }
            } catch (\Throwable $e) {}
            try {
                $r2 = $conn->query("SHOW COLUMNS FROM messages");
                while ($r2 && ($c = $r2->fetch_assoc())) {
                    $colCache['messages'][strtolower((string)$c['Field'])] = true;
                }
            } catch (\Throwable $e) {}
        }

        $phoneNorm = preg_replace('/[^0-9]/', '', ltrim((string)$phone, '+'));
        $phonePlus = '+' . $phoneNorm;
        $name = $phoneNorm;
        if (isset($value['contacts'][0]['profile']['name'])) {
            $name = htmlspecialchars((string)$value['contacts'][0]['profile']['name'], ENT_QUOTES, 'UTF-8');
        }

        // Match both stored formats (with and without +) to avoid duplicating contacts
        $sel = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND (phone=? OR phone=?) LIMIT 1");
        if ($sel) {
            $sel->bind_param('iss', $companyId, $phoneNorm, $phonePlus);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            if ($row && isset($row['id'])) {
                $cid = (int)$row['id'];
                $upd = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=? AND company_id=?");
                if ($upd) {
                    $upd->bind_param('ii', $cid, $companyId);
                    $upd->execute();
                }
                // Fill in the WhatsApp profile name when the saved name is still empty or just the phone number
                // (a name edited by the business is never overwritten).
                if ($name !== $phoneNorm && $name !== '') {
                    $nm = $conn->prepare("UPDATE contacts SET name=? WHERE id=? AND company_id=? AND (name IS NULL OR name='' OR name=? OR name=?)");
                    if ($nm) {
                        $nm->bind_param('siiss', $name, $cid, $companyId, $phoneNorm, $phonePlus);
                        $nm->execute();
                    }
                }
                return $cid;
            }
        }

        // Insert contact with maximum compatibility across schemas.
        $hasSource = isset($colCache['contacts']['source']);
        $hasStatus = isset($colCache['contacts']['status']);
        $hasCreated = isset($colCache['contacts']['created_at']);

        if ($hasSource && $hasStatus && $hasCreated) {
            $ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name, source, status, created_at) VALUES (?, ?, ?, 'whatsapp', 'active', NOW())");
            if ($ins) {
                $ins->bind_param('iss', $companyId, $phonePlus, $name);
                if ($ins->execute()) return (int)$conn->insert_id;
            }
        }

        // Fallback: no source column
        if ($hasStatus && $hasCreated) {
            $ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
            if ($ins) {
                $ins->bind_param('iss', $companyId, $phonePlus, $name);
                if ($ins->execute()) return (int)$conn->insert_id;
            }
        }

        // Minimal fallback
        $ins = $conn->prepare("INSERT INTO contacts (company_id, phone, name) VALUES (?, ?, ?)");
        if (!$ins) return 0;
        $ins->bind_param('iss', $companyId, $phonePlus, $name);
        if (!$ins->execute()) {
            error_log('[WAAMINI WEBHOOK] contacts insert failed: ' . $ins->error);
            return 0;
        }
        return (int)$conn->insert_id;
    }
    
    /**
     * Update Message Status (Sent/Delivered/Read/Failed)
     */
    private function processStatus(array $status): void {
        global $conn;
        $messageId = $status['id'] ?? '';
        $statusVal = $status['status'] ?? '';
        $timestamp = date('Y-m-d H:i:s', (int)($status['timestamp'] ?? time()));
        
        if (empty($messageId) || empty($statusVal)) return;
        
        $validStatuses = ['sent', 'delivered', 'read', 'failed'];
        if (!in_array($statusVal, $validStatuses)) return;
        
        // Update outgoing message status by whatsapp_message_id (avoid non-existent columns like updated_at)
        $upd = $conn->prepare("UPDATE messages SET status=? WHERE whatsapp_message_id=?");
        if ($upd) {
            $upd->bind_param('ss', $statusVal, $messageId);
            $upd->execute();
        }

        // Keep WhatsApp's reason for a failure (e.g. 131053 media download failed), so the chat can say why.
        if ($statusVal === 'failed' && !empty($status['errors'][0]) && is_array($status['errors'][0])) {
            $err = $status['errors'][0];
            $errCode = (string)($err['code'] ?? '');
            $errText = trim((string)($err['title'] ?? '') . ': ' . (string)($err['error_data']['details'] ?? $err['message'] ?? ''), ': ');
            $errText = mb_substr($errText, 0, 250);
            error_log('[WAAMINI webhook] message ' . $messageId . ' failed ' . $errCode . ' ' . $errText);
            try {
                $updE = $conn->prepare("UPDATE messages SET error_code=?, error_message=? WHERE whatsapp_message_id=?");
                if ($updE) {
                    $updE->bind_param('sss', $errCode, $errText, $messageId);
                    $updE->execute();
                }
            } catch (\Throwable $e) { /* older DBs without the columns */ }
        }

        // Campaign delivery tracking (optional; safe on older DBs)
        try {
            foreach ([
                "ALTER TABLE campaign_queue ADD COLUMN whatsapp_message_id VARCHAR(120) DEFAULT NULL",
                "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_status VARCHAR(20) DEFAULT NULL",
                "ALTER TABLE campaign_queue ADD COLUMN wa_delivery_ts DATETIME DEFAULT NULL",
                "ALTER TABLE campaign_queue ADD COLUMN retry_count INT DEFAULT 0",
                "ALTER TABLE campaign_queue ADD COLUMN retry_after DATETIME DEFAULT NULL",
                "ALTER TABLE campaign_queue ADD COLUMN last_error_message VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE campaigns ADD COLUMN expired_count INT DEFAULT 0",
            ] as $_alterSql) {
                try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
            }
            unset($_alterSql);

            // Update campaign_queue row if this message belongs to a campaign send
            $newStatus = $statusVal; // sent/delivered/read/failed
            $updQ = $conn->prepare("UPDATE campaign_queue
                SET wa_delivery_status=?, wa_delivery_ts=?
                WHERE whatsapp_message_id=? AND (wa_delivery_status IS NULL OR wa_delivery_status<>?)");
            if ($updQ) {
                $updQ->bind_param('ssss', $newStatus, $timestamp, $messageId, $newStatus);
                $updQ->execute();
                $changed = (int)$conn->affected_rows;
                if ($changed > 0) {
                    // VULN-013-030 FIX: Replaced raw queries with prepared statements for aggregate counters
                    if ($newStatus === 'delivered') {
                        $updC = $conn->prepare("UPDATE campaigns c
                            JOIN campaign_queue q ON q.campaign_id=c.id AND q.company_id=c.company_id
                            SET c.delivered_count = c.delivered_count + 1
                            WHERE q.whatsapp_message_id=? LIMIT 1");
                        if ($updC) {
                            $updC->bind_param('s', $messageId);
                            $updC->execute();
                        }
                    } elseif ($newStatus === 'read') {
                        $updC = $conn->prepare("UPDATE campaigns c
                            JOIN campaign_queue q ON q.campaign_id=c.id AND q.company_id=c.company_id
                            SET c.read_count = c.read_count + 1
                            WHERE q.whatsapp_message_id=? LIMIT 1");
                        if ($updC) {
                            $updC->bind_param('s', $messageId);
                            $updC->execute();
                        }
                    } elseif ($newStatus === 'failed') {
                        // Mark queue as failed too (keeps UI consistent). WhatsApp already accepted this message and then
                        // could not deliver it, so it is final: no automatic resend (retry_count = 3), keep Meta's reason.
                        $failReason = mb_substr(trim((string)($status['errors'][0]['title'] ?? '') . ': ' . (string)($status['errors'][0]['error_data']['details'] ?? $status['errors'][0]['message'] ?? ''), ': '), 0, 250);
                        $updQ2 = $conn->prepare("UPDATE campaign_queue SET status='failed', retry_count=3, retry_after=NULL, last_error_message=? WHERE whatsapp_message_id=? LIMIT 1");
                        if ($updQ2) {
                            $updQ2->bind_param('ss', $failReason, $messageId);
                            $updQ2->execute();
                        }
                        
                        $updC = $conn->prepare("UPDATE campaigns c
                            JOIN campaign_queue q ON q.campaign_id=c.id AND q.company_id=c.company_id
                            SET c.failed_count = c.failed_count + 1
                            WHERE q.whatsapp_message_id=? LIMIT 1");
                        if ($updC) {
                            $updC->bind_param('s', $messageId);
                            $updC->execute();
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[WAAMINI Campaign] status tracking failed: ' . $e->getMessage());
        }
    }
}

// ============ MAIN EXECUTION ============
if (!defined('WEBHOOK_INCLUDED')) {
    define('WEBHOOK_INCLUDED', true);
    $handler = new WebhookHandler();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    switch ($method) {
        case 'GET':  $handler->verify(); break;
        case 'POST': $handler->handleEvent(); break;
        default:
            http_response_code(405);
            exit(json_encode(['error' => 'Method not allowed']));
    }
}
