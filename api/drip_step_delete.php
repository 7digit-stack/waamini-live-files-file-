<?php
require_once '../includes/client_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_feature('feat_drip_campaign')) {
    csrf_verify();
    $stepId = intval($_POST['id']);
    $campaignId = intval($_POST['campaign_id']);
    $companyId = $_SESSION['company_id'];

    if ($stepId > 0 && $campaignId > 0) {
        $check = $conn->prepare("SELECT id FROM drip_campaigns WHERE id=? AND company_id=?");
        $check->bind_param("ii", $campaignId, $companyId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $stmt = $conn->prepare("DELETE FROM drip_steps WHERE id = ? AND drip_campaign_id = ?");
            $stmt->bind_param("ii", $stepId, $campaignId);
            $stmt->execute();

            // Also delete any pending executions for this step
            $stmt2 = $conn->prepare("DELETE FROM drip_executions WHERE drip_step_id = ? AND company_id = ?");
            $stmt2->bind_param("ii", $stepId, $companyId);
            $stmt2->execute();
        }
    }
}
header("Location: ../client/premium_drip_builder.php?id=" . $campaignId);
exit();
?>
