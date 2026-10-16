<?php
/** Landing API — Public Site Settings */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=600');

$publicKeys = ['site_name','tagline','support_email','logo_url','footer_text','og_image','twitter_handle','linkedin_url','instagram_url','facebook_url'];
$placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
$stmt = $conn->prepare("SELECT `key`,`value` FROM lp_settings WHERE `key` IN ($placeholders)");
$out = array_fill_keys($publicKeys, '');
if ($stmt) {
    $stmt->bind_param(str_repeat('s', count($publicKeys)), ...$publicKeys);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $out[$r['key']] = $r['value'];
    $stmt->close();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
