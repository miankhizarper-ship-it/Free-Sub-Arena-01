<?php

declare(strict_types=1);

/**
 * DNS Record Management Endpoint (`/web/api/dns-records.php`)
 *
 * - `GET` (`?subdomain_id=...`): Verifies subdomain belongs to authenticated user and lists its DNS records.
 * - `POST`: Validates and provisions a new DNS record (`A`, `AAAA`, `CNAME`, `MX`, `TXT`) inside the user's
 *           subdomain namespace (`{subdomain}.{root_domain}` or child hostnames), enforces
 *           `max_dns_records_per_subdomain`, creates on Cloudflare, and inserts into `dns_records`.
 * - `PUT` / `PATCH`: Updates an existing DNS record owned by the authenticated user's subdomain,
 *                    syncing via `CloudflareService::updateDnsRecord()` and updating `dns_records`.
 * - `DELETE` (`?id=...`): Deletes a DNS record owned by the authenticated user's subdomain via
 *                         `CloudflareService::deleteDnsRecord()` and deletes the row from `dns_records`.
 */

use FreeSub\Core\CloudflareService;
use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_bootstrap.php';

$method = requireHttpMethods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
$pdo = Database::getInstance();
$user = requireAuthenticatedUser($pdo);
$userId = (int) $user['id'];

switch ($method) {
    case 'GET':
        handleListDnsRecords($pdo, $userId);
        break;

    case 'POST':
        handleCreateDnsRecord($pdo, $userId);
        break;

    case 'PUT':
    case 'PATCH':
        handleUpdateDnsRecord($pdo, $userId);
        break;

    case 'DELETE':
        handleDeleteDnsRecord($pdo, $userId);
        break;
}

/**
 * Fetch a subdomain + root domain row and strictly verify ownership (`user_id === $userId`).
 *
 * @return array<string, mixed>
 */
function getOwnedSubdomainWithZone(PDO $pdo, int $subdomainId, int $userId, bool $requireActive = false): array
{
    $stmt = $pdo->prepare(
        'SELECT
            s.id,
            s.user_id,
            s.root_domain_id,
            s.subdomain_name,
            s.full_domain,
            s.status,
            s.ssl_status,
            rd.domain_name AS root_domain_name,
            rd.cloudflare_zone_id,
            rd.cloudflare_api_token,
            rd.is_active AS root_domain_active
         FROM subdomains s
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $subdomainId]);
    $subdomain = $stmt->fetch();

    if ($subdomain === false) {
        throw new ApiException('Subdomain not found.', 404, 'SUBDOMAIN_NOT_FOUND');
    }

    if ((int) $subdomain['user_id'] !== $userId) {
        throw new ApiException('You do not have permission to manage DNS records for this subdomain.', 403, 'FORBIDDEN');
    }

    if ($requireActive && ($subdomain['status'] ?? 'active') === 'suspended') {
        throw new ApiException('This subdomain is suspended and its DNS records cannot be modified.', 403, 'SUBDOMAIN_SUSPENDED');
    }

    return $subdomain;
}

/**
 * Strictly resolve and validate `record_name` so it can NEVER escape `{subdomain}.{root_domain}`.
 *
 * Accepts:
 * - `@` or `""` or exact `{subdomain}.{root_domain}` -> `{subdomain}.{root_domain}`
 * - Relative host label(s) such as `www`, `api.v1`, `_acme-challenge` -> `{label}.{subdomain}.{root_domain}`
 * - Full FQDN ending in `.{subdomain}.{root_domain}` -> `{label}.{subdomain}.{root_domain}`
 *
 * Rejects any FQDN belonging to the parent root domain or another user's subdomain!
 */
