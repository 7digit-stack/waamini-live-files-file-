<?php
/**
 * Public side of the Landing CMS.
 *
 * Every function returns CMS content when the Landing CMS has an active entry for that section and
 * falls back to the built-in content (includes/cms_defaults.php) when it does not, or when the
 * database is unavailable. So the website always renders and never shows an empty section.
 *
 * Storage:
 *   lp_hero(page='home')            hero text and buttons
 *   lp_page_sections(page='home')   hero_extra, trust_stats, feature_hub, faq, final_cta  (data_json)
 *   lp_testimonials / lp_pricing    testimonials and plans
 *   lp_settings                     site name, footer text, support email, social links, GA id, og image
 */

require_once __DIR__ . '/cms_defaults.php';

if (!function_exists('cms_db')) {

    function cms_db(): ?mysqli
    {
        global $conn;
        return (isset($conn) && $conn instanceof mysqli) ? $conn : null;
    }

    function cms_e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "**bold**" to <strong>, everything else escaped. */
    function cms_inline(string $text): string
    {
        $safe = cms_e($text);
        return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
    }

    /** One lp_page_sections block (decoded data_json) or null when missing/inactive. */
    function cms_block(string $key, string $page = 'home'): ?array
    {
        static $cache = [];
        $ck = $page . '|' . $key;
        if (array_key_exists($ck, $cache)) return $cache[$ck];
        $cache[$ck] = null;
        $db = cms_db();
        if (!$db) return null;
        try {
            $st = $db->prepare("SELECT data_json, title, content, is_active FROM lp_page_sections WHERE page = ? AND section_key = ? LIMIT 1");
            if ($st) {
                $st->bind_param('ss', $page, $key);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row && (int)$row['is_active'] === 1 && trim((string)$row['data_json']) !== '') {
                    $d = json_decode((string)$row['data_json'], true);
                    if (is_array($d) && $d) $cache[$ck] = $d;
                }
            }
        } catch (\Throwable $e) {
            error_log('[CMS] block ' . $key . ': ' . $e->getMessage());
        }
        return $cache[$ck];
    }

    /** Which home sections are driven by the CMS right now (for the CMS dashboard). */
    function cms_connected_keys(): array
    {
        $keys = [];
        foreach (['hero_extra', 'trust_stats', 'feature_hub', 'home_pricing', 'faq', 'final_cta'] as $k) { if (cms_block($k) !== null) $keys[] = $k; }
        return $keys;
    }

    function cms_setting(string $key, string $default = ''): string
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            $db = cms_db();
            if ($db) {
                try {
                    $r = $db->query("SELECT `key`, `value` FROM lp_settings");
                    while ($r && ($x = $r->fetch_assoc())) { $all[(string)$x['key']] = (string)$x['value']; }
                } catch (\Throwable $e) {}
            }
        }
        $v = trim((string)($all[$key] ?? ''));
        return $v !== '' ? $v : $default;
    }

    function cms_hero(): array
    {
        $d = cms_default_hero();
        $db = cms_db();
        if ($db) {
            try {
                $r = $db->query("SELECT announce_text, subtext, cta_primary_text, cta_primary_url, cta_sec_text, cta_sec_url, is_active FROM lp_hero WHERE page = 'home' LIMIT 1");
                $row = $r ? $r->fetch_assoc() : null;
                if ($row && (int)$row['is_active'] === 1) {
                    if (trim((string)$row['announce_text']) !== '')   $d['announce'] = trim((string)$row['announce_text']);
                    if (trim((string)$row['subtext']) !== '')         $d['desc'] = trim((string)$row['subtext']);
                    if (trim((string)$row['cta_primary_text']) !== '') $d['cta_primary_text'] = trim((string)$row['cta_primary_text']);
                    if (trim((string)$row['cta_primary_url']) !== '')  $d['cta_primary_url'] = trim((string)$row['cta_primary_url']);
                    if (trim((string)$row['cta_sec_text']) !== '')     $d['cta_sec_text'] = trim((string)$row['cta_sec_text']);
                    if (trim((string)$row['cta_sec_url']) !== '')      $d['cta_sec_url'] = trim((string)$row['cta_sec_url']);
                }
            } catch (\Throwable $e) {}
        }
        $x = cms_block('hero_extra');
        if ($x) {
            foreach (['typed', 'bullets', 'meta'] as $k) {
                if (!empty($x[$k]) && is_array($x[$k])) {
                    $list = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $x[$k]), static fn($v) => $v !== ''));
                    if ($list) $d[$k] = $list;
                }
            }
        }
        return $d;
    }

    function cms_stats(): array
    {
        $b = cms_block('trust_stats');
        if ($b && isset($b['items']) && is_array($b['items'])) {
            $out = [];
            foreach ($b['items'] as $s) {
                if (!is_array($s) || trim((string)($s['label'] ?? '')) === '') continue;
                $out[] = ['prefix' => (string)($s['prefix'] ?? ''), 'value' => (float)($s['value'] ?? 0), 'suffix' => (string)($s['suffix'] ?? ''), 'label' => (string)$s['label']];
            }
            if ($out) return $out;
        }
        return cms_default_stats();
    }

    function cms_hub(): array
    {
        $b = cms_block('feature_hub');
        if ($b && isset($b['items']) && is_array($b['items'])) {
            $out = [];
            foreach ($b['items'] as $i) {
                if (!is_array($i) || trim((string)($i['title'] ?? '')) === '') continue;
                $out[] = [
                    (($i['side'] ?? 'L') === 'R') ? 'R' : 'L',
                    (string)($i['icon'] ?? 'link'),
                    (string)$i['title'],
                    (string)($i['tag'] ?? ''),
                    (string)($i['desc'] ?? ''),
                    (string)($i['in'] ?? ''),
                    (string)($i['out'] ?? ''),
                    [(string)($i['chat_in'] ?? ''), (string)($i['chat_out'] ?? '')],
                    (string)($i['chip'] ?? ''),
                ];
            }
            if (count($out) >= 2) return $out;
        }
        return cms_default_hub();
    }

    function cms_faqs(): array
    {
        $b = cms_block('faq');
        if ($b && isset($b['items']) && is_array($b['items'])) {
            $out = [];
            foreach ($b['items'] as $i) {
                if (is_array($i) && trim((string)($i['q'] ?? '')) !== '' && trim((string)($i['a'] ?? '')) !== '') $out[] = [(string)$i['q'], (string)$i['a']];
            }
            if ($out) return $out;
        }
        return cms_default_faqs();
    }

    function cms_cta(): array
    {
        $d = cms_default_cta();
        $b = cms_block('final_cta');
        if ($b) {
            foreach (['heading', 'highlight', 'text', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'] as $k) {
                if (isset($b[$k]) && trim((string)$b[$k]) !== '') $d[$k] = trim((string)$b[$k]);
            }
            if (!empty($b['meta']) && is_array($b['meta'])) {
                $m = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $b['meta'])));
                if ($m) $d['meta'] = $m;
            }
        }
        return $d;
    }

    /** Testimonials in the shape the home page card expects: [initials, name, 'Job, City', avatar class, stars, text] */
    function cms_testimonials(): array
    {
        $db = cms_db();
        if ($db) {
            try {
                $r = $db->query("SELECT name, company, role, content, rating FROM lp_testimonials WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 20");
                $rows = [];
                while ($r && ($x = $r->fetch_assoc())) { $rows[] = $x; }
                if (count($rows) >= 2) {
                    $out = [];
                    $av = ['av-a', 'av-b', 'av-c', 'av-d', 'av-e'];
                    foreach ($rows as $i => $x) {
                        $name = trim((string)$x['name']);
                        // initials from the first and last word, skipping titles such as "Dr."
                        $clean = trim((string)preg_replace('/^(dr|mr|mrs|ms|prof)\.?\s+/i', '', $name));
                        $parts = array_map(static fn($p) => (string)preg_replace('/[^\p{L}]/u', '', $p), preg_split('/\s+/', $clean) ?: []);
                        $init = '';
                        foreach (array_slice(array_filter($parts), 0, 2) as $p) { $init .= mb_strtoupper(mb_substr($p, 0, 1)); }
                        $job = trim((string)$x['role']);
                        $place = trim((string)$x['company']);
                        $out[] = [$init !== '' ? $init : '?', $name, $place !== '' ? ($job . ', ' . $place) : $job, $av[$i % 5], max(1, min(5, (int)$x['rating'])), (string)$x['content']];
                    }
                    return $out;
                }
            } catch (\Throwable $e) {}
        }
        return cms_default_testimonials();
    }

    /** Plans for the home pricing section (CMS block 'home_pricing', else the built-in two). */
    function cms_pricing(): array
    {
        $b = cms_block('home_pricing');
        if ($b && isset($b['items']) && is_array($b['items'])) {
            $out = [];
            foreach ($b['items'] as $p) {
                if (!is_array($p) || trim((string)($p['name'] ?? '')) === '') continue;
                $feat = $p['features'] ?? [];
                if (is_string($feat)) $feat = preg_split('/\R/', $feat);
                $feat = array_values(array_filter(array_map(static fn($v) => trim((string)$v), (array)$feat), static fn($v) => $v !== ''));
                $out[] = [
                    'name' => (string)$p['name'], 'tagline' => (string)($p['tagline'] ?? ''),
                    'price_monthly' => (float)($p['price'] ?? 0), 'currency' => (string)($p['currency'] ?? 'INR'),
                    'is_popular' => !empty($p['popular']) ? 1 : 0,
                    'cta_text' => (string)($p['cta_text'] ?? ''), 'cta_url' => (string)($p['cta_url'] ?? ''),
                    'features' => $feat,
                ];
            }
            if ($out) return $out;
        }
        return cms_default_pricing();
    }
}
