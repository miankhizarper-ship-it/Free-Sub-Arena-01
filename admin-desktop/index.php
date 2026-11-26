<?php

declare(strict_types=1);

/**
 * Admin Overview Dashboard (`/admin-desktop/index.php`)
 *
 * Displays platform KPI metrics (Total Users, Active Root Domains, Total Subdomains,
 * Active vs Suspended Subdomains, Default Free Subdomain Limit), recent activity,
 * and quick navigation cards.
 */

use FreeSub\Core\Database;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$dbError = null;
$stats = [
    'total_users' => 0,
    'active_users' => 0,
    'suspended_users' => 0,
    'active_root_domains' => 0,
    'total_root_domains' => 0,
    'total_subdomains' => 0,
    'active_subdomains' => 0,
    'suspended_subdomains' => 0,
    'total_dns_records' => 0,
    'default_free_limit' => '2',
    'max_dns_per_sub' => '10',
];
$recentSubdomains = [];

try {
    $pdo = Database::getInstance();

    $userRow = $pdo->query(
        "SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_users
         FROM users"
    )->fetch();

    $domainRow = $pdo->query(
        'SELECT
            COUNT(*) AS total_root_domains,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_root_domains
         FROM root_domains'
    )->fetch();

    $subRow = $pdo->query(
        "SELECT
            COUNT(*) AS total_subdomains,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subdomains,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_subdomains
         FROM subdomains"
    )->fetch();

    $dnsRow = $pdo->query('SELECT COUNT(*) AS total_dns_records FROM dns_records')->fetch();

    $stats['total_users'] = (int) ($userRow['total_users'] ?? 0);
    $stats['active_users'] = (int) ($userRow['active_users'] ?? 0);
    $stats['suspended_users'] = (int) ($userRow['suspended_users'] ?? 0);
    $stats['total_root_domains'] = (int) ($domainRow['total_root_domains'] ?? 0);
    $stats['active_root_domains'] = (int) ($domainRow['active_root_domains'] ?? 0);
    $stats['total_subdomains'] = (int) ($subRow['total_subdomains'] ?? 0);
    $stats['active_subdomains'] = (int) ($subRow['active_subdomains'] ?? 0);
    $stats['suspended_subdomains'] = (int) ($subRow['suspended_subdomains'] ?? 0);
    $stats['total_dns_records'] = (int) ($dnsRow['total_dns_records'] ?? 0);
    $stats['default_free_limit'] = adminGetSetting($pdo, 'default_free_subdomain_limit', '2');
    $stats['max_dns_per_sub'] = adminGetSetting($pdo, 'max_dns_records_per_subdomain', '10');

    $recentStmt = $pdo->query(
        'SELECT
            s.id,
            s.full_domain,
            s.status,
            s.created_at,
            u.id AS user_id,
            u.email,
            u.full_name
         FROM subdomains s
         INNER JOIN users u ON u.id = s.user_id
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 6'
    );
    $recentSubdomains = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

renderAdminHeader('Platform Overview', 'overview');
?>

<?php if ($dbError !== null): ?>
    <div class="alert alert-danger shadow-sm">
        <i class="bi bi-database-fill-exclamation me-2"></i>
        <strong>Database Connection Warning:</strong> <?= e($dbError) ?>
        <div class="small mt-1">Ensure MySQL is running, <code>database/schema.sql</code> is imported, and <code>.env</code> credentials match.</div>
    </div>
<?php endif; ?>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl">
        <div class="ad-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-secondary small text-uppercase fw-semibold">Total Users</span>
                <i class="bi bi-people-fill text-info fs-5"></i>
            </div>
            <div class="fs-2 fw-bold font-mono"><?= e($stats['total_users']) ?></div>
            <div class="small text-secondary mt-1">
                <span class="text-success"><?= e($stats['active_users']) ?> active</span>
                <?php if ($stats['suspended_users'] > 0): ?>
                    &middot; <span class="text-danger"><?= e($stats['suspended_users']) ?> suspended</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl">
        <div class="ad-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-secondary small text-uppercase fw-semibold">Active Root Domains</span>
                <i class="bi bi-globe2 text-success fs-5"></i>
            </div>
            <div class="fs-2 fw-bold font-mono"><?= e($stats['active_root_domains']) ?></div>
            <div class="small text-secondary mt-1">
                <?= e($stats['total_root_domains']) ?> total configured zone<?= $stats['total_root_domains'] === 1 ? '' : 's' ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl">
        <div class="ad-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-secondary small text-uppercase fw-semibold">Total Subdomains</span>
                <i class="bi bi-hdd-network text-info fs-5"></i>
            </div>
            <div class="fs-2 fw-bold font-mono"><?= e($stats['total_subdomains']) ?></div>
            <div class="small text-secondary mt-1">
                <?= e($stats['total_dns_records']) ?> provisioned DNS records
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl">
        <div class="ad-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-secondary small text-uppercase fw-semibold">Active vs Suspended</span>
                <i class="bi bi-activity text-warning fs-5"></i>
            </div>
            <div class="d-flex align-items-baseline gap-2">
                <span class="fs-2 fw-bold font-mono text-success"><?= e($stats['active_subdomains']) ?></span>
                <span class="text-secondary">/</span>
                <span class="fs-4 fw-bold font-mono text-danger"><?= e($stats['suspended_subdomains']) ?></span>
            </div>
            <div class="small text-secondary mt-1">Active / Suspended subdomains</div>
        </div>
    </div>

    <div class="col-sm-12 col-xl">
        <div class="ad-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-secondary small text-uppercase fw-semibold">Default Free Limit</span>
                <i class="bi bi-sliders text-info fs-5"></i>
            </div>
            <div class="fs-2 fw-bold font-mono"><?= e($stats['default_free_limit']) ?></div>
            <div class="small text-secondary mt-1">
                Max <?= e($stats['max_dns_per_sub']) ?> DNS records / subdomain
            </div>
        </div>
    </div>
</div>

<!-- Quick Navigation Modules -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <a href="domains.php" class="ad-card p-3 d-block text-decoration-none text-light h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 px-3 bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                    <i class="bi bi-globe2 fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold">Root Domains</div>
                    <div class="small text-secondary">Add Cloudflare zones &amp; API tokens</div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6 col-xl-3">
        <a href="subdomains.php" class="ad-card p-3 d-block text-decoration-none text-light h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 px-3 bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                    <i class="bi bi-hdd-network fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold">Subdomains</div>
                    <div class="small text-secondary">Inspect DNS, suspend, or force delete</div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6 col-xl-3">
        <a href="users.php" class="ad-card p-3 d-block text-decoration-none text-light h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 px-3 bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                    <i class="bi bi-people-fill fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold">Users &amp; Quotas</div>
                    <div class="small text-secondary">Manage plans, custom limits &amp; status</div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6 col-xl-3">
        <a href="settings.php" class="ad-card p-3 d-block text-decoration-none text-light h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 px-3 bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">
                    <i class="bi bi-sliders fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold">Global Settings</div>
                    <div class="small text-secondary">Default quotas &amp; reserved names</div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Recently Claimed Subdomains -->
<div class="ad-card overflow-hidden">
    <div class="ad-card-header">
        <div class="fw-bold"><i class="bi bi-clock-history text-info me-2"></i>Recently Provisioned Subdomains</div>
        <a href="subdomains.php" class="btn btn-sm btn-outline-light">View All</a>
    </div>

    <?php if ($recentSubdomains === []): ?>
        <div class="p-4 text-center text-secondary small">
            No subdomains have been registered yet.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ad-table align-middle">
                <thead>
                    <tr>
                        <th>Subdomain</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Inspect</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSubdomains as $sub): ?>
                        <tr>
                            <td class="font-mono fw-semibold"><?= e($sub['full_domain']) ?></td>
                            <td>
                                <a href="user-details.php?id=<?= (int) $sub['user_id'] ?>" class="text-info text-decoration-none small">
                                    <?= e($sub['email'] ?: ($sub['full_name'] ?: 'User #' . $sub['user_id'])) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($sub['status'] === 'active'): ?>
                                    <span class="ad-badge ad-badge-active">Active</span>
                                <?php else: ?>
                                    <span class="ad-badge ad-badge-suspended">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary font-mono"><?= e($sub['created_at']) ?></td>
                            <td class="text-end">
                                <a href="subdomains.php?q=<?= urlencode((string) $sub['full_domain']) ?>" class="btn btn-sm btn-outline-info">
                                    Manage
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
renderAdminFooter();
