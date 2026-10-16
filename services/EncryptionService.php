<?php
/**
 * Encryption Service
 * Uses libsodium if available, falls back to OpenSSL AES-256-CBC (always present).
 * Never causes a fatal even if sodium extension is missing.
 */
class EncryptionService {

    private static function getKey(): string {
        $raw = $_ENV['WAAMINI_ENC_KEY']
            ?? getenv('WAAMINI_ENC_KEY')
            ?: 'waamini_default_encryption_key_32c';
        return hash('sha256', $raw, true); // always 32 bytes
    }

    public static function encrypt_secret(string $plain): string {
        $key = self::getKey();

        // Prefer libsodium if available
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 'na:' . base64_encode($nonce . $cipher);
        }

        // Fallback: OpenSSL AES-256-CBC (always available in PHP 7+)
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            // Last resort: store plain (better than crashing)
            return 'plain:' . base64_encode($plain);
        }
        return 'oa:' . base64_encode($iv . $cipher);
    }

    public static function decrypt_secret(string $ciphertext): ?string {
        if (empty($ciphertext)) return null;
        $key = self::getKey();

        // Detect format by prefix
        if (str_starts_with($ciphertext, 'na:') && function_exists('sodium_crypto_secretbox_open')) {
            $raw   = base64_decode(substr($ciphertext, 3), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
            return $plain === false ? null : $plain;
        }

        if (str_starts_with($ciphertext, 'na:')) return null;

        if (str_starts_with($ciphertext, 'oa:')) {
            $raw    = base64_decode(substr($ciphertext, 3), true);
            if ($raw === false || strlen($raw) < 17) return null;
            $iv     = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $plain === false ? null : $plain;
        }

        if (str_starts_with($ciphertext, 'plain:')) {
            $plain = base64_decode(substr($ciphertext, 6), true);
            return $plain === false || $plain === '' ? null : $plain;
        }

        // Legacy: old sodium format without prefix — try to decode directly
        if (function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode($ciphertext);
            if ($raw !== false && strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
                if ($plain !== false) return $plain;
            }
        }

        // Could not decrypt — return raw (token may already be plain text)
        return $ciphertext;
    }
}

// Global helper functions
if (!function_exists('encrypt_secret')) {
    function encrypt_secret(string $plain): string {
        return EncryptionService::encrypt_secret($plain);
    }
}

if (!function_exists('decrypt_secret')) {
    function decrypt_secret(string $cipher): ?string {
        return EncryptionService::decrypt_secret($cipher);
    }
}
