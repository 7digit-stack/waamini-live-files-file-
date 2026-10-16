<?php
// includes/drip_engine.php
//
// Drip Champion — CRM Trigger Engine (enhanced)
// Called from includes/ai_crm_sync.php on every incoming message
// AND from api/drip_toggle.php on campaign activation.
//
// Delegates timing to drip_interval_engine.php and scheduling
// orchestration to services/DripOptimizerService.php.
// Falls back to original smart-send-time logic if DripOptimizerService
// is unavailable (backward-compatible).

require_once __DIR__ . '/drip_interval_engine.php';
require_once __DIR__ . '/drip_message_variator.php';
require_once __DIR__ . '/drip_champion_schema.php';
if (file_exists(__DIR__ . '/../services/DripOptimizerService.php')) {
    require_once __DIR__ . '/../services/DripOptimizerService.php';
}

/**
 * Smart send-time backward-compatible shim.
 * Migrated logic → now delegates to drip_interval_engine.php.
 * Kept public so cron files referencing it do not break.
 */
function drip_smart_send_time(int $timestamp, int $contactId = 0): int
{
    return drip_apply_business_hours($timestamp, $contactId ?: 1, 10, 30, 300);
}

/**
 * Trigger drip campaign enrollment for a contact.
 *
 * Enhanced to:
 *  1. Use DripOptimizerService if available (full randomization + variants)
 *  2. Fall back to inline enrollment using drip_interval_engine
 *  3. Support enrollment on activation (drip_toggle.php path)
 *  4. Log structured enrollment event
 *
 * @param mysqli $conn
 * @param int    $companyId
 * @param int    $contactId
 * @param string|null $labelsString  Pre-resolved labels; null = fetch from DB
 * @param bool   $forceEnroll  Skip dedup check (used on re-activation)
 * @return void
 */
