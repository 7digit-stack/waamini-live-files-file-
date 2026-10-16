<?php
/**
 * WAAMINI - WhatsApp Cloud API Configuration
 * Secure implementation with retry logic
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class WhatsAppConfig {
    private static ?self $instance = null;
    private array $config;
    private array $messageQueue = [];
    private array $processedMessages = [];
    private int $maxRetryAttempts = 3;
    private int $retryDelay = 1000000; // 1 second in microseconds
    
    private function __construct() {
        $this->config = AppConfig::getInstance()->getSection('whatsapp');
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getBaseUrl(): string {
        return $this->config['base_url'];
    }
    
    public function getPhoneNumberId(): string {
        return $this->config['phone_number_id'];
    }
    
    public function getAccessToken(): string {
        return $this->config['access_token'];
    }
    
    public function getVerifyToken(): string {
        return $this->config['verify_token'];
    }
    
    public function getApiVersion(): string {
        return $this->config['api_version'];
    }
    
    /**
     * Check if message was already processed (idempotency)
     */
    public function isMessageProcessed(string $messageId): bool {
        if (isset($this->processedMessages[$messageId])) {
            return true;
        }
        return false;
    }
    
    /**
     * Mark message as processed
     */
    public function markMessageProcessed(string $messageId): void {
        $this->processedMessages[$messageId] = [
            'processed_at' => time(),
            'ttl' => 3600 // 1 hour TTL for memory
        ];
        
        // Cleanup old entries
        $this->cleanupProcessedMessages();
    }
    
    /**
     * Cleanup old processed message IDs
     */
    private function cleanupProcessedMessages(): void {
        $currentTime = time();
        foreach ($this->processedMessages as $id => $data) {
            if ($currentTime - $data['processed_at'] > $data['ttl']) {
                unset($this->processedMessages[$id]);
            }
        }
    }
    
    /**
     * Add message to queue
     */
    public function addToQueue(string $to, array $message, int $priority = 0): string {
        $queueId = uniqid('msg_', true);
        $this->messageQueue[] = [
            'id' => $queueId,
            'to' => $to,
            'message' => $message,
            'priority' => $priority,
            'attempts' => 0,
            'status' => 'queued',
            'created_at' => time()
        ];
        
        // Sort by priority
        usort($this->messageQueue, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        return $queueId;
    }
    
    /**
     * Get queue size
     */
    public function getQueueSize(): int {
        return count($this->messageQueue);
    }
    
    /**
     * Get max retry attempts
     */
    public function getMaxRetryAttempts(): int {
        return $this->maxRetryAttempts;
    }
    
    /**
     * Get retry delay
     */
    public function getRetryDelay(): int {
        return $this->retryDelay;
    }
    
    private function __clone() {}
}