<?php
/**
 * MetaGraphClient — the single HTTP client for every Ads & Attribution call to Meta's Graph API.
 *
 *  - Version from META_GRAPH_VERSION (config/meta.php), never hard-coded in callers.
 *  - appsecret_proof added only when the token was issued to our own app (tenants often use their own
 *    Meta app for WhatsApp; a proof made with our secret would make Meta reject their token).
 *  - Reads X-App-Usage / X-Business-Use-Case-Usage and backs off above META_USAGE_BACKOFF_PCT.
 *  - Retries transient errors (1, 2, 4, 17, 32, 613, 80000-series, HTTP 5xx) with exponential backoff + jitter.
 *    Never retries auth errors (190): those are reported so the account can be marked "needs reconnect".
 *  - Turns Meta errors into short messages a business owner understands.
 *  - Tokens are masked in every log line; fbtrace_id is logged for support tickets.
 *
 * Every call returns: ['ok' => bool, 'status' => int, 'data' => array, 'usage' => int (0-100), 'error' => ?array]
 * error = ['code','subcode','type','message','user_message','fbtrace_id','retryable','auth']
 */
require_once __DIR__ . '/../config/meta.php';

class MetaGraphClient
{
    private const RETRYABLE_CODES = [1, 2, 4, 17, 32, 341, 613];

    private string $token;
    private string $appSecret;
    private bool $useProof;
    private int $companyId;
    private int $maxRetries;
    private int $timeout;
    /** @var callable(string $method, string $url, array $fields, bool $multipart): array{0:int,1:array,2:string} */
    private $transport;
    /** @var callable(float $seconds): void */
    private $sleeper;
    /** @var ?callable(array $error): void  called once on an auth error (code 190) */
    private $onAuthError;
    private int $lastUsage = 0;

    /**
     * @param array $opts company_id, app_secret, use_proof (bool, default: true when META_APP_SECRET is set and
     *                    'own_app' => true), max_retries (3), timeout (30), transport, sleeper, on_auth_error
     */
    public function __construct(string $accessToken, array $opts = [])
    {
        $this->token       = trim($accessToken);
        $this->appSecret   = (string)($opts['app_secret'] ?? META_APP_SECRET);
        $this->useProof    = (bool)($opts['use_proof'] ?? (!empty($opts['own_app']) && $this->appSecret !== ''));
        $this->companyId   = (int)($opts['company_id'] ?? 0);
        $this->maxRetries  = max(0, (int)($opts['max_retries'] ?? 3));
        $this->timeout     = max(5, (int)($opts['timeout'] ?? 30));
        $this->transport   = $opts['transport'] ?? [$this, 'curlTransport'];
        $this->sleeper     = $opts['sleeper'] ?? static function (float $s): void { usleep((int)round($s * 1e6)); };
        $this->onAuthError = $opts['on_auth_error'] ?? null;
    }

    public function get(string $path, array $params = []): array          { return $this->request('GET', $path, $params); }
    public function post(string $path, array $params = []): array         { return $this->request('POST', $path, $params); }
    public function delete(string $path, array $params = []): array       { return $this->request('DELETE', $path, $params); }
    /** $files: ['source' => '/abs/path.mp4'] — sent as multipart/form-data. */
    public function postMultipart(string $path, array $params, array $files): array
    {
        foreach ($files as $field => $file) $params[$field] = new CURLFile($file);
        return $this->request('POST', $path, $params, true);
    }
    /** Highest usage percentage Meta reported on the last response (0-100). */
    public function lastUsage(): int { return $this->lastUsage; }

    public static function graphUrl(string $path): string
    {
        return META_GRAPH_BASE . '/' . META_GRAPH_VERSION . '/' . ltrim($path, '/');
    }

    public static function appSecretProof(string $token, string $secret): string
    {
        return hash_hmac('sha256', $token, $secret);
    }

