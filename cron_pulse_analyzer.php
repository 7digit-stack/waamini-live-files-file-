<?php
/**
 * WAAMINI Pulse — Self-Healing Engine (safe, app-level)
 *
 * IMPORTANT:
 * - This script can auto-fix ONLY application-level issues (missing tables/columns, stuck queues, cleanup).
 * - It does NOT patch Windows/Apache/PHP, and it does NOT apply OS security updates.
 *
 * Run modes:
 * - CLI: php cron_pulse_analyzer.php
 * - HTTP (manual): cron_pulse_analyzer.php?key=CRON_SECRET_KEY
 */

// Queue mode: enqueue instead of executing inline (worker executes with WAAMINI_QUEUE_WORKER=1)
if (!defined('WAAMINI_QUEUE_WORKER')) {
    require_once __DIR__ . '/config/bootstrap.php';
    require_once __DIR__ . '/db.php';
set_time_limit(55);
ini_set('memory_limit', '256M');

    $isTrustedInternalRun = (defined('WAAMINI_TRUSTED_INTERNAL_RUN') && WAAMINI_TRUSTED_INTERNAL_RUN === true);
    if (php_sapi_name() !== 'cli' && !$isTrustedInternalRun) {
        $key = (string)($_GET['key'] ?? '');
        if ($key === '' || !defined('CRON_SECRET_KEY') || !hash_equals((string)CRON_SECRET_KEY, $key)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    $payload = json_encode(['enqueued_at' => date('c')], JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO queue_jobs (type, payload_json, status, run_at, attempts, max_attempts) VALUES ('cron_pulse_analyzer', ?, 'pending', NOW(), 0, 1)");
    if ($stmt) {
        $stmt->bind_param('s', $payload);
        $stmt->execute();
    }
    echo "Enqueued cron_pulse_analyzer\n";
    exit;
}

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
set_time_limit(55);
ini_set('memory_limit', '256M');

// Security for HTTP runs
$isTrustedInternalRun = (defined('WAAMINI_TRUSTED_INTERNAL_RUN') && WAAMINI_TRUSTED_INTERNAL_RUN === true);
if (php_sapi_name() !== 'cli' && !$isTrustedInternalRun) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !defined('CRON_SECRET_KEY') || !hash_equals((string)CRON_SECRET_KEY, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// Minimal output only in CLI
$isCli = (php_sapi_name() === 'cli');
function pulse_out(string $s): void {
    global $isCli;
    if ($isCli) echo $s . PHP_EOL;
}

// Ensure base tables exist (idempotent)
try {
    $conn->query("CREATE TABLE IF NOT EXISTS system_errors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        error_type VARCHAR(50) NULL,
        message TEXT NULL,
        file VARCHAR(255) NULL,
        line INT NULL,
        stack_trace TEXT NULL,
        status ENUM('new','analyzed','fixed') DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS pulse_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        last_run_at DATETIME NULL,
        last_status VARCHAR(30) DEFAULT NULL,
        last_summary VARCHAR(255) DEFAULT NULL,
        UNIQUE KEY uniq_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

/**
 * Insert a pulse event (shows in client Pulse).
 */
function pulse_event(?int $companyId, string $type, string $message, string $status = 'new'): void {
    global $conn;
    $status = in_array($status, ['new','analyzed','fixed'], true) ? $status : 'new';
    try {
        $file = 'cron_pulse_analyzer.php';
        $line = 0;
        $trace = '';
        $stmt = $conn->prepare("INSERT INTO system_errors (company_id, error_type, message, file, line, stack_trace, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $cid = $companyId > 0 ? $companyId : null;
        $stmt->bind_param("isssiss", $cid, $type, $message, $file, $line, $trace, $status);
        $stmt->execute();
    } catch (\Throwable $e) {
        // last resort
        error_log('[PULSE] Failed to insert system_errors: ' . $e->getMessage());
    }
}

/**
 * Check if a column exists.
 */
function pulse_has_column(string $table, string $column): bool {
    global $conn;
    $db = $conn->real_escape_string((string)($conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] ?? ''));
    $tableEsc = $conn->real_escape_string($table);
    $colEsc = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$tableEsc' AND COLUMN_NAME='$colEsc' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

/**
 * Apply a safe ALTER TABLE ADD COLUMN (if missing).
 */
function pulse_add_column(string $table, string $column, string $ddl, ?int $companyId = null): bool {
    global $conn;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        pulse_event($companyId, 'Auto Fix Failed', "Rejected unsafe identifier for `$table`.`$column`", 'new');
        return false;
    }
    if (!preg_match('/^' . preg_quote($column, '/') . '\s+[A-Za-z0-9_()\s,`\-\.]+$/', $ddl)) {
        pulse_event($companyId, 'Auto Fix Failed', "Rejected unsafe DDL fragment for `$table`.`$column`", 'new');
        return false;
    }
    if (pulse_has_column($table, $column)) return false;
    try {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` " . preg_replace('/^' . preg_quote($column, '/') . '\s+/', '', $ddl, 1));
        pulse_event($companyId, 'Auto Fix', "Added missing column `$table`.`$column`", 'fixed');
        return true;
    } catch (\Throwable $e) {
        pulse_event($companyId, 'Auto Fix Failed', "Failed adding `$table`.`$column`: " . $e->getMessage(), 'new');
        return false;
    }
}

/**
 * Run self-heal checks for one company.
 */
function pulse_heal_company(int $companyId): array {
    global $conn;
    $fixes = 0;
    $warnings = 0;

    // Schema drift: contacts labels/bot flags (used by whatsapp.php)
    $fixes += pulse_add_column('contacts', 'chat_labels', "chat_labels VARCHAR(255) DEFAULT ''", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('contacts', 'assigned_to', "assigned_to INT DEFAULT NULL", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('contacts', 'bot_enabled', "bot_enabled TINYINT(1) DEFAULT 0", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('contacts', 'reply_bot_enabled', "reply_bot_enabled TINYINT(1) DEFAULT 0", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('contacts', 'last_message_at', "last_message_at TIMESTAMP NULL DEFAULT NULL", $companyId) ? 1 : 0;

    // Schema drift: messages delivery fields used across app
    $fixes += pulse_add_column('messages', 'whatsapp_message_id', "whatsapp_message_id VARCHAR(255) DEFAULT NULL", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('messages', 'error_message', "error_message TEXT DEFAULT NULL", $companyId) ? 1 : 0;
    $fixes += pulse_add_column('messages', 'updated_at', "updated_at DATETIME NULL DEFAULT NULL", $companyId) ? 1 : 0;

    // automation_queue drift (used by cron_automation_sender.php)
    if ($conn->query("SHOW TABLES LIKE 'automation_queue'")->num_rows > 0) {
        $fixes += pulse_add_column('automation_queue', 'sent_at', "sent_at DATETIME NULL", $companyId) ? 1 : 0;
        $fixes += pulse_add_column('automation_queue', 'error_msg', "error_msg VARCHAR(255) NULL", $companyId) ? 1 : 0;

        // Stuck items: processing older than 30 minutes -> back to pending
        if (pulse_has_column('automation_queue', 'updated_at')) {
            $st = $conn->prepare("UPDATE automation_queue
                SET status='pending'
                WHERE company_id=? AND status='processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            if ($st) {
                $st->bind_param("i", $companyId);
                $st->execute();
                $n = (int)$conn->affected_rows;
                if ($n > 0) {
                    pulse_event($companyId, 'Auto Fix', "Reset $n stuck automation_queue items (processing → pending)", 'fixed');
                    $fixes++;
                }
            }
        }
    }

    // WhatsApp config sanity check
    try {
        if ($conn->query("SHOW TABLES LIKE 'whatsapp_config'")->num_rows > 0) {
            $st = $conn->prepare("SELECT COUNT(*) c FROM whatsapp_config WHERE company_id=? AND access_token<>'' AND phone_number_id<>''");
            if ($st) {
                $st->bind_param("i", $companyId);
                $st->execute();
                $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
                if ($c <= 0) {
                    pulse_event($companyId, 'Config Warning', 'WhatsApp config missing/incomplete (access_token / phone_number_id). Sending will fail.', 'new');
                    $warnings++;
                }
            }
        }
    } catch (\Throwable $e) {}

    // Cleanup: keep fixed events only for 14 days per company
    try {
        $st = $conn->prepare("DELETE FROM system_errors WHERE company_id=? AND status='fixed' AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        if ($st) {
            $st->bind_param("i", $companyId);
            $st->execute();
        }
    } catch (\Throwable $e) {}

    return ['fixes' => $fixes, 'warnings' => $warnings];
}

// Discover companies to run for
$companyIds = [];
try {
    if ($conn->query("SHOW TABLES LIKE 'companies'")->num_rows > 0) {
        $res = $conn->query("SELECT id FROM companies WHERE status='active' ORDER BY id ASC");
        while ($res && ($row = $res->fetch_assoc())) {
            $companyIds[] = (int)$row['id'];
        }
    }
} catch (\Throwable $e) {}

// Rate-limit: skip if ran within last 5 minutes (per company) unless forced
$force = (php_sapi_name() === 'cli') || isset($_GET['force']);

foreach ($companyIds as $cid) {
    $shouldRun = true;
    if (!$force) {
        try {
            $st = $conn->prepare("SELECT last_run_at FROM pulse_runs WHERE company_id=? LIMIT 1");
            if ($st) {
                $st->bind_param("i", $cid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $last = $row['last_run_at'] ?? null;
                if ($last) {
                    $ago = time() - strtotime((string)$last);
                    if ($ago >= 0 && $ago < 300) $shouldRun = false;
                }
            }
        } catch (\Throwable $e) {}
    }
    if (!$shouldRun) continue;

    $r = pulse_heal_company($cid);
    $summary = "fixes={$r['fixes']}, warnings={$r['warnings']}";
    $status = ($r['warnings'] > 0) ? 'warning' : (($r['fixes'] > 0) ? 'fixed' : 'stable');
    try {
        $st = $conn->prepare("INSERT INTO pulse_runs (company_id, last_run_at, last_status, last_summary)
            VALUES (?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at), last_status=VALUES(last_status), last_summary=VALUES(last_summary)");
        if ($st) {
            $st->bind_param("iss", $cid, $status, $summary);
            $st->execute();
        }
    } catch (\Throwable $e) {}

    pulse_out("[Pulse] company_id=$cid $summary");
}

if ($isCli) {
    pulse_out("[Pulse] done");
}

