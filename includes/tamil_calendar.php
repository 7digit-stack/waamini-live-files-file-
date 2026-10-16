<?php
/**
 * Tamil festival dates, computed (no external service).
 *
 * Uses sidereal (Lahiri) positions of the Sun and Moon, from the standard low-precision series in
 * Meeus, "Astronomical Algorithms" (Sun to ~0.01 deg, Moon to ~0.01 deg), to find:
 *  - the Tamil solar months (Chithirai ... Panguni) and their first day,
 *  - the Moon's nakshatra (star) and tithi (lunar day) at sunrise in Chennai.
 * Festivals are then picked the way a Tamil panchangam does, e.g. Thaipusam = the day in Thai
 * when the Moon is in Pusam at sunrise. Results are checked in tests against published dates.
 */

const TAMIL_TZ_OFFSET = 5.5;      // IST
const TAMIL_SUNRISE_IST = 6.25;   // ~06:15 Chennai (varies 06:00-06:35 over the year)
const TAMIL_SUNSET_IST = 18.0;
// Tamil Nadu follows the Vakya panchangam, whose sankranti runs a few hours behind the modern (Drik)
// calculation: a month starts the same day only when the Sun changes sign before about 2:30 pm.
const TAMIL_MONTH_CUTOFF_IST = 14.5;

/** Julian Day for a UT date/time. */
function tamil_jd(int $y, int $m, float $d): float
{
    if ($m <= 2) { $y -= 1; $m += 12; }
    $a = intdiv($y, 100);
    $b = 2 - $a + intdiv($a, 4);
    return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $b - 1524.5;
}

function tamil_norm(float $deg): float
{
    $deg = fmod($deg, 360.0);
    return $deg < 0 ? $deg + 360.0 : $deg;
}

/** Lahiri ayanamsa in degrees (linear fit, good to a few arc-seconds this century). */
function tamil_ayanamsa(float $jd): float
{
    return 23.85310 + ($jd - 2451545.0) / 365.25 * (50.2796 / 3600.0);
}

/** Apparent tropical longitude of the Sun (degrees). */
function tamil_sun_lon(float $jd): float
{
    $t = ($jd - 2451545.0) / 36525.0;
    $l0 = 280.46646 + 36000.76983 * $t + 0.0003032 * $t * $t;
    $m = deg2rad(357.52911 + 35999.05029 * $t - 0.0001537 * $t * $t);
    $c = (1.914602 - 0.004817 * $t - 0.000014 * $t * $t) * sin($m)
       + (0.019993 - 0.000101 * $t) * sin(2 * $m) + 0.000289 * sin(3 * $m);
    $om = deg2rad(125.04 - 1934.136 * $t);
    return tamil_norm($l0 + $c - 0.00569 - 0.00478 * sin($om));
}

