<?php

declare(strict_types=1);

/**
 * Global Platform Settings (`/admin-desktop/settings.php`)
 *
 * Manages:
 * - `default_free_subdomain_limit` (default subdomains granted to free users without custom overrides)
 * - `max_dns_records_per_subdomain` (maximum DNS records allowed per subdomain)
 * - `custom_reserved_subdomains` (additional blocked subdomain labels alongside built-in system reserved names)
 */

use FreeSub\Core\Database;
use FreeSub\Core\Validator;

require_once __DIR__ . '/_auth.php';

requireAdminAuth();

$pdo = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_quotas') {
            $rawFreeLimit = trim((string) ($_POST['default_free_subdomain_limit'] ?? ''));
            $rawMaxDns = trim((string) ($_POST['max_dns_records_per_subdomain'] ?? ''));

            if ($rawFreeLimit === '' || filter_var($rawFreeLimit, FILTER_VALIDATE_INT) === false || (int) $rawFreeLimit < 0 || (int) $rawFreeLimit > 1000) {
                throw new RuntimeException('Default Free Subdomain Limit must be an integer between 0 and 1000.');
            }

            if ($rawMaxDns === '' || filter_var($rawMaxDns, FILTER_VALIDATE_INT) === false || (int) $rawMaxDns < 1 || (int) $rawMaxDns > 500) {
                throw new RuntimeException('Max DNS Records per Subdomain must be an integer between 1 and 500.');
            }

            adminSaveSetting($pdo, 'default_free_subdomain_limit', (string) ((int) $rawFreeLimit));
            adminSaveSetting($pdo, 'max_dns_records_per_subdomain', (string) ((int) $rawMaxDns));

            setFlash('success', 'Global quota settings saved successfully.');
        } elseif ($action === 'save_reserved') {
            $rawReserved = strtolower((string) ($_POST['custom_reserved_subdomains'] ?? ''));
            $tokens = preg_split('/[\s,]+/', $rawReserved) ?: [];
            $cleanTokens = [];

            foreach ($tokens as $tok) {
                $t = trim($tok);
                if ($t === '') {
                    continue;
                }
                if (!preg_match('/^[a-z0-9-]{1,63}$/', $t)) {
                    throw new RuntimeException(sprintf('Invalid reserved label "%s". Use lowercase alphanumeric characters and hyphens only.', $t));
                }
                // Skip built-in reserved names so we don't duplicate them unnecessarily
                if (!in_array($t, Validator::RESERVED_SUBDOMAINS, true) && !in_array($t, $cleanTokens, true)) {
                    $cleanTokens[] = $t;
                }
            }

            sort($cleanTokens);
            adminSaveSetting($pdo, 'custom_reserved_subdomains', implode(', ', $cleanTokens));

            setFlash('success', sprintf('Saved %d custom reserved subdomain label(s).', count($cleanTokens)));
        }
    } catch (Throwable $e) {
        setFlash('danger', $e->getMessage());
    }

    header('Location: settings.php');
    exit;
}

$defaultFreeLimit = adminGetSetting($pdo, 'default_free_subdomain_limit', '2');
$maxDnsPerSub = adminGetSetting($pdo, 'max_dns_records_per_subdomain', '10');
$customReservedRaw = adminGetSetting($pdo, 'custom_reserved_subdomains', '');
$customReservedList = array_values(array_filter(array_map(
    static fn(string $item): string => trim($item),
    preg_split('/[\s,]+/', $customReservedRaw) ?: []
)));

renderAdminHeader('Global Settings', 'settings');
?>

<div class="row g-4">
    <!-- Left Column: Global Quotas & Limits -->
    <div class="col-lg-6">
        <div class="ad-card h-100">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-sliders text-info me-2"></i>Platform Quotas &amp; Limits</div>
            </div>
            <div class="ad-card-body">
                <form method="post" action="settings.php">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="save_quotas">

                    <div class="mb-4">
                        <label class="form-label" for="defaultFreeLimitInput">Default Free Subdomain Limit</label>
                        <input type="number"
                               id="defaultFreeLimitInput"
                               name="default_free_subdomain_limit"
                               class="form-control font-mono"
                               min="0"
                               max="1000"
                               value="<?= e($defaultFreeLimit) ?>"
                               required>
                        <div class="form-text text-secondary small mt-1">
                            Maximum subdomains each user can claim unless overridden by a per-user <code>custom_subdomain_limit</code> on their profile (e.g. <code>2</code>, <code>3</code>, or <code>5</code>).
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="maxDnsRecordsInput">Max DNS Records per Subdomain</label>
                        <input type="number"
                               id="maxDnsRecordsInput"
                               name="max_dns_records_per_subdomain"
                               class="form-control font-mono"
                               min="1"
                               max="500"
                               value="<?= e($maxDnsPerSub) ?>"
                               required>
                        <div class="form-text text-secondary small mt-1">
                            Caps how many Cloudflare DNS records (`A`, `AAAA`, `CNAME`, `MX`, `TXT`) a user can create under a single subdomain.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-ad-primary w-100">
                        <i class="bi bi-check2-circle me-1"></i>Update Global Quotas
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Reserved Subdomain Labels -->
    <div class="col-lg-6">
        <div class="ad-card h-100">
            <div class="ad-card-header">
                <div class="fw-bold"><i class="bi bi-shield-slash-fill text-warning me-2"></i>Reserved Subdomain Names</div>
            </div>
            <div class="ad-card-body">
                <div class="mb-3">
                    <div class="form-label mb-2">Core System Reserved Names (Always Blocked)</div>
                    <div class="d-flex flex-wrap gap-1 p-3 rounded-3" style="background: rgba(8,13,25,0.65); border: 1px solid var(--ad-border);">
                        <?php foreach (Validator::RESERVED_SUBDOMAINS as $reserved): ?>
                            <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 font-mono">
                                <?= e($reserved) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <form method="post" action="settings.php">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="save_reserved">

                    <div class="mb-3">
                        <label class="form-label" for="customReservedTextarea">Additional Custom Reserved Names</label>
                        <textarea id="customReservedTextarea"
                                  name="custom_reserved_subdomains"
                                  rows="4"
                                  class="form-control font-mono"
                                  placeholder="blog, docs, staging, portal, smtp"><?= e(implode(', ', $customReservedList)) ?></textarea>
                        <div class="form-text text-secondary small mt-1">
                            Separate labels with commas or newlines. Both availability checks and subdomain creation APIs enforce this list immediately.
                        </div>
                    </div>

                    <?php if ($customReservedList !== []): ?>
                        <div class="mb-3">
                            <div class="small text-secondary mb-1">Active Custom Reserved Labels (<?= count($customReservedList) ?>):</div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($customReservedList as $cRes): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 font-mono">
                                        <?= e($cRes) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-ad-primary w-100">
                        <i class="bi bi-save me-1"></i>Save Custom Reserved Names
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
renderAdminFooter();
