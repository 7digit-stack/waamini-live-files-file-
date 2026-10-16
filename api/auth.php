<?php
/**
 * WAAMINI - Authentication API
 * Endpoints: login, register, logout, check
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ratelimiter.php';
require_once __DIR__ . '/../includes/auth.php';

// Initialize Security
Security::init();

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');

// Get action from URL parameter
 $action = $_GET['action'] ?? '';

try {
    $auth = new Auth();
    
    switch ($action) {
        case 'login':
            handleLogin($auth);
            break;
            
        case 'register':
            handleRegister($auth);
            break;
            
        case 'logout':
            handleLogout($auth);
            break;
            
        case 'check':
            handleCheck($auth);
            break;
            
        case 'forgot-password':
            handleForgotPassword($auth);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Rate limit exceeded. Please try again later.') {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
} catch (Exception $e) {
    error_log('[AUTH API ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

/**
 * Handle Login
 */
function handleLogin(Auth $auth): void {
    // Validate request method
    if (!Security::validateMethod('POST')) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    // Get input
    $input = getInput();
    
    // CSRF Validation for web forms
    if (!Security::isAjax()) {
        if (!Security::validateCsrf($input['_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
            return;
        }
    } else {
        // For AJAX, check header
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_token'] ?? '';
        if (!Security::validateCsrf($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            return;
        }
    }
    
    // Sanitize and validate
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    $remember = isset($input['remember']) && $input['remember'] === 'true';
    
    // Attempt login
    $result = $auth->login($email, $password, $remember);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'user' => $result['user'],
            'redirect' => '/dashboard.php'
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
}

/**
 * Handle Registration
 */
function handleRegister(Auth $auth): void {
    if (!Security::validateMethod('POST')) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $input = getInput();
    
    // CSRF Validation
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_token'] ?? '';
    if (!Security::validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        return;
    }
    
    $result = $auth->register($input);
    
    if ($result['success']) {
        http_response_code(201);
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
}

/**
 * Handle Logout
 */
function handleLogout(Auth $auth): void {
    $auth->logout();
    
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => '/login.php'
    ]);
}

/**
 * Handle Session Check
 */
function handleCheck(Auth $auth): void {
    if ($auth->isLoggedIn()) {
        $user = $auth->getUser();
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'user' => $user
        ]);
    } else {
        // Check remember me
        if ($auth->checkRememberMe()) {
            $user = $auth->getUser();
            echo json_encode([
                'success' => true,
                'authenticated' => true,
                'user' => $user
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'authenticated' => false
            ]);
        }
    }
}

/**
 * Handle Forgot Password
 */
function handleForgotPassword(Auth $auth): void {
    if (!Security::validateMethod('POST')) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $input = getInput();
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    
    if (!Security::validateEmail($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid email is required']);
        return;
    }
    
    // Rate limit
    $rateLimiter = new RateLimiter('forgot:' . Security::getClientIp());
    $rateLimiter->check();
    
    // Generate reset token
    $token = Security::generateToken();
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
    
    $sql = "UPDATE users SET reset_token = :token, reset_token_expires = :expires WHERE email = :email";
    Database::query($sql, [
        'token' => $token,
        'expires' => $expires,
        'email' => $email
    ]);
    
    // In production, send email with reset link
    // For now, just return success to prevent email enumeration
    echo json_encode([
        'success' => true,
        'message' => 'If an account with that email exists, a reset link has been sent.'
    ]);
}

/**
 * Get JSON input
 */
function getInput(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
    
    return $_POST;
}
