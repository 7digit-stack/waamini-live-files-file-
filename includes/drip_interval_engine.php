<?php

const DRIP_BH_START_HOUR = 10;
const DRIP_BH_END_HOUR   = 20;

/**
 * Seeded PRNG — uses a contact+step seed for reproducible jitter per contact,
 * but different across contacts. Uses mt_rand with explicit seed management
 * to avoid PHP 8.2+ deprecation.
 */
if (!function_exists('drip_seeded_rand')) {
function drip_seeded_rand(int $seed): int
{
    $state = hash('fnv1a64', (string)$seed, true);
    $val = (int)(unpack('V', substr($state, 4, 4))[1] & 0x7FFFFFFF);
    return max(1, $val % 2147483647 + 1);
}
}

/**
 * Interval pattern pools for each mode.
 * Each pool entry is [min_seconds, max_seconds] defining the random range.
 * Key insight: pools store MANY patterns so every contact gets a unique
 * sequence — no two contacts ever see the same interval cadence.
 */
const DRIP_INTERVAL_POOLS = [
    'none' => [
        [0, 0], // exact, no jitter
    ],
    'minimal' => [
        [60, 300],    // 1-5 min
        [120, 600],   // 2-10 min
        [300, 900],   // 5-15 min
        [600, 1800],  // 10-30 min
        [1, 60],      // sudden quick 1-60s
    ],
    'human' => [
        [180, 900],     // 3-15 min: quick check-in
        [600, 3600],    // 10-60 min: standard reply pace
        [1800, 7200],   // 30 min - 2 hr: busy period
        [3600, 14400],  // 1-4 hr: half-day gap
        [7200, 28800],  // 2-8 hr: work-day spread
        [120, 480],     // 2-8 min: urgent follow-up burst
        [43200, 86400], // 12-24 hr: overnight gap
    ],
    'ghost' => [
        [3600, 7200],    // 1-2 hr: short ghost
        [7200, 14400],   // 2-4 hr: standard ghost gap
        [14400, 43200],  // 4-12 hr: long disappearance
        [43200, 129600], // 12-36 hr: overnight + next day
        [600, 3600],     // 10-60 min: re-emergence burst
        [180, 900],      // 3-15 min: rapid re-engagement
        [86400, 172800], // 24-48 hr: full silent day
    ],
    'chaos' => [
        [5, 30],         // 5-30s: hyper-rapid typing burst
        [3, 15],         // 3-15s: rapid-fire exchange
        [25, 120],       // 25s-2min: short pause
        [120, 600],      // 2-10min: thinking pause
        [600, 3600],     // 10-60min: step away
        [1, 5],          // 1-5s: instant reply feel
        [45, 180],       // 45s-3min: casual reply
        [3600, 14400],   // 1-4hr: long silence
        [1800, 7200],    // 30min-2hr: medium silence
        [300, 900],      // 5-15min: standard reply
    ],
];

/**
 * Pick a random interval range from the mode's pool, weighted toward
 * certain patterns based on step_index to create narrative arcs.
 *
 * Earlier steps → shorter intervals (urgency/interest).
 * Later steps → longer intervals (nurturing/cool-down).
 */
function drip_pick_interval_pool(string $mode, int $step_index, int $seed): array
{
    $pool = DRIP_INTERVAL_POOLS[$mode] ?? DRIP_INTERVAL_POOLS['human'];
    $poolSize = count($pool);

    // For chaos and ghost modes, bias the pick based on step_index + seed
    // to create a spread of patterns across the campaign lifecycle
    if ($mode === 'chaos' || $mode === 'ghost') {
        $bias = ($step_index * 7 + $seed) % $poolSize;
        // 40% chance to pick from a biased cluster, 60% fully random
        $r = drip_seeded_rand($seed + $step_index * 13) % 100;
        if ($r < 40) {
            $cluster = max(0, min($poolSize - 3, $bias));
            $idx = $cluster + (drip_seeded_rand($seed + $step_index * 31) % min(3, $poolSize - $cluster));
        } else {
            $idx = drip_seeded_rand($seed + $step_index * 17) % $poolSize;
        }
    } else {
        $idx = drip_seeded_rand($seed + $step_index * 11) % $poolSize;
    }

    return $pool[$idx];
}

