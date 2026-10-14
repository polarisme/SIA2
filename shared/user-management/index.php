<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_users';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'User Management';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('User management database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$currentUserId = (int) ($_SESSION['user']['user_id'] ?? 0);

/** True if deactivating/deleting $targetUserId would leave zero active IT Administrators. */
function is_last_active_it_admin(PDO $pdo, int $targetUserId): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE system_access = 'user_management' AND status = 'Active' AND deleted_at IS NULL AND user_id <> ?"
    );
    $statement->execute([$targetUserId]);

    return (int) $statement->fetchColumn() === 0;
}

$counts = ['total' => 0, 'active' => 0, 'inactive' => 0, 'pms' => 0, 'ais' => 0, 'integrated' => 0, 'user_management' => 0];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'Active') AS active,
                    SUM(status = 'Inactive') AS inactive,
                    SUM(system_access = 'pms') AS pms,
                    SUM(system_access = 'ais') AS ais,
                    SUM(system_access = 'integrated') AS integrated,
                    SUM(system_access = 'user_management') AS user_management
             FROM users WHERE deleted_at IS NULL"
        )->fetch();
        $counts = array_map('intval', $row);
    } catch (PDOException $exception) {
        error_log('User management dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Dashboard figures could not be loaded because of a database error.';
    }
}

$showDeleted = (string) ($_GET['show_deleted'] ?? '') === '1';
$search = trim((string) ($_GET['search'] ?? ''));

$users = [];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $conditions = [$showDeleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $parameters = [];
        if ($search !== '') {
            $conditions[] = '(username LIKE ? OR display_name LIKE ?)';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
        }
        $statement = $pdo->prepare(
            'SELECT * FROM users WHERE ' . implode(' AND ', $conditions) . ' ORDER BY system_access, username'
        );
        $statement->execute($parameters);
        $users = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('User management list query failed: ' . $exception->getMessage());
        $databaseError = 'Users could not be loaded because of a database error.';
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">YIELD</div>
    <h1>User Management</h1>
    <p>Create and administer accounts for PMS, AIS, the Integrated System, and User Management itself. Each account is authorized for exactly one system.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value"><?= number_format($counts['total']) ?></div></div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">Active</div><div class="stat-value text-success"><?= number_format($counts['active']) ?></div></div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">Inactive</div><div class="stat-value"><?= number_format($counts['inactive']) ?></div></div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">PMS Users</div><div class="stat-value"><?= number_format($counts['pms']) ?></div></div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">AIS Users</div><div class="stat-value"><?= number_format($counts['ais']) ?></div></div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card"><div class="stat-label">Integrated Users</div><div class="stat-value"><?= number_format($counts['integrated']) ?></div></div>
    </div>
</div>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" value="<?= h($search) ?>" placeholder="Username or display name">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="show_deleted" name="show_deleted" value="1" <?= $showDeleted ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="show_deleted">Show Recycle Bin (deleted users)</label>
                </div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="index.php">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0"><?= $showDeleted ? 'Recycle Bin — Deleted Users' : 'Users' ?></h2>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="audit-trail.php">View Audit Trail</a>
            <?php if (!$showDeleted): ?><a class="btn btn-primary btn-sm" href="create.php">Create User</a><?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>User ID</th><th>Username</th><th>Display Name</th><th>Assigned System</th><th>Role</th><th>Status</th><th>Date Created</th><th>Last Login</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No <?= $showDeleted ? 'deleted' : '' ?> users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int) $user['user_id'] ?></td>
                            <td><?= h($user['username']) ?><?= (int) $user['user_id'] === $currentUserId ? ' <span class="badge text-bg-secondary">You</span>' : '' ?></td>
                            <td><?= h($user['display_name']) ?></td>
                            <td><span class="badge text-bg-<?= h(system_badge($user['system_access'])) ?>"><?= h(SYSTEM_LABELS[$user['system_access']] ?? $user['system_access']) ?></span></td>
                            <td><?= h($user['role']) ?></td>
                            <td><span class="badge text-bg-<?= h(user_status_badge($user['status'])) ?>"><?= h($user['status']) ?></span></td>
                            <td><?= h((string) $user['created_at']) ?></td>
                            <td><?= h((string) ($user['last_login_at'] ?? '—')) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($showDeleted): ?>
                                    <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Restore this user account?\nUsername: <?= h($user['username']) ?>\nThey will be able to sign in again (if Active).');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                        <input type="hidden" name="mode" value="restore">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Restore</button>
                                    </form>
                                    <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('PERMANENTLY delete this user account?\nUsername: <?= h($user['username']) ?>\nThis cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                        <input type="hidden" name="mode" value="permanent">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete Permanently</button>
                                    </form>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int) $user['user_id'] ?>">Edit</a>
                                    <form method="post" action="deactivate.php" class="d-inline" onsubmit="return confirm('<?= $user['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> this user?\nUsername: <?= h($user['username']) ?><?= $user['status'] === 'Active' ? '\nThey will no longer be able to sign in.' : '' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $user['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                    <?php if ((int) $user['user_id'] !== $currentUserId): ?>
                                        <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this user account?\nUsername: <?= h($user['username']) ?>\nThis account will move to the Recycle Bin and can be restored later, but will immediately lose sign-in access.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                            <input type="hidden" name="mode" value="soft">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
