<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

const FARM_ACTIVITY_TYPES = ['Feeding', 'Cleaning', 'Vaccination', 'Medication', 'Egg Collection', 'Weighing', 'Other'];
const FARM_ACTIVITY_STATUSES = ['Scheduled', 'In Progress', 'Completed', 'Missed', 'Cancelled'];
const FARM_ACTIVITY_INVENTORY_TYPES = ['Feeding', 'Vaccination', 'Medication'];

function activity_form_data(array $source): array
{
    return [
        'flock_id' => trim((string) ($source['flock_id'] ?? '')),
        'activity_type' => trim((string) ($source['activity_type'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'scheduled_date' => trim((string) ($source['scheduled_date'] ?? '')),
        'assigned_to' => trim((string) ($source['assigned_to'] ?? '')),
        'inventory_item_name' => trim((string) ($source['inventory_item_name'] ?? '')),
        'inventory_quantity_used' => trim((string) ($source['inventory_quantity_used'] ?? '')),
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

    if (mb_strlen($activity['inventory_item_name']) > 150) {
        $errors['inventory_item_name'] = 'Item name must be 150 characters or fewer.';
    }
    if ($activity['inventory_quantity_used'] !== '' && !preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $activity['inventory_quantity_used'])) {
        $errors['inventory_quantity_used'] = 'Quantity used must be a non-negative number with up to two decimal places.';
    }
    if ($activity['inventory_item_name'] !== '' && $activity['inventory_quantity_used'] === '') {
        $errors['inventory_quantity_used'] = 'Enter the quantity used with the item name.';
    }
    if ($activity['inventory_quantity_used'] !== '' && $activity['inventory_item_name'] === '') {
        $errors['inventory_item_name'] = 'Enter the item name with the quantity used.';
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
$stats = ['scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'missed' => 0, 'cancelled' => 0, 'due_or_overdue' => 0];
$records = [];
$flocks = [];
$inventoryItems = [];
$filters = [
    'activity_type' => trim((string) ($_GET['activity_type'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'flock_id' => trim((string) ($_GET['flock_id'] ?? '')),
    'scheduled_date' => trim((string) ($_GET['scheduled_date'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
    'overdue_only' => trim((string) ($_GET['overdue_only'] ?? '')),
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
                $values['inventory_item_name'] = $formData['inventory_item_name'] === '' ? null : $formData['inventory_item_name'];
                $values['inventory_quantity_used'] = $formData['inventory_quantity_used'] === '' ? null : $formData['inventory_quantity_used'];

                if ($isEditing) {
                    $statement = $pdo->prepare(
                        "UPDATE farm_activities
                         SET completed_date = CASE
                                 WHEN :completion_status = 'Completed' THEN
                                     CASE WHEN status = 'Completed' THEN COALESCE(completed_date, CURDATE()) ELSE CURDATE() END
                                 ELSE NULL
                             END,
                             flock_id = :flock_id, activity_type = :activity_type, description = :description,
                             scheduled_date = :scheduled_date, assigned_to = :assigned_to,
                             inventory_item_name = :inventory_item_name, inventory_quantity_used = :inventory_quantity_used,
                             status = :status, remarks = :remarks
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

                $canDeductForType = in_array($formData['activity_type'], FARM_ACTIVITY_INVENTORY_TYPES, true);
                $deductFromInventory = $canDeductForType && isset($_POST['deduct_from_inventory']);
                $matchedItem = $deductFromInventory && $values['inventory_item_name'] !== null && (float) $values['inventory_quantity_used'] > 0
                    ? pms_find_inventory_item_by_name($pdo, $values['inventory_item_name'])
                    : null;
                if ($deductFromInventory && $values['inventory_item_name'] !== null && (float) $values['inventory_quantity_used'] > 0 && $matchedItem === null) {
                    $errors['inventory_item_name'] = 'No active inventory item named "' . $values['inventory_item_name'] . '" was found to deduct from.';
                }

                if ($errors === []) {
                    $completedDate = $formData['status'] === 'Completed' ? date('Y-m-d') : null;
                    $pdo->beginTransaction();
                    try {
                        $statement = $pdo->prepare(
                            'INSERT INTO farm_activities
                                (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, inventory_item_name, inventory_quantity_used, status, remarks)
                             VALUES
                                (:flock_id, :activity_type, :description, :scheduled_date, :completed_date, :assigned_to, :inventory_item_name, :inventory_quantity_used, :status, :remarks)'
                        );
                        $statement->execute($values + ['completed_date' => $completedDate]);
                        $newActivityId = (int) $pdo->lastInsertId();
                        if ($matchedItem !== null) {
                            $adjustmentId = pms_apply_inventory_adjustment(
                                $pdo, (int) $matchedItem['item_id'], 'Reduce', number_format((float) $values['inventory_quantity_used'], 2, '.', ''),
                                'Used for farm activity #' . $newActivityId, 'Farm Activity', $newActivityId
                            );
                        }
                        $pdo->commit();
                        audit_pms_action($pdo, 'CREATE', 'farm_activities', $newActivityId);
                        if ($matchedItem !== null) {
                            audit_pms_action($pdo, 'UPDATE', 'poultry_inventory', (int) $matchedItem['item_id']);
                            audit_pms_action($pdo, 'CREATE', 'inventory_adjustments', $adjustmentId);
                        }
                        set_flash('success', 'Farm activity scheduled.' . ($matchedItem !== null ? ' Inventory deducted.' : ''));
                        redirect_to('index.php');
                    } catch (RuntimeException $exception) {
                        $pdo->rollBack();
                        $errors['inventory_item_name'] = 'Not enough "' . $values['inventory_item_name'] . '" in stock to deduct this quantity.';
                    }
                }
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
        $inventoryItems = $pdo->query("SELECT item_name FROM poultry_inventory WHERE deleted_at IS NULL AND status <> 'Out of Stock' ORDER BY item_name")->fetchAll();

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
                    COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled,
                    COALESCE(SUM(status IN ('Scheduled', 'In Progress') AND scheduled_date <= CURDATE()), 0) AS due_or_overdue
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
            if ($filters['overdue_only'] === '1') {
                $conditions[] = "a.status IN ('Scheduled', 'In Progress') AND a.scheduled_date <= CURDATE()";
            }

            $whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

            $countStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM farm_activities a LEFT JOIN flocks f ON f.flock_id = a.flock_id' . $whereSql
            );
            $countStatement->execute($parameters);
            $totalRecords = (int) $countStatement->fetchColumn();

            ['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();

            $sql = "SELECT a.activity_id, a.flock_id, f.batch_code, a.activity_type, a.description,
                           a.scheduled_date, a.assigned_to, a.status, a.completed_date,
                           (a.status IN ('Scheduled', 'In Progress') AND a.scheduled_date < CURDATE()) AS is_overdue
                    FROM farm_activities a
                    LEFT JOIN flocks f ON f.flock_id = a.flock_id" . $whereSql . '
                    ORDER BY a.scheduled_date DESC, a.activity_id DESC
                    LIMIT ' . $perPage . ' OFFSET ' . $offset;
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
                    <div class="col-md-6">
                        <label class="form-label" for="inventory_item_name">Inventory item used</label>
                        <input class="form-control<?= isset($errors['inventory_item_name']) ? ' is-invalid' : '' ?>" id="inventory_item_name" name="inventory_item_name" maxlength="150" list="inventory-item-options" value="<?= h($formData['inventory_item_name']) ?>">
                        <datalist id="inventory-item-options"><?php foreach ($inventoryItems as $inventoryItem): ?><option value="<?= h($inventoryItem['item_name']) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['inventory_item_name'])): ?><div class="invalid-feedback"><?= h($errors['inventory_item_name']) ?></div><?php endif; ?>
                        <div class="form-text">Only used for Feeding / Vaccination / Medication activities.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="inventory_quantity_used">Quantity used</label>
                        <input type="number" class="form-control<?= isset($errors['inventory_quantity_used']) ? ' is-invalid' : '' ?>" id="inventory_quantity_used" name="inventory_quantity_used" min="0" step="0.01" value="<?= h($formData['inventory_quantity_used']) ?>">
                        <?php if (isset($errors['inventory_quantity_used'])): ?><div class="invalid-feedback"><?= h($errors['inventory_quantity_used']) ?></div><?php endif; ?>
                    </div>
                    <?php if (!$isEditing): ?>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="deduct_from_inventory" name="deduct_from_inventory" value="1">
                                <label class="form-check-label" for="deduct_from_inventory">Deduct from inventory</label>
                            </div>
                        </div>
                    <?php endif; ?>
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
            ['Due Today / Overdue', $stats['due_or_overdue']],
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
            <span class="text-secondary small"><?= number_format($totalRecords) ?> record<?= $totalRecords === 1 ? '' : 's' ?></span>
        </div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <?php pagination_preserve_per_page_field($perPage); ?>
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
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="filter_overdue" name="overdue_only" value="1" <?= $filters['overdue_only'] === '1' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="filter_overdue">Due today / overdue only</label>
                    </div>
                </div>
                <div class="col-md-2 ms-md-auto d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Search</button>
                    <a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 5%"><col style="width: 8%"><col style="width: 8%"><col style="width: 22%">
                    <col style="width: 9%"><col style="width: 9%"><col style="width: 10%"><col style="width: 8%"><col style="width: 21%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Batch</th>
                        <th scope="col">Type</th>
                        <th scope="col">Description</th>
                        <th scope="col">Scheduled</th>
                        <th scope="col">Assigned To</th>
                        <th scope="col">Status</th>
                        <th scope="col">Completed</th>
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
                                <td><?= $record['batch_code'] !== null ? '<span class="cell-id" title="' . h($record['batch_code']) . '">' . h($record['batch_code']) . '</span>' : '<span class="text-secondary">Farm-wide</span>' ?></td>
                                <td class="cell-wrap"><?= h($record['activity_type']) ?></td>
                                <td class="cell-clamp" title="<?= h($record['description'] ?? '') ?>"><?= h($record['description'] ?? '') ?></td>
                                <td><span class="cell-id" title="<?= h($record['scheduled_date']) ?>"><?= h($record['scheduled_date']) ?></span></td>
                                <td class="cell-wrap"><?= h($record['assigned_to'] ?? '') ?></td>
                                <td>
                                    <span class="badge text-bg-<?= h(activity_status_badge($record['status'])) ?>"><?= h($record['status']) ?></span>
                                    <?php if ((bool) $record['is_overdue']): ?><span class="badge text-bg-danger">Overdue</span><?php endif; ?>
                                </td>
                                <td><?php if ($record['completed_date'] !== null): ?><span class="cell-id" title="<?= h($record['completed_date']) ?>"><?= h($record['completed_date']) ?></span><?php endif; ?></td>
                                <td class="text-end">
                                    <?php if ($canManage): ?>
                                        <div class="action-cell">
                                            <form method="post" action="index.php?action=status" class="d-flex align-items-center gap-1">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="activity_id" value="<?= (int) $record['activity_id'] ?>">
                                                <label class="visually-hidden" for="status-<?= (int) $record['activity_id'] ?>">Change status for activity <?= (int) $record['activity_id'] ?></label>
                                                <select class="form-select form-select-sm" id="status-<?= (int) $record['activity_id'] ?>" name="status" style="max-width: 7.5rem;">
                                                    <?php foreach (FARM_ACTIVITY_STATUSES as $statusOption): ?><option value="<?= h($statusOption) ?>" <?= $record['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option><?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Update status" aria-label="Update status"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                                            </form>
                                            <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['activity_id'] ?>" title="Edit" aria-label="Edit activity <?= (int) $record['activity_id'] ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                            <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this farm activity to the Recycle Bin?\nActivity: <?= h($record['activity_type']) ?>\nDate: <?= h($record['scheduled_date']) ?>\nThis record can be restored later.');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="activity_id" value="<?= (int) $record['activity_id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete" aria-label="Delete activity <?= (int) $record['activity_id'] ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                            </form>
                                        </div>
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
        <?php render_pagination_controls($pageNum, $perPage, $totalRecords, $_GET, 'index.php'); ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>