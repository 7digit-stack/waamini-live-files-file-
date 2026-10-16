<?php
/**
 * Landing CMS: put the CURRENT landing page content into the CMS so the editors start with exactly what
 * visitors see today. Used by the "Sync from landing page" button and by the one-off installer.
 *
 * $overwrite = false: only create what is missing (safe to run any time).
 * $overwrite = true : replace the CMS content of the home page sections with the built-in landing content.
 */

require_once __DIR__ . '/../../includes/cms_defaults.php';

/** Public pages are cached for about a minute; clear it so a CMS save shows up at once. */
function la_purge_page_cache(): int
{
    $dir = dirname(__DIR__, 2) . '/storage/page-cache';
    $n = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.html') ?: [] as $f) { if (@unlink($f)) $n++; }
    }
    return $n;
}

/** Create or replace one home-page block. */
function la_block_save(mysqli $conn, string $key, string $title, array $data, int $active = 1, int $sort = 0): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $conn->prepare("INSERT INTO lp_page_sections (page, section_key, title, data_json, is_active, sort_order) VALUES ('home', ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE title = VALUES(title), data_json = VALUES(data_json), is_active = VALUES(is_active), sort_order = VALUES(sort_order)");
    if (!$st) return false;
    $st->bind_param('sssii', $key, $title, $json, $active, $sort);
    $ok = $st->execute();
    $st->close();
    return $ok;
}

function la_block_exists(mysqli $conn, string $key): bool
{
    $st = $conn->prepare("SELECT 1 FROM lp_page_sections WHERE page = 'home' AND section_key = ? AND data_json IS NOT NULL AND data_json <> '' LIMIT 1");
    if (!$st) return false;
    $st->bind_param('s', $key);
    $st->execute();
    $has = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $has;
}

/** The home-page content as CMS blocks. [key => [title, data]] */
function la_site_blocks(): array
{
    $hero = cms_default_hero();
    $hub = [];
    foreach (cms_default_hub() as $h) {
        $hub[] = ['side' => $h[0], 'icon' => $h[1], 'title' => $h[2], 'tag' => $h[3], 'desc' => $h[4], 'in' => $h[5], 'out' => $h[6],
                  'chat_in' => $h[7][0] ?? '', 'chat_out' => $h[7][1] ?? '', 'chip' => $h[8] ?? ''];
    }
    $faq = array_map(static fn($f) => ['q' => $f[0], 'a' => $f[1]], cms_default_faqs());
    $plans = array_map(static fn($p) => [
        'name' => $p['name'], 'tagline' => $p['tagline'], 'price' => $p['price_monthly'], 'currency' => $p['currency'], 'popular' => (int)$p['is_popular'],
        'cta_text' => $p['cta_text'], 'cta_url' => $p['cta_url'], 'features' => $p['features'],
    ], cms_default_pricing());
    return [
        'hero_extra'   => ['Hero: typed words, bullets, small print', ['typed' => $hero['typed'], 'bullets' => $hero['bullets'], 'meta' => $hero['meta']]],
        'trust_stats'  => ['Trust strip numbers', ['items' => cms_default_stats()]],
        'feature_hub'  => ['Platform features hub', ['items' => $hub]],
        'home_pricing' => ['Home pricing cards', ['items' => $plans]],
        'faq'          => ['FAQ', ['items' => $faq]],
        'final_cta'    => ['Final call to action', cms_default_cta()],
    ];
}

/** @return string[] human readable log */
function la_sync_from_site(mysqli $conn, bool $overwrite = false): array
{
    $log = [];

    // 1) hero (lp_hero, page = home)
    $h = cms_default_hero();
    $stats = array_map(static fn($s) => ['value' => $s['prefix'] . $s['value'] . $s['suffix'], 'label' => $s['label']], cms_default_stats());
    $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
    $r = $conn->query("SELECT id FROM lp_hero WHERE page = 'home' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        $st = $conn->prepare("INSERT INTO lp_hero (page, announce_text, headline, highlight_word, subtext, cta_primary_text, cta_primary_url, cta_sec_text, cta_sec_url, stats_json, is_active)
                              VALUES ('home', ?, 'Waamini', 'WhatsApp', ?, ?, ?, ?, ?, ?, 1)");
        if ($st) { $st->bind_param('sssssss', $h['announce'], $h['desc'], $h['cta_primary_text'], $h['cta_primary_url'], $h['cta_sec_text'], $h['cta_sec_url'], $statsJson); $st->execute(); $st->close(); }
        $log[] = 'Hero: created from the landing page';
    } elseif ($overwrite) {
        $st = $conn->prepare("UPDATE lp_hero SET announce_text=?, subtext=?, cta_primary_text=?, cta_primary_url=?, cta_sec_text=?, cta_sec_url=?, stats_json=?, is_active=1 WHERE page='home'");
        if ($st) { $st->bind_param('sssssss', $h['announce'], $h['desc'], $h['cta_primary_text'], $h['cta_primary_url'], $h['cta_sec_text'], $h['cta_sec_url'], $statsJson); $st->execute(); $st->close(); }
        $log[] = 'Hero: updated to match the landing page';
    } else {
        $log[] = 'Hero: kept as it is';
    }

    // 2) page blocks
    $i = 0;
    foreach (la_site_blocks() as $key => [$title, $data]) {
        $i++;
        if ($overwrite || !la_block_exists($conn, $key)) {
            la_block_save($conn, $key, $title, $data, 1, $i);
            $log[] = $title . ': ' . ($overwrite ? 'updated' : 'created') . ' from the landing page';
        } else {
            $log[] = $title . ': kept as it is';
        }
    }

    // 3) testimonials (lp_testimonials): job title in "role", city in "company"
    $cnt = (int)($conn->query("SELECT COUNT(*) c FROM lp_testimonials")->fetch_assoc()['c'] ?? 0);
    if ($overwrite || $cnt === 0) {
        if ($overwrite && $cnt > 0) { $conn->query("DELETE FROM lp_testimonials"); }
        $st = $conn->prepare("INSERT INTO lp_testimonials (name, company, role, content, rating, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)");
        if ($st) {
            foreach (cms_default_testimonials() as $n => $t) {
                $job = trim((string)preg_replace('/,[^,]*$/', '', $t[2]));
                $city = trim((string)substr((string)strrchr($t[2], ','), 1));
                $rating = (int)$t[4]; $sort = $n + 1;
                $st->bind_param('ssssii', $t[1], $city, $job, $t[5], $rating, $sort);
                $st->execute();
            }
            $st->close();
        }
        $log[] = 'Testimonials: ' . count(cms_default_testimonials()) . ' stories ' . ($overwrite ? 'updated' : 'created') . ' from the landing page';
    } else {
        $log[] = 'Testimonials: kept as they are';
    }

    la_purge_page_cache();
    return $log;
}
