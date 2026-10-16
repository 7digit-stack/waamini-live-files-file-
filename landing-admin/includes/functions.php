<?php
/** Landing Admin — shared helper functions */

/** Get a setting value from lp_settings */
function la_setting(mysqli $conn, string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $st = $conn->prepare("SELECT `value` FROM lp_settings WHERE `key`=? LIMIT 1");
        if ($st) {
            $st->bind_param('s', $key);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $cache[$key] = $row ? (string)$row['value'] : $default;
            $st->close();
        } else {
            $cache[$key] = $default;
        }
    }
    return $cache[$key];
}

/** Upsert a setting */
function la_set_setting(mysqli $conn, string $key, string $value): void {
    $st = $conn->prepare("INSERT INTO lp_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?");
    if ($st) { $st->bind_param('sss', $key, $value, $value); $st->execute(); $st->close(); }
}

/** Generate a URL slug from a title */
function la_slugify(string $title): string {
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^\w\s-]/u', '', $slug);
    $slug = preg_replace('/[\s_-]+/', '-', $slug);
    return trim($slug, '-');
}

/** Unique slug — checks lp_blogs */
function la_unique_slug(mysqli $conn, string $title, int $excludeId = 0): string {
    $base = la_slugify($title);
    $slug = $base;
    $i    = 1;
    while (true) {
        $st = $conn->prepare("SELECT id FROM lp_blogs WHERE slug=? AND id!=? LIMIT 1");
        if (!$st) break;
        $st->bind_param('si', $slug, $excludeId);
        $st->execute();
        if ($st->get_result()->num_rows === 0) break;
        $slug = $base . '-' . (++$i);
        $st->close();
    }
    return $slug;
}

/** Human-readable file size */
function la_filesize(int $bytes): string {
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}

/** Time-ago string */
function la_time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('d M Y', strtotime($datetime));
}

/** Safe HTML escape */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Flash message helpers */
function la_flash_set(string $type, string $msg): void {
    $_SESSION['la_flash'] = ['type' => $type, 'msg' => $msg];
}
function la_flash_get(): ?array {
    $f = $_SESSION['la_flash'] ?? null;
    unset($_SESSION['la_flash']);
    return $f;
}
function la_flash_html(): string {
    $f = la_flash_get();
    if (!$f) return '';
    $cls = $f['type'] === 'success' ? 'la-alert-success' : ($f['type'] === 'error' ? 'la-alert-error' : 'la-alert-info');
    $icons = [
        'success' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
        'error'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        'info'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
    ];
    $icon = $icons[$f['type']] ?? $icons['info'];
    return '<div class="la-alert '.$cls.'">'.$icon.' '.e($f['msg']).'</div>';
}

/** Allowed image MIME types for upload */
function la_allowed_mime(string $mime): bool {
    return in_array($mime, ['image/jpeg','image/jpg','image/png','image/gif','image/webp','image/svg+xml'], true);
}

// Any CMS save (POST) or delete (?delete=) clears the public page cache so the website shows the change at once.
if (!function_exists('la_purge_page_cache_after')) {
    function la_purge_page_cache_after(): void
    {
        $changed = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || isset($_GET['delete']);
        if (!$changed) return;
        register_shutdown_function(static function () {
            $dir = dirname(__DIR__, 2) . '/storage/page-cache';
            if (is_dir($dir)) { foreach (glob($dir . '/*.html') ?: [] as $f) { @unlink($f); } }
        });
    }
    la_purge_page_cache_after();
}
