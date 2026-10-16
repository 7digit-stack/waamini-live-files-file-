<?php
/**
 * WAAMINI - Settings API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';

Security::init();
header('Content-Type: application/json; charset=utf-8');

 $auth = new Auth();
 $auth->requireAuth('/login.php');

 $action = $_GET['action'] ?? $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'get':
            getSettings();
            break;
        case 'save':
            saveSettings();
            break;
        case 'test_whatsapp':
            testWhatsAppConnection();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[SETTINGS API ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process settings']);
}

function getSettings(): void {
    $category = $_GET['category'] ?? '';
    $sql = "SELECT * FROM settings";
    $params = [];
    
    if (!empty($category)) {
        $sql .= " WHERE category = :cat";
        $params['cat'] = $category;
    }
    
    $settings = Database::fetchAll($sql, $params);
    
    // Format as key => value
    $formatted = [];
    foreach ($settings as $s) {
        $formatted[$s['key']] = $s['value'];
    }
    
    echo json_encode(['success' => true, 'data' => $formatted]);
}

function saveSettings(): void {
    $input = getInput();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_token'] ?? '';
    
    if (!Security::validateCsrf($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        return;
    }
    
    $settings = $input['settings'] ?? [];
    if (empty($settings) || !is_array($settings)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No settings provided']);
        return;
    }
    
    Database::beginTransaction();
    try {
        foreach ($settings as $key => $value) {
            // Prevent updating critical keys via UI
            $restricted = ['ENCRYPTION_KEY', 'DB_PASS', 'WHATSAPP_ACCESS_TOKEN'];
            if (in_array(strtoupper($key), $restricted)) {
                continue;
            }
            
            $sql = "UPDATE settings SET value = :val WHERE `key` = :key";
            Database::query($sql, ['val' => $value, 'key' => $key]);
        }
        Database::commit();
        echo json_encode(['success' => true, 'message' => 'Settings saved']);
    } catch (Exception $e) {
        Database::rollback();
        throw $e;
    }
}

function testWhatsAppConnection(): void {
    require_once __DIR__ . '/../includes/whatsappapi.php';
    
    $wa = new WhatsAppAPI();
    // Sending a test to an invalid number will fail gracefully but proves token works if error is specific
    $result = $wa->sendTextMessage('+1234567890', 'Test Connection');
    
    if (isset($result['error']['code']) && $result['error']['code'] === 401) {
        echo json_encode(['success' => false, 'message' => 'Invalid Access Token']);
    } else if (isset($result['error']['message']) && strpos($result['error']['message'], 'recipient') !== false) {
        // If we get a recipient error, the token is valid!
        echo json_encode(['success' => true, 'message' => 'Connection successful! Token is valid.']);
    } else {
        echo json_encode($result);
    }
}

function getInput(): array {
    if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_POST;
}
