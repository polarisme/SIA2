<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_employees';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

function employee_form_data(array $source): array
{
    return [
        'employee_code' => trim((string) ($source['employee_code'] ?? '')),
        'full_name' => trim((string) ($source['full_name'] ?? '')),
        'job_title' => trim((string) ($source['job_title'] ?? '')),
        'department' => trim((string) ($source['department'] ?? '')),
        'employment_status' => trim((string) ($source['employment_status'] ?? 'Active')),
        'pay_type' => trim((string) ($source['pay_type'] ?? 'Daily')),
        'basic_rate' => trim((string) ($source['basic_rate'] ?? '')),
        'date_hired' => trim((string) ($source['date_hired'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? '')),
    ];
}

function validate_employee(array $data): array
{
    $errors = [];
    if ($data['employee_code'] === '' || mb_strlen($data['employee_code']) > 20) {
        $errors['employee_code'] = 'Employee code is required (up to 20 characters).';
    }
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150) {
        $errors['full_name'] = 'Full name is required (up to 150 characters).';
    }
    if ($data['job_title'] === '' || mb_strlen($data['job_title']) > 100) {
        $errors['job_title'] = 'Job title is required (up to 100 characters).';
    }
    if (!in_array($data['department'], PAYROLL_DEPARTMENTS, true)) {
        $errors['department'] = 'Select a valid department.';
    }
    if (!in_array($data['employment_status'], ['Active', 'Inactive', 'Terminated'], true)) {
        $errors['employment_status'] = 'Select a valid employment status.';
    }
    if (!in_array($data['pay_type'], ['Daily', 'Monthly'], true)) {
        $errors['pay_type'] = 'Select a valid pay type.';
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $data['basic_rate']) || (float) $data['basic_rate'] <= 0) {
        $errors['basic_rate'] = 'Enter a positive wage/salary amount.';
    }
    $hiredDate = DateTimeImmutable::createFromFormat('!Y-m-d', $data['date_hired']);
    if ($hiredDate === false) {
        $errors['date_hired'] = 'Enter a valid hire date.';
    }
    if (mb_strlen($data['notes']) > 255) {
        $errors['notes'] = 'Notes must be 255 characters or fewer.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Employees database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
$errors = [];
$formData = employee_form_data([]);
$isEditing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create' || $action === 'update') {
        $isEditing = $action === 'update';
        $employeeId = $isEditing ? filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($isEditing && ($employeeId === false || $employeeId < 1)) {
            set_flash('danger', 'The selected employee could not be found.');
            redirect_to('index.php');
        }

        $formData = employee_form_data($_POST);
        $errors = validate_employee($formData);
        if ($errors === []) {
            $dupCheck = $pdo->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND deleted_at IS NULL' . ($isEditing ? ' AND employee_id <> ?' : ''));
            $dupCheck->execute($isEditing ? [$formData['employee_code'], $employeeId] : [$formData['employee_code']]);
            if ($dupCheck->fetchColumn() !== false) {
                $errors['employee_code'] = 'This employee code is already in use.';
            }
        }

        if ($errors === []) {
            if ($isEditing) {
                $pdo->prepare(
                    'UPDATE employees SET employee_code = ?, full_name = ?, job_title = ?, department = ?, employment_status = ?,
                        pay_type = ?, basic_rate = ?, date_hired = ?, notes = ? WHERE employee_id = ? AND deleted_at IS NULL'
                )->execute([
                    $formData['employee_code'], $formData['full_name'], $formData['job_title'], $formData['department'],
                    $formData['employment_status'], $formData['pay_type'], $formData['basic_rate'], $formData['date_hired'],
                    $formData['notes'] !== '' ? $formData['notes'] : null, $employeeId,
                ]);
                set_flash('success', 'Employee record updated.');
                redirect_to('index.php');
            }
            $pdo->prepare(
                'INSERT INTO employees (employee_code, full_name, job_title, department, employment_status, pay_type, basic_rate, date_hired, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $formData['employee_code'], $formData['full_name'], $formData['job_title'], $formData['department'],
                $formData['employment_status'], $formData['pay_type'], $formData['basic_rate'], $formData['date_hired'],
                $formData['notes'] !== '' ? $formData['notes'] : null,
            ]);
            set_flash('success', 'Employee added.');
            redirect_to('index.php');
        }
        $action = $isEditing ? 'edit' : 'add';
    } elseif ($action === 'delete') {
        $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($employeeId !== false && $employeeId > 0) {
            $pdo->prepare('UPDATE employees SET deleted_at = NOW(), deleted_by = ? WHERE employee_id = ? AND deleted_at IS NULL')
                ->execute([current_username(), $employeeId]);
            set_flash('success', 'Employee record removed.');
        }
        redirect_to('index.php');
    }
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $employeeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($employeeId === false || $employeeId < 1) {
        set_flash('danger', 'The selected employee could not be found.');
        redirect_to('index.php');
    }
    $statement = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ? AND deleted_at IS NULL');
    $statement->execute([$employeeId]);
    $record = $statement->fetch();
    if (!$record) {
        set_flash('danger', 'The selected employee could not be found.');
        redirect_to('index.php');
    }
    $formData = employee_form_data($record);
    $isEditing = true;
}

