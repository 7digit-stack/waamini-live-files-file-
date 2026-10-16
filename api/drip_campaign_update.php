<?php
require_once __DIR__ . '/../includes/client_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_feature('feat_drip_campaign')) {
    csrf_verify();

    $id                     = (int)($_POST['id'] ?? 0);
    $companyId              = (int)$_SESSION['company_id'];
    $globalRandomization     = strtolower((string)($_POST['global_randomization'] ?? 'none'));
    $respectBusinessHours    = (int)($_POST['respect_business_hours'] ?? 0);
    $maxMessagesPerDay       = (int)($_POST['max_messages_per_day'] ?? 10);
    $cooldownHours           = (int)($_POST['cooldown_hours'] ?? 4);

    $allowedModes = ['none', 'minimal', 'human', 'ghost', 'chaos'];
    if (!in_array($globalRandomization, $allowedModes, true)) {
        $globalRandomization = 'none';
    }

    $maxMessagesPerDay = max(1, min(50, $maxMessagesPerDay));
    $cooldownHours     = max(1, min(72, $cooldownHours));

    $check = $conn->prepare("SELECT id FROM drip_campaigns WHERE id=? AND company_id=? LIMIT 1");
    if ($check) {
        $check->bind_param('ii', $id, $companyId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $upd = $conn->prepare(
                "UPDATE drip_campaigns SET
                    global_randomization = ?,
                    respect_business_hours = ?,
                    max_messages_per_day = ?,
                    cooldown_hours = ?
                 WHERE id = ? AND company_id = ?"
            );
            if ($upd) {
                $upd->bind_param('siiiii', $globalRandomization, $respectBusinessHours, $maxMessagesPerDay, $cooldownHours, $id, $companyId);
                $upd->execute();
                $upd->close();
            }
        }
        $check->close();
    }
}

header("Location: ../client/premium_drip_builder.php?id=" . $id);
exit();
