<?php
/**
 * Ads & Attribution unit tests — no database, no network (mock transport / fetch).
 *   php tests/ads_unit_test.php
 */
chdir(dirname(__DIR__));
require_once 'services/MetaGraphClient.php';
require_once 'services/AttributionService.php';
require_once 'services/MediaVaultService.php';
require_once 'includes/ads/helpers.php';
require_once 'services/CapiService.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }

echo "MetaGraphClient\n";
// Scripted transport: returns queued responses and records requests.
$mk = static function (array $responses, array &$calls, array &$sleeps, array $opts = []) {
    return new MetaGraphClient('EAATESTTOKEN1234567890abcdefXYZ', $opts + [
        'company_id' => 99001, 'max_retries' => 3,
        'transport' => static function ($m, $url, $fields, $mp) use (&$responses, &$calls) { $calls[] = [$m, $url, $fields]; return array_shift($responses); },
        'sleeper' => static function ($s) use (&$sleeps) { $sleeps[] = $s; },
    ]);
};
$calls = []; $sleeps = [];
$c = $mk([[200, [], '{"id":"123"}']], $calls, $sleeps);
$r = $c->get('me', ['fields' => 'id']);
check('success returns data', $r['ok'] && $r['data']['id'] === '123');
check('version from config in URL', strpos($calls[0][1], 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/me?') === 0);
check('no appsecret_proof for a tenant token', strpos($calls[0][1], 'appsecret_proof') === false);

$calls = []; $sleeps = [];
$c = $mk([[200, [], '{"id":"1"}']], $calls, $sleeps, ['own_app' => true, 'app_secret' => 'shh']);
$c->get('me');
check('appsecret_proof added for our own app', strpos($calls[0][1], 'appsecret_proof=' . hash_hmac('sha256', 'EAATESTTOKEN1234567890abcdefXYZ', 'shh')) !== false);

$calls = []; $sleeps = [];
$c = $mk([[500, [], '{"error":{"code":2,"message":"temp"}}'], [400, [], '{"error":{"code":613,"message":"rate"}}'], [200, [], '{"ok":true}']], $calls, $sleeps);
$r = $c->post('act_1/campaigns', ['name' => 'x', 'targeting' => ['geo' => ['IN']]]);
check('retries transient errors then succeeds', $r['ok'] && count($calls) === 3);
check('backoff waits between retries', count($sleeps) === 2 && $sleeps[1] > $sleeps[0] - 0.6);
check('nested arrays sent as JSON', ($calls[0][2]['targeting'] ?? '') === '{"geo":["IN"]}');

$calls = []; $sleeps = []; $authHit = 0;
$c = $mk([[400, [], '{"error":{"code":190,"error_subcode":463,"message":"Session has expired","fbtrace_id":"ABC"}}']], $calls, $sleeps, ['on_auth_error' => static function () use (&$authHit) { $authHit++; }]);
$r = $c->get('me');
check('auth error (190) is never retried', !$r['ok'] && count($calls) === 1 && $r['error']['auth'] === true);
check('auth error callback fires once (needs reconnect)', $authHit === 1);
check('friendly message for expired session', stripos($r['error']['user_message'], 'reconnect') !== false);
check('fbtrace_id kept', $r['error']['fbtrace_id'] === 'ABC');

$calls = []; $sleeps = [];
$c = $mk([[400, [], '{"error":{"code":100,"message":"Invalid parameter"}}']], $calls, $sleeps);
$r = $c->get('x');
check('permanent error (100) not retried', !$r['ok'] && count($calls) === 1 && !$r['error']['retryable']);

$calls = []; $sleeps = [];
$c = $mk([[200, ['x-business-use-case-usage' => '{"123":[{"call_count":91,"total_cputime":10,"total_time":12}]}'], '{}'], [200, [], '{}']], $calls, $sleeps);
$c->get('a'); $c->get('b');
check('reads usage headers', $c->lastUsage() === 0 && count($sleeps) === 1, 'sleeps=' . count($sleeps));
check('usage parser', MetaGraphClient::usageFromHeaders(['X-App-Usage' => '{"call_count":85,"total_time":20}']) === 85);
check('token masked', MetaGraphClient::maskToken('EAATESTTOKEN1234567890abcdefXYZ') === 'EAATES…fXYZ');
check('tokens scrubbed from text', strpos(MetaGraphClient::scrub('url?access_token=EAAsecret&x=1 EAAABBBCCCDDDEEEFFFGGGHHH'), 'secret') === false);

echo "AttributionService::referral\n";
$fixture = json_decode(file_get_contents('tests/fixtures/ctwa_referral.json'), true);
$msg = $fixture['entry'][0]['changes'][0]['value']['messages'][0];
$ref = AttributionService::referral($msg);
check('fixture parsed', $ref && $ref['source_id'] === '120211234567890' && $ref['source_type'] === 'ad');
check('video url and thumbnail kept', $ref['media_type'] === 'video' && strpos($ref['thumbnail_url'], 'fbcdn.net') !== false && strpos($ref['media_url'], '.mp4') !== false);
check('ctwa_clid kept', strpos($ref['ctwa_clid'], 'ARAk') === 0);
check('message without referral → null', AttributionService::referral(['type' => 'text', 'text' => ['body' => 'hi']]) === null);
check('empty referral → null', AttributionService::referral(['referral' => []]) === null);
check('referral with nothing identifying → null', AttributionService::referral(['referral' => ['media_type' => 'image']]) === null);
$post = AttributionService::referral(['type' => 'image', 'referral' => ['source_type' => 'post', 'source_url' => 'https://fb.com/p/1', 'image_url' => 'https://scontent.fbcdn.net/i.jpg', 'media_type' => 'image']]);
check('post on an image message', $post && $post['source_type'] === 'post' && $post['media_url'] !== '');
$unk = AttributionService::referral(['type' => 'button', 'referral' => ['source_type' => 'reel_v2!', 'source_id' => '9']]);
check('unknown source type kept, cleaned', $unk && $unk['source_type'] === 'reel_v');
$noType = AttributionService::referral(['referral' => ['source_id' => '5']]);
check('missing source type → unknown', $noType['source_type'] === 'unknown');

echo "MediaVaultService\n";
check('Meta CDN allowed', MediaVaultService::isAllowedUrl('https://scontent.xx.fbcdn.net/v/a.jpg'));
check('http rejected', !MediaVaultService::isAllowedUrl('http://scontent.xx.fbcdn.net/v/a.jpg'));
check('other host rejected (SSRF)', !MediaVaultService::isAllowedUrl('https://169.254.169.254/latest') && !MediaVaultService::isAllowedUrl('https://evil-fbcdn.net/a.jpg'));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$res = MediaVaultService::saveFromUrl(99001, 'https://scontent.fbcdn.net/t.png', 'ad_media', static fn($u) => [200, 'image/png', $png]);
check('download saved in the company folder', $res['ok'] && strpos($res['path'], 'ad_media/99001/') === 0 && MediaVaultService::absPath($res['path']) !== '');
$bad = MediaVaultService::saveFromUrl(99001, 'https://scontent.fbcdn.net/t.html', 'ad_media', static fn($u) => [200, 'text/html', '<html>x</html>']);
check('non-media rejected', !$bad['ok']);
check('path traversal blocked', MediaVaultService::absPath('../config/meta.php') === '');
if ($res['ok']) { @unlink(MediaVaultService::absPath($res['path'])); @rmdir(MediaVaultService::root() . '/ad_media/99001'); }

echo "Helpers\n";
check('₹ Indian format', ads_inr(125000) === '₹1,25,000' && ads_inr(4999) === '₹4,999' && ads_inr(12345678.5) === '₹1,23,45,678.50');
check('stage from outcome', ads_stage_for_outcome('Converted') === 'won' && ads_stage_for_outcome('Not Interested') === 'lost' && ads_stage_for_outcome('Follow-up') === 'qualified');
$rg = ads_range(['range' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-01']);
check('custom range swaps reversed dates', $rg['from'] === '2026-10-01 00:00:00' && $rg['to'] === '2026-10-20 23:59:59');

echo "CapiService payload\n";

$p = CapiService::payload('Purchase', 'waamini_1_2_Purchase', 1760345000, 'WABA1', 'ARAkclid', 4999.0);
$ev = $p['data'][0];
check('business messaging action source', $ev['action_source'] === 'business_messaging' && $ev['messaging_channel'] === 'whatsapp');
check('click id + WABA in user_data', $ev['user_data'] === ['whatsapp_business_account_id' => 'WABA1', 'ctwa_clid' => 'ARAkclid']);
check('INR value on Purchase', $ev['custom_data'] === ['currency' => 'INR', 'value' => 4999.0]);
check('stable event_id for dedup', $ev['event_id'] === 'waamini_1_2_Purchase' && $ev['event_time'] === 1760345000);
$p2 = CapiService::payload('LeadSubmitted', 'x', 1, 'W', 'C', null, 'INR', 'TEST123');
check('no custom_data without a value', !isset($p2['data'][0]['custom_data']));
check('test event code at top level', $p2['test_event_code'] === 'TEST123');
check('default mapping', CapiService::DEFAULT_MAPPING['won'] === 'Purchase' && CapiService::DEFAULT_MAPPING['lost'] === '');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
