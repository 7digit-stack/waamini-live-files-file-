<?php
/**
 * Waamini Audit Log Helper
 */
function waamini_audit_log($conn, $action, $companyId = null, $description = null) {
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    if (is_array($description)) {
        $description = json_encode($description);
    }
    
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, company_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iisss", $userId, $companyId, $action, $description, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
