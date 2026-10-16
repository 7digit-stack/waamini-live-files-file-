<?php
// AI Bot Manual Processor AJAX Handler
include '../includes/client_auth_check.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Verify CSRF token if present
    if (isset($_POST['_token'])) {
        if (empty($_SESSION['csrf_token']) || $_POST['_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid token');
        }
    }
    
    // Get company ID from session
    $companyId = $_SESSION['company_id'] ?? 0;
    if (!$companyId) {
        throw new Exception('Company not found');
    }
    
    // Check if AI bot feature is enabled
    if (!has_feature('ai_chatbot')) {
        throw new Exception('AI Bot feature not enabled');
    }
    
    // Fetch AI bot settings
    $stmt = $conn->prepare("SELECT * FROM ai_bot_settings WHERE company_id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $botSettings = $stmt->get_result()->fetch_assoc();
    
    if (!$botSettings || !$botSettings['is_active']) {
        throw new Exception('AI Bot is not active');
    }
    
    if (empty($botSettings['openai_api_key'])) {
        throw new Exception('OpenAI API Key not configured');
    }
    
    // Run the processor (placeholder logic)
    // This would call your actual bot processor logic
    $result = [
        'success' => true,
        'message' => 'Manual processor completed successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
