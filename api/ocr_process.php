<?php
// Must load before session_start(): same session.save_path as client pages (bootstrap), otherwise cookie maps to empty session → 401
require_once '../config/bootstrap.php';
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/feature_check.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$companyId = intval($_SESSION['company_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

// No file (often happens when POST exceeds post_max_size — PHP clears $_FILES)
if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'No image received. If the file is large, increase upload_max_filesize and post_max_size in php.ini.',
    ]);
    exit();
}

// Ensure the user has the feature enabled (security check)
if (!has_feature('feat_ocr_scanner')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Plan does not support OCR.']);
    exit();
}

// Get OpenAI API Key
$stmt_ai = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id = ? LIMIT 1");
$stmt_ai->bind_param("i", $companyId);
$stmt_ai->execute();
$botSettings = $stmt_ai->get_result()->fetch_assoc();
if (!$botSettings || empty($botSettings['openai_api_key'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'OpenAI API Key not configured.']);
    exit();
}

if (file_exists(dirname(__DIR__) . '/services/EncryptionService.php')) {
    require_once dirname(__DIR__) . '/services/EncryptionService.php';
}
$apiKey = trim((string)$botSettings['openai_api_key']);
if ($apiKey !== '' && function_exists('decrypt_secret')) {
    $apiKey = trim((string)(decrypt_secret($apiKey) ?? $apiKey));
}
if ($apiKey === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'OpenAI API Key could not be read. Re-save your key on AI Bot settings.']);
    exit();
}
$file = $_FILES['image'];

if (!isset($file['error'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Upload failed (unknown error).']);
    exit();
}

$err = (int)$file['error'];
if ($err !== UPLOAD_ERR_OK) {
    $msg = 'Upload failed.';
    switch ($err) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $msg = 'File is too large for the server (check upload_max_filesize / post_max_size in php.ini).';
            break;
        case UPLOAD_ERR_PARTIAL:
            $msg = 'Upload was interrupted; try again.';
            break;
        case UPLOAD_ERR_NO_FILE:
            $msg = 'No file was sent.';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $msg = 'Server missing temporary folder (upload_tmp_dir).';
            break;
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            $msg = 'Server could not save the upload.';
            break;
        default:
            break;
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit();
}

if (empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Upload temporary file missing or unreadable.']);
    exit();
}

// Prefer real image decode over mime_content_type (Windows often reports octet-stream or generic types).
$imgInfo = @getimagesize($file['tmp_name']);
if ($imgInfo === false) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Could not read image. Use JPG, PNG, or WEBP.']);
    exit();
}

$imaType = (int)($imgInfo[2] ?? 0);
$allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
if (!in_array($imaType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unsupported image type. Only JPG, PNG, or WEBP.']);
    exit();
}

if (($imgInfo[0] ?? 0) < 8 || ($imgInfo[1] ?? 0) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Image is too small (minimum 8×8 pixels).']);
    exit();
}

// is_uploaded_file can be false on some proxy/Windows setups; UPLOAD_ERR_OK + readable tmp is enough for multipart handler
if (!is_uploaded_file($file['tmp_name'])) {
    error_log('[WAAMINI OCR] is_uploaded_file=false path=' . $file['tmp_name']);
}

$mimeType = image_type_to_mime_type($imaType);
// Normalize legacy alias for data URLs
if ($mimeType === 'image/jpg') {
    $mimeType = 'image/jpeg';
}

if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'File size exceeds 5MB limit.']);
    exit();
}

// Convert image to base64
$imageData = base64_encode(file_get_contents($file['tmp_name']));
$dataUrl = "data:{$mimeType};base64,{$imageData}";

// Prepare OpenAI API Payload — JSON object mode + explicit schema reduces parse failures on handwriting
$payload = [
    'model' => 'gpt-4o',
    'messages' => [
        [
            'role' => 'system',
            'content' => <<<'SYS'
You extract contact rows from photos: printed or handwritten business cards, lists, and notes.
Rules:
- Find EVERY phone number visible (mobiles are often 10 digits; may include spaces or +country code).
- For each number, set "name" to the person name OR the text label on the SAME line (e.g. if the line reads "7 digit - 9150802906", name is "7 digit" and phone is the number).
- One JSON object per distinct phone number. Do not skip rows because handwriting looks informal.
- Output must be valid JSON only: an object with key "leads" whose value is an array of objects, each with exactly "name" and "phone" (strings).
- If only a number exists on a line, use name "Unknown".
SYS
        ],
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Read this image carefully (including cursive handwriting). Return JSON with shape {"leads":[{"name":"...","phone":"..."},...]} containing every phone number you see.'
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $dataUrl,
                        'detail' => 'high'
                    ]
                ]
            ]
        ]
    ],
    'max_tokens' => 2048,
    'temperature' => 0.1,
    'response_format' => [
        'type' => 'json_object',
    ],
];

// Call OpenAI
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 90
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    error_log('[WAAMINI OCR] OpenAI failure http=' . $httpCode . ' curl_err=' . $error . ' body_len=' . strlen((string)$response));
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Could not analyze the image. Check your OpenAI API key and quota, then try again.']);
    exit();
}

// Parse Response
$data = json_decode($response, true);
$content = (is_array($data) && is_array($data['choices'] ?? null) && is_array($data['choices'][0]['message'] ?? null)) ? (string)($data['choices'][0]['message']['content'] ?? '') : '';

$parsedLeads = waamini_ocr_parse_lead_rows($content);

