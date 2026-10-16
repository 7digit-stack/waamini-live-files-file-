<?php
require_once '../includes/client_auth_check.php';
require_once '../includes/drip_engine.php'; // loads interval engine, variator, schema guard + DripOptimizerService

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_feature('feat_drip_campaign')) {
    csrf_verify();

    $campaignId     = intval($_POST['drip_campaign_id']);
    $delayDays      = intval($_POST['delay_days']);
    $delayHours     = intval($_POST['delay_hours']);
    $delayMinutes   = intval($_POST['delay_minutes'] ?? 0);
    $delaySeconds   = intval($_POST['delay_seconds'] ?? 0);
    $templateId     = intval($_POST['template_id']);
    $messageText    = sanitize($_POST['message_text'] ?? '');
    $randomMode     = strtolower(sanitize($_POST['randomization_mode'] ?? 'human'));
    $jitterRange    = intval($_POST['jitter_range_seconds'] ?? 300);
    $maxVariants    = intval($_POST['max_variants'] ?? 3);
    $companyId      = $_SESSION['company_id'];

    $allowedModes  = ['none', 'minimal', 'human', 'ghost', 'chaos'];
    $randomMode    = in_array($randomMode, $allowedModes, true) ? $randomMode : 'human';
    $delayDays     = max(0, $delayDays);
    $delayHours    = max(0, min(23, $delayHours));
    $delayMinutes  = max(0, min(59, $delayMinutes));
    $delaySeconds  = max(0, min(59, $delaySeconds));
    $jitterRange   = max(0, min(3600, $jitterRange));
    $maxVariants   = max(1, min(5, $maxVariants));

    // Text-only steps are allowed (engine sends message_text when no template)
    if ($campaignId > 0 && ($templateId > 0 || $messageText !== '')) {
        drip_champion_ensure_schema($conn);

        $check = $conn->prepare(
            "SELECT id, trigger_label FROM drip_campaigns WHERE id=? AND company_id=? LIMIT 1"
        );
        $check->bind_param("ii", $campaignId, $companyId);
        $check->execute();
        $camp = $check->get_result()->fetch_assoc();
        $check->close();

        if ($camp) {
            $tplIdOrNull = $templateId > 0 ? $templateId : null;
            $stmt = $conn->prepare(
                "INSERT INTO drip_steps
                    (drip_campaign_id, delay_days, delay_hours, delay_minutes, delay_seconds,
                     template_id, message_text, randomization_mode, jitter_range_seconds, max_variants)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "iiiiiisssi",
                $campaignId, $delayDays, $delayHours, $delayMinutes, $delaySeconds,
                $tplIdOrNull, $messageText, $randomMode, $jitterRange, $maxVariants
            );
            $stmt->execute();
            $newStepId = (int) $conn->insert_id;
            $stmt->close();

            if ($newStepId > 0 && $messageText !== '' && class_exists('DripOptimizerService')) {
                try {
                    $optimizer = new DripOptimizerService($conn);
                    $optimizer->generateStepVariants($companyId, $campaignId, $newStepId);
                } catch (\Throwable $e) {
                    error_log("[DripStepAdd] Variant generation failed: " . $e->getMessage());
                }
            }

            if ($newStepId > 0) {
                $trigger = trim((string)($camp['trigger_label'] ?? ''));
                if ($trigger !== '') {
                    $contactsStmt = $conn->prepare(
                        "SELECT id, chat_labels, tags
                         FROM contacts
                         WHERE company_id=?
                           AND ((chat_labels IS NOT NULL AND TRIM(chat_labels) != '')
                            OR (tags IS NOT NULL AND TRIM(tags) != ''))"
                    );
                    if ($contactsStmt) {
                        $contactsStmt->bind_param('i', $companyId);
                        $contactsStmt->execute();
                        $contactsRes = $contactsStmt->get_result();
                        $contactsStmt->close();

                        $baseSecs = drip_delay_to_seconds($delayDays, $delayHours, $delayMinutes, $delaySeconds);

                        while ($c = $contactsRes->fetch_assoc()) {
                            $contactId = (int)($c['id'] ?? 0);
                            if ($contactId <= 0) continue;

                            $labelsRaw = trim(($c['chat_labels'] ?? '') . ',' . ($c['tags'] ?? ''), ',');
                            $matched   = false;
                            foreach (explode(',', $labelsRaw) as $p) {
                                $p = trim($p);
                                if ($p !== '' && strcasecmp($p, $trigger) === 0) { $matched = true; break; }
                            }
                            if (!$matched) continue;

                            $exists = $conn->prepare(
                                "SELECT id FROM drip_executions WHERE company_id=? AND contact_id=? AND drip_step_id=? LIMIT 1"
                            );
                            if ($exists) {
                                $exists->bind_param('iii', $companyId, $contactId, $newStepId);
                                $exists->execute();
                                $has = (bool)$exists->get_result()->fetch_assoc();
                                $exists->close();
                                if ($has) continue;
                            }

                            // Backfill schedules ONLY this new step for existing enrollees
                            // (full enrollContact would duplicate earlier steps).
                            $stepRow = [
                                'id'                    => $newStepId,
                                'delay_days'            => $delayDays,
                                'delay_hours'           => $delayHours,
                                'delay_minutes'         => $delayMinutes,
                                'delay_seconds'         => $delaySeconds,
                                'randomization_mode'    => $randomMode,
                                'jitter_range_seconds'  => $jitterRange,
                                'max_variants'          => $maxVariants,
                            ];

                            $ts = drip_compute_send_timestamp($baseSecs, $stepRow, $contactId, 0, true);
                            $scheduledFor = date('Y-m-d H:i:s', $ts);
                            $variants     = drip_generate_variants($messageText, '', $contactId, $maxVariants, $newStepId);
                            $variationsJson = empty($variants) ? '[]' : json_encode(array_values($variants), JSON_UNESCAPED_UNICODE);

                            $ins = $conn->prepare(
                                "INSERT INTO drip_executions
                                    (company_id, contact_id, drip_step_id, status, scheduled_for, message_variations)
                                 VALUES (?, ?, ?, 'pending', ?, ?)"
                            );
                            if ($ins) {
                                $ins->bind_param('iiiss', $companyId, $contactId, $newStepId, $scheduledFor, $variationsJson);
                                $ins->execute();
                                $ins->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

header("Location: ../client/premium_drip_builder.php?id=" . $campaignId);
exit();
