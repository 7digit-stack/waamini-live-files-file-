<?php
/**
 * Queue Service
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/cache.php';

class QueueService {
    public static function enqueue(mysqli $conn, $data): bool {
        // Optional Redis queue (horizontal scaling). Falls back to DB if Redis unavailable.
        $driver = strtolower((string)($_ENV['QUEUE_DRIVER'] ?? getenv('QUEUE_DRIVER') ?: 'db'));
        if ($driver === 'redis') {
            $redis = function_exists('wa_redis') ? wa_redis() : null;
            if ($redis instanceof Redis) {
                $listKey = (string)($_ENV['REDIS_QUEUE_LIST'] ?? getenv('REDIS_QUEUE_LIST') ?: (wa_cache_prefix() . 'queue:automation'));
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($payload)) {
                    return (bool)$redis->lPush($listKey, $payload);
                }
            }
        }

        $companyId = intval($data['company_id']);
        $contactId = isset($data['contact_id']) ? intval($data['contact_id']) : null;
        $type      = $data['type'] ?? 'instant';
        $phone     = $data['phone'] ?? null;
        $message   = $data['message'] ?? null;
        $mediaUrl  = $data['media_url'] ?? null;
        $payload   = isset($data['payload']) ? json_encode($data['payload']) : null;
        $scheduledAt = $data['scheduled_at'] ?? null;

        $stmt = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, phone, message, media_url, payload_json, scheduled_at, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        if (!$stmt) {
             error_log("[QueueService] Enqueue prepare failed: " . $conn->error);
             return false;
        }
        
        $stmt->bind_param("iissssss", $companyId, $contactId, $type, $phone, $message, $mediaUrl, $payload, $scheduledAt);
        return $stmt->execute();
    }

    public static function enqueueText(mysqli $conn, int $companyId, string $phone, string $message, ?int $contactId = null, ?string $scheduledAt = null): bool {
        return self::enqueue($conn, [
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'phone'      => $phone,
            'message'    => $message,
            'type'       => 'text',
            'scheduled_at' => $scheduledAt
        ]);
    }

    public static function enqueueTemplate(mysqli $conn, int $companyId, string $phone, string $templateName, string $langCode, array $components, ?int $contactId = null, ?string $scheduledAt = null): bool {
        return self::enqueue($conn, [
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'phone'      => $phone,
            'type'       => 'template',
            'payload'    => [
                'template_name' => $templateName,
                'language_code' => $langCode,
                'components'    => $components
            ],
            'scheduled_at' => $scheduledAt
        ]);
    }
}