/** Apparent tropical longitude of the Moon (degrees), main periodic terms of Meeus ch. 47. */
function tamil_moon_lon(float $jd): float
{
    $t = ($jd - 2451545.0) / 36525.0;
    $lp = 218.3164477 + 481267.88123421 * $t - 0.0015786 * $t * $t;
    $d  = deg2rad(297.8501921 + 445267.1114034 * $t - 0.0018819 * $t * $t);
    $m  = deg2rad(357.5291092 + 35999.0502909 * $t - 0.0001536 * $t * $t);
    $mp = deg2rad(134.9633964 + 477198.8675055 * $t + 0.0087414 * $t * $t);
    $f  = deg2rad(93.2720950 + 483202.0175233 * $t - 0.0036539 * $t * $t);
    $e  = 1 - 0.002516 * $t - 0.0000074 * $t * $t;
    // [coefficient, D, M, M', F]
    static $terms = [
        [6.288774, 0, 0, 1, 0], [1.274027, 2, 0, -1, 0], [0.658314, 2, 0, 0, 0], [0.213618, 0, 0, 2, 0],
        [-0.185116, 0, 1, 0, 0], [-0.114332, 0, 0, 0, 2], [0.058793, 2, 0, -2, 0], [0.057066, 2, -1, -1, 0],
        [0.053322, 2, 0, 1, 0], [0.045758, 2, -1, 0, 0], [-0.040923, 0, 1, -1, 0], [-0.034720, 1, 0, 0, 0],
        [-0.030383, 0, 1, 1, 0], [0.015327, 2, 0, 0, -2], [-0.012528, 0, 0, 1, 2], [0.010980, 0, 0, 1, -2],
        [0.010675, 4, 0, -1, 0], [0.010034, 0, 0, 3, 0], [0.008548, 4, 0, -2, 0], [-0.007888, 2, 1, -1, 0],
        [-0.006766, 2, 1, 0, 0], [-0.005163, 1, 0, -1, 0], [0.004987, 1, 1, 0, 0], [0.004036, 2, -1, 1, 0],
        [0.003994, 2, 0, 2, 0], [0.003861, 4, 0, 0, 0], [0.003665, 2, 0, -3, 0], [-0.002689, 0, 1, -2, 0],
        [-0.002602, 2, 0, -1, 2], [0.002390, 2, -1, -2, 0], [-0.002348, 1, 0, 1, 0], [0.002236, 2, -2, 0, 0],
        [-0.002120, 0, 1, 2, 0], [-0.002069, 0, 2, 0, 0], [0.002048, 2, -2, -1, 0], [-0.001773, 2, 0, 1, -2],
        [-0.001595, 2, 0, 0, 2], [0.001215, 4, -1, -1, 0], [-0.001110, 0, 0, 2, 2], [-0.000892, 3, 0, -1, 0],
        [-0.000810, 2, 1, 1, 0], [0.000759, 4, -1, -2, 0], [-0.000713, 0, 2, -1, 0], [-0.000700, 2, 2, -1, 0],
        [0.000691, 2, 1, -2, 0], [0.000596, 2, -1, 0, -2], [0.000549, 4, 0, 1, 0], [0.000537, 0, 0, 4, 0],
        [0.000520, 4, -1, 0, 0], [-0.000487, 1, 0, -2, 0], [-0.000399, 2, 1, 0, -2], [-0.000381, 0, 0, 2, -2],
        [0.000351, 1, 1, 1, 0], [-0.000340, 3, 0, -2, 0], [0.000330, 4, 0, -3, 0], [0.000327, 2, -1, 2, 0],
        [-0.000323, 0, 2, 1, 0], [0.000299, 1, 1, -1, 0], [0.000294, 2, 0, 3, 0],
    ];
    $sum = 0.0;
    foreach ($terms as [$c, $kd, $km, $kmp, $kf]) {
        $arg = $kd * $d + $km * $m + $kmp * $mp + $kf * $f;
        $ef = abs($km) === 1 ? $e : (abs($km) === 2 ? $e * $e : 1.0);
        $sum += $c * $ef * sin($arg);
    }
    // Additive terms (Venus, Jupiter, flattening)
    $a1 = deg2rad(119.75 + 131.849 * $t);
    $a2 = deg2rad(53.09 + 479264.290 * $t);
    $lpr = deg2rad($lp);
    $sum += 0.003958 * sin($a1) + 0.001962 * sin($lpr - $f) + 0.000318 * sin($a2);
    $om = deg2rad(125.04452 - 1934.136261 * $t);
    return tamil_norm($lp + $sum - 0.00478 * sin($om)); // + nutation in longitude (main term)
}

/** Sidereal Sun / Moon longitudes at a local IST date and hour. */
function tamil_positions(string $date, float $hourIst): array
{
    [$y, $mo, $da] = array_map('intval', explode('-', $date));
    $jd = tamil_jd($y, $mo, $da + ($hourIst - TAMIL_TZ_OFFSET) / 24.0);
    $ay = tamil_ayanamsa($jd);
    return [tamil_norm(tamil_sun_lon($jd) - $ay), tamil_norm(tamil_moon_lon($jd) - $ay)];
}

