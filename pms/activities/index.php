<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

const FARM_ACTIVITY_TYPES = ['Feeding', 'Cleaning', 'Vaccination', 'Medication', 'Egg Collection', 'Weighing', 'Other'];
const FARM_ACTIVITY_STATUSES = ['Scheduled', 'In Progress', 'Completed', 'Missed', 'Cancelled'];

function activity_form_data(array $source): array
{
    return [
        'flock_id' => trim((string) ($source['flock_id'] ?? '')),
        'activity_type' => trim((string) ($source['activity_type'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'scheduled_date' => trim((string) ($source['scheduled_date'] ?? '')),
        'assigned_to' => trim((string) ($source['assigned_to'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Scheduled')),
        'remarks' => trim((string) ($source['remarks'] ?? '')),
    ];
}

function validate_activity(array $activity, PDO $pdo): array
{
    $errors = [];

    if (!in_array($activity['activity_type'], FARM_ACTIVITY_TYPES, true)) {
        $errors['activity_type'] = 'Select a valid activity type.';
    }

    if ($activity['scheduled_date'] === '') {
        $errors['scheduled_date'] = 'Scheduled date is required.';
    } else {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $activity['scheduled_date']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $activity['scheduled_date']) {
            $errors['scheduled_date'] = 'Enter a valid scheduled date.';
        }
    }

    if (!in_array($activity['status'], FARM_ACTIVITY_STATUSES, true)) {
        $errors['status'] = 'Select a valid activity status.';
    }
    if (mb_strlen($activity['description']) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    if (mb_strlen($activity['assigned_to']) > 100) {
        $errors['assigned_to'] = 'Assigned to must be 100 characters or fewer.';
    }

    if ($activity['flock_id'] !== '') {
        $flockId = filter_var($activity['flock_id'], FILTER_VALIDATE_INT);
        if ($flockId === false || $flockId < 1) {
            $errors['flock_id'] = 'Select a valid flock.';
        } else {
            $statement = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $statement->execute([$flockId]);
            if ($statement->fetchColumn() === false) {
                $errors['flock_id'] = 'The selected flock does not exist.';
            }
        }
    }

    return $errors;
}

function activity_status_badge(string $status): string
{
    return match ($status) {
        'Scheduled' => 'primary',
        'In Progress' => 'warning',
        'Completed' => 'success',
        'Missed' => 'danger',
        'Cancelled' => 'secondary',
        default => 'light',
    };
}

function valid_activity_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Farm activities database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
$activeNavigation = 'activities';
$canManage = can_manage_flocks();
$errors = [];
$formData = activity_form_data([]);
$activity = null;
$isEditing = false;
$pageTitle = 'Farm Activities';
$stats = ['scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'missed' => 0, 'cancelled' => 0];
$records = [];
$flocks = [];
$filters = [
    'activity_type' => trim((string) ($_GET['activity_type'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'flock_id' => trim((string) ($_GET['flock_id'] ?? '')),
    'scheduled_date' => trim((string) ($_GET['scheduled_date'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$databaseFailure = $databaseError !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && !$databaseFailure) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }
    if (!$canManage) {
        set_flash('danger', 'You are not authorized to change farm activities.');
        redirect_to('index.php');
    }

    try {
        if ($action === 'create' || $action === 'update') {
            $formData = activity_form_data($_POST);
            $errors = validate_activity($formData, $pdo);
            $isEditing = $action === 'update';
            $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT);

            if ($isEditing && ($activityId === false || $activityId < 1)) {
                set_flash('danger', 'The selected activity could not be found.');
                redirect_to('index.php');
            }
            if ($isEditing) {
                $exists = $pdo->prepare('SELECT activity_id FROM farm_activities WHERE activity_id = ? AND deleted_at IS NULL');
                $exists->execute([$activityId]);
                if ($exists->fetchColumn() === false) {
                    set_flash('danger', 'The selected activity could not be found.');
                    redirect_to('index.php');
                }
            }

            if ($errors === []) {
                $values = $formData;
                $values['flock_id'] = $formData['flock_id'] === '' ? null : (int) $formData['flock_id'];
                if ($isEditing) {
                    $statement = $pdo->prepare(
                        "UPDATE farm_activities
                         SET completed_date = CASE
                                 WHEN :completion_status = 'Completed' THEN
                                     CASE WHEN status = 'Completed' THEN COALESCE(completed_date, CURDATE()) ELSE CURDATE() END
                                 ELSE NULL
                             END,
                             flock_id = :flock_id, activity_type = :activity_type, description = :description,
                             scheduled_date = :scheduled_date, assigned_to = :assigned_to, status = :status,
                             remarks = :remarks
                         WHERE activity_id = :activity_id AND deleted_at IS NULL"
                    );
                    $statement->execute($values + [
                        'completion_status' => $formData['status'],
                        'activity_id' => $activityId,
                    ]);
                    audit_pms_action($pdo, 'UPDATE', 'farm_activities', $activityId);
                    set_flash('success', 'Farm activity updated.');
                    redirect_to('index.php?action=edit&id=' . $activityId);
                }

                $completedDate = $formData['status'] === 'Completed' ? date('Y-m-d') : null;
                $statement = $pdo->prepare(
                    'INSERT INTO farm_activities
                        (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks)
                     VALUES
                        (:flock_id, :activity_type, :description, :scheduled_date, :completed_date, :assigned_to, :status, :remarks)'
                );
                $statement->execute($values + ['completed_date' => $completedDate]);
                audit_pms_action($pdo, 'CREATE', 'farm_activities', (int) $pdo->lastInsertId());
                set_flash('success', 'Farm activity scheduled.');
                redirect_to('index.php');
            }

            $action = $isEditing ? 'edit' : 'add';
            $pageTitle = $isEditing ? 'Edit Farm Activity' : 'Add Farm Activity';
            if ($isEditing) {
                $activity = ['activity_id' => $activityId] + $formData;
            }
        } elseif ($action === 'delete') {
            $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT);
            if ($activityId === false || $activityId < 1) {
                set_flash('danger', 'The selected activity could not be found.');
                redirect_to('index.php');
            }
            $identity = $pdo->prepare('SELECT activity_type, scheduled_date FROM farm_activities WHERE activity_id = ? AND deleted_at IS NULL');
            $identity->execute([$activityId]);
            $details = $identity->fetch();
            if (!$details) {
                set_flash('danger', 'The selected active activity could not be found.');
                redirect_to('index.php');
            }
            if (!pms_soft_delete($pdo, 'farm_activities', $activityId)) {
                set_flash('danger', 'The activity could not be moved to the Recycle Bin.');
            } else {
                set_flash('success', $details['activity_type'] . ' activity moved to the Recycle Bin.');
            }
            redirect_to('index.php');
        } elseif ($action === 'status') {
            $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT);
            $status = trim((string) ($_POST['status'] ?? ''));
            if ($activityId === false || $activityId < 1 || !in_array($status, FARM_ACTIVITY_STATUSES, true)) {
                set_flash('danger', 'Choose a valid activity and status.');
                redirect_to('index.php');
            }

            $statement = $pdo->prepare(
                "UPDATE farm_activities
                 SET completed_date = CASE
                         WHEN :completion_status = 'Completed' THEN
                             CASE WHEN status = 'Completed' THEN COALESCE(completed_date, CURDATE()) ELSE CURDATE() END
                         ELSE NULL
                     END,
                     status = :status
                 WHERE activity_id = :activity_id AND deleted_at IS NULL"
            );
            $statement->execute([
                'completion_status' => $status,
                'status' => $status,
                'activity_id' => $activityId,
            ]);
            if ($statement->rowCount() === 1) {
                audit_pms_action($pdo, 'UPDATE', 'farm_activities', $activityId);
            }
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM farm_activities WHERE activity_id = ? AND deleted_at IS NULL');
                $exists->execute([$activityId]);
                if ($exists->fetchColumn() === false) {
                    set_flash('danger', 'The selected activity could not be found.');
                    redirect_to('index.php');
                }
            }
            set_flash('success', 'Activity status updated.');
            redirect_to('index.php');
        } else {
            set_flash('danger', 'The requested action is not available.');
            redirect_to('index.php');
        }
    } catch (PDOException $exception) {
        error_log('Farm activity write failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'The activity could not be saved because of a database error.';
    }
}

if ($pdo instanceof PDO && !$databaseFailure && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code, status FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code')->fetchAll();

        if (in_array($action, ['add', 'edit'], true)) {
            if (!$canManage) {
                set_flash('warning', 'Sign in as an administrator to add or edit activities.');
                redirect_to('index.php');
            }
            $isEditing = $action === 'edit';
            $pageTitle = $isEditing ? 'Edit Farm Activity' : 'Add Farm Activity';

            if ($isEditing) {
                $activityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                if ($activityId === false || $activityId < 1) {
                    set_flash('danger', 'The selected activity could not be found.');
                    redirect_to('index.php');
                }
                $statement = $pdo->prepare('SELECT * FROM farm_activities WHERE activity_id = ? AND deleted_at IS NULL');
                $statement->execute([$activityId]);
                $activity = $statement->fetch();
                if (!$activity) {
                    set_flash('danger', 'The selected activity could not be found.');
                    redirect_to('index.php');
                }
                $formData = activity_form_data($activity);
            }
        } else {
            $action = 'list';
            $summary = $pdo->query(
                "SELECT
                    COALESCE(SUM(status = 'Scheduled'), 0) AS scheduled,
                    COALESCE(SUM(status = 'In Progress'), 0) AS in_progress,
                    COALESCE(SUM(status = 'Completed'), 0) AS completed,
                    COALESCE(SUM(status = 'Missed'), 0) AS missed,
                    COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled
                 FROM farm_activities WHERE deleted_at IS NULL"
            )->fetch();
            if ($summary) {
                $stats = $summary;
            }

            $conditions = ['a.deleted_at IS NULL', '(a.flock_id IS NULL OR f.deleted_at IS NULL)'];
            $parameters = [];
            if (in_array($filters['activity_type'], FARM_ACTIVITY_TYPES, true)) {
                $conditions[] = 'a.activity_type = :activity_type';
                $parameters['activity_type'] = $filters['activity_type'];
            } else {
                $filters['activity_type'] = '';
            }
            if (in_array($filters['status'], FARM_ACTIVITY_STATUSES, true)) {
                $conditions[] = 'a.status = :status';
                $parameters['status'] = $filters['status'];
            } else {
                $filters['status'] = '';
            }
            if ($filters['flock_id'] !== '') {
                $flockId = filter_var($filters['flock_id'], FILTER_VALIDATE_INT);
                if ($flockId !== false && $flockId > 0) {
                    $conditions[] = 'a.flock_id = :flock_id';
                    $parameters['flock_id'] = $flockId;
                } else {
                    $filters['flock_id'] = '';
                }
            }
            if ($filters['scheduled_date'] !== '') {
                if (valid_activity_date($filters['scheduled_date'])) {
                    $conditions[] = 'a.scheduled_date = :scheduled_date';
                    $parameters['scheduled_date'] = $filters['scheduled_date'];
                } else {
                    $filters['scheduled_date'] = '';
                }
            }
            if ($filters['search'] !== '') {
                $conditions[] = '(a.description LIKE :description_search OR a.assigned_to LIKE :assigned_search)';
                $parameters['description_search'] = '%' . $filters['search'] . '%';
                $parameters['assigned_search'] = '%' . $filters['search'] . '%';
            }

            $sql = 'SELECT a.activity_id, a.flock_id, f.batch_code, a.activity_type, a.description,
                           a.scheduled_date, a.assigned_to, a.status, a.completed_date
                    FROM farm_activities a
                    LEFT JOIN flocks f ON f.flock_id = a.flock_id';
            if ($conditions !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= ' ORDER BY a.scheduled_date DESC, a.activity_id DESC';
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $records = $statement->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Farm activity query failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'Farm activity information could not be loaded. Check the database connection and schema.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div>
<?php endif; ?>

<?php if (!$canManage && !$databaseFailure): ?>
    <div class="alert alert-warning role-note" role="status">Read-only access. <a href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in as an administrator</a> to add or update activities.</div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1><?= $isEditing ? 'Edit farm activity' : 'Schedule farm activity' ?></h1>
            <p>Assign and track farm work against a flock or the farm as a whole.</p>
        </div>
        <a class="btn btn-outline-secondary" href="index.php">Back to activities</a>
    </div>

    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert"><strong>Please correct the highlighted fields.</strong></div><?php endif; ?>

    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Activity details</h2></div>
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'edit' : 'add' ?><?= $isEditing ? '&id=' . (int) $activity['activity_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="activity_id" value="<?= (int) $activity['activity_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="flock_id">Flock</label>
                        <select class="form-select<?= isset($errors['flock_id']) ? ' is-invalid' : '' ?>" id="flock_id" name="flock_id">
                            <option value="">Farm-wide / no specific flock</option>
                            <?php foreach ($flocks as $flockOption): ?>
                                <option value="<?= (int) $flockOption['flock_id'] ?>" <?= (string) $formData['flock_id'] === (string) $flockOption['flock_id'] ? 'selected' : '' ?>><?= h($flockOption['batch_code']) ?> (<?= h($flockOption['status']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['flock_id'])): ?><div class="invalid-feedback"><?= h($errors['flock_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="activity_type">Activity type <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['activity_type']) ? ' is-invalid' : '' ?>" id="activity_type" name="activity_type" required>
                            <option value="">Select activity</option>
                            <?php foreach (FARM_ACTIVITY_TYPES as $type): ?><option value="<?= h($type) ?>" <?= $formData['activity_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['activity_type'])): ?><div class="invalid-feedback"><?= h($errors['activity_type']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="scheduled_date">Scheduled date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control<?= isset($errors['scheduled_date']) ? ' is-invalid' : '' ?>" id="scheduled_date" name="scheduled_date" required value="<?= h($formData['scheduled_date']) ?>">
                        <?php if (isset($errors['scheduled_date'])): ?><div class="invalid-feedback"><?= h($errors['scheduled_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="assigned_to">Assigned to</label>
                        <input class="form-control<?= isset($errors['assigned_to']) ? ' is-invalid' : '' ?>" id="assigned_to" name="assigned_to" maxlength="100" value="<?= h($formData['assigned_to']) ?>">
                        <?php if (isset($errors['assigned_to'])): ?><div class="invalid-feedback"><?= h($errors['assigned_to']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required>
                            <?php foreach (FARM_ACTIVITY_STATUSES as $statusOption): ?><option value="<?= h($statusOption) ?>" <?= $formData['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <input class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" maxlength="255" value="<?= h($formData['description']) ?>">
                        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($formData['remarks']) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Schedule activity' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1>Farm activities</h1>
            <p>Schedule, assign, and monitor farm tasks.</p>
        </div>
        <?php if ($canManage && !$databaseFailure): ?><a class="btn btn-primary" href="index.php?action=add">Schedule activity</a><?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $statusCards = [
            ['Total Scheduled', $stats['scheduled']],
            ['Total In Progress', $stats['in_progress']],
            ['Total Completed', $stats['completed']],
            ['Total Missed', $stats['missed']],
            ['Total Cancelled', $stats['cancelled']],
        ];
        ?>
        <?php foreach ($statusCards as [$label, $value]): ?>
            <div class="col-6 col-lg">
                <div class="stat-card"><div class="stat-label"><?= h($label) ?></div><div class="stat-value"><?= number_format((int) $value) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="content-panel">
        <div class="panel-heading d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="h5 mb-0">Activity schedule</h2>
            <span class="text-secondary small"><?= number_format(count($records)) ?> record<?= count($records) === 1 ? '' : 's' ?></span>
        </div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="filter_type">Activity type</label>
                    <select class="form-select" id="filter_type" name="activity_type">
                        <option value="">All types</option>
                        <?php foreach (FARM_ACTIVITY_TYPES as $type): ?><option value="<?= h($type) ?>" <?= $filters['activity_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter_status">Status</label>
                    <select class="form-select" id="filter_status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (FARM_ACTIVITY_STATUSES as $statusOption): ?><option value="<?= h($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter_flock">Flock</label>
                    <select class="form-select" id="filter_flock" name="flock_id">
                        <option value="">All flocks</option>
                        <?php foreach ($flocks as $flockOption): ?><option value="<?= (int) $flockOption['flock_id'] ?>" <?= $filters['flock_id'] === (string) $flockOption['flock_id'] ? 'selected' : '' ?>><?= h($flockOption['batch_code']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter_date">Scheduled date</label>
                    <input type="date" class="form-control" id="filter_date" name="scheduled_date" value="<?= h($filters['scheduled_date']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter_search">Description / assignee</label>
                    <input class="form-control" id="filter_search" name="search" value="<?= h($filters['search']) ?>" placeholder="Search tasks">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button>
                    <a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th scope="col">Activity ID</th>
                        <th scope="col">Flock / Batch Code</th>
                        <th scope="col">Activity Type</th>
                        <th scope="col">Description</th>
                        <th scope="col">Scheduled Date</th>
                        <th scope="col">Assigned To</th>
                        <th scope="col">Status</th>
                        <th scope="col">Completed Date</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td colspan="9" class="text-center text-secondary py-5">No activities match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= (int) $record['activity_id'] ?></td>
                                <td><?= $record['batch_code'] !== null ? h($record['batch_code']) : '<span class="text-secondary">Farm-wide</span>' ?></td>
                                <td><?= h($record['activity_type']) ?></td>
                                <td><?= h($record['description'] ?? '') ?></td>
                                <td><?= h($record['scheduled_date']) ?></td>
                                <td><?= h($record['assigned_to'] ?? '') ?></td>
                                <td><span class="badge text-bg-<?= h(activity_status_badge($record['status'])) ?>"><?= h($record['status']) ?></span></td>
                                <td><?= h($record['completed_date'] ?? '') ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canManage): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['activity_id'] ?>">Edit</a>
                                        <form method="post" action="index.php?action=status" class="d-inline-flex align-items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="status">
                                            <input type="hidden" name="activity_id" value="<?= (int) $record['activity_id'] ?>">
                                            <label class="visually-hidden" for="status-<?= (int) $record['activity_id'] ?>">Change status for activity <?= (int) $record['activity_id'] ?></label>
                                            <select class="form-select form-select-sm" id="status-<?= (int) $record['activity_id'] ?>" name="status">
                                                <?php foreach (FARM_ACTIVITY_STATUSES as $statusOption): ?><option value="<?= h($statusOption) ?>" <?= $record['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option><?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
                                        </form>
                                        <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this farm activity to the Recycle Bin?\nActivity: <?= h($record['activity_type']) ?>\nDate: <?= h($record['scheduled_date']) ?>\nThis record can be restored later.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="activity_id" value="<?= (int) $record['activity_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary">Read only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>