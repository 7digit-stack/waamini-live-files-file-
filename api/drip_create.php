<?php
require_once '../includes/client_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_feature('feat_drip_campaign')) {
    csrf_verify();
    $name = trim((string)($_POST['name'] ?? ''));
    $triggerLabel = trim((string)($_POST['trigger_label'] ?? ''));
    $companyId = $_SESSION['company_id'];

    if (!empty($name) && !empty($triggerLabel)) {
        // Default to active so a newly created campaign works immediately.
        $stmt = $conn->prepare("INSERT INTO drip_campaigns (company_id, name, trigger_label, status) VALUES (?, ?, ?, 'active')");
        $stmt->bind_param("iss", $companyId, $name, $triggerLabel);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            header("Location: ../client/premium_drip_builder.php?id=" . $newId);
            exit();
        }
        else {
            error_log('[WAAMINI] drip_create failed: ' . $stmt->error);
            http_response_code(500);
            exit('Unable to create drip campaign.');
        }
    }
    else {
        http_response_code(400);
        echo "Missing required fields.";
        exit;
    }
}
header("Location: ../client/premium_drip.php");
exit();
?>
