<?php
/**
 * Meta (Facebook / WhatsApp / Marketing API) settings for the Ads & Attribution module.
 * ONE place for the Graph API version: change META_GRAPH_VERSION in .env to move every ads call.
 * Secrets come from .env only and are never echoed to the browser.
 */

if (!function_exists('meta_env')) {
    function meta_env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        if (($v === false || $v === null || $v === '') && class_exists('\Waamini\Config\Env')) {
            $v = \Waamini\Config\Env::get($key, '');
        }
        $v = trim((string)($v ?? ''));
        return $v !== '' ? $v : $default;
    }
}

// VERIFY: bump to Meta's current Graph API version when you deploy (Meta retires versions after ~2 years).
if (!defined('META_GRAPH_VERSION')) {
    $metaVer = meta_env('META_GRAPH_VERSION', 'v23.0');
    define('META_GRAPH_VERSION', preg_match('/^v\d+\.\d+$/', $metaVer) ? $metaVer : 'v23.0');
}
if (!defined('META_GRAPH_BASE')) define('META_GRAPH_BASE', 'https://graph.facebook.com');

// 7Digit's own Meta app (used for Facebook Login for Business in Phase 3+ and for appsecret_proof).
// META_APP_SECRET is already used by webhook.php for signature checks.
if (!defined('META_APP_ID'))     define('META_APP_ID', meta_env('META_APP_ID'));
if (!defined('META_APP_SECRET')) define('META_APP_SECRET', meta_env('META_APP_SECRET', meta_env('WHATSAPP_APP_SECRET')));

// Tokens are encrypted with the existing EncryptionService key (WAAMINI_ENC_KEY).
if (!defined('META_TOKEN_KEY_ENV')) define('META_TOKEN_KEY_ENV', 'WAAMINI_ENC_KEY');

// Back off when Meta reports usage above this percentage (X-App-Usage / X-Business-Use-Case-Usage).
if (!defined('META_USAGE_BACKOFF_PCT')) define('META_USAGE_BACKOFF_PCT', 80);

// Ad media downloads (referral thumbnails / images): size cap per file.
if (!defined('ADS_MEDIA_MAX_BYTES')) define('ADS_MEDIA_MAX_BYTES', 10 * 1024 * 1024);
