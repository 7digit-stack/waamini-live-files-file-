<?php
/**
 * MetaTokenVault — encrypts Meta access tokens at rest with the project's EncryptionService
 * (libsodium secretbox, AES-256 fallback; key = WAAMINI_ENC_KEY). Tokens never go to the browser:
 * use mask() for anything displayed or logged.
 */
require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/MetaGraphClient.php';

class MetaTokenVault
{
    public static function encrypt(string $token): string
    {
        $token = trim($token);
        return $token === '' ? '' : encrypt_secret($token);
    }

    /** Plain token, or '' when the value is empty or cannot be decrypted (wrong / rotated key). */
    public static function decrypt(?string $stored): string
    {
        $stored = (string)$stored;
        if ($stored === '') return '';
        $plain = decrypt_secret($stored);
        return is_string($plain) ? $plain : '';
    }

    public static function mask(string $token): string
    {
        return MetaGraphClient::maskToken($token);
    }
}
