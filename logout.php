<?php
require_once __DIR__ . '/config/bootstrap.php';

// Remove token from DB if cookie exists
if (isset($_COOKIE['remember_me'])) {
    require_once __DIR__ . '/db.php';
    $parts = explode(':', $_COOKIE['remember_me']);
    if (count($parts) === 2 && isset($conn)) {
        $selector = $parts[0];
        $stmt = $conn->prepare("DELETE FROM user_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param("s", $selector);
            $stmt->execute();
        }
    }
    require_once __DIR__ . '/includes/security.php';
    setcookie('remember_me', '', time() - 3600, '/', '', Security::isHttps(), true);
}

session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
