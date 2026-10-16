<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
require_once '../includes/bot_triggers.php';

if (!has_feature('bot_replies')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Bot Replies';
$currentPage = 'bot_replies';

// Trigger Types (Welcome, Is, Starts with, ... Stop AI bot) need match_type to be a free string
bt_ensure_schema($conn);
$triggerTypes = bt_trigger_types();

/* ── Schema self-heal: older installs lack columns this page writes (media, buttons, template...) ── */
$brCols = [];
$cr = $conn->query("SHOW COLUMNS FROM bot_replies");
while ($cr && ($row = $cr->fetch_assoc())) { $brCols[strtolower((string)$row['Field'])] = (string)$row['Type']; }
$brWanted = [
    'media_url'        => "VARCHAR(500) DEFAULT NULL",
    'header_type'      => "VARCHAR(20) DEFAULT 'none'",
    'footer_text'      => "VARCHAR(100) DEFAULT NULL",
    'template_id'      => "INT DEFAULT NULL",
    'interactive_data' => "TEXT DEFAULT NULL",
];
foreach ($brWanted as $c => $def) {
    if (isset($brCols[$c])) continue;
    try { $conn->query("ALTER TABLE bot_replies ADD COLUMN {$c} {$def}"); $brCols[$c] = 'x'; } catch (Throwable $e) { error_log('[WAAMINI bot_replies schema] ' . $e->getMessage()); }
}
if (isset($brCols['reply_type']) && stripos($brCols['reply_type'], 'interactive') === false && stripos($brCols['reply_type'], 'enum') !== false) {
    try { $conn->query("ALTER TABLE bot_replies MODIFY COLUMN reply_type ENUM('text','template','media','interactive') DEFAULT 'text'"); } catch (Throwable $e) { error_log('[WAAMINI bot_replies enum] ' . $e->getMessage()); }
}

/* ── Templates the bot can send (with text, so the preview can show the real message) ── */
$availableTemplates = [];
$st_tpl = $conn->prepare("SELECT id, name, body, body_text, header_text, footer, wa_status FROM message_templates WHERE company_id=? AND wa_status IN ('APPROVED','PENDING') ORDER BY name ASC");
if ($st_tpl) {
    $st_tpl->bind_param("i", $companyId);
    $st_tpl->execute();
    $tplRes = $st_tpl->get_result();
    while ($tplRes && ($row = $tplRes->fetch_assoc())) {
        $row['text'] = (string)(($row['body'] ?? '') !== '' ? $row['body'] : ($row['body_text'] ?? ''));
        $availableTemplates[] = $row;
    }
}
$tplIds = array_map(static fn($t) => (int)$t['id'], $availableTemplates);

$strlen = static function (string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); };
$allowedFilterKeys = ['q', 'type', 'state', 'p'];
$backUrl = static function (string $raw) use ($allowedFilterKeys): string {
    parse_str($raw, $in);
    $out = [];
    foreach ($allowedFilterKeys as $k) { if (isset($in[$k]) && is_scalar($in[$k]) && (string)$in[$k] !== '') $out[$k] = (string)$in[$k]; }
    return 'bot_replies.php' . ($out ? '?' . http_build_query($out) : '');
};

/* ── Rule counts / plan limit ── */
$cs = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(is_active=1),0) AS a FROM bot_replies WHERE company_id=?");
$cs->bind_param("i", $companyId);
$cs->execute();
$cRow = $cs->get_result()->fetch_assoc() ?: [];
$totalRules  = (int)($cRow['c'] ?? 0);
$activeRules = (int)($cRow['a'] ?? 0);
$ruleLimit   = (int)get_limit('bot_replies');
$atLimit     = $ruleLimit > 0 && $totalRules >= $ruleLimit;

$formError = '';
$restore = null;

