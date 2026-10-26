<?php
/**
 * Cron run log: records when each script started and finished when it runs from the command line
 * (that is, from a Hostinger cron job). Shown in Admin > System Report > "Cron jobs".
 * Loaded from db.php, config/bootstrap.php and config/database.php; counts once per run.
 * Normal page visits are never recorded. Data: storage/cron_runs.json (not reachable from the web).
 */
if (PHP_SAPI === 'cli' && !defined('WAAMINI_CRON_RUNLOG')) {
    define('WAAMINI_CRON_RUNLOG', true);
    (static function () {
        $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '')));
        if ($script === '' || !preg_match('/^[\w.-]{1,80}\.php$/', $script)) return;
        $file  = dirname(__DIR__) . '/storage/cron_runs.json';
        $start = microtime(true);

        $write = static function (array $patch) use ($file, $script): void {
            try {
                $dir = dirname($file);
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $fh = @fopen($file, 'c+');
                if (!$fh) return;
                if (flock($fh, LOCK_EX)) {
                    $all = json_decode((string)stream_get_contents($fh), true);
                    if (!is_array($all)) $all = [];
                    $row = array_merge($all[$script] ?? [], $patch);
                    if (!empty($patch['started'])) {
                        $row['runs'] = (int)($row['runs'] ?? 0) + 1;
                        // last 6 start times: shows a wrong schedule (e.g. a daily job running twice)
                        $row['recent'] = array_slice(array_merge([(int)$patch['started']], (array)($row['recent'] ?? [])), 0, 6);
                    }
                    $all[$script] = $row;
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    fflush($fh);
                    flock($fh, LOCK_UN);
                }
                fclose($fh);
            } catch (\Throwable $e) { /* never break a cron job because of its log */ }
        };

        $write(['started' => time(), 'running' => true]);
        register_shutdown_function(static function () use ($write, $start): void {
            $e = error_get_last();
            $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            $write([
                'finished' => time(),
                'seconds'  => round(microtime(true) - $start, 1),
                'running'  => false,
                'error'    => $fatal ? substr((string)$e['message'], 0, 220) : '',
            ]);
        });
    })();
}
