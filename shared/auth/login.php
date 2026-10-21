<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$redirectParam = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$destination = sia2_safe_redirect_target($redirectParam) ?? app_url('/pms/index.php');

if (is_authenticated()) {
    redirect_to($destination);
}

// One shared login page serves every YIELD system, but the requested system is
// now part of the authorization decision, not just the page's branding: the
// destination tells us which system this login attempt is for, and a correct
// username/password is only accepted if that account's system_access matches.
$activeSystem = sia2_infer_system_from_path($destination);
$themeSlug = str_replace('_', '-', $activeSystem);
$systemBranding = [
    'pms' => ['name' => 'Poultry Management System', 'blurb' => 'Sign in to manage flocks, inventory, and farm records.', 'icon' => 'bi-feather'],
    'ais' => ['name' => 'Accounting Information System', 'blurb' => 'Sign in to manage transactions, receivables, and payables.', 'icon' => 'bi-cash-coin'],
    'integrated' => ['name' => 'Integrated Farm Operations & Accounting', 'blurb' => 'Sign in to view combined operational and financial insights.', 'icon' => 'bi-diagram-3'],
    'user_management' => ['name' => 'User Management', 'blurb' => 'Sign in to manage platform user accounts and the audit trail.', 'icon' => 'bi-shield-lock'],
][$activeSystem];

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Login database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Please try again shortly.';
}

$error = null;
$submittedUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$pdo instanceof PDO) {
        $error = 'Sign-in is temporarily unavailable.';
    } else {
        $submittedUsername = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $statement = $pdo->prepare('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL');
        $statement->execute([$submittedUsername]);
        $user = $statement->fetch();

        $credentialsValid = $user
            && $user['status'] === 'Active'
            && password_verify($password, $user['password_hash']);

        if ($credentialsValid && $user['system_access'] === $activeSystem) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['user'] = [
                'user_id' => (int) $user['user_id'],
                'username' => $user['username'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
                'system_access' => $user['system_access'],
            ];
            $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$user['user_id']]);
            user_management_log_event($pdo, 'LOGIN', $user['username'], $user['username'], $activeSystem, 'Signed in to ' . $systemBranding['name'] . '.');
            set_flash('success', 'Signed in as ' . $user['username'] . '.', true);
            redirect_to($destination);
        }

        if ($credentialsValid && $user['system_access'] !== $activeSystem) {
            // Correct username + correct password, wrong system: tell the user exactly
            // why, since they already possess valid credentials for a real account.
            user_management_log_event(
                $pdo, 'LOGIN_FAILED', $user['username'], $user['username'], $activeSystem,
                'Valid credentials but account is not authorized for this system.'
            );
            $error = 'Your account is not authorized to access this system.';
        } else {
            // Wrong username, wrong password, inactive, or deleted: one generic message,
            // and never reveal which of those it was.
            user_management_log_event(
                $pdo, 'LOGIN_FAILED', null, $submittedUsername !== '' ? $submittedUsername : null, $activeSystem,
                'Invalid username or password, or account is not active.'
            );
            $error = 'The username or password is incorrect.';
        }
    }
}

$pageTitle = 'Sign In';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | YIELD <?= h($systemBranding['name']) ?></title>
    <link href="<?= h(versioned_asset_url('/shared/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= h(versioned_asset_url('/shared/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= h(versioned_asset_url('/shared/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body theme-<?= h($themeSlug) ?>">
<div class="auth-decor" aria-hidden="true"></div>
<main class="auth-shell">
    <section class="auth-card">
        <div class="auth-brand">
            <span class="sidebar-brand-icon" aria-hidden="true">
                <?php if ($activeSystem === 'user_management'): ?>
                    <i class="bi <?= h($systemBranding['icon']) ?>" aria-hidden="true"></i>
                <?php else: ?>
                    <img class="yield-brand-logo sidebar-logo" src="<?= h(logo_url($activeSystem)) ?>" alt="">
                <?php endif; ?>
            </span>
            <span class="sidebar-brand-copy">
                <strong>YIELD</strong>
                <small><?= h($systemBranding['name']) ?></small>
            </span>
        </div>

        <h1 class="auth-heading">Welcome Back</h1>
        <p class="auth-subheading"><?= h($systemBranding['blurb']) ?></p>

        <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php<?= $redirectParam !== '' ? '?redirect=' . urlencode($redirectParam) : '' ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="redirect" value="<?= h($redirectParam) ?>">
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <div class="input-group auth-input-group">
                    <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <input class="form-control" id="username" name="username" autocomplete="username" required autofocus value="<?= h($submittedUsername) ?>">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <div class="input-group auth-input-group">
                    <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                    <button class="btn btn-outline-secondary auth-toggle-password" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <button class="btn btn-primary w-100 auth-submit" type="submit">Sign In</button>
        </form>

        <a class="auth-back-link" href="<?= h(app_url('/')) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to YIELD
        </a>
    </section>
</main>
<script src="<?= h(app_url('/shared/assets/js/auth.js')) ?>"></script>
</body>
</html>
