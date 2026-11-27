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
    <title><?= $appName ?> — Free Subdomains, Instant Cloudflare DNS &amp; Edge SSL</title>
    <meta name="description" content="Claim free subdomains on community domains like kharcha.info with instant Cloudflare Anycast DNS and automatic SSL/TLS management.">

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

            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#fsMainNav" aria-controls="fsMainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="fsMainNav">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-3">
                    <li class="nav-item"><a class="nav-link fs-nav-link" href="#availability-checker">Availability</a></li>
                    <li class="nav-item"><a class="nav-link fs-nav-link" href="#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link fs-nav-link" href="#dns-ssl-features">DNS &amp; SSL</a></li>
                    <li class="nav-item"><a class="nav-link fs-nav-link" href="#free-tier">Free Tier</a></li>
                </ul>

                <div id="fsAuthControls" class="d-flex align-items-center">
                    <a href="dashboard.php" class="btn btn-sm btn-fs-outline">Dashboard</a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        <!-- Hero & Interactive Availability Checker -->
        <section class="fs-hero" id="availability-checker">
            <div class="container">
                <div class="row justify-content-center text-center">
                    <div class="col-xl-9 col-lg-10">
                        <div class="fs-pill mb-3">
                            <i class="bi bi-lightning-charge-fill"></i>
                            <span>Powered by Cloudflare Global Anycast DNS &amp; Edge SSL</span>
                        </div>

                        <h1 class="fs-hero-title mb-3">
                            Claim Your Free Subdomain.<br>
                            <span class="fs-gradient-text">Deploy DNS &amp; HTTPS in Seconds.</span>
                        </h1>

                        <p class="lead text-secondary mx-auto mb-4" style="max-width: 680px; font-size: 1.08rem;">
                            Launch side-projects, APIs, portfolios, and homelabs on clean community domains like
                            <span class="font-mono text-light">kharcha.info</span> — complete with full
                            <span class="font-mono text-info">A, AAAA, CNAME, MX &amp; TXT</span> control.
                        </p>

                        <!-- Availability Search Box -->
                        <div class="fs-search-shell text-start mx-auto mt-4" style="max-width: 780px;">
                            <form id="heroCheckForm" class="row g-2 align-items-center" novalidate>
                                <div class="col-md-5">
                                    <label for="heroSubdomainInput" class="visually-hidden">Subdomain label</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-hdd-network"></i></span>
                                        <input type="text"
                                               id="heroSubdomainInput"
                                               class="form-control font-mono"
                                               placeholder="your-project"
                                               minlength="3"
                                               maxlength="63"
                                               autocomplete="off"
                                               spellcheck="false"
                                               required>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="heroRootDomainSelect" class="visually-hidden">Root domain</label>
                                    <select id="heroRootDomainSelect" class="form-select font-mono" aria-label="Choose root domain">
                                        <option value="">Loading domains...</option>
                                    </select>
                                </div>

                                <div class="col-md-3 d-grid">
                                    <button type="submit" id="heroCheckBtn" class="btn btn-fs-primary py-2">
                                        <i class="bi bi-search me-1"></i>Check Availability
                                    </button>
                                </div>
                            </form>

                            <!-- Live Availability Result Banner -->
                            <div id="heroAvailabilityResult" class="mt-3 d-none"></div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-1 small text-secondary">
                                <span>
                                    <i class="bi bi-check2-circle text-success me-1"></i>3–63 lowercase letters, numbers, or hyphens
                                </span>
                                <span id="heroDomainHint" class="font-mono text-info"></span>
                            </div>
                        </div>

                        <!-- Quick Trust / Spec Strip -->
                        <div class="row g-3 justify-content-center mt-4 pt-2 text-start">
                            <div class="col-6 col-md-3">
                                <div class="fs-card p-3 h-100">
                                    <div class="text-info fw-bold font-mono fs-5">&lt; 5s</div>
                                    <div class="text-secondary small">Edge DNS Propagation</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="fs-card p-3 h-100">
                                    <div class="text-success fw-bold font-mono fs-5">Auto SSL</div>
                                    <div class="text-secondary small">Free TLS Edge Certificates</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="fs-card p-3 h-100">
                                    <div class="text-warning fw-bold font-mono fs-5">5 Types</div>
                                    <div class="text-secondary small">A, AAAA, CNAME, MX, TXT</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="fs-card p-3 h-100">
                                    <div class="text-light fw-bold font-mono fs-5" id="statFreeSubLimit">2 Free</div>
                                    <div class="text-secondary small">Subdomains Included</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How It Works -->
        <section class="py-5 border-top border-secondary border-opacity-10" id="how-it-works">
            <div class="container py-2">
                <div class="text-center mb-5">
                    <span class="text-uppercase small fw-bold text-info letter-spacing-1">Simple Workflow</span>
                    <h2 class="fw-bold mt-1">Go Live in Three Steps</h2>
                    <p class="text-secondary mb-0">Zero registrar paperwork or manual nameserver delegation required.</p>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="fs-card fs-card-interactive p-4 h-100">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3 px-3 py-2 font-mono fw-bold text-info"
                                 style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.25);">
                                01
                            </div>
                            <h5 class="fw-bold">Search &amp; Reserve</h5>
                            <p class="text-secondary small mb-0">
                                Pick your preferred label on any active community domain (such as <span class="font-mono text-light">app.kharcha.info</span>) and claim it instantly.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="fs-card fs-card-interactive p-4 h-100">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3 px-3 py-2 font-mono fw-bold text-info"
                                 style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.25);">
                                02
                            </div>
                            <h5 class="fw-bold">Point Your DNS Records</h5>
                            <p class="text-secondary small mb-0">
                                Point <span class="font-mono text-light">A</span> / <span class="font-mono text-light">AAAA</span> to your VPS IP, <span class="font-mono text-light">CNAME</span> to Vercel, Render, Netlify, or GitHub Pages, and <span class="font-mono text-light">TXT</span> for verification.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="fs-card fs-card-interactive p-4 h-100">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3 px-3 py-2 font-mono fw-bold text-info"
                                 style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.25);">
                                03
                            </div>
                            <h5 class="fw-bold">Serve Over HTTPS</h5>
                            <p class="text-secondary small mb-0">
                                Toggle Cloudflare Orange-Cloud Proxy for instant DDoS protection &amp; edge SSL, or use DNS-Only mode for custom Let's Encrypt / platform SSL.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Supported DNS Records & Cloudflare SSL Architecture -->
        <section class="py-5 border-top border-secondary border-opacity-10" id="dns-ssl-features">
            <div class="container py-2">
                <div class="row g-4 align-items-stretch">
                    <!-- Supported DNS Records -->
                    <div class="col-lg-7">
                        <div class="fs-card h-100">
                            <div class="fs-card-header">
                                <div>
                                    <h4 class="fw-bold mb-0 fs-5"><i class="bi bi-diagram-3-fill text-info me-2"></i>Full DNS Record Control</h4>
                                </div>
                                <span class="fs-badge fs-badge-active">Real-Time Cloudflare v4 Sync</span>
                            </div>
                            <div class="fs-card-body">
                                <p class="text-secondary small mb-4">
                                    Create root (<span class="font-mono text-light">@</span>) or nested child records (like <span class="font-mono text-light">api</span>, <span class="font-mono text-light">www</span>, or <span class="font-mono text-light">_acme-challenge</span>) directly inside your subdomain slice.
                                </p>

                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex align-items-start gap-3 p-2 rounded-3" style="background: rgba(255,255,255,0.02);">
                                        <span class="fs-type-pill fs-type-A mt-1">A</span>
                                        <div>
                                            <div class="fw-semibold small">IPv4 Address Mapping</div>
                                            <div class="text-secondary small">Route traffic directly to any public IPv4 server with optional Cloudflare CDN proxy.</div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-start gap-3 p-2 rounded-3" style="background: rgba(255,255,255,0.02);">
                                        <span class="fs-type-pill fs-type-AAAA mt-1">AAAA</span>
                                        <div>
                                            <div class="fw-semibold small">IPv6 Address Mapping</div>
                                            <div class="text-secondary small">Native dual-stack IPv6 origin routing with full proxy toggle support.</div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-start gap-3 p-2 rounded-3" style="background: rgba(255,255,255,0.02);">
                                        <span class="fs-type-pill fs-type-CNAME mt-1">CNAME</span>
                                        <div>
                                            <div class="fw-semibold small">Canonical Hostname Alias</div>
                                            <div class="text-secondary small">Connect seamlessly to Vercel, Netlify, Railway, Render, Fly.io, or GitHub Pages.</div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-start gap-3 p-2 rounded-3" style="background: rgba(255,255,255,0.02);">
                                        <span class="fs-type-pill fs-type-MX mt-1">MX</span>
                                        <div>
                                            <div class="fw-semibold small">Mail Exchange Routing</div>
                                            <div class="text-secondary small">Configure custom Priority + Mail Server hostnames to receive email on your subdomain.</div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-start gap-3 p-2 rounded-3" style="background: rgba(255,255,255,0.02);">
                                        <span class="fs-type-pill fs-type-TXT mt-1">TXT</span>
                                        <div>
                                            <div class="fw-semibold small">SPF, DKIM, DMARC &amp; Domain Verification</div>
                                            <div class="text-secondary small">Verify ownership with Google Search Console, Let's Encrypt ACME DNS-01, Resend, or Mailgun.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SSL & Edge Security -->
                    <div class="col-lg-5">
                        <div class="fs-card h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="fs-card-header">
                                    <h4 class="fw-bold mb-0 fs-5"><i class="bi bi-shield-check text-success me-2"></i>SSL &amp; Cloudflare Edge</h4>
                                </div>
                                <div class="fs-card-body">
                                    <div class="fs-callout fs-callout-cloudflare mb-3">
                                        <div class="fw-semibold small mb-1" style="color: #fb923c;">
                                            <i class="bi bi-cloud-fill me-1"></i>Proxied Mode (Orange Cloud ON)
                                        </div>
                                        <div class="text-secondary small">
                                            Shields your origin IP, caches static assets across 300+ edge cities, and terminates HTTPS automatically at Cloudflare's edge.
                                        </div>
                                    </div>

                                    <div class="fs-callout mb-3">
                                        <div class="fw-semibold small text-info mb-1">
                                            <i class="bi bi-hdd-rack-fill me-1"></i>DNS-Only Mode (Grey Cloud OFF)
                                        </div>
                                        <div class="text-secondary small">
                                            Ideal when pointing CNAMEs to PaaS providers (Vercel, Render, Netlify, GitHub Pages) or issuing your own Let's Encrypt certificates via Certbot/Caddy without redirect loops.
                                        </div>
                                    </div>

                                    <ul class="list-unstyled small text-secondary mb-0 d-flex flex-column gap-2 mt-3">
                                        <li><i class="bi bi-check-circle-fill text-success me-2"></i>Scoped namespace isolation per user</li>
                                        <li><i class="bi bi-check-circle-fill text-success me-2"></i>Automatic placeholder DNS provisioning</li>
                                        <li><i class="bi bi-check-circle-fill text-success me-2"></i>Clean InfinityFree-inspired management console</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Free Tier Quota Highlights -->
        <section class="py-5 border-top border-secondary border-opacity-10" id="free-tier">
            <div class="container py-2">
                <div class="row justify-content-center">
                    <div class="col-lg-9">
                        <div class="fs-card p-4 p-md-5">
                            <div class="row align-items-center g-4">
                                <div class="col-md-7">
                                    <span class="fs-badge fs-badge-active mb-2">$0 / Forever Developer Plan</span>
                                    <h3 class="fw-bold mb-2">Generous Free Tier Out of the Box</h3>
                                    <p class="text-secondary small mb-3">
                                        Built for indie hackers, students, and self-hosters. Start immediately with no credit card required.
                                    </p>
                                    <div class="row g-2 small">
                                        <div class="col-sm-6">
                                            <i class="bi bi-check2 text-info me-2"></i><strong id="tierSubLimitText">2</strong> Free Subdomains
                                        </div>
                                        <div class="col-sm-6">
                                            <i class="bi bi-check2 text-info me-2"></i><strong id="tierDnsLimitText">10</strong> DNS Records / Subdomain
                                        </div>
                                        <div class="col-sm-6">
                                            <i class="bi bi-check2 text-info me-2"></i>Instant Record Edits &amp; TTL Control
                                        </div>
                                        <div class="col-sm-6">
                                            <i class="bi bi-check2 text-info me-2"></i>Multiple Community Root Domains
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-5 text-md-end">
                                    <div class="p-4 rounded-4 text-center" style="background: rgba(10,16,31,0.75); border: 1px solid var(--fs-border-strong);">
                                        <div class="text-secondary small text-uppercase fw-semibold">Developer Starter</div>
                                        <div class="display-5 fw-extrabold my-1">$0</div>
                                        <div class="text-secondary small mb-3">Instant Clerk Sign-In</div>
                                        <button type="button" class="btn btn-fs-primary w-100" id="ctaBottomBtn">
                                            Launch Control Panel <i class="bi bi-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="fs-footer mt-auto">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-semibold text-light"><?= $appName ?></span> &mdash; Subdomain, Cloudflare DNS &amp; SSL Management Platform
            </div>
            <div class="d-flex gap-3">
                <a href="index.php" class="text-secondary text-decoration-none">Home</a>
                <a href="dashboard.php" class="text-secondary text-decoration-none">Dashboard</a>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS & FreeSub App -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/app.js"></script>
    <script>
        (function () {
            'use strict';

            const {
                initAuth,
                renderAuthControls,
                loadBootstrapConfig,
                apiRequest,
                isSignedIn,
                openSignInModal,
                escapeHtml,
                showToast,
            } = window.FreeSub;

            const form = document.getElementById('heroCheckForm');
            const subInput = document.getElementById('heroSubdomainInput');
            const domainSelect = document.getElementById('heroRootDomainSelect');
            const checkBtn = document.getElementById('heroCheckBtn');
            const resultBox = document.getElementById('heroAvailabilityResult');
            const domainHint = document.getElementById('heroDomainHint');
            const ctaBottomBtn = document.getElementById('ctaBottomBtn');

            let debounceTimer = null;

            function updatePreviewHint() {
                const label = (subInput.value || '').trim().toLowerCase() || 'your-project';
                const selectedOpt = domainSelect.options[domainSelect.selectedIndex];
                const domainName = selectedOpt && selectedOpt.dataset.domain
                    ? selectedOpt.dataset.domain
                    : 'kharcha.info';
                domainHint.textContent = `${label}.${domainName}`;
            }

            function populateDomains(domains) {
                if (!Array.isArray(domains) || domains.length === 0) {
                    domainSelect.innerHTML = `<option value="" data-domain="kharcha.info">.kharcha.info (No active domain seeded yet)</option>`;
                    updatePreviewHint();
                    return;
                }

                domainSelect.innerHTML = domains
                    .map(
                        (d) =>
                            `<option value="${Number(d.id)}" data-domain="${escapeHtml(d.domain_name)}">.${escapeHtml(d.domain_name)}</option>`
                    )
                    .join('');
                updatePreviewHint();
            }

            function renderAvailabilityBanner(data, rootDomainId, label) {
                resultBox.classList.remove('d-none');

                if (data.available) {
                    const claimParams = new URLSearchParams({
                        claim: label,
                        root_domain_id: String(rootDomainId),
                    }).toString();
                    const targetDashboardUrl = `dashboard.php?${claimParams}`;

                    resultBox.innerHTML = `
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 p-3 rounded-3"
                             style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.35);">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <div>
                                    <div class="fw-bold font-mono text-light">${escapeHtml(data.full_domain)}</div>
                                    <div class="small text-success">${escapeHtml(data.reason || 'Available for instant registration!')}</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-fs-primary px-3 flex-shrink-0" id="btnClaimNow">
                                <i class="bi bi-rocket-takeoff me-1"></i>Claim in Dashboard
                            </button>
                        </div>
                    `;

                    const claimBtn = document.getElementById('btnClaimNow');
                    if (claimBtn) {
                        claimBtn.addEventListener('click', () => {
                            if (isSignedIn()) {
                                window.location.href = targetDashboardUrl;
                            } else {
                                openSignInModal(
                                    new URL(targetDashboardUrl, window.location.href).href
                                );
                            }
                        });
                    }
                } else {
                    resultBox.innerHTML = `
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3"
                             style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.32);">
                            <i class="bi bi-x-circle-fill text-danger fs-5"></i>
                            <div>
                                <div class="fw-bold font-mono text-light">${escapeHtml(data.full_domain || label)}</div>
                                <div class="small text-danger">${escapeHtml(data.reason || 'This subdomain is not available.')}</div>
                            </div>
                        </div>
                    `;
                }
            }

            async function performCheck(showLoadingButton = true) {
                const rawLabel = (subInput.value || '').trim().toLowerCase();
                const rootDomainId = domainSelect.value;

                if (!rawLabel) {
                    resultBox.classList.add('d-none');
                    return;
                }

                if (!rootDomainId) {
                    showToast('No active root domains are configured in the database yet.', 'warning');
                    return;
                }

                if (showLoadingButton) {
                    checkBtn.disabled = true;
                    checkBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Checking...`;
                }

                try {
                    const qs = new URLSearchParams({
                        subdomain: rawLabel,
                        root_domain_id: rootDomainId,
                    });
                    const res = await apiRequest(`check-availability.php?${qs.toString()}`, {
                        method: 'GET',
                        skipAuth: true,
                    });
                    renderAvailabilityBanner(res, rootDomainId, rawLabel);
                } catch (err) {
                    resultBox.classList.remove('d-none');
                    resultBox.innerHTML = `
                        <div class="p-3 rounded-3 small text-warning"
                             style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3);">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>${escapeHtml(err.message)}
                        </div>
                    `;
                } finally {
                    if (showLoadingButton) {
                        checkBtn.disabled = false;
                        checkBtn.innerHTML = `<i class="bi bi-search me-1"></i>Check Availability`;
                    }
                }
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                clearTimeout(debounceTimer);
                performCheck(true);
            });

            subInput.addEventListener('input', () => {
                // Normalize to lowercase automatically while typing
                const pos = subInput.selectionStart;
                const lowered = subInput.value.toLowerCase();
                if (subInput.value !== lowered) {
                    subInput.value = lowered;
                    subInput.setSelectionRange(pos, pos);
                }
                updatePreviewHint();

                clearTimeout(debounceTimer);
                if (subInput.value.trim().length >= 3 && domainSelect.value) {
                    debounceTimer = setTimeout(() => performCheck(false), 380);
                } else if (subInput.value.trim().length === 0) {
                    resultBox.classList.add('d-none');
                }
            });

            domainSelect.addEventListener('change', () => {
                updatePreviewHint();
                if (subInput.value.trim().length >= 3 && domainSelect.value) {
                    performCheck(false);
                }
            });

            ctaBottomBtn.addEventListener('click', () => {
                if (isSignedIn()) {
                    window.location.href = 'dashboard.php';
                } else {
                    openSignInModal(new URL('dashboard.php', window.location.href).href);
                }
            });

            // Initialize bootstrap config & Clerk controls
            (async function bootstrapLanding() {
                const config = await loadBootstrapConfig();
                populateDomains(config.root_domains || []);

                if (config.limits) {
                    const subLim = Number(config.limits.default_free_subdomain_limit ?? 2);
                    const dnsLim = Number(config.limits.max_dns_records_per_subdomain ?? 10);
                    document.getElementById('statFreeSubLimit').textContent = `${subLim} Free`;
                    document.getElementById('tierSubLimitText').textContent = String(subLim);
                    document.getElementById('tierDnsLimitText').textContent = String(dnsLim);
                }

                await initAuth();
                await renderAuthControls('#fsAuthControls');
            })();
        })();
    </script>
</body>
</html>
