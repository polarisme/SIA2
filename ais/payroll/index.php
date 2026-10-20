<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_payroll';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Payroll database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create') {
        $periodStart = trim((string) ($_POST['period_start'] ?? ''));
        $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
        $payDate = trim((string) ($_POST['pay_date'] ?? ''));
        $errors = [];
        if (!ais_valid_date($periodStart)) {
            $errors[] = 'Enter a valid period start date.';
        }
        if (!ais_valid_date($periodEnd)) {
            $errors[] = 'Enter a valid period end date.';
        }
        if (!ais_valid_date($payDate)) {
            $errors[] = 'Enter a valid pay date.';
        }
        if ($errors === [] && $periodEnd < $periodStart) {
            $errors[] = 'The period end date cannot be before the period start date.';
        }
        if ($errors === []) {
            $dupCheck = $pdo->prepare('SELECT 1 FROM payroll_periods WHERE period_start = ? AND period_end = ? AND deleted_at IS NULL');
            $dupCheck->execute([$periodStart, $periodEnd]);
            if ($dupCheck->fetchColumn() !== false) {
                $errors[] = 'A payroll period with these exact dates already exists.';
            }
        }
        if ($errors !== []) {
            set_flash('danger', implode(' ', $errors));
            redirect_to('index.php?action=add');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO payroll_periods (period_start, period_end, pay_date, status, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$periodStart, $periodEnd, $payDate, 'Draft', current_username()]);
            $payrollPeriodId = (int) $pdo->lastInsertId();

            $employees = $pdo->query("SELECT * FROM employees WHERE employment_status = 'Active' AND deleted_at IS NULL ORDER BY department, full_name")->fetchAll();
            $insertItem = $pdo->prepare(
                'INSERT INTO payroll_items
                    (payroll_period_id, employee_id, days_worked, basic_pay, overtime_hours, overtime_pay, allowances,
                     sss_employee, philhealth_employee, pagibig_employee, other_deductions,
                     sss_employer, philhealth_employer, pagibig_employer, allocation_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($employees as $employee) {
                // Sensible default attendance: full standard work period, no overtime/
                // allowances/extra deductions yet — these are editable before approval.
                $defaultDaysWorked = $employee['pay_type'] === 'Daily' ? 22.0 : 0.0;
                $figures = payroll_calculate_item($employee, $defaultDaysWorked, 0.0, 0.0, 0.0);
                $insertItem->execute([
                    $payrollPeriodId, (int) $employee['employee_id'], $figures['days_worked'], $figures['basic_pay'],
                    $figures['overtime_hours'], $figures['overtime_pay'], $figures['allowances'],
                    $figures['sss_employee'], $figures['philhealth_employee'], $figures['pagibig_employee'], $figures['other_deductions'],
                    $figures['sss_employer'], $figures['philhealth_employer'], $figures['pagibig_employer'], 'Overhead',
                ]);
            }
            $pdo->commit();
            set_flash('success', 'Payroll period created with ' . count($employees) . ' employee(s). Review and adjust figures before approving.');
            redirect_to('index.php?action=view&id=' . $payrollPeriodId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Payroll period creation failed: ' . $exception->getMessage());
            set_flash('danger', 'The payroll period could not be created because of a database error.');
            redirect_to('index.php');
        }
    } elseif ($action === 'recalculate') {
        $payrollPeriodId = filter_var($_POST['payroll_period_id'] ?? null, FILTER_VALIDATE_INT);
        if ($payrollPeriodId === false || $payrollPeriodId < 1) {
            set_flash('danger', 'The selected payroll period could not be found.');
            redirect_to('index.php');
        }
        $periodCheck = $pdo->prepare('SELECT status FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
        $periodCheck->execute([$payrollPeriodId]);
        $periodStatus = $periodCheck->fetchColumn();
        if ($periodStatus === false) {
            set_flash('danger', 'The selected payroll period could not be found.');
            redirect_to('index.php');
        }
        if (!in_array($periodStatus, ['Draft', 'Reviewed'], true)) {
            set_flash('danger', 'This payroll period has already been approved or paid and can no longer be edited.');
            redirect_to('index.php?action=view&id=' . $payrollPeriodId);
        }

        $itemIds = array_map('intval', (array) ($_POST['item_id'] ?? []));
        $employeeStatement = $pdo->prepare('SELECT e.* FROM payroll_items pi JOIN employees e ON e.employee_id = pi.employee_id WHERE pi.payroll_item_id = ? AND pi.payroll_period_id = ?');
        $updateStatement = $pdo->prepare(
            'UPDATE payroll_items SET days_worked = ?, basic_pay = ?, overtime_hours = ?, overtime_pay = ?, allowances = ?,
                sss_employee = ?, philhealth_employee = ?, pagibig_employee = ?, other_deductions = ?,
                sss_employer = ?, philhealth_employer = ?, pagibig_employer = ?, allocation_type = ?, allocation_flock_id = ?
             WHERE payroll_item_id = ?'
        );
        foreach ($itemIds as $itemId) {
            $employeeStatement->execute([$itemId, $payrollPeriodId]);
            $employee = $employeeStatement->fetch();
            if (!$employee) {
                continue;
            }
            $daysWorked = (float) ($_POST['days_worked'][$itemId] ?? 0);
            $overtimeHours = (float) ($_POST['overtime_hours'][$itemId] ?? 0);
            $allowances = (float) ($_POST['allowances'][$itemId] ?? 0);
            $otherDeductions = (float) ($_POST['other_deductions'][$itemId] ?? 0);
            $allocationType = ($_POST['allocation_type'][$itemId] ?? 'Overhead') === 'Flock' ? 'Flock' : 'Overhead';
            $allocationFlockId = $allocationType === 'Flock' ? filter_var($_POST['allocation_flock_id'][$itemId] ?? null, FILTER_VALIDATE_INT) : null;
            $allocationFlockId = ($allocationFlockId !== false && $allocationFlockId > 0) ? $allocationFlockId : null;
            if ($allocationType === 'Flock' && $allocationFlockId === null) {
                $allocationType = 'Overhead';
            }

            $figures = payroll_calculate_item($employee, $daysWorked, $overtimeHours, $allowances, $otherDeductions);
            $updateStatement->execute([
                $figures['days_worked'], $figures['basic_pay'], $figures['overtime_hours'], $figures['overtime_pay'], $figures['allowances'],
                $figures['sss_employee'], $figures['philhealth_employee'], $figures['pagibig_employee'], $figures['other_deductions'],
                $figures['sss_employer'], $figures['philhealth_employer'], $figures['pagibig_employer'], $allocationType, $allocationFlockId,
                $itemId,
            ]);
        }
        set_flash('success', 'Payroll figures recalculated and saved.');
        redirect_to('index.php?action=view&id=' . $payrollPeriodId);
    } elseif ($action === 'advance_status') {
        $payrollPeriodId = filter_var($_POST['payroll_period_id'] ?? null, FILTER_VALIDATE_INT);
        $targetStatus = (string) ($_POST['target_status'] ?? '');
        if ($payrollPeriodId === false || $payrollPeriodId < 1 || !in_array($targetStatus, PAYROLL_PERIOD_STATUSES, true)) {
            set_flash('danger', 'Invalid payroll status request.');
            redirect_to('index.php');
        }
        $periodStatement = $pdo->prepare('SELECT * FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
        $periodStatement->execute([$payrollPeriodId]);
        $period = $periodStatement->fetch();
        if (!$period) {
            set_flash('danger', 'The selected payroll period could not be found.');
            redirect_to('index.php');
        }
        $allowedTransitions = ['Draft' => 'Reviewed', 'Reviewed' => 'Approved', 'Approved' => 'Paid'];
        if (($allowedTransitions[$period['status']] ?? null) !== $targetStatus) {
            set_flash('danger', "A payroll period in '{$period['status']}' status cannot move directly to '{$targetStatus}'.");
            redirect_to('index.php?action=view&id=' . $payrollPeriodId);
        }

        if ($targetStatus === 'Paid') {
            $pdo->beginTransaction();
            try {
                $result = payroll_post_to_ais($pdo, $payrollPeriodId, current_username());
                if (!$result['already_posted']) {
                    payroll_allocate_batch_costs($pdo, $payrollPeriodId);
                }
                $pdo->commit();
                set_flash('success', 'Payroll period marked Paid. Wages and employer contributions have been posted to AIS.');
            } catch (Throwable $exception) {
                $pdo->rollBack();
                error_log('Payroll posting failed: ' . $exception->getMessage());
                set_flash('danger', 'Payroll could not be posted because of a database error.');
            }
        } else {
            $column = $targetStatus === 'Approved' ? 'approved_by' : null;
            if ($column !== null) {
                $pdo->prepare("UPDATE payroll_periods SET status = ?, approved_by = ?, approved_at = NOW() WHERE payroll_period_id = ?")
                    ->execute([$targetStatus, current_username(), $payrollPeriodId]);
            } else {
                $pdo->prepare('UPDATE payroll_periods SET status = ? WHERE payroll_period_id = ?')->execute([$targetStatus, $payrollPeriodId]);
            }
            set_flash('success', "Payroll period status updated to $targetStatus.");
        }
        redirect_to('index.php?action=view&id=' . $payrollPeriodId);
    } elseif ($action === 'delete') {
        $payrollPeriodId = filter_var($_POST['payroll_period_id'] ?? null, FILTER_VALIDATE_INT);
        if ($payrollPeriodId !== false && $payrollPeriodId > 0) {
            $statusCheck = $pdo->prepare('SELECT status FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
            $statusCheck->execute([$payrollPeriodId]);
            $status = $statusCheck->fetchColumn();
            if ($status === 'Draft' || $status === 'Reviewed') {
                $pdo->prepare('UPDATE payroll_periods SET deleted_at = NOW(), deleted_by = ? WHERE payroll_period_id = ?')->execute([current_username(), $payrollPeriodId]);
                $pdo->prepare('UPDATE payroll_items SET deleted_at = NOW(), deleted_by = ? WHERE payroll_period_id = ?')->execute([current_username(), $payrollPeriodId]);
                set_flash('success', 'Payroll period removed.');
            } else {
                set_flash('danger', 'Only Draft or Reviewed payroll periods can be removed — this one has already been approved or paid.');
            }
        }
        redirect_to('index.php');
    }
}

$periods = [];
$totalPeriods = 0;
$periodDetail = null;
$items = [];
$itemTotals = ['gross' => 0.0, 'net' => 0.0, 'employer_cost' => 0.0];
$flockOptions = [];

if ($pdo instanceof PDO && $databaseError === null) {
    try {
        if ($action === 'view') {
            $payrollPeriodId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($payrollPeriodId === false || $payrollPeriodId < 1) {
                set_flash('danger', 'The selected payroll period could not be found.');
                redirect_to('index.php');
            }
            $periodStatement = $pdo->prepare('SELECT * FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
            $periodStatement->execute([$payrollPeriodId]);
            $periodDetail = $periodStatement->fetch();
            if (!$periodDetail) {
                set_flash('danger', 'The selected payroll period could not be found.');
                redirect_to('index.php');
            }
            $itemsStatement = $pdo->prepare(
                "SELECT pi.*, e.employee_code, e.full_name, e.job_title, e.department, e.pay_type, e.basic_rate
                 FROM payroll_items pi JOIN employees e ON e.employee_id = pi.employee_id
                 WHERE pi.payroll_period_id = ? AND pi.deleted_at IS NULL ORDER BY e.department, e.full_name"
            );
            $itemsStatement->execute([$payrollPeriodId]);
            $items = $itemsStatement->fetchAll();
            foreach ($items as $item) {
                $itemTotals['gross'] += (float) $item['gross_pay'];
                $itemTotals['net'] += (float) $item['net_pay'];
                $itemTotals['employer_cost'] += (float) $item['basic_pay'] + (float) $item['overtime_pay'] + (float) $item['allowances']
                    + (float) $item['sss_employer'] + (float) $item['philhealth_employer'] + (float) $item['pagibig_employer'];
            }
            $flockOptions = $pdo->query("SELECT flock_id, batch_code FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code")->fetchAll();
        } else {
            ['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
            $totalPeriods = (int) $pdo->query('SELECT COUNT(*) FROM payroll_periods WHERE deleted_at IS NULL')->fetchColumn();
            $periods = $pdo->query(
                "SELECT pp.*,
                        (SELECT COALESCE(SUM(gross_pay),0) FROM payroll_items WHERE payroll_period_id = pp.payroll_period_id AND deleted_at IS NULL) AS total_gross,
                        (SELECT COUNT(*) FROM payroll_items WHERE payroll_period_id = pp.payroll_period_id AND deleted_at IS NULL) AS headcount
                 FROM payroll_periods pp WHERE pp.deleted_at IS NULL
                 ORDER BY pp.period_start DESC LIMIT $perPage OFFSET $offset"
            )->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Payroll query failed: ' . $exception->getMessage());
        $databaseError = 'Payroll data could not be loaded because of a database error.';
    }
}

$pageTitle = $action === 'view' ? 'Payroll Period Detail' : ($action === 'add' ? 'Create Payroll Period' : 'Payroll');
require __DIR__ . '/../../shared/includes/header.php';
?>
<?php if ($action === 'add'): ?>
    <div class="page-heading">
        <div class="eyebrow">PAYROLL</div>
        <h1>Create Payroll Period</h1>
        <p>A new period auto-generates one line per active employee using their standard rate, so you can review and adjust before approving.</p>
    </div>
    <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
    <section class="content-panel">
        <div class="panel-body">
            <form method="post" action="index.php?action=create" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-md-4">
                    <label class="form-label" for="period_start">Period start</label>
                    <input class="form-control" type="date" id="period_start" name="period_start" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="period_end">Period end</label>
                    <input class="form-control" type="date" id="period_end" name="period_end" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pay_date">Pay date</label>
                    <input class="form-control" type="date" id="pay_date" name="pay_date" required>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Create Period</button>
                    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php elseif ($action === 'view' && $periodDetail !== null): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">PAYROLL PERIOD</div>
            <h1><?= h((string) $periodDetail['period_start']) ?> &ndash; <?= h((string) $periodDetail['period_end']) ?></h1>
            <p>Pay date <?= h((string) $periodDetail['pay_date']) ?> &middot; <span class="badge text-bg-<?= payroll_period_status_badge($periodDetail['status']) ?>"><?= h($periodDetail['status']) ?></span></p>
        </div>
        <a class="btn btn-outline-secondary" href="index.php">Back to Payroll</a>
    </div>
    <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Headcount</div><div class="stat-value"><?= count($items) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Gross Pay</div><div class="stat-value">&#8369;<?= money($itemTotals['gross']) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Net Pay</div><div class="stat-value text-success">&#8369;<?= money($itemTotals['net']) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Employer Cost</div><div class="stat-value text-danger">&#8369;<?= money($itemTotals['employer_cost']) ?></div></div></div>
    </div>

    <section class="content-panel mb-3">
        <div class="panel-heading d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Workflow</h2>
        </div>
        <div class="panel-body d-flex flex-wrap gap-2 align-items-center">
            <?php
            $transitions = ['Draft' => 'Reviewed', 'Reviewed' => 'Approved', 'Approved' => 'Paid'];
            $next = $transitions[$periodDetail['status']] ?? null;
            ?>
            <?php if ($next !== null): ?>
                <form method="post" action="index.php?action=advance_status" onsubmit="return confirm('<?= $next === 'Paid' ? 'Mark this period as Paid? This will post wages and employer contributions to AIS and allocate labor cost to batches — this cannot be done twice.' : "Move this period to '$next'?" ?>');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="payroll_period_id" value="<?= (int) $periodDetail['payroll_period_id'] ?>">
                    <input type="hidden" name="target_status" value="<?= h($next) ?>">
                    <button class="btn btn-primary" type="submit">Mark as <?= h($next) ?></button>
                </form>
            <?php else: ?>
                <span class="text-secondary small">This period has been fully processed.</span>
            <?php endif; ?>
            <?php if (in_array($periodDetail['status'], ['Draft', 'Reviewed'], true)): ?>
                <form method="post" action="index.php?action=delete" onsubmit="return confirm('Remove this payroll period and its line items?');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="payroll_period_id" value="<?= (int) $periodDetail['payroll_period_id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Remove Period</button>
                </form>
            <?php endif; ?>
            <?php if ($periodDetail['wages_transaction_id']): ?>
                <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/transactions/index.php')) ?>?action=view&amp;id=<?= (int) $periodDetail['wages_transaction_id'] ?>">View Wages Transaction</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Payroll Items</h2></div>
        <form method="post" action="index.php?action=recalculate">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="payroll_period_id" value="<?= (int) $periodDetail['payroll_period_id'] ?>">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle table-compact">
                    <thead>
                        <tr>
                            <th scope="col">Employee</th><th scope="col" class="text-end">Days</th><th scope="col" class="text-end">OT Hrs</th><th scope="col" class="text-end">Allowances</th>
                            <th scope="col" class="text-end">Other Ded.</th><th scope="col">Allocation</th><th scope="col" class="text-end">Gross</th><th scope="col" class="text-end">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items === []): ?>
                            <tr><td colspan="8" class="text-center text-secondary py-4">No payroll items for this period.</td></tr>
                        <?php else: ?>
                            <?php $editable = in_array($periodDetail['status'], ['Draft', 'Reviewed'], true); ?>
                            <?php foreach ($items as $item): ?>
                                <?php $itemId = (int) $item['payroll_item_id']; ?>
                                <tr>
                                    <td class="cell-wrap">
                                        <div class="fw-semibold"><?= h($item['full_name']) ?></div>
                                        <div class="small text-secondary"><?= h($item['job_title']) ?> &middot; <?= h($item['department']) ?></div>
                                        <input type="hidden" name="item_id[]" value="<?= $itemId ?>">
                                    </td>
                                    <td class="text-end" style="width: 80px;">
                                        <input class="form-control form-control-sm text-end" type="number" step="0.5" min="0" max="31"
                                               name="days_worked[<?= $itemId ?>]" value="<?= h((string) $item['days_worked']) ?>" <?= $editable ? '' : 'readonly' ?>>
                                    </td>
                                    <td class="text-end" style="width: 80px;">
                                        <input class="form-control form-control-sm text-end" type="number" step="0.5" min="0"
                                               name="overtime_hours[<?= $itemId ?>]" value="<?= h((string) $item['overtime_hours']) ?>" <?= $editable ? '' : 'readonly' ?>>
                                    </td>
                                    <td class="text-end" style="width: 95px;">
                                        <input class="form-control form-control-sm text-end" type="number" step="0.01" min="0"
                                               name="allowances[<?= $itemId ?>]" value="<?= h((string) $item['allowances']) ?>" <?= $editable ? '' : 'readonly' ?>>
                                    </td>
                                    <td class="text-end" style="width: 95px;">
                                        <input class="form-control form-control-sm text-end" type="number" step="0.01" min="0"
                                               name="other_deductions[<?= $itemId ?>]" value="<?= h((string) $item['other_deductions']) ?>" <?= $editable ? '' : 'readonly' ?>>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <?php if ($editable): ?>
                                            <select class="form-select form-select-sm mb-1" name="allocation_type[<?= $itemId ?>]" onchange="document.getElementById('flock_<?= $itemId ?>').disabled = this.value !== 'Flock';">
                                                <option value="Overhead" <?= $item['allocation_type'] === 'Overhead' ? 'selected' : '' ?>>Overhead (shared)</option>
                                                <option value="Flock" <?= $item['allocation_type'] === 'Flock' ? 'selected' : '' ?>>Specific batch</option>
                                            </select>
                                            <select class="form-select form-select-sm" id="flock_<?= $itemId ?>" name="allocation_flock_id[<?= $itemId ?>]" <?= $item['allocation_type'] !== 'Flock' ? 'disabled' : '' ?>>
                                                <option value="">—</option>
                                                <?php foreach ($flockOptions as $flockOption): ?>
                                                    <option value="<?= (int) $flockOption['flock_id'] ?>" <?= (int) ($item['allocation_flock_id'] ?? 0) === (int) $flockOption['flock_id'] ? 'selected' : '' ?>><?= h($flockOption['batch_code']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <span class="small"><?= h($item['allocation_type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">&#8369;<?= money($item['gross_pay']) ?></td>
                                    <td class="text-end fw-semibold">&#8369;<?= money($item['net_pay']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($editable && $items !== []): ?>
                <div class="panel-body border-top">
                    <button class="btn btn-outline-primary" type="submit">Save &amp; Recalculate</button>
                    <span class="small text-secondary ms-2">SSS, PhilHealth, and Pag-IBIG contributions recompute automatically from gross pay.</span>
                </div>
            <?php endif; ?>
        </form>
    </section>
<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">PAYROLL</div>
            <h1>Payroll</h1>
            <p>Prepare, review, approve, and pay farm payroll. Paid periods post wages and employer contributions to AIS automatically.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/employees/index.php')) ?>">Manage Employees</a>
            <a class="btn btn-primary" href="index.php?action=add">Create Payroll Period</a>
        </div>
    </div>
    <?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

    <section class="content-panel">
        <div class="panel-heading d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Payroll Periods</h2>
            <span class="text-secondary small"><?= number_format($totalPeriods) ?> period<?= $totalPeriods === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 24%"><col style="width: 14%"><col style="width: 12%">
                    <col style="width: 15%"><col style="width: 13%"><col style="width: 22%">
                </colgroup>
                <thead><tr><th scope="col">Period</th><th scope="col">Pay Date</th><th scope="col" class="text-end">Headcount</th><th scope="col" class="text-end">Total Gross</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php if ($periods === []): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No payroll periods yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($periods as $period): ?>
                            <tr>
                                <td><span class="cell-id" title="<?= h((string) $period['period_start']) ?> &ndash; <?= h((string) $period['period_end']) ?>"><?= h((string) $period['period_start']) ?> &ndash; <?= h((string) $period['period_end']) ?></span></td>
                                <td><span class="cell-id" title="<?= h((string) $period['pay_date']) ?>"><?= h((string) $period['pay_date']) ?></span></td>
                                <td class="text-end"><?= number_format((int) $period['headcount']) ?></td>
                                <td class="text-end">&#8369;<?= money($period['total_gross']) ?></td>
                                <td class="cell-wrap"><span class="badge text-bg-<?= payroll_period_status_badge($period['status']) ?>"><?= h($period['status']) ?></span></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $period['payroll_period_id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination_controls($pageNum ?? 1, $perPage ?? PAGINATION_DEFAULT_PER_PAGE, $totalPeriods, $_GET, 'index.php'); ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
