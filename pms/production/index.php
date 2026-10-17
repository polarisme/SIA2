<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

function production_form_data(array $source): array
{
    return [
        'flock_id' => trim((string) ($source['flock_id'] ?? '')),
        'record_date' => trim((string) ($source['record_date'] ?? '')),
        'eggs_produced' => trim((string) ($source['eggs_produced'] ?? '0')),
        'good_eggs' => trim((string) ($source['good_eggs'] ?? '0')),
        'damaged_eggs' => trim((string) ($source['damaged_eggs'] ?? '0')),
        'sample_size' => trim((string) ($source['sample_size'] ?? '0')),
        'average_weight' => trim((string) ($source['average_weight'] ?? '0')),
        'mortality_count' => trim((string) ($source['mortality_count'] ?? '0')),
        'remarks' => trim((string) ($source['remarks'] ?? '')),
    ];
}

function production_date_is_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function production_count_is_valid(string $value): bool
{
    return preg_match('/\A\d{1,9}\z/', $value) === 1;
}

function production_decimal_is_valid(string $value): bool
{
    return preg_match('/\A\d{1,5}(?:\.\d{1,2})?\z/', $value) === 1;
}

function validate_production_record(array $record, PDO $pdo): array
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

    if (!production_date_is_valid($record['record_date'])) {
        $errors['record_date'] = 'Enter a valid record date.';
    }

    foreach (['eggs_produced', 'good_eggs', 'damaged_eggs', 'sample_size', 'mortality_count'] as $field) {
        if (!production_count_is_valid($record[$field])) {
            $errors[$field] = 'Enter a non-negative whole number.';
        }
    }
    if (!production_decimal_is_valid($record['average_weight'])) {
        $errors['average_weight'] = 'Enter a non-negative weight with up to two decimal places.';
    }

    if (production_count_is_valid($record['eggs_produced'])
        && production_count_is_valid($record['good_eggs'])
        && production_count_is_valid($record['damaged_eggs'])
        && (int) $record['good_eggs'] + (int) $record['damaged_eggs'] > (int) $record['eggs_produced']) {
        $errors['eggs_produced'] = 'Good and damaged eggs cannot total more than eggs produced.';
    }
    if (mb_strlen($record['remarks']) > 65535) {
        $errors['remarks'] = 'Remarks are too long.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Production dashboard database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
$activeNavigation = 'production';
$canManage = can_manage_flocks();
$pageTitle = 'Production Monitoring';
$formData = production_form_data([]);
$errors = [];
$record = null;
$isEditing = false;
$flocks = [];
$records = [];
$flockTotals = [];
$stats = [
    'eggs_produced' => 0,
    'good_eggs' => 0,
    'damaged_eggs' => 0,
    'mortality_count' => 0,
    'average_weight' => null,
    'good_percentage' => null,
    'damaged_percentage' => null,
    'mortality_rate' => null,
];
$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'flock_id' => trim((string) ($_GET['flock_id'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$databaseFailure = $databaseError !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && !$databaseFailure) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }
    if (!$canManage) {
        set_flash('danger', 'You are not authorized to change production records.');
        redirect_to('index.php');
    }

    try {
        if ($action === 'delete') {
            $productionId = filter_var($_POST['production_id'] ?? null, FILTER_VALIDATE_INT);
            if ($productionId === false || $productionId < 1) {
                set_flash('danger', 'The selected production record could not be found.');
                redirect_to('index.php');
            }
            if (!pms_soft_delete($pdo, 'production_records', $productionId)) {
                set_flash('danger', 'The production record could not be moved to the Recycle Bin.');
            } else {
                set_flash('success', 'Production record moved to the Recycle Bin.');
            }
            redirect_to('index.php');
        }
        if (!in_array($action, ['create', 'update'], true)) {
            set_flash('danger', 'The requested action is not available.');
            redirect_to('index.php');
        }

        $formData = production_form_data($_POST);
        $errors = validate_production_record($formData, $pdo);
        $isEditing = $action === 'update';
        $productionId = filter_var($_POST['production_id'] ?? null, FILTER_VALIDATE_INT);
        if ($isEditing && ($productionId === false || $productionId < 1)) {
            set_flash('danger', 'The selected production record could not be found.');
            redirect_to('index.php');
        }
        if ($isEditing) {
            $exists = $pdo->prepare('SELECT production_id FROM production_records WHERE production_id = ? AND deleted_at IS NULL');
            $exists->execute([$productionId]);
            if ($exists->fetchColumn() === false) {
                set_flash('danger', 'The selected production record could not be found.');
                redirect_to('index.php');
            }
        }

        if ($errors === []) {
            $values = [
                'flock_id' => (int) $formData['flock_id'],
                'record_date' => $formData['record_date'],
                'eggs_produced' => (int) $formData['eggs_produced'],
                'good_eggs' => (int) $formData['good_eggs'],
                'damaged_eggs' => (int) $formData['damaged_eggs'],
                'sample_size' => (int) $formData['sample_size'],
                'average_weight' => $formData['average_weight'],
                'mortality_count' => (int) $formData['mortality_count'],
                'remarks' => $formData['remarks'] === '' ? null : $formData['remarks'],
            ];

            if ($isEditing) {
                $priorStatement = $pdo->prepare('SELECT flock_id, mortality_count FROM production_records WHERE production_id = ? AND deleted_at IS NULL');
                $priorStatement->execute([$productionId]);
                $prior = $priorStatement->fetch();

                $pdo->beginTransaction();
                try {
                    $statement = $pdo->prepare(
                        'UPDATE production_records
                         SET flock_id = :flock_id, record_date = :record_date, eggs_produced = :eggs_produced,
                             good_eggs = :good_eggs, damaged_eggs = :damaged_eggs, sample_size = :sample_size,
                             average_weight = :average_weight, mortality_count = :mortality_count, remarks = :remarks
                         WHERE production_id = :production_id AND deleted_at IS NULL'
                    );
                    $statement->execute($values + ['production_id' => $productionId]);
                    if ($prior) {
                        // Reverse the old mortality on its (possibly different) flock, then apply the new one.
                        pms_adjust_flock_birds($pdo, (int) $prior['flock_id'], (int) $prior['mortality_count']);
                        pms_adjust_flock_birds($pdo, $values['flock_id'], -$values['mortality_count']);
                    }
                    $pdo->commit();
                    audit_pms_action($pdo, 'UPDATE', 'production_records', $productionId);
                    set_flash('success', 'Production record updated.');
                    redirect_to('index.php');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $errors['mortality_count'] = 'This mortality count would reduce a flock\'s current birds below zero.';
                }
            } else {
                $pdo->beginTransaction();
                try {
                    $statement = $pdo->prepare(
                        'INSERT INTO production_records
                            (flock_id, record_date, eggs_produced, good_eggs, damaged_eggs, sample_size, average_weight, mortality_count, remarks)
                         VALUES
                            (:flock_id, :record_date, :eggs_produced, :good_eggs, :damaged_eggs, :sample_size, :average_weight, :mortality_count, :remarks)'
                    );
                    $statement->execute($values);
                    $newProductionId = (int) $pdo->lastInsertId();
                    pms_adjust_flock_birds($pdo, $values['flock_id'], -$values['mortality_count']);
                    $pdo->commit();
                    audit_pms_action($pdo, 'CREATE', 'production_records', $newProductionId);
                    set_flash('success', 'Production record saved.');
                    redirect_to('index.php');
                } catch (RuntimeException $exception) {
                    $pdo->rollBack();
                    $errors['mortality_count'] = 'This mortality count would reduce the flock\'s current birds below zero.';
                }
            }
        }

        $action = $isEditing ? 'edit' : 'add';
        $pageTitle = $isEditing ? 'Edit Production Record' : 'Add Production Record';
        if ($isEditing) {
            $record = ['production_id' => $productionId] + $formData;
        }
    } catch (PDOException $exception) {
        error_log('Production record write failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'The production record could not be saved because of a database error.';
    }
}

if ($pdo instanceof PDO && !$databaseFailure && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code, initial_birds, status FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code')->fetchAll();

        if (in_array($action, ['add', 'edit'], true)) {
            if (!$canManage) {
                set_flash('warning', 'Sign in as an administrator to add or edit production records.');
                redirect_to('index.php');
            }
            $isEditing = $action === 'edit';
            $pageTitle = $isEditing ? 'Edit Production Record' : 'Add Production Record';
            if ($isEditing) {
                $productionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                if ($productionId === false || $productionId < 1) {
                    set_flash('danger', 'The selected production record could not be found.');
                    redirect_to('index.php');
                }
                $statement = $pdo->prepare('SELECT * FROM production_records WHERE production_id = ? AND deleted_at IS NULL');
                $statement->execute([$productionId]);
                $record = $statement->fetch();
                if (!$record) {
                    set_flash('danger', 'The selected production record could not be found.');
                    redirect_to('index.php');
                }
                $formData = production_form_data($record);
            }
        } else {
            $action = 'list';
            $conditions = ['p.deleted_at IS NULL', 'f.deleted_at IS NULL'];
            $parameters = [];
            if ($filters['date_from'] !== '') {
                if (production_date_is_valid($filters['date_from'])) {
                    $conditions[] = 'p.record_date >= :date_from';
                    $parameters['date_from'] = $filters['date_from'];
                } else {
                    $filters['date_from'] = '';
                }
            }
            if ($filters['date_to'] !== '') {
                if (production_date_is_valid($filters['date_to'])) {
                    $conditions[] = 'p.record_date <= :date_to';
                    $parameters['date_to'] = $filters['date_to'];
                } else {
                    $filters['date_to'] = '';
                }
            }
            if ($filters['flock_id'] !== '') {
                $flockId = filter_var($filters['flock_id'], FILTER_VALIDATE_INT);
                if ($flockId !== false && $flockId > 0) {
                    $conditions[] = 'p.flock_id = :flock_id';
                    $parameters['flock_id'] = $flockId;
                } else {
                    $filters['flock_id'] = '';
                }
            }
            if ($filters['search'] !== '') {
                $conditions[] = '(f.batch_code LIKE :batch_search OR p.remarks LIKE :remarks_search)';
                $parameters['batch_search'] = '%' . $filters['search'] . '%';
                $parameters['remarks_search'] = '%' . $filters['search'] . '%';
            }

            $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
            $summary = $pdo->prepare(
                'SELECT COALESCE(SUM(p.eggs_produced), 0) AS eggs_produced,
                        COALESCE(SUM(p.good_eggs), 0) AS good_eggs,
                        COALESCE(SUM(p.damaged_eggs), 0) AS damaged_eggs,
                        COALESCE(SUM(p.mortality_count), 0) AS mortality_count,
                        AVG(NULLIF(p.average_weight, 0)) AS average_weight
                 FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id' . $whereSql
            );
            $summary->execute($parameters);
            $summaryData = $summary->fetch();
            if ($summaryData) {
                $stats = array_merge($stats, $summaryData);
                $eggTotal = (int) $stats['eggs_produced'];
                $stats['good_percentage'] = $eggTotal > 0 ? (int) $stats['good_eggs'] / $eggTotal * 100 : null;
                $stats['damaged_percentage'] = $eggTotal > 0 ? (int) $stats['damaged_eggs'] / $eggTotal * 100 : null;
            }

            $mortality = $pdo->prepare(
                'SELECT COALESCE(SUM(p.mortality_count), 0) AS mortality_count,
                        COALESCE(SUM(f.initial_birds), 0) AS initial_birds
                 FROM (
                     SELECT p.flock_id, SUM(p.mortality_count) AS mortality_count
                     FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id' . $whereSql . '
                     GROUP BY p.flock_id
                 ) p JOIN flocks f ON f.flock_id = p.flock_id'
            );
            $mortality->execute($parameters);
            $mortalityData = $mortality->fetch();
            $initialBirds = (int) ($mortalityData['initial_birds'] ?? 0);
            $stats['mortality_rate'] = $initialBirds > 0 ? (int) ($mortalityData['mortality_count'] ?? 0) / $initialBirds * 100 : null;

            $recordsSql = 'SELECT p.production_id, p.flock_id, f.batch_code, p.record_date,
                                  p.eggs_produced, p.good_eggs, p.damaged_eggs, p.sample_size,
                                  p.average_weight, p.mortality_count, p.remarks
                           FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id' . $whereSql . '
                           ORDER BY p.record_date DESC, p.production_id DESC';
            $recordQuery = $pdo->prepare($recordsSql);
            $recordQuery->execute($parameters);
            $records = $recordQuery->fetchAll();

            $byFlockSql = 'SELECT f.flock_id, f.batch_code, SUM(p.eggs_produced) AS eggs_produced,
                                  SUM(p.good_eggs) AS good_eggs, SUM(p.damaged_eggs) AS damaged_eggs
                           FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id' . $whereSql . '
                           GROUP BY f.flock_id, f.batch_code ORDER BY eggs_produced DESC, f.batch_code';
            $flockQuery = $pdo->prepare($byFlockSql);
            $flockQuery->execute($parameters);
            $flockTotals = $flockQuery->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Production dashboard query failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'Production information could not be loaded. Check the database connection and schema.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>

<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<?php if (!$canManage && !$databaseFailure): ?><div class="alert alert-warning role-note" role="status">Read-only access. <a href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in as an administrator</a> to add or edit production records.</div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div><div class="eyebrow">POULTRY MANAGEMENT</div><h1><?= $isEditing ? 'Edit production record' : 'Add production record' ?></h1><p>Record flock production and mortality observations.</p></div>
        <a class="btn btn-outline-secondary" href="index.php">Back to production</a>
    </div>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert"><strong>Please correct the highlighted fields.</strong></div><?php endif; ?>
    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Production details</h2></div>
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'edit' : 'add' ?><?= $isEditing ? '&id=' . (int) $record['production_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="production_id" value="<?= (int) $record['production_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="flock_id">Flock / batch <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['flock_id']) ? ' is-invalid' : '' ?>" id="flock_id" name="flock_id" required>
                            <option value="">Select flock</option>
                            <?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['flock_id'] ?>" <?= (string) $formData['flock_id'] === (string) $flock['flock_id'] ? 'selected' : '' ?>><?= h($flock['batch_code']) ?> (<?= h($flock['status']) ?>)</option><?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['flock_id'])): ?><div class="invalid-feedback"><?= h($errors['flock_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="record_date">Record date <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['record_date']) ? ' is-invalid' : '' ?>" type="date" id="record_date" name="record_date" required value="<?= h($formData['record_date']) ?>">
                        <?php if (isset($errors['record_date'])): ?><div class="invalid-feedback"><?= h($errors['record_date']) ?></div><?php endif; ?>
                    </div>
                    <?php foreach ([
                        'eggs_produced' => 'Eggs produced',
                        'good_eggs' => 'Good eggs',
                        'damaged_eggs' => 'Damaged eggs',
                        'sample_size' => 'Sample size',
                        'average_weight' => 'Average weight',
                        'mortality_count' => 'Mortality count',
                    ] as $field => $label): ?>
                        <div class="col-md-4">
                            <label class="form-label" for="<?= h($field) ?>"><?= h($label) ?><?= in_array($field, ['eggs_produced', 'good_eggs', 'damaged_eggs', 'sample_size', 'mortality_count'], true) ? ' (0 if none)' : '' ?></label>
                            <input class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" type="number" id="<?= h($field) ?>" name="<?= h($field) ?>" min="0" step="<?= $field === 'average_weight' ? '0.01' : '1' ?>" value="<?= h($formData[$field]) ?>">
                            <?php if (isset($errors[$field])): ?><div class="invalid-feedback"><?= h($errors[$field]) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea class="form-control<?= isset($errors['remarks']) ? ' is-invalid' : '' ?>" id="remarks" name="remarks" rows="3"><?= h($formData['remarks']) ?></textarea>
                        <?php if (isset($errors['remarks'])): ?><div class="invalid-feedback"><?= h($errors['remarks']) ?></div><?php endif; ?>
                    </div>
                    <?php if (isset($errors['eggs_produced'])): ?><div class="col-12 text-danger small"><?= h($errors['eggs_produced']) ?></div><?php endif; ?>
                    <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Save production record' ?></button><a class="btn btn-outline-secondary" href="index.php">Cancel</a></div>
                </div>
            </form>
        </div>
    </section>
<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div><div class="eyebrow">POULTRY MANAGEMENT</div><h1>Production monitoring</h1><p>Review egg production, quality, weight, and flock mortality.</p></div>
        <?php if ($canManage && !$databaseFailure): ?><a class="btn btn-primary" href="index.php?action=add">Add production record</a><?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $summaryCards = [
            ['Total eggs produced', number_format((int) $stats['eggs_produced'])],
            ['Good eggs', number_format((int) $stats['good_eggs'])],
            ['Damaged eggs', number_format((int) $stats['damaged_eggs'])],
            ['Total mortality', number_format((int) $stats['mortality_count'])],
            ['Average production weight', $stats['average_weight'] === null ? 'N/A' : number_format((float) $stats['average_weight'], 2)],
        ];
        ?>
        <?php foreach ($summaryCards as [$label, $value]): ?><div class="col-6 col-lg"><div class="stat-card"><div class="stat-label"><?= h($label) ?></div><div class="stat-value"><?= h($value) ?></div></div></div><?php endforeach; ?>
    </div>

    <section class="content-panel mb-4">
        <div class="panel-heading"><h2 class="h5 mb-0">Production performance</h2></div>
        <div class="panel-body">
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3"><div class="detail-label">Good egg percentage</div><div class="detail-value fw-semibold"><?= $stats['good_percentage'] === null ? 'N/A' : number_format((float) $stats['good_percentage'], 1) . '%' ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="detail-label">Damaged egg percentage</div><div class="detail-value fw-semibold"><?= $stats['damaged_percentage'] === null ? 'N/A' : number_format((float) $stats['damaged_percentage'], 1) . '%' ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="detail-label">Mortality / initial birds</div><div class="detail-value fw-semibold"><?= $stats['mortality_rate'] === null ? 'N/A' : number_format((float) $stats['mortality_rate'], 2) . '%' ?></div></div>
                <div class="col-sm-6 col-lg-3"><div class="detail-label">Weight unit</div><div class="detail-value text-secondary">As entered in production records</div></div>
            </div>
            <p class="small text-secondary mt-3 mb-0">Percentages reflect the current filters. Mortality rate divides filtered mortality by the initial bird count of each represented flock once. Weight units are not defined by the existing schema.</p>
        </div>
    </section>

    <section class="content-panel mb-4">
        <div class="panel-heading"><h2 class="h5 mb-0">Production by flock / batch</h2></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th scope="col">Flock / Batch Code</th><th scope="col" class="text-end">Eggs Produced</th><th scope="col" class="text-end">Good Eggs</th><th scope="col" class="text-end">Damaged Eggs</th></tr></thead>
                <tbody>
                    <?php if ($flockTotals === []): ?><tr><td colspan="4" class="text-center text-secondary py-4">No production totals to display.</td></tr>
                    <?php else: foreach ($flockTotals as $flockTotal): ?><tr><td><?= h($flockTotal['batch_code']) ?></td><td class="text-end"><?= number_format((int) $flockTotal['eggs_produced']) ?></td><td class="text-end"><?= number_format((int) $flockTotal['good_eggs']) ?></td><td class="text-end"><?= number_format((int) $flockTotal['damaged_eggs']) ?></td></tr><?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="content-panel">
        <div class="panel-heading d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="h5 mb-0">Production records</h2><span class="text-secondary small"><?= number_format(count($records)) ?> record<?= count($records) === 1 ? '' : 's' ?></span></div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label" for="date_from">Date from</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($filters['date_from']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="date_to">Date to</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($filters['date_to']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="flock_filter">Flock / batch</label><select class="form-select" id="flock_filter" name="flock_id"><option value="">All flocks</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['flock_id'] ?>" <?= $filters['flock_id'] === (string) $flock['flock_id'] ? 'selected' : '' ?>><?= h($flock['batch_code']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label" for="production_search">Search batch / remarks</label><input class="form-control" id="production_search" name="search" value="<?= h($filters['search']) ?>" placeholder="Search records"></div>
                <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button><a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th scope="col">Record Date</th><th scope="col">Flock / Batch Code</th><th scope="col" class="text-end">Eggs Produced</th><th scope="col" class="text-end">Good Eggs</th><th scope="col" class="text-end">Damaged Eggs</th><th scope="col" class="text-end">Sample Size</th><th scope="col" class="text-end">Average Weight</th><th scope="col" class="text-end">Mortality Count</th><th scope="col">Remarks</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php if ($records === []): ?><tr><td colspan="10" class="text-center text-secondary py-5">No production records match these filters.</td></tr>
                    <?php else: foreach ($records as $productionRecord): ?><tr>
                        <td><?= h($productionRecord['record_date']) ?></td><td class="fw-semibold"><?= h($productionRecord['batch_code']) ?></td>
                        <td class="text-end"><?= number_format((int) $productionRecord['eggs_produced']) ?></td><td class="text-end"><?= number_format((int) $productionRecord['good_eggs']) ?></td><td class="text-end"><?= number_format((int) $productionRecord['damaged_eggs']) ?></td><td class="text-end"><?= number_format((int) $productionRecord['sample_size']) ?></td><td class="text-end"><?= number_format((float) $productionRecord['average_weight'], 2) ?></td><td class="text-end"><?= number_format((int) $productionRecord['mortality_count']) ?></td><td><?= h($productionRecord['remarks'] ?? '') ?></td>
                        <td class="text-end text-nowrap"><?php if ($canManage): ?><a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $productionRecord['production_id'] ?>">Edit</a><form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this production record to the Recycle Bin?\nBatch: <?= h($productionRecord['batch_code']) ?>\nDate: <?= h($productionRecord['record_date']) ?>\nThis record can be restored later.');"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="production_id" value="<?= (int) $productionRecord['production_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form><?php else: ?><span class="text-secondary">Read only</span><?php endif; ?></td>
                    </tr><?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>