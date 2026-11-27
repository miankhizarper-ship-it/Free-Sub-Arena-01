/**
 * FreeSub SaaS Frontend Core (`web/assets/app.js`)
 *
 * Provides:
 * - Bootstrap config loader (`/web/api/bootstrap-config.php`)
 * - Clerk JS dynamic SDK initialization & session management
 *   (`initAuth`, `getAuthToken`, `signOutUser`, `renderAuthControls`, `requireSignInOrPrompt`)
 * - Authenticated REST API helper (`apiRequest(path, options)`)
 * - Safe HTML/DOM escaping (`escapeHtml`) & Toast / Clipboard utilities
 */

(function (window) {
    'use strict';

    const API_BASE = 'api';

    const state = {
        bootstrapConfig: null,
        clerk: null,
        authInitialized: false,
        authInitPromise: null,
        clerkConfigured: false,
        clerkError: null,
    };

    /**
     * Escape untrusted strings safely before inserting into HTML/DOM attributes.
     */
    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Format ISO/MySQL timestamp into readable local date string.
     */
    function formatDate(dateStr) {
        if (!dateStr) {
            return '—';
        }
        const normalized = String(dateStr).includes('T')
            ? String(dateStr)
            : String(dateStr).replace(' ', 'T') + 'Z';
        const parsed = new Date(normalized);
        if (Number.isNaN(parsed.getTime())) {
            return String(dateStr);
        }
        return parsed.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    }

    /**
     * Display a Bootstrap 5 toast notification.
     *
     * @param {string} message
     * @param {'success'|'danger'|'warning'|'info'} variant
     */
    function showToast(message, variant = 'info') {
        let container = document.getElementById('fsToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'fsToastContainer';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1100';
            document.body.appendChild(container);
        }

        const colorMap = {
            success: { border: 'rgba(16,185,129,0.45)', icon: 'bi-check-circle-fill text-success' },
            danger: { border: 'rgba(239,68,68,0.45)', icon: 'bi-exclamation-octagon-fill text-danger' },
            warning: { border: 'rgba(245,158,11,0.45)', icon: 'bi-exclamation-triangle-fill text-warning' },
            info: { border: 'rgba(56,189,248,0.45)', icon: 'bi-info-circle-fill text-info' },
        };
        const style = colorMap[variant] || colorMap.info;

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-light border-0 show';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.style.background = '#111a2e';
        toastEl.style.border = `1px solid ${style.border}`;
        toastEl.style.boxShadow = '0 12px 30px rgba(0,0,0,0.45)';

        toastEl.innerHTML = `
            <div class="d-flex align-items-center px-3 py-2">
                <i class="bi ${style.icon} me-2 fs-5"></i>
                <div class="toast-body px-1 py-1 small fw-medium">${escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white ms-auto me-0" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        container.appendChild(toastEl);

        if (window.bootstrap && window.bootstrap.Toast) {
            const bsToast = new window.bootstrap.Toast(toastEl, { delay: 4200 });
            bsToast.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        } else {
            setTimeout(() => toastEl.remove(), 4200);
        }
    }

    /**
     * Copy text to clipboard and show feedback toast.
     */
    async function copyToClipboard(text, label = 'Copied to clipboard') {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const temp = document.createElement('textarea');
                temp.value = text;
                temp.style.position = 'fixed';
                temp.style.opacity = '0';
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                temp.remove();
            }
            showToast(`${label}: ${text}`, 'success');
        } catch (_err) {
            showToast('Unable to copy automatically. Please copy manually.', 'warning');
        }
    }

    /**
     * Resolve relative API path (`bootstrap-config.php` -> `api/bootstrap-config.php`).
     */
    function resolveApiUrl(path) {
        const clean = String(path || '').trim();
        if (
            clean.startsWith('http://') ||
            clean.startsWith('https://') ||
            clean.startsWith('/web/api/') ||
            clean.startsWith('api/')
        ) {
            return clean;
        }
        if (clean.startsWith('/api/')) {
            return clean.slice(1);
        }
        return `${API_BASE}/${clean.replace(/^\/+/, '')}`;
    }

    /**
     * Get a fresh Clerk session JWT if signed in.
     *
     * @param {boolean} forceRefresh Bypass Clerk's short-lived memory cache when true
     * @returns {Promise<string|null>}
     */
    async function getAuthToken(forceRefresh = false) {
        if (!state.authInitialized && state.authInitPromise) {
            try {
                await state.authInitPromise;
            } catch (_e) {
                // Continue gracefully
            }
        }

        if (state.clerk && state.clerk.session) {
            try {
                const tokenOptions = forceRefresh ? { skipCache: true } : {};
                const token = await state.clerk.session.getToken(tokenOptions);
                if (token) {
                    return token;
                }
            } catch (_err) {
                return null;
            }
        }
        return null;
    }

    /**
     * Perform a JSON REST API call against `/web/api/`, automatically requesting
     * a fresh `Authorization: Bearer <token>` via `clerk.session.getToken()` before
     * every authenticated request (and retrying once with `skipCache: true` on 401)
     * so long-lived browser sessions never fail due to stale JWTs.
     *
     * @param {string} path
     * @param {object} options
     * @returns {Promise<any>}
     */
    async function apiRequest(path, options = {}) {
        const url = resolveApiUrl(path);
        const method = (options.method || 'GET').toUpperCase();
        const headers = Object.assign(
            { Accept: 'application/json' },
            options.headers || {}
        );

        if (options.skipAuth !== true) {
            const token = await getAuthToken(Boolean(options._forceRefreshToken));
            if (token) {
                headers.Authorization = `Bearer ${token}`;
            }
        }

        const fetchOptions = {
            method,
            headers,
            credentials: 'same-origin',
        };

        if (options.body !== undefined && options.body !== null) {
            if (typeof options.body === 'string') {
                fetchOptions.body = options.body;
                if (!headers['Content-Type']) {
                    headers['Content-Type'] = 'application/json';
                }
            } else {
                fetchOptions.body = JSON.stringify(options.body);
                headers['Content-Type'] = 'application/json';
            }
        }

        let response;
        try {
            response = await fetch(url, fetchOptions);
        } catch (networkError) {
            const err = new Error('Network error: Unable to reach the FreeSub API server.');
            err.status = 0;
            err.code = 'NETWORK_ERROR';
            err.cause = networkError;
            throw err;
        }

        // If Clerk session exists and token just crossed expiration boundary, force-refresh once
        if (
            response.status === 401 &&
            options.skipAuth !== true &&
            !options._retried401 &&
            state.clerk &&
            state.clerk.session
        ) {
            return apiRequest(
                path,
                Object.assign({}, options, {
                    _forceRefreshToken: true,
                    _retried401: true,
                })
            );
        }

        let data = null;
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (_parseErr) {
                data = null;
            }
        } else {
            const text = await response.text();
            try {
                data = JSON.parse(text);
            } catch (_e) {
                data = { error: text || `HTTP ${response.status}` };
            }
        }

        if (!response.ok || (data && data.success === false)) {
            const errMessage =
                (data && (data.error || data.message)) ||
                `Request failed with status ${response.status}.`;
            const apiErr = new Error(errMessage);
            apiErr.status = response.status;
            apiErr.code = (data && data.code) || 'API_ERROR';
            apiErr.details = (data && data.details) || null;
            apiErr.payload = data;
            throw apiErr;
        }

        return data;
    }

    /**
     * Load public bootstrap configuration from `/web/api/bootstrap-config.php`.
     */
    async function loadBootstrapConfig(forceRefresh = false) {
        if (state.bootstrapConfig && !forceRefresh) {
            return state.bootstrapConfig;
        }

        try {
            const config = await apiRequest('bootstrap-config.php', {
                method: 'GET',
                skipAuth: true,
            });
            state.bootstrapConfig = config;
            return config;
        } catch (err) {
            state.bootstrapConfig = {
                success: false,
                app_name: 'FreeSub',
                clerkPublishableKey: '',
                root_domains: [],
                limits: {
                    default_free_subdomain_limit: 2,
                    max_dns_records_per_subdomain: 10,
                },
                allowed_record_types: ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
                reserved_subdomains: [],
                _loadError: err.message,
            };
            return state.bootstrapConfig;
        }
    }

    /**
     * Derive Clerk Frontend API script URL from a Clerk publishable key (`pk_test_...` / `pk_live_...`).
     */
    function deriveClerkFrontendApiFromPublishableKey(publishableKey) {
        if (!publishableKey || typeof publishableKey !== 'string') {
            return null;
        }
        const trimmed = publishableKey.trim();
        if (!trimmed.startsWith('pk_test_') && !trimmed.startsWith('pk_live_')) {
            return null;
        }
        if (trimmed === 'pk_test_replace_me' || trimmed === 'pk_live_replace_me') {
            return null;
        }
        try {
            const encodedPart = trimmed
                .replace(/^pk_(test|live)_/, '')
                .replace(/-/g, '+')
                .replace(/_/g, '/');
            const padded = encodedPart + '='.repeat((4 - (encodedPart.length % 4)) % 4);
            const decoded = atob(padded).replace(/\$$/, '');
            if (decoded && decoded.includes('.')) {
                return `https://${decoded}`;
            }
        } catch (_e) {
            return null;
        }
        return null;
    }

    /**
     * Dynamically inject Clerk JS SDK script and wait for `window.Clerk.load()`.
     */
    function loadClerkScript(publishableKey) {
        return new Promise((resolve, reject) => {
            if (window.Clerk) {
                resolve(window.Clerk);
                return;
            }

            const frontendUrl = deriveClerkFrontendApiFromPublishableKey(publishableKey);
            const scriptSrc = frontendUrl
                ? `${frontendUrl}/npm/@clerk/clerk-js@5/dist/clerk.browser.js`
                : 'https://cdn.jsdelivr.net/npm/@clerk/clerk-js@5/dist/clerk.browser.js';

            const script = document.createElement('script');
            script.async = true;
            script.crossOrigin = 'anonymous';
            script.setAttribute('data-clerk-publishable-key', publishableKey);
            script.src = scriptSrc;

            script.onload = () => {
                if (window.Clerk) {
                    resolve(window.Clerk);
                } else {
                    reject(new Error('Clerk JS SDK loaded without exposing window.Clerk.'));
                }
            };
            script.onerror = () => {
                reject(new Error('Failed to load Clerk JS SDK from CDN.'));
            };

            document.head.appendChild(script);
        });
    }

    /**
     * Initialize Clerk authentication using `clerkPublishableKey` from `/web/api/bootstrap-config.php`.
     * Safe to call multiple times across page lifecycles.
     */
    async function initAuth() {
        if (state.authInitPromise) {
            return state.authInitPromise;
        }

        state.authInitPromise = (async () => {
            const config = await loadBootstrapConfig();
            const pubKey = (config.clerkPublishableKey || '').trim();

            const isPlaceholder =
                !pubKey ||
                pubKey === 'pk_test_replace_me' ||
                pubKey === 'pk_live_replace_me' ||
                (!pubKey.startsWith('pk_test_') && !pubKey.startsWith('pk_live_'));

            if (isPlaceholder) {
                state.clerkConfigured = false;
                state.authInitialized = true;
                state.clerkError =
                    'Clerk Publishable Key is not configured yet. Set CLERK_PUBLISHABLE_KEY in .env to enable sign-in.';
                return {
                    configured: false,
                    signedIn: false,
                    user: null,
                    config,
                    error: state.clerkError,
                };
            }

            try {
                const clerkInstance = await loadClerkScript(pubKey);
                await clerkInstance.load({
                    publishableKey: pubKey,
                });

                state.clerk = clerkInstance;
                state.clerkConfigured = true;
                state.authInitialized = true;

                return {
                    configured: true,
                    signedIn: Boolean(clerkInstance.user && clerkInstance.session),
                    user: clerkInstance.user || null,
                    config,
                    error: null,
                };
            } catch (err) {
                state.clerkConfigured = false;
                state.authInitialized = true;
                state.clerkError = err.message || 'Unable to initialize Clerk authentication.';
                return {
                    configured: false,
                    signedIn: false,
                    user: null,
                    config,
                    error: state.clerkError,
                };
            }
        })();

        return state.authInitPromise;
    }

    /**
     * Check whether a Clerk user session is currently active.
     */
    function isSignedIn() {
        return Boolean(state.clerk && state.clerk.user && state.clerk.session);
    }

    /**
     * Open Clerk Sign-In modal (or Sign-Up modal) cleanly.
     */
    function openSignInModal(redirectUrl) {
        const targetRedirect = redirectUrl || window.location.href;
        if (!state.clerkConfigured || !state.clerk) {
            showToast(
                state.clerkError || 'Clerk authentication is not configured in .env.',
                'warning'
            );
            return;
        }
        state.clerk.openSignIn({
            afterSignInUrl: targetRedirect,
            afterSignUpUrl: targetRedirect,
            redirectUrl: targetRedirect,
        });
    }

    /**
     * Open Clerk Sign-Up modal cleanly.
     */
    function openSignUpModal(redirectUrl) {
        const targetRedirect = redirectUrl || 'dashboard.php';
        if (!state.clerkConfigured || !state.clerk) {
            showToast(
                state.clerkError || 'Clerk authentication is not configured in .env.',
                'warning'
            );
            return;
        }
        state.clerk.openSignUp({
            afterSignInUrl: targetRedirect,
            afterSignUpUrl: targetRedirect,
            redirectUrl: targetRedirect,
        });
    }

    /**
     * Sign out the current Clerk user and redirect to landing page (`index.php`).
     */
    async function signOutUser(redirectTo = 'index.php') {
        if (state.clerk) {
            try {
                await state.clerk.signOut();
            } catch (_err) {
                // Ignore sign-out errors and redirect
            }
        }
        window.location.href = redirectTo;
    }

    /**
     * Render Navbar Auth Controls into a container element (default `#fsAuthControls`).
     */
    async function renderAuthControls(containerOrSelector = '#fsAuthControls') {
        const container =
            typeof containerOrSelector === 'string'
                ? document.querySelector(containerOrSelector)
                : containerOrSelector;

        if (!container) {
            return;
        }

        const authState = await initAuth();
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';

        if (authState.signedIn && authState.user) {
            const primaryEmail =
                (authState.user.primaryEmailAddress &&
                    authState.user.primaryEmailAddress.emailAddress) ||
                authState.user.fullName ||
                authState.user.username ||
                'Account';
            const avatarUrl = authState.user.imageUrl || '';

            container.innerHTML = `
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    ${
                        currentPage !== 'dashboard.php'
                            ? `<a href="dashboard.php" class="btn btn-sm btn-fs-primary px-3">
                                   <i class="bi bi-grid-1x2-fill me-1"></i>Dashboard
                               </a>`
                            : ''
                    }
                    <div class="dropdown">
                        <button class="btn btn-sm btn-fs-outline dropdown-toggle d-inline-flex align-items-center gap-2"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            ${
                                avatarUrl
                                    ? `<img src="${escapeHtml(avatarUrl)}" alt="" width="22" height="22" class="rounded-circle object-fit-cover">`
                                    : `<i class="bi bi-person-circle text-info"></i>`
                            }
                            <span class="small text-truncate" style="max-width: 150px;">${escapeHtml(primaryEmail)}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border-secondary border-opacity-25">
                            <li>
                                <a class="dropdown-item small" href="dashboard.php">
                                    <i class="bi bi-hdd-network me-2 text-info"></i>My Subdomains
                                </a>
                            </li>
                            <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                            <li>
                                <button type="button" class="dropdown-item small text-danger" id="fsBtnSignOut">
                                    <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            `;

            const signOutBtn = container.querySelector('#fsBtnSignOut');
            if (signOutBtn) {
                signOutBtn.addEventListener('click', () => signOutUser('index.php'));
            }
            return;
        }

        // Unauthenticated controls
        container.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-fs-outline" id="fsNavSignInBtn">
                    Sign In
                </button>
                <button type="button" class="btn btn-sm btn-fs-primary" id="fsNavSignUpBtn">
                    Get Started Free
                </button>
            </div>
        `;

        const signInBtn = container.querySelector('#fsNavSignInBtn');
        const signUpBtn = container.querySelector('#fsNavSignUpBtn');

        if (signInBtn) {
            signInBtn.addEventListener('click', () => openSignInModal('dashboard.php'));
        }
        if (signUpBtn) {
            signUpBtn.addEventListener('click', () => openSignUpModal('dashboard.php'));
        }
    }

    /**
     * Guard a protected page or action:
     * - If signed in, resolves `true`.
     * - If not signed in and `gateContainer` is supplied, renders a clean Sign-In required card
     *   inside `gateContainer` and automatically opens the Clerk Sign-In prompt if Clerk is configured.
     *
     * @param {object} options
     * @returns {Promise<boolean>}
     */
    async function requireSignInOrPrompt(options = {}) {
        const authState = await initAuth();
        if (authState.signedIn) {
            return true;
        }

        const gateEl = options.gateContainer
            ? typeof options.gateContainer === 'string'
                ? document.querySelector(options.gateContainer)
                : options.gateContainer
            : null;

        if (gateEl) {
            const configHint = !authState.configured
                ? `<div class="alert alert-warning bg-warning bg-opacity-10 border-warning border-opacity-25 text-warning small text-start mt-3 mb-0">
                       <i class="bi bi-gear-fill me-2"></i>
                       <strong>Setup Required:</strong> ${escapeHtml(authState.error || 'Configure CLERK_PUBLISHABLE_KEY in your .env file.')}
                   </div>`
                : '';

            gateEl.innerHTML = `
                <div class="row justify-content-center py-5">
                    <div class="col-md-7 col-lg-5">
                        <div class="fs-card text-center p-4 p-md-5">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                                 style="width:60px;height:60px;background:rgba(56,189,248,0.12);border:1px solid rgba(56,189,248,0.28);">
                                <i class="bi bi-shield-lock-fill fs-3 text-info"></i>
                            </div>
                            <h4 class="fw-bold mb-2">Sign in to FreeSub</h4>
                            <p class="text-secondary small mb-4">
                                Authenticate with your account to claim free subdomains, configure Cloudflare DNS records, and manage SSL settings.
                            </p>
                            <div class="d-grid gap-2 sm-flex justify-content-center">
                                <button type="button" class="btn btn-fs-primary px-4" id="fsGateSignInBtn">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In / Create Account
                                </button>
                                <a href="index.php" class="btn btn-fs-outline btn-sm mt-1">
                                    <i class="bi bi-arrow-left me-1"></i>Back to Landing Page
                                </a>
                            </div>
                            ${configHint}
                        </div>
                    </div>
                </div>
            `;

            const gateBtn = gateEl.querySelector('#fsGateSignInBtn');
            if (gateBtn) {
                gateBtn.addEventListener('click', () => openSignInModal(window.location.href));
            }
        }

        if (options.autoPrompt !== false && authState.configured) {
            openSignInModal(options.redirectUrl || window.location.href);
        }

        return false;
    }

    // Export public FreeSub frontend toolkit onto `window.FreeSub`
    window.FreeSub = {
        initAuth,
        isSignedIn,
        getAuthToken,
        openSignInModal,
        openSignUpModal,
        signOutUser,
        renderAuthControls,
        requireSignInOrPrompt,
        loadBootstrapConfig,
        apiRequest,
        escapeHtml,
        formatDate,
        showToast,
        copyToClipboard,
    };
})(window);
