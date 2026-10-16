<?php
/**
 * Pricing page plans (pricing.php) <-> Landing CMS "Pricing Plans" (lp_pricing).
 *
 * lp_pricing columns used: name, tagline, price_monthly (shown price), price_yearly (price for 12 months),
 * currency, is_popular, features_json, cta_text, cta_url, sort_order, is_active, extra_json.
 * extra_json: { list_price (struck-through monthly price), ribbon_monthly, ribbon_yearly, cta_icon }
 * A feature line may start with an icon key: "chat|WhatsApp API Access". No key = a tick.
 * If the table has no active plans the page shows the built-in plans below, so it never renders empty.
 */

function cms_price_icons(): array
{
    $a = 'width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"';
    return [
        'check'     => "<svg $a stroke-width=\"2.5\"><polyline points=\"20 6 9 17 4 12\"/></svg>",
        'chat'      => "<svg $a stroke-width=\"2.5\"><path d=\"M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z\"/></svg>",
        'broadcast' => "<svg $a stroke-width=\"2.5\"><polygon points=\"11 5 6 9 2 9 2 15 6 15 11 19 11 5\"/><path d=\"M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07\"/></svg>",
        'contacts'  => "<svg $a stroke-width=\"2.5\"><path d=\"M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M23 21v-2a4 4 0 0 0-3-3.87\"/><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"/></svg>",
        'bot'       => "<svg $a stroke-width=\"2\"><rect x=\"3\" y=\"11\" width=\"18\" height=\"10\" rx=\"2\"/><circle cx=\"12\" cy=\"5\" r=\"2\"/><path d=\"M12 7v4\"/><line x1=\"8\" y1=\"16\" x2=\"8\" y2=\"16\"/><line x1=\"16\" y1=\"16\" x2=\"16\" y2=\"16\"/></svg>",
        'bolt'      => "<svg $a stroke-width=\"2.5\"><polygon points=\"13 2 3 14 12 14 11 22 21 10 12 10 13 2\"/></svg>",
        'link'      => "<svg $a stroke-width=\"2.5\"><path d=\"M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71\"/><path d=\"M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71\"/></svg>",
        'user'      => "<svg $a stroke-width=\"2.5\"><path d=\"M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2\"/><circle cx=\"12\" cy=\"7\" r=\"4\"/></svg>",
        'star'      => "<svg width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"currentColor\" stroke=\"currentColor\" stroke-width=\"1\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"/></svg>",
        'speed'     => "<svg $a stroke-width=\"2.5\"><path d=\"M12 2s8 4 8 10c0 5-8 10-8 10s-8-5-8-10c0-6 8-10 8-10z\"/><circle cx=\"12\" cy=\"12\" r=\"3\"/></svg>",
        'plus'      => "<svg $a stroke-width=\"3\"><line x1=\"12\" y1=\"5\" x2=\"12\" y2=\"19\"/><line x1=\"5\" y1=\"12\" x2=\"19\" y2=\"12\"/></svg>",
        'fire'      => "<svg $a stroke-width=\"2\"><path d=\"M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.125.25-2.25 1-3 1 2 2.5 3.5 3.5 3.5z\"/></svg>",
    ];
}

/** "chat|Text" -> ['chat', 'Text']; plain text -> ['check', 'Text'] */
function cms_price_feature(string $line): array
{
    $icons = cms_price_icons();
    if (preg_match('/^([a-z]+)\|(.+)$/s', trim($line), $m) && isset($icons[$m[1]])) return [$m[1], trim($m[2])];
    return ['check', trim($line)];
}

