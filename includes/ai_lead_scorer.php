<?php
/**
 * AI Lead Scorer — uses OpenAI to score 0-100 based on chat history
 * Called after every incoming message
 */
function ai_score_lead($conn, $companyId, $contactId)
{
    // Get OpenAI key
    $stmt = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id=? AND is_active=1 LIMIT 1");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $ai = $stmt->get_result()->fetch_assoc();
    if (!$ai || empty($ai['openai_api_key'])) return;

    // Get last 10 messages
    $stmt_m = $conn->prepare("SELECT direction, message FROM messages WHERE contact_id=? AND company_id=? ORDER BY id DESC LIMIT 10");
    $stmt_m->bind_param("ii", $contactId, $companyId);
    $stmt_m->execute();
    $msgs = $stmt_m->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($msgs)) return;

    $history = implode("\n", array_map(fn($m) => strtoupper($m['direction']) . ": " . $m['message'], array_reverse($msgs)));

    $prompt = "You are a sales lead scoring AI. Based on this WhatsApp conversation, score the lead from 0-100 (100 = very hot, ready to buy). Also detect sentiment: positive/neutral/negative.\n\nConversation:\n$history\n\nReply ONLY with JSON: {\"score\": 75, \"sentiment\": \"positive\", \"reason\": \"one sentence\"}";

    $payload = json_encode([
        'model' => 'gpt-3.5-turbo',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 100,
        'temperature' => 0.3,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $ai['openai_api_key'], 'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    $result = json_decode(trim($text), true);

    if (isset($result['score'])) {
        $score = max(0, min(100, intval($result['score'])));
        $sentiment = in_array($result['sentiment'] ?? '', ['positive','neutral','negative']) ? $result['sentiment'] : 'neutral';
        $stmt_upd = $conn->prepare("UPDATE contacts SET ai_lead_score=?, ai_sentiment=?, ai_score_updated=NOW() WHERE id=? AND company_id=?");
        $stmt_upd->bind_param("isii", $score, $sentiment, $contactId, $companyId);
        $stmt_upd->execute();
    }
}
