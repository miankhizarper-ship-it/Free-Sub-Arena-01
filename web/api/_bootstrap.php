<?php

declare(strict_types=1);

/**
 * Shared REST API Bootstrap & Helper Utilities (`/web/api/_bootstrap.php`)
 *
 * Sets JSON/CORS headers, installs global error/exception handlers that render
 * structured JSON error responses (`400`, `401`, `403`, `404`, `409`, `422`, `500`),
 * and exposes reusable authentication, quota, and request-parsing helpers.
 */

use FreeSub\Core\ClerkAuth;
use FreeSub\Core\Config;
use FreeSub\Core\Database;

require_once dirname(__DIR__, 2) . '/core/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Validator.php';
require_once dirname(__DIR__, 2) . '/core/ClerkAuth.php';
require_once dirname(__DIR__, 2) . '/core/CloudflareService.php';

/**
 * Structured HTTP API Exception carrying an explicit HTTP status code and optional error code/details.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        public readonly ?string $errorCode = null,
        public readonly ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

// Configure default JSON & security headers
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    applyCorsHeaders();
}

// Handle CORS preflight requests immediately
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Convert PHP warnings/notices into ErrorException so nothing leaks HTML into JSON responses
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch all uncaught Throwables and format clean JSON error responses
set_exception_handler(static function (Throwable $e): never {
    if ($e instanceof ApiException) {
        sendJsonError(
            $e->getMessage(),
            $e->statusCode,
            $e->errorCode,
            $e->details
        );
    }

    if ($e instanceof InvalidArgumentException) {
        sendJsonError($e->getMessage(), 400, 'BAD_REQUEST');
    }

    $debug = Config::getBool('APP_DEBUG', false);
    $message = $debug
        ? $e->getMessage()
        : 'An unexpected server error occurred.';
    $details = $debug
        ? ['exception' => $e::class, 'file' => basename($e->getFile()), 'line' => $e->getLine()]
        : null;

    error_log(sprintf('[FreeSub API Error] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    sendJsonError($message, 500, 'INTERNAL_SERVER_ERROR', $details);
});

/**
 * Apply CORS headers cleanly according to `CORS_ALLOWED_ORIGINS`.
 */
function applyCorsHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = Config::all()['cors']['allowed_origins'] ?? ['*'];

    if ($origin !== '') {
        if (in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } elseif (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    } elseif (in_array('*', $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Emit a JSON response with an HTTP status code and terminate execution.
 *
 * @param array<string, mixed> $payload
 */
function sendJson(array $payload, int $statusCode = 200): never
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo '{"success":false,"error":"Failed to encode JSON response."}';
        exit;
    }

    echo $json;
    exit;
}

/**
 * Emit a standardized JSON error response and terminate execution.
 *
 * @param array<string, mixed>|null $details
 */
function sendJsonError(
    string $message,
    int $statusCode = 400,
    ?string $errorCode = null,
    ?array $details = null
): never {
    $response = [
        'success' => false,
        'error' => $message,
        'code' => $errorCode ?? defaultErrorCodeForStatus($statusCode),
    ];

    if ($details !== null && $details !== []) {
        $response['details'] = $details;
    }

    sendJson($response, $statusCode);
}

/**
 * Map HTTP status codes to standard machine-readable error codes.
 */
function defaultErrorCodeForStatus(int $status): string
{
    return match ($status) {
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        422 => 'VALIDATION_ERROR',
        429 => 'TOO_MANY_REQUESTS',
        default => 'INTERNAL_SERVER_ERROR',
    };
}

/**
 * Return the uppercase HTTP method of the current request.
 */
function getRequestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Enforce allowed HTTP methods for an endpoint.
 *
 * @param list<string> $allowedMethods
 */
function requireHttpMethods(array $allowedMethods): string
{
    $method = getRequestMethod();
    if (!in_array($method, $allowedMethods, true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $allowedMethods));
        }
        throw new ApiException(
            sprintf('Method %s is not allowed. Allowed: %s.', $method, implode(', ', $allowedMethods)),
            405,
            'METHOD_NOT_ALLOWED'
        );
    }

    return $method;
}

/**
 * Parse JSON or form-encoded request body into an associative array.
 *
 * @return array<string, mixed>
 */