/* ── Save (add / edit) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rule'])) {
    csrf_verify();
    $back = $backUrl((string)($_POST['back'] ?? ''));
    if ($viewOnlyMode) { header("Location: bot_replies.php?msg=expired_block"); exit(); }

    $id          = intval($_POST['rule_id'] ?? 0);
    $matchType   = array_key_exists((string)($_POST['match_type'] ?? ''), $triggerTypes) ? (string)$_POST['match_type'] : 'contains';
    $replyType   = in_array(($_POST['reply_type'] ?? ''), ['text', 'template', 'media', 'interactive'], true) ? $_POST['reply_type'] : 'text';
    $replyContent = trim((string)($_POST['reply_content'] ?? ''));
    $mediaUrl    = trim((string)($_POST['media_url'] ?? ''));
    $headerType  = in_array(($_POST['header_type'] ?? ''), ['none', 'image', 'video', 'document'], true) ? $_POST['header_type'] : 'none';
    $footerText  = trim((string)($_POST['footer_text'] ?? ''));
    $templateId  = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

    // keywords: comma separated, trimmed, unique, bounded
    $kwList = [];
    foreach (explode(',', (string)($_POST['keyword'] ?? '')) as $k) {
        $k = trim($k);
        if ($k === '') continue;
        $key = function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k);
        if (!isset($kwList[$key])) $kwList[$key] = $k;
    }
    $kwList = array_values($kwList);
    $keyword = implode(', ', $kwList);
    // A Welcome rule has no keywords: it is sent to a customer's first message
    if ($matchType === 'welcome') { $kwList = ['[welcome]']; $keyword = '[welcome]'; }

    $btns = [];
    if ($replyType === 'interactive') {
        // A button keeps its id when the rule is edited (even if the label changes): WhatsApp sends the id back when a
        // customer taps it, and the Flow Builder connections hang on it.
        $slotIds = [];
        if ($id > 0) {
            $ob = $conn->prepare("SELECT interactive_data FROM bot_replies WHERE id=? AND company_id=?");
            if ($ob) {
                $ob->bind_param("ii", $id, $companyId); $ob->execute();
                $od = json_decode((string)($ob->get_result()->fetch_assoc()['interactive_data'] ?? ''), true);
                foreach ((array)($od['buttons'] ?? []) as $oldB) { if (preg_match('/^btn_(\d)_/', (string)($oldB['id'] ?? ''), $mm)) $slotIds[(int)$mm[1]] = (string)$oldB['id']; }
                $ob->close();
            }
        }
        for ($i = 1; $i <= 3; $i++) {
            $bt = trim((string)($_POST["btn_{$i}_text"] ?? ''));
            if ($bt !== '') $btns[] = ['id' => $slotIds[$i] ?? ('btn_' . $i . '_' . substr(bin2hex(random_bytes(4)), 0, 8)), 'text' => $bt];
        }
    }

    if ($id === 0 && $atLimit) { $formError = "You have reached your plan limit of {$ruleLimit} rules. Delete an old rule or upgrade your plan."; }
    elseif (!$kwList) { $formError = 'Add at least one keyword that should trigger this reply.'; }
    elseif (count($kwList) > 20) { $formError = 'Use at most 20 keywords per rule.'; }
    elseif ($strlen($keyword) > 255) { $formError = 'Your keywords are too long. Keep them under 255 characters in total.'; }
    elseif (in_array(true, array_map(static fn($k) => $strlen($k) > 60, $kwList), true)) { $formError = 'Each keyword can be at most 60 characters.'; }
    elseif ($replyType === 'template' && (!$templateId || !in_array($templateId, $tplIds, true))) { $formError = 'Choose one of your templates for this reply.'; }
    elseif (in_array($replyType, ['text', 'interactive'], true) && $replyContent === '') { $formError = 'Write the message the bot should send.'; }
    elseif ($replyType === 'text' && $strlen($replyContent) > 4096) { $formError = 'A text reply can be at most 4096 characters.'; }
    elseif (in_array($replyType, ['interactive', 'media'], true) && $strlen($replyContent) > 1024) { $formError = 'This reply can be at most 1024 characters.'; }
    elseif ($replyType === 'interactive' && !$btns) { $formError = 'Add at least one button label.'; }
    elseif ($replyType === 'interactive' && in_array(true, array_map(static fn($b) => $strlen($b['text']) > 20, $btns), true)) { $formError = 'Button labels can be at most 20 characters.'; }
    elseif ($replyType === 'media' && ($headerType === 'none')) { $formError = 'Choose the media type (image, video or document).'; }
    elseif ($replyType === 'media' && !preg_match('#^https?://[^\s]{4,490}$#i', $mediaUrl)) { $formError = 'Enter a valid media link starting with https://'; }
    elseif ($strlen($footerText) > 60) { $formError = 'Footer can be at most 60 characters.'; }

    if (!$formError) {
        // Same keywords + same match type already used by another rule?
        $dup = $conn->prepare("SELECT id FROM bot_replies WHERE company_id=? AND id<>? AND LOWER(keyword)=LOWER(?) AND match_type=? LIMIT 1");
        if ($dup) {
            $dup->bind_param("iiss", $companyId, $id, $keyword, $matchType);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) $formError = $matchType === 'welcome' ? 'You already have a Welcome rule. Edit that one instead.' : 'Another rule already uses these exact keywords with the same trigger type.';
            $dup->close();
        }
    }

    if (!$formError) {
        $interactiveData = $replyType === 'interactive' ? json_encode(['type' => 'button', 'buttons' => $btns]) : null;
        if ($replyType !== 'media') { $mediaUrl = ''; $headerType = 'none'; }
        if ($replyType === 'template') { $replyContent = ''; }
        if (!in_array($replyType, ['interactive', 'media'], true)) { $footerText = ''; }
        if ($replyType !== 'template') { $templateId = null; }

        $vals = [
            'keyword' => $keyword, 'trigger_keyword' => $keyword, 'match_type' => $matchType, 'reply_type' => $replyType,
            'reply_content' => $replyContent, 'reply_message' => $replyContent, 'media_url' => $mediaUrl, 'header_type' => $headerType,
            'footer_text' => $footerText, 'template_id' => $templateId, 'interactive_data' => $interactiveData,
        ];
        $vals = array_filter($vals, static fn($k) => isset($brCols[$k]), ARRAY_FILTER_USE_KEY);
        $typeOf = static fn($c) => $c === 'template_id' ? 'i' : 's';

        if ($id > 0) {
            $set = implode(', ', array_map(static fn($c) => "$c=?", array_keys($vals)));
            $types = implode('', array_map($typeOf, array_keys($vals))) . 'ii';
            $bind = array_merge(array_values($vals), [$id, $companyId]);
            $stmt = $conn->prepare("UPDATE bot_replies SET $set WHERE id=? AND company_id=?");
        } else {
            $cols = array_merge(['company_id'], array_keys($vals), ['is_active']);
            $types = 'i' . implode('', array_map($typeOf, array_keys($vals))) . 'i';
            $bind = array_merge([$companyId], array_values($vals), [1]);
            $stmt = $conn->prepare("INSERT INTO bot_replies (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
        }
        if ($stmt) {
            $stmt->bind_param($types, ...$bind);
            if ($stmt->execute()) {
                // keep Flow Builder nodes that are linked to this Bot Reply in step with its text and button labels
                try { require_once __DIR__ . '/../includes/flow/schema.php'; require_once __DIR__ . '/../includes/flow/nodes.php'; require_once __DIR__ . '/../includes/flow/botreply.php'; flow_ensure_schema($conn); flow_botreply_push($conn, (int)$companyId, $id > 0 ? $id : (int)$conn->insert_id); } catch (Throwable $syncE) { error_log('[WAAMINI flow sync] ' . $syncE->getMessage()); }
                header("Location: " . $back . (strpos($back, '?') === false ? '?' : '&') . "msg=saved"); exit();
            }
            error_log('[WAAMINI bot_replies save] ' . $stmt->error);
        } else {
            error_log('[WAAMINI bot_replies prepare] ' . $conn->error);
        }
        $formError = 'Could not save the rule. Please try again.';
    }

    $restore = [
        'id' => $id, 'keyword' => $keyword, 'match_type' => $matchType, 'reply_type' => $replyType, 'reply_content' => $replyContent,
        'media_url' => $mediaUrl, 'header_type' => $headerType, 'footer_text' => $footerText, 'template_id' => $templateId,
        'buttons' => array_map(static fn($b) => $b['text'], $btns),
    ];
}

/* ── Delete ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_verify();
    if ($viewOnlyMode) { header("Location: bot_replies.php?msg=expired_block"); exit(); }
    $did = intval($_POST['delete_id']);
    $stmt = $conn->prepare("DELETE FROM bot_replies WHERE id=? AND company_id=?");
    if ($stmt) { $stmt->bind_param("ii", $did, $companyId); $stmt->execute(); }
    $back = $backUrl((string)($_POST['back'] ?? ''));
    header("Location: " . $back . (strpos($back, '?') === false ? '?' : '&') . "msg=deleted");
    exit();
}

/* ── Pause / resume ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    csrf_verify();
    if ($viewOnlyMode) { header("Location: bot_replies.php?msg=expired_block"); exit(); }
    $tid = intval($_POST['toggle_id'] ?? 0);
    $newState = intval($_POST['new_state'] ?? 0) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE bot_replies SET is_active=? WHERE id=? AND company_id=?");
    if ($stmt) { $stmt->bind_param("iii", $newState, $tid, $companyId); $stmt->execute(); }
    $back = $backUrl((string)($_POST['back'] ?? ''));
    header("Location: " . $back . (strpos($back, '?') === false ? '?' : '&') . "msg=" . ($newState ? 'resumed' : 'paused'));
    exit();
}

/* ── List: search, filters, pagination ── */
$perPage = 12;
$page    = max(1, intval($_GET['p'] ?? 1));
$search  = trim((string)($_GET['q'] ?? ''));
$typeF   = in_array(($_GET['type'] ?? ''), ['text', 'media', 'interactive', 'template'], true) ? $_GET['type'] : '';
$stateF  = in_array(($_GET['state'] ?? ''), ['active', 'paused'], true) ? $_GET['state'] : '';

$where = "r.company_id=?"; $types = 'i'; $params = [$companyId];
if ($search !== '') { $like = '%' . $search . '%'; $where .= " AND (r.keyword LIKE ? OR r.reply_content LIKE ?)"; $types .= 'ss'; array_push($params, $like, $like); }
if ($typeF !== '')  { $where .= " AND r.reply_type=?"; $types .= 's'; $params[] = $typeF; }
if ($stateF !== '') { $where .= " AND r.is_active=?"; $types .= 'i'; $params[] = $stateF === 'active' ? 1 : 0; }