/** Nakshatra index 0..26 (0 = Ashwini, 2 = Karthigai, 7 = Pusam, 11 = Uthiram, 15 = Visakam). */
function tamil_nakshatra(string $date, float $hourIst): int
{
    [, $moon] = tamil_positions($date, $hourIst);
    return (int)floor($moon / (360 / 27));
}

/** Tithi 1..30 (15 = Pournami, 30 = Amavasai, 11 = Shukla Ekadasi). */
function tamil_tithi(string $date, float $hourIst): int
{
    [$sun, $moon] = tamil_positions($date, $hourIst);
    return (int)floor(tamil_norm($moon - $sun) / 12) + 1;
}

/** Sun's sidereal sign 0..11 (0 = Mesha / Chithirai ... 9 = Makara / Thai, 11 = Meena / Panguni). */
function tamil_sun_sign(string $date, float $hourIst): int
{
    [$sun] = tamil_positions($date, $hourIst);
    return (int)floor($sun / 30);
}

/**
 * First day of a Tamil month (sign 0..11) that starts in the given Gregorian year.
 * The month starts on the day of the sankranti if the Sun enters the sign before TAMIL_MONTH_CUTOFF_IST,
 * otherwise on the next day (matches the Tamil Nadu government calendar).
 */
function tamil_month_start(int $year, int $sign): ?string
{
    // Approximate civil date of each sankranti, then search around it
    static $approx = [0 => '04-14', 1 => '05-15', 2 => '06-15', 3 => '07-17', 4 => '08-17', 5 => '09-17',
                      6 => '10-17', 7 => '11-16', 8 => '12-16', 9 => '01-14', 10 => '02-13', 11 => '03-14'];
    $base = strtotime($year . '-' . $approx[$sign]);
    for ($i = -4; $i <= 4; $i++) {
        $day = date('Y-m-d', $base + $i * 86400);
        // Sign at this day's cut-off vs the previous day's cut-off
        $prev = date('Y-m-d', $base + ($i - 1) * 86400);
        if (tamil_sun_sign($day, TAMIL_MONTH_CUTOFF_IST) === $sign && tamil_sun_sign($prev, TAMIL_MONTH_CUTOFF_IST) !== $sign) {
            return $day;
        }
    }
    return null;
}

/** All days of a Tamil month as Y-m-d (from its first day until the next month starts). */
function tamil_month_days(int $year, int $sign): array
{
    $start = tamil_month_start($year, $sign);
    if (!$start) return [];
    $nextSign = ($sign + 1) % 12;
    $nextYear = $sign === 8 ? $year + 1 : $year; // only Margazhi (Dec) runs into the next year (Thai, Jan)
    $end = tamil_month_start($nextYear, $nextSign);
    $days = [];
    for ($t = strtotime($start); $end && date('Y-m-d', $t) < $end; $t += 86400) $days[] = date('Y-m-d', $t);
    return $days;
}

/**
 * Day in a Tamil month when the Moon is in the given nakshatra: at sunrise for day festivals, or at any
 * time in the evening (after sunset) for evening festivals such as Karthigai Deepam, when lamps are lit.
 * A month can contain the star twice; the festival is the one paired with the month's own full moon,
 * so the candidate nearest to the Pournami that falls inside the month is chosen.
 */
