<?php
/**
 * WAAMINI - Authentication Class
 * Secure user authentication with bcrypt
 */

declare(strict_types=1);

class Auth {
    private Database $db;
    private ?array $user = null;
    private int $maxLoginAttempts = 5;
    private int $lockoutTime = 900; // 15 minutes
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Login user
     */
    public function login(string $email, string $password, bool $remember = false): array {
        // Rate limiting check
        $rateLimiter = new RateLimiter('login:' . Security::getClientIp());
        $rateLimiter->check();
        
        // Validate input
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Email and password are required'];
        }
        
        if (!Security::validateEmail($email)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }
        
        // Check if account is locked
        if ($this->isAccountLocked($email)) {
            $lockoutRemaining = $this->getLockoutRemaining($email);
            return [
                'success' => false, 
                'message' => "Account temporarily locked. Try again in {$lockoutRemaining} minutes."
            ];
        }
        
        // Get user by email
        $user = $this->getUserByEmail($email);
        
        if ($user === null) {
            $this->recordFailedAttempt($email);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Verify password
        if (!Security::verifyPassword($password, $user['password'])) {
            $this->recordFailedAttempt($email);
            
            // Check if we need to rehash
            if (Security::passwordNeedsRehash($user['password'])) {
                $this->updatePasswordHash($user['id'], $password);
            }
            
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Check if account is active
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Account is not active'];
        }
        
        // Clear failed attempts
        $this->clearFailedAttempts($email);
        
        // Set session
        $this->setUserSession($user, $remember);
        
        // Update last login
        $this->updateLastLogin($user['id']);
        
        return ['success' => true, 'message' => 'Login successful', 'user' => $this->getUserData($user)];
    }
    
