<?php

declare(strict_types=1);

/**
 * Local Passcode Login Screen (`/admin-desktop/login.php`)
 */

use FreeSub\Core\Config;

require_once __DIR__ . '/_auth.php';

// Support GET logout redirect if requested via `login.php?action=logout`
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logoutAdminSession();
    header('Location: login.php?logged_out=1');
    exit;
}

if (isAdminAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = null;
$loggedOutNotice = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';

// Basic brute-force throttling per session
$_SESSION['login_attempts'] ??= 0;
$_SESSION['login_locked_until'] ??= 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? null);

    $now = time();
    if ((int) $_SESSION['login_locked_until'] > $now) {
        $waitSeconds = (int) $_SESSION['login_locked_until'] - $now;
        $error = sprintf('Too many failed passcode attempts. Please wait %d seconds.', $waitSeconds);
    } else {
        $passcode = isset($_POST['passcode']) ? (string) $_POST['passcode'] : '';

        if (verifyAdminPasscode($passcode)) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_locked_until'] = 0;
            establishAdminSession();
            setFlash('success', 'Welcome to FreeSub Admin Desktop.');
            header('Location: index.php');
            exit;
        }

        $_SESSION['login_attempts'] = ((int) $_SESSION['login_attempts']) + 1;
        if ((int) $_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_locked_until'] = $now + 30;
            $_SESSION['login_attempts'] = 0;
            $error = 'Too many failed passcode attempts. Locked for 30 seconds.';
        } else {
            $error = 'Invalid local admin passcode. Check ADMIN_LOCAL_PASSCODE in your .env file.';
        }
    }
}

$appName = Config::get('APP_NAME', 'FreeSub') ?? 'FreeSub';
$usingDefaultPasscode = (Config::get('ADMIN_LOCAL_PASSCODE', 'change-this-admin-passcode') === 'change-this-admin-passcode');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unlock Admin Desktop — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #070b14;
            background-image:
                radial-gradient(circle at 20% 15%, rgba(56, 189, 248, 0.1) 0%, transparent 45%),
                radial-gradient(circle at 80% 85%, rgba(99, 102, 241, 0.1) 0%, transparent 45%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f8fafc;
            padding: 1.5rem;
        }
        .login-card {
            width: 100%;
            max-width: 430px;
            background: #111b31;
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 1rem;
            padding: 2.25rem;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
        }
        .brand-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: linear-gradient(135deg, #38bdf8 0%, #6366f1 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #04101d;
            font-size: 1.55rem;
        }
        .form-control {
            background-color: #0a1020 !important;
            border: 1px solid rgba(148, 163, 184, 0.28) !important;
            color: #f8fafc !important;
            padding: 0.7rem 0.95rem;
        }
        .form-control:focus {
            border-color: #38bdf8 !important;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
        }
        .btn-unlock {
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.68rem 1.2rem;
        }
        .btn-unlock:hover { color: #fff; filter: brightness(1.08); }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <div class="brand-icon mb-3">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h4 class="fw-bold mb-1"><?= e($appName) ?> Admin Desktop</h4>
            <p class="text-secondary small mb-0">
                Bound to local loopback (<span class="font-monospace text-info">127.0.0.1</span>)
            </p>
        </div>

        <?php if ($loggedOutNotice): ?>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-check-circle me-1"></i>Admin session locked successfully.
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger py-2 small">
                <i class="bi bi-exclamation-octagon-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($usingDefaultPasscode): ?>
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Security Notice:</strong> Set a custom <code>ADMIN_LOCAL_PASSCODE</code> in your <code>.env</code> file.
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <?= csrfInput() ?>

            <div class="mb-3">
                <label for="passcodeInput" class="form-label small text-uppercase fw-semibold text-secondary">
                    Local Admin Passcode
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                        <i class="bi bi-key-fill"></i>
                    </span>
                    <input type="password"
                           id="passcodeInput"
                           name="passcode"
                           class="form-control font-monospace"
                           placeholder="Enter ADMIN_LOCAL_PASSCODE"
                           autofocus
                           required>
                </div>
            </div>

            <button type="submit" class="btn btn-unlock w-100">
                <i class="bi bi-unlock-fill me-1"></i>Unlock Console
            </button>
        </form>
    </div>
</body>
</html>
