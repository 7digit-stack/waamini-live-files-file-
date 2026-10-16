<?php
/**
 * Voice notes → text for the chat, WAAMINI Bot and AI CRM.
 *
 * Every customer voice note is queued (ai_voice_jobs), downloaded from Meta and transcribed with the
 * first AI route the platform has a key for:
 *   1. OpenAI Whisper            (OpenAI key)
 *   2. Google Gemini, native API (Gemini key; free tier works, OGG/Opus accepted as-is)
 *   3. OpenRouter + Gemini Flash-Lite (OpenRouter key; needs a small paid balance for audio)
 * The transcript is stored in messages.message (the bubble text) and messages.transcript, then the
 * bot is asked to answer it and the CRM re-reads the chat.
 */
require_once __DIR__ . '/voice_transcriber.php';
require_once __DIR__ . '/waamini_bot/bootstrap.php';

if (!function_exists('voice_ai_enqueue')) {

    define('VOICE_AI_PROMPT', 'You transcribe WhatsApp voice notes that customers send to a business in India.
Decide the language as spoken:
- "english": spoken in English.
- "tamil": spoken mainly in Tamil (a few English words like price/demo/order are fine).
- "tanglish": a casual Tamil-English mix where many English words or phrases are used inside Tamil sentences.
- "hindi", "malayalam" or "other" when that is the language.
Write the transcript word for word in this script: english -> English; tamil -> Tamil script (keep English words in English letters); tanglish -> Tamil written in English letters (Roman script) like people type on WhatsApp, e.g. "Price evlo sir? Demo venum"; hindi -> Devanagari; malayalam -> Malayalam script.
Reply with JSON only: {"language":"english|tamil|tanglish|hindi|malayalam|other","transcript":"..."}');

    function voice_ai_schema(mysqli $c): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        foreach ([
            "CREATE TABLE IF NOT EXISTS ai_voice_jobs (
                message_id INT NOT NULL PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts TINYINT NOT NULL DEFAULT 0,
                via VARCHAR(40) NULL, error VARCHAR(300) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, done_at DATETIME NULL,
                KEY idx_status (status, created_at)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE messages ADD COLUMN transcript TEXT NULL",
            "ALTER TABLE messages ADD COLUMN transcript_lang VARCHAR(20) NULL",
        ] as $sql) {
            try { $c->query($sql); } catch (Throwable $e) { /* exists */ }
        }
    }

    /** Which transcription routes have a key. ['openai' => bool, 'gemini' => bool, 'openrouter' => bool] */
    function voice_ai_routes(mysqli $c): array
    {
        $k = static function (string $p) use ($c): bool {
            try { return (string)(WB_Store::getProviderKey($c, $p) ?? '') !== ''; } catch (Throwable $e) { return false; }
        };
        return ['groq' => $k('groq'), 'gemini' => $k('gemini'), 'openai' => $k('openai') || (string)(getenv('OPENAI_API_KEY') ?: '') !== '', 'openrouter' => $k('openrouter')];
    }

    function voice_ai_available(mysqli $c): bool
    {
        return in_array(true, voice_ai_routes($c), true);
    }

    /** Audio bytes → ['text','language','via'] or ['error' => readable reason]. Tries every configured route. */
    function voice_ai_transcribe_bytes(mysqli $c, string $bytes): array
    {
        $errors = [];
        $routes = voice_ai_routes($c);

        if ($routes['groq']) {
            $r = voice_ai_groq_whisper($bytes, (string)WB_Store::getProviderKey($c, 'groq'));
            if (!isset($r['error'])) { return $r + ['via' => 'groq:whisper-large-v3']; }
            $errors[] = 'Groq: ' . $r['error'];
        }

        if ($routes['openai']) {
            $key = (string)(WB_Store::getProviderKey($c, 'openai') ?: getenv('OPENAI_API_KEY') ?: '');
            $r = voice_whisper($bytes, $key);
            if (!isset($r['error'])) { $r['language'] = voice_ai_label((string)($r['language'] ?? ''), (string)$r['text']); return $r + ['via' => 'openai-whisper']; }
            if ($r['error'] === 'unclear_audio') { return $r + ['via' => 'openai-whisper']; }
            $errors[] = $r['error'] === 'whisper_http_429' ? 'OpenAI: no credits or rate limited' : ($r['error'] === 'whisper_http_401' ? 'OpenAI: key is invalid' : 'OpenAI ' . $r['error']);
        }

        if ($routes['gemini']) {
            $key = (string)WB_Store::getProviderKey($c, 'gemini');
            foreach (['gemini-2.5-flash', 'gemini-2.5-flash-lite'] as $model) {
                $body = ['contents' => [['parts' => [
                    ['text' => VOICE_AI_PROMPT],
                    ['inline_data' => ['mime_type' => 'audio/ogg', 'data' => base64_encode($bytes)]],
                ]]], 'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 1200, 'thinkingConfig' => ['thinkingBudget' => 0]]];
                [$code, $j, $raw] = voice_ai_post('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent', $body, ['x-goog-api-key: ' . $key]);
                $text = '';
                foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $p) { $text .= (string)($p['text'] ?? ''); }
                if ($code === 200 && trim($text) !== '') { return voice_ai_parse_answer($text) + ['via' => 'gemini:' . $model]; }
                $errors[] = 'Gemini ' . $model . ' HTTP ' . $code . ': ' . mb_substr((string)($j['error']['message'] ?? $raw), 0, 120);
                if ($code === 400 || $code === 401 || $code === 403) { break; }
            }
        }

        if ($routes['openrouter']) {
            $key = (string)WB_Store::getProviderKey($c, 'openrouter');
            $body = ['model' => 'google/gemini-2.5-flash-lite', 'max_tokens' => 1200, 'temperature' => 0, 'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => VOICE_AI_PROMPT],
                ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($bytes), 'format' => 'ogg']],
            ]]]];
            [$code, $j, $raw] = voice_ai_post('https://openrouter.ai/api/v1/chat/completions', $body, ['Authorization: Bearer ' . $key, 'X-Title: WAAMINI Voice']);
            $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
            if ($code === 200 && $text !== '') { return voice_ai_parse_answer($text) + ['via' => 'openrouter:gemini-2.5-flash-lite']; }
            $msg = (string)($j['error']['message'] ?? $raw);
            $errors[] = $code === 402 ? 'OpenRouter: audio needs at least $0.50 balance on the OpenRouter account' : 'OpenRouter HTTP ' . $code . ': ' . mb_substr($msg, 0, 120);
        }

        if (!$errors) { return ['error' => 'No AI provider with audio support is connected. Add a free Groq or Google Gemini key in Admin → WAAMINI Bot → Provider keys.']; }
        return ['error' => mb_substr(implode(' | ', $errors), 0, 290)];
    }

    /** @return array{0:int,1:?array,2:string} */
    function voice_ai_post(string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_POSTFIELDS => json_encode($body)]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        return [$code, is_array($j) ? $j : null, mb_substr($raw, 0, 200)];
    }

    /** Groq Whisper (free tier). Returns ['text','language'] or ['error']. */
    function voice_ai_groq_whisper(string $bytes, string $key): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wa_v_') . '.ogg';
        file_put_contents($tmp, $bytes);
        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($tmp, 'audio/ogg', 'voice.ogg'), 'model' => 'whisper-large-v3',
                'response_format' => 'verbose_json', 'temperature' => '0',
                'prompt' => 'Customer voice message to an Indian business on WhatsApp. May be Tamil, Tanglish, Hindi or English. Words: price, demo, order, booking, vanakkam, venum, evlo, epdi.']]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmp);
        $j = json_decode($raw, true);
        if ($code !== 200) {
            $m = (string)($j['error']['message'] ?? ('HTTP ' . $code));
            return ['error' => $code === 401 ? 'key is invalid' : ($code === 429 ? 'free limit reached, try again later' : mb_substr($m, 0, 120))];
        }
        $text = trim((string)($j['text'] ?? ''));
        if ($text === '') { return ['error' => 'empty transcript']; }
        return ['text' => $text, 'language' => voice_ai_label((string)($j['language'] ?? ''), $text)];
    }

    /** {"language","transcript"} answer from Gemini/OpenRouter -> ['text','language']; tolerates code fences or plain text. */
    function voice_ai_parse_answer(string $raw): array
    {
        $raw = trim($raw);
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $x = strpos($raw, '{'); $y = strrpos($raw, '}');
            $j = ($x !== false && $y !== false && $y > $x) ? json_decode(substr($raw, $x, $y - $x + 1), true) : null;
        }
        if (is_array($j) && trim((string)($j['transcript'] ?? '')) !== '') {
            $text = trim((string)$j['transcript']);
            return ['text' => $text, 'language' => voice_ai_label((string)($j['language'] ?? ''), $text)];
        }
        return ['text' => $raw, 'language' => voice_ai_label('', $raw)];
    }

    /** Normalise a language name/code to english|tamil|tanglish|hindi|malayalam|other, checking the text itself too. */
    function voice_ai_label(string $lang, string $text): string
    {
        $l = strtolower(trim($lang));
        $map = ['en' => 'english', 'english' => 'english', 'ta' => 'tamil', 'tamil' => 'tamil', 'tanglish' => 'tanglish',
                'hi' => 'hindi', 'hindi' => 'hindi', 'ml' => 'malayalam', 'malayalam' => 'malayalam'];
        $l = $map[$l] ?? ($l === '' ? '' : 'other');
        $seen = WB_Engine::detectLanguage($text);      // what the written transcript actually looks like
        if ($l === '' || $l === 'other') { return $seen; }
        if ($l === 'tamil' && $seen === 'tanglish') { return 'tanglish'; }   // Tamil spoken, written in English letters
        if ($l === 'english' && $seen === 'tanglish') { return 'tanglish'; }
        return $l;
    }

    /** Queue one incoming voice note. Called by the webhook right after the message row is saved. */
    function voice_ai_enqueue(mysqli $c, int $companyId, int $contactId, int $messageId): void
    {
        if ($companyId <= 0 || $contactId <= 0 || $messageId <= 0) { return; }
        voice_ai_schema($c);
        $st = $c->prepare("INSERT IGNORE INTO ai_voice_jobs (message_id, company_id, contact_id) VALUES (?, ?, ?)");
        if ($st) { $st->bind_param('iii', $messageId, $companyId, $contactId); $st->execute(); }
    }

    /**
     * Transcribe one stored voice message now. Returns ['ok' => bool, 'text' => ..., 'error' => ..., 'via' => ...].
     * $react: ask the bot to answer and the CRM to re-read the chat (true for incoming customer notes).
     */
    function voice_ai_process_message(mysqli $c, int $companyId, int $messageId, bool $react = true): array
    {
        voice_ai_schema($c);
        $st = $c->prepare("SELECT id, contact_id, type, direction, media_url, whatsapp_message_id, transcript FROM messages WHERE id = ? AND company_id = ? LIMIT 1");
        $st->bind_param('ii', $messageId, $companyId);
        $st->execute();
        $m = $st->get_result()->fetch_assoc();
        if (!$m) { return ['ok' => false, 'error' => 'Message not found.']; }
        if (!in_array((string)$m['type'], ['audio', 'voice'], true)) { return ['ok' => false, 'error' => 'Not a voice note.']; }
        if (trim((string)$m['transcript']) !== '') { return ['ok' => true, 'text' => (string)$m['transcript'], 'via' => 'cached']; }
        $mediaId = preg_replace('/\D+/', '', (string)$m['media_url']);
        if ($mediaId === '') { return ['ok' => false, 'error' => 'This voice note has no WhatsApp media id.']; }

        // WhatsApp token of this company
        if (!function_exists('waamini_whatsapp_decrypt_config_token')) { @require_once __DIR__ . '/whatsapp_settings.php'; }
        $cs = $c->prepare("SELECT access_token, api_version FROM whatsapp_config WHERE company_id = ? LIMIT 1");
        $cs->bind_param('i', $companyId);
        $cs->execute();
        $cfg = $cs->get_result()->fetch_assoc();
        if (!$cfg || empty($cfg['access_token'])) { return ['ok' => false, 'error' => 'WhatsApp is not connected.']; }
        $token = function_exists('waamini_whatsapp_decrypt_config_token') ? (string)waamini_whatsapp_decrypt_config_token($cfg) : (string)$cfg['access_token'];

        $url = voice_media_url($mediaId, $token);
        if (!$url) { return ['ok' => false, 'error' => 'Could not get the voice note from WhatsApp (media expired or token invalid).']; }
        $bin = voice_download($url, $token);
        if (!$bin) { return ['ok' => false, 'error' => 'Could not download the voice note from WhatsApp.']; }

        $r = voice_ai_transcribe_bytes($c, $bin);
        if (isset($r['error']) && empty($r['text'])) { return ['ok' => false, 'error' => (string)$r['error']]; }
        $text = trim((string)$r['text']);
        $unclear = isset($r['error']) && $r['error'] === 'unclear_audio';
        if ($unclear) { $text = '(unclear voice note)'; }
        $lang = mb_substr((string)($r['language'] ?? ''), 0, 20);
        $show = $unclear ? '🎤 (unclear voice note)' : '🎤 ' . $text;
        $u = $c->prepare("UPDATE messages SET transcript = ?, transcript_lang = ?, message = ? WHERE id = ? AND company_id = ?");
        $u->bind_param('sssii', $text, $lang, $show, $messageId, $companyId);
        $u->execute();

        if ($react && !$unclear && (string)$m['direction'] === 'incoming') {
            $cid = (int)$m['contact_id'];
            try {
                if (WB_Guard::isLive($c, $companyId)) {
                    WB_Guard::enqueueIfEligible($c, $companyId, $cid, (string)($m['whatsapp_message_id'] ?? ('voice-' . $messageId)));
                }
            } catch (Throwable $e) { error_log('[VOICE AI] bot enqueue: ' . $e->getMessage()); }
            try {
                require_once __DIR__ . '/ai_crm_sync.php';
                crm_ai_enqueue($c, $companyId, $cid);
            } catch (Throwable $e) { error_log('[VOICE AI] crm enqueue: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'text' => $text, 'via' => (string)($r['via'] ?? '')];
    }

    /** Worker: transcribe queued voice notes. Returns how many were handled. */
    function voice_ai_process_due(mysqli $c, int $limit = 3): int
    {
        voice_ai_schema($c);
        $done = 0;
        $q = $c->query("SELECT message_id, company_id, attempts FROM ai_voice_jobs WHERE status = 'pending' ORDER BY created_at LIMIT " . max(1, $limit));
        while ($q && ($j = $q->fetch_assoc())) {
            $mid = (int)$j['message_id']; $co = (int)$j['company_id'];
            $cl = $c->prepare("UPDATE ai_voice_jobs SET status = 'processing', attempts = attempts + 1 WHERE message_id = ? AND status = 'pending'");
            $cl->bind_param('i', $mid);
            $cl->execute();
            if ($c->affected_rows !== 1) { continue; }
            $r = voice_ai_process_message($c, $co, $mid, true);
            $status = $r['ok'] ? 'done' : ((int)$j['attempts'] + 1 < 3 && strpos((string)$r['error'], 'No AI provider') === false ? 'pending' : 'failed');
            $err = $r['ok'] ? null : mb_substr((string)$r['error'], 0, 290);
            $via = $r['ok'] ? mb_substr((string)($r['via'] ?? ''), 0, 40) : null;
            $u = $c->prepare("UPDATE ai_voice_jobs SET status = ?, error = ?, via = ?, done_at = NOW() WHERE message_id = ?");
            $u->bind_param('sssi', $status, $err, $via, $mid);
            $u->execute();
            $done++;
        }
        return $done;
    }
}
