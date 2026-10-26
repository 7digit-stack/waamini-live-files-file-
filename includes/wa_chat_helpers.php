<?php
/**
 * WhatsApp-Web style display helpers shared by client/whatsapp.php,
 * client/ajax_load_chat.php and api/get_sidebar_updates.php.
 */

if (!function_exists('wa_chat_time')) {
    /** Chat-list timestamp: "3:42 PM" today, "Yesterday", weekday within a week, else dd/mm/yyyy. */
    function wa_chat_time(?string $datetime): string {
        if (!$datetime || $datetime === '0000-00-00 00:00:00') return '';
        $t = strtotime($datetime);
        if (!$t) return '';
        $today = strtotime('today');
        if ($t >= $today)                return date('g:i A', $t);
        if ($t >= $today - 86400)        return 'Yesterday';
        if ($t >= $today - 6 * 86400)    return date('l', $t);
        return date('d/m/Y', $t);
    }
}

if (!function_exists('wa_day_label')) {
    /** Date-separator chip inside a conversation. */
    function wa_day_label(int $t): string {
        $today = strtotime('today');
        if ($t >= $today)                return 'Today';
        if ($t >= $today - 86400)        return 'Yesterday';
        if ($t >= $today - 6 * 86400)    return date('l', $t);
        return date('j F Y', $t);
    }
}

if (!function_exists('wa_tick_html')) {
    /** Delivery ticks for outgoing messages: clock, single, double grey, double blue, failed. */
    function wa_tick_html(string $status, string $reason = ''): string {
        $svg = static function (string $inner, int $w = 16, int $h = 11): string {
            return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
        };
        $status = strtolower($status);
        if ($status === 'failed') {
            return '<span class="wa-tick failed" title="' . htmlspecialchars($reason !== '' ? 'Failed to send: ' . $reason : 'Failed to send', ENT_QUOTES) . '">!</span>';
        }
        if (in_array($status, ['queued', 'pending', 'sending', 'processing'], true)) {
            return '<span class="wa-tick" title="Sending">' . $svg('<circle cx="6" cy="5.5" r="4.2"/><polyline points="6 3.2 6 5.6 7.6 6.5"/>', 12, 11) . '</span>';
        }
        $first  = '<polyline points="1.5 5.8 5 9.3 11.5 1.8"/>';
        $second = '<polyline points="6.2 8.2 7.4 9.3 14 1.8"/>';
        if ($status === 'read') {
            return '<span class="wa-tick read" title="Read">' . $svg($first . $second) . '</span>';
        }
        if ($status === 'delivered') {
            return '<span class="wa-tick" title="Delivered">' . $svg($first . $second) . '</span>';
        }
        return '<span class="wa-tick" title="Sent">' . $svg($first) . '</span>';
    }
}
