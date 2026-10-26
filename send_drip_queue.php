/<?php
/**
 * WAAMINI Drip Queue Worker (CLI) — Drip Champion Enhanced
 * Delivers due drip_executions WITHOUT needing a browser open.
 * Now includes full Drip Champion message variation engine.
 */

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/cron_guard.php';
waamini_cron_guard(); // cron (CLI), the main worker chain, or ?key=CRON_SECRET_KEY only
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/whatsapp_api_helper.php';
require_once __DIR__ . '/includes/drip_message_variator.php';
if (file_exists(__DIR__ . '/services/WhatsAppService.php')) {
    require_once __DIR__ . '/services/WhatsAppService.php';
}

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

function dlog(string $m): void { error_log('[DRIP WORKER] ' . $m); echo $m . "\n"; }

$lock = fopen(__DIR__ . '/storage/drip_worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another drip worker is running - exit.\n";
    return;
}

$limitPerCompany = 25;

$res = $conn->query(
    "SELECT DISTINCT company_id FROM drip_executions
     WHERE status='pending' AND scheduled_for <= NOW()"
);
$companies = [];
while ($r = $res->fetch_assoc()) $companies[] = (int)$r['company_id'];

if (empty($companies)) {
    echo "No due drip messages.\n";
    return;
}

$totSent = 0; $totFailed = 0; $variantStats = [];

