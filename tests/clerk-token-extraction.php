<?php

declare(strict_types=1);

// CLI regression tests. Under CLI, stub Apache's header function if absent.
if (!function_exists('apache_request_headers')) {
    function apache_request_headers(): array
    {
        return $GLOBALS['testApacheHeaders'] ?? [];
    }
}

require_once dirname(__DIR__) . '/core/ClerkAuth.php';

use FreeSub\Core\ClerkAuth;

function checkToken(?string $expected, string $label): void
{
    $actual = ClerkAuth::extractTokenFromRequest();
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer primary.token';
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect.token';
$_COOKIE['__session'] = 'cookie.token';
checkToken('primary.token', 'direct authorization takes precedence');
$_SERVER['HTTP_AUTHORIZATION'] = '';
checkToken('redirect.token', 'empty direct header does not mask redirect header');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$GLOBALS['testApacheHeaders'] = ['aUtHoRiZaTiOn' => 'bearer apache.token'];
checkToken('apache.token', 'case-insensitive Apache header');
$GLOBALS['testApacheHeaders'] = [];
checkToken('cookie.token', 'cookie fallback');
$_COOKIE['__session'] = ' ';
checkToken(null, 'empty cookie');
