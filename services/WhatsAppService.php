<?php
/**
 * WhatsApp Service
 * 
 * Central service for WhatsApp API interactions and config management.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/../includes/whatsapp_api_helper.php';
require_once __DIR__ . '/../includes/wa_rate_limiter.php';

class WhatsAppService {
    private static function logApi(mysqli $conn, ?int $companyId, ?int $jobId, array $meta, string $direction = 'outbound'): void {
        $direction = ($direction === 'inbound') ? 'inbound' : 'outbound';
        try {
            // Avoid hard-failing if migration not applied yet
            $chk = $conn->query("SHOW TABLES LIKE 'whatsapp_api_logs'");
            if (!$chk || (int)$chk->num_rows <= 0) {
                return;
            }
            $endpoint = (string)($meta['endpoint'] ?? '');
            $httpCode = isset($meta['http_code']) ? (int)$meta['http_code'] : null;
            $durationMs = isset($meta['duration_ms']) ? (int)$meta['duration_ms'] : null;
            $req = (string)($meta['request_json'] ?? '');
            $resp = (string)($meta['response'] ?? '');
            $curlErr = (string)($meta['curl_error'] ?? '');
            // Back-compat: duration_ms column may not exist yet
            $hasDuration = false;
            try {
                $c = $conn->query("SHOW COLUMNS FROM whatsapp_api_logs LIKE 'duration_ms'");
                $hasDuration = $c && (int)$c->num_rows > 0;
            } catch (\Throwable $e) {}

            $stmt = $hasDuration
                ? $conn->prepare("INSERT INTO whatsapp_api_logs (company_id, job_id, direction, endpoint, http_code, duration_ms, request_json, response_json, curl_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                : $conn->prepare("INSERT INTO whatsapp_api_logs (company_id, job_id, direction, endpoint, http_code, request_json, response_json, curl_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                if (strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')) === 'local') {
                    error_log('[WAAMINI logApi] prepare failed: ' . $conn->error);
                }
                return;
            }
            $cid = ($companyId && $companyId > 0) ? $companyId : null;
            $jid = ($jobId && $jobId > 0) ? $jobId : null;
            if ($hasDuration) {
                $stmt->bind_param('iissiisss', $cid, $jid, $direction, $endpoint, $httpCode, $durationMs, $req, $resp, $curlErr);
            } else {
                $stmt->bind_param('iississs', $cid, $jid, $direction, $endpoint, $httpCode, $req, $resp, $curlErr);
            }
            $ok = $stmt->execute();
            if (!$ok && strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')) === 'local') {
                error_log('[WAAMINI logApi] execute failed: ' . $stmt->error);
            }
        } catch (\Throwable $e) {
            // Never block sending on logging failures
        }
    }

    /**
     * Get decrypted WhatsApp configuration for a company
     */
    public static function getConfig(mysqli $conn, int $companyId): ?array {
        $stmt = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $config = $stmt->get_result()->fetch_assoc();

        if ($config && !empty($config['access_token'])) {
            $raw = (string)$config['access_token'];
            $dec = decrypt_secret($raw);
            // If token is encrypted and decrypt fails, DO NOT fall back to ciphertext.
            if ($dec === null && (str_starts_with($raw, 'oa:') || str_starts_with($raw, 'na:') || str_starts_with($raw, 'plain:'))) {
                $config['access_token'] = '';
            } else {
                $config['access_token'] = $dec ?? $raw;
            }
        }

        // Local/dev fallback: allow env-based WA config when table row is missing or incomplete.
        // This makes localhost testing work even before onboarding UI is completed.
        if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
            $envToken = (string)($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: ($_ENV['WA_ACCESS_TOKEN'] ?? getenv('WA_ACCESS_TOKEN') ?: ''));
            $envPhone = (string)($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?: ($_ENV['WA_BUSINESS_PHONE_NUMBER_ID'] ?? getenv('WA_BUSINESS_PHONE_NUMBER_ID') ?: ''));
            $envVer   = (string)($_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v20.0');
            if ($envToken !== '' && $envPhone !== '') {
                $config = $config ?: [];
                $config['access_token'] = $config['access_token'] ?: $envToken;
                $config['phone_number_id'] = $config['phone_number_id'] ?: $envPhone;
                $config['api_version'] = $config['api_version'] ?? $envVer;
            }
        }
        return $config;
    }

    /**
     * Send a text message using the central helper
     */
    public static function sendText(mysqli $conn, int $companyId, string $phone, string $message, ?int $jobId = null): ?string {
        $config = self::getConfig($conn, $companyId);
        if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
            error_log("[WhatsAppService] Missing config for company $companyId");
            return null;
        }

        // Rate limit (per company)
        $perSec = (float)($_ENV['WHATSAPP_RATE_LIMIT_PER_SEC'] ?? getenv('WHATSAPP_RATE_LIMIT_PER_SEC') ?: 10);
        $burst  = (int)($_ENV['WHATSAPP_RATE_LIMIT_BURST'] ?? getenv('WHATSAPP_RATE_LIMIT_BURST') ?: 10);
        wa_rl_take('wa:sendText:company:' . $companyId, $perSec, $burst);

        $meta = [];
        $id = send_whatsapp_text($phone, $message, $config['access_token'], $config['phone_number_id'], $config['api_version'] ?? null, $meta);
        self::logApi($conn, $companyId, $jobId, $meta, 'outbound');
        return $id;
    }

    /**
     * Send a template message using the central helper
     */
    public static function sendTemplate(mysqli $conn, int $companyId, string $phone, string $templateName, string $langCode, array $components, ?int $jobId = null): ?string {
        $config = self::getConfig($conn, $companyId);
        if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
            error_log("[WhatsAppService] Missing config for company $companyId");
            return null;
        }

        $perSec = (float)($_ENV['WHATSAPP_RATE_LIMIT_PER_SEC'] ?? getenv('WHATSAPP_RATE_LIMIT_PER_SEC') ?: 10);
        $burst  = (int)($_ENV['WHATSAPP_RATE_LIMIT_BURST'] ?? getenv('WHATSAPP_RATE_LIMIT_BURST') ?: 10);
        wa_rl_take('wa:sendTemplate:company:' . $companyId, $perSec, $burst);

        $meta = [];
        $id = send_whatsapp_template($phone, $templateName, $langCode, $components, $config['access_token'], $config['phone_number_id'], $meta);
        self::logApi($conn, $companyId, $jobId, $meta, 'outbound');
        return $id;
    }

    /**
     * Send a media message using the central helper
     */
    public static function sendMedia(mysqli $conn, int $companyId, string $phone, string $mediaUrl, string $type, string $caption, ?int $jobId = null): ?string {
        $config = self::getConfig($conn, $companyId);
        if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
            error_log("[WhatsAppService] Missing config for company $companyId");
            return null;
        }

        $perSec = (float)($_ENV['WHATSAPP_RATE_LIMIT_PER_SEC'] ?? getenv('WHATSAPP_RATE_LIMIT_PER_SEC') ?: 10);
        $burst  = (int)($_ENV['WHATSAPP_RATE_LIMIT_BURST'] ?? getenv('WHATSAPP_RATE_LIMIT_BURST') ?: 10);
        wa_rl_take('wa:sendMedia:company:' . $companyId, $perSec, $burst);

        $meta = [];
        $id = send_whatsapp_media($phone, $mediaUrl, $type, $caption, $config['access_token'], $config['phone_number_id'], $meta);
        self::logApi($conn, $companyId, $jobId, $meta, 'outbound');
        return $id;
    }
}
