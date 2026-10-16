<?php
require_once __DIR__ . '/../services/EncryptionService.php';

if (!function_exists('waamini_whatsapp_decrypt_secret')) {
    function waamini_whatsapp_decrypt_secret(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        if (!function_exists('decrypt_secret')) {
            return (string)$stored;
        }
        $plain = decrypt_secret((string)$stored);
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }
        if (preg_match('/^(?:oa|na|plain):/', (string)$stored) === 1) {
            return '';
        }
        return (string)$stored;
    }
}

if (!function_exists('waamini_whatsapp_mask_secret')) {
    function waamini_whatsapp_mask_secret(?string $stored): string
    {
        return ($stored !== null && $stored !== '') ? '********' : '';
    }
}

if (!function_exists('waamini_whatsapp_store_secret')) {
    function waamini_whatsapp_store_secret(?string $newValue, ?string $existingStored = null): string
    {
        $incoming = trim((string)$newValue);
        if ($incoming === '' || $incoming === '********') {
            return (string)($existingStored ?? '');
        }
        return function_exists('encrypt_secret') ? encrypt_secret($incoming) : $incoming;
    }
}

if (!function_exists('waamini_whatsapp_load_system_settings')) {
    function waamini_whatsapp_load_system_settings(mysqli $conn): array
    {
        $settings = [];
        $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('system_wa_token', 'system_wa_phone_id', 'wa_default_api_version', 'wa_webhook_verify_token', 'wa_onboarding_guide', 'campaign_send_rate_limit_mps')";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
            }
        }

        if (isset($settings['system_wa_token'])) {
            $settings['system_wa_token'] = waamini_whatsapp_decrypt_secret($settings['system_wa_token']);
        }

        return $settings;
    }
}

if (!function_exists('waamini_whatsapp_system_config_ready')) {
    function waamini_whatsapp_system_config_ready(array $settings): bool
    {
        return !empty($settings['system_wa_token']) && !empty($settings['system_wa_phone_id']);
    }
}

if (!function_exists('waamini_whatsapp_decrypt_config_token')) {
    function waamini_whatsapp_decrypt_config_token($cfgRow): string
    {
        $token = (string)($cfgRow['access_token'] ?? '');
        return waamini_whatsapp_decrypt_secret($token);
    }
}

if (!function_exists('waamini_whatsapp_decrypt_config_row')) {
    function waamini_whatsapp_decrypt_config_row(array &$row): void
    {
        if (isset($row['access_token'])) {
            $row['access_token'] = waamini_whatsapp_decrypt_secret($row['access_token']);
        }
    }
}