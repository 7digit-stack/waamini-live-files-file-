<?php
/**
 * WAAMINI Health Check API
 * No authentication required — safe read-only status endpoint
 * Usage: GET /waamini/api/health.php
 */

header('Content-Type: application/json');

// VULN-002 FIX: Removed Access-Control-Allow-Origin: * to prevent cross-origin leaks.
// Check for session to allow full health check, otherwise return minimal info.
require_once __DIR__ . '/../config/bootstrap.php';
$isAuthenticated = (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0);

$result = [
    'status'    => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks'    => []
];

// Minimal check for everyone
$result['checks']['system'] = 'online';

if (!$isAuthenticated) {
    echo json_encode($result);
    exit;
}

// Full checks for authenticated users ONLY
try {
    require_once __DIR__ . '/../db.php';
    if ($conn && $conn->ping()) {
        $result['checks']['database'] = 'connected';
    } else {
        $result['checks']['database'] = 'error';
        $result['status'] = 'degraded';
    }
} catch (Exception $e) {
    $result['checks']['database'] = 'error';
    $result['status'] = 'degraded';
}

// ── 2. WhatsApp Env Check (No secrets leaked) ────────────────────────
$waPhoneId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?? '';
$waToken   = $_ENV['WHATSAPP_ACCESS_TOKEN']    ?? getenv('WHATSAPP_ACCESS_TOKEN')    ?? '';
$result['checks']['whatsapp_env'] = (!empty($waPhoneId) && !empty($waToken)) ? 'configured' : 'missing';

// ── 3. Encryption Check ─────────────────────────────────────────────
try {
    require_once __DIR__ . '/../services/EncryptionService.php';
    $result['checks']['encryption'] = 'functional';
} catch (Exception $e) {
    $result['checks']['encryption'] = 'error';
}

// Final status
if ($result['status'] !== 'ok') {
    http_response_code(503);
}

echo json_encode($result, JSON_PRETTY_PRINT);
