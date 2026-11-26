<?php

declare(strict_types=1);

/**
 * Root Domains Management (`/admin-desktop/domains.php`)
 *
 * Allows local admins to:
 * - Add new root domains (`domain_name`, `cloudflare_zone_id`, `cloudflare_api_token`, `is_active`, `description`)
 *   with optional live Cloudflare API credential validation before saving.
 * - Edit existing root domain details (leaving token blank preserves current token, or replace with new token).
 * - Test Cloudflare connection on any saved root domain.
 * - Safely delete a root domain (checks existing subdomains / foreign key `ON DELETE RESTRICT` cleanly).
 */

use FreeSub\Core\CloudflareService;
use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$pdo = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_domain') {
            $domainName = strtolower(rtrim(trim((string) ($_POST['domain_name'] ?? '')), '.'));
            $zoneId = trim((string) ($_POST['cloudflare_zone_id'] ?? ''));
            $apiToken = trim((string) ($_POST['cloudflare_api_token'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $verifyFirst = isset($_POST['verify_cloudflare']);

            if (!Validator::isValidHostname($domainName)) {
                throw new RuntimeException('Please enter a valid root domain name (e.g. kharcha.info).');
            }
            if ($zoneId === '' || !preg_match('/^[a-fA-F0-9]{20,64}$/', $zoneId)) {
                throw new RuntimeException('Please enter a valid Cloudflare Zone ID (hexadecimal identifier).');
            }
            if ($apiToken === '') {
                throw new RuntimeException('Cloudflare API Token is required.');
            }

            if ($verifyFirst) {
                $cf = new CloudflareService($zoneId, $apiToken);
                $test = $cf->verifyZoneCredentials();
                if (!$test['success']) {
                    throw new RuntimeException('Cloudflare credential check failed: ' . ($test['error'] ?? 'Unknown error'));
                }
            }

            $dupStmt = $pdo->prepare('SELECT id FROM root_domains WHERE domain_name = :domain_name LIMIT 1');
            $dupStmt->execute([':domain_name' => $domainName]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException(sprintf('Root domain "%s" already exists.', $domainName));
            }

            $insStmt = $pdo->prepare(
                'INSERT INTO root_domains (domain_name, cloudflare_zone_id, cloudflare_api_token, is_active, description)
                 VALUES (:domain_name, :zone_id, :api_token, :is_active, :description)'
            );
            $insStmt->execute([
                ':domain_name' => $domainName,
                ':zone_id' => $zoneId,
                ':api_token' => $apiToken,
                ':is_active' => $isActive,
                ':description' => $description !== '' ? $description : null,
            ]);

            setFlash(
                'success',
                sprintf(
                    'Root domain "%s" added successfully%s.',
                    $domainName,
                    $verifyFirst ? ' (Cloudflare credentials verified)' : ''
                )
            );
        } elseif ($action === 'update_domain') {
            $id = (int) ($_POST['id'] ?? 0);
            $domainName = strtolower(rtrim(trim((string) ($_POST['domain_name'] ?? '')), '.'));
            $zoneId = trim((string) ($_POST['cloudflare_zone_id'] ?? ''));
            $newApiToken = trim((string) ($_POST['cloudflare_api_token'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $verifyFirst = isset($_POST['verify_cloudflare']);

            if ($id <= 0) {
                throw new RuntimeException('Invalid root domain ID.');
            }
            if (!Validator::isValidHostname($domainName)) {
                throw new RuntimeException('Please enter a valid root domain name.');
            }
            if ($zoneId === '' || !preg_match('/^[a-fA-F0-9]{20,64}$/', $zoneId)) {
                throw new RuntimeException('Please enter a valid Cloudflare Zone ID.');
            }

            $existingStmt = $pdo->prepare('SELECT * FROM root_domains WHERE id = :id LIMIT 1');
            $existingStmt->execute([':id' => $id]);
            $existing = $existingStmt->fetch();

            if ($existing === false) {
                throw new RuntimeException('Root domain not found.');
            }

            $effectiveToken = $newApiToken !== '' ? $newApiToken : (string) $existing['cloudflare_api_token'];

            if ($verifyFirst) {
                $cf = new CloudflareService($zoneId, $effectiveToken);
                $test = $cf->verifyZoneCredentials();
                if (!$test['success']) {
                    throw new RuntimeException('Cloudflare credential check failed: ' . ($test['error'] ?? 'Unknown error'));
                }
            }

            $updStmt = $pdo->prepare(
                'UPDATE root_domains
                 SET domain_name = :domain_name,
                     cloudflare_zone_id = :zone_id,
                     cloudflare_api_token = :api_token,
                     is_active = :is_active,
                     description = :description
                 WHERE id = :id'
            );
            $updStmt->execute([
                ':domain_name' => $domainName,
                ':zone_id' => $zoneId,
                ':api_token' => $effectiveToken,
                ':is_active' => $isActive,
                ':description' => $description !== '' ? $description : null,
                ':id' => $id,
            ]);

            setFlash('success', sprintf('Root domain "%s" updated successfully.', $domainName));
        } elseif ($action === 'test_domain') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM root_domains WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $domain = $stmt->fetch();

            if ($domain === false) {
                throw new RuntimeException('Root domain not found.');
            }

            $cf = CloudflareService::fromRootDomain($domain);
            $test = $cf->verifyZoneCredentials();
            if ($test['success']) {
                setFlash('success', sprintf('Cloudflare API connection verified for "%s"!', (string) $domain['domain_name']));
            } else {
                setFlash(
                    'danger',
                    sprintf('Cloudflare test failed for "%s": %s', (string) $domain['domain_name'], $test['error'] ?? 'Unknown error')
                );
            }
        } elseif ($action === 'delete_domain') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT rd.id, rd.domain_name, COUNT(s.id) AS subdomain_count
                 FROM root_domains rd
                 LEFT JOIN subdomains s ON s.root_domain_id = rd.id
                 WHERE rd.id = :id
                 GROUP BY rd.id, rd.domain_name'
            );
            $stmt->execute([':id' => $id]);
            $domain = $stmt->fetch();

            if ($domain === false) {
                throw new RuntimeException('Root domain not found.');
            }

            $subCount = (int) ($domain['subdomain_count'] ?? 0);
            if ($subCount > 0) {
                throw new RuntimeException(sprintf(
                    'Cannot delete "%s" because %d active/suspended subdomain(s) still reference it. Delete those subdomains first or mark the root domain Inactive.',
                    (string) $domain['domain_name'],
                    $subCount
                ));
            }

            $delStmt = $pdo->prepare('DELETE FROM root_domains WHERE id = :id');
            $delStmt->execute([':id' => $id]);

            setFlash('success', sprintf('Root domain "%s" deleted.', (string) $domain['domain_name']));
        }
    } catch (PDOException $pdoEx) {
        if ((string) $pdoEx->getCode() === '23000') {
            setFlash('danger', 'Database constraint prevented this operation (duplicate domain or existing child subdomains).');
        } else {
            setFlash('danger', 'Database error: ' . $pdoEx->getMessage());
        }
    } catch (Throwable $e) {
        setFlash('danger', $e->getMessage());
    }

    header('Location: domains.php');
    exit;
}

