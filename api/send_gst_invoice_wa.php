<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/whatsapp_settings.php';
csrf_verify($_GET['csrf'] ?? null);

$invId = intval($_GET['invoice_id'] ?? 0);
if ($invId <= 0) {
    header("Location: ../client/gst_invoices.php?error=invalid");
    exit();
}
$stmt = $conn->prepare("SELECT gi.*, c.name as contact_name, c.phone FROM gst_invoices gi JOIN contacts c ON gi.contact_id=c.id WHERE gi.id=? AND gi.company_id=?");
$stmt->bind_param("ii", $invId, $companyId); $stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
if (!$inv) { header("Location: ../client/gst_invoices.php?error=notfound"); exit(); }

$stmt_gs = $conn->prepare("SELECT * FROM gst_settings WHERE company_id=?");
$stmt_gs->bind_param("i", $companyId); $stmt_gs->execute();
$gs = $stmt_gs->get_result()->fetch_assoc();

$items = json_decode($inv['items_json'], true) ?: [];
$itemLines = implode("\n", array_map(fn($it) => "• {$it['name']} x{$it['qty']} = ₹".number_format($it['amount'],2), $items));

$msg  = "🧾 *Invoice from " . ($gs['business_name']??'') . "*\n\n";
$msg .= "Invoice #: *{$inv['invoice_number']}*\n";
$msg .= "Date: " . date('d M Y', strtotime($inv['invoice_date'])) . "\n\n";
$msg .= $itemLines . "\n\n";
$msg .= "━━━━━━━━━━━━━\n";
if ($inv['cgst_amount'] > 0) $msg .= "CGST: ₹" . number_format($inv['cgst_amount'],2) . "\nSGST: ₹" . number_format($inv['sgst_amount'],2) . "\n";
if ($inv['igst_amount'] > 0) $msg .= "IGST: ₹" . number_format($inv['igst_amount'],2) . "\n";
$msg .= "💰 *Total: ₹" . number_format($inv['total_amount'],2) . "*\n\n";
if (!empty($gs['upi_id'])) $msg .= "📱 Pay via UPI: *{$gs['upi_id']}*\n";
if (!empty($gs['bank_name'])) $msg .= "🏦 Bank: {$gs['bank_name']} | A/C: {$gs['account_number']} | IFSC: {$gs['ifsc_code']}\n";
$msg .= "\n" . ($gs['footer_note'] ?? 'Thank you for your business! 🙏');

// Get WA config
$stmt_wc = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? AND status='active' LIMIT 1");
$stmt_wc->bind_param("i", $companyId); $stmt_wc->execute();
$wc = $stmt_wc->get_result()->fetch_assoc();

if (!$wc) { header("Location: ../client/gst_invoices.php?error=nowaconfig"); exit(); }

$apiVer = $wc['api_version'] ?: 'v18.0';
$accessToken = waamini_whatsapp_decrypt_config_token($wc);
$payload = json_encode(['messaging_product'=>'whatsapp','to'=>$inv['phone'],'type'=>'text','text'=>['body'=>$msg]]);
$ch = curl_init("https://graph.facebook.com/{$apiVer}/{$wc['phone_number_id']}/messages");
curl_setopt_array($ch,[
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken,'Content-Type: application/json']
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

if ($code === 200) {
    $stmt_upd = $conn->prepare("UPDATE gst_invoices SET wa_sent=1, wa_sent_at=NOW(), status=IF(status='draft','sent',status) WHERE id=?");
    $stmt_upd->bind_param("i", $invId); $stmt_upd->execute();
    header("Location: ../client/gst_invoice_view.php?id=$invId&msg=wa_sent"); exit();
} else {
    header("Location: ../client/gst_invoice_view.php?id=$invId&msg=wa_error"); exit();
}
