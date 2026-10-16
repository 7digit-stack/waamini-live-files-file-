<?php

require_once __DIR__ . '/../includes/drip_interval_engine.php';
require_once __DIR__ . '/../includes/drip_message_variator.php';
require_once __DIR__ . '/../includes/drip_champion_schema.php';
require_once __DIR__ . '/EncryptionService.php';

class DripOptimizerService
{
    public function __construct(private mysqli $conn) {}

    /**
     * Enroll a contact in a drip campaign using the Drip Champion system.
     * Uses database transactions for atomicity.
     */
    public function enrollContact(int $companyId, int $contactId, int $campaignId, bool $checkEnrollment = true): bool
    {
        if ($companyId <= 0 || $contactId <= 0 || $campaignId <= 0) return false;

        drip_champion_ensure_schema($this->conn);

        $this->conn->begin_transaction();
        try {
            $camp = $this->getCampaign($companyId, $campaignId);
            if (!$camp) { $this->conn->rollback(); return false; }

            if ($checkEnrollment && $this->isAlreadyEnrolled($contactId, $companyId, $campaignId)) {
                $this->conn->rollback();
                return false;
            }

            $steps = $this->getCampaignSteps($campaignId);
            if (empty($steps)) { $this->conn->rollback(); return false; }

            $contact = $this->getContact($companyId, $contactId);

            // global_randomization is a TINYINT flag: 1 = per-step modes apply, 0 = force exact schedule
            $randomizationOn = (int)($camp['global_randomization'] ?? 1) === 1;
            $respectBH    = (int)($camp['respect_business_hours'] ?? 1) === 1;
            $maxPerDay    = (int)($camp['max_messages_per_day'] ?? 10);
            $cooldownHrs  = (int)($camp['cooldown_hours'] ?? 4);

            $insertStmt = $this->conn->prepare(
                "INSERT INTO drip_executions
                    (company_id, contact_id, drip_step_id, status, scheduled_for,
                     message_variations, visited_variants, delivered_variant, created_at)
                 VALUES (?, ?, ?, 'pending', ?, ?, '[]', NULL, NOW())"
            );
            if (!$insertStmt) { $this->conn->rollback(); return false; }

            $success = true;
            $todaySendCount = 0;

            foreach ($steps as $idx => $step) {
                if (!$randomizationOn) $step['randomization_mode'] = 'none';

                $baseSecs = drip_delay_to_seconds(
                    max(0, (int)$step['delay_days']),
                    max(0, (int)$step['delay_hours']),
                    max(0, (int)$step['delay_minutes']),
                    max(0, (int)($step['delay_seconds'] ?? 0))
                );

                $ts = drip_compute_send_timestamp($baseSecs, $step, $contactId, $idx, $respectBH);

                if ($todaySendCount >= $maxPerDay && $idx > 0) {
                    $ts = strtotime('+' . max($cooldownHrs, 4) . ' hours', $ts);
                }

                $variants = [];
                $rawText  = trim((string)($step['message_text'] ?? ''));
                if ($rawText !== '' || !empty($step['template_id'])) {
                    $variantCount = (int)($step['max_variants'] ?? 3);
                    $variants = drip_generate_variants(
                        $rawText,
                        (string)($contact['name'] ?? ''),
                        $contactId,
                        min($variantCount, 5),
                        (int)$step['id']
                    );
                }

                $variationsJson = empty($variants) ? '[]' : json_encode(array_values($variants), JSON_UNESCAPED_UNICODE);
                $scheduledFor   = date('Y-m-d H:i:s', $ts);

                $stepIdInt = (int)$step['id'];
                $insertStmt->bind_param('iiiss', $companyId, $contactId, $stepIdInt, $scheduledFor, $variationsJson);
                $ok = $insertStmt->execute();
                if (!$ok) {
                    error_log("[DripOptimizer] Insert failed step={$step['id']} contact=$contactId: " . $insertStmt->error);
                    $success = false;
                } else {
                    $todaySendCount++;
                }
            }

            $insertStmt->close();

            if ($success) {
                $this->updateContactCrmState($companyId, $contactId, $campaignId);
                $this->conn->commit();
            } else {
                $this->conn->rollback();
            }

            return $success;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            error_log("[DripOptimizer] Exception: " . $e->getMessage());
            return false;
        }
    }

