<?php
/**
 * WAAMINI - Chatbot Management API
 * CRUD for rules and flows
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ratelimiter.php';
require_once __DIR__ . '/../includes/auth.php';

Security::init();
header('Content-Type: application/json; charset=utf-8');

 $auth = new Auth();
 $auth->requireAuth('/login.php');

 $action = $_GET['action'] ?? $_SERVER['REQUEST_METHOD'];

 $rateLimiter = new RateLimiter();
 $rateLimiter->check();

try {
    switch ($action) {
        case 'list_rules':
            listRules();
            break;
        case 'create_rule':
            createRule();
            break;
        case 'update_rule':
            updateRule();
            break;
        case 'delete_rule':
            deleteRule();
            break;
        case 'list_flows':
            listFlows();
            break;
        case 'create_flow':
            createFlow();
            break;
        case 'toggle_status':
            toggleStatus();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[CHATBOT API ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process request']);
}

function listRules(): void {
    $sql = "SELECT * FROM chatbot_rules ORDER BY priority DESC, id ASC";
    $rules = Database::fetchAll($sql);
    echo json_encode(['success' => true, 'data' => $rules]);
}

function createRule(): void {
    validateCsrf();
    $input = getInput();
    
    $name = Security::sanitize($input['name'] ?? '');
    $pattern = $input['pattern'] ?? '';
    $responseType = $input['response_type'] ?? 'text';
    $responseContent = $input['response_content'] ?? [];
    $priority = (int)($input['priority'] ?? 0);
    
    if (empty($name) || empty($pattern)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name and pattern are required']);
        return;
    }
    
    // Validate JSON content
    if (is_string($responseContent)) {
        $decoded = json_decode($responseContent, true);
        if (!is_array($decoded)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid response']);
            exit();
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid response content JSON']);
            return;
        }
        $responseContent = $decoded;
    }
    
    // Validate regex if it looks like one
    if (strpos($pattern, '/') === 0) {
        set_error_handler(function() {}, E_WARNING);
        $isValid = preg_match($pattern, '') !== false;
        restore_error_handler();
        if (!$isValid) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid regex pattern']);
            return;
        }
    }
    
    $sql = "INSERT INTO chatbot_rules (name, pattern, response_type, response_content, priority) 
            VALUES (:name, :pattern, :type, :content, :priority)";
    
    Database::query($sql, [
        'name' => $name,
        'pattern' => $pattern,
        'type' => $responseType,
        'content' => json_encode($responseContent),
        'priority' => $priority
    ]);
    
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Rule created']);
}

function updateRule(): void {
    validateCsrf();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    
    if ($id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Rule ID required']);
        return;
    }
    
    $fields = ['name', 'pattern', 'response_type', 'response_content', 'priority', 'status', 'case_sensitive', 'exact_match'];
    $updates = [];
    $params = ['id' => $id];
    
    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "{$field} = :{$field}";
            $val = $input[$field];
            if ($field === 'response_content') {
                $val = is_string($val) ? $val : json_encode($val);
            }
            $params[$field] = $val;
        }
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }
    
    $sql = "UPDATE chatbot_rules SET " . implode(', ', $updates) . " WHERE id = :id";
    Database::query($sql, $params);
    
    echo json_encode(['success' => true, 'message' => 'Rule updated']);
}

function deleteRule(): void {
    validateCsrf();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    
    if ($id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Rule ID required']);
        return;
    }
    
    Database::query("DELETE FROM chatbot_rules WHERE id = :id", ['id' => $id]);
    echo json_encode(['success' => true, 'message' => 'Rule deleted']);
}

function listFlows(): void {
    $sql = "SELECT id, name, description, status, action_type, created_at FROM chatbot_flows ORDER BY name";
    $flows = Database::fetchAll($sql);
    echo json_encode(['success' => true, 'data' => $flows]);
}

function createFlow(): void {
    validateCsrf();
    $input = getInput();
    
    $name = Security::sanitize($input['name'] ?? '');
    $steps = $input['steps'] ?? [];
    
    if (empty($name) || empty($steps)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name and steps are required']);
        return;
    }
    
    $sql = "INSERT INTO chatbot_flows (name, description, steps, action_type, action_config) 
            VALUES (:name, :desc, :steps, :action_type, :action_config)
            ON DUPLICATE KEY UPDATE steps = :steps, action_type = :action_type, action_config = :action_config";
    
    Database::query($sql, [
        'name' => $name,
        'desc' => Security::sanitize($input['description'] ?? ''),
        'steps' => json_encode($steps),
        'action_type' => $input['action_type'] ?? null,
        'action_config' => json_encode($input['action_config'] ?? [])
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Flow saved']);
}

function toggleStatus(): void {
    validateCsrf();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    $type = $input['type'] ?? 'rule'; // rule or flow
    $status = $input['status'] === 'active' ? 'active' : 'inactive';
    
    $table = $type === 'flow' ? 'chatbot_flows' : 'chatbot_rules';
    Database::query("UPDATE {$table} SET status = :status WHERE id = :id", ['id' => $id, 'status' => $status]);
    
    echo json_encode(['success' => true, 'message' => 'Status updated']);
}

function validateCsrf(): void {
    $input = getInput();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
}

function getInput(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_POST;
}
