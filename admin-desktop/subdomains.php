<?php

declare(strict_types=1);

/**
 * Global Subdomains Management (`/admin-desktop/subdomains.php`)
 *
 * View all subdomains across all users with search and status filter (`all`, `active`, `suspended`),
 * inspect live DNS records in a modal, suspend/unsuspend subdomains (de-provisioning / re-provisioning
 * Cloudflare DNS records cleanly while preserving local record rows), and force-delete subdomains.
 */

use FreeSub\Core\CloudflareService;
use FreeSub\Core\Database;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$pdo = Database::getInstance();

// Lightweight JSON responder for DNS inspection modal (`GET subdomains.php?ajax_dns=1&subdomain_id=...`)
if (isset($_GET['ajax_dns']) && $_GET['ajax_dns'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $subId = (int) ($_GET['subdomain_id'] ?? 0);
    if ($subId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid subdomain ID.']);
        exit;
    }

    $recStmt = $pdo->prepare(
        'SELECT id, cf_record_id, record_type, record_name, target_value, proxied, ttl, priority, created_at
         FROM dns_records
         WHERE subdomain_id = :subdomain_id
         ORDER BY id ASC'
    );
    $recStmt->execute([':subdomain_id' => $subId]);
    $records = $recStmt->fetchAll();

    echo json_encode(['success' => true, 'records' => $records], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Toggle subdomain status (`active` <-> `suspended`) and synchronize Cloudflare DNS records:
 * - On `suspended`: removes live Cloudflare records and sets `cf_record_id = NULL` so resolution stops immediately
 *   while preserving the user's record configuration in `dns_records`.
 * - On `active`: re-creates any records with `cf_record_id IS NULL` back onto Cloudflare.
 */
function adminSetSubdomainStatus(PDO $pdo, int $subdomainId, string $targetStatus): string
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.full_domain, s.status, rd.cloudflare_zone_id, rd.cloudflare_api_token
         FROM subdomains s
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $subdomainId]);
    $sub = $stmt->fetch();

    if ($sub === false) {
        throw new RuntimeException('Subdomain not found.');
    }

    $recStmt = $pdo->prepare('SELECT * FROM dns_records WHERE subdomain_id = :subdomain_id');
    $recStmt->execute([':subdomain_id' => $subdomainId]);
    $records = $recStmt->fetchAll();

    $cfSyncNotes = 0;
    $cfFailedCount = 0;

    if ($records !== []) {
        try {
            $cf = CloudflareService::fromRootDomain($sub);

            if ($targetStatus === 'suspended') {
                $clearCfStmt = $pdo->prepare('UPDATE dns_records SET cf_record_id = NULL WHERE id = :id');
                foreach ($records as $rec) {
                    $cfId = trim((string) ($rec['cf_record_id'] ?? ''));
                    if ($cfId !== '') {
                        $del = $cf->deleteDnsRecord($cfId);
                        $errText = strtolower($del['error'] ?? '');
                        $alreadyGone = str_contains($errText, '81044')
                            || str_contains($errText, 'does not exist')
                            || str_contains($errText, 'not found');

                        if ($del['success'] || $alreadyGone) {
                            $clearCfStmt->execute([':id' => (int) $rec['id']]);
                            $cfSyncNotes++;
                        } else {
                            $cfFailedCount++;
                            error_log(sprintf(
                                '[FreeSub Suspend Sync Warning] Failed to delete CF record %s for sub #%d: %s',
                                $cfId,
                                $subdomainId,
                                $del['error'] ?? 'Unknown error'
                            ));
                        }
                    }
                }
            } elseif ($targetStatus === 'active') {
                $setCfStmt = $pdo->prepare('UPDATE dns_records SET cf_record_id = :cf_id WHERE id = :id');
                foreach ($records as $rec) {
                    $cfId = trim((string) ($rec['cf_record_id'] ?? ''));
                    $payload = [
                        'type' => (string) $rec['record_type'],
                        'name' => (string) $rec['record_name'],
                        'content' => (string) $rec['target_value'],
                        'proxied' => ((int) $rec['proxied']) === 1,
                        'ttl' => (int) $rec['ttl'],
                        'priority' => $rec['priority'] !== null ? (int) $rec['priority'] : null,
                        'comment' => sprintf('FreeSub unsuspended sub:%d', $subdomainId),
                    ];

                    if ($cfId === '') {
                        $cfRes = $cf->createDnsRecord($payload);
                    } else {
                        // Verify/sync existing CF record; if deleted externally on CF, recreate and save new cf_record_id
                        $cfRes = $cf->updateDnsRecord($cfId, $payload);
                        if (!$cfRes['success']) {
                            $cfRes = $cf->createDnsRecord($payload);
                        }
                    }

                    if ($cfRes['success'] && is_array($cfRes['data']) && !empty($cfRes['data']['id'])) {
                        $setCfStmt->execute([
                            ':cf_id' => (string) $cfRes['data']['id'],
                            ':id' => (int) $rec['id'],
                        ]);
                        $cfSyncNotes++;
                    } else {
                        $cfFailedCount++;
                        error_log(sprintf(
                            '[FreeSub Unsuspend Sync Warning] Failed to provision DNS record #%d for sub #%d: %s',
                            (int) $rec['id'],
                            $subdomainId,
                            $cfRes['error'] ?? 'Unknown error'
                        ));
                    }
                }
            }
        } catch (Throwable $cfErr) {
            $cfFailedCount++;
            error_log('[FreeSub Admin Status Sync] ' . $cfErr->getMessage());
        }
    }

    $updStmt = $pdo->prepare('UPDATE subdomains SET status = :status WHERE id = :id');
    $updStmt->execute([
        ':status' => $targetStatus,
        ':id' => $subdomainId,
    ]);

    $failureNote = $cfFailedCount > 0 ? sprintf(', %d Cloudflare warning(s)', $cfFailedCount) : '';

    return sprintf(
        'Subdomain "%s" marked as %s (%d Cloudflare DNS record(s) synced%s).',
        (string) $sub['full_domain'],
        $targetStatus,
        $cfSyncNotes,
        $failureNote
    );
}

