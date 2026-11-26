<?php

declare(strict_types=1);

/**
 * Public Frontend Bootstrap Configuration Endpoint (`GET /web/api/bootstrap-config.php`)
 *
 * Returns non-sensitive frontend configuration (`clerkPublishableKey`, app metadata,
 * active root domains without Cloudflare credentials, default limits, and optional
 * authenticated user profile if a valid session token is attached).
 */

use FreeSub\Core\Config;
use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_bootstrap.php';

requireHttpMethods(['GET']);

$pdo = Database::getInstance();

// Fetch active root domains — strictly selecting public fields only (never zone_id or api_token)
$domainStmt = $pdo->prepare(
    'SELECT id, domain_name, description
     FROM root_domains
     WHERE is_active = 1
     ORDER BY domain_name ASC'
);
$domainStmt->execute();
$rawDomains = $domainStmt->fetchAll();

$rootDomains = array_map(
    static fn(array $row): array => [
        'id' => (int) $row['id'],
        'domain_name' => (string) $row['domain_name'],
        'description' => $row['description'] !== null ? (string) $row['description'] : null,
    ],
    $rawDomains
);

$defaultSubdomainLimit = (int) getSystemSetting($pdo, 'default_free_subdomain_limit', '2');
$maxDnsRecordsPerSubdomain = getMaxDnsRecordsPerSubdomain($pdo);

$optionalUser = getOptionalAuthenticatedUser($pdo);
$userData = null;

if ($optionalUser !== null) {
    $userData = [
        'id' => (int) $optionalUser['id'],
        'clerk_user_id' => (string) $optionalUser['clerk_user_id'],
        'email' => $optionalUser['email'],
        'full_name' => $optionalUser['full_name'],
        'plan' => (string) $optionalUser['plan'],
        'status' => (string) $optionalUser['status'],
        'subdomain_limit' => computeUserSubdomainLimit($pdo, $optionalUser),
    ];
}

$allReserved = array_values(array_unique(array_merge(
    Validator::RESERVED_SUBDOMAINS,
    getCustomReservedSubdomains($pdo)
)));

sendJson([
    'success' => true,
    'app_name' => Config::get('APP_NAME', 'FreeSub') ?? 'FreeSub',
    'clerkPublishableKey' => Config::get('CLERK_PUBLISHABLE_KEY', '') ?? '',
    'root_domains' => $rootDomains,
    'limits' => [
        'default_free_subdomain_limit' => max(0, $defaultSubdomainLimit),
        'max_dns_records_per_subdomain' => $maxDnsRecordsPerSubdomain,
    ],
    'allowed_record_types' => Validator::ALLOWED_RECORD_TYPES,
    'reserved_subdomains' => $allReserved,
    'user' => $userData,
]);
