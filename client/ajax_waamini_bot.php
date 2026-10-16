<?php
/**
 * WAAMINI Bot: client panel JSON API.
 * company_id always comes from the logged-in session, never from the request or the model.
 */
include '../includes/client_auth_check.php';
include_once '../includes/security.php';
require_once '../includes/waamini_bot/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$reply = static function (array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if ($companyId <= 0 || !has_feature('ai_chatbot')) { $reply(['ok' => false, 'error' => 'Not available on your plan.'], 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $reply(['ok' => false, 'error' => 'POST required.'], 405); }
csrf_verify();

$in = $_POST;
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $j = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($j)) { $in = $j; }
}
$act = (string)($in['action'] ?? '');

$S = WB_Store::settings($conn);
$platformOk = WB_Store::platformReady($S);
$bot = WB_Store::bot($conn, $companyId, true);
if (!$bot) { $reply(['ok' => false, 'error' => 'Could not load your bot.'], 500); }
$botId = (int)$bot['id'];

// Inbox "Resume bot" works even when the bot is switched off elsewhere
if ($act === 'resume_bot') {
    $cid = (int)($in['contact_id'] ?? 0);
    if ($cid <= 0) { $reply(['ok' => false, 'error' => 'Missing chat.'], 400); }
    $chk = $conn->prepare("SELECT id FROM contacts WHERE id = ? AND company_id = ?");
    $chk->bind_param('ii', $cid, $companyId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { $reply(['ok' => false, 'error' => 'Chat not found.'], 404); }
    WB_Guard::resume($conn, $companyId, $cid);
    $reply(['ok' => true]);
}

if (!$platformOk || !$bot['is_admin_enabled']) {
    $reply(['ok' => false, 'error' => 'Bot is not available, contact support.', 'unavailable' => true], 403);
}

$sourcesOut = static function () use ($conn, $companyId, $botId): array {
    $st = $conn->prepare("SELECT id, type, uri, title, status, bytes, error, trained_at FROM ai_knowledge_sources WHERE company_id = ? AND bot_id = ? ORDER BY type, id");
    $st->bind_param('ii', $companyId, $botId);
    $st->execute();
    $r = $st->get_result();
    $o = [];
    while ($x = $r->fetch_assoc()) { $o[] = $x; }
    return $o;
};
$usageOut = static function () use ($conn, $companyId): array {
    $u = WB_Store::usage($conn, $companyId);
    $l = WB_Store::limitsFor($conn, $companyId);
    $kb = $conn->prepare("SELECT COALESCE(SUM(bytes),0) AS b, SUM(type='url') AS urls, SUM(type='file') AS docs FROM ai_knowledge_sources WHERE company_id = ? AND status = 'success'");
    $kb->bind_param('i', $companyId);
    $kb->execute();
    $k = $kb->get_result()->fetch_assoc();
    return ['replies' => $u['replies'], 'tokens' => $u['tokens'], 'limits' => $l, 'kb_kb' => (int)ceil(((int)$k['b']) / 1024), 'urls' => (int)$k['urls'], 'docs' => (int)$k['docs']];
};

try {
    switch ($act) {
        case 'status': {
            $bot = WB_Store::bot($conn, $companyId);
            $reply(['ok' => true, 'sources' => $sourcesOut(), 'progress' => WB_Store::progress($conn, $companyId, $bot), 'usage' => $usageOut(), 'is_live' => $bot['is_live']]);
        }

        case 'fetch_site':
        case 'add_url': {
            [$ok, $msg] = WB_Knowledge::startCrawl($conn, $companyId, $bot, (string)($in['url'] ?? ''));
            $reply(['ok' => $ok, 'message' => $msg, 'error' => $ok ? null : $msg, 'sources' => $sourcesOut()]);
        }

        case 'tick': {
            $r = WB_Knowledge::tick($conn, $companyId, $botId, 12, 4);
            $reply(['ok' => true, 'processed' => $r['processed'], 'remaining' => $r['remaining'], 'sources' => $sourcesOut(), 'usage' => $usageOut(),
                'progress' => WB_Store::progress($conn, $companyId, WB_Store::bot($conn, $companyId))]);
        }

        case 'retrain': {
            $sid = isset($in['id']) && $in['id'] !== 'all' ? (int)$in['id'] : null;
            $n = WB_Knowledge::retrain($conn, $companyId, $botId, $sid);
            $reply(['ok' => true, 'queued' => $n, 'sources' => $sourcesOut()]);
        }

        case 'delete_source': {
            WB_Knowledge::deleteSource($conn, $companyId, (int)($in['id'] ?? 0));
            $reply(['ok' => true, 'sources' => $sourcesOut(), 'usage' => $usageOut()]);
        }

        case 'upload_doc': {
            if (empty($_FILES['file'])) { $reply(['ok' => false, 'error' => 'Choose a file first.'], 400); }
            [$ok, $msg] = WB_Knowledge::addDocument($conn, $companyId, $bot, $_FILES['file']);
            $reply(['ok' => $ok, 'message' => $msg, 'error' => $ok ? null : $msg, 'sources' => $sourcesOut(), 'usage' => $usageOut()]);
        }

        case 'draft_profile': {
            [$ok, $err, $fields] = WB_Knowledge::draftProfile($conn, $companyId, $botId);
            $reply(['ok' => $ok, 'error' => $ok ? null : $err, 'fields' => $fields]);
        }

        case 'save_profile': {
            $in_p = is_array($in['profile'] ?? null) ? $in['profile'] : [];
            $clean = [];
            foreach (array_keys(WB_Store::profileFields()) as $k) { $clean[$k] = mb_substr(trim((string)($in_p[$k] ?? '')), 0, 2000); }
            foreach (['hours_start', 'hours_end'] as $k) {
                $v = trim((string)($in_p[$k] ?? ''));
                $clean[$k] = preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '';
            }
            $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
            $confirm = !empty($in['confirm']);
            $st = $conn->prepare("UPDATE ai_bots SET business_profile = ?" . ($confirm ? ", profile_confirmed_at = NOW()" : "") . " WHERE id = ? AND company_id = ?");
            $st->bind_param('sii', $json, $botId, $companyId);
            $st->execute();
            $reply(['ok' => true, 'confirmed' => $confirm]);
        }

        case 'save_instructions': {
            $lines = static fn($v) => array_values(array_filter(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 300), is_array($v) ? $v : preg_split('/\r?\n/', (string)$v)), static fn($x) => $x !== ''));
            $name = mb_substr(trim((string)($in['name'] ?? $bot['name'])), 0, 100) ?: 'WAAMINI Bot';
            $role = mb_substr(trim((string)($in['role_text'] ?? '')), 0, 2000);
            $tone = mb_substr(trim((string)($in['tone_text'] ?? '')), 0, 1000);
            $scope = json_encode(array_slice($lines($in['scope'] ?? []), 0, 15), JSON_UNESCAPED_UNICODE);
            $oos = json_encode(array_slice($lines($in['out_of_scope'] ?? []), 0, 15), JSON_UNESCAPED_UNICODE);
            if ($role === '') { $reply(['ok' => false, 'error' => 'Role cannot be empty.'], 400); }
            $st = $conn->prepare("UPDATE ai_bots SET name = ?, role_text = ?, tone_text = ?, scope = ?, out_of_scope = ? WHERE id = ? AND company_id = ?");
            $st->bind_param('sssssii', $name, $role, $tone, $scope, $oos, $botId, $companyId);
            $st->execute();
            $fresh = WB_Store::bot($conn, $companyId);
            $n = WB_Store::saveVersion($conn, $companyId, $fresh, (int)($_SESSION['user_id'] ?? 0));
            $reply(['ok' => true, 'version' => $n]);
        }

        case 'versions': {
            $st = $conn->prepare("SELECT id, version_no, created_at FROM ai_bot_versions WHERE company_id = ? AND bot_id = ? ORDER BY version_no DESC LIMIT 20");
            $st->bind_param('ii', $companyId, $botId);
            $st->execute();
            $r = $st->get_result();
            $o = [];
            while ($x = $r->fetch_assoc()) { $o[] = $x; }
            $reply(['ok' => true, 'versions' => $o]);
        }

        case 'restore_version': {
            $vid = (int)($in['id'] ?? 0);
            $st = $conn->prepare("SELECT snapshot FROM ai_bot_versions WHERE id = ? AND company_id = ? AND bot_id = ?");
            $st->bind_param('iii', $vid, $companyId, $botId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $snap = $row ? json_decode((string)$row['snapshot'], true) : null;
            if (!is_array($snap)) { $reply(['ok' => false, 'error' => 'Version not found.'], 404); }
            $name = (string)($snap['name'] ?? $bot['name']); $role = (string)($snap['role_text'] ?? ''); $tone = (string)($snap['tone_text'] ?? '');
            $scope = json_encode($snap['scope'] ?? [], JSON_UNESCAPED_UNICODE); $oos = json_encode($snap['out_of_scope'] ?? [], JSON_UNESCAPED_UNICODE);
            $up = $conn->prepare("UPDATE ai_bots SET name = ?, role_text = ?, tone_text = ?, scope = ?, out_of_scope = ? WHERE id = ? AND company_id = ?");
            $up->bind_param('sssssii', $name, $role, $tone, $scope, $oos, $botId, $companyId);
            $up->execute();
            WB_Store::saveVersion($conn, $companyId, WB_Store::bot($conn, $companyId), (int)($_SESSION['user_id'] ?? 0));
            $reply(['ok' => true]);
        }

        case 'save_settings': {
            $name = mb_substr(trim((string)($in['name'] ?? $bot['name'])), 0, 100) ?: 'WAAMINI Bot';
            $lang = in_array($in['language_mode'] ?? '', ['same', 'english', 'tamil', 'tanglish'], true) ? $in['language_mode'] : 'same';
            $mode = in_array($in['reply_mode'] ?? '', ['always', 'after_hours'], true) ? $in['reply_mode'] : 'always';
            $welcome = mb_substr(trim((string)($in['welcome_line'] ?? '')), 0, 500);
            $handoff = mb_substr(trim((string)($in['handoff_line'] ?? '')), 0, 500);
            $target = (int)($in['handoff_target'] ?? 0);
            if ($target > 0) {
                $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
                $chk->bind_param('ii', $target, $companyId);
                $chk->execute();
                if (!$chk->get_result()->fetch_row()) { $target = 0; }
            }
            $targetDb = $target > 0 ? $target : null;
            $st = $conn->prepare("UPDATE ai_bots SET name = ?, language_mode = ?, reply_mode = ?, welcome_line = ?, handoff_line = ?, handoff_target = ? WHERE id = ? AND company_id = ?");
            $st->bind_param('sssssiii', $name, $lang, $mode, $welcome, $handoff, $targetDb, $botId, $companyId);
            $st->execute();
            // follow-up after silence + quick instructions (WhatsApp Business app style)
            if (array_key_exists('followup_enabled', $in)) {
                $fuOn = !empty($in['followup_enabled']) && $in['followup_enabled'] !== '0' ? 1 : 0;
                $fuH = max(1, min(23, (int)($in['followup_hours'] ?? 1)));
                $known = array_keys(WB_Store::quickRules());
                $presets = array_values(array_intersect(array_map('strval', (array)($in['rule_presets'] ?? [])), $known));
                $custom = array_slice(array_values(array_filter(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 200), (array)($in['rule_custom'] ?? [])), static fn($x) => $x !== '')), 0, 10);
                $qr = json_encode(['presets' => $presets, 'custom' => $custom], JSON_UNESCAPED_UNICODE);
                $fu = $conn->prepare("UPDATE ai_bots SET followup_enabled = ?, followup_hours = ?, quick_rules = ? WHERE id = ? AND company_id = ?");
                $fu->bind_param('iisii', $fuOn, $fuH, $qr, $botId, $companyId);
                $fu->execute();
            }
            // business hours live inside the confirmed profile
            if (isset($in['hours_start']) || isset($in['hours_end'])) {
                $prof = $bot['business_profile'];
                foreach (['hours_start', 'hours_end'] as $k) {
                    $v = trim((string)($in[$k] ?? ''));
                    $prof[$k] = preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '';
                }
                $pj = json_encode($prof, JSON_UNESCAPED_UNICODE);
                $up = $conn->prepare("UPDATE ai_bots SET business_profile = ? WHERE id = ? AND company_id = ?");
                $up->bind_param('sii', $pj, $botId, $companyId);
                $up->execute();
            }
            $reply(['ok' => true]);
        }

        case 'test_chat': {
            $msgs = is_array($in['messages'] ?? null) ? $in['messages'] : [];
            $clean = [];
            foreach ($msgs as $m) { if (is_array($m)) { $clean[] = ['role' => (string)($m['role'] ?? ''), 'content' => mb_substr((string)($m['content'] ?? ''), 0, 2000)]; } }
            $res = WB_Engine::run($conn, $companyId, WB_Store::bot($conn, $companyId), $clean, ['is_test' => true]);
            $res['pages'] = array_values($res['pages']);
            $titles = [];
            foreach ($sourcesOut() as $s) { $titles[$s['uri']] = $s['title'] ?: $s['uri']; }
            foreach ($res['pages'] as &$pg) { $pg['title'] = $titles[$pg['url']] ?? $pg['url']; }
            unset($pg);
            WB_Guard::logRun($conn, $companyId, 0, null, null, true, $res);
            if ($res['prompt_tokens'] + $res['completion_tokens'] > 0) { WB_Store::addUsage($conn, $companyId, 0, $res['prompt_tokens'] + $res['completion_tokens']); }
            if ($res['text'] !== '' && in_array($res['outcome'], ['answered', 'handoff_requested', 'handoff_scope', 'handoff_nomatch'], true) && empty($bot['tested_at'])) {
                $conn->query("UPDATE ai_bots SET tested_at = NOW() WHERE id = " . $botId . " AND company_id = " . $companyId);
            }
            $reply(['ok' => true, 'text' => $res['text'], 'handoff' => $res['handoff'], 'outcome' => $res['outcome'], 'steps' => $res['steps'], 'pages' => $res['pages'],
                'matched' => $res['matched'], 'error' => $res['error'] ? 'The AI service had a problem. Please try again.' : null,
                'progress' => WB_Store::progress($conn, $companyId, WB_Store::bot($conn, $companyId))]);
        }

        case 'fix_answer': {
            [$ok, $msg] = WB_Knowledge::saveQa($conn, $companyId, $botId, null, (string)($in['question'] ?? ''), (string)($in['answer'] ?? ''));
            $reply(['ok' => $ok, 'error' => $ok ? null : $msg]);
        }

        case 'qa_save': {
            [$ok, $msg] = WB_Knowledge::saveQa($conn, $companyId, $botId, !empty($in['id']) ? (int)$in['id'] : null, (string)($in['question'] ?? ''), (string)($in['answer'] ?? ''));
            $reply(['ok' => $ok, 'error' => $ok ? null : $msg]);
        }

        case 'qa_delete': {
            $id = (int)($in['id'] ?? 0);
            $st = $conn->prepare("DELETE FROM ai_qa_pairs WHERE id = ? AND company_id = ?");
            $st->bind_param('ii', $id, $companyId);
            $st->execute();
            $reply(['ok' => true]);
        }

        case 'qa_toggle': {
            $id = (int)($in['id'] ?? 0);
            $st = $conn->prepare("UPDATE ai_qa_pairs SET is_active = 1 - is_active WHERE id = ? AND company_id = ?");
            $st->bind_param('ii', $id, $companyId);
            $st->execute();
            $reply(['ok' => true]);
        }

        case 'toggle_live': {
            $on = !empty($in['on']);
            if ($on) {
                $p = WB_Store::progress($conn, $companyId, WB_Store::bot($conn, $companyId));
                if (!$p['complete']) { $reply(['ok' => false, 'error' => 'Finish the setup first: train your website, confirm the details, save instructions and answer one test message.'], 400); }
            }
            $v = $on ? 1 : 0;
            $st = $conn->prepare("UPDATE ai_bots SET is_live = ? WHERE id = ? AND company_id = ?");
            $st->bind_param('iii', $v, $botId, $companyId);
            $st->execute();
            $reply(['ok' => true, 'is_live' => $on]);
        }

        default:
            $reply(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[WAAMINI Bot ajax] ' . WB_OpenAI::scrub($e->getMessage()));
    $reply(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
