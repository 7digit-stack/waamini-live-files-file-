<?php
include '../includes/client_auth_check.php';
// Optional legacy migration helper (not required in current installs)
if (file_exists(__DIR__ . '/../upgrade_templates_table.php')) {
    require_once __DIR__ . '/../upgrade_templates_table.php';
}

$pageTitle = 'Templates';
$currentPage = 'templates';

// Handle sync from WhatsApp Cloud API
if (isset($_GET['sync'])) {    // ... existing sync logic ...
    // Use central config resolver (decrypts access_token + supports env fallback)
    require_once __DIR__ . '/../services/WhatsAppService.php';
    $waConfig = WhatsAppService::getConfig($conn, (int)$companyId);
    $syncCount = 0;
    $syncError = '';

    $token = is_array($waConfig) ? (string)($waConfig['access_token'] ?? '') : '';
    $wabaId = is_array($waConfig) ? (string)($waConfig['waba_id'] ?? '') : '';
    $apiVer = is_array($waConfig) ? (string)($waConfig['api_version'] ?? 'v18.0') : 'v18.0';

    // If WABA ID isn't stored yet, allow env override (common on localhost)
    if ($wabaId === '') {
        $wabaId = (string)($_ENV['WHATSAPP_WABA_ID'] ?? getenv('WHATSAPP_WABA_ID') ?: ($_ENV['WA_WABA_ID'] ?? getenv('WA_WABA_ID') ?: ''));
    }

    if ($token !== '' && $wabaId !== '') {
        $url = "https://graph.facebook.com/" . ($apiVer ?: 'v18.0') . "/" . $wabaId . "/message_templates";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $token],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $tpl) {
                $tplName = $tpl['name'] ?? '';
                $tplCategory = strtolower($tpl['category'] ?? 'utility');
                $tplLang = $tpl['language'] ?? 'en';
                $tplStatus = $tpl['status'] ?? 'PENDING';

                // Extract body from components
                $tplBody = '';
                $tplHeader = '';
                $tplFooter = '';
                $tplHeaderType = 'none';
                $tplButtons = [];
                if (isset($tpl['components'])) {
                    foreach ($tpl['components'] as $comp) {
                        if ($comp['type'] === 'BODY')
                            $tplBody = $comp['text'] ?? '';
                        
                        if ($comp['type'] === 'HEADER') {
                            $format = $comp['format'] ?? 'TEXT';
                            $tplHeaderType = strtolower($format);
                            if ($format === 'TEXT') {
                                $tplHeader = $comp['text'] ?? '';
                            }
                        }
                        
                        if ($comp['type'] === 'FOOTER')
                            $tplFooter = $comp['text'] ?? '';

                        // Keep buttons in the panel's format (FLOW buttons are needed for WA Native Forms group sends)
                        if ($comp['type'] === 'BUTTONS' && is_array($comp['buttons'] ?? null)) {
                            $tplButtons = [];
                            foreach ($comp['buttons'] as $mb) {
                                $bt = strtoupper((string)($mb['type'] ?? ''));
                                $b = ['type' => ['QUICK_REPLY' => 'quick_reply', 'URL' => 'url', 'PHONE_NUMBER' => 'phone', 'FLOW' => 'flow', 'COPY_CODE' => 'copy_code'][$bt] ?? strtolower($bt),
                                      'text' => (string)($mb['text'] ?? '')];
                                if ($bt === 'URL') $b['url'] = (string)($mb['url'] ?? '');
                                if ($bt === 'PHONE_NUMBER') $b['phone'] = (string)($mb['phone_number'] ?? '');
                                if ($bt === 'FLOW') $b['flow_id'] = (string)($mb['flow_id'] ?? '');
                                $tplButtons[] = $b;
                            }
                        }
                    }
                }

                // Check if template already exists
                $check = $conn->prepare("SELECT id FROM message_templates WHERE company_id=? AND name=? AND language=?");
                $check->bind_param("iss", $companyId, $tplName, $tplLang);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $ins = $conn->prepare("INSERT INTO message_templates (company_id, name, category, language, header_type, header_text, body, footer, wa_status) VALUES (?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("issssssss", $companyId, $tplName, $tplCategory, $tplLang, $tplHeaderType, $tplHeader, $tplBody, $tplFooter, $tplStatus);
                    $ins->execute();
                    $syncCount++;
                }
                else {
                    // Update existing
                    $upd = $conn->prepare("UPDATE message_templates SET body=?, header_text=?, header_type=?, footer=?, wa_status=?, category=? WHERE company_id=? AND name=? AND language=?");
                    // body, header_text, header_type, footer, wa_status, category, company_id, name, language
                    $upd->bind_param("ssssssiss", $tplBody, $tplHeader, $tplHeaderType, $tplFooter, $tplStatus, $tplCategory, $companyId, $tplName, $tplLang);
                    $upd->execute();
                }
                if (!empty($tplButtons)) {
                    $btnJson = json_encode($tplButtons, JSON_UNESCAPED_UNICODE);
                    $updB = $conn->prepare("UPDATE message_templates SET buttons=? WHERE company_id=? AND name=? AND language=?");
                    if ($updB) {
                        $updB->bind_param("siss", $btnJson, $companyId, $tplName, $tplLang);
                        $updB->execute();
                    }
                }
            }
        }
        else {
            $syncError = $data['error']['message'] ?? 'Unknown API error';
        }
    }
    else {
        if ($token === '') {
            $syncError = 'WhatsApp access token missing/invalid. Re-save token in WhatsApp Setup (or check WAAMINI_ENC_KEY if token was encrypted).';
        } elseif ($wabaId === '') {
            $syncError = 'WABA ID missing. Save WABA ID in WhatsApp Setup (or set WHATSAPP_WABA_ID in .env for localhost).';
        } else {
            $syncError = 'WhatsApp not configured. Go to WhatsApp Setup first.';
        }
    }

    if ($syncError) {
        header("Location: templates.php?error=" . urlencode($syncError));
    }
    else {
        header("Location: templates.php?synced=$syncCount");
    }
    exit();
}

// Handle single delete via POST (VULN-004 FIX: Converted from GET to POST + CSRF)
if (isset($_POST['delete_template'])) {
    csrf_verify();
    $did = intval($_POST['delete_id']);
    $stTp2 = $conn->prepare("DELETE FROM message_templates WHERE id=? AND company_id=?");
    $stTp2->bind_param("ii", $did, $companyId);
    $stTp2->execute();
    header("Location: templates.php?msg=deleted");
    exit();
}

// Handle bulk delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    csrf_verify(); // VULN-004 FIX: Added CSRF verification for bulk delete
    $ids = $_POST['selected_ids'] ?? [];
    if (is_array($ids) && count($ids) > 0) {
        $clean_ids = array_map('intval', $ids);
        $in_clause = implode(',', $clean_ids);
        $stTp2 = $conn->prepare("DELETE FROM message_templates WHERE id IN ($in_clause) AND company_id=?");
        $stTp2->bind_param("i", $companyId);
        $stTp2->execute();
        header("Location: templates.php?msg=bulk_deleted");
        exit();
    }
}


$stTp3 = $conn->prepare("SELECT * FROM message_templates WHERE company_id=? ORDER BY id DESC");
$stTp3->bind_param("i", $companyId);
$stTp3->execute();
$tplRes = $stTp3->get_result();
$templates = [];
while ($tplRes && ($r = $tplRes->fetch_assoc())) {
    $templates[] = $r;
}

/* Map Meta's wa_status onto four groups the UI understands */
function tp_group(?string $waStatus): string {
    switch (strtoupper(trim((string)$waStatus))) {
        case 'APPROVED': return 'approved';
        case 'PENDING': case 'IN_APPEAL': return 'pending';
        case 'REJECTED': case 'PAUSED': case 'DISABLED': return 'rejected';
        default: return 'local';
    }
}
/* ── Render a template the way WhatsApp shows it (media, bold text, footer, button rows) ── */
function tp_fmt(string $t): string {
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\*(.+?)\*/s', '<b>$1</b>', $t);
    $t = preg_replace('/(?<![\w])_(.+?)_(?![\w])/s', '<i>$1</i>', $t);
    $t = preg_replace('/~(.+?)~/s', '<s>$1</s>', $t);
    return nl2br($t);
}
function tp_btn_icon(string $type): string {
    $a = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    if ($type === 'url')   return '<svg ' . $a . '><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
    if ($type === 'phone') return '<svg ' . $a . '><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
    return '<svg ' . $a . '><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>';
}
/**
 * @param array{media?:string,header?:string,body?:string,footer?:string,buttons?:array<int,array{0:string,1:string}>,time?:string} $m
 */
function tp_msg(array $m, bool $callouts = false): string {
    $co = static function (int $n) use ($callouts): string {
        return $callouts ? '<span class="tp-callout">' . $n . '</span>' : '';
    };
    $media = (string)($m['media'] ?? '');
    $labels = ['image' => 'Image', 'video' => 'Video', 'document' => 'Document', 'location' => 'Location'];
    $o = '<div class="tp-msg"><div class="tp-bubble">';
    if ($media !== '' && isset($labels[$media])) {
        $o .= '<div class="part">' . $co(1) . '<div class="tp-media"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>' . $labels[$media] . ' header</div></div>';
    } elseif (!empty($m['header'])) {
        $o .= '<div class="part">' . $co(1) . '<div class="h">' . htmlspecialchars((string)$m['header']) . '</div></div>';
    }
    $o .= '<div class="part">' . $co(2) . '<div class="b">' . tp_fmt((string)($m['body'] ?? '')) . '</div></div>';
    if (!empty($m['footer'])) {
        $o .= '<div class="part">' . $co(3) . '<div class="f">' . htmlspecialchars((string)$m['footer']) . '</div></div>';
    }
    $o .= '<div class="t">' . htmlspecialchars((string)($m['time'] ?? '10:42 AM')) . '</div></div>';
    if (!empty($m['buttons'])) {
        $o .= '<div class="tp-btns">';
        foreach ($m['buttons'] as $i => $b) {
            $o .= '<div class="bt">' . ($i === 0 ? $co(4) : '') . tp_btn_icon((string)$b[1]) . htmlspecialchars((string)$b[0]) . '</div>';
        }
        $o .= '</div>';
    }
    return $o . '</div>';
}

$groupInfo = [
    'approved' => ['Approved', 'Ready to send'],
    'pending'  => ['Pending',  'Waiting for Meta review'],
    'rejected' => ['Rejected', 'Needs changes'],
    'local'    => ['Local',    'Saved here only'],
];
$counts = ['all' => count($templates), 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'local' => 0];
foreach ($templates as $t) { $counts[tp_group($t['wa_status'] ?? '')]++; }
$startTab = $counts['all'] > 0 ? 'list' : 'guide';

include '../includes/client_header.php';
?>