function resolveScopedRecordName(string $rawRecordName, string $subdomainFullDomain, string $rootDomainName): string
{
    $input = strtolower(rtrim(trim($rawRecordName), '.'));
    $baseSubdomain = strtolower(rtrim(trim($subdomainFullDomain), '.'));
    $parentDomain = strtolower(rtrim(trim($rootDomainName), '.'));

    // Guard against attempts to pass the bare root domain or sibling subdomains under the root domain
    if ($input !== '' && $input !== '@' && $input !== $baseSubdomain) {
        if ($input === $parentDomain || (str_ends_with($input, '.' . $parentDomain) && !str_ends_with($input, '.' . $baseSubdomain))) {
            throw new ApiException(
                sprintf('Record name "%s" must belong to "%s".', $rawRecordName, $baseSubdomain),
                422,
                'OUT_OF_SCOPE_RECORD_NAME'
            );
        }
    }

    $nameValidation = Validator::validateRecordName($input, $baseSubdomain);
    if (!$nameValidation['valid']) {
        throw new ApiException(
            $nameValidation['error'] ?? 'Invalid DNS record name.',
            422,
            'INVALID_RECORD_NAME'
        );
    }

    $resolved = $nameValidation['full_record_name'];

    // Final invariant check: resolved FQDN must equal `$baseSubdomain` or end with `.{$baseSubdomain}`
    if ($resolved !== $baseSubdomain && !str_ends_with($resolved, '.' . $baseSubdomain)) {
        throw new ApiException(
            sprintf('Resolved DNS record name must be scoped within "%s".', $baseSubdomain),
            422,
            'OUT_OF_SCOPE_RECORD_NAME'
        );
    }

    return $resolved;
}

/**
 * Format a `dns_records` DB row for JSON output.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function formatDnsRecordRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'subdomain_id' => (int) $row['subdomain_id'],
        'cf_record_id' => $row['cf_record_id'] !== null ? (string) $row['cf_record_id'] : null,
        'record_type' => (string) $row['record_type'],
        'record_name' => (string) $row['record_name'],
        'target_value' => (string) $row['target_value'],
        'proxied' => ((int) $row['proxied']) === 1,
        'ttl' => (int) $row['ttl'],
        'priority' => $row['priority'] !== null ? (int) $row['priority'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * Parse boolean input safely from JSON booleans, ints, or strings.
 */
function parseBooleanInput(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    if (is_string($value)) {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }

    return $default;
}

/**
 * GET /web/api/dns-records.php?subdomain_id=...
 */
function handleListDnsRecords(PDO $pdo, int $userId): never
{
    $rawSubdomainId = $_GET['subdomain_id'] ?? null;
    if ($rawSubdomainId === null || filter_var($rawSubdomainId, FILTER_VALIDATE_INT) === false || (int) $rawSubdomainId <= 0) {
        throw new ApiException('Query parameter "subdomain_id" is required.', 400, 'MISSING_SUBDOMAIN_ID');
    }

    $subdomainId = (int) $rawSubdomainId;
    $subdomain = getOwnedSubdomainWithZone($pdo, $subdomainId, $userId, false);

    $stmt = $pdo->prepare(
        'SELECT id, subdomain_id, cf_record_id, record_type, record_name, target_value, proxied, ttl, priority, created_at
         FROM dns_records
         WHERE subdomain_id = :subdomain_id
         ORDER BY id ASC'
    );
    $stmt->execute([':subdomain_id' => $subdomainId]);
    $rows = $stmt->fetchAll();

    $records = array_map(
        static fn(array $row): array => formatDnsRecordRow($row),
        $rows
    );

    sendJson([
        'success' => true,
        'subdomain' => [
            'id' => (int) $subdomain['id'],
            'subdomain_name' => (string) $subdomain['subdomain_name'],
            'full_domain' => (string) $subdomain['full_domain'],
            'status' => (string) $subdomain['status'],
            'ssl_status' => (string) $subdomain['ssl_status'],
        ],
        'records' => $records,
        'used_count' => count($records),
        'max_allowed' => getMaxDnsRecordsPerSubdomain($pdo),
    ]);
}

/**
 * POST /web/api/dns-records.php
 */
