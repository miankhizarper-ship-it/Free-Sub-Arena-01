<?php

declare(strict_types=1);

namespace FreeSub\Core;

/**
 * Application Configuration & Environment Loader
 *
 * Parses a local `.env` file if present without overriding pre-existing
 * OS/container environment variables, and exposes typed helper accessors
 * with safe defaults for FreeSub services.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $env = [];

    private static bool $loaded = false;

    /**
     * Load environment variables from a `.env` file.
     */
    public static function load(?string $envFilePath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $envFilePath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $rawLine) {
                    $line = trim($rawLine);

                    // Skip comments and blank lines
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    // Strip optional `export ` prefix
                    if (str_starts_with($line, 'export ')) {
                        $line = ltrim(substr($line, 7));
                    }

                    $separatorPos = strpos($line, '=');
                    if ($separatorPos === false) {
                        continue;
                    }

                    $key = trim(substr($line, 0, $separatorPos));
                    $value = trim(substr($line, $separatorPos + 1));

                    if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                        continue;
                    }

                    $value = self::parseValue($value);
                    self::$env[$key] = $value;

                    if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Retrieve a string configuration value with fallback default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();

        $osVal = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($osVal !== false && $osVal !== null) {
            return (string) $osVal;
        }

        return self::$env[$key] ?? $default;
    }

    /**
     * Retrieve an integer configuration value.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key);
        if ($val === null || $val === '' || filter_var($val, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return (int) $val;
    }

    /**
     * Retrieve a boolean configuration value.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            return $default;
        }

        return match (strtolower(trim($val))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * Return structured configuration array with safe defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::load();

        $issuer = rtrim(self::get('CLERK_ISSUER', 'https://clerk.freesub.local') ?? '', '/');
        $defaultJwks = $issuer !== '' ? $issuer . '/.well-known/jwks.json' : '';

        return [
            'app' => [
                'name' => self::get('APP_NAME', 'FreeSub'),
                'env' => self::get('APP_ENV', 'production'),
                'debug' => self::getBool('APP_DEBUG', false),
                'url' => self::get('APP_URL', 'http://localhost:8000'),
                'timezone' => self::get('APP_TIMEZONE', 'UTC'),
            ],
            'db' => [
                'host' => self::get('DB_HOST', '127.0.0.1'),
                'port' => self::getInt('DB_PORT', 3306),
                'name' => self::get('DB_NAME', 'freesub'),
                'user' => self::get('DB_USER', 'freesub_user'),
                'pass' => self::get('DB_PASS', ''),
                'charset' => self::get('DB_CHARSET', 'utf8mb4'),
            ],
            'clerk' => [
                'issuer' => $issuer,
                'jwks_url' => self::get('CLERK_JWKS_URL', $defaultJwks),
                'publishable_key' => self::get('CLERK_PUBLISHABLE_KEY', ''),
                'secret_key' => self::get('CLERK_SECRET_KEY', ''),
                'authorized_parties' => array_values(array_filter(array_map(
                    static fn(string $item): string => trim($item),
                    explode(',', self::get('CLERK_AUTHORIZED_PARTIES', '') ?? '')
                ))),
                'jwks_cache_ttl' => self::getInt('CLERK_JWKS_CACHE_TTL', 3600),
                'clock_leeway' => self::getInt('CLERK_CLOCK_LEEWAY', 60),
            ],
            'admin' => [
                'local_passcode' => self::get('ADMIN_LOCAL_PASSCODE', 'change-this-admin-passcode'),
            ],
            'cloudflare' => [
                'api_base_url' => rtrim(
                    self::get('CLOUDFLARE_API_BASE_URL', 'https://api.cloudflare.com/client/v4') ?? '',
                    '/'
                ),
                'timeout' => self::getInt('CLOUDFLARE_API_TIMEOUT', 15),
                'default_placeholder_ip' => self::get('DEFAULT_PLACEHOLDER_IP', '192.0.2.1'),
                'default_placeholder_proxied' => self::getBool('DEFAULT_PLACEHOLDER_PROXIED', true),
            ],
            'cors' => [
                'allowed_origins' => array_values(array_filter(array_map(
                    static fn(string $item): string => trim($item),
                    explode(',', self::get('CORS_ALLOWED_ORIGINS', '*') ?? '*')
                ))),
            ],
        ];
    }

    /**
     * Parse raw `.env` right-hand value (handles quotes, escapes, and inline comments).
     */
    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];

        if ($firstChar === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $matches)) {
                return str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    $matches[1]
                );
            }
            return trim($value, '"');
        }

        if ($firstChar === "'") {
            if (preg_match("/^'([^']*)'/", $value, $matches)) {
                return $matches[1];
            }
            return trim($value, "'");
        }

        // Unquoted value: strip inline `#` comments preceded by whitespace
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = substr($value, 0, $commentPos);
        }

        return rtrim($value);
    }
}

// Automatically load `.env` and apply default timezone on bootstrap
Config::load();
date_default_timezone_set(Config::get('APP_TIMEZONE', 'UTC') ?? 'UTC');

// Define core configuration constants with safe defaults for convenient global access
if (!defined('DB_HOST')) {
    define('DB_HOST', Config::get('DB_HOST', '127.0.0.1'));
    define('DB_PORT', Config::getInt('DB_PORT', 3306));
    define('DB_NAME', Config::get('DB_NAME', 'freesub'));
    define('DB_USER', Config::get('DB_USER', 'freesub_user'));
    define('DB_PASS', Config::get('DB_PASS', ''));
    define('CLERK_ISSUER', rtrim(Config::get('CLERK_ISSUER', 'https://clerk.freesub.local') ?? '', '/'));
    define(
        'CLERK_JWKS_URL',
        Config::get('CLERK_JWKS_URL', CLERK_ISSUER . '/.well-known/jwks.json')
    );
    define('ADMIN_LOCAL_PASSCODE', Config::get('ADMIN_LOCAL_PASSCODE', 'change-this-admin-passcode'));
}
