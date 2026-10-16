<?php
/**
 * WAAMINI - Login Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Auth.php';

Security::init();
 $auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $auth->logout();
    header('Location: /login.php?logged_out=1');
    exit;
}

 $error = '';
 $success = '';

if (isset($_GET['logged_out'])) {
    $success = 'You have been logged out successfully.';
}

if (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please log in again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WAAMINI</title>
    <?php echo Security::csrfMeta(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .login-container { max-width: 400px; margin: 100px auto; }
        .card { border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .logo { font-size: 2rem; font-weight: bold; color: #25D366; }
        .btn-primary { background-color: #25D366; border-color: #25D366; }
        .btn-primary:hover { background-color: #128C7E; border-color: #128C7E; }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <div class="logo">WAAMINI</div>
                    <p class="text-muted">WhatsApp Marketing Platform</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo Security::escape($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo Security::escape($success); ?></div>
                <?php endif; ?>
                
                <form id="loginForm" method="POST" action="/api/auth.php?action=login">
                    <?php echo Security::csrfField(); ?>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="email" name="email" required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" value="true">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="loginSpinner"></span>
                            Login
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-3">
                    <a href="/forgot-password.php" class="text-muted">Forgot password?</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('loginBtn');
            const spinner = document.getElementById('loginSpinner');
            
            btn.disabled = true;
            spinner.classList.remove('d-none');
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch('/api/auth.php?action=login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': formData.get('_token')
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = result.redirect || '/dashboard.php';
                } else {
                    alert(result.message);
                    btn.disabled = false;
                    spinner.classList.add('d-none');
                }
            } catch (error) {
                alert('An error occurred. Please try again.');
                btn.disabled = false;
                spinner.classList.add('d-none');
            }
        });
    </script>
</body>
</html>