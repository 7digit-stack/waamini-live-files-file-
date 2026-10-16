<?php
/**
 * Flow Builder media upload (image / video / document for a "Send message" step).
 * POST multipart: file, kind = image|video|document. CSRF token in the X-CSRF-Token header.
 * The file is stored under uploads/flows/<company_id>/ with a random name and returned as an absolute URL,
 * because WhatsApp downloads the media from that URL when the step runs.
 */
include '../includes/client_auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fu_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fu_out(['ok' => false, 'error' => 'POST only'], 405);
csrf_verify();
if (!has_feature('feat_chatbot_builder')) fu_out(['ok' => false, 'error' => 'Flow Builder is not part of your plan'], 403);

$companyId = (int)$companyId;
$kind = (string)($_POST['kind'] ?? '');
// extension => allowed MIME types (checked against the real file content, not the browser's claim)
$rules = [
    'image'    => ['max' => 5,  'types' => ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']]],
    'video'    => ['max' => 16, 'types' => ['mp4' => ['video/mp4'], '3gp' => ['video/3gpp', 'video/3gp']]],
    'document' => ['max' => 20, 'types' => [
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword', 'application/octet-stream'], 'xls' => ['application/vnd.ms-excel', 'application/octet-stream'], 'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    ]],
];
if (!isset($rules[$kind])) fu_out(['ok' => false, 'error' => 'Unknown file type'], 400);

$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    fu_out(['ok' => false, 'error' => ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ? 'The file is too large. Max ' . $rules[$kind]['max'] . ' MB.' : 'No file received.'], 400);
}
if ((int)$f['size'] > $rules[$kind]['max'] * 1024 * 1024) fu_out(['ok' => false, 'error' => 'The file is too large. Max ' . $rules[$kind]['max'] . ' MB.'], 413);

$ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
if (!isset($rules[$kind]['types'][$ext])) fu_out(['ok' => false, 'error' => 'Allowed ' . $kind . ' files: ' . strtoupper(implode(', ', array_keys($rules[$kind]['types'])))], 415);
$mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : '';
if ($mime !== '' && !in_array($mime, $rules[$kind]['types'][$ext], true)) fu_out(['ok' => false, 'error' => 'The file content does not match its type (' . $mime . ').'], 415);

$dir = dirname(__DIR__) . '/uploads/flows/' . $companyId . '/';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fu_out(['ok' => false, 'error' => 'Could not create the upload folder.'], 500);
$guard = dirname(__DIR__) . '/uploads/flows/.htaccess';
if (!file_exists($guard)) @file_put_contents($guard, "# uploaded media only: never execute anything here\nOptions -Indexes -ExecCGI\nphp_flag engine off\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py)$\">\n  Require all denied\n</FilesMatch>\n");

$name = 'flow_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . $name)) fu_out(['ok' => false, 'error' => 'Could not save the file.'], 500);

// absolute URL that WhatsApp can open
$base = rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
if ($base === '' || preg_match('~^https?://(localhost|127\.)~i', $base)) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$url = $base . '/uploads/flows/' . $companyId . '/' . $name;
$host = (string)parse_url($url, PHP_URL_HOST);
$public = !preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host) && strpos($host, '.') !== false && strpos($url, 'https://') === 0;

fu_out(['ok' => true, 'url' => $url, 'name' => mb_substr(preg_replace('/[^\w.\- ]/u', '_', (string)$f['name']), 0, 120), 'size' => (int)$f['size'], 'public' => $public]);