foreach ($companies as $companyId) {
    $cfg = null;
    if (class_exists('WhatsAppService') && method_exists('WhatsAppService', 'getConfig')) {
        $cfg = WhatsAppService::getConfig($conn, $companyId);
    } else {
        $stW = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? LIMIT 1");
        if ($stW) {
            $stW->bind_param('i', $companyId);
            $stW->execute();
            $cfg = $stW->get_result()->fetch_assoc();
            $stW->close();
            if ($cfg && function_exists('waamini_whatsapp_decrypt_config_token')) {
                $cfg['access_token'] = waamini_whatsapp_decrypt_config_token($cfg);
            }
        }
    }
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['phone_number_id'])) {
        dlog("company $companyId: no WhatsApp config - skipped");
        continue;
    }
    $_ENV['WHATSAPP_API_VERSION'] = (string)($cfg['api_version'] ?? 'v18.0');

    $claim = $conn->prepare("UPDATE drip_executions SET status='processing' WHERE company_id=? AND status='pending' AND scheduled_for <= NOW() LIMIT ?");
    $claim->bind_param('ii', $companyId, $limitPerCompany);
    $claim->execute();
    $claimed = $claim->affected_rows;
    $claim->close();
    if ($claimed <= 0) continue;

    $stmt = $conn->prepare("
        SELECT e.id as execution_id, e.contact_id, e.drip_step_id, e.retry_count,
               e.message_variations, e.visited_variants,
               c.phone as contact_phone, c.name as contact_name,
               s.template_id, s.message_text, s.randomization_mode,
               dc.status as campaign_status, dc.id as campaign_id
        FROM drip_executions e
        JOIN contacts c ON e.contact_id = c.id AND c.company_id = e.company_id
        JOIN drip_steps s ON e.drip_step_id = s.id
        JOIN drip_campaigns dc ON s.drip_campaign_id = dc.id
        WHERE e.company_id = ? AND e.status = 'processing'
        ORDER BY e.scheduled_for ASC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $companyId, $limitPerCompany);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $executionId  = (int)$row['execution_id'];
        $contactId    = (int)$row['contact_id'];
        $stepId       = (int)($row['drip_step_id'] ?? 0);
        $contactPhone = (string)$row['contact_phone'];
        $contactName  = (string)$row['contact_name'];
        $templateId   = (int)($row['template_id'] ?? 0);
        $campaignId   = (int)($row['campaign_id'] ?? 0);
        $retryCount   = (int)($row['retry_count'] ?? 0);

        if ($executionId <= 0 || $contactId <= 0 || $contactPhone === '') {
            $conn->query("UPDATE drip_executions SET status='failed', error_msg='invalid_data' WHERE id=$executionId");
            $totFailed++;
            continue;
        }
        if (($row['campaign_status'] ?? '') !== 'active') {
            $conn->query("UPDATE drip_executions SET status='failed', error_msg='campaign_inactive' WHERE id=$executionId");
            continue;
        }

        $phoneLast10 = substr(preg_replace('/\D+/', '', $contactPhone), -10);
        if ($phoneLast10 !== '') {
            $bl = $conn->prepare("SELECT id FROM blacklist WHERE company_id=? AND phone LIKE ? LIMIT 1");
            $pat = '%' . $phoneLast10;
            $bl->bind_param('is', $companyId, $pat);
            $bl->execute();
            $isBl = $bl->get_result()->num_rows > 0;
            $bl->close();
            if ($isBl) {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='blacklisted' WHERE id=$executionId");
                continue;
            }
        }

        // ── Drip Champion: Build body with variation engine ─────────────────
        $messageBody = '';
        $tplName = ''; $tplLang = 'en_US'; $tplWaStatus = '';
        $usedVariant = null;

        if ($templateId > 0) {
            $t = $conn->prepare("SELECT name, language, wa_status, body, body_text FROM message_templates WHERE id=? AND (company_id=? OR company_id IS NULL) LIMIT 1");
            $t->bind_param('ii', $templateId, $companyId);
            $t->execute();
            $tRow = $t->get_result()->fetch_assoc();
            $t->close();
            if ($tRow) {
                $tplName = (string)($tRow['name'] ?? '');
                $tplLang = (string)($tRow['language'] ?? 'en_US');
                $tplWaStatus = strtolower(trim((string)($tRow['wa_status'] ?? '')));
                $messageBody = (string)($tRow['body'] ?? $tRow['body_text'] ?? '');
            }
        }

        // Drip Champion: read pre-generated variants, pick next untried one
        $variationsJson = (string)($row['message_variations'] ?? '[]');
        $variants = json_decode($variationsJson, true) ?: [];
        if (empty($messageBody) && !empty($variants)) {
            $visited   = json_decode((string)($row['visited_variants'] ?? '[]'), true) ?: [];
            $nextIdx   = drip_next_variant_index($visited, $variants, $contactId, $stepId);
            $usedVariant = $variants[$nextIdx] ?? null;
            if ($usedVariant) {
                $messageBody = str_replace(
                    ['{{1}}', '{{name}}', '{{contact_name}}'],
                    $contactName ?: 'there',
                    $usedVariant
                );
                $newVisited = json_encode(
                    array_values(array_unique(array_merge($visited, [$nextIdx]))),
                    JSON_UNESCAPED_UNICODE
                );
                $conn->query(
                    "UPDATE drip_executions SET visited_variants='" . $conn->real_escape_string($newVisited) . "',
                     delivered_variant=$nextIdx WHERE id=$executionId"
                );
                $key = "c{$campaignId}_s{$stepId}_v$nextIdx";
                $variantStats[$key] = ($variantStats[$key] ?? 0) + 1;
            }
        }

        if ($messageBody === '') $messageBody = (string)($row['message_text'] ?? '');
        if (empty($usedVariant)) {
            $messageBody = str_replace(
                ['{{1}}', '{{name}}', '{{contact_name}}'],
                $contactName ?: 'there',
                $messageBody
            );
        }
        $messageBody = trim($messageBody);

        if ($messageBody === '') {
            $conn->query("UPDATE drip_executions SET status='failed', error_msg='empty_body' WHERE id=$executionId");
            $totFailed++;
            continue;
        }

        $waMsgId = null;
        $meta = [];

        $isApprovedTpl = ($tplName !== '' && in_array($tplWaStatus, ['approved', 'approve'], true));
        if ($isApprovedTpl && function_exists('send_whatsapp_template')) {
            $maxIdx = 0;
            if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $messageBody, $m)) {
                foreach ($m[1] as $n) { $i = (int)$n; if ($i > $maxIdx) $maxIdx = $i; }
            }
            $params = [];
            for ($i = 1; $i <= $maxIdx; $i++) {
                $params[] = ['type' => 'text', 'text' => ($i === 1 && $contactName !== '') ? $contactName : 'there'];
            }
            $components = !empty($params) ? [['type' => 'body', 'parameters' => $params]] : [];
            $waMsgId = send_whatsapp_template($contactPhone, $tplName, $tplLang ?: 'en_US', $components, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], $meta);
        }
        if (!$waMsgId) {
            $waMsgId = send_whatsapp_text($contactPhone, $messageBody, (string)$cfg['access_token'], (string)$cfg['phone_number_id'], null, $meta);
        }

        if ($waMsgId) {
            $msgStmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, sender, direction, type, message, is_read, status, whatsapp_message_id, created_at) VALUES (?, ?, 'business', 'outgoing', 'text', ?, 1, 'sent', ?, NOW())");
            if ($msgStmt) {
                $msgStmt->bind_param('iiss', $companyId, $contactId, $messageBody, $waMsgId);
                $msgStmt->execute();
                $msgStmt->close();
            }
            $conn->query("UPDATE drip_executions SET status='sent', sent_at=NOW(), error_msg=NULL WHERE id=$executionId");
            $totSent++;
            dlog("✅ drip #{$executionId} → {$contactPhone} sent" . ($usedVariant ? " (variant)" : ""));
        } else {
            if ($retryCount < 3) {
                $next = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $newRetry = $retryCount + 1;
                $u = $conn->prepare("UPDATE drip_executions SET status='pending', scheduled_for=?, retry_count=? WHERE id=?");
                $u->bind_param('sii', $next, $newRetry, $executionId);
                $u->execute();
                $u->close();
                dlog("↻ drip #{$executionId} retry {$newRetry} in 10 min");
            } else {
                $conn->query("UPDATE drip_executions SET status='failed', error_msg='max_retries' WHERE id=$executionId");
                $totFailed++;
                dlog("❌ drip #{$executionId} failed (max retries)");
            }
        }
    }

    $conn->query("UPDATE drip_executions SET status='pending' WHERE company_id=$companyId AND status='processing'");
}

if (!empty($variantStats)) {
    dlog("📊 Variants used: " . json_encode($variantStats));
}
dlog("🏁 Drip worker done: {$totSent} sent, {$totFailed} failed");
return;
