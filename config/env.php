<?php
/**
 * Waamini Environment Loader
 * Safely parses .env file without polluting global namespace.
 */

namespace Waamini\Config;

class Env {
    private static array $values = [];
    private static bool $loaded = false;  // guard: only parse .env once per process

    public static function load(string $path): void {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            // In some environments, we might rely on system env vars, so don't throw if file is missing
            // unless we are in a strict environment.
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::$loaded = true;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes
                if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                    $value = substr($value, 1, -1);
                } else {
                    // Support inline comments
                    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                }

                self::$values[$key] = $value;
                
                // Populate both getenv and $_ENV for maximum compatibility
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    public static function get(string $key, $default = null) {
        return self::$values[$key] ?? $default;
    }
}
