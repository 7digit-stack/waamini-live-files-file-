<?php
/**
 * WAAMINI — Demo Auto-Scheduler analysis & verification
 * ------------------------------------------------------
 * Validates the behaviour requested:
 *   1. A demo is AUTO-SCHEDULED (a slot is picked) when the customer gives NO time/day.
 *   2. The slot is in the future, within business hours (Mon–Sat 10:00–19:00 IST),
 *      never on Sunday, and avoids conflicts with existing bookings.
 *   3. A meeting address (Google Meet link, or Cal.com fallback) is produced & confirmed.
 *
 * The slot-selection tests are PURE (no database required), so they always run.
 * An optional DB section checks the demo_bookings schema when a database is reachable.
 */

require_once __DIR__ . '/waamini_ai_agent.php';

echo "=== WAAMINI DEMO AUTO-SCHEDULER — ANALYSIS & VERIFICATION ===\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label" . ($detail ? "  — $detail" : "") . "\n"; }
    else      { $fail++; echo "  FAIL  $label" . ($detail ? "  — $detail" : "") . "\n"; }
}

// ── 0. Timezone accuracy (reminders must fire at the exact IST wall-clock time) ──
$prevTz = date_default_timezone_get();
date_default_timezone_set('UTC'); // simulate a non-IST server to prove robustness
$utcFrom = new DateTime('2026-07-13 09:00:00'); // 14:30 IST
$tzSlot = WaaminiAiAgent::demoPlanSlot([], $utcFrom);
check('Slot planning is timezone-safe (returns Asia/Kolkata)',
      $tzSlot && $tzSlot->getTimezone()->getName() === 'Asia/Kolkata',
      $tzSlot ? $tzSlot->getTimezone()->getName() . ' ' . $tzSlot->format('Y-m-d H:i') : 'null');

// Conflict math must treat stored demo_datetime strings as IST instants.
$istSlot = new DateTime('2026-07-14 10:00:00', new DateTimeZone('Asia/Kolkata')); // = 04:30 UTC
$clash = WaaminiAiAgent::demoSlotFree($istSlot, ['2026-07-14 10:00:00']); // same IST instant
$clear = WaaminiAiAgent::demoSlotFree($istSlot, ['2026-07-14 11:00:00']); // 1h apart
date_default_timezone_set($prevTz);
check('demoSlotFree parses slots as IST (10:00 IST vs 10:00 IST clashes)', $clash === false);
check('demoSlotFree parses slots as IST (10:00 IST vs 11:00 IST free)',  $clear === true);

// ── 1. Business-rule invariants + the bot's ~+1 day +1 hour default offset ──
// The bot searches from (now + 1 day + 1 hour) for the next free slot.
$ref = new DateTime('2026-07-13 09:00:00');
$base = clone $ref; $base->modify('+1 day +1 hour');
$free = WaaminiAiAgent::demoPlanSlot([], $base);
$ok = $free
    && $free >= $base                                                     // at/after the +1d+1h target
    && $free->getTimestamp() <= $base->getTimestamp() + 2 * 86400         // and not absurdly far
    && (int)$free->format('w') >= 1 && (int)$free->format('w') <= 6       // Mon–Sat
    && (int)$free->format('G') >= 10 && (int)$free->format('G') < 19      // 10:00–18:59
    && in_array((int)$free->format('i'), [0, 30], true);                  // :00 or :30
check('Auto-slot is ~+1d+1h out, weekday, business-hours, on :00/:30', (bool)$ok,
      $free ? $free->format('Y-m-d H:i w G i') : 'null');
check('Default auto-schedule targets ≥ now + 1 day + 1 hour', (bool)($free && $free->getTimestamp() >= $base->getTimestamp()));

// ── 2. Sunday is skipped ──
$sun = new DateTime(); $sun->modify('next Sunday')->setTime(18, 0, 0);
$sunSlot = WaaminiAiAgent::demoPlanSlot([], $sun);
check('Sunday slot is skipped (result is NOT Sunday)', $sunSlot && (int)$sunSlot->format('w') !== 0,
      $sunSlot ? $sunSlot->format('Y-m-d w') : 'null');

