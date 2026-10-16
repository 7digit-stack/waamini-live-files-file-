<?php
/**
 * WAAMINI AI Queue Worker v2.0
 * Processes pending customer messages → AI reply → sends via WhatsApp API
 *
 * Run via cron:   * * * * * php /path/to/process_ai_queue.php
 * Or manually:    php C:\xampp\htdocs\waamini\process_ai_queue.php
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/waamini_ai_agent.php';
require_once __DIR__ . '/includes/ai_crm_sync.php';
require_once __DIR__ . '/includes/voice_transcriber.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

// --- Concurrency lock: only ONE worker runs at a time (prevents double replies) ---
$lockFile = fopen(__DIR__ . '/storage/ai_queue.lock', 'c');
if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another worker is already running - exiting.\n";
    exit(0);
}

function qlog(string $msg): void {
    error_log('[AI QUEUE] ' . $msg);
    echo $msg . "\n";
}

// --- Credentials ---
$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
$waToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
$waPhoneId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';

if ($apiKey === '') {
    qlog('❌ OPENAI_API_KEY not set in .env');
    exit(1);
}
if ($waToken === '' || $waPhoneId === '') {
    qlog('❌ WhatsApp credentials not set in .env');
    exit(1);
}

/**
 * Send a text message via Meta WhatsApp Cloud API. Returns [ok, errorMessage].
 */
function sendWhatsAppText(string $phoneId, string $token, string $to, string $body): array {
    $to = preg_replace('/[^0-9]/', '', $to); // Meta wants digits only
    $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $body],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) return [true, ''];

    $err = json_decode((string)$raw, true)['error']['message'] ?? ('HTTP ' . $code);
    return [false, $err];
}

qlog('🚀 AI Queue Worker started ' . date('Y-m-d H:i:s'));

