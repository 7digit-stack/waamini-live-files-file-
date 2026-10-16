<?php
/**
 * WAAMINI - Dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Auth.php';

Security::init();
 $auth = new Auth();
 $auth->requireAuth('/login.php?expired=1');

 $user = $auth->getUser();

// Get dashboard stats
 $stats = [
    'contacts' => Database::fetch("SELECT COUNT(*) as total FROM contacts WHERE status = 'active'")['total'] ?? 0,
    'messages_today' => Database::fetch("SELECT COUNT(*) as total FROM messages WHERE DATE(created_at) = CURDATE()")['total'] ?? 0,
    'active_campaigns' => Database::fetch("SELECT COUNT(*) as total FROM campaigns WHERE status IN ('running', 'scheduled')")['total'] ?? 0,
    'open_tickets' => Database::fetch("SELECT COUNT(*) as total FROM tickets WHERE status IN ('open', 'in_progress')")['total'] ?? 0
];

// Get recent messages
 $recentMessages = Database::fetchAll(
    "SELECT m.*, c.name as contact_name 
     FROM messages m 
     LEFT JOIN contacts c ON m.wa_phone = c.phone 
     ORDER BY m.created_at DESC LIMIT 10"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WAAMINI</title>
    <?php echo Security::csrfMeta(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; }
        .sidebar .nav-link { color: #adb5bd; padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background-color: #495057; }
        .sidebar .nav-link i { margin-right: 10px; }
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .icon { font-size: 2.5rem; }
        .message-incoming { border-left: 4px solid #25D366; }
        .message-outgoing { border-left: 4px solid #007bff; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 p-0 sidebar">
                <div class="p-3 text-white">
                    <h4>WAAMINI</h4>
                </div>
                <nav class="nav flex-column">
                    <a href="/dashboard.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    <a href="/contacts.php" class="nav-link"><i class="bi bi-people"></i> Contacts</a>
                    <a href="/campaigns.php" class="nav-link"><i class="bi bi-megaphone"></i> Campaigns</a>
                    <a href="/chatbot.php" class="nav-link"><i class="bi bi-robot"></i> Chatbot</a>
                    <a href="/messages.php" class="nav-link"><i class="bi bi-chat-dots"></i> Messages</a>
                    <a href="/tickets.php" class="nav-link"><i class="bi bi-ticket"></i> Tickets</a>
                    <a href="/settings.php" class="nav-link"><i class="bi bi-gear"></i> Settings</a>
                </nav>
                <div class="position-absolute bottom-0 p-3 w-100">
                    <div class="text-white small">
                        <i class="bi bi-person-circle"></i> <?php echo Security::escape($user['name']); ?>
                    </div>
                    <a href="/api/auth.php?action=logout" class="nav-link small"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Dashboard</h2>
                    <span class="text-muted"><?php echo date('l, F j, Y'); ?></span>
                </div>
                
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Contacts</h6>
                                        <h3><?php echo number_format((int)$stats['contacts']); ?></h3>
                                    </div>
                                    <div class="icon text-primary"><i class="bi bi-people-fill"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Messages Today</h6>
                                        <h3><?php echo number_format((int)$stats['messages_today']); ?></h3>
                                    </div>
                                    <div class="icon text-success"><i class="bi bi-chat-dots-fill"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Active Campaigns</h6>
                                        <h3><?php echo number_format((int)$stats['active_campaigns']); ?></h3>
                                    </div>
                                    <div class="icon text-warning"><i class="bi bi-megaphone-fill"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="text-muted">Open Tickets</h6>
                                        <h3><?php echo number_format((int)$stats['open_tickets']); ?></h3>
                                    </div>
                                    <div class="icon text-danger"><i class="bi bi-ticket-fill"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Messages -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Messages</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Contact</th>
                                        <th>Direction</th>
                                        <th>Message</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentMessages)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted p-4">No messages yet</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentMessages as $msg): ?>
                                            <tr class="<?php echo $msg['direction'] === 'incoming' ? 'message-incoming' : 'message-outgoing'; ?>">
                                                <td>
                                                    <strong><?php echo Security::escape($msg['contact_name'] ?? $msg['wa_phone']); ?></strong>
                                                    <br><small class="text-muted"><?php echo Security::escape($msg['wa_phone']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $msg['direction'] === 'incoming' ? 'bg-success' : 'bg-primary'; ?>">
                                                        <?php echo ucfirst($msg['direction']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo Security::escape(substr($msg['content'] ?? '', 0, 50)) . (strlen($msg['content'] ?? '') > 50 ? '...' : ''); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo ucfirst($msg['status']); ?></span></td>
                                                <td><small><?php echo date('M j, g:i A', strtotime($msg['created_at'])); ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>