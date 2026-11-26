<?php

declare(strict_types=1);

/**
 * Local Admin Desktop Security, Session & Shared Layout Helper (`/admin-desktop/_auth.php`)
 *
 * Enforces:
 * - Strict local loopback access check (`127.0.0.1` / `::1`) unless explicitly overridden
 * - Secure PHP session management (`HttpOnly`, `SameSite=Strict`)
 * - Constant-time `ADMIN_LOCAL_PASSCODE` verification (`hash_equals`)
 * - Per-session CSRF token generation & POST verification
 * - Flash notification helpers & shared desktop shell layout renderer
 */

use FreeSub\Core\Config;
use FreeSub\Core\Database;

require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Validator.php';
require_once dirname(__DIR__) . '/core/CloudflareService.php';

// Restrict Admin Desktop strictly to local loopback requests unless explicitly allowed in .env
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$allowRemoteAdmin = Config::getBool('ADMIN_ALLOW_REMOTE', false);
if (!$allowRemoteAdmin && !in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden: FreeSub Admin Desktop is restricted to local loopback (127.0.0.1).');
}

// Start hardened PHP session
if (session_status() === PHP_SESSION_NONE) {
    session_name('FREESUB_ADMIN_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Security headers for Admin Desktop pages
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

/**
 * Escape string safely for HTML output.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Check if the current session is authenticated as local administrator.
 */
function isAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;
}

/**
 * Redirect unauthenticated visitors to `login.php`, and handle `?logout=1` if requested.
 */
function requireAdminAuth(): void
{
    if (isset($_POST['admin_logout']) || (isset($_GET['action']) && $_GET['action'] === 'logout')) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken($_POST['csrf_token'] ?? null);
        }
        logoutAdminSession();
        header('Location: login.php?logged_out=1');
        exit;
    }

    if (!isAdminAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Verify a candidate passcode against `ADMIN_LOCAL_PASSCODE` in constant time.
 */
function verifyAdminPasscode(string $inputPasscode): bool
{
    $expected = Config::get('ADMIN_LOCAL_PASSCODE', 'change-this-admin-passcode') ?? '';
    if ($expected === '' || $inputPasscode === '') {
        return false;
    }

    return hash_equals($expected, $inputPasscode);
}

/**
 * Establish an authenticated admin session and regenerate session ID.
 */
function establishAdminSession(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_login_at'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Destroy the active admin session cleanly.
 */
function logoutAdminSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

/**
 * Retrieve (or lazily generate) the per-session CSRF token.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden `<input type="hidden" name="csrf_token" ...>` field.
 */
function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(getCsrfToken()) . '">';
}

/**
 * Validate a submitted CSRF token or halt/redirect with an error flash.
 */
function verifyCsrfToken(?string $submittedToken): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submittedToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        setFlash('danger', 'Security check failed (invalid or expired CSRF token). Please try again.');
        $fallback = basename($_SERVER['PHP_SELF'] ?? 'index.php');
        header('Location: ' . $fallback);
        exit;
    }
}

/**
 * Store a one-time flash message in session.
 *
 * @param 'success'|'danger'|'warning'|'info' $type
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['admin_flashes'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Consume and return all queued flash messages.
 *
 * @return list<array{type: string, message: string}>
 */
function consumeFlashes(): array
{
    $flashes = $_SESSION['admin_flashes'] ?? [];
    unset($_SESSION['admin_flashes']);
    return is_array($flashes) ? $flashes : [];
}

/**
 * Fetch a setting value from `settings` with fallback default.
 */
function adminGetSetting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();

    if ($row === false || !isset($row['setting_value']) || trim((string) $row['setting_value']) === '') {
        return $default;
    }

    return trim((string) $row['setting_value']);
}

/**
 * Upsert a setting key/value pair in `settings`.
 */
function adminSaveSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (:key, :val)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([
        ':key' => $key,
        ':val' => $value,
    ]);
}

/**
 * Mask a sensitive API token for safe display (e.g. `••••••••9f3a`).
 */
function maskSecret(string $secret): string
{
    $len = strlen($secret);
    if ($len === 0) {
        return '—';
    }
    if ($len <= 8) {
        return str_repeat('•', $len);
    }

    return substr($secret, 0, 4) . '••••••••••••' . substr($secret, -4);
}

/**
 * Render the top HTML + Desktop Sidebar Layout shell.
 */
