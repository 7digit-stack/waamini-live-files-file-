<?php
/**
 * Waamini Database Configuration
 * Provides both namespaced and legacy global Database APIs.
 */

namespace Waamini\Config {
use PDO;
use PDOException;

require_once __DIR__ . '/env.php';
require_once dirname(__DIR__) . '/includes/cron_runlog.php';   // cron jobs: note when this script ran

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
        $port = Env::get('DB_PORT', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3307');
        $dbname = Env::get('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: null);
        $user = Env::get('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: null);
        $pass = Env::get('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');
        $appEnv = strtolower((string)Env::get('APP_ENV', $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));

        // Local-dev safety: fallback from placeholder password to blank root password.
        if (
            $appEnv === 'local'
            && in_array((string)$host, ['127.0.0.1', 'localhost'], true)
            && (string)$user === 'root'
            && in_array((string)$pass, ['your_secure_db_password_here', 'CHANGE_ME_STRONG_PASSWORD'], true)
        ) {
            $pass = '';
        }

        if (!$dbname || !$user) {
            throw new \RuntimeException('Database credentials are missing in .env file.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => filter_var(Env::get('DB_PERSISTENT', 'false'), FILTER_VALIDATE_BOOLEAN),
            // Same SQL mode as db.php, so queries behave the same on every host (see DB_SQL_MODE there)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION sql_mode = '"
                . (preg_match('/^[A-Z_,]*$/', (string)Env::get('DB_SQL_MODE', '')) && Env::get('DB_SQL_MODE', '') !== '' ? Env::get('DB_SQL_MODE', '') : 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION') . "'"
                . ", time_zone = '" . date('P') . "'" // same clock as PHP date() (see db.php)
        ];

        try {
            self::$instance = new PDO($dsn, (string)$user, (string)$pass, $options);
            return self::$instance;
        } catch (PDOException $e) {
            error_log('Database Connection Failed: ' . $e->getMessage());
            throw new \RuntimeException('System Error: Could not connect to the database.');
        }
    }

    private function __clone() {}
}
}

namespace {
    use Waamini\Config\Database as NamespacedDatabase;

    if (!class_exists('Database')) {
        class Database {
            public static function getInstance(): \PDO {
                return NamespacedDatabase::getConnection();
            }

            public static function query(string $sql, array $params = []): \PDOStatement {
                $stmt = self::getInstance()->prepare($sql);
                self::bindParams($stmt, $params);
                $stmt->execute();
                return $stmt;
            }

            public static function fetch(string $sql, array $params = []): ?array {
                $row = self::query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
                return $row === false ? null : $row;
            }

            public static function fetchAll(string $sql, array $params = []): array {
                return self::query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
            }

            public static function insert(string $sql, array $params = []): int {
                self::query($sql, $params);
                return (int)self::getInstance()->lastInsertId();
            }

            public static function execute(string $sql, array $params = []): int {
                return self::query($sql, $params)->rowCount();
            }

            public static function beginTransaction(): bool {
                return self::getInstance()->beginTransaction();
            }

            public static function commit(): bool {
                return self::getInstance()->commit();
            }

            public static function rollback(): bool {
                if (self::getInstance()->inTransaction()) {
                    return self::getInstance()->rollBack();
                }
                return true;
            }

            private static function bindParams(\PDOStatement $stmt, array $params): void {
                if ($params === []) {
                    return;
                }

                $isNumeric = array_keys($params) === range(0, count($params) - 1);
                if ($isNumeric) {
                    foreach ($params as $idx => $value) {
                        $stmt->bindValue($idx + 1, $value, self::pdoType($value));
                    }
                    return;
                }

                foreach ($params as $key => $value) {
                    $placeholder = str_starts_with((string)$key, ':') ? (string)$key : ':' . $key;
                    $stmt->bindValue($placeholder, $value, self::pdoType($value));
                }
            }

            private static function pdoType(mixed $value): int {
                return match (true) {
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR
                };
            }
        }
    }
}