/**
 * Compute the effective base + randomized jitter for a given mode.
 *
 * @param string $mode       Randomization mode
 * @param int    $base_sec   Raw delay from step config (days+hours+minutes to seconds)
 * @param int    $contact_id Contact ID for seeding
 * @param int    $step_id    Step ID for seeding
 * @param int    $step_index 0-based step index
 * @param int    $jitter_sec Max additional jitter from step config
 * @return int   Randomized delay in seconds
 */
function drip_compute_effective_delay(
    string $mode,
    int $base_sec,
    int $contact_id,
    int $step_id,
    int $step_index = 0,
    int $jitter_sec = 300
): int {
    $seed = $contact_id * 1000 + $step_id * 7 + $step_index * 53;

    if ($mode === 'none') {
        return max(5, $base_sec); // exact schedule — allow second-level cadence (e.g. 25s)
    }

    // For all non-static modes: pick from interval pool
    [$poolMin, $poolMax] = drip_pick_interval_pool($mode, $step_index, $seed + 997);
    $poolDelay = $poolMin + (drip_seeded_rand($seed + 991) % max(1, $poolMax - $poolMin + 1));

    // Mix base delay with pool delay (weighted: later steps lean heavier on pool)
    $poolWeight = min(0.8, 0.3 + ($step_index * 0.08));
    if ($base_sec > 0) {
        $mixed = (int)round($base_sec * (1 - $poolWeight) + $poolDelay * $poolWeight);
    } else {
        $mixed = $poolDelay;
    }

    // Add step-config jitter
    $jitter = $jitter_sec > 0 ? (drip_seeded_rand($seed + 773) % ($jitter_sec * 2 + 1)) - $jitter_sec : 0;
    $mixed += $jitter;

    // Ghost mode: 15% chance of extra "silent gap" (4-24 hours)
    if ($mode === 'ghost' && (drip_seeded_rand($seed + 557) % 100) < 15) {
        $silentGap = 14400 + (drip_seeded_rand($seed + 571) % 72000); // 4-24hrs
        $mixed += $silentGap;
    }

    // Chaos mode: create burst clusters (multi-message rapid-fire simulation)
    if ($mode === 'chaos' && $step_index > 0) {
        $burstSeed = $seed + 313;
        $subBursts = drip_seeded_rand($burstSeed) % 3; // 0-2 extra sub-bursts
        $totalSubDelay = 0;
        for ($b = 0; $b < $subBursts; $b++) {
            $totalSubDelay += 3 + (drip_seeded_rand($burstSeed + $b * 17) % 28); // 3-30s per sub-burst
        }
        $mixed += $totalSubDelay;
    }

    return max(5, $mixed);
}

function drip_human_mode(int $base_seconds, array $step, int $contactId, bool $respect_bh = true): int
{
    $delay = drip_compute_effective_delay(
        'human', $base_seconds, $contactId,
        (int)($step['id'] ?? 0), 0,
        (int)($step['jitter_range_seconds'] ?? 300)
    );
    $ts = time() + max(15, $delay);
    if ($respect_bh && $base_seconds >= 3600) {
        return drip_apply_business_hours($ts, $contactId, 10, 30, (int)($step['jitter_range_seconds'] ?? 300));
    }
    return $ts;
}

function drip_minimal_mode(int $base_seconds, array $step, int $contactId): int
{
    $delay = drip_compute_effective_delay(
        'minimal', $base_seconds, $contactId,
        (int)($step['id'] ?? 0), 0,
        (int)($step['jitter_range_seconds'] ?? 120)
    );
    return time() + max(5, $delay);
}