// Refusal / empty payload from model
if ($parsedLeads !== null && count($parsedLeads) === 0) {
    error_log('[WAAMINI OCR] Parsed leads array is empty. Raw content sample: ' . substr($content, 0, 600));
}

if ($parsedLeads === null) {
    error_log('[WAAMINI OCR] Could not parse leads from model output: ' . substr($content, 0, 1200));
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Could not read extracted contacts from the AI response. Try another image or crop closer to the text.']);
    exit();
}

// Normalize extracted data — digits-only phones (10-digit Indian mobiles OK)
$leads = [];
foreach ($parsedLeads as $lead) {
    $row = waamini_ocr_normalize_lead_row($lead);
    if ($row['phone'] === '') {
        continue;
    }
    $cleanPhone = preg_replace('/\D+/', '', $row['phone']);
    if (strlen($cleanPhone) < 7 || strlen($cleanPhone) > 15) {
        continue;
    }
    $name = $row['name'] !== '' ? $row['name'] : 'Unknown';
    $name = substr($name, 0, 100);
    $leads[] = [
        'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        'phone' => $cleanPhone,
    ];
}

// Always log one line per scan (inspect storage/logs/error.log — also check Apache logs if this file stays empty)
$finishReason = (is_array($data) && is_array($data['choices'] ?? null)) ? (string)($data['choices'][0]['finish_reason'] ?? '') : '';
$nParsed = is_array($parsedLeads) ? count($parsedLeads) : 0;
$nOut = count($leads);
error_log(sprintf(
    '[WAAMINI OCR] company_id=%d parsed_rows=%d leads_json=%d finish_reason=%s content_chars=%d',
    $companyId,
    $nParsed,
    $nOut,
    $finishReason !== '' ? $finishReason : '-',
    strlen($content)
));
if ($nParsed > 0 && $nOut === 0) {
    $sample = json_encode($parsedLeads[0] ?? null, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    error_log('[WAAMINI OCR] WARN all rows dropped after normalize/phone rules. First parsed row sample: ' . substr((string)$sample, 0, 500));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'success', 'leads' => $leads], JSON_UNESCAPED_UNICODE);

/**
 * Extract lead rows from model message (JSON object, bare array, or fenced blocks).
 *
 * @return array<int, array>|null
 */
function waamini_ocr_parse_lead_rows(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    // Strip markdown fences only (do not delete newlines — breaks JSON)
    $content = preg_replace('/^\s*```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```\s*$/', '', $content);
    $content = trim($content);

    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach (['leads', 'contacts', 'results', 'items', 'data'] as $wrap) {
            if (isset($decoded[$wrap]) && is_array($decoded[$wrap])) {
                return $decoded[$wrap];
            }
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return $decoded;
        }
        if (isset($decoded['phone'])) {
            return [$decoded];
        }
    }

    // Try to isolate a JSON object or array from extra prose
    if (preg_match('/\{[\s\S]*"leads"[\s\S]*\}/', $content, $m)) {
        $decoded = json_decode($m[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['leads']) && is_array($decoded['leads'])) {
            return $decoded['leads'];
        }
    }
    if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
        $decoded = json_decode($m[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * First non-empty trimmed string from ordered keys (fixes PHP ?? stopping on "" which hid phone in "number" etc.).
 */
function waamini_ocr_first_nonempty_string(array $assoc, array $orderedKeys): string
{
    foreach ($orderedKeys as $k) {
        if (!array_key_exists($k, $assoc)) {
            continue;
        }
        $v = $assoc[$k];
        if ($v === null) {
            continue;
        }
        $s = trim((string)$v);
        if ($s !== '') {
            return $s;
        }
    }

    return '';
}

/**
 * @param mixed $lead
 * @return array{name: string, phone: string}
 */
function waamini_ocr_normalize_lead_row($lead): array
{
    if (is_string($lead)) {
        $try = json_decode($lead, true);
        $lead = is_array($try) ? $try : [];
    }
    if (!is_array($lead)) {
        return ['name' => '', 'phone' => ''];
    }

    // Unwrap one level: {"contact": {"name":"...","phone":"..."}}
    foreach (['contact', 'person', 'details', 'entry', 'lead'] as $nest) {
        foreach ($lead as $k => $v) {
            if (strtolower((string)$k) === $nest && is_array($v)) {
                $lead = array_merge($lead, $v);
                break;
            }
        }
    }

    $lc = array_change_key_case($lead, CASE_LOWER);

    $phone = waamini_ocr_first_nonempty_string($lc, [
        'phone', 'mobile', 'tel', 'telephone', 'whatsapp', 'phone_number', 'phonenumber', 'number', 'cell',
    ]);

    if ($phone === '') {
        $phone = waamini_ocr_scan_array_for_phone_string($lead);
    }

    $name = waamini_ocr_first_nonempty_string($lc, [
        'name', 'label', 'contact', 'person', 'fullname', 'full_name', 'title',
    ]);

    return [
        'name' => trim((string)$name),
        'phone' => trim((string)$phone),
    ];
}

/**
 * Find any string in nested arrays that looks like a phone number.
 */
function waamini_ocr_scan_array_for_phone_string(array $arr): string
{
    $walker = static function ($v) use (&$walker): array {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $x) {
                $out = array_merge($out, $walker($x));
            }
        } elseif (is_scalar($v) && (string)$v !== '') {
            $out[] = (string)$v;
        }
        return $out;
    };

    foreach ($walker($arr) as $s) {
        $digits = preg_replace('/\D+/', '', $s);
        if ($digits !== null && strlen($digits) >= 7 && strlen($digits) <= 15) {
            return $s;
        }
    }

    return '';
}
