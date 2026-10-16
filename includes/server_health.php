<?php
// WAAMINI Server Health Helper

require_once __DIR__ . '/cache.php';

function getServerHealth($conn)
{
    // Disk + aggregate checks can be surprisingly slow on Windows (Defender / path resolution). Cache across requests.
    $cacheKey = 'waamini:server_health:v2';
    $cached = waamini_cache_get($cacheKey, 60);
    if (is_array($cached) && isset($cached['php_version'], $cached['disk_pct'])) {
        try {
            $cached['mysql_status'] = (function () use ($conn) {
                try {
                    $conn->query('SELECT 1');

                    return '<span style="color: var(--success); font-weight: 600;">CONNECTED</span>';
                } catch (\Throwable $e) {
                    return '<span style="color: var(--danger); font-weight: 600;">FAILED</span>';
                }
            })();
        } catch (\Throwable $_e) {
            // keep cached mysql line on failure
        }
        $cached['php_memory'] = round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';

        return $cached;
    }

    $health = [
        'php_version' => phpversion(),
        'mysql_status' => (function () use ($conn) {
            // mysqli::ping() deprecated in PHP 8.1+ — use a lightweight query instead
            try {
                $conn->query('SELECT 1');

                return '<span style="color: var(--success); font-weight: 600;">CONNECTED</span>';
            } catch (\Throwable $e) {
                return '<span style="color: var(--danger); font-weight: 600;">FAILED</span>';
            }
        })(),
        'php_memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'disk_total' => 'N/A',
        'disk_used' => 'N/A',
        'disk_pct' => 0,
        'server_ram' => 'N/A',
        'server_cpu' => 'N/A'
    ];

    // Disk Space
    $dt = @disk_total_space(".");
    $df = @disk_free_space(".");
    if ($dt > 0) {
        $du = $dt - $df;
        $health['disk_pct'] = round(($du / $dt) * 100, 1);
        $health['disk_used'] = round($du / 1024 / 1024 / 1024, 2) . ' GB';
        $health['disk_total'] = round($dt / 1024 / 1024 / 1024, 2) . ' GB';
    }

    // RAM & CPU (Linux Only natively, fallback for Windows)
    if (PHP_OS_FAMILY === 'Linux') {
        // RAM
        $free = @shell_exec('free -m');
        if ($free) {
            $free_arr = explode("\n", trim($free));
            $mem = array_values(array_filter(explode(" ", $free_arr[1])));
            if (isset($mem[1]) && isset($mem[2])) {
                $health['server_ram'] = round($mem[2] / 1024, 2) . 'GB / ' . round($mem[1] / 1024, 2) . 'GB';
            }
        }

        // CPU Load
        $load = sys_getloadavg();
        if ($load) {
            $health['server_cpu'] = $load[0] . ' (1m avg)';
        }
    }
    else {
        $health['server_ram'] = 'OS Native Info Hidden';
        $health['server_cpu'] = 'OS Native Info Hidden';
    }

    waamini_cache_set($cacheKey, $health, 60);

    return $health;
}
?>