function tamil_star_day(int $year, int $sign, int $nak, bool $evening = false): ?string
{
    $days = tamil_month_days($year, $sign);
    $hours = $evening ? [TAMIL_SUNSET_IST, 19.0, 20.5, 22.0, 23.9] : [TAMIL_SUNRISE_IST];
    $has = static function (string $d, array $hs) use ($nak): bool {
        foreach ($hs as $h) if (tamil_nakshatra($d, $h) === $nak) return true;
        return false;
    };
    $cands = array_values(array_filter($days, static fn($d) => $has($d, $hours)));
    if (!$cands) $cands = array_values(array_filter($days, static fn($d) => $has($d, [0.0, 12.0, 23.5]))); // star skips the check times
    if (!$cands) return null;
    // The month's full moon (Pournami at some time of the day)
    $pournami = null;
    foreach ($days as $d) {
        foreach ([TAMIL_SUNRISE_IST, 12.0, 18.0, 23.5] as $h) if (tamil_tithi($d, $h) === 15) { $pournami = $d; break 2; }
    }
    $ref = $pournami ? strtotime($pournami) : null;
    usort($cands, static function ($a, $b) use ($ref) {
        if ($ref !== null) return abs(strtotime($a) - $ref) <=> abs(strtotime($b) - $ref);
        return 0;
    });
    return $cands[0];
}

/** Day in a Tamil month whose sunrise has the given tithi (else the day it occurs). */
function tamil_tithi_day(int $year, int $sign, int $tithi): ?string
{
    $days = tamil_month_days($year, $sign);
    foreach ($days as $d) {
        if (tamil_tithi($d, TAMIL_SUNRISE_IST) === $tithi) return $d;
    }
    foreach ($days as $d) {
        foreach ([12.0, 18.0, 23.5] as $h) if (tamil_tithi($d, $h) === $tithi) return $d;
    }
    return null;
}

/**
 * Tamil Nadu festivals in a Gregorian year.
 * Returns [['name', 'date', 'type' => holiday|observance]]; 'holiday' = Tamil Nadu government holiday.
 */
function tamil_festivals(int $year): array
{
    $out = [];
    $add = static function (string $name, ?string $date, string $type) use (&$out) {
        if ($date) $out[] = [$name, $date, $type];
    };
    $thai = tamil_month_start($year, 9);
    if ($thai) {
        $add('Bhogi', date('Y-m-d', strtotime($thai . ' -1 day')), 'observance');
        $add('Thai Pongal', $thai, 'holiday');
        $add('Thiruvalluvar Day', date('Y-m-d', strtotime($thai . ' +1 day')), 'holiday');
        $add('Uzhavar Thirunal', date('Y-m-d', strtotime($thai . ' +2 days')), 'holiday');
    }
    $add('Thaipusam', tamil_star_day($year, 9, 7), 'holiday');
    $add('Thai Amavasai', tamil_tithi_day($year, 9, 30), 'observance');
    $add('Masi Magam', tamil_star_day($year, 10, 9), 'observance');
    $add('Panguni Uthiram', tamil_star_day($year, 11, 11), 'observance');
    // Puthandu: Tamil Nadu keeps it on 14 April by convention (the Vakya sankranti rarely moves it)
    $add('Tamil New Year', sprintf('%04d-04-14', $year), 'holiday');
    $add('Chithra Pournami', tamil_tithi_day($year, 0, 15), 'observance');
    $add('Vaikasi Visakam', tamil_star_day($year, 1, 15), 'observance');
    $aadi = tamil_month_start($year, 3);
    $add('Aadi Amavasai', tamil_tithi_day($year, 3, 30), 'observance');
    if ($aadi) $add('Aadi Perukku', date('Y-m-d', strtotime($aadi . ' +17 days')), 'observance');
    $add('Tamil Nadu Day', sprintf('%04d-07-18', $year), 'observance');
    $add('Mahalaya Amavasai', tamil_tithi_day($year, 5, 30), 'observance');
    $add('Karthigai Deepam', tamil_star_day($year, 7, 2, true), 'observance'); // lamps lit in the evening
    // Margazhi straddles two years: take the one whose Shukla Ekadasi falls in this year
    foreach ([$year - 1, $year] as $y) {
        $d = tamil_tithi_day($y, 8, 11);
        if ($d && (int)substr($d, 0, 4) === $year) $add('Vaikunta Ekadasi', $d, 'observance');
    }
    return $out;
}
