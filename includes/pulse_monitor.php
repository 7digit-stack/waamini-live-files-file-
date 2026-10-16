<?php
/**
 * WAAMINI Pulse — Panel-wide monitoring library.
 *
 * Loaded by config/bootstrap.php so EVERY admin/client panel page gets error
 * capture into the `system_errors` table (what admin/pulse.php displays).
 * Also shared by cron_pulse_watchdog.php for the MCP health watchdog.
 *
 * All functions are fail-safe: they never throw and never emit output, so a
 * monitoring failure can never break a page.
 */

/**
 * Return the shared mysqli connection if it is usable, else null.
 */
function waamini_pulse_db(): ?mysqli
{
    $conn = $GLOBALS['conn'] ?? null;
    return ($conn instanceof mysqli) ? $conn : null;
}

/**
 * Record an error into system_errors (deduplicated).
 *
 * Dedup: an identical type+message+file+line already logged within the last
 * 10 minutes is skipped, so a hot loop cannot flood the table.
 */
function waamini_pulse_capture(string $type, string $message, string $file = '', int $line = 0, string $trace = ''): void
{
    static $inCapture = false;
    if ($inCapture) {
        return; // re-entrancy guard: an error inside capture must not recurse
    }
    $inCapture = true;

    try {
        $conn = waamini_pulse_db();
        if (!$conn) {
            return;
        }

        $message = mb_substr($message, 0, 1000);
        $file = mb_substr($file, 0, 250);
        $trace = mb_substr($trace, 0, 5000);

        $dup = $conn->prepare("SELECT id FROM system_errors WHERE error_type=? AND message=? AND file=? AND line=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
        if ($dup) {
            $dup->bind_param('sssi', $type, $message, $file, $line);
            if ($dup->execute() && ($dupRes = $dup->get_result()) && $dupRes->num_rows > 0) {
                return;
            }
        }

        $companyId = $GLOBALS['companyId'] ?? ($_SESSION['company_id'] ?? null);
        $companyId = is_numeric($companyId) ? (int)$companyId : null;

        $stmt = $conn->prepare("INSERT INTO system_errors (company_id, error_type, message, file, line, stack_trace) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('isssis', $companyId, $type, $message, $file, $line, $trace);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        @error_log('[PULSE CAPTURE FAILURE] ' . $e->getMessage());
    } finally {
        $inCapture = false;
    }
}

/**
 * Map a PHP severity to a Pulse error type, or null when the severity is too
 * noisy to store in the DB (still goes to the error_log file).
 */
function waamini_pulse_severity_type(int $severity): ?string
{
    switch ($severity) {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_PARSE:
            return 'Fatal Error';
        case E_RECOVERABLE_ERROR:
            return 'Recoverable Error';
        case E_USER_ERROR:
            return 'User Error';
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
            return 'PHP Warning';
        case E_USER_WARNING:
            return 'User Warning';
        default:
            return null; // notices/deprecations: file log only
    }
}

/**
 * Check the waamini-mcp server health endpoint.
 * Fail-safe with short timeouts; never throws, never warns.
 */
function waamini_pulse_check_mcp(): array
{
    $url = defined('WAAMINI_MCP_HEALTH_URL') ? WAAMINI_MCP_HEALTH_URL : 'http://127.0.0.1:8765/health';
    $health = ['online' => false, 'service' => '', 'latency_ms' => null];
    $start = microtime(true);
    $body = false;

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $httpCode === 200) {
                $body = $resp;
            }
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                $body = $resp;
            }
        }

        if ($body !== false) {
            $decoded = json_decode((string)$body, true);
            if (is_array($decoded) && (($decoded['status'] ?? '') === 'ok')) {
                $health['online'] = true;
                $health['service'] = (string)($decoded['service'] ?? 'mcp');
                $health['latency_ms'] = (int)round((microtime(true) - $start) * 1000);
            }
        }
    } catch (Throwable $e) {
        // offline result already set
    }

    return $health;
}
