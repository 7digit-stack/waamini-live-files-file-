<?php
/**
 * WhatsApp Native Flows (Meta "WhatsApp Flows") — schema, Graph API calls and response matching.
 *
 * Lifecycle: local draft -> create on Meta (gets meta_flow_id) -> upload Flow JSON asset
 * -> publish -> send via template FLOW button or interactive flow message -> nfm_reply on webhook.
 */
require_once __DIR__ . '/../services/WhatsAppService.php';

const WAF_CATEGORIES = [
    'LEAD_GENERATION'     => 'Lead generation',
    'APPOINTMENT_BOOKING' => 'Appointment booking',
    'SIGN_UP'             => 'Sign up',
    'SIGN_IN'             => 'Sign in',
    'CONTACT_US'          => 'Contact us',
    'CUSTOMER_SUPPORT'    => 'Customer support',
    'SURVEY'              => 'Survey',
    'OTHER'               => 'Other',
];

/** Add Meta columns + responses table. Safe to call on every request. */
function waf_ensure_schema(mysqli $conn): void {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS whatsapp_flows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            flow_name VARCHAR(100) NOT NULL,
            flow_json LONGTEXT NOT NULL,
            status ENUM('draft','published') DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}

    $cols = [];
    try {
        $r = $conn->query("SHOW COLUMNS FROM whatsapp_flows");
        while ($r && ($c = $r->fetch_assoc())) $cols[$c['Field']] = true;
    } catch (\Throwable $e) { return; }
    $add = [
        'meta_flow_id' => "ALTER TABLE whatsapp_flows ADD COLUMN meta_flow_id VARCHAR(64) NULL",
        'meta_status'  => "ALTER TABLE whatsapp_flows ADD COLUMN meta_status VARCHAR(20) NULL",
        'category'     => "ALTER TABLE whatsapp_flows ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'OTHER'",
        'last_error'   => "ALTER TABLE whatsapp_flows ADD COLUMN last_error TEXT NULL",
        'published_at' => "ALTER TABLE whatsapp_flows ADD COLUMN published_at DATETIME NULL",
        'updated_at'   => "ALTER TABLE whatsapp_flows ADD COLUMN updated_at DATETIME NULL",
    ];
    foreach ($add as $col => $sql) {
        if (!isset($cols[$col])) { try { $conn->query($sql); } catch (\Throwable $e) {} }
    }

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS whatsapp_flow_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            flow_id INT NULL,
            contact_id INT NULL,
            phone VARCHAR(32) NULL,
            wa_message_id VARCHAR(128) NULL,
            flow_token VARCHAR(128) NULL,
            response_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company_flow (company_id, flow_id),
            UNIQUE KEY uq_wamid (company_id, wa_message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}
}

/** Decrypted WhatsApp config, or null when WABA / token are missing. */
function waf_config(mysqli $conn, int $companyId): ?array {
    $cfg = WhatsAppService::getConfig($conn, $companyId);
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['waba_id'])) return null;
    $v = (string)($cfg['api_version'] ?? '');
    // Flows need a recent Graph version; old stored values (v15-v18) are bumped.
    if (!preg_match('/^v(\d+)\.\d+$/', $v, $m) || (int)$m[1] < 19) $v = 'v20.0';
    $cfg['api_version'] = $v;
    return $cfg;
}

/**
 * Graph API call. $body: array (JSON), array with 'multipart' => [...] (form-data), or null.
 * Returns ['ok' => bool, 'code' => int, 'data' => array, 'error' => string].
 */
function waf_graph(array $cfg, string $method, string $path, $body = null): array {
    $url = 'https://graph.facebook.com/' . $cfg['api_version'] . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . trim((string)$cfg['access_token'])];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if (is_array($body) && isset($body['multipart'])) {
        $opts[CURLOPT_POSTFIELDS] = $body['multipart'];
    } elseif ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) $data = [];
    $ok = $code >= 200 && $code < 300 && !isset($data['error']);
    $err = '';
    if (!$ok) {
        $e = $data['error'] ?? [];
        $err = (string)($e['error_user_msg'] ?? $e['message'] ?? '');
        if ($err === '') $err = $resp === false ? ('Could not reach Meta: ' . $curlErr) : ('Meta returned HTTP ' . $code);
    }
    return ['ok' => $ok, 'code' => $code, 'data' => $data, 'error' => $err];
}