/**
 * Force-delete a subdomain: purge all associated Cloudflare DNS records (best-effort) and remove from MySQL.
 */
function adminForceDeleteSubdomain(PDO $pdo, int $subdomainId): string
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.full_domain, rd.cloudflare_zone_id, rd.cloudflare_api_token
         FROM subdomains s
         INNER JOIN root_domains rd ON rd.id = s.root_domain_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $subdomainId]);
    $sub = $stmt->fetch();

    if ($sub === false) {
        throw new RuntimeException('Subdomain not found.');
    }

    $recStmt = $pdo->prepare('SELECT cf_record_id FROM dns_records WHERE subdomain_id = :subdomain_id');
    $recStmt->execute([':subdomain_id' => $subdomainId]);
    $records = $recStmt->fetchAll();

    if ($records !== []) {
        try {
            $cf = CloudflareService::fromRootDomain($sub);
            foreach ($records as $rec) {
                $cfId = trim((string) ($rec['cf_record_id'] ?? ''));
                if ($cfId !== '') {
                    $cf->deleteDnsRecord($cfId);
                }
            }
        } catch (Throwable $cfErr) {
            error_log('[FreeSub Admin Force Delete Warning] ' . $cfErr->getMessage());
        }
    }

    $delStmt = $pdo->prepare('DELETE FROM subdomains WHERE id = :id');
    $delStmt->execute([':id' => $subdomainId]);

    return sprintf('Subdomain "%s" and all associated DNS records were permanently deleted.', (string) $sub['full_domain']);
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $subdomainId = (int) ($_POST['subdomain_id'] ?? 0);
    $returnTo = trim((string) ($_POST['return_to'] ?? 'subdomains.php'));
    // Restrict redirect strictly to relative local admin scripts
    if (!preg_match('/^[a-z0-9_-]+\.php(?:\?[a-zA-Z0-9_=&%-]*)?$/', $returnTo)) {
        $returnTo = 'subdomains.php';
    }

    try {
        if ($action === 'toggle_status') {
            $newStatus = ($_POST['target_status'] ?? '') === 'suspended' ? 'suspended' : 'active';
            $msg = adminSetSubdomainStatus($pdo, $subdomainId, $newStatus);
            setFlash('success', $msg);
        } elseif ($action === 'force_delete') {
            $msg = adminForceDeleteSubdomain($pdo, $subdomainId);
            setFlash('success', $msg);
        }
    } catch (Throwable $e) {
        setFlash('danger', $e->getMessage());
    }

    header('Location: ' . $returnTo);
    exit;
}

