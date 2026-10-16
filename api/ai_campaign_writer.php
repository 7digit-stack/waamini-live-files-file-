<?php
include '../includes/client_auth_check.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'POST only']); exit(); }
csrf_verify();

$stmt_ai = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id=? AND is_active=1 LIMIT 1");
$stmt_ai->bind_param("i", $companyId);
$stmt_ai->execute();
$ai = $stmt_ai->get_result()->fetch_assoc();
if (!$ai || empty($ai['openai_api_key'])) { echo json_encode(['error'=>'OpenAI not configured']); exit(); }

$goal    = sanitize($_POST['goal'] ?? 'sale');
$biz     = sanitize($_POST['biz'] ?? 'business');
$details = sanitize($_POST['details'] ?? '');
$tone    = sanitize($_POST['tone'] ?? 'Friendly');
$lang    = sanitize($_POST['lang'] ?? 'English');

$goalMap = ['sale'=>'announce a sale/discount offer','launch'=>'announce a new product launch','event'=>'invite customers to an event or webinar','followup'=>'re-engage inactive customers','festive'=>'send festive greetings with an offer','reminder'=>'remind customers about something important','custom'=>'send a custom message'];
$goalDesc = $goalMap[$goal] ?? $goal;

$prompt = "You are an expert WhatsApp marketing copywriter for Indian businesses. Write 3 different WhatsApp campaign messages for a $biz that wants to $goalDesc.

Details: $details
Tone: $tone
Language: $lang

Requirements:
- Max 300 characters each (WhatsApp limit)
- Use emojis naturally
- Include a clear call-to-action
- WhatsApp bold formatting: *bold text*
- Keep it conversational, not salesy

Reply ONLY with a JSON array of exactly 3 message strings. No markdown, no explanation.";

$payload = json_encode(['model'=>'gpt-3.5-turbo','messages'=>[['role'=>'user','content'=>$prompt]],'max_tokens'=>500,'temperature'=>0.8]);
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$ai['openai_api_key'],'Content-Type: application/json']]);
$resp = curl_exec($ch); curl_close($ch);

$data = json_decode($resp, true);
if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
    echo json_encode(['error' => 'AI returned invalid response. Try again.']);
    exit();
}
$text = (string)($data['choices'][0]['message']['content'] ?? '[]');
$text = trim(preg_replace('/```json|```/', '', $text));
$messages = json_decode($text, true);
if (!is_array($messages)) {
    echo json_encode(['error' => 'AI returned invalid response. Try again.']);
    exit();
}

echo json_encode(['messages' => array_slice($messages, 0, 3)]);
