<?php

declare(strict_types=1);

/**
 * User Subdomain Management Endpoint (`/web/api/subdomains.php`)
 *
 * - `GET`: Lists authenticated user's subdomains with DNS record counts, `used_count`, and `max_allowed`.
 * - `POST`: Claims a new subdomain under an active root domain, enforces user subdomain quota and
 *           global uniqueness, provisions a default placeholder `A` record on Cloudflare inside a
 *           safe DB transaction (rolling back & cleaning up Cloudflare on partial failure), and persists
 *           both the `subdomains` row and initial `dns_records` row.
 * - `DELETE`: Verifies ownership of `id`, removes all associated Cloudflare DNS records (best-effort),
 *             and deletes the subdomain row (cascading `dns_records` in MySQL).
 */

use FreeSub\Core\CloudflareService;
use FreeSub\Core\Config;
use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_bootstrap.php';

$method = requireHttpMethods(['GET', 'POST', 'DELETE']);
$pdo = Database::getInstance();
$user = requireAuthenticatedUser($pdo);
$userId = (int) $user['id'];

switch ($method) {
    case 'GET':
        handleListSubdomains($pdo, $user, $userId);
        break;

    case 'POST':
        handleCreateSubdomain($pdo, $user, $userId);
        break;

    case 'DELETE':
        handleDeleteSubdomain($pdo, $userId);
        break;
}

/**
 * GET /web/api/subdomains.php
 *
 * @param array<string, mixed> $user
 */
function handleListSubdomains(PDO $pdo, array $user, int $userId): never
{
    $stmt = $pdo->prepare(
        'SELECT
            s.id,
            s.root_domain_id,
            rd.domain_name AS root_domain_name,
            s.subdomain_name,
            s.full_domain,
            s.status,
            s.ssl_status,
            s.created_at,
            COUNT(dr.id) AS dns_record_count
         FROM subdomains s
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         LEFT JOIN dns_records dr ON dr.subdomain_id = s.id
         WHERE s.user_id = :user_id
         GROUP BY
            s.id,
            s.root_domain_id,
            rd.domain_name,
            s.subdomain_name,
            s.full_domain,
            s.status,
            s.ssl_status,
            s.created_at
         ORDER BY s.created_at DESC, s.id DESC'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    $subdomains = array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'root_domain_id' => (int) $row['root_domain_id'],
            'root_domain_name' => (string) $row['root_domain_name'],
            'subdomain_name' => (string) $row['subdomain_name'],
            'full_domain' => (string) $row['full_domain'],
            'status' => (string) $row['status'],
            'ssl_status' => (string) $row['ssl_status'],
            'dns_record_count' => (int) $row['dns_record_count'],
            'created_at' => (string) $row['created_at'],
        ],
        $rows
    );

    $maxAllowed = computeUserSubdomainLimit($pdo, $user);
    $usedCount = count($subdomains);

    sendJson([
        'success' => true,
        'subdomains' => $subdomains,
        'used_count' => $usedCount,
        'max_allowed' => $maxAllowed,
        'max_dns_records_per_subdomain' => getMaxDnsRecordsPerSubdomain($pdo),
    ]);
}

/**
 * POST /web/api/subdomains.php
 *
 * @param array<string, mixed> $user
 */
