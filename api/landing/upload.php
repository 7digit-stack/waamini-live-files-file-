<?php
/**
 * Landing API — Authenticated Image Upload
 * POST /api/landing/upload.php
 * Requires: landing admin session (la_session)
 * Returns: { url, id, filename, size }
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

// Landing admin auth
if (session_status() === PHP_SESSION_NONE) {
    session_name('la_session');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (empty($_SESSION['la_admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Must be POST with file
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Error check
if ($file['error'] !== UPLOAD_ERR_OK) {
    $msgs = [UPLOAD_ERR_INI_SIZE=>'File too large (server limit)',UPLOAD_ERR_FORM_SIZE=>'File too large (form limit)',UPLOAD_ERR_NO_TMP_DIR=>'No temp directory',UPLOAD_ERR_CANT_WRITE=>'Cannot write file'];
    http_response_code(400);
    echo json_encode(['error' => $msgs[$file['error']] ?? 'Upload error']);
    exit;
}

// Size limit: 5 MB
if ($file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File must be under 5 MB']);
    exit;
}

// MIME check via finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowedMimes = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP']);
    exit;
}

$extensions = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
$ext = $extensions[$mime];

// Destination: assets/uploads/blog/{year}/{month}/
$year  = date('Y');
$month = date('m');
$uploadRel = "assets/uploads/blog/$year/$month";
$uploadAbs = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadRel);

if (!is_dir($uploadAbs) && !mkdir($uploadAbs, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot create upload directory']);
    exit;
}

// Sanitized unique filename
$origName = pathinfo($file['name'], PATHINFO_FILENAME);
$origName = preg_replace('/[^a-z0-9_-]/i', '-', $origName);
$origName = strtolower(substr($origName, 0, 60));
$filename = $origName . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadAbs . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

    $publicUrl = "/$uploadRel/$filename";
$fileSize  = filesize($destPath);
$adminId   = (int)$_SESSION['la_admin_id'];

// Save to lp_media
$st = $conn->prepare("INSERT INTO lp_media (filename,url,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?)");
$st->bind_param('sssii', $filename, $publicUrl, $mime, $fileSize, $adminId);
$st->execute();
$mediaId = (int)$conn->insert_id;
$st->close();

echo json_encode([
    'ok'       => true,
    'id'       => $mediaId,
    'url'      => $publicUrl,
    'filename' => $filename,
    'mime'     => $mime,
    'size'     => $fileSize,
], JSON_UNESCAPED_UNICODE);
