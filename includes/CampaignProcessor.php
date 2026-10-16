<?php
/**
 * WAAMINI - Campaign Background Processor
 * Designed to be triggered via cron or async call
 */

declare(strict_types=1);

// Only allow CLI or internal calls
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_PROCESSOR_SECRET'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Validate internal secret if from HTTP
if (isset($_SERVER['HTTP_X_PROCESSOR_SECRET'])) {
    require_once __DIR__ . '/../config/config.php';
    if ($_SERVER['HTTP_X_PROCESSOR_SECRET'] !== AppConfig::getInstance()->get('ENCRYPTION_KEY')) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Legacy WhatsApp Cloud API class (file name is lowercase in this codebase)
require_once __DIR__ . '/../includes/whatsappapi.php';

class CampaignProcessor {
    private WhatsAppAPI $whatsapp;
    private int $batchSize = 50; // Messages per batch
    private int $batchDelay = 2000000; // 2 seconds between batches (WhatsApp rate limit protection)
    
    public function __construct() {
        $this->whatsapp = new WhatsAppAPI();
    }
    
    /**
     * Process a specific campaign
     */
    public function processCampaign(int $campaignId): void {
        $this->log("Starting campaign processor for ID: {$campaignId}");
        
        $campaign = Database::fetch("SELECT * FROM campaigns WHERE id = :id", ['id' => $campaignId]);
        
        if (!$campaign) {
            $this->log("Campaign {$campaignId} not found");
            return;
        }
        
        if ($campaign['status'] !== 'running') {
            $this->log("Campaign {$campaignId} is not running (status: {$campaign['status']})");
            return;
        }
        
        // Check daily limit
        if (!$this->checkDailyLimit()) {
            $this->log("Daily limit reached. Pausing campaign {$campaignId}");
            Database::query("UPDATE campaigns SET status = 'paused' WHERE id = :id", ['id' => $campaignId]);
            return;
        }
        
        // Get pending queued items (current architecture uses campaign_queue)
        try {
            $sql = "SELECT id, company_id, campaign_id, contact_id, phone, message_payload
                    FROM campaign_queue
                    WHERE campaign_id = :id
                      AND status = 'pending'
                      AND scheduled_at <= NOW()
                    ORDER BY id ASC
                    LIMIT {$this->batchSize}";
            $pending = Database::fetchAll($sql, ['id' => $campaignId]);
        } catch (\Throwable $e) {
            $this->log("campaign_queue not available or query failed: " . $e->getMessage());
            return;
        }
        
        if (empty($pending)) {
            // No more pending recipients
            $this->completeCampaign($campaignId);
            return;
        }
        
        $sentCount = 0;
        $failedCount = 0;
        
        foreach ($pending as $job) {
            // Double check campaign is still running
            $status = Database::fetch("SELECT status FROM campaigns WHERE id = :id", ['id' => $campaignId])['status'] ?? '';
            if ($status !== 'running') {
                $this->log("Campaign {$campaignId} stopped during processing");
                return;
            }
            
            $jobId = (int)($job['id'] ?? 0);
            $phone = (string)($job['phone'] ?? '');
            if ($jobId <= 0 || $phone === '') {
                continue;
            }

            // Mark processing
            Database::query("UPDATE campaign_queue SET status='processing' WHERE id=:id", ['id' => $jobId]);

            // Send message (campaigns are template-based in this SaaS)
            $payload = json_decode((string)($job['message_payload'] ?? ''), true);
            $templateName = (string)($payload['template_name'] ?? '');
            $lang = (string)($payload['template_language'] ?? 'en');
            $fields = [];
            for ($i = 1; $i <= 10; $i++) {
                $k = "field_{$i}";
                if (isset($payload[$k]) && $payload[$k] !== '') {
                    $fields[] = (string)$payload[$k];
                }
            }

            $result = ['success' => false, 'message_id' => null, 'error' => ['message' => 'Invalid payload']];
            try {
                if ($templateName !== '') {
                    // WhatsAppAPI in this codebase supports template sending in cron sender via raw cURL;
                    // here we use a conservative text fallback if template isn't supported by class.
                    if (method_exists($this->whatsapp, 'sendTemplateMessage')) {
                        $apiRes = $this->whatsapp->sendTemplateMessage($phone, $templateName, $lang, $fields);
                        $result = is_array($apiRes) ? $apiRes : ['success' => true];
                    } else {
                        $body = (string)($campaign['name'] ?? 'Campaign');
                        $apiRes = $this->whatsapp->sendTextMessage($phone, $body);
                        $result = is_array($apiRes) ? $apiRes : ['success' => true];
                    }
                }
            } catch (\Throwable $e) {
                $result = ['success' => false, 'error' => ['message' => $e->getMessage()]];
            }
            
            if ($result['success']) {
                Database::query("UPDATE campaign_queue SET status='sent' WHERE id=:id", ['id' => $jobId]);
                $sentCount++;
            } else {
                $errorMsg = $result['error']['message'] ?? 'Unknown error';
                Database::query("UPDATE campaign_queue SET status='failed', error_message=:err WHERE id=:id", ['id' => $jobId, 'err' => $errorMsg]);
                $failedCount++;
            }
            
            // Small delay between messages to respect rate limits
            usleep(200000); // 200ms
        }
        
        // Update campaign counts from queue
        Database::query(
            "UPDATE campaigns SET
                sent_count = (SELECT COUNT(*) FROM campaign_queue WHERE campaign_id=:id AND status='sent'),
                failed_count = (SELECT COUNT(*) FROM campaign_queue WHERE campaign_id=:id AND status='failed')
             WHERE id=:id",
            ['id' => $campaignId]
        );
        
        $this->log("Campaign {$campaignId} batch processed: {$sentCount} sent, {$failedCount} failed");
        
        // If there are more pending, cron will pick them up; no extra task table required.
    }
    
    /**
     * Send message based on campaign type
     */
    private function sendMessage(array $campaign, string $phone): array {
        $type = $campaign['message_type'];
        $content = $campaign['message_content'];
        
        switch ($type) {
            case 'text':
                return $this->whatsapp->sendTextMessage($phone, $content);
                
            case 'image':
            case 'video':
            case 'document':
                return $this->whatsapp->sendMediaMessage(
                    $phone,
                    $type,
                    $campaign['media_url'],
                    $content
                );
                
            case 'template':
                $params = json_decode($campaign['template_params'] ?? '[]', true);
                return $this->whatsapp->sendTemplateMessage(
                    $phone,
                    $campaign['template_name'],
                    'en',
                    $params
                );
                
            default:
                return ['success' => false, 'error' => ['message' => 'Unsupported message type']];
        }
    }
    
    /**
     * Update recipient status
     */
    // Legacy recipient status updater removed: current system tracks delivery in campaign_queue.
    
    /**
     * Complete campaign
     */
    private function completeCampaign(int $campaignId): void {
        $sql = "UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = :id";
        Database::query($sql, ['id' => $campaignId]);
        $this->log("Campaign {$campaignId} completed");
    }
    
    /**
     * Check daily sending limit
     */
    private function checkDailyLimit(): bool {
        $limit = (int)Database::fetch("SELECT value FROM settings WHERE `key` = 'daily_message_limit'")['value'] ?? 1000;
        
        $today = date('Y-m-d');
        $sql = "SELECT COUNT(*) as total FROM messages WHERE direction = 'outgoing' AND DATE(created_at) = :today";
        $sentToday = Database::fetch($sql, ['today' => $today])['total'] ?? 0;
        
        return $sentToday < $limit;
    }
    
    /**
     * Process scheduled tasks (called via cron)
     */
    public function processScheduledTasks(): void {
        $this->log("Processing scheduled tasks");
        // This SaaS uses dedicated cron scripts + campaign_queue; no scheduled_tasks table is required.
    }
    
    /**
     * Log message
     */
    private function log(string $message): void {
        $logMsg = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        $logFile = LOGS_PATH . '/campaign_processor.log';
        
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        file_put_contents($logFile, $logMsg, FILE_APPEND);
    }
}

// ============ EXECUTION ============

if (php_sapi_name() === 'cli') {
    // CLI mode: Process all scheduled tasks or specific campaign
    $campaignId = $argv[1] ?? null;
    
    $processor = new CampaignProcessor();
    
    if ($campaignId) {
        $processor->processCampaign((int)$campaignId);
    } else {
        $processor->processScheduledTasks();
    }
} else {
    // HTTP mode: Process specific campaign
    $input = json_decode(file_get_contents('php://input'), true);
    $campaignId = $input['campaign_id'] ?? 0;
    
    if ($campaignId > 0) {
        $processor = new CampaignProcessor();
        $processor->processCampaign($campaignId);
        echo json_encode(['status' => 'processing']);
    }
}