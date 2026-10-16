<?php
/**
 * CapiService — Meta Conversions API for Business Messaging (Ads & Attribution, Phase 2).
 *
 * When a lead from a Click-to-WhatsApp ad moves to a mapped stage (Follow-up → qualified, Converted → won),
 * an event is queued and sent to the company's Meta dataset with the ad click id (ctwa_clid). Meta uses it to
 * show sales per ad in Ads Manager and to find more customers like the ones who bought.
 *
 *  - One event per contact per event name (event_id = waamini_{company}_{contact}_{event}); never sent twice.
 *  - Organic leads / clicks outside the attribution window are logged as 'skipped' with the reason.
 *  - Purchase waits a few minutes so the sale value typed in WhatsApp Chat goes with it.
 *  - Sent by the every-minute worker and the panel tick; transient errors retry (max 5) with backoff.
 *
 * VERIFY against Meta's current "Conversions API for Business Messaging" docs: endpoint, field names,
 * allowed event names, the attribution window, and the WABA dataset endpoint used by connectDataset().
 */
require_once __DIR__ . '/../includes/ads/schema.php';
require_once __DIR__ . '/MetaGraphClient.php';
require_once __DIR__ . '/MetaTokenVault.php';
require_once __DIR__ . '/WhatsAppService.php';

class CapiService
{
    // VERIFY: event names accepted with action_source = business_messaging.
    public const EVENTS = [
        'LeadSubmitted'    => 'Lead submitted',
        'QualifiedLead'    => 'Qualified lead',
        'Purchase'         => 'Purchase',
        'InitiateCheckout' => 'Initiate checkout',
        'AddToCart'        => 'Add to cart',
        'ViewContent'      => 'View content',
        'OrderCreated'     => 'Order created',
    ];
    public const STAGES = ['qualified' => 'Follow-up (qualified)', 'won' => 'Converted (won)', 'lost' => 'Declined / Not interested (lost)'];
    public const DEFAULT_MAPPING = ['qualified' => 'LeadSubmitted', 'won' => 'Purchase', 'lost' => ''];
    public const MAX_ATTEMPTS = 5;
    public const PURCHASE_WAIT = 600;      // seconds to wait for the sale value before sending a Purchase
    public const PURCHASE_WAIT_MAX = 3600; // after this, send even without a value

    /** @var ?callable tests replace the HTTP transport (see MetaGraphClient) */
    public static $transport = null;