<style>
/* ── Templates — dashboard design system, no divider lines ── */
.tp{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.tp *{box-sizing:border-box}
.tp-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.tp-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.tp-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.tp-hero-ico svg{width:22px;height:22px}
.tp-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.tp-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:540px;line-height:1.5}
.tp-hero-actions{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.tp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.tp-btn:hover{border-color:var(--g);color:var(--g)}
.tp-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.tp-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.tp-btn--danger{background:#fff;color:#c62828;border-color:#f5c6c6}
.tp-btn--danger:hover{background:#fdecec;border-color:#c62828;color:#c62828}
.tp-btn.syncing{opacity:.7;pointer-events:none}
.tp-btn.syncing svg{animation:tp-spin .9s linear infinite}
@keyframes tp-spin{to{transform:rotate(360deg)}}

.tp-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;background:#E3F7EE;color:#0B6B5E}
.tp-notice.err{background:#FDE7E7;color:#9b1c1c}

.tp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.tp-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s}
.tp-kpi:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.tp-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.tp-kpi-ico svg{width:20px;height:20px}
.tp-kpi.is-blue .tp-kpi-ico{background:#E4EFFD;color:#1d6fd6}
.tp-kpi.is-amber .tp-kpi-ico{background:#FEF3DC;color:#b7791f}
.tp-kpi.is-pink .tp-kpi-ico{background:#FDE7F1;color:#d1356f}
.tp-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.tp-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

/* tabs */
.tp-tabs{display:inline-flex;gap:6px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:5px;margin-bottom:18px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-tab{border:0;background:transparent;padding:9px 20px;border-radius:10px;font:700 14px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer;transition:background .15s,color .15s}
.tp-tab:hover{background:var(--soft)}
.tp-tab.on{background:var(--g);color:#fff}
.tp-pane{display:none}.tp-pane.on{display:block}

.tp-panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);padding:18px 18px 12px}
.tp-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.tp-chips{display:flex;gap:8px;flex-wrap:wrap}
.tp-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;background:var(--soft);color:var(--tx);font:600 13.5px Inter,system-ui,sans-serif;border:0;cursor:pointer;transition:background .15s,color .15s}
.tp-chip:hover{background:#E7F7F1}
.tp-chip.on{background:var(--g);color:#fff}
.tp-chip em{font-style:normal;font-size:12px;opacity:.8}
.tp-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.tp-search{display:flex;align-items:center;gap:10px;background:var(--soft);border-radius:11px;padding:0 14px;height:40px;min-width:240px}
.tp-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0}
.tp-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.tp-search:focus-within{box-shadow:0 0 0 2px var(--g);background:#fff}

.tp-list{display:flex;flex-direction:column;gap:8px}
.tp-row{display:grid;grid-template-columns:36px minmax(170px,1.3fr) minmax(200px,2fr) 170px 128px;gap:14px;align-items:center;padding:12px 14px;border-radius:13px;background:var(--soft);transition:background .15s}
.tp-row:not(.tp-head):hover{background:#E7F7F1}
.tp-head{background:transparent;padding-top:2px;padding-bottom:2px;font-size:12px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.tp-row input[type=checkbox]{accent-color:var(--g);width:17px;height:17px;cursor:pointer}
.tp-name{font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tp-meta{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap}
.tp-tag{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:999px;background:#fff;color:var(--tx)}
.tp-tag.cat-marketing{background:#EFE9FD;color:#6d44d8}
.tp-tag.cat-utility{background:#E4EFFD;color:#1d6fd6}
.tp-tag.cat-authentication{background:#FEF3DC;color:#a15c00}
.tp-body{font-size:13.5px;color:var(--tx);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tp-pill{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.tp-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.tp-pill.s-approved{background:#E3F7EE;color:#0B6B5E}
.tp-pill.s-pending{background:#FEF3DC;color:#8a5a00}
.tp-pill.s-rejected{background:#FDE7E7;color:#c62828}
.tp-pill.s-local{background:#EEF3F1;color:#5d6f69}
.tp-hint{font-size:12px;color:var(--mu);margin-top:4px}
.tp-acts{display:flex;gap:6px;justify-content:flex-end}
.tp-ib{width:36px;height:36px;border-radius:10px;border:0;background:#fff;color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}
.tp-ib:hover{background:var(--g);color:#fff}
.tp-ib.danger:hover{background:#c62828}
.tp-ib svg{width:17px;height:17px}
.tp-empty{text-align:center;padding:44px 20px}
.tp-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 16px}
.tp-empty-ico svg{width:30px;height:30px}
.tp-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.tp-empty p{margin:0 0 18px;color:var(--mu);font-size:14.5px}
.tp-none{display:none;text-align:center;padding:26px;color:var(--mu);font-size:14.5px}

/* guide */
.tp-sec-title{font-size:18px;font-weight:800;margin:0 0 4px}
.tp-sec-sub{font-size:13.5px;color:var(--mu);margin:0 0 16px;line-height:1.5}
.tp-block{margin-bottom:26px}
.tp-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.tp-step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-step-n{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;font-size:14px;font-weight:800;display:grid;place-items:center;margin-bottom:12px}
.tp-step b{display:block;font-size:15.5px;margin-bottom:5px}
.tp-step span{font-size:13.5px;color:var(--mu);line-height:1.55}
.tp-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.tp-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.tp-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-card h3{margin:0 0 6px;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px}
.tp-card p{margin:0;font-size:13.5px;color:var(--mu);line-height:1.6}
.tp-card ul{margin:10px 0 0;padding:0;list-style:none;display:grid;gap:7px}
.tp-card li{font-size:13.5px;color:var(--tx);line-height:1.5;padding:8px 12px;border-radius:10px;background:var(--soft)}
.tp-card li b{color:var(--ink)}
.tp-card.do li{background:#EAF8F1}
.tp-card.dont li{background:#FDEEEE}
.tp-ico{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:#E3F7EE;color:var(--g);flex-shrink:0}
.tp-ico svg{width:18px;height:18px}
.tp-ico.blue{background:#E4EFFD;color:#1d6fd6}.tp-ico.purple{background:#EFE9FD;color:#6d44d8}.tp-ico.amber{background:#FEF3DC;color:#b7791f}.tp-ico.red{background:#FDE7E7;color:#c62828}
.tp-limits{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.tp-limit{background:var(--soft);border-radius:13px;padding:14px 16px}
.tp-limit b{display:block;font-size:20px;font-weight:800;color:var(--gd)}
.tp-limit span{font-size:12.5px;color:var(--mu);font-weight:600}

/* guide extras */
.tp-short{display:flex;gap:16px;align-items:flex-start;padding:20px 22px;border-radius:16px;background:var(--soft);margin-bottom:26px}
.tp-short-ico{width:42px;height:42px;border-radius:10px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.tp-short-ico svg{width:20px;height:20px}
.tp-short h2{margin:0 0 4px;font-size:17px;font-weight:800}
.tp-short p{margin:0;font-size:14.5px;color:var(--tx);line-height:1.65}
.tp-steps5{grid-template-columns:repeat(5,minmax(0,1fr)) !important}
.tp-anat{display:grid;grid-template-columns:minmax(260px,380px) 1fr;gap:20px;align-items:start}
.tp-anat-list{display:grid;gap:10px}
.tp-anat-list>div{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px 14px 56px;position:relative;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-anat-list>div .tp-callout{position:absolute;left:16px;top:14px;margin:0}
.tp-anat-list b{font-size:15px}
.tp-anat-list em{font-size:12.5px;color:var(--mu);font-style:normal;margin-left:4px}
.tp-anat-list p{margin:4px 0 0;font-size:13.5px;color:var(--mu);line-height:1.55}
.tp-callout{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:50%;background:var(--g);color:#fff;font-size:11.5px;font-weight:800;margin-right:7px;vertical-align:middle;flex-shrink:0}
.tp-form{display:grid;gap:10px}
.tp-form-row{display:flex;gap:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-form-n{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;font-weight:800;display:grid;place-items:center;flex-shrink:0}
.tp-form-row b{font-size:15.5px}
.tp-form-row p{margin:4px 0 0;font-size:14px;color:var(--tx);line-height:1.65}
.tp-form-row code,.tp-faq code{background:var(--soft);padding:1px 7px;border-radius:6px;font-size:13px;color:var(--gd);font-weight:600}
.tp-qa{display:grid;gap:10px}
.tp-qa-row{display:grid;grid-template-columns:minmax(220px,1fr) 2fr;gap:14px;background:var(--soft);border-radius:14px;padding:14px 18px;align-items:center}
.tp-qa-q{font-size:14.5px;color:var(--ink)}
.tp-qa-a{font-size:14px;color:var(--tx);line-height:1.6}
.tp-qa-a .tp-tag{margin-right:8px}
.tp-check{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
.tp-check label{display:flex;gap:10px;align-items:flex-start;background:var(--soft);border-radius:12px;padding:12px 14px;font-size:14px;color:var(--tx);line-height:1.5;cursor:pointer}
.tp-check input{accent-color:var(--g);width:17px;height:17px;margin-top:2px;flex-shrink:0}
.tp-check label:has(input:checked){background:#E3F7EE;color:var(--gd)}
.tp-faq{display:grid;gap:8px}
.tp-faq details{background:#fff;border:1px solid var(--line);border-radius:14px;padding:0;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.tp-faq summary{cursor:pointer;list-style:none;padding:15px 18px;font-weight:700;font-size:14.5px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.tp-faq summary::-webkit-details-marker{display:none}
.tp-faq summary::after{content:'+';font-size:20px;font-weight:600;color:var(--g);flex-shrink:0;line-height:1}
.tp-faq details[open] summary::after{content:'−'}
.tp-faq details[open]{background:var(--soft)}
.tp-faq p{margin:0;padding:0 18px 16px;font-size:14px;color:var(--tx);line-height:1.65}
.tp-faq a{color:var(--gd);font-weight:700}
.tp-gloss{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
.tp-gloss>div{background:var(--soft);border-radius:12px;padding:12px 16px;display:flex;flex-direction:column;gap:2px}
.tp-gloss b{font-size:14.5px;color:var(--gd)}
.tp-gloss span{font-size:13.5px;color:var(--tx);line-height:1.5}
@media (max-width:1100px){.tp-steps5{grid-template-columns:repeat(2,minmax(0,1fr)) !important}.tp-anat{grid-template-columns:1fr}}
@media (max-width:900px){.tp-qa-row{grid-template-columns:1fr}.tp-check,.tp-gloss{grid-template-columns:1fr}.tp-3[style]{grid-template-columns:repeat(2,minmax(0,1fr)) !important}}
@media (max-width:640px){.tp-steps5{grid-template-columns:1fr !important}.tp-3[style]{grid-template-columns:1fr !important}.tp-short{flex-direction:column}}

/* ── Beginner guide wizard ── */
.gd{max-width:1180px}
.gd-top{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:16px}
.gd-kicker{font-size:13px;font-weight:700;color:var(--g);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.gd-title{margin:0;font-size:26px;font-weight:800;letter-spacing:-.4px}
.gd-prog{display:flex;align-items:center;gap:12px;min-width:220px}
.gd-prog-bar{flex:1;height:9px;background:#DCEBE5;border-radius:99px;overflow:hidden}
.gd-prog-bar i{display:block;height:100%;width:4%;background:linear-gradient(90deg,var(--g),var(--g2));border-radius:99px;transition:width .35s ease}
.gd-prog span{font-size:14px;font-weight:800;color:var(--gd);min-width:38px;text-align:right}
.gd-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:20px;align-items:start}

.gd-rail{background:#fff;border:1px solid var(--line);border-radius:16px;padding:10px;display:flex;flex-direction:column;gap:4px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:sticky;top:90px}
.gd-rail button{display:flex;align-items:center;gap:12px;border:0;background:transparent;text-align:left;padding:11px 12px;border-radius:11px;cursor:pointer;font:600 14.5px Inter,system-ui,sans-serif;color:var(--tx);transition:background .15s,color .15s}
.gd-rail button b{width:28px;height:28px;border-radius:50%;background:var(--soft);color:var(--gd);display:grid;place-items:center;font-size:13px;font-weight:800;flex-shrink:0;transition:background .15s,color .15s}
.gd-rail button:hover{background:var(--soft)}
.gd-rail button.on{background:#E3F7EE;color:var(--gd)}
.gd-rail button.on b{background:var(--g);color:#fff}
.gd-rail button.done b{background:#CDEBDF;color:var(--gd)}
.gd-rail button.done b::before{content:'✓'}
.gd-rail button.done b{font-size:0}
.gd-rail button.done b::before{font-size:14px}

.gd-main{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px 28px 22px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);min-width:0}
.gd-step{display:none;animation:gd-in .3s ease}
.gd-step.on{display:block}
@keyframes gd-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.gd-lead{font-size:17px;line-height:1.65;color:var(--ink);margin:0 0 20px;max-width:760px}
.gd-lead a,.gd-lane a,.gd-note a{color:var(--gd)}
.gd-h3{font-size:17px;font-weight:800;margin:26px 0 12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.gd-note{font-size:13.5px;color:var(--mu);margin:10px 0 0}
.gd-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:18px}
.gd-lane{border-radius:16px;padding:20px;background:var(--soft)}
.gd-lane.ok{background:#EAF8F1}
.gd-lane.warn{background:#FEF6E6}
.gd-lane.bad{background:#FDEEEE}
.gd-lane-ico{width:42px;height:42px;border-radius:10px;background:#fff;color:var(--g);display:grid;place-items:center;margin-bottom:12px}
.gd-lane.warn .gd-lane-ico{color:#b7791f}
.gd-lane-ico svg{width:20px;height:20px}
.gd-lane h4{margin:0 0 6px;font-size:16px;font-weight:800;line-height:1.35}
.gd-lane p{margin:0 0 12px;font-size:14.5px;color:var(--tx);line-height:1.6}
.gd-badge{display:inline-block;font-size:12.5px;font-weight:800;padding:4px 12px;border-radius:999px}
.gd-badge.ok{background:#fff;color:var(--gd)}
.gd-badge.warn{background:#fff;color:#8a5a00}
.gd-tip{display:flex;gap:14px;align-items:flex-start;background:#E3F7EE;border-radius:14px;padding:16px 18px;font-size:14.5px;line-height:1.6;color:var(--gd);margin-top:18px}
.gd-tip svg{width:20px;height:20px;flex-shrink:0;margin-top:2px}
.gd-tip.warn{background:#FEF3DC;color:#7a4b00}

.gd-choices{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}
.gd-choices button{display:flex;flex-direction:column;align-items:flex-start;gap:6px;text-align:left;border:2px solid transparent;background:var(--soft);border-radius:16px;padding:18px;cursor:pointer;font-family:Inter,system-ui,sans-serif;transition:border-color .15s,background .15s,transform .15s}
.gd-choices button:hover{background:#E7F7F1;transform:translateY(-2px)}
.gd-choices button.on{border-color:var(--g);background:#fff;box-shadow:0 14px 30px -20px rgba(18,140,126,.6)}
.gd-choices .ic{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;background:#E3F7EE;color:var(--g);margin-bottom:4px}
.gd-choices .ic svg{width:20px;height:20px}
.gd-choices .ic.purple,.gd-use .ic.purple{background:#EFE9FD;color:#6d44d8}
.gd-choices .ic.blue,.gd-use .ic.blue{background:#E4EFFD;color:#1d6fd6}
.gd-choices .ic.amber,.gd-use .ic.amber{background:#FEF3DC;color:#b7791f}
.gd-choices b{font-size:15.5px;color:var(--ink);line-height:1.35}
.gd-choices small{font-size:13px;color:var(--mu);line-height:1.45}
.gd-result{background:var(--soft);border-radius:16px;padding:18px 20px;min-height:110px;display:flex;align-items:center}
.gd-result-empty{display:none;width:100%;text-align:center;color:var(--mu);font-size:14.5px;font-weight:600}
.gd-result[data-empty] .gd-result-empty{display:block}
.gd-result-body{display:none;width:100%}
.gd-result-body.on{display:block;animation:gd-in .25s ease}
.gd-result-body h4{margin:8px 0 4px;font-size:18px;font-weight:800}
.gd-result-body p{margin:0;font-size:14.5px;color:var(--tx);line-height:1.65}

.gd-var{display:grid;grid-template-columns:minmax(0,1fr) 44px minmax(0,1fr);gap:10px;align-items:center}
.gd-arrow{font-size:26px;color:var(--g);text-align:center;font-weight:800}
.gd-form{display:grid;gap:10px}
.gd-form>div{display:flex;gap:14px;background:var(--soft);border-radius:14px;padding:14px 16px}
.gd-form>div>b{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;display:grid;place-items:center;flex-shrink:0;font-size:14px}
.gd-form h4{margin:0 0 3px;font-size:15.5px;font-weight:800}
.gd-form h4 em{font-style:normal;font-weight:600;color:var(--mu);font-size:13px}
.gd-form p{margin:0;font-size:14px;color:var(--tx);line-height:1.65}
.gd-form code,.gd-lane code,.tp-faq code{background:#fff;padding:1px 7px;border-radius:6px;font-size:13px;color:var(--gd);font-weight:600}
.gd-count{font-size:12.5px;font-weight:700;background:var(--soft);color:var(--mu);padding:3px 11px;border-radius:999px}
.gd-count.ok{background:#E3F7EE;color:var(--gd)}

.gd-line{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}
.gd-line>div{background:var(--soft);border-radius:14px;padding:16px;position:relative;display:flex;flex-direction:column;gap:4px}
.gd-line>div span{width:30px;height:30px;border-radius:50%;background:#CDEBDF;color:var(--gd);display:grid;place-items:center;font-weight:800;font-size:14px;margin-bottom:6px}
.gd-line>div.done span{background:var(--g);color:#fff}
.gd-line>div.mid span{background:#FEF3DC;color:#8a5a00}
.gd-line>div.end span{background:#E4EFFD;color:#1d6fd6}
.gd-line b{font-size:15.5px}
.gd-line small{font-size:13.5px;color:var(--tx);line-height:1.5}
.gd-line>div:not(:last-child)::after{content:'→';position:absolute;right:-13px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--g);font-weight:800;z-index:1}
.gd-stat{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.gd-stat>div{background:var(--soft);border-radius:13px;padding:14px;display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.gd-stat p{margin:0;font-size:13.5px;color:var(--tx);line-height:1.5}
.gd-use{display:grid;gap:12px}
.gd-use-card{display:flex;align-items:center;gap:16px;background:var(--soft);border-radius:16px;padding:18px 20px;text-decoration:none;color:inherit;transition:background .15s,transform .15s}
.gd-use-card:hover{background:#E7F7F1;transform:translateY(-2px);color:inherit}
.gd-use .ic{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;background:#E3F7EE;color:var(--g);flex-shrink:0}
.gd-use .ic svg{width:21px;height:21px}
.gd-use h4{margin:0 0 3px;font-size:16.5px;font-weight:800}
.gd-use p{margin:0;font-size:14px;color:var(--tx);line-height:1.5}
.gd-use-card i{margin-left:auto;font-style:normal;font-size:22px;color:var(--g);font-weight:800}

.gd-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:26px;padding-top:6px}
.gd-nav-r{display:flex;gap:10px;flex-wrap:wrap}

@media (max-width:1000px){
  .gd-layout{grid-template-columns:1fr}
  .gd-rail{position:static;flex-direction:row;overflow-x:auto;padding:8px;gap:6px}
  .gd-rail button{flex-shrink:0;padding:8px 12px}
  .gd-rail button span{display:none}
  .gd-rail button.on span{display:inline}
  .gd-choices,.gd-stat{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:720px){
  .gd-main{padding:20px 16px}
  .gd-title{font-size:21px}
  .gd-two,.gd-line,.gd-choices,.gd-stat{grid-template-columns:1fr}
  .gd-line>div:not(:last-child)::after{content:'↓';right:auto;left:50%;top:auto;bottom:-14px;transform:translateX(-50%)}
  .gd-var{grid-template-columns:1fr}
  .gd-arrow{transform:rotate(90deg)}
  .gd-lead{font-size:16px}
  .gd-nav-r,.gd-nav-r .tp-btn{width:100%}
}

/* ── Meta-style stepper + "Set up your template" screen ── */
.gd-layout{display:block}
.gd-rail{position:static;flex-direction:row;flex-wrap:wrap;align-items:center;gap:2px;padding:0;margin-bottom:14px;background:transparent;border:0;box-shadow:none;overflow:visible}
.gd-rail button{padding:7px 12px;border-radius:6px;gap:8px;font:600 14px Inter,system-ui,sans-serif;color:#67788A;background:transparent}
.gd-rail button b{width:16px;height:16px;border-radius:0;background:transparent !important;color:inherit;display:grid;place-items:center;font-size:0 !important}
.gd-rail button b::before{content:none !important}
.gd-rail button b svg{width:16px;height:16px}
.gd-rail button:not(:last-child)::after{content:'';width:12px;height:1px;background:#CBD2D9;margin-left:8px}
.gd-rail button:hover{background:#F3F5F7}
.gd-rail button.on{background:#DCE9F5;color:#1C2B33}
.gd-rail button.on b,.gd-rail button.done b{color:#0A78BE}
.gd-rail button span{display:inline !important}
.gd-main{padding:22px 24px 20px}

.gd-meta{--page:#EEF1F6;--blue:#0A78BE;--blue-strong:#075F97;--sel:#DCE9F5;--tab-sel:#DDE2E7;--ink:#1C2B33;--ink-2:#465A69;--ink-3:#67788A;--line:#CBD2D9;--line-soft:#E3E7EC;--hover:#F3F5F7;--wall:#E9E1D8}
.gd-step.gd-meta.on{margin:-22px -24px -20px;padding:22px 24px 20px;background:linear-gradient(115deg,#F4EEF5 0%,#EEF1F7 40%,#E5EEF8 100%);border-radius:16px 16px 0 0}
.mb-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start}
.mb-col{display:flex;flex-direction:column;gap:12px;min-width:0}
.mb-card{background:#fff;border-radius:6px;box-shadow:0 0 0 1px rgba(28,43,51,.06),0 1px 3px rgba(28,43,51,.1);padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0;color:var(--ink)}
.mb-card h2{margin:0;font-size:16px;font-weight:700;line-height:1.3}
.mb-card h3{margin:0;font-size:14px;font-weight:700}
.mb-card h4{margin:0;font-size:14px;font-weight:700}
.mb-desc{margin:0;color:var(--ink-2);font-size:14px;line-height:1.5}
.mb-link{border:0;background:none;padding:0;color:var(--blue);font:inherit;cursor:pointer}
.mb-link:hover{text-decoration:underline}
.mb-learn{background:var(--hover);border-radius:6px;padding:12px 14px;display:grid;gap:6px;font-size:13.5px;color:var(--ink-2);line-height:1.55}
.mb-learn p{margin:0}
.mb-learn[hidden]{display:none}

.mb-seg{position:relative;display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#fff}
.mb-seg .thumb{position:absolute;top:0;bottom:0;left:0;width:33.3334%;background:var(--tab-sel);transform:translateX(calc(var(--i,0)*100%));transition:transform .32s cubic-bezier(.3,.9,.3,1)}
.mb-seg button{position:relative;z-index:1;display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-left:1px solid var(--line);background:transparent;padding:9px 6px;font:700 14px Inter,system-ui,sans-serif;color:var(--ink);cursor:pointer;min-width:0}
.mb-seg button:first-of-type{border-left:0}
.mb-seg button svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none}

.mb-types{position:relative;display:flex;flex-direction:column}
.mb-hl{position:absolute;left:0;right:0;top:0;height:0;background:var(--sel);border-radius:4px;transition:transform .28s cubic-bezier(.3,.9,.3,1),height .28s}
.mb-type{position:relative;z-index:1;display:flex;gap:12px;align-items:flex-start;padding:9px 12px;border-radius:4px;cursor:pointer;border:0;background:transparent;text-align:left;font-family:inherit;color:inherit;width:100%}
.mb-type:not(.on):hover{background:var(--hover)}
.mb-type .dot{width:16px;height:16px;border-radius:50%;border:1.5px solid #8595A3;background:#fff;margin-top:2px;flex:none;display:grid;place-items:center;transition:border-color .2s}
.mb-type .dot::after{content:'';width:8px;height:8px;border-radius:50%;background:var(--blue);transform:scale(0);transition:transform .22s cubic-bezier(.3,1.6,.5,1)}
.mb-type.on .dot{border-color:var(--blue)}.mb-type.on .dot::after{transform:scale(1)}
.mb-type b{display:block;font-weight:400;font-size:14px;line-height:1.35}
.mb-type .d{display:block;font-size:12px;color:var(--ink-3);line-height:1.4}
.mb-type:focus-visible{outline:2px solid var(--blue);outline-offset:-2px}

.mb-lib{padding:0;gap:0;overflow:hidden}
.mb-librow{display:grid;grid-template-columns:194px minmax(0,1fr)}
.mb-libart{background:#BFE3C4;min-height:140px;position:relative;overflow:hidden}
.mb-libart svg{position:absolute;inset:0;width:100%;height:100%}
.mb-libart .fl{animation:mbfloat 5s ease-in-out infinite}.mb-libart .fl2{animation:mbfloat 6s ease-in-out -2s infinite}
@keyframes mbfloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
.mb-libtxt{padding:16px;display:flex;flex-direction:column;gap:8px}
.mb-libtxt .mb-btn{align-self:flex-end;margin-top:6px}
.mb-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:#fff;border-radius:6px;padding:7px 14px;font:600 14px Inter,system-ui,sans-serif;color:var(--ink);cursor:pointer;transition:background .15s}
.mb-btn:hover{background:var(--hover)}
.mb-rules{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.mb-rules li{display:flex;gap:9px;align-items:flex-start;color:var(--ink-2);font-size:14px;line-height:1.5}
.mb-rules li::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--blue);margin-top:8px;flex:none}

.mb-side{position:sticky;top:90px}
.mb-wall{position:relative;border-radius:4px;overflow:hidden;min-height:420px;background-color:var(--wall);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='84' height='84' fill='none' stroke='%23DCD2C6' stroke-width='1.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='14' cy='16' r='5'/%3E%3Cpath d='M46 10l6 6m0-6l-6 6M60 46c4-4 8 4 12 0M12 54h10v8H12zM38 50l5 9 5-9zM66 20v8m-4-4h8M30 32c3 3 7 3 10 0'/%3E%3C/svg%3E")}
.mb-chat{padding:14px 12px 16px 16px;display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.mb-msg{position:relative;max-width:250px;width:100%;background:#fff;color:#111B21;border-radius:0 8px 8px 8px;box-shadow:0 1px .5px rgba(11,20,26,.16);font-size:13.5px;line-height:1.38;animation:mbpop .36s cubic-bezier(.2,.9,.3,1.2) both;transform-origin:top left}
.mb-msg::before{content:'';position:absolute;left:-7px;top:0;border-top:9px solid #fff;border-left:8px solid transparent}
@keyframes mbpop{from{opacity:0;transform:scale(.92) translateY(8px)}to{opacity:1;transform:none}}
.mb-msg .pad{padding:6px 8px 5px;display:flex;flex-direction:column;gap:3px}
.mb-media{margin:3px 3px 0;border-radius:6px;overflow:hidden;aspect-ratio:1.91/1;background:linear-gradient(135deg,#F3D9B8,#E0A63C 55%,#B4472F);display:grid;place-items:center;color:rgba(255,255,255,.9)}
.mb-media svg{width:34px;height:34px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.mb-thumb{display:flex;gap:8px;margin:3px 3px 0;background:#F0F2F5;border-radius:6px;overflow:hidden;align-items:center}
.mb-thumb i{width:60px;height:60px;flex:none;background:linear-gradient(135deg,#F3D9B8,#E0A63C)}
.mb-thumb div{padding:5px 8px 5px 0;min-width:0}
.mb-thumb b{display:block;font-size:12.5px;line-height:1.25}.mb-thumb span{font-size:11.5px;color:#6B7C85;line-height:1.25;display:block}
.mb-bhead{font-weight:700}
.mb-bbody{overflow-wrap:anywhere;white-space:pre-wrap}
.mb-var{border-bottom:1.5px dotted #0A8BD6}
.mb-bfoot{color:#6B7C85;font-size:12px}
.mb-meta{text-align:right;color:#6B7C85;font-size:11px;margin-top:-1px}
.mb-bbtn{display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;border-top:1px solid #E6EAED;color:#0A8BD6;font-weight:600}
.mb-bbtn:last-child{border-radius:0 0 8px 8px}
.mb-bbtn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.mb-ocard{border:1px solid #E6EAED;border-radius:6px;padding:7px 8px;display:flex;flex-direction:column;gap:5px;margin-top:2px;font-size:12.5px}
.mb-ocard .oh{display:flex;justify-content:space-between;gap:8px;align-items:center}
.mb-ocard .ln{display:flex;justify-content:space-between;gap:8px;color:#465A69}
.mb-ocard .ln.tot{border-top:1px solid #E6EAED;padding-top:5px;font-weight:700;color:#111B21}
.mb-pill{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#DCE9F5;color:#075F97;white-space:nowrap}
.mb-prog{height:4px;border-radius:2px;background:#E6EAED;overflow:hidden}
.mb-prog i{display:block;height:100%;background:#0A78BE;border-radius:2px}
.mb-psteps{display:flex;justify-content:space-between;font-size:10px;color:#6B7C85}
.mb-psteps .at{color:#111B21;font-weight:700}
.mb-kv{display:flex;flex-direction:column;gap:3px}
.mb-kv p{margin:0;color:var(--ink-2);font-size:13.5px;line-height:1.5}
.mb-fine{margin:0;font-size:12px;color:var(--ink-3)}

@media (max-width:1000px){.mb-grid{grid-template-columns:minmax(0,1fr)}.mb-side{position:static}.gd-main{padding:18px 16px}.gd-step.gd-meta.on{margin:-18px -16px;padding:18px 16px}}
@media (max-width:720px){.mb-librow{grid-template-columns:1fr}.mb-libart{min-height:110px}.mb-seg button svg{display:none}.gd-rail button:not(:last-child)::after{display:none}.gd-rail button span{display:none !important}.gd-rail button.on span{display:inline !important}}
@media (prefers-reduced-motion:reduce){.mb-msg,.mb-libart .fl,.mb-libart .fl2{animation:none}}

/* stepper: solid, high-contrast bar */
.gd-rail{background:#fff !important;border-radius:12px !important;padding:8px !important;box-shadow:0 0 0 1px rgba(28,43,51,.08),0 6px 18px -10px rgba(28,43,51,.25) !important;flex-wrap:nowrap !important;overflow-x:auto;gap:4px !important;margin-bottom:16px !important;scrollbar-width:thin}
.gd-rail button{flex:1 1 0;min-width:max-content;justify-content:center;white-space:nowrap;padding:11px 14px !important;font-size:15px !important;font-weight:700 !important;color:#1C2B33 !important;border-radius:9px !important}
.gd-rail button b{width:20px !important;height:20px !important;color:#8595A3}
.gd-rail button b svg{width:20px !important;height:20px !important}
.gd-rail button::after{display:none !important}
.gd-rail button:hover{background:#F0F4F8}
.gd-rail button.done{color:#075F97 !important}
.gd-rail button.done b{color:#0A78BE}
.gd-rail button.on{background:#0A78BE !important;color:#fff !important;box-shadow:0 6px 14px -6px rgba(10,120,190,.7)}
.gd-rail button.on b{color:#fff !important}
@media (max-width:900px){.gd-rail button{min-width:0;flex:0 0 auto}.gd-rail button span{display:none !important}.gd-rail button.on span{display:inline !important}.gd-rail button{padding:10px 12px !important}}
/* examples */
.tp-ex{display:flex;flex-direction:column;gap:12px}
.tp-ex-top{display:flex;justify-content:space-between;align-items:center;gap:10px}
.tp-ex h4{margin:0;font-size:15px;font-weight:800}
/* WhatsApp message look (matches a real template on the phone) */
.tp-wa{background:#efeae2 url(../assets/img/wa-wallpaper.jpg) repeat;background-size:360px auto;border-radius:14px;padding:18px 16px}
.tp-wa.callouts{padding-left:46px}
.tp-msg{display:flex;flex-direction:column;gap:3px;max-width:340px}
.tp-bubble{background:#fff;border-radius:0 9px 9px 9px;padding:4px;box-shadow:0 1px .5px rgba(11,20,26,.13);font-size:14.5px;line-height:1.4;color:#111b21;position:relative;max-width:100%}
.tp-bubble::before{content:'';position:absolute;top:0;left:-7px;width:8px;height:12px;background:#fff;clip-path:polygon(100% 0,0 0,100% 100%)}
.tp-media{height:150px;border-radius:7px;background:linear-gradient(135deg,#dff3ea,#c9e6f2);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:#5b8a99;font-size:12.5px;font-weight:600}
.tp-media svg{width:34px;height:34px}
.tp-bubble .h{font-weight:700;padding:7px 6px 0}
.tp-bubble .b{padding:6px 6px 0;overflow-wrap:anywhere}
.tp-bubble .f{font-size:12.5px;color:#667781;padding:5px 6px 0}
.tp-bubble .t{font-size:11px;color:#667781;text-align:right;padding:2px 6px 2px}
.tp-btns{display:flex;flex-direction:column;gap:3px}
.tp-btns .bt{background:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 10px;font-size:14.5px;font-weight:500;color:#00a5f4;box-shadow:0 1px .5px rgba(11,20,26,.13);position:relative}
.tp-btns .bt svg{width:16px;height:16px;flex-shrink:0}
.tp-msg .part{position:relative}
.tp-wa .tp-callout{position:absolute;left:-34px;top:8px;margin:0;z-index:2;box-shadow:0 0 0 3px #efeae2}
.tp-wa .tp-btns .bt .tp-callout{left:-34px;top:50%;transform:translateY(-50%)}
.tp-copy{height:34px;font-size:13px;padding:0 14px}

/* preview modal */
.tp-modal{display:none;position:fixed;inset:0;background:rgba(11,20,26,.55);z-index:9999;backdrop-filter:blur(3px);justify-content:center;align-items:center;padding:16px}
.tp-phone{width:min(380px,100%);background:#efeae2 url(../assets/img/wa-wallpaper.jpg) repeat;background-size:360px auto;border-radius:20px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);font-family:'Segoe UI',Helvetica,Arial,sans-serif}
.tp-phone-hd{background:#f0f2f5;padding:12px 16px;display:flex;align-items:center;gap:12px}
.tp-phone-av{width:38px;height:38px;border-radius:50%;background:var(--g);color:#fff;display:grid;place-items:center;font-weight:700;font-size:13px}
.tp-phone-hd b{display:block;font-size:15px;color:#111b21}
.tp-phone-hd small{font-size:12px;color:#667781}
.tp-phone-x{margin-left:auto;border:0;background:transparent;color:#54656f;cursor:pointer;width:34px;height:34px;border-radius:50%;display:grid;place-items:center}
.tp-phone-x:hover{background:rgba(11,20,26,.08)}
.tp-phone-bd{padding:20px 16px 26px;min-height:240px}

@media (max-width:1100px){.tp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.tp-steps{grid-template-columns:repeat(2,minmax(0,1fr))}.tp-3{grid-template-columns:repeat(2,minmax(0,1fr))}.tp-limits{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:900px){
  .tp-head{display:none}
  .tp-row{grid-template-columns:30px 1fr auto;grid-template-areas:"chk name acts" "body body body" "st st st";row-gap:10px}
  .tp-row>.c-chk{grid-area:chk}.tp-row>.c-name{grid-area:name}.tp-row>.c-body{grid-area:body}.tp-row>.c-st{grid-area:st}.tp-row>.c-act{grid-area:acts}
  .tp-2{grid-template-columns:1fr}
}
@media (max-width:640px){
  .tp-hero{padding:18px}.tp-hero h1{font-size:21px}.tp-hero-actions,.tp-hero-actions .tp-btn{width:100%}
  .tp-kpis,.tp-steps,.tp-3,.tp-limits{grid-template-columns:1fr}
  .tp-search{min-width:0;width:100%}.tp-tools{width:100%}
}
</style>

<div class="tp">

<?php if (isset($_GET['msg'])): ?>
    <div class="tp-notice"><?php echo $_GET['msg'] === 'bulk_deleted' ? 'Selected templates deleted successfully.' : 'Template ' . htmlspecialchars($_GET['msg']) . '.'; ?></div>
<?php endif; ?>
<?php if (isset($_GET['synced'])): ?>
    <div class="tp-notice"><?php echo intval($_GET['synced']); ?> new template(s) synced from WhatsApp. Existing ones were refreshed with their latest Meta status.</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="tp-notice err"><?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<!-- Hero -->
<div class="tp-hero">
    <div class="tp-hero-l">
        <div class="tp-hero-ico"><?php echo cn_svg('templates'); ?></div>
        <div>
            <h1>Message Templates</h1>
            <p>Templates are pre-approved WhatsApp messages. Build them here, get them approved by Meta, then send them in campaigns and to customers outside the 24-hour window.</p>
        </div>
    </div>
    <div class="tp-hero-actions">
        <a href="template_create.php" class="tp-btn tp-btn--primary">+ Create template</a>
        <a href="templates.php?sync=1" class="tp-btn" id="tpSync" onclick="this.classList.add('syncing')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Sync from Meta
        </a>
        <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener" class="tp-btn">Manage on Meta ↗</a>
    </div>
</div>

<!-- KPIs -->
<div class="tp-kpis">
    <div class="tp-kpi"><div class="tp-kpi-ico"><?php echo cn_svg('templates'); ?></div><div><div class="tp-kpi-v"><?php echo $counts['all']; ?></div><div class="tp-kpi-l">Total templates</div></div></div>
    <div class="tp-kpi is-blue"><div class="tp-kpi-ico"><?php echo cn_svg('shield'); ?></div><div><div class="tp-kpi-v"><?php echo $counts['approved']; ?></div><div class="tp-kpi-l">Approved &amp; ready</div></div></div>
    <div class="tp-kpi is-amber"><div class="tp-kpi-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="tp-kpi-v"><?php echo $counts['pending']; ?></div><div class="tp-kpi-l">Awaiting review</div></div></div>
    <div class="tp-kpi is-pink"><div class="tp-kpi-ico"><?php echo cn_svg('blacklist'); ?></div><div><div class="tp-kpi-v"><?php echo $counts['rejected']; ?></div><div class="tp-kpi-l">Need changes</div></div></div>
</div>

<!-- Tabs -->
<div class="tp-tabs" role="tablist">
    <button type="button" class="tp-tab<?php echo $startTab === 'list' ? ' on' : ''; ?>" data-pane="list">My templates</button>
    <button type="button" class="tp-tab<?php echo $startTab === 'guide' ? ' on' : ''; ?>" data-pane="guide">Step-by-step guide</button>
    <button type="button" class="tp-tab" data-pane="examples">Ready-made examples</button>
</div>

<!-- ═════ PANE: LIST ═════ -->
<div class="tp-pane<?php echo $startTab === 'list' ? ' on' : ''; ?>" id="pane-list">
<form id="bulkDeleteForm" method="POST">
<?php echo csrf_field(); ?>
<input type="hidden" name="bulk_delete" value="1">
<div class="tp-panel">
<?php if (count($templates) > 0): ?>
    <div class="tp-toolbar">
        <div class="tp-chips" id="tpChips">
            <button type="button" class="tp-chip on" data-f="all">All <em><?php echo $counts['all']; ?></em></button>
            <button type="button" class="tp-chip" data-f="approved">Approved <em><?php echo $counts['approved']; ?></em></button>
            <button type="button" class="tp-chip" data-f="pending">Pending <em><?php echo $counts['pending']; ?></em></button>
            <button type="button" class="tp-chip" data-f="rejected">Rejected <em><?php echo $counts['rejected']; ?></em></button>
            <button type="button" class="tp-chip" data-f="local">Local <em><?php echo $counts['local']; ?></em></button>
        </div>
        <div class="tp-tools">
            <button type="button" id="bulk-actions" class="tp-btn tp-btn--danger" style="display:none;height:40px;" onclick="submitBulkDelete()">Delete selected (<span id="selCount">0</span>)</button>
            <label class="tp-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" id="tpSearch" placeholder="Search templates" autocomplete="off">
            </label>
        </div>
    </div>

    <div class="tp-list">
        <div class="tp-row tp-head">
            <div class="c-chk"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" aria-label="Select all"></div>
            <div>Template</div><div>Message</div><div>Meta status</div><div></div>
        </div>
        <?php foreach ($templates as $t):
            $group = tp_group($t['wa_status'] ?? '');
            [$gLabel, $gHint] = $groupInfo[$group];
            $body = (string)($t['body'] ?? ($t['body_text'] ?? ''));
            $hdr  = (string)($t['header_text'] ?? '');
            $ftr  = (string)($t['footer'] ?? ($t['footer_text'] ?? ''));
            $cat  = strtolower((string)($t['category'] ?? 'marketing'));
        ?>
        <div class="tp-row tp-item" data-group="<?php echo $group; ?>" data-text="<?php echo htmlspecialchars(strtolower($t['name'] . ' ' . $body), ENT_QUOTES); ?>">
            <div class="c-chk"><input type="checkbox" name="selected_ids[]" value="<?php echo (int)$t['id']; ?>" class="row-checkbox" onchange="updateBulkActions()" aria-label="Select template"></div>
            <div class="c-name" style="min-width:0;">
                <div class="tp-name"><?php echo htmlspecialchars($t['name']); ?></div>
                <div class="tp-meta"><span class="tp-tag cat-<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars(ucfirst($cat)); ?></span><span class="tp-tag"><?php echo htmlspecialchars(strtoupper((string)($t['language'] ?? 'en'))); ?></span></div>
            </div>
            <div class="c-body tp-body"><?php echo htmlspecialchars($body); ?></div>
            <div class="c-st"><span class="tp-pill s-<?php echo $group; ?>"><?php echo $gLabel; ?></span><div class="tp-hint"><?php echo $gHint; ?></div></div>
            <div class="c-act tp-acts">
                <button type="button" class="tp-ib" title="Preview" aria-label="Preview"
                    onclick='previewTemplate(<?php echo json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($ftr, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode(strtolower((string)($t['header_type'] ?? 'none')), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode(json_decode((string)($t['buttons'] ?? '[]'), true) ?: [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></button>
                <a href="template_create.php" class="tp-ib" title="Meta doesn't allow editing approved templates. Create a new version instead." aria-label="Create new version"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg></a>
                <button type="submit" form="del_<?php echo (int)$t['id']; ?>" class="tp-ib danger" title="Delete" aria-label="Delete template" onclick="return confirm('Are you sure you want to delete this template?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="tp-none" id="tpNone">No templates match your search.</div>
    </div>
<?php else: ?>
    <div class="tp-empty">
        <div class="tp-empty-ico"><?php echo cn_svg('templates'); ?></div>
        <h3>No templates yet</h3>
        <p>Create your first template, or sync the ones already approved on your Meta account. Not sure where to start? Open the guide tab.</p>
        <a href="template_create.php" class="tp-btn tp-btn--primary">+ Create template</a>
    </div>
<?php endif; ?>
</div>
</form>
</div>

<!-- ═════ PANE: GUIDE ═════ -->
<div class="tp-pane<?php echo $startTab === 'guide' ? ' on' : ''; ?>" id="pane-guide">
<div class="gd" id="gd">

    <!-- progress -->
    <div class="gd-top">
        <div>
            <div class="gd-kicker" id="gdKicker">Step 1 of 7</div>
            <h2 class="gd-title" id="gdTitle">What is a template?</h2>
        </div>
        <div class="gd-prog"><div class="gd-prog-bar"><i id="gdBar"></i></div><span id="gdPct">0%</span></div>
    </div>

    <div class="gd-layout">

        <!-- left rail -->
        <nav class="gd-rail" id="gdRail" aria-label="Guide steps">
            <button type="button" data-go="0"><b></b><span>What is it?</span></button>
            <button type="button" data-go="1"><b></b><span>Pick a category</span></button>
            <button type="button" data-go="2"><b></b><span>Write the message</span></button>
            <button type="button" data-go="3"><b></b><span>Fill in the form</span></button>
            <button type="button" data-go="4"><b></b><span>Submit and wait</span></button>
            <button type="button" data-go="5"><b></b><span>Send it</span></button>
            <button type="button" data-go="6"><b></b><span>Fix problems</span></button>
        </nav>

        <!-- content -->
        <div class="gd-main">

            <!-- STEP 1 -->
            <section class="gd-step" data-title="What is a template?" data-next="Pick a category">
                <p class="gd-lead">A template is a <b>message form that WhatsApp must approve once</b>. After it is approved, you can send it to as many customers as you like.</p>

                <div class="gd-two">
                    <div class="gd-lane ok">
                        <div class="gd-lane-ico"><?php echo cn_svg('chat'); ?></div>
                        <h4>Customer wrote to you in the last 24 hours</h4>
                        <p>You can reply with <b>any text</b> you want. No template needed.</p>
                        <span class="gd-badge ok">Free reply</span>
                    </div>
                    <div class="gd-lane warn">
                        <div class="gd-lane-ico"><?php echo cn_svg('scheduled'); ?></div>
                        <h4>You message first, or it has been over 24 hours</h4>
                        <p>WhatsApp only allows an <b>approved template</b>. Campaigns, offers and reminders all work this way.</p>
                        <span class="gd-badge warn">Template needed</span>
                    </div>
                </div>

                <div class="gd-tip"><?php echo cn_svg('discover'); ?><div><b>In one line:</b> write the template, Meta checks it, and when it says <b>Approved</b> you can use it. Until then it cannot be sent.</div></div>
            </section>

            <!-- STEP 2 -->
            <section class="gd-step gd-meta" data-title="Pick a category" data-next="Write the message">
                <div class="mb-grid">
                    <div class="mb-col">
                        <div class="mb-card">
                            <h2>Set up your template</h2>
                            <p class="mb-desc">Choose the category that best describes your message template. Then, select the type of message that you want to send. <button type="button" class="mb-link" id="mbLearnBtn">Learn more about categories.</button></p>
                            <div class="mb-seg" id="mbSeg" role="tablist" aria-label="Template category"></div>
                            <div class="mb-types" id="mbTypes" role="radiogroup" aria-label="Template type"></div>
                            <div class="mb-learn" id="mbLearn" hidden>
                                <p><b>Marketing:</b> promotions, offers, product news and anything meant to sell. If it is unclear, Meta treats it as Marketing.</p>
                                <p><b>Utility:</b> not promotional, and about something the customer asked for or did, such as an order, booking or payment.</p>
                                <p><b>Authentication:</b> one-time passcodes only.</p>
                                <p>A message that mixes an order update with an offer is classed as Marketing.</p>
                            </div>
                        </div>

                        <div class="mb-card mb-lib">
                            <div class="mb-librow">
                                <div class="mb-libart" aria-hidden="true">
                                    <svg viewBox="0 0 194 130" preserveAspectRatio="xMidYMid slice">
                                        <g class="fl"><g transform="rotate(-12 60 52)"><rect x="18" y="20" width="84" height="62" rx="6" fill="#3E8BD9"/><rect x="38" y="42" width="30" height="18" rx="2" fill="#fff"/><path d="M68 47h10l6 7v6H68z" fill="#fff"/><circle cx="48" cy="62" r="4" fill="#1C2B33"/><circle cx="76" cy="62" r="4" fill="#1C2B33"/></g></g>
                                        <g class="fl2"><g transform="rotate(8 135 50)"><rect x="100" y="14" width="74" height="70" rx="6" fill="#fff"/><rect x="100" y="14" width="74" height="16" rx="6" fill="#2E9E5B"/><path d="M112 42h12v10h-12zM131 42h12v10h-12zM150 42h12v10h-12zM112 60h12v10h-12zM131 60h12v10h-12z" fill="#BFE3C4"/><rect x="150" y="60" width="12" height="10" fill="#2E9E5B"/></g></g>
                                        <g class="fl"><g transform="rotate(-6 120 104)"><rect x="92" y="84" width="56" height="44" rx="6" fill="#fff"/><path d="M110 96h7l3 8-4 2a16 16 0 0 0 8 8l2-4 8 3v6a2 2 0 0 1-2 2 24 24 0 0 1-24-23 2 2 0 0 1 2-2z" fill="#2E9E5B"/></g></g>
                                    </svg>
                                </div>
                                <div class="mb-libtxt">
                                    <h2>Start with pre-approved templates</h2>
                                    <p class="mb-desc">Save time and effort by using ready-made samples. You can quickly build professional-looking templates without starting from scratch.</p>
                                    <button type="button" class="mb-btn" id="mbLib">Go to template library</button>
                                </div>
                            </div>
                        </div>

                        <div class="mb-card">
                            <h2>Rules for this type</h2>
                            <ul class="mb-rules" id="mbRules"></ul>
                        </div>
                    </div>

                    <aside class="mb-card mb-side" aria-label="Template preview">
                        <h3>Template preview</h3>
                        <div class="mb-wall"><div class="mb-chat" id="mbChat"></div></div>
                        <div class="mb-kv"><h4>This template is good for</h4><p id="mbGood"></p></div>
                        <div class="mb-kv"><h4>Template areas that you can customise</h4><p id="mbCustom"></p></div>
                        <p class="mb-fine">Preview uses invented sample businesses and values. WhatsApp draws the final message.</p>
                    </aside>
                </div>
            </section>

            <!-- STEP 3 -->
            <section class="gd-step" data-title="Write the message" data-next="Fill in the form">
                <p class="gd-lead">A template has up to four parts. Only the <b>Body</b> is required.</p>

                <div class="tp-anat">
                    <div class="tp-wa callouts"><?php echo tp_msg([
                        'media'   => 'image',
                        'body'    => "*Spring is here!*\nGive your wardrobe a makeover with the newly launched Spring Collection in vibrant colors. Explore and place your order today for guaranteed shipping in 1 day!",
                        'footer'  => 'Reply STOP to opt out',
                        'buttons' => [['View Catalog', 'url'], ['Deal of the Day', 'quick_reply']],
                    ], true); ?></div>
                    <div class="tp-anat-list">
                        <div><span class="tp-callout">1</span><b>Header</b> <em>optional</em><p>A short bold title (up to 60 characters), or a picture, video or document.</p></div>
                        <div><span class="tp-callout">2</span><b>Body</b> <em>required</em><p>Your main message, up to 1,024 characters.</p></div>
                        <div><span class="tp-callout">3</span><b>Footer</b> <em>optional</em><p>Small grey line at the bottom (up to 60 characters).</p></div>
                        <div><span class="tp-callout">4</span><b>Buttons</b> <em>optional</em><p>Up to 3: Quick reply, open a website, or call a number.</p></div>
                    </div>
                </div>

                <h3 class="gd-h3">Variables: the blanks that change for each customer</h3>
                <div class="gd-var">
                    <div class="tp-wa"><?php echo tp_msg(['body' => "Hi *{{1}}*, your order *{{2}}* is ready for pickup."]); ?></div>
                    <div class="gd-arrow" aria-hidden="true">&rarr;</div>
                    <div class="tp-wa"><?php echo tp_msg(['body' => "Hi *Priya*, your order *#1042* is ready for pickup."]); ?></div>
                </div>
                <p class="gd-note">What you write (left) and what the customer receives (right). WAAMINI fills the blanks for every customer.</p>

                <div class="tp-2" style="margin-top:14px">
                    <div class="tp-card do">
                        <h3><span class="tp-ico"><?php echo cn_svg('shield'); ?></span> Do</h3>
                        <ul>
                            <li>Number variables 1, 2, 3 with no gaps</li>
                            <li>Put real words before and after each variable</li>
                            <li>Say clearly why you are messaging them</li>
                            <li>Keep one purpose per template</li>
                        </ul>
                    </div>
                    <div class="tp-card dont">
                        <h3><span class="tp-ico red"><?php echo cn_svg('blacklist'); ?></span> Avoid</h3>
                        <ul>
                            <li>Starting or ending the body with a variable</li>
                            <li>Two variables side by side: {{1}}{{2}}</li>
                            <li>Shortened links (bit.ly) and ALL CAPS</li>
                            <li>Asking for passwords or card details</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- STEP 4 -->
            <section class="gd-step" data-title="Fill in the form" data-next="Submit and wait">
                <p class="gd-lead">Open <a href="template_create.php"><b>Create template</b></a> and fill it in from top to bottom. The phone on the right shows what your customer will see.</p>

                <div class="gd-form">
                    <div><b>1</b><div><h4>Name, language, category</h4><p>Name: lowercase letters, numbers and underscores, such as <code>weekend_sale_v1</code>. It cannot be changed later, so end it with <code>_v1</code>. Language: the language you wrote in. Category: from step 2.</p></div></div>
                    <div><b>2</b><div><h4>Header <em>(optional)</em></h4><p>Choose None, Text, Image, Video, Document or Location. Files are uploaded to Meta for you.</p></div></div>
                    <div><b>3</b><div><h4>Body message</h4><p>Type your text. Use the toolbar for bold or italic. Press <b>+ {{x}}</b> to add the next variable.</p></div></div>
                    <div><b>4</b><div><h4>Footer <em>(optional)</em></h4><p>One short grey line. A good place for the opt-out text.</p></div></div>
                    <div><b>5</b><div><h4>Buttons <em>(optional)</em></h4><p>Add up to 3: <b>Quick reply</b> (label only), <b>URL</b> (label and link) or <b>Phone</b> (label and number with country code). Want swipeable cards? Choose <b>Carousel</b> at the top (up to 5 cards).</p></div></div>
                </div>

                <h3 class="gd-h3">Last check before you submit <span class="gd-count" id="gdCount">0 of 6 done</span></h3>
                <div class="tp-check" id="gdCheck">
                    <label><input type="checkbox"> The name is lowercase with underscores and ends in _v1</label>
                    <label><input type="checkbox"> The category matches what the message does</label>
                    <label><input type="checkbox"> The language matches what I wrote</label>
                    <label><input type="checkbox"> Variables are 1, 2, 3 and none are at the very start or end</label>
                    <label><input type="checkbox"> Marketing messages have an opt-out line</label>
                    <label><input type="checkbox"> No shortened links and no requests for sensitive data</label>
                </div>
            </section>

            <!-- STEP 5 -->
            <section class="gd-step" data-title="Submit and wait" data-next="Send it">
                <p class="gd-lead">Press the green submit button. WAAMINI sends your template to Meta. Here is what happens next.</p>

                <div class="gd-line">
                    <div class="done"><span>1</span><b>Submitted</b><small>You pressed submit</small></div>
                    <div class="mid"><span>2</span><b>Pending</b><small>Meta is reviewing. Minutes to a few hours, sometimes 1 to 2 days</small></div>
                    <div class="end"><span>3</span><b>Approved or Rejected</b><small>Press <b>Sync from Meta</b> to see the result</small></div>
                </div>

                <div class="gd-two">
                    <div class="gd-lane ok">
                        <h4><span class="tp-pill s-approved">Approved</span></h4>
                        <p><b>It works.</b> It now appears when you create a campaign. Go to step 6.</p>
                    </div>
                    <div class="gd-lane bad">
                        <h4><span class="tp-pill s-rejected">Rejected</span></h4>
                        <p><b>Do not try to edit it.</b> Open <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener">Meta's template manager</a>, read the reason, then create a new version such as <code>weekend_sale_v2</code> with the fix.</p>
                    </div>
                </div>

                <h3 class="gd-h3">What each status means</h3>
                <div class="gd-stat">
                    <div><span class="tp-pill s-approved">Approved</span><p>Works. Ready to send.</p></div>
                    <div><span class="tp-pill s-pending">Pending</span><p>Not yet. Meta is still reviewing.</p></div>
                    <div><span class="tp-pill s-rejected">Rejected</span><p>Does not work. Needs a new version.</p></div>
                    <div><span class="tp-pill s-local">Local</span><p>Saved only in WAAMINI. Never approved by Meta, so it will not send.</p></div>
                </div>
            </section>

            <!-- STEP 6 -->
            <section class="gd-step" data-title="Send it" data-next="">
                <p class="gd-lead">Your template is approved. Now use it. <b>Only Approved templates appear in these lists.</b></p>

                <div class="gd-use">
                    <a href="campaign_create.php" class="gd-use-card"><span class="ic"><?php echo cn_svg('campaigns'); ?></span><div><h4>Create a campaign</h4><p>Send your template to a group of contacts, now or on a schedule.</p></div><i>&rarr;</i></a>
                    <a href="whatsapp.php" class="gd-use-card"><span class="ic amber"><?php echo cn_svg('chat'); ?></span><div><h4>WhatsApp Chat</h4><p>Message a customer after the 24-hour window has closed.</p></div><i>&rarr;</i></a>
                </div>

                <div class="gd-tip"><?php echo cn_svg('discover'); ?><div><b>Cannot see your template in the list?</b> Open <b>My templates</b> and check its status. Only <b>Approved</b> ones show up. If you made it on Meta directly, press <b>Sync from Meta</b> first.</div></div>
            </section>

            <!-- STEP 7 -->
            <section class="gd-step" data-title="If something goes wrong" data-next="">
                <p class="gd-lead">Most problems have a quick fix. Tap a question to see the answer.</p>

                <div class="tp-faq">
                    <details><summary>My template has been Pending for a long time</summary><p>Most reviews finish within hours, but Meta can take 1 to 2 days. Press <b>Sync from Meta</b> to refresh the status. If it is still pending after 2 days, check Meta's template manager.</p></details>
                    <details><summary>My template was Rejected. What now?</summary><p>You cannot edit it. Read the reason on <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener">Meta's template manager</a>, then create a new version (<code>_v2</code>). Common causes: wrong category, a badly placed variable, an offer inside a Utility template, or a shortened link.</p></details>
                    <details><summary>I cannot find my template when creating a campaign</summary><p>Campaigns list <b>Approved</b> templates only. Check the status on the My templates tab.</p></details>
                    <details><summary>Can I edit an approved template?</summary><p>No, Meta does not allow it. Create a new one with a new name, keep using the old one until the new one is approved, then delete the old one.</p></details>
                    <details><summary>Sync says “WABA ID missing” or “access token invalid”</summary><p>Open <a href="settings_whatsapp.php">WhatsApp Setup</a>, check that the <b>WABA ID</b> and <b>Access Token</b> are saved correctly, and sync again. An expired token must be replaced with a new one.</p></details>
                    <details><summary>Meta changed my category</summary><p>If a Utility template reads like a promotion, Meta may move it to Marketing, which can change the price per message. Keep Utility messages purely informational.</p></details>
                    <details><summary>I got a “Meta API Error” when submitting</summary><p>The message under the form comes straight from Meta. Typical causes are a duplicate name and language, a variable problem, or text that is too long. Fix what it says and submit again.</p></details>
                </div>

                <h3 class="gd-h3">Words you will see</h3>
                <div class="tp-gloss">
                    <div><b>Template</b><span>A pre-approved WhatsApp message you can send to start a conversation.</span></div>
                    <div><b>Meta</b><span>The company that owns WhatsApp. It approves or rejects templates.</span></div>
                    <div><b>WABA</b><span>WhatsApp Business Account, the account your number belongs to.</span></div>
                    <div><b>Variable</b><span>A blank like {{1}} filled with a name, order number and so on.</span></div>
                    <div><b>Opt-in</b><span>A customer's permission to receive your messages.</span></div>
                    <div><b>24-hour window</b><span>The time after a customer writes to you when you can reply freely.</span></div>
                    <div><b>Quick reply</b><span>A button the customer taps to answer without typing.</span></div>
                    <div><b>Sync</b><span>Pulling the latest templates and statuses from Meta into WAAMINI.</span></div>
                </div>
            </section>

            <!-- footer nav -->
            <div class="gd-nav">
                <button type="button" class="tp-btn" id="gdBack">&larr; Back</button>
                <div class="gd-nav-r">
                    <button type="button" class="tp-btn" onclick="document.querySelector('.tp-tab[data-pane=examples]').click();window.scrollTo({top:0,behavior:'smooth'})" id="gdEx">See examples</button>
                    <button type="button" class="tp-btn tp-btn--primary" id="gdNext">Next &rarr;</button>
                    <a href="template_create.php" class="tp-btn tp-btn--primary" id="gdCreate" style="display:none">+ Create my template</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('gd'); if (!root) return;
    var steps = root.querySelectorAll('.gd-step'), rail = root.querySelectorAll('#gdRail button');
    var kick = document.getElementById('gdKicker'), ttl = document.getElementById('gdTitle'), bar = document.getElementById('gdBar'), pct = document.getElementById('gdPct');
    var back = document.getElementById('gdBack'), next = document.getElementById('gdNext'), create = document.getElementById('gdCreate'), ex = document.getElementById('gdEx');
    var cur = 0, KEY = 'tp_guide_step';
    var STEPIC = {
        cur: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 1.5a6.5 6.5 0 0 0 0 13z" fill="currentColor"/></svg>',
        later: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
        done: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="7.3" fill="currentColor"/><path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    };
    try { var sv = parseInt(localStorage.getItem(KEY), 10); if (sv >= 0 && sv < steps.length) cur = sv; } catch (e) {}

    function show(i) {
        cur = Math.max(0, Math.min(steps.length - 1, i));
        steps.forEach(function (s, k) { s.classList.toggle('on', k === cur); });
        rail.forEach(function (b, k) { b.classList.toggle('on', k === cur); b.classList.toggle('done', k < cur); b.firstElementChild.innerHTML = k < cur ? STEPIC.done : (k === cur ? STEPIC.cur : STEPIC.later); });
        kick.textContent = 'Step ' + (cur + 1) + ' of ' + steps.length;
        ttl.textContent = steps[cur].dataset.title;
        var p = Math.round(cur / (steps.length - 1) * 100); bar.style.width = (cur === 0 ? 4 : p) + '%'; pct.textContent = p + '%';
        back.style.visibility = cur === 0 ? 'hidden' : 'visible';
        var last = cur === steps.length - 1;
        next.style.display = last ? 'none' : ''; create.style.display = last ? '' : 'none';
        var nx = steps[cur].dataset.next;
        next.innerHTML = (nx ? 'Next: ' + nx : 'Next') + ' &rarr;';
        try { localStorage.setItem(KEY, String(cur)); } catch (e) {}
    }
    next.addEventListener('click', function () { show(cur + 1); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    back.addEventListener('click', function () { show(cur - 1); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    rail.forEach(function (b) { b.addEventListener('click', function () { show(parseInt(b.dataset.go, 10)); }); });

    // category quiz
    var res = document.getElementById('gdResult');
    document.querySelectorAll('#gdChoices button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#gdChoices button').forEach(function (x) { x.classList.toggle('on', x === b); });
            res.removeAttribute('data-empty');
            res.querySelectorAll('.gd-result-body').forEach(function (r) { r.classList.toggle('on', r.dataset.for === b.dataset.pick); });
        });
    });

    // checklist counter
    var boxes = document.querySelectorAll('#gdCheck input'), cnt = document.getElementById('gdCount');
    function recount() { var n = document.querySelectorAll('#gdCheck input:checked').length; cnt.textContent = n + ' of ' + boxes.length + ' done'; cnt.classList.toggle('ok', n === boxes.length); }
    boxes.forEach(function (c) { c.addEventListener('change', recount); });
    show(cur);
})();
</script>
<script>
(function () {
    var seg = document.getElementById('mbSeg'); if (!seg) return;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var P = {
        megaphone: '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/>',
        bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        key: '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        link: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        reply: '<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        bag: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        form: '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        card: '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>'
    };
    var ic = function (n) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + P[n] + '</svg>'; };

    var CATS = [['marketing', 'Marketing', 'megaphone'], ['utility', 'Utility', 'bell'], ['authentication', 'Authentication', 'key']];
    var TYPES = [
        { cat: 'marketing', kind: 'default', label: 'Default', desc: 'Send messages with media and customised buttons to engage your customers.',
          good: 'Offers, festival sales, new arrivals, back-in-stock alerts, re-engagement', custom: 'Media, header, body, footer, buttons',
          rules: ['The body is the only required part, up to 1,024 characters.', 'One header: text up to 60 characters, or an image, video or document.', 'Add a footer such as "Reply STOP to opt out". Customers must have opted in.', 'Up to 3 buttons in WAAMINI: quick reply, website link or phone number.'],
          m: { media: true, body: 'Vanakkam {{1}}, our Pongal collection is here.\n\nTake *20% off* Kanchipuram and soft silk sarees until {{2}}.', s: ['Priya', '16 January'], foot: 'Reply STOP to opt out', btns: [['link', 'Shop the collection'], ['copy', 'Copy offer code'], ['reply', 'Stop promotions']] } },
        { cat: 'marketing', kind: 'catalog', label: 'Catalogue', desc: 'Send messages that drive sales by connecting your product catalogue.',
          good: 'Product discovery, sales, offers, personalised shopping', custom: 'Connect a catalogue, body',
          rules: ['Needs a product catalogue connected to your WhatsApp Business Account.', 'Body up to 1,024 characters and an optional footer.', 'The button is fixed as View catalog.', 'Customers browse and add to cart without leaving the chat.'],
          m: { thumb: ['Antique temple necklace', 'Rs 48,500'], body: 'Discover our new bridal collection in our catalog, {{1}}. Browse temple jewellery, necklaces and bangles with ease on WhatsApp.', s: ['Lakshmi'], btns: [['bag', 'View catalog']] } },
        { cat: 'marketing', kind: 'flow', label: 'Flows', desc: 'Send a form to capture customer interests, appointment requests or run surveys.',
          good: 'Lead capture, site visit and appointment requests, surveys, sign-ups', custom: 'Header, body, footer, button text, the form',
          rules: ['Needs a published WhatsApp Flow. The button opens it as a form in the chat.', 'You write the button text, up to 25 characters.', 'Answers come back to your webhook when the customer submits.'],
          m: { head: 'Model villa open this weekend', body: 'Hi {{1}}, the model villa at Vaigai Gardens is open this weekend. Pick a slot and our team will keep it for you.', s: ['Arun'], foot: 'Takes under a minute', btns: [['form', 'Book a site visit']] } },
        { cat: 'marketing', kind: 'order', label: 'Order details', desc: 'Send messages through which customers can pay you.',
          good: 'Pre-orders, paid festival offers, carts left unpaid', custom: 'Header, body, footer. Items and amounts are sent with each message.',
          rules: ['The button is fixed as Review and Pay.', 'Items, prices, tax and shipping are sent with each message, not stored in the template.', 'In Marketing this layout is for selling. Use the Utility version for an order already placed.'],
          m: { head: 'Your Diwali box is reserved', body: 'Hi {{1}}, we have set aside the box you asked about. Pay now to confirm and we will deliver it on {{2}}.', s: ['Meena', '30 October'], foot: 'Annam Sweets, Karaikudi', order: { ref: 'AS-2310', rows: [['Festive Sweet Box x2', '1,300'], ['Mixture 500 g', '180']], total: '1,540' }, btns: [['card', 'Review and Pay']] } },
        { cat: 'marketing', kind: 'call', label: 'Calling permissions request', desc: 'Ask customers if you can call them on WhatsApp.',
          good: 'Sales consultations, product walkthroughs, high-value enquiries', custom: 'Header, body, footer. The permission buttons are fixed.',
          rules: ['One request to a customer in 24 hours, and two in 7 days.', 'Allow calls lasts 7 days. Always allow stays until the customer changes it.', 'Each number can make 100 connected calls every 24 hours.'],
          m: { body: 'Hi {{1}}, our advisor can walk you through the new 11-month gold savings plan on a short WhatsApp call. May we call you?', s: ['Lakshmi'], btns: [['phone', 'Allow calls'], ['reply', 'Not now']] } },

        { cat: 'utility', kind: 'default', label: 'Default', desc: 'Send messages about an existing order or account.',
          good: 'Order confirmations, account updates, receipts, appointment reminders, billing', custom: 'Media, header, body, footer, button',
          rules: ['It must be about something the customer did or asked for: an order, booking, payment or account.', 'No offers or upsells. One promotional sentence moves the whole template to Marketing.', 'Buttons: quick replies, website links and a phone number.'],
          m: { head: 'Appointment reminder', body: 'Hello {{1}}, this is a reminder of your appointment with {{2}} on {{3}} at {{4}}. Please arrive 10 minutes early.', s: ['Ramesh', 'Dr. Kavitha', '12 October', '10:30 AM'], foot: 'Thendral Clinic, Karaikudi', btns: [['reply', 'Confirm'], ['reply', 'Reschedule'], ['phone', 'Call the clinic']] } },
        { cat: 'utility', kind: 'flow', label: 'Flows', desc: 'Send a form to collect feedback, send reminders or manage orders.',
          good: 'Feedback after a purchase, reschedule requests, order changes', custom: 'Header, body, footer, button text, the form',
          rules: ['Needs a published WhatsApp Flow.', 'The form must serve the order or account, not sell. A survey that ends in an offer is Marketing.', 'Button text up to 25 characters.'],
          m: { body: 'Hi {{1}}, your order {{2}} was delivered on {{3}}. Could you tell us how it went? It takes three taps.', s: ['Priya', 'KS-2291', '5 October'], foot: 'Kaveri Silks', btns: [['form', 'Share feedback']] } },
        { cat: 'utility', kind: 'status', label: 'Order status', desc: 'Send messages to tell customers about the progress of their orders.',
          good: 'Shipping updates, payment confirmation, completed or cancelled orders', custom: 'Body, footer. The order reference and status are sent with each message.',
          rules: ['Status is one of: pending, processing, partially-shipped, shipped, completed, canceled.', 'The reference ID must match an order sent earlier with an order details message.', 'An order can be cancelled only if no payment succeeded.'],
          m: { body: 'Hi {{1}}, your order {{2}} has an update. Tap the order to see the details.', s: ['Priya', 'KS-2291'], foot: 'Kaveri Silks', status: ['Shipped', 2] } },
        { cat: 'utility', kind: 'order', label: 'Order details', desc: 'Send messages through which customers can pay you.',
          good: 'Payment requests for confirmed orders, invoices, fee collection', custom: 'Header, body, footer. Items and amounts are sent with each message.',
          rules: ['The button is fixed as Review and Pay.', 'Items, prices, tax and shipping are sent with each message.', 'Follow it with an order status message when the payment lands.'],
          m: { head: 'Order AS-1042 confirmed', body: 'Hi {{1}}, your order is confirmed and will be packed once payment is received. Review the items and pay securely on WhatsApp.', s: ['Meena'], foot: 'Annam Sweets, Karaikudi', order: { ref: 'AS-1042', rows: [['Mysore Pak 500 g', '320'], ['Kaju Katli 250 g x2', '560']], total: '920' }, btns: [['card', 'Review and Pay']] } },
        { cat: 'utility', kind: 'call', label: 'Calling permissions request', desc: 'Ask customers if you can call them on WhatsApp.',
          good: 'Delivery coordination, support call-backs, account verification calls', custom: 'Header, body, footer. The permission buttons are fixed.',
          rules: ['One request to a customer in 24 hours, and two in 7 days.', 'Four unanswered calls in a row remove the permission.'],
          m: { body: 'Hi {{1}}, our delivery partner would like to call you on WhatsApp about your order {{2}}. May we call you?', s: ['Priya', 'KS-2291'], btns: [['phone', 'Allow calls'], ['reply', 'Not now']] } },

        { cat: 'authentication', kind: 'otp', label: 'One-time passcode', desc: 'Send codes to verify a transaction or login.',
          good: 'Login verification, signup, transaction confirmation', custom: 'Code delivery method and expiry time only',
          rules: ['The body is fixed: "<code> is your verification code."', 'You can add the line "For your security, do not share this code." and an expiry footer (1 to 90 minutes).', 'No links, media or emojis. The code can be 15 characters at most.'],
          m: { body: '*{{1}}* is your verification code. For your security, do not share this code.', s: ['482 915'], foot: 'This code expires in 10 minutes.', btns: [['copy', 'Copy code']] } }
    ];

    var cat = 'marketing', idx = 0;
    var els = { types: $('mbTypes'), rules: $('mbRules'), chat: $('mbChat'), good: $('mbGood'), custom: $('mbCustom') };

    function fill(t, s) {
        var h = esc(t).replace(/\*(.+?)\*/g, '<b>$1</b>');
        return h.replace(/\{\{(\d+)\}\}/g, function (m, n) { return '<span class="mb-var">' + esc((s || [])[n - 1] || m) + '</span>'; });
    }
    function bubble(t) {
        var m = t.m, h = '<div class="mb-msg">';
        if (m.media) h += '<div class="mb-media">' + ic('image') + '</div>';
        if (m.thumb) h += '<div class="mb-thumb"><i></i><div><b>' + esc(m.thumb[0]) + '</b><span>' + esc(m.thumb[1]) + '</span></div></div>';
        h += '<div class="pad">';
        if (m.head) h += '<div class="mb-bhead">' + fill(m.head, m.s) + '</div>';
        h += '<div class="mb-bbody">' + fill(m.body, m.s) + '</div>';
        if (m.order) {
            h += '<div class="mb-ocard"><div class="oh"><b>Order ' + esc(m.order.ref) + '</b><span class="mb-pill">Pending</span></div>';
            m.order.rows.forEach(function (r) { h += '<div class="ln"><span>' + esc(r[0]) + '</span><span>Rs ' + esc(r[1]) + '</span></div>'; });
            h += '<div class="ln tot"><span>Total</span><span>Rs ' + esc(m.order.total) + '</span></div></div>';
        }
        if (m.status) {
            var steps = ['Placed', 'Packed', 'Shipped', 'Delivered'], at = m.status[1];
            h += '<div class="mb-ocard"><div class="oh"><b>Order ' + esc((m.s || [])[1] || 'KS-2291') + '</b><span class="mb-pill">' + esc(m.status[0]) + '</span></div><div class="mb-prog"><i style="width:' + Math.round((at + 1) / steps.length * 100) + '%"></i></div><div class="mb-psteps">' + steps.map(function (x, i) { return '<span' + (i === at ? ' class="at"' : '') + '>' + x + '</span>'; }).join('') + '</div></div>';
        }
        if (m.foot) h += '<div class="mb-bfoot">' + esc(m.foot) + '</div>';
        h += '<div class="mb-meta">11:59</div></div>';
        (m.btns || []).forEach(function (b) { h += '<div class="mb-bbtn">' + ic(b[0]) + esc(b[1]) + '</div>'; });
        return h + '</div>';
    }
    function list() { return TYPES.filter(function (t) { return t.cat === cat; }); }

    function renderSeg() {
        var i = CATS.findIndex(function (c) { return c[0] === cat; });
        seg.style.setProperty('--i', i);
        seg.innerHTML = '<div class="thumb"></div>' + CATS.map(function (c) {
            return '<button type="button" role="tab" data-cat="' + c[0] + '" aria-selected="' + (c[0] === cat) + '"><svg viewBox="0 0 24 24">' + P[c[2]] + '</svg>' + c[1] + '</button>';
        }).join('');
    }
    function renderTypes() {
        els.types.innerHTML = '<div class="mb-hl" id="mbHl"></div>' + list().map(function (t, i) {
            return '<button type="button" role="radio" aria-checked="' + (i === idx) + '" class="mb-type' + (i === idx ? ' on' : '') + '" data-i="' + i + '"><span class="dot"></span><span><b>' + esc(t.label) + '</b><span class="d">' + esc(t.desc) + '</span></span></button>';
        }).join('');
        moveHl();
    }
    function moveHl() {
        var on = els.types.querySelector('.mb-type.on'), hl = $('mbHl');
        if (on && hl) { hl.style.height = on.offsetHeight + 'px'; hl.style.transform = 'translateY(' + on.offsetTop + 'px)'; }
    }
    function renderPreview() {
        var t = list()[idx];
        els.chat.innerHTML = bubble(t);
        els.good.textContent = t.good; els.custom.textContent = t.custom;
        els.rules.innerHTML = t.rules.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('');
    }
    function all() { renderSeg(); renderTypes(); renderPreview(); }

    seg.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-cat]'); if (!b) return;
        cat = b.dataset.cat; idx = 0; all();
    });
    els.types.addEventListener('click', function (e) {
        var b = e.target.closest('.mb-type'); if (!b) return;
        idx = parseInt(b.dataset.i, 10);
        els.types.querySelectorAll('.mb-type').forEach(function (x, i) { x.classList.toggle('on', i === idx); x.setAttribute('aria-checked', i === idx); });
        moveHl(); renderPreview();
    });
    $('mbLearnBtn').addEventListener('click', function () { var l = $('mbLearn'); l.hidden = !l.hidden; });
    $('mbLib').addEventListener('click', function () {
        var tab = document.querySelector('.tp-tab[data-pane=examples]'); if (tab) tab.click();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    window.addEventListener('resize', moveHl);
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#gdRail,#gdNext,#gdBack,.tp-tab')) setTimeout(moveHl, 30); });
    all();
})();
</script>
</div>


<!-- ═════ PANE: EXAMPLES ═════ -->
<div class="tp-pane" id="pane-examples">
    <h2 class="tp-sec-title">Ready-made examples</h2>
    <p class="tp-sec-sub">These follow Meta's guidelines and look just like they will on your customer's phone. Copy one, then adjust the wording in Create template.</p>
    <div class="tp-2">
        <?php
        $examples = [
            ['Order confirmation', 'Utility', ['media' => '', 'header' => '', 'body' => "Hi {{1}}, your order #{{2}} is confirmed and will be delivered by {{3}}. Thank you for shopping with us!", 'footer' => '', 'buttons' => [['Track order', 'url']]]],
            ['Weekend offer', 'Marketing', ['media' => 'image', 'header' => '', 'body' => "*Weekend sale is here!*\nHello {{1}}, enjoy {{2}}% off everything this weekend. Use code {{3}} at checkout.", 'footer' => 'Reply STOP to opt out', 'buttons' => [['Shop now', 'url'], ['Call us', 'phone']]]],
            ['Appointment reminder', 'Utility', ['media' => '', 'header' => 'Appointment reminder', 'body' => "Hi {{1}}, this is a reminder of your appointment on {{2}} at {{3}}. Please confirm your visit.", 'footer' => '', 'buttons' => [['Confirm', 'quick_reply'], ['Reschedule', 'quick_reply']]]],
            ['Verification code', 'Authentication', ['media' => '', 'header' => '', 'body' => "*{{1}}* is your verification code. For your security, do not share this code.", 'footer' => '', 'buttons' => []]],
        ];
        foreach ($examples as [$exName, $exCat, $exMsg]):
            $copy = trim(($exMsg['header'] ? $exMsg['header'] . "\n\n" : '') . $exMsg['body'] . ($exMsg['footer'] ? "\n\n" . $exMsg['footer'] : ''));
        ?>
        <div class="tp-card tp-ex">
            <div class="tp-ex-top">
                <div><h4><?php echo htmlspecialchars($exName); ?></h4><span class="tp-tag cat-<?php echo strtolower($exCat); ?>" style="margin-top:4px;display:inline-block"><?php echo $exCat; ?></span></div>
                <button type="button" class="tp-btn tp-copy" data-copy="<?php echo htmlspecialchars($copy, ENT_QUOTES); ?>">Copy text</button>
            </div>
            <div class="tp-wa"><?php echo tp_msg($exMsg); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- .tp -->

<?php foreach ($templates as $t): ?>
<form id="del_<?php echo (int)$t['id']; ?>" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="delete_id" value="<?php echo (int)$t['id']; ?>">
    <input type="hidden" name="delete_template" value="1">
</form>
<?php endforeach; ?>

<!-- Preview modal (WhatsApp light) -->
<div id="previewModal" class="tp-modal">
    <div class="tp-phone">
        <div class="tp-phone-hd">
            <div class="tp-phone-av">WA</div>
            <div><b>Your Business</b><small>Business account</small></div>
            <button type="button" class="tp-phone-x" onclick="document.getElementById('previewModal').style.display='none'" aria-label="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
        </div>
        <div class="tp-phone-bd"><div class="tp-wa" style="background:none;padding:0" id="previewMsg"></div></div>
    </div>
</div>

<script>
function toggleSelectAll(sa) {
    document.querySelectorAll('.tp-item:not([hidden]) .row-checkbox').forEach(function (cb) { cb.checked = sa.checked; });
    updateBulkActions();
}
function updateBulkActions() {
    var checked = document.querySelectorAll('.row-checkbox:checked').length;
    var bulk = document.getElementById('bulk-actions'), cnt = document.getElementById('selCount');
    if (bulk) bulk.style.display = checked > 0 ? 'inline-flex' : 'none';
    if (cnt) cnt.textContent = checked;
    var all = document.getElementById('selectAllCheckbox'), total = document.querySelectorAll('.row-checkbox').length;
    if (all && total > 0) all.checked = (checked === total);
}
function submitBulkDelete() {
    if (confirm('Are you sure you want to delete all selected templates? This cannot be undone.')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}
var TP_ICONS = {
    url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
    phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    quick_reply: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>'
};
function tpEsc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function tpFmt(t) {
    t = tpEsc(t).replace(/\*(.+?)\*/g, '<b>$1</b>').replace(/(^|[^\w])_(.+?)_(?=$|[^\w])/g, '$1<i>$2</i>').replace(/~(.+?)~/g, '<s>$1</s>');
    return t.replace(/\n/g, '<br>');
}
function previewTemplate(header, body, footer, headerType, buttons) {
    var html = '<div class="tp-msg"><div class="tp-bubble">';
    var labels = { image: 'Image', video: 'Video', document: 'Document', location: 'Location' };
    if (labels[headerType]) {
        html += '<div class="tp-media"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>' + labels[headerType] + ' header</div>';
    } else if (header) {
        html += '<div class="h">' + tpEsc(header) + '</div>';
    }
    html += '<div class="b">' + tpFmt(body) + '</div>';
    if (footer) html += '<div class="f">' + tpEsc(footer) + '</div>';
    html += '<div class="t">10:42 AM</div></div>';
    if (buttons && buttons.length) {
        html += '<div class="tp-btns">';
        buttons.forEach(function (b) { html += '<div class="bt">' + (TP_ICONS[b.type] || TP_ICONS.quick_reply) + tpEsc(b.text) + '</div>'; });
        html += '</div>';
    }
    document.getElementById('previewMsg').innerHTML = html + '</div>';
    document.getElementById('previewModal').style.display = 'flex';
}
(function () {
    var modal = document.getElementById('previewModal');
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });

    // Tabs
    var tabs = document.querySelectorAll('.tp-tab');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
            document.querySelectorAll('.tp-pane').forEach(function (p) { p.classList.toggle('on', p.id === 'pane-' + t.dataset.pane); });
        });
    });

    // Filter chips + search
    var chips = document.querySelectorAll('#tpChips .tp-chip'), items = document.querySelectorAll('.tp-item');
    var search = document.getElementById('tpSearch'), none = document.getElementById('tpNone'), filter = 'all';
    function apply() {
        var q = (search && search.value || '').toLowerCase().trim(), shown = 0;
        items.forEach(function (el) {
            var ok = (filter === 'all' || el.dataset.group === filter) && (!q || el.dataset.text.indexOf(q) !== -1);
            el.hidden = !ok; el.style.display = ok ? '' : 'none'; if (ok) shown++;
        });
        if (none) none.style.display = shown ? 'none' : 'block';
    }
    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            chips.forEach(function (x) { x.classList.remove('on'); });
            c.classList.add('on'); filter = c.dataset.f; apply();
        });
    });
    if (search) search.addEventListener('input', apply);

    // Copy example text
    document.querySelectorAll('.tp-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var txt = btn.getAttribute('data-copy') || '', orig = btn.textContent;
            (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () {
                btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = orig; }, 1800);
            }).catch(function () { btn.textContent = 'Copy failed'; setTimeout(function () { btn.textContent = orig; }, 1800); });
        });
    });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
