<?php
/**
 * Per-client helpers for the demo / meeting flow.
 * Every client sends from THEIR OWN WhatsApp number (whatsapp_config) and uses THEIR OWN business name,
 * so the flow works for any number of clients, not just the platform owner.
 */

if (!function_exists('demo_wa_creds')) {

    /** WhatsApp credentials saved by this client in Settings -> WhatsApp Setup, or null. */
    function demo_wa_creds(mysqli $conn, int $companyId): ?array
    {
        static $cache = [];
        if (array_key_exists($companyId, $cache)) return $cache[$companyId];
        $cache[$companyId] = null;
        try {
            if (!function_exists('decrypt_secret') && file_exists(__DIR__ . '/../services/EncryptionService.php')) {
                require_once __DIR__ . '/../services/EncryptionService.php';
            }
            $st = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
            if (!$st) return null;
            $st->bind_param('i', $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row || trim((string)$row['access_token']) === '' || trim((string)$row['phone_number_id']) === '') return null;
            $token = (string)$row['access_token'];
            if (function_exists('decrypt_secret')) {
                $dec = decrypt_secret($token);
                if ($dec !== null && $dec !== '') $token = $dec;
            }
            $cache[$companyId] = [
                'token' => $token,
                'phone_id' => trim((string)$row['phone_number_id']),
                'api_version' => trim((string)($row['api_version'] ?: 'v18.0')),
            ];
        } catch (\Throwable $e) {
            error_log('[DEMO WA] creds: ' . $e->getMessage());
        }
        return $cache[$companyId];
    }

    /** Sends a WhatsApp text from the client's own number. Returns [ok, error]. */
    function demo_wa_send(mysqli $conn, int $companyId, string $to, string $body): array
    {
        $c = demo_wa_creds($conn, $companyId);
        if (!$c) return [false, 'This client has not connected WhatsApp yet (Settings > WhatsApp Setup).'];
        $to = preg_replace('/[^0-9]/', '', $to);
        if ($to === '') return [false, 'Customer phone number is missing'];
        $ch = curl_init("https://graph.facebook.com/{$c['api_version']}/{$c['phone_id']}/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
            CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $body]], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return [true, ''];
        $err = json_decode((string)$raw, true)['error']['message'] ?? ('HTTP ' . $code);
        return [false, (string)$err];
    }

    /** The client's business name, used in messages and calendar events. */
    function demo_brand(mysqli $conn, int $companyId): string
    {
        static $cache = [];
        if (isset($cache[$companyId])) return $cache[$companyId];
        $name = '';
        try {
            $st = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $companyId);
                $st->execute();
                $name = trim((string)($st->get_result()->fetch_assoc()['name'] ?? ''));
                $st->close();
            }
        } catch (\Throwable $e) {}
        return $cache[$companyId] = ($name !== '' ? $name : 'Our team');
    }
}
