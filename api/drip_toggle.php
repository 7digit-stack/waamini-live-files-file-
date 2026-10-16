<?php
require_once '../includes/client_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_feature('feat_drip_campaign')) {
    csrf_verify();
    $id = intval($_POST['id']);
    $companyId = $_SESSION['company_id'];

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT status, trigger_label FROM drip_campaigns WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $id, $companyId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $currentStatus = (string)($row['status'] ?? '');
            $triggerLabel = (string)($row['trigger_label'] ?? '');
            $newStatus = ($currentStatus === 'active') ? 'paused' : 'active';

            $update = $conn->prepare("UPDATE drip_campaigns SET status = ? WHERE id = ? AND company_id = ?");
            $update->bind_param("sii", $newStatus, $id, $companyId);
            $update->execute();

            // When activating, schedule for existing contacts that already have the trigger label.
            if ($newStatus === 'active' && $triggerLabel !== '') {
                require_once __DIR__ . '/../includes/drip_engine.php';
                // Check BOTH chat_labels and tags columns for the trigger label
                $cStmt = $conn->prepare("SELECT id, chat_labels, tags FROM contacts WHERE company_id=? AND ((chat_labels IS NOT NULL AND TRIM(chat_labels) != '') OR (tags IS NOT NULL AND TRIM(tags) != ''))");
                if ($cStmt) {
                    $cStmt->bind_param('i', $companyId);
                    $cStmt->execute();
                    $cRes = $cStmt->get_result();
                    if ($cRes) {
                        while ($c = $cRes->fetch_assoc()) {
                            $cid = (int)($c['id'] ?? 0);
                            $l1 = (string)($c['chat_labels'] ?? '');
                            $l2 = (string)($c['tags'] ?? '');
                            $combined = trim($l1 . ',' . $l2, ',');
                            if ($cid > 0 && $combined !== '') {
                                trigger_drip_campaigns($conn, (int)$companyId, $cid, $combined);
                            }
                        }
                    }
                    $cStmt->close();
                }
            }
        }
    }
}
header("Location: ../client/premium_drip.php");
exit();
?>
