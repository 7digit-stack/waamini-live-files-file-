<?php
/**
 * WAAMINI Pulse — Global Monitoring & Error Handler
 * This script captures all PHP errors, exceptions, and fatal errors to log them into the database.
 */

function pulse_error_handler($errno, $errstr, $errfile, $errline)
{
    global $conn;

    // Ignore suppressed errors (like @fopen)
    if (!(error_reporting()& $errno))
        return false;

    $errorType = 'PHP Error';
    switch ($errno) {
        case E_USER_ERROR:
            $errorType = 'User Error';
            break;
        case E_USER_WARNING:
            $errorType = 'User Warning';
            break;
        case E_USER_NOTICE:
            $errorType = 'User Notice';
            break;
        default:
            $errorType = 'PHP Error (' . $errno . ')';
            break;
    }

    log_pulse_error($errorType, $errstr, $errfile, $errline);
    return false; // Let standard handler run too
}

function pulse_exception_handler($exception)
{
    log_pulse_error('Uncaught Exception', $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString());
}

function pulse_fatal_handler()
{
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        log_pulse_error('Fatal Error', $error['message'], $error['file'], $error['line']);

        // Self-heal hint: the analyzer is designed to run via cron/CLI.
        // Avoid including it during shutdown (may do DB/schema operations while in fatal state).
    }
}

function log_pulse_error($type, $message, $file, $line, $trace = '')
{
    global $conn, $companyId;

    if (!$conn)
        return;

    try {
        $stmt = $conn->prepare("INSERT INTO system_errors (company_id, error_type, message, file, line, stack_trace) VALUES (?, ?, ?, ?, ?, ?)");
        $cid = $companyId ?? null;
        $stmt->bind_param("isssis", $cid, $type, $message, $file, $line, $trace);
        $stmt->execute();
    }
    catch (Exception $e) {
        // Fallback to file if DB is down
        error_log("PULSE DB FAILURE: " . $e->getMessage());
    }
}

// Register Handlers
set_error_handler("pulse_error_handler");
set_exception_handler("pulse_exception_handler");
register_shutdown_function("pulse_fatal_handler");

// Performance Tracking
$pulse_start_time = microtime(true);
$pulse_start_memory = memory_get_usage();

// --- PERFORMANCE & STORAGE MONITORING ---

// Initialize storage structure if missing
if (!is_dir(dirname(__DIR__) . "/storage")) {
    @mkdir(dirname(__DIR__) . "/storage", 0777, true);
}

/**
 * Calculate the total size of a directory in bytes
 */
function get_folder_size($path)
{
    $size = 0;
    if (!is_dir($path))
        return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Formats bytes into readable string
 */
function format_bytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get message usage for a specific company
 */
function get_company_msg_usage($cid)
{
    global $conn;
    $month = date('Y-m');
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='outgoing' AND DATE_FORMAT(created_at,'%Y-%m')=?");
    $stmt->bind_param("is", $cid, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    return intval($res->fetch_assoc()['c'] ?? 0);
}

/**
 * Clear vendor specific cache/temp files
 */
function clear_vendor_cache($cid)
{
    $path = dirname(__DIR__) . "/storage/vendor_$cid";
    if (is_dir($path)) {
        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_file($file))
                unlink($file);
        }
        return true;
    }
    return false;
}

function pulse_log_performance()
{
    global $pulse_start_time, $pulse_start_memory;
// Future implementation for monitoring slow pages
}
?>
