<?php
/**
 * Ads & Attribution — Phase 2 (Conversions API) integration test.
 * Real database, mocked Meta HTTP. Uses fake company ids 99001 / 99002 and removes everything it created.
 *   php tests/ads_capi_test.php
 *
 * Covers: ad lead → Won queues one Purchase with the sale value; organic lead → skipped; never twice;
 * transient failures retry, permanent ones fail with a readable reason; manual retry; tenant isolation.
 */
chdir(dirname(__DIR__));
define('WAAMINI_LOADED', true);
require 'db.php';
require_once __DIR__ . '/../services/AttributionService.php';
require_once __DIR__ . '/../services/CapiService.php';

$CO = 99001; $CO2 = 99002;
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }
function one(mysqli $c, string $sql, string $types = '', ...$p) { $st = $c->prepare($sql); if ($types) $st->bind_param($types, ...$p); $st->execute(); return $st->get_result()->fetch_assoc(); }
function cleanup(mysqli $conn, array $cos): void {
    foreach ($cos as $co) foreach (['capi_events', 'capi_settings', 'ad_referrals', 'ad_catalog', 'contacts', 'whatsapp_config'] as $t) {
        $st = $conn->prepare("DELETE FROM $t WHERE company_id = ?"); $st->bind_param('i', $co); $st->execute();
    }
}

schema_reset('ads_attribution_v1'); schema_reset('ads_capi_v1');
ads_ensure_capi_schema($conn);
cleanup($conn, [$CO, $CO2]);

// WhatsApp connection for the fake companies (token is what CAPI uses by default)
foreach ([$CO, $CO2] as $co) {
    $st = $conn->prepare("INSERT INTO whatsapp_config (company_id, access_token, phone_number_id, waba_id) VALUES (?, 'EAATESTTOKENxxxxxxxxxxxxxxxx', '111', ?)");
    $w = 'WABA' . $co; $st->bind_param('is', $co, $w); $st->execute();
}
CapiService::saveSettings($conn, $CO, ['dataset_id' => '555', 'enabled' => 1, 'mapping' => CapiService::DEFAULT_MAPPING, 'send_value' => 1, 'window_days' => 7, 'test_event_code' => '']);

// Mock Meta: records requests, answers from a queue (default success)
$sent = []; $answers = [];
CapiService::$transport = static function ($m, $url, $fields, $mp) use (&$sent, &$answers) {
    $sent[] = ['url' => $url, 'fields' => $fields];
    return array_shift($answers) ?? [200, [], '{"events_received":1,"fbtrace_id":"T1"}'];
};

$newContact = static function (int $co, string $phone) use ($conn): int {
    $st = $conn->prepare("INSERT INTO contacts (company_id, name, phone) VALUES (?, 'Test', ?)");
    $st->bind_param('is', $co, $phone); $st->execute();
    return (int)$conn->insert_id;
};
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/ctwa_referral.json'), true);
$msg = $fixture['entry'][0]['changes'][0]['value']['messages'][0];
$msg['timestamp'] = (string)time();   // tapped just now: inside the attribution window

echo "Queueing\n";
$adLead = $newContact($CO, '919876543210');
AttributionService::capture($conn, $CO, $adLead, $msg);
$organic = $newContact($CO, '919811111111');

check('ad lead → Won queues Purchase', CapiService::queueForStage($conn, $CO, $adLead, 'won') === 'pending');
check('same stage again is not queued twice', CapiService::queueForStage($conn, $CO, $adLead, 'won') === 'duplicate');
check('organic lead is logged as skipped', CapiService::queueForStage($conn, $CO, $organic, 'won') === 'skipped');
$sk = one($conn, "SELECT last_error FROM capi_events WHERE company_id=? AND contact_id=?", 'ii', $CO, $organic);
check('skip reason is readable', stripos((string)$sk['last_error'], 'not from a click-to-whatsapp ad') !== false);
check('lost is not mapped by default', CapiService::queueForStage($conn, $CO, $adLead, 'lost') === null);

