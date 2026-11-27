<?php

declare(strict_types=1);

/**
 * FreeSub CLI Setup & Environment Diagnostics Script (`/setup-check.php`)
 *
 * Usage:
 *   php setup-check.php              # Run full environment, .env, & database checklist
 *   php setup-check.php --init-db    # Automatically create DB (if missing) & execute /database/schema.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: setup-check.php may only be executed from the command line (CLI).\n");
}

$argvList = $_SERVER['argv'] ?? [];
$shouldInitDb = in_array('--init-db', $argvList, true);
$noColor = in_array('--no-color', $argvList, true)
    || (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support') && !@sapi_windows_vt100_support(STDOUT, true));

$cReset = $noColor ? '' : "\033[0m";
$cBold = $noColor ? '' : "\033[1m";
$cGreen = $noColor ? '' : "\033[32m";
$cYellow = $noColor ? '' : "\033[33m";
$cRed = $noColor ? '' : "\033[31m";
$cCyan = $noColor ? '' : "\033[36m";

$passCount = 0;
$warnCount = 0;
$failCount = 0;

$printLine = static function (string $status, string $label, string $detail = '') use (
    &$passCount,
    &$warnCount,
    &$failCount,
    $cReset,
    $cBold,
    $cGreen,
    $cYellow,
    $cRed
): void {
    $badge = match ($status) {
        'PASS' => "{$cGreen}[PASS]{$cReset}",
        'WARN' => "{$cYellow}[WARN]{$cReset}",
        default => "{$cRed}[FAIL]{$cReset}",
    };

    if ($status === 'PASS') {
        $passCount++;
    } elseif ($status === 'WARN') {
        $warnCount++;
    } else {
        $failCount++;
    }

    $suffix = $detail !== '' ? " — {$detail}" : '';
    echo sprintf("  %s %s%s%s%s\n", $badge, $cBold, $label, $cReset, $suffix);
};

echo "\n{$cCyan}{$cBold}========================================================================{$cReset}\n";
echo "{$cCyan}{$cBold}  FreeSub SaaS Platform — Local Setup & Environment Diagnostics{$cReset}\n";
echo "{$cCyan}{$cBold}========================================================================{$cReset}\n\n";

// -----------------------------------------------------------------------------
// 1. PHP Runtime & Extensions
// -----------------------------------------------------------------------------
echo "{$cBold}1. PHP Runtime & Required Extensions{$cReset}\n";

$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.3.0', '>=')) {
    $printLine('PASS', 'PHP Version', "v{$phpVersion} (PHP 8.3+ optimal)");
} elseif (version_compare($phpVersion, '8.2.0', '>=')) {
    $printLine('PASS', 'PHP Version', "v{$phpVersion} (meets >= 8.2 requirement; PHP 8.3 recommended)");
} else {
    $printLine('FAIL', 'PHP Version', "v{$phpVersion} detected — PHP >= 8.2 is required");
}

$requiredExtensions = [
    'pdo_mysql' => 'MySQL PDO database driver',
    'curl' => 'Cloudflare API v4 & Clerk JWKS HTTP client',
    'openssl' => 'Clerk RS256 JWT cryptographic verification',
    'json' => 'REST API & JWKS JSON serialization',
    'mbstring' => 'UTF-8 multibyte string handling',
];

foreach ($requiredExtensions as $ext => $purpose) {
    if (extension_loaded($ext)) {
        $printLine('PASS', "Extension: {$ext}", $purpose);
    } else {
        $printLine('FAIL', "Extension: {$ext}", "Missing! Enable `extension={$ext}` in php.ini ({$purpose})");
    }
}

echo "\n";

// -----------------------------------------------------------------------------
// 2. Project Files, Cache Permissions & .env Configuration
// -----------------------------------------------------------------------------
echo "{$cBold}2. Configuration & Filesystem Checks{$cReset}\n";

$rootDir = __DIR__;
$envPath = $rootDir . DIRECTORY_SEPARATOR . '.env';
$envExamplePath = $rootDir . DIRECTORY_SEPARATOR . '.env.example';
$schemaPath = $rootDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
$cacheDir = $rootDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'cache';

if (is_file($envPath) && is_readable($envPath)) {
    $printLine('PASS', '.env file', 'Found at ' . $envPath);
} elseif (is_file($envExamplePath)) {
    $printLine('WARN', '.env file', 'Missing — using fallback defaults. Copy `.env.example` to `.env` and set your credentials.');
} else {
    $printLine('FAIL', '.env & .env.example', 'Neither `.env` nor `.env.example` found.');
}

if (is_file($schemaPath) && is_readable($schemaPath)) {
    $printLine('PASS', 'Database schema file', 'Found `/database/schema.sql`');
} else {
    $printLine('FAIL', 'Database schema file', 'Missing `/database/schema.sql`');
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $printLine('PASS', 'JWKS cache directory', '`/core/cache` exists and is writable');
} else {
    $printLine('FAIL', 'JWKS cache directory', '`/core/cache` is not writable by PHP');
}

require_once $rootDir . '/core/config.php';

use FreeSub\Core\Config;

$clerkPub = trim(Config::get('CLERK_PUBLISHABLE_KEY', '') ?? '');
$clerkIssuer = trim(Config::get('CLERK_ISSUER', '') ?? '');
$adminPasscode = Config::get('ADMIN_LOCAL_PASSCODE', 'change-this-admin-passcode') ?? '';

if ($clerkPub !== '' && $clerkPub !== 'pk_test_replace_me' && (str_starts_with($clerkPub, 'pk_test_') || str_starts_with($clerkPub, 'pk_live_'))) {
    $printLine('PASS', 'Clerk Publishable Key', 'Configured (' . substr($clerkPub, 0, 12) . '...)');
} else {
    $printLine('WARN', 'Clerk Publishable Key', 'Set `CLERK_PUBLISHABLE_KEY` in `.env` before testing user sign-in');
}

if ($clerkIssuer !== '' && !str_contains($clerkIssuer, 'your-instance') && $clerkIssuer !== 'https://clerk.freesub.local') {
    $printLine('PASS', 'Clerk Issuer / JWKS', $clerkIssuer);
} else {
    $printLine('WARN', 'Clerk Issuer / JWKS', 'Set `CLERK_ISSUER` and `CLERK_JWKS_URL` in `.env`');
}

if ($adminPasscode !== '' && !in_array($adminPasscode, ['change-this-admin-passcode', 'change-this-strong-admin-passcode'], true)) {
    $printLine('PASS', 'Admin Local Passcode', 'Custom passcode configured');
} else {
    $printLine('WARN', 'Admin Local Passcode', 'Using placeholder `ADMIN_LOCAL_PASSCODE` — update it in `.env`');
}

echo "\n";

// -----------------------------------------------------------------------------
// 3. MySQL Connection & Schema Verification (`--init-db` support)
// -----------------------------------------------------------------------------
echo "{$cBold}3. MySQL 8+ Database & Tables{$cReset}\n";

$requiredTables = [
    'settings',
    'users',
    'root_domains',
    'subdomains',
    'dns_records',
];

if (!extension_loaded('pdo_mysql')) {
    $printLine('FAIL', 'MySQL Connection', 'Skipped because `pdo_mysql` extension is not loaded');
} else {
    $dbHost = Config::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $dbPort = Config::getInt('DB_PORT', 3306);
    $dbName = Config::get('DB_NAME', 'freesub') ?? 'freesub';
    $dbUser = Config::get('DB_USER', 'freesub_user') ?? 'freesub_user';
    $dbPass = Config::get('DB_PASS', '') ?? '';
    $dbCharset = Config::get('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4';

    $pdo = null;

    try {
        // If `--init-db` was requested, connect to server first and ensure database exists
        if ($shouldInitDb && preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $dbHost, $dbPort, $dbCharset);
            $serverPdo = new PDO($serverDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $serverPdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $dbName
            ));
            $printLine('PASS', 'Database Ensure (--init-db)', "Database `{$dbName}` verified/created");
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $mysqlVer = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $printLine('PASS', 'MySQL Connection', "Connected to `{$dbName}` @ {$dbHost}:{$dbPort} (Server {$mysqlVer})");
    } catch (PDOException $e) {
        $printLine('FAIL', 'MySQL Connection', $e->getMessage());
        if (str_contains(strtolower($dbHost), 'infinityfree.com')) {
            echo "         Note: InfinityFree (`{$dbHost}`) blocks external/remote MySQL connections.\n";
            echo "         Scripts running on your InfinityFree hosting account can connect to `{$dbHost}`,\n";
            echo "         while local Windows testing requires a local MySQL instance (`127.0.0.1`).\n";
        } elseif (!$shouldInitDb) {
            echo "         Hint: Verify MySQL is running, `.env` DB_* credentials are valid, or run `php setup-check.php --init-db`.\n";
        }
    }

    if ($pdo !== null) {
        $existingTablesStmt = $pdo->query('SHOW TABLES');
        $existingTables = array_map('strtolower', $existingTablesStmt->fetchAll(PDO::FETCH_COLUMN));

        $missingTables = array_values(array_diff($requiredTables, $existingTables));

        if ($missingTables !== [] && $shouldInitDb) {
            if (is_file($schemaPath) && is_readable($schemaPath)) {
                $sqlContent = file_get_contents($schemaPath);
                if ($sqlContent !== false) {
                    try {
                        // Strip single-line SQL comments and execute individual statements cleanly
                        $cleanLines = [];
                        foreach (explode("\n", $sqlContent) as $line) {
                            $trimmedLine = ltrim($line);
                            if (str_starts_with($trimmedLine, '--')) {
                                continue;
                            }
                            $cleanLines[] = $line;
                        }
                        $statements = array_filter(array_map('trim', explode(';', implode("\n", $cleanLines))));
                        foreach ($statements as $stmtSql) {
                            if ($stmtSql !== '') {
                                $pdo->exec($stmtSql);
                            }
                        }

                        $printLine('PASS', 'Schema Import (--init-db)', 'Executed `/database/schema.sql` successfully');
                        $existingTables = array_map(
                            'strtolower',
                            $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
                        );
                    } catch (PDOException $schemaErr) {
                        $printLine('FAIL', 'Schema Import (--init-db)', $schemaErr->getMessage());
                    }
                }
            }
        }

        foreach ($requiredTables as $tbl) {
            if (in_array($tbl, $existingTables, true)) {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                $printLine('PASS', "Table `{$tbl}`", "Present ({$count} row" . ($count === 1 ? '' : 's') . ')');
            } else {
                $printLine('FAIL', "Table `{$tbl}`", 'Missing — run `php setup-check.php --init-db`');
            }
        }

        if (in_array('root_domains', $existingTables, true)) {
            $activeZones = (int) $pdo->query('SELECT COUNT(*) FROM `root_domains` WHERE `is_active` = 1')->fetchColumn();
            if ($activeZones > 0) {
                $printLine('PASS', 'Active Cloudflare Root Domains', "{$activeZones} active root domain(s) ready for users");
            } else {
                $printLine('WARN', 'Active Cloudflare Root Domains', '0 active zones — launch `/admin-desktop/launch-admin.bat` to add e.g. `kharcha.info`');
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Summary Footer
// -----------------------------------------------------------------------------
echo "\n{$cCyan}------------------------------------------------------------------------{$cReset}\n";
echo sprintf(
    "  Summary: {$cGreen}%d passed{$cReset}, {$cYellow}%d warning(s){$cReset}, {$cRed}%d failed{$cReset}\n",
    $passCount,
    $warnCount,
    $failCount
);
echo "{$cCyan}------------------------------------------------------------------------{$cReset}\n\n";

exit($failCount > 0 ? 1 : 0);
