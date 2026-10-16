<?php
/**
 * CI self-test for includes/cache.php (file driver, no database needed).
 * Run: php .github/ci/cache_selftest.php
 */
putenv('CACHE_DRIVER=file');
$_ENV['CACHE_DRIVER'] = 'file';
require __DIR__ . '/../../includes/cache.php';

$ok = 0;
$fail = 0;
$check = function (string $name, bool $cond) use (&$ok, &$fail) {
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n";
    $cond ? $ok++ : $fail++;
};

$k = 'ci:' . bin2hex(random_bytes(4));
$check('miss before set', wa_cache_get($k, 60) === null);
$check('set', wa_cache_set($k, ['a' => 1], 30));
$check('get after set', wa_cache_get($k, 60) === ['a' => 1]);

$calls = 0;
$fn = function () use (&$calls) { $calls++; return null; };
wa_cache_remember($k . ':null', 30, $fn);
wa_cache_remember($k . ':null', 30, $fn);
$check('cached null counts as a hit', $calls === 1);

$n = 0;
$count = function () use (&$n) { return ++$n; };
$a = wa_cache_remember_tables($k . ':t', ['companies'], 30, $count);
$b = wa_cache_remember_tables($k . ':t', ['companies'], 30, $count);
$check('table-scoped hit', $a === 1 && $b === 1);
wa_cache_bump_tables(['companies']);
$check('table bump refreshes', wa_cache_remember_tables($k . ':t', ['companies'], 30, $count) === 2);

wa_cache_forget($k);
$check('forget', wa_cache_get($k, 60) === null);

echo "$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
