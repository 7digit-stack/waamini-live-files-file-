<?php
include '../includes/auth_check.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'keepalive') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code(204);
    exit;
}
include '../db.php';
include_once '../includes/security.php';
require_once '../includes/waamini_bot/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required.']); exit; }
csrf_verify();
$provider = (string)($_POST['provider'] ?? '');
[$ok, $models, $error] = WB_OpenAI::listProviderModels($conn, $provider);
echo json_encode(['ok'=>$ok,'models'=>$models,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
