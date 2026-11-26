<?php

declare(strict_types=1);

/**
 * Complete User Details & Quota Management (`/admin-desktop/user-details.php`)
 *
 * Shows full user profile (`id`, `clerk_user_id`, `email`, `full_name`, `plan`, `status`, `created_at`),
 * allows editing `plan` (`free`/`pro`), account `status` (`active`/`suspended`), and custom per-user
 * subdomain quota (`custom_subdomain_limit` integer or `NULL` to inherit global free limit), and
 * displays/manages every subdomain owned by this user.
 */

use FreeSub\Core\Database;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$pdo = Database::getInstance();

$userId = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) {
    setFlash('warning', 'Please select a valid user from the Users directory.');
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_user_settings') {
            $plan = ($_POST['plan'] ?? 'free') === 'pro' ? 'pro' : 'free';
            $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
            $limitMode = (string) ($_POST['limit_mode'] ?? 'inherit');

            $customLimit = null;
            if ($limitMode === 'custom') {
                $rawCustom = trim((string) ($_POST['custom_subdomain_limit'] ?? ''));
                if ($rawCustom === '' || filter_var($rawCustom, FILTER_VALIDATE_INT) === false || (int) $rawCustom < 0 || (int) $rawCustom > 10000) {
                    throw new RuntimeException('Custom subdomain limit must be an integer between 0 and 10000.');
                }
                $customLimit = (int) $rawCustom;
            }

            $updStmt = $pdo->prepare(
                'UPDATE users
                 SET plan = :plan,
                     status = :status,
                     custom_subdomain_limit = :custom_limit
                 WHERE id = :id'
            );
            $updStmt->execute([
                ':plan' => $plan,
                ':status' => $status,
                ':custom_limit' => $customLimit,
                ':id' => $userId,
            ]);

            setFlash('success', 'User profile, plan, status, and subdomain quota updated.');
        } elseif ($action === 'reset_custom_limit') {
            $updStmt = $pdo->prepare('UPDATE users SET custom_subdomain_limit = NULL WHERE id = :id');
            $updStmt->execute([':id' => $userId]);
            setFlash('success', 'User subdomain limit reset to inherit global default.');
        }
    } catch (Throwable $e) {
        setFlash('danger', $e->getMessage());
    }

    header('Location: user-details.php?id=' . $userId);
    exit;
}

// Fetch user record
$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => $userId]);
$user = $userStmt->fetch();

if ($user === false) {
    setFlash('danger', sprintf('User #%d was not found.', $userId));
    header('Location: users.php');
    exit;
}

$defaultFreeLimit = (int) adminGetSetting($pdo, 'default_free_subdomain_limit', '2');
$hasCustomLimit = $user['custom_subdomain_limit'] !== null;
$effectiveLimit = $hasCustomLimit ? (int) $user['custom_subdomain_limit'] : $defaultFreeLimit;

// Fetch all subdomains owned by this user
$subStmt = $pdo->prepare(
    'SELECT
        s.id,
        s.subdomain_name,
        s.full_domain,
        s.status,
        s.ssl_status,
        s.created_at,
        rd.domain_name AS root_domain_name,
        COUNT(dr.id) AS dns_count
     FROM subdomains s
     INNER JOIN root_domains rd ON rd.id = s.root_domain_id
     LEFT JOIN dns_records dr ON dr.subdomain_id = s.id
     WHERE s.user_id = :user_id
     GROUP BY s.id, s.subdomain_name, s.full_domain, s.status, s.ssl_status, s.created_at, rd.domain_name
     ORDER BY s.created_at DESC, s.id DESC'
);
$subStmt->execute([':user_id' => $userId]);
$userSubdomains = $subStmt->fetchAll();

$returnHere = 'user-details.php?id=' . $userId;

renderAdminHeader('User Details #' . $userId, 'users');
?>

<div class="mb-3">
    <a href="users.php" class="btn btn-sm btn-outline-light">
        <i class="bi bi-arrow-left me-1"></i>Back to Users Directory
    </a>
</div>

