<?php
/**
 * WAAMINI - Campaigns API
 * Create, manage, and trigger campaigns
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
        case 'list':
        case 'GET':
            listCampaigns();
            break;
            
        case 'create':
        case 'POST':
            createCampaign();
            break;
            
        case 'start':
            startCampaign();
            break;
            
        case 'pause':
            pauseCampaign();
            break;
            
        case 'resume':
            resumeCampaign();
            break;
            
        case 'stats':
            getCampaignStats();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[CAMPAIGNS API ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process request']);
}

/**
 * List campaigns
 */
function listCampaigns(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    $status = $_GET['status'] ?? '';
    if (in_array($status, ['draft', 'scheduled', 'running', 'paused', 'completed', 'failed'])) {
        $where[] = "status = :status";
        $params['status'] = $status;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $total = Database::fetch("SELECT COUNT(*) as total FROM campaigns {$whereClause}", $params)['total'] ?? 0;
    
    $sql = "SELECT c.*, u.name as creator_name 
            FROM campaigns c 
            LEFT JOIN users u ON c.created_by = u.id 
            {$whereClause}
            ORDER BY c.created_at DESC 
            LIMIT :limit OFFSET :offset";
    
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $campaigns = Database::fetchAll($sql, $params);
    
    echo json_encode([
        'success' => true,
        'data' => $campaigns,
        'pagination' => [
            'total' => (int)$total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Create new campaign
 */
function createCampaign(): void {
    validateCsrf();
    $input = getInput();
    $user = (new Auth())->getUser();
    
    // Validation
    $name = Security::sanitize($input['name'] ?? '');
    $type = $input['type'] ?? 'blast';
    $messageContent = $input['message_content'] ?? '';
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign name is required']);
        return;
    }
    
    if (empty($messageContent)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message content is required']);
        return;
    }
    
    if (!in_array($type, ['blast', 'scheduled', 'trigger', 'drip'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid campaign type']);
        return;
    }
    
    $messageType = $input['message_type'] ?? 'text';
    $mediaUrl = filter_var($input['media_url'] ?? '', FILTER_VALIDATE_URL) ?: null;
    $templateName = Security::sanitize($input['template_name'] ?? '');
    $scheduledAt = null;
    
    if ($type === 'scheduled') {
        $scheduledAt = $input['scheduled_at'] ?? '';
        if (empty($scheduledAt) || strtotime($scheduledAt) <= time()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid future schedule date is required']);
            return;
        }
        $scheduledAt = date('Y-m-d H:i:s', strtotime($scheduledAt));
    }
    
    // Handle recipients
    $recipients = $input['recipients'] ?? [];
    $groupId = $input['group_id'] ?? null;
    
    if (empty($recipients) && empty($groupId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Recipients or group is required']);
        return;
    }
    
    Database::beginTransaction();
    
    try {
        // Create campaign
        $sql = "INSERT INTO campaigns (
                    name, description, type, status, message_type, message_content,
                    media_url, template_name, scheduled_at, created_by, created_at
                ) VALUES (
                    :name, :desc, :type, 'draft', :msg_type, :msg_content,
                    :media_url, :template, :scheduled_at, :user_id, NOW()
                )";
        
        $campaignId = Database::insert($sql, [
            'name' => $name,
            'desc' => Security::sanitize($input['description'] ?? ''),
            'type' => $type,
            'msg_type' => $messageType,
            'msg_content' => $messageContent,
            'media_url' => $mediaUrl,
            'template' => $templateName,
            'scheduled_at' => $scheduledAt,
            'user_id' => $user['id']
        ]);
        
        // Add recipients
        $recipientPhones = [];
        
        if (!empty($groupId) && is_numeric($groupId)) {
            $sql = "SELECT phone FROM contact_group_members cgm JOIN contacts c ON cgm.contact_id = c.id WHERE cgm.group_id = :gid AND c.status = 'active'";
            $groupContacts = Database::fetchAll($sql, ['gid' => (int)$groupId]);
            $recipientPhones = array_column($groupContacts, 'phone');
        }
        
        if (!empty($recipients) && is_array($recipients)) {
            $recipientPhones = array_unique(array_merge($recipientPhones, $recipients));
        }
        
        // Validate phone numbers
        $validRecipients = array_filter($recipientPhones, function($phone) {
            return Security::validatePhone($phone);
        });
        
        // Batch insert recipients
        if (!empty($validRecipients)) {
            $placeholders = [];
            $params = ['campaign_id' => $campaignId];
            $i = 0;
            
            foreach ($validRecipients as $phone) {
                $key = "phone_{$i}";
                $placeholders[] = "(:campaign_id, :{$key})";
                $params[$key] = $phone;
                $i++;
            }
            
            $sql = "INSERT IGNORE INTO campaign_contacts (campaign_id, phone) VALUES " . implode(', ', $placeholders);
            Database::query($sql, $params);
            
            // Update total recipients
            $sql = "UPDATE campaigns SET total_recipients = (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id = :id) WHERE id = :id";
            Database::query($sql, ['id' => $campaignId]);
        }
        
        // If scheduled, add to scheduled tasks
        if ($type === 'scheduled' && $scheduledAt) {
            $sql = "INSERT INTO scheduled_tasks (type, payload, scheduled_at, status) VALUES ('campaign', :payload, :scheduled_at, 'pending')";
            Database::query($sql, [
                'payload' => json_encode(['campaign_id' => $campaignId]),
                'scheduled_at' => $scheduledAt
            ]);
        }
        
        Database::commit();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Campaign created successfully',
            'id' => $campaignId,
            'recipients_count' => count($validRecipients)
        ]);
        
    } catch (Exception $e) {
        Database::rollback();
        throw $e;
    }
}

/**
 * Start campaign
 */
function startCampaign(): void {
    validateCsrf();
    $input = getInput();
    $campaignId = (int)($input['id'] ?? 0);
    
    if ($campaignId === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
        return;
    }
    
    $campaign = Database::fetch("SELECT * FROM campaigns WHERE id = :id", ['id' => $campaignId]);
    
    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Campaign not found']);
        return;
    }
    
    if (!in_array($campaign['status'], ['draft', 'paused'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign cannot be started']);
        return;
    }
    
    // Update status to running
    $sql = "UPDATE campaigns SET status = 'running', started_at = NOW() WHERE id = :id";
    Database::query($sql, ['id' => $campaignId]);
    
    // Trigger async processor
    triggerCampaignProcessor($campaignId);
    
    echo json_encode(['success' => true, 'message' => 'Campaign started']);
}

/**
 * Pause campaign
 */
function pauseCampaign(): void {
    validateCsrf();
    $input = getInput();
    $campaignId = (int)($input['id'] ?? 0);
    
    $sql = "UPDATE campaigns SET status = 'paused' WHERE id = :id AND status = 'running'";
    $affected = Database::execute($sql, ['id' => $campaignId]);
    
    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Campaign paused']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign cannot be paused']);
    }
}

/**
 * Resume campaign
 */
function resumeCampaign(): void {
    validateCsrf();
    $input = getInput();
    $campaignId = (int)($input['id'] ?? 0);
    
    $sql = "UPDATE campaigns SET status = 'running' WHERE id = :id AND status = 'paused'";
    $affected = Database::execute($sql, ['id' => $campaignId]);
    
    if ($affected > 0) {
        triggerCampaignProcessor($campaignId);
        echo json_encode(['success' => true, 'message' => 'Campaign resumed']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign cannot be resumed']);
    }
}

/**
 * Get campaign statistics
 */
function getCampaignStats(): void {
    $campaignId = (int)($_GET['id'] ?? 0);
    
    if ($campaignId === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campaign ID is required']);
        return;
    }
    
    $campaign = Database::fetch("SELECT * FROM campaigns WHERE id = :id", ['id' => $campaignId]);
    
    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Campaign not found']);
        return;
    }
    
    // Get contact stats
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                 FROM campaign_contacts WHERE campaign_id = :id";
    
    $contactStats = Database::fetch($statsSql, ['id' => $campaignId]);
    
    echo json_encode([
        'success' => true,
        'campaign' => $campaign,
        'stats' => $contactStats
    ]);
}

/**
 * Trigger background campaign processor
 */
function triggerCampaignProcessor(int $campaignId): void {
    // In a production environment with proper queue infrastructure, 
    // this would dispatch a job to Redis/SQS/RabbitMQ.
    
    // For XAMPP, we trigger an async cURL request or rely on cron
    $processorUrl = AppConfig::getInstance()->get('APP_URL') . '/includes/CampaignProcessor.php';
    
    // Async curl call (fire and forget)
    $ch = curl_init($processorUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['campaign_id' => $campaignId]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Processor-Secret: ' . AppConfig::getInstance()->get('ENCRYPTION_KEY')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1,
        CURLOPT_NOSIGNAL => true
    ]);
    curl_exec($ch);
    curl_close($ch);
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
