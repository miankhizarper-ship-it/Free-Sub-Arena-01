<?php

declare(strict_types=1);

/**
 * Subdomain Availability Check Endpoint (`GET|POST /web/api/check-availability.php`)
 *
 * Accepts `subdomain` and either `root_domain_id` or `domain_name`.
 * Validates the subdomain label via `Validator`, checks that the target root domain
 * exists and is active, checks `subdomains` for an existing `full_domain`, and returns:
 * `{"available": bool, "full_domain": "...", "reason": "..."}`
 */

use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_bootstrap.php';

$method = requireHttpMethods(['GET', 'POST']);

$input = $method === 'POST'
    ? array_merge($_GET, getJsonBody(false))
    : $_GET;

$rawSubdomain = isset($input['subdomain']) ? (string) $input['subdomain'] : '';
$rootDomainId = isset($input['root_domain_id']) && $input['root_domain_id'] !== ''
    ? $input['root_domain_id']
    : null;
$domainNameInput = isset($input['domain_name']) && is_string($input['domain_name'])
    ? strtolower(trim($input['domain_name']))
    : '';

if (trim($rawSubdomain) === '') {
    throw new ApiException('Parameter "subdomain" is required.', 400, 'MISSING_SUBDOMAIN');
}

if ($rootDomainId === null && $domainNameInput === '') {
    throw new ApiException(
        'Either "root_domain_id" or "domain_name" must be provided.',
        400,
        'MISSING_ROOT_DOMAIN'
    );
}

$pdo = Database::getInstance();

// Look up active root domain by ID or domain_name
if ($rootDomainId !== null) {
    if (filter_var($rootDomainId, FILTER_VALIDATE_INT) === false || (int) $rootDomainId <= 0) {
        throw new ApiException('Invalid "root_domain_id" parameter.', 400, 'INVALID_ROOT_DOMAIN_ID');
    }

    $domainStmt = $pdo->prepare(
        'SELECT id, domain_name, is_active
         FROM root_domains
         WHERE id = :id
         LIMIT 1'
    );
    $domainStmt->execute([':id' => (int) $rootDomainId]);
} else {
    $domainStmt = $pdo->prepare(
        'SELECT id, domain_name, is_active
         FROM root_domains
         WHERE domain_name = :domain_name
         LIMIT 1'
    );
    $domainStmt->execute([':domain_name' => $domainNameInput]);
}

$rootDomain = $domainStmt->fetch();

if ($rootDomain === false || (int) $rootDomain['is_active'] !== 1) {
    throw new ApiException(
        'Selected root domain was not found or is currently inactive.',
        404,
        'ROOT_DOMAIN_NOT_FOUND'
    );
}

$normalizedSubdomain = strtolower(trim($rawSubdomain));
$rootDomainName = strtolower(trim((string) $rootDomain['domain_name']));
$candidateFullDomain = $normalizedSubdomain . '.' . $rootDomainName;

// Validate subdomain label rules & reserved list (built-in + custom settings)
$validation = Validator::validateSubdomainLabel($normalizedSubdomain, getCustomReservedSubdomains($pdo));
if (!$validation['valid']) {
    sendJson([
        'success' => true,
        'available' => false,
        'full_domain' => $candidateFullDomain,
        'reason' => $validation['error'] ?? 'Invalid subdomain label.',
    ]);
}

// Check if `full_domain` is already registered in `subdomains`
$existsStmt = $pdo->prepare(
    'SELECT id
     FROM subdomains
     WHERE full_domain = :full_domain
        OR (root_domain_id = :root_domain_id AND subdomain_name = :subdomain_name)
     LIMIT 1'
);
$existsStmt->execute([
    ':full_domain' => $candidateFullDomain,
    ':root_domain_id' => (int) $rootDomain['id'],
    ':subdomain_name' => $validation['normalized'],
]);

if ($existsStmt->fetch() !== false) {
    sendJson([
        'success' => true,
        'available' => false,
        'full_domain' => $candidateFullDomain,
        'reason' => sprintf('"%s" is already registered.', $candidateFullDomain),
    ]);
}

sendJson([
    'success' => true,
    'available' => true,
    'full_domain' => $candidateFullDomain,
    'reason' => 'Subdomain is available.',
]);