$old = $newContact($CO, '919822222222');
$mOld = $msg; $mOld['id'] = 'wamid.OLD'; $mOld['timestamp'] = (string)(time() - 10 * 86400);
AttributionService::capture($conn, $CO, $old, $mOld);
check('tap older than the window is skipped', CapiService::queueForStage($conn, $CO, $old, 'qualified') === 'skipped');

echo "Sending\n";
$r = CapiService::processDue($conn, $CO, 10);
check('Purchase waits for the sale value', $r['sent'] === 0 && count($sent) === 0);
$conn->query("UPDATE contacts SET won_value = 4999 WHERE id = $adLead");
$r = CapiService::processDue($conn, $CO, 10, time() + CapiService::PURCHASE_WAIT + 5);
check('sent once the value is there', $r['sent'] === 1 && count($sent) === 1);
check('posted to /{dataset}/events', strpos($sent[0]['url'] ?? '', '/555/events') !== false);
$data = json_decode((string)($sent[0]['fields']['data'] ?? ''), true);
check('payload carries click id, WABA and ₹ value', ($data[0]['user_data']['ctwa_clid'] ?? '') !== '' && ($data[0]['user_data']['whatsapp_business_account_id'] ?? '') === 'WABA' . $CO && ($data[0]['custom_data']['value'] ?? 0) == 4999);
$ev = one($conn, "SELECT status, value, fbtrace_id FROM capi_events WHERE company_id=? AND contact_id=? AND event_name='Purchase'", 'ii', $CO, $adLead);
check('marked sent with value and trace id', $ev['status'] === 'sent' && (float)$ev['value'] === 4999.0 && $ev['fbtrace_id'] === 'T1');

echo "Failures\n";
$q = $newContact($CO, '919833333333');
$mQ = $msg; $mQ['id'] = 'wamid.Q';
AttributionService::capture($conn, $CO, $q, $mQ);
CapiService::queueForStage($conn, $CO, $q, 'qualified');
$answers = [[500, [], '{"error":{"code":2,"message":"Service temporarily unavailable","is_transient":true}}']];
CapiService::processDue($conn, $CO, 10);
$e = one($conn, "SELECT status, attempts, run_at FROM capi_events WHERE company_id=? AND contact_id=?", 'ii', $CO, $q);
check('transient error retried later', $e['status'] === 'pending' && (int)$e['attempts'] === 1 && strtotime($e['run_at']) > time());
$answers = [[400, [], '{"error":{"code":100,"message":"Invalid ctwa_clid"}}']];
CapiService::processDue($conn, $CO, 10, time() + 3600);
$e = one($conn, "SELECT status, last_error FROM capi_events WHERE company_id=? AND contact_id=?", 'ii', $CO, $q);
check('permanent error fails with Meta\'s reason', $e['status'] === 'failed' && stripos((string)$e['last_error'], 'Invalid ctwa_clid') !== false);
$eid = (int)one($conn, "SELECT id FROM capi_events WHERE company_id=? AND contact_id=?", 'ii', $CO, $q)['id'];
check('manual retry re-queues a failed event', CapiService::retry($conn, $CO, $eid) && one($conn, "SELECT status s FROM capi_events WHERE id=?", 'i', $eid)['s'] === 'pending');
check('other company cannot retry it', !CapiService::retry($conn, $CO2, $eid));

echo "Isolation\n";
$b = $newContact($CO2, '919876543210');
AttributionService::capture($conn, $CO2, $b, $msg);
check('company without CAPI settings queues nothing', CapiService::queueForStage($conn, $CO2, $b, 'won') === null);
$stats = CapiService::stats($conn, $CO, date('Y-m-d 00:00:00', strtotime('-1 day')));
check('stats scoped to the company', isset($stats[$CO]) && !isset($stats[$CO2]));

CapiService::$transport = null;
cleanup($conn, [$CO, $CO2]);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
