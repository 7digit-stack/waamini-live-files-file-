<?php
/**
 * WAAMINI - Contacts API
 * CRUD operations for contacts
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

// Rate limiting
 $rateLimiter = new RateLimiter();
 $rateLimiter->check();

try {
    switch ($action) {
        case 'list':
        case 'GET':
            listContacts();
            break;
            
        case 'create':
        case 'POST':
            createContact();
            break;
            
        case 'update':
        case 'PUT':
            updateContact();
            break;
            
        case 'delete':
        case 'DELETE':
            deleteContact();
            break;
            
        case 'import':
            importContacts();
            break;
            
        case 'export':
            exportContacts();
            break;
            
        case 'groups':
            getGroups();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[CONTACTS API ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process request']);
}

/**
 * List contacts with filters and pagination
 */
function listContacts(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    // Build WHERE clause
    $where = ["c.status != 'blocked'"];
    $params = [];
    
    // Search filter
    $search = $_GET['search'] ?? '';
    if (!empty($search)) {
        $where[] = "(c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }
    
    // Status filter
    $status = $_GET['status'] ?? '';
    if (in_array($status, ['active', 'inactive'])) {
        $where[] = "c.status = :status";
        $params['status'] = $status;
    }
    
    // Source filter
    $source = $_GET['source'] ?? '';
    if (in_array($source, ['whatsapp', 'manual', 'import', 'api'])) {
        $where[] = "c.source = :source";
        $params['source'] = $source;
    }
    
    // Group filter
    $groupId = $_GET['group_id'] ?? '';
    if (is_numeric($groupId)) {
        $where[] = "c.id IN (SELECT contact_id FROM contact_group_members WHERE group_id = :group_id)";
        $params['group_id'] = (int)$groupId;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM contacts c WHERE {$whereClause}";
    $total = Database::fetch($countSql, $params)['total'] ?? 0;
    
    // Get contacts
    $sql = "SELECT c.*, 
                   (SELECT COUNT(*) FROM messages WHERE wa_phone = c.phone AND direction = 'incoming') as message_count,
                   (SELECT MAX(created_at) FROM messages WHERE wa_phone = c.phone) as last_message_at
            FROM contacts c 
            WHERE {$whereClause}
            ORDER BY c.last_activity DESC, c.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $contacts = Database::fetchAll($sql, $params);
    
    // Sanitize output
    $contacts = array_map(function($contact) {
        $contact['name'] = Security::escape($contact['name'] ?? '');
        $contact['phone'] = Security::escape($contact['phone']);
        $contact['email'] = Security::escape($contact['email'] ?? '');
        return $contact;
    }, $contacts);
    
    echo json_encode([
        'success' => true,
        'data' => $contacts,
        'pagination' => [
            'total' => (int)$total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Create new contact
 */
function createContact(): void {
    validateCsrf();
    
    $input = getInput();
    
    // Validation
    $phone = preg_replace('/[^\d+]/', '', $input['phone'] ?? '');
    
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required']);
        return;
    }
    
    if (!Security::validatePhone($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format (use E.164: +255...)']);
        return;
    }
    
    $name = Security::sanitize($input['name'] ?? '');
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $company = Security::sanitize($input['company'] ?? '');
    $notes = Security::sanitize($input['notes'] ?? '');
    
    if (!empty($email) && !Security::validateEmail($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        return;
    }
    
    // Check for duplicate
    $existing = Database::fetch("SELECT id FROM contacts WHERE phone = :phone", ['phone' => $phone]);
    if ($existing) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Contact with this phone number already exists']);
        return;
    }
    
    // Insert contact
    $sql = "INSERT INTO contacts (phone, name, email, company, notes, source, status, created_at) 
            VALUES (:phone, :name, :email, :company, :notes, 'manual', 'active', NOW())";
    
    $id = Database::insert($sql, [
        'phone' => $phone,
        'name' => $name,
        'email' => $email,
        'company' => $company,
        'notes' => $notes
    ]);
    
    // Handle group assignment
    if (!empty($input['groups']) && is_array($input['groups'])) {
        foreach ($input['groups'] as $groupId) {
            if (is_numeric($groupId)) {
                $sql = "INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (:group_id, :contact_id)";
                Database::query($sql, ['group_id' => (int)$groupId, 'contact_id' => $id]);
                
                // Update group count
                $sql = "UPDATE contact_groups SET contact_count = (SELECT COUNT(*) FROM contact_group_members WHERE group_id = :id) WHERE id = :id";
                Database::query($sql, ['id' => (int)$groupId]);
            }
        }
    }
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Contact created successfully',
        'id' => $id
    ]);
}

/**
 * Update contact
 */
function updateContact(): void {
    validateCsrf();
    
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    
    if ($id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contact ID is required']);
        return;
    }
    
    // Check if contact exists
    $contact = Database::fetch("SELECT id FROM contacts WHERE id = :id", ['id' => $id]);
    if (!$contact) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contact not found']);
        return;
    }
    
    // Build update data
    $updates = [];
    $params = ['id' => $id];
    
    $fields = ['name', 'email', 'company', 'notes', 'status'];
    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "{$field} = :{$field}";
            $params[$field] = Security::sanitize($input[$field]);
        }
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }
    
    $updates[] = "updated_at = NOW()";
    $sql = "UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = :id";
    Database::query($sql, $params);
    
    echo json_encode(['success' => true, 'message' => 'Contact updated successfully']);
}

/**
 * Delete contact (soft delete)
 */
function deleteContact(): void {
    validateCsrf();
    
    $input = getInput();
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    
    if ($id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Contact ID is required']);
        return;
    }
    
    // Soft delete
    $sql = "UPDATE contacts SET status = 'blocked', updated_at = NOW() WHERE id = :id";
    Database::query($sql, ['id' => $id]);
    
    echo json_encode(['success' => true, 'message' => 'Contact deleted successfully']);
}

/**
 * Import contacts from CSV/JSON
 */
function importContacts(): void {
    validateCsrf();
    
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        return;
    }
    
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['csv', 'json'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only CSV and JSON files are allowed']);
        return;
    }
    
    $data = [];
    if ($ext === 'csv') {
        if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
            $headers = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                }
            }
            fclose($handle);
        }
    } else {
        $json = file_get_contents($file['tmp_name']);
        $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = [];
    }
    }
    
    $imported = 0;
    $skipped = 0;
    
    foreach ($data as $row) {
        $phone = preg_replace('/[^\d+]/', '', $row['phone'] ?? '');
        
        if (!Security::validatePhone($phone)) {
            $skipped++;
            continue;
        }
        
        $sql = "INSERT IGNORE INTO contacts (phone, name, email, company, source, status) 
                VALUES (:phone, :name, :email, :company, 'import', 'active')";
        
        $result = Database::query($sql, [
            'phone' => $phone,
            'name' => Security::sanitize($row['name'] ?? ''),
            'email' => filter_var($row['email'] ?? '', FILTER_SANITIZE_EMAIL),
            'company' => Security::sanitize($row['company'] ?? '')
        ]);
        
        if ($result->rowCount() > 0) {
            $imported++;
        } else {
            $skipped++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Import completed',
        'imported' => $imported,
        'skipped' => $skipped
    ]);
}

/**
 * Export contacts
 */
function exportContacts(): void {
    $format = $_GET['format'] ?? 'csv';
    
    $sql = "SELECT phone, name, email, company, source, status, created_at FROM contacts WHERE status != 'blocked'";
    $contacts = Database::fetchAll($sql);
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="contacts_' . date('Y-m-d') . '.json"');
        echo json_encode($contacts, JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="contacts_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Phone', 'Name', 'Email', 'Company', 'Source', 'Status', 'Created At']);
        
        foreach ($contacts as $contact) {
            fputcsv($output, $contact);
        }
        
        fclose($output);
    }
    exit;
}

/**
 * Get contact groups
 */
function getGroups(): void {
    $sql = "SELECT * FROM contact_groups ORDER BY name ASC";
    $groups = Database::fetchAll($sql);
    
    echo json_encode(['success' => true, 'data' => $groups]);
}

/**
 * Validate CSRF token
 */
function validateCsrf(): void {
    $input = getInput();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_token'] ?? '';
    
    if (!Security::validateCsrf($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
}

/**
 * Get input data
 */
function getInput(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }
    
    return $_POST;
}