/** Turn Meta validation_errors into one readable string ('' when none). */
function waf_validation_text(array $data): string {
    $errs = $data['validation_errors'] ?? [];
    if (!is_array($errs) || !$errs) return '';
    $out = [];
    foreach (array_slice($errs, 0, 8) as $e) {
        $line = isset($e['line_start']) ? ' (line ' . (int)$e['line_start'] . ')' : '';
        $out[] = trim(($e['error'] ?? 'ERROR') . ': ' . ($e['message'] ?? '')) . $line;
    }
    return implode("\n", $out);
}

/**
 * Normalize Flow JSON without losing empty objects. Decoding with assoc=true turns {} into []
 * (Meta then rejects "data": []), so decode to stdClass and also repair rows saved that way earlier.
 * Returns '' when the JSON is invalid.
 */
function waf_normalize_json(string $json): string {
    $d = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) return '';
    // Keys the Flow schema defines as objects; an empty [] here can only be a damaged {}.
    $objKeys = ['data' => 1, 'payload' => 1, 'routing_model' => 1, 'init-values' => 1, 'error-messages' => 1, 'properties' => 1];
    $fix = static function ($n) use (&$fix, $objKeys) {
        if (is_array($n)) return array_map($fix, $n);
        if (!is_object($n)) return $n;
        foreach (get_object_vars($n) as $k => $v) {
            $n->$k = (isset($objKeys[$k]) && $v === []) ? new stdClass() : $fix($v);
        }
        return $n;
    };
    $out = json_encode($fix($d), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $out === false ? '' : $out;
}

/** Upload the Flow JSON as the flow's asset. Returns ['ok', 'error']. */
function waf_upload_json(array $cfg, string $metaFlowId, string $json): array {
    $json = waf_normalize_json($json) ?: $json;
    $tmp = tempnam(sys_get_temp_dir(), 'waf');
    file_put_contents($tmp, $json);
    $r = waf_graph($cfg, 'POST', $metaFlowId . '/assets', ['multipart' => [
        'name'       => 'flow.json',
        'asset_type' => 'FLOW_JSON',
        'file'       => new CURLFile($tmp, 'application/json', 'flow.json'),
    ]]);
    @unlink($tmp);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $v = waf_validation_text($r['data']);
    return ['ok' => $v === '', 'error' => $v];
}

/** Create the flow on Meta (if needed) and upload its JSON. Returns ['ok', 'meta_flow_id', 'error']. */
function waf_push(array $cfg, array $flow): array {
    $metaId = (string)($flow['meta_flow_id'] ?? '');
    if ($metaId === '') {
        $name = html_entity_decode((string)$flow['flow_name'], ENT_QUOTES, 'UTF-8') . ' #' . (int)$flow['id'];
        $r = waf_graph($cfg, 'POST', $cfg['waba_id'] . '/flows', [
            'name'       => $name,
            'categories' => [(string)($flow['category'] ?: 'OTHER')],
        ]);
        if (!$r['ok'] || empty($r['data']['id'])) return ['ok' => false, 'meta_flow_id' => '', 'error' => $r['error'] ?: 'Meta did not return a flow ID.'];
        $metaId = (string)$r['data']['id'];
    }
    $u = waf_upload_json($cfg, $metaId, (string)$flow['flow_json']);
    return ['ok' => $u['ok'], 'meta_flow_id' => $metaId, 'error' => $u['error']];
}

/** Current Meta status: ['ok', 'status', 'error']. */
function waf_fetch_status(array $cfg, string $metaFlowId): array {
    $r = waf_graph($cfg, 'GET', $metaFlowId . '?fields=id,name,status,validation_errors');
    if (!$r['ok']) return ['ok' => false, 'status' => '', 'error' => $r['error']];
    return ['ok' => true, 'status' => (string)($r['data']['status'] ?? ''), 'error' => waf_validation_text($r['data'])];
}

