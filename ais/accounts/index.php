<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_accounts';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

function account_form_data(array $source): array
{
    return [
        'account_code' => trim((string) ($source['account_code'] ?? '')),
        'account_name' => trim((string) ($source['account_name'] ?? '')),
        'account_type' => trim((string) ($source['account_type'] ?? 'Asset')),
        'description' => trim((string) ($source['description'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Active')),
    ];
}

function validate_account(array $data): array
{
    $errors = [];
    if ($data['account_code'] === '' || mb_strlen($data['account_code']) > 20) {
        $errors['account_code'] = 'Account code is required (up to 20 characters).';
    }
    if ($data['account_name'] === '' || mb_strlen($data['account_name']) > 100) {
        $errors['account_name'] = 'Account name is required (up to 100 characters).';
    }
    if (!in_array($data['account_type'], AIS_ACCOUNT_TYPES, true)) {
        $errors['account_type'] = 'Select a valid account type.';
    }
    if (mb_strlen($data['description']) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    if (!in_array($data['status'], ['Active', 'Inactive'], true)) {
        $errors['status'] = 'Select a valid status.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS accounts database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create' || $action === 'update') {
        $isEditing = $action === 'update';
        $accountId = $isEditing ? filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($isEditing && ($accountId === false || $accountId < 1)) {
            set_flash('danger', 'The selected account could not be found.');
            redirect_to('index.php');
        }

        $formData = account_form_data($_POST);
        $errors = validate_account($formData);

        if ($errors === []) {
            $description = $formData['description'] !== '' ? $formData['description'] : null;
            try {
                if ($isEditing) {
                    $statement = $pdo->prepare(
                        'UPDATE accounts SET account_code = :account_code, account_name = :account_name,
                         account_type = :account_type, description = :description, status = :status
                         WHERE account_id = :account_id'
                    );
                    $statement->execute($formData + ['description' => $description, 'account_id' => $accountId]);
                    set_flash('success', 'Account updated.');
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO accounts (account_code, account_name, account_type, description, status)
                         VALUES (:account_code, :account_name, :account_type, :description, :status)'
                    );
                    $statement->execute($formData + ['description' => $description]);
                    set_flash('success', 'Account added.');
                }
                redirect_to('index.php');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors['account_code'] = 'That account code is already in use.';
                } else {
                    throw $exception;
                }
            }
        }
        $action = $isEditing ? 'edit' : 'add';
    } elseif ($action === 'toggle_status') {
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
        if ($accountId === false || $accountId < 1) {
            set_flash('danger', 'The selected account could not be found.');
            redirect_to('index.php');
        }
        $pdo->prepare("UPDATE accounts SET status = IF(status = 'Active', 'Inactive', 'Active') WHERE account_id = ?")->execute([$accountId]);
        set_flash('success', 'Account status updated.');
        redirect_to('index.php');
    }
}

// ---------------------------------------------------------------------------
// Add / edit form
// ---------------------------------------------------------------------------
if (in_array($action, ['add', 'edit'], true) && $pdo instanceof PDO) {
    $isEditing = $action === 'edit';
    $account = null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($isEditing) {
            $accountId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($accountId !== false && $accountId >= 1) {
                $statement = $pdo->prepare('SELECT * FROM accounts WHERE account_id = ?');
                $statement->execute([$accountId]);
                $account = $statement->fetch();
            }
            if (!$account) {
                set_flash('danger', 'The selected account could not be found.');
                redirect_to('index.php');
            }
        }
        $formData = account_form_data($isEditing ? $account : []);
        $errors = [];
    } else {
        $account = $isEditing ? ['account_id' => $accountId] : null;
    }

    $pageTitle = $isEditing ? 'Edit Account' : 'Add Account';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">CHART OF ACCOUNTS</div>
        <h1><?= $isEditing ? 'Edit Account' : 'Add Account' ?></h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to accounts</a>
    </div>
    <section class="content-panel" style="max-width: 640px">
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'update' : 'create' ?><?= $isEditing ? '&id=' . (int) $account['account_id'] : '' ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <?php if ($isEditing): ?><input type="hidden" name="account_id" value="<?= (int) $account['account_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="account_code">Account code <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['account_code']) ? ' is-invalid' : '' ?>" id="account_code" name="account_code" maxlength="20" required value="<?= h($formData['account_code']) ?>">
                        <?php if (isset($errors['account_code'])): ?><div class="invalid-feedback"><?= h($errors['account_code']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="account_name">Account name <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['account_name']) ? ' is-invalid' : '' ?>" id="account_name" name="account_name" maxlength="100" required value="<?= h($formData['account_name']) ?>">
                        <?php if (isset($errors['account_name'])): ?><div class="invalid-feedback"><?= h($errors['account_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="account_type">Account type <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['account_type']) ? ' is-invalid' : '' ?>" id="account_type" name="account_type" required>
                            <?php foreach (AIS_ACCOUNT_TYPES as $type): ?>
                                <option value="<?= h($type) ?>" <?= $formData['account_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['account_type'])): ?><div class="invalid-feedback"><?= h($errors['account_type']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required>
                            <option value="Active" <?= $formData['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $formData['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <input class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" maxlength="255" value="<?= h($formData['description']) ?>">
                        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Save account' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$typeFilter = trim((string) ($_GET['account_type'] ?? ''));
if ($typeFilter !== '' && !in_array($typeFilter, AIS_ACCOUNT_TYPES, true)) {
    $typeFilter = '';
}
$search = trim((string) ($_GET['search'] ?? ''));

$conditions = [];
$parameters = [];
if ($typeFilter !== '') {
    $conditions[] = 'account_type = ?';
    $parameters[] = $typeFilter;
}
if ($search !== '') {
    $conditions[] = '(account_code LIKE ? OR account_name LIKE ?)';
    $parameters[] = '%' . $search . '%';
    $parameters[] = '%' . $search . '%';
}
$whereSql = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

$accounts = [];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $statement = $pdo->prepare("SELECT * FROM accounts $whereSql ORDER BY account_code");
        $statement->execute($parameters);
        $accounts = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('AIS accounts list query failed: ' . $exception->getMessage());
        $databaseError = 'Accounts could not be loaded because of a database error.';
    }
}

$pageTitle = 'Accounts';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">ACCOUNT MASTER</div>
    <h1>Chart of Accounts</h1>
    <p>Reference list of accounts used for classifying income and expenses.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" placeholder="Account code or name" value="<?= h($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="account_type">Account type</label>
                <select class="form-select" id="account_type" name="account_type">
                    <option value="">All types</option>
                    <?php foreach (AIS_ACCOUNT_TYPES as $type): ?>
                        <option value="<?= h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button>
                <a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Accounts</h2>
        <a class="btn btn-primary btn-sm" href="index.php?action=add">Add account</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Code</th><th>Account name</th><th>Type</th><th>Description</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($accounts === []): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">No accounts match the current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?= h($account['account_code']) ?></td>
                            <td><?= h($account['account_name']) ?></td>
                            <td><?= h($account['account_type']) ?></td>
                            <td><?= h($account['description'] ?: '—') ?></td>
                            <td><span class="badge text-bg-<?= $account['status'] === 'Active' ? 'success' : 'secondary' ?>"><?= h($account['status']) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $account['account_id'] ?>">Edit</a>
                                <form method="post" action="index.php?action=toggle_status" class="d-inline" onsubmit="return confirm('<?= $account['status'] === 'Active' ? 'Deactivate' : 'Reactivate' ?> this account?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['account_id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $account['status'] === 'Active' ? 'Deactivate' : 'Reactivate' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
