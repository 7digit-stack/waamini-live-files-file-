<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../services/WhatsAppService.php';

header('Content-Type: application/json');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get company ID from session
$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No active session']);
    exit;
}

$config = WhatsAppService::getConfig($conn, $companyId);
$token = $config['access_token'] ?? '';

if (empty($token)) {
    echo json_encode(['ok' => false, 'error' => 'No WhatsApp token found in settings']);
    exit;
}

// Meta Debug Token Endpoint
// We use the token itself to call /debug_token if it's an app token, or we call /app to see its identity
$url = "https://graph.facebook.com/v20.0/app?access_token=" . trim($token);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);

if ($http === 200 && isset($data['id'])) {
    // If we can fetch app details, the token is at least valid for SOME app.
    // Let's try to get permissions if it's a User token
    $urlPerms = "https://graph.facebook.com/v20.0/me/permissions?access_token=" . trim($token);
    $chP = curl_init($urlPerms);
    curl_setopt($chP, CURLOPT_RETURNTRANSFER, true);
    $respP = curl_exec($chP);
    curl_close($chP);
    $permsData = json_decode($respP, true);
    
    $scopes = [];
    if (isset($permsData['data'])) {
        foreach ($permsData['data'] as $p) {
            if ($p['status'] === 'granted') {
                $scopes[] = $p['permission'];
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'app_id' => $data['id'],
        'app_name' => $data['name'] ?? 'Unknown App',
        'scopes' => $scopes ?: ['No granular permissions visible (likely an App Token)']
    ]);
} else {
    $err = $data['error']['message'] ?? 'Invalid token or Meta API unreachable';
    echo json_encode(['ok' => false, 'error' => $err]);
}
