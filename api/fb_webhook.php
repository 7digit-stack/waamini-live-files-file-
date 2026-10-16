<?php
/**
 * WAAMINI - WhatsApp Cloud API Webhook
 *
 * NOTE: Despite the filename, this endpoint is used for WhatsApp webhooks:
 * - Verification (GET)
 * - Inbound messages + status updates (POST)
 *
 * It routes events to the correct tenant (company) using the webhook payload's phone_number_id.
 */

require_once __DIR__ . '/../db.php';

function wa_get_verify_token(mysqli $conn): string {
    // Preferred: admin/settings_whatsapp.php stores system_settings key 'wa_webhook_verify_token'
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='wa_webhook_verify_token' LIMIT 1");
    if ($res) {
        $row = $res->fetch_assoc();
        $v = trim((string)($row['setting_value'] ?? ''));
        if ($v !== '') return $v;
    }
    // Fallback: env
    $env = (string)($_ENV['WHATSAPP_VERIFY_TOKEN'] ?? getenv('WHATSAPP_VERIFY_TOKEN') ?? '');
    if ($env !== '') return $env;
    return 'waamini_global_fallback_token';
}

function wa_norm_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);
    if (!is_string($digits)) return '';
    $digits = ltrim($digits, '0');
    if ($digits === '') return '';
    // Store in contacts as +<digits> to stay consistent with E.164-ish formatting
    return '+' . $digits;
}

function wa_find_company_by_phone_id(mysqli $conn, string $phoneNumberId): int {
    $stmt = $conn->prepare("SELECT company_id FROM whatsapp_config WHERE phone_number_id=? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $phoneNumberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['company_id'] ?? 0);
}

function wa_get_or_create_contact(mysqli $conn, int $companyId, string $waFrom, ?string $profileName): int {
    $phone = wa_norm_phone($waFrom);
    if ($phone === '') return 0;

    $stmt = $conn->prepare("SELECT id FROM contacts WHERE company_id=? AND phone=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $companyId, $phone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && (int)$row['id'] > 0) return (int)$row['id'];
    }

    $name = trim((string)($profileName ?? ''));
    if ($name === '') $name = $phone;
    $ins = $conn->prepare("INSERT INTO contacts (company_id, name, phone, status, last_message_at) VALUES (?, ?, ?, 'active', NOW())");
    if (!$ins) return 0;
    $ins->bind_param('iss', $companyId, $name, $phone);
    $ins->execute();
    return (int)$conn->insert_id;
}

// ── 1) Verification (GET) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    if ($mode === 'subscribe' && $challenge !== '' && hash_equals(wa_get_verify_token($conn), $token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Verification Failed';
    exit;
}

// ── 2) Events (POST) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo 'Bad JSON';
    exit;
}

// WhatsApp payload shape: entry[].changes[].value
$value = null;
if (is_array($input['entry'] ?? null) && is_array($input['entry'][0]['changes'] ?? null)) {
    $value = $input['entry'][0]['changes'][0]['value'] ?? null;
}
if (!is_array($value)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
if ($phoneNumberId === '') {
    http_response_code(200);
    echo 'OK';
    exit;
}

$companyId = wa_find_company_by_phone_id($conn, $phoneNumberId);
if ($companyId <= 0) {
    // Unknown tenant: acknowledge so Meta doesn't retry forever
    http_response_code(200);
    echo 'OK';
    exit;
}

// a) Inbound messages
if (!empty($value['messages']) && is_array($value['messages'])) {
    foreach ($value['messages'] as $m) {
        if (!is_array($m)) continue;
        $from = (string)($m['from'] ?? '');
        $waMsgId = (string)($m['id'] ?? '');
        if ($from === '' || $waMsgId === '') continue;

        $profileName = (string)($value['contacts'][0]['profile']['name'] ?? '');
        $contactId = wa_get_or_create_contact($conn, $companyId, $from, $profileName);
        if ($contactId <= 0) continue;

        // De-dupe: don't insert the same WA message twice
        $dupe = $conn->prepare("SELECT id FROM messages WHERE company_id=? AND whatsapp_message_id=? LIMIT 1");
        if ($dupe) {
            $dupe->bind_param('is', $companyId, $waMsgId);
            $dupe->execute();
            $r = $dupe->get_result()->fetch_assoc();
            if ($r) continue;
        }

        $type = (string)($m['type'] ?? 'text');
        $body = '';
        $mediaUrl = '';

        if ($type === 'text') {
            $body = (string)($m['text']['body'] ?? '');
        } elseif (in_array($type, ['image', 'video', 'audio', 'document'], true)) {
            // We store media placeholder; download can be implemented later
            $body = '[' . strtoupper($type) . ' received]';
        } else {
            $body = '[' . strtoupper($type ?: 'message') . ' received]';
        }

        $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, media_url, is_read, status, whatsapp_message_id, created_at)
            VALUES (?, ?, 'incoming', ?, ?, ?, 0, 'received', ?, NOW())");
        if ($ins) {
            $ins->bind_param('iissss', $companyId, $contactId, $type, $body, $mediaUrl, $waMsgId);
            $ins->execute();
        }

        $upd = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param('ii', $contactId, $companyId);
            $upd->execute();
        }
    }
}

// b) Status updates (sent/delivered/read/failed)
if (!empty($value['statuses']) && is_array($value['statuses'])) {
    foreach ($value['statuses'] as $st) {
        if (!is_array($st)) continue;
        $waMsgId = (string)($st['id'] ?? '');
        $status = strtolower((string)($st['status'] ?? ''));
        if ($waMsgId === '' || $status === '') continue;
        $status = preg_replace('/[^a-z_]/', '', $status);
        if (!is_string($status) || $status === '') continue;

        $upd = $conn->prepare("UPDATE messages SET status=? WHERE company_id=? AND whatsapp_message_id=?");
        if ($upd) {
            $upd->bind_param('sis', $status, $companyId, $waMsgId);
            $upd->execute();
        }
    }
}

http_response_code(200);
echo 'OK';
