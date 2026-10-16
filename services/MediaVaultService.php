<?php
/**
 * MediaVaultService — stores ad / referral media inside storage/ (blocked from the web by .htaccess),
 * one folder per company. Files are only served through client/ad_media.php after an auth check.
 *
 * Referral thumbnail / image URLs expire, so they are downloaded soon after the message arrives.
 * Only Meta CDN hosts are accepted: a forged webhook cannot make the server fetch internal URLs (SSRF).
 */
require_once __DIR__ . '/../config/meta.php';

class MediaVaultService
{
    // VERIFY: Meta serves referral media from these CDNs today; add a host here if a new one appears in raw_json.
    private const ALLOWED_HOSTS = ['fbcdn.net', 'facebook.com', 'fbsbx.com', 'whatsapp.net', 'cdninstagram.com', 'instagram.com', 'fb.me'];
    private const MIME_EXT = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov',
    ];

    public static function root(): string
    {
        return dirname(__DIR__) . '/storage';
    }

    public static function isAllowedUrl(string $url): bool
    {
        if (!preg_match('#^https://#i', $url)) return false;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;
        foreach (self::ALLOWED_HOSTS as $h) {
            if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) return true;
        }
        return false;
    }

    /**
     * Download $url into storage/{$subdir}/{$companyId}/. Returns ['ok', 'path' (relative to storage/), 'mime', 'bytes', 'error'].
     * @param callable|null $fetch for tests: fn(string $url): array{0:int status,1:string content_type,2:string body}
     */
    public static function saveFromUrl(int $companyId, string $url, string $subdir = 'ad_media', ?callable $fetch = null): array
    {
        $fail = static fn(string $e) => ['ok' => false, 'path' => '', 'mime' => '', 'bytes' => 0, 'error' => $e];
        if ($companyId <= 0) return $fail('No company.');
        if (!self::isAllowedUrl($url)) return $fail('Not a Meta media link.');

        [$status, $type, $body] = $fetch ? $fetch($url) : self::fetch($url);
        if ($status !== 200 || $body === '') return $fail('Download failed (HTTP ' . $status . ').');
        if (strlen($body) > ADS_MEDIA_MAX_BYTES) return $fail('File is larger than the limit.');
        $mime = strtolower(trim(explode(';', $type)[0]));
        if (!isset(self::MIME_EXT[$mime]) && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_buffer($fi, $body);
            finfo_close($fi);
        }
        $ext = self::MIME_EXT[$mime] ?? '';
        if ($ext === '') return $fail('Unsupported media type: ' . $mime);

        $subdir = preg_replace('/[^a-z0-9_]/', '', strtolower($subdir)) ?: 'ad_media';
        $dir = self::root() . '/' . $subdir . '/' . $companyId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return $fail('Cannot create the media folder.');
        $name = sha1($url) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $body, LOCK_EX) === false) return $fail('Cannot write the file.');
        return ['ok' => true, 'path' => $subdir . '/' . $companyId . '/' . $name, 'mime' => $mime, 'bytes' => strlen($body), 'error' => ''];
    }

    /** Absolute path for a stored relative path, or '' if it escapes storage/ or does not exist. */
    public static function absPath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) return '';
        $abs = self::root() . '/' . $rel;
        return is_file($abs) ? $abs : '';
    }

    /** cURL GET with size cap; follows at most 3 redirects, all of which must stay on Meta hosts. */
    private static function fetch(string $url): array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $type = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'WAAMINI-MediaVault/1.0',
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => static fn($c, $dlTotal, $dlNow) => $dlNow > ADS_MEDIA_MAX_BYTES ? 1 : 0,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($status >= 300 && $status < 400 && $next !== '') {
                if (!self::isAllowedUrl($next)) return [0, '', ''];
                $url = $next;
                continue;
            }
            return [$status, $type, is_string($body) ? $body : ''];
        }
        return [0, '', ''];
    }
}