/** ID of the first screen in a Flow JSON (needed for navigate actions). */
function waf_first_screen(string $json): string {
    $d = json_decode($json, true);
    if (!empty($d['routing_model']) && is_array($d['routing_model'])) {
        // Entry screen = a screen no other screen routes to.
        $targets = [];
        foreach ($d['routing_model'] as $to) foreach ((array)$to as $t) $targets[$t] = true;
        foreach (array_keys($d['routing_model']) as $s) if (!isset($targets[$s])) return (string)$s;
    }
    return (string)($d['screens'][0]['id'] ?? '');
}

/** Every input field name declared in a Flow JSON. */
function waf_field_names(string $json): array {
    $names = [];
    $walk = static function ($node) use (&$walk, &$names) {
        if (!is_array($node)) return;
        if (isset($node['type'], $node['name']) && is_string($node['name'])) $names[$node['name']] = true;
        foreach ($node as $v) if (is_array($v)) $walk($v);
    };
    $walk(json_decode($json, true));
    return array_keys($names);
}

/**
 * Send a flow as an interactive message (24-hour window only). Drafts are sent in draft mode,
 * which Meta allows for testing. Returns ['ok', 'error'].
 */
function waf_send_interactive(array $cfg, array $flow, string $phone, string $body, string $cta): array {
    if (empty($cfg['phone_number_id'])) return ['ok' => false, 'error' => 'Phone number ID is missing in WhatsApp settings.'];
    $params = [
        'flow_message_version' => '3',
        'flow_token'           => 'wf_' . (int)$flow['id'] . '_' . bin2hex(random_bytes(4)),
        'flow_id'              => (string)$flow['meta_flow_id'],
        'flow_cta'             => $cta,
        'flow_action'          => 'navigate',
        'flow_action_payload'  => ['screen' => waf_first_screen((string)$flow['flow_json'])],
    ];
    if (strtoupper((string)$flow['meta_status']) !== 'PUBLISHED') $params['mode'] = 'draft';
    $r = waf_graph($cfg, 'POST', $cfg['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp',
        'to'                => preg_replace('/\D/', '', $phone),
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'flow',
            'body'   => ['text' => $body],
            'action' => ['name' => 'flow', 'parameters' => $params],
        ],
    ]);
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/**
 * Work out which local flow a submission belongs to.
 * 1) flow_token "wf_<id>_…" (set by interactive sends), 2) best overlap between answer keys and
 * the field names declared in each flow's JSON (template sends carry no token).
 */
function waf_match_flow(mysqli $conn, int $companyId, array $answers): ?int {
    $token = (string)($answers['flow_token'] ?? '');
    if (preg_match('/^wf_(\d+)_/', $token, $m)) {
        $st = $conn->prepare("SELECT id FROM whatsapp_flows WHERE id=? AND company_id=? LIMIT 1");
        if ($st) {
            $id = (int)$m[1];
            $st->bind_param('ii', $id, $companyId);
            $st->execute();
            if ($st->get_result()->fetch_assoc()) return $id;
        }
    }
    $keys = array_diff(array_keys($answers), ['flow_token']);
    if (!$keys) return null;
    $st = $conn->prepare("SELECT id, flow_json FROM whatsapp_flows WHERE company_id=? AND meta_flow_id IS NOT NULL");
    if (!$st) return null;
    $st->bind_param('i', $companyId);
    $st->execute();
    $res = $st->get_result();
    $best = null; $bestScore = 0;
    while ($row = $res->fetch_assoc()) {
        $score = count(array_intersect($keys, waf_field_names((string)$row['flow_json'])));
        if ($score > $bestScore) { $best = (int)$row['id']; $bestScore = $score; }
    }
    return $best;
}

