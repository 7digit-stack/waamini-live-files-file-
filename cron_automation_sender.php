<?php
/**
 * WAAMINI Advanced CRM Automation Sender
 * This script runs as a cron job to process the automation_queue.
 * It sends targeted messages based on lead status cycles.
 */

// Queue mode: enqueue instead of executing inline (worker executes with WAAMINI_QUEUE_WORKER=1)
if (!defined('WAAMINI_QUEUE_WORKER')) {
    require_once __DIR__ . '/config/bootstrap.php';
    require_once __DIR__ . '/db.php';

    // HTTP guard (optional)
    if (php_sapi_name() !== 'cli') {
        $cronKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '';
        $provided = $_GET['key'] ?? '';
        if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
            http_response_code(403);
            exit('Unauthorized');
        }
    }

    $payload = json_encode(['enqueued_at' => date('c')], JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO queue_jobs (type, payload_json, status, run_at, attempts, max_attempts) VALUES ('cron_automation_sender', ?, 'pending', NOW(), 0, 3)");
    if ($stmt) {
        $stmt->bind_param('s', $payload);
        $stmt->execute();
    }
    echo "Enqueued cron_automation_sender\n";
    exit;
}

// Load DB first — CRON_SECRET_KEY is defined inside db.php
if (!isset($conn)) {
    include 'db.php';
}

// Encryption helpers (decrypt stored secrets like tokens / AI keys)
if (file_exists(__DIR__ . '/services/EncryptionService.php')) {
    require_once __DIR__ . '/services/EncryptionService.php';
} elseif (file_exists(__DIR__ . '/../services/EncryptionService.php')) {
    require_once __DIR__ . '/../services/EncryptionService.php';
}

// WhatsApp settings helpers (waamini_whatsapp_decrypt_config_token, etc.)
if (file_exists(__DIR__ . '/includes/whatsapp_settings.php')) {
    require_once __DIR__ . '/includes/whatsapp_settings.php';
}

// CLI Guard — only enforce key when this file is accessed directly via HTTP (not included by another script)
$isCLI          = (php_sapi_name() === 'cli');
$isDirectAccess = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__);

