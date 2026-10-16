<?php
/**
 * WAAMINI Schema Guard
 * =====================
 * Prevents expensive CREATE TABLE / ALTER TABLE statements from running
 * on every HTTP request. Uses a lightweight file-based flag system.
 *
 * Usage in page files:
 *   include_once __DIR__ . '/../includes/schema_guard.php';
 *   schema_ensure('bot_replies', function() use ($conn) {
 *       $conn->query("CREATE TABLE IF NOT EXISTS bot_replies (...)");
 *       $conn->query("ALTER TABLE bot_replies ADD COLUMN ...");
 *   });
 *
 * The callback runs ONLY ONCE per schema key. After that it's skipped instantly.
 */

if (!function_exists('schema_ensure')) {
    
    // Flag directory: writable storage folder
    define('SCHEMA_FLAG_DIR', (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/schema_flags');

    // Ensure flag directory exists (once per process startup, not per request)
    if (!is_dir(SCHEMA_FLAG_DIR)) {
        @mkdir(SCHEMA_FLAG_DIR, 0775, true);
    }

    /**
     * Run schema setup callback only if not already applied.
     *
     * @param string   $key      Unique identifier for this schema block (e.g. 'bot_replies')
     * @param callable $callback Function containing CREATE TABLE / ALTER TABLE statements
     * @param bool     $force    Set true to force re-run (e.g. after explicit migration)
     */
    function schema_ensure(string $key, callable $callback, bool $force = false): void
    {
        static $checked = [];

        // In-request memory cache — already verified this key this request
        if (!$force && isset($checked[$key])) {
            return;
        }

        $flagFile = SCHEMA_FLAG_DIR . '/' . preg_replace('/[^a-z0-9_\-]/', '_', strtolower($key)) . '.done';

        if (!$force && file_exists($flagFile)) {
            $checked[$key] = true;
            return;
        }

        // Run the setup callback
        try {
            $callback();
            // Mark as done
            @file_put_contents($flagFile, date('Y-m-d H:i:s'));
            $checked[$key] = true;
        } catch (\Throwable $e) {
            // Log but don't crash the page
            error_log('[WAAMINI SchemaGuard] ' . $key . ': ' . $e->getMessage());
        }
    }

    /**
     * Reset a schema flag to force re-run on next request.
     * Use after intentional schema changes (e.g. in migration scripts).
     */
    function schema_reset(string $key): void
    {
        $flagFile = SCHEMA_FLAG_DIR . '/' . preg_replace('/[^a-z0-9_\-]/', '_', strtolower($key)) . '.done';
        @unlink($flagFile);
    }

    /**
     * Reset ALL schema flags (e.g., after a major migration).
     */
    function schema_reset_all(): void
    {
        $files = glob(SCHEMA_FLAG_DIR . '/*.done');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }
}