/** Save a structured submission. Idempotent on the WhatsApp message ID. */
function waf_record_response(mysqli $conn, int $companyId, int $contactId, string $phone, string $waMessageId, string $responseJson): void {
    waf_ensure_schema($conn);
    $answers = json_decode($responseJson, true);
    if (!is_array($answers)) $answers = [];
    $flowId = waf_match_flow($conn, $companyId, $answers);
    $token = mb_substr((string)($answers['flow_token'] ?? ''), 0, 128);
    $st = $conn->prepare("INSERT IGNORE INTO whatsapp_flow_responses (company_id, flow_id, contact_id, phone, wa_message_id, flow_token, response_json) VALUES (?,?,?,?,?,?,?)");
    if (!$st) return;
    $st->bind_param('iiissss', $companyId, $flowId, $contactId, $phone, $waMessageId, $token, $responseJson);
    $st->execute();
}

/* ───── Bulk send to a contact group (via an approved template with an Open form button) ───── */

/** Approved templates whose FLOW button opens this Meta flow. Each row gets 'flow_index' (button position). */
function waf_flow_templates(mysqli $conn, int $companyId, string $metaFlowId): array {
    if ($metaFlowId === '') return [];
    $st = $conn->prepare("SELECT * FROM message_templates WHERE company_id=? AND UPPER(wa_status)='APPROVED' AND buttons LIKE '%flow%' ORDER BY id DESC");
    if (!$st) return [];
    $st->bind_param('i', $companyId);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $btns = json_decode((string)$t['buttons'], true);
        if (!is_array($btns)) continue;
        foreach (array_values($btns) as $i => $b) {
            if (($b['type'] ?? '') === 'flow' && (string)($b['flow_id'] ?? '') === $metaFlowId) {
                $t['flow_index'] = $i;
                $t['flow_button_text'] = (string)($b['text'] ?? '');
                $out[] = $t;
                break;
            }
        }
    }
    return $out;
}

/** Contact groups with how many contacts (with a phone) each holds, plus an "all contacts" total. */
function waf_contact_groups(mysqli $conn, int $companyId): array {
    $groups = [];
    $st = $conn->prepare("SELECT g.id, g.name, COUNT(c.id) AS cnt FROM contact_groups g
        LEFT JOIN contacts c ON c.group_id = g.id AND c.company_id = g.company_id AND c.phone IS NOT NULL AND c.phone <> ''
        WHERE g.company_id = ? GROUP BY g.id, g.name ORDER BY g.name ASC");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $groups = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $all = 0;
    $st = $conn->prepare("SELECT COUNT(*) AS n FROM contacts WHERE company_id=? AND phone IS NOT NULL AND phone <> ''");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $all = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);
    }
    return ['groups' => $groups, 'all' => $all];
}

/**
 * Queue a campaign that sends $tpl (must contain this flow's button) to a group (0 = all contacts).
 * Reuses campaigns + campaign_queue, so pacing, opt-outs, delivery tracking and retries work as usual.
 * Each message carries flow_token "wf_<flowId>_c<campaignId>_<contactId>" so answers match the form exactly.
 * Returns ['ok', 'campaign_id', 'count', 'error'].
 */