function trigger_drip_campaigns($conn, int $companyId, int $contactId, ?string $labelsString = null, bool $forceEnroll = false): void
{
    if ($companyId <= 0 || $contactId <= 0) return;

    drip_champion_ensure_schema($conn);
    error_log("[DRIP ENGINE] trigger_drip_campaigns called company=$companyId contact=$contactId force=" . ($forceEnroll ? '1' : '0'));

    // ── 1. Load contact + blacklist check ──────────────────────────────────
    $cStmt = $conn->prepare(
        "SELECT id, phone, chat_labels, tags, name
         FROM contacts WHERE id=? AND company_id=? LIMIT 1"
    );
    if (!$cStmt) return;
    $cStmt->bind_param("ii", $contactId, $companyId);
    $cStmt->execute();
    $cRes = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();
    if (!$cRes) return;

    $phone       = trim((string)($cRes['phone'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    $phoneLast10 = substr($phoneDigits, -10);

    // Blacklist
    if ($phoneLast10 !== '') {
        $blStmt = $conn->prepare(
            "SELECT id FROM blacklist WHERE company_id=? AND (phone LIKE ? OR phone LIKE ?) LIMIT 1"
        );
        if ($blStmt) {
            $pat1 = '%' . $phoneLast10;
            $pat2 = '%' . $phoneLast10 . '%';
            $blStmt->bind_param("iss", $companyId, $pat1, $pat2);
            $blStmt->execute();
            if ($blStmt->get_result()->num_rows > 0) { $blStmt->close(); return; }
            $blStmt->close();
        }
    }

    // ── 2. Resolve labels ──────────────────────────────────────────────────
    if ($labelsString === null) {
        $labelsString = ($cRes['chat_labels'] ?? '') . ',' . ($cRes['tags'] ?? '');
    }
    $labelsString = trim($labelsString, ", \t\n\r\0\x0B");
    if ($labelsString === '') return;
    $labels = array_filter(array_map('trim', explode(',', $labelsString)), fn($v) => $v !== '');
    if (empty($labels)) return;

    // ── 3. Find matching active campaigns ──────────────────────────────────
    $stmt = $conn->prepare(
        "SELECT id, trigger_label, global_randomization, respect_business_hours
         FROM drip_campaigns
         WHERE company_id = ? AND status = 'active'
         ORDER BY id ASC"
    );
    if (!$stmt) return;
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $activeCampaigns = [];
    $seenTriggers    = [];
    while ($c = $res->fetch_assoc()) {
        $trigger = trim((string)($c['trigger_label'] ?? ''));
        if ($trigger === '' || in_array($trigger, $seenTriggers, true)) continue;
        foreach ($labels as $l) {
            if (strcasecmp($l, $trigger) === 0) {
                $activeCampaigns[] = [
                    'id'         => (int)$c['id'],
                    'trigger'    => $trigger,
                ];
                $seenTriggers[] = $trigger;
                break;
            }
        }
    }
    if (empty($activeCampaigns)) return;

    // ── 4. Enroll via DripOptimizerService (preferred) ─────────────────────
    $hasOptimizer = class_exists('DripOptimizerService');

    foreach ($activeCampaigns as $campData) {
        $campaignId = $campData['id'];

        // Dedup check
        if (!$forceEnroll) {
            $ckeck = $conn->prepare(
                "SELECT e.id FROM drip_executions e
                 JOIN drip_steps s ON e.drip_step_id = s.id
                 WHERE e.contact_id = ?
                   AND e.company_id = ?
                   AND s.drip_campaign_id = ?
                   AND e.status IN ('pending','processing','sent')
                 LIMIT 1"
            );
            if ($ckeck) {
                $ckeck->bind_param("iii", $contactId, $companyId, $campaignId);
                $ckeck->execute();
                if ($ckeck->get_result()->num_rows > 0) { $ckeck->close(); continue; }
                $ckeck->close();
            }
        }

        if ($hasOptimizer) {
            try {
                $optimizer = new DripOptimizerService($conn);
                $ok = $optimizer->enrollContact($companyId, $contactId, $campaignId, false);
                error_log("[DRIP ENGINE] DripOptimizerService enrollment c=$companyId ct=$contactId camp=$campaignId ok=" . ($ok ? '1' : '0'));
                continue;
            } catch (\Throwable $e) {
                error_log("[DRIP ENGINE] DripOptimizerService threw: " . $e->getMessage() . " — falling back to inline engine");
            }
        }

        // ── 5. Fallback: inline enrollment using drip_interval_engine ────────
        inlineEnrollContact($conn, $companyId, $contactId, $campaignId, $cRes);
    }
}

/**
 * Inline enrollment fallback (used when DripOptimizerService is unavailable).
 * Uses drip_interval_engine for timing but without pre-generated variant storage.
 *
 * @param mysqli $conn
 * @param int    $companyId
 * @param int    $contactId
 * @param int    $campaignId
 * @param array  $contactRow
 * @return void
 */
function inlineEnrollContact(mysqli $conn, int $companyId, int $contactId, int $campaignId, array $contactRow): void
{
    // Load campaign + steps
    $campStmt = $conn->prepare(
        "SELECT id, global_randomization, respect_business_hours
         FROM drip_campaigns WHERE id = ? AND company_id = ? LIMIT 1"
    );
    if (!$campStmt) return;
    $campStmt->bind_param('ii', $campaignId, $companyId);
    $campStmt->execute();
    $camp = $campStmt->get_result()->fetch_assoc();
    $campStmt->close();
    if (!$camp) return;

    $stepsStmt = $conn->prepare(
        "SELECT id, delay_days, delay_hours, COALESCE(delay_minutes,0) AS delay_minutes,
                COALESCE(delay_seconds,0) AS delay_seconds,
                COALESCE(randomization_mode,'human') AS randomization_mode,
                COALESCE(jitter_range_seconds,300) AS jitter_range_seconds,
                message_variations
         FROM drip_steps
         WHERE drip_campaign_id = ?
         ORDER BY COALESCE(step_order,0) ASC, delay_days ASC, delay_hours ASC, delay_minutes ASC, delay_seconds ASC"
    );
    if (!$stepsStmt) return;
    $stepsStmt->bind_param('i', $campaignId);
    $stepsStmt->execute();
    $stepsRes = $stepsStmt->get_result();
    $stepsStmt->close();

    // global_randomization is a TINYINT flag: 1 = per-step modes apply, 0 = exact schedule
    $randomizationOn = (int)($camp['global_randomization'] ?? 1) === 1;
    $respectBH  = (int)($camp['respect_business_hours'] ?? 1) === 1;

    $insertStmt = $conn->prepare(
        "INSERT INTO drip_executions (company_id, contact_id, drip_step_id, status, scheduled_for, message_variations, created_at)
         VALUES (?, ?, ?, 'pending', ?, ?, NOW())"
    );
    if (!$insertStmt) return;

    $idx = 0;
    while ($step = $stepsRes->fetch_assoc()) {
        if (!$randomizationOn) $step['randomization_mode'] = 'none';

        $baseSecs = drip_delay_to_seconds(
            max(0, (int)$step['delay_days']),
            max(0, (int)$step['delay_hours']),
            max(0, (int)$step['delay_minutes']),
            max(0, (int)$step['delay_seconds'])
        );

        $ts = drip_compute_send_timestamp($baseSecs, $step, $contactId, $idx, $respectBH);
        $scheduledFor = date('Y-m-d H:i:s', $ts);
        $stepIdInt = (int)$step['id'];
        $variantsJson = (string)($step['message_variations'] ?? '');

        $insertStmt->bind_param('iiiss', $companyId, $contactId, $stepIdInt, $scheduledFor, $variantsJson);
        $insertStmt->execute();
        if ($insertStmt->errno) {
            error_log("[DRIP ENGINE] Inline insert failed camp=$campaignId step={$step['id']} ct=$contactId: " . $insertStmt->error);
        }
        $idx++;
    }
    $insertStmt->close();
}