function cms_price_default_plans(): array
{
    $common = static fn(string $speed, string $bc, string $ct, string $flows, string $team, string $spd) => [
        'chat|WhatsApp API Access', "broadcast|$bc", "contacts|$ct", 'bot|AI Chatbot Included', "bolt|$flows",
        'link|API & Webhook Access', "user|$team", 'star|Priority Support', "speed|$spd", 'plus|Message Add-Ons Pay-as-you-go',
    ];
    return [
        ['name' => 'Starter', 'tagline' => 'A great start for growing businesses', 'price_monthly' => 999, 'price_yearly' => 9490, 'currency' => 'INR', 'is_popular' => 0,
         'features' => $common('', 'Broadcasts — 10/month', '1,000 Contacts/Campaign', '5 Automation Flows', '2 Team Members', 'Standard Message Speed'),
         'cta_text' => 'Start Your Growth Journey', 'cta_url' => 'register.php',
         'extra' => ['list_price' => 1499, 'ribbon_monthly' => '15% OFF', 'ribbon_yearly' => '20% OFF', 'cta_icon' => 'speed']],
        ['name' => 'Growth', 'tagline' => 'More tools, more reach, more growth', 'price_monthly' => 1499, 'price_yearly' => 14390, 'currency' => 'INR', 'is_popular' => 1,
         'features' => $common('', 'Broadcasts — 20/month', '5,000 Contacts/Campaign', '15 Automation Flows', '5 Team Members', 'High Priority Message Speed'),
         'cta_text' => 'Scale Your Marketing', 'cta_url' => 'register.php',
         'extra' => ['list_price' => 1999, 'ribbon_monthly' => '17% OFF', 'ribbon_yearly' => '25% OFF', 'cta_icon' => 'fire']],
        ['name' => 'Enterprise', 'tagline' => 'Enterprise-level automation & AI power', 'price_monthly' => 1999, 'price_yearly' => 18990, 'currency' => 'INR', 'is_popular' => 0,
         'features' => $common('', 'Unlimited Broadcasts', 'Unlimited Contacts', 'Unlimited Automation Flows', 'Unlimited Team Members', 'Highest Priority + 1200 msgs/min'),
         'cta_text' => 'Unlock Full AI Power', 'cta_url' => 'register.php',
         'extra' => ['list_price' => 2499, 'ribbon_monthly' => '20% OFF', 'ribbon_yearly' => '30% OFF', 'cta_icon' => 'bolt']],
    ];
}

/** Add the extra_json column once (self-healing, like the rest of the app). */
function cms_price_schema(mysqli $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $r = $db->query("SHOW COLUMNS FROM lp_pricing LIKE 'extra_json'");
        if ($r && $r->num_rows === 0) $db->query("ALTER TABLE lp_pricing ADD COLUMN extra_json LONGTEXT NULL");
    } catch (\Throwable $e) { error_log('[CMS] pricing schema: ' . $e->getMessage()); }
}

/** Plans for the public pricing page: active CMS plans, else the built-in ones. */
function cms_price_plans(?mysqli $db): array
{
    if ($db) {
        try {
            cms_price_schema($db);
            $r = $db->query("SELECT * FROM lp_pricing WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
            $out = [];
            while ($r && ($x = $r->fetch_assoc())) {
                $x['features'] = array_values(array_filter(array_map('strval', json_decode((string)($x['features_json'] ?? '[]'), true) ?: [])));
                $x['extra'] = json_decode((string)($x['extra_json'] ?? ''), true) ?: [];
                $out[] = $x;
            }
            if ($out) return $out;
        } catch (\Throwable $e) { error_log('[CMS] pricing: ' . $e->getMessage()); }
    }
    return cms_price_default_plans();
}

/** Put the built-in plans into lp_pricing (replace = wipe the table first). Returns number of plans written. */
function cms_price_seed(mysqli $db, bool $replace): int
{
    cms_price_schema($db);
    $n = (int)($db->query("SELECT COUNT(*) c FROM lp_pricing")->fetch_assoc()['c'] ?? 0);
    if ($n > 0 && !$replace) return 0;
    if ($replace) $db->query("DELETE FROM lp_pricing");
    $st = $db->prepare("INSERT INTO lp_pricing (name,tagline,price_monthly,price_yearly,currency,features_json,cta_text,cta_url,sort_order,is_popular,is_active,extra_json) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)");
    if (!$st) return 0;
    foreach (cms_price_default_plans() as $i => $p) {
        $pm = (string)$p['price_monthly']; $py = (string)$p['price_yearly'];
        $f = json_encode($p['features'], JSON_UNESCAPED_UNICODE); $ex = json_encode($p['extra'], JSON_UNESCAPED_UNICODE);
        $sort = $i + 1; $pop = (int)$p['is_popular'];
        $st->bind_param('ssssssssiis', $p['name'], $p['tagline'], $pm, $py, $p['currency'], $f, $p['cta_text'], $p['cta_url'], $sort, $pop, $ex);
        $st->execute();
    }
    $st->close();
    return count(cms_price_default_plans());
}