// Fetch all root domains with subdomain counts
$domainsStmt = $pdo->query(
    'SELECT
        rd.*,
        COUNT(s.id) AS subdomain_count
     FROM root_domains rd
     LEFT JOIN subdomains s ON s.root_domain_id = rd.id
     GROUP BY rd.id
     ORDER BY rd.is_active DESC, rd.domain_name ASC'
);
$domains = $domainsStmt->fetchAll();

renderAdminHeader('Root Domains Management', 'domains');
?>

<div class="row g-4">
    <!-- Left Column: Add New Root Domain -->
    <div class="col-xl-4">
        <div class="ad-card">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-plus-circle-fill text-info me-2"></i>Add Root Domain</div>
            </div>
            <div class="ad-card-body">
                <form method="post" action="domains.php" autocomplete="off">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="create_domain">

                    <div class="mb-3">
                        <label class="form-label" for="addDomainName">Root Domain Name</label>
                        <input type="text"
                               id="addDomainName"
                               name="domain_name"
                               class="form-control font-mono"
                               placeholder="kharcha.info"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="addZoneId">Cloudflare Zone ID</label>
                        <input type="text"
                               id="addZoneId"
                               name="cloudflare_zone_id"
                               class="form-control font-mono"
                               placeholder="32-char Zone ID from Cloudflare"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="addApiToken">Cloudflare Scoped API Token</label>
                        <input type="password"
                               id="addApiToken"
                               name="cloudflare_api_token"
                               class="form-control font-mono"
                               placeholder="Zone:DNS:Edit scoped token"
                               required>
                        <div class="form-text text-secondary small">
                            Requires <code>Zone &rarr; DNS &rarr; Edit</code> permission on this zone.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="addDesc">Public Description (Optional)</label>
                        <input type="text"
                               id="addDesc"
                               name="description"
                               class="form-control"
                               maxlength="500"
                               placeholder="Community developer domain">
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="addIsActive" name="is_active" value="1" checked>
                        <label class="form-check-label small fw-semibold" for="addIsActive">
                            Active (Allow new user registrations)
                        </label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="addVerifyCf" name="verify_cloudflare" value="1" checked>
                        <label class="form-check-label small" for="addVerifyCf">
                            Test Cloudflare API credentials before saving
                        </label>
                    </div>

                    <button type="submit" class="btn btn-ad-primary w-100">
                        <i class="bi bi-check2-circle me-1"></i>Save Root Domain
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Configured Root Domains Table -->
    <div class="col-xl-8">
        <div class="ad-card overflow-hidden">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-globe2 text-info me-2"></i>Configured Cloudflare Zones (<?= count($domains) ?>)</div>
            </div>

            <?php if ($domains === []): ?>
                <div class="p-5 text-center text-secondary small">
                    <i class="bi bi-globe fs-2 d-block mb-2 text-info"></i>
                    No root domains configured yet. Add your first Cloudflare zone on the left to unlock subdomain registrations.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table ad-table align-middle">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Zone ID &amp; Token</th>
                                <th>Subdomains</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domains as $dom): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold font-mono text-light"><?= e($dom['domain_name']) ?></div>
                                        <?php if (!empty($dom['description'])): ?>
                                            <div class="small text-secondary"><?= e($dom['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small font-mono text-info">Zone: <?= e($dom['cloudflare_zone_id']) ?></div>
                                        <div class="small font-mono text-secondary">Token: <?= e(maskSecret((string) $dom['cloudflare_api_token'])) ?></div>
                                    </td>
                                    <td>
                                        <a href="subdomains.php?q=<?= urlencode((string) $dom['domain_name']) ?>" class="font-mono fw-bold text-decoration-none text-light">
                                            <?= (int) $dom['subdomain_count'] ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ((int) $dom['is_active'] === 1): ?>
                                            <span class="ad-badge ad-badge-active">Active</span>
                                        <?php else: ?>
                                            <span class="ad-badge ad-badge-suspended">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <!-- Live Test Cloudflare Button -->
                                            <form method="post" action="domains.php" class="d-inline">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="action" value="test_domain">
                                                <input type="hidden" name="id" value="<?= (int) $dom['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-info" title="Test Cloudflare API connection">
                                                    <i class="bi bi-plug-fill"></i>
                                                </button>
                                            </form>

                                            <!-- Edit Trigger -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-light js-edit-domain"
                                                    data-id="<?= (int) $dom['id'] ?>"
                                                    data-domain="<?= e($dom['domain_name']) ?>"
                                                    data-zone="<?= e($dom['cloudflare_zone_id']) ?>"
                                                    data-desc="<?= e($dom['description'] ?? '') ?>"
                                                    data-active="<?= (int) $dom['is_active'] ?>"
                                                    title="Edit domain">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- Delete Trigger -->
                                            <form method="post"
                                                  action="domains.php"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Delete root domain <?= e(addslashes((string) $dom['domain_name'])) ?>?');">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="action" value="delete_domain">
                                                <input type="hidden" name="id" value="<?= (int) $dom['id'] ?>">
                                                <button type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        <?= (int) $dom['subdomain_count'] > 0 ? 'title="Cannot delete while subdomains exist"' : 'title="Delete root domain"' ?>>
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Root Domain Modal -->
<div class="modal fade" id="editDomainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ad-modal">
            <form method="post" action="domains.php" autocomplete="off">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update_domain">
                <input type="hidden" name="id" id="editDomainId">

                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-info me-2"></i>Edit Root Domain</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="editDomainName">Root Domain Name</label>
                        <input type="text" id="editDomainName" name="domain_name" class="form-control font-mono" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="editZoneId">Cloudflare Zone ID</label>
                        <input type="text" id="editZoneId" name="cloudflare_zone_id" class="form-control font-mono" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="editApiToken">Replace Cloudflare API Token (Optional)</label>
                        <input type="password"
                               id="editApiToken"
                               name="cloudflare_api_token"
                               class="form-control font-mono"
                               placeholder="Leave blank to keep existing API token">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="editDesc">Public Description</label>
                        <input type="text" id="editDesc" name="description" class="form-control" maxlength="500">
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="editIsActive" name="is_active" value="1">
                        <label class="form-check-label small fw-semibold" for="editIsActive">
                            Active for new subdomain registrations
                        </label>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="editVerifyCf" name="verify_cloudflare" value="1">
                        <label class="form-check-label small" for="editVerifyCf">
                            Verify Cloudflare API credentials before saving
                        </label>
                    </div>
                </div>

                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ad-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('editDomainModal');
        if (!modalEl || !window.bootstrap) return;
        const modal = new window.bootstrap.Modal(modalEl);

        document.querySelectorAll('.js-edit-domain').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('editDomainId').value = btn.dataset.id || '';
                document.getElementById('editDomainName').value = btn.dataset.domain || '';
                document.getElementById('editZoneId').value = btn.dataset.zone || '';
                document.getElementById('editDesc').value = btn.dataset.desc || '';
                document.getElementById('editApiToken').value = '';
                document.getElementById('editIsActive').checked = btn.dataset.active === '1';
                document.getElementById('editVerifyCf').checked = false;
                modal.show();
            });
        });
    });
</script>

<?php
renderAdminFooter();
