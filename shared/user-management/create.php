<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_users';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'Create User';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('User management database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$formData = [
    'display_name' => '',
    'username' => '',
    'system_access' => 'pms',
    'status' => 'Active',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('create.php');
    }

    $formData['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
    $formData['username'] = trim((string) ($_POST['username'] ?? ''));
    $formData['system_access'] = trim((string) ($_POST['system_access'] ?? ''));
    $formData['status'] = trim((string) ($_POST['status'] ?? 'Active'));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($formData['display_name'] === '' || mb_strlen($formData['display_name']) > 150) {
        $errors['display_name'] = 'Display name is required (up to 150 characters).';
    }
    if ($formData['username'] === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $formData['username'])) {
        $errors['username'] = 'Username must be 3-50 characters (letters, numbers, underscore, period, hyphen only).';
    } else {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $statement->execute([$formData['username']]);
        if ((int) $statement->fetchColumn() > 0) {
            $errors['username'] = 'That username is already in use.';
        }
    }
    if (!array_key_exists($formData['system_access'], SYSTEM_ROLE_MAP)) {
        $errors['system_access'] = 'Select a valid system.';
    }
    if (!in_array($formData['status'], USER_STATUSES, true)) {
        $errors['status'] = 'Select a valid status.';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = 'Password and confirmation do not match.';
    }

    if ($errors === []) {
        $role = SYSTEM_ROLE_MAP[$formData['system_access']];
        $statement = $pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, system_access, role, status) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $formData['username'],
            $formData['display_name'],
            password_hash($password, PASSWORD_BCRYPT),
            $formData['system_access'],
            $role,
            $formData['status'],
        ]);
        user_management_log_event(
            $pdo, 'USER_CREATED', current_username(), $formData['username'], $formData['system_access'],
            'User account created with role ' . $role . '.'
        );
        set_flash('success', 'User created.');
        redirect_to('index.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">USER MANAGEMENT</div>
    <h1>Create User</h1>
    <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to users</a>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<section class="content-panel" style="max-width: 640px">
    <div class="panel-body">
        <form method="post" action="create.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="display_name">Display Name <span class="text-danger">*</span></label>
                    <input class="form-control<?= isset($errors['display_name']) ? ' is-invalid' : '' ?>" id="display_name" name="display_name" maxlength="150" required value="<?= h($formData['display_name']) ?>">
                    <?php if (isset($errors['display_name'])): ?><div class="invalid-feedback"><?= h($errors['display_name']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                    <input class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>" id="username" name="username" maxlength="50" required value="<?= h($formData['username']) ?>">
                    <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= h($errors['username']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                    <div class="input-group auth-input-group">
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary auth-toggle-password" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                    <div class="input-group auth-input-group">
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary auth-toggle-password" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <?php if (isset($errors['password'])): ?><div class="text-danger small mt-1"><?= h($errors['password']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="system_access">System Access <span class="text-danger">*</span></label>
                    <select class="form-select<?= isset($errors['system_access']) ? ' is-invalid' : '' ?>" id="system_access" name="system_access" required>
                        <?php foreach (SYSTEM_LABELS as $systemKey => $systemLabel): ?>
                            <option value="<?= h($systemKey) ?>" <?= $formData['system_access'] === $systemKey ? 'selected' : '' ?>><?= h($systemLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Each user may access exactly one system. Role is set automatically from this choice.</div>
                    <?php if (isset($errors['system_access'])): ?><div class="invalid-feedback"><?= h($errors['system_access']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status">Account Status <span class="text-danger">*</span></label>
                    <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required>
                        <?php foreach (USER_STATUSES as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $formData['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Create user</button>
                    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
