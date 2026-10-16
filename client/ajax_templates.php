<?php
/**
 * Live list of the company's APPROVED templates (same source as the Templates page).
 * Used by Create Campaign to keep its template picker in step with the platform.
 */
ob_start();
include '../includes/client_auth_check.php';
ob_clean();

// Read-only endpoint: release the session lock so polling never blocks other requests.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($companyId) || !$companyId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'templates' => []]);
    exit();
}

$out = [];
$stmt = $conn->prepare("SELECT id, name, language, category, header_type, header_text, header_link, body, body_text, footer, footer_text, buttons
                        FROM message_templates
                        WHERE (company_id=? OR company_id IS NULL) AND wa_status='APPROVED'
                        ORDER BY name ASC");
if ($stmt) {
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($t = $res->fetch_assoc())) {
        $out[] = [
            'id'          => (int)$t['id'],
            'name'        => (string)$t['name'],
            'language'    => (string)($t['language'] ?? 'en'),
            'category'    => strtolower((string)($t['category'] ?? 'marketing')),
            'header_type' => strtolower((string)($t['header_type'] ?? 'none')),
            'header_text' => (string)($t['header_text'] ?? ''),
            'header_link' => (string)($t['header_link'] ?? ''),
            'body'        => (string)(($t['body'] ?? '') !== '' ? $t['body'] : ($t['body_text'] ?? '')),
            'footer'      => (string)(($t['footer'] ?? '') !== '' ? $t['footer'] : ($t['footer_text'] ?? '')),
            'buttons'     => (string)($t['buttons'] ?? ''),
        ];
    }
    $stmt->close();
}

echo json_encode(['ok' => true, 'at' => time(), 'templates' => $out], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