    private function getCampaign(int $companyId, int $campaignId): ?array
    {
        $st = $this->conn->prepare(
            "SELECT * FROM drip_campaigns WHERE id = ? AND company_id = ? LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('ii', $campaignId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    private function getCampaignSteps(int $campaignId): array
    {
        $steps = [];
        $st = $this->conn->prepare(
            "SELECT id, delay_days, delay_hours, COALESCE(delay_minutes,0) AS delay_minutes,
                    COALESCE(delay_seconds,0) AS delay_seconds, template_id, message_text,
                    COALESCE(randomization_mode,'human') AS randomization_mode,
                    COALESCE(jitter_range_seconds,300) AS jitter_range_seconds,
                    COALESCE(max_variants,3) AS max_variants,
                    COALESCE(step_order,0) AS step_order
             FROM drip_steps
             WHERE drip_campaign_id = ?
             ORDER BY COALESCE(step_order,0) ASC, delay_days ASC, delay_hours ASC, delay_minutes ASC, delay_seconds ASC"
        );
        if ($st) {
            $st->bind_param('i', $campaignId);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $steps[] = $r;
            $st->close();
        }
        return $steps;
    }

    private function getContact(int $companyId, int $contactId): ?array
    {
        $st = $this->conn->prepare(
            "SELECT id, name, phone, chat_labels, tags FROM contacts WHERE id=? AND company_id=? LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    private function isAlreadyEnrolled(int $contactId, int $companyId, int $campaignId): bool
    {
        $st = $this->conn->prepare(
            "SELECT e.id
             FROM drip_executions e
             JOIN drip_steps s ON e.drip_step_id = s.id
             WHERE e.contact_id = ?
               AND e.company_id = ?
               AND s.drip_campaign_id = ?
               AND e.status IN ('pending','processing','sent')
             LIMIT 1"
        );
        if (!$st) return false;
        $st->bind_param('iii', $contactId, $companyId, $campaignId);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;
        $st->close();
        return $exists;
    }

    /**
     * Update contact CRM state with drip enrollment info.
     * Uses JSON column to track campaign enrollment history.
     */
    private function updateContactCrmState(int $companyId, int $contactId, int $campaignId): void
    {
        try {
            $now = date('Y-m-d H:i:s');

            // Get existing drip_meta or create
            $get = $this->conn->prepare(
                "SELECT drip_meta FROM contacts WHERE id = ? AND company_id = ? LIMIT 1"
            );
            if (!$get) return;
            $get->bind_param('ii', $contactId, $companyId);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();
            $get->close();

            $meta = [];
            if ($row && !empty($row['drip_meta'])) {
                $decoded = json_decode($row['drip_meta'], true);
                if (is_array($decoded)) $meta = $decoded;
            }

            $meta[] = [
                'campaign_id' => $campaignId,
                'enrolled_at' => $now,
            ];

            // Keep last 20 enrollments
            if (count($meta) > 20) {
                $meta = array_slice($meta, -20);
            }

            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

            // Use a dedicated column or fall back to notes
            $checkCol = $this->conn->query("SHOW COLUMNS FROM contacts LIKE 'drip_meta'");
            if ($checkCol && $checkCol->num_rows > 0) {
                $upd = $this->conn->prepare(
                    "UPDATE contacts SET drip_meta = ? WHERE id = ? AND company_id = ?"
                );
                if ($upd) {
                    $upd->bind_param('sii', $metaJson, $contactId, $companyId);
                    $upd->execute();
                    $upd->close();
                }
            } else {
                $upd = $this->conn->prepare(
                    "UPDATE contacts SET notes = CONCAT(IFNULL(notes,''), '\n[DripChampion] enrolled in campaign $campaignId at $now')
                     WHERE id = ? AND company_id = ?"
                );
                if ($upd) {
                    $upd->bind_param('ii', $contactId, $companyId);
                    $upd->execute();
                    $upd->close();
                }
            }
        } catch (\Throwable $e) {
            error_log("[DripOptimizer] CRM state update failed: " . $e->getMessage());
        }
    }

    public function getOptimizedSendTimes(int $companyId, int $campaignId, int $contactId = 1): array
    {
        $camp  = $this->getCampaign($companyId, $campaignId);
        if (!$camp) return [];
        $steps = $this->getCampaignSteps($campaignId);
        if (empty($steps)) return [];

        $respectBH = (int)($camp['respect_business_hours'] ?? 1) === 1;
        $randomizationOn = (int)($camp['global_randomization'] ?? 1) === 1;

        $results = [];
        foreach ($steps as $idx => $step) {
            $mode = $randomizationOn ? strtolower((string)($step['randomization_mode'] ?? 'human')) : 'none';

            $baseSecs = drip_delay_to_seconds(
                max(0, (int)$step['delay_days']),
                max(0, (int)$step['delay_hours']),
                max(0, (int)$step['delay_minutes']),
                max(0, (int)($step['delay_seconds'] ?? 0))
            );

            $ts = drip_compute_send_timestamp($baseSecs, ['randomization_mode' => $mode] + $step, $contactId, $idx, $respectBH);
            $results[] = [
                'step_id'       => (int)$step['id'],
                'scheduled_for' => date('Y-m-d H:i:s', $ts),
                'mode'          => $mode,
            ];
        }
        return $results;
    }

    public function generateStepVariants(int $companyId, int $campaignId, int $stepId): bool
    {
        // Use prepared statements throughout (fix SQL injection)
        $st = $this->conn->prepare(
            "SELECT s.id, s.message_text, s.max_variants
             FROM drip_steps s
             JOIN drip_campaigns dc ON s.drip_campaign_id = dc.id
             WHERE s.id = ? AND dc.id = ? AND dc.company_id = ? LIMIT 1"
        );
        if (!$st) return false;
        $st->bind_param('iii', $stepId, $campaignId, $companyId);
        $st->execute();
        $res = $st->get_result();
        if ($res->num_rows === 0) { $st->close(); return false; }
        $step = $res->fetch_assoc();
        $st->close();

        $variants = drip_generate_variants(
            (string)($step['message_text'] ?? ''),
            'there',
            $stepId * 37,
            (int)($step['max_variants'] ?? 3),
            $stepId
        );

        $json = empty($variants) ? '[]' : json_encode(array_values($variants), JSON_UNESCAPED_UNICODE);
        $upd  = $this->conn->prepare("UPDATE drip_steps SET message_variations = ? WHERE id = ?");
        if (!$upd) return false;
        $upd->bind_param('si', $json, $stepId);
        $ok = $upd->execute();
        $upd->close();
        return $ok;
    }

    public function getNextVariant(int $executionId): ?string
    {
        $st = $this->conn->prepare(
            "SELECT e.id, e.contact_id, e.drip_step_id, e.visited_variants, s.message_variations
             FROM drip_executions e
             JOIN drip_steps s ON e.drip_step_id = s.id
             WHERE e.id = ? LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('i', $executionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return null;

        $variantsJson = (string)($row['message_variations'] ?? '[]');
        $variants     = json_decode($variantsJson, true) ?: [];
        if (empty($variants)) return null;

        $visited = json_decode((string)($row['visited_variants'] ?? '[]'), true) ?: [];

        // Use optimistic locking to prevent race condition
        $nextIdx = drip_next_variant_index($visited, $variants, (int)$row['contact_id'], (int)$row['drip_step_id']);

        $visited[] = $nextIdx;
        $newVisited = json_encode(array_values(array_unique($visited)), JSON_UNESCAPED_UNICODE);

        // Atomic update: only update if visited_variants hasn't changed
        $upd = $this->conn->prepare(
            "UPDATE drip_executions SET visited_variants = ?, delivered_variant = ? WHERE id = ? AND visited_variants = ?"
        );
        if ($upd) {
            $oldVisited = json_encode($visited, JSON_UNESCAPED_UNICODE);
            // Use the $visited minus the last element as the "expected current" state
            $expected = json_encode(array_slice($visited, 0, -1), JSON_UNESCAPED_UNICODE);
            $upd->bind_param('siis', $newVisited, $nextIdx, $executionId, $expected);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();
            if ($affected <= 0) {
                // Race condition — someone else already updated; re-read
                return $this->getNextVariant($executionId);
            }
        }

        return $variants[$nextIdx] ?? $variants[0];
    }
}
