<?php
/**
 * WAAMINI - Entry Point
 * Routes to login or dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Auth.php';

Security::init();
 $auth = new Auth();

// Check remember me
if (!$auth->isLoggedIn()) {
    $auth->checkRememberMe();
}

// Route based on auth status
if ($auth->isLoggedIn()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;