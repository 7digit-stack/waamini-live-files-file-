<?php
/**
 * Inbox message rendering shared by client/whatsapp.php (first load) and client/ajax_load_chat.php (polling),
 * so a message looks identical before and after a refresh. It draws a message the way WhatsApp shows it:
 *   - image / video / document / voice note (with transcript) and its caption,
 *   - interactive messages sent by the bot or a flow as real buttons / a list (structure kept in messages.meta_json),
 *   - the customer's tap on a button or list row as the label they tapped, not as raw JSON,
 *   - a WhatsApp Form (Flow) submission as a small answers card.
 */

/** messages.meta_json holds the structure of interactive messages (buttons, list). Added once, safe to call often. */
function wa_chat_ensure_meta_column(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $r = $conn->query("SHOW COLUMNS FROM messages LIKE 'meta_json'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE messages ADD COLUMN meta_json TEXT NULL");
    } catch (\Throwable $e) { error_log('[chat] meta_json column: ' . $e->getMessage()); }
}

/** The customer's tap on a button / list row is stored as {"type":"button_reply","id":"..","title":".."}. */
function wa_chat_reply_from_content(string $content): ?array
{
    $c = ltrim($content);
    if ($c === '' || $c[0] !== '{') return null;
    $j = json_decode($c, true);
    if (is_array($j) && in_array($j['type'] ?? '', ['button_reply', 'list_reply'], true) && isset($j['title'])) {
        return ['kind' => $j['type'], 'id' => (string)($j['id'] ?? ''), 'title' => (string)$j['title']];
    }
    return null;
}

/** Plain text for the conversation list preview and the bubble. */
function wa_chat_text(array $m): string
{
    $t = (string)($m['content'] ?? '');
    if ($t === '') $t = (string)($m['message'] ?? '');
    $reply = wa_chat_reply_from_content($t);
    return $reply ? $reply['title'] : $t;
}

/** Older flow messages were saved as "text\n[Sales] | [Support]": draw those as buttons too. */
function wa_chat_legacy_buttons(string $text): ?array
{
    if (!preg_match('/^(.*?)\R+((?:\[[^\[\]]{1,24}\])(?:\s*\|\s*\[[^\[\]]{1,24}\]){0,9})\s*$/su', $text, $m)) return null;
    preg_match_all('/\[([^\[\]]{1,24})\]/u', $m[2], $mm);
    return ['body' => trim($m[1]), 'buttons' => array_map(static fn($t) => ['title' => $t], $mm[1])];
}

function wa_chat_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** WhatsApp-style text: *bold*, _italic_, ~strike~, ```mono``` and clickable links, after escaping. */
function wa_chat_format(string $text): string
{
    $h = wa_chat_h($text);
    $h = preg_replace('/```(.+?)```/s', '<code>$1</code>', $h);
    $h = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<b>$1</b>', $h);
    $h = preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<i>$1</i>', $h);
    $h = preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<s>$1</s>', $h);
    $h = preg_replace('#\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]#i', '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>', $h);
    return nl2br($h);
}

function wa_chat_btn_html(string $label, bool $list = false): string
{
    $icon = $list
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/></svg>'
        : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>';
    return '<div class="wa-ibtn">' . $icon . '<span>' . wa_chat_h($label) . '</span></div>';
}

