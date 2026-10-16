<?php
/** Landing API — Features  GET (no params) */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$res = $conn->query("SELECT id,icon,title,description,badge,sort_order FROM lp_features WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
$features = [];
if ($res) while ($r = $res->fetch_assoc()) $features[] = ['id'=>(int)$r['id'],'icon'=>$r['icon'],'title'=>$r['title'],'description'=>$r['description'],'badge'=>$r['badge']];
echo json_encode(['features' => $features], JSON_UNESCAPED_UNICODE);
