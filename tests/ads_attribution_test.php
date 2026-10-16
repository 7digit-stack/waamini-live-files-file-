<?php
/**
 * Ads & Attribution — Phase 1 integration test (real database, mock media download).
 * Uses fake company ids 99001 / 99002 only and removes everything it created.
 *   php tests/ads_attribution_test.php
 *
 * Covers the Phase 1 acceptance criteria: one referral per message, no duplicate on replay,
 * no change without a referral, contact marked as an ad lead, thumbnail stored, tenant isolation.
 */
chdir(dirname(__DIR__));
define('WAAMINI_LOADED', true);
require 'db.php';
require_once __DIR__ . '/../services/AttributionService.php';
require_once __DIR__ . '/../includes/flow/engine.php';

$CO = 99001; $CO2 = 99002;
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }
function one(mysqli $c, string $sql, string $types = '', ...$p) { $st = $c->prepare($sql); if ($types) $st->bind_param($types, ...$p); $st->execute(); return $st->get_result()->fetch_assoc(); }

function cleanup(mysqli $conn, array $cos): void {
    foreach ($cos as $co) {
        foreach (['ad_referrals', 'ad_catalog', 'contacts'] as $t) { $st = $conn->prepare("DELETE FROM $t WHERE company_id = ?"); $st->bind_param('i', $co); $st->execute(); }
        $dir = MediaVaultService::root() . '/ad_media/' . $co;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

schema_reset('ads_attribution_v1');
ads_ensure_schema($conn);
cleanup($conn, [$CO, $CO2]);

$newContact = static function (int $co, string $phone, string $source = 'organic') use ($conn): int {
    $st = $conn->prepare("INSERT INTO contacts (company_id, name, phone, lead_source) VALUES (?, 'Priya', ?, ?)");
    $st->bind_param('iss', $co, $phone, $source);
    $st->execute();
    return (int)$conn->insert_id;
};
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/ctwa_referral.json'), true);
$msg = $fixture['entry'][0]['changes'][0]['value']['messages'][0];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$fakeFetch = static fn($url) => [200, 'image/png', $png];

echo "Capture\n";
$c1 = $newContact($CO, '919876543210');
$id = AttributionService::capture($conn, $CO, $c1, $msg);
check('referral stored', $id > 0);
check('exactly one row', (int)one($conn, "SELECT COUNT(*) n FROM ad_referrals WHERE company_id=?", 'i', $CO)['n'] === 1);
$ct = one($conn, "SELECT lead_source, first_ad_referral_id, last_ad_referral_id, latest_ctwa_clid FROM contacts WHERE id=?", 'i', $c1);
check('contact marked as ad lead', $ct['lead_source'] === 'ad');
check('first and last touch set', (int)$ct['first_ad_referral_id'] === $id && (int)$ct['last_ad_referral_id'] === $id);
check('latest ctwa_clid saved', strpos((string)$ct['latest_ctwa_clid'], 'ARAk') === 0);
$cat = one($conn, "SELECT headline, media_type FROM ad_catalog WHERE company_id=? AND source_id='120211234567890'", 'i', $CO);
check('ad added to catalog', $cat && $cat['headline'] === 'Diwali Silk Saree Collection' && $cat['media_type'] === 'video');
$row = one($conn, "SELECT clicked_at, media_status FROM ad_referrals WHERE id=?", 'i', $id);
check('clicked_at from the message timestamp', $row['clicked_at'] === date('Y-m-d H:i:s', 1760340000));
check('thumbnail queued', $row['media_status'] === 'pending');

echo "Idempotency\n";
$again = AttributionService::capture($conn, $CO, $c1, $msg);
check('replay returns the same id', $again === $id);
check('replay creates no duplicate', (int)one($conn, "SELECT COUNT(*) n FROM ad_referrals WHERE company_id=?", 'i', $CO)['n'] === 1);
$plain = $msg; unset($plain['referral']); $plain['id'] = 'wamid.PLAIN';
check('message without referral → nothing', AttributionService::capture($conn, $CO, $c1, $plain) === null
    && (int)one($conn, "SELECT COUNT(*) n FROM ad_referrals WHERE company_id=?", 'i', $CO)['n'] === 1);

echo "Touch history\n";
$m2 = $msg; $m2['id'] = 'wamid.SECOND'; $m2['timestamp'] = '1760440000';
$m2['referral']['source_id'] = '120219999999999'; $m2['referral']['headline'] = 'Bridal Collection'; $m2['referral']['ctwa_clid'] = 'ARAnewclid';
$id2 = AttributionService::capture($conn, $CO, $c1, $m2);
$ct = one($conn, "SELECT first_ad_referral_id, last_ad_referral_id, latest_ctwa_clid FROM contacts WHERE id=?", 'i', $c1);
check('first touch kept, last touch updated', (int)$ct['first_ad_referral_id'] === $id && (int)$ct['last_ad_referral_id'] === $id2);
check('latest click id updated', $ct['latest_ctwa_clid'] === 'ARAnewclid');
$touches = AttributionService::touches($conn, $CO, $c1);
check('touch history newest first', count($touches) === 2 && (int)$touches[0]['id'] === $id2);

$imp = $newContact($CO, '919800000001', 'import');
$m3 = $msg; $m3['id'] = 'wamid.IMPORTED';
AttributionService::capture($conn, $CO, $imp, $m3);
check('imported contact keeps its source', one($conn, "SELECT lead_source FROM contacts WHERE id=?", 'i', $imp)['lead_source'] === 'import');

echo "Tenant isolation\n";
$c2 = $newContact($CO2, '919876543210');
$idB = AttributionService::capture($conn, $CO2, $c2, $msg);   // same wamid, other company
check('other company gets its own row', $idB > 0 && $idB !== $id);
check('company A still has its rows only', (int)one($conn, "SELECT COUNT(*) n FROM ad_referrals WHERE company_id=?", 'i', $CO)['n'] === 3);
check('company B sees one row', (int)one($conn, "SELECT COUNT(*) n FROM ad_referrals WHERE company_id=?", 'i', $CO2)['n'] === 1);
check('touches never cross companies', count(AttributionService::touches($conn, $CO2, $c1)) === 0);

echo "Thumbnails\n";
$n = AttributionService::processPendingMedia($conn, $CO, 10, $fakeFetch);
$row = one($conn, "SELECT media_status, local_media_path FROM ad_referrals WHERE id=?", 'i', $id);
check('pending thumbnails downloaded', $n >= 1 && $row['media_status'] === 'done');
check('stored in the company folder', strpos((string)$row['local_media_path'], 'ad_media/' . $CO . '/') === 0 && MediaVaultService::absPath($row['local_media_path']) !== '');
check('catalog gets the thumbnail', (string)one($conn, "SELECT local_thumbnail_path p FROM ad_catalog WHERE company_id=? AND source_id='120211234567890'", 'i', $CO)['p'] !== '');
check('company B untouched by company A run', one($conn, "SELECT media_status s FROM ad_referrals WHERE id=?", 'i', $idB)['s'] === 'pending');
AttributionService::processPendingMedia($conn, $CO2, 10, static fn($u) => [404, '', '']);
AttributionService::processPendingMedia($conn, $CO2, 10, static fn($u) => [404, '', '']);
AttributionService::processPendingMedia($conn, $CO2, 10, static fn($u) => [404, '', '']);
check('expired link gives up after 3 tries', one($conn, "SELECT media_status s FROM ad_referrals WHERE id=?", 'i', $idB)['s'] === 'failed');

echo "Flow trigger input\n";
$in = flow_inbound_from_message($msg, false);
check('referral passed to Flow Builder', is_array($in['referral']) && $in['referral']['source_id'] === '120211234567890');
check('plain message has no referral', flow_inbound_from_message($plain)['referral'] === null);

cleanup($conn, [$CO, $CO2]);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
