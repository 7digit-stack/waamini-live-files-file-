<?php
/** Landing API — Hero Section  GET ?page=home */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$page = trim((string)($_GET['page'] ?? 'home'));
$page = preg_replace('/[^a-z0-9_-]/', '', strtolower($page)) ?: 'home';

$stmt = $conn->prepare("SELECT * FROM lp_hero WHERE page=? AND is_active=1 LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'db_error']); exit; }
$stmt->bind_param('s', $page);
$stmt->execute();
$h = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$h) { http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

echo json_encode([
    'page'             => $h['page'],
    'announce_text'    => $h['announce_text'],
    'headline'         => $h['headline'],
    'highlight_word'   => $h['highlight_word'],
    'subtext'          => $h['subtext'],
    'cta_primary_text' => $h['cta_primary_text'],
    'cta_primary_url'  => $h['cta_primary_url'],
    'cta_sec_text'     => $h['cta_sec_text'],
    'cta_sec_url'      => $h['cta_sec_url'],
    'stats'            => json_decode($h['stats_json'] ?? '[]', true) ?: [],
    'updated_at'       => $h['updated_at'],
], JSON_UNESCAPED_UNICODE);
