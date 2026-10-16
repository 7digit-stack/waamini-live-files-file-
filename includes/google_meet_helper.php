<?php
/**
 * Google Calendar + Meet helper for Waamini demo bookings.
 * Plain cURL implementation (no SDK). Tokens stored per-company in google_calendar_tokens.
 *
 * Requires .env: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI
 */

if (!function_exists('gmeet_curl')) {

    /** Redirect address for this server, e.g. http://localhost:8000/auth/google-callback.php */
    function gmeet_default_redirect(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
        return ($https ? 'https' : 'http') . '://' . $host . '/auth/google-callback.php';
    }

    /**
     * The platform's Google OAuth app. Looks in .env first, then in the database (saved from
     * Admin > Google Login), so it can be set up without editing files or restarting.
     * @return array{client_id:string,client_secret:string,redirect_uri:string,ready:bool,source:string}
     */
    function gmeet_oauth_config($conn = null, int $companyId = 0): array
    {
        $id = (string)(getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? ''));
        $secret = (string)(getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? ''));
        $redirect = (string)(getenv('GOOGLE_REDIRECT_URI') ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? ''));
        $source = ($id !== '' && $secret !== '') ? 'env' : 'none';
        if ($source === 'none' && $conn instanceof mysqli) {
            try {
                $r = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('google_oauth_client_id','google_oauth_client_secret','google_oauth_redirect_uri')");
                $vals = [];
                while ($r && ($x = $r->fetch_assoc())) { $vals[$x['setting_key']] = (string)$x['setting_value']; }
                $dbId = trim($vals['google_oauth_client_id'] ?? '');
                $dbSecret = (string)($vals['google_oauth_client_secret'] ?? '');
                if ($dbSecret !== '' && function_exists('decrypt_secret')) {
                    $dec = decrypt_secret($dbSecret);
                    if ($dec !== null && $dec !== '') $dbSecret = $dec;
                }
                if ($dbId !== '' && $dbSecret !== '') {
                    $id = $dbId; $secret = $dbSecret; $source = 'database';
                    if (trim($vals['google_oauth_redirect_uri'] ?? '') !== '') $redirect = trim($vals['google_oauth_redirect_uri']);
                }
            } catch (\Throwable $e) {}
        }
        // A client's own Google app (Settings > Google Calendar & Meet) wins over the platform's
        if ($companyId > 0 && $conn instanceof mysqli) {
            $app = gmeet_client_app($conn, $companyId);
            if ($app['client_id'] !== '' && $app['client_secret'] !== '') {
                $id = $app['client_id']; $secret = $app['client_secret']; $source = 'client';
                $redirect = $app['redirect_uri'] !== '' ? $app['redirect_uri'] : '';
            }
        }
        if ($redirect === '') $redirect = gmeet_default_redirect();
        return ['client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => $redirect, 'ready' => ($id !== '' && $secret !== ''), 'source' => $source];
    }

    /** Per-client Google settings table (own Google app + on/off switch for Meet). */
    function gmeet_ensure_apps_table($conn): void
    {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS google_client_apps (
                company_id INT PRIMARY KEY,
                client_id VARCHAR(255) DEFAULT NULL,
                client_secret TEXT DEFAULT NULL,
                redirect_uri VARCHAR(255) DEFAULT NULL,
                meet_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('[GMEET] apps table: ' . $e->getMessage());
        }
    }

    /** This client's own Google app and Meet switch. @return array{client_id:string,client_secret:string,redirect_uri:string,meet_enabled:bool,exists:bool} */
    function gmeet_client_app(mysqli $conn, int $companyId): array
    {
        $out = ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'meet_enabled' => true, 'exists' => false];
        try {
            gmeet_ensure_apps_table($conn);
            $st = $conn->prepare("SELECT client_id, client_secret, redirect_uri, meet_enabled FROM google_client_apps WHERE company_id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $companyId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    $sec = (string)($row['client_secret'] ?? '');
                    if ($sec !== '' && function_exists('decrypt_secret')) { $dec = decrypt_secret($sec); if ($dec !== null && $dec !== '') $sec = $dec; }
                    $out = ['client_id' => trim((string)($row['client_id'] ?? '')), 'client_secret' => $sec, 'redirect_uri' => trim((string)($row['redirect_uri'] ?? '')), 'meet_enabled' => (int)$row['meet_enabled'] === 1, 'exists' => true];
                }
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    function gmeet_meet_enabled($conn, int $companyId): bool
    {
        return $conn instanceof mysqli ? gmeet_client_app($conn, $companyId)['meet_enabled'] : true;
    }

    /** Saves a client's own Google app. A blank secret keeps the saved one. */
    function gmeet_save_client_app(mysqli $conn, int $companyId, string $clientId, string $secret, string $redirect): bool
    {
        try {
            gmeet_ensure_apps_table($conn);
            if (!function_exists('encrypt_secret') && file_exists(__DIR__ . '/../services/EncryptionService.php')) { require_once __DIR__ . '/../services/EncryptionService.php'; }
            $enc = ($secret !== '' && function_exists('encrypt_secret')) ? encrypt_secret($secret) : $secret;
            if ($secret === '') {
                $st = $conn->prepare("INSERT INTO google_client_apps (company_id, client_id, redirect_uri) VALUES (?, ?, ?)
                                      ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), redirect_uri = VALUES(redirect_uri)");
                if (!$st) return false;
                $st->bind_param('iss', $companyId, $clientId, $redirect);
            } else {
                $st = $conn->prepare("INSERT INTO google_client_apps (company_id, client_id, client_secret, redirect_uri) VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), client_secret = VALUES(client_secret), redirect_uri = VALUES(redirect_uri)");
                if (!$st) return false;
                $st->bind_param('isss', $companyId, $clientId, $enc, $redirect);
            }
            $ok = $st->execute();
            $st->close();
            return $ok;
        } catch (\Throwable $e) {
            error_log('[GMEET] save client app: ' . $e->getMessage());
            return false;
        }
    }

    /** Back to the platform's Google login (forgets the client's own app, keeps the on/off switch). */
    function gmeet_clear_client_app(mysqli $conn, int $companyId): bool
    {
        gmeet_ensure_apps_table($conn);
        $st = $conn->prepare("UPDATE google_client_apps SET client_id = NULL, client_secret = NULL, redirect_uri = NULL WHERE company_id = ?");
        if (!$st) return false;
        $st->bind_param('i', $companyId);
        $ok = $st->execute();
        $st->close();
        return $ok;
    }

    /** Administrator switch: may this client use Google Meet? */
    function gmeet_set_meet_enabled(mysqli $conn, int $companyId, bool $enabled): bool
    {
        gmeet_ensure_apps_table($conn);
        $v = $enabled ? 1 : 0;
        $st = $conn->prepare("INSERT INTO google_client_apps (company_id, meet_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE meet_enabled = VALUES(meet_enabled)");
        if (!$st) return false;
        $st->bind_param('ii', $companyId, $v);
        $ok = $st->execute();
        $st->close();
        return $ok;
    }

    /** Saves the Google OAuth app from the admin form (secret stored encrypted when possible). */
    function gmeet_save_oauth_config(mysqli $conn, string $clientId, string $clientSecret, string $redirect): bool
    {
        try {
            if (!function_exists('encrypt_secret') && file_exists(__DIR__ . '/../services/EncryptionService.php')) {
                require_once __DIR__ . '/../services/EncryptionService.php';
            }
            $storeSecret = $clientSecret;
            if ($storeSecret !== '' && function_exists('encrypt_secret')) { $storeSecret = encrypt_secret($clientSecret); }
            $rows = ['google_oauth_client_id' => $clientId, 'google_oauth_client_secret' => $storeSecret, 'google_oauth_redirect_uri' => $redirect];
            $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'google')
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = 'google'");
            if (!$st) return false;
            foreach ($rows as $k => $v) {
                if ($k === 'google_oauth_client_secret' && $clientSecret === '') continue; // keep the saved secret
                $st->bind_param('ss', $k, $v);
                if (!$st->execute()) return false;
            }
            $st->close();
            return true;
        } catch (\Throwable $e) {
            error_log('[GMEET] save config failed: ' . $e->getMessage());
            return false;
        }
    }

    function gmeet_curl(string $url, array $opts): array
    {
        $ch = curl_init($url);
        $base = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $ca = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
        if (PHP_OS_FAMILY === 'Windows' && file_exists($ca)) {
            $base[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $base + $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, json_decode((string)$body, true) ?: [], $err];
    }

    /** Ensure token storage table exists (idempotent). */
    function gmeet_ensure_table(mysqli|PDO $conn): void
    {
        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS google_calendar_tokens (
                    company_id INT PRIMARY KEY,
                    google_email VARCHAR(190) DEFAULT NULL,
                    refresh_token TEXT NOT NULL,
                    access_token TEXT DEFAULT NULL,
                    access_expires_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            error_log('[GMEET] table create failed: ' . $e->getMessage());
        }
    }

    /** Is Google Calendar connected for this company? Returns google_email or null. */
    function gmeet_connected(mysqli $conn, int $companyId): ?string
    {
        gmeet_ensure_table($conn);
        $st = $conn->prepare("SELECT google_email FROM google_calendar_tokens WHERE company_id=? LIMIT 1");
        $st->bind_param('i', $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ? (string)($row['google_email'] ?: 'connected') : null;
    }

    /** Get a valid access token (refresh if expired). Returns token string or null. */
    function gmeet_access_token(mysqli|PDO $conn, int $companyId): ?string
    {
        gmeet_ensure_table($conn);
        $st = $conn->prepare("SELECT refresh_token, access_token, access_expires_at FROM google_calendar_tokens WHERE company_id=? LIMIT 1");
        $st->bind_param('i', $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row || $row['refresh_token'] === '') return null;

        // Cached token still valid (with 60s buffer)?
        if (!empty($row['access_token']) && !empty($row['access_expires_at'])
            && strtotime($row['access_expires_at']) > time() + 60) {
            return $row['access_token'];
        }

        // Refresh
        if (!gmeet_meet_enabled($conn, $companyId)) return null; // switched off by the administrator
        $cfg = gmeet_oauth_config($conn instanceof mysqli ? $conn : null, $companyId);
        $clientId = $cfg['client_id'];
        $clientSecret = $cfg['client_secret'];
        if ($clientId === '' || $clientSecret === '') return null;

        [$code, $data] = gmeet_curl('https://oauth2.googleapis.com/token', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $row['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        if ($code !== 200 || empty($data['access_token'])) {
            error_log('[GMEET] token refresh failed HTTP ' . $code . ': ' . json_encode($data));
            return null;
        }

        $access = $data['access_token'];
        $expiresAt = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3500));
        $up = $conn->prepare("UPDATE google_calendar_tokens SET access_token=?, access_expires_at=? WHERE company_id=?");
        $up->bind_param('ssi', $access, $expiresAt, $companyId);
        $up->execute();
        $up->close();

        return $access;
    }

    /**
     * Parse a human/AI time string ("2026-07-08 16:00", "tomorrow 4pm") into a DateTime (IST).
     * Returns null if unparseable.
     */
    function gmeet_parse_time(string $timeStr): ?DateTime
    {
        try {
            $tz = new DateTimeZone('Asia/Kolkata');
            $raw = trim($timeStr);

            if ($raw === '') return null;

            // A written calendar date wins over a weekday.  For example,
            // "Monday, 15 September at 8 AM" must use 15 September rather
            // than replacing it with the next Monday.
            $month = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
            $clock = '(?:[01]?\\d|2[0-3])(?::[0-5]\\d)?\\s*(?:a\\.?m\\.?|p\\.?m\\.?)';
            $datePatterns = [
                '/\\b\\d{1,2}\\s+' . $month . '(?:\\s*,?\\s*\\d{4})?\\b/i',
                '/\\b' . $month . '\\s+\\d{1,2}(?:\\s*,?\\s*\\d{4})?\\b/i',
                '/\\b\\d{4}[-\\/]\\d{1,2}[-\\/]\\d{1,2}\\b/',
                '/\\b\\d{1,2}[-\\/]\\d{1,2}[-\\/]\\d{2,4}\\b/',
            ];
            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $raw, $dateMatch)) {
                    if (!preg_match('/\\b(' . $clock . ')\\b/i', $raw, $clockMatch)) return null;
                    return new DateTime($dateMatch[0] . ' ' . $clockMatch[1], $tz);
                }
            }

            // strtotime() interprets relative phrases in the PHP server
            // timezone. Parse today/tomorrow explicitly in the business TZ.
            $relativeMatch = preg_match('/\b(today|tomorrow|tonight)\b.*?\b(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?/i', $raw, $m);
            if ($relativeMatch) {
                $dayWord = strtolower($m[1]);
                $hour = (int)$m[2];
                $minute = (int)($m[3] ?? 0);
                $meridiem = strtolower((string)($m[4] ?? ''));
            } elseif (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?\b.*?\b(today|tomorrow|tonight)\b/i', $raw, $m)) {
                $dayWord = strtolower($m[4]);
                $hour = (int)$m[1];
                $minute = (int)($m[2] ?? 0);
                $meridiem = strtolower((string)($m[3] ?? ''));
            } elseif (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b.*?\b(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?/i', $raw, $m)) {
                $dayWord = strtolower($m[1]);
                $hour = (int)$m[2];
                $minute = (int)($m[3] ?? 0);
                $meridiem = strtolower((string)($m[4] ?? ''));
                $weekdayOnly = true;
            }
            if ($relativeMatch || isset($dayWord)) {
                if ($meridiem !== '' && str_starts_with($meridiem, 'p') && $hour < 12) $hour += 12;
                if ($meridiem !== '' && str_starts_with($meridiem, 'a') && $hour === 12) $hour = 0;
                if ($hour > 23 || $minute > 59) return null;

                $dt = new DateTime('now', $tz);
                if ($dayWord === 'tomorrow') $dt->modify('+1 day');
                if (!empty($weekdayOnly)) $dt->modify('next ' . $dayWord);
                $dt->setTime($hour, $minute, 0);
                return $dt;
            }

            $dt = new DateTime($raw, $tz);
            if ($dt->getTimestamp() < time() - 86400 * 365) return null;
            return $dt;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Remove an event from the connected calendar (used by the setup test). */
    function gmeet_delete_event(mysqli|PDO $conn, int $companyId, string $eventId): bool
    {
        $token = gmeet_access_token($conn, $companyId);
        if (!$token || $eventId === '') return false;
        [$code] = gmeet_curl('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventId), [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        return in_array($code, [200, 204, 410], true);
    }

    /** Disconnect: revoke the grant at Google (best effort) and forget the tokens. */
    function gmeet_disconnect(mysqli $conn, int $companyId): void
    {
        gmeet_ensure_table($conn);
        $st = $conn->prepare("SELECT refresh_token FROM google_calendar_tokens WHERE company_id=? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!empty($row['refresh_token'])) {
                gmeet_curl('https://oauth2.googleapis.com/revoke', [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(['token' => $row['refresh_token']]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                    CURLOPT_TIMEOUT => 8,
                ]);
            }
        }
        $del = $conn->prepare("DELETE FROM google_calendar_tokens WHERE company_id=?");
        if ($del) { $del->bind_param('i', $companyId); $del->execute(); $del->close(); }
    }

    /**
     * Create a Google Calendar event with a Google Meet link.
     * Returns ['meet_link'=>, 'event_link'=>, 'event_id'=>] on success or ['error'=>string].
     */
    function gmeet_create_demo_event(mysqli|PDO $conn, int $companyId, string $customerName, string $customerPhone, string $timeStr, string $topic, string $brand = ''): array
    {
        $token = gmeet_access_token($conn, $companyId);
        if (!$token) return ['error' => 'Google Calendar not connected'];

        $start = gmeet_parse_time($timeStr);
        if (!$start) return ['error' => 'Could not parse demo time: ' . $timeStr];

        $end = clone $start;
        $end->modify('+30 minutes');

        $event = [
            'summary' => (($brand !== '' ? $brand : 'Waamini') . " demo — {$customerName}"),
            'description' => "Meeting: {$topic}\nCustomer: {$customerName}\nWhatsApp: {$customerPhone}\n\nBooked from the WhatsApp chat (Waamini).",
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Kolkata'],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Kolkata'],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'waamini-demo-' . uniqid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
            'reminders' => ['useDefault' => false, 'overrides' => [
                ['method' => 'popup', 'minutes' => 30],
                ['method' => 'popup', 'minutes' => 10],
            ]],
        ];

        [$code, $data] = gmeet_curl(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1',
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($event, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
            ]
        );

        if ($code !== 200 || empty($data['id'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            error_log('[GMEET] event create failed: ' . $msg);
            return ['error' => $msg];
        }

        // Meet link: from conferenceData entryPoints or hangoutLink
        $meet = $data['hangoutLink'] ?? '';
        if ($meet === '' && !empty($data['conferenceData']['entryPoints'])) {
            foreach ($data['conferenceData']['entryPoints'] as $ep) {
                if (($ep['entryPointType'] ?? '') === 'video') { $meet = $ep['uri'] ?? ''; break; }
            }
        }

        return [
            'meet_link' => $meet,
            'event_link' => $data['htmlLink'] ?? '',
            'event_id' => $data['id'],
        ];
    }
}
?>