function getJsonBody(bool $required = false): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        if ($required && $_POST === []) {
            throw new ApiException('Request body cannot be empty.', 400, 'EMPTY_BODY');
        }
        return $_POST !== [] ? $_POST : [];
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $parsed = [];
        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new ApiException('Invalid JSON payload in request body.', 400, 'INVALID_JSON');
    }

    return $decoded;
}

/**
 * Authenticate the current HTTP request via ClerkAuth (`Authorization: Bearer <token>` or `__session` cookie),
 * sync/load the user row from MySQL `users`, and reject invalid tokens (`401`) or suspended users (`403`).
 *
 * @return array<string, mixed> Authenticated `users` row
 */
function requireAuthenticatedUser(?PDO $pdo = null): array
{
    $token = ClerkAuth::extractTokenFromRequest();
    if ($token === null || $token === '') {
        throw new ApiException(
            'Authentication required. Please provide a valid Bearer token.',
            401,
            'UNAUTHENTICATED'
        );
    }

    $db = $pdo ?? Database::getInstance();
    $clerkAuth = new ClerkAuth();

    try {
        $claims = $clerkAuth->verifyJwt($token);
        $identity = $clerkAuth->extractUserIdentity($claims);
    } catch (Throwable $e) {
        throw new ApiException(
            'Invalid or expired authentication token: ' . $e->getMessage(),
            401,
            'INVALID_TOKEN',
            null,
            $e
        );
    }

    $user = $clerkAuth->syncUser(
        $identity['clerk_user_id'],
        $identity['email'],
        $identity['full_name'],
        $db
    );

    if (($user['status'] ?? 'active') === 'suspended') {
        throw new ApiException(
            'Your account is suspended and cannot perform this action.',
            403,
            'ACCOUNT_SUSPENDED'
        );
    }

    $user['_claims'] = $claims;

    return $user;
}

/**
 * Attempt optional authentication: returns the user row if a valid token is supplied, or `null` if anonymous/invalid.
 *
 * @return array<string, mixed>|null
 */
function getOptionalAuthenticatedUser(?PDO $pdo = null): ?array
{
    $token = ClerkAuth::extractTokenFromRequest();
    if ($token === null || $token === '') {
        return null;
    }

    try {
        return requireAuthenticatedUser($pdo);
    } catch (ApiException) {
        return null;
    }
}

/**
 * Read a system setting from the `settings` table with a safe fallback value.
 */
function getSystemSetting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();

    if ($row === false || !isset($row['setting_value']) || trim((string) $row['setting_value']) === '') {
        return $default;
    }

    return trim((string) $row['setting_value']);
}

/**
 * Compute the maximum number of subdomains a user is permitted to own.
 *
 * Returns `custom_subdomain_limit` if non-null on the `users` row;
 * otherwise reads `default_free_subdomain_limit` from `settings` (fallback `2`).
 *
 * @param array<string, mixed> $user
 */
function computeUserSubdomainLimit(PDO $pdo, array $user): int
{
    if (array_key_exists('custom_subdomain_limit', $user) && $user['custom_subdomain_limit'] !== null) {
        $customLimit = (int) $user['custom_subdomain_limit'];
        return max(0, $customLimit);
    }

    $rawDefault = getSystemSetting($pdo, 'default_free_subdomain_limit', '2');
    if (filter_var($rawDefault, FILTER_VALIDATE_INT) === false) {
        return 2;
    }

    return max(0, (int) $rawDefault);
}

/**
 * Read `max_dns_records_per_subdomain` from `settings` (fallback `10`).
 */
function getMaxDnsRecordsPerSubdomain(PDO $pdo): int
{
    $raw = getSystemSetting($pdo, 'max_dns_records_per_subdomain', '10');
    if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
        return 10;
    }

    return max(1, (int) $raw);
}

/**
 * Read custom admin-defined reserved subdomain labels from `settings.custom_reserved_subdomains`.
 *
 * @return list<string>
 */
function getCustomReservedSubdomains(PDO $pdo): array
{
    $raw = getSystemSetting($pdo, 'custom_reserved_subdomains', '');
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
    $clean = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '' && !in_array($trimmed, $clean, true)) {
            $clean[] = $trimmed;
        }
    }

    return $clean;
}