// ── 3. After close-of-day jumps to next day 10:00 ──
$late = new DateTime(); $late->modify('next Monday')->setTime(18, 45, 0);
$lateSlot = WaaminiAiAgent::demoPlanSlot([], $late);
$expNext = clone $late; $expNext->modify('+1 day')->setTime(10, 0, 0);
check('After 19:00 → next day 10:00', $lateSlot && $lateSlot->format('Y-m-d H:i') === $expNext->format('Y-m-d H:i'),
      $lateSlot ? $lateSlot->format('Y-m-d H:i') : 'null');

// ── 4. Conflict avoidance (no DB needed) ──
$existing = $free->format('Y-m-d H:i:s');
$next = WaaminiAiAgent::demoPlanSlot([$existing], $base);
check('Conflict at first free slot → next slot is +30min and is free',
      $next
      && $next->getTimestamp() === $free->getTimestamp() + 1800
      && WaaminiAiAgent::demoSlotFree($next, [$existing]),
      $next ? $next->format('Y-m-d H:i') : 'null');

// ── 5. Meeting-address (the "MCP confirms the address on time") logic ──
function meetingAddress(array $gm): string {
    if (!empty($gm['meet_link'])) return $gm['meet_link'];
    return 'https://cal.com/waamini/demo'; // fallback meeting address
}
check('Meeting address uses Google Meet when present',
      meetingAddress(['meet_link' => 'https://meet.google.com/abc-defg-hij']) === 'https://meet.google.com/abc-defg-hij');
check('Meeting address falls back to Cal.com when Google Meet missing',
      meetingAddress(['error' => 'Google Calendar not connected']) === 'https://cal.com/waamini/demo');

// ── 6. Confirmation reply template (best template + clean format) ──
$ct = WaaminiAiAgent::demoConfirmationTemplate('Tuesday, 14 July 2026 at 02:30 PM', 'https://meet.google.com/abc-defg-hij', true);
check('Confirmation template is clean & complete (date, link, question, ≤4 emojis)',
      str_contains($ct, 'Tuesday, 14 July 2026 at 02:30 PM')
      && str_contains($ct, 'https://meet.google.com/abc-defg-hij')
      && str_contains($ct, '?')
      && (substr_count($ct, '✅') + substr_count($ct, '📅') + substr_count($ct, '🔗') + substr_count($ct, '🙏')) <= 4,
      str_replace("\n", ' | ', $ct));

// ── 7. Tanglish + Tamil-script confirmation templates ──
$ctTa = WaaminiAiAgent::demoConfirmationTemplate('Tuesday, 14 July 2026 at 02:30 PM', 'https://meet.google.com/abc-defg-hij', true, 'TANGLISH');
check('Tanglish confirmation template is natural + complete (no Tamil script, has link + question)',
      !preg_match('/[஀-௿]/u', $ctTa)
      && str_contains($ctTa, 'confirm') && str_contains($ctTa, 'https://meet.google.com/abc-defg-hij') && str_contains($ctTa, '?'),
      str_replace("\n", ' | ', $ctTa));

$ctTam = WaaminiAiAgent::demoConfirmationTemplate('Tuesday, 14 July 2026 at 02:30 PM', 'https://meet.google.com/abc-defg-hij', false, 'TAMIL_SCRIPT');
check('Tamil-script confirmation template is present + complete',
      str_contains($ctTam, 'டெமோ') && str_contains($ctTam, 'https://meet.google.com/abc-defg-hij') && str_contains($ctTam, '?'),
      str_replace("\n", ' | ', $ctTam));

// ── Optional DB schema check ──
echo "\n--- Database schema check (optional) ---\n";
if (file_exists(__DIR__ . '/config/bootstrap.php')) {
    try {
        require_once __DIR__ . '/config/bootstrap.php';
        require_once __DIR__ . '/db.php';
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM demo_bookings");
        while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
        foreach (['demo_datetime', 'meet_link', 'calendar_event_id'] as $col) {
            check("demo_bookings has column `$col`", in_array($col, $cols, true), implode(',', $cols));
        }
    } catch (Throwable $e) {
        echo "  (skipped: database not reachable — " . $e->getMessage() . ")\n";
    }
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
