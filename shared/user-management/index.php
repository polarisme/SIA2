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

$showDeleted = (string) ($_GET['show_deleted'] ?? '') === '1';
$search = trim((string) ($_GET['search'] ?? ''));

$users = [];
$totalUsers = 0;
['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $conditions = [$showDeleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $parameters = [];
        if ($search !== '') {
            $conditions[] = '(username LIKE ? OR display_name LIKE ?)';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $conditions);

        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);
        $countStatement->execute($parameters);
        $totalUsers = (int) $countStatement->fetchColumn();

        $statement = $pdo->prepare(
            'SELECT * FROM users WHERE ' . $whereSql . " ORDER BY system_access, username LIMIT $perPage OFFSET $offset"
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
<div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="eyebrow">YIELD</div>
        <h1>User Management</h1>
        <p>Create and administer accounts for PMS, AIS, the Integrated System, and User Management itself. Each account is authorized for exactly one system.</p>
    </div>
    <a class="btn btn-outline-secondary" href="dashboard.php"><i class="bi bi-grid-1x2" aria-hidden="true"></i> IT Admin Dashboard</a>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <?php pagination_preserve_per_page_field($perPage); ?>
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
        <h2 class="h5 mb-0"><?= $showDeleted ? 'Recycle Bin — Deleted Users' : 'Users' ?> <span class="text-secondary small fw-normal"><?= number_format($totalUsers) ?></span></h2>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="audit-trail.php">View Audit Trail</a>
            <?php if (!$showDeleted): ?><a class="btn btn-primary btn-sm" href="create.php">Create User</a><?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 table-compact">
            <colgroup>
                <col style="width: 6%"><col style="width: 13%"><col style="width: 13%"><col style="width: 11%">
                <col style="width: 9%"><col style="width: 9%"><col style="width: 11%"><col style="width: 11%"><col style="width: 17%">
            </colgroup>
            <thead><tr><th>User ID</th><th>Username</th><th>Display Name</th><th>Assigned System</th><th>Role</th><th>Status</th><th>Date Created</th><th>Last Login</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No <?= $showDeleted ? 'deleted' : '' ?> users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int) $user['user_id'] ?></td>
                            <td><span class="cell-id" title="<?= h($user['username']) ?>"><?= h($user['username']) ?></span><?= (int) $user['user_id'] === $currentUserId ? ' <span class="badge text-bg-secondary">You</span>' : '' ?></td>
                            <td class="cell-wrap"><?= h($user['display_name']) ?></td>
                            <td class="cell-wrap"><span class="badge text-bg-<?= h(system_badge($user['system_access'])) ?>"><?= h(SYSTEM_LABELS[$user['system_access']] ?? $user['system_access']) ?></span></td>
                            <td class="cell-wrap"><?= h($user['role']) ?></td>
                            <td class="cell-wrap"><span class="badge text-bg-<?= h(user_status_badge($user['status'])) ?>"><?= h($user['status']) ?></span></td>
                            <td><span class="cell-id" title="<?= h((string) $user['created_at']) ?>"><?= h((string) $user['created_at']) ?></span></td>
                            <td><span class="cell-id" title="<?= h((string) ($user['last_login_at'] ?? '—')) ?>"><?= h((string) ($user['last_login_at'] ?? '—')) ?></span></td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap justify-content-end gap-1">
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
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination_controls($pageNum, $perPage, $totalUsers, $_GET, 'index.php'); ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
