<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/config.php';

use FreeSub\Core\Config;

$appName = htmlspecialchars(Config::get('APP_NAME', 'FreeSub') ?? 'FreeSub', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subdomain — <?= $appName ?></title>

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
                <a href="dashboard.php" class="fs-nav-link text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i>All Subdomains
                </a>
                <div id="fsAuthControls"></div>
            </div>
        </div>
    </nav>

    <main class="py-4 py-md-5">
        <div class="container">
            <!-- Auth / Loading Gate -->
            <div id="manageGate">
                <div class="text-center py-5">
                    <div class="spinner-border text-info mb-3" role="status"></div>
                    <div class="text-secondary small">Loading subdomain management workspace...</div>
                </div>
            </div>

            <!-- Main Subdomain Control Panel Workspace -->
            <div id="manageWorkspace" class="d-none">
                <!-- Breadcrumb & Domain Header Banner -->
                <div class="fs-card p-3 p-md-4 mb-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <div>
                            <nav aria-label="breadcrumb" class="mb-2">
                                <ol class="breadcrumb mb-0 small">
                                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-info text-decoration-none">Subdomains</a></li>
                                    <li class="breadcrumb-item active text-secondary font-mono" aria-current="page" id="breadcrumbDomain">loading...</li>
                                </ol>
                            </nav>

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h2 class="fw-bold font-mono mb-0 fs-3" id="headerFullDomain">—</h2>
                                <button type="button" class="btn btn-fs-outline fs-copy-btn" id="btnHeaderCopyDomain" title="Copy full domain">
                                    <i class="bi bi-copy me-1"></i>Copy
                                </button>
                                <span id="headerStatusBadge"></span>
                                <span id="headerSslBadge"></span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="#" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-fs-outline" id="btnOpenLiveSite">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Visit HTTPS URL
                            </a>
                            <button type="button" class="btn btn-sm btn-fs-outline" id="btnReloadAll">
                                <i class="bi bi-arrow-clockwise me-1"></i>Sync / Refresh
                            </button>
                        </div>
                    </div>

                    <!-- InfinityFree-Style Tab Navigation Bar -->
                    <ul class="nav fs-tabs mt-4" id="manageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-dns-btn" data-bs-toggle="tab" data-bs-target="#pane-dns" type="button" role="tab" aria-controls="pane-dns" aria-selected="true">
                                <i class="bi bi-diagram-3-fill"></i>DNS Records
                                <span class="badge rounded-pill bg-info bg-opacity-25 text-info font-mono ms-1" id="tabDnsCountBadge">0</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-overview-btn" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab" aria-controls="pane-overview" aria-selected="false">
                                <i class="bi bi-sliders"></i>Overview &amp; Properties
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-ssl-btn" data-bs-toggle="tab" data-bs-target="#pane-ssl" type="button" role="tab" aria-controls="pane-ssl" aria-selected="false">
                                <i class="bi bi-shield-lock-fill"></i>SSL / HTTPS Status
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Suspended Subdomain Warning Banner (shown only when status === 'suspended') -->
                <div id="suspendedNoticeBanner" class="alert alert-danger d-none mb-4 shadow-sm" style="background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.38); color: #fca5a5;">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-slash-circle-fill fs-4 text-danger mt-1"></i>
                        <div>
                            <div class="fw-bold text-light mb-1">This Subdomain Is Currently Suspended</div>
                            <div class="small">
                                An administrator has suspended <span class="font-mono fw-semibold text-light" id="suspendedBannerDomain"></span>. Live Cloudflare DNS resolution is paused, and creating, editing, or deleting individual DNS records is disabled while suspended. You may still view your saved records or delete the subdomain from the Overview tab.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content Panes -->
                <div class="tab-content" id="manageTabsContent">

                    <!-- =========================================================
                         TAB 1: DNS RECORDS MANAGER
                         ========================================================= -->
                    <div class="tab-pane fade show active" id="pane-dns" role="tabpanel" aria-labelledby="tab-dns-btn">

                        <!-- Cloudflare Proxy Guidance Banner -->
                        <div class="fs-callout fs-callout-cloudflare mb-4">
                            <div class="d-flex align-items-start gap-3">
                                <i class="bi bi-lightbulb-fill fs-5 mt-1" style="color: #fb923c;"></i>
                                <div class="small">
                                    <div class="fw-bold text-light mb-1">
                                        When should I keep Cloudflare Proxy <span style="color:#fb923c;">OFF (DNS Only)</span>?
                                    </div>
                                    <div class="text-secondary">
                                        Turn <strong>Cloudflare Proxy OFF</strong> when pointing <span class="font-mono text-light">CNAME</span> or <span class="font-mono text-light">A</span> records to managed platforms that issue their own SSL certificates — such as
                                        <strong>Vercel, Netlify, Render, Railway, Fly.io, or GitHub Pages</strong> — or when running Certbot/Caddy HTTP-01 verification on your own VPS. Keep <strong>Proxy ON</strong> when pointing directly to your raw server IP for instant Cloudflare Edge HTTPS &amp; DDoS protection.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Add New DNS Record Card -->
                        <div class="fs-card mb-4">
                            <div class="fs-card-header">
                                <div>
                                    <h5 class="fw-bold mb-0 fs-6">
                                        <i class="bi bi-plus-circle-fill text-info me-2"></i>Add New DNS Record
                                    </h5>
                                </div>
                                <span class="small font-mono text-secondary" id="dnsQuotaBadge">0 / 10 records used</span>
                            </div>
                            <div class="fs-card-body">
                                <form id="addDnsRecordForm" novalidate>
                                    <div class="row g-3 align-items-end">
                                        <!-- Type -->
                                        <div class="col-6 col-md-2">
                                            <label for="addRecordType" class="form-label">Type</label>
                                            <select id="addRecordType" class="form-select font-mono" required>
                                                <option value="A">A</option>
                                                <option value="AAAA">AAAA</option>
                                                <option value="CNAME">CNAME</option>
                                                <option value="MX">MX</option>
                                                <option value="TXT">TXT</option>
                                            </select>
                                        </div>

                                        <!-- Name -->
                                        <div class="col-6 col-md-3">
                                            <label for="addRecordName" class="form-label">Name / Host</label>
                                            <input type="text"
                                                   id="addRecordName"
                                                   class="form-control font-mono"
                                                   value="@"
                                                   placeholder="@ or www"
                                                   autocomplete="off"
                                                   spellcheck="false"
                                                   required>
                                        </div>

                                        <!-- Target / Content -->
                                        <div class="col-12 col-md-4" id="addTargetCol">
                                            <label for="addTargetValue" class="form-label" id="addTargetLabel">IPv4 Address</label>
                                            <input type="text"
                                                   id="addTargetValue"
                                                   class="form-control font-mono"
                                                   placeholder="203.0.113.10"
                                                   autocomplete="off"
                                                   spellcheck="false"
                                                   required>
                                        </div>

                                        <!-- Priority (visible only for MX) -->
                                        <div class="col-6 col-md-2 d-none" id="addPriorityCol">
                                            <label for="addPriorityInput" class="form-label">Priority</label>
                                            <input type="number"
                                                   id="addPriorityInput"
                                                   class="form-control font-mono"
                                                   min="0"
                                                   max="65535"
                                                   value="10"
                                                   placeholder="10">
                                        </div>

                                        <!-- TTL -->
                                        <div class="col-6 col-md-2">
                                            <label for="addTtlSelect" class="form-label">TTL</label>
                                            <select id="addTtlSelect" class="form-select font-mono">
                                                <option value="1" selected>Auto</option>
                                                <option value="60">1 min</option>
                                                <option value="300">5 min</option>
                                                <option value="1800">30 min</option>
                                                <option value="3600">1 hour</option>
                                                <option value="14400">4 hours</option>
                                                <option value="86400">1 day</option>
                                            </select>
                                        </div>

                                        <!-- Proxy Toggle + Submit Row -->
                                        <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 pt-2 border-top border-secondary border-opacity-10 mt-3">
                                            <div class="d-flex flex-wrap align-items-center gap-3">
                                                <div class="form-check form-switch mb-0" id="addProxyWrapper">
                                                    <input class="form-check-input fs-proxy-switch" type="checkbox" role="switch" id="addProxiedToggle">
                                                    <label class="form-check-label small fw-semibold" for="addProxiedToggle">
                                                        Cloudflare Proxy <span class="text-secondary fw-normal" id="addProxyStateHint">(DNS Only)</span>
                                                    </label>
                                                </div>
                                                <span class="small text-secondary font-mono" id="addResolvedFqdnPreview"></span>
                                            </div>

                                            <button type="submit" class="btn btn-fs-primary px-4" id="btnAddDnsSubmit">
                                                <i class="bi bi-plus-lg me-1"></i>Add Record
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Active DNS Records Table -->
                        <div class="fs-card overflow-hidden">
                            <div class="fs-card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-list-ul text-info"></i>
                                    <span class="fw-bold">Current DNS Zone Records</span>
                                </div>
                                <span class="small text-secondary">Use <code class="text-info">@</code> for apex of your subdomain</span>
                            </div>

                            <div id="dnsRecordsTableContainer">
                                <div class="text-center py-5">
                                    <div class="spinner-border spinner-border-sm text-info me-2"></div>
                                    <span class="text-secondary small">Loading DNS records...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =========================================================
                         TAB 2: OVERVIEW & PROPERTIES + DANGER ZONE
                         ========================================================= -->
                    <div class="tab-pane fade" id="pane-overview" role="tabpanel" aria-labelledby="tab-overview-btn">
                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="fs-card h-100">
                                    <div class="fs-card-header">
                                        <h5 class="fw-bold mb-0 fs-6">
                                            <i class="bi bi-info-circle-fill text-info me-2"></i>Subdomain Properties
                                        </h5>
                                    </div>
                                    <div class="fs-card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="fs-prop-item">
                                                    <div class="fs-prop-label">Full Subdomain (FQDN)</div>
                                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                                        <span class="fs-prop-value font-mono" id="propFullDomain">—</span>
                                                        <button type="button" class="btn btn-fs-outline fs-copy-btn" id="btnPropCopyFqdn">
                                                            <i class="bi bi-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="fs-prop-item">
                                                    <div class="fs-prop-label">Parent Root Domain</div>
                                                    <div class="fs-prop-value font-mono" id="propRootDomain">—</div>
                                                </div>
                                            </div>

                                            <div class="col-sm-6 col-md-4">
                                                <div class="fs-prop-item">
                                                    <div class="fs-prop-label">Operational Status</div>
                                                    <div class="fs-prop-value text-capitalize" id="propStatus">—</div>
                                                </div>
                                            </div>

                                            <div class="col-sm-6 col-md-4">
                                                <div class="fs-prop-item">
                                                    <div class="fs-prop-label">Created Date</div>
                                                    <div class="fs-prop-value" id="propCreatedDate">—</div>
                                                </div>
                                            </div>

                                            <div class="col-sm-12 col-md-4">
                                                <div class="fs-prop-item">
                                                    <div class="fs-prop-label">DNS Record Quota</div>
                                                    <div class="fs-prop-value font-mono" id="propDnsUsage">0 / 10</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4">
                                            <div class="d-flex justify-content-between small text-secondary mb-1">
                                                <span>DNS Record Capacity</span>
                                                <span class="font-mono" id="propDnsPctLabel">0%</span>
                                            </div>
                                            <div class="fs-progress">
                                                <div class="fs-progress-bar" id="propDnsProgressBar" style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Danger Zone -->
                            <div class="col-lg-4">
                                <div class="fs-card fs-danger-card h-100">
                                    <div class="fs-card-header">
                                        <h5 class="fw-bold mb-0 fs-6 text-danger">
                                            <i class="bi bi-exclamation-octagon-fill me-2"></i>Danger Zone
                                        </h5>
                                    </div>
                                    <div class="fs-card-body d-flex flex-column justify-content-between">
                                        <p class="small text-secondary mb-4">
                                            Deleting this subdomain immediately purges every associated DNS record from Cloudflare's edge network and frees the name so anyone else can register it.
                                        </p>
                                        <button type="button" class="btn btn-outline-danger w-100 fw-semibold" id="btnOpenDangerDeleteModal">
                                            <i class="bi bi-trash3 me-1"></i>Delete This Subdomain
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =========================================================
                         TAB 3: SSL / HTTPS STATUS PANEL
                         ========================================================= -->
                    <div class="tab-pane fade" id="pane-ssl" role="tabpanel" aria-labelledby="tab-ssl-btn">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="fs-card h-100">
                                    <div class="fs-card-header">
                                        <h5 class="fw-bold mb-0 fs-6">
                                            <i class="bi bi-lock-fill text-success me-2"></i>Edge SSL / TLS Status
                                        </h5>
                                        <span id="sslPanelBadge"></span>
                                    </div>
                                    <div class="fs-card-body">
                                        <div class="fs-prop-item mb-3">
                                            <div class="fs-prop-label">Protected Hostname</div>
                                            <div class="fs-prop-value font-mono" id="sslHostnameLabel">—</div>
                                        </div>

                                        <p class="small text-secondary mb-3" id="sslStatusDescription">
                                            Cloudflare Universal SSL covers apex subdomains automatically when proxying is enabled, while DNS-only records delegate TLS termination directly to your hosting provider or origin server.
                                        </p>

                                        <div class="p-3 rounded-3 small" style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25);">
                                            <div class="fw-semibold text-success mb-1">
                                                <i class="bi bi-check2-all me-1"></i>HTTPS Best Practice
                                            </div>
                                            <div class="text-secondary">
                                                If your origin server already has a valid SSL certificate (e.g., Let's Encrypt / Caddy / Nginx), both Proxied and DNS-Only modes work seamlessly without `ERR_TOO_MANY_REDIRECTS`.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="fs-card h-100">
                                    <div class="fs-card-header">
                                        <h5 class="fw-bold mb-0 fs-6">
                                            <i class="bi bi-journal-code text-info me-2"></i>Platform &amp; Origin SSL Guide
                                        </h5>
                                    </div>
                                    <div class="fs-card-body">
                                        <div class="d-flex flex-column gap-3 small">
                                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--fs-border);">
                                                <div class="fw-bold text-light mb-1">1. Vercel / Netlify / Render / GitHub Pages</div>
                                                <div class="text-secondary">
                                                    Create a <span class="font-mono text-info">CNAME</span> record (<span class="font-mono">@</span>) pointing to their target hostname with <strong>Cloudflare Proxy OFF</strong>. Add your full subdomain in their Custom Domains settings so their automated Let's Encrypt HTTP-01 check succeeds.
                                                </div>
                                            </div>

                                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--fs-border);">
                                                <div class="fw-bold text-light mb-1">2. Custom VPS (Nginx / Apache / Docker)</div>
                                                <div class="text-secondary">
                                                    Create an <span class="font-mono text-info">A</span> / <span class="font-mono text-info">AAAA</span> record to your server IP. Keep <strong>Cloudflare Proxy ON</strong> for instant edge HTTPS &amp; IP masking, or use a <span class="font-mono text-info">TXT</span> record on <span class="font-mono">_acme-challenge</span> for wildcard DNS-01 certificates.
                                                </div>
                                            </div>

                                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid var(--fs-border);">
                                                <div class="fw-bold text-light mb-1">3. Nested Sub-hosts (e.g., <span class="font-mono">api.sub.domain</span>)</div>
                                                <div class="text-secondary">
                                                    Multi-level sub-hosts deeper than one label under the root zone are not covered by free Cloudflare Universal wildcard certs when Proxied — keep <strong>Proxy OFF</strong> on deep nested hosts and let your origin/PaaS serve TLS directly.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- Edit DNS Record Modal -->
    <div class="modal fade" id="editDnsRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fs-modal">
                <form id="editDnsRecordForm" novalidate>
                    <input type="hidden" id="editRecordId">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-pencil-square text-info me-2"></i>Edit DNS Record
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-sm-4">
                                <label for="editRecordType" class="form-label">Type</label>
                                <select id="editRecordType" class="form-select font-mono" required>
                                    <option value="A">A</option>
                                    <option value="AAAA">AAAA</option>
                                    <option value="CNAME">CNAME</option>
                                    <option value="MX">MX</option>
                                    <option value="TXT">TXT</option>
                                </select>
                            </div>

                            <div class="col-sm-8">
                                <label for="editRecordName" class="form-label">Name / Host</label>
                                <input type="text" id="editRecordName" class="form-control font-mono" required>
                            </div>

                            <div class="col-12">
                                <label for="editTargetValue" class="form-label" id="editTargetLabel">Target / Content</label>
                                <input type="text" id="editTargetValue" class="form-control font-mono" required>
                            </div>

                            <div class="col-sm-6 d-none" id="editPriorityCol">
                                <label for="editPriorityInput" class="form-label">MX Priority</label>
                                <input type="number" id="editPriorityInput" class="form-control font-mono" min="0" max="65535" value="10">
                            </div>

                            <div class="col-sm-6">
                                <label for="editTtlSelect" class="form-label">TTL</label>
                                <select id="editTtlSelect" class="form-select font-mono">
                                    <option value="1">Auto</option>
                                    <option value="60">1 min</option>
                                    <option value="300">5 min</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="14400">4 hours</option>
                                    <option value="86400">1 day</option>
                                </select>
                            </div>

                            <div class="col-12" id="editProxyWrapper">
                                <div class="form-check form-switch">
                                    <input class="form-check-input fs-proxy-switch" type="checkbox" role="switch" id="editProxiedToggle">
                                    <label class="form-check-label small fw-semibold" for="editProxiedToggle">
                                        Cloudflare Proxy (Orange Cloud)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-fs-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-fs-primary" id="btnSaveDnsEdit">
                            <i class="bi bi-check2 me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete DNS Record Modal -->
    <div class="modal fade" id="deleteDnsRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fs-modal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-trash3-fill me-2"></i>Delete DNS Record
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-2">
                        Remove this DNS record from Cloudflare and FreeSub?
                    </p>
                    <div class="p-3 rounded-3 font-mono small" style="background: rgba(10,16,31,0.8); border: 1px solid var(--fs-border);" id="deleteDnsSummaryBox"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-fs-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger fw-semibold" id="btnConfirmDeleteDns">
                        Delete Record
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Danger Zone: Delete Subdomain Modal -->
    <div class="modal fade" id="dangerDeleteSubdomainModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fs-modal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-exclamation-octagon-fill me-2"></i>Delete Subdomain Permanently
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-3">
                        Type <strong class="font-mono text-light" id="confirmSubdomainMatchText"></strong> to confirm permanent deletion of this subdomain and all its DNS records:
                    </p>
                    <input type="text" id="confirmSubdomainInput" class="form-control font-mono" placeholder="Type full subdomain to confirm" autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-fs-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger fw-semibold" id="btnConfirmDangerDeleteSub" disabled>
                        <i class="bi bi-trash3 me-1"></i>Delete Subdomain
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="fs-footer mt-auto">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <div><span class="fw-semibold text-light"><?= $appName ?></span> Subdomain &amp; DNS Console</div>
            <div><a href="dashboard.php" class="text-secondary text-decoration-none">Back to Dashboard</a></div>
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

            const params = new URLSearchParams(window.location.search);
            const subdomainId = Number(params.get('id') || 0);

            const gateEl = document.getElementById('manageGate');
            const workspaceEl = document.getElementById('manageWorkspace');
            const dnsTableContainer = document.getElementById('dnsRecordsTableContainer');

            // Add Form Elements
            const addForm = document.getElementById('addDnsRecordForm');
            const addType = document.getElementById('addRecordType');
            const addName = document.getElementById('addRecordName');
            const addTarget = document.getElementById('addTargetValue');
            const addTargetLabel = document.getElementById('addTargetLabel');
            const addTargetCol = document.getElementById('addTargetCol');
            const addPriorityCol = document.getElementById('addPriorityCol');
            const addPriorityInput = document.getElementById('addPriorityInput');
            const addTtl = document.getElementById('addTtlSelect');
            const addProxyWrapper = document.getElementById('addProxyWrapper');
            const addProxyToggle = document.getElementById('addProxiedToggle');
            const addProxyStateHint = document.getElementById('addProxyStateHint');
            const addFqdnPreview = document.getElementById('addResolvedFqdnPreview');
            const btnAddDnsSubmit = document.getElementById('btnAddDnsSubmit');

            // Edit Modal Elements
            const editModalEl = document.getElementById('editDnsRecordModal');
            const editForm = document.getElementById('editDnsRecordForm');
            const editRecordId = document.getElementById('editRecordId');
            const editType = document.getElementById('editRecordType');
            const editName = document.getElementById('editRecordName');
            const editTarget = document.getElementById('editTargetValue');
            const editTargetLabel = document.getElementById('editTargetLabel');
            const editPriorityCol = document.getElementById('editPriorityCol');
            const editPriorityInput = document.getElementById('editPriorityInput');
            const editTtl = document.getElementById('editTtlSelect');
            const editProxyWrapper = document.getElementById('editProxyWrapper');
            const editProxyToggle = document.getElementById('editProxiedToggle');
            const btnSaveDnsEdit = document.getElementById('btnSaveDnsEdit');

            // Delete DNS Modal
            const deleteDnsModalEl = document.getElementById('deleteDnsRecordModal');
            const deleteDnsSummaryBox = document.getElementById('deleteDnsSummaryBox');
            const btnConfirmDeleteDns = document.getElementById('btnConfirmDeleteDns');

            // Danger Delete Subdomain Modal
            const dangerSubModalEl = document.getElementById('dangerDeleteSubdomainModal');
            const confirmMatchText = document.getElementById('confirmSubdomainMatchText');
            const confirmSubInput = document.getElementById('confirmSubdomainInput');
            const btnConfirmDangerDeleteSub = document.getElementById('btnConfirmDangerDeleteSub');

            let currentSubdomain = null;
            let currentRecords = [];
            let maxRecordsAllowed = 10;
            let pendingDeleteRecordId = null;

            let editModalInstance = null;
            let deleteDnsModalInstance = null;
            let dangerSubModalInstance = null;

            const TARGET_META = {
                A: { label: 'IPv4 Address', placeholder: '203.0.113.10', proxyable: true },
                AAAA: { label: 'IPv6 Address', placeholder: '2001:db8::1', proxyable: true },
                CNAME: { label: 'Target Hostname', placeholder: 'cname.vercel-dns.com', proxyable: true },
                MX: { label: 'Mail Server Hostname', placeholder: 'mx1.improvmx.com', proxyable: false },
                TXT: { label: 'TXT Content / Value', placeholder: 'v=spf1 include:_spf.google.com ~all', proxyable: false },
            };

            function formatTtl(ttl) {
                const n = Number(ttl);
                if (!n || n === 1) return 'Auto';
                if (n < 60) return `${n}s`;
                if (n < 3600) return `${Math.round(n / 60)} min`;
                if (n < 86400) return `${Math.round(n / 3600)} hr`;
                return '1 day';
            }

            /**
             * Convert stored FQDN (`www.app.kharcha.info`) into concise display host (`@` or `www`).
             */
            function toShortHost(recordName, fullDomain) {
                if (!recordName || !fullDomain) return recordName || '@';
                const r = recordName.toLowerCase();
                const f = fullDomain.toLowerCase();
                if (r === f) return '@';
                if (r.endsWith('.' + f)) {
                    return r.slice(0, -(f.length + 1));
                }
                return recordName;
            }

            function syncTypeFormControls(typeSelectEl, targetLabelEl, targetInputEl, priorityColEl, proxyWrapperEl, proxyToggleEl, targetColEl) {
                const t = typeSelectEl.value || 'A';
                const meta = TARGET_META[t] || TARGET_META.A;

                targetLabelEl.textContent = meta.label;
                targetInputEl.placeholder = meta.placeholder;

                if (t === 'MX') {
                    priorityColEl.classList.remove('d-none');
                    if (targetColEl) {
                        targetColEl.className = 'col-12 col-md-3';
                    }
                } else {
                    priorityColEl.classList.add('d-none');
                    if (targetColEl) {
                        targetColEl.className = 'col-12 col-md-5';
                    }
                }

                if (meta.proxyable) {
                    proxyWrapperEl.classList.remove('opacity-50');
                    proxyToggleEl.disabled = false;
                } else {
                    proxyToggleEl.checked = false;
                    proxyToggleEl.disabled = true;
                    proxyWrapperEl.classList.add('opacity-50');
                }
                updateAddProxyHint();
            }

            function updateAddProxyHint() {
                addProxyStateHint.textContent = addProxyToggle.checked
                    ? '(Proxied — Orange Cloud)'
                    : '(DNS Only — Grey Cloud)';
            }

            function updateResolvedNamePreview() {
                if (!currentSubdomain) {
                    addFqdnPreview.textContent = '';
                    return;
                }
                const raw = (addName.value || '').trim().toLowerCase().replace(/\.$/, '');
                const base = currentSubdomain.full_domain;
                let preview = base;
                if (raw && raw !== '@' && raw !== base) {
                    preview = raw.endsWith('.' + base) ? raw : `${raw}.${base}`;
                }
                addFqdnPreview.innerHTML = `Resolves as: <span class="text-info">${escapeHtml(preview)}</span>`;
            }

            function renderHeaderAndOverview() {
                if (!currentSubdomain) return;

                const fqdn = currentSubdomain.full_domain;
                const isSuspended = currentSubdomain.status === 'suspended';
                const isSslActive = currentSubdomain.ssl_status === 'active';

                document.title = `${fqdn} — Manage Subdomain`;
                document.getElementById('breadcrumbDomain').textContent = fqdn;
                document.getElementById('headerFullDomain').textContent = fqdn;

                const liveLink = document.getElementById('btnOpenLiveSite');
                liveLink.href = `https://${encodeURIComponent(fqdn).replace(/%2E/gi, '.')}`;

                document.getElementById('headerStatusBadge').innerHTML = isSuspended
                    ? `<span class="fs-badge fs-badge-suspended"><i class="bi bi-slash-circle"></i>Suspended</span>`
                    : `<span class="fs-badge fs-badge-active"><i class="bi bi-check-circle-fill"></i>Active</span>`;

                const sslBadgeHtml = isSslActive
                    ? `<span class="fs-badge fs-badge-active"><i class="bi bi-lock-fill"></i>SSL Active</span>`
                    : `<span class="fs-badge fs-badge-pending"><i class="bi bi-hourglass-split"></i>SSL Pending</span>`;

                document.getElementById('headerSslBadge').innerHTML = sslBadgeHtml;
                document.getElementById('sslPanelBadge').innerHTML = sslBadgeHtml;
                document.getElementById('sslHostnameLabel').textContent = `https://${fqdn}`;

                // Overview tab properties
                document.getElementById('propFullDomain').textContent = fqdn;
                document.getElementById('propRootDomain').textContent = currentSubdomain.root_domain_name || '—';
                document.getElementById('propStatus').textContent = currentSubdomain.status || 'active';
                document.getElementById('propCreatedDate').textContent = formatDate(currentSubdomain.created_at);

                const used = currentRecords.length;
                const max = maxRecordsAllowed;
                const pct = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 100;

                document.getElementById('tabDnsCountBadge').textContent = String(used);
                document.getElementById('dnsQuotaBadge').textContent = `${used} / ${max} records used`;
                document.getElementById('propDnsUsage').textContent = `${used} / ${max}`;
                document.getElementById('propDnsPctLabel').textContent = `${pct}%`;
                document.getElementById('propDnsProgressBar').style.width = `${pct}%`;

                // Toggle suspended banner & lock Add DNS form inputs when suspended
                const suspendedBanner = document.getElementById('suspendedNoticeBanner');
                const suspendedDomainText = document.getElementById('suspendedBannerDomain');
                if (suspendedBanner) {
                    if (isSuspended) {
                        suspendedBanner.classList.remove('d-none');
                        if (suspendedDomainText) suspendedDomainText.textContent = fqdn;
                    } else {
                        suspendedBanner.classList.add('d-none');
                    }
                }

                [addType, addName, addTarget, addPriorityInput, addTtl].forEach((el) => {
                    if (el) el.disabled = isSuspended;
                });
                if (isSuspended) {
                    addProxyToggle.disabled = true;
                } else {
                    const meta = TARGET_META[addType.value] || TARGET_META.A;
                    addProxyToggle.disabled = !meta.proxyable;
                }

                btnAddDnsSubmit.disabled = isSuspended || used >= max;
                confirmMatchText.textContent = fqdn;
                updateResolvedNamePreview();
            }

            function renderDnsRecordsTable() {
                if (!Array.isArray(currentRecords) || currentRecords.length === 0) {
                    dnsTableContainer.innerHTML = `
                        <div class="text-center py-5 px-3">
                            <i class="bi bi-diagram-2 fs-2 text-secondary d-block mb-2"></i>
                            <div class="fw-semibold mb-1">No DNS Records Found</div>
                            <div class="text-secondary small">Use the form above to add an A, AAAA, CNAME, MX, or TXT record.</div>
                        </div>
                    `;
                    return;
                }

                const baseDomain = currentSubdomain ? currentSubdomain.full_domain : '';
                const isSuspended = Boolean(currentSubdomain && currentSubdomain.status === 'suspended');

                const rowsHtml = currentRecords
                    .map((rec) => {
                        const shortHost = toShortHost(rec.record_name, baseDomain);
                        const proxySupported = ['A', 'AAAA', 'CNAME'].includes(rec.record_type);

                        let proxyBadge = `<span class="text-secondary small">—</span>`;
                        if (proxySupported) {
                            proxyBadge = rec.proxied
                                ? `<span class="fs-badge fs-badge-proxy-on"><i class="bi bi-cloud-fill"></i>Proxied</span>`
                                : `<span class="fs-badge fs-badge-proxy-off"><i class="bi bi-cloud"></i>DNS Only</span>`;
                        }

                        const priorityTag =
                            rec.record_type === 'MX' && rec.priority !== null
                                ? `<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 font-mono me-2">Prio ${Number(rec.priority)}</span>`
                                : '';

                        return `
                            <tr>
                                <td>
                                    <span class="fs-type-pill fs-type-${escapeHtml(rec.record_type)}">${escapeHtml(rec.record_type)}</span>
                                </td>
                                <td>
                                    <div class="font-mono fw-semibold text-light">${escapeHtml(shortHost)}</div>
                                    <div class="small text-secondary font-mono">${escapeHtml(rec.record_name)}</div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        ${priorityTag}
                                        <span class="font-mono small fs-truncate-target" title="${escapeHtml(rec.target_value)}">
                                            ${escapeHtml(rec.target_value)}
                                        </span>
                                        <button type="button"
                                                class="btn btn-fs-outline fs-copy-btn js-copy-target"
                                                data-val="${escapeHtml(rec.target_value)}"
                                                title="Copy target value">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>${proxyBadge}</td>
                                <td class="font-mono small text-secondary">${escapeHtml(formatTtl(rec.ttl))}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <button type="button"
                                                class="btn btn-sm btn-fs-outline js-edit-record"
                                                data-id="${Number(rec.id)}"
                                                ${isSuspended ? 'disabled title="Subdomain is suspended"' : 'title="Edit record"'}>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger js-delete-record"
                                                data-id="${Number(rec.id)}"
                                                ${isSuspended ? 'disabled title="Subdomain is suspended"' : 'title="Delete record"'}>
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    })
                    .join('');

                dnsTableContainer.innerHTML = `
                    <div class="table-responsive">
                        <table class="table fs-table align-middle">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Content / Target</th>
                                    <th>Proxy Status</th>
                                    <th>TTL</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>
                `;

                dnsTableContainer.querySelectorAll('.js-copy-target').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        copyToClipboard(btn.dataset.val || '', 'Copied target');
                    });
                });

                dnsTableContainer.querySelectorAll('.js-edit-record').forEach((btn) => {
                    btn.addEventListener('click', () => openEditModal(Number(btn.dataset.id)));
                });

                dnsTableContainer.querySelectorAll('.js-delete-record').forEach((btn) => {
                    btn.addEventListener('click', () => openDeleteDnsModal(Number(btn.dataset.id)));
                });
            }

            function openEditModal(recordId) {
                const rec = currentRecords.find((r) => Number(r.id) === recordId);
                if (!rec || !currentSubdomain) return;

                editRecordId.value = String(rec.id);
                editType.value = rec.record_type;
                editName.value = toShortHost(rec.record_name, currentSubdomain.full_domain);
                editTarget.value = rec.target_value;
                editPriorityInput.value = rec.priority !== null ? String(rec.priority) : '10';

                // Ensure TTL option exists or fallback to Auto
                const ttlStr = String(rec.ttl || 1);
                if (editTtl.querySelector(`option[value="${CSS.escape(ttlStr)}"]`)) {
                    editTtl.value = ttlStr;
                } else {
                    editTtl.value = '1';
                }

                syncTypeFormControls(
                    editType,
                    editTargetLabel,
                    editTarget,
                    editPriorityCol,
                    editProxyWrapper,
                    editProxyToggle,
                    null
                );
                if (!editProxyToggle.disabled) {
                    editProxyToggle.checked = Boolean(rec.proxied);
                }

                editModalInstance.show();
            }

            function openDeleteDnsModal(recordId) {
                const rec = currentRecords.find((r) => Number(r.id) === recordId);
                if (!rec) return;

                pendingDeleteRecordId = recordId;
                deleteDnsSummaryBox.innerHTML = `
                    <div><span class="text-info fw-bold">${escapeHtml(rec.record_type)}</span> &nbsp; ${escapeHtml(rec.record_name)}</div>
                    <div class="text-secondary mt-1">&rarr; ${escapeHtml(rec.target_value)}</div>
                `;
                deleteDnsModalInstance.show();
            }

            async function loadSubdomainAndRecords() {
                if (!subdomainId || subdomainId <= 0) {
                    gateEl.classList.remove('d-none');
                    workspaceEl.classList.add('d-none');
                    gateEl.innerHTML = `
                        <div class="fs-card p-4 text-center mx-auto" style="max-width: 520px;">
                            <i class="bi bi-exclamation-circle text-warning fs-2 mb-2 d-block"></i>
                            <h5 class="fw-bold">Missing Subdomain ID</h5>
                            <p class="text-secondary small mb-3">Please select a subdomain from your dashboard to manage its DNS &amp; SSL settings.</p>
                            <a href="dashboard.php" class="btn btn-fs-primary btn-sm px-4">Go to Dashboard</a>
                        </div>
                    `;
                    return;
                }

                try {
                    const [subListRes, dnsRes] = await Promise.all([
                        apiRequest('subdomains.php'),
                        apiRequest(`dns-records.php?subdomain_id=${encodeURIComponent(subdomainId)}`),
                    ]);

                    const matchedSub = (subListRes.subdomains || []).find(
                        (s) => Number(s.id) === subdomainId
                    );

                    currentSubdomain = Object.assign({}, dnsRes.subdomain || {}, matchedSub || {});
                    currentRecords = dnsRes.records || [];
                    maxRecordsAllowed = Number(dnsRes.max_allowed || 10);

                    gateEl.classList.add('d-none');
                    workspaceEl.classList.remove('d-none');

                    renderHeaderAndOverview();
                    renderDnsRecordsTable();
                } catch (err) {
                    gateEl.classList.remove('d-none');
                    workspaceEl.classList.add('d-none');
                    gateEl.innerHTML = `
                        <div class="fs-card p-4 text-center mx-auto" style="max-width: 520px;">
                            <i class="bi bi-shield-exclamation text-danger fs-2 mb-2 d-block"></i>
                            <h5 class="fw-bold">Unable to Load Subdomain</h5>
                            <p class="text-secondary small mb-3">${escapeHtml(err.message || 'Subdomain not found or access denied.')}</p>
                            <a href="dashboard.php" class="btn btn-fs-outline btn-sm px-4">Back to Dashboard</a>
                        </div>
                    `;
                }
            }

            // Event Listeners
            addType.addEventListener('change', () => {
                syncTypeFormControls(
                    addType,
                    addTargetLabel,
                    addTarget,
                    addPriorityCol,
                    addProxyWrapper,
                    addProxyToggle,
                    addTargetCol
                );
            });

            editType.addEventListener('change', () => {
                syncTypeFormControls(
                    editType,
                    editTargetLabel,
                    editTarget,
                    editPriorityCol,
                    editProxyWrapper,
                    editProxyToggle,
                    null
                );
            });

            addProxyToggle.addEventListener('change', updateAddProxyHint);
            addName.addEventListener('input', updateResolvedNamePreview);

            addForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!currentSubdomain) return;

                const payload = {
                    subdomain_id: subdomainId,
                    record_type: addType.value,
                    record_name: (addName.value || '@').trim(),
                    target_value: (addTarget.value || '').trim(),
                    proxied: !addProxyToggle.disabled && addProxyToggle.checked,
                    ttl: Number(addTtl.value || 1),
                    priority: addType.value === 'MX' ? Number(addPriorityInput.value || 10) : null,
                };

                btnAddDnsSubmit.disabled = true;
                btnAddDnsSubmit.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Adding...`;

                try {
                    const res = await apiRequest('dns-records.php', {
                        method: 'POST',
                        body: payload,
                    });
                    showToast(res.message || 'DNS record added!', 'success');
                    addTarget.value = '';
                    addName.value = '@';
                    await loadSubdomainAndRecords();
                } catch (err) {
                    showToast(err.message || 'Failed to add DNS record.', 'danger');
                } finally {
                    btnAddDnsSubmit.disabled = false;
                    btnAddDnsSubmit.innerHTML = `<i class="bi bi-plus-lg me-1"></i>Add Record`;
                    renderHeaderAndOverview();
                }
            });

            editForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const id = Number(editRecordId.value);
                if (!id) return;

                const payload = {
                    id,
                    record_type: editType.value,
                    record_name: (editName.value || '@').trim(),
                    target_value: (editTarget.value || '').trim(),
                    proxied: !editProxyToggle.disabled && editProxyToggle.checked,
                    ttl: Number(editTtl.value || 1),
                    priority: editType.value === 'MX' ? Number(editPriorityInput.value || 10) : null,
                };

                btnSaveDnsEdit.disabled = true;
                btnSaveDnsEdit.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Saving...`;

                try {
                    const res = await apiRequest('dns-records.php', {
                        method: 'PUT',
                        body: payload,
                    });
                    showToast(res.message || 'DNS record updated.', 'success');
                    editModalInstance.hide();
                    await loadSubdomainAndRecords();
                } catch (err) {
                    showToast(err.message || 'Failed to update DNS record.', 'danger');
                } finally {
                    btnSaveDnsEdit.disabled = false;
                    btnSaveDnsEdit.innerHTML = `<i class="bi bi-check2 me-1"></i>Save Changes`;
                }
            });

            btnConfirmDeleteDns.addEventListener('click', async () => {
                if (!pendingDeleteRecordId) return;

                btnConfirmDeleteDns.disabled = true;
                btnConfirmDeleteDns.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Deleting...`;

                try {
                    const res = await apiRequest(
                        `dns-records.php?id=${encodeURIComponent(pendingDeleteRecordId)}`,
                        { method: 'DELETE' }
                    );
                    showToast(res.message || 'DNS record deleted.', 'success');
                    deleteDnsModalInstance.hide();
                    pendingDeleteRecordId = null;
                    await loadSubdomainAndRecords();
                } catch (err) {
                    showToast(err.message || 'Failed to delete DNS record.', 'danger');
                } finally {
                    btnConfirmDeleteDns.disabled = false;
                    btnConfirmDeleteDns.textContent = 'Delete Record';
                }
            });

            // Copy & Refresh actions
            document.getElementById('btnHeaderCopyDomain').addEventListener('click', () => {
                if (currentSubdomain) {
                    copyToClipboard(currentSubdomain.full_domain, 'Copied domain');
                }
            });

            document.getElementById('btnPropCopyFqdn').addEventListener('click', () => {
                if (currentSubdomain) {
                    copyToClipboard(currentSubdomain.full_domain, 'Copied FQDN');
                }
            });

            document.getElementById('btnReloadAll').addEventListener('click', () => {
                loadSubdomainAndRecords();
            });

            // Danger Zone Delete Subdomain flow
            document.getElementById('btnOpenDangerDeleteModal').addEventListener('click', () => {
                confirmSubInput.value = '';
                btnConfirmDangerDeleteSub.disabled = true;
                dangerSubModalInstance.show();
            });

            confirmSubInput.addEventListener('input', () => {
                const expected = currentSubdomain ? currentSubdomain.full_domain.toLowerCase() : '';
                btnConfirmDangerDeleteSub.disabled =
                    !expected || confirmSubInput.value.trim().toLowerCase() !== expected;
            });

            btnConfirmDangerDeleteSub.addEventListener('click', async () => {
                if (!currentSubdomain) return;

                btnConfirmDangerDeleteSub.disabled = true;
                btnConfirmDangerDeleteSub.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Deleting...`;

                try {
                    const res = await apiRequest(
                        `subdomains.php?id=${encodeURIComponent(subdomainId)}`,
                        { method: 'DELETE' }
                    );
                    showToast(res.message || 'Subdomain deleted.', 'success');
                    window.location.href = 'dashboard.php';
                } catch (err) {
                    showToast(err.message || 'Failed to delete subdomain.', 'danger');
                    btnConfirmDangerDeleteSub.disabled = false;
                    btnConfirmDangerDeleteSub.innerHTML = `<i class="bi bi-trash3 me-1"></i>Delete Subdomain`;
                }
            });

            // Initialize Management Workspace
            (async function initManagePage() {
                editModalInstance = new window.bootstrap.Modal(editModalEl);
                deleteDnsModalInstance = new window.bootstrap.Modal(deleteDnsModalEl);
                dangerSubModalInstance = new window.bootstrap.Modal(dangerSubModalEl);

                syncTypeFormControls(
                    addType,
                    addTargetLabel,
                    addTarget,
                    addPriorityCol,
                    addProxyWrapper,
                    addProxyToggle,
                    addTargetCol
                );

                await loadBootstrapConfig();
                await renderAuthControls('#fsAuthControls');

                const signedIn = await requireSignInOrPrompt({
                    gateContainer: gateEl,
                    autoPrompt: true,
                });

                if (!signedIn) {
                    return;
                }

                await loadSubdomainAndRecords();
            })();
        })();
    </script>
</body>
</html>
