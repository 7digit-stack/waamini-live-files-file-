<?php
/**
 * WAAMINI Production Database Configuration Template
 * Copy this to db.php on Hostinger with actual values
 */
define('DB_HOST', 'YOUR_HOSTINGER_MYSQL_HOST');
define('DB_PORT', 3306);
define('DB_USER', 'YOUR_DB_USERNAME');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_NAME', 'YOUR_DB_NAME');
define('APP_ENV', 'production');
define('APP_URL', 'https://7digitapi.com');
define('CRON_SECRET_KEY', 'CHANGE_THIS_TO_RANDOM_64_CHAR_STRING');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    error_log('[WAAMINI] DB connection failed: ' . $conn->connect_error);
    http_response_code(503);
    die('Service temporarily unavailable');
}
$conn->set_charset('utf8mb4');
