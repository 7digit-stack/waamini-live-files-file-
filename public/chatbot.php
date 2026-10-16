<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Auth.php';

Security::init();
 $auth = new Auth();
 $auth->requireAuth('/login.php?expired=1');
 $user = $auth->getUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot - WAAMINI</title>
    <?php echo Security::csrfMeta(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; }
        .sidebar .nav-link { color: #adb5bd; padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background-color: #495057; }
        .sidebar .nav-link i { margin-right: 10px; }
        .rule-row:hover { background-color: #f8f9fa; }
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
                    <a href="/chatbot.php" class="nav-link active"><i class="bi bi-robot"></i> Chatbot</a>
                    <a href="/messages.php" class="nav-link"><i class="bi bi-chat-dots"></i> Messages</a>
                    <a href="/settings.php" class="nav-link"><i class="bi bi-gear"></i> Settings</a>
                </nav>
            </div>
            
            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Chatbot Rules</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ruleModal">
                        <i class="bi bi-plus-lg"></i> Add Rule
                    </button>
                </div>

                <div class="card">
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    <th>Name</th>
                                    <th>Pattern</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="rulesTable">
                                <!-- Populated by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rule Modal -->
    <div class="modal fade" id="ruleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="ruleForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Add/Edit Rule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="ruleId">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Rule Name</label>
                                <input type="text" class="form-control" id="ruleName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority (Higher = First)</label>
                                <input type="number" class="form-control" id="rulePriority" value="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Trigger Pattern (Text or /Regex/)</label>
                            <input type="text" class="form-control" id="rulePattern" placeholder="e.g., hello|hi or /price(.*)/i" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Response Type</label>
                            <select class="form-select" id="ruleType">
                                <option value="text">Simple Text</option>
                                <option value="interactive_list">Interactive List</option>
                                <option value="interactive_button">Interactive Buttons</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Response Content (JSON for interactive)</label>
                            <textarea class="form-control" id="ruleContent" rows="5" required>{ "text": "Hello! How can I help?" }</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken };

        async function loadRules() {
            const res = await fetch('/api/chatbot.php?action=list_rules');
            const data = await res.json();
            const tbody = document.getElementById('rulesTable');
            tbody.innerHTML = data.data.map(r => `
                <tr class="rule-row">
                    <td><span class="badge bg-secondary">${r.priority}</span></td>
                    <td>${r.name}</td>
                    <td><code>${r.pattern}</code></td>
                    <td>${r.response_type}</td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" ${r.status === 'active' ? 'checked' : ''} 
                                   onchange="toggleRule(${r.id}, this.checked)">
                        </div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="editRule(${r.id})"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRule(${r.id})"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `).join('');
        }

        document.getElementById('ruleForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('ruleId').value;
            const action = id ? 'update_rule' : 'create_rule';
            const payload = {
                id, name: document.getElementById('ruleName').value,
                pattern: document.getElementById('rulePattern').value,
                response_type: document.getElementById('ruleType').value,
                response_content: JSON.parse(document.getElementById('ruleContent').value),
                priority: document.getElementById('rulePriority').value
            };
            
            await fetch(`/api/chatbot.php?action=${action}`, { method: 'POST', headers, body: JSON.stringify(payload) });
            bootstrap.Modal.getInstance(document.getElementById('ruleModal')).hide();
            loadRules();
        });

        async function editRule(id) {
            const res = await fetch(`/api/chatbot.php?action=list_rules`);
            const data = await res.json();
            const rule = data.data.find(r => r.id === id);
            if(rule) {
                document.getElementById('ruleId').value = rule.id;
                document.getElementById('ruleName').value = rule.name;
                document.getElementById('rulePattern').value = rule.pattern;
                document.getElementById('ruleType').value = rule.response_type;
                document.getElementById('ruleContent').value = JSON.stringify(rule.response_content, null, 2);
                document.getElementById('rulePriority').value = rule.priority;
                new bootstrap.Modal(document.getElementById('ruleModal')).show();
            }
        }

        async function deleteRule(id) {
            if(confirm('Delete this rule?')) {
                await fetch('/api/chatbot.php?action=delete_rule', { method: 'POST', headers, body: JSON.stringify({id}) });
                loadRules();
            }
        }

        async function toggleRule(id, status) {
            await fetch('/api/chatbot.php?action=toggle_status', { method: 'POST', headers, body: JSON.stringify({id, status: status ? 'active' : 'inactive'}) });
        }

        loadRules();
    </script>
</body>
</html>