// Filter & Search Parameters
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'suspended'], true)) {
    $statusFilter = 'all';
}

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 's.status = :status';
    $params[':status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(s.full_domain LIKE :q1 OR u.email LIKE :q2 OR u.full_name LIKE :q3 OR rd.domain_name LIKE :q4)';
    $like = '%' . $search . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
            s.id,
            s.subdomain_name,
            s.full_domain,
            s.status,
            s.ssl_status,
            s.created_at,
            u.id AS user_id,
            u.email AS user_email,
            u.full_name AS user_name,
            rd.domain_name AS root_domain_name,
            COUNT(dr.id) AS dns_count
        FROM subdomains s
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN root_domains rd ON rd.id = s.root_domain_id
        LEFT JOIN dns_records dr ON dr.subdomain_id = s.id
        {$whereSql}
        GROUP BY
            s.id, s.subdomain_name, s.full_domain, s.status, s.ssl_status, s.created_at,
            u.id, u.email, u.full_name, rd.domain_name
        ORDER BY s.created_at DESC, s.id DESC";

$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$subdomains = $listStmt->fetchAll();

$currentReturnQuery = http_build_query(array_filter([
    'q' => $search !== '' ? $search : null,
    'status' => $statusFilter !== 'all' ? $statusFilter : null,
]));
$currentReturnUrl = 'subdomains.php' . ($currentReturnQuery !== '' ? '?' . $currentReturnQuery : '');

renderAdminHeader('Subdomains Management', 'subdomains');
?>

<!-- Filter & Search Bar -->
<div class="ad-card p-3 mb-4">
    <form method="get" action="subdomains.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text"
                       name="q"
                       value="<?= e($search) ?>"
                       class="form-control"
                       placeholder="Search by full domain, owner email, owner name, or root zone...">
            </div>
        </div>

        <div class="col-sm-6 col-md-3">
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
            </select>
        </div>

        <div class="col-sm-6 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-ad-primary flex-grow-1">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                <a href="subdomains.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Subdomains Table -->
<div class="ad-card overflow-hidden">
    <div class="ad-card-header">
        <div class="fw-bold">
            <i class="bi bi-hdd-network text-info me-2"></i>All Subdomains (<?= count($subdomains) ?>)
        </div>
    </div>

    <?php if ($subdomains === []): ?>
        <div class="p-5 text-center text-secondary small">
            No subdomains match your current filter criteria.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ad-table align-middle">
                <thead>
                    <tr>
                        <th>Full Domain</th>
                        <th>Owner</th>
                        <th>Root Zone</th>
                        <th>DNS Records</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subdomains as $sub): ?>
                        <tr>
                            <td>
                                <div class="fw-bold font-mono text-light"><?= e($sub['full_domain']) ?></div>
                                <div class="small text-secondary">ID #<?= (int) $sub['id'] ?></div>
                            </td>
                            <td>
                                <a href="user-details.php?id=<?= (int) $sub['user_id'] ?>" class="text-info text-decoration-none fw-semibold small">
                                    <?= e($sub['user_email'] ?: 'No email') ?>
                                </a>
                                <?php if (!empty($sub['user_name'])): ?>
                                    <div class="small text-secondary"><?= e($sub['user_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="font-mono small"><?= e($sub['root_domain_name']) ?></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-outline-info font-mono js-inspect-dns"
                                        data-id="<?= (int) $sub['id'] ?>"
                                        data-domain="<?= e($sub['full_domain']) ?>">
                                    <i class="bi bi-diagram-3 me-1"></i><?= (int) $sub['dns_count'] ?>
                                </button>
                            </td>
                            <td>
                                <?php if ($sub['status'] === 'active'): ?>
                                    <span class="ad-badge ad-badge-active"><i class="bi bi-check-circle-fill"></i>Active</span>
                                <?php else: ?>
                                    <span class="ad-badge ad-badge-suspended"><i class="bi bi-slash-circle"></i>Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary font-mono"><?= e($sub['created_at']) ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <!-- View DNS Modal Trigger -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-light js-inspect-dns"
                                            data-id="<?= (int) $sub['id'] ?>"
                                            data-domain="<?= e($sub['full_domain']) ?>"
                                            title="Inspect DNS records">
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <!-- Suspend / Unsuspend Toggle -->
                                    <form method="post" action="subdomains.php" class="d-inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="subdomain_id" value="<?= (int) $sub['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e($currentReturnUrl) ?>">
                                        <?php if ($sub['status'] === 'active'): ?>
                                            <input type="hidden" name="target_status" value="suspended">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Suspend subdomain">
                                                <i class="bi bi-pause-circle"></i> Suspend
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="target_status" value="active">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Unsuspend subdomain">
                                                <i class="bi bi-play-circle"></i> Unsuspend
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Force Delete -->
                                    <form method="post"
                                          action="subdomains.php"
                                          class="d-inline"
                                          onsubmit="return confirm('Force delete <?= e(addslashes((string) $sub['full_domain'])) ?> and remove all Cloudflare DNS records?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="force_delete">
                                        <input type="hidden" name="subdomain_id" value="<?= (int) $sub['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e($currentReturnUrl) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Force delete subdomain">
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

<!-- Inspect DNS Records Modal -->
<div class="modal fade" id="inspectDnsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content ad-modal">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-diagram-3-fill text-info me-2"></i>DNS Records for <span class="font-mono text-info" id="inspectDnsDomainTitle"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="inspectDnsBody">
                <div class="p-4 text-center text-secondary small">Loading records...</div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        'use strict';

        function esc(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const modalEl = document.getElementById('inspectDnsModal');
            if (!modalEl || !window.bootstrap) return;
            const modal = new window.bootstrap.Modal(modalEl);
            const titleEl = document.getElementById('inspectDnsDomainTitle');
            const bodyEl = document.getElementById('inspectDnsBody');

            document.querySelectorAll('.js-inspect-dns').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const subId = btn.dataset.id;
                    const domain = btn.dataset.domain || '';
                    titleEl.textContent = domain;
                    bodyEl.innerHTML = '<div class="p-4 text-center text-secondary small"><span class="spinner-border spinner-border-sm text-info me-2"></span>Loading DNS records...</div>';
                    modal.show();

                    try {
                        const res = await fetch('subdomains.php?ajax_dns=1&subdomain_id=' + encodeURIComponent(subId), {
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await res.json();
                        if (!data.success || !Array.isArray(data.records) || data.records.length === 0) {
                            bodyEl.innerHTML = '<div class="p-4 text-center text-secondary small">No DNS records stored for this subdomain.</div>';
                            return;
                        }

                        const rows = data.records.map(function (r) {
                            const cfState = r.cf_record_id
                                ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 font-mono">' + esc(r.cf_record_id.slice(0, 8)) + '…</span>'
                                : '<span class="badge bg-secondary bg-opacity-25 text-secondary">Paused / Unsynced</span>';
                            const proxyLabel = Number(r.proxied) === 1 ? 'Proxied' : 'DNS Only';
                            const prio = r.priority !== null ? ' (Prio ' + Number(r.priority) + ')' : '';

                            return '<tr>' +
                                '<td class="font-mono fw-bold text-info">' + esc(r.record_type) + '</td>' +
                                '<td class="font-mono small">' + esc(r.record_name) + '</td>' +
                                '<td class="font-mono small text-break">' + esc(r.target_value) + esc(prio) + '</td>' +
                                '<td class="small">' + esc(proxyLabel) + '</td>' +
                                '<td>' + cfState + '</td>' +
                            '</tr>';
                        }).join('');

                        bodyEl.innerHTML =
                            '<div class="table-responsive">' +
                                '<table class="table ad-table align-middle mb-0">' +
                                    '<thead><tr><th>Type</th><th>Name</th><th>Target</th><th>Proxy</th><th>CF Record</th></tr></thead>' +
                                    '<tbody>' + rows + '</tbody>' +
                                '</table>' +
                            '</div>';
                    } catch (err) {
                        bodyEl.innerHTML = '<div class="p-4 text-center text-danger small">Failed to load DNS records.</div>';
                    }
                });
            });
        });
    })();
</script>

<?php
renderAdminFooter();
