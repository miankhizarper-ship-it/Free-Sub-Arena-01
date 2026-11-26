<?php

declare(strict_types=1);

/**
 * Users Directory (`/admin-desktop/users.php`)
 *
 * Lists all registered Clerk-synced users with search & filters, plan badge (`free`/`pro`),
 * effective subdomain limit (`custom_subdomain_limit` or global default), active subdomain count,
 * account status (`active`/`suspended`), and direct link to `/admin-desktop/user-details.php?id=<user_id>`.
 */

use FreeSub\Core\Database;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$pdo = Database::getInstance();

$defaultFreeLimit = (int) adminGetSetting($pdo, 'default_free_subdomain_limit', '2');

$search = trim((string) ($_GET['q'] ?? ''));
$planFilter = strtolower(trim((string) ($_GET['plan'] ?? 'all')));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));

if (!in_array($planFilter, ['all', 'free', 'pro'], true)) {
    $planFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'active', 'suspended'], true)) {
    $statusFilter = 'all';
}

$where = [];
$params = [];

if ($planFilter !== 'all') {
    $where[] = 'u.plan = :plan';
    $params[':plan'] = $planFilter;
}

if ($statusFilter !== 'all') {
    $where[] = 'u.status = :status';
    $params[':status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(u.email LIKE :q1 OR u.full_name LIKE :q2 OR u.clerk_user_id LIKE :q3)';
    $like = '%' . $search . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}

$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
            u.id,
            u.clerk_user_id,
            u.email,
            u.full_name,
            u.plan,
            u.custom_subdomain_limit,
            u.status,
            u.created_at,
            COUNT(s.id) AS subdomain_count
        FROM users u
        LEFT JOIN subdomains s ON s.user_id = u.id
        {$whereSql}
        GROUP BY
            u.id, u.clerk_user_id, u.email, u.full_name,
            u.plan, u.custom_subdomain_limit, u.status, u.created_at
        ORDER BY u.created_at DESC, u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

renderAdminHeader('Users & Quotas', 'users');
?>

<!-- Filter & Search Bar -->
<div class="ad-card p-3 mb-4">
    <form method="get" action="users.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text"
                       name="q"
                       value="<?= e($search) ?>"
                       class="form-control"
                       placeholder="Search email, full name, or Clerk User ID...">
            </div>
        </div>

        <div class="col-6 col-md-2">
            <select name="plan" class="form-select">
                <option value="all" <?= $planFilter === 'all' ? 'selected' : '' ?>>All Plans</option>
                <option value="free" <?= $planFilter === 'free' ? 'selected' : '' ?>>Free Plan</option>
                <option value="pro" <?= $planFilter === 'pro' ? 'selected' : '' ?>>Pro Plan</option>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-ad-primary flex-grow-1">Search</button>
            <?php if ($search !== '' || $planFilter !== 'all' || $statusFilter !== 'all'): ?>
                <a href="users.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="ad-card overflow-hidden">
    <div class="ad-card-header">
        <div class="fw-bold"><i class="bi bi-people-fill text-info me-2"></i>Registered Users (<?= count($users) ?>)</div>
        <div class="small text-secondary">
            Global Free Limit: <span class="font-mono text-info fw-bold"><?= e($defaultFreeLimit) ?></span> subdomains
        </div>
    </div>

    <?php if ($users === []): ?>
        <div class="p-5 text-center text-secondary small">
            No users found matching your criteria.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ad-table align-middle">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Clerk ID</th>
                        <th>Plan</th>
                        <th>Usage / Effective Limit</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-end">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $hasCustom = $u['custom_subdomain_limit'] !== null;
                        $effectiveLimit = $hasCustom ? (int) $u['custom_subdomain_limit'] : $defaultFreeLimit;
                        $usedCount = (int) $u['subdomain_count'];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-light"><?= e($u['full_name'] ?: 'Unnamed User') ?></div>
                                <div class="small text-info"><?= e($u['email'] ?: 'No email synced') ?></div>
                            </td>
                            <td>
                                <span class="font-mono small text-secondary"><?= e($u['clerk_user_id']) ?></span>
                            </td>
                            <td>
                                <?php if ($u['plan'] === 'pro'): ?>
                                    <span class="ad-badge ad-badge-pro"><i class="bi bi-stars"></i>PRO</span>
                                <?php else: ?>
                                    <span class="ad-badge ad-badge-free">FREE</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="font-mono fw-bold <?= $usedCount >= $effectiveLimit ? 'text-warning' : 'text-light' ?>">
                                    <?= $usedCount ?> / <?= $effectiveLimit ?>
                                </span>
                                <?php if ($hasCustom): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 ms-1">Custom</span>
                                <?php else: ?>
                                    <span class="small text-secondary ms-1">(Default)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="ad-badge ad-badge-active">Active</span>
                                <?php else: ?>
                                    <span class="ad-badge ad-badge-suspended">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary font-mono"><?= e($u['created_at']) ?></td>
                            <td class="text-end">
                                <a href="user-details.php?id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-ad-primary px-3">
                                    <i class="bi bi-person-gear me-1"></i>Manage User
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