<div class="row g-4 mb-4">
    <!-- User Profile Overview Card -->
    <div class="col-lg-6">
        <div class="ad-card h-100">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-person-vcard-fill text-info me-2"></i>User Profile</div>
                <div>
                    <?php if ($user['status'] === 'active'): ?>
                        <span class="ad-badge ad-badge-active">Active Account</span>
                    <?php else: ?>
                        <span class="ad-badge ad-badge-suspended">Suspended Account</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ad-card-body">
                <div class="row g-3 small">
                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Internal User ID</div>
                        <div class="font-mono fw-bold fs-6">#<?= (int) $user['id'] ?></div>
                    </div>

                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Clerk User ID</div>
                        <div class="font-mono text-info text-break"><?= e($user['clerk_user_id']) ?></div>
                    </div>

                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Email Address</div>
                        <div class="fw-semibold text-break"><?= e($user['email'] ?: '—') ?></div>
                    </div>

                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Full Name</div>
                        <div class="fw-semibold"><?= e($user['full_name'] ?: '—') ?></div>
                    </div>

                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Current Plan</div>
                        <div>
                            <?php if ($user['plan'] === 'pro'): ?>
                                <span class="ad-badge ad-badge-pro"><i class="bi bi-stars"></i>PRO</span>
                            <?php else: ?>
                                <span class="ad-badge ad-badge-free">FREE</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Registered At</div>
                        <div class="font-mono"><?= e($user['created_at']) ?></div>
                    </div>
                </div>

                <hr class="border-secondary border-opacity-25 my-3">

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-secondary">Effective Subdomain Allowance</div>
                        <div class="fs-5 fw-bold font-mono">
                            <?= count($userSubdomains) ?> / <?= $effectiveLimit ?> used
                            <?php if ($hasCustomLimit): ?>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 ms-1 small">Custom Override</span>
                            <?php else: ?>
                                <span class="small text-secondary fw-normal ms-1">(Global Free Default: <?= $defaultFreeLimit ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($hasCustomLimit): ?>
                        <form method="post" action="user-details.php?id=<?= $userId ?>">
                            <?= csrfInput() ?>
                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                            <input type="hidden" name="action" value="reset_custom_limit">
                            <button type="submit" class="btn btn-sm btn-outline-info">
                                Reset to Default (<?= $defaultFreeLimit ?>)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quota, Plan & Account Status Editor Card -->
    <div class="col-lg-6">
        <div class="ad-card h-100">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-sliders text-info me-2"></i>Plan, Status &amp; Subdomain Limit Editor</div>
            </div>
            <div class="ad-card-body">
                <form method="post" action="user-details.php?id=<?= $userId ?>">
                    <?= csrfInput() ?>
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="action" value="update_user_settings">

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="userPlanSelect">Account Plan</label>
                            <select id="userPlanSelect" name="plan" class="form-select">
                                <option value="free" <?= $user['plan'] === 'free' ? 'selected' : '' ?>>Free</option>
                                <option value="pro" <?= $user['plan'] === 'pro' ? 'selected' : '' ?>>Pro</option>
                            </select>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label" for="userStatusSelect">Account Status</label>
                            <select id="userStatusSelect" name="status" class="form-select">
                                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended (Block API Access)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Subdomain Quota Policy</label>

                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="radio"
                                   name="limit_mode"
                                   id="limitModeInherit"
                                   value="inherit"
                                   <?= !$hasCustomLimit ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="limitModeInherit">
                                Inherit Global Default Limit (currently <strong class="font-mono text-info"><?= $defaultFreeLimit ?></strong>)
                            </label>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="radio"
                                   name="limit_mode"
                                   id="limitModeCustom"
                                   value="custom"
                                   <?= $hasCustomLimit ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="limitModeCustom">
                                Set Custom Per-User Subdomain Limit:
                            </label>
                        </div>

                        <div class="mt-2" style="max-width: 220px;">
                            <input type="number"
                                   id="customLimitInput"
                                   name="custom_subdomain_limit"
                                   class="form-control font-mono"
                                   min="0"
                                   max="10000"
                                   value="<?= e($hasCustomLimit ? (string) $user['custom_subdomain_limit'] : (string) $defaultFreeLimit) ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-ad-primary w-100">
                        <i class="bi bi-check2-circle me-1"></i>Save User Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- User's Owned Subdomains Table -->
<div class="ad-card overflow-hidden">
    <div class="ad-card-header">
        <div class="fw-bold">
            <i class="bi bi-hdd-network text-info me-2"></i>Subdomains Owned by This User (<?= count($userSubdomains) ?>)
        </div>
    </div>

    <?php if ($userSubdomains === []): ?>
        <div class="p-5 text-center text-secondary small">
            This user has not claimed any subdomains yet.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ad-table align-middle">
                <thead>
                    <tr>
                        <th>Full Domain</th>
                        <th>Root Zone</th>
                        <th>DNS Records</th>
                        <th>SSL</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userSubdomains as $sub): ?>
                        <tr>
                            <td class="font-mono fw-bold text-light"><?= e($sub['full_domain']) ?></td>
                            <td class="font-mono small"><?= e($sub['root_domain_name']) ?></td>
                            <td>
                                <a href="subdomains.php?q=<?= urlencode((string) $sub['full_domain']) ?>" class="font-mono text-info text-decoration-none">
                                    <?= (int) $sub['dns_count'] ?> record<?= (int) $sub['dns_count'] === 1 ? '' : 's' ?>
                                </a>
                            </td>
                            <td>
                                <span class="ad-badge <?= $sub['ssl_status'] === 'active' ? 'ad-badge-active' : 'ad-badge-free' ?>">
                                    <?= e(strtoupper((string) $sub['ssl_status'])) ?>
                                </span>
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
                                <div class="d-inline-flex gap-1">
                                    <form method="post" action="subdomains.php" class="d-inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="subdomain_id" value="<?= (int) $sub['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e($returnHere) ?>">
                                        <?php if ($sub['status'] === 'active'): ?>
                                            <input type="hidden" name="target_status" value="suspended">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-pause-circle"></i> Suspend
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="target_status" value="active">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-play-circle"></i> Unsuspend
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="post"
                                          action="subdomains.php"
                                          class="d-inline"
                                          onsubmit="return confirm('Delete subdomain <?= e(addslashes((string) $sub['full_domain'])) ?> and purge its Cloudflare DNS records?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="force_delete">
                                        <input type="hidden" name="subdomain_id" value="<?= (int) $sub['id'] ?>">
                                        <input type="hidden" name="return_to" value="<?= e($returnHere) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete subdomain">
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

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const inheritRadio = document.getElementById('limitModeInherit');
        const customRadio = document.getElementById('limitModeCustom');
        const customInput = document.getElementById('customLimitInput');

        function syncDisabled() {
            if (customInput && customRadio) {
                customInput.disabled = !customRadio.checked;
            }
        }
        if (inheritRadio) inheritRadio.addEventListener('change', syncDisabled);
        if (customRadio) customRadio.addEventListener('change', syncDisabled);
        syncDisabled();
    });
</script>

<?php
renderAdminFooter();