    /**
     * Logout user
     */
    public function logout(): void {
        // Clear session
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Get current user
     */
    public function getUser(): ?array {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        if ($this->user === null) {
            $sql = "SELECT id, name, email, role, status, created_at 
                    FROM users 
                    WHERE id = :id AND status = 'active'";
            $this->user = Database::fetch($sql, ['id' => $_SESSION['user_id']]);
        }
        
        return $this->user;
    }
    
    /**
     * Require authentication (redirect if not logged in)
     */
    public function requireAuth(string $redirectUrl = '/login.php'): void {
        if (!$this->isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole(string $role, string $redirectUrl = '/dashboard.php'): void {
        $this->requireAuth();
        
        $user = $this->getUser();
        
        if ($user === null || $user['role'] !== $role) {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
    
    /**
     * Register new user
     */
    public function register(array $data): array {
        // Validate required fields
        $required = ['name', 'email', 'password', 'password_confirm'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "{$field} is required"];
            }
        }
        
        // Validate email
        if (!Security::validateEmail($data['email'])) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }
        
        // Check password match
        if ($data['password'] !== $data['password_confirm']) {
            return ['success' => false, 'message' => 'Passwords do not match'];
        }
        
        // Validate password strength
        $passwordValidation = $this->validatePasswordStrength($data['password']);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }
        
        // Check if email exists
        if ($this->getUserByEmail($data['email']) !== null) {
            return ['success' => false, 'message' => 'Email already registered'];
        }
        
        // Hash password
        $hashedPassword = Security::hashPassword($data['password']);
        
        // Generate verification token
        $verificationToken = Security::generateToken();
        
        // Insert user
        try {
            Database::beginTransaction();
            
            $sql = "INSERT INTO users (name, email, password, verification_token, status, created_at) 
                    VALUES (:name, :email, :password, :token, 'pending', NOW())";
            
            $userId = Database::insert($sql, [
                'name' => Security::sanitize($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => $hashedPassword,
                'token' => $verificationToken
            ]);
            
            Database::commit();
            
            return [
                'success' => true, 
                'message' => 'Registration successful. Please verify your email.',
                'user_id' => $userId
            ];
            
        } catch (Exception $e) {
            Database::rollback();
            return ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }
    }
    
    /**
     * Validate password strength
     */
    private function validatePasswordStrength(string $password): array {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters'];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number'];
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character'];
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    /**
     * Get user by email
     */
    private function getUserByEmail(string $email): ?array {
        $sql = "SELECT * FROM users WHERE email = :email";
        return Database::fetch($sql, ['email' => strtolower(trim($email))]);
    }
    
    /**
     * Set user session
     */
    private function setUserSession(array $user, bool $remember): void {
        // Regenerate session ID
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = Security::getClientIp();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if ($remember) {
            // Set remember me cookie (30 days)
            $token = Security::generateToken();
            $selector = Security::generateToken(16);
            
            // Store in database
            $sql = "INSERT INTO user_tokens (user_id, selector, token, expires_at) 
                    VALUES (:user_id, :selector, :token, DATE_ADD(NOW(), INTERVAL 30 DAY))";
            Database::query($sql, [
                'user_id' => $user['id'],
                'selector' => $selector,
                'token' => hash('sha256', $token)
            ]);
            
            // Set cookie
            $cookieValue = $selector . ':' . $token;
            setcookie(
                'remember_me',
                $cookieValue,
                time() + (30 * 24 * 60 * 60),
                '/',
                '',
                Security::isHttps(),
                true
            );
        }
    }
    
    /**
     * Check for remember me login
     */
    public function checkRememberMe(): bool {
        if (isset($_COOKIE['remember_me']) && !$this->isLoggedIn()) {
            $parts = explode(':', $_COOKIE['remember_me']);
            
            if (count($parts) === 2) {
                $selector = $parts[0];
                $token = $parts[1];
                
                $sql = "SELECT ut.*, u.id as user_id, u.email, u.role, u.status 
                        FROM user_tokens ut 
                        JOIN users u ON ut.user_id = u.id 
                        WHERE ut.selector = :selector AND ut.expires_at > NOW() AND u.status = 'active'";
                
                $record = Database::fetch($sql, ['selector' => $selector]);
                
                if ($record && hash_equals($record['token'], hash('sha256', $token))) {
                    $_SESSION['user_id'] = $record['user_id'];
                    $_SESSION['user_email'] = $record['email'];
                    $_SESSION['user_role'] = $record['role'];
                    $_SESSION['login_time'] = time();
                    
                    // Extend token expiration
                    $sql = "UPDATE user_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) 
                            WHERE id = :id";
                    Database::query($sql, ['id' => $record['id']]);
                    
                    return true;
                }
            }
            
            // Invalid token - delete cookie
            setcookie('remember_me', '', time() - 3600, '/');
        }
        
        return false;
    }
    
    /**
     * Get safe user data (no sensitive info)
     */
    private function getUserData(array $user): array {
        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
    }
    
    /**
     * Check if account is locked
     */
    private function isAccountLocked(string $email): bool {
        $sql = "SELECT * FROM login_attempts 
                WHERE email = :email 
                AND attempts >= :max_attempts 
                AND last_attempt > DATE_SUB(NOW(), INTERVAL :lockout SECOND)";
        
        $record = Database::fetch($sql, [
            'email' => strtolower(trim($email)),
            'max_attempts' => $this->maxLoginAttempts,
            'lockout' => $this->lockoutTime
        ]);
        
        return $record !== null;
    }
    
    /**
     * Get lockout remaining time
     */
    private function getLockoutRemaining(string $email): int {
        $sql = "SELECT TIMESTAMPDIFF(SECOND, last_attempt, DATE_ADD(NOW(), INTERVAL :lockout SECOND)) as remaining
                FROM login_attempts 
                WHERE email = :email 
                AND attempts >= :max_attempts";
        
        $record = Database::fetch($sql, [
            'email' => strtolower(trim($email)),
            'max_attempts' => $this->maxLoginAttempts,
            'lockout' => $this->lockoutTime
        ]);
        
        return $record ? (int)ceil($record['remaining'] / 60) : 0;
    }
    
    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt(string $email): void {
        $sql = "INSERT INTO login_attempts (email, attempts, last_attempt) 
                VALUES (:email, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                attempts = attempts + 1, 
                last_attempt = NOW()";
        
        Database::query($sql, ['email' => strtolower(trim($email))]);
    }
    
    /**
     * Clear failed attempts
     */
    private function clearFailedAttempts(string $email): void {
        $sql = "DELETE FROM login_attempts WHERE email = :email";
        Database::query($sql, ['email' => strtolower(trim($email))]);
    }
    
    /**
     * Update last login time
     */
    private function updateLastLogin(int $userId): void {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
        Database::query($sql, ['id' => $userId]);
    }
    
    /**
     * Update password hash
     */
    private function updatePasswordHash(int $userId, string $password): void {
        $sql = "UPDATE users SET password = :password WHERE id = :id";
        Database::query($sql, [
            'id' => $userId,
            'password' => Security::hashPassword($password)
        ]);
    }
}