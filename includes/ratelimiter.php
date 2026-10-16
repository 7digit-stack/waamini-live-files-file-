<?php
/**
 * WAAMINI - Rate Limiter
 * Prevent API abuse and brute force attacks
 */

declare(strict_types=1);

class RateLimiter {
    private PDO $db;
    private string $identifier;
    private int $maxAttempts;
    private int $windowSeconds;
    
    public function __construct(string $identifier = null) {
        $this->db = Database::getInstance();
        $config = AppConfig::getInstance()->getSection('security');
        
        $this->identifier = $identifier ?? $this->generateIdentifier();
        $this->maxAttempts = $config['rate_limit_max'];
        $this->windowSeconds = $config['rate_limit_window'];
        
        $this->ensureTableExists();
    }
    
    /**
     * Generate identifier from request
     */
    private function generateIdentifier(): string {
        $ip = Security::getClientIp();
        $endpoint = $_SERVER['REQUEST_URI'] ?? '';
        return md5($ip . ':' . $endpoint);
    }
    
    /**
     * Ensure rate limit table exists
     */
    private function ensureTableExists(): void {
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            attempts INT DEFAULT 0,
            window_start INT NOT NULL,
            UNIQUE KEY idx_identifier (identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }
    
    /**
     * Check if rate limit exceeded
     */
    public function isLimited(): bool {
        $record = $this->getRecord();
        
        if ($record === null) {
            return false;
        }
        
        // Check if window has expired
        if (time() - $record['window_start'] > $this->windowSeconds) {
            $this->reset();
            return false;
        }
        
        return $record['attempts'] >= $this->maxAttempts;
    }
    
    /**
     * Get remaining attempts
     */
    public function getRemainingAttempts(): int {
        if ($this->isLimited()) {
            return 0;
        }
        
        $record = $this->getRecord();
        
        if ($record === null) {
            return $this->maxAttempts;
        }
        
        if (time() - $record['window_start'] > $this->windowSeconds) {
            return $this->maxAttempts;
        }
        
        return max(0, $this->maxAttempts - $record['attempts']);
    }
    
    /**
     * Get time until reset
     */
    public function getResetTime(): int {
        $record = $this->getRecord();
        
        if ($record === null) {
            return 0;
        }
        
        $expiresAt = $record['window_start'] + $this->windowSeconds;
        return max(0, $expiresAt - time());
    }
    
    /**
     * Increment attempt counter
     */
    public function hit(): void {
        $record = $this->getRecord();
        
        if ($record === null || time() - $record['window_start'] > $this->windowSeconds) {
            // Create new record
            $sql = "INSERT INTO rate_limits (identifier, attempts, window_start) 
                    VALUES (:identifier, 1, :time)
                    ON DUPLICATE KEY UPDATE attempts = 1, window_start = :time";
            Database::query($sql, [
                'identifier' => $this->identifier,
                'time' => time()
            ]);
        } else {
            // Increment existing record
            $sql = "UPDATE rate_limits 
                    SET attempts = attempts + 1 
                    WHERE identifier = :identifier";
            Database::query($sql, ['identifier' => $this->identifier]);
        }
    }
    
    /**
     * Reset rate limit
     */
    public function reset(): void {
        $sql = "DELETE FROM rate_limits WHERE identifier = :identifier";
        Database::query($sql, ['identifier' => $this->identifier]);
    }
    
    /**
     * Get rate limit record
     */
    private function getRecord(): ?array {
        $sql = "SELECT * FROM rate_limits WHERE identifier = :identifier";
        return Database::fetch($sql, ['identifier' => $this->identifier]);
    }
    
    /**
     * Send rate limit headers
     */
    public function sendHeaders(): void {
        header('X-RateLimit-Limit: ' . $this->maxAttempts);
        header('X-RateLimit-Remaining: ' . $this->getRemainingAttempts());
        header('X-RateLimit-Reset: ' . (time() + $this->getResetTime()));
    }
    
    /**
     * Check and enforce rate limit (throws exception if limited)
     */
    public function check(): void {
        $this->sendHeaders();
        
        if ($this->isLimited()) {
            http_response_code(429);
            header('Retry-After: ' . $this->getResetTime());
            throw new RuntimeException('Rate limit exceeded. Please try again later.');
        }
        
        $this->hit();
    }
}