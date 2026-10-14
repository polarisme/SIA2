<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_users';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'Edit User';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('User management database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

function is_last_active_it_admin(PDO $pdo, int $targetUserId): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE system_access = 'user_management' AND status = 'Active' AND deleted_at IS NULL AND user_id <> ?"
    );
    $statement->execute([$targetUserId]);

    return (int) $statement->fetchColumn() === 0;
}

$userId = filter_var($_GET['id'] ?? $_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1 || !$pdo instanceof PDO) {
    set_flash('danger', 'The selected user could not be found.');
    redirect_to('index.php');
}

$statement = $pdo->prepare('SELECT * FROM users WHERE user_id = ? AND deleted_at IS NULL');
$statement->execute([$userId]);
$user = $statement->fetch();
if (!$user) {
    set_flash('danger', 'The selected user could not be found.');
    redirect_to('index.php');
}

$formData = [
    'display_name' => $user['display_name'],
    'username' => $user['username'],
    'system_access' => $user['system_access'],
    'status' => $user['status'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('edit.php?id=' . $userId);
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
        $dupStatement = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $dupStatement->execute([$formData['username']]);
        $existingId = $dupStatement->fetchColumn();
        if ($existingId !== false && (int) $existingId !== $userId) {
            $errors['username'] = 'That username is already in use.';
        }
    }
    if (!array_key_exists($formData['system_access'], SYSTEM_ROLE_MAP)) {
        $errors['system_access'] = 'Select a valid system.';
    }
    if (!in_array($formData['status'], USER_STATUSES, true)) {
        $errors['status'] = 'Select a valid status.';
    }
    if ($password !== '' || $confirmPassword !== '') {
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $errors['password'] = 'Password and confirmation do not match.';
        }
    }

    // Safeguard: never let the last active IT Administrator deactivate themselves,
    // or edit away the last IT Administrator, into a total platform lockout.
    if ($errors === [] && $user['system_access'] === 'user_management' && $user['status'] === 'Active') {
        $wouldStayActiveItAdmin = $formData['status'] === 'Active' && $formData['system_access'] === 'user_management';
        if (!$wouldStayActiveItAdmin && is_last_active_it_admin($pdo, $userId)) {
            $errors['status'] = 'At least one active IT Administrator must remain — cannot change this account.';
        }
    }

    if ($errors === []) {
        $role = SYSTEM_ROLE_MAP[$formData['system_access']];
        $systemChanged = $formData['system_access'] !== $user['system_access'];

        if ($password !== '') {
            $updateStatement = $pdo->prepare(
                'UPDATE users SET display_name = ?, username = ?, system_access = ?, role = ?, status = ?, password_hash = ? WHERE user_id = ?'
            );
            $updateStatement->execute([$formData['display_name'], $formData['username'], $formData['system_access'], $role, $formData['status'], password_hash($password, PASSWORD_BCRYPT), $userId]);
            user_management_log_event($pdo, 'PASSWORD_RESET', current_username(), $formData['username'], $formData['system_access'], 'Password reset during profile edit.');
        } else {
            $updateStatement = $pdo->prepare(
                'UPDATE users SET display_name = ?, username = ?, system_access = ?, role = ?, status = ? WHERE user_id = ?'
            );
            $updateStatement->execute([$formData['display_name'], $formData['username'], $formData['system_access'], $role, $formData['status'], $userId]);
        }

        $description = 'User account details updated.';
        if ($systemChanged) {
            $description = 'System access changed from ' . SYSTEM_LABELS[$user['system_access']] . ' to ' . SYSTEM_LABELS[$formData['system_access']] . '.';
        }
        user_management_log_event($pdo, 'USER_EDITED', current_username(), $formData['username'], $formData['system_access'], $description);
        set_flash('success', 'User updated.');
        redirect_to('index.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">USER MANAGEMENT</div>
    <h1>Edit User</h1>
    <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to users</a>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<section class="content-panel" style="max-width: 640px">
    <div class="panel-body">
        <form method="post" action="edit.php?id=<?= $userId ?>" novalidate id="edit-user-form" data-original-system="<?= h($user['system_access']) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
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
                    <label class="form-label" for="password">New Password (leave blank to keep current)</label>
                    <div class="input-group auth-input-group">
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" autocomplete="new-password">
                        <button class="btn btn-outline-secondary auth-toggle-password" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <div class="input-group auth-input-group">
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="confirm_password" name="confirm_password" autocomplete="new-password">
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
                    <button class="btn btn-primary" type="submit">Save changes</button>
                    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</section>
<script>
(function () {
    var form = document.getElementById('edit-user-form');
    if (!form) { return; }
    var systemSelect = document.getElementById('system_access');
    var labels = <?= json_encode(SYSTEM_LABELS, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    form.addEventListener('submit', function (event) {
        var original = form.getAttribute('data-original-system');
        var chosen = systemSelect.value;
        if (chosen !== original) {
            var message = 'Change this user\'s system access from ' + labels[original] + ' to ' + labels[chosen] + '?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        }
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
