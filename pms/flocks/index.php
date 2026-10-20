<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

function flock_form_data(array $source): array
{
    return [
        'batch_code' => trim((string) ($source['batch_code'] ?? '')),
        'breed' => trim((string) ($source['breed'] ?? '')),
        'start_date' => trim((string) ($source['start_date'] ?? '')),
        'age_at_arrival_days' => trim((string) ($source['age_at_arrival_days'] ?? '0')),
        'initial_birds' => trim((string) ($source['initial_birds'] ?? '')),
        'current_birds' => trim((string) ($source['current_birds'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Active')),
        'notes' => trim((string) ($source['notes'] ?? '')),
    ];
}

function validate_flock(array $flock): array
{
    $errors = [];
    $validStatuses = ['Active', 'Completed', 'Sold', 'Terminated'];

    if ($flock['batch_code'] === '') {
        $errors['batch_code'] = 'Batch code is required.';
    } elseif (mb_strlen($flock['batch_code']) > 50) {
        $errors['batch_code'] = 'Batch code must be 50 characters or fewer.';
    }

    if ($flock['breed'] === '') {
        $errors['breed'] = 'Breed is required.';
    } elseif (mb_strlen($flock['breed']) > 100) {
        $errors['breed'] = 'Breed must be 100 characters or fewer.';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $flock['start_date']);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($flock['start_date'] === '' || $date === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $flock['start_date']) {
        $errors['start_date'] = 'Enter a valid start date.';
    }

    $ageAtArrival = filter_var($flock['age_at_arrival_days'], FILTER_VALIDATE_INT);
    if ($flock['age_at_arrival_days'] === '' || $ageAtArrival === false || $ageAtArrival < 0) {
        $errors['age_at_arrival_days'] = 'Age at arrival must be a whole number of zero or more days.';
    }

    $initialBirds = filter_var($flock['initial_birds'], FILTER_VALIDATE_INT);
    if ($flock['initial_birds'] === '' || $initialBirds === false || $initialBirds <= 0) {
        $errors['initial_birds'] = 'Initial birds must be a whole number greater than zero.';
    }

    $currentBirds = filter_var($flock['current_birds'], FILTER_VALIDATE_INT);
    if ($flock['current_birds'] === '' || $currentBirds === false || $currentBirds < 0) {
        $errors['current_birds'] = 'Current birds must be a whole number of zero or more.';
    }

    if (!in_array($flock['status'], $validStatuses, true)) {
        $errors['status'] = 'Select a valid flock status.';
    }

    return $errors;
}

function flock_status_badge(string $status): string
{
    return match ($status) {
        'Active' => 'success',
        'Completed' => 'primary',
        'Sold' => 'secondary',
        'Terminated' => 'danger',
        default => 'light',
    };
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Flock management database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
$activeNavigation = 'flocks';
$canManage = can_manage_flocks();
$errors = [];
$formData = flock_form_data([]);
$flock = null;
$isEditing = false;
$pageTitle = 'Flock Dashboard';
$stats = [
    'active_flocks' => 0,
    'total_birds' => 0,
    'completed_flocks' => 0,
    'sold_flocks' => 0,
    'terminated_flocks' => 0,
];
$records = [];
$databaseFailure = $databaseError !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && !$databaseFailure) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if (!$canManage) {
        set_flash('danger', 'You are not authorized to change flock records.');
        redirect_to('index.php');
    }

    try {
        if ($action === 'create' || $action === 'update') {
            $formData = flock_form_data($_POST);
            $errors = validate_flock($formData);
            $isEditing = $action === 'update';
            $flockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);

            if ($isEditing && ($flockId === false || $flockId < 1)) {
                set_flash('danger', 'The selected flock could not be found.');
                redirect_to('index.php');
            }

            if ($isEditing) {
                $exists = $pdo->prepare('SELECT flock_id FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
                $exists->execute([$flockId]);
                if ($exists->fetchColumn() === false) {
                    set_flash('danger', 'The selected flock could not be found.');
                    redirect_to('index.php');
                }
            }

            if ($errors === []) {
                try {
                    if ($isEditing) {
                        $statement = $pdo->prepare(
                            'UPDATE flocks
                             SET batch_code = :batch_code, breed = :breed, start_date = :start_date,
                                 age_at_arrival_days = :age_at_arrival_days, initial_birds = :initial_birds, current_birds = :current_birds,
                                 status = :status, notes = :notes
                             WHERE flock_id = :flock_id AND deleted_at IS NULL'
                        );
                        $statement->execute($formData + ['flock_id' => $flockId]);
                        audit_pms_action($pdo, 'UPDATE', 'flocks', $flockId);
                        set_flash('success', 'Flock information updated.');
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO flocks
                                (batch_code, breed, start_date, age_at_arrival_days, initial_birds, current_birds, status, notes)
                             VALUES
                                (:batch_code, :breed, :start_date, :age_at_arrival_days, :initial_birds, :current_birds, :status, :notes)'
                        );
                        $statement->execute($formData);
                        audit_pms_action($pdo, 'CREATE', 'flocks', (int) $pdo->lastInsertId());
                        set_flash('success', 'Flock registered successfully.');
                    }

                    redirect_to($isEditing ? 'index.php?action=view&id=' . $flockId : 'index.php');
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        $errors['batch_code'] = 'That batch code is already in use.';
                    } else {
                        throw $exception;
                    }
                }
            }

            $action = $isEditing ? 'edit' : 'add';
            $pageTitle = $isEditing ? 'Edit Flock' : 'Add Flock';
            if ($isEditing) {
                $flock = ['flock_id' => $flockId] + $formData;
            }
        } elseif ($action === 'delete') {
            $flockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
            if ($flockId === false || $flockId < 1) {
                set_flash('danger', 'The selected flock could not be found.');
                redirect_to('index.php');
            }
            $identity = $pdo->prepare('SELECT batch_code FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $identity->execute([$flockId]);
            $batchCode = $identity->fetchColumn();
            if ($batchCode === false) {
                set_flash('danger', 'The selected active flock could not be found.');
                redirect_to('index.php');
            }
            try {
                if (!pms_soft_delete($pdo, 'flocks', $flockId)) {
                    set_flash('danger', 'The flock could not be moved to the Recycle Bin.');
                } else {
                    set_flash('success', 'Flock ' . $batchCode . ' moved to the Recycle Bin.');
                }
            } catch (RuntimeException $exception) {
                set_flash('danger', $exception->getMessage());
            }
            redirect_to('index.php');
        } elseif ($action === 'status') {
            $flockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            $validStatuses = ['Active', 'Completed', 'Sold', 'Terminated'];

            if ($flockId === false || $flockId < 1 || !in_array($status, $validStatuses, true)) {
                set_flash('danger', 'Choose a valid flock and status.');
                redirect_to('index.php');
            }

            $statement = $pdo->prepare('UPDATE flocks SET status = :status WHERE flock_id = :flock_id AND deleted_at IS NULL');
            $statement->execute(['status' => $status, 'flock_id' => $flockId]);
            if ($statement->rowCount() === 1) {
                audit_pms_action($pdo, 'UPDATE', 'flocks', $flockId);
            }
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
                $exists->execute([$flockId]);
                if ($exists->fetchColumn() === false) {
                    set_flash('danger', 'The selected flock could not be found.');
                    redirect_to('index.php');
                }
            }

            set_flash('success', 'Flock status updated.');
            redirect_to('index.php?action=view&id=' . $flockId);
        } else {
            set_flash('danger', 'The requested action is not available.');
            redirect_to('index.php');
        }
    } catch (PDOException $exception) {
        error_log('Flock management request failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'The flock record could not be saved because of a database error.';
    }
}

if ($pdo instanceof PDO && !$databaseFailure && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        if (in_array($action, ['add', 'edit'], true)) {
            if (!$canManage) {
                set_flash('warning', 'Sign in as an administrator to add or edit flock records.');
                redirect_to('index.php');
            }

            $isEditing = $action === 'edit';
            $pageTitle = $isEditing ? 'Edit Flock' : 'Add Flock';
            if ($isEditing) {
                $flockId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                if ($flockId === false || $flockId < 1) {
                    set_flash('danger', 'The selected flock could not be found.');
                    redirect_to('index.php');
                }

                $statement = $pdo->prepare('SELECT * FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
                $statement->execute([$flockId]);
                $flock = $statement->fetch();
                if (!$flock) {
                    set_flash('danger', 'The selected flock could not be found.');
                    redirect_to('index.php');
                }
                $formData = flock_form_data($flock);
            }
        } elseif ($action === 'view') {
            $flockId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($flockId === false || $flockId < 1) {
                set_flash('danger', 'The selected flock could not be found.');
                redirect_to('index.php');
            }

            $statement = $pdo->prepare('SELECT * FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $statement->execute([$flockId]);
            $flock = $statement->fetch();
            if (!$flock) {
                set_flash('danger', 'The selected flock could not be found.');
                redirect_to('index.php');
            }
            $pageTitle = 'Flock ' . $flock['batch_code'];
        } else {
            $action = 'list';
            $summary = $pdo->query(
                "SELECT
                    COALESCE(SUM(status = 'Active'), 0) AS active_flocks,
                    COALESCE(SUM(CASE WHEN status = 'Active' THEN current_birds ELSE 0 END), 0) AS total_birds,
                    COALESCE(SUM(status = 'Completed'), 0) AS completed_flocks,
                    COALESCE(SUM(status = 'Sold'), 0) AS sold_flocks,
                    COALESCE(SUM(status = 'Terminated'), 0) AS terminated_flocks
                 FROM flocks WHERE deleted_at IS NULL"
            )->fetch();
            if ($summary) {
                $stats = $summary;
            }

            $conditions = ['deleted_at IS NULL'];
            $parameters = [];
            $batchSearch = trim((string) ($_GET['batch_code'] ?? ''));
            $breedSearch = trim((string) ($_GET['breed'] ?? ''));
            $statusFilter = trim((string) ($_GET['status'] ?? ''));

            if ($batchSearch !== '') {
                $conditions[] = 'batch_code LIKE :batch_code';
                $parameters['batch_code'] = '%' . $batchSearch . '%';
            }
            if ($breedSearch !== '') {
                $conditions[] = 'breed LIKE :breed';
                $parameters['breed'] = '%' . $breedSearch . '%';
            }
            if (in_array($statusFilter, ['Active', 'Completed', 'Sold', 'Terminated'], true)) {
                $conditions[] = 'status = :status';
                $parameters['status'] = $statusFilter;
            } else {
                $statusFilter = '';
            }

            $whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

            $countStatement = $pdo->prepare('SELECT COUNT(*) FROM flocks' . $whereSql);
            $countStatement->execute($parameters);
            $totalRecords = (int) $countStatement->fetchColumn();

            ['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();

            $sql = 'SELECT flock_id, batch_code, breed, start_date, age_at_arrival_days, initial_birds, current_birds, status
                    FROM flocks' . $whereSql . ' ORDER BY created_at DESC, flock_id DESC
                    LIMIT ' . $perPage . ' OFFSET ' . $offset;
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $records = $statement->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Flock management query failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'Flock information could not be loaded. Check the database connection and schema.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div>
<?php endif; ?>

<?php if (!$canManage && !$databaseFailure): ?>
    <div class="alert alert-warning role-note" role="status">
        Read-only access. <a href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in as an administrator</a> to add, edit, or change flock status.
    </div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1><?= $isEditing ? 'Edit flock' : 'Register a flock' ?></h1>
            <p><?= $isEditing ? 'Update the selected flock record.' : 'Enter the flock details to create a record.' ?></p>
        </div>
        <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Please correct the highlighted fields.</strong>
            <?php if (isset($errors['batch_code']) && str_contains($errors['batch_code'], 'already in use')): ?>
                <div><?= h($errors['batch_code']) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Flock information</h2></div>
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'edit' : 'add' ?><?= $isEditing ? '&id=' . (int) $flock['flock_id'] : '' ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="flock_id" value="<?= (int) $flock['flock_id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="batch_code">Batch code <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['batch_code']) ? ' is-invalid' : '' ?>" id="batch_code" name="batch_code" maxlength="50" required value="<?= h($formData['batch_code']) ?>">
                        <?php if (isset($errors['batch_code'])): ?><div class="invalid-feedback"><?= h($errors['batch_code']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="breed">Breed <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['breed']) ? ' is-invalid' : '' ?>" id="breed" name="breed" maxlength="100" required value="<?= h($formData['breed']) ?>">
                        <?php if (isset($errors['breed'])): ?><div class="invalid-feedback"><?= h($errors['breed']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="start_date">Start date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>" id="start_date" name="start_date" required value="<?= h($formData['start_date']) ?>">
                        <?php if (isset($errors['start_date'])): ?><div class="invalid-feedback"><?= h($errors['start_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="age_at_arrival_days">Age at arrival (days)</label>
                        <input type="number" class="form-control<?= isset($errors['age_at_arrival_days']) ? ' is-invalid' : '' ?>" id="age_at_arrival_days" name="age_at_arrival_days" min="0" step="1" value="<?= h($formData['age_at_arrival_days']) ?>">
                        <div class="form-text">0 if the birds were day-old chicks when this batch started.</div>
                        <?php if (isset($errors['age_at_arrival_days'])): ?><div class="invalid-feedback"><?= h($errors['age_at_arrival_days']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="initial_birds">Initial birds <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['initial_birds']) ? ' is-invalid' : '' ?>" id="initial_birds" name="initial_birds" min="1" step="1" required value="<?= h($formData['initial_birds']) ?>">
                        <?php if (isset($errors['initial_birds'])): ?><div class="invalid-feedback"><?= h($errors['initial_birds']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="current_birds">Current birds <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['current_birds']) ? ' is-invalid' : '' ?>" id="current_birds" name="current_birds" min="0" step="1" required value="<?= h($formData['current_birds']) ?>">
                        <?php if (isset($errors['current_birds'])): ?><div class="invalid-feedback"><?= h($errors['current_birds']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required>
                            <?php foreach (['Active', 'Completed', 'Sold', 'Terminated'] as $statusOption): ?>
                                <option value="<?= h($statusOption) ?>" <?= $formData['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4"><?= h($formData['notes']) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Register flock' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

<?php elseif ($action === 'view' && $flock !== null): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">FLOCK RECORD #<?= (int) $flock['flock_id'] ?></div>
            <h1><?= h($flock['batch_code']) ?></h1>
            <p><?= h($flock['breed']) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="index.php">Back to dashboard</a>
            <?php if ($canManage): ?>
                <a class="btn btn-primary" href="index.php?action=edit&amp;id=<?= (int) $flock['flock_id'] ?>">Edit flock</a>
                <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this flock record to the Recycle Bin?\nBatch Code: <?= h($flock['batch_code']) ?>\nThis record can be restored later.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="flock_id" value="<?= (int) $flock['flock_id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Move to Recycle Bin</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <section class="content-panel">
        <div class="panel-heading d-flex justify-content-between align-items-center gap-3">
            <h2 class="h5 mb-0">Flock details</h2>
            <span class="badge text-bg-<?= h(flock_status_badge($flock['status'])) ?>"><?= h($flock['status']) ?></span>
        </div>
        <div class="panel-body">
            <div class="row g-4">
                <?php
                $age = pms_flock_age($flock['start_date'], (int) $flock['age_at_arrival_days']);
                $mortalityToDate = max(0, (int) $flock['initial_birds'] - (int) $flock['current_birds']);
                $details = [
                    'Flock ID' => $flock['flock_id'],
                    'Batch code' => $flock['batch_code'],
                    'Breed' => $flock['breed'],
                    'Start date' => $flock['start_date'],
                    'Age' => number_format($age['days']) . ' days (' . number_format($age['weeks'], 1) . ' weeks)',
                    'Initial birds' => number_format((int) $flock['initial_birds']),
                    'Mortality to date' => number_format($mortalityToDate),
                    'Current birds' => number_format((int) $flock['current_birds']),
                    'Created date' => $flock['created_at'],
                ];
                ?>
                <?php foreach ($details as $label => $value): ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="detail-label"><?= h($label) ?></div>
                        <div class="detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="col-12">
                    <div class="detail-label">Notes</div>
                    <div class="detail-value text-break"><?= $flock['notes'] !== null && $flock['notes'] !== '' ? nl2br(h($flock['notes'])) : '<span class="text-secondary">No notes</span>' ?></div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <section class="content-panel mt-4">
            <div class="panel-heading"><h2 class="h5 mb-0">Update status</h2></div>
            <div class="panel-body">
                <form method="post" action="index.php?action=status" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="flock_id" value="<?= (int) $flock['flock_id'] ?>">
                    <div class="col-sm-6 col-md-4">
                        <label class="form-label" for="flock_status">New status</label>
                        <select class="form-select" id="flock_status" name="status" required>
                            <?php foreach (['Active', 'Completed', 'Sold', 'Terminated'] as $statusOption): ?>
                                <option value="<?= h($statusOption) ?>" <?= $flock['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-auto"><button class="btn btn-outline-primary" type="submit">Update status</button></div>
                </form>
            </div>
        </section>
    <?php endif; ?>

<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1>Flock dashboard</h1>
            <p>Monitor flock counts and maintain batch records.</p>
        </div>
        <?php if ($canManage && !$databaseFailure): ?>
            <a class="btn btn-primary" href="index.php?action=add">Add flock</a>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['Total active flocks', $stats['active_flocks']],
            ['Total birds', $stats['total_birds']],
            ['Completed flocks', $stats['completed_flocks']],
            ['Sold flocks', $stats['sold_flocks']],
            ['Terminated flocks', $stats['terminated_flocks']],
        ];
        ?>
        <?php foreach ($statCards as [$label, $value]): ?>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-label"><?= h($label) ?></div>
                    <div class="stat-value"><?= number_format((int) $value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="content-panel">
        <div class="panel-heading">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2 class="h5 mb-0">Flock records</h2>
                <span class="text-secondary small"><?= number_format($totalRecords ?? count($records)) ?> record<?= ($totalRecords ?? count($records)) === 1 ? '' : 's' ?></span>
            </div>
        </div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <?php if (isset($perPage)): pagination_preserve_per_page_field($perPage); endif; ?>
                <div class="col-md-4">
                    <label class="form-label" for="batch_search">Batch code</label>
                    <input class="form-control" id="batch_search" name="batch_code" value="<?= h($batchSearch ?? '') ?>" placeholder="Search batch code">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="breed_search">Breed</label>
                    <input class="form-control" id="breed_search" name="breed" value="<?= h($breedSearch ?? '') ?>" placeholder="Search breed">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="status_filter">Status</label>
                    <select class="form-select" id="status_filter" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (['Active', 'Completed', 'Sold', 'Terminated'] as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= ($statusFilter ?? '') === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Search</button>
                    <a class="btn btn-outline-secondary" href="index.php" aria-label="Clear filters" title="Clear filters">Clear</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 5%"><col style="width: 13%"><col style="width: 14%"><col style="width: 10%">
                    <col style="width: 9%"><col style="width: 9%"><col style="width: 9%"><col style="width: 10%"><col style="width: 21%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Flock ID</th>
                        <th scope="col">Batch code</th>
                        <th scope="col">Breed</th>
                        <th scope="col">Start date</th>
                        <th scope="col">Age</th>
                        <th scope="col" class="text-end">Initial birds</th>
                        <th scope="col" class="text-end">Current birds</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td colspan="9" class="text-center text-secondary py-5">No flock records match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= (int) $record['flock_id'] ?></td>
                                <td class="fw-semibold"><span class="cell-id" title="<?= h($record['batch_code']) ?>"><?= h($record['batch_code']) ?></span></td>
                                <td class="cell-wrap"><?= h($record['breed']) ?></td>
                                <td><span class="cell-id" title="<?= h($record['start_date']) ?>"><?= h($record['start_date']) ?></span></td>
                                <td class="cell-wrap"><?php $rowAge = pms_flock_age($record['start_date'], (int) $record['age_at_arrival_days']); ?><?= number_format($rowAge['days']) ?> days</td>
                                <td class="text-end"><?= number_format((int) $record['initial_birds']) ?></td>
                                <td class="text-end"><?= number_format((int) $record['current_birds']) ?></td>
                                <td class="cell-wrap"><span class="badge text-bg-<?= h(flock_status_badge($record['status'])) ?>"><?= h($record['status']) ?></span></td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $record['flock_id'] ?>">View</a>
                                        <?php if ($canManage): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['flock_id'] ?>">Edit</a>
                                            <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this flock record to the Recycle Bin?\nBatch Code: <?= h($record['batch_code']) ?>\nThis record can be restored later.');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="flock_id" value="<?= (int) $record['flock_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (isset($totalRecords, $pageNum, $perPage)): render_pagination_controls($pageNum, $perPage, $totalRecords, $_GET, 'index.php'); endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>