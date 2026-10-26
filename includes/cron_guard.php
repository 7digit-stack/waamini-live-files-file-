<?php
/**
 * Access check for cron/worker scripts that can also be reached by URL.
 * Allowed: the server's cron (CLI), a script chained by another trusted worker
 * (WAAMINI_TRUSTED_INTERNAL_RUN / WAAMINI_ADMIN_CRON_TRIGGER), or ?key=CRON_SECRET_KEY.
 * Anything else gets 403, before the script touches the database or sends messages.
 */
if (!function_exists('waamini_cron_guard')) {
    function waamini_cron_guard(): void
    {
        if (php_sapi_name() === 'cli') return;
        if (defined('WAAMINI_TRUSTED_INTERNAL_RUN') && WAAMINI_TRUSTED_INTERNAL_RUN === true) return;
        if (defined('WAAMINI_ADMIN_CRON_TRIGGER') && WAAMINI_ADMIN_CRON_TRIGGER === true) return;

        $key = (string)($_GET['key'] ?? '');
        $secret = defined('CRON_SECRET_KEY') ? (string)CRON_SECRET_KEY : '';
        if ($key === '' || $secret === '' || !hash_equals($secret, $key)) {
            http_response_code(403);
            exit('Forbidden');
        }
        header('Content-Type: text/plain; charset=utf-8');
    }
}