    /** "EAAGm0PX4ZCps…Zd3f" — enough to tell tokens apart in logs, useless to an attacker. */
    public static function maskToken(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        return strlen($s) <= 12 ? str_repeat('*', strlen($s)) : substr($s, 0, 6) . '…' . substr($s, -4);
    }

    /** Remove access tokens / proofs from any string (URLs, payload dumps, error text) before logging. */
    public static function scrub(string $s): string
    {
        $s = preg_replace('/(access_token|appsecret_proof|input_token)=[^&\s"\']+/i', '$1=***', $s);
        return preg_replace('/\bEAA[A-Za-z0-9]{20,}/', 'EAA***', (string)$s);
    }

    public function request(string $method, string $path, array $params = [], bool $multipart = false): array
    {
        $method = strtoupper($method);
        if ($this->token === '') {
            return $this->fail(0, ['code' => 190, 'message' => 'No access token.', 'auth' => true]);
        }
        $params['access_token'] = $this->token;
        if ($this->useProof) $params['appsecret_proof'] = self::appSecretProof($this->token, $this->appSecret);

        $url = self::graphUrl($path);
        $attempt = 0;
        while (true) {
            // Back off before calling when Meta told us we are close to the limit.
            if ($this->lastUsage >= META_USAGE_BACKOFF_PCT) {
                ($this->sleeper)(min(30.0, 2.0 + ($this->lastUsage - META_USAGE_BACKOFF_PCT) * 0.5));
            }
            $sendUrl = $url;
            $fields = $params;
            if ($method === 'GET' || $method === 'DELETE') {
                $sendUrl .= (strpos($url, '?') === false ? '?' : '&') . http_build_query(self::flatten($params));
                $fields = [];
            } elseif (!$multipart) {
                $fields = self::flatten($params);
            }

            [$status, $headers, $body] = ($this->transport)($method, $sendUrl, $fields, $multipart);
            $this->lastUsage = self::usageFromHeaders($headers);
            $data = json_decode((string)$body, true);
            if (!is_array($data)) $data = [];

            if ($status >= 200 && $status < 300 && !isset($data['error'])) {
                return ['ok' => true, 'status' => $status, 'data' => $data, 'usage' => $this->lastUsage, 'error' => null];
            }

            $err = self::parseError($status, $data, (string)$body);
            if ($err['auth']) {
                $this->log('auth error', $method, $path, $err);
                if ($this->onAuthError) ($this->onAuthError)($err);
                return $this->fail($status, $err);
            }
            if ($err['retryable'] && $attempt < $this->maxRetries) {
                $attempt++;
                $wait = min(20.0, (2 ** ($attempt - 1))) + mt_rand(0, 500) / 1000;
                $this->log("retry $attempt/{$this->maxRetries} in {$wait}s", $method, $path, $err);
                ($this->sleeper)($wait);
                continue;
            }
            $this->log('failed', $method, $path, $err);
            return $this->fail($status, $err);
        }
    }

    /** Meta's error object → our shape, with retry/auth flags and a plain-language message. */
    public static function parseError(int $status, array $data, string $raw = ''): array
    {
        $e = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = (int)($e['code'] ?? 0);
        $sub = (int)($e['error_subcode'] ?? 0);
        $msg = (string)($e['message'] ?? '');
        if ($msg === '' && $status === 0) $msg = 'Could not reach Meta.';
        if ($msg === '') $msg = 'Meta returned HTTP ' . $status . '.';
        $auth = $code === 190 || in_array($sub, [458, 459, 460, 463, 464, 467], true);
        $retryable = !$auth && ($status === 0 || $status >= 500 || in_array($code, self::RETRYABLE_CODES, true)
            || ($code >= 80000 && $code < 81000) || !empty($e['is_transient']));
        return [
            'code' => $code, 'subcode' => $sub, 'type' => (string)($e['type'] ?? ''),
            'message' => self::scrub($msg), 'user_message' => self::friendly($code, $sub, (string)($e['error_user_msg'] ?? '')),
            'fbtrace_id' => (string)($e['fbtrace_id'] ?? ''), 'retryable' => $retryable, 'auth' => $auth,
        ];
    }

