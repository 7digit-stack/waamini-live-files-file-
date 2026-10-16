<?php
include '../includes/client_auth_check.php';
header('Content-Type: application/json');
$pincodes = array_values(array_filter(array_map(static function ($value) {
	$value = preg_replace('/\D+/', '', trim((string)$value));
	return $value !== '' ? $value : null;
}, explode(',', (string)($_GET['pincodes'] ?? '')))));
$cities = array_values(array_filter(array_map(static function ($value) {
	$value = trim((string)$value);
	$value = preg_replace('/[^\p{L}\p{N}\s\-.,&()]/u', '', $value);
	return $value !== '' ? $value : null;
}, explode(',', (string)($_GET['cities'] ?? '')))));
$where = "company_id=? AND status!='blocked' AND opted_in=1";
$params = [$companyId]; $types = "i";
if (!empty($pincodes)) { $where .= " AND pincode IN (".implode(',',array_fill(0,count($pincodes),'?')).")"; foreach ($pincodes as $p){$params[]=$p;$types.="s";} }
if (!empty($cities)) { $where .= " AND city IN (".implode(',',array_fill(0,count($cities),'?')).")"; foreach ($cities as $c){$params[]=$c;$types.="s";} }
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM contacts WHERE $where");
$stmt->bind_param($types,...$params); $stmt->execute();
echo json_encode(['count'=>intval($stmt->get_result()->fetch_assoc()['c'])]);
