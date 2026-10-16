<?php
/**
 * WAAMINI Loyalty Engine
 * Tracks points and triggers rewards
 */

function add_loyalty_points($companyId, $contactId, $points, $reason = "Interaction")
{
    global $conn;

    // 1. Check if Loyalty is active for this company
    $stmt = $conn->prepare("SELECT * FROM loyalty_settings WHERE company_id = ? AND is_active = 1");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    if (!$settings)
        return false;

    if ($points <= 0)
        return false;

    // 2. Update Contact Points
    $stmtU = $conn->prepare("UPDATE contacts SET loyalty_points = loyalty_points + ? WHERE id = ?");
    $stmtU->bind_param("ii", $points, $contactId);
    $stmtU->execute();

    // 3. Log the change
    $stmtL = $conn->prepare("INSERT INTO loyalty_logs (company_id, contact_id, points, reason) VALUES (?, ?, ?, ?)");
    $stmtL->bind_param("iids", $companyId, $contactId, $points, $reason);
    $stmtL->execute();

    // 4. Check for Reward Threshold
    $stmtC = $conn->prepare("SELECT name, phone, loyalty_points FROM contacts WHERE id = ?");
    $stmtC->bind_param("i", $contactId);
    $stmtC->execute();
    $contact = $stmtC->get_result()->fetch_assoc();
    $currentPoints = $contact['loyalty_points'];
    $threshold = $settings['reward_threshold'];

    if ($currentPoints >= $threshold) {
        // Trigger Reward Message if not already sent for this threshold
        // To prevent spam, we only send if points just crossed the threshold
        if (($currentPoints - $points) < $threshold) {
            send_loyalty_reward($companyId, $contact, $settings);
        }
    }

    return true;
}

function send_loyalty_reward($companyId, $contact, $settings)
{
    global $conn;
    $rewardMsg = str_replace("{{POINTS}}", round($contact['loyalty_points']), $settings['reward_message'] ?? '');

    if (empty($rewardMsg))
        return;

    // Queue WhatsApp Message
    $stmtM = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, message, type) VALUES (?, ?, 'outgoing', ?, 'text')");
    $stmtM->bind_param("iis", $companyId, $contact['id'], $rewardMsg);
    $stmtM->execute();

    // Log the reward event
    $stmtLR = $conn->prepare("INSERT INTO loyalty_logs (company_id, contact_id, points, reason) VALUES (?, ?, ?, ?)");
    $points_zero = 0;
    $reason_reward = '🏆 Reward Milestone Reached';
    $stmtLR->bind_param("iiis", $companyId, $contact['id'], $points_zero, $reason_reward);
    $stmtLR->execute();
}
?>
