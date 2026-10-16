<?php
/**
 * WAAMINI - Campaign Trigger Helper
 * Handles sending queued messages when user activity is detected.
 */

function trigger_queued_messages($conn, $phone)
{
    // 1. Find pending messages for this phone that are waiting for trigger
    // scheduled_at <= NOW() ensures we don't send future-scheduled messages prematurely
    $stmt = $conn->prepare(
        "SELECT q.*, c.whatsapp_token, c.whatsapp_phone_id
         FROM campaign_queue q
         JOIN companies c ON q.company_id = c.id
         WHERE q.phone_number = ? 
         AND q.status = 'pending'
         AND q.trigger_on_activity = 1
         AND q.scheduled_at <= NOW()
         LIMIT 10"
    );

    // Clean phone number (remove +)
    $cleanPhone = str_replace('+', '', $phone);
    $stmt->bind_param("s", $cleanPhone);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return 0;
    }

    $triggeredCount = 0;

    // Load API version (VULN-013 FIX: Prepared Statement)
    $stVer = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key='wa_default_api_version' LIMIT 1");
    $apiVersion = 'v17.0';
    if ($stVer) {
        $stVer->execute();
        $vrow = $stVer->get_result()->fetch_assoc();
        if ($vrow) $apiVersion = $vrow['setting_value'];
    }

    while ($msgJob = $result->fetch_assoc()) {
        $msgId = intval($msgJob['id']);
        $campaignId = intval($msgJob['campaign_id']);
        $token = $msgJob['whatsapp_token'];
        $phoneId = $msgJob['whatsapp_phone_id'];

        if (empty($token) || empty($phoneId))
            continue;

        $payloadData = json_decode($msgJob['message_payload'], true);
        if (!$payloadData)
            continue;

        // Mark as processing (VULN-013 FIX: Prepared Statement)
        $stP = $conn->prepare("UPDATE campaign_queue SET status='processing' WHERE id=?");
        if ($stP) {
            $stP->bind_param("i", $msgId);
            $stP->execute();
        }

        $graphPayload = [
            'messaging_product' => 'whatsapp',
            'to' => $cleanPhone,
            'type' => 'template',
            'template' => [
                'name' => $payloadData['template_name'],
                'language' => ['code' => $payloadData['template_language']]
            ]
        ];

        $components = [];
        $body_parameters = [];
        if (!empty($payloadData['field_1']))
            $body_parameters[] = ['type' => 'text', 'text' => $payloadData['field_1']];
        if (!empty($payloadData['field_2']))
            $body_parameters[] = ['type' => 'text', 'text' => $payloadData['field_2']];
        if (!empty($body_parameters))
            $components[] = ['type' => 'body', 'parameters' => $body_parameters];
        if (!empty($components))
            $graphPayload['template']['components'] = $components;

        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneId}/messages";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer " . trim($token), "Content-Type: application/json"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($graphPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseObj = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($responseObj['messages'])) {
            // VULN-013 FIX: Prepared Statement
            $stS = $conn->prepare("UPDATE campaign_queue SET status='sent' WHERE id=?");
            if ($stS) {
                $stS->bind_param("i", $msgId);
                $stS->execute();
            }
            if ($campaignId) {
                $stC = $conn->prepare("UPDATE campaigns SET sent_count = sent_count + 1 WHERE id=?");
                if ($stC) {
                    $stC->bind_param("i", $campaignId);
                    $stC->execute();
                }
            }
            $triggeredCount++;
        }
        else {
            // VULN-006 FIX: Prepared Statement for error_message
            $errorMsg = $responseObj['error']['message'] ?? "HTTP $httpCode";
            $stF = $conn->prepare("UPDATE campaign_queue SET status='failed', error_message=? WHERE id=?");
            if ($stF) {
                $stF->bind_param("si", $errorMsg, $msgId);
                $stF->execute();
            }
        }
    }

    return $triggeredCount;
}
