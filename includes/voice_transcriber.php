<?php
/**
 * VOICE TRANSCRIBER — WhatsApp voice notes → text via OpenAI Whisper
 * ------------------------------------------------------------------
 * Pipeline: Meta Media ID → CDN URL → download OGG/Opus → Whisper
 * (whisper-1, auto language detection: Tamil / Hindi / English / etc.)
 *
 * The transcript feeds the normal AI agent, whose deterministic language
 * detector then replies in the SAME language the customer spoke.
 */

if (!function_exists('voice_transcribe_media')) {

    /** Resolve a Meta media ID to its temporary CDN URL. Returns url or null. */
    function voice_media_url(string $mediaId, string $waToken): ?string
    {
        $ch = curl_init("https://graph.facebook.com/v20.0/" . rawurlencode($mediaId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $waToken],
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log("[VOICE] media lookup failed HTTP $code for $mediaId");
            return null;
        }
        $j = json_decode((string)$raw, true);
        return !empty($j['url']) ? (string)$j['url'] : null;
    }

    /** Download the audio binary (Meta CDN requires the bearer token). */
    function voice_download(string $url, string $waToken): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $waToken],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $bin = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || $bin === false || strlen($bin) < 100) {
            error_log("[VOICE] audio download failed HTTP $code");
            return null;
        }
        return $bin;
    }

    /**
     * Transcribe audio bytes with OpenAI Whisper.
     * Returns ['text' => ..., 'language' => ISO code] or ['error' => ...].
     */
    function voice_whisper(string $audioBytes, string $apiKey, string $filename = 'voice.ogg'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wa_voice_');
        // Whisper needs a proper extension to sniff the container
        $tmpOgg = $tmp . '.ogg';
        @rename($tmp, $tmpOgg);
        file_put_contents($tmpOgg, $audioBytes);

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($tmpOgg, 'audio/ogg', $filename),
                'model' => 'whisper-1',
                'response_format' => 'verbose_json', // includes detected language
                'temperature' => '0',
                // Domain prompt = big accuracy boost for Indian-language business audio
                'prompt' => 'Customer voice message to an Indian business on WhatsApp. May be Tamil, Malayalam, Hindi, English, or mixed Tanglish. Business words: Waamini, 7digit, demo, price, WhatsApp, booking, order, vanakkam, venum, epdi, namaskaram.',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmpOgg);

        if ($code !== 200) {
            error_log('[VOICE] whisper HTTP ' . $code . ': ' . substr((string)$raw, 0, 300));
            return ['error' => 'whisper_http_' . $code];
        }
        $j = json_decode((string)$raw, true);
        $text = trim((string)($j['text'] ?? ''));
        if ($text === '') return ['error' => 'empty_transcript'];

        // Noise guard: reject transcripts with no real content (breath/static clips)
        // so the AI never replies nonsense to an unclear voice note.
        $wordCount = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
        if ($wordCount < 2 && mb_strlen($text, 'UTF-8') < 8) {
            return ['error' => 'unclear_audio', 'text' => $text];
        }

        return ['text' => $text, 'language' => (string)($j['language'] ?? 'unknown')];
    }

    /**
     * Full pipeline: media ID → transcript.
     * Returns ['text'=>, 'language'=>] or ['error'=>].
     */
    function voice_transcribe_media(string $mediaId, string $waToken, string $openaiKey): array
    {
        if ($mediaId === '') return ['error' => 'no_media_id'];
        $url = voice_media_url($mediaId, $waToken);
        if (!$url) return ['error' => 'media_url_failed'];
        $bin = voice_download($url, $waToken);
        if (!$bin) return ['error' => 'download_failed'];
        return voice_whisper($bin, $openaiKey);
    }
}
?>