$employees = [];
$totalEmployees = 0;
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'daily' => 0, 'monthly' => 0];
['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
if ($pdo instanceof PDO && $databaseError === null && in_array($action, ['list', 'add', 'edit'], true)) {
    try {
        $summary = $pdo->query(
            "SELECT COUNT(*) AS total, SUM(employment_status = 'Active') AS active, SUM(employment_status = 'Inactive') AS inactive,
                    SUM(pay_type = 'Daily') AS daily, SUM(pay_type = 'Monthly') AS monthly
             FROM employees WHERE deleted_at IS NULL"
        )->fetch();
        if ($summary) {
            $stats = array_map('intval', $summary);
        }

        $departmentFilter = trim((string) ($_GET['department'] ?? ''));
        $statusFilter = trim((string) ($_GET['employment_status'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $conditions = ['deleted_at IS NULL'];
        $parameters = [];
        if ($departmentFilter !== '' && in_array($departmentFilter, PAYROLL_DEPARTMENTS, true)) {
            $conditions[] = 'department = ?';
            $parameters[] = $departmentFilter;
        } else {
            $departmentFilter = '';
        }
        if ($statusFilter !== '' && in_array($statusFilter, ['Active', 'Inactive', 'Terminated'], true)) {
            $conditions[] = 'employment_status = ?';
            $parameters[] = $statusFilter;
        } else {
            $statusFilter = '';
        }
        if ($search !== '') {
            $conditions[] = '(full_name LIKE ? OR employee_code LIKE ? OR job_title LIKE ?)';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $countStatement = $pdo->prepare("SELECT COUNT(*) FROM employees $whereSql");
        $countStatement->execute($parameters);
        $totalEmployees = (int) $countStatement->fetchColumn();

        $statement = $pdo->prepare("SELECT * FROM employees $whereSql ORDER BY department, full_name LIMIT $perPage OFFSET $offset");
        $statement->execute($parameters);
        $employees = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Employees list query failed: ' . $exception->getMessage());
        $databaseError = 'Employee records could not be loaded because of a database error.';
    }
}

$pageTitle = $action === 'add' ? 'Add Employee' : ($action === 'edit' ? 'Edit Employee' : 'Employees');
require __DIR__ . '/../../shared/includes/header.php';
?>
<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading">
        <div class="eyebrow">PAYROLL &middot; EMPLOYEES</div>
        <h1><?= $isEditing ? 'Edit Employee' : 'Add Employee' ?></h1>
    </div>
    <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
    <section class="content-panel">
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'update' : 'create' ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <?php if ($isEditing): ?><input type="hidden" name="employee_id" value="<?= (int) $record['employee_id'] ?>"><?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label" for="employee_code">Employee ID</label>
                    <input class="form-control<?= isset($errors['employee_code']) ? ' is-invalid' : '' ?>" id="employee_code" name="employee_code" value="<?= h($formData['employee_code']) ?>" maxlength="20" required>
                    <?php if (isset($errors['employee_code'])): ?><div class="invalid-feedback"><?= h($errors['employee_code']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="full_name">Full name</label>
                    <input class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>" id="full_name" name="full_name" value="<?= h($formData['full_name']) ?>" maxlength="150" required>
                    <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?= h($errors['full_name']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="job_title">Job title</label>
                    <input class="form-control<?= isset($errors['job_title']) ? ' is-invalid' : '' ?>" id="job_title" name="job_title" value="<?= h($formData['job_title']) ?>" maxlength="100" placeholder="e.g. Broiler House Attendant" required>
                    <?php if (isset($errors['job_title'])): ?><div class="invalid-feedback"><?= h($errors['job_title']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="department">Department / farm unit</label>
                    <select class="form-select<?= isset($errors['department']) ? ' is-invalid' : '' ?>" id="department" name="department" required>
                        <option value="">Select department</option>
                        <?php foreach (PAYROLL_DEPARTMENTS as $dept): ?>
                            <option value="<?= h($dept) ?>" <?= $formData['department'] === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Farm Management, Feed &amp; Inventory, Maintenance, and Administration &amp; Accounting are shared/overhead roles allocated across all active flocks; the other departments allocate labor cost to the flocks they directly work with.</div>
                    <?php if (isset($errors['department'])): ?><div class="invalid-feedback"><?= h($errors['department']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="employment_status">Employment status</label>
                    <select class="form-select" id="employment_status" name="employment_status">
                        <?php foreach (['Active', 'Inactive', 'Terminated'] as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $formData['employment_status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="pay_type">Pay type</label>
                    <select class="form-select" id="pay_type" name="pay_type">
                        <option value="Daily" <?= $formData['pay_type'] === 'Daily' ? 'selected' : '' ?>>Daily rate</option>
                        <option value="Monthly" <?= $formData['pay_type'] === 'Monthly' ? 'selected' : '' ?>>Fixed monthly salary</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="basic_rate">Basic rate (&#8369;)</label>
                    <input class="form-control<?= isset($errors['basic_rate']) ? ' is-invalid' : '' ?>" type="text" id="basic_rate" name="basic_rate" value="<?= h($formData['basic_rate']) ?>" placeholder="e.g. 450.00">
                    <div class="form-text">Daily wage if "Daily rate", or full monthly salary if "Fixed monthly salary".</div>
                    <?php if (isset($errors['basic_rate'])): ?><div class="invalid-feedback"><?= h($errors['basic_rate']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="date_hired">Date hired</label>
                    <input class="form-control<?= isset($errors['date_hired']) ? ' is-invalid' : '' ?>" type="date" id="date_hired" name="date_hired" value="<?= h($formData['date_hired']) ?>" required>
                    <?php if (isset($errors['date_hired'])): ?><div class="invalid-feedback"><?= h($errors['date_hired']) ?></div><?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes (optional)</label>
                    <input class="form-control<?= isset($errors['notes']) ? ' is-invalid' : '' ?>" id="notes" name="notes" value="<?= h($formData['notes']) ?>" maxlength="255">
                    <?php if (isset($errors['notes'])): ?><div class="invalid-feedback"><?= h($errors['notes']) ?></div><?php endif; ?>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Add employee' ?></button>
                    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">PAYROLL &middot; EMPLOYEES</div>
            <h1>Employees</h1>
            <p>Farm personnel records used to prepare payroll. Compensation figures are restricted to Accounting Admin and IT Admin roles.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/payroll/index.php')) ?>">Go to Payroll</a>
            <a class="btn btn-primary" href="index.php?action=add">Add Employee</a>
        </div>
    </div>
    <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Employees</div><div class="stat-value"><?= number_format($stats['total']) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Active</div><div class="stat-value text-success"><?= number_format($stats['active']) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Daily-Rate Workers</div><div class="stat-value"><?= number_format($stats['daily']) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Monthly-Salaried Staff</div><div class="stat-value"><?= number_format($stats['monthly']) ?></div></div></div>
    </div>

    <section class="content-panel">
        <div class="panel-heading d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Employee Records</h2>
            <span class="text-secondary small"><?= number_format($totalEmployees) ?> record<?= $totalEmployees === 1 ? '' : 's' ?></span>
        </div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <?php pagination_preserve_per_page_field($perPage); ?>
                <div class="col-md-4">
                    <label class="form-label" for="search">Search</label>
                    <input class="form-control" id="search" name="search" value="<?= h($search ?? '') ?>" placeholder="Name, employee ID, or job title">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="department">Department</label>
                    <select class="form-select" id="department" name="department">
                        <option value="">All departments</option>
                        <?php foreach (PAYROLL_DEPARTMENTS as $dept): ?>
                            <option value="<?= h($dept) ?>" <?= ($departmentFilter ?? '') === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="employment_status">Status</label>
                    <select class="form-select" id="employment_status" name="employment_status">
                        <option value="">All statuses</option>
                        <?php foreach (['Active', 'Inactive', 'Terminated'] as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= ($statusFilter ?? '') === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Search</button>
                    <a class="btn btn-outline-secondary" href="index.php">Clear</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 9%"><col style="width: 17%"><col style="width: 16%"><col style="width: 14%">
                    <col style="width: 9%"><col style="width: 11%"><col style="width: 9%"><col style="width: 15%">
                </colgroup>
                <thead><tr><th scope="col">Employee ID</th><th scope="col">Name</th><th scope="col">Job Title</th><th scope="col">Department</th><th scope="col">Pay Type</th><th scope="col" class="text-end">Basic Rate</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php if ($employees === []): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-4">No employee records match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><span class="cell-id" title="<?= h($employee['employee_code']) ?>"><?= h($employee['employee_code']) ?></span></td>
                                <td class="fw-semibold cell-wrap"><?= h($employee['full_name']) ?></td>
                                <td class="cell-wrap"><?= h($employee['job_title']) ?></td>
                                <td class="cell-wrap"><?= h($employee['department']) ?></td>
                                <td class="cell-wrap"><?= h($employee['pay_type']) ?></td>
                                <td class="text-end">&#8369;<?= money($employee['basic_rate']) ?><?= $employee['pay_type'] === 'Daily' ? '/day' : '/mo' ?></td>
                                <td class="cell-wrap"><span class="badge text-bg-<?= employee_status_badge($employee['employment_status']) ?>"><?= h($employee['employment_status']) ?></span></td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $employee['employee_id'] ?>">Edit</a>
                                        <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Remove this employee record?\nName: <?= h($employee['full_name']) ?>\nPast payroll history is kept; this only stops future payroll generation for them.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="employee_id" value="<?= (int) $employee['employee_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination_controls($pageNum, $perPage, $totalEmployees, $_GET, 'index.php'); ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
