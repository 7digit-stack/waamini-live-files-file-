<?php
/**
 * WhatsApp fetches template header media (image / video / document) from a public https link.
 * Templates created in this panel store an uploaded header as a relative path ("uploads/templates/x.jpg"),
 * so senders turn that path into a full link on this site before sending.
 */

if (!function_exists('wa_public_base_url')) {
    /** This site's public https base (no trailing slash), or '' when none is known (e.g. plain localhost). */
    function wa_public_base_url(): string
    {
        static $base = null;
        if ($base !== null) return $base;
        $isPublic = static fn(string $u): bool => (bool)preg_match('#^https://#i', $u) && !preg_match('#//(localhost|127\.0\.0\.1|0\.0\.0\.0)([:/]|$)#i', $u);
        $env = static function (string $k): string {
            $v = $_ENV[$k] ?? getenv($k) ?: '';
            if ($v === '' && class_exists('\Waamini\Config\Env')) $v = (string)(\Waamini\Config\Env::get($k, '') ?: '');
            return rtrim(trim((string)$v), '/');
        };

        // 1) The current request, when it is served over https on a real host.
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($host !== '' && $https) {
            $prefix = function_exists('app_url') ? rtrim(app_url(''), '/') : '';
            $u = 'https://' . preg_replace('/[^a-zA-Z0-9.:-]/', '', $host) . $prefix;
            if ($isPublic($u)) return $base = $u;
        }
        // 2) PUBLIC_URL (tunnel for local testing), then APP_URL — also what cron jobs use.
        foreach (['PUBLIC_URL', 'APP_URL'] as $k) {
            $u = $env($k);
            if ($u !== '' && $isPublic($u)) return $base = $u;
        }
        return $base = '';
    }
}

if (!function_exists('wa_public_media_url')) {
    /** A link WhatsApp can fetch: keeps public https links, completes "uploads/…" paths, else ''. */
    function wa_public_media_url(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';
        if (preg_match('#^https://#i', $link)) {
            return preg_match('#//(localhost|127\.0\.0\.1)([:/]|$)#i', $link) ? '' : $link;
        }
        if (preg_match('#^https?:#i', $link)) return ''; // plain http is not accepted
        $rel = ltrim(str_replace('\\', '/', $link), '/');
        if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) return '';
        if (!is_file(dirname(__DIR__) . '/' . $rel)) return '';
        $base = wa_public_base_url();
        return $base !== '' ? $base . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) : '';
    }
}
