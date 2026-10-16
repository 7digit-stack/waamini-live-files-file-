<?php
// Called by Exotel/Knowlarity when missed call received
header('Content-Type: application/json');
require_once '../db.php';
$companyId = intval($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'missing company_id']);
    exit;
}
// Get caller number from various provider formats
$callerNum = preg_replace('/[^0-9]/', '', $_POST['CallFrom'] ?? $_POST['caller_id_number'] ?? $_POST['from'] ?? $_GET['caller'] ?? '');
if (strlen($callerNum) < 10) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}
if (strlen($callerNum) === 10) $callerNum = '91' . $callerNum;

// Get settings
$stmt_s = $conn->prepare("SELECT mcs.*, c.name as biz_name FROM missed_call_settings mcs JOIN companies c ON mcs.company_id=c.id WHERE mcs.company_id=? AND mcs.is_active=1 LIMIT 1");
if (!$stmt_s) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}
$stmt_s->bind_param("i",$companyId);
$stmt_s->execute();
$sRes = $stmt_s->get_result();
$s = $sRes ? $sRes->fetch_assoc() : null;
if (!$s) {
    http_response_code(200);
    echo json_encode(['status' => 'disabled']);
    exit;
}

// Log it
$stmt_log = $conn->prepare("INSERT INTO missed_call_logs (company_id,caller_number,called_at) VALUES (?,?,NOW())");
$stmt_log->bind_param("is",$companyId,$callerNum); $stmt_log->execute();
$logId = $stmt_log->insert_id;

// Find or create contact
$stmt_ct = $conn->prepare("SELECT id, name FROM contacts WHERE company_id=? AND phone=? LIMIT 1");
$stmt_ct->bind_param("is",$companyId,$callerNum); $stmt_ct->execute();
$ct = $stmt_ct->get_result()->fetch_assoc();
if (!$ct) {
    $stmt_nc = $conn->prepare("INSERT INTO contacts (company_id,phone,name) VALUES (?,?,'Unknown')");
    $stmt_nc->bind_param("is",$companyId,$callerNum); $stmt_nc->execute();
    $contactId = $stmt_nc->insert_id; $firstName = 'there';
} else { $contactId = $ct['id']; $firstName = explode(' ',$ct['name'])[0]; }

$msg = str_replace(['{{business_name}}','{{first_name}}'],[$s['biz_name'],$firstName],$s['message']??"Hi! We missed your call. How can we help? Reply here!");

// Queue the reply instead of direct send
$stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status, phone) VALUES (?, ?, 'missed_call_reply', ?, 'pending', ?)");
if ($stmt_q) {
    $stmt_q->bind_param("iiss", $companyId, $contactId, $msg, $callerNum);
    $stmt_q->execute();
    $upd = $conn->prepare("UPDATE missed_call_logs SET wa_sent=1, wa_sent_at=NOW() WHERE id=?");
    if ($upd) {
        $lid = (int)$logId;
        $upd->bind_param('i', $lid);
        $upd->execute();
    }
}
echo json_encode(['status'=>'ok']);
