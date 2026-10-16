<?php
/**
 * WAAMINI - Main Configuration
 * Version: 2.1.0 (Optimized to prevent double-parsing)
 */

declare(strict_types=1);

if (!defined('WAAMINI_LOADED')) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(403);
        die('Direct access forbidden');
    }
    define('WAAMINI_LOADED', true);
}

require_once __DIR__ . '/env.php';
if (file_exists(dirname(__DIR__) . '/.env') && getenv('APP_ENV') === false) {
    \Waamini\Config\Env::load(dirname(__DIR__) . '/.env');
}

class AppConfig {
    private static ?self $instance = null;
    private array $config = [];
    
    private function __construct() {
        $this->setErrorReporting();
        $this->setDefaults();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function setErrorReporting(): void {
        $env = $this->get('APP_ENV', 'production');
        
        if ($env === 'production') {
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', $this->get('LOG_PATH', dirname(__DIR__) . '/storage/logs/error.log'));
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
    }
    
    private function setDefaults(): void {
        $this->config = [
            'app' => [
                'env' => $this->get('APP_ENV', 'production'),
                'debug' => $this->get('APP_DEBUG', 'false') === 'true',
                'url' => $this->get('APP_URL', 'http://localhost'),
                'timezone' => $this->get('APP_TIMEZONE', 'UTC'),
                'key' => $this->get('ENCRYPTION_KEY', ''),
            ],
            'db' => [
                'host' => $this->get('DB_HOST', '127.0.0.1'),
                'port' => (int)$this->get('DB_PORT', '3306'),
                'name' => $this->get('DB_NAME', 'waamini_db'),
                'user' => $this->get('DB_USER', 'root'),
                'pass' => $this->get('DB_PASS', ''),
            ],
            'whatsapp' => [
                'api_version' => $this->get('WHATSAPP_API_VERSION', 'v18.0'),
                'phone_number_id' => $this->get('WHATSAPP_PHONE_NUMBER_ID', ''),
                'business_account_id' => $this->get('WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
                'access_token' => $this->get('WHATSAPP_ACCESS_TOKEN', ''),
                'verify_token' => $this->get('WHATSAPP_VERIFY_TOKEN', ''),
                'webhook_url' => $this->get('WHATSAPP_WEBHOOK_URL', ''),
                'base_url' => 'https://graph.facebook.com/' . $this->get('WHATSAPP_API_VERSION', 'v18.0'),
            ],
            'security' => [
                'session_lifetime' => (int)$this->get('SESSION_LIFETIME', '7200'),
                'csrf_lifetime' => (int)$this->get('CSRF_TOKEN_LIFETIME', '3600'),
                'rate_limit_max' => (int)$this->get('RATE_LIMIT_MAX', '100'),
                'rate_limit_window' => (int)$this->get('RATE_LIMIT_WINDOW', '60'),
            ],
        ];
    }
    
    /**
     * Safely get env variable (relies on env.php having already loaded them)
     */
    public function get(string $key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value !== false && $value !== null && $value !== '') {
            return $value;
        }

        $aliases = [
            'WHATSAPP_ACCESS_TOKEN' => ['WA_ACCESS_TOKEN'],
            'WHATSAPP_PHONE_NUMBER_ID' => ['WA_BUSINESS_PHONE_NUMBER_ID'],
            'WHATSAPP_VERIFY_TOKEN' => ['WA_WEBHOOK_VERIFY_TOKEN'],
            'WHATSAPP_API_VERSION' => ['WA_API_VERSION'],
        ];

        foreach ($aliases[$key] ?? [] as $alias) {
            $aliasValue = $_ENV[$alias] ?? getenv($alias);
            if ($aliasValue !== false && $aliasValue !== null && $aliasValue !== '') {
                return $aliasValue;
            }
        }

        return $default;
    }
    
    public function getSection(string $section): array {
        return $this->config[$section] ?? [];
    }
    
    public function all(): array {
        return $this->config;
    }
    
    private function __clone() {}
}

// Initialize configuration
 $config = AppConfig::getInstance();

// Set timezone
date_default_timezone_set($config->get('APP_TIMEZONE', 'UTC'));

// Define paths
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('API_PATH', ROOT_PATH . '/api');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('LOGS_PATH', ROOT_PATH . '/logs');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
