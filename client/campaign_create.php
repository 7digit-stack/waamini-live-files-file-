<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
require_once '../includes/bot_triggers.php';
if (!has_feature('campaigns')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Create Campaign';
$currentPage = 'campaigns';

// Schema checks removed. Migration handles this.


$success = '';
$error   = '';

// Ensure optional columns exist for multi-number sending (safe/idempotent)
foreach ([
    "ALTER TABLE campaigns ADD COLUMN whatsapp_phone_number_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE campaign_queue ADD COLUMN wa_phone_id VARCHAR(100) DEFAULT NULL",
] as $_alterSql) {
    try { $conn->query($_alterSql); } catch (\Throwable $e) { /* ignore */ }
}
unset($_alterSql);

// --- Fetch approved templates ---
$tmplStmt = $conn->prepare("SELECT * FROM message_templates WHERE (company_id=? OR company_id IS NULL) AND wa_status='APPROVED' ORDER BY name ASC");
if (!$tmplStmt) {
    error_log('[WAAMINI Campaign Create] templates prepare failed: ' . $conn->error);
    $templates = false;
} else {
    $tmplStmt->bind_param("i", $companyId);
    $tmplStmt->execute();
    $templates = $tmplStmt->get_result();
}
$templateList = [];
if ($templates) {
    while ($t = $templates->fetch_assoc()) $templateList[] = $t;
}

// Template data for the live picker/preview (built before any POST error jump)
$tplJson = [];
foreach ($templateList as $t) {
    $tplJson[$t['id']] = [
        'name'        => $t['name'],
        'language'    => $t['language'] ?? 'en',
        'category'    => strtolower((string)($t['category'] ?? 'marketing')),
        'header_type' => strtolower((string)($t['header_type'] ?? 'none')),
        'header_text' => (string)($t['header_text'] ?? ''),
        'header_link' => (string)($t['header_link'] ?? ''),
        'body'        => (string)(($t['body'] ?? '') !== '' ? $t['body'] : ($t['body_text'] ?? '')),
        'footer'      => (string)(($t['footer'] ?? '') !== '' ? $t['footer'] : ($t['footer_text'] ?? '')),
        'buttons'     => (string)($t['buttons'] ?? ''),
    ];
}

// --- Fetch contact groups ---
$groupsRes = null;
$gStmt = $conn->prepare("
    SELECT g.id, g.name, COUNT(c.id) as cnt
    FROM contact_groups g
    LEFT JOIN contacts c ON c.group_id = g.id AND c.company_id = ?
    WHERE g.company_id = ?
    GROUP BY g.id
    ORDER BY g.name ASC
");
if ($gStmt) {
    $gStmt->bind_param("ii", $companyId, $companyId);
    $gStmt->execute();
    $groupsRes = $gStmt->get_result();
} else {
    error_log('[WAAMINI Campaign Create] groups prepare failed: ' . $conn->error);
}
$groupList = [];
if ($groupsRes) while ($g = $groupsRes->fetch_assoc()) $groupList[] = $g;

// --- Helper: parse tags (handles both JSON and plain CSV) ---
function parseTags($tagsStr) {
    if (empty($tagsStr)) return [];
    $decoded = json_decode($tagsStr, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('trim', $decoded)));
    return array_values(array_filter(array_map('trim', explode(',', $tagsStr))));
}

// Fetch WhatsApp phone config for "Send using Phone Number" dropdown
$waPhones = [];
try {
    $st12a = $conn->prepare("SELECT phone_number_id, display_phone, verified_name FROM whatsapp_config WHERE company_id=? LIMIT 5");
    $st12a->bind_param("i", $companyId);
    $st12a->execute();
    $waRes = $st12a->get_result();
    if ($waRes) while ($w = $waRes->fetch_assoc()) $waPhones[] = $w;
} catch (\Exception $e) {}
// Fallback: try phone_number column if config fails
if (empty($waPhones)) {
    try {
        $st12b = $conn->prepare("SELECT phone_number_id FROM whatsapp_config WHERE company_id=? LIMIT 5");
        $st12b->bind_param("i", $companyId);
        $st12b->execute();
        $waRes2 = $st12b->get_result();
        if ($waRes2) while ($w = $waRes2->fetch_assoc()) { $w['display_phone'] = ''; $w['verified_name'] = ''; $waPhones[] = $w; }
    } catch (\Exception $e) {}
}

// --- POST: Create Campaign ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_campaign'])) {
    csrf_verify();

    if ($viewOnlyMode) {
        $error = "Plan expired. Renew to create campaigns.";
        goto form_done;
    }

    $name       = sanitize($_POST['name'] ?? '');
    $template_id = intval($_POST['template_id'] ?? 0);
    $audience   = sanitize($_POST['audience'] ?? 'all');     // all | group
    $group_id   = intval($_POST['group_id'] ?? 0);
    $send_type  = ($_POST['send_type'] ?? 'now') === 'schedule' ? 'schedule' : 'now';
    $sched_date = sanitize($_POST['scheduled_date'] ?? '');
    $sched_time = sanitize($_POST['scheduled_time'] ?? '');
    $media_type = sanitize($_POST['media_type'] ?? '');
    $wa_phone_id = sanitize($_POST['wa_phone_id'] ?? '');
    $media_url  = '';

    // Template variable mapping (field_1..field_10)
    // - mode=auto  -> use built-in defaults per contact
    // - mode=custom-> use provided text for all recipients
    // - mode=first_name/last_name/full_name/mobile/email/tags/group_name/contact_id -> use that contact field
    $varMode = [];
    $customFields = [];
    for ($i = 1; $i <= 10; $i++) {
        $modeKey = 'var_mode_' . $i;
        $k = 'field_' . $i; // custom text
        $mode = isset($_POST[$modeKey]) ? trim((string)$_POST[$modeKey]) : 'auto';
        $mode = $mode !== '' ? strtolower($mode) : 'auto';
        $allow = ['auto','custom','first_name','last_name','full_name','mobile','email','tags','group_name','contact_id'];
        if (!in_array($mode, $allow, true)) $mode = 'auto';
        $varMode[$i] = $mode;

        $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
        if ($v !== '') {
            $v = sanitize($v);
            if (function_exists('mb_substr')) $v = mb_substr($v, 0, 300, 'UTF-8');
            else $v = substr($v, 0, 300);
        }
        $customFields[$i] = $v;
    }

    // Handle File Upload for Header
    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/campaigns/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo((string)($_FILES['media_file']['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4'];
        if (!in_array($ext, $allowedExts, true)) {
            $error = "Unsupported file type. Allowed: " . implode(', ', $allowedExts);
            goto form_done;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['media_file']['tmp_name'], $dest)) {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' ? "https" : "http";
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            $appPath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
            // WhatsApp Cloud API must fetch header media from a public HTTPS URL.
            $isLocal = $scheme !== 'https' || stripos($host, 'localhost') !== false || stripos($host, '127.0.0.1') !== false;
            if (!$isLocal) {
                $baseUrl = $scheme . "://" . $host . $appPath;
            } else {
                // Working on localhost: use the public tunnel (ngrok) from PUBLIC_URL in .env, which serves this same folder.
                $publicUrl = rtrim((string)(\Waamini\Config\Env::get('PUBLIC_URL', '') ?: ''), '/');
                if (!preg_match('#^https://#i', $publicUrl)) {
                    @unlink($dest);
                    $error = "WhatsApp needs a public https link for header media, and this site is running on localhost. Add PUBLIC_URL=https://your-ngrok-address to .env (then uploads work), or paste a public https Media URL instead of uploading.";
                    goto form_done;
                }
                $baseUrl = $publicUrl;
            }
            $media_url = rtrim($baseUrl, '/') . "/uploads/campaigns/" . $filename;
        }
    } else {
        $media_url = trim(sanitize($_POST['media_url'] ?? ''));
    }

    // Throttling
    $min_delay  = max(1, intval($_POST['min_delay']  ?? 2));
    $max_delay  = max(2, intval($_POST['max_delay']  ?? 5));
    $batch_size = max(50, intval($_POST['batch_size'] ?? 250));
    $batch_rest = max(1,  intval($_POST['batch_rest'] ?? 5));

    if (empty($name) || empty($template_id)) {
        $error = "Campaign name and template are required.";
        goto form_done;
    }

    // Build scheduled_at
    $scheduled_at = null;
    if ($send_type === 'schedule') {
        if (empty($sched_date) || empty($sched_time)) {
            $error = "Please set a date and time for scheduled sending.";
            goto form_done;
        }
        $scheduled_at = date('Y-m-d H:i:s', strtotime("$sched_date $sched_time"));
        if ($scheduled_at <= date('Y-m-d H:i:s')) {
            $error = "Scheduled time must be in the future.";
            goto form_done;
        }
    }

    // Customers who opted out of promotions (replied STOP) are never messaged
    $optOut = bt_optout_filter($conn);

    // Fetch contacts
    $restrictLang = isset($_POST['restrict_lang']);
    $tplLang = '';
    try { $tplLang = (string)($template['language'] ?? ''); } catch (\Throwable $e) { $tplLang = ''; }

    if ($audience === 'group' && $group_id > 0) {
        if ($restrictLang && $tplLang !== '') {
            $cStmt = $conn->prepare("
                SELECT c.*, g.name AS group_name
                FROM contacts c
                LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
                WHERE c.company_id=? AND c.group_id=? AND c.phone IS NOT NULL AND c.phone != ''{$optOut}
                  AND (c.language=? OR c.language IS NULL OR c.language='')
                ORDER BY c.id ASC
            ");
        } else {
            $cStmt = $conn->prepare("
                SELECT c.*, g.name AS group_name
                FROM contacts c
                LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
                WHERE c.company_id=? AND c.group_id=? AND c.phone IS NOT NULL AND c.phone != ''{$optOut}
                ORDER BY c.id ASC
            ");
        }
        if (!$cStmt) {
            error_log('[WAAMINI Campaign Create] contacts(group) prepare failed: ' . $conn->error);
            $error = "System error while loading contacts.";
            goto form_done;
        }
        if ($restrictLang && $tplLang !== '') {
            $cStmt->bind_param("iis", $companyId, $group_id, $tplLang);
        } else {
            $cStmt->bind_param("ii", $companyId, $group_id);
        }
    } else {
        if ($restrictLang && $tplLang !== '') {
            $cStmt = $conn->prepare("
                SELECT c.*, g.name AS group_name
                FROM contacts c
                LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
                WHERE c.company_id=? AND c.phone IS NOT NULL AND c.phone != ''{$optOut}
                  AND (c.language=? OR c.language IS NULL OR c.language='')
                ORDER BY c.id ASC
            ");
        } else {
            $cStmt = $conn->prepare("
                SELECT c.*, g.name AS group_name
                FROM contacts c
                LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
                WHERE c.company_id=? AND c.phone IS NOT NULL AND c.phone != ''{$optOut}
                ORDER BY c.id ASC
            ");
        }
        if (!$cStmt) {
            error_log('[WAAMINI Campaign Create] contacts(all) prepare failed: ' . $conn->error);
            $error = "System error while loading contacts.";
            goto form_done;
        }
        if ($restrictLang && $tplLang !== '') {
            $cStmt->bind_param("is", $companyId, $tplLang);
        } else {
            $cStmt->bind_param("i", $companyId);
        }
    }
    $cStmt->execute();
    $allContacts   = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total_recipients = count($allContacts);

    if ($total_recipients === 0) {
        $grpLabel = ($audience === 'group' && $group_id > 0) ? 'in the selected group' : 'in your database';
        $error = "No contacts found {$grpLabel} with a valid phone number.";
        goto form_done;
    }

    // Fetch template
    $tmplDetStmt = $conn->prepare("SELECT * FROM message_templates WHERE id=? AND (company_id=? OR company_id IS NULL)");
    if (!$tmplDetStmt) {
        error_log('[WAAMINI Campaign Create] template details prepare failed: ' . $conn->error);
        $error = "System error while loading template.";
        goto form_done;
    }
    $tmplDetStmt->bind_param("ii", $template_id, $companyId);
    $tmplDetStmt->execute();
    $template = $tmplDetStmt->get_result()->fetch_assoc();

    if (!$template || empty($template['name'])) {
        $error = "Selected template not found or has no name.";
        goto form_done;
    }

    // A template with an image/video/document header must be sent WITH that media, otherwise Meta rejects
    // every message (#132012 "Parameter format does not match"). Stop here instead of failing the whole list.
    $tplHeader = strtolower(trim((string)($template['header_type'] ?? '')));
    if (in_array($tplHeader, ['image', 'video', 'document'], true)) {
        $hdrMedia = $media_url !== '' ? $media_url : trim((string)($template['header_link'] ?? ''));
        if ($hdrMedia === '') {
            $error = "This template has a " . $tplHeader . " header. Add the " . $tplHeader . " (upload it or paste a public https link) in the Media step, then send again.";
            goto form_done;
        }
        if (!preg_match('#^https://#i', $hdrMedia) || preg_match('#//(localhost|127\.0\.0\.1)[:/]#i', $hdrMedia)) {
            $error = "WhatsApp can only fetch header media from a public https link. The current link (" . htmlspecialchars($hdrMedia, ENT_QUOTES, 'UTF-8') . ") is not public.";
            goto form_done;
        }
    }

    // Insert campaign record
    $camStmt = $conn->prepare("INSERT INTO campaigns (company_id, name, template_id, group_id, status, total_contacts, sent_count, send_type, scheduled_at, media_url, media_type, whatsapp_phone_number_id) VALUES (?,?,?,?,?,?,0,?,?,?,?,?)");
    if (!$camStmt) {
        error_log('[WAAMINI Campaign Create] campaigns insert prepare failed: ' . $conn->error);
        $error = "Failed to create campaign record.";
        goto form_done;
    }
    $camStatus = ($send_type === 'schedule') ? 'scheduled' : 'running';
    $camStmt->bind_param("isiisisssss",
        $companyId, $name, $template_id, $group_id,
        $camStatus, $total_recipients,
        $send_type, $scheduled_at, $media_url, $media_type, $wa_phone_id
    );

    if (!$camStmt->execute()) {
        $error = "Failed to create campaign record.";
        goto form_done;
    }
    $campaign_id = $camStmt->insert_id;

    // Queue messages
    $base_time = ($send_type === 'schedule' && $scheduled_at)
        ? strtotime($scheduled_at)
        : time();

    $qStmt = $conn->prepare("INSERT INTO campaign_queue (company_id, campaign_id, contact_id, phone, wa_phone_id, message_payload, scheduled_at, status) VALUES (?,?,?,?,?,?,FROM_UNIXTIME(?),'pending')");
    if (!$qStmt) {
        error_log('[WAAMINI Campaign Create] queue insert prepare failed: ' . $conn->error);
        $error = "Failed to queue campaign messages.";
        goto form_done;
    }

    foreach ($allContacts as $idx => $contact) {
        $delay = rand($min_delay, $max_delay);
        $base_time += $delay;
        if ($idx > 0 && $idx % $batch_size === 0) $base_time += ($batch_rest * 60);

        $fullName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        if (empty($fullName)) $fullName = $contact['name'] ?? '';

        $payload = [
            'phone_number'      => $contact['phone'],
            'template_name'     => $template['name'],
            'template_language' => $template['language'] ?? 'en',
        ];

        // Variable substitution for {{1}}, {{2}} etc.
        $bodyContent = $template['body'] ?? $template['body_text'] ?? '';
        for ($i = 1; $i <= 10; $i++) {
            if (strpos($bodyContent, "{{{$i}}}") === false) {
                continue;
            }
            $mode = $varMode[$i] ?? 'auto';
            if ($mode === 'custom') {
                $payload["field_{$i}"] = (string)($customFields[$i] ?? '');
                continue;
            }
            if ($mode !== 'auto') {
                $payload["field_{$i}"] = match ($mode) {
                    'first_name' => (string)($contact['first_name'] ?? ''),
                    'last_name' => (string)($contact['last_name'] ?? ''),
                    'full_name' => (string)($fullName ?: ($contact['name'] ?? '')),
                    'mobile' => (string)($contact['phone'] ?? ''),
                    'email' => (string)($contact['email'] ?? ''),
                    'tags' => (string)($contact['tags'] ?? ''),
                    'group_name' => (string)($contact['group_name'] ?? ''),
                    'contact_id' => (string)($contact['id'] ?? ''),
                    default => '',
                };
                continue;
            }

            // auto (existing behavior)
            $payload["field_{$i}"] = match($i) {
                1 => (string)($contact['first_name'] ?? ($fullName ?: 'there')),
                2 => (string)($fullName ?: ($contact['name'] ?? '')),
                3 => (string)($contact['phone'] ?? ''),
                4 => (string)($contact['email'] ?? ''),
                5 => (string)($contact['tags'] ?? ''),
                default => ''
            };
        }

        // Media — use campaign-level media_url, or fallback to template's header_link
        $resolvedMedia = !empty($media_url) ? $media_url : ($template['header_link'] ?? '');
        if (!empty($resolvedMedia)) {
            $payload['header_type'] = !empty($media_type) ? $media_type : ($template['header_type'] ?? 'image');
            $payload['header_link'] = $resolvedMedia;
        }

        $json_payload = json_encode($payload);
        $contactId = (int)($contact['id'] ?? 0);
        $contactPhone = (string)($contact['phone'] ?? '');
        $qStmt->bind_param("iiisssi", $companyId, $campaign_id, $contactId, $contactPhone, $wa_phone_id, $json_payload, $base_time);
        $qStmt->execute();
    }

    header("Location: campaigns.php?msg=queued");
    exit();
}
form_done:

include '../includes/client_header.php';
$postedTpl = (int)($_POST['template_id'] ?? 0);
?>

<style>
/* ── Create Campaign — dashboard design system, no divider lines ── */
.cc{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.cc *{box-sizing:border-box}
.cc-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:22px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.cc-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.cc-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.cc-hero-ico svg{width:22px;height:22px}
.cc-hero h1{margin:0 0 4px;font-size:25px;font-weight:800;letter-spacing:-.4px}
.cc-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:540px;line-height:1.5}
.cc-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s;position:relative;z-index:1}
.cc-btn:hover{border-color:var(--g);color:var(--g)}
.cc-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.cc-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.cc-btn--block{width:100%;height:48px;font-size:15px}
.cc-btn--muted{opacity:.55;cursor:not-allowed}
.cc-btn.sm{height:34px;padding:0 12px;font-size:13px;border-radius:9px}
.cc-alert{padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;background:#FDE7E7;color:#9b1c1c;font-weight:600}
.cc-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:22px;align-items:start}
.cc-col{display:flex;flex-direction:column;gap:18px;min-width:0}
.cc-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:14px;min-width:0}
.cc-card-hd{display:flex;align-items:center;gap:12px}
.cc-n{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;font-weight:800;display:grid;place-items:center;flex-shrink:0}
.cc-card-hd h2{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px}
.cc-card-hd p{margin:2px 0 0;font-size:13px;color:var(--mu)}
.cc-live{margin-left:auto;display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--gd);background:#E3F7EE;padding:5px 12px;border-radius:999px;white-space:nowrap}
.cc-live i{width:7px;height:7px;border-radius:50%;background:var(--g2);animation:ccp 1.8s infinite}
@keyframes ccp{0%{box-shadow:0 0 0 0 rgba(37,211,102,.6)}100%{box-shadow:0 0 0 8px rgba(37,211,102,0)}}
.cc-live.busy i{background:#F59E0B;animation:none}
.cc-ib{width:34px;height:34px;border-radius:9px;border:0;background:var(--soft);color:var(--tx);display:grid;place-items:center;cursor:pointer}
.cc-ib:hover{background:var(--g);color:#fff}
.cc-ib svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.cc-ib.spin svg{animation:ccs .8s linear infinite}
@keyframes ccs{to{transform:rotate(360deg)}}

.cc-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.cc-search{display:flex;align-items:center;gap:10px;background:var(--soft);border-radius:11px;padding:0 14px;height:40px;flex:1;min-width:200px}
.cc-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.cc-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.cc-search:focus-within{box-shadow:0 0 0 2px var(--g);background:#fff}
.cc-chips{display:flex;gap:6px;flex-wrap:wrap}
.cc-chip{border:0;background:var(--soft);color:var(--tx);border-radius:999px;padding:7px 13px;font:600 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.cc-chip:hover{background:#E7F7F1}.cc-chip.on{background:var(--g);color:#fff}

.cc-tpls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-height:360px;overflow:auto;padding:2px}
.cc-tpl{text-align:left;border:2px solid transparent;background:var(--soft);border-radius:14px;padding:13px 14px;cursor:pointer;display:flex;flex-direction:column;gap:6px;font-family:inherit;color:inherit;transition:background .15s,border-color .15s,transform .15s}
.cc-tpl:hover{background:#E7F7F1;transform:translateY(-1px)}
.cc-tpl.on{border-color:var(--g);background:#fff;box-shadow:0 14px 30px -20px rgba(18,140,126,.6)}
.cc-tpl-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.cc-tpl b{font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cc-tag{font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;background:#fff;color:var(--tx);white-space:nowrap}
.cc-tag.marketing{background:#EFE9FD;color:#6d44d8}.cc-tag.utility{background:#E4EFFD;color:#1d6fd6}.cc-tag.authentication{background:#FEF3DC;color:#a15c00}
.cc-tpl p{margin:0;font-size:13px;color:var(--mu);line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cc-tpl small{font-size:11.5px;color:var(--mu);display:flex;gap:10px}
.cc-empty{grid-column:1/-1;text-align:center;padding:30px 16px;color:var(--mu);font-size:14px;line-height:1.6}
.cc-empty a{color:var(--gd);font-weight:700}

.cc-f{display:flex;flex-direction:column;gap:6px;min-width:0}
.cc-f>label,.cc-lab{font-size:13px;font-weight:700;color:var(--ink)}
.cc-help{font-size:12.5px;color:var(--mu);line-height:1.5}
.cc input[type=text],.cc input[type=number],.cc input[type=date],.cc input[type=time],.cc input[type=file],.cc select{width:100%;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);background:var(--soft);border:1.5px solid transparent;border-radius:11px;padding:11px 13px;min-height:44px;outline:none;transition:border-color .15s,background .15s}
.cc input:focus,.cc select:focus{border-color:var(--g);background:#fff}
.cc input[type=file]{padding:9px 12px;font-size:13px}
.cc-row2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.cc-seg{display:inline-grid;grid-template-columns:1fr 1fr;background:var(--soft);border-radius:12px;padding:4px;gap:4px;align-self:flex-start;min-width:260px}
.cc-seg button{border:0;background:transparent;border-radius:9px;padding:9px 16px;font:700 14px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer;transition:background .15s,color .15s}
.cc-seg button.on{background:#fff;color:var(--gd);box-shadow:0 4px 12px -6px rgba(18,140,126,.5)}
.cc-check{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:var(--tx);line-height:1.5;cursor:pointer;background:var(--soft);border-radius:12px;padding:12px 14px}
.cc-check input{accent-color:var(--g);width:17px;height:17px;margin-top:2px;flex-shrink:0}
.cc-vars{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.cc-var{background:var(--soft);border-radius:13px;padding:12px;display:flex;flex-direction:column;gap:8px}
.cc-var label{font-size:12.5px;font-weight:800;color:var(--gd)}
.cc-var select,.cc-var input{background:#fff !important;min-height:40px !important;padding:8px 11px !important}
.cc-presets{display:flex;gap:8px;flex-wrap:wrap}
.cc-adv summary{cursor:pointer;font-weight:700;font-size:14px;list-style:none;display:flex;align-items:center;justify-content:space-between;padding:2px 0}
.cc-adv summary::-webkit-details-marker{display:none}
.cc-adv summary::after{content:'+';font-size:20px;color:var(--g)}
.cc-adv[open] summary::after{content:'−'}
.cc-adv .cc-row2{margin-top:14px}

.cc-pick{display:none;gap:10px;align-items:flex-start;background:var(--soft);border-radius:13px;padding:12px 14px;font-size:13.5px;color:var(--tx);line-height:1.5}
.cc-pick.on{display:flex;flex-direction:column}
.cc-pick-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.cc-pick p{margin:0;color:var(--mu)}
.cc-pick a{color:var(--gd);font-weight:700}
/* preview */
.cc-side{position:sticky;top:90px}
.cc-wall{border-radius:14px;overflow:hidden;min-height:340px;background-color:#E9E1D8;background-image:url(../assets/img/wa-wallpaper.jpg);background-size:340px auto;padding:16px 14px 18px}
.cc-msg{position:relative;max-width:300px;background:#fff;color:#111B21;border-radius:0 9px 9px 9px;box-shadow:0 1px .5px rgba(11,20,26,.16);font-size:14px;line-height:1.4;animation:ccpop .3s cubic-bezier(.2,.9,.3,1.2) both;transform-origin:top left}
.cc-msg::before{content:'';position:absolute;left:-7px;top:0;border-top:9px solid #fff;border-left:8px solid transparent}
@keyframes ccpop{from{opacity:.4;transform:scale(.96)}to{opacity:1;transform:none}}
.cc-msg .pad{padding:6px 9px 5px;display:flex;flex-direction:column;gap:3px}
.cc-media{margin:3px 3px 0;border-radius:7px;overflow:hidden;min-height:140px;background:linear-gradient(135deg,#dff3ea,#c9e6f2);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:#4d7a89;font-size:12.5px;font-weight:600;text-align:center;padding:10px}
.cc-media svg{width:32px;height:32px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.cc-media img{width:100%;max-height:200px;object-fit:cover;display:block;margin:-10px}
.cc-media.has-img{padding:0;min-height:0;display:block}
.cc-media.has-img img{margin:0}
.cc-bhead{font-weight:700}
.cc-bbody{overflow-wrap:anywhere;white-space:pre-wrap}
.cc-bfoot{color:#6B7C85;font-size:12px}
.cc-meta{text-align:right;color:#6B7C85;font-size:11px}
.cc-var-chip{background:#DCF3EA;color:#0B6B5E;border-radius:5px;padding:0 5px;font-weight:600;font-size:.92em;white-space:nowrap}
.cc-var-chip.custom{background:#FEF3DC;color:#7a4b00}
.cc-bbtn{display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;border-top:1px solid #E6EAED;color:#0A8BD6;font-weight:600}
.cc-bbtn:last-child{border-radius:0 0 9px 9px}
.cc-bbtn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.cc-none{text-align:center;color:#6B7C85;padding:70px 18px;font-size:14px;line-height:1.6;background:rgba(255,255,255,.8);border-radius:12px}
.cc-none svg{width:34px;height:34px;display:block;margin:0 auto 10px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.cc-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.cc-summary div{background:var(--soft);border-radius:12px;padding:10px 12px}
.cc-summary small{display:block;font-size:11.5px;color:var(--mu);font-weight:600}
.cc-summary b{font-size:14px;overflow-wrap:anywhere}
.cc-toggle-note{font-size:12.5px;color:var(--mu);line-height:1.5;background:#FEF6E6;color:#7a4b00;border-radius:12px;padding:10px 12px}

@media (max-width:1100px){.cc-grid{grid-template-columns:minmax(0,1fr)}.cc-side{position:static}}
@media (max-width:640px){.cc-hero{padding:18px}.cc-hero h1{font-size:21px}.cc-tpls,.cc-vars,.cc-row2{grid-template-columns:1fr}.cc-seg{min-width:0;width:100%}.cc-live{display:none}}
</style>

<div class="cc">

<?php if ($error): ?>
    <div class="cc-alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="cc-hero">
    <div class="cc-hero-l">
        <div class="cc-hero-ico"><?php echo cn_svg('campaigns'); ?></div>
        <div>
            <h1>Create Campaign</h1>
            <p>Pick an approved template, choose who receives it and when it goes out. The preview updates as you type.</p>
        </div>
    </div>
    <a href="campaigns.php" class="cc-btn">&larr; All campaigns</a>
</div>

<form method="POST" id="campaignForm" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="audience"  id="audienceInput" value="all">
    <input type="hidden" name="group_id"  id="groupIdInput"  value="0">
    <input type="hidden" name="send_type" id="sendTypeInput" value="now">
    <input type="hidden" name="template_id" id="templateDropdown" value="<?php echo $postedTpl ?: ''; ?>">

<div class="cc-grid">

    <!-- LEFT -->
    <div class="cc-col">

        <!-- STEP 1 -->
        <div class="cc-card">
            <div class="cc-card-hd">
                <div class="cc-n">1</div>
                <div><h2>Choose a template</h2><p>Only templates approved by Meta can be sent.</p></div>
                <span class="cc-live" id="ccLive"><i></i><span id="ccLiveTxt">Live</span></span>
                <button type="button" class="cc-ib" id="ccSync" title="Sync from Meta and refresh" aria-label="Sync from Meta and refresh"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg></button>
            </div>
            <div class="cc-row2">
                <div class="cc-f"><label for="ccCat">Category</label><select id="ccCat" aria-label="Template category"></select></div>
                <div class="cc-f"><label for="ccTplSel">Template</label><select id="ccTplSel" aria-label="Approved template"></select></div>
            </div>
            <div class="cc-pick" id="ccPickInfo"></div>
        </div>

        <!-- Variables -->
        <div class="cc-card" id="tplVarsCard" style="display:none;">
            <div class="cc-card-hd">
                <div><h2>Fill in the variables</h2><p>Each <b>{{1}}</b>, <b>{{2}}</b> is replaced for every contact.</p></div>
            </div>
            <div class="cc-presets">
                <button type="button" class="cc-chip" onclick="applyVarPreset('auto')">Auto</button>
                <button type="button" class="cc-chip" onclick="applyVarPreset('name_phone')">{{1}} name, {{2}} mobile</button>
                <button type="button" class="cc-chip" onclick="applyVarPreset('full_email')">{{1}} full name, {{2}} email</button>
                <button type="button" class="cc-chip" onclick="clearVarCustom()">Clear custom text</button>
            </div>
            <div class="cc-vars" id="tplVarsGrid"></div>
            <p class="cc-help">Approved templates cannot be edited on Meta. To change the wording, <a href="template_create.php" style="color:var(--gd);font-weight:700">create a new template</a>.</p>
        </div>

        <!-- Header media -->
        <div class="cc-card" id="ccMediaCard" style="display:none;">
            <div class="cc-card-hd"><div><h2>Header media</h2><p id="ccMediaHint">This template has a media header.</p></div></div>
            <div class="cc-row2">
                <div class="cc-f"><label for="mediaFileInput">File</label>
                    <input type="file" name="media_file" id="mediaFileInput" onchange="updatePreviewMediaFile(this)"></div>
                <div class="cc-f"><label for="ccMediaType">Type</label>
                    <select name="media_type" id="ccMediaType" onchange="renderPreview(document.getElementById('templateDropdown').value, '')">
                        <option value="image">Image</option><option value="video">Video</option><option value="document">Document</option>
                    </select></div>
            </div>
            <input type="hidden" name="media_url" id="mediaUrlInput" value="">
        </div>

        <!-- STEP 2 -->
        <div class="cc-card">
            <div class="cc-card-hd"><div class="cc-n">2</div><div><h2>Audience and schedule</h2><p>Who gets it, and when.</p></div></div>

            <div class="cc-f"><label for="ccName">Campaign title</label>
                <input type="text" name="name" id="ccName" required placeholder="e.g. Diwali offer, Product launch" value="<?php echo htmlspecialchars((string)($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" oninput="renderSummary()"></div>

            <div class="cc-f"><label for="groupDropdown">Send to</label>
                <select id="groupDropdown" onchange="onGroupChange(this.value)">
                    <option value="0">All contacts</option>
                    <?php foreach ($groupList as $g): ?>
                        <option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?> (<?php echo number_format($g['cnt']); ?> contacts)</option>
                    <?php endforeach; ?>
                </select>
                <a href="contact_groups.php" class="cc-help" style="color:var(--gd);font-weight:700;text-decoration:none">+ Manage groups</a>
            </div>

            <label class="cc-check"><input type="checkbox" name="restrict_lang" id="restrictLang"><span>Only send to contacts whose language code matches the template language.</span></label>

            <div class="cc-f"><span class="cc-lab">When to send</span>
                <div class="cc-seg" id="ccWhen">
                    <button type="button" class="on" data-w="now">Send now</button>
                    <button type="button" data-w="schedule">Schedule</button>
                </div>
            </div>
            <div id="schedDateBox" style="display:none;">
                <div class="cc-row2">
                    <div class="cc-f"><label for="ccDate">Date</label><input type="date" name="scheduled_date" id="ccDate" min="<?php echo date('Y-m-d'); ?>" onchange="renderSummary()"></div>
                    <div class="cc-f"><label for="ccTime">Time</label><input type="time" name="scheduled_time" id="ccTime" onchange="renderSummary()"></div>
                </div>
            </div>

            <div class="cc-f"><label for="ccPhone">Send from</label>
                <select name="wa_phone_id" id="ccPhone">
                    <?php if (!empty($waPhones)): foreach ($waPhones as $wp): ?>
                        <option value="<?php echo htmlspecialchars($wp['phone_number_id']); ?>"><?php
                            $dispStr = trim(($wp['verified_name'] ?? '') . ' ' . ($wp['display_phone'] ?? ''));
                            echo htmlspecialchars($dispStr ?: $wp['phone_number_id']);
                        ?></option>
                    <?php endforeach; else: ?>
                        <option value="">Configure WhatsApp first</option>
                    <?php endif; ?>
                </select>
            </div>

            <details class="cc-adv">
                <summary>Sending speed (anti-ban)</summary>
                <p class="cc-help" style="margin-top:8px">Spreads messages out so WhatsApp sees natural sending. The defaults are safe for most accounts.</p>
                <div class="cc-row2">
                    <div class="cc-f"><label>Min delay (sec)</label><input type="number" name="min_delay" value="2" min="1"></div>
                    <div class="cc-f"><label>Max delay (sec)</label><input type="number" name="max_delay" value="5" min="2"></div>
                    <div class="cc-f"><label>Batch size</label><input type="number" name="batch_size" value="250" min="50"></div>
                    <div class="cc-f"><label>Batch rest (min)</label><input type="number" name="batch_rest" value="5" min="1"></div>
                </div>
            </details>
        </div>

        <?php if ($viewOnlyMode): ?>
            <button type="button" class="cc-btn cc-btn--block cc-btn--muted">Plan expired</button>
        <?php elseif (!can_use('campaigns')): ?>
            <button type="button" class="cc-btn cc-btn--block cc-btn--muted">Limit reached</button>
        <?php else: ?>
            <button type="submit" name="create_campaign" id="submitBtn" class="cc-btn cc-btn--primary cc-btn--block">Send now</button>
        <?php endif; ?>
    </div>

    <!-- RIGHT: live preview of the real template -->
    <aside class="cc-card cc-side" aria-label="Message preview">
        <div class="cc-card-hd"><div><h2>Live preview</h2><p>The selected template, as customers see it.</p></div></div>
        <div class="cc-wall" id="ccWall"></div>
        <div class="cc-summary" id="ccSummary"></div>
        <div class="cc-toggle-note">Variables show as highlighted tags. Each contact receives their own details.</div>
    </aside>

</div>
</form>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Real templates from the platform (approved), kept in step with the Templates page
var tplData = <?php echo json_encode($tplJson, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
var tplFieldDefaults = <?php
    $defs = [];
    for ($i = 1; $i <= 10; $i++) { $k = 'field_' . $i; $defs[$k] = (string)($_POST[$k] ?? ''); }
    echo json_encode($defs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
?>;
var tplVarModeDefaults = <?php
    $defs = [];
    for ($i = 1; $i <= 10; $i++) { $k = 'var_mode_' . $i; $defs[$k] = (string)($_POST[$k] ?? 'auto'); }
    echo json_encode($defs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
?>;

var _schedNow = true;
var ccFilter = 'all';
var MODE_LABEL = { auto: 'Auto', first_name: 'First name', last_name: 'Last name', full_name: 'Full name', mobile: 'Mobile', email: 'Email', tags: 'Tags', group_name: 'Group', contact_id: 'Contact ID' };
var ICON = {
    phone: '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    url: '<svg viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
    reply: '<svg viewBox="0 0 24 24"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>',
    image: '<svg viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
    doc: '<svg viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>',
    chat: '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
};
function escapeHtml(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function $(id) { return document.getElementById(id); }
function selectedId() { return $('templateDropdown').value; }
function varCount(tpl) {
    var re = /\{\{\s*(\d+)\s*\}\}/g, max = 0, m, body = (tpl.body || '') + ' ' + (tpl.header_text || '');
    while ((m = re.exec(body)) !== null) { var n = parseInt(m[1], 10); if (n > max) max = n; }
    return Math.min(max, 10);
}

/* ── Template picker (real, approved templates) ── */
var CAT_NAMES = { marketing: 'Marketing', utility: 'Utility', authentication: 'Authentication' };
function catOf(t) { var c = String(t.category || 'marketing').toLowerCase(); return CAT_NAMES[c] ? c : 'marketing'; }
function renderPicker() {
    var cat = $('ccCat'), sel = $('ccTplSel'), cur = selectedId(), ids = Object.keys(tplData);
    var counts = { marketing: 0, utility: 0, authentication: 0 };
    ids.forEach(function (id) { counts[catOf(tplData[id])]++; });
    cat.innerHTML = '<option value="all">All categories (' + ids.length + ')</option>' + Object.keys(CAT_NAMES).map(function (k) {
        return '<option value="' + k + '"' + (counts[k] ? '' : ' disabled') + '>' + CAT_NAMES[k] + ' (' + counts[k] + ')</option>';
    }).join('');
    cat.value = ccFilter;

    function opt(id) {
        var t = tplData[id];
        return '<option value="' + id + '"' + (String(id) === String(cur) ? ' selected' : '') + '>' + escapeHtml(t.name) + ' (' + escapeHtml((t.language || 'en')) + ')</option>';
    }
    var html = '<option value="">' + (ids.length ? 'Select an approved template' : 'No approved templates yet') + '</option>';
    if (ccFilter === 'all') {
        Object.keys(CAT_NAMES).forEach(function (k) {
            var inCat = ids.filter(function (id) { return catOf(tplData[id]) === k; });
            if (inCat.length) html += '<optgroup label="' + CAT_NAMES[k] + '">' + inCat.map(opt).join('') + '</optgroup>';
        });
    } else {
        html += ids.filter(function (id) { return catOf(tplData[id]) === ccFilter; }).map(opt).join('');
    }
    sel.innerHTML = html;
    sel.disabled = !ids.length;
    renderPickInfo();
}
function renderPickInfo() {
    var box = $('ccPickInfo'), t = tplData[selectedId()];
    if (!Object.keys(tplData).length) {
        box.className = 'cc-pick on';
        box.innerHTML = '<p>You have no approved templates yet. <a href="template_create.php">Create a template</a>, wait for Meta to approve it, or press the sync button above to pull in templates already approved on Meta.</p>';
        return;
    }
    if (!t) { box.className = 'cc-pick'; box.innerHTML = ''; return; }
    var c = catOf(t), ht = (t.header_type || 'none'), v = varCount(t);
    box.className = 'cc-pick on';
    box.innerHTML = '<div class="cc-pick-top"><span class="cc-tag ' + c + '">' + CAT_NAMES[c] + '</span><span class="cc-tag">' + escapeHtml((t.language || 'en').toUpperCase()) + '</span>'
        + (ht !== 'none' && ht !== '' && ht !== 'text' ? '<span class="cc-tag">' + escapeHtml(ht) + ' header</span>' : '')
        + '<span class="cc-tag">' + v + ' variable' + (v === 1 ? '' : 's') + '</span></div>';
}
function chooseTemplate(id) {
    $('templateDropdown').value = id;
    renderPicker();
    selectTemplateDropdown({ value: id });
}
function selectTemplateDropdown(el) {
    var id = el.value, tpl = tplData[id];
    if (id && tpl) {
        renderTemplateVariables(id);
        configureMedia(tpl);
        renderPreview(id, '');
    } else {
        $('tplVarsCard').style.display = 'none'; $('tplVarsGrid').innerHTML = '';
        $('ccMediaCard').style.display = 'none';
        renderPreview('', '');
    }
    renderSummary();
}
function configureMedia(tpl) {
    var ht = (tpl.header_type || 'none').toLowerCase(), card = $('ccMediaCard');
    if (ht === 'image' || ht === 'video' || ht === 'document') {
        card.style.display = 'flex';
        $('ccMediaType').value = ht;
        $('ccMediaHint').textContent = 'This template has a ' + ht + ' header. Upload the ' + ht + ' to send with it.';
    } else {
        card.style.display = 'none';
        $('mediaFileInput').value = '';
    }
}

/* ── Live preview ── */
var _mediaObjUrl = '';
function varChip(i) {
    var sel = document.querySelector('select[name="var_mode_' + i + '"]');
    var mode = sel ? sel.value : 'auto';
    if (mode === 'custom') {
        var inp = $('var_custom_' + i), v = inp ? inp.value.trim() : '';
        return '<span class="cc-var-chip custom">' + (v ? escapeHtml(v) : '{{' + i + '}}') + '</span>';
    }
    return '<span class="cc-var-chip">' + escapeHtml(MODE_LABEL[mode] || 'Auto') + '</span>';
}
function fmt(text) {
    var s = escapeHtml(text || '');
    s = s.replace(/\{\{\s*(\d+)\s*\}\}/g, function (m, n) { return '\u0001' + n + '\u0002'; });
    s = s.replace(/\*(.+?)\*/g, '<b>$1</b>').replace(/(^|[^\w])_(.+?)_(?=$|[^\w])/g, '$1<i>$2</i>').replace(/~(.+?)~/g, '<s>$1</s>');
    return s.replace(/\u0001(\d+)\u0002/g, function (m, n) { return varChip(n); });
}
function renderPreview(tplId, mediaUrl) {
    var wall = $('ccWall'), tpl = tplData[tplId];
    if (!tpl) {
        wall.innerHTML = '<div class="cc-none">' + ICON.chat.replace('<svg', '<svg') + 'Choose a template and its real message appears here.</div>';
        return;
    }
    var ht = (tpl.header_type || 'none').toLowerCase(), h = '<div class="cc-msg">';
    var img = mediaUrl || _mediaObjUrl || '';
    if (ht === 'image' || ht === 'video' || ht === 'document' || ht === 'location') {
        var cur = $('ccMediaType') ? $('ccMediaType').value : ht;
        if (img && cur === 'image') h += '<div class="cc-media has-img"><img src="' + escapeHtml(img) + '" alt=""></div>';
        else if (tpl.header_link && ht === 'image') h += '<div class="cc-media has-img"><img src="' + escapeHtml(tpl.header_link) + '" alt=""></div>';
        else h += '<div class="cc-media">' + (ht === 'document' ? ICON.doc : ICON.image) + (ht.charAt(0).toUpperCase() + ht.slice(1)) + ' header' + (ht === 'location' ? '' : '<br><span style="font-weight:500">Upload the file in the card on the left</span>') + '</div>';
    }
    h += '<div class="pad">';
    if (ht === 'text' && tpl.header_text) h += '<div class="cc-bhead">' + fmt(tpl.header_text) + '</div>';
    h += '<div class="cc-bbody">' + fmt(tpl.body || '') + '</div>';
    if (tpl.footer) h += '<div class="cc-bfoot">' + escapeHtml(tpl.footer) + '</div>';
    h += '<div class="cc-meta">now</div></div>';
    try {
        var btns = tpl.buttons ? JSON.parse(tpl.buttons) : [];
        if (Array.isArray(btns)) btns.forEach(function (b) {
            var label = b.text || b.title || ''; if (!label) return;
            var t = String(b.type || '').toLowerCase();
            var ic = (t === 'phone' || t === 'phone_number') ? ICON.phone : (t === 'url' ? ICON.url : ICON.reply);
            h += '<div class="cc-bbtn">' + ic + escapeHtml(label) + '</div>';
        });
    } catch (e) {}
    wall.innerHTML = h + '</div>';
}
function updatePreviewMediaFile(input) {
    if (_mediaObjUrl) { try { URL.revokeObjectURL(_mediaObjUrl); } catch (e) {} _mediaObjUrl = ''; }
    if (input.files && input.files[0] && input.files[0].type.indexOf('image/') === 0) _mediaObjUrl = URL.createObjectURL(input.files[0]);
    renderPreview(selectedId(), '');
}

/* ── Variables ── */
function renderTemplateVariables(tplId) {
    var tpl = tplData[tplId], card = $('tplVarsCard'), grid = $('tplVarsGrid');
    if (!tpl) return;
    var max = varCount(tpl);
    if (max <= 0) { card.style.display = 'none'; grid.innerHTML = ''; return; }
    var html = '';
    for (var i = 1; i <= max; i++) {
        var name = 'field_' + i, modeName = 'var_mode_' + i;
        var v = (tplFieldDefaults && tplFieldDefaults[name]) ? String(tplFieldDefaults[name]) : '';
        var mode = (tplVarModeDefaults && tplVarModeDefaults[modeName]) ? String(tplVarModeDefaults[modeName]) : 'auto';
        var opts = [['auto','Auto'],['first_name','First Name'],['last_name','Last Name'],['full_name','Full Name'],['mobile','Mobile Number'],['email','Email'],['tags','Tags'],['group_name','Group Name'],['contact_id','Contact ID'],['custom','Custom text (same for all)']];
        html += '<div class="cc-var"><label>{{' + i + '}}</label>'
            + '<select name="' + modeName + '" onchange="onVarModeChange(' + i + ', this.value)">'
            + opts.map(function (o) { return '<option value="' + o[0] + '"' + (mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('')
            + '</select>'
            + '<input type="text" id="var_custom_' + i + '" name="' + name + '" value="' + escapeHtml(v) + '" placeholder="Custom text for all recipients" oninput="renderPreview(selectedId(), \'\')" style="' + (mode === 'custom' ? '' : 'display:none;') + '"></div>';
    }
    grid.innerHTML = html; card.style.display = 'flex';
}
function onVarModeChange(i, mode) {
    var el = $('var_custom_' + i); if (el) el.style.display = (mode === 'custom') ? 'block' : 'none';
    renderPreview(selectedId(), '');
}
function applyVarPreset(preset) {
    var tplId = selectedId(); if (!tplId) return;
    renderTemplateVariables(tplId);
    function setMode(idx, mode) { var sel = document.querySelector('select[name="var_mode_' + idx + '"]'); if (sel) { sel.value = mode; onVarModeChange(idx, mode); } }
    if (preset === 'auto') { for (var i = 1; i <= 10; i++) setMode(i, 'auto'); }
    else if (preset === 'name_phone') { setMode(1, 'first_name'); setMode(2, 'mobile'); }
    else if (preset === 'full_email') { setMode(1, 'full_name'); setMode(2, 'email'); }
    renderPreview(tplId, '');
}
function clearVarCustom() {
    var tplId = selectedId(); if (!tplId) return;
    renderTemplateVariables(tplId);
    for (var i = 1; i <= 10; i++) { var inp = $('var_custom_' + i); if (inp) inp.value = ''; }
    renderPreview(tplId, '');
}

/* ── Audience / schedule ── */
function onGroupChange(val) {
    if (val === '0' || val === '') { $('audienceInput').value = 'all'; $('groupIdInput').value = '0'; }
    else { $('audienceInput').value = 'group'; $('groupIdInput').value = val; }
    renderSummary();
}
function setWhen(w) {
    _schedNow = (w === 'now');
    document.querySelectorAll('#ccWhen button').forEach(function (b) { b.classList.toggle('on', b.dataset.w === w); });
    $('schedDateBox').style.display = _schedNow ? 'none' : 'block';
    $('sendTypeInput').value = _schedNow ? 'now' : 'schedule';
    var s = $('submitBtn'); if (s) s.textContent = _schedNow ? 'Send now' : 'Schedule campaign';
    renderSummary();
}
function toggleSchedule() { setWhen(_schedNow ? 'schedule' : 'now'); }
function renderSummary() {
    var t = tplData[selectedId()], g = $('groupDropdown'), box = $('ccSummary');
    var when = _schedNow ? 'Immediately' : (($('ccDate').value || 'Pick a date') + ' ' + ($('ccTime').value || ''));
    box.innerHTML = '<div><small>Template</small><b>' + escapeHtml(t ? t.name : 'Not chosen') + '</b></div>'
        + '<div><small>Audience</small><b>' + escapeHtml(g.options[g.selectedIndex].text) + '</b></div>'
        + '<div><small>Campaign</small><b>' + escapeHtml($('ccName').value || 'Untitled') + '</b></div>'
        + '<div><small>Goes out</small><b>' + escapeHtml(when.trim()) + '</b></div>';
}

/* ── Live refresh of the template list ── */
function setLive(state, txt) { var l = $('ccLive'); l.classList.toggle('busy', state === 'busy'); $('ccLiveTxt').textContent = txt; }
function loadTemplates(quiet) {
    if (!quiet) setLive('busy', 'Updating');
    return fetch('ajax_templates.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (d) {
            if (!d || !d.ok) return;
            var fresh = {};
            d.templates.forEach(function (t) { fresh[t.id] = t; });
            tplData = fresh;
            if (selectedId() && !tplData[selectedId()]) { $('templateDropdown').value = ''; selectTemplateDropdown({ value: '' }); }
            else if (selectedId()) { renderPreview(selectedId(), ''); }
            renderPicker();
            setLive('ok', 'Live · ' + Object.keys(tplData).length + ' approved');
        }).catch(function () { setLive('ok', 'Live'); });
}
$('ccSync').addEventListener('click', function () {
    var b = this; b.classList.add('spin'); setLive('busy', 'Syncing with Meta');
    fetch('templates.php?sync=1', { credentials: 'same-origin' })
        .then(function () { return loadTemplates(true); })
        .finally(function () { b.classList.remove('spin'); });
});
$('ccCat').addEventListener('change', function () {
    ccFilter = this.value;
    var cur = tplData[selectedId()];
    if (cur && ccFilter !== 'all' && catOf(cur) !== ccFilter) { chooseTemplate(''); return; }
    renderPicker();
});
$('ccTplSel').addEventListener('change', function () { chooseTemplate(this.value); });
$('ccWhen').addEventListener('click', function (e) { var b = e.target.closest('button[data-w]'); if (b) setWhen(b.dataset.w); });
setInterval(function () { if (!document.hidden) loadTemplates(true); }, 30000);
document.addEventListener('visibilitychange', function () { if (!document.hidden) loadTemplates(true); });

/* ── Validation ── */
$('campaignForm').addEventListener('submit', function (e) {
    if (!selectedId()) { e.preventDefault(); alert('Please choose a template in Step 1.'); return; }
    if (!$('ccName').value.trim()) { e.preventDefault(); alert('Please enter a Campaign title in Step 2.'); return; }
});

/* ── Init ── */
renderPicker();
if (selectedId() && tplData[selectedId()]) selectTemplateDropdown({ value: selectedId() }); else renderPreview('', '');
renderSummary();
setLive('ok', 'Live · ' + Object.keys(tplData).length + ' approved');
</script>

<?php include '../includes/client_footer.php'; ?>