function handleCreateSubdomain(PDO $pdo, array $user, int $userId): never
{
    $body = getJsonBody(true);

    $rawSubdomain = isset($body['subdomain'])
        ? strtolower(trim((string) $body['subdomain']))
        : (isset($body['subdomain_name']) ? strtolower(trim((string) $body['subdomain_name'])) : '');

    $rootDomainIdRaw = $body['root_domain_id'] ?? null;

    if ($rawSubdomain === '') {
        throw new ApiException('Field "subdomain" is required.', 422, 'VALIDATION_ERROR');
    }

    if ($rootDomainIdRaw === null || filter_var($rootDomainIdRaw, FILTER_VALIDATE_INT) === false || (int) $rootDomainIdRaw <= 0) {
        throw new ApiException('Valid "root_domain_id" is required.', 422, 'VALIDATION_ERROR');
    }
    $rootDomainId = (int) $rootDomainIdRaw;

    // Validate subdomain label syntax and reserved words (built-in + custom settings)
    $labelCheck = Validator::validateSubdomainLabel($rawSubdomain, getCustomReservedSubdomains($pdo));
    if (!$labelCheck['valid']) {
        throw new ApiException(
            $labelCheck['error'] ?? 'Invalid subdomain label.',
            422,
            'INVALID_SUBDOMAIN_LABEL'
        );
    }
    $subdomainName = $labelCheck['normalized'];

    // Fetch active root domain (including Cloudflare credentials for provisioning)
    $domainStmt = $pdo->prepare(
        'SELECT id, domain_name, cloudflare_zone_id, cloudflare_api_token, is_active
         FROM root_domains
         WHERE id = :id
         LIMIT 1'
    );
    $domainStmt->execute([':id' => $rootDomainId]);
    $rootDomain = $domainStmt->fetch();

    if ($rootDomain === false || (int) $rootDomain['is_active'] !== 1) {
        throw new ApiException('Selected root domain was not found or is inactive.', 404, 'ROOT_DOMAIN_NOT_FOUND');
    }

    $rootDomainName = strtolower(trim((string) $rootDomain['domain_name']));
    $fullDomain = $subdomainName . '.' . $rootDomainName;

    // Determine default placeholder IP & proxy flag from config (safe RFC 5737 TEST-NET-1 fallback `192.0.2.1`)
    $cfConfig = Config::all()['cloudflare'];
    $placeholderIp = (string) ($cfConfig['default_placeholder_ip'] ?? '192.0.2.1');
    if (!Validator::isValidIpv4($placeholderIp)) {
        $placeholderIp = '192.0.2.1';
    }
    $placeholderProxied = (bool) ($cfConfig['default_placeholder_proxied'] ?? true);

    $cfService = CloudflareService::fromRootDomain($rootDomain);
    $createdCfRecordId = null;

    $pdo->beginTransaction();

    try {
        // Lock the user row to serialize concurrent subdomain creations & prevent quota race conditions
        $lockUserStmt = $pdo->prepare('SELECT id, custom_subdomain_limit, status FROM users WHERE id = :id FOR UPDATE');
        $lockUserStmt->execute([':id' => $userId]);
        $lockedUser = $lockUserStmt->fetch();

        if ($lockedUser === false || ($lockedUser['status'] ?? 'active') === 'suspended') {
            throw new ApiException('Your account is suspended.', 403, 'ACCOUNT_SUSPENDED');
        }

        $maxAllowed = computeUserSubdomainLimit($pdo, $lockedUser);

        $countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM subdomains WHERE user_id = :user_id');
        $countStmt->execute([':user_id' => $userId]);
        $currentCount = (int) ($countStmt->fetch()['cnt'] ?? 0);

        if ($currentCount >= $maxAllowed) {
            throw new ApiException(
                sprintf('Subdomain limit reached (%d of %d used). Upgrade your plan or delete an existing subdomain.', $currentCount, $maxAllowed),
                403,
                'SUBDOMAIN_LIMIT_REACHED',
                ['used_count' => $currentCount, 'max_allowed' => $maxAllowed]
            );
        }

        // Check uniqueness of `full_domain` inside transaction
        $dupStmt = $pdo->prepare(
            'SELECT id FROM subdomains
             WHERE full_domain = :full_domain
                OR (root_domain_id = :root_domain_id AND subdomain_name = :subdomain_name)
             LIMIT 1
             FOR UPDATE'
        );
        $dupStmt->execute([
            ':full_domain' => $fullDomain,
            ':root_domain_id' => $rootDomainId,
            ':subdomain_name' => $subdomainName,
        ]);

        if ($dupStmt->fetch() !== false) {
            throw new ApiException(
                sprintf('The subdomain "%s" is already taken.', $fullDomain),
                409,
                'SUBDOMAIN_TAKEN'
            );
        }

        // Insert subdomain row inside transaction
        $insertSubStmt = $pdo->prepare(
            'INSERT INTO subdomains (user_id, root_domain_id, subdomain_name, full_domain, status, ssl_status)
             VALUES (:user_id, :root_domain_id, :subdomain_name, :full_domain, :status, :ssl_status)'
        );
        $insertSubStmt->execute([
            ':user_id' => $userId,
            ':root_domain_id' => $rootDomainId,
            ':subdomain_name' => $subdomainName,
            ':full_domain' => $fullDomain,
            ':status' => 'active',
            ':ssl_status' => 'active',
        ]);
        $subdomainId = (int) $pdo->lastInsertId();

        // Provision default placeholder A record on Cloudflare
        $cfResult = $cfService->createDnsRecord([
            'type' => 'A',
            'name' => $fullDomain,
            'content' => $placeholderIp,
            'proxied' => $placeholderProxied,
            'ttl' => 1,
            'comment' => sprintf('FreeSub initial record (uid:%d)', $userId),
        ]);

        if (!$cfResult['success'] || !is_array($cfResult['data']) || empty($cfResult['data']['id'])) {
            throw new ApiException(
                'Failed to provision initial DNS record on Cloudflare: ' . ($cfResult['error'] ?? 'Unknown Cloudflare error'),
                502,
                'CLOUDFLARE_PROVISION_FAILED'
            );
        }

        $createdCfRecordId = (string) $cfResult['data']['id'];
        $actualProxied = isset($cfResult['data']['proxied']) ? (bool) $cfResult['data']['proxied'] : $placeholderProxied;
        $actualTtl = isset($cfResult['data']['ttl']) ? (int) $cfResult['data']['ttl'] : 1;

        // Save initial DNS record in `dns_records`
        $insertDnsStmt = $pdo->prepare(
            'INSERT INTO dns_records (subdomain_id, cf_record_id, record_type, record_name, target_value, proxied, ttl, priority)
             VALUES (:subdomain_id, :cf_record_id, :record_type, :record_name, :target_value, :proxied, :ttl, :priority)'
        );
        $insertDnsStmt->execute([
            ':subdomain_id' => $subdomainId,
            ':cf_record_id' => $createdCfRecordId,
            ':record_type' => 'A',
            ':record_name' => $fullDomain,
            ':target_value' => $placeholderIp,
            ':proxied' => $actualProxied ? 1 : 0,
            ':ttl' => $actualTtl,
            ':priority' => null,
        ]);
        $dnsRecordId = (int) $pdo->lastInsertId();

        $pdo->commit();

        sendJson([
            'success' => true,
            'message' => sprintf('Subdomain "%s" created successfully.', $fullDomain),
            'subdomain' => [
                'id' => $subdomainId,
                'root_domain_id' => $rootDomainId,
                'root_domain_name' => $rootDomainName,
                'subdomain_name' => $subdomainName,
                'full_domain' => $fullDomain,
                'status' => 'active',
                'ssl_status' => 'active',
                'dns_record_count' => 1,
            ],
            'default_dns_record' => [
                'id' => $dnsRecordId,
                'cf_record_id' => $createdCfRecordId,
                'record_type' => 'A',
                'record_name' => $fullDomain,
                'target_value' => $placeholderIp,
                'proxied' => $actualProxied,
                'ttl' => $actualTtl,
                'priority' => null,
            ],
            'used_count' => $currentCount + 1,
            'max_allowed' => $maxAllowed,
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Compensating cleanup: if Cloudflare created the record before DB commit failed, delete it
        if ($createdCfRecordId !== null) {
            try {
                $cfService->deleteDnsRecord($createdCfRecordId);
            } catch (Throwable $cleanupErr) {
                error_log(sprintf(
                    '[FreeSub Cleanup Warning] Failed to roll back Cloudflare DNS record %s: %s',
                    $createdCfRecordId,
                    $cleanupErr->getMessage()
                ));
            }
        }

        // Convert unique-key race condition (`23000`) into clean 409 Conflict
        if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            throw new ApiException(
                sprintf('The subdomain "%s" is already taken.', $fullDomain),
                409,
                'SUBDOMAIN_TAKEN',
                null,
                $e
            );
        }

        throw $e;
    }
}

/**
 * DELETE /web/api/subdomains.php?id=... (or JSON body `{"id": ...}`)
 */
function handleDeleteSubdomain(PDO $pdo, int $userId): never
{
    $body = getJsonBody(false);
    $rawId = $_GET['id'] ?? $body['id'] ?? null;

    if ($rawId === null || filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
        throw new ApiException('Valid subdomain "id" is required for deletion.', 400, 'INVALID_SUBDOMAIN_ID');
    }
    $subdomainId = (int) $rawId;

    // Fetch subdomain + associated root domain credentials, verifying user ownership
    $subStmt = $pdo->prepare(
        'SELECT
            s.id,
            s.user_id,
            s.full_domain,
            rd.cloudflare_zone_id,
            rd.cloudflare_api_token
         FROM subdomains s
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $subStmt->execute([':id' => $subdomainId]);
    $subdomain = $subStmt->fetch();

    if ($subdomain === false) {
        throw new ApiException('Subdomain not found.', 404, 'SUBDOMAIN_NOT_FOUND');
    }

    if ((int) $subdomain['user_id'] !== $userId) {
        throw new ApiException('You do not have permission to delete this subdomain.', 403, 'FORBIDDEN');
    }

    // Load all Cloudflare record IDs tied to this subdomain
    $recordsStmt = $pdo->prepare(
        'SELECT id, cf_record_id
         FROM dns_records
         WHERE subdomain_id = :subdomain_id'
    );
    $recordsStmt->execute([':subdomain_id' => $subdomainId]);
    $dnsRecords = $recordsStmt->fetchAll();

    // Best-effort deletion of each record from Cloudflare
    $cfWarnings = [];
    if ($dnsRecords !== []) {
        try {
            $cfService = CloudflareService::fromRootDomain($subdomain);
            foreach ($dnsRecords as $record) {
                $cfRecordId = isset($record['cf_record_id']) ? trim((string) $record['cf_record_id']) : '';
                if ($cfRecordId === '') {
                    continue;
                }

                $delResult = $cfService->deleteDnsRecord($cfRecordId);
                if (!$delResult['success']) {
                    $errText = $delResult['error'] ?? 'Unknown Cloudflare error';
                    // Log warning (e.g., if record was already manually deleted in Cloudflare) and proceed
                    error_log(sprintf(
                        '[FreeSub Cloudflare Delete Notice] Subdomain #%d (%s) record %s: %s',
                        $subdomainId,
                        (string) $subdomain['full_domain'],
                        $cfRecordId,
                        $errText
                    ));
                    $cfWarnings[] = $cfRecordId;
                }
            }
        } catch (Throwable $cfInitErr) {
            error_log(sprintf(
                '[FreeSub Cloudflare Init Warning] Subdomain #%d: %s',
                $subdomainId,
                $cfInitErr->getMessage()
            ));
        }
    }

    // Delete subdomain from MySQL (`ON DELETE CASCADE` removes associated `dns_records`)
    $deleteStmt = $pdo->prepare('DELETE FROM subdomains WHERE id = :id AND user_id = :user_id');
    $deleteStmt->execute([
        ':id' => $subdomainId,
        ':user_id' => $userId,
    ]);

    sendJson([
        'success' => true,
        'message' => sprintf('Subdomain "%s" deleted successfully.', (string) $subdomain['full_domain']),
        'deleted_id' => $subdomainId,
        'cloudflare_cleanup_warnings' => count($cfWarnings),
    ]);
}
