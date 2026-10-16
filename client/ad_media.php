<?php
/**
 * Serves a stored ad thumbnail (storage/ad_media/{company_id}/…) to the company that owns it.
 * storage/ is blocked from the web, so this auth-checked endpoint is the only way to view the file.
 *   ad_media.php?r=<ad_referrals.id>   or   ad_media.php?ad=<ad source_id>
 */
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../services/MediaVaultService.php';
require_once __DIR__ . '/../includes/ads/schema.php';

ads_ensure_schema($conn);
$companyId = (int)($_SESSION['company_id'] ?? 0);
$path = '';
if (!empty($_GET['r'])) {
    $id = (int)$_GET['r'];
    $st = $conn->prepare("SELECT local_media_path FROM ad_referrals WHERE id=? AND company_id=? LIMIT 1");
    $st->bind_param('ii', $id, $companyId);
    $st->execute();
    $path = (string)($st->get_result()->fetch_assoc()['local_media_path'] ?? '');
} elseif (!empty($_GET['ad'])) {
    $sid = substr(preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$_GET['ad']), 0, 64);
    $st = $conn->prepare("SELECT COALESCE(c.local_thumbnail_path, (SELECT r.local_media_path FROM ad_referrals r WHERE r.company_id=c.company_id AND r.source_id=c.source_id AND r.local_media_path IS NOT NULL ORDER BY r.id DESC LIMIT 1)) AS p
        FROM ad_catalog c WHERE c.company_id=? AND c.source_id=? LIMIT 1");
    $st->bind_param('is', $companyId, $sid);
    $st->execute();
    $path = (string)($st->get_result()->fetch_assoc()['p'] ?? '');
}

// The stored path must sit in this company's own folder.
$abs = ($path !== '' && strpos($path, 'ad_media/' . $companyId . '/') === 0) ? MediaVaultService::absPath($path) : '';
if ($abs === '') {
    http_response_code(404);
    exit();
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', '3gp' => 'video/3gpp', 'mov' => 'video/quicktime'];
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($abs);