if (!$isCLI && $isDirectAccess) {
    $cronKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '';
    $provided = $_GET['key'] ?? '';
    if (empty($cronKey) || !hash_equals($cronKey, $provided)) {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
}

set_time_limit(55);
ini_set('memory_limit', '256M');

// Safe include monitor_handler
if (file_exists(__DIR__ . '/includes/monitor_handler.php')) {
    include 'includes/monitor_handler.php';
}

// AI usage guard (daily cap)
if (file_exists(__DIR__ . '/includes/ai_usage.php')) {
    require_once __DIR__ . '/includes/ai_usage.php';
}

if (!function_exists('pulse_log_performance')) {
    function pulse_log_performance() { /* stub */ }
}

if (!function_exists('waamini_gemini_extract_text')) {
    /** @param mixed $resJson decoded Gemini generateContent JSON */
    function waamini_gemini_extract_text($resJson): ?string {
        if (!is_array($resJson)) {
            return null;
        }
        foreach ($resJson['candidates'] ?? [] as $cand) {
            foreach ($cand['content']['parts'] ?? [] as $part) {
                if (!empty($part['text']) && trim((string)$part['text']) !== '') {
                    return trim((string)$part['text']);
                }
            }
        }

        return null;
    }
}

if (!function_exists('waamini_gemini_log_meta')) {
    /** Log safety blocks / empty candidates so admins can grep error_log */
    function waamini_gemini_log_meta($resJson, int $companyId): void {
        if (!is_array($resJson)) {
            return;
        }
        $block = (string)($resJson['promptFeedback']['blockReason'] ?? '');
        $parts = [];
        foreach ($resJson['candidates'] ?? [] as $c) {
            $parts[] = (string)($c['finishReason'] ?? '');
        }
        $fin = implode(',', array_filter($parts));
        if ($block !== '' || $fin !== '') {
            error_log('[WAAMINI AI] Gemini meta company=' . $companyId . ' blockReason=' . ($block ?: 'none') . ' finishReason=' . ($fin ?: 'none'));
        }
    }
}

$debug = ($isCLI && (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'local'));
if ($debug) {
    echo "Pulse Automation Engine Started: " . date('Y-m-d H:i:s') . "\n";
}

// Festival wishes that are due get queued first, so they go out in this same run (each contact once).
try {
    require_once __DIR__ . '/includes/festival_engine.php';
    festival_dispatch_due($conn);
} catch (\Throwable $e) {
    error_log('[festival] ' . $e->getMessage());
}

// Fetch pending automations that are due
// LEFT JOIN contacts so rows with contact_id still work; for AI jobs we also support using q.phone if contact is missing.
$now = date('Y-m-d H:i:s');

// Always compute raw pending count (useful even when not in debug)
$rawPending = 0;
$rawStmt = $conn->prepare("SELECT COUNT(*) as c FROM automation_queue WHERE status='pending' AND (scheduled_at IS NULL OR scheduled_at <= ?)");
if ($rawStmt) {
    $rawStmt->bind_param('s', $now);
    $rawStmt->execute();
    $rawRes = $rawStmt->get_result();
    $rawRow = $rawRes ? $rawRes->fetch_assoc() : null;
    $rawPending = (int)($rawRow['c'] ?? 0);
    if ($debug) {
        echo "Raw pending items (before JOIN): $rawPending\n";
    }
}

$sql = "SELECT q.*,
               COALESCE(c.phone, q.phone, '') AS phone,
               COALESCE(c.name, 'Customer') AS contact_name,
               w.access_token, w.phone_number_id
          FROM automation_queue q
          LEFT JOIN contacts c ON q.contact_id = c.id
          JOIN whatsapp_config w ON q.company_id = w.company_id
         WHERE q.status = 'pending'
           AND (q.scheduled_at IS NULL OR q.scheduled_at <= ?)
           AND w.access_token IS NOT NULL AND w.access_token != ''
           AND COALESCE(c.phone, q.phone) IS NOT NULL
           AND COALESCE(c.phone, q.phone) != ''
         LIMIT 50";
$stmt = $conn->prepare($sql);
$res = null;
if ($stmt) {
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $res = $stmt->get_result();
}

if ($res && $res->num_rows > 0) {
    if ($debug) {
        echo "Found " . $res->num_rows . " messages to send.\n";
    }

    while ($row = $res->fetch_assoc()) {
        $qid = (int)$row['id'];
        $phone = (string)($row['phone'] ?? '');
        $type = $row['type'];
        // Legacy bug: webhook once bound queue `type` as integer; MySQL stored 0 instead of 'ai_reply', so cron echoed user text.
        if ($type === 0 || $type === '0') {
            $type = 'ai_reply';
        }
        $message = $row['message'];
        $mediaUrl = $row['media_url'] ?? null;
        // Decrypt token if encrypted
        $token = waamini_whatsapp_decrypt_config_token($row);
        $phoneId = $row['phone_number_id'];
        $cid = (int)($row['contact_id'] ?? 0);
        $companyId = (int)($row['company_id'] ?? 0);
        $contactName = (string)($row['contact_name'] ?? 'Customer');

        // Skip if no phone number available
        if (empty($phone)) {
            if ($debug) {
                echo "Skipping Queue ID #$qid — no phone number.\n";
            }
            $failStmt = $conn->prepare("UPDATE automation_queue SET status='failed' WHERE id=?");
            if ($failStmt) {
                $failStmt->bind_param('i', $qid);
                $failStmt->execute();
            }
            continue;
        }

        if ($debug) {
            echo "Processing Queue ID #$qid for Contact $phone" . ($mediaUrl ? " (with media)" : "") . "...\n";
        }

        // Demo requests must use the real Calendar booking workflow before
        // the configurable legacy sales prompt can promise a manual handoff.
        if ($type === 'ai_reply' && preg_match('/\b(?:demo|meeting|meet|appointment|link|url)\b/i', (string)$message)) {
            require_once __DIR__ . '/waamini_ai_agent.php';
            $demoAgent = new WaaminiAiAgent($conn, $companyId, 'deterministic-demo');
            $demoReply = trim($demoAgent->handleMessage($phone, (string)$message));
            if ($demoReply !== '') {
                require_once __DIR__ . '/includes/whatsapp_api_helper.php';
                $demoMsgId = send_whatsapp_text($phone, $demoReply, $token, $phoneId);
                if ($demoMsgId) {
                    $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, whatsapp_message_id, created_at) VALUES (?, ?, 'outgoing', 'text', ?, ?, 'sent', ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param('iisss', $companyId, $cid, $demoReply, $demoReply, $demoMsgId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $sentStmt = $conn->prepare("UPDATE automation_queue SET status='sent' WHERE id=?");
                    if ($sentStmt) { $sentStmt->bind_param('i', $qid); $sentStmt->execute(); $sentStmt->close(); }
                } else {
                    $failStmt = $conn->prepare("UPDATE automation_queue SET status='failed', error_msg=? WHERE id=?");
                    if ($failStmt) { $sendErr = 'demo_reply_send_failed'; $failStmt->bind_param('si', $sendErr, $qid); $failStmt->execute(); $failStmt->close(); }
                }
                continue;
            }
        }

        // ── AI Reply Handler (Gemini + OpenAI via bot_settings, legacy OpenAI via ai_bot_settings) ──
        if ($type === 'ai_reply') {
            // Backwards-compat: older installs may have an incomplete ai_bot_settings schema.
            // Make it compatible with the premium AI bot UI and the query below.
            try {
                $alterStatements = [
                    "ALTER TABLE ai_bot_settings ADD COLUMN is_active TINYINT(1) DEFAULT 0",
                    "ALTER TABLE ai_bot_settings ADD COLUMN bot_persona TEXT DEFAULT NULL",
                    "ALTER TABLE ai_bot_settings ADD COLUMN fallback_message TEXT DEFAULT NULL",
                ];
                foreach ($alterStatements as $sqlAlter) {
                    try { $conn->query($sqlAlter); } catch (\Throwable $e) { /* column exists */ }
                }
            } catch (\Throwable $e) {
                // If the table itself doesn't exist, later queries will just fail and be handled.
            }

            // Load config — prefer bot_settings (settings_bot.php), fall back to ai_bot_settings
            $aiProvider  = null;
            $aiApiKey    = null;
            $aiModel     = null;
            $systemPrompt = <<<'PROMPT'
You are a smart, friendly WhatsApp sales assistant for this business.

━━━ RULE #1 — LANGUAGE & TONE (MOST IMPORTANT) ━━━
ALWAYS reply in the EXACT SAME language and tone the customer used.
• Customer texts in Tanglish (Tamil words in English letters like "venum", "sollu", "vachuruken", "na", "da", "bro") → You MUST reply in Tanglish. Never reply in formal English to a Tanglish customer.
• Customer texts in Tamil script → reply fully in Tamil script.
• Customer texts in Hinglish → reply in Hinglish.
• Customer texts in English → reply in clear, casual English (not corporate/formal).
• NEVER switch to formal English when the customer is being casual.

━━━ RULE #2 — SALES ASSISTANT BEHAVIOUR ━━━
• You are a friendly sales team member, NOT a corporate chatbot.
• Be warm, helpful, and human — like a colleague, not a brochure.
• Guide the customer to the next step (see demo, check pricing, place order).
• Ask ONE smart qualifying question per reply to understand their need better.
• Keep replies SHORT: 2–4 lines max. WhatsApp style, not email style.

━━━ RULE #3 — PRODUCTS & PRICING ━━━
• Never make up prices or product specs you don't know.
• If customer asks what you sell → give a brief 1-sentence pitch → direct them to type *products* to see live catalog with prices.
• Focus on ROI and benefits, not features lists.

━━━ RULE #4 — HONESTY ━━━
• If you don't know something, say so honestly and offer to connect them to the team.
• Never invent policies, addresses, or delivery times.
PROMPT;
            $maxTokens   = 500;

            $fallbackMsg = "I'm having trouble reaching the AI assistant right now. To see our catalog and prices, reply with *products* or */products*. If this keeps happening, check AI keys and daily limits in Bot settings.";
            $fallbackDailyMsg = "Today's AI reply limit has been reached for this account. Reply *products* or */products* for our catalog. Ask your admin to raise AI_DAILY_REQUEST_LIMIT or check usage in Premium AI Bot.";
            $demoAlertNumbers = '';

            // Priority 1: bot_settings table (Gemini / OpenAI / OpenRouter multi-provider)
            $aiApiKey = '';
            $aiProvider = '';
            $aiModel = '';
            try {
                $bsRes = null;
                $bsStmt = $conn->prepare("SELECT * FROM bot_settings WHERE company_id=? AND ai_enabled=1 LIMIT 1");
                if ($bsStmt) {
                    $bsStmt->bind_param('i', $companyId);
                    $bsStmt->execute();
                    $bsRes = $bsStmt->get_result();
                }
                if ($bsRes) {
                    $bsData = $bsRes->fetch_assoc();
                    if ($bsData) {
                        $aiProvider   = strtolower(trim((string)($bsData['ai_provider'] ?? '')));
                        $aiModel      = trim((string)($bsData['ai_model'] ?? ''));
                        $systemPrompt = !empty($bsData['system_prompt']) ? $bsData['system_prompt'] : $systemPrompt;
                        $maxTokens    = intval($bsData['max_tokens'] ?? 500) ?: 500;
                        $demoAlertNumbers = (string)($bsData['demo_alert_number'] ?? '');

                        if ($aiProvider === 'openrouter') {
                            $orKeyRaw = trim((string)($bsData['openrouter_api_key'] ?? ''));
                            $aiApiKey = $orKeyRaw;
                            if (!empty($orKeyRaw) && function_exists('decrypt_secret')) {
                                $dec = decrypt_secret($orKeyRaw);
                                if ($dec !== null && $dec !== '') {
                                    $aiApiKey = $dec;
                                }
                            }
                        } else {
                            $aiApiKey = trim((string)($bsData['ai_api_key'] ?? ''));
                            if (!empty($aiApiKey) && function_exists('decrypt_secret')) {
                                $dec = decrypt_secret($aiApiKey);
                                if ($dec !== null && $dec !== '') {
                                    $aiApiKey = $dec;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) { /* bot_settings may not exist */ }

            if ($aiProvider === '') {
                $aiProvider = 'gemini';
            }

            error_log('[WAAMINI AI] company=' . $companyId . ' provider=' . $aiProvider . ' model=' . $aiModel . ' keyLength=' . strlen($aiApiKey));

            // Priority 2: ai_bot_settings (legacy OpenAI-only premium bot)
            if (!$aiApiKey) {
                try {
                    $asRes = null;
                    $asStmt = $conn->prepare("SELECT * FROM ai_bot_settings WHERE company_id=? AND is_active=1 LIMIT 1");
                    if ($asStmt) {
                        $asStmt->bind_param('i', $companyId);
                        $asStmt->execute();
                        $asRes = $asStmt->get_result();
                    }
                    if ($asRes) {
                        $asData = $asRes->fetch_assoc();
                        if ($asData && !empty($asData['openai_api_key'])) {
                            $aiProvider   = 'openai';
                            $aiApiKey     = $asData['openai_api_key'];
                            if (!empty($aiApiKey) && function_exists('decrypt_secret')) {
                                $dec = decrypt_secret((string)$aiApiKey);
                                if ($dec !== null && $dec !== '') {
                                    $aiApiKey = $dec;
                                }
                            }
                            $aiModel      = 'gpt-4o-mini';
                            $systemPrompt = !empty($asData['bot_persona']) ? $asData['bot_persona'] : $systemPrompt;
                            $fallbackMsg  = $asData['fallback_message'] ?? $fallbackMsg;
                        }
                    }
                } catch (\Exception $e) { /* ai_bot_settings may not exist */ }
            }

            if ($aiApiKey !== null && trim((string)$aiApiKey) !== '') {
                $aiApiKey = trim((string)$aiApiKey);
                $aiProvider = strtolower(trim((string)($aiProvider ?? '')));
                if ($aiProvider === '') {
                    $aiProvider = 'gemini';
                }
            }

            // Do NOT append redundant constraints when the prompt already has structured rules
            // (our new default prompt, admin custom prompts, or legacy strict prompts).
            $hasStrictAdminPrompt = (stripos($systemPrompt, 'OUTPUT RULES (NON-NEGOTIABLE') !== false)
                || (stripos($systemPrompt, 'OUTPUT (NON-NEGOTIABLE') !== false)
                || (stripos($systemPrompt, 'Elite AI Sales Closer') !== false)
                || (stripos($systemPrompt, 'LANGUAGE (MIRROR THE USER)') !== false)
                || (stripos($systemPrompt, 'LANGUAGE RULES') !== false)
                || (stripos($systemPrompt, 'MIRROR THE USER') !== false)
                || (stripos($systemPrompt, 'RULE #1') !== false)
                || (stripos($systemPrompt, 'STRICT LANGUAGE') !== false)
                || (stripos($systemPrompt, 'NON-NEGOTIABLE') !== false);

            if (!$hasStrictAdminPrompt) {
                // Minimal fallback for very short custom prompts
                $systemPrompt .= "\n\nReply in the same language and tone the customer used. Keep it to 2-3 short WhatsApp-style sentences.";
            }


            require_once __DIR__ . '/includes/ai_language_reply.php';
            $pj = null;
            $pjRaw = $row['payload_json'] ?? null;
            if ($pjRaw !== null && $pjRaw !== '') {
                $decoded = json_decode((string)$pjRaw, true);
                $pj = is_array($decoded) ? $decoded : null;
            }
            $resolvedIso = null;
            if ($pj !== null && !empty($pj['input_lang'])) {
                $resolvedIso = strtolower(substr((string)$pj['input_lang'], 0, 5));
            }
            if ($resolvedIso === null || $resolvedIso === '') {
                $hint = ($pj !== null && !empty($pj['whisper_lang'])) ? (string)$pj['whisper_lang'] : null;
                $resolvedIso = waamini_resolve_reply_language_iso((string)$message, $hint);
            }

            // CRITICAL: PREPEND language instruction at the TOP of the prompt.
            // LLMs give highest priority to instructions at the BEGINNING.
            // Appending at the end of a 10K+ char prompt causes the model to ignore it.
            $langInstruction = waamini_strict_language_instruction($resolvedIso);
            if ($langInstruction !== '') {
                $systemPrompt = $langInstruction . "\n\n" . $systemPrompt;
            }

            if ($aiApiKey && $aiProvider) {
                // Daily cap — do not call LLM when over limit (saves cost + avoids overwriting fallback)
                $skipLlm = false;
                if (function_exists('ai_usage_consume')) {
                    $ok = ai_usage_consume($conn, $companyId, 1);
                    if (!$ok) {
                        $limit = function_exists('ai_daily_limit') ? ai_daily_limit() : 0;
                        $msg = "AI daily limit reached (" . (int)$limit . "/day) company_id=" . (int)$companyId . ". Auto-reply uses daily-limit message.";
                        error_log('[WAAMINI AI] ' . $msg);
                        if (function_exists('log_pulse_error')) {
                            try { log_pulse_error('AI Rate Limit', $msg, __FILE__, __LINE__); } catch (\Throwable $e) {}
                        }
                        $message = $fallbackDailyMsg;
                        $skipLlm = true;
                    }
                }

                $aiReply = null;

                if (!$skipLlm) {
                // Build recent chat history (last 10 messages for context)
                $history = [];
                try {
                    $ctxRes = null;
                    if ($cid > 0) {
                        $ctxStmt = $conn->prepare("SELECT direction, COALESCE(content, message, '') AS message FROM messages WHERE company_id=? AND contact_id=? ORDER BY id DESC LIMIT 10");
                        if ($ctxStmt) {
                            $ctxStmt->bind_param('ii', $companyId, $cid);
                            $ctxStmt->execute();
                            $ctxRes = $ctxStmt->get_result();
                        }
                    }
                    if ($ctxRes) {
                        $rows = [];
                        while ($cMsg = $ctxRes->fetch_assoc()) { $rows[] = $cMsg; }
                        $rows = array_reverse($rows); // oldest first
                        foreach ($rows as $cMsg) {
                            if (empty(trim($cMsg['message']))) continue;
                            $role = ($cMsg['direction'] === 'incoming') ? 'user' : 'assistant';
                            // Merge consecutive same-role messages (Gemini requires strict alternation)
                            if (!empty($history) && end($history)['role'] === $role) {
                                $history[count($history)-1]['content'] .= "\n" . $cMsg['message'];
                            } else {
                                $history[] = ['role' => $role, 'content' => $cMsg['message']];
                            }
                        }
                    }
                } catch (\Exception $e) { /* messages table issue */ }

                // Queue row holds the inbound prompt — never send this string as the bot reply if the LLM fails
                $queuedInboundText = (string)$row['message'];

                // Ensure history ends with a user message for this ai_reply job
                if (empty($history) || end($history)['role'] !== 'user') {
                    $history[] = ['role' => 'user', 'content' => $queuedInboundText];
                }

                // CRITICAL: Inject compact language reminder into the final user message.
                // This is the most reliable technique — the AI sees the constraint
                // right next to the user's actual input, not buried in a 10K system prompt.
                if ($resolvedIso !== null && $resolvedIso !== '' && $resolvedIso !== 'en') {
                    $langName = function_exists('waamini_language_display_name')
                        ? waamini_language_display_name($resolvedIso)
                        : strtoupper($resolvedIso);
                    $lastIdx = count($history) - 1;
                    $langReminder = "\n\n[SYSTEM: Customer is writing in {$langName}. You MUST reply in {$langName} — NOT in English. This overrides all other instructions.]";
                    $history[$lastIdx]['content'] .= $langReminder;
                }

                // Ensure history starts with a user message (Gemini requirement)
                while (!empty($history) && $history[0]['role'] !== 'user') {
                    array_shift($history);
                }

                if ($aiProvider === 'gemini') {
                    // ── Gemini API ─────────────────────────────────────────
                    $geminiModel = $aiModel ?: 'gemini-2.0-flash';
                    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$geminiModel}:generateContent?key={$aiApiKey}";

                    // Convert history to Gemini format (user/model alternating)
                    $geminiContents = [];
                    foreach ($history as $h) {
                        $geminiRole = ($h['role'] === 'user') ? 'user' : 'model';
                        $geminiContents[] = ['role' => $geminiRole, 'parts' => [['text' => $h['content']]]];
                    }

                    $geminiPayload = [
                        'contents' => $geminiContents,
                        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                        'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.7]
                    ];

                    $ch = curl_init($geminiUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_POSTFIELDS     => json_encode($geminiPayload),
                        CURLOPT_TIMEOUT        => 15,
                    ]);
                    $response = curl_exec($ch);
                    $curlErr = curl_error($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($curlErr !== '') {
                        error_log('[WAAMINI AI] Gemini curl error company=' . $companyId . ': ' . $curlErr);
                    }

                    if ($httpCode === 200 && $response !== false && $response !== '') {
                        $resJson = json_decode((string)$response, true);
                        if (!is_array($resJson)) {
                            error_log('[WAAMINI AI] Gemini invalid JSON company=' . $companyId . ': ' . substr((string)$response, 0, 300));
                        } else {
                            $gErr = $resJson['error']['message'] ?? '';
                            if ($gErr !== '') {
                                error_log('[WAAMINI AI] Gemini error object company=' . $companyId . ': ' . $gErr);
                            }
                            waamini_gemini_log_meta($resJson, $companyId);
                            $aiReply = waamini_gemini_extract_text($resJson);
                            if ($aiReply === null || trim((string)$aiReply) === '') {
                                error_log('[WAAMINI AI] Gemini no assistant text company=' . $companyId . ' snippet=' . substr(json_encode($resJson), 0, 500));
                            }
                        }
                    } else {
                        $sj = json_decode((string)$response, true);
                        $apiMsg = is_array($sj) ? (string)($sj['error']['message'] ?? '') : '';
                        error_log('[WAAMINI AI] Gemini HTTP ' . $httpCode . ' company=' . $companyId . ' api=' . ($apiMsg ?: 'n/a') . ' body=' . substr((string)$response, 0, 350));
                    }
                } else {
                    // ── OpenAI / OpenRouter API ─────────────────────────────────────────
                    $oaiPayload = [
                        'model'      => $aiModel ?: 'gpt-4o-mini',
                        'messages'   => array_merge(
                            [['role' => 'system', 'content' => $systemPrompt]],
                            $history
                        ),
                        'max_tokens' => $maxTokens,
                    ];

                    $apiUrl = 'https://api.openai.com/v1/chat/completions';
                    $headers = [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $aiApiKey,
                    ];

                    if ($aiProvider === 'openrouter') {
                        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
                        $headers[] = 'HTTP-Referer: https://waamini.com';
                        $headers[] = 'X-Title: WAAMINI CRM';
                    }

                    $ch = curl_init($apiUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_HTTPHEADER     => $headers,
                        CURLOPT_POSTFIELDS     => json_encode($oaiPayload),
                        CURLOPT_TIMEOUT        => 15,
                    ]);
                    $response = curl_exec($ch);
                    $curlErr = curl_error($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $providerLabel = $aiProvider === 'openrouter' ? 'OpenRouter' : 'OpenAI';

                    if ($curlErr !== '') {
                        error_log('[WAAMINI AI] ' . $providerLabel . ' curl error company=' . $companyId . ': ' . $curlErr);
                    }

                    if ($httpCode === 200 && $response !== false && $response !== '') {
                        $resJson = json_decode((string)$response, true);
                        if (!is_array($resJson)) {
                            error_log('[WAAMINI AI] ' . $providerLabel . ' invalid JSON company=' . $companyId . ': ' . substr((string)$response, 0, 300));
                        } else {
                            $aiReply = $resJson['choices'][0]['message']['content'] ?? null;
                            $oaErr = $resJson['error']['message'] ?? '';
                            if ($oaErr !== '') {
                                error_log('[WAAMINI AI] ' . $providerLabel . ' error object company=' . $companyId . ': ' . $oaErr);
                            }
                            if (!$aiReply) {
                                error_log('[WAAMINI AI] ' . $providerLabel . ' empty choices company=' . $companyId . ': ' . substr((string)$response, 0, 350));
                            }
                        }
                    } else {
                        $sj = json_decode((string)$response, true);
                        $apiMsg = is_array($sj) ? (string)($sj['error']['message'] ?? '') : '';
                        error_log('[WAAMINI AI] ' . $providerLabel . ' HTTP ' . $httpCode . ' company=' . $companyId . ' api=' . ($apiMsg ?: 'n/a') . ' body=' . substr((string)$response, 0, 350));
                    }
                }

                // Never echo automation_queue.message (customer text) when AI fails or returns empty
                if ($aiReply !== null && trim((string)$aiReply) !== '') {
                    $message = trim((string)$aiReply);
                } else {
                    $message = $fallbackMsg;
                    error_log('[WAAMINI AI] company=' . $companyId . ' provider=' . $aiProvider . ' model=' . $aiModel . ' using_fallback=1 httpCode=' . (int)($httpCode ?? 0) . ' replyEmpty=' . ($aiReply === null || trim((string)$aiReply) === '' ? 'yes' : 'no'));
                }

                // If AI returned an obvious error/apology phrase, treat it as failure and use fallback
                $lowerMsg = strtolower((string)$message);
                if (preg_match("/(i'?m having trouble|connect you to a human|can'?t help|unable to assist|as an ai language model|not available in your country|content policy|safety guidelines|I cannot|I am unable|error)/i", $lowerMsg)) {
                    $message = $fallbackMsg;
                    error_log('[WAAMINI AI] company=' . $companyId . ' provider=' . $aiProvider . ' model=' . $aiModel . ' filtered_error_reply=1');
                }
                if ($debug) {
                    echo "  AI ($aiProvider) reply: " . substr($message, 0, 80) . "\n";
                }

                } // end !$skipLlm — LLM path

                // If the AI response includes a demo link or confirms a time, alert sales numbers configured in settings_bot.php
                // This is implemented as a real backend notification (not hidden tags in customer chat).
                $shouldNotifySales = false;
                $reason = '';

                // Path C: Demo Link Marker
                if (stripos($message, '[NOTIFY_SALES]') !== false) {
                    $shouldNotifySales = true;
                    $reason = 'demo_link_requested';
                    $message = str_ireplace('[NOTIFY_SALES]', '', $message);
                }

                // Path B: Time Confirmation Marker
                if (preg_match('/\[NOTIFY_SALES:\s*([^\]]+)\]/i', $message, $m)) {
                    $shouldNotifySales = true;
                    $confirmedTime = trim($m[1]);
                    $reason = "demo_time_confirmed ($confirmedTime)";
                    $message = preg_replace('/\[NOTIFY_SALES:\s*[^\]]+\]/i', '', $message);
                }

                // Clean up any trailing whitespace/newlines from markers
                $message = trim($message);

                // Fallback to legacy string detection if no markers found
                if (!$shouldNotifySales) {
                    if (strpos($message, 'https://meet.google.com/your-demo-link') !== false) {
                        $shouldNotifySales = true;
                        $reason = 'demo_link_sent (fallback)';
                    } elseif (stripos($message, 'informed our sales team') !== false) {
                        $shouldNotifySales = true;
                        $reason = 'demo_time_confirmed (fallback)';
                    }
                }
                $demoAlertNumbers = trim($demoAlertNumbers);
                if ($shouldNotifySales && $demoAlertNumbers !== '') {
                    require_once 'includes/whatsapp_api_helper.php';
                    $nums = array_filter(array_map('trim', explode(',', $demoAlertNumbers)));
                    $safeTo = preg_replace('/[^0-9]/', '', (string)$phone);
                    $alertText = "WAAMINI Demo Alert\nReason: {$reason}\nCustomer: {$contactName} ({$safeTo})\nCustomer msg: " . mb_strimwidth((string)$row['message'], 0, 240, '…') . "\nAI reply: " . mb_strimwidth((string)$message, 0, 240, '…');
                    foreach ($nums as $n) {
                        $salesTo = preg_replace('/[^0-9]/', '', $n);
                        if ($salesTo === '') continue;
                        // Send alert using the same WhatsApp business token/phoneId
                        $sentId = send_whatsapp_text($salesTo, $alertText, $token, $phoneId);
                        if (!$sentId) {
                            error_log("[WAAMINI AI] sales alert send failed to {$salesTo}");
                        }
                    }
                }
            } else {
                $err = "AI bot not configured — no API key found in bot_settings or ai_bot_settings.";
                error_log("[WAAMINI AI] $err");
                if ($debug) {
                    echo "  $err\n";
                }
                // Mark failed and skip sending
                $failStmt = $conn->prepare("UPDATE automation_queue SET status='failed' WHERE id=?");
                if ($failStmt) {
                    $failStmt->bind_param('i', $qid);
                    $failStmt->execute();
                }
                continue;
            }
        }
        // ──────────────────────────────────────────────────────
        
        require_once 'includes/whatsapp_api_helper.php';
        if ($type === 'template') {
            // Approved template (e.g. festival wishes): name, language and parameters are in payload_json
            $tp = json_decode((string)($row['payload_json'] ?? ''), true);
            $tp = is_array($tp) ? $tp : [];
            $whatsappMsgId = empty($tp['template_name']) ? null
                : send_whatsapp_template($phone, (string)$tp['template_name'], (string)($tp['language_code'] ?? 'en'), (array)($tp['components'] ?? []), $token, $phoneId);
        } else {
            $whatsappMsgId = send_whatsapp_text($phone, $message, $token, $phoneId);
        }

        if ($whatsappMsgId) {
            // Log message in the messages table
            $msgType = $mediaUrl ? 'image' : 'text';
            $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, whatsapp_message_id, created_at) VALUES (?, ?, 'outgoing', ?, ?, ?, 'sent', ?, NOW())");
            if ($stmt) {
                // Fixed: matched 6 placeholders with 6 types 'iissss' and 6 variables
                $stmt->bind_param("iissss", $companyId, $cid, $msgType, $message, $message, $whatsappMsgId);
                $stmt->execute();
            }

            // Update queue status
            $sentStmt = $conn->prepare("UPDATE automation_queue SET status='sent' WHERE id=?");
            if ($sentStmt) {
                $sentStmt->bind_param('i', $qid);
                $sentStmt->execute();
            }

            // Log in pulse for admin to see
            pulse_log_performance();
        }
        else {
            $failStmt = $conn->prepare("UPDATE automation_queue SET status='failed', error_msg=? WHERE id=?");
            if ($failStmt) {
                $errMsg = 'whatsapp_send_failed';
                $failStmt->bind_param('si', $errMsg, $qid);
                $failStmt->execute();
            }
        }
    }
}

// ─── PREMIUM DRIP CAMPAIGNS PROCESSOR (Production-Grade) ───
if ($debug) echo "Checking Premium Drip Campaigns...\n";
$dripProcessed = 0;
$dripSent = 0;
$dripFailed = 0;
$dripSkipped = 0;

// Atomically claim pending executions with SELECT ... FOR UPDATE to prevent double-sends
// if both cron_drip.php and cron_automation_sender.php run at the same time.
// We claim by setting status='processing' first, then send, then update to 'sent' or 'failed'.
$conn->query("UPDATE drip_executions SET status='processing' WHERE status='pending' AND scheduled_for <= NOW() LIMIT 50");
$claimedCount = $conn->affected_rows;

if ($claimedCount > 0) {
    $dripSql = "
        SELECT e.id as execution_id, e.company_id, e.contact_id, e.drip_step_id,
               e.retry_count,
               c.phone as contact_phone, c.name as contact_name,
               s.template_id, s.message_text,
               dc.status as campaign_status
        FROM drip_executions e
        JOIN contacts c ON e.contact_id = c.id AND c.company_id = e.company_id
        JOIN drip_steps s ON e.drip_step_id = s.id
        JOIN drip_campaigns dc ON s.drip_campaign_id = dc.id
        WHERE e.status = 'processing'
        ORDER BY e.scheduled_for ASC
        LIMIT 50
    ";
    $dripRes = $conn->query($dripSql);

    if ($dripRes && $dripRes->num_rows > 0) {
        if (!function_exists('send_whatsapp_text')) {
            require_once __DIR__ . '/includes/whatsapp_api_helper.php';
        }
        if (!class_exists('WhatsAppService')) {
            @include_once __DIR__ . '/services/WhatsAppService.php';
        }

        $dripCfgCache = [];

        while ($dRow = $dripRes->fetch_assoc()) {
            $dripProcessed++;
            $exId = (int)$dRow['execution_id'];
            $dCompanyId = (int)$dRow['company_id'];
            $dContactId = (int)$dRow['contact_id'];
            $phone = (string)$dRow['contact_phone'];
            $name = (string)$dRow['contact_name'];
            $retryCount = (int)($dRow['retry_count'] ?? 0);

            // Skip if campaign is not active (paused/deleted)
            if (($dRow['campaign_status'] ?? '') !== 'active') {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='campaign_inactive' WHERE id=$exId");
                $dripSkipped++;
                continue;
            }

            // Skip if phone is empty
            if ($phone === '' || strlen(preg_replace('/\D+/', '', $phone)) < 10) {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='invalid_phone' WHERE id=$exId");
                $dripFailed++;
                continue;
            }

            // Check blacklist
            $phoneLast10 = substr(preg_replace('/\D+/', '', $phone), -10);
            $blCheck = $conn->prepare("SELECT id FROM blacklist WHERE company_id=? AND phone LIKE ? LIMIT 1");
            if ($blCheck) {
                $blPat = '%' . $phoneLast10;
                $blCheck->bind_param("is", $dCompanyId, $blPat);
                $blCheck->execute();
                if ($blCheck->get_result()->num_rows > 0) {
                    $blCheck->close();
                    $conn->query("UPDATE drip_executions SET status='failed', error_msg='blacklisted' WHERE id=$exId");
                    $dripSkipped++;
                    continue;
                }
                $blCheck->close();
            }

            // Check feature access for this company
            $hasFeature = false;
            $planStmt = $conn->prepare("
                SELECT sp.feat_drip_campaign
                FROM subscriptions sub
                JOIN subscription_plans sp ON sub.plan_id = sp.id
                WHERE sub.company_id = ?
                  AND sub.status IN ('active','trial')
                  AND (sub.expires_at >= NOW() OR sub.expires_at IS NULL)
                LIMIT 1
            ");
            if ($planStmt) {
                $planStmt->bind_param("i", $dCompanyId);
                $planStmt->execute();
                $planRes = $planStmt->get_result()->fetch_assoc();
                $hasFeature = ($planRes && ($planRes['feat_drip_campaign'] ?? 0) == 1);
                $planStmt->close();
            }
            if (!$hasFeature) {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='no_feature_access' WHERE id=$exId");
                $dripSkipped++;
                continue;
            }

            // Load WhatsApp config (cached per company)
            if (!isset($dripCfgCache[$dCompanyId])) {
                if (class_exists('WhatsAppService') && method_exists('WhatsAppService', 'getConfig')) {
                    $dripCfgCache[$dCompanyId] = WhatsAppService::getConfig($conn, $dCompanyId);
                } else {
                    $stW = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1");
                    if ($stW) {
                        $stW->bind_param('i', $dCompanyId);
                        $stW->execute();
                        $waCfg = $stW->get_result()->fetch_assoc();
                        $stW->close();
                        if ($waCfg) {
                            $waCfg['access_token'] = function_exists('waamini_whatsapp_decrypt_config_token')
                                ? waamini_whatsapp_decrypt_config_token($waCfg)
                                : ($waCfg['access_token'] ?? '');
                        }
                        $dripCfgCache[$dCompanyId] = $waCfg ?: null;
                    }
                }
            }
            $cfg = $dripCfgCache[$dCompanyId] ?? null;
            if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='missing_wa_config' WHERE id=$exId");
                $dripFailed++;
                continue;
            }

            // Build message body — prefer template, fallback to text
            $body = '';
            $tplName = '';
            $tplLang = 'en_US';
            $tplWaStatus = '';
            $templateId = (int)($dRow['template_id'] ?? 0);

            if ($templateId > 0) {
                $stT = $conn->prepare("SELECT name, language, wa_status, body, body_text FROM message_templates WHERE id=? AND (company_id=? OR company_id IS NULL) LIMIT 1");
                if ($stT) {
                    $stT->bind_param('ii', $templateId, $dCompanyId);
                    $stT->execute();
                    $tRow = $stT->get_result()->fetch_assoc();
                    $stT->close();
                    if ($tRow) {
                        $tplName = (string)($tRow['name'] ?? '');
                        $tplLang = (string)($tRow['language'] ?? 'en_US');
                        $tplWaStatus = strtolower(trim((string)($tRow['wa_status'] ?? '')));
                        $body = (string)($tRow['body'] ?? $tRow['body_text'] ?? '');
                    }
                }
            }
            if ($body === '') {
                $body = (string)($dRow['message_text'] ?? '');
            }
            // Variable replacement
            $body = str_replace(['{{1}}', '{{name}}', '{{contact_name}}'], $name, $body);
            $body = trim($body);

            if ($body === '') {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='empty_body' WHERE id=$exId");
                $dripFailed++;
                continue;
            }

            // Set API version
            $apiVer = (string)($cfg['api_version'] ?? ($_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0'));
            $_ENV['WHATSAPP_API_VERSION'] = $apiVer;

            $waMsgId = null;

            // Try template send first (works outside 24-hour window)
            $isApprovedTpl = ($tplName !== '' && in_array($tplWaStatus, ['approved', 'approve'], true));
            if ($isApprovedTpl && function_exists('send_whatsapp_template')) {
                // Build template parameters
                $maxIdx = 0;
                if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
                    foreach ($m[1] as $n) {
                        $i = (int)$n;
                        if ($i > $maxIdx) $maxIdx = $i;
                    }
                }
                $params = [];
                for ($i = 1; $i <= $maxIdx; $i++) {
                    $val = ($i === 1) ? ($name !== '' ? $name : 'there') : '';
                    $params[] = ['type' => 'text', 'text' => $val];
                }
                $components = [];
                if (!empty($params)) {
                    $components[] = ['type' => 'body', 'parameters' => $params];
                }
                $waMsgId = send_whatsapp_template($phone, $tplName, $tplLang ?: 'en_US', $components, (string)$cfg['access_token'], (string)$cfg['phone_number_id']);
            }

            // Fallback to text send (only works within 24-hour window)
            if (!$waMsgId) {
                $waMsgId = send_whatsapp_text($phone, $body, (string)$cfg['access_token'], (string)$cfg['phone_number_id']);
            }

            if ($waMsgId) {
                $upd = $conn->prepare("UPDATE drip_executions SET status='sent', sent_at=NOW(), error_msg=NULL WHERE id=?");
                if ($upd) { $upd->bind_param('i', $exId); $upd->execute(); $upd->close(); }

                // Log to messages table (correct column: 'message' not 'content')
                $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, sender, direction, type, message, is_read, status, whatsapp_message_id, created_at) VALUES (?, ?, 'business', 'outgoing', 'text', ?, 1, 'sent', ?, NOW())");
                if ($ins) { $ins->bind_param('iiss', $dCompanyId, $dContactId, $body, $waMsgId); $ins->execute(); $ins->close(); }

                $dripSent++;
            } else {
                // Retry logic: allow up to 3 retries for transient failures
                $maxRetries = 3;
                if ($retryCount < $maxRetries) {
                    // Reschedule for 10 minutes later
                    $nextRetry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $newRetry = $retryCount + 1;
                    $upd = $conn->prepare("UPDATE drip_executions SET status='pending', scheduled_for=?, retry_count=? WHERE id=?");
                    if ($upd) { $upd->bind_param('sii', $nextRetry, $newRetry, $exId); $upd->execute(); $upd->close(); }
                    if ($debug) echo "  Drip #$exId: retry $newRetry/$maxRetries scheduled\n";
                } else {
                    $conn->query("UPDATE drip_executions SET status='failed', error_msg='max_retries_exceeded' WHERE id=$exId");
                    $dripFailed++;
                }
            }
        }
    }

    // Release any 'processing' rows that weren't matched (orphaned from JOIN failures)
    $conn->query("UPDATE drip_executions SET status='pending' WHERE status='processing'");
}

if ($debug) {
    echo "Drip campaigns: processed=$dripProcessed sent=$dripSent failed=$dripFailed skipped=$dripSkipped\n";
}

if ($debug) {
    echo "Pulse Automation Engine Finished.\n";
}