function waf_send_to_group(mysqli $conn, int $companyId, array $flow, array $tpl, int $groupId, string $name, ?string $scheduledAt): array {
    $fail = static fn(string $m) => ['ok' => false, 'campaign_id' => 0, 'count' => 0, 'error' => $m];

    $hType = strtolower(trim((string)($tpl['header_type'] ?? '')));
    require_once __DIR__ . '/public_url.php';
    $hLink = wa_public_media_url((string)($tpl['header_link'] ?? '')); // completes "uploads/…" paths
    $hasMedia = in_array($hType, ['image', 'video', 'document'], true);
    if ($hasMedia && !preg_match('#^https://#i', $hLink)) {
        return $fail("This template has a {$hType} header without a public https link. Use Campaigns → Create to attach the {$hType}, or pick another template.");
    }

    $optOut = '';
    if (!function_exists('bt_optout_filter') && is_file(__DIR__ . '/bot_triggers.php')) require_once __DIR__ . '/bot_triggers.php';
    if (function_exists('bt_optout_filter')) $optOut = bt_optout_filter($conn);

    $sql = "SELECT c.*, g.name AS group_name FROM contacts c
            LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
            WHERE c.company_id = ? AND c.phone IS NOT NULL AND c.phone <> ''" . ($groupId > 0 ? " AND c.group_id = ?" : "") . $optOut . "
            ORDER BY c.id ASC";
    $st = $conn->prepare($sql);
    if (!$st) return $fail('Could not load contacts.');
    if ($groupId > 0) $st->bind_param('ii', $companyId, $groupId); else $st->bind_param('i', $companyId);
    $st->execute();
    $contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$contacts) return $fail($groupId > 0 ? 'This group has no contacts with a phone number (or they all opted out).' : 'No contacts with a phone number.');

    $total = count($contacts);
    $status = $scheduledAt ? 'scheduled' : 'running';
    $sendType = $scheduledAt ? 'schedule' : 'now';
    $tplId = (int)$tpl['id'];
    $mediaUrl = $hasMedia ? $hLink : '';
    $mediaType = $hasMedia ? $hType : '';
    $empty = '';
    $st = $conn->prepare("INSERT INTO campaigns (company_id, name, template_id, group_id, status, total_contacts, sent_count, send_type, scheduled_at, media_url, media_type, whatsapp_phone_number_id) VALUES (?,?,?,?,?,?,0,?,?,?,?,?)");
    if (!$st) return $fail('Could not create the campaign.');
    $st->bind_param('isiisisssss', $companyId, $name, $tplId, $groupId, $status, $total, $sendType, $scheduledAt, $mediaUrl, $mediaType, $empty);
    if (!$st->execute()) return $fail('Could not create the campaign.');
    $campaignId = (int)$conn->insert_id;

    $body = (string)(($tpl['body'] ?? '') !== '' ? $tpl['body'] : ($tpl['body_text'] ?? ''));
    $q = $conn->prepare("INSERT INTO campaign_queue (company_id, campaign_id, contact_id, phone, wa_phone_id, message_payload, scheduled_at, status) VALUES (?,?,?,?,?,?,FROM_UNIXTIME(?),'pending')");
    if (!$q) return $fail('Could not queue messages.');
    $t = $scheduledAt ? strtotime($scheduledAt) : time();
    foreach ($contacts as $i => $c) {
        // Same pacing as Campaigns → Create: 2–5 s apart, 5 min rest every 250.
        $t += random_int(2, 5);
        if ($i > 0 && $i % 250 === 0) $t += 300;
        $full = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($full === '') $full = (string)($c['name'] ?? '');
        $payload = [
            'phone_number'      => $c['phone'],
            'template_name'     => $tpl['name'],
            'template_language' => ($tpl['language'] ?? '') ?: 'en',
            'flow_token'        => 'wf_' . (int)$flow['id'] . '_c' . $campaignId . '_' . (int)$c['id'],
            'flow_button_index' => (int)$tpl['flow_index'],
        ];
        for ($n = 1; $n <= 10; $n++) {
            if (strpos($body, '{{' . $n . '}}') === false) continue;
            if ($n === 1)     $v = (string)(($c['first_name'] ?? '') ?: ($full !== '' ? $full : 'there'));
            elseif ($n === 2) $v = $full;
            elseif ($n === 3) $v = (string)$c['phone'];
            elseif ($n === 4) $v = (string)($c['email'] ?? '');
            elseif ($n === 5) $v = (string)($c['tags'] ?? '');
            else              $v = '';
            $payload['field_' . $n] = $v;
        }
        if ($hasMedia) {
            $payload['header_type'] = $hType;
            $payload['header_link'] = $hLink;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cid = (int)$c['id'];
        $phone = (string)$c['phone'];
        $q->bind_param('iiisssi', $companyId, $campaignId, $cid, $phone, $empty, $json, $t);
        $q->execute();
    }
    return ['ok' => true, 'campaign_id' => $campaignId, 'count' => $total, 'error' => ''];
}