function drip_ghost_mode(int $base_seconds, array $step, int $contactId, int $step_index = 0): int
{
    $delay = drip_compute_effective_delay(
        'ghost', $base_seconds, $contactId,
        (int)($step['id'] ?? 0), $step_index,
        (int)($step['jitter_range_seconds'] ?? 900)
    );
    return time() + max(3600, $delay);
}

function drip_chaos_mode(int $base_seconds, array $step, int $contactId, int $step_index = 0): int
{
    $delay = drip_compute_effective_delay(
        'chaos', $base_seconds, $contactId,
        (int)($step['id'] ?? 0), $step_index,
        (int)($step['jitter_range_seconds'] ?? 600)
    );
    return time() + max(5, $delay);
}

function drip_apply_business_hours(
    int $timestamp,
    int $contactId,
    int $openHour = 10,
    int $openMin  = 30,
    int $extra_jitter_sec = 0
): int {
    $hour = (int)date('G', $timestamp);
    $min  = (int)date('i', $timestamp);

    if ($hour >= DRIP_BH_START_HOUR && $hour < DRIP_BH_END_HOUR) {
        $jitter = ($contactId > 0) ? ($contactId % 37) : 0;
        return $timestamp + $jitter * 60 + mt_rand(0, min(600, $extra_jitter_sec));
    }

    $seed = $contactId + $hour * 1000;
    $jitter = drip_seeded_rand($seed) % 20;

    if ($hour >= DRIP_BH_END_HOUR) {
        $nextDay = strtotime('+1 day', strtotime(date('Y-m-d', $timestamp) . ' 00:00:00'));
        return strtotime(date('Y-m-d', $nextDay) . sprintf(' %02d:%02d:00', $openHour, $openMin + $jitter))
            + mt_rand(0, min(600, $extra_jitter_sec));
    }

    return strtotime(date('Y-m-d', $timestamp) . sprintf(' %02d:%02d:00', $openHour, $openMin + $jitter))
        + mt_rand(0, min(600, $extra_jitter_sec));
}

function drip_compute_send_timestamp(
    int $base_seconds,
    array $step,
    int $contactId,
    int $step_index = 0,
    bool $respect_bh = true
): int {
    $mode = strtolower((string)($step['randomization_mode'] ?? 'human'));

    switch ($mode) {
        case 'chaos':
            $ts = drip_chaos_mode($base_seconds, $step, $contactId, $step_index);
            break;
        case 'ghost':
            $ts = drip_ghost_mode($base_seconds, $step, $contactId, $step_index);
            break;
        case 'minimal':
            $ts = drip_minimal_mode($base_seconds, $step, $contactId);
            break;
        case 'none':
            $ts = time() + max(5, $base_seconds);
            break;
        case 'human':
        default:
            $ts = drip_human_mode($base_seconds, $step, $contactId, $respect_bh);
            break;
    }

    // Business-hours clamp only for long delays (>= 1h). Short second/minute
    // cadences are conversational bursts — they send immediately at any hour.
    if ($respect_bh && $mode !== 'chaos' && $base_seconds >= 3600) {
        $extraJitter = (int)($step['jitter_range_seconds'] ?? 300);
        $ts = drip_apply_business_hours($ts, $contactId, 10, 30, $extraJitter);
    } elseif (!$respect_bh && $mode !== 'chaos') {
        $seed  = $contactId * 31 + ($step['id'] ?? 0);
        $minutes = drip_seeded_rand($seed) % 15;
        $ts += $minutes * 60 + drip_seeded_rand($seed + 7) % 60;
    }

    return (int)$ts;
}

function drip_delay_to_seconds(int $days, int $hours, int $minutes, int $seconds = 0): int
{
    return ($days * 86400) + ($hours * 3600) + ($minutes * 60) + max(0, $seconds);
}
