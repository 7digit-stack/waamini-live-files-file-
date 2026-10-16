<?php
/**
 * Ads & Attribution module — database schema (Phase 0 + Phase 1).
 *
 * Mirrors migrations/034_ads_foundation.sql and 035_ads_attribution.sql, so the module also works on
 * installs where migrations are not run by hand. Runs once per server (schema_guard flag file) and every
 * statement is idempotent. IDs are INT to match contacts.id / companies.id.
 */
require_once __DIR__ . '/../schema_guard.php';

function ads_ensure_schema(mysqli $conn): void
{
    schema_ensure('ads_attribution_v1', static function () use ($conn) {
        $cols = static function (string $table) use ($conn): array {
            $out = [];
            $r = $conn->query("SHOW COLUMNS FROM `$table`");
            while ($r && ($c = $r->fetch_assoc())) $out[$c['Field']] = true;
            return $out;
        };

        // Plan flags (Phase 0). Off by default: the module is a paid add-on.
        $p = $cols('subscription_plans');
        foreach (['feat_ads_attribution', 'feat_ads_capi', 'feat_ads_insights', 'feat_ads_builder'] as $f) {
            if (!isset($p[$f])) $conn->query("ALTER TABLE subscription_plans ADD COLUMN `$f` TINYINT(1) NOT NULL DEFAULT 0");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS ad_referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            conversation_id INT NULL,
            message_id VARCHAR(128) NOT NULL,
            source_type VARCHAR(20) NOT NULL,
            source_id VARCHAR(64) NULL,
            source_url VARCHAR(512) NULL,
            headline VARCHAR(255) NULL,
            body TEXT NULL,
            media_type VARCHAR(20) NULL,
            media_url TEXT NULL,
            thumbnail_url TEXT NULL,
            local_media_path VARCHAR(512) NULL,
            media_status ENUM('none','pending','processing','done','failed') NOT NULL DEFAULT 'none',
            media_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ctwa_clid VARCHAR(512) NULL,
            raw_json LONGTEXT NULL,
            clicked_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_message (company_id, message_id),
            KEY idx_company_source (company_id, source_id),
            KEY idx_company_contact (company_id, contact_id),
            KEY idx_company_clicked (company_id, clicked_at),
            KEY idx_media_status (media_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS ad_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            source_id VARCHAR(64) NOT NULL,
            source_type VARCHAR(20) NOT NULL,
            headline VARCHAR(255) NULL,
            media_type VARCHAR(20) NULL,
            local_thumbnail_path VARCHAR(512) NULL,
            ad_name VARCHAR(255) NULL,
            campaign_id VARCHAR(64) NULL,
            campaign_name VARCHAR(255) NULL,
            adset_id VARCHAR(64) NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            UNIQUE KEY uq_company_ad (company_id, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $c = $cols('contacts');
        $add = [
            'lead_source'          => "ENUM('organic','ad','post','import','manual') NOT NULL DEFAULT 'organic'",
            'first_ad_referral_id' => "INT NULL",
            'last_ad_referral_id'  => "INT NULL",
            'latest_ctwa_clid'     => "VARCHAR(512) NULL",
            'lead_stage'           => "ENUM('new','qualified','won','lost') NOT NULL DEFAULT 'new'",
            'lead_stage_at'        => "DATETIME NULL",
            'won_value'            => "DECIMAL(12,2) NULL",
        ];
        $addedStage = !isset($c['lead_stage']);
        foreach ($add as $col => $def) {
            if (!isset($c[$col])) $conn->query("ALTER TABLE contacts ADD COLUMN `$col` $def");
        }
        $idx = $conn->query("SHOW INDEX FROM contacts WHERE Key_name = 'idx_company_lead_source'");
        if ($idx && $idx->num_rows === 0) $conn->query("ALTER TABLE contacts ADD KEY idx_company_lead_source (company_id, lead_source)");

        // Backfill the stage from the outcome labels set in WhatsApp Chat (Follow-up / Converted / Declined / Not Interested).
        if ($addedStage) ads_backfill_lead_stage($conn);
    });
}

/** Phase 2 — Conversions API tables. Mirrors migrations/036_ads_capi.sql. */
function ads_ensure_capi_schema(mysqli $conn): void
{
    ads_ensure_schema($conn);
    schema_ensure('ads_capi_v1', static function () use ($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS capi_settings (
            company_id INT NOT NULL PRIMARY KEY,
            dataset_id VARCHAR(64) NULL,
            access_token_enc TEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            mapping_json TEXT NULL,
            send_value TINYINT(1) NOT NULL DEFAULT 1,
            window_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
            test_event_code VARCHAR(64) NULL,
            last_test_at DATETIME NULL,
            last_test_result TEXT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS capi_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT NOT NULL,
            ad_referral_id INT NULL,
            stage VARCHAR(20) NULL,
            event_name VARCHAR(64) NOT NULL,
            event_id VARCHAR(191) NOT NULL,
            event_time INT UNSIGNED NOT NULL,
            value DECIMAL(12,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            status ENUM('pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            run_at DATETIME NULL,
            last_error TEXT NULL,
            response_json TEXT NULL,
            fbtrace_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            UNIQUE KEY uq_company_event (company_id, event_id),
            KEY idx_company_status (company_id, status),
            KEY idx_due (status, run_at),
            KEY idx_company_contact (company_id, contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    });
}

/**
 * Is an Ads flag on for a company, without a logged-in session (webhook, workers, AI)?
 * Same rule as has_feature(): plan column, then companies.features_override wins.
 */
function ads_company_feature(mysqli $conn, int $companyId, string $flag): bool
{
    static $cache = [];
    $k = $companyId . ':' . $flag;
    if (isset($cache[$k])) return $cache[$k];
    $on = false;
    try {
        $st = $conn->prepare("SELECT sp.* FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.company_id = ? AND s.status IN ('active','trial') ORDER BY s.id DESC LIMIT 1");
        if ($st) { $st->bind_param('i', $companyId); $st->execute(); $p = $st->get_result()->fetch_assoc(); $on = !empty($p[$flag]); }
        $st = $conn->prepare("SELECT features_override FROM companies WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $companyId); $st->execute();
            $ov = json_decode((string)($st->get_result()->fetch_assoc()['features_override'] ?? ''), true);
            if (is_array($ov) && array_key_exists($flag, $ov)) $on = (bool)(int)$ov[$flag];
        }
    } catch (\Throwable $e) { $on = false; }
    return $cache[$k] = $on;
}

/**
 * The one place a lead's pipeline stage changes (WhatsApp Chat outcome buttons, AI auto-outcome).
 * Saves the stage and queues the Meta Conversions API event when the company uses it. Never throws.
 */
function ads_set_stage(mysqli $conn, int $companyId, int $contactId, string $stage): void
{
    if (!in_array($stage, ['new', 'qualified', 'won', 'lost'], true) || !ads_columns_ready($conn)) return;
    try {
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("UPDATE contacts SET lead_stage=?, lead_stage_at=? WHERE id=? AND company_id=?");
        if ($st) { $st->bind_param('ssii', $stage, $now, $contactId, $companyId); $st->execute(); }
        if (ads_company_feature($conn, $companyId, 'feat_ads_capi')) {
            require_once __DIR__ . '/../../services/CapiService.php';
            CapiService::queueForStage($conn, $companyId, $contactId, $stage);
        }
    } catch (\Throwable $e) {
        error_log('[ads] set stage: ' . $e->getMessage());
    }
}

/** True once the contacts attribution columns exist (pages read them only then, so a failed ALTER never breaks a page). */
function ads_columns_ready(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $r = $conn->query("SHOW COLUMNS FROM contacts LIKE 'won_value'");
        $ok = (bool)($r && $r->num_rows > 0);
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** lead_stage from chat_labels for contacts still at 'new'. Safe to run again. */
function ads_backfill_lead_stage(mysqli $conn, int $companyId = 0): void
{
    $scope = $companyId > 0 ? ' AND company_id = ' . $companyId : '';
    $conn->query("UPDATE contacts SET lead_stage = CASE
            WHEN LOWER(chat_labels) LIKE '%converted%' THEN 'won'
            WHEN LOWER(chat_labels) LIKE '%declined%' OR LOWER(chat_labels) LIKE '%not interested%' THEN 'lost'
            WHEN LOWER(chat_labels) LIKE '%follow-up%' OR LOWER(chat_labels) LIKE '%follow up%' THEN 'qualified'
            ELSE lead_stage END
        WHERE lead_stage = 'new' AND chat_labels IS NOT NULL AND chat_labels <> ''" . $scope);
}

/** Outcome label (WhatsApp Chat CRM buttons) → lead stage. */
function ads_stage_for_outcome(string $label): string
{
    $l = strtolower(trim($label));
    if ($l === 'converted') return 'won';
    if ($l === 'declined' || $l === 'not interested') return 'lost';
    if ($l === 'follow-up' || $l === 'follow up') return 'qualified';
    return 'new';
}
