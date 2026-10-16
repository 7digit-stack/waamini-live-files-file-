<?php
/**
 * WAAMINI - Data cache (Redis first, file fallback)
 *
 * Driver choice (.env):
 *   CACHE_DRIVER=redis   use Redis (REDIS_URL or REDIS_HOST/PORT/PASSWORD/DB). Falls back to files if Redis is down.
 *   CACHE_DRIVER=file    files under storage/cache (default; safe on shared hosting)
 *   CACHE_DRIVER=auto    Redis when REDIS_URL or REDIS_HOST is set, otherwise files
 *
 * Redis works with the phpredis extension when installed, and otherwise with a small built-in client
 * (plain TCP or TLS via rediss://), so it also runs on hosts without ext-redis.
 *
 * Invalidation: every cached read that depends on a table is keyed by that table's version
 * (wa_cache_remember_tables). includes/db_cache.php bumps the version whenever a write hits a
 * watched table, so cached data is refreshed right after it changes instead of waiting for a TTL.
 */

if (!defined('WAAMINI_LOADED')) {
    define('WAAMINI_LOADED', true);
}

function wa_cache_env(string $key, string $default = ''): string
{
    if (class_exists('\Waamini\Config\Env')) {
        $v = \Waamini\Config\Env::get($key, null);
        if ($v !== null && $v !== '') return (string)$v;
    }
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}

/** Connection settings, from REDIS_URL (redis://user:pass@host:port/db, rediss:// for TLS) or REDIS_* keys. */
function wa_redis_config(): array
{
    $c = [
        'scheme' => 'tcp',
        'host' => wa_cache_env('REDIS_HOST', '127.0.0.1'),
        'port' => (int)wa_cache_env('REDIS_PORT', '6379'),
        'user' => wa_cache_env('REDIS_USERNAME', ''),
        'pass' => wa_cache_env('REDIS_PASSWORD', ''),
        'db' => (int)wa_cache_env('REDIS_DB', '0'),
        'timeout' => (float)wa_cache_env('REDIS_TIMEOUT', '1.0'),
        'persistent' => in_array(strtolower(wa_cache_env('REDIS_PERSISTENT', '1')), ['1', 'true', 'yes', 'on'], true),
    ];
    $url = wa_cache_env('REDIS_URL', '');
    if ($url !== '' && ($u = parse_url($url))) {
        if (!empty($u['scheme'])) $c['scheme'] = strtolower($u['scheme']) === 'rediss' ? 'tls' : 'tcp';
        if (!empty($u['host'])) $c['host'] = $u['host'];
        if (!empty($u['port'])) $c['port'] = (int)$u['port'];
        if (isset($u['user']) && $u['user'] !== '') $c['user'] = rawurldecode($u['user']);
        if (isset($u['pass']) && $u['pass'] !== '') $c['pass'] = rawurldecode($u['pass']);
        if (!empty($u['path']) && trim($u['path'], '/') !== '') $c['db'] = (int)trim($u['path'], '/');
    }
    if (strtolower(wa_cache_env('REDIS_SCHEME', '')) === 'tls') $c['scheme'] = 'tls';
    return $c;
}

function wa_cache_driver_wanted(): string
{
    $d = strtolower(wa_cache_env('CACHE_DRIVER', 'file'));
    if ($d === 'auto') {
        return (wa_cache_env('REDIS_URL', '') !== '' || wa_cache_env('REDIS_HOST', '') !== '') ? 'redis' : 'file';
    }
    return $d === 'redis' ? 'redis' : 'file';
}

/**
 * Native phpredis connection (kept for the optional Redis queue code, which needs the Redis class).
 * Returns null when the extension is missing or Redis is not selected.
 */
function wa_redis(): ?Redis
{
    static $redis = null;
    static $failed = false;
    if ($failed) return null;
    if ($redis instanceof Redis) return $redis;
    if (wa_cache_driver_wanted() !== 'redis' || !class_exists('Redis')) return null;

    $c = wa_redis_config();
    try {
        $r = new Redis();
        $host = $c['scheme'] === 'tls' ? 'tls://' . $c['host'] : $c['host'];
        if ($c['persistent']) {
            $r->pconnect($host, $c['port'], $c['timeout'], 'waamini');
        } else {
            $r->connect($host, $c['port'], $c['timeout']);
        }
        if ($c['pass'] !== '') {
            $r->auth($c['user'] !== '' ? [$c['user'], $c['pass']] : $c['pass']);
        }
        if ($c['db'] > 0) $r->select($c['db']);
        return $redis = $r;
    } catch (\Throwable $e) {
        $failed = true;
        error_log('[WAAMINI Redis] unavailable: ' . $e->getMessage());
        return null;
    }
}

