<?php
/** Provider adapters for WAAMINI Bot chat plus the existing OpenAI embedding client. */
class WB_OpenAI
{
    /** Remove anything that looks like a key from text before it is stored or shown. */
    public static function scrub(string $s): string
    {
        return mb_substr((string)preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-***', $s), 0, 300);
    }

    /** @return array{ok:bool,status:int,data:?array,error:string} */
    public static function request(mysqli $c, string $path, array $body, int $timeout = 60): array
    {
        $key = WB_Store::getKey($c);
        if ($key === null) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'No OpenAI key is configured.'];
        }
        $ch = curl_init('https://api.openai.com/v1/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        unset($key);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => self::scrub('Network error: ' . $cerr)];
        }
        $data = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) ? (string)($data['error']['message'] ?? ('HTTP ' . $status)) : ('HTTP ' . $status);
            return ['ok' => false, 'status' => $status, 'data' => null, 'error' => self::scrub($msg)];
        }
        return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : [], 'error' => ''];
    }

    /** Chat completion with one retry on rate limit / server error. */
    public static function chat(mysqli $c, array $messages, ?array $tools = null, array $opts = []): array
    {
        $res = self::chatOnce($c, $messages, $tools, $opts);
        // Reasoning models spend part of the token budget thinking and can return an empty answer when it runs out.
        if ($res['ok'] && trim((string)($res['data']['choices'][0]['message']['content'] ?? '')) === '' && empty($res['data']['choices'][0]['message']['tool_calls'])) {
            $big = $opts; $big['max_tokens'] = min(4096, max(1024, (int)($opts['max_tokens'] ?? 1024)) * 3);
            $again = self::chatOnce($c, $messages, $tools, $big);
            if ($again['ok']) { $res = $again; }
        }
        // Some models (free tiers, small models) reject tool calling or JSON mode with a 4xx.
        // Retry once without them so the bot still answers; the engine already retrieves knowledge up front.
        if (!$res['ok'] && in_array($res['status'], [400, 404, 422], true) && ($tools || !empty($opts['json']))
            && preg_match('/tool|function|json|response_format|schema|endpoints? found|not support/i', (string)$res['error'])) {
            $plain = $opts; unset($plain['json']);
            $retry = self::chatOnce($c, $messages, null, $plain);
            if ($retry['ok']) { $retry['data']['_degraded'] = true; return $retry; }
        }
        return $res;
    }

    /**
     * Chat completion against a specific provider/model, ignoring the saved active provider.
     * Used by fallback chains (e.g. CRM lead analysis) so a dead primary provider does not
     * stop the call when another provider key is available.
     */
    public static function chatAs(mysqli $c, string $provider, string $model, array $messages, ?array $tools = null, array $opts = []): array
    {
        $opts['provider'] = $provider;
        if (trim($model) !== '') { $opts['model'] = $model; }
        return self::chat($c, $messages, $tools, $opts);
    }

    private static function chatOnce(mysqli $c, array $messages, ?array $tools = null, array $opts = []): array
    {
        $s = WB_Store::settings($c);
        $provider = (string)($opts['provider'] ?? $s['chat_provider'] ?? 'openai');
        if ($provider === 'claude') { return self::claudeChat($c, $s, $messages, $tools, $opts); }
        $model = trim((string)($opts['model'] ?? '')) !== '' ? (string)$opts['model'] : (string)($s['chat_model'] ?? '');
        $body = ['model' => $model !== '' ? $model : 'gpt-4o-mini', 'messages' => $messages];
        // Always cap the answer length. Without it OpenRouter reserves the model's maximum (for example 32768 tokens)
        // and rejects the call when the account balance is small. OpenAI's newer models need max_completion_tokens.
        $cap = max(16, (int)($opts['max_tokens'] ?? 1024));
        $body[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $cap;
        if ($provider === 'openrouter') { $body['reasoning'] = ['effort' => 'low']; }   // ignored by models without reasoning
        if ($tools) { $body['tools'] = $tools; $body['tool_choice'] = 'auto'; }
        if (!empty($opts['json'])) { $body['response_format'] = ['type' => 'json_object']; }
        $res = self::providerRequest($c, $provider, 'chat/completions', $body, (int)($opts['timeout'] ?? 60));
        if (!$res['ok'] && ($res['status'] === 429 || $res['status'] >= 500 || $res['status'] === 0)) {
            sleep(1);
            $res = self::providerRequest($c, $provider, 'chat/completions', $body, (int)($opts['timeout'] ?? 60));
        }
        return $res;
    }

    private static function providerRequest(mysqli $c, string $provider, string $path, array $body, int $timeout = 60): array
    {
        $providers = WB_Store::providers(); $cfg = $providers[$provider] ?? null;
        $key = WB_Store::getProviderKey($c, $provider);
        if (!$cfg || $key === null) { return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'No API key is configured for the selected provider.']; }
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        if ($provider === 'openrouter') { $headers[] = 'HTTP-Referer: ' . (string)(getenv('APP_URL') ?: 'https://waamini.com'); $headers[] = 'X-Title: WAAMINI Bot'; }
        $ch = curl_init(rtrim($cfg['base'], '/') . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch); unset($key);
        if ($raw === false) { return ['ok'=>false,'status'=>0,'data'=>null,'error'=>self::scrub('Network error: '.$cerr)]; }
        $data = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = self::errMsg($data, $status);
            return ['ok'=>false,'status'=>$status,'data'=>null,'error'=>self::scrub((string)$msg)];
        }
        return ['ok'=>true,'status'=>$status,'data'=>is_array($data)?$data:[],'error'=>''];
    }

    /** Readable provider error: OpenRouter hides the real reason in error.metadata.raw (for example upstream rate limits). */
    private static function errMsg($data, int $status): string
    {
        if (!is_array($data)) { return 'HTTP ' . $status; }
        $e = $data['error'] ?? null;
        $msg = is_array($e) ? (string)($e['message'] ?? '') : (is_string($e) ? $e : (string)($data['message'] ?? ''));
        if (is_array($e) && isset($e['metadata'])) {
            $raw = $e['metadata']['raw'] ?? ($e['metadata']['provider_name'] ?? '');
            if (is_array($raw)) { $raw = json_encode($raw, JSON_UNESCAPED_UNICODE); }
            $raw = trim((string)$raw);
            if ($raw !== '') { $msg .= ' (' . mb_substr($raw, 0, 220) . ')'; }
        }
        $msg = trim($msg);
        return $msg !== '' ? $msg : 'HTTP ' . $status;
    }

    private static function claudeChat(mysqli $c, array $s, array $messages, ?array $tools, array $opts): array
    {
        $system = ''; $turns = [];
        foreach ($messages as $m) {
            $role = (string)($m['role'] ?? 'user'); $content = $m['content'] ?? '';
            if ($role === 'system') { $system .= ($system === '' ? '' : "\n\n") . (string)$content; continue; }
            if ($role === 'tool') {
                $turns[] = ['role'=>'user','content'=>[['type'=>'tool_result','tool_use_id'=>(string)($m['tool_call_id'] ?? ''),'content'=>(string)$content]]]; continue;
            }
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $blocks = []; if (trim((string)$content) !== '') { $blocks[]=['type'=>'text','text'=>(string)$content]; }
                foreach ($m['tool_calls'] as $tc) { $f=$tc['function'] ?? []; $args=json_decode((string)($f['arguments'] ?? '{}'),true); $blocks[]=['type'=>'tool_use','id'=>(string)($tc['id'] ?? uniqid('tool_',true)),'name'=>(string)($f['name'] ?? ''),'input'=>is_array($args)?$args:[]]; }
                $turns[]=['role'=>'assistant','content'=>$blocks]; continue;
            }
            $turns[]=['role'=>$role === 'assistant'?'assistant':'user','content'=>(string)$content];
        }
        $body=['model'=> (trim((string)($opts['model'] ?? '')) !== '' ? (string)$opts['model'] : $s['chat_model']),'max_tokens'=>1024,'messages'=>$turns];
        if ($system !== '') { $body['system']=$system; }
        if ($tools) {
            $body['tools']=array_map(static function($t){$f=$t['function']??[];return ['name'=>(string)($f['name']??''),'description'=>(string)($f['description']??''),'input_schema'=>$f['parameters']??['type'=>'object','properties'=>[]]];},$tools);
        }
        if (!empty($opts['json'])) { $body['system']=trim($system."\n\nReturn only a valid JSON object, without markdown fences."); }
        $key=WB_Store::getProviderKey($c,'claude');
        if ($key===null) { return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'No Anthropic API key is configured.']; }
        $ch=curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>(int)($opts['timeout']??60),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>['content-type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$cerr=curl_error($ch);curl_close($ch);unset($key);
        if($raw===false){return ['ok'=>false,'status'=>0,'data'=>null,'error'=>self::scrub('Network error: '.$cerr)];}
        $d=json_decode((string)$raw,true);
        if($status<200||$status>=300){$msg=(string)($d['error']['message']??('HTTP '.$status));return ['ok'=>false,'status'=>$status,'data'=>null,'error'=>self::scrub($msg)];}
        $text='';$calls=[];foreach(($d['content']??[]) as $b){if(($b['type']??'')==='text'){$text.=(string)($b['text']??'');}elseif(($b['type']??'')==='tool_use'){$calls[]=['id'=>$b['id'],'type'=>'function','function'=>['name'=>$b['name'],'arguments'=>json_encode($b['input']??[])]];}}
        return ['ok'=>true,'status'=>$status,'data'=>['choices'=>[['message'=>['role'=>'assistant','content'=>$text,'tool_calls'=>$calls]]],'usage'=>['prompt_tokens'=>(int)($d['usage']['input_tokens']??0),'completion_tokens'=>(int)($d['usage']['output_tokens']??0)]],'error'=>''];
    }

    public static function listProviderModels(mysqli $c, string $provider): array
    {
        $cfg=WB_Store::providers()[$provider]??null; if(!$cfg){return [false,[],'Unsupported provider.'];}
        $key=WB_Store::getProviderKey($c,$provider);
        if($provider!=='openrouter' && $key===null){return [false,[],'Save an API key for this provider first.'];}
        $url=rtrim($cfg['base'],'/').$cfg['models']; $headers=[];
        if($key!==null){$headers[]=$provider==='claude'?'x-api-key: '.$key:'Authorization: Bearer '.$key;}
        if($provider==='claude'){$headers[]='anthropic-version: 2023-06-01';}
        if($provider==='gemini'){$headers[]='x-goog-api-key: '.(string)$key;}   // Gemini's OpenAI-compatible endpoint takes the key as a Bearer header; never put it in the URL
        if($provider==='openrouter'){$headers[]='HTTP-Referer: '.(string)(getenv('APP_URL')?:'https://waamini.com');}
        $models=[]; $cursor='';
        for($page=0;$page<20;$page++){
            $pageUrl=$url;
            if($provider==='claude'){$pageUrl.=(str_contains($pageUrl,'?')?'&':'?').'limit=1000'.($cursor!==''?'&after_id='.rawurlencode($cursor):'');}
            elseif($provider==='openai'){$pageUrl.=(str_contains($pageUrl,'?')?'&':'?').'limit=1000'.($cursor!==''?'&after='.rawurlencode($cursor):'');}
            $ch=curl_init($pageUrl);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            $d=json_decode((string)$raw,true);if($raw===false||$status<200||$status>=300||!is_array($d)){unset($key);return [false,[],self::scrub((string)($d['error']['message']??'Could not load model catalog (HTTP '.$status.').'))];}
            $rows=$d['data']??$d['models']??[];
            foreach($rows as $row){$id=(string)($row['id']??$row['name']??'');$id=preg_replace('#^models/#','',$id);if($id===''||preg_match('/(embed|moderation|whisper|transcri|tts|audio|image|video|dall-e|rerank)/i',$id)){continue;}
                if($provider==='gemini' && isset($row['supportedGenerationMethods']) && !in_array('generateContent',(array)$row['supportedGenerationMethods'],true)){continue;}
                if($provider==='openrouter' && !in_array('text',(array)($row['architecture']['output_modalities']??['text']),true)){continue;}
                $label=(string)($row['display_name']??$row['displayName']??$row['name']??$id);$models[$id]=$label;
            }
            if($provider==='gemini'){$cursor=(string)($d['nextPageToken']??'');if($cursor==='')break;}
            elseif(in_array($provider,['openai','claude'],true)){$hasMore=(bool)($d['has_more']??false);$cursor=(string)($d['last_id']??($rows ? (end($rows)['id']??'') : ''));if(!$hasMore||$cursor==='')break;}
            else{break;}
        }
        unset($key);
        asort($models,SORT_NATURAL|SORT_FLAG_CASE);return [true,$models,''];
    }

    public static string $lastEmbedError = '';
    public static int $lastEmbedStatus = 0;
    /** Tests only: pretend no provider can make embeddings. */
    public static bool $forceNoEmbeddings = false;
    /** Set after a permanent embeddings refusal so every later question in this process skips the failing call. */
    private static bool $embedBroken = false;

    private const EMBED_DEFAULTS = ['openai' => 'text-embedding-3-small', 'gemini' => 'text-embedding-004', 'openrouter' => 'openai/text-embedding-3-small'];

    /**
     * Which provider and model make embeddings. The chat provider when it supports them (OpenAI, Gemini, OpenRouter),
     * otherwise OpenAI if a key is saved, otherwise none: the bot then uses keyword search only and still works.
     * @return array{0:string,1:string}|null
     */
    public static function embeddingTarget(mysqli $c): ?array
    {
        if (self::$forceNoEmbeddings) { return null; }
        $s = WB_Store::settings($c);
        $typed = trim((string)($s['embedding_model'] ?? ''));
        $chatProv = (string)($s['chat_provider'] ?? 'openai');
        $embedMap = self::EMBED_DEFAULTS + (WB_Store::$providerOverrides ? array_filter(array_map(static fn($p) => $p['embed'] ?? null, WB_Store::$providerOverrides)) : []);
        foreach ([$chatProv, 'openai'] as $prov) {
            if (!isset($embedMap[$prov]) || !WB_Store::providerKeyStatus($c, $prov)['has_key']) { continue; }
            $model = $embedMap[$prov];
            // a model typed in admin applies to the provider it was written for (OpenAI-style names)
            if ($prov === 'openai' && $typed !== '') { $model = $typed; }
            return [$prov, $model];
        }
        return null;
    }

    /** True when knowledge can be embedded for semantic search. */
    public static function embeddingsAvailable(mysqli $c): bool { return self::embeddingTarget($c) !== null; }

    private static function embedRequest(mysqli $c, string $provider, string $model, array $texts): array
    {
        return self::providerRequest($c, $provider, 'embeddings', ['model' => $model, 'input' => $texts], 60);
    }

    /** @return array|null list of float vectors in the same order as $texts, or null on failure */
    public static function embed(mysqli $c, array $texts): ?array
    {
        if (!$texts) { return []; }
        $target = self::embeddingTarget($c);
        if ($target === null || self::$embedBroken) { return null; }   // no provider can do embeddings (or it refused): keyword search only
        [$prov, $model] = $target;
        $texts = array_map(static fn($t) => mb_substr((string)$t, 0, 6000), array_values($texts));
        $res = self::embedRequest($c, $prov, $model, $texts);
        if (!$res['ok'] && ($res['status'] === 429 || $res['status'] >= 500 || $res['status'] === 0)) {
            sleep(1);
            $res = self::embedRequest($c, $prov, $model, $texts);
        }
        if (!$res['ok'] || empty($res['data']['data'])) { self::$lastEmbedError = (string)$res['error']; self::$lastEmbedStatus = (int)$res['status']; if ($res['status'] >= 400 && $res['status'] < 500 && $res['status'] !== 429) { self::$embedBroken = true; } return null; }
        $out = [];
        foreach ($res['data']['data'] as $row) { $out[(int)$row['index']] = $row['embedding']; }
        ksort($out);
        return array_values($out);
    }

    public static function pack(array $v): string { return pack('g*', ...array_map('floatval', $v)); }

    public static function cosine(string $a, string $b): float
    {
        if ($a === '' || $b === '' || strlen($a) !== strlen($b)) { return 0.0; }
        $x = unpack('g*', $a); $y = unpack('g*', $b);
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        foreach ($x as $i => $xv) { $yv = $y[$i]; $dot += $xv * $yv; $na += $xv * $xv; $nb += $yv * $yv; }
        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /** Admin "Test connection": one tiny chat call and one tiny embedding call. */
    public static function test(mysqli $c): array
    {
        $chat = self::chat($c, [['role' => 'user', 'content' => 'Reply with the single word OK.']], null, ['timeout'=>30, 'max_tokens'=>256]);
        if (!$chat['ok']) { return [false, 'Chat model: ' . $chat['error']]; }
        if (trim((string)($chat['data']['choices'][0]['message']['content'] ?? '')) === '') { return [false, 'Chat model returned an empty answer. It may be a reasoning-only or content-filter model. Choose a general chat model.']; }
        // Embeddings are optional: without them the bot searches knowledge by keywords instead.
        $target = self::embeddingTarget($c);
        if ($target !== null) {
            $emb = self::embedRequest($c, $target[0], $target[1], ['test']);
            if (!$emb['ok']) { return [true, 'Chat works. Embeddings (' . $target[0] . ' / ' . $target[1] . ') failed: ' . $emb['error'] . ' Knowledge will use keyword search.']; }
            return [true, ''];
        }
        return [true, 'Chat works. This provider has no embeddings, so knowledge uses keyword search. Add an OpenAI key for semantic search.'];
    }
}
