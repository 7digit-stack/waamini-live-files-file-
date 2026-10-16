<?php
/**
 * Build a single minified public CSS bundle.
 *
 * Run:
 *   php scripts/build_public_css_bundle.php
 */

$root = dirname(__DIR__);
$inputs = [
    $root . '/assets/css/modern-style.css',
    $root . '/assets/css/premium-effects.css',
    $root . '/assets/css/industries.css',
    $root . '/assets/css/bottimeline.css',
];
$outPath = $root . '/assets/css/public.bundle.min.css';

$buf = "/* waamini public bundle */\n";
foreach ($inputs as $p) {
    if (!is_file($p)) continue;
    $css = (string)file_get_contents($p);
    // strip comments
    $css = preg_replace('!/\\*.*?\\*/!s', '', $css) ?? $css;
    // collapse whitespace
    $css = preg_replace('/\\s+/', ' ', $css) ?? $css;
    // trim around symbols
    $css = preg_replace('/\\s*([{}:;,])\\s*/', '$1', $css) ?? $css;
    $css = str_replace(';}', '}', $css);
    $buf .= $css . "\n";
}

@file_put_contents($outPath, $buf);
echo "Wrote: {$outPath}\n";