    /** Short explanation for business owners (shown in the panel). */
    public static function friendly(int $code, int $subcode = 0, string $metaUserMsg = ''): string
    {
        if ($code === 190) {
            return $subcode === 463 ? 'Your Meta connection expired. Reconnect your Facebook account.'
                : 'Meta no longer accepts the saved access. Reconnect your Facebook account.';
        }
        if (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code < 81000)) return 'Meta is limiting requests right now. WAAMINI will try again shortly.';
        if (in_array($code, [1, 2], true)) return 'Meta had a temporary problem. WAAMINI will try again shortly.';
        if ($code === 10 || ($code >= 200 && $code < 300)) return 'This Meta account has not given WAAMINI the permission needed for this action.';
        if ($code === 100) return $metaUserMsg !== '' ? $metaUserMsg : 'Meta rejected one of the values sent. Check the details and try again.';
        if ($code === 368) return 'Meta has temporarily blocked this action on your account.';
        return $metaUserMsg !== '' ? $metaUserMsg : 'Meta could not complete the request.';
    }

    /** Highest percentage across X-App-Usage and X-Business-Use-Case-Usage. */
    public static function usageFromHeaders(array $headers): int
    {
        $max = 0;
        $h = array_change_key_case($headers, CASE_LOWER);
        foreach (['x-app-usage', 'x-ad-account-usage'] as $k) {
            $j = json_decode((string)($h[$k] ?? ''), true);
            if (is_array($j)) foreach ($j as $v) if (is_numeric($v)) $max = max($max, (int)$v);
        }
        $b = json_decode((string)($h['x-business-use-case-usage'] ?? ''), true);
        if (is_array($b)) {
            foreach ($b as $entries) foreach ((array)$entries as $e) {
                foreach (['call_count', 'total_cputime', 'total_time'] as $f) if (isset($e[$f])) $max = max($max, (int)$e[$f]);
            }
        }
        return min(100, $max);
    }

    /** Graph expects nested arrays (targeting, object_story_spec…) as JSON strings in form posts. */
    private static function flatten(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            elseif (is_bool($v)) $params[$k] = $v ? 'true' : 'false';
        }
        return $params;
    }

    private function fail(int $status, array $err): array
    {
        $err += ['code' => 0, 'subcode' => 0, 'type' => '', 'message' => '', 'fbtrace_id' => '', 'retryable' => false, 'auth' => false];
        if (empty($err['user_message'])) $err['user_message'] = self::friendly((int)$err['code'], (int)$err['subcode']);
        return ['ok' => false, 'status' => $status, 'data' => [], 'usage' => $this->lastUsage, 'error' => $err];
    }

    private function log(string $what, string $method, string $path, array $err): void
    {
        error_log(sprintf('[ADS meta] company=%d %s %s %s code=%s sub=%s fbtrace=%s token=%s msg=%s',
            $this->companyId, $what, $method, self::scrub($path), $err['code'] ?? '', $err['subcode'] ?? '',
            $err['fbtrace_id'] ?? '', self::maskToken($this->token), self::scrub((string)($err['message'] ?? ''))));
    }

    /** Default transport: cURL. Returns [status, headers(lowercase => value), body]. */
    public function curlTransport(string $method, string $url, array $fields, bool $multipart): array
    {
        $headers = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
                $p = strpos($line, ':');
                if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ];
        if ($method === 'POST') $opts[CURLOPT_POSTFIELDS] = $multipart ? $fields : http_build_query($fields);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [0, $headers, json_encode(['error' => ['message' => 'Network error: ' . $cerr, 'code' => 2, 'is_transient' => true]])];
        }
        return [$status, $headers, (string)$body];
    }
}
