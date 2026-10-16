<?php
/**
 * Authenticated lead import endpoint for CRMs and automation platforms.
 * Accepts one contact or a bounded batch and never queues WhatsApp messages.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

function crm_lead_sync_respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function crm_lead_sync_string($value, int $maxLength): ?string {
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $value = trim((string)$value);
    if (strlen($value) > $maxLength) {
        return null;
    }
    return $value;
}

function crm_lead_sync_bind(mysqli_stmt $stmt, string $types, array &$values): bool {
    $args = [$types];
    foreach ($values as $key => &$value) {
        $args[] = &$value;
    }
    unset($value);
    return call_user_func_array([$stmt, 'bind_param'], $args);
}

function crm_lead_sync_tags($value): ?string {
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $items = explode(',', $value);
    } else {
        return null;
    }

    $tags = [];
    foreach ($items as $item) {
        if (!is_string($item) && !is_numeric($item)) {
            return null;
        }
        $tag = trim((string)$item);
        if ($tag === '') {
            continue;
        }
        if (strlen($tag) > 60) {
            return null;
        }
        $tags[strtolower($tag)] = $tag;
    }
    return implode(', ', array_values($tags));
}

function crm_lead_sync_prepare_contact(array $input, string $defaultSource, int $index): array {
    $errors = [];
    $phoneRaw = $input['phone'] ?? $input['phone_number'] ?? $input['mobile'] ?? null;
    $phone = is_string($phoneRaw) || is_numeric($phoneRaw) ? sanitize_phone((string)$phoneRaw) : '';
    $digits = preg_replace('/\D+/', '', $phone);
    if ($phone === '' || !is_string($digits) || strlen($digits) < 8 || strlen($digits) > 15) {
        $errors[] = 'phone must contain 8 to 15 digits, including country code';
    }

    $firstName = crm_lead_sync_string($input['first_name'] ?? '', 100);
    $lastName = crm_lead_sync_string($input['last_name'] ?? '', 100);
    $name = crm_lead_sync_string($input['name'] ?? '', 255);
    if ($firstName === null || $lastName === null || $name === null) {
        $errors[] = 'name, first_name, or last_name is too long or invalid';
    }
    if ($name === '' && ($firstName !== '' || $lastName !== '')) {
        $name = trim($firstName . ' ' . $lastName);
    }

    $email = crm_lead_sync_string($input['email'] ?? '', 255);
    if ($email === null || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        $errors[] = 'email must be a valid email address of at most 255 characters';
    }

    $tags = null;
    if (array_key_exists('tags', $input)) {
        $tags = crm_lead_sync_tags($input['tags']);
        if ($tags === null || strlen($tags) > 2000) {
            $errors[] = 'tags must be a comma-separated string or array with values of at most 60 characters';
        }
    }

    $source = crm_lead_sync_string($input['source'] ?? $defaultSource, 50);
    if ($source === null) {
        $errors[] = 'source must be at most 50 characters';
    }
    $status = crm_lead_sync_string($input['status'] ?? '', 50);
    if ($status === null) {
        $errors[] = 'status must be at most 50 characters';
    }

    return [
        'index' => $index,
        'errors' => $errors,
        'phone' => $phone,
        'name' => $name,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'tags' => $tags,
        'source' => $source,
        'status' => $status,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    crm_lead_sync_respond(405, ['status' => 'error', 'message' => 'Use POST with a JSON body.']);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    crm_lead_sync_respond(415, ['status' => 'error', 'message' => 'Content-Type must be application/json.']);
}

$apiKey = trim((string)($_SERVER['HTTP_X_WAAMINI_API_KEY'] ?? ''));
if ($apiKey === '' && preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $matches)) {
    $apiKey = trim($matches[1]);
}
if ($apiKey === '') {
    crm_lead_sync_respond(401, ['status' => 'error', 'message' => 'Provide the API key in X-WAAMINI-API-Key or Authorization: Bearer.']);
}

$auth = $conn->prepare('SELECT company_id FROM api_keys WHERE api_key=? AND is_active=1 LIMIT 1');
if (!$auth) {
    error_log('[WAAMINI CRM LEAD SYNC] Failed to prepare API-key lookup: ' . $conn->error);
    crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'Could not authenticate the request.']);
}
$auth->bind_param('s', $apiKey);
if (!$auth->execute()) {
    error_log('[WAAMINI CRM LEAD SYNC] API-key lookup failed: ' . $auth->error);
    $auth->close();
    crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'Could not authenticate the request.']);
}
$authResult = $auth->get_result();
$authRow = $authResult ? $authResult->fetch_assoc() : null;
$auth->close();
if (!$authRow) {
    crm_lead_sync_respond(403, ['status' => 'error', 'message' => 'Invalid or inactive API key.']);
}
$companyId = (int)$authRow['company_id'];

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1048576) {
    crm_lead_sync_respond(413, ['status' => 'error', 'message' => 'Request body must not exceed 1 MB.']);
}
$inputStream = fopen('php://input', 'rb');
$rawBody = $inputStream ? stream_get_contents($inputStream, 1048577) : false;
if (is_resource($inputStream)) {
    fclose($inputStream);
}
if (!is_string($rawBody)) {
    crm_lead_sync_respond(400, ['status' => 'error', 'message' => 'Could not read the request body.']);
}
if (strlen($rawBody) > 1048576) {
    crm_lead_sync_respond(413, ['status' => 'error', 'message' => 'Request body must not exceed 1 MB.']);
}
$payload = json_decode($rawBody, true);
if (!is_array($payload) || substr(ltrim($rawBody), 0, 1) !== '{') {
    crm_lead_sync_respond(400, ['status' => 'error', 'message' => 'Request body must be a JSON object.']);
}

$defaultSource = crm_lead_sync_string($payload['source'] ?? 'crm_api', 50);
if ($defaultSource === null) {
    crm_lead_sync_respond(422, ['status' => 'error', 'message' => 'source must be at most 50 characters.']);
}
if ($defaultSource === '') {
    $defaultSource = 'crm_api';
}

if (isset($payload['contacts'])) {
    $contacts = $payload['contacts'];
    if (!is_array($contacts) || count($contacts) < 1 || count($contacts) > 100) {
        crm_lead_sync_respond(422, ['status' => 'error', 'message' => 'contacts must be an array containing between 1 and 100 leads.']);
    }
    foreach (array_keys($contacts) as $expectedIndex => $actualIndex) {
        if ($expectedIndex !== $actualIndex || !is_array($contacts[$actualIndex])) {
            crm_lead_sync_respond(422, ['status' => 'error', 'message' => 'Each item in contacts must be a lead object.']);
        }
    }
} else {
    $contacts = [isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : $payload];
}

$preparedContacts = [];
$validationErrors = [];
foreach ($contacts as $index => $contact) {
    $prepared = crm_lead_sync_prepare_contact($contact, $defaultSource, (int)$index);
    if ($prepared['errors']) {
        $validationErrors[] = ['index' => (int)$index, 'errors' => $prepared['errors']];
    }
    $preparedContacts[] = $prepared;
}
if ($validationErrors) {
    crm_lead_sync_respond(422, [
        'status' => 'error',
        'message' => 'One or more leads failed validation; no contacts were imported.',
        'errors' => $validationErrors,
    ]);
}

$columnResult = $conn->query('SHOW COLUMNS FROM contacts');
if (!$columnResult) {
    error_log('[WAAMINI CRM LEAD SYNC] Failed to inspect contacts schema: ' . $conn->error);
    crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'Could not inspect the contacts table.']);
}
$columns = [];
while ($column = $columnResult->fetch_assoc()) {
    $columns[(string)$column['Field']] = true;
}
if (empty($columns['source'])) {
    if (!$conn->query("ALTER TABLE contacts ADD COLUMN source VARCHAR(50) DEFAULT NULL")) {
        error_log('[WAAMINI CRM LEAD SYNC] Failed to add contacts.source: ' . $conn->error);
        crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'Could not enable lead source tracking.']);
    }
    $columns['source'] = true;
}
foreach (['id', 'company_id', 'phone'] as $requiredColumn) {
    if (empty($columns[$requiredColumn])) {
        error_log('[WAAMINI CRM LEAD SYNC] Required contacts column missing: ' . $requiredColumn);
        crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'The contacts table is missing required fields.']);
    }
}

if (!$conn->begin_transaction()) {
    error_log('[WAAMINI CRM LEAD SYNC] Failed to start transaction for company ' . $companyId . ': ' . $conn->error);
    crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'Could not start the lead import transaction.']);
}
$results = [];
$created = 0;
$updated = 0;
try {
    foreach ($preparedContacts as $contact) {
        $phone = $contact['phone'];
        $digits = preg_replace('/\D+/', '', $phone);
        $find = $conn->prepare("SELECT * FROM contacts WHERE company_id=? AND (phone=? OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')','')=?) ORDER BY id ASC LIMIT 1 FOR UPDATE");
        if (!$find) {
            throw new RuntimeException('Could not prepare contact lookup: ' . $conn->error);
        }
        $find->bind_param('iss', $companyId, $phone, $digits);
        if (!$find->execute()) {
            throw new RuntimeException('Could not look up contact: ' . $find->error);
        }
        $existing = $find->get_result()->fetch_assoc() ?: null;
        $find->close();

        if ($existing) {
            $updates = [];
            $updateValues = [];
            $updateTypes = '';
            foreach (['name', 'first_name', 'last_name', 'email', 'status'] as $field) {
                $value = $contact[$field];
                if ($value !== '' && !empty($columns[$field]) && trim((string)($existing[$field] ?? '')) !== $value) {
                    $updates[] = "`$field`=?";
                    $updateTypes .= 's';
                    $updateValues[] = $value;
                }
            }
            if ($contact['source'] !== '' && !empty($columns['source']) && trim((string)($existing['source'] ?? '')) === '') {
                $updates[] = '`source`=?';
                $updateTypes .= 's';
                $updateValues[] = $contact['source'];
            }
            if ($contact['tags'] !== null && $contact['tags'] !== '' && !empty($columns['tags'])) {
                $existingTags = array_filter(array_map('trim', explode(',', (string)($existing['tags'] ?? ''))));
                $tagMap = [];
                foreach ($existingTags as $tag) {
                    $tagMap[strtolower($tag)] = $tag;
                }
                foreach (array_filter(array_map('trim', explode(',', $contact['tags']))) as $tag) {
                    $tagMap[strtolower($tag)] = $tag;
                }
                $mergedTags = implode(', ', array_values($tagMap));
                if (strlen($mergedTags) > 2000) {
                    throw new RuntimeException('The merged tag list exceeds the contacts field limit.');
                }
                if ($mergedTags !== (string)($existing['tags'] ?? '')) {
                    $updates[] = '`tags`=?';
                    $updateTypes .= 's';
                    $updateValues[] = $mergedTags;
                }
            }
            if ($updates) {
                $updateTypes .= 'ii';
                $updateValues[] = (int)$existing['id'];
                $updateValues[] = $companyId;
                $update = $conn->prepare('UPDATE contacts SET ' . implode(', ', $updates) . ' WHERE id=? AND company_id=?');
                if (!$update) {
                    throw new RuntimeException('Could not prepare contact update: ' . $conn->error);
                }
                if (!crm_lead_sync_bind($update, $updateTypes, $updateValues) || !$update->execute()) {
                    throw new RuntimeException('Could not update contact: ' . $update->error);
                }
                $update->close();
            }
            $results[] = ['index' => $contact['index'], 'contact_id' => (int)$existing['id'], 'action' => 'updated'];
            $updated++;
            continue;
        }

        $insertData = [
            'company_id' => $companyId,
            'phone' => $phone,
        ];
        foreach (['name', 'first_name', 'last_name', 'email', 'tags', 'source', 'status'] as $field) {
            if (!empty($columns[$field]) && $contact[$field] !== null && $contact[$field] !== '') {
                $insertData[$field] = $contact[$field];
            }
        }
        $insertFields = array_keys($insertData);
        $quotedFields = array_map(static function ($field) { return '`' . $field . '`'; }, $insertFields);
        $placeholders = implode(',', array_fill(0, count($insertFields), '?'));
        $insert = $conn->prepare('INSERT INTO contacts (' . implode(',', $quotedFields) . ') VALUES (' . $placeholders . ')');
        if (!$insert) {
            throw new RuntimeException('Could not prepare contact insert: ' . $conn->error);
        }
        $insertTypes = '';
        $insertValues = [];
        foreach ($insertData as $field => $value) {
            $insertTypes .= $field === 'company_id' ? 'i' : 's';
            $insertValues[] = $value;
        }
        if (!crm_lead_sync_bind($insert, $insertTypes, $insertValues) || !$insert->execute()) {
            throw new RuntimeException('Could not insert contact: ' . $insert->error);
        }
        $contactId = (int)$insert->insert_id;
        $insert->close();
        $results[] = ['index' => $contact['index'], 'contact_id' => $contactId, 'action' => 'created'];
        $created++;
    }
    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit lead import: ' . $conn->error);
    }
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[WAAMINI CRM LEAD SYNC] Import failed for company ' . $companyId . ': ' . $e->getMessage());
    crm_lead_sync_respond(500, ['status' => 'error', 'message' => 'The lead import failed; no changes were saved.']);
}

crm_lead_sync_respond(200, [
    'status' => 'success',
    'created' => $created,
    'updated' => $updated,
    'results' => $results,
]);
