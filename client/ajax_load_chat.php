<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/wa_chat_helpers.php';
require_once __DIR__ . '/../includes/chat_message_render.php';
wa_chat_ensure_meta_column($conn);

$cid = isset($_GET['c']) ? (int)$_GET['c'] : 0;
if ($cid <= 0) {
    exit();
}

$afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
$asJson = isset($_GET['json']) && (string)$_GET['json'] === '1';

$own = $conn->prepare('SELECT id FROM contacts WHERE id = ? AND company_id = ? LIMIT 1');
if (!$own) {
    exit();
}
$own->bind_param('ii', $cid, $companyId);
$own->execute();
if (!$own->get_result()->fetch_assoc()) {
    exit();
}

// 1. Mark as read — prepared statements
$stUpd1 = $conn->prepare('UPDATE chat_conversations SET unread_count = 0 WHERE contact_id = ? AND company_id = ?');
if ($stUpd1) {
    $stUpd1->bind_param('ii', $cid, $companyId);
    $stUpd1->execute();
}

$stUpd2 = $conn->prepare("UPDATE messages SET is_read = 1 WHERE contact_id = ? AND company_id = ? AND direction = 'incoming'");
if ($stUpd2) {
    $stUpd2->bind_param('ii', $cid, $companyId);
    $stUpd2->execute();
    // Bust the header badge session cache so the red dot clears immediately
    unset($_SESSION['_unread_count_'.$companyId], $_SESSION['_unread_count_at_'.$companyId]);
}

// 2. Fetch messages — prepared statement
$sql = 'SELECT id, direction, type, content, message, media_url, meta_json, status, error_message, created_at
        FROM messages
        WHERE contact_id=? AND company_id=?';
$types = 'ii';
$params = [$cid, $companyId];
if ($afterId > 0) {
    $sql .= ' AND id > ?';
    $types .= 'i';
    $params[] = $afterId;
}
$sql .= ' ORDER BY id ASC LIMIT 50';

$stMsg = $conn->prepare($sql);
if (!$stMsg) {
    exit();
}
$stMsg->bind_param($types, ...$params);
$stMsg->execute();
$messages = $stMsg->get_result();

$html = '';
$lastIdOut = $afterId;
if ($messages && $messages->num_rows > 0) {
    while ($m = $messages->fetch_assoc()) {
        $lastIdOut = max($lastIdOut, (int)($m['id'] ?? 0));
        $html .= wa_chat_row_html($m);
    }
}

if ($asJson) {
    // Current ticks of the latest sent messages, so ✓ → ✓✓ → blue ✓✓ updates live like WhatsApp.
    $ticks = [];
    $stT = $conn->prepare("SELECT id, status, error_message FROM messages WHERE contact_id=? AND company_id=? AND direction='outgoing' ORDER BY id DESC LIMIT 40");
    if ($stT) {
        $stT->bind_param('ii', $cid, $companyId);
        $stT->execute();
        foreach ($stT->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $ticks[(int)$t['id']] = wa_tick_html((string)$t['status'], (string)($t['error_message'] ?? ''));
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => $html, 'last_id' => $lastIdOut, 'ticks' => (object)$ticks], JSON_UNESCAPED_UNICODE);
    exit();
}

echo $html;
