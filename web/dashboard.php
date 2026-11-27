<?php

declare(strict_types=1);

require_once __DIR__ . '/_paths.php';
require_once $coreDirectory . '/config.php';

use FreeSub\Core\Config;

$appName = htmlspecialchars(Config::get('APP_NAME', 'FreeSub') ?? 'FreeSub', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= $appName ?></title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 & Bootstrap Icons CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- FreeSub Styles -->
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body>

    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg fs-navbar sticky-top">
        <div class="container">
            <a class="fs-brand" href="index.php">
                <span class="fs-brand-mark"><i class="bi bi-globe2"></i></span>
                <span><?= $appName ?></span>
            </a>

            <div class="d-flex align-items-center gap-3 ms-auto">
                <a href="index.php" class="fs-nav-link text-decoration-none d-none d-sm-inline">Home</a>
                <div id="fsAuthControls"></div>
            </div>
        </div>
    </nav>

    <main class="py-4 py-md-5">
        <div class="container">
            <!-- Initial Loading / Sign-In Gate Container -->
            <div id="dashboardGate">
                <div class="text-center py-5">
                    <div class="spinner-border text-info mb-3" role="status"></div>
                    <div class="text-secondary small">Authenticating your session...</div>
                </div>
            </div>

            <!-- Authenticated Workspace (hidden until verified) -->
            <div id="dashboardWorkspace" class="d-none">
                <!-- Header Row -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                    <div>
                        <h2 class="fw-bold mb-1">My Subdomains</h2>
                        <p class="text-secondary small mb-0" id="welcomeUserText">
                            Manage your active subdomains, Cloudflare Anycast DNS records, and SSL settings.
                        </p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-fs-primary" id="btnOpenCreateModal" data-bs-toggle="modal" data-bs-target="#createSubdomainModal">
                            <i class="bi bi-plus-lg me-1"></i>Claim New Subdomain
                        </button>
                    </div>
                </div>

                <!-- Summary Metrics & Quota Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-5">
                        <div class="fs-card p-3 p-md-4 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-secondary small text-uppercase fw-semibold">Subdomain Quota</span>
                                <span class="font-mono fw-bold text-info" id="quotaCounterBadge">0 / 2</span>
                            </div>
                            <div class="fs-progress mb-2">
                                <div class="fs-progress-bar" id="quotaProgressBar" style="width: 0%;"></div>
                            </div>
                            <div class="small text-secondary" id="quotaHelperText">
                                Loading quota...
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-md-3">
                        <div class="fs-card p-3 p-md-4 h-100 d-flex flex-column justify-content-center">
                            <div class="text-secondary small text-uppercase fw-semibold mb-1">Active Plan</div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fs-4 fw-bold text-capitalize" id="userPlanLabel">Free</span>
                                <span class="fs-badge fs-badge-active">Active</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-md-4">
                        <div class="fs-card p-3 p-md-4 h-100 d-flex flex-column justify-content-center">
                            <div class="text-secondary small text-uppercase fw-semibold mb-1">DNS Record Allowance</div>
                            <div class="fs-5 fw-bold font-mono">
                                Up to <span class="text-info" id="maxDnsPerSubLabel">10</span> / subdomain
                            </div>
                            <div class="text-secondary small">A, AAAA, CNAME, MX &amp; TXT</div>
                        </div>
                    </div>
                </div>

                <!-- Subdomains Table Card -->
                <div class="fs-card overflow-hidden">
                    <div class="fs-card-header">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-hdd-network text-info"></i>
                            <span class="fw-bold">Provisioned Subdomains</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-fs-outline" id="btnRefreshSubdomains" title="Refresh list">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                    </div>

                    <div id="subdomainsTableContainer">
                        <div class="text-center py-5">
                            <div class="spinner-border spinner-border-sm text-info me-2"></div>
                            <span class="text-secondary small">Loading your subdomains...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Subdomain Modal -->
    <div class="modal fade" id="createSubdomainModal" tabindex="-1" aria-labelledby="createSubdomainModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fs-modal">
                <form id="createSubdomainForm" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="createSubdomainModalLabel">
                            <i class="bi bi-plus-circle text-info me-2"></i>Claim a Free Subdomain
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="modalRootDomain" class="form-label">Root Domain</label>
                            <select id="modalRootDomain" class="form-select font-mono" required>
                                <option value="">Loading domains...</option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label for="modalSubdomainInput" class="form-label">Subdomain Label</label>
                            <div class="input-group">
                                <input type="text"
                                       id="modalSubdomainInput"
                                       class="form-control font-mono"
                                       placeholder="my-awesome-app"
                                       minlength="3"
                                       maxlength="63"
                                       autocomplete="off"
                                       spellcheck="false"
                                       required>
                                <span class="input-group-text" id="modalDomainSuffix">.domain.com</span>
                            </div>
                        </div>

                        <div class="small text-secondary mb-3">
                            Allowed: lowercase <span class="font-mono">a-z</span>, <span class="font-mono">0-9</span>, and hyphens (<span class="font-mono">3–63</span> chars).
                        </div>

                        <!-- Live Availability Status inside Modal -->
                        <div id="modalAvailabilityStatus" class="d-none"></div>

                        <div class="fs-callout small text-secondary mt-3">
                            <i class="bi bi-lightning-charge text-info me-1"></i>
                            FreeSub automatically provisions an initial Cloudflare <span class="font-mono text-light">A</span> record so your subdomain is active immediately. You can customize all DNS records on the next screen.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-fs-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-fs-primary" id="btnSubmitCreateSubdomain">
                            <i class="bi bi-check2-circle me-1"></i>Create Subdomain
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSubdomainModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fs-modal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Subdomain
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-2">
                        Are you sure you want to permanently delete <strong class="font-mono text-light" id="deleteTargetDomainName"></strong>?
                    </p>
                    <div class="p-3 rounded-3 small text-danger" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3);">
                        This action immediately removes all associated Cloudflare DNS records and releases the subdomain back to the public pool.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-fs-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger fw-semibold" id="btnConfirmDeleteSubdomain">
                        <i class="bi bi-trash3 me-1"></i>Delete Permanently
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="fs-footer mt-auto">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <div><span class="fw-semibold text-light"><?= $appName ?></span> Control Panel</div>
            <div><a href="index.php" class="text-secondary text-decoration-none">Landing Page</a></div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/app.js"></script>
    <script>
        (function () {
            'use strict';

            const {
                requireSignInOrPrompt,
                renderAuthControls,
                loadBootstrapConfig,
                apiRequest,
                escapeHtml,
                formatDate,
                showToast,
                copyToClipboard,
            } = window.FreeSub;

            const gateEl = document.getElementById('dashboardGate');
            const workspaceEl = document.getElementById('dashboardWorkspace');
            const tableContainer = document.getElementById('subdomainsTableContainer');
            const btnOpenCreateModal = document.getElementById('btnOpenCreateModal');
            const btnRefresh = document.getElementById('btnRefreshSubdomains');

            // Create Modal Elements
            const createModalEl = document.getElementById('createSubdomainModal');
            const createForm = document.getElementById('createSubdomainForm');
            const modalRootSelect = document.getElementById('modalRootDomain');
            const modalSubInput = document.getElementById('modalSubdomainInput');
            const modalSuffix = document.getElementById('modalDomainSuffix');
            const modalStatus = document.getElementById('modalAvailabilityStatus');
            const btnSubmitCreate = document.getElementById('btnSubmitCreateSubdomain');

            // Delete Modal Elements
            const deleteModalEl = document.getElementById('deleteSubdomainModal');
            const deleteDomainLabel = document.getElementById('deleteTargetDomainName');
            const btnConfirmDelete = document.getElementById('btnConfirmDeleteSubdomain');

            let createModalInstance = null;
            let deleteModalInstance = null;
            let pendingDeleteId = null;
            let checkTimer = null;

            function populateModalDomains(domains) {
                if (!Array.isArray(domains) || domains.length === 0) {
                    modalRootSelect.innerHTML = `<option value="" data-domain="">No active root domains available</option>`;
                    modalSuffix.textContent = '.domain';
                    return;
                }

                modalRootSelect.innerHTML = domains
                    .map(
                        (d) =>
                            `<option value="${Number(d.id)}" data-domain="${escapeHtml(d.domain_name)}">${escapeHtml(d.domain_name)}${d.description ? ' — ' + escapeHtml(d.description) : ''}</option>`
                    )
                    .join('');
                syncSuffixText();
            }

            function syncSuffixText() {
                const opt = modalRootSelect.options[modalRootSelect.selectedIndex];
                const dName = opt && opt.dataset.domain ? opt.dataset.domain : 'domain';
                modalSuffix.textContent = `.${dName}`;
            }

            function renderQuotaSummary(usedCount, maxAllowed, maxDnsRecords) {
                const used = Number(usedCount || 0);
                const max = Number(maxAllowed || 0);
                const pct = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 100;

                document.getElementById('quotaCounterBadge').textContent = `${used} / ${max}`;
                document.getElementById('quotaProgressBar').style.width = `${pct}%`;
                document.getElementById('maxDnsPerSubLabel').textContent = String(maxDnsRecords || 10);

                const remaining = Math.max(0, max - used);
                const helperEl = document.getElementById('quotaHelperText');
                if (remaining === 0) {
                    helperEl.innerHTML = `<span class="text-warning"><i class="bi bi-info-circle me-1"></i>Quota full. Delete an unused subdomain to claim another.</span>`;
                    btnOpenCreateModal.disabled = true;
                } else {
                    helperEl.textContent = `You can claim ${remaining} more subdomain${remaining === 1 ? '' : 's'} on your account.`;
                    btnOpenCreateModal.disabled = false;
                }
            }

            function renderSubdomainsList(subdomains) {
                if (!Array.isArray(subdomains) || subdomains.length === 0) {
                    tableContainer.innerHTML = `
                        <div class="text-center py-5 px-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                                 style="width:56px;height:56px;background:rgba(56,189,248,0.1);border:1px solid rgba(56,189,248,0.22);">
                                <i class="bi bi-globe-americas fs-4 text-info"></i>
                            </div>
                            <h5 class="fw-bold mb-1">No Subdomains Claimed Yet</h5>
                            <p class="text-secondary small mb-3">
                                Claim your first free subdomain to start configuring Cloudflare DNS &amp; SSL records.
                            </p>
                            <button type="button" class="btn btn-sm btn-fs-primary px-3" id="emptyStateCreateBtn">
                                <i class="bi bi-plus-lg me-1"></i>Claim Your First Subdomain
                            </button>
                        </div>
                    `;
                    const emptyBtn = document.getElementById('emptyStateCreateBtn');
                    if (emptyBtn && createModalInstance) {
                        emptyBtn.addEventListener('click', () => createModalInstance.show());
                    }
                    return;
                }

                const rowsHtml = subdomains
                    .map((sub) => {
                        const isSuspended = sub.status === 'suspended';
                        const statusBadge = isSuspended
                            ? `<span class="fs-badge fs-badge-suspended"><i class="bi bi-slash-circle"></i>Suspended</span>`
                            : `<span class="fs-badge fs-badge-active"><i class="bi bi-check-circle-fill"></i>Active</span>`;

                        const sslBadge = sub.ssl_status === 'active'
                            ? `<span class="fs-badge fs-badge-active"><i class="bi bi-lock-fill"></i>SSL Active</span>`
                            : `<span class="fs-badge fs-badge-pending"><i class="bi bi-hourglass-split"></i>SSL Pending</span>`;

                        return `
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <a href="manage.php?id=${Number(sub.id)}" class="fw-bold font-mono text-light text-decoration-none">
                                            ${escapeHtml(sub.full_domain)}
                                        </a>
                                        <button type="button"
                                                class="btn btn-fs-outline fs-copy-btn js-copy-domain"
                                                data-domain="${escapeHtml(sub.full_domain)}"
                                                title="Copy domain">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                        <a href="https://${escapeHtml(sub.full_domain)}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-secondary small"
                                           title="Open in new tab">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                    <div class="small text-secondary">Root zone: <span class="font-mono">${escapeHtml(sub.root_domain_name)}</span></div>
                                </td>
                                <td>${statusBadge}</td>
                                <td>${sslBadge}</td>
                                <td>
                                    <span class="font-mono fw-semibold">${Number(sub.dns_record_count || 0)}</span>
                                    <span class="text-secondary small">records</span>
                                </td>
                                <td class="text-secondary small">${escapeHtml(formatDate(sub.created_at))}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <a href="manage.php?id=${Number(sub.id)}" class="btn btn-sm btn-fs-primary px-3">
                                            <i class="bi bi-sliders me-1"></i>Manage
                                        </a>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger js-delete-subdomain"
                                                data-id="${Number(sub.id)}"
                                                data-domain="${escapeHtml(sub.full_domain)}"
                                                title="Delete subdomain">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    })
                    .join('');

                tableContainer.innerHTML = `
                    <div class="table-responsive">
                        <table class="table fs-table align-middle">
                            <thead>
                                <tr>
                                    <th>Subdomain</th>
                                    <th>Status</th>
                                    <th>SSL / Edge</th>
                                    <th>DNS Records</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>
                `;

                tableContainer.querySelectorAll('.js-copy-domain').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        copyToClipboard(btn.dataset.domain || '', 'Copied domain');
                    });
                });

                tableContainer.querySelectorAll('.js-delete-subdomain').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        pendingDeleteId = Number(btn.dataset.id);
                        deleteDomainLabel.textContent = btn.dataset.domain || '';
                        deleteModalInstance.show();
                    });
                });
            }

            async function loadDashboardData() {
                try {
                    const [bootRes, subRes] = await Promise.all([
                        apiRequest('bootstrap-config.php'),
                        apiRequest('subdomains.php'),
                    ]);

                    if (bootRes && bootRes.user) {
                        const u = bootRes.user;
                        const displayIdentity = u.full_name || u.email || 'Developer';
                        document.getElementById('welcomeUserText').textContent =
                            `Signed in as ${displayIdentity}. Manage your subdomains and Cloudflare DNS below.`;
                        document.getElementById('userPlanLabel').textContent = u.plan || 'free';
                    }

                    populateModalDomains((bootRes && bootRes.root_domains) || []);
                    renderQuotaSummary(
                        subRes.used_count,
                        subRes.max_allowed,
                        subRes.max_dns_records_per_subdomain
                    );
                    renderSubdomainsList(subRes.subdomains || []);
                } catch (err) {
                    showToast(err.message || 'Failed to load subdomains.', 'danger');
                    tableContainer.innerHTML = `
                        <div class="p-4 text-center text-danger small">
                            <i class="bi bi-exclamation-octagon me-2"></i>${escapeHtml(err.message)}
                        </div>
                    `;
                }
            }

            async function checkModalAvailability() {
                const label = (modalSubInput.value || '').trim().toLowerCase();
                const rootId = modalRootSelect.value;

                if (!label || !rootId) {
                    modalStatus.classList.add('d-none');
                    return;
                }

                try {
                    const qs = new URLSearchParams({
                        subdomain: label,
                        root_domain_id: rootId,
                    });
                    const res = await apiRequest(`check-availability.php?${qs.toString()}`, {
                        method: 'GET',
                        skipAuth: true,
                    });

                    modalStatus.classList.remove('d-none');
                    if (res.available) {
                        modalStatus.innerHTML = `
                            <div class="p-2 px-3 rounded-3 small text-success"
                                 style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3);">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                <strong class="font-mono">${escapeHtml(res.full_domain)}</strong> is available!
                            </div>
                        `;
                    } else {
                        modalStatus.innerHTML = `
                            <div class="p-2 px-3 rounded-3 small text-danger"
                                 style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3);">
                                <i class="bi bi-x-circle-fill me-1"></i>
                                ${escapeHtml(res.reason || 'Subdomain is unavailable.')}
                            </div>
                        `;
                    }
                } catch (err) {
                    modalStatus.classList.remove('d-none');
                    modalStatus.innerHTML = `<div class="small text-warning">${escapeHtml(err.message)}</div>`;
                }
            }

            modalRootSelect.addEventListener('change', () => {
                syncSuffixText();
                if (modalSubInput.value.trim().length >= 3) {
                    checkModalAvailability();
                }
            });

            modalSubInput.addEventListener('input', () => {
                const pos = modalSubInput.selectionStart;
                const lowered = modalSubInput.value.toLowerCase();
                if (modalSubInput.value !== lowered) {
                    modalSubInput.value = lowered;
                    modalSubInput.setSelectionRange(pos, pos);
                }

                clearTimeout(checkTimer);
                if (modalSubInput.value.trim().length >= 3) {
                    checkTimer = setTimeout(checkModalAvailability, 350);
                } else {
                    modalStatus.classList.add('d-none');
                }
            });

            createForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const subdomain = (modalSubInput.value || '').trim().toLowerCase();
                const rootDomainId = Number(modalRootSelect.value);

                if (!subdomain || !rootDomainId) {
                    showToast('Please select a root domain and enter a subdomain label.', 'warning');
                    return;
                }

                btnSubmitCreate.disabled = true;
                btnSubmitCreate.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Provisioning...`;

                try {
                    const res = await apiRequest('subdomains.php', {
                        method: 'POST',
                        body: {
                            subdomain,
                            root_domain_id: rootDomainId,
                        },
                    });

                    showToast(res.message || 'Subdomain created!', 'success');
                    createModalInstance.hide();
                    modalSubInput.value = '';
                    modalStatus.classList.add('d-none');

                    if (res.subdomain && res.subdomain.id) {
                        // Redirect directly into the management panel for smooth onboarding, or reload list
                        window.location.href = `manage.php?id=${Number(res.subdomain.id)}`;
                        return;
                    }

                    await loadDashboardData();
                } catch (err) {
                    showToast(err.message || 'Could not create subdomain.', 'danger');
                } finally {
                    btnSubmitCreate.disabled = false;
                    btnSubmitCreate.innerHTML = `<i class="bi bi-check2-circle me-1"></i>Create Subdomain`;
                }
            });

            btnConfirmDelete.addEventListener('click', async () => {
                if (!pendingDeleteId) {
                    return;
                }

                btnConfirmDelete.disabled = true;
                btnConfirmDelete.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Deleting...`;

                try {
                    const res = await apiRequest(`subdomains.php?id=${encodeURIComponent(pendingDeleteId)}`, {
                        method: 'DELETE',
                    });
                    showToast(res.message || 'Subdomain deleted.', 'success');
                    deleteModalInstance.hide();
                    pendingDeleteId = null;
                    await loadDashboardData();
                } catch (err) {
                    showToast(err.message || 'Failed to delete subdomain.', 'danger');
                } finally {
                    btnConfirmDelete.disabled = false;
                    btnConfirmDelete.innerHTML = `<i class="bi bi-trash3 me-1"></i>Delete Permanently`;
                }
            });

            btnRefresh.addEventListener('click', () => loadDashboardData());

            // Boot Dashboard
            (async function initDashboard() {
                createModalInstance = new window.bootstrap.Modal(createModalEl);
                deleteModalInstance = new window.bootstrap.Modal(deleteModalEl);

                await loadBootstrapConfig();
                await renderAuthControls('#fsAuthControls');

                const signedIn = await requireSignInOrPrompt({
                    gateContainer: gateEl,
                    autoPrompt: true,
                });

                if (!signedIn) {
                    return;
                }

                gateEl.classList.add('d-none');
                workspaceEl.classList.remove('d-none');

                await loadDashboardData();

                // Pre-fill modal if redirected from Landing Page availability checker (`?claim=...&root_domain_id=...`)
                const params = new URLSearchParams(window.location.search);
                const claimLabel = (params.get('claim') || '').trim().toLowerCase();
                const claimRootId = params.get('root_domain_id') || '';

                if (claimLabel) {
                    if (claimRootId && modalRootSelect.querySelector(`option[value="${CSS.escape(claimRootId)}"]`)) {
                        modalRootSelect.value = claimRootId;
                        syncSuffixText();
                    }
                    modalSubInput.value = claimLabel;
                    createModalInstance.show();
                    checkModalAvailability();

                    // Clean query params from address bar without reloading
                    window.history.replaceState({}, document.title, 'dashboard.php');
                }
            })();
        })();
    </script>
</body>
</html>