function renderAdminHeader(string $pageTitle, string $activeNav = 'overview'): void
{
    $appName = Config::get('APP_NAME', 'FreeSub') ?? 'FreeSub';
    $flashes = consumeFlashes();

    $navItems = [
        'overview' => ['href' => 'index.php', 'icon' => 'bi-speedometer2', 'label' => 'Overview'],
        'domains' => ['href' => 'domains.php', 'icon' => 'bi-globe2', 'label' => 'Root Domains'],
        'subdomains' => ['href' => 'subdomains.php', 'icon' => 'bi-hdd-network', 'label' => 'Subdomains'],
        'users' => ['href' => 'users.php', 'icon' => 'bi-people-fill', 'label' => 'Users'],
        'settings' => ['href' => 'settings.php', 'icon' => 'bi-sliders', 'label' => 'Global Settings'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($appName) ?> Admin Desktop</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --ad-bg: #080d19;
            --ad-sidebar: #0c1324;
            --ad-card: #111b31;
            --ad-input: #0a1020;
            --ad-border: rgba(148, 163, 184, 0.15);
            --ad-border-strong: rgba(148, 163, 184, 0.28);
            --ad-accent: #38bdf8;
            --ad-indigo: #6366f1;
            --ad-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--ad-bg);
            color: #f8fafc;
            min-height: 100vh;
            margin: 0;
        }
        .font-mono { font-family: var(--ad-mono) !important; }
        .ad-shell {
            display: flex;
            min-height: 100vh;
        }
        .ad-sidebar {
            width: 255px;
            flex-shrink: 0;
            background-color: var(--ad-sidebar);
            border-right: 1px solid var(--ad-border);
            display: flex;
            flex-direction: column;
            padding: 1.25rem 0.9rem;
        }
        .ad-brand {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.35rem 0.6rem 1.15rem;
            border-bottom: 1px solid var(--ad-border);
            margin-bottom: 1rem;
            text-decoration: none;
            color: #f8fafc;
        }
        .ad-brand-badge {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: linear-gradient(135deg, var(--ad-accent) 0%, var(--ad-indigo) 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #04101d;
            font-size: 1.1rem;
            font-weight: 800;
        }
        .ad-nav-link {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.65rem 0.85rem;
            border-radius: 0.55rem;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.92rem;
            margin-bottom: 0.25rem;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .ad-nav-link:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #f8fafc;
        }
        .ad-nav-link.active {
            background: rgba(56, 189, 248, 0.13);
            color: var(--ad-accent);
            font-weight: 600;
            border: 1px solid rgba(56, 189, 248, 0.25);
        }
        .ad-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .ad-topbar {
            background: rgba(12, 19, 36, 0.85);
            border-bottom: 1px solid var(--ad-border);
            padding: 0.85rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .ad-content {
            padding: 1.75rem;
            flex: 1;
        }
        .ad-card {
            background-color: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-radius: 0.85rem;
            box-shadow: 0 8px 26px rgba(0, 0, 0, 0.24);
        }
        .ad-card-header {
            padding: 1rem 1.35rem;
            border-bottom: 1px solid var(--ad-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .ad-card-body { padding: 1.35rem; }
        .form-control, .form-select {
            background-color: var(--ad-input) !important;
            border: 1px solid var(--ad-border-strong) !important;
            color: #f8fafc !important;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--ad-accent) !important;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18) !important;
        }
        .form-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
            color: #94a3b8;
        }
        .btn-ad-primary {
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
            border: none;
            color: #fff;
            font-weight: 600;
        }
        .btn-ad-primary:hover { color: #fff; filter: brightness(1.08); }
        .ad-table {
            --bs-table-bg: transparent;
            --bs-table-color: #f8fafc;
            --bs-table-border-color: var(--ad-border);
            margin-bottom: 0;
        }
        .ad-table thead th {
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            background: rgba(8, 13, 25, 0.55);
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--ad-border);
        }
        .ad-table tbody td {
            padding: 0.88rem 1rem;
            vertical-align: middle;
        }
        .ad-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.24rem 0.62rem;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 600;
        }
        .ad-badge-active { background: rgba(16,185,129,0.14); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
        .ad-badge-suspended { background: rgba(239,68,68,0.14); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .ad-badge-pro { background: rgba(99,102,241,0.18); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.35); }
        .ad-badge-free { background: rgba(148,163,184,0.14); color: #cbd5e1; border: 1px solid var(--ad-border); }
        .modal-content.ad-modal {
            background-color: var(--ad-card);
            border: 1px solid var(--ad-border-strong);
            color: #f8fafc;
        }
        @media (max-width: 991.98px) {
            .ad-shell { flex-direction: column; }
            .ad-sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--ad-border); }
        }
    </style>
</head>
<body>
<div class="ad-shell">
    <!-- Left Sidebar -->
    <aside class="ad-sidebar">
        <a href="index.php" class="ad-brand">
            <span class="ad-brand-badge"><i class="bi bi-shield-shaded"></i></span>
            <div>
                <div class="fw-bold lh-sm"><?= e($appName) ?></div>
                <div class="text-secondary" style="font-size: 0.72rem;">Admin Desktop Console</div>
            </div>
        </a>

        <nav class="flex-grow-1">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= e($item['href']) ?>" class="ad-nav-link <?= $activeNav === $key ? 'active' : '' ?>">
                    <i class="bi <?= e($item['icon']) ?>"></i>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="pt-3 mt-3 border-top border-secondary border-opacity-25">
            <div class="d-flex align-items-center justify-content-between px-2 mb-2 small text-secondary">
                <span><i class="bi bi-pc-display me-1 text-success"></i>Local Loopback</span>
                <span class="font-mono">127.0.0.1</span>
            </div>
            <form method="post" action="index.php" class="m-0">
                <?= csrfInput() ?>
                <input type="hidden" name="admin_logout" value="1">
                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                    <i class="bi bi-box-arrow-left me-1"></i>Lock / Sign Out
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Panel -->
    <div class="ad-main">
        <header class="ad-topbar">
            <h1 class="h5 fw-bold mb-0"><?= e($pageTitle) ?></h1>
            <div class="d-flex align-items-center gap-2 small text-secondary">
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                    <i class="bi bi-lock-fill me-1"></i>Session Unlocked
                </span>
            </div>
        </header>

        <div class="ad-content">
            <?php foreach ($flashes as $flash):
                $bsType = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
            ?>
                <div class="alert alert-<?= e($bsType) ?> alert-dismissible fade show small shadow-sm mb-3" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
    <?php
}

/**
 * Render closing Desktop Layout tags and Bootstrap JS bundle.
 */
function renderAdminFooter(): void
{
    ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}
