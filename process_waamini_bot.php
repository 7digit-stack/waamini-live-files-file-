<?php
/**
 * WAAMINI Bot background worker (CLI).
 *  - answers due WhatsApp reply jobs (ai_reply_jobs)
 *  - trains queued website pages of every client
 * The webhook starts it when a job is queued. For safety also run it every minute from cron / Task Scheduler:
 *   * * * * * php /path/to/process_waamini_bot.php
 * It stays alive for about 45 seconds while there is work, then exits. Only one copy runs at a time.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/waamini_bot/bootstrap.php';
require_once __DIR__ . '/includes/ai_crm_sync.php';
require_once __DIR__ . '/includes/voice_ai.php';

@mkdir(__DIR__ . '/storage', 0775, true);
$lock = fopen(__DIR__ . '/storage/waamini_bot.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

set_time_limit(120);
$deadline = time() + 45;
$idleRounds = 0;
while (time() < $deadline) {
    // voice notes first: their transcript becomes the text the bot answers
    $work = voice_ai_process_due($conn, 3);
    $work += WB_Guard::processDue($conn, 10);

    // train queued pages (all companies)
    $q = $conn->query("SELECT DISTINCT company_id, bot_id FROM ai_knowledge_sources WHERE type = 'url' AND status = 'queued' LIMIT 5");
    while ($q && ($row = $q->fetch_assoc())) {
        $r = WB_Knowledge::tick($conn, (int)$row['company_id'], (int)$row['bot_id'], 15, 4);
        $work += (int)$r['processed'];
    }

    // follow-up after the customer went quiet (1-23 h, inside the 24-hour window); light query, run every ~15 s
    if (($fuTick = ($fuTick ?? 0) + 1) % 15 === 1) { $work += WB_Guard::processFollowups($conn, 5); }

    // AI lead outcome analyses (labels + drip campaigns)
    $work += crm_ai_process_due($conn, 5);

    $pend = $conn->query("SELECT (SELECT COUNT(*) FROM ai_reply_jobs WHERE status IN ('pending','processing')) + (SELECT COUNT(*) FROM ai_knowledge_sources WHERE status IN ('queued','training') AND type = 'url') AS n");
    $left = $pend ? (int)($pend->fetch_assoc()['n'] ?? 0) : 0;
    $vj = @$conn->query("SELECT COUNT(*) AS n FROM ai_voice_jobs WHERE status IN ('pending','processing')");
    if ($vj) { $left += (int)($vj->fetch_assoc()['n'] ?? 0); }
    $cj = @$conn->query("SELECT COUNT(*) AS n FROM crm_ai_jobs WHERE due_at <= DATE_ADD(NOW(), INTERVAL 45 SECOND)");
    if ($cj) { $left += (int)($cj->fetch_assoc()['n'] ?? 0); }
    if ($work === 0 && $left === 0) {
        if (++$idleRounds >= 3) { break; }
    } else {
        $idleRounds = 0;
    }
    sleep(1);
}
echo 'waamini bot worker done ' . date('c') . PHP_EOL;
