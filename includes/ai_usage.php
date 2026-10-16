<?php
/**
 * WAAMINI - AI Usage Guard (mysqli)
 *
 * Goal: protect vendors from unexpected AI bills by enforcing a daily request cap.
 * This is app-level (OpenAI/Gemini calls), not server/OS security.
 */

/**
 * Ensure daily usage table exists.
 */
function ai_usage_ensure_table(mysqli $conn): void {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ai_usage_daily (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            day DATE NOT NULL,
            requests INT NOT NULL DEFAULT 0,
            last_at DATETIME NULL,
            UNIQUE KEY uniq_company_day (company_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        // swallow
    }
}

/**
 * Return the configured daily request limit.
 * You can override via .env: AI_DAILY_REQUEST_LIMIT
 *
 * Note: `??` does not treat "" as missing; an empty value must fall back to default 200,
 * otherwise (int)"" becomes 0 and disables the cap UI + blocks every AI call.
 */
function ai_daily_limit(): int {
    $raw = null;
    if (class_exists(\Waamini\Config\Env::class, false)) {
        $v = \Waamini\Config\Env::get('AI_DAILY_REQUEST_LIMIT');
        if ($v !== null && trim((string)$v) !== '') {
            $raw = trim((string)$v);
        }
    }
    if ($raw === null) {
        $g = $_ENV['AI_DAILY_REQUEST_LIMIT'] ?? getenv('AI_DAILY_REQUEST_LIMIT');
        if ($g !== false && $g !== null && trim((string)$g) !== '') {
            $raw = trim((string)$g);
        }
    }
    if ($raw === null) {
        $raw = '200';
    }
    $n = (int)$raw;
    if ($n < 0) $n = 0;
    if ($n > 5000) $n = 5000;
    return $n;
}

/**
 * Attempt to consume 1 request from the daily budget.
 * Returns true if allowed, false if rate-limited.
 */
function ai_usage_consume(mysqli $conn, int $companyId, int $cost = 1): bool {
    if ($companyId <= 0) return false;
    $limit = ai_daily_limit();
    if ($limit === 0) return false;
    if ($cost < 1) $cost = 1;
    if ($cost > 50) $cost = 50;

    ai_usage_ensure_table($conn);
    $day = date('Y-m-d');

    /**
     * Bump counter only when requests + cost <= limit.
     * affected_rows === 1 means this call actually consumed budget (not at cap).
     */
    try {
        $upd = $conn->prepare(
            'UPDATE ai_usage_daily SET requests = requests + ?, last_at = NOW()
             WHERE company_id = ? AND day = ? AND requests + ? <= ?'
        );
        if (!$upd) {
            return false;
        }
        $upd->bind_param('iisii', $cost, $companyId, $day, $cost, $limit);
        $upd->execute();
        if ($upd->affected_rows === 1) {
            return true;
        }

        // First message of the day: no row yet — insert if still under cap.
        if ($cost > $limit) {
            return false;
        }

        $ins = $conn->prepare(
            'INSERT INTO ai_usage_daily (company_id, day, requests, last_at) VALUES (?, ?, ?, NOW())'
        );
        if (!$ins) {
            return false;
        }
        $ins->bind_param('isi', $companyId, $day, $cost);
        if ($ins->execute()) {
            return ($ins->affected_rows === 1);
        }
        // 1062 duplicate key: another worker inserted this company/day — bump that row instead.
        $dup = (int)($conn->errno ?? 0) === 1062 || (int)($ins->errno ?? 0) === 1062;
        if (!$dup) {
            return false;
        }
        $upd->execute();

        return $upd->affected_rows === 1;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Helper: remaining daily requests.
 */
function ai_usage_remaining(mysqli $conn, int $companyId): int {
    $limit = ai_daily_limit();
    if ($limit <= 0 || $companyId <= 0) return 0;
    ai_usage_ensure_table($conn);
    $day = date('Y-m-d');
    try {
        $st = $conn->prepare("SELECT requests FROM ai_usage_daily WHERE company_id=? AND day=? LIMIT 1");
        if (!$st) return $limit;
        $st->bind_param("is", $companyId, $day);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $used = (int)($row['requests'] ?? 0);
        return max(0, $limit - $used);
    } catch (\Throwable $e) {
        return 0;
    }
}