$cnt = $conn->prepare("SELECT COUNT(*) c FROM bot_replies r WHERE $where");
$cnt->bind_param($types, ...$params);
$cnt->execute();
$matching = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int)ceil($matching / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$tplJoin = isset($brCols['template_id']) ? "LEFT JOIN message_templates t ON r.template_id = t.id" : "";
$tplSel  = isset($brCols['template_id']) ? ", t.name AS template_name" : ", NULL AS template_name";
$ls = $conn->prepare("SELECT r.*{$tplSel} FROM bot_replies r {$tplJoin} WHERE $where ORDER BY r.id DESC LIMIT ? OFFSET ?");
$lp = array_merge($params, [$perPage, $offset]);
$ls->bind_param($types . 'ii', ...$lp);
$ls->execute();
$rules = [];
$lr = $ls->get_result();
while ($lr && ($row = $lr->fetch_assoc())) { $rules[] = $row; }

// every rule's keywords (for the duplicate hint inside the editor)
$allRules = [];
$ar = $conn->prepare("SELECT id, keyword, match_type FROM bot_replies WHERE company_id=? ORDER BY id DESC LIMIT 1000");
if ($ar) { $ar->bind_param("i", $companyId); $ar->execute(); $arr = $ar->get_result(); while ($arr && ($row = $arr->fetch_assoc())) { $allRules[] = ['id' => (int)$row['id'], 'k' => (string)$row['keyword'], 'm' => (string)$row['match_type']]; } }
$keywordTotal = 0;
foreach ($allRules as $a) { foreach (explode(',', $a['k']) as $k) { if (trim($k) !== '' && trim($k) !== '[welcome]') $keywordTotal++; } }

$qs = static function (array $over = []) use ($search, $typeF, $stateF): string {
    $q = array_filter(array_merge(['q' => $search, 'type' => $typeF, 'state' => $stateF], $over), static fn($v) => $v !== '' && $v !== null && $v !== 1);
    return $q ? '?' . http_build_query($q) : '';
};
$currentQs = http_build_query(array_filter(['q' => $search, 'type' => $typeF, 'state' => $stateF, 'p' => $page > 1 ? $page : ''], static fn($v) => $v !== ''));
$filtersOn = ($search !== '' || $typeF !== '' || $stateF !== '');
$typeMeta = ['text' => ['Text', 'chat'], 'media' => ['Media', 'ocr'], 'interactive' => ['Buttons', 'flowbuilder'], 'template' => ['Template', 'templates']];
$matchPhrase = [
    'welcome' => "A new customer's first message", 'exact' => 'Message is exactly', 'starts_with' => 'Message starts with', 'ends_with' => 'Message ends with',
    'contains_word' => 'Message contains the word', 'contains' => 'Message contains',
    'stop_promo' => 'Customer asks to stop promotions', 'start_promo' => 'Customer asks to start promotions',
    'start_ai' => 'Customer asks to turn the AI bot on', 'stop_ai' => 'Customer asks to turn the AI bot off',
    'book_demo' => 'Customer asks for a demo or meeting',
];
$actionNote = [
    'stop_promo' => 'Also removes them from promotional campaigns', 'start_promo' => 'Also lets them receive promotions again',
    'stop_ai' => 'Also turns the AI bot off for their chat', 'start_ai' => 'Also turns the AI bot on for their chat',
    'book_demo' => 'Books a Google Meet when they give a time',
];
$limitPct = $ruleLimit > 0 ? min(100, (int)round($totalRules / $ruleLimit * 100)) : 0;

include '../includes/client_header.php';
?>

<style>
/* ── Bot Replies — dashboard design system, no divider lines ── */
.br{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.br *{box-sizing:border-box}
.br-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:linear-gradient(135deg,#fff 0%,var(--soft) 60%,#DDF5EA 140%);box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.br-hero::after{content:'';position:absolute;right:-60px;top:-80px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(37,211,102,.18),transparent 70%);pointer-events:none}
.br-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.br-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.br-hero-ico svg{width:22px;height:22px}
.br-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.br-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:540px;line-height:1.5}
.br-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s;position:relative;z-index:1}
.br-btn:hover{border-color:var(--g);color:var(--g)}
.br-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.br-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.br-btn--danger{background:#c62828;border-color:#c62828;color:#fff}.br-btn--danger:hover{background:#a81f1f;color:#fff;border-color:#a81f1f}
.br-btn[disabled],.br-btn--muted{opacity:.55;cursor:not-allowed;transform:none}
.br-btn.sm{height:36px;padding:0 14px;font-size:13px;border-radius:10px}
.br-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;font-weight:600;background:#E3F7EE;color:#0B6B5E}
.br-notice.err{background:#FDE7E7;color:#9b1c1c}.br-notice.warn{background:#FEF3DC;color:#7a4b00}
.br-notice a{color:inherit;text-decoration:underline;font-weight:700}

.br-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.br-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);min-width:0}
.br-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.br-kpi-ico svg{width:20px;height:20px}
.br-kpi.amber .br-kpi-ico{background:#FEF3DC;color:#b7791f}.br-kpi.blue .br-kpi-ico{background:#E4EFFD;color:#1d6fd6}.br-kpi.purple .br-kpi-ico{background:#EFE9FD;color:#6d44d8}
.br-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.br-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}
.br-lim{height:6px;border-radius:99px;background:#DCEBE5;overflow:hidden;margin-top:7px;width:120px}
.br-lim i{display:block;height:100%;background:linear-gradient(90deg,var(--g),var(--g2));border-radius:99px}
.br-lim.hot i{background:linear-gradient(90deg,#F59E0B,#EF4444)}

.br-how{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}
.br-how div{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;display:flex;gap:13px;align-items:flex-start;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.br-how b.n{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;display:grid;place-items:center;flex-shrink:0;font-size:13.5px}
.br-how strong{display:block;font-size:14.5px;margin-bottom:2px}.br-how span{font-size:13px;color:var(--mu);line-height:1.5}

.br-panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);padding:18px 18px 12px}
.br-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.br-chips{display:flex;gap:8px;flex-wrap:wrap}
.br-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;background:var(--soft);color:var(--tx);font:600 13.5px Inter,system-ui,sans-serif;text-decoration:none;transition:background .15s,color .15s}
.br-chip:hover{background:#E7F7F1}.br-chip.on{background:var(--g);color:#fff}
.br-search{display:flex;align-items:center;gap:10px;background:var(--soft);border-radius:11px;padding:0 14px;height:42px;min-width:250px}
.br-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.br-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.br-search:focus-within{box-shadow:0 0 0 2px var(--g);background:#fff}
.br-meta{font-size:13px;color:var(--mu);font-weight:600;margin:0 2px 12px}

.br-list{display:flex;flex-direction:column;gap:8px}
.br-row{display:grid;grid-template-columns:minmax(200px,1.1fr) minmax(240px,1.6fr) 130px 140px;gap:16px;align-items:center;padding:14px 16px;border-radius:14px;background:var(--soft);transition:background .15s}
.br-row:hover{background:#E7F7F1}
.br-row.off{opacity:.62}
.br-when small,.br-says small{display:block;font-size:11.5px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.br-kws{display:flex;flex-wrap:wrap;gap:6px}
.br-actline{margin-top:7px;font-size:12px;font-weight:700;color:#0B6B5E}
.br-kw-plain{max-width:none !important;white-space:normal !important;font-family:inherit}
.br-actnote{display:none;font-size:12.5px;color:#0B6B5E;background:#E3F7EE;border-radius:10px;padding:9px 12px;line-height:1.5}.br-actnote.on{display:block}
.br-kw{background:#fff;color:var(--gd);border-radius:8px;padding:3px 10px;font-size:13px;font-weight:700;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;box-shadow:0 0 0 1px #CDEBDF}
.br-says-body{display:flex;gap:11px;align-items:flex-start}
.br-tico{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.br-tico svg{width:16px;height:16px}
.br-tico.media{background:#E4EFFD;color:#1d6fd6}.br-tico.interactive{background:#EFE9FD;color:#6d44d8}.br-tico.template{background:#FEF3DC;color:#b7791f}
.br-says-t{font-size:13.5px;color:var(--tx);line-height:1.5;overflow-wrap:anywhere}
.br-says-t em{font-style:normal;font-weight:700;color:var(--ink)}
.br-bchips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.br-bchips span{font-size:12px;font-weight:700;background:#fff;color:#0A8BD6;padding:2px 10px;border-radius:7px}
.br-state{display:flex;align-items:center;gap:10px}
.br-state b{font-size:13px}
.br-sw{position:relative;width:46px;height:26px;display:inline-block;flex-shrink:0}
.br-sw input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;margin:0;z-index:2}
.br-sw i{position:absolute;inset:0;border-radius:99px;background:#CBD5D1;transition:background .2s}
.br-sw i::before{content:'';position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.25);transition:transform .2s}
.br-sw input:checked+i{background:var(--g)}.br-sw input:checked+i::before{transform:translateX(20px)}
.br-sw input:focus-visible+i{outline:3px solid rgba(18,140,126,.35);outline-offset:2px}
.br-acts{display:flex;gap:6px;justify-content:flex-end}
.br-ib{width:36px;height:36px;border-radius:10px;border:0;background:#fff;color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;transition:background .15s,color .15s}
.br-ib:hover{background:var(--g);color:#fff}.br-ib.danger:hover{background:#c62828}
.br-ib svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.br-form{display:inline;margin:0}
.br-empty{text-align:center;padding:48px 20px}
.br-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 16px}
.br-empty-ico svg{width:30px;height:30px}
.br-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.br-empty p{margin:0 auto 18px;color:var(--mu);font-size:14.5px;max-width:440px;line-height:1.55}
.br-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 4px 6px}
.br-pager span{font-size:13px;color:var(--mu);font-weight:600}
.br-pages{display:flex;gap:6px;flex-wrap:wrap}
.br-pages a,.br-pages b{min-width:36px;height:36px;padding:0 10px;border-radius:10px;display:inline-grid;place-items:center;font:700 13.5px Inter,system-ui,sans-serif;text-decoration:none;color:var(--tx);background:var(--soft)}
.br-pages a:hover{background:#E7F7F1;color:var(--gd)}.br-pages b{background:var(--g);color:#fff}.br-pages .gap{background:transparent;color:var(--mu)}

/* editor dialog */
.br-modal{position:fixed;inset:0;z-index:9999;background:rgba(11,20,26,.5);backdrop-filter:blur(3px);display:none;align-items:flex-start;justify-content:center;padding:24px 16px;overflow:auto}
.br-modal.open{display:flex}
.br-dialog{width:min(980px,100%);background:#fff;border-radius:18px;box-shadow:0 30px 70px rgba(0,0,0,.35);animation:brin .22s ease both;margin:auto}
@keyframes brin{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.br-dhead{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:20px 24px 4px}
.br-dhead h2{margin:0;font-size:21px;font-weight:800}
.br-dhead p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.br-x{border:0;background:var(--soft);width:38px;height:38px;border-radius:50%;font-size:22px;line-height:1;color:var(--tx);cursor:pointer;flex-shrink:0}
.br-x:hover{background:#FDE7E7;color:#c62828}
.br-dbody{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:22px;padding:14px 24px 22px}
.br-sec{display:flex;flex-direction:column;gap:12px;margin-bottom:20px}
.br-sec>h3{margin:0;font-size:15px;font-weight:800;display:flex;align-items:center;gap:10px}
.br-sec>h3 b{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;display:grid;place-items:center;font-size:12.5px}
.br-f{display:flex;flex-direction:column;gap:6px;min-width:0}
.br-lab{display:flex;justify-content:space-between;align-items:baseline;gap:10px;font-size:13px;font-weight:700}
.br-lab em{font-style:normal;font-weight:600;color:var(--mu);font-size:12px}
.br-help{font-size:12.5px;color:var(--mu);line-height:1.5;margin:0}
.br-help code{background:var(--soft);padding:1px 6px;border-radius:5px;font-size:12px;color:var(--gd)}
.br input[type=text],.br input[type=url],.br select,.br textarea{width:100%;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);background:var(--soft);border:1.5px solid transparent;border-radius:12px;padding:11px 14px;min-height:44px;outline:none;transition:border-color .15s,background .15s}
.br textarea{min-height:110px;line-height:1.55;resize:vertical}
.br input:focus,.br select:focus,.br textarea:focus{border-color:var(--g);background:#fff}
.br-tags{display:flex;flex-wrap:wrap;gap:7px;align-items:center;background:var(--soft);border:1.5px solid transparent;border-radius:12px;padding:8px 10px;min-height:46px;cursor:text;transition:border-color .15s,background .15s}
.br-tags:focus-within{border-color:var(--g);background:#fff}
.br-tags .t{display:inline-flex;align-items:center;gap:6px;background:#fff;color:var(--gd);border-radius:8px;padding:4px 6px 4px 10px;font-size:13px;font-weight:700;box-shadow:0 0 0 1px #CDEBDF}
.br-tags .t button{border:0;background:#E3F7EE;color:var(--gd);width:18px;height:18px;border-radius:50%;line-height:1;cursor:pointer;font-size:13px;padding:0}
.br-tags .t button:hover{background:#c62828;color:#fff}
.br-tags input{flex:1;min-width:140px;border:0 !important;background:transparent !important;padding:4px 2px !important;min-height:28px !important}
.br-dup{display:none;font-size:13px;font-weight:600;color:#7a4b00;background:#FEF3DC;border-radius:10px;padding:8px 12px}
.br-dup.on{display:block}
.br-opts{display:grid;gap:10px}.br-opts.three{grid-template-columns:repeat(3,minmax(0,1fr))}.br-opts.four{grid-template-columns:repeat(4,minmax(0,1fr))}
.br-opt{position:relative;display:flex;flex-direction:column;gap:3px;background:var(--soft);border:2px solid transparent;border-radius:13px;padding:11px 13px;cursor:pointer;transition:background .15s,border-color .15s}
.br-opt:hover{background:#E7F7F1}.br-opt.on{background:#fff;border-color:var(--g);box-shadow:0 12px 26px -18px rgba(18,140,126,.6)}
.br-opt input{position:absolute;opacity:0;pointer-events:none}
.br-opt b{font-size:14px;display:flex;align-items:center;gap:7px}.br-opt b svg{width:16px;height:16px;color:var(--g)}
.br-opt span{font-size:12.5px;color:var(--mu);line-height:1.4}
.br-chipbtns{display:flex;gap:8px;flex-wrap:wrap}
.br-cb{border:0;background:var(--soft);color:var(--gd);border-radius:999px;padding:6px 12px;font:700 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.br-cb:hover{background:var(--g);color:#fff}
.br-button-fields{display:flex;flex-direction:column;gap:6px}
.br-button-field{display:flex;flex-direction:column;gap:6px}
.br-button-field label{font-size:12.5px;color:var(--mu);font-weight:600}
.br-add-button{align-self:flex-start}
.br-tip{display:none;font-size:12.5px;color:#7a4b00;background:#FEF6E6;border-radius:10px;padding:9px 12px;line-height:1.5}
.br-tip.on{display:block}
.br-test{background:var(--soft);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:9px}
.br-test h4{margin:0;font-size:14px;font-weight:800}
.br-res{font-size:13.5px;font-weight:700;padding:8px 12px;border-radius:10px;display:none}
.br-res.yes{display:block;background:#E3F7EE;color:#0B6B5E}.br-res.no{display:block;background:#EEF3F1;color:#5d6f69}
.br-side{position:sticky;top:0;align-self:start;display:flex;flex-direction:column;gap:12px}
.br-side>h4{margin:0;font-size:13px;font-weight:800;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.br-wall{background:#efeae2 url(../assets/img/wa-wallpaper.jpg) repeat;background-size:320px auto;border-radius:14px;padding:14px;min-height:200px}
.br-in{background:#fff;border-radius:0 9px 9px 9px;padding:7px 10px;font-size:13px;color:#111B21;box-shadow:0 1px .5px rgba(11,20,26,.16);display:inline-block;max-width:80%;margin-bottom:8px}
.br-bub{position:relative;background:#d9fdd3;border-radius:9px 0 9px 9px;margin-left:auto;max-width:92%;box-shadow:0 1px .5px rgba(11,20,26,.16);font-size:13.5px;line-height:1.42;color:#111B21}
.br-bub .pad{padding:6px 9px 5px;white-space:pre-wrap;overflow-wrap:anywhere}
.br-bub .media{margin:3px 3px 0;border-radius:7px;min-height:90px;background:linear-gradient(135deg,#dff3ea,#c9e6f2);display:grid;place-items:center;color:#4d7a89;font-size:12.5px;font-weight:600;text-align:center;padding:8px}
.br-bub .ft{color:#6B7C85;font-size:12px;margin-top:3px}
.br-bub .tm{text-align:right;color:#6B7C85;font-size:11px}
.br-bub .bt{border-top:1px solid #bfe8b8;padding:8px 10px;text-align:center;color:#0A8BD6;font-weight:600}
.br-bub .var{background:rgba(18,140,126,.15);color:#0B6B5E;border-radius:5px;padding:0 4px;font-weight:600}
.br-bub .empty{color:#6B7C85}
.br-dfoot{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:0 24px 22px;flex-wrap:wrap}
.br-dfoot .err{margin-right:auto;font-size:13.5px;font-weight:600;color:#9b1c1c;background:#FDE7E7;border-radius:10px;padding:9px 12px;display:none}
.br-dfoot .err.on{display:block}
.br-confirm .br-dialog{width:min(440px,100%)}
.br-confirm .box{padding:24px}
.br-confirm h2{margin:0 0 6px;font-size:20px;font-weight:800}.br-confirm p{margin:0 0 18px;color:var(--mu);font-size:14px;line-height:1.55}
.br-confirm .row{display:flex;justify-content:flex-end;gap:10px}

@media (max-width:1100px){.br-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:900px){
  .br-row{grid-template-columns:1fr auto;grid-template-areas:"when state" "says says" "acts acts";row-gap:12px}
  .br-row>.br-when{grid-area:when}.br-row>.br-says{grid-area:says}.br-row>.br-state{grid-area:state;justify-content:flex-end}.br-row>.br-acts{grid-area:acts;justify-content:flex-start}
  .br-dbody{grid-template-columns:1fr}.br-side{position:static}.br-how{grid-template-columns:1fr}.br-opts.four{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:560px){.br-hero{padding:18px}.br-hero h1{font-size:21px}.br-kpis{grid-template-columns:1fr}.br-search{min-width:0;width:100%}.br-opts.three{grid-template-columns:1fr}.br-dhead,.br-dbody,.br-dfoot{padding-left:16px;padding-right:16px}}
@media (prefers-reduced-motion:reduce){.br-dialog{animation:none}}
</style>

<div class="br">

<?php if (isset($_GET['msg'])):
    $m = (string)$_GET['msg'];
    $msgMap = ['saved' => 'Rule saved. It is live for your WhatsApp customers.', 'deleted' => 'Rule deleted.', 'paused' => 'Rule paused. The bot will not use it until you turn it back on.', 'resumed' => 'Rule is live again.'];
    if ($m === 'expired_block'): ?>
        <div class="br-notice err">Action restricted. Your plan has expired. <a href="subscription.php">Renew now</a></div>
    <?php elseif (isset($msgMap[$m])): ?>
        <div class="br-notice"><?php echo htmlspecialchars($msgMap[$m]); ?></div>
    <?php endif; endif; ?>
<?php if ($viewOnlyMode): ?>
    <div class="br-notice warn"><strong>View-only mode:</strong> your plan has expired, so rules cannot be changed. <a href="subscription.php">Renew your plan</a></div>
<?php endif; ?>

<div class="br-hero">
    <div class="br-hero-l">
        <div class="br-hero-ico"><?php echo cn_svg('bot'); ?></div>
        <div>
            <h1>Bot Replies</h1>
            <p>Teach your bot to answer common questions instantly. When a customer's message matches a keyword, the bot replies for you.</p>
        </div>
    </div>
    <?php if ($viewOnlyMode): ?>
        <span class="br-btn br-btn--muted">New rule</span>
    <?php elseif ($atLimit): ?>
        <a href="subscription.php" class="br-btn" title="You have used all rules in your plan">Limit reached. Upgrade</a>
    <?php else: ?>
        <button type="button" class="br-btn br-btn--primary" id="brNew">+ New rule</button>
    <?php endif; ?>
</div>

<div class="br-kpis">
    <div class="br-kpi"><div class="br-kpi-ico"><?php echo cn_svg('health'); ?></div><div><div class="br-kpi-v"><?php echo $activeRules; ?></div><div class="br-kpi-l">Live rules</div></div></div>
    <div class="br-kpi amber"><div class="br-kpi-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="br-kpi-v"><?php echo max(0, $totalRules - $activeRules); ?></div><div class="br-kpi-l">Paused rules</div></div></div>
    <div class="br-kpi blue"><div class="br-kpi-ico"><?php echo cn_svg('chat'); ?></div><div><div class="br-kpi-v"><?php echo $keywordTotal; ?></div><div class="br-kpi-l">Keywords listening</div></div></div>
    <div class="br-kpi purple"><div class="br-kpi-ico"><?php echo cn_svg('shield'); ?></div><div><div class="br-kpi-v"><?php echo $totalRules; ?><?php if ($ruleLimit > 0): ?><span style="font-size:15px;color:var(--mu);font-weight:600"> / <?php echo $ruleLimit; ?></span><?php endif; ?></div><div class="br-kpi-l">Rules in your plan</div><?php if ($ruleLimit > 0): ?><div class="br-lim<?php echo $limitPct >= 85 ? ' hot' : ''; ?>"><i style="width:<?php echo $limitPct; ?>%"></i></div><?php endif; ?></div></div>
</div>

<?php if ($totalRules === 0): ?>
<div class="br-how">
    <div><b class="n">1</b><p style="margin:0"><strong>Pick the words</strong><span>Choose what customers might say, like "price" or "hello".</span></p></div>
    <div><b class="n">2</b><p style="margin:0"><strong>Write the reply</strong><span>Text, a picture, quick-reply buttons or one of your templates.</span></p></div>
    <div><b class="n">3</b><p style="margin:0"><strong>Test it</strong><span>Type a sample message in the editor to see if the rule would answer.</span></p></div>
</div>
<?php endif; ?>

<div class="br-panel">
    <div class="br-toolbar">
        <div class="br-chips">
            <a class="br-chip<?php echo $typeF === '' && $stateF === '' ? ' on' : ''; ?>" href="bot_replies.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>">All</a>
            <?php foreach ($typeMeta as $k => $tm): ?>
                <a class="br-chip<?php echo $typeF === $k ? ' on' : ''; ?>" href="bot_replies.php<?php echo $qs(['type' => $k, 'state' => '', 'p' => 1]); ?>"><?php echo $tm[0]; ?></a>
            <?php endforeach; ?>
            <a class="br-chip<?php echo $stateF === 'paused' ? ' on' : ''; ?>" href="bot_replies.php<?php echo $qs(['state' => 'paused', 'type' => '', 'p' => 1]); ?>">Paused</a>
        </div>
        <form method="GET" action="bot_replies.php" style="margin:0">
            <?php if ($typeF !== ''): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($typeF); ?>"><?php endif; ?>
            <?php if ($stateF !== ''): ?><input type="hidden" name="state" value="<?php echo htmlspecialchars($stateF); ?>"><?php endif; ?>
            <label class="br-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" name="q" placeholder="Search keywords or replies" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off"></label>
        </form>
    </div>

    <?php if ($matching > 0): ?>
    <div class="br-meta">Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format($offset + count($rules)); ?> of <?php echo number_format($matching); ?> rule<?php echo $matching === 1 ? '' : 's'; ?></div>
    <div class="br-list">
        <?php foreach ($rules as $r):
            $rid = (int)$r['id']; $on = !empty($r['is_active']); $rt = (string)($r['reply_type'] ?? 'text');
            $meta = $typeMeta[$rt] ?? $typeMeta['text'];
            $idata = []; if ($rt === 'interactive' && !empty($r['interactive_data'])) { $d = json_decode((string)$r['interactive_data'], true); $idata = is_array($d['buttons'] ?? null) ? $d['buttons'] : []; }
            $content = (string)($r['reply_content'] ?? '');
            $payload = [
                'id' => $rid, 'keyword' => (string)$r['keyword'], 'match_type' => (string)$r['match_type'], 'reply_type' => $rt, 'reply_content' => $content,
                'media_url' => (string)($r['media_url'] ?? ''), 'header_type' => (string)($r['header_type'] ?? 'none'), 'footer_text' => (string)($r['footer_text'] ?? ''),
                'template_id' => !empty($r['template_id']) ? (int)$r['template_id'] : 0, 'buttons' => array_map(static fn($b) => (string)($b['text'] ?? ''), $idata),
            ];
        ?>
        <div class="br-row<?php echo $on ? '' : ' off'; ?>">
            <div class="br-when">
                <small><?php echo htmlspecialchars($matchPhrase[$r['match_type']] ?? 'Message contains'); ?></small>
                <div class="br-kws"><?php if ($r['match_type'] === 'welcome'): ?><span class="br-kw br-kw-plain">First message from a new customer</span><?php else: foreach (explode(',', (string)$r['keyword']) as $kw): if (trim($kw) !== ''): ?><span class="br-kw" title="<?php echo htmlspecialchars(trim($kw)); ?>"><?php echo htmlspecialchars(trim($kw)); ?></span><?php endif; endforeach; endif; ?></div>
                <?php if (isset($actionNote[$r['match_type']])): ?><div class="br-actline"><?php echo htmlspecialchars($actionNote[$r['match_type']]); ?></div><?php endif; ?>
            </div>
            <div class="br-says">
                <small>Bot replies with</small>
                <div class="br-says-body">
                    <span class="br-tico <?php echo htmlspecialchars($rt); ?>"><?php echo cn_svg($meta[1]); ?></span>
                    <div class="br-says-t">
                        <?php if ($rt === 'template'): ?><em>Template:</em> <?php echo htmlspecialchars((string)($r['template_name'] ?: 'Template removed')); ?>
                        <?php elseif ($rt === 'media'): ?><em><?php echo htmlspecialchars(ucfirst((string)($r['header_type'] ?: 'media'))); ?></em><?php echo $content !== '' ? ': ' . htmlspecialchars(mb_substr($content, 0, 110, 'UTF-8')) : ''; ?>
                        <?php else: echo nl2br(htmlspecialchars(mb_substr($content, 0, 130, 'UTF-8'))); echo mb_strlen($content, 'UTF-8') > 130 ? '…' : ''; endif; ?>
                        <?php if ($idata): ?><div class="br-bchips"><?php foreach ($idata as $b): ?><span><?php echo htmlspecialchars((string)($b['text'] ?? '')); ?></span><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="br-state">
                <form method="POST" class="br-form" title="<?php echo $on ? 'Pause this rule' : 'Turn this rule on'; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="toggle_active" value="1"><input type="hidden" name="toggle_id" value="<?php echo $rid; ?>"><input type="hidden" name="new_state" value="<?php echo $on ? 0 : 1; ?>"><input type="hidden" name="back" value="<?php echo htmlspecialchars($currentQs); ?>">
                    <label class="br-sw"><input type="checkbox" onchange="this.closest('form').submit()" aria-label="Rule is live"<?php echo $on ? ' checked' : ''; ?><?php echo $viewOnlyMode ? ' disabled' : ''; ?>><i></i></label>
                </form>
                <b><?php echo $on ? 'Live' : 'Paused'; ?></b>
            </div>
            <div class="br-acts">
                <?php if (!$viewOnlyMode): ?>
                <button type="button" class="br-ib" data-edit='<?php echo htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES); ?>' title="Edit rule" aria-label="Edit rule"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg></button>
                <?php if (!$atLimit): ?><button type="button" class="br-ib" data-dup='<?php echo htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES); ?>' title="Duplicate rule" aria-label="Duplicate rule"><svg viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg></button><?php endif; ?>
                <button type="button" class="br-ib danger" data-del="<?php echo $rid; ?>" data-name="<?php echo htmlspecialchars(mb_substr((string)$r['keyword'], 0, 60, 'UTF-8'), ENT_QUOTES); ?>" title="Delete rule" aria-label="Delete rule"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1):
        $win = array_unique(array_filter(array_merge([1, $totalPages], range(max(1, $page - 2), min($totalPages, $page + 2))))); sort($win); ?>
    <div class="br-pager">
        <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
        <div class="br-pages">
            <?php if ($page > 1): ?><a href="bot_replies.php<?php echo $qs(['p' => $page - 1]); ?>" aria-label="Previous page">&larr;</a><?php endif; ?>
            <?php $prev = 0; foreach ($win as $p): if ($p - $prev > 1): ?><b class="gap">…</b><?php endif; $prev = $p;
                if ($p === $page): ?><b><?php echo $p; ?></b><?php else: ?><a href="bot_replies.php<?php echo $qs(['p' => $p]); ?>"><?php echo $p; ?></a><?php endif; endforeach; ?>
            <?php if ($page < $totalPages): ?><a href="bot_replies.php<?php echo $qs(['p' => $page + 1]); ?>" aria-label="Next page">&rarr;</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="br-empty">
        <div class="br-empty-ico"><?php echo cn_svg('bot'); ?></div>
        <?php if ($filtersOn): ?>
            <h3>No rules match</h3><p>Try a different search or clear the filters.</p>
            <a href="bot_replies.php" class="br-btn br-btn--primary">Show all rules</a>
        <?php else: ?>
            <h3>No bot rules yet</h3>
            <p>Create your first rule. For example, when someone says "price", the bot sends your price list automatically.</p>
            <?php if (!$viewOnlyMode && !$atLimit): ?><button type="button" class="br-btn br-btn--primary" id="brNew2">+ Create your first rule</button><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div>

<!-- Editor -->
<div class="br-modal br" id="brModal" role="dialog" aria-modal="true" aria-labelledby="brTitle">
<div class="br-dialog">
    <form method="POST" id="brForm" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="save_rule" value="1">
        <input type="hidden" name="rule_id" id="fId" value="0">
        <input type="hidden" name="back" value="<?php echo htmlspecialchars($currentQs); ?>">
        <input type="hidden" name="keyword" id="fKeyword" value="">
        <div class="br-dhead">
            <div><h2 id="brTitle">New rule</h2><p id="brSub">Choose what customers say and what the bot answers.</p></div>
            <button type="button" class="br-x" id="brClose" aria-label="Close">&times;</button>
        </div>
        <div class="br-dbody">
            <div>
                <div class="br-sec">
                    <h3><b>1</b> When should this reply?</h3>
                    <div class="br-f">
                        <label class="br-lab" for="fTrig">Trigger Type</label>
                        <select name="match_type" id="fTrig">
<?php foreach ($triggerTypes as $tv => $tm): ?>
                            <option value="<?php echo htmlspecialchars($tv); ?>"<?php echo $tv === 'contains' ? ' selected' : ''; ?>><?php echo htmlspecialchars($tm[0]); ?></option>
<?php endforeach; ?>
                        </select>
                        <p class="br-help" id="fTrigHelp"></p>
                    </div>
                    <div class="br-f" id="fKwBox">
                        <div class="br-lab"><label for="fKwIn">Keywords</label><em>Press Enter or comma after each one</em></div>
                        <div class="br-tags" id="fTags"><input type="text" id="fKwIn" placeholder="e.g. price, cost, rate" maxlength="60"></div>
                        <div class="br-dup" id="fDup"></div>
                    </div>
                    <div class="br-actnote" id="fAct"></div>
                </div>

                <div class="br-sec">
                    <h3><b>2</b> The bot replies with</h3>
                    <div class="br-opts four" id="fType">
                        <label class="br-opt on" data-t="text"><input type="radio" name="reply_type" value="text" checked><b><?php echo cn_svg('chat'); ?> Text</b><span>A normal message</span></label>
                        <label class="br-opt" data-t="media"><input type="radio" name="reply_type" value="media"><b><?php echo cn_svg('ocr'); ?> Media</b><span>Image, video or file</span></label>
                        <label class="br-opt" data-t="interactive"><input type="radio" name="reply_type" value="interactive"><b><?php echo cn_svg('flowbuilder'); ?> Buttons</b><span>Quick-reply buttons</span></label>
                        <label class="br-opt" data-t="template"><input type="radio" name="reply_type" value="template"><b><?php echo cn_svg('templates'); ?> Template</b><span>One of your templates</span></label>
                    </div>
                    <div class="br-tip" id="fTip">Heads up: WhatsApp only allows free-text replies within 24 hours of the customer's last message. After that, only <b>Template</b> replies are sent.</div>

                    <div class="br-f" id="boxTpl" style="display:none">
                        <div class="br-lab"><label for="fTpl">Your template</label><em><a href="templates.php" style="color:var(--gd);font-weight:700">Manage templates</a></em></div>
                        <select name="template_id" id="fTpl">
                            <option value="">Choose a template</option>
                            <?php foreach ($availableTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?><?php echo $t['wa_status'] === 'PENDING' ? ' (pending approval)' : ''; ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!$availableTemplates): ?><p class="br-help">You have no usable templates yet. <a href="template_create.php" style="color:var(--gd);font-weight:700">Create one</a> first.</p><?php endif; ?>
                    </div>

                    <div class="br-f" id="boxMsg">
                        <div class="br-lab"><label for="fMsg" id="fMsgLab">Message</label><span class="ab-count" id="fCnt" style="font-size:12px;color:var(--mu);font-weight:600">0/4096</span></div>
                        <textarea name="reply_content" id="fMsg" rows="5" placeholder="Type what the bot should send"></textarea>
                        <div class="br-chipbtns"><button type="button" class="br-cb" data-ins="{full_name}">+ Customer name</button><button type="button" class="br-cb" data-ins="*bold*">Bold</button><button type="button" class="br-cb" data-ins="_italic_">Italic</button></div>
                        <p class="br-help">Use <code>{full_name}</code> to greet the customer by name.</p>
                    </div>

                    <div id="boxMedia" style="display:none" class="br-opts" >
                        <div class="br-f"><label class="br-lab" for="fHdr">Media type</label>
                            <select name="header_type" id="fHdr"><option value="none">Choose type</option><option value="image">Image</option><option value="video">Video</option><option value="document">Document</option></select></div>
                        <div class="br-f"><label class="br-lab" for="fUrl">Link to the file <em>https://</em></label>
                            <input type="url" name="media_url" id="fUrl" placeholder="https://example.com/brochure.pdf"></div>
                    </div>

                    <div id="boxBtns" style="display:none" class="br-f">
                        <div class="br-lab"><span>Quick-reply buttons</span><em>Up to 3, each max 20 characters</em></div>
                        <div class="br-button-fields">
                            <div class="br-button-field" id="buttonField1"><label for="fB1">Button 1 label</label><input type="text" name="btn_1_text" id="fB1" maxlength="20" placeholder="e.g. Pricing"></div>
                            <div class="br-button-field" id="buttonField2" style="display:none"><label for="fB2">Button 2 label (optional)</label><input type="text" name="btn_2_text" id="fB2" maxlength="20" placeholder="e.g. Book a demo"></div>
                            <div class="br-button-field" id="buttonField3" style="display:none"><label for="fB3">Button 3 label (optional)</label><input type="text" name="btn_3_text" id="fB3" maxlength="20" placeholder="e.g. Contact support"></div>
                        </div>
                        <button type="button" class="br-cb br-add-button" id="fAddButton">Add another button (1/3)</button>
                        <p class="br-help" id="fButtonLimit" aria-live="polite">WhatsApp supports up to 3 quick-reply buttons.</p>
                    </div>

                    <div id="boxFoot" style="display:none" class="br-f">
                        <div class="br-lab"><label for="fFoot">Small footer text</label><em>Optional</em></div>
                        <input type="text" name="footer_text" id="fFoot" maxlength="60" placeholder="e.g. Reply STOP to opt out">
                    </div>
                </div>
            </div>

            <aside class="br-side">
                <h4>Live preview</h4>
                <div class="br-wall"><div class="br-in" id="pvIn">price</div><div class="br-bub" id="pvOut"></div></div>
                <div class="br-test">
                    <h4>Try it out</h4>
                    <input type="text" id="tIn" placeholder="Type a customer message">
                    <div class="br-res" id="tRes"></div>
                </div>
            </aside>
        </div>
        <div class="br-dfoot">
            <div class="err<?php echo $formError ? ' on' : ''; ?>" id="fErr"><?php echo htmlspecialchars($formError); ?></div>
            <button type="button" class="br-btn" id="brCancel">Cancel</button>
            <button type="submit" class="br-btn br-btn--primary" id="brSave">Save rule</button>
        </div>
    </form>
</div>
</div>

<!-- Delete confirmation -->
<div class="br-modal br br-confirm" id="brDel" role="dialog" aria-modal="true" aria-labelledby="brDelT">
<div class="br-dialog"><div class="box">
    <h2 id="brDelT">Delete this rule?</h2>
    <p id="brDelP"></p>
    <form method="POST" class="row">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="delete_id" id="brDelId" value="0"><input type="hidden" name="back" value="<?php echo htmlspecialchars($currentQs); ?>">
        <button type="button" class="br-btn" id="brDelNo">Keep rule</button>
        <button type="submit" class="br-btn br-btn--danger">Delete</button>
    </form>
</div></div>
</div>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var TEMPLATES = <?php echo json_encode(array_column($availableTemplates, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var EXISTING = <?php echo json_encode($allRules, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var RESTORE = <?php echo json_encode($restore, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var modal = $('brModal'), del = $('brDel');
    var TRIG = <?php echo json_encode(array_map(static fn($t) => ['help' => $t[1], 'needs' => (bool)$t[2]], $triggerTypes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var ACT = { stop_promo: 'When a customer says this, they are also removed from promotional campaigns. Your reply below confirms it to them.', start_promo: 'When a customer says this, they can receive promotional campaigns again. Your reply below confirms it.', stop_ai: 'When a customer says this, the AI bot stops answering their chat. Your reply below tells them.', start_ai: 'When a customer says this, the AI bot answers their chat again. Your reply below tells them.' };
    var KWHINT = { stop_promo: 'e.g. stop, unsubscribe, opt out', start_promo: 'e.g. start, subscribe, opt in', stop_ai: 'e.g. human, agent, stop bot', start_ai: 'e.g. bot, start bot, ai', book_demo: 'e.g. demo, meeting, book a call, schedule' };
    ACT.book_demo = 'When a customer asks for a demo and includes a time (for example "tomorrow 4 PM"), a Google Meet is created and the confirmation is sent automatically. If they do not give a time, the reply below is sent to collect the details.';
    var DEMO_REPLY = 'Thank you for your interest in a Waamini demo. \uD83D\uDE4F\n\nTo book your 20-minute Google Meet session, please reply with:\n1. Your name and business name\n2. The service you would like to see (WhatsApp automation, campaigns, checkout, etc.)\n3. Your preferred date and time (for example, "Tomorrow at 4 PM")\n\nAs soon as we have these, we will confirm your slot and send the Google Meet link here.';
    var kws = [], curType = 'text', curMatch = 'contains';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function fmt(t) {
        var h = esc(t).replace(/\{full_name\}/g, '\u0001').replace(/\*(.+?)\*/g, '<b>$1</b>').replace(/(^|[^\w])_(.+?)_(?=$|[^\w])/g, '$1<i>$2</i>');
        return h.replace(/\u0001/g, '<span class="var">Customer name</span>');
    }

    /* keyword tags */
    function renderTags() {
        var box = $('fTags'), inp = $('fKwIn');
        box.querySelectorAll('.t').forEach(function (n) { n.remove(); });
        kws.forEach(function (k, i) {
            var s = document.createElement('span'); s.className = 't'; s.textContent = k;
            var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Remove ' + k); b.innerHTML = '&times;';
            b.addEventListener('click', function () { kws.splice(i, 1); renderTags(); sync(); });
            s.appendChild(b); box.insertBefore(s, inp);
        });
        $('fKeyword').value = kws.join(', ');
    }
    function addKw(raw) {
        raw.split(',').forEach(function (p) {
            p = p.trim(); if (!p || p.length > 60) return;
            if (!kws.some(function (k) { return k.toLowerCase() === p.toLowerCase(); }) && kws.length < 20) kws.push(p);
        });
        renderTags(); sync();
    }
    $('fKwIn').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addKw(this.value); this.value = ''; }
        else if (e.key === 'Backspace' && !this.value && kws.length) { kws.pop(); renderTags(); sync(); }
    });
    $('fKwIn').addEventListener('blur', function () { if (this.value.trim()) { addKw(this.value); this.value = ''; } });
    $('fTags').addEventListener('click', function () { $('fKwIn').focus(); });
    $('fKwIn').addEventListener('paste', function (e) { var t = (e.clipboardData || window.clipboardData).getData('text'); if (t.indexOf(',') !== -1) { e.preventDefault(); addKw(t); } });

    /* radio cards */
    function radioGroup(id, cb) {
        $(id).addEventListener('change', function () {
            this.querySelectorAll('.br-opt').forEach(function (o) { o.classList.toggle('on', o.querySelector('input').checked); });
            cb(this.querySelector('input:checked').value); sync();
        });
    }
    function showType(t) {
        curType = t;
        $('boxTpl').style.display = t === 'template' ? '' : 'none';
        $('boxMsg').style.display = t === 'template' ? 'none' : '';
        $('boxMedia').style.display = t === 'media' ? 'grid' : 'none';
        $('boxBtns').style.display = t === 'interactive' ? '' : 'none';
        $('boxFoot').style.display = (t === 'media' || t === 'interactive') ? '' : 'none';
        $('fTip').classList.toggle('on', t !== 'template');
        $('fMsgLab').textContent = t === 'media' ? 'Caption (optional)' : (t === 'interactive' ? 'Message above the buttons' : 'Message');
        $('fMsg').maxLength = t === 'text' ? 4096 : 1024;
        $('fMsg').required = (t === 'text' || t === 'interactive');
        $('fTpl').required = t === 'template';
        $('fHdr').required = t === 'media'; $('fUrl').required = t === 'media';
        $('fB1').required = t === 'interactive';
        count();
    }
    radioGroup('fType', showType);
    function trigUI() {
        var t = TRIG[curMatch] || { help: '', needs: true };
        $('fTrigHelp').textContent = t.help;
        $('fKwBox').style.display = t.needs ? '' : 'none';
        $('fKwIn').placeholder = KWHINT[curMatch] || 'e.g. price, cost, rate';
        var an = $('fAct'); an.textContent = ACT[curMatch] || ''; an.classList.toggle('on', !!ACT[curMatch]);
        // Offer a ready-made professional reply the first time Book Demo is chosen
        if (curMatch === 'book_demo' && !$('fMsg').value.trim()) { $('fMsg').value = DEMO_REPLY; count(); }
    }
    $('fTrig').addEventListener('change', function () { curMatch = this.value; trigUI(); sync(); });

    function count() { var m = $('fMsg'); $('fCnt').textContent = m.value.length + '/' + (curType === 'text' ? 4096 : 1024); }

    function showButtonSlots(visibleCount) {
        visibleCount = Math.max(1, Math.min(3, visibleCount));
        ['buttonField1', 'buttonField2', 'buttonField3'].forEach(function (id, index) {
            $(id).style.display = index < visibleCount ? '' : 'none';
        });
        $('fAddButton').style.display = visibleCount < 3 ? '' : 'none';
        $('fAddButton').textContent = 'Add another button (' + visibleCount + '/3)';
        $('fButtonLimit').textContent = visibleCount === 3
            ? 'You have reached the WhatsApp limit of 3 quick-reply buttons.'
            : 'WhatsApp supports up to 3 quick-reply buttons.';
    }

    $('fAddButton').addEventListener('click', function () {
        var fields = ['buttonField1', 'buttonField2', 'buttonField3'];
        var visibleCount = fields.filter(function (id) { return $(id).style.display !== 'none'; }).length;
        if (visibleCount >= fields.length) return;
        showButtonSlots(visibleCount + 1);
        $('fB' + (visibleCount + 1)).focus();
    });

    /* matching: same rules the live bot uses */
    function matches(text) {
        text = (text || '').toLowerCase().trim(); if (!text) return null;
        function esc2(x) { return x.replace(/[.*+?^$\{\}()|[\]\\\-]/g, '\\$&'); }
        for (var i = 0; i < kws.length; i++) {
            var k = kws[i].toLowerCase();
            if (curMatch === 'exact') { if (text === k) return k; }
            else if (curMatch === 'starts_with') { if (text.indexOf(k) === 0) return k; }
            else if (curMatch === 'ends_with') { if (text.length >= k.length && text.slice(-k.length) === k) return k; }
            else if (curMatch === 'contains_word' || curMatch === 'stop_promo' || curMatch === 'start_promo' || curMatch === 'stop_ai' || curMatch === 'start_ai' || curMatch === 'book_demo') {
                try { if (new RegExp('(^|[^\\p{L}\\p{N}_])' + esc2(k) + '($|[^\\p{L}\\p{N}_])', 'u').test(text)) return k; } catch (e) { if (text.indexOf(k) !== -1) return k; }
            } else {
                if (/^[a-z0-9_\-]+$/.test(k) && k.length >= 2) { if (new RegExp('(^|[^a-z0-9_])' + esc2(k) + '($|[^a-z0-9_])').test(text)) return k; }
                else if (text.indexOf(k) !== -1) return k;
            }
        }
        return false;
    }
    function dupCheck() {
        var key = kws.map(function (k) { return k.toLowerCase(); }).join(', '), id = parseInt($('fId').value, 10) || 0, clash = null;
        EXISTING.forEach(function (r) { if (r.id !== id && key && r.k.toLowerCase().replace(/\s*,\s*/g, ', ') === key && r.m === curMatch) clash = r; });
        var d = $('fDup');
        if (curMatch === 'welcome') {
            var hasW = EXISTING.some(function (r) { return r.m === 'welcome' && r.id !== id; });
            $('fAct').textContent = hasW ? 'You already have a Welcome rule. Edit that one instead of adding another.' : 'Sent once, automatically, when a new customer messages you for the first time. Other matching rules still reply after it.';
            $('fAct').classList.add('on');
            return;
        }
        if (clash) { d.textContent = 'Another rule already uses these exact keywords with the same match type.'; d.classList.add('on'); } else d.classList.remove('on');
    }
    function preview() {
        var out = $('pvOut'), h = '';
        $('pvIn').textContent = curMatch === 'welcome' ? 'Hi' : (kws[0] || 'hello');
        var msg = $('fMsg').value, foot = $('fFoot').value.trim();
        if (curType === 'template') {
            var t = TEMPLATES[$('fTpl').value];
            h = t ? '<div class="pad">' + (t.header_text ? '<b>' + esc(t.header_text) + '</b><br>' : '') + esc(t.text) + (t.footer ? '<div class="ft">' + esc(t.footer) + '</div>' : '') + '<div class="tm">now</div></div>' : '<div class="pad empty">Choose a template to preview it</div>';
        } else {
            if (curType === 'media') { var ht = $('fHdr').value; h += '<div class="media">' + (ht === 'none' ? 'Choose a media type' : esc(ht.charAt(0).toUpperCase() + ht.slice(1))) + '</div>'; }
            h += '<div class="pad">' + (msg.trim() ? fmt(msg) : '<span class="empty">Your message appears here</span>') + (foot && (curType === 'media' || curType === 'interactive') ? '<div class="ft">' + esc(foot) + '</div>' : '') + '<div class="tm">now</div></div>';
            if (curType === 'interactive') ['fB1', 'fB2', 'fB3'].forEach(function (id) { var v = $(id).value.trim(); if (v) h += '<div class="bt">' + esc(v) + '</div>'; });
        }
        out.innerHTML = h;
    }
    function test() {
        var v = $('tIn').value, r = $('tRes'), m = matches(v);
        if (curMatch === 'welcome') { r.className = 'br-res no'; r.textContent = 'This rule is sent automatically to a customer\'s very first message, so there is nothing to test.'; return; }
        if (m === null) { r.className = 'br-res'; return; }
        if (!kws.length) { r.className = 'br-res no'; r.textContent = 'Add a keyword first.'; return; }
        r.className = 'br-res ' + (m ? 'yes' : 'no');
        r.textContent = m ? 'Yes. This rule would reply (matched "' + m + '").' : 'No. This message would not trigger this rule.';
    }
    function sync() { dupCheck(); preview(); test(); }

    ['fMsg', 'fFoot', 'fB1', 'fB2', 'fB3', 'fHdr', 'fUrl', 'fTpl', 'tIn'].forEach(function (id) { $(id).addEventListener('input', function () { count(); sync(); }); $(id).addEventListener('change', sync); });
    document.querySelectorAll('[data-ins]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = $('fMsg'), s = t.selectionStart || 0, e = t.selectionEnd || 0, ins = b.dataset.ins;
            if (ins.length > 6 && e > s) ins = ins.charAt(0) + t.value.slice(s, e) + ins.charAt(ins.length - 1);
            t.value = t.value.slice(0, s) + ins + t.value.slice(e); t.focus(); count(); sync();
        });
    });

    /* open / fill / close */
    function setRadio(group, val) {
        $(group).querySelectorAll('input').forEach(function (i) { i.checked = i.value === val; i.closest('.br-opt').classList.toggle('on', i.checked); });
    }
    function open(d, mode) {
        d = d || {};
        var edit = mode === 'edit';
        $('brTitle').textContent = edit ? 'Edit rule' : (mode === 'dup' ? 'Duplicate rule' : 'New rule');
        $('brSub').textContent = edit ? 'Change what customers say or what the bot answers.' : 'Choose what customers say and what the bot answers.';
        $('brSave').textContent = edit ? 'Save changes' : 'Save rule';
        $('fId').value = edit ? (d.id || 0) : 0;
        curMatch = d.match_type || 'contains'; $('fTrig').value = curMatch; if ($('fTrig').value !== curMatch) { curMatch = 'contains'; $('fTrig').value = 'contains'; }
        kws = []; renderTags(); $('fKwIn').value = ''; addKw(curMatch === 'welcome' ? '' : (d.keyword || ''));
        trigUI();
        var t = d.reply_type || 'text'; setRadio('fType', t); showType(t);
        $('fMsg').value = d.reply_content || ''; $('fHdr').value = d.header_type || 'none'; $('fUrl').value = d.media_url || '';
        $('fFoot').value = d.footer_text || ''; $('fTpl').value = d.template_id ? String(d.template_id) : '';
        var b = d.buttons || []; $('fB1').value = b[0] || ''; $('fB2').value = b[1] || ''; $('fB3').value = b[2] || '';
        showButtonSlots(Math.max(1, b.length));
        $('tIn').value = ''; $('tRes').className = 'br-res'; $('fErr').classList.remove('on');
        count(); sync(); modal.classList.add('open'); document.body.style.overflow = 'hidden';
        setTimeout(function () { $('fKwIn').focus(); }, 40);
    }
    function close() { modal.classList.remove('open'); del.classList.remove('open'); document.body.style.overflow = ''; }

    ['brNew', 'brNew2'].forEach(function (id) { var b = $(id); if (b) b.addEventListener('click', function () { open({}, 'new'); }); });
    document.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]'), dp = e.target.closest('[data-dup]'), dl = e.target.closest('[data-del]');
        if (ed) open(JSON.parse(ed.dataset.edit), 'edit');
        if (dp) { var d = JSON.parse(dp.dataset.dup); d.id = 0; open(d, 'dup'); }
        if (dl) { $('brDelId').value = dl.dataset.del; $('brDelP').textContent = '“' + dl.dataset.name + '” will stop replying to customers. This cannot be undone. If you only want a break, pause it instead.'; del.classList.add('open'); }
    });
    $('brClose').addEventListener('click', close); $('brCancel').addEventListener('click', close); $('brDelNo').addEventListener('click', close);
    [modal, del].forEach(function (m) { m.addEventListener('click', function (e) { if (e.target === m) close(); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    $('brForm').addEventListener('submit', function (e) {
        if ($('fKwIn').value.trim()) { addKw($('fKwIn').value); $('fKwIn').value = ''; }
        if ((TRIG[curMatch] || { needs: true }).needs && !kws.length) { e.preventDefault(); var er = $('fErr'); er.textContent = 'Add at least one keyword that should trigger this reply.'; er.classList.add('on'); $('fKwIn').focus(); }
    });

    if (RESTORE) { open(RESTORE, RESTORE.id > 0 ? 'edit' : 'new'); $('fErr').classList.add('on'); }
})();
</script>

<?php include '../includes/client_footer.php'; ?>