/**
 * Minimal Redis client (RESP2 over a socket) used when ext-redis is not installed.
 * Implements only what the cache needs.
 */
class WaaminiRedisLite
{
    private $sock;

    public function __construct(array $c)
    {
        $target = ($c['scheme'] === 'tls' ? 'tls://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $flags = STREAM_CLIENT_CONNECT | ($c['persistent'] ? STREAM_CLIENT_PERSISTENT : 0);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client($target, $errno, $errstr, max(0.2, $c['timeout']), $flags, $ctx);
        if (!$sock) throw new RuntimeException("connect {$c['host']}:{$c['port']} failed: $errstr");
        stream_set_timeout($sock, (int)ceil(max(0.5, $c['timeout'])));
        $this->sock = $sock;
        if ($c['pass'] !== '') {
            $this->cmd($c['user'] !== '' ? ['AUTH', $c['user'], $c['pass']] : ['AUTH', $c['pass']]);
        }
        if ($c['db'] > 0) $this->cmd(['SELECT', (string)$c['db']]);
    }

    public function cmd(array $args)
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        for ($off = 0, $len = strlen($out); $off < $len;) {
            $w = @fwrite($this->sock, substr($out, $off));
            if ($w === false || $w === 0) throw new RuntimeException('Redis write failed');
            $off += $w;
        }
        return $this->read();
    }