/** Everything inside the bubble except the time / ticks line. */
function wa_chat_render_body(array $m): string
{
    $type  = (string)($m['type'] ?? 'text');
    $media = (string)($m['media_url'] ?? '');
    $text  = wa_chat_text($m);
    $meta  = json_decode((string)($m['meta_json'] ?? ''), true);
    $isOut = ((string)($m['direction'] ?? '') === 'outgoing');
    $out = '';

    $src = static function (string $u): string {
        return (strpos($u, 'http') === 0 || strpos($u, 'uploads/') === 0 || strpos($u, '/uploads/') === 0)
            ? wa_chat_h($u) : 'ajax_media_proxy.php?id=' . urlencode($u);
    };

    if ($type === 'image' && $media !== '') {
        $out .= '<a class="wa-media" href="' . $src($media) . '" target="_blank" rel="noopener"><img src="' . $src($media) . '" alt="Photo" loading="lazy"></a>';
    } elseif ($type === 'video' && $media !== '') {
        $out .= '<div class="wa-media"><video controls preload="metadata" src="' . $src($media) . '"></video></div>';
    } elseif ($type === 'audio' || $type === 'voice') {
        $tr = trim((string)preg_replace('/^🎤\s*/u', '', $text));
        $out .= '<div class="wa-voice">';
        $out .= $media !== '' ? '<audio controls preload="none" src="' . $src($media) . '"></audio>' : '<span class="wa-voice-tag">🎤 Voice message</span>';
        if ($tr !== '' && strpos($tr, '[VOICE:') !== 0) {
            $out .= '<div class="wa-voice-tr"><small>Transcript</small>' . nl2br(wa_chat_h($tr)) . '</div>';
        } elseif ($media !== '' && !$isOut) {
            $out .= '<button type="button" class="wa-voice-btn" data-voice-mid="' . (int)($m['id'] ?? 0) . '">Transcribe</button>';
        }
        return $out . '</div>';
    } elseif ($type === 'document' && $media !== '') {
        $fn = is_array($meta) && !empty($meta['filename']) ? (string)$meta['filename'] : (basename((string)parse_url($media, PHP_URL_PATH)) ?: 'Document');
        $ext = strtoupper((string)pathinfo($fn, PATHINFO_EXTENSION));
        $out .= '<a class="wa-doc" href="' . $src($media) . '" target="_blank" rel="noopener">'
              . '<span class="wa-doc-ico">' . wa_chat_h($ext !== '' ? substr($ext, 0, 4) : 'FILE') . '</span>'
              . '<span class="wa-doc-name">' . wa_chat_h($fn) . '</span>'
              . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg></a>';
        if ($text === $fn || $text === '[document]') $text = '';
    }

    // the customer tapped a button / list row
    if (!$isOut && ($reply = wa_chat_reply_from_content((string)($m['content'] ?? '')))) {
        return $out . '<div class="wa-message-text">' . wa_chat_h($reply['title']) . '</div>';
    }

    // WhatsApp Form (Flow) submission: "📋 WhatsApp Flow submission\nkey: value…"
    if (!$isOut && strpos($text, '📋 WhatsApp Flow submission') === 0) {
        $rows = '';
        foreach (array_slice(preg_split('/\R/u', $text), 1) as $line) {
            if (!preg_match('/^([^:]{1,80}):\s?(.*)$/u', $line, $kv) || $kv[1] === 'flow_token') continue;
            $k = ucfirst(trim(preg_replace('/[_\-]+/', ' ', preg_replace('/^screen_\d+_/', '', $kv[1]))));
            $rows .= '<div class="wa-form-row"><span>' . wa_chat_h($k) . '</span><b>' . wa_chat_h($kv[2] !== '' ? $kv[2] : '—') . '</b></div>';
        }
        return $out . '<div class="wa-form-card"><div class="wa-form-h"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/></svg> Form response</div>' . ($rows ?: '<div class="wa-form-row"><span>No answers</span></div>') . '</div>';
    }

    // bot / flow message with buttons or a list
    if ($isOut && is_array($meta) && in_array($meta['kind'] ?? '', ['buttons', 'list'], true)) {
        $out .= '<div class="wa-message-text">' . wa_chat_format($text) . '</div>';
        if (!empty($meta['footer'])) $out .= '<div class="wa-ifoot">' . wa_chat_h($meta['footer']) . '</div>';
        $btns = '';
        if ($meta['kind'] === 'buttons') {
            foreach ((array)($meta['buttons'] ?? []) as $b) $btns .= wa_chat_btn_html((string)($b['title'] ?? ''));
        } else {
            $btns .= wa_chat_btn_html((string)($meta['button'] ?? 'Choose'), true);
        }
        return $out . '<div class="wa-ibtns">' . $btns . '</div>';
    }
    if ($isOut && $type === 'text' && ($lg = wa_chat_legacy_buttons($text))) {
        $out .= '<div class="wa-message-text">' . wa_chat_format($lg['body']) . '</div><div class="wa-ibtns">';
        foreach ($lg['buttons'] as $b) $out .= wa_chat_btn_html($b['title']);
        return $out . '</div>';
    }

    // types that have no text of their own: show what they are instead of an empty bubble
    if ($type === 'sticker') {
        return $media !== ''
            ? '<img class="wa-sticker" src="' . $src($media) . '" alt="Sticker" loading="lazy">'
            : '<div class="wa-message-text wa-msg-note">Sticker</div>';
    }
    if ($type === 'reaction') {
        return '<div class="wa-message-text wa-msg-note">' . ($text !== '' ? 'Reacted ' . wa_chat_h($text) . ' to a message' : 'Removed a reaction') . '</div>';
    }
    if (preg_match('/^\[location(?::(-?[\d.]+),(-?[\d.]+))?\]$/', $text, $loc)) {
        $label = '📍 Location';
        return !empty($loc[1])
            ? '<div class="wa-message-text"><a href="https://www.google.com/maps?q=' . rawurlencode($loc[1] . ',' . $loc[2]) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a></div>'
            : '<div class="wa-message-text">' . $label . '</div>';
    }
    if ($text === '[contact]') {
        return '<div class="wa-message-text wa-msg-note">👤 Contact card</div>';
    }
    if ($type === 'order') {
        return '<div class="wa-message-text">🛒 Order received</div>';
    }

    if ($text !== '' && !in_array($text, ['[image]', '[video]'], true)) $out .= '<div class="wa-message-text">' . wa_chat_format($text) . '</div>';

    if ($out === '') {
        $what = ['image' => '📷 Photo', 'video' => '🎥 Video', 'document' => '📄 Document'][$type] ?? '';
        $out = '<div class="wa-message-text wa-msg-note">'
             . ($what !== '' ? $what . ' (file not available)' : 'This message can only be seen in the WhatsApp app')
             . '</div>';
    }
    return $out;
}

/** One full message row (bubble + time/ticks). data-day lets the page add date chips for new days. */
function wa_chat_row_html(array $m): string
{
    $isOut = ((string)($m['direction'] ?? '') === 'outgoing');
    $ts = strtotime((string)($m['created_at'] ?? ''));
    $body = wa_chat_render_body($m);
    $isMedia = in_array((string)($m['type'] ?? ''), ['image', 'video'], true) && !empty($m['media_url']) && strpos($body, 'wa-message-text') === false;
    $tick = $isOut && function_exists('wa_tick_html') ? wa_tick_html((string)($m['status'] ?? 'sent'), (string)($m['error_message'] ?? '')) : '';
    return '<div class="wa-message-row ' . ($isOut ? 'outgoing' : 'incoming') . '" data-msgid="' . (int)($m['id'] ?? 0) . '"'
        . ' data-day="' . ($ts ? date('Y-m-d', $ts) : '') . '" data-daylabel="' . ($ts && function_exists('wa_day_label') ? wa_chat_h(wa_day_label($ts)) : '') . '">'
        . '<div class="wa-message-bubble' . ($isMedia ? ' media-only' : '') . '">' . $body
        . '<div class="wa-message-meta"><span>' . ($ts ? date('g:i A', $ts) : '') . '</span>' . $tick . '</div>'
        . '</div></div>';
}
