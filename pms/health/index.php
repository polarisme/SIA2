<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

const HEALTH_RECORD_TYPES = ['Disease', 'Vaccination', 'Treatment', 'Health Check'];
const HEALTH_STATUSES = ['Observed', 'Under Treatment', 'Resolved', 'Monitoring'];

function health_form_data(array $source): array
{
    return [
        'flock_id' => trim((string) ($source['flock_id'] ?? '')),
        'record_date' => trim((string) ($source['record_date'] ?? '')),
        'record_type' => trim((string) ($source['record_type'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'affected_birds' => trim((string) ($source['affected_birds'] ?? '0')),
        'medicine_or_vaccine' => trim((string) ($source['medicine_or_vaccine'] ?? '')),
        'quantity_used' => trim((string) ($source['quantity_used'] ?? '0')),
        'unit' => trim((string) ($source['unit'] ?? '')),
        'action_taken' => trim((string) ($source['action_taken'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Observed')),
        'remarks' => trim((string) ($source['remarks'] ?? '')),
    ];
}

function health_date_is_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function health_count_is_valid(string $value): bool
{
    return preg_match('/\A\d{1,9}\z/', $value) === 1;
}

function health_quantity_is_valid(string $value): bool
{
    return preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $value) === 1;
}

function validate_health_record(array $record, PDO $pdo): array
{
    $errors = [];
    $flockId = filter_var($record['flock_id'], FILTER_VALIDATE_INT);
    if ($flockId === false || $flockId < 1) {
        $errors['flock_id'] = 'Select a flock.';
    } else {
        $statement = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
        $statement->execute([$flockId]);
        if ($statement->fetchColumn() === false) {
            $errors['flock_id'] = 'The selected flock does not exist.';
        }
    }

    if (!health_date_is_valid($record['record_date'])) {
        $errors['record_date'] = 'Enter a valid record date.';
    }
    if (!in_array($record['record_type'], HEALTH_RECORD_TYPES, true)) {
        $errors['record_type'] = 'Select a valid health record type.';
    }
    if (!in_array($record['status'], HEALTH_STATUSES, true)) {
        $errors['status'] = 'Select a valid health status.';
    }
    if (!health_count_is_valid($record['affected_birds'])) {
        $errors['affected_birds'] = 'Affected birds must be a non-negative whole number.';
    }
    if (!health_quantity_is_valid($record['quantity_used'])) {
        $errors['quantity_used'] = 'Quantity used must be non-negative with up to two decimal places.';
    }
    if (mb_strlen($record['description']) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    if (mb_strlen($record['medicine_or_vaccine']) > 150) {
        $errors['medicine_or_vaccine'] = 'Medicine or vaccine must be 150 characters or fewer.';
    }
    if (mb_strlen($record['unit']) > 30) {
        $errors['unit'] = 'Unit must be 30 characters or fewer.';
    }

    return $errors;
}

function health_status_badge(string $status): string
{
    return match ($status) {
        'Observed' => 'secondary',
        'Under Treatment' => 'warning',
        'Resolved' => 'success',
        'Monitoring' => 'info',
        default => 'light',
    };
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Health monitoring database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
$activeNavigation = 'health';
$canManage = can_manage_flocks();
$pageTitle = 'Poultry Health & Disease';
$formData = health_form_data([]);
$errors = [];
$healthRecord = null;
$isEditing = false;
$flocks = [];
$medicineItems = [];
$records = [];
$stats = ['total_records' => 0, 'disease_count' => 0, 'vaccination_count' => 0, 'treatment_count' => 0, 'health_check_count' => 0, 'under_treatment_count' => 0, 'birds_under_treatment' => 0];
$filters = [
    'record_type' => trim((string) ($_GET['record_type'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'flock_id' => trim((string) ($_GET['flock_id'] ?? '')),
    'record_date' => trim((string) ($_GET['record_date'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$databaseFailure = $databaseError !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && !$databaseFailure) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }
    if (!$canManage) {
        set_flash('danger', 'You are not authorized to change health records.');
        redirect_to('index.php');
    }

    try {
        if ($action === 'delete') {
            $healthId = filter_var($_POST['health_id'] ?? null, FILTER_VALIDATE_INT);
            if ($healthId === false || $healthId < 1) {
                set_flash('danger', 'The selected health record could not be found.');
                redirect_to('index.php');
            }
            if (!pms_soft_delete($pdo, 'health_records', $healthId)) {
                set_flash('danger', 'The health record could not be moved to the Recycle Bin.');
            } else {
                set_flash('success', 'Health record moved to the Recycle Bin.');
            }
            redirect_to('index.php');
        }
        if (!in_array($action, ['create', 'update'], true)) {
            set_flash('danger', 'The requested action is not available.');
            redirect_to('index.php');
        }

        $formData = health_form_data($_POST);
        $errors = validate_health_record($formData, $pdo);
        $isEditing = $action === 'update';
        $healthId = filter_var($_POST['health_id'] ?? null, FILTER_VALIDATE_INT);
        if ($isEditing && ($healthId === false || $healthId < 1)) {
            set_flash('danger', 'The selected health record could not be found.');
            redirect_to('index.php');
        }
        if ($isEditing) {
            $exists = $pdo->prepare('SELECT health_id FROM health_records WHERE health_id = ? AND deleted_at IS NULL');
            $exists->execute([$healthId]);
            if ($exists->fetchColumn() === false) {
                set_flash('danger', 'The selected health record could not be found.');
                redirect_to('index.php');
            }
        }

        if ($errors === []) {
            $values = [
                'flock_id' => (int) $formData['flock_id'],
                'record_date' => $formData['record_date'],
                'record_type' => $formData['record_type'],
                'description' => $formData['description'] === '' ? null : $formData['description'],
                'affected_birds' => (int) $formData['affected_birds'],
                'medicine_or_vaccine' => $formData['medicine_or_vaccine'] === '' ? null : $formData['medicine_or_vaccine'],
                'quantity_used' => $formData['quantity_used'],
                'unit' => $formData['unit'] === '' ? null : $formData['unit'],
                'action_taken' => $formData['action_taken'] === '' ? null : $formData['action_taken'],
                'status' => $formData['status'],
                'remarks' => $formData['remarks'] === '' ? null : $formData['remarks'],
            ];

            if ($isEditing) {
                $statement = $pdo->prepare(
                    'UPDATE health_records
                     SET flock_id = :flock_id, record_date = :record_date, record_type = :record_type,
                         description = :description, affected_birds = :affected_birds,
                         medicine_or_vaccine = :medicine_or_vaccine, quantity_used = :quantity_used,
                         unit = :unit, action_taken = :action_taken, status = :status, remarks = :remarks
                     WHERE health_id = :health_id AND deleted_at IS NULL'
                );
                $statement->execute($values + ['health_id' => $healthId]);
                audit_pms_action($pdo, 'UPDATE', 'health_records', $healthId);
                set_flash('success', 'Health record updated.');
                redirect_to('index.php');
            }

            $statement = $pdo->prepare(
                'INSERT INTO health_records
                    (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
                 VALUES
                    (:flock_id, :record_date, :record_type, :description, :affected_birds, :medicine_or_vaccine, :quantity_used, :unit, :action_taken, :status, :remarks)'
            );
            $statement->execute($values);
            audit_pms_action($pdo, 'CREATE', 'health_records', (int) $pdo->lastInsertId());
            set_flash('success', 'Health record saved.');
            redirect_to('index.php');
        }

        $action = $isEditing ? 'edit' : 'add';
        $pageTitle = $isEditing ? 'Edit Health Record' : 'Add Health Record';
        if ($isEditing) {
            $healthRecord = ['health_id' => $healthId] + $formData;
        }
    } catch (PDOException $exception) {
        error_log('Health record write failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'The health record could not be saved because of a database error.';
    }
}

if ($pdo instanceof PDO && !$databaseFailure && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code, status FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code')->fetchAll();
        $medicineItems = $pdo->query(
            "SELECT item_name, unit FROM poultry_inventory
             WHERE category IN ('Vaccine', 'Medicine') AND deleted_at IS NULL ORDER BY item_name"
        )->fetchAll();

        if (in_array($action, ['add', 'edit'], true)) {
            if (!$canManage) {
                set_flash('warning', 'Sign in as an administrator to add or edit health records.');
                redirect_to('index.php');
            }
            $isEditing = $action === 'edit';
            $pageTitle = $isEditing ? 'Edit Health Record' : 'Add Health Record';
            if ($isEditing) {
                $healthId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                if ($healthId === false || $healthId < 1) {
                    set_flash('danger', 'The selected health record could not be found.');
                    redirect_to('index.php');
                }
                $statement = $pdo->prepare('SELECT * FROM health_records WHERE health_id = ? AND deleted_at IS NULL');
                $statement->execute([$healthId]);
                $healthRecord = $statement->fetch();
                if (!$healthRecord) {
                    set_flash('danger', 'The selected health record could not be found.');
                    redirect_to('index.php');
                }
                $formData = health_form_data($healthRecord);
            }
        } else {
            $action = 'list';
            $conditions = ['h.deleted_at IS NULL', 'f.deleted_at IS NULL'];
            $parameters = [];
            if (in_array($filters['record_type'], HEALTH_RECORD_TYPES, true)) {
                $conditions[] = 'h.record_type = :record_type';
                $parameters['record_type'] = $filters['record_type'];
            } else {
                $filters['record_type'] = '';
            }
            if (in_array($filters['status'], HEALTH_STATUSES, true)) {
                $conditions[] = 'h.status = :status';
                $parameters['status'] = $filters['status'];
            } else {
                $filters['status'] = '';
            }
            if ($filters['flock_id'] !== '') {
                $flockId = filter_var($filters['flock_id'], FILTER_VALIDATE_INT);
                if ($flockId !== false && $flockId > 0) {
                    $conditions[] = 'h.flock_id = :flock_id';
                    $parameters['flock_id'] = $flockId;
                } else {
                    $filters['flock_id'] = '';
                }
            }
            if ($filters['record_date'] !== '') {
                if (health_date_is_valid($filters['record_date'])) {
                    $conditions[] = 'h.record_date = :record_date';
                    $parameters['record_date'] = $filters['record_date'];
                } else {
                    $filters['record_date'] = '';
                }
            }
            if ($filters['search'] !== '') {
                $conditions[] = '(h.description LIKE :description_search OR h.medicine_or_vaccine LIKE :medicine_search OR h.action_taken LIKE :action_search OR h.remarks LIKE :remarks_search OR f.batch_code LIKE :batch_search)';
                $searchTerm = '%' . $filters['search'] . '%';
                $parameters['description_search'] = $searchTerm;
                $parameters['medicine_search'] = $searchTerm;
                $parameters['action_search'] = $searchTerm;
                $parameters['remarks_search'] = $searchTerm;
                $parameters['batch_search'] = $searchTerm;
            }

            $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
            $summary = $pdo->prepare(
                "SELECT COUNT(*) AS total_records,
                        COALESCE(SUM(h.record_type = 'Disease'), 0) AS disease_count,
                        COALESCE(SUM(h.record_type = 'Vaccination'), 0) AS vaccination_count,
                        COALESCE(SUM(h.record_type = 'Treatment'), 0) AS treatment_count,
                        COALESCE(SUM(h.record_type = 'Health Check'), 0) AS health_check_count,
                        COALESCE(SUM(h.status = 'Under Treatment'), 0) AS under_treatment_count,
                        COALESCE(SUM(CASE WHEN h.status = 'Under Treatment' THEN h.affected_birds ELSE 0 END), 0) AS birds_under_treatment
                 FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id" . $whereSql
            );
            $summary->execute($parameters);
            $summaryData = $summary->fetch();
            if ($summaryData) {
                $stats = $summaryData;
            }

            $recordQuery = $pdo->prepare(
                'SELECT h.health_id, h.record_date, h.flock_id, f.batch_code, h.record_type,
                        h.description, h.affected_birds, h.medicine_or_vaccine, h.quantity_used,
                        h.unit, h.action_taken, h.status, h.remarks
                 FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id' . $whereSql . '
                 ORDER BY h.record_date DESC, h.health_id DESC'
            );
            $recordQuery->execute($parameters);
            $records = $recordQuery->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Health monitoring query failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'Health records could not be loaded. Check the database connection and schema.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>

<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<?php if (!$canManage && !$databaseFailure): ?><div class="alert alert-warning role-note" role="status">Read-only access. <a href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in as an administrator</a> to add or edit health records.</div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div><div class="eyebrow">POULTRY MANAGEMENT</div><h1><?= $isEditing ? 'Edit health record' : 'Add health record' ?></h1><p>Record disease incidents, vaccinations, treatments, or health checks.</p></div>
        <a class="btn btn-outline-secondary" href="index.php">Back to health records</a>
    </div>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert"><strong>Please correct the highlighted fields.</strong></div><?php endif; ?>
    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Health record details</h2></div>
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'edit' : 'add' ?><?= $isEditing ? '&id=' . (int) $healthRecord['health_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="health_id" value="<?= (int) $healthRecord['health_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="flock_id">Flock / batch <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['flock_id']) ? ' is-invalid' : '' ?>" id="flock_id" name="flock_id" required><option value="">Select flock</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['flock_id'] ?>" <?= (string) $formData['flock_id'] === (string) $flock['flock_id'] ? 'selected' : '' ?>><?= h($flock['batch_code']) ?> (<?= h($flock['status']) ?>)</option><?php endforeach; ?></select>
                        <?php if (isset($errors['flock_id'])): ?><div class="invalid-feedback"><?= h($errors['flock_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="record_date">Record date <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['record_date']) ? ' is-invalid' : '' ?>" type="date" id="record_date" name="record_date" required value="<?= h($formData['record_date']) ?>">
                        <?php if (isset($errors['record_date'])): ?><div class="invalid-feedback"><?= h($errors['record_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="record_type">Record type <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['record_type']) ? ' is-invalid' : '' ?>" id="record_type" name="record_type" required><option value="">Select type</option><?php foreach (HEALTH_RECORD_TYPES as $type): ?><option value="<?= h($type) ?>" <?= $formData['record_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?></select>
                        <?php if (isset($errors['record_type'])): ?><div class="invalid-feedback"><?= h($errors['record_type']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required><?php foreach (HEALTH_STATUSES as $status): ?><option value="<?= h($status) ?>" <?= $formData['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <input class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" maxlength="255" value="<?= h($formData['description']) ?>">
                        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="affected_birds">Affected birds</label>
                        <input class="form-control<?= isset($errors['affected_birds']) ? ' is-invalid' : '' ?>" type="number" id="affected_birds" name="affected_birds" min="0" step="1" value="<?= h($formData['affected_birds']) ?>">
                        <?php if (isset($errors['affected_birds'])): ?><div class="invalid-feedback"><?= h($errors['affected_birds']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="medicine_or_vaccine">Medicine / Vaccine</label>
                        <input class="form-control<?= isset($errors['medicine_or_vaccine']) ? ' is-invalid' : '' ?>" id="medicine_or_vaccine" name="medicine_or_vaccine" maxlength="150" list="medicine-options" value="<?= h($formData['medicine_or_vaccine']) ?>">
                        <datalist id="medicine-options"><?php foreach ($medicineItems as $medicineItem): ?><option value="<?= h($medicineItem['item_name']) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['medicine_or_vaccine'])): ?><div class="invalid-feedback"><?= h($errors['medicine_or_vaccine']) ?></div><?php endif; ?>
                        <div class="form-text">Suggestions use current medicine/vaccine inventory names; selecting one does not deduct stock.</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="quantity_used">Quantity used</label>
                        <input class="form-control<?= isset($errors['quantity_used']) ? ' is-invalid' : '' ?>" type="number" id="quantity_used" name="quantity_used" min="0" max="99999999.99" step="0.01" value="<?= h($formData['quantity_used']) ?>">
                        <?php if (isset($errors['quantity_used'])): ?><div class="invalid-feedback"><?= h($errors['quantity_used']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="unit">Unit</label>
                        <input class="form-control<?= isset($errors['unit']) ? ' is-invalid' : '' ?>" id="unit" name="unit" maxlength="30" value="<?= h($formData['unit']) ?>">
                        <?php if (isset($errors['unit'])): ?><div class="invalid-feedback"><?= h($errors['unit']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="action_taken">Action taken</label>
                        <textarea class="form-control" id="action_taken" name="action_taken" rows="3"><?= h($formData['action_taken']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($formData['remarks']) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Save health record' ?></button><a class="btn btn-outline-secondary" href="index.php">Cancel</a></div>
                </div>
            </form>
        </div>
    </section>
<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div><div class="eyebrow">POULTRY MANAGEMENT</div><h1>Poultry health &amp; disease</h1><p>Monitor health incidents, vaccinations, treatments, and checks.</p></div>
        <?php if ($canManage && !$databaseFailure): ?><a class="btn btn-primary" href="index.php?action=add">Add health record</a><?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $summaryCards = [
            ['Total health records', $stats['total_records']],
            ['Disease incidents', $stats['disease_count']],
            ['Vaccinations', $stats['vaccination_count']],
            ['Treatments', $stats['treatment_count']],
            ['Health checks', $stats['health_check_count']],
            ['Birds under treatment', $stats['birds_under_treatment']],
        ];
        ?>
        <?php foreach ($summaryCards as [$label, $value]): ?><div class="col-6 col-lg-4 col-xl-2"><div class="stat-card"><div class="stat-label"><?= h($label) ?></div><div class="stat-value"><?= number_format((int) $value) ?></div></div></div><?php endforeach; ?>
    </div>

    <section class="content-panel">
        <div class="panel-heading d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="h5 mb-0">Health records</h2><span class="text-secondary small"><?= number_format(count($records)) ?> record<?= count($records) === 1 ? '' : 's' ?></span></div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label" for="filter_type">Record type</label><select class="form-select" id="filter_type" name="record_type"><option value="">All types</option><?php foreach (HEALTH_RECORD_TYPES as $type): ?><option value="<?= h($type) ?>" <?= $filters['record_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label" for="filter_status">Status</label><select class="form-select" id="filter_status" name="status"><option value="">All statuses</option><?php foreach (HEALTH_STATUSES as $status): ?><option value="<?= h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label" for="filter_flock">Flock / batch</label><select class="form-select" id="filter_flock" name="flock_id"><option value="">All flocks</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['flock_id'] ?>" <?= $filters['flock_id'] === (string) $flock['flock_id'] ? 'selected' : '' ?>><?= h($flock['batch_code']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label" for="filter_date">Record date</label><input class="form-control" type="date" id="filter_date" name="record_date" value="<?= h($filters['record_date']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="health_search">Search</label><input class="form-control" id="health_search" name="search" value="<?= h($filters['search']) ?>" placeholder="Search health records"></div>
                <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button><a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th scope="col">Health Record ID</th><th scope="col">Date</th><th scope="col">Flock / Batch Code</th><th scope="col">Record Type</th><th scope="col">Description</th><th scope="col" class="text-end">Affected Birds</th><th scope="col">Medicine / Vaccine</th><th scope="col" class="text-end">Quantity Used</th><th scope="col">Unit</th><th scope="col">Action Taken</th><th scope="col">Status</th><th scope="col">Remarks</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php if ($records === []): ?><tr><td colspan="13" class="text-center text-secondary py-5">No health records match these filters.</td></tr>
                    <?php else: foreach ($records as $healthRow): ?><tr>
                        <td><?= (int) $healthRow['health_id'] ?></td><td><?= h($healthRow['record_date']) ?></td><td class="fw-semibold"><?= h($healthRow['batch_code']) ?></td><td><?= h($healthRow['record_type']) ?></td><td><?= h($healthRow['description'] ?? '') ?></td><td class="text-end"><?= number_format((int) $healthRow['affected_birds']) ?></td><td><?= h($healthRow['medicine_or_vaccine'] ?? '') ?></td><td class="text-end"><?= number_format((float) $healthRow['quantity_used'], 2) ?></td><td><?= h($healthRow['unit'] ?? '') ?></td><td><?= h($healthRow['action_taken'] ?? '') ?></td><td><span class="badge text-bg-<?= h(health_status_badge($healthRow['status'])) ?>"><?= h($healthRow['status']) ?></span></td><td><?= h($healthRow['remarks'] ?? '') ?></td>
                        <td class="text-end text-nowrap"><?php if ($canManage): ?><a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $healthRow['health_id'] ?>">Edit</a><form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this health record to the Recycle Bin?\nType: <?= h($healthRow['record_type']) ?>\nBatch: <?= h($healthRow['batch_code']) ?>\nThis record can be restored later.');"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="health_id" value="<?= (int) $healthRow['health_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form><?php else: ?><span class="text-secondary">Read only</span><?php endif; ?></td>
                    </tr><?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>