<?php
/**
 * Media proxy — streams WhatsApp voice notes / media from Meta CDN.
 * URL: client/ajax_media_proxy.php?id=MEDIA_ID
 * Only serves media belonging to the authenticated company.
 */
include '../includes/client_auth_check.php';

$mediaId = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
if ($mediaId === '') {
    http_response_code(400);
    exit('Bad request');
}

// Load WhatsApp config for this company
require_once __DIR__ . '/../includes/whatsapp_settings.php';
require_once __DIR__ . '/../services/EncryptionService.php';

$cfgStmt = $conn->prepare(
    "SELECT access_token, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1"
);
$cfgStmt->bind_param("i", $companyId);
$cfgStmt->execute();
$cfg = $cfgStmt->get_result()->fetch_assoc();
$cfgStmt->close();

if (!$cfg || empty($cfg['access_token'])) {
    http_response_code(503);
    exit('WhatsApp not configured');
}

$token  = waamini_whatsapp_decrypt_config_token($cfg);
$apiVer = (string)($cfg['api_version'] ?: 'v20.0');

// Step 1 — get the temporary download URL from Meta
$metaUrl = "https://graph.facebook.com/{$apiVer}/{$mediaId}";
$ch = curl_init($metaUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
curl_close($ch);

$meta = json_decode((string)$resp, true);
$downloadUrl  = (string)($meta['url']       ?? '');
$mimeType     = (string)($meta['mime_type'] ?? 'audio/ogg');

if ($downloadUrl === '') {
    $apiErr = (string)($meta['error']['message'] ?? '');
    $code   = (strpos($apiErr, 'token') !== false || strpos($apiErr, 'expired') !== false || strpos($apiErr, 'Invalid') !== false) ? 401 : 404;
    error_log('[WAAMINI PROXY] Media fetch failed id=' . $mediaId . ' err=' . $apiErr);
    http_response_code($code);
    exit('Media unavailable: ' . ($apiErr ?: 'ID may have expired'));
}

// Step 2 — stream the audio back to the browser
$ch2 = curl_init($downloadUrl);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$audioData = curl_exec($ch2);
$httpCode  = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($httpCode !== 200 || $audioData === false || $audioData === '') {
    http_response_code(502);
    exit('Failed to fetch media');
}

header('Content-Type: '  . $mimeType);
header('Content-Length: ' . strlen($audioData));
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
echo $audioData;
