<?php
/**
 * Ads & Attribution — small shared helpers for the client and admin pages.
 */

if (!function_exists('ads_inr')) {
    /** Indian number format: 125000 → "1,25,000" (decimals only when there are paise). */
    function ads_inr($n, bool $symbol = true): string
    {
        $n = (float)$n;
        $neg = $n < 0;
        $n = abs($n);
        $dec = round($n - floor($n), 2) > 0 ? sprintf('%02d', (int)round(($n - floor($n)) * 100)) : '';
        $int = (string)(int)floor($n);
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $int . ($dec !== '' ? '.' . $dec : '');
    }
}

if (!function_exists('ads_range')) {
    /**
     * Date range from ?range=today|7d|30d|month|custom&from=Y-m-d&to=Y-m-d, in the app timezone (Asia/Kolkata).
     * Returns ['key','from','to' (Y-m-d H:i:s, inclusive),'label','from_d','to_d'].
     */
    function ads_range(array $q): array
    {
        $key = in_array($q['range'] ?? '', ['today', '7d', '30d', 'month', 'custom'], true) ? $q['range'] : '30d';
        $today = date('Y-m-d');
        switch ($key) {
            case 'today': $f = $today; $t = $today; $label = 'Today'; break;
            case '7d':    $f = date('Y-m-d', strtotime('-6 days')); $t = $today; $label = 'Last 7 days'; break;
            case 'month': $f = date('Y-m-01'); $t = $today; $label = 'This month'; break;
            case 'custom':
                $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['from'] ?? '')) ? $q['from'] : date('Y-m-d', strtotime('-29 days'));
                $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['to'] ?? '')) ? $q['to'] : $today;
                if ($f > $t) [$f, $t] = [$t, $f];
                $label = date('d M Y', strtotime($f)) . ' – ' . date('d M Y', strtotime($t));
                break;
            default:      $f = date('Y-m-d', strtotime('-29 days')); $t = $today; $label = 'Last 30 days';
        }
        return ['key' => $key, 'from' => $f . ' 00:00:00', 'to' => $t . ' 23:59:59', 'label' => $label, 'from_d' => $f, 'to_d' => $t];
    }
}

if (!function_exists('ads_stage_label')) {
    function ads_stage_label(string $s): array
    {
        return [
            'won'       => ['Won', 'is-ok'],
            'qualified' => ['Qualified', 'is-info'],
            'lost'      => ['Lost', 'is-bad'],
        ][$s] ?? ['New', 'is-off'];
    }
}