function handleCreateDnsRecord(PDO $pdo, int $userId): never
{
    $body = getJsonBody(true);

    $rawSubdomainId = $body['subdomain_id'] ?? null;
    if ($rawSubdomainId === null || filter_var($rawSubdomainId, FILTER_VALIDATE_INT) === false || (int) $rawSubdomainId <= 0) {
        throw new ApiException('Valid "subdomain_id" is required.', 422, 'VALIDATION_ERROR');
    }

    $subdomainId = (int) $rawSubdomainId;
    $subdomain = getOwnedSubdomainWithZone($pdo, $subdomainId, $userId, true);
    $fullDomain = (string) $subdomain['full_domain'];
    $rootDomainName = (string) $subdomain['root_domain_name'];

    $rawType = isset($body['record_type'])
        ? (string) $body['record_type']
        : (isset($body['type']) ? (string) $body['type'] : '');

    $rawName = isset($body['record_name'])
        ? (string) $body['record_name']
        : (isset($body['name']) ? (string) $body['name'] : '@');

    $rawTarget = isset($body['target_value'])
        ? (string) $body['target_value']
        : (isset($body['content']) ? (string) $body['content'] : '');

    $proxied = parseBooleanInput($body['proxied'] ?? false, false);
    $ttl = isset($body['ttl']) && $body['ttl'] !== '' ? (int) $body['ttl'] : 1;

    $priority = null;
    if (isset($body['priority']) && $body['priority'] !== '' && $body['priority'] !== null) {
        if (filter_var($body['priority'], FILTER_VALIDATE_INT) === false) {
            throw new ApiException('Field "priority" must be a valid integer.', 422, 'INVALID_PRIORITY');
        }
        $priority = (int) $body['priority'];
    }

    // Validate & scope `record_name` under `{subdomain}.{root_domain}`
    $scopedRecordName = resolveScopedRecordName($rawName, $fullDomain, $rootDomainName);

    // Validate DNS record type, target, TTL, proxy compatibility, and MX priority
    $recordCheck = Validator::validateDnsRecord($rawType, $rawTarget, $priority, $ttl, $proxied);
    if (!$recordCheck['valid']) {
        throw new ApiException(
            $recordCheck['error'] ?? 'Invalid DNS record fields.',
            422,
            'INVALID_DNS_RECORD'
        );
    }
    $norm = $recordCheck['normalized'];

    $maxRecords = getMaxDnsRecordsPerSubdomain($pdo);
    $cfService = CloudflareService::fromRootDomain($subdomain);
    $createdCfRecordId = null;

    $pdo->beginTransaction();

    try {
        // Lock the subdomain row to enforce `max_dns_records_per_subdomain` atomically
        $lockStmt = $pdo->prepare('SELECT id FROM subdomains WHERE id = :id FOR UPDATE');
        $lockStmt->execute([':id' => $subdomainId]);

        $countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM dns_records WHERE subdomain_id = :subdomain_id');
        $countStmt->execute([':subdomain_id' => $subdomainId]);
        $currentCount = (int) ($countStmt->fetch()['cnt'] ?? 0);

        if ($currentCount >= $maxRecords) {
            throw new ApiException(
                sprintf('Maximum DNS records limit (%d) reached for "%s".', $maxRecords, $fullDomain),
                403,
                'DNS_RECORD_LIMIT_REACHED',
                ['used_count' => $currentCount, 'max_allowed' => $maxRecords]
            );
        }

        // Provision record in Cloudflare
        $cfPayload = [
            'type' => $norm['type'],
            'name' => $scopedRecordName,
            'content' => $norm['target'],
            'proxied' => $norm['proxied'],
            'ttl' => $norm['ttl'],
            'priority' => $norm['priority'],
            'comment' => sprintf('FreeSub uid:%d sub:%d', $userId, $subdomainId),
        ];

        $cfResult = $cfService->createDnsRecord($cfPayload);
        if (!$cfResult['success'] || !is_array($cfResult['data']) || empty($cfResult['data']['id'])) {
            throw new ApiException(
                'Cloudflare rejected the DNS record: ' . ($cfResult['error'] ?? 'Unknown Cloudflare error'),
                422,
                'CLOUDFLARE_REJECTED'
            );
        }

        $createdCfRecordId = (string) $cfResult['data']['id'];
        $savedProxied = isset($cfResult['data']['proxied']) ? (bool) $cfResult['data']['proxied'] : $norm['proxied'];
        $savedTtl = isset($cfResult['data']['ttl']) ? (int) $cfResult['data']['ttl'] : $norm['ttl'];

        $insertStmt = $pdo->prepare(
            'INSERT INTO dns_records (subdomain_id, cf_record_id, record_type, record_name, target_value, proxied, ttl, priority)
             VALUES (:subdomain_id, :cf_record_id, :record_type, :record_name, :target_value, :proxied, :ttl, :priority)'
        );
        $insertStmt->execute([
            ':subdomain_id' => $subdomainId,
            ':cf_record_id' => $createdCfRecordId,
            ':record_type' => $norm['type'],
            ':record_name' => $scopedRecordName,
            ':target_value' => $norm['target'],
            ':proxied' => $savedProxied ? 1 : 0,
            ':ttl' => $savedTtl,
            ':priority' => $norm['priority'],
        ]);

        $newRecordId = (int) $pdo->lastInsertId();

        $fetchStmt = $pdo->prepare('SELECT * FROM dns_records WHERE id = :id LIMIT 1');
        $fetchStmt->execute([':id' => $newRecordId]);
        $createdRow = $fetchStmt->fetch();

        $pdo->commit();

        sendJson([
            'success' => true,
            'message' => 'DNS record created successfully.',
            'record' => $createdRow !== false ? formatDnsRecordRow($createdRow) : null,
            'used_count' => $currentCount + 1,
            'max_allowed' => $maxRecords,
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($createdCfRecordId !== null) {
            try {
                $cfService->deleteDnsRecord($createdCfRecordId);
            } catch (Throwable $cleanupErr) {
                error_log(sprintf(
                    '[FreeSub Cleanup Warning] Failed to delete orphaned Cloudflare DNS record %s: %s',
                    $createdCfRecordId,
                    $cleanupErr->getMessage()
                ));
            }
        }

        throw $e;
    }
}

/**
 * PUT|PATCH /web/api/dns-records.php
 */
function handleUpdateDnsRecord(PDO $pdo, int $userId): never
{
    $body = getJsonBody(true);
    $rawId = $_GET['id'] ?? $body['id'] ?? null;

    if ($rawId === null || filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
        throw new ApiException('Valid DNS record "id" is required.', 400, 'INVALID_RECORD_ID');
    }
    $recordId = (int) $rawId;

    // Fetch existing DNS record joined with subdomain & root domain to verify ownership
    $stmt = $pdo->prepare(
        'SELECT
            dr.id,
            dr.subdomain_id,
            dr.cf_record_id,
            dr.record_type,
            dr.record_name,
            dr.target_value,
            dr.proxied,
            dr.ttl,
            dr.priority,
            dr.created_at,
            s.user_id,
            s.full_domain,
            s.status AS subdomain_status,
            rd.domain_name AS root_domain_name,
            rd.cloudflare_zone_id,
            rd.cloudflare_api_token
         FROM dns_records dr
         INNER JOIN subdomains s ON s.id = dr.subdomain_id
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE dr.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $recordId]);
    $existing = $stmt->fetch();

    if ($existing === false) {
        throw new ApiException('DNS record not found.', 404, 'DNS_RECORD_NOT_FOUND');
    }

    if ((int) $existing['user_id'] !== $userId) {
        throw new ApiException('You do not have permission to modify this DNS record.', 403, 'FORBIDDEN');
    }

    if (($existing['subdomain_status'] ?? 'active') === 'suspended') {
        throw new ApiException('This subdomain is suspended and its DNS records cannot be updated.', 403, 'SUBDOMAIN_SUSPENDED');
    }

    $fullDomain = (string) $existing['full_domain'];
    $rootDomainName = (string) $existing['root_domain_name'];

    // Support partial PATCH updates by defaulting omitted fields to current DB values
    $rawType = isset($body['record_type'])
        ? (string) $body['record_type']
        : (isset($body['type']) ? (string) $body['type'] : (string) $existing['record_type']);

    $rawName = isset($body['record_name'])
        ? (string) $body['record_name']
        : (isset($body['name']) ? (string) $body['name'] : (string) $existing['record_name']);

    $rawTarget = isset($body['target_value'])
        ? (string) $body['target_value']
        : (isset($body['content']) ? (string) $body['content'] : (string) $existing['target_value']);

    $proxied = array_key_exists('proxied', $body)
        ? parseBooleanInput($body['proxied'], ((int) $existing['proxied']) === 1)
        : (((int) $existing['proxied']) === 1);

    $ttl = array_key_exists('ttl', $body) && $body['ttl'] !== ''
        ? (int) $body['ttl']
        : (int) $existing['ttl'];

    if (array_key_exists('priority', $body)) {
        if ($body['priority'] === null || $body['priority'] === '') {
            $priority = null;
        } elseif (filter_var($body['priority'], FILTER_VALIDATE_INT) === false) {
            throw new ApiException('Field "priority" must be a valid integer.', 422, 'INVALID_PRIORITY');
        } else {
            $priority = (int) $body['priority'];
        }
    } else {
        $priority = $existing['priority'] !== null ? (int) $existing['priority'] : null;
    }

    $scopedRecordName = resolveScopedRecordName($rawName, $fullDomain, $rootDomainName);

    $recordCheck = Validator::validateDnsRecord($rawType, $rawTarget, $priority, $ttl, $proxied);
    if (!$recordCheck['valid']) {
        throw new ApiException(
            $recordCheck['error'] ?? 'Invalid DNS record fields.',
            422,
            'INVALID_DNS_RECORD'
        );
    }
    $norm = $recordCheck['normalized'];

    $cfService = CloudflareService::fromRootDomain($existing);
    $cfRecordId = isset($existing['cf_record_id']) ? trim((string) $existing['cf_record_id']) : '';

    $cfPayload = [
        'type' => $norm['type'],
        'name' => $scopedRecordName,
        'content' => $norm['target'],
        'proxied' => $norm['proxied'],
        'ttl' => $norm['ttl'],
        'priority' => $norm['priority'],
        'comment' => sprintf('FreeSub uid:%d sub:%d', $userId, (int) $existing['subdomain_id']),
    ];

    // If `cf_record_id` exists, update in Cloudflare; if missing locally, re-create on Cloudflare
    if ($cfRecordId !== '') {
        $cfResult = $cfService->updateDnsRecord($cfRecordId, $cfPayload);
    } else {
        $cfResult = $cfService->createDnsRecord($cfPayload);
    }

    if (!$cfResult['success']) {
        throw new ApiException(
            'Cloudflare failed to update DNS record: ' . ($cfResult['error'] ?? 'Unknown Cloudflare error'),
            422,
            'CLOUDFLARE_UPDATE_FAILED'
        );
    }

    $updatedCfRecordId = (is_array($cfResult['data']) && !empty($cfResult['data']['id']))
        ? (string) $cfResult['data']['id']
        : $cfRecordId;

    $savedProxied = (is_array($cfResult['data']) && isset($cfResult['data']['proxied']))
        ? (bool) $cfResult['data']['proxied']
        : $norm['proxied'];

    $savedTtl = (is_array($cfResult['data']) && isset($cfResult['data']['ttl']))
        ? (int) $cfResult['data']['ttl']
        : $norm['ttl'];

    $updateStmt = $pdo->prepare(
        'UPDATE dns_records
         SET cf_record_id = :cf_record_id,
             record_type = :record_type,
             record_name = :record_name,
             target_value = :target_value,
             proxied = :proxied,
             ttl = :ttl,
             priority = :priority
         WHERE id = :id AND subdomain_id = :subdomain_id'
    );
    $updateStmt->execute([
        ':cf_record_id' => $updatedCfRecordId !== '' ? $updatedCfRecordId : null,
        ':record_type' => $norm['type'],
        ':record_name' => $scopedRecordName,
        ':target_value' => $norm['target'],
        ':proxied' => $savedProxied ? 1 : 0,
        ':ttl' => $savedTtl,
        ':priority' => $norm['priority'],
        ':id' => $recordId,
        ':subdomain_id' => (int) $existing['subdomain_id'],
    ]);

    $fetchStmt = $pdo->prepare('SELECT * FROM dns_records WHERE id = :id LIMIT 1');
    $fetchStmt->execute([':id' => $recordId]);
    $updatedRow = $fetchStmt->fetch();

    sendJson([
        'success' => true,
        'message' => 'DNS record updated successfully.',
        'record' => $updatedRow !== false ? formatDnsRecordRow($updatedRow) : null,
    ]);
}

/**
 * DELETE /web/api/dns-records.php?id=... (or JSON body `{"id": ...}`)
 */
function handleDeleteDnsRecord(PDO $pdo, int $userId): never
{
    $body = getJsonBody(false);
    $rawId = $_GET['id'] ?? $body['id'] ?? null;

    if ($rawId === null || filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
        throw new ApiException('Valid DNS record "id" is required for deletion.', 400, 'INVALID_RECORD_ID');
    }
    $recordId = (int) $rawId;

    $stmt = $pdo->prepare(
        'SELECT
            dr.id,
            dr.subdomain_id,
            dr.cf_record_id,
            dr.record_type,
            dr.record_name,
            s.user_id,
            s.full_domain,
            s.status AS subdomain_status,
            rd.cloudflare_zone_id,
            rd.cloudflare_api_token
         FROM dns_records dr
         INNER JOIN subdomains s ON s.id = dr.subdomain_id
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE dr.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $recordId]);
    $existing = $stmt->fetch();

    if ($existing === false) {
        throw new ApiException('DNS record not found.', 404, 'DNS_RECORD_NOT_FOUND');
    }

    if ((int) $existing['user_id'] !== $userId) {
        throw new ApiException('You do not have permission to delete this DNS record.', 403, 'FORBIDDEN');
    }

    if (($existing['subdomain_status'] ?? 'active') === 'suspended') {
        throw new ApiException(
            'This subdomain is suspended and its DNS records cannot be deleted or modified.',
            403,
            'SUBDOMAIN_SUSPENDED'
        );
    }

    $cfRecordId = isset($existing['cf_record_id']) ? trim((string) $existing['cf_record_id']) : '';

    if ($cfRecordId !== '') {
        $cfService = CloudflareService::fromRootDomain($existing);
        $cfResult = $cfService->deleteDnsRecord($cfRecordId);

        // Treat HTTP 404 / code 81044 ("Record does not exist") as idempotent success; fail on real auth/network errors
        if (!$cfResult['success']) {
            $errText = $cfResult['error'] ?? '';
            $alreadyMissing = str_contains($errText, '[81044]')
                || str_contains(strtolower($errText), 'does not exist')
                || str_contains(strtolower($errText), 'not found');

            if (!$alreadyMissing) {
                throw new ApiException(
                    'Failed to delete DNS record from Cloudflare: ' . ($errText ?: 'Unknown Cloudflare error'),
                    502,
                    'CLOUDFLARE_DELETE_FAILED'
                );
            }
        }
    }

    $deleteStmt = $pdo->prepare('DELETE FROM dns_records WHERE id = :id AND subdomain_id = :subdomain_id');
    $deleteStmt->execute([
        ':id' => $recordId,
        ':subdomain_id' => (int) $existing['subdomain_id'],
    ]);

    sendJson([
        'success' => true,
        'message' => 'DNS record deleted successfully.',
        'deleted_id' => $recordId,
    ]);
}