    private function read()
    {
        $line = fgets($this->sock);
        if ($line === false) throw new RuntimeException('Redis read failed');
        $type = $line[0];
        $body = substr($line, 1, -2);
        switch ($type) {
            case '+': return $body;
            case '-': throw new RuntimeException('Redis error: ' . $body);
            case ':': return (int)$body;
            case '$':
                $n = (int)$body;
                if ($n < 0) return false;
                $data = '';
                while (strlen($data) < $n + 2) {
                    $chunk = fread($this->sock, $n + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') throw new RuntimeException('Redis read failed');
                    $data .= $chunk;
                }
                return substr($data, 0, $n);
            case '*':
                $n = (int)$body;
                if ($n < 0) return false;
                $arr = [];
                for ($i = 0; $i < $n; $i++) $arr[] = $this->read();
                return $arr;
        }
        throw new RuntimeException('Redis protocol error');
    }

    public function get(string $k) { return $this->cmd(['GET', $k]); }
    public function setex(string $k, int $ttl, string $v) { return $this->cmd(['SETEX', $k, (string)$ttl, $v]) === 'OK'; }
    public function incr(string $k) { return (int)$this->cmd(['INCR', $k]); }
    public function del(string $k) { return (int)$this->cmd(['DEL', $k]); }
    public function mget(array $keys) { return $keys ? $this->cmd(array_merge(['MGET'], $keys)) : []; }
    public function ping() { return $this->cmd(['PING']); }
    public function info() { $raw = (string)$this->cmd(['INFO', 'server']); $o = []; foreach (preg_split('/\r?\n/', $raw) as $l) { if (strpos($l, ':') !== false) { [$a, $b] = explode(':', $l, 2); $o[$a] = $b; } } return $o; }
}

/**
 * Connection used by the cache: phpredis when installed, otherwise the built-in client.
 * Returns null when Redis is not selected or not reachable (the cache then uses files).
 */
function wa_cache_redis()
{
    global $__waCacheRedisFailed;
    static $client = null;
    if (!empty($__waCacheRedisFailed)) return null;
    if ($client !== null) return $client;
    if (wa_cache_driver_wanted() !== 'redis') return null;

    $native = wa_redis();
    if ($native instanceof Redis) return $client = $native;
    try {
        return $client = new WaaminiRedisLite(wa_redis_config());
    } catch (\Throwable $e) {
        wa_cache_redis_down($e);
        return null;
    }
}

/** Stop using Redis for the rest of this request after any error, so a Redis outage never breaks a page. */
function wa_cache_redis_down(\Throwable $e): void
{
    global $__waCacheRedisFailed;
    if (empty($__waCacheRedisFailed)) error_log('[WAAMINI cache] Redis unavailable, using file cache: ' . $e->getMessage());
    $__waCacheRedisFailed = true;
}

/** Driver actually in use for this request: "redis" or "file". */
function wa_cache_driver(): string
{
    return wa_cache_redis() !== null ? 'redis' : 'file';
}

function wa_cache_prefix(): string
{
    return wa_cache_env('CACHE_PREFIX', 'waamini:');
}

function wa_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function wa_cache_meta_dir(): string
{
    $dir = wa_cache_dir() . '/_meta';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function wa_cache_key(string $key): string
{
    return hash('sha256', $key);
}

function wa_cache_default_ttl(): int
{
    return max(1, (int)wa_cache_env('CACHE_DEFAULT_TTL', '60'));
}

function wa_cache_ns_clean(string $namespace): string
{
    return (string)preg_replace('/[^a-zA-Z0-9:_\\-\\.]/', '_', $namespace);
}

/**
 * Cache namespace versioning:
 * - Reads are keyed with a namespace version.
 * - Writes "bump" the version after DB updates to invalidate everything in that namespace.
 */
function wa_cache_ns_version(string $namespace): int
{
    $namespace = wa_cache_ns_clean($namespace);
    if ($r = wa_cache_redis()) {
        try {
            return max(0, (int)$r->get(wa_cache_prefix() . 'ns:' . $namespace));
        } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    $raw = @file_get_contents(wa_cache_meta_dir() . '/' . wa_cache_key('ns:' . $namespace) . '.txt');
    return max(0, is_string($raw) ? (int)trim($raw) : 0);
}

/** Versions of several namespaces in one round trip. */
function wa_cache_ns_versions(array $namespaces): array
{
    $namespaces = array_values(array_unique(array_map('wa_cache_ns_clean', $namespaces)));
    if (!$namespaces) return [];
    if ($r = wa_cache_redis()) {
        try {
            $vals = $r->mget(array_map(static fn($n) => wa_cache_prefix() . 'ns:' . $n, $namespaces));
            $out = [];
            foreach ($namespaces as $i => $n) $out[$n] = max(0, (int)($vals[$i] ?? 0));
            return $out;
        } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    $out = [];
    foreach ($namespaces as $n) $out[$n] = wa_cache_ns_version($n);
    return $out;
}

function wa_cache_bump(string $namespace): int
{
    $namespace = wa_cache_ns_clean($namespace);
    $next = 0;
    if ($r = wa_cache_redis()) {
        try {
            $next = (int)$r->incr(wa_cache_prefix() . 'ns:' . $namespace);
        } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    // Always bump the file version too, so a later fallback to files never serves data cached before this write.
    $path = wa_cache_meta_dir() . '/' . wa_cache_key('ns:' . $namespace) . '.txt';
    $fh = @fopen($path, 'c+');
    if ($fh) {
        if (@flock($fh, LOCK_EX)) {
            $cur = (int)trim((string)stream_get_contents($fh));
            $fileNext = max($cur + 1, $next);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)$fileNext);
            fflush($fh);
            flock($fh, LOCK_UN);
            $next = max($next, $fileNext);
        }
        fclose($fh);
    }
    return $next;
}

function wa_cache_scoped_key(string $namespace, string $key): string
{
    $v = wa_cache_ns_version($namespace);
    return "ns={$namespace}:v={$v}:{$key}";
}

/** Low-level read: [true, value] on hit, [false, null] on miss. Cached nulls and falses count as hits. */
function wa_cache_fetch(string $key, ?int $maxAge = null): array
{
    if ($r = wa_cache_redis()) {
        try {
            $raw = $r->get(wa_cache_prefix() . 'cache:' . $key);
            if (!is_string($raw) || $raw === '') return [false, null];
            $d = json_decode($raw, true);
            return is_array($d) && array_key_exists('value', $d) ? [true, $d['value']] : [false, null];
        } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    $path = wa_cache_dir() . '/' . wa_cache_key($key) . '.json';
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [false, null];
    $d = json_decode($raw, true);
    if (!is_array($d) || !array_key_exists('value', $d)) return [false, null];
    $now = time();
    if (isset($d['exp']) && (int)$d['exp'] < $now) return [false, null];
    if ($maxAge !== null) {
        $mtime = @filemtime($path);
        if (!$mtime || ($now - (int)$mtime) > max(1, $maxAge)) return [false, null];
    }
    return [true, $d['value']];
}

function wa_cache_get(string $key, int $ttlSeconds)
{
    [$hit, $val] = wa_cache_fetch($key, $ttlSeconds);
    return $hit ? $val : null;
}

/** Store a JSON-serialisable value. $ttlSeconds defaults to CACHE_DEFAULT_TTL (60s). */
function wa_cache_set(string $key, $value, ?int $ttlSeconds = null): bool
{
    $ttl = max(1, $ttlSeconds ?? wa_cache_default_ttl());
    // Without an explicit TTL, file entries keep the old behaviour: the reader's max age decides.
    $data = ['value' => $value];
    if ($ttlSeconds !== null) $data['exp'] = time() + $ttl;
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload)) return false;
    if ($r = wa_cache_redis()) {
        try {
            return (bool)$r->setex(wa_cache_prefix() . 'cache:' . $key, $ttl, $payload);
        } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    $path = wa_cache_dir() . '/' . wa_cache_key($key) . '.json';
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

function wa_cache_forget(string $key): void
{
    if ($r = wa_cache_redis()) {
        try { $r->del(wa_cache_prefix() . 'cache:' . $key); } catch (\Throwable $e) { wa_cache_redis_down($e); }
    }
    @unlink(wa_cache_dir() . '/' . wa_cache_key($key) . '.json');
}

/**
 * Cache helper: compute once, store, return.
 * @param callable $fn must return a JSON-serializable value
 */
function wa_cache_remember(string $key, int $ttlSeconds, callable $fn)
{
    [$hit, $val] = wa_cache_fetch($key, $ttlSeconds);
    if ($hit) {
        $GLOBALS['__waCacheStats']['hit'] = ($GLOBALS['__waCacheStats']['hit'] ?? 0) + 1;
        return $val;
    }
    $GLOBALS['__waCacheStats']['miss'] = ($GLOBALS['__waCacheStats']['miss'] ?? 0) + 1;
    $val = $fn();
    wa_cache_set($key, $val, $ttlSeconds);
    return $val;
}

/**
 * Like wa_cache_remember, but the entry is tied to the current version of each table in $tables.
 * Any INSERT/UPDATE/DELETE on one of those tables (see includes/db_cache.php) makes it refresh.
 */
function wa_cache_remember_tables(string $key, array $tables, int $ttlSeconds, callable $fn)
{
    $vers = wa_cache_ns_versions(array_map(static fn($t) => 'tbl:' . strtolower($t), $tables));
    $tag = [];
    foreach ($vers as $n => $v) $tag[] = substr($n, 4) . $v;
    return wa_cache_remember('t[' . implode(',', $tag) . ']:' . $key, $ttlSeconds, $fn);
}

/** Bump the version of one or more tables (normally done automatically by includes/db_cache.php). */
function wa_cache_bump_tables(array $tables): void
{
    foreach (array_unique(array_map('strtolower', $tables)) as $t) wa_cache_bump('tbl:' . $t);
}

/** Health info for admin pages. */
function wa_cache_status(): array
{
    $wanted = wa_cache_driver_wanted();
    $out = ['wanted' => $wanted, 'driver' => 'file', 'client' => '', 'detail' => 'Files in storage/cache'];
    if ($wanted !== 'redis') return $out;
    $r = wa_cache_redis();
    if ($r === null) {
        $out['detail'] = 'Redis selected but not reachable, so files are used';
        return $out;
    }
    $out['driver'] = 'redis';
    $out['client'] = $r instanceof Redis ? 'phpredis' : 'built-in';
    try {
        $info = $r->info();
        $c = wa_redis_config();
        $out['detail'] = 'Redis ' . ($info['redis_version'] ?? '') . ' at ' . $c['host'] . ':' . $c['port'];
    } catch (\Throwable $e) {
        $out['detail'] = 'Redis connected';
    }
    return $out;
}

// ---------------------------------------------------------------------
// Backwards compatibility for older admin code paths using waamini_cache_*
// ---------------------------------------------------------------------
if (!function_exists('waamini_cache_get')) {
    function waamini_cache_get(string $key, int $ttlSeconds)
    {
        return wa_cache_get($key, $ttlSeconds);
    }
}
if (!function_exists('waamini_cache_set')) {
    function waamini_cache_set(string $key, $value, ?int $ttlSeconds = null): void
    {
        wa_cache_set($key, $value, $ttlSeconds);
    }
}