try {
    // Process max 5 messages per run
    $stmt = $conn->prepare(
        "SELECT id, company_id, customer_phone, message_text, wamid
         FROM ai_message_queue
         WHERE status = 'pending'
         ORDER BY created_at ASC
         LIMIT 5"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) {
        qlog('✅ No pending messages');
        exit(0);
    }

    qlog('📨 Processing ' . count($rows) . ' pending message(s)...');
    $ok = 0; $fail = 0;

    foreach ($rows as $row) {
        $queueId = (int)$row['id'];
        $companyId = (int)$row['company_id'];
        $phone = (string)$row['customer_phone'];
        $text = (string)$row['message_text'];

        try {
            // 0. 🎙️ VOICE NOTE: transcribe with Whisper, then treat as text
            $isVoice = false;
            if (preg_match('/^\[VOICE:(.+)\]$/', trim($text), $vm)) {
                $isVoice = true;
                $mediaId = trim($vm[1]);
                qlog("🎙️ #{$queueId} voice note — transcribing media {$mediaId}...");
                $tr = voice_transcribe_media($mediaId, $waToken, $apiKey);

                if (isset($tr['error'])) {
                    // Unclear/noise audio → polite bilingual ask-to-repeat, then done
                    $sorry = "🎙️ Sorry, ungal voice message clear-ah kekkala 🙏 Konjam text la type panni anupunga, or voice-a marupadiyum anupunga.\n(We couldn't hear your voice note clearly — please type your message or resend the voice note.)";
                    [$sentS, ] = sendWhatsAppText($waPhoneId, $waToken, $phone, $sorry);
                    $st = $conn->prepare("UPDATE ai_message_queue SET status='done', processed_at=NOW(), error_msg=? WHERE id=?");
                    $em = 'voice_' . $tr['error'];
                    $st->bind_param('si', $em, $queueId);
                    $st->execute();
                    $st->close();
                    qlog("⚠️ #{$queueId} voice unclear ({$tr['error']}) — asked customer to repeat" . ($sentS ? ' ✅' : ''));
                    $ok++;
                    continue;
                }

                // 🎙️ prefix marks voice-origin rows in conversation history
                // (so the Tanglish-vs-Tamil-script "typed" heuristic ignores them)
                $text = "🎙️ " . $tr['text'];
                qlog("   📝 [{$tr['language']}] \"" . mb_substr($tr['text'], 0, 80, 'UTF-8') . "\"");

                // 🌐 VOICE REPLY LANGUAGE ROUTER
                // Tamil speech → ALWAYS TANGLISH (customers read Tanglish far more
                // easily than Tamil script — confirmed by real customer feedback:
                // "தங்கிழிஸ்ல பேசுங்க, தமிழ்ல வேண்டாம்"). Malayalam/Hindi/English map 1:1.
                $voiceLang = strtolower((string)($tr['language'] ?? ''));
                if ($voiceLang === 'tamil') {
                    $forceLang = 'TANGLISH';
                } elseif ($voiceLang === 'malayalam') {
                    $forceLang = 'MALAYALAM';
                } elseif ($voiceLang === 'hindi') {
                    $forceLang = 'HINDI';
                } elseif ($voiceLang === 'english') {
                    $forceLang = 'ENGLISH';
                } else {
                    $forceLang = null; // let the agent auto-detect from transcript
                }
                qlog("   🌐 voice reply language → " . ($forceLang ?: 'auto'));

                // Show the transcript in the chat inbox (update the stored audio message)
                if ($wamidRow = trim((string)($row['wamid'] ?? ''))) {
                    $tt = "🎙️ [Voice] " . $text;
                    $st = $conn->prepare("UPDATE messages SET content = ?, message = ? WHERE company_id = ? AND whatsapp_message_id = ? AND type='audio'");
                    if ($st) { $st->bind_param('ssis', $tt, $tt, $companyId, $wamidRow); $st->execute(); $st->close(); }
                }
            }

            // 1. AI generates the reply (full 7digit + Waamini knowledge, demo booking tools)
            $agent = new WaaminiAiAgent($conn, $companyId, $apiKey);
            $reply = $agent->handleMessage($phone, $text, $isVoice ? ($forceLang ?? null) : null);
            if (trim($reply) === '') {
                throw new Exception('AI returned empty reply');
            }

            // 2. Send via WhatsApp Cloud API
            [$sent, $waErr] = sendWhatsAppText($waPhoneId, $waToken, $phone, $reply);
            if (!$sent) {
                throw new Exception('WhatsApp send failed: ' . $waErr);
            }

            // 3. Log outgoing message — prefer exact phone match, then fuzzy last-10
            $digits = preg_replace('/[^0-9]/', '', $phone);
            $last10 = substr($digits, -10);
            $like = '%' . $last10;

            // Try exact match first (handles +91XXXXXXXXXX vs stored number)
            $stmt2 = $conn->prepare(
                "INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at)
                 SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW()
                 FROM contacts WHERE company_id = ? AND phone = ? LIMIT 1"
            );
            $stmt2->bind_param('isis', $companyId, $reply, $companyId, $phone);
            $stmt2->execute();
            if ($stmt2->affected_rows === 0) {
                $stmt2->close();
                // Fallback: fuzzy last-10 match (with/without country prefix)
                $stmt2 = $conn->prepare(
                    "INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at)
                     SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW()
                     FROM contacts WHERE company_id = ? AND phone LIKE ? ORDER BY LENGTH(phone) DESC LIMIT 1"
                );
                $stmt2->bind_param('isis', $companyId, $reply, $companyId, $like);
                $stmt2->execute();
            }
            $stmt2->close();

            // 4. Update inbox preview — use same matching logic
            $preview = mb_substr($reply, 0, 200, 'UTF-8');
            $stmt3 = $conn->prepare(
                "UPDATE chat_conversations cc
                 JOIN contacts c ON c.id = cc.contact_id AND c.company_id = cc.company_id
                 SET cc.last_message = ?, cc.last_message_at = NOW()
                 WHERE cc.company_id = ? AND c.phone = ?"
            );
            $stmt3->bind_param('sis', $preview, $companyId, $phone);
            $stmt3->execute();
            if ($stmt3->affected_rows === 0) {
                $stmt3->close();
                $stmt3 = $conn->prepare(
                    "UPDATE chat_conversations cc
                     JOIN contacts c ON c.id = cc.contact_id AND c.company_id = cc.company_id
                     SET cc.last_message = ?, cc.last_message_at = NOW()
                     WHERE cc.company_id = ? AND c.phone LIKE ? ORDER BY LENGTH(c.phone) DESC LIMIT 1"
                );
                $stmt3->bind_param('sis', $preview, $companyId, $like);
                $stmt3->execute();
            }
            $stmt3->close();

            // 5. Mark as done
            $update = $conn->prepare(
                "UPDATE ai_message_queue SET status = 'done', processed_at = NOW() WHERE id = ?"
            );
            $update->bind_param('i', $queueId);
            $update->execute();
            $update->close();

            $ok++;
            qlog("✅ #{$queueId} → {$phone} REPLIED: " . mb_substr($reply, 0, 70, 'UTF-8') . '...');

            // 6. 🤖 CRM AUTO-SYNC: classify the conversation and apply the lead
            //    outcome (labels + automation_queue + drip campaigns) automatically.
            try {
                // Find contact: exact match first, then fuzzy last-10
                $stC = $conn->prepare("SELECT id FROM contacts WHERE company_id = ? AND phone = ? LIMIT 1");
                $stC->bind_param('is', $companyId, $phone);
                $stC->execute();
                $cRow = $stC->get_result()->fetch_assoc();
                $stC->close();
                if (!$cRow) {
                    $stC = $conn->prepare("SELECT id FROM contacts WHERE company_id = ? AND phone LIKE ? ORDER BY LENGTH(phone) DESC LIMIT 1");
                    $stC->bind_param('is', $companyId, $like);
                    $stC->execute();
                    $cRow = $stC->get_result()->fetch_assoc();
                    $stC->close();
                }
                if ($cRow) {
                    $syncResult = crm_auto_sync($conn, $companyId, (int)$cRow['id'], $apiKey);
                    qlog("   🔄 CRM sync (contact #{$cRow['id']}): {$syncResult}");
                }
            } catch (Throwable $e) {
                qlog("   ⚠️ CRM sync skipped: " . $e->getMessage());
            }

        } catch (Throwable $e) {
            $errMsg = substr($e->getMessage(), 0, 500);
            $update = $conn->prepare(
                "UPDATE ai_message_queue SET status = 'failed', error_msg = ?, processed_at = NOW() WHERE id = ?"
            );
            $update->bind_param('si', $errMsg, $queueId);
            $update->execute();
            $update->close();

            $fail++;
            qlog("❌ #{$queueId} FAILED: {$errMsg}");
        }
    }

    qlog("🏁 Done: {$ok} replied, {$fail} failed");

} catch (Throwable $e) {
    qlog('❌ FATAL: ' . $e->getMessage());
    exit(1);
}

// Chained workers: demo reminders + drip queue delivery + demo rescheduler + pulse watchdog.
// Each is include-safe (top-level `return`, own lock) so one failing never blocks the next.
// cron_pulse_watchdog.php must stay LAST: it may define WAAMINI_QUEUE_WORKER.
try { flock($lockFile, LOCK_UN); } catch (Throwable $e) {}
// cron_flow_tick.php = Flow Builder delays / "no reply" follow-ups + the account's 1-hour chat auto follow-up. It runs FIRST,
// before cron_meetup_notifier.php changes the MySQL session time zone.
foreach (['cron_flow_tick.php', 'cron_meetup_notifier.php', 'send_drip_queue.php', 'cron_demo_rescheduler.php', 'cron_pulse_watchdog.php'] as $w) {
    try {
        require __DIR__ . '/' . $w;
    } catch (Throwable $e) {
        qlog("worker {$w} error: " . $e->getMessage());
    }
}

exit(0);
?>
