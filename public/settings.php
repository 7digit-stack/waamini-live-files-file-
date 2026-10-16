<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Auth.php';

Security::init();
 $auth = new Auth();
 $auth->requireAuth('/login.php?expired=1');
 $user = $auth->getUser();

// Fetch initial settings
 $whatsappSettings = Database::fetchAll("SELECT * FROM settings WHERE category = 'whatsapp'");
 $generalSettings = Database::fetchAll("SELECT * FROM settings WHERE category = 'general'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - WAAMINI</title>
    <?php echo Security::csrfMeta(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; }
        .sidebar .nav-link { color: #adb5bd; padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background-color: #495057; }
        .sidebar .nav-link i { margin-right: 10px; }
        .settings-section { margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 p-0 sidebar">
                <div class="p-3 text-white"><h4>WAAMINI</h4></div>
                <nav class="nav flex-column">
                    <a href="/dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    <a href="/contacts.php" class="nav-link"><i class="bi bi-people"></i> Contacts</a>
                    <a href="/campaigns.php" class="nav-link"><i class="bi bi-megaphone"></i> Campaigns</a>
                    <a href="/chatbot.php" class="nav-link"><i class="bi bi-robot"></i> Chatbot</a>
                    <a href="/messages.php" class="nav-link"><i class="bi bi-chat-dots"></i> Messages</a>
                    <a href="/settings.php" class="nav-link active"><i class="bi bi-gear"></i> Settings</a>
                </nav>
            </div>
            
            <div class="col-md-10 p-4">
                <h2 class="mb-4">System Settings</h2>
                
                <div id="alertContainer"></div>

                <!-- WhatsApp Settings -->
                <div class="card settings-section">
                    <div class="card-header bg-white"><h5 class="mb-0">WhatsApp Configuration</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number ID</label>
                                <input type="text" class="form-control" id="waPhoneId" value="<?php echo Security::escape(AppConfig::getInstance()->get('WHATSAPP_PHONE_NUMBER_ID')); ?>" readonly>
                                <div class="form-text">Set via .env file</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Webhook URL</label>
                                <input type="text" class="form-control" id="waWebhook" value="<?php echo Security::escape(AppConfig::getInstance()->get('WHATSAPP_WEBHOOK_URL')); ?>" readonly>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Greeting Message</label>
                            <textarea class="form-control" id="greeting_message" rows="3"><?php 
                                foreach($whatsappSettings as $s) if($s['key']==='greeting_message') echo Security::escape($s['value']); 
                            ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Offline Message</label>
                            <textarea class="form-control" id="offline_message" rows="3"><?php 
                                foreach($whatsappSettings as $s) if($s['key']==='offline_message') echo Security::escape($s['value']); 
                            ?></textarea>
                        </div>
                        <button class="btn btn-success" onclick="testConnection()">
                            <i class="bi bi-plug"></i> Test API Connection
                        </button>
                    </div>
                </div>

                <!-- General Settings -->
                <div class="card settings-section">
                    <div class="card-header bg-white"><h5 class="mb-0">General Settings</h5></div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_reply" <?php 
                                foreach($whatsappSettings as $s) if($s['key']==='auto_reply_enabled' && $s['value']==='1') echo 'checked'; 
                            ?>>
                            <label class="form-check-label" for="auto_reply">Enable Auto-Reply</label>
                        </div>
                        <button class="btn btn-primary" onclick="saveSettings()">
                            <i class="bi bi-save"></i> Save All Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken };

        function showAlert(msg, type) {
            document.getElementById('alertContainer').innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">${msg}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;
        }

        async function saveSettings() {
            const settings = {
                greeting_message: document.getElementById('greeting_message').value,
                offline_message: document.getElementById('offline_message').value,
                auto_reply_enabled: document.getElementById('auto_reply').checked ? '1' : '0'
            };
            
            const res = await fetch('/api/settings.php?action=save', { method: 'POST', headers, body: JSON.stringify({settings}) });
            const data = await res.json();
            showAlert(data.message, data.success ? 'success' : 'danger');
        }

        async function testConnection() {
            showAlert('Testing connection...', 'info');
            const res = await fetch('/api/settings.php?action=test_whatsapp');
            const data = await res.json();
            showAlert(data.message, data.success ? 'success' : 'danger');
        }
    </script>
</body>
</html>