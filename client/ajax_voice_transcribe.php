<?php
/**
 * Transcribe one voice note on demand (the "Transcribe" button in WhatsApp Chat).
 * POST: message_id, _token. Returns { ok, text } or { ok:false, error }.
 * Tenant scoped: the message must belong to the logged-in company.
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/voice_ai.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
csrf_verify();

$messageId = (int)($_POST['message_id'] ?? 0);
if ($messageId <= 0) { echo json_encode(['ok' => false, 'error' => 'message_id required']); exit; }
if (!voice_ai_available($conn)) {
    echo json_encode(['ok' => false, 'error' => 'Voice transcription is not set up yet. Ask your WAAMINI admin to add a free Groq or Google Gemini key.']);
    exit;
}
// on-demand: no bot reply, the agent is reading the chat
$r = voice_ai_process_message($conn, (int)$companyId, $messageId, false);
echo json_encode($r['ok'] ? ['ok' => true, 'text' => (string)$r['text']] : ['ok' => false, 'error' => (string)$r['error']], JSON_UNESCAPED_UNICODE);
