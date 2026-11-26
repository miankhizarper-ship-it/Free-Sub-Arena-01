<?php

declare(strict_types=1);

namespace FreeSub\Core;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/config.php';

/**
 * PDO Singleton Database Connection Manager
 *
 * Enforces UTF-8MB4 charset, associative fetch mode, native prepared statements,
 * and exception-based error reporting.
 */
final class Database
{
    private static ?PDO $instance = null;

    /**
     * Prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning of singleton instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization of singleton instance.
     */
    public function __wakeup(): never
    {
        throw new RuntimeException('Cannot unserialize a singleton Database instance.');
    }

    /**
     * Get the shared PDO database connection instance.
     *
     * @throws RuntimeException If the database connection fails.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::getInt('DB_PORT', 3306);
        $dbName = Config::get('DB_NAME', 'freesub');
        $user = Config::get('DB_USER', 'freesub_user');
        $pass = Config::get('DB_PASS', '') ?? '';
        $charset = Config::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbName,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $debug = Config::getBool('APP_DEBUG', false);
            $message = $debug
                ? 'Database connection failed: ' . $e->getMessage()
                : 'Database connection failed. Please verify your configuration.';

            throw new RuntimeException($message, (int) $e->getCode(), $e);
        }

        return self::$instance;
    }

    /**
     * Convenience alias for getInstance().
     */
    public static function connection(): PDO
    {
        return self::getInstance();
    }

    /**
     * Reset the active PDO connection (useful for long-running scripts or testing).
     */
    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
