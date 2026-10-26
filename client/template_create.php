<?php
include '../includes/client_auth_check.php';
if (!has_feature('templates')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Create Template';
$currentPage = 'templates';

$languages = [
    'en' => 'English', 'hi' => 'Hindi', 'ta' => 'Tamil', 'te' => 'Telugu', 'ml' => 'Malayalam',
    'kn' => 'Kannada', 'mr' => 'Marathi', 'gu' => 'Gujarati', 'bn' => 'Bengali', 'pa' => 'Punjabi',
    'ur' => 'Urdu', 'or' => 'Odia', 'as' => 'Assamese', 'ar' => 'Arabic', 'es' => 'Spanish',
    'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'zh_CN' => 'Chinese',
];

$error = '';

// Published WhatsApp Flows available for an "Open form" (FLOW) button, keyed by Meta flow ID.
$waFlows = [];
if (has_feature('whatsapp_flows')) {
    require_once '../includes/wa_flows.php';
    waf_ensure_schema($conn);
    $stWf = $conn->prepare("SELECT flow_name, meta_flow_id, flow_json FROM whatsapp_flows WHERE company_id=? AND meta_status='PUBLISHED' AND meta_flow_id IS NOT NULL ORDER BY id DESC");
    if ($stWf) {
        $stWf->bind_param('i', $companyId);
        $stWf->execute();
        foreach ($stWf->get_result()->fetch_all(MYSQLI_ASSOC) as $wf) {
            $waFlows[(string)$wf['meta_flow_id']] = [
                'name'   => html_entity_decode((string)$wf['flow_name'], ENT_QUOTES, 'UTF-8'),
                'screen' => waf_first_screen((string)$wf['flow_json']),
            ];
        }
    }
}

/* "Edit" from the Templates list: pre-fill this form with an existing template. Meta does not allow changing an
   approved template's wording, so the edited copy is submitted as a new template (a new name for Meta-known ones). */
$tpPrefill = null;
$tpFromId = (int)($_GET['from'] ?? 0);
if ($tpFromId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stFrom = $conn->prepare('SELECT * FROM message_templates WHERE id=? AND company_id=? LIMIT 1');
    if ($stFrom) {
        $stFrom->bind_param('ii', $tpFromId, $companyId);
        $stFrom->execute();
        $src = $stFrom->get_result()->fetch_assoc();
        $stFrom->close();
        if ($src) {
            $srcStatus = strtoupper(trim((string)($src['wa_status'] ?? '')));
            $onMeta = $srcStatus !== '' && $srcStatus !== 'LOCAL';
            $name = strtolower((string)$src['name']);
            if ($onMeta) {   // Meta will not accept the same name again: suggest the next free _vN
                $taken = [];
                $stN = $conn->prepare('SELECT name FROM message_templates WHERE company_id=?');
                if ($stN) { $stN->bind_param('i', $companyId); $stN->execute(); foreach ($stN->get_result()->fetch_all() as $r) $taken[strtolower((string)$r[0])] = true; $stN->close(); }
                $base = preg_replace('/_v\d+$/', '', $name); $n = 2;
                while (isset($taken[$base . '_v' . $n])) $n++;
                $name = substr($base, 0, 500) . '_v' . $n;
            }
            $btns = [];
            foreach ((json_decode((string)($src['buttons'] ?? '[]'), true) ?: []) as $b) {
                $t = strtolower((string)($b['type'] ?? 'quick_reply'));
                $t = ['phone_number' => 'phone', 'dynamic_url' => 'url'][$t] ?? $t;
                if (!in_array($t, ['quick_reply', 'url', 'phone', 'flow'], true)) continue;
                $btns[] = ['type' => $t, 'text' => (string)($b['text'] ?? ''), 'value' => (string)($b['url'] ?? ($b['phone'] ?? ($b['phone_number'] ?? ($b['flow_id'] ?? ''))))];
            }
            $tpPrefill = [
                'name'        => $name,
                'oldName'     => (string)$src['name'],
                'onMeta'      => $onMeta,
                'category'    => strtolower((string)($src['category'] ?? 'marketing')),
                'language'    => (string)($src['language'] ?? 'en'),
                'header_type' => strtolower((string)($src['header_type'] ?? 'none')) ?: 'none',
                'header_text' => (string)($src['header_text'] ?? ''),
                'body'        => (string)(($src['body'] ?? '') !== '' ? $src['body'] : ($src['body_text'] ?? '')),
                'footer'      => (string)(($src['footer'] ?? '') !== '' ? $src['footer'] : ($src['footer_text'] ?? '')),
                'buttons'     => array_slice($btns, 0, 3),
                'carousel'    => !empty($src['carousel_cards']),
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_template'])) {
    csrf_verify();

    $name = sanitize($_POST['name']);
    $category = sanitize($_POST['category']);
    $language = sanitize($_POST['language'] ?? 'en');
    $tpl_format = (($_POST['template_format'] ?? 'header') === 'carousel') ? 'carousel' : 'header';

    $header_type = 'none';
    $header_text = '';
    $header_link = '';
    $buttons_json = null;
    $carousel_json = null;
    $body = trim($_POST['body']);
    $footer = trim((string)($_POST['footer'] ?? ''));

    // Uploads (safe pathing + basic validation)
    $uploadDirFs = __DIR__ . '/../uploads/templates/';
    $uploadDirUrl = 'uploads/templates/';
    if (!is_dir($uploadDirFs)) {
        @mkdir($uploadDirFs, 0755, true);
    }
    $maxUploadMB = 10;
    $allowedExt = [
        'image' => ['jpg','jpeg','png','gif','webp'],
        'video' => ['mp4','3gp','mov'],
        'document' => ['pdf','doc','docx','xls','xlsx','csv','txt'],
    ];

    // Carousel is supported, but requires media files for each card header.
    if ($tpl_format === 'carousel') {
        // Header settings are ignored in carousel mode.
        $header_type = 'none';
        $header_text = '';
        $header_link = '';
        $buttons_json = null;
        $footer = ''; // carousel templates have no footer
    }

    if (!$error && $tpl_format === 'header') {
        // --- HEADER MODE ---
        $header_type = sanitize($_POST['header_type'] ?? 'none');
        if ($header_type === 'text') {
            $header_text = trim((string)($_POST['header_text'] ?? ''));
        }
        else if (in_array($header_type, ['image', 'video', 'document'], true)) {
            if (isset($_FILES['header_file']) && $_FILES['header_file']['error'] === UPLOAD_ERR_OK) {
                if (($_FILES['header_file']['size'] ?? 0) > ($maxUploadMB * 1024 * 1024)) {
                    $error = "Header file too large. Max {$maxUploadMB}MB.";
                } else {
                    $ext = strtolower(pathinfo($_FILES['header_file']['name'], PATHINFO_EXTENSION));
                    $ok = in_array($ext, $allowedExt[$header_type] ?? [], true);
                    if (!$ok) {
                        $error = "Invalid header file type.";
                    } else {
                        $fileName = 'tpl_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['header_file']['tmp_name'], $uploadDirFs . $fileName)) {
                            $header_link = $uploadDirUrl . $fileName;
                        }
                    }
                }
            }
        }
        else if ($header_type === 'location') {
            $header_link = sanitize($_POST['header_link'] ?? '');
        }

        // Header Mode Buttons
        $buttons = [];
        if (isset($_POST['btn_type']) && is_array($_POST['btn_type'])) {
            for ($i = 0; $i < count($_POST['btn_type']); $i++) {
                $type = sanitize($_POST['btn_type'][$i]);
                $text = sanitize($_POST['btn_text'][$i] ?? '');
                if ($type && $text) {
                    $btn = ['type' => $type, 'text' => $text];
                    if (in_array($type, ['url', 'dynamic_url']))
                        $btn['url'] = sanitize($_POST['btn_value'][$i] ?? '');
                    if ($type === 'phone')
                        $btn['phone'] = sanitize($_POST['btn_value'][$i] ?? '');
                    if ($type === 'copy_code')
                        $btn['copy_code'] = sanitize($_POST['btn_value'][$i] ?? '');
                    if ($type === 'flow')
                        $btn['flow_id'] = preg_replace('/\D/', '', (string)($_POST['btn_value'][$i] ?? ''));
                    $buttons[] = $btn;
                }
            }
        }
        $flowBtns = array_values(array_filter($buttons, fn($b) => $b['type'] === 'flow'));
        if (count($flowBtns) > 1) {
            $error = "A template can have only one Open form button.";
        } elseif ($flowBtns && !isset($waFlows[$flowBtns[0]['flow_id']])) {
            $error = "Pick a published WhatsApp form for the Open form button.";
        }
        $buttons_json = empty($buttons) ? null : json_encode($buttons);

    } else if (!$error) {
        // --- CAROUSEL MODE ---
        // Meta rules: 2 to 10 cards; every card uses the same media type and the same button layout.
        $cMediaType = (($_POST['carousel_media_type'] ?? 'image') === 'video') ? 'video' : 'image';
        $cExts  = $cMediaType === 'video' ? ['mp4'] : ['jpg', 'jpeg', 'png'];
        $cMaxMB = $cMediaType === 'video' ? 16 : 5;
        $cLen   = static function (string $t): int { return function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t); };
        $idxList = (isset($_POST['card_index']) && is_array($_POST['card_index'])) ? array_values($_POST['card_index']) : [];
        $cards = [];
        $layoutSig = null;

        if (count($idxList) < 2) {
            $error = "A carousel needs at least 2 cards.";
        } elseif (count($idxList) > 10) {
            $error = "A carousel can have at most 10 cards.";
        }

        foreach ($idxList as $n => $idxRaw) {
            if ($error) break;
            $idx = preg_replace('/\D/', '', (string)$idxRaw);
            $label = 'Card ' . ($n + 1);

            $c_body = trim((string)($_POST['card_body'][$idx] ?? ''));
            if ($c_body === '') { $error = "$label needs body text."; break; }
            if ($cLen($c_body) > 160) { $error = "$label text is longer than 160 characters."; break; }

            $f = $_FILES['card_file_' . $idx] ?? null;
            $fErr = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($fErr === UPLOAD_ERR_INI_SIZE || $fErr === UPLOAD_ERR_FORM_SIZE) { $error = "$label file is too large. Max {$cMaxMB}MB."; break; }
            if ($fErr !== UPLOAD_ERR_OK) { $error = "$label needs " . ($cMediaType === 'video' ? 'a video' : 'an image') . " file."; break; }
            if (($f['size'] ?? 0) > ($cMaxMB * 1024 * 1024)) { $error = "$label file is too large. Max {$cMaxMB}MB."; break; }
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $cExts, true)) { $error = "$label: please upload a " . strtoupper(implode(' / ', $cExts)) . " file."; break; }
            $fileName = 'card_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $uploadDirFs . $fileName)) { $error = "$label file could not be saved. Please try again."; break; }
            $c_media = $uploadDirUrl . $fileName;

            // Buttons (max 2): quick reply or website link
            $c_btns = [];
            $bTypes = $_POST['cbtn_type_' . $idx] ?? [];
            if (!is_array($bTypes)) $bTypes = [];
            foreach (array_values($bTypes) as $b => $bTypeRaw) {
                $bType = (string)$bTypeRaw;
                if (!in_array($bType, ['quick_reply', 'url'], true)) continue;
                $bText = trim((string)($_POST['cbtn_text_' . $idx][$b] ?? ''));
                $bVal  = trim((string)($_POST['cbtn_val_' . $idx][$b] ?? ''));
                if ($bText === '') { $error = "$label: button " . ($b + 1) . " needs a label."; break 2; }
                if ($cLen($bText) > 25) { $error = "$label: button " . ($b + 1) . " label is longer than 25 characters."; break 2; }
                $btn = ['type' => $bType, 'text' => $bText];
                if ($bType === 'url') {
                    if (!filter_var(str_replace('{{1}}', 'x', $bVal), FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $bVal)) {
                        $error = "$label: button " . ($b + 1) . " needs a full website link starting with https://"; break 2;
                    }
                    $btn['url'] = $bVal;
                }
                $c_btns[] = $btn;
                if (count($c_btns) >= 2) break;
            }
            $sig = implode(',', array_column($c_btns, 'type'));
            if ($layoutSig === null) { $layoutSig = $sig; }
            elseif ($sig !== $layoutSig) { $error = "$label has different buttons from Card 1. Every card needs the same button types in the same order."; break; }

            $cards[] = [
                'header_type' => $cMediaType,
                'media_url' => $c_media,
                'body' => $c_body,
                'buttons' => $c_btns
            ];
        }
        $carousel_json = (!$error && !empty($cards)) ? json_encode($cards) : null;
    }

    if (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $error = "Template name can only contain lowercase letters, numbers, and underscores.";
    }
    elseif (empty($body)) {
        $error = ($tpl_format === 'carousel')
            ? "Intro body text is required for carousel."
            : "Body text is required.";
    }
    elseif ($tpl_format === 'carousel' && empty($carousel_json) && !$error) {
        $error = "Carousel templates require at least 2 cards.";
    }
    elseif ($tpl_format === 'carousel' && $category === 'authentication') {
        $error = "Carousel templates must use the Marketing or Utility category.";
    }
    elseif (!$error) {
        // --- META API PUSH ---
        $stTc1 = $conn->prepare("SELECT * FROM whatsapp_config WHERE company_id=?");
        $stTc1->bind_param("i", $companyId);
        $stTc1->execute();
        $waConfig = $stTc1->get_result()->fetch_assoc();
        if ($waConfig && $waConfig['access_token'] && $waConfig['waba_id']) {
            $apiVersion = $waConfig['api_version'] ?? 'v18.0';
            $wabaId = $waConfig['waba_id'];
            // Decrypt stored token if needed
            $accessToken = $waConfig['access_token'];
            if (function_exists('decrypt_secret')) {
                $accessToken = decrypt_secret($accessToken) ?? $accessToken;
            }
            $fbAppId = (string)($waConfig['fb_app_id'] ?? '');

            // Helper: Upload media via resumable upload to get header_handle (h)
            $getHeaderHandle = static function(string $filePath, string $fileName, string $mimeType) use ($apiVersion, $accessToken, $fbAppId): ?string {
                if ($fbAppId === '' || !file_exists($filePath)) return null;
                $fileLen = filesize($filePath);
                if ($fileLen === false || $fileLen <= 0) return null;

                // 1) Initiate upload session
                $initUrl = "https://graph.facebook.com/{$apiVersion}/{$fbAppId}/uploads";
                $initPayload = json_encode([
                    'file_length' => (int)$fileLen,
                    'file_type'   => $mimeType,
                    'file_name'   => $fileName,
                ]);
                $ch1 = curl_init($initUrl);
                curl_setopt_array($ch1, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $initPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/json",
                        "Authorization: Bearer {$accessToken}",
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $initResp = curl_exec($ch1);
                $initCode = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
                curl_close($ch1);
                if ($initCode < 200 || $initCode >= 300) return null;
                $initJson = json_decode((string)$initResp, true);
                $sessionId = (string)($initJson['id'] ?? '');
                if ($sessionId === '') return null;

                // 2) Upload file bytes
                $bytes = file_get_contents($filePath);
                if ($bytes === false) return null;
                $ch2 = curl_init("https://graph.facebook.com/{$apiVersion}/{$sessionId}");
                curl_setopt_array($ch2, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $bytes,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$accessToken}",
                        "Content-Type: {$mimeType}",
                        "file_offset: 0",
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $upResp = curl_exec($ch2);
                $upCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                if ($upCode < 200 || $upCode >= 300) return null;
                $upJson = json_decode((string)$upResp, true);
                $h = (string)($upJson['h'] ?? $upJson['handle'] ?? '');
                return $h !== '' ? $h : null;
            };

            // Build Components for Meta
            $components = [];

            // 1. Header (standard format only)
            if ($tpl_format === 'header' && $header_type !== 'none') {
                $comp = ["type" => "HEADER"];
                if ($header_type === 'text') {
                    $comp["format"] = "TEXT";
                    $comp["text"] = $header_text;
                    if (strpos($header_text, '{{1}}') !== false) {
                        $comp["example"] = ["header_text" => ["Sample Header"]];
                    }
                } else if (in_array($header_type, ['image', 'video', 'document'], true)) {
                    $comp["format"] = strtoupper($header_type);
                    $localFs = '';
                    if (!empty($header_link)) {
                        $localFs = realpath(__DIR__ . '/../' . ltrim($header_link, '/\\')) ?: '';
                    }
                    $mime = 'application/octet-stream';
                    if ($header_type === 'image') $mime = (strtolower(pathinfo($localFs, PATHINFO_EXTENSION)) === 'png') ? 'image/png' : 'image/jpeg';
                    elseif ($header_type === 'video') $mime = 'video/mp4';
                    elseif ($header_type === 'document') $mime = 'application/pdf';
                    $handle = $localFs ? $getHeaderHandle($localFs, basename($localFs), $mime) : null;
                    if (!$handle) {
                        $error = "Failed to upload header media to Meta. Please verify fb_app_id, access token, and try again.";
                    } else {
                        $comp["example"] = ["header_handle" => [$handle]];
                    }
                }
                if (!$error) $components[] = $comp;
            }

            // 2. Body or Intro Body (for carousel, this acts as intro text)
            $bodyComp = ["type" => "BODY", "text" => $body];
            preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
            if (!empty($matches[1])) {
                $examples = [];
                foreach ($matches[1] as $m) $examples[] = "Sample";
                $bodyComp["example"] = ["body_text" => [$examples]];
            }
            $components[] = $bodyComp;

            // 3. Footer
            if (!empty($footer)) {
                $components[] = ["type" => "FOOTER", "text" => $footer];
            }

            // 4. Buttons (standard only)
            if ($tpl_format === 'header' && $buttons_json) {
                $btns = json_decode($buttons_json, true);
                $metaButtons = [];
                foreach ($btns as $b) {
                    $mb = ["type" => strtoupper($b['type']), "text" => $b['text']];
                    if ($b['type'] === 'url') $mb["url"] = $b['url'];
                    if ($b['type'] === 'phone') { $mb["type"] = "PHONE_NUMBER"; $mb["phone_number"] = $b['phone']; }
                    // Meta uses QUICK_REPLY with underscore
                    if ($b['type'] === 'quick_reply') $mb["type"] = "QUICK_REPLY";
                    if ($b['type'] === 'flow') {
                        $mb["flow_id"] = $b['flow_id'];
                        $mb["flow_action"] = "navigate";
                        $scr = $waFlows[$b['flow_id']]['screen'] ?? '';
                        if ($scr !== '') $mb["navigate_screen"] = $scr;
                    }
                    $metaButtons[] = $mb;
                }
                $components[] = ["type" => "BUTTONS", "buttons" => $metaButtons];
            }

            // 5. Carousel component
            if ($tpl_format === 'carousel') {
                $cards = json_decode((string)$carousel_json, true);
                if (!is_array($cards) || empty($cards)) {
                    $error = "Carousel templates require at least 1 card.";
                } else {
                    $metaCards = [];
                    foreach ($cards as $card) {
                        $cType = strtolower((string)($card['header_type'] ?? 'image'));
                        $mediaUrl = (string)($card['media_url'] ?? '');
                        $cBody = (string)($card['body'] ?? '');
                        $cBtns = is_array($card['buttons'] ?? null) ? $card['buttons'] : [];

                        $localFs = realpath(__DIR__ . '/../' . ltrim($mediaUrl, '/\\')) ?: '';
                        $mime = ($cType === 'video') ? 'video/mp4' : ((strtolower(pathinfo($localFs, PATHINFO_EXTENSION)) === 'png') ? 'image/png' : 'image/jpeg');
                        $handle = $localFs ? $getHeaderHandle($localFs, basename($localFs), $mime) : null;
                        if (!$handle) {
                            $error = ($fbAppId === '')
                                ? "Your Facebook App ID is missing, so card media cannot be sent to Meta. Add it in Settings > WhatsApp Setup and try again."
                                : "Meta did not accept the media for a carousel card. Check that the files are valid (JPG/PNG up to 5 MB or MP4 up to 16 MB), your access token is still valid, and try again.";
                            break;
                        }

                        $cardComps = [];
                        $cardComps[] = [
                            'type' => 'HEADER',
                            'format' => strtoupper($cType),
                            'example' => ['header_handle' => [$handle]],
                        ];

                        $cardBody = ['type' => 'BODY', 'text' => $cBody];
                        preg_match_all('/\{\{(\d+)\}\}/', $cBody, $mm);
                        if (!empty($mm[1])) {
                            $ex = [];
                            foreach ($mm[1] as $x) $ex[] = "Sample";
                            $cardBody['example'] = ['body_text' => [$ex]];
                        }
                        $cardComps[] = $cardBody;

                        if (!empty($cBtns)) {
                            $metaBtns = [];
                            foreach ($cBtns as $b) {
                                $bt = strtolower((string)($b['type'] ?? ''));
                                $txt = (string)($b['text'] ?? '');
                                if ($txt === '') continue;
                                if ($bt === 'quick_reply') {
                                    $metaBtns[] = ['type' => 'QUICK_REPLY', 'text' => $txt];
                                } elseif ($bt === 'url' || $bt === 'dynamic_url') {
                                    $u = (string)($b['url'] ?? '');
                                    if ($u === '') continue;
                                    $mb2 = ['type' => 'URL', 'text' => $txt, 'url' => $u];
                                    if (strpos($u, '{{1}}') !== false) $mb2['example'] = [str_replace('{{1}}', 'sample', $u)];
                                    $metaBtns[] = $mb2;
                                }
                            }
                            if (!empty($metaBtns)) {
                                $cardComps[] = ['type' => 'BUTTONS', 'buttons' => $metaBtns];
                            }
                        }

                        $metaCards[] = ['components' => $cardComps];
                    }
                    if (!$error) {
                        $components[] = ['type' => 'CAROUSEL', 'cards' => $metaCards];
                    }
                }
            }

            if (!$error) {
            $payload = [
                "name" => $name,
                "category" => strtoupper($category),
                "language" => $language,
                "components" => $components
            ];

            // CALL META API
            $url = "https://graph.facebook.com/$apiVersion/$wabaId/message_templates";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer $accessToken"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 60
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode((string)$response, true);

            if ($httpCode >= 200 && $httpCode < 300) {
                // Success! Save to Local DB
                $wa_id = $resData['id'] ?? null;
                // Make sure the carousel column exists on older databases
                try { $cc = $conn->query("SHOW COLUMNS FROM message_templates LIKE 'carousel_cards'"); if ($cc && $cc->num_rows === 0) $conn->query("ALTER TABLE message_templates ADD COLUMN carousel_cards LONGTEXT NULL"); } catch (\Throwable $e) {}
                // Backwards/forwards compat: populate both body/body_text + footer/footer_text
                $stmt = $conn->prepare("INSERT INTO message_templates
                    (company_id, name, category, language, header_type, header_text, header_link, body, body_text, footer, footer_text, buttons, carousel_cards, wa_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'PENDING')");
                $stmt->bind_param(
                    "issssssssssss",
                    $companyId,
                    $name,
                    $category,
                    $language,
                    $header_type,
                    $header_text,
                    $header_link,
                    $body,
                    $body,
                    $footer,
                    $footer,
                    $buttons_json,
                    $carousel_json
                );

                if ($stmt->execute()) {
                    header("Location: templates.php?msg=" . urlencode("Template pushed to Meta successfully."));
                    exit();
                } else {
                    $error = "DB Save Error: " . $conn->error;
                }
            } else {
                $mErr = is_array($resData) ? ($resData['error'] ?? []) : [];
                $mMsg = (string)($mErr['error_user_msg'] ?? $mErr['message'] ?? '');
                if ($mMsg === '') $mMsg = $response === false ? 'Could not reach Meta. Please try again.' : 'Unknown Error';
                $mTitle = (string)($mErr['error_user_title'] ?? '');
                $error = "Meta API Error: " . htmlspecialchars(($mTitle !== '' ? $mTitle . ' — ' : '') . $mMsg, ENT_QUOTES, 'UTF-8');
            }
            } // end if (!$error) — Meta call
        } else {
            $error = "WhatsApp not configured correctly. WABA ID or Access Token missing.";
        }
    }
}

include '../includes/client_header.php';
?>

<style>
/* ═══ Create template — WhatsApp Manager "Edit template" look ═══ */
.mt{--blue:#0A78BE;--blue-strong:#075F97;--sel:#DCE9F5;--ink:#1C2B33;--ink-2:#465A69;--ink-3:#67788A;--line:#CBD2D9;--line-soft:#E3E7EC;--hover:#F3F5F7;--wall:#E9E1D8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);font-size:14px;line-height:1.45;margin:-6px -4px 0;padding:14px 16px 20px;border-radius:16px;background:linear-gradient(115deg,#F4EEF5 0%,#EEF1F7 40%,#E5EEF8 100%)}
.mt *{box-sizing:border-box}
.mt h2,.mt h3,.mt h4,.mt p{margin:0}
.mt-steps{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:12px}
.mt-step{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:6px;font-weight:700;color:var(--ink-2)}
.mt-step svg{width:16px;height:16px;flex:none}
.mt-step.done svg{color:var(--blue)}
.mt-step.cur{background:var(--sel);color:var(--ink)}
.mt-step.cur svg{color:var(--blue)}
.mt-step.later{color:var(--ink-3)}
.mt-dash{width:14px;height:1px;background:var(--line)}
.mt-alert{background:#FBE7E5;color:#8f1d14;border-radius:6px;padding:11px 14px;margin-bottom:12px;font-weight:600;box-shadow:0 0 0 1px rgba(196,40,28,.18)}
.mt-grid{display:grid;grid-template-columns:minmax(0,1fr) clamp(290px,26vw,380px);gap:clamp(16px,1.6vw,24px);align-items:start}
.mt-col{display:flex;flex-direction:column;gap:12px;min-width:0}
.mt-card{background:#fff;border-radius:6px;box-shadow:0 0 0 1px rgba(28,43,51,.06),0 1px 3px rgba(28,43,51,.1);padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0}
.mt-card h2{font-size:16px;font-weight:700}
.mt-card h3{font-size:14px;font-weight:700}
.mt-head{flex-direction:row;align-items:center;gap:12px;padding:12px 16px}
.mt-ico{width:36px;height:36px;border-radius:6px;background:#1C7B5D;color:#fff;display:grid;place-items:center;flex:none}
.mt-ico svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.mt-head b{display:block;font-size:15px;overflow-wrap:anywhere}
.mt-head span{display:block;font-size:12px;color:var(--ink-3)}
.mt-desc{color:var(--ink-2)}
.mt-row2{display:grid;grid-template-columns:minmax(0,1fr) 230px;gap:12px}
.mt-f{display:flex;flex-direction:column;gap:5px;min-width:0}
.mt-f>label,.mt-lab{font-weight:700;display:flex;gap:8px;align-items:baseline;justify-content:space-between;font-size:13px}
.mt-opt{font-weight:400;color:var(--ink-3)}
.mt-count{font-size:12px;font-weight:400;color:var(--ink-3);font-variant-numeric:tabular-nums}
.mt-count.over{color:#C4281C;font-weight:700}
.mt .form-control{width:100%;font:inherit;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:4px;padding:8px 10px;min-height:34px;box-shadow:none}
.mt .form-control:focus{outline:2px solid var(--blue);outline-offset:-1px;border-color:var(--blue)}
.mt textarea.form-control{min-height:130px;line-height:1.45;resize:vertical;padding-bottom:30px}
.mt-boxwrap{position:relative}
.mt-boxwrap .mt-count{position:absolute;right:10px;top:8px;background:#fff;padding-left:6px;pointer-events:none}
.mt-boxwrap.in-input .mt-count{top:50%;transform:translateY(-50%)}
.mt-boxwrap .form-control{padding-right:64px}
.mt-tools{display:flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin-top:-4px}
.format-btn{border:0;background:transparent;color:var(--ink-2);min-width:28px;height:28px;border-radius:4px;display:inline-grid;place-items:center;font:700 13px inherit;cursor:pointer;padding:0 8px}
.format-btn:hover{background:var(--hover);color:var(--ink)}
.mt-chips{display:flex;gap:6px;flex-wrap:wrap}
.mt-chip{position:relative}
.mt-chip input{position:absolute;opacity:0;pointer-events:none}
.mt-chip span{display:inline-block;border:1px solid var(--line);background:#fff;border-radius:6px;padding:6px 14px;font-weight:600;color:var(--ink-2);cursor:pointer;transition:background .2s,border-color .2s}
.mt-chip:hover span{background:var(--hover)}
.mt-chip input:checked+span{background:var(--sel);border-color:var(--blue);color:var(--ink)}
.mt-chip input:focus-visible+span{outline:2px solid var(--blue);outline-offset:2px}
.mt-pills{display:grid;grid-template-columns:repeat(2,1fr);border:1px solid var(--line);border-radius:6px;overflow:hidden}
.mt-pills label{display:flex;align-items:center;justify-content:center;padding:8px 6px;font-weight:700;cursor:pointer;background:#fff;color:var(--ink)}
.mt-pills label+label{border-left:1px solid var(--line)}
.mt-pills label input{display:none}
.mt-pills label:has(input:checked){background:#DDE2E7}
.mt-sub{display:flex;flex-direction:column;gap:8px}
.mt-dd{position:relative;display:inline-block}
.mt-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--line);background:#fff;border-radius:6px;padding:7px 14px;font:600 14px inherit;color:var(--ink);cursor:pointer;text-decoration:none;transition:background .15s}
.mt-btn:hover{background:var(--hover)}
.mt-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.mt-btn.primary:hover{background:var(--blue-strong);border-color:var(--blue-strong)}
.mt-btn.sm{padding:5px 10px;font-size:13px}
.mt-btn.danger:hover{color:#C4281C;border-color:#C4281C}
.mt-menu{display:none;position:absolute;left:0;top:calc(100% + 4px);z-index:20;min-width:190px;background:#fff;border-radius:6px;box-shadow:0 0 0 1px rgba(28,43,51,.1),0 8px 24px rgba(28,43,51,.18);padding:4px}
.mt-menu.open{display:block}
.mt-menu button{display:block;width:100%;text-align:left;border:0;background:transparent;padding:8px 12px;border-radius:4px;font:inherit;cursor:pointer}
.mt-menu button:hover{background:var(--hover)}
.mt-brow{display:flex;gap:8px;align-items:center;background:var(--hover);padding:8px;border-radius:6px;margin-bottom:8px}
.mt-brow .kind{font-size:12px;font-weight:700;color:var(--ink-2);width:84px;text-transform:capitalize}
.card-box{background:var(--hover);border-radius:6px;padding:14px;margin-bottom:12px;display:flex;flex-direction:column;gap:10px;position:relative}
.card-box .form-control{background:#fff}
.car-tip{background:#fff;border-radius:8px;padding:10px 12px;font-size:12.5px;color:var(--ink-2);line-height:1.5}
.car-tip b{color:var(--ink)}
.car-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.car-head strong{font-size:14px}
.car-fcount{font-size:11.5px;color:var(--ink-3);text-align:right}
.car-fcount.over{color:#c4281c;font-weight:700}
.car-bt{display:flex;gap:8px;align-items:center;margin-bottom:6px}
.car-bt .kind{font-size:12px;font-weight:700;color:var(--ink-2);width:92px;flex:none}
.mt-ccard video{width:100%;height:100px;object-fit:cover;border-radius:6px;display:block}
#car_err{display:none;margin-bottom:0}
.mt-actions{position:sticky;bottom:0;z-index:5;display:flex;justify-content:flex-end;align-items:center;gap:8px;background:#fff;border-radius:6px;box-shadow:0 0 0 1px rgba(28,43,51,.06),0 -2px 10px rgba(28,43,51,.08);padding:10px 16px}
.mt-actions .said{margin-right:auto;color:var(--ink-3);font-size:13px}

/* preview */
.mt-side{position:sticky;top:90px;gap:12px;display:flex;flex-direction:column;max-height:calc(100vh - 110px);max-height:calc(100dvh - 110px)}
.mt-side>h3,.mt-side>.mt-fine{flex:none}
.mt-side .mt-wall{flex:1 1 auto;min-height:220px;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin}
.mt-side .mt-msg{max-width:min(100%,300px)}
.mt-wall{border-radius:4px;overflow:hidden;min-height:340px;background-color:var(--wall);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='84' height='84' fill='none' stroke='%23DCD2C6' stroke-width='1.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='14' cy='16' r='5'/%3E%3Cpath d='M46 10l6 6m0-6l-6 6M60 46c4-4 8 4 12 0M12 54h10v8H12zM38 50l5 9 5-9zM66 20v8m-4-4h8M30 32c3 3 7 3 10 0'/%3E%3C/svg%3E")}
.mt-chat{padding:14px 12px 16px 16px;display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.mt-msg{position:relative;max-width:250px;width:100%;background:#fff;color:#111B21;border-radius:0 8px 8px 8px;box-shadow:0 1px .5px rgba(11,20,26,.16);font-size:13.5px;line-height:1.38}
.mt-msg::before{content:'';position:absolute;left:-7px;top:0;border-top:9px solid #fff;border-left:8px solid transparent}
.mt-msg .pad{padding:6px 8px 5px;display:flex;flex-direction:column;gap:3px}
.mt-media{margin:3px 3px 0;border-radius:6px;overflow:hidden;aspect-ratio:1.91/1;background:linear-gradient(135deg,#F3D9B8,#E0A63C 55%,#B4472F);display:none;align-items:center;justify-content:center;color:rgba(255,255,255,.92);position:relative}
.mt-media img{width:100%;height:100%;object-fit:cover;display:none}
.mt-media .ico{display:none}
.mt-media svg{width:34px;height:34px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.mt-bhead{font-weight:700;display:none}
.mt-bbody{overflow-wrap:anywhere;white-space:pre-wrap}
.mt-bfoot{color:#6B7C85;font-size:12px;display:none}
.mt-meta{text-align:right;color:#6B7C85;font-size:11px;margin-top:-1px}
.mt-bbtns{display:flex;flex-direction:column}
.mt-bbtn{display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;border-top:1px solid #E6EAED;color:#0A8BD6;font-weight:600;overflow-wrap:anywhere}
.mt-bbtn:last-child{border-radius:0 0 8px 8px}
.mt-bbtn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none}
.mt-ccard{min-width:200px;max-width:200px;background:#fff;border-radius:8px;padding:4px;scroll-snap-align:start;flex-shrink:0;box-shadow:0 1px .5px rgba(11,20,26,.16);color:#111B21}
.mt-ccard .cm{height:100px;background:linear-gradient(135deg,#F3D9B8,#E0A63C);border-radius:6px;display:grid;place-items:center;color:rgba(255,255,255,.92)}
.mt-ccard .cm svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.mt-ccard img{width:100%;height:100px;object-fit:cover;border-radius:6px}
.mt-ccard .ct{font-size:13px;padding:6px 4px;line-height:1.3;overflow-wrap:anywhere}
.mt-ccard .cb{display:flex;flex-direction:column}
.mt-ccard .cb div{border-top:1px solid #E6EAED;color:#0A8BD6;font-size:12.5px;font-weight:600;text-align:center;padding:7px}
.mt-fine{font-size:12px;color:var(--ink-3)}
@media (max-width:980px){.mt-grid{grid-template-columns:minmax(0,1fr)}.mt-side{position:static;max-height:none}.mt-side .mt-wall{max-height:65vh;max-height:65dvh}}
@media (max-width:600px){.mt-row2{grid-template-columns:minmax(0,1fr)}.mt-dash{display:none}}
</style>

<div class="mt">

<nav class="mt-steps" aria-label="Steps">
    <span class="mt-step done"><svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="7.3" fill="currentColor"/><path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Set up template</span><span class="mt-dash"></span>
    <span class="mt-step cur"><svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 1.5a6.5 6.5 0 0 0 0 13z" fill="currentColor"/></svg>Edit template</span><span class="mt-dash"></span>
    <span class="mt-step later"><svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>Submit for Review</span>
</nav>

<?php if ($error): ?>
    <div class="mt-alert"><?php echo $error; ?></div>
<?php endif; ?>

<div class="mt-grid">

    <!-- LEFT: BUILDER -->
    <div class="mt-col">
        <?php if ($tpPrefill): ?>
        <div style="margin:0 0 14px;padding:12px 16px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13.5px;line-height:1.55;">
            <b>Editing &ldquo;<?php echo htmlspecialchars($tpPrefill['oldName'], ENT_QUOTES, 'UTF-8'); ?>&rdquo;.</b>
            <?php if ($tpPrefill['onMeta']): ?>Meta does not allow changing a template it has already reviewed, so your changes are sent as a new template named <b><?php echo htmlspecialchars($tpPrefill['name'], ENT_QUOTES, 'UTF-8'); ?></b> (you can rename it). The original stays as it is; delete it afterwards if you no longer need it.<?php else: ?>Change anything you need and submit it to Meta for review.<?php endif; ?>
            <?php if ($tpPrefill['carousel']): ?><br>This is a carousel template: re-add its cards below.<?php endif; ?>
            <?php if (in_array($tpPrefill['header_type'], ['image', 'video', 'document'], true)): ?><br>Upload the header <?php echo htmlspecialchars($tpPrefill['header_type'], ENT_QUOTES, 'UTF-8'); ?> again; files are not copied.<?php endif; ?>
        </div>
        <?php endif; ?>
        <form id="templateForm" method="POST" enctype="multipart/form-data" class="mt-col">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="create_template" value="1">

            <div class="mt-card mt-head">
                <div class="mt-ico" id="mtIco"><svg viewBox="0 0 24 24"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg></div>
                <div><b id="mtTitle">your_template_name • English</b><span id="mtSub">Marketing · Default</span></div>
            </div>

            <div class="mt-card">
                <h2>Template name and language</h2>
                <div class="mt-row2">
                    <div class="mt-f">
                        <label for="tpl_name">Name your template <span class="mt-count" id="cnt_name">0/512</span></label>
                        <input type="text" id="tpl_name" name="name" class="form-control" placeholder="Enter a template name e.g. summer_sale" required pattern="[a-z0-9_]+" maxlength="512"
                               oninput="this.value = this.value.toLowerCase().replace(/[\s\-]/g, '_').replace(/[^a-z0-9_]/g, ''); updatePreview()">
                        <span class="mt-fine">Lowercase letters, numbers and underscores only. It cannot be changed after submitting.</span>
                    </div>
                    <div class="mt-f">
                        <label for="tpl_lang">Select language</label>
                        <select name="language" id="tpl_lang" class="form-control" onchange="updatePreview()">
                            <?php foreach ($languages as $code => $label): ?>
                                <option value="<?php echo $code; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mt-card">
                <h2>Category and format</h2>
                <div class="mt-row2" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
                    <div class="mt-f">
                        <label for="tpl_cat">Category</label>
                        <select name="category" id="tpl_cat" class="form-control" onchange="updatePreview()">
                            <option value="marketing">Marketing (Promotions, Updates)</option>
                            <option value="utility">Utility (Account Updates, Alerts)</option>
                            <option value="authentication">Authentication (OTP)</option>
                        </select>
                    </div>
                    <div class="mt-f">
                        <label>Template format</label>
                        <div class="mt-pills">
                            <label><input type="radio" name="template_format" value="header" checked onchange="toggleFormat()"> Standard</label>
                            <label><input type="radio" name="template_format" value="carousel" onchange="toggleFormat()"> Carousel</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-card">
                <h2>Content</h2>
                <p class="mt-desc">Add a header, body and footer for your template. Meta will review the template variables and content before it can be sent.</p>

                <!-- Standard Header Block -->
                <div id="block_standard_header" class="mt-sub">
                    <div class="mt-lab">Header <span class="mt-opt">· Optional</span></div>
                    <div class="mt-chips">
                        <label class="mt-chip"><input type="radio" name="header_type" value="none" checked onchange="toggleStandardHeader()"><span>None</span></label>
                        <label class="mt-chip"><input type="radio" name="header_type" value="text" onchange="toggleStandardHeader()"><span>Text</span></label>
                        <label class="mt-chip"><input type="radio" name="header_type" value="image" onchange="toggleStandardHeader()"><span>Image</span></label>
                        <label class="mt-chip"><input type="radio" name="header_type" value="video" onchange="toggleStandardHeader()"><span>Video</span></label>
                        <label class="mt-chip"><input type="radio" name="header_type" value="document" onchange="toggleStandardHeader()"><span>Document</span></label>
                        <label class="mt-chip"><input type="radio" name="header_type" value="location" onchange="toggleStandardHeader()"><span>Location</span></label>
                    </div>
                    <div id="h_text_wrap" style="display:none;">
                        <div class="mt-boxwrap in-input">
                            <input type="text" name="header_text" id="h_text" class="form-control" placeholder="Add a short line of text to the header of your message" maxlength="60" oninput="updatePreview()">
                            <span class="mt-count" id="cnt_h">0/60</span>
                        </div>
                    </div>
                    <div id="h_media_wrap" style="display:none;">
                        <input type="file" name="header_file" id="h_file" class="form-control" onchange="handleStandardUpload(this)">
                        <input type="url" name="header_link" id="h_link" class="form-control" style="display:none;" placeholder="Maps Link" oninput="updatePreview()">
                    </div>
                </div>

                <!-- Shared Body Block -->
                <div class="mt-sub">
                    <div class="mt-lab"><span id="title_body">Body</span></div>
                    <div class="mt-boxwrap">
                        <textarea name="body" id="tpl_body" class="form-control" rows="5" maxlength="1024" oninput="updatePreview()" required placeholder="Enter the text for your message in the language that you have selected"></textarea>
                        <span class="mt-count" id="cnt_body">0/1024</span>
                    </div>
                    <div class="mt-tools">
                        <button type="button" class="format-btn" title="Bold" onclick="formatText('*')">B</button>
                        <button type="button" class="format-btn" title="Italic" style="font-style:italic;" onclick="formatText('_')">I</button>
                        <button type="button" class="format-btn" title="Strikethrough" style="text-decoration:line-through;" onclick="formatText('~')">S</button>
                        <button type="button" class="format-btn" title="Code" style="font-family:monospace;" onclick="formatCode()">&lt;/&gt;</button>
                        <button type="button" class="format-btn" title="Add variable" style="color:var(--blue)" onclick="insertVariable()">+ Add variable</button>
                    </div>
                </div>

                <!-- Shared Footer Block -->
                <div class="mt-sub" id="block_footer">
                    <div class="mt-lab">Footer <span class="mt-opt">· Optional</span></div>
                    <div class="mt-boxwrap in-input">
                        <input type="text" name="footer" id="tpl_footer" class="form-control" placeholder="Add a short line of text to the bottom of your message" maxlength="60" oninput="updatePreview()">
                        <span class="mt-count" id="cnt_foot">0/60</span>
                    </div>
                </div>
            </div>

            <!-- Standard Buttons Block -->
            <div id="block_standard_buttons" class="mt-card">
                <h2>Buttons <span class="mt-opt" style="font-size:13px;font-weight:400">· Optional</span></h2>
                <p class="mt-desc">Create buttons that let customers respond to your message or take action. You can add up to three buttons.</p>
                <div id="std_buttons_container"></div>
                <div class="mt-dd" id="mtDd">
                    <button type="button" class="mt-btn" id="mtAddBtn" aria-haspopup="true" aria-expanded="false">+ Add button &#9662;</button>
                    <div class="mt-menu" id="mtMenu" role="menu">
                        <button type="button" role="menuitem" onclick="addStdButton('quick_reply');mtMenu(false)">Quick reply</button>
                        <button type="button" role="menuitem" onclick="addStdButton('url');mtMenu(false)">Visit website</button>
                        <button type="button" role="menuitem" onclick="addStdButton('phone');mtMenu(false)">Call phone number</button>
                        <?php if (has_feature('whatsapp_flows')): ?>
                        <button type="button" role="menuitem" onclick="addStdButton('flow');mtMenu(false)">Open form (WhatsApp Flow)</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Carousel Cards Builder Block -->
            <div id="block_carousel_cards" class="mt-card" style="display:none;">
                <h2>Carousel cards <span class="mt-opt" style="font-size:13px;font-weight:400">· 2 to 10 cards</span></h2>
                <p class="mt-desc">Customers swipe left and right between cards. Each card has its own picture or video, text and buttons. Use the intro message above for the text that appears before the cards.</p>
                <div class="mt-row2" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
                    <div class="mt-f">
                        <label for="car_media">Card media</label>
                        <select name="carousel_media_type" id="car_media" class="form-control" onchange="carMediaChange()">
                            <option value="image">Image (JPG or PNG, up to 5 MB)</option>
                            <option value="video">Video (MP4, up to 16 MB)</option>
                        </select>
                    </div>
                    <div class="mt-f">
                        <label for="car_btnlayout">Buttons on every card</label>
                        <select id="car_btnlayout" class="form-control" onchange="carLayoutChange()">
                            <option value="">No buttons</option>
                            <option value="quick_reply">1 button · Quick reply</option>
                            <option value="url">1 button · Visit website</option>
                            <option value="quick_reply,quick_reply">2 buttons · Quick reply + Quick reply</option>
                            <option value="quick_reply,url">2 buttons · Quick reply + Visit website</option>
                            <option value="url,url">2 buttons · Visit website + Visit website</option>
                        </select>
                    </div>
                </div>
                <div class="car-tip"><b>Meta's rules:</b> every card must use the same media type and the same buttons. Keep all pictures the same shape (all square or all wide). Card text can be up to 160 characters and button labels up to 25.</div>
                <div class="mt-alert" id="car_err" role="alert"></div>
                <div id="carousel_container"></div>
                <button type="button" class="mt-btn" id="addCardBtn" onclick="addCard()" style="align-self:flex-start">+ Add card</button>
            </div>

            <div class="mt-actions">
                <span class="said">Meta reviews every template before it can be sent.</span>
                <a href="templates.php" class="mt-btn">Previous</a>
                <button type="submit" class="mt-btn primary">Submit for Review</button>
            </div>
        </form>
    </div>

    <!-- RIGHT: LIVE PREVIEW -->
    <aside class="mt-card mt-side" aria-label="Template preview">
        <h3>Template preview</h3>
        <div class="mt-wall">
            <div class="mt-chat">
                <div class="mt-msg">
                    <div id="prev_std_media" class="mt-media">
                        <img id="prev_std_img" src="" alt="">
                        <div id="prev_std_icon" class="ico"></div>
                    </div>
                    <div class="pad">
                        <div id="prev_std_header" class="mt-bhead"></div>
                        <div id="prev_body" class="mt-bbody">Hello</div>
                        <div id="prev_footer" class="mt-bfoot"></div>
                        <div class="mt-meta">12:45</div>
                    </div>
                    <div id="prev_std_btns" class="mt-bbtns"></div>
                </div>
                <div id="prev_carousel_scroller" style="display:none;gap:8px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;max-width:100%;padding-bottom:6px;"></div>
            </div>
        </div>
        <p class="mt-fine">This is how your message will look on WhatsApp. Variables like {{1}} show as [x] until you send.</p>
    </aside>

</div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
const BTN_ICONS = {
    quick_reply: '<svg viewBox="0 0 24 24"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>',
    url: '<svg viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
    phone: '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    flow: '<svg viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'
};
const WA_FLOWS = <?php echo json_encode(array_map(fn($f) => $f['name'], $waFlows), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT); ?>;
const CAT_ICONS = {
    marketing: '<svg viewBox="0 0 24 24"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg>',
    utility: '<svg viewBox="0 0 24 24"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>',
    authentication: '<svg viewBox="0 0 24 24"><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>'
};
function setCount(id, val, max) {
    const el = document.getElementById(id); if (!el) return;
    el.textContent = val + '/' + max;
    el.classList.toggle('over', val > max);
}
function updateCounts() {
    const v = id => (document.getElementById(id) || {value: ''}).value.length;
    setCount('cnt_name', v('tpl_name'), 512);
    setCount('cnt_h', v('h_text'), 60);
    setCount('cnt_body', v('tpl_body'), 1024);
    setCount('cnt_foot', v('tpl_footer'), 60);
}
function updateHead() {
    const name = (document.getElementById('tpl_name').value || 'your_template_name');
    const lang = document.getElementById('tpl_lang');
    const cat = document.getElementById('tpl_cat');
    const fmt = document.querySelector('input[name="template_format"]:checked').value;
    const catKey = cat.value;
    const catLabel = cat.options[cat.selectedIndex].text.split(' (')[0];
    document.getElementById('mtTitle').textContent = name + ' • ' + lang.options[lang.selectedIndex].text;
    document.getElementById('mtSub').textContent = catLabel + ' · ' + (fmt === 'carousel' ? 'Carousel' : 'Default');
    document.getElementById('mtIco').innerHTML = CAT_ICONS[catKey] || CAT_ICONS.marketing;
}
function mtMenu(open) {
    document.getElementById('mtMenu').classList.toggle('open', open);
    document.getElementById('mtAddBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
}
let stdBtnCount = 0;
let cardCount = 0;
let stdMediaUrl = '';
let cardMediaUrls = {}; // map card id to local url

function toggleFormat() {
    const format = document.querySelector('input[name="template_format"]:checked').value;
    const stdHeader = document.getElementById('block_standard_header');
    const stdBtns = document.getElementById('block_standard_buttons');
    const carouselBlock = document.getElementById('block_carousel_cards');
    const bodyTitle = document.getElementById('title_body');
    
    if (format === 'carousel') {
        stdHeader.style.display = 'none';
        stdBtns.style.display = 'none';
        carouselBlock.style.display = 'flex';
        bodyTitle.innerText = "Intro body message";
        const ftr = document.getElementById('block_footer');
        ftr.style.display = 'none';
        Array.from(ftr.querySelectorAll('input')).forEach(el => el.disabled = true);
        
        // Disable header inputs to prevent invisible HTML5 validation blocking
        Array.from(stdHeader.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = true);
        Array.from(stdBtns.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = true);
        // Enable carousel inputs
        Array.from(carouselBlock.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = false);
        
        while (cardCount < 2) addCard();
    } else {
        stdHeader.style.display = '';
        stdBtns.style.display = '';
        carouselBlock.style.display = 'none';
        bodyTitle.innerText = "Body";
        const ftr2 = document.getElementById('block_footer');
        ftr2.style.display = '';
        Array.from(ftr2.querySelectorAll('input')).forEach(el => el.disabled = false);
        
        // Enable header inputs
        Array.from(stdHeader.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = false);
        Array.from(stdBtns.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = false);
        // Disable carousel inputs to prevent invisible HTML5 validation blocking
        Array.from(carouselBlock.querySelectorAll('input, select, textarea')).forEach(el => el.disabled = true);
    }
    updatePreview();
}

function toggleStandardHeader() {
    const type = document.querySelector('input[name="header_type"]:checked').value;
    const txtWrap = document.getElementById('h_text_wrap');
    const mediaWrap = document.getElementById('h_media_wrap');
    const file = document.getElementById('h_file');
    const link = document.getElementById('h_link');
    
    txtWrap.style.display = 'none';
    mediaWrap.style.display = 'none';
    stdMediaUrl = '';
    
    if (type === 'text') {
        txtWrap.style.display = 'block';
    } else if (type !== 'none') {
        mediaWrap.style.display = 'block';
        if (type === 'location') {
            file.style.display = 'none';
            link.style.display = 'block';
        } else {
            file.style.display = 'block';
            link.style.display = 'none';
        }
    }
    updatePreview();
}

function handleStandardUpload(input) {
    if (input.files && input.files[0]) {
        stdMediaUrl = URL.createObjectURL(input.files[0]);
        updatePreview();
    }
}

// ----- Standard Buttons Logic -----
function addStdButton(type) {
    if (stdBtnCount >= 3) return alert("Max 3 buttons");
    const container = document.getElementById('std_buttons_container');
    const id = Date.now();
    const isVal = type !== 'quick_reply';
    let valueField = `<input type="text" name="btn_value[]" class="form-control" placeholder="Value/Link" style="flex:1;${isVal?'':'display:none;'}" oninput="updatePreview()" ${isVal?'required':''}>`;
    if (type === 'flow') {
        if (document.querySelector('#std_buttons_container input[name="btn_type[]"][value="flow"]')) return alert('A template can have only one Open form button.');
        const ids = Object.keys(WA_FLOWS);
        if (!ids.length) return alert('No published WhatsApp forms yet. Publish one under Premium Growth → WA Native Forms first.');
        const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
        valueField = '<select name="btn_value[]" class="form-control" style="flex:1;" required>' +
            ids.map(fid => '<option value="' + esc(fid) + '">' + esc(WA_FLOWS[fid]) + '</option>').join('') + '</select>';
    }

    const html = `
        <div id="sbtn_${id}" class="mt-brow">
            <input type="hidden" name="btn_type[]" value="${type}">
            <div class="kind">${type === 'flow' ? 'open form' : type.replace('_',' ')}</div>
            <input type="text" name="btn_text[]" class="form-control" placeholder="Text" maxlength="25" style="flex:1;" oninput="updatePreview()" required>
            ${valueField}
            <button type="button" class="mt-btn sm danger" onclick="document.getElementById('sbtn_${id}').remove(); stdBtnCount--; updatePreview();"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    stdBtnCount++;
    updatePreview();
}

// Coming from WA Native Forms ("Create template for this form"): add the Open form button pre-selected.
document.addEventListener('DOMContentLoaded', () => {
    const fid = new URLSearchParams(location.search).get('flow');
    if (!fid || !WA_FLOWS[fid] || document.querySelector('#std_buttons_container input[name="btn_type[]"][value="flow"]')) return;
    addStdButton('flow');
    const row = document.querySelector('#std_buttons_container > div:last-child');
    if (!row) return;
    row.querySelector('select[name="btn_value[]"]').value = fid;
    row.querySelector('input[name="btn_text[]"]').value = 'Fill the form';
    updatePreview();
});

// ----- Carousel Logic -----
let cardSeq = 0;
const CAR_MAX = 10, CAR_MIN = 2;
function carMedia() { return document.getElementById('car_media').value; }
function carLayout() { const v = document.getElementById('car_btnlayout').value; return v ? v.split(',') : []; }
function carCards() { return Array.from(document.querySelectorAll('#carousel_container .card-box')); }
function carAccept() { return carMedia() === 'video' ? '.mp4,video/mp4' : '.jpg,.jpeg,.png,image/jpeg,image/png'; }
function carBtnRows(id, old) {
    return carLayout().map((type, i) => {
        const isUrl = type === 'url';
        const o = (old && old[i]) || {t: '', v: ''};
        return '<div class="car-bt">' +
            '<input type="hidden" name="cbtn_type_' + id + '[]" value="' + type + '">' +
            '<span class="kind">' + (isUrl ? 'Website' : 'Quick reply') + '</span>' +
            '<input type="text" name="cbtn_text_' + id + '[]" class="form-control" placeholder="Button text" maxlength="25" style="flex:1;min-width:0" value="' + o.t.replace(/"/g, '&quot;') + '" oninput="updatePreview()" required>' +
            (isUrl
                ? '<input type="url" name="cbtn_val_' + id + '[]" class="form-control" placeholder="https://example.com" style="flex:1.3;min-width:0" value="' + o.v.replace(/"/g, '&quot;') + '" required>'
                : '<input type="hidden" name="cbtn_val_' + id + '[]" value="">') +
            '</div>';
    }).join('');
}
function addCard() {
    if (cardCount >= CAR_MAX) return;
    const container = document.getElementById('carousel_container');
    const id = ++cardSeq;
    const html = '<div id="card_' + id + '" class="card-box" data-id="' + id + '">' +
        '<input type="hidden" name="card_index[]" value="' + id + '">' +
        '<div class="car-head"><strong class="card-no">Card</strong>' +
        '<button type="button" class="mt-btn sm danger" onclick="removeCard(' + id + ')">Delete card</button></div>' +
        '<input type="file" name="card_file_' + id + '" class="form-control" accept="' + carAccept() + '" onchange="handleCardUpload(this, ' + id + ')" required>' +
        '<div><textarea name="card_body[' + id + ']" class="form-control" rows="2" maxlength="160" placeholder="Card text (up to 160 characters)" style="width:100%;resize:vertical" oninput="updatePreview()" required></textarea>' +
        '<div class="car-fcount" id="cc_' + id + '">0/160</div></div>' +
        '<div id="cbtns_wrap_' + id + '">' + carBtnRows(id, null) + '</div>' +
        '</div>';
    container.insertAdjacentHTML('beforeend', html);
    cardCount++;
    renumberCards();
    updatePreview();
}
function removeCard(id) {
    if (cardCount <= CAR_MIN) { carError('A carousel needs at least ' + CAR_MIN + ' cards.'); return; }
    const el = document.getElementById('card_' + id);
    if (el) el.remove();
    cardCount--;
    delete cardMediaUrls[id];
    carError('');
    renumberCards();
    updatePreview();
}
function renumberCards() {
    carCards().forEach((c, i) => { c.querySelector('.card-no').textContent = 'Card ' + (i + 1); });
    const add = document.getElementById('addCardBtn');
    if (add) { add.disabled = cardCount >= CAR_MAX; add.textContent = cardCount >= CAR_MAX ? 'Maximum 10 cards' : '+ Add card'; }
}
function handleCardUpload(input, id) {
    carError('');
    if (input.files && input.files[0]) {
        const f = input.files[0];
        const max = (carMedia() === 'video' ? 16 : 5) * 1024 * 1024;
        const ok = carMedia() === 'video' ? /\.mp4$/i.test(f.name) : /\.(jpe?g|png)$/i.test(f.name);
        if (!ok || f.size > max) {
            carError('Card ' + (carCards().findIndex(c => c.dataset.id == id) + 1) + ': ' + (!ok ? 'please choose a ' + (carMedia() === 'video' ? 'MP4 video.' : 'JPG or PNG image.') : 'that file is too large (max ' + (max / 1048576) + ' MB).'));
            input.value = ''; delete cardMediaUrls[id]; updatePreview(); return;
        }
        cardMediaUrls[id] = URL.createObjectURL(f);
    } else { delete cardMediaUrls[id]; }
    updatePreview();
}
function carMediaChange() {
    carCards().forEach(c => {
        const f = c.querySelector('input[type="file"]'); f.value = ''; f.accept = carAccept();
        delete cardMediaUrls[c.dataset.id];
    });
    updatePreview();
}
function carLayoutChange() {
    carCards().forEach(c => {
        const id = c.dataset.id;
        const old = Array.from(c.querySelectorAll('.car-bt')).map(r => ({
            t: (r.querySelector('input[name^="cbtn_text_"]') || {value: ''}).value,
            v: (r.querySelector('input[type="url"]') || {value: ''}).value
        }));
        c.querySelector('#cbtns_wrap_' + id).innerHTML = carBtnRows(id, old);
    });
    updatePreview();
}
function carError(msg) {
    const e = document.getElementById('car_err');
    e.textContent = msg; e.style.display = msg ? 'block' : 'none';
    if (msg) e.scrollIntoView({behavior: 'smooth', block: 'center'});
}
document.getElementById('templateForm').addEventListener('submit', function (e) {
    if (document.querySelector('input[name="template_format"]:checked').value !== 'carousel') return;
    const cards = carCards();
    let msg = '';
    if (cards.length < CAR_MIN) msg = 'A carousel needs at least ' + CAR_MIN + ' cards.';
    else cards.forEach((c, i) => {
        if (msg) return;
        const f = c.querySelector('input[type="file"]');
        if (!f.files || !f.files[0]) msg = 'Card ' + (i + 1) + ' needs ' + (carMedia() === 'video' ? 'a video' : 'an image') + '.';
        else if (!c.querySelector('textarea').value.trim()) msg = 'Card ' + (i + 1) + ' needs text.';
    });
    if (msg) { e.preventDefault(); carError(msg); }
});

// ----- formatting helpers -----
function formatText(char) {
    const el = document.getElementById('tpl_body');
    const start = el.selectionStart, end = el.selectionEnd, text = el.value;
    el.value = (start === end) 
        ? text.substring(0,start)+char+char+text.substring(end) 
        : text.substring(0,start)+char+text.substring(start,end)+char+text.substring(end);
    el.focus(); updatePreview();
}
function formatCode() {
    const el = document.getElementById('tpl_body');
    const start = el.selectionStart, end = el.selectionEnd, text = el.value;
    const fence = '`';
    el.value = (start === end)
        ? text.substring(0,start)+fence+fence+text.substring(end)
        : text.substring(0,start)+fence+text.substring(start,end)+fence+text.substring(end);
    el.focus(); updatePreview();
}
function insertVariable() {
    const el = document.getElementById('tpl_body');
    const start = el.selectionStart, text = el.value;
    const matches = text.match(/\{\{(\d+)\}\}/g);
    let nextVar = 1;
    if (matches) { let m=0; matches.forEach(x=>{ const n=parseInt(x.replace(/\D/g,'')); if(n>m)m=n;}); nextVar=m+1; }
    el.value = text.substring(0,start)+`{{${nextVar}}}`+text.substring(start);
    el.focus(); updatePreview();
}

function waMarkdownToHtml(text) {
    if(!text) return '';
    let s = text.replace(/</g,"&lt;").replace(/>/g,"&gt;");
    s = s.replace(/\{\{(\d+)\}\}/g, '<span style="color:#00a884;">[x]</span>');
    s = s.replace(/\*(.*?)\*/g, "<strong>$1</strong>");
    s = s.replace(/_(.*?)_/g, "<em>$1</em>");
    s = s.replace(/~(.*?)~/g, "<del>$1</del>");
    return s.replace(/\n/g, '<br>');
}

// ----- SUPER PREVIEW ENGINE -----
function updatePreview() {
    const format = document.querySelector('input[name="template_format"]:checked').value;
    updateCounts(); updateHead();
    
    const pBody = document.getElementById('prev_body');
    const pFoot = document.getElementById('prev_footer');
    
    // Top-Level Body/Foot
    pBody.innerHTML = waMarkdownToHtml(document.getElementById('tpl_body').value) || '<span style="color:#8696a0">Hello</span>';
    const footVal = document.getElementById('tpl_footer').value;
    if(footVal) { pFoot.textContent = footVal; pFoot.style.display='block'; } else { pFoot.style.display='none'; }
    
    const sHdr = document.getElementById('prev_std_header');
    const sMed = document.getElementById('prev_std_media');
    const sImg = document.getElementById('prev_std_img');
    const sIco = document.getElementById('prev_std_icon');
    const sBtns = document.getElementById('prev_std_btns');
    const cScroll = document.getElementById('prev_carousel_scroller');
    
    if (format === 'header') {
        cScroll.style.display = 'none';
        sBtns.style.display = 'flex';
        
        const hType = document.querySelector('input[name="header_type"]:checked').value;
        sHdr.style.display='none'; sMed.style.display='none'; sImg.style.display='none'; sIco.style.display='none';
        
        if (hType === 'text') {
            sHdr.innerHTML = waMarkdownToHtml(document.getElementById('h_text').value);
            if(sHdr.innerHTML) sHdr.style.display='block';
        } else if (hType !== 'none') {
            sMed.style.display = 'flex';
            if (stdMediaUrl && (hType==='image'||hType==='video')) { sImg.src = stdMediaUrl; sImg.style.display='block'; }
            else { sIco.innerHTML = hType==='document'?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>':(hType==='location'?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'); sIco.style.display='block'; }
        }
        
        // Render Std Buttons (Dark WA Theme)
        sBtns.innerHTML = '';
        document.querySelectorAll('#std_buttons_container > div').forEach(row => {
            const t = (row.querySelector('input[name="btn_text[]"]') || {}).value || 'Button';
            const ty = (row.querySelector('input[name="btn_type[]"]') || {}).value || 'quick_reply';
            sBtns.insertAdjacentHTML('beforeend', '<div class="mt-bbtn">' + (BTN_ICONS[ty] || BTN_ICONS.quick_reply) + t.replace(/</g,"&lt;") + '</div>');
        });
        
    } else {
        // Carousel Mode
        sHdr.style.display='none'; sMed.style.display='none'; sBtns.style.display='none';
        cScroll.style.display = 'flex';
        cScroll.innerHTML = '';
        
        const isVideo = carMedia() === 'video';
        carCards().forEach(c => {
            const cId = c.dataset.id;
            const cBody = c.querySelector('textarea').value;
            const cc = document.getElementById('cc_' + cId);
            if (cc) { cc.textContent = cBody.length + '/160'; cc.classList.toggle('over', cBody.length > 160); }

            const mediaUrl = cardMediaUrls[cId];
            let mediaHtml = '<div class="cm"><svg viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></div>';
            if (mediaUrl) mediaHtml = isVideo ? '<video src="' + mediaUrl + '" muted playsinline></video>' : '<img src="' + mediaUrl + '" alt="">';

            let cBtnsHtml = '<div class="cb">';
            c.querySelectorAll('input[name^="cbtn_text_"]').forEach(txt => {
                cBtnsHtml += '<div>' + (txt.value || 'Button').replace(/</g, "&lt;") + '</div>';
            });
            cBtnsHtml += '</div>';

            cScroll.insertAdjacentHTML('beforeend', '<div class="mt-ccard">' + mediaHtml + '<div class="ct">' + (waMarkdownToHtml(cBody) || '<i>Card text...</i>') + '</div>' + cBtnsHtml + '</div>');
        });
    }
}

// add-button menu
document.getElementById('mtAddBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    mtMenu(!document.getElementById('mtMenu').classList.contains('open'));
});
document.addEventListener('click', function (e) { if (!e.target.closest('#mtDd')) mtMenu(false); });

// init
toggleFormat();
updateCounts();
// "Edit" from the Templates list: fill the form with the template's current content
(function () {
    const P = <?php echo json_encode($tpPrefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (!P) return;
    document.addEventListener('DOMContentLoaded', function () {
        const set = (id, v) => { const el = document.getElementById(id); if (el && v != null) el.value = v; };
        set('tpl_name', P.name);
        set('tpl_cat', P.category);
        set('tpl_lang', P.language);
        set('tpl_body', P.body);
        set('tpl_footer', P.footer);
        const hr = document.querySelector('input[name="header_type"][value="' + (P.header_type || 'none') + '"]');
        if (hr) { hr.checked = true; if (typeof toggleStandardHeader === 'function') toggleStandardHeader(); }
        set('h_text', P.header_text);
        (P.buttons || []).forEach(function (b) {
            if (b.type === 'flow' && (typeof WA_FLOWS === 'undefined' || !WA_FLOWS[b.value])) return;
            if (typeof addStdButton !== 'function') return;
            const before = document.querySelectorAll('#std_buttons_container .mt-brow').length;
            addStdButton(b.type);
            const rows = document.querySelectorAll('#std_buttons_container .mt-brow');
            if (rows.length === before) return;
            const row = rows[rows.length - 1];
            const t = row.querySelector('input[name="btn_text[]"]'), v = row.querySelector('[name="btn_value[]"]');
            if (t) t.value = b.text || '';
            if (v && b.value) v.value = b.value;
        });
        if (typeof updatePreview === 'function') updatePreview();
    });
})();
</script>

<?php include '../includes/client_footer.php'; ?>