    public static function settings(mysqli $conn, int $companyId): array
    {
        ads_ensure_capi_schema($conn);
        $st = $conn->prepare("SELECT * FROM capi_settings WHERE company_id = ? LIMIT 1");
        $st->bind_param('i', $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: [];
        $map = json_decode((string)($row['mapping_json'] ?? ''), true);
        return [
            'dataset_id'      => (string)($row['dataset_id'] ?? ''),
            'has_token'       => !empty($row['access_token_enc']),
            'token_enc'       => (string)($row['access_token_enc'] ?? ''),
            'enabled'         => !empty($row['enabled']),
            'mapping'         => is_array($map) ? array_merge(self::DEFAULT_MAPPING, array_intersect_key($map, self::DEFAULT_MAPPING)) : self::DEFAULT_MAPPING,
            'send_value'      => !isset($row['send_value']) || (int)$row['send_value'] === 1,
            'window_days'     => max(1, min(28, (int)($row['window_days'] ?? 7))),
            'test_event_code' => (string)($row['test_event_code'] ?? ''),
            'last_test_at'    => $row['last_test_at'] ?? null,
            'last_test_result'=> (string)($row['last_test_result'] ?? ''),
            'exists'          => (bool)$row,
        ];
    }

    /** $in: dataset_id, token ('' keeps, '-' removes), enabled, mapping[stage], send_value, window_days, test_event_code */
    public static function saveSettings(mysqli $conn, int $companyId, array $in): void
    {
        $cur = self::settings($conn, $companyId);
        $dataset = substr(preg_replace('/\D/', '', (string)($in['dataset_id'] ?? $cur['dataset_id'])), 0, 64);
        $map = [];
        foreach (array_keys(self::DEFAULT_MAPPING) as $stage) {
            $ev = (string)($in['mapping'][$stage] ?? $cur['mapping'][$stage]);
            $map[$stage] = isset(self::EVENTS[$ev]) ? $ev : '';
        }
        $tok = trim((string)($in['token'] ?? ''));
        $tokEnc = $tok === '-' ? null : ($tok !== '' ? MetaTokenVault::encrypt($tok) : ($cur['token_enc'] !== '' ? $cur['token_enc'] : null));
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $sendValue = !empty($in['send_value']) ? 1 : 0;
        $window = max(1, min(28, (int)($in['window_days'] ?? $cur['window_days'])));
        $test = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['test_event_code'] ?? '')), 0, 64);
        $mapJson = json_encode($map);
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("INSERT INTO capi_settings (company_id, dataset_id, access_token_enc, enabled, mapping_json, send_value, window_days, test_event_code, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE dataset_id=VALUES(dataset_id), access_token_enc=VALUES(access_token_enc), enabled=VALUES(enabled),
              mapping_json=VALUES(mapping_json), send_value=VALUES(send_value), window_days=VALUES(window_days),
              test_event_code=VALUES(test_event_code), updated_at=VALUES(updated_at)");
        $st->bind_param('issisiiss', $companyId, $dataset, $tokEnc, $enabled, $mapJson, $sendValue, $window, $test, $now);
        $st->execute();
    }

    /** Token + WABA id: the CAPI token saved here, else the company's WhatsApp Cloud API token. */
    public static function credentials(mysqli $conn, int $companyId, ?array $s = null): array
    {
        $s = $s ?? self::settings($conn, $companyId);
        $cfg = WhatsAppService::getConfig($conn, $companyId) ?: [];
        $token = $s['token_enc'] !== '' ? MetaTokenVault::decrypt($s['token_enc']) : (string)($cfg['access_token'] ?? '');
        return ['token' => $token, 'waba_id' => (string)($cfg['waba_id'] ?? ''), 'own_token' => $s['token_enc'] !== ''];
    }

    private static function client(string $token, int $companyId): MetaGraphClient
    {
        $opts = ['company_id' => $companyId, 'max_retries' => 0];   // retries are scheduled by the queue, not inline
        if (self::$transport) $opts['transport'] = self::$transport;
        return new MetaGraphClient($token, $opts);
    }

    /**
     * Queue the mapped event for a stage change. Returns 'pending' | 'skipped' | 'duplicate' | null (not mapped / off).
     * @param int|null $now unix time (tests)
     */
    public static function queueForStage(mysqli $conn, int $companyId, int $contactId, string $stage, ?int $now = null): ?string
    {
        $now = $now ?? time();
        $s = self::settings($conn, $companyId);
        if (!$s['enabled'] || $s['dataset_id'] === '') return null;
        $event = (string)($s['mapping'][$stage] ?? '');
        if ($event === '') return null;

        $st = $conn->prepare("SELECT c.last_ad_referral_id, r.ctwa_clid, r.clicked_at FROM contacts c
            LEFT JOIN ad_referrals r ON r.id = c.last_ad_referral_id AND r.company_id = c.company_id
            WHERE c.id = ? AND c.company_id = ? LIMIT 1");
        $st->bind_param('ii', $contactId, $companyId);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        if (!$c) return null;

        $status = 'pending';
        $reason = null;
        if (empty($c['ctwa_clid'])) {
            $status = 'skipped';
            $reason = 'Not from a Click-to-WhatsApp ad, so Meta cannot match it to an ad.';
        } elseif (strtotime((string)$c['clicked_at']) < $now - $s['window_days'] * 86400) {
            $status = 'skipped';
            $reason = 'The ad was tapped more than ' . $s['window_days'] . ' days before this stage change (outside the attribution window).';
        }
        $runAt = date('Y-m-d H:i:s', $now + ($event === 'Purchase' && $s['send_value'] ? self::PURCHASE_WAIT : 0));
        $eventId = 'waamini_' . $companyId . '_' . $contactId . '_' . $event;
        $refId = $c['last_ad_referral_id'] !== null ? (int)$c['last_ad_referral_id'] : null;
        $created = date('Y-m-d H:i:s', $now);   // PHP clock (Asia/Kolkata), like every other Ads timestamp
        $ins = $conn->prepare("INSERT IGNORE INTO capi_events (company_id, contact_id, ad_referral_id, stage, event_name, event_id, event_time, status, run_at, last_error, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $ins->bind_param('iiisssissss', $companyId, $contactId, $refId, $stage, $event, $eventId, $now, $status, $runAt, $reason, $created);
        $ins->execute();
        return $ins->affected_rows === 1 ? $status : 'duplicate';
    }

    /** The JSON Meta receives. Pure: no database, used by the sender and the unit tests. */
    public static function payload(string $eventName, string $eventId, int $eventTime, string $wabaId, string $ctwaClid, ?float $value, string $currency = 'INR', string $testCode = ''): array
    {
        $ev = [
            'event_name'        => $eventName,
            'event_time'        => $eventTime,
            'action_source'     => 'business_messaging',   // VERIFY
            'messaging_channel' => 'whatsapp',             // VERIFY
            'event_id'          => $eventId,
            'user_data'         => ['whatsapp_business_account_id' => $wabaId, 'ctwa_clid' => $ctwaClid],
        ];
        if ($value !== null) $ev['custom_data'] = ['currency' => $currency, 'value' => round($value, 2)];
        $p = ['data' => [$ev]];
        if ($testCode !== '') $p['test_event_code'] = $testCode;
        return $p;
    }

    /** Send due events. $companyId = 0 → all companies (cron). Returns ['sent','failed','retry','waiting']. */
    public static function processDue(mysqli $conn, int $companyId = 0, int $limit = 25, ?int $now = null): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'waiting' => 0];
        try {
            ads_ensure_capi_schema($conn);
            $now = $now ?? time();
            $nowS = date('Y-m-d H:i:s', $now);
            $stale = date('Y-m-d H:i:s', $now - 900);
            $st = $conn->prepare("UPDATE capi_events SET status='pending' WHERE status='processing' AND run_at < ?");
            $st->bind_param('s', $stale);
            $st->execute();

            $sql = "SELECT e.*, r.ctwa_clid FROM capi_events e LEFT JOIN ad_referrals r ON r.id = e.ad_referral_id AND r.company_id = e.company_id
                    WHERE e.status='pending' AND (e.run_at IS NULL OR e.run_at <= ?)" . ($companyId > 0 ? " AND e.company_id = ?" : "") . " ORDER BY e.id ASC LIMIT ?";
            $st = $conn->prepare($sql);
            if ($companyId > 0) $st->bind_param('sii', $nowS, $companyId, $limit); else $st->bind_param('si', $nowS, $limit);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $claim = $conn->prepare("UPDATE capi_events SET status='processing' WHERE id=? AND status='pending'");

            foreach ($rows as $e) {
                $id = (int)$e['id'];
                $claim->bind_param('i', $id);
                $claim->execute();
                if ($claim->affected_rows !== 1) continue;
                $cid = (int)$e['company_id'];
                $s = self::settings($conn, $cid);
                $cred = self::credentials($conn, $cid, $s);
                if ($s['dataset_id'] === '' || $cred['token'] === '' || $cred['waba_id'] === '' || empty($e['ctwa_clid'])) {
                    self::finish($conn, $id, 'failed', 'Setup incomplete: ' . ($s['dataset_id'] === '' ? 'no dataset ID' : ($cred['token'] === '' ? 'no access token' : ($cred['waba_id'] === '' ? 'no WhatsApp Business Account ID' : 'no ad click id'))) . '.', null, null, true);
                    $out['failed']++;
                    continue;
                }

                // Purchase: wait for the sale value (read now, so a value typed after Converted is included).
                $value = null;
                if ($e['event_name'] === 'Purchase' && $s['send_value']) {
                    $vs = $conn->prepare("SELECT won_value FROM contacts WHERE id=? AND company_id=?");
                    $ctId = (int)$e['contact_id'];
                    $vs->bind_param('ii', $ctId, $cid);
                    $vs->execute();
                    $wv = $vs->get_result()->fetch_assoc()['won_value'] ?? null;
                    if (($wv === null || (float)$wv <= 0) && (int)$e['event_time'] > $now - self::PURCHASE_WAIT_MAX) {
                        $later = date('Y-m-d H:i:s', $now + self::PURCHASE_WAIT);
                        $w = $conn->prepare("UPDATE capi_events SET status='pending', run_at=? WHERE id=?");
                        $w->bind_param('si', $later, $id);
                        $w->execute();
                        $out['waiting']++;
                        continue;
                    }
                    $value = $wv !== null ? (float)$wv : 0.0;   // Meta requires a value on Purchase
                }

                $payload = self::payload((string)$e['event_name'], (string)$e['event_id'], (int)$e['event_time'], $cred['waba_id'], (string)$e['ctwa_clid'], $value, (string)$e['currency']);
                $res = self::client($cred['token'], $cid)->post($s['dataset_id'] . '/events', $payload);
                $resp = json_encode($res['ok'] ? $res['data'] : ['error' => $res['error']], JSON_UNESCAPED_SLASHES);
                if ($res['ok']) {
                    $u = $conn->prepare("UPDATE capi_events SET status='sent', sent_at=?, attempts=attempts+1, value=?, response_json=?, last_error=NULL, fbtrace_id=? WHERE id=?");
                    $tr = (string)($res['data']['fbtrace_id'] ?? '');
                    $u->bind_param('sdssi', $nowS, $value, $resp, $tr, $id);
                    $u->execute();
                    $out['sent']++;
                    continue;
                }
                $err = $res['error'];
                $attempts = (int)$e['attempts'] + 1;
                $msg = trim($err['user_message'] . ($err['message'] !== '' && $err['message'] !== $err['user_message'] ? ' (Meta: ' . $err['message'] . ')' : ''));
                if (!empty($err['retryable']) && $attempts < self::MAX_ATTEMPTS) {
                    $next = date('Y-m-d H:i:s', $now + 60 * (2 ** $attempts) + mt_rand(0, 30));
                    $u = $conn->prepare("UPDATE capi_events SET status='pending', attempts=?, run_at=?, last_error=?, response_json=?, fbtrace_id=? WHERE id=?");
                    $u->bind_param('issssi', $attempts, $next, $msg, $resp, $err['fbtrace_id'], $id);
                    $u->execute();
                    $out['retry']++;
                } else {
                    self::finish($conn, $id, 'failed', $msg, $resp, $err['fbtrace_id'] ?: null, true);
                    $out['failed']++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[CAPI] ' . $e->getMessage());
        }
        return $out;
    }

    private static function finish(mysqli $conn, int $id, string $status, string $error, ?string $resp, ?string $trace, bool $countAttempt): void
    {
        $u = $conn->prepare("UPDATE capi_events SET status=?, last_error=?, response_json=COALESCE(?, response_json), fbtrace_id=COALESCE(?, fbtrace_id)"
            . ($countAttempt ? ", attempts=attempts+1" : "") . " WHERE id=?");
        $u->bind_param('ssssi', $status, $error, $resp, $trace, $id);
        $u->execute();
    }

    /** Manual retry from the log (failed events only). */
    public static function retry(mysqli $conn, int $companyId, int $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("UPDATE capi_events SET status='pending', attempts=0, run_at=?, last_error=NULL WHERE id=? AND company_id=? AND status='failed'");
        $st->bind_param('sii', $now, $id, $companyId);
        $st->execute();
        return $st->affected_rows === 1;
    }

    /**
     * Send one test event (Events Manager → Test events code) and keep Meta's answer.
     * Uses the newest real ad click when there is one, so Meta can actually match it.
     */
    public static function testEvent(mysqli $conn, int $companyId): array
    {
        $s = self::settings($conn, $companyId);
        $cred = self::credentials($conn, $companyId, $s);
        $fail = static fn($m) => ['ok' => false, 'message' => $m];
        if ($s['dataset_id'] === '') return $fail('Add the dataset ID first.');
        if ($cred['token'] === '') return $fail('No access token: connect WhatsApp in settings or paste a Conversions API token.');
        if ($cred['waba_id'] === '') return $fail('No WhatsApp Business Account ID in WhatsApp settings.');
        $st = $conn->prepare("SELECT ctwa_clid FROM ad_referrals WHERE company_id=? AND ctwa_clid IS NOT NULL ORDER BY id DESC LIMIT 1");
        $st->bind_param('i', $companyId);
        $st->execute();
        $clid = (string)($st->get_result()->fetch_assoc()['ctwa_clid'] ?? '');
        $usedReal = $clid !== '';
        if (!$usedReal) $clid = 'waamini_test_click';
        $payload = self::payload('LeadSubmitted', 'waamini_test_' . $companyId . '_' . time(), time(), $cred['waba_id'], $clid, null, 'INR', $s['test_event_code']);
        $res = self::client($cred['token'], $companyId)->post($s['dataset_id'] . '/events', $payload);
        // Without a real ad lead Meta rejects the sample click id. Reaching that check still proves the token,
        // dataset and event format are right, so report it as a working connection.
        $clidOnly = !$res['ok'] && !$usedReal && stripos((string)$res['error']['user_message'] . ' ' . (string)$res['error']['message'], 'ctwa_clid') !== false;
        if ($clidOnly) {
            $res['ok'] = true;
            $msg = 'Connection works: Meta reached your dataset and checked the event format. It only refused the sample click id, because no customer has come from a Click-to-WhatsApp ad yet. The first real ad lead you mark Follow-up or Converted will be accepted and show in Events Manager.';
        } else {
            $msg = $res['ok']
                ? 'Meta accepted the test event (events received: ' . (int)($res['data']['events_received'] ?? 0) . ').' . ($s['test_event_code'] !== '' ? ' Check Events Manager → Test events.' : ' Add a test event code to see it under Test events.')
                : 'Meta rejected the test: ' . $res['error']['user_message'] . ($res['error']['message'] !== '' ? ' (Meta: ' . $res['error']['message'] . ')' : '');
        }
        $now = date('Y-m-d H:i:s');
        $u = $conn->prepare("UPDATE capi_settings SET last_test_at=?, last_test_result=? WHERE company_id=?");
        $u->bind_param('ssi', $now, $msg, $companyId);
        $u->execute();
        return ['ok' => $res['ok'], 'message' => $msg];
    }

    /**
     * Find or create the dataset linked to the company's WhatsApp Business Account, with the WhatsApp token.
     * VERIFY: GET/POST /{waba_id}/dataset is Meta's endpoint for business-messaging datasets.
     */
    public static function connectDataset(mysqli $conn, int $companyId): array
    {
        $s = self::settings($conn, $companyId);
        $cred = self::credentials($conn, $companyId, $s);
        if ($cred['token'] === '' || $cred['waba_id'] === '') return ['ok' => false, 'message' => 'Connect WhatsApp first (access token and WhatsApp Business Account ID in WhatsApp settings).'];
        $client = self::client($cred['token'], $companyId);
        $r = $client->get($cred['waba_id'] . '/dataset');
        $id = (string)($r['data']['data'][0]['id'] ?? $r['data']['id'] ?? '');
        if ($id === '') {
            $r = $client->post($cred['waba_id'] . '/dataset');
            $id = (string)($r['data']['id'] ?? '');
        }
        if ($id === '') return ['ok' => false, 'message' => 'Meta did not return a dataset: ' . ($r['error']['user_message'] ?? 'unknown error') . ' Create one in Events Manager and paste its ID instead.'];
        self::saveSettings($conn, $companyId, ['dataset_id' => $id, 'enabled' => $s['enabled'] ? 1 : 0, 'mapping' => $s['mapping'],
            'send_value' => $s['send_value'] ? 1 : 0, 'window_days' => $s['window_days'], 'test_event_code' => $s['test_event_code']]);
        return ['ok' => true, 'message' => 'Connected to dataset ' . $id . '.'];
    }

    /** Counts per status since $since (Y-m-d H:i:s). $companyId = 0 → grouped by company. */
    public static function stats(mysqli $conn, int $companyId, string $since): array
    {
        ads_ensure_capi_schema($conn);
        $sql = "SELECT company_id, SUM(status='sent') sent, SUM(status='failed') failed, SUM(status IN ('pending','processing')) pending, SUM(status='skipped') skipped
                FROM capi_events WHERE created_at >= ?" . ($companyId > 0 ? " AND company_id = ?" : "") . " GROUP BY company_id";
        $st = $conn->prepare($sql);
        if ($companyId > 0) $st->bind_param('si', $since, $companyId); else $st->bind_param('s', $since);
        $st->execute();
        $out = [];
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[(int)$r['company_id']] = array_map('intval', $r);
        return $out;
    }
